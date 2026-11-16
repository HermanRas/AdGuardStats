<?php
// Weekly (Mon 03:00): download UT1, HaGeZi and the PSL, rebuild list_domains, re-categorise every domain,
// and rebuild the daily rollup. With --if-empty it only runs when no lists are loaded yet (first start).
// Usage: update-lists.php [--if-empty] [--from-dir=<folder>]   (--from-dir loads local files, no network)
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use App\{Categoriser, Db, Job, Lists, Psl, Rollup};

$opts = getopt('', ['if-empty', 'from-dir:', 'min-total:']);
if (!Job::lockOrSkip('update-lists')) {
    Job::log('lists', 'previous run still going, skipped');
    exit(0);
}
$pdo = Db::open();
if (isset($opts['if-empty']) && (int) $pdo->query('SELECT count(*) FROM (SELECT 1 FROM list_domains LIMIT 1)')->fetchColumn() > 0) {
    exit(0);
}
$log = static fn (string $m) => Job::log('lists', $m);
$lists = new Lists($pdo, $log);
$t0 = microtime(true);
$work = data_dir() . '/lists';
@mkdir($work, 0775, true);

try {
    $dir = $opts['from-dir'] ?? $lists->download($work);
    $minTotal = (int) ($opts['min-total'] ?? (isset($opts['from-dir']) ? 1 : 100000));
    Job::lockDb();
    $counts = $lists->loadFromDir($dir, $minTotal);
    if (is_file("$dir/public_suffix_list.dat") && !isset($opts['from-dir'])) {
        copy("$dir/public_suffix_list.dat", Psl::refreshedPath() . '.new');
        rename(Psl::refreshedPath() . '.new', Psl::refreshedPath());
    }
} catch (Throwable $e) {
    Db::setMeta($pdo, 'lists_error', date('c') . ' ' . $e->getMessage());
    $log('FAILED, previous lists kept: ' . $e->getMessage());
    exit(1);
} finally {
    if (isset($dir) && !isset($opts['from-dir'])) {
        Lists::rmrf($dir);
    }
}

// New PSL may move base domains; new lists move categories. Then the rollup follows.
$psl = Psl::load();
$pdo->beginTransaction();
$upd = $pdo->prepare('UPDATE domains SET base = ? WHERE id = ?');
$rebased = 0;
foreach ($pdo->query('SELECT id, fqdn, base FROM domains')->fetchAll() as $r) {
    if (($b = $psl->base($r['fqdn'])) !== $r['base']) {
        $upd->execute([$b, $r['id']]);
        $rebased++;
    }
}
$changed = (new Categoriser($pdo))->recategoriseAll();
$days = Rollup::rebuildAll($pdo);
Db::setMeta($pdo, 'lists_error', null);
Db::setMeta($pdo, 'lists_ok_at', (string) time());
$pdo->commit();

$log(sprintf('ok list_domains=%d recategorised=%d rebased=%d rollup_days=%d in %.0fs',
    $counts['total'], $changed, $rebased, $days, microtime(true) - $t0));
