<?php
declare(strict_types=1);

namespace App;

use PDO;
use PDOStatement;

/**
 * Picks a category for a domain (Plan section 4): walk from the FQDN up to its base domain, checking
 * overrides then the merged list table at each level; first hit wins. Cloudflare's answer for the base
 * domain is used only when no level matched.
 */
final class Categoriser
{
    private PDOStatement $override;
    private PDOStatement $list;
    private PDOStatement $cf;

    public function __construct(private PDO $pdo)
    {
        $this->override = $pdo->prepare('SELECT category_id FROM overrides WHERE domain = ?');
        $this->list = $pdo->prepare('SELECT category_id, source FROM list_domains WHERE domain = ?');
        $this->cf = $pdo->prepare("SELECT json_extract(cf_raw, '$.app_category_id') AS cid,
            json_extract(cf_raw, '$.source') AS src FROM base_cf WHERE base = ?");
    }

    /** The levels to check, most specific first: a.b.example.co.uk, b.example.co.uk, example.co.uk. */
    public static function levels(string $fqdn, string $base): array
    {
        if ($base === '' || ($fqdn !== $base && !str_ends_with($fqdn, '.' . $base))) {
            return [$fqdn];
        }
        $out = [];
        $name = $fqdn;
        while (true) {
            $out[] = $name;
            if ($name === $base) {
                return $out;
            }
            $name = substr($name, strpos($name, '.') + 1);
        }
    }

    /** @return array{0:int,1:string} [category id, source label] */
    public function categorise(string $fqdn, string $base): array
    {
        foreach (self::levels($fqdn, $base) as $name) {
            $this->override->execute([$name]);
            $id = $this->override->fetchColumn();
            $this->override->closeCursor();
            if ($id !== false) {
                return [(int) $id, 'override'];
            }
            $this->list->execute([$name]);
            $row = $this->list->fetch();
            $this->list->closeCursor();
            if ($row) {
                return [(int) $row['category_id'], (string) $row['source']];
            }
        }
        // Overrides may also name a level above the base domain (`lan`, `home.arpa`, `local`).
        $name = $base;
        while (($dot = strpos($name, '.')) !== false) {
            $name = substr($name, $dot + 1);
            $this->override->execute([$name]);
            $id = $this->override->fetchColumn();
            $this->override->closeCursor();
            if ($id !== false) {
                return [(int) $id, 'override'];
            }
        }
        $this->cf->execute([$base]);
        $row = $this->cf->fetch();
        $this->cf->closeCursor();
        if ($row && $row['cid'] !== null) {
            return [(int) $row['cid'], (string) $row['src']];
        }
        return [Categories::UNCATEGORISED, 'none'];
    }

    /** Re-categorises every row in `domains` (or those under one base). Returns how many changed. */
    public function recategoriseAll(?string $onlyBase = null): int
    {
        $sql = 'SELECT id, fqdn, base, category_id, category_source FROM domains';
        $rows = $onlyBase === null
            ? $this->pdo->query($sql)->fetchAll()
            : (function () use ($sql, $onlyBase) {
                $st = $this->pdo->prepare($sql . ' WHERE base = ?');
                $st->execute([$onlyBase]);
                return $st->fetchAll();
            })();
        $upd = $this->pdo->prepare('UPDATE domains SET category_id = ?, category_source = ? WHERE id = ?');
        $changed = 0;
        foreach ($rows as $r) {
            [$cid, $src] = $this->categorise($r['fqdn'], $r['base']);
            if ($cid !== (int) $r['category_id'] || $src !== $r['category_source']) {
                $upd->execute([$cid, $src, $r['id']]);
                $changed++;
            }
        }
        return $changed;
    }

    /** Adds the seed overrides from config/seed-overrides.php; never replaces one already there. */
    public static function seedOverrides(PDO $pdo): void
    {
        $st = $pdo->prepare('INSERT OR IGNORE INTO overrides (domain, category_id, note) VALUES (?, ?, ?)');
        foreach (require APP_ROOT . '/config/seed-overrides.php' as $domain => [$slug, $note]) {
            $id = Categories::idForSlug($slug) ?? throw new \LogicException("seed override $domain: unknown slug $slug");
            $st->execute([Psl::normalise($domain), $id, $note]);
        }
        foreach (self::localDomains() as $domain) {
            $st->execute([$domain, Categories::idForSlug('infrastructure'), 'LOCAL_DOMAINS']);
        }
    }

    /** Your own domains from LOCAL_DOMAINS (comma-separated), categorised as Infrastructure. */
    public static function localDomains(): array
    {
        $out = [];
        foreach (explode(',', env('LOCAL_DOMAINS')) as $d) {
            $d = Psl::normalise($d);
            if ($d !== '' && preg_match('/^[a-z0-9.-]+$/', $d)) {
                $out[] = $d;
            }
        }
        return $out;
    }
}
