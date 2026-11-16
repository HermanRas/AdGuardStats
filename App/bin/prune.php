<?php
// Daily 03:30: delete raw queries older than RETENTION_DAYS (whole local days; the daily rollup is kept),
// PRAGMA optimize; VACUUM on the 1st of the month; a weekly (Monday) backup keeping the last two.
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use App\{Db, Job};

if (!Job::lockOrSkip('prune')) {
    exit(0);
}
Job::lockDb();
$pdo = Db::open();
$days = max(1, (int) env('RETENTION_DAYS', '180'));
$cutoff = (new DateTimeImmutable('today'))->modify("-$days days")->getTimestamp();

$deleted = 0;
do {   // in chunks, so the write lock is never held for long
    $n = $pdo->exec("DELETE FROM queries WHERE id IN (SELECT id FROM queries WHERE ts < $cutoff LIMIT 50000)");
    $deleted += $n;
} while ($n > 0);
$orphans = $deleted > 0 ? $pdo->exec('DELETE FROM domains WHERE id NOT IN (SELECT domain_id FROM queries)') : 0;
$pdo->exec('PRAGMA optimize');

$extra = '';
if (date('j') === '1') {
    $pdo->exec('VACUUM');
    $extra .= ' vacuum';
}
if (date('N') === '1') {
    $dir = data_dir() . '/backup';
    @mkdir($dir, 0775, true);
    $file = "$dir/stats-" . date('Y-m-d') . '.sqlite';
    @unlink($file);
    $pdo->exec('VACUUM INTO ' . $pdo->quote($file));
    $old = glob("$dir/stats-*.sqlite") ?: [];
    rsort($old);
    foreach (array_slice($old, 2) as $f) {
        unlink($f);
    }
    $extra .= ' backup=' . basename($file);
}
Db::setMeta($pdo, 'prune_ok_at', (string) time());
Job::log('prune', "ok deleted=$deleted domains_dropped=$orphans (before " . date('Y-m-d', $cutoff) . ")$extra");
