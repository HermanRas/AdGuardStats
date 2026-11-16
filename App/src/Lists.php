<?php
declare(strict_types=1);

namespace App;

use PDO;

/**
 * Category lists (UT1 + HaGeZi) and the Public Suffix List: download, parse, and load into
 * `list_domains_new`, then swap it into place in one transaction. A failed download or a list that
 * comes back suspiciously small leaves the current lists untouched.
 *
 * Folder layout used by loadFromDir() (the download step produces the same shape, and so do the
 * test fixtures):  <dir>/hagezi/<list>.txt   <dir>/ut1/<category>/domains
 */
final class Lists
{
    /** @param callable(string):void $log */
    public function __construct(private PDO $pdo, private $log = null)
    {
        $this->log ??= static function (string $m): void {};
    }

    public static function sources(): array
    {
        return require APP_ROOT . '/config/sources.php';
    }

    /** Downloads everything into a fresh temp folder. Throws on any failure. Returns the folder. */
    public function download(string $workDir): string
    {
        $src = self::sources();
        $dir = $workDir . '/dl-' . getmypid() . '-' . time();
        self::rmrf($dir);
        mkdir($dir . '/hagezi', 0775, true);

        self::fetch($src['psl'], $dir . '/public_suffix_list.dat');
        if (Psl::load($dir . '/public_suffix_list.dat')->ruleCount() < 5000) {
            throw new \RuntimeException('PSL download looks truncated');
        }
        foreach ($src['hagezi'] as $name => $url) {
            self::fetch($url, "$dir/hagezi/$name.txt");
        }

        // UT1: one tarball; extract only the categories we map.
        self::fetch($src['ut1'], "$dir/ut1.tar.gz");
        $members = self::run(['tar', '-tzf', "$dir/ut1.tar.gz"]);
        $want = [];
        foreach (array_keys(Categories::mapping('ut1')) as $cat) {
            $m = "blacklists/$cat/domains";
            if (in_array($m, $members, true)) {
                $want[] = $m;
            } else {
                ($this->log)("ut1: category '$cat' not in the archive, skipped");
            }
        }
        if (count($want) < 10) {
            throw new \RuntimeException('UT1 archive has too few of the expected categories');
        }
        mkdir("$dir/ut1x", 0775, true);
        self::run(array_merge(['tar', '-xzf', "$dir/ut1.tar.gz", '-C', "$dir/ut1x"], $want));
        rename("$dir/ut1x/blacklists", "$dir/ut1");
        unlink("$dir/ut1.tar.gz");
        rmdir("$dir/ut1x");
        return $dir;
    }

    /**
     * Loads <dir> into list_domains (swap in one transaction). Missing files are skipped; the whole
     * load is refused if fewer than $minTotal domains result.
     * @return array<string,int> domains loaded per source label, plus 'total'
     */
    public function loadFromDir(string $dir, int $minTotal = 100000): array
    {
        $pdo = $this->pdo;
        $pdo->exec('DROP TABLE IF EXISTS list_domains_new');
        $pdo->exec('CREATE TABLE list_domains_new (domain TEXT PRIMARY KEY, category_id INTEGER NOT NULL,
            source TEXT NOT NULL) WITHOUT ROWID');
        $ins = $pdo->prepare('INSERT OR IGNORE INTO list_domains_new (domain, category_id, source) VALUES (?, ?, ?)');

        $counts = [];
        $pdo->beginTransaction();
        try {
            // Priority: HaGeZi before UT1, and config order within each; INSERT OR IGNORE keeps the first.
            foreach (Categories::mapping('hagezi') as $name => $cid) {
                $counts["hagezi:$name"] = $this->loadFile("$dir/hagezi/$name.txt", $cid, "hagezi:$name", $ins);
            }
            foreach (Categories::mapping('ut1') as $cat => $cid) {
                $counts["ut1:$cat"] = $this->loadFile("$dir/ut1/$cat/domains", $cid, "ut1:$cat", $ins);
            }
            $total = (int) $pdo->query('SELECT count(*) FROM list_domains_new')->fetchColumn();
            if ($total < $minTotal) {
                throw new \RuntimeException("only $total list domains loaded (minimum $minTotal); keeping the old lists");
            }
            $pdo->exec('DROP TABLE list_domains');
            $pdo->exec('ALTER TABLE list_domains_new RENAME TO list_domains');
            Db::setMeta($pdo, 'lists_loaded_at', (string) time());
            Db::setMeta($pdo, 'lists_counts', json_encode($counts));
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $pdo->exec('DROP TABLE IF EXISTS list_domains_new');
            throw $e;
        }
        $counts['total'] = $total;
        return $counts;
    }

    private function loadFile(string $file, int $cid, string $source, \PDOStatement $ins): int
    {
        if (!is_file($file)) {
            ($this->log)("$source: file missing, skipped");
            return 0;
        }
        $fh = fopen($file, 'rb');
        $n = 0;
        while (($line = fgets($fh)) !== false) {
            $d = self::parseLine($line);
            if ($d !== null) {
                $ins->execute([$d, $cid, $source]);
                $n += $ins->rowCount();
            }
        }
        fclose($fh);
        return $n;
    }

    /** One list line to a normalised domain, or null for comments, wildcards, IPs and junk. */
    public static function parseLine(string $line): ?string
    {
        $line = strtolower(trim($line));
        if ($line === '' || $line[0] === '#' || $line[0] === '!') {
            return null;
        }
        $line = rtrim($line, '.');
        if (!preg_match('/^(?=.{1,253}$)[a-z0-9_](?:[a-z0-9_-]{0,62}[a-z0-9_])?(?:\.[a-z0-9_](?:[a-z0-9_-]{0,62}[a-z0-9_])?)+$/', $line)
            || filter_var($line, FILTER_VALIDATE_IP)) {
            return null;
        }
        return $line;
    }

    private static function fetch(string $url, string $to): void
    {
        self::run(['curl', '-fsSL', '--retry', '3', '--connect-timeout', '20', '--max-time', '900',
            '-A', 'AdGuardStats list updater', '-o', $to, $url]);
        if (!is_file($to) || filesize($to) === 0) {
            throw new \RuntimeException("download of $url is empty");
        }
    }

    /** Runs a command without a shell. Returns stdout lines; throws on a non-zero exit. */
    private static function run(array $cmd): array
    {
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) {
            throw new \RuntimeException('cannot run ' . $cmd[0]);
        }
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($p);
        if ($code !== 0) {
            throw new \RuntimeException($cmd[0] . " exited $code: " . trim($err));
        }
        return preg_split('/\R/', trim((string) $out)) ?: [];
    }

    public static function rmrf(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $f) {
                if ($f !== '.' && $f !== '..') {
                    self::rmrf("$path/$f");
                }
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }
}
