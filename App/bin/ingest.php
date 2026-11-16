<?php
// Every 5 min: pull new AdGuard query log entries into SQLite (Plan section 6). Exits 0 when there is
// nothing to do; a failed run (e.g. AdGuard's Sunday restart) logs and exits 1, and the next run catches up.
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use App\{AdGuard, Categoriser, Db, Ingest, Job, Psl};

if (!Job::lockOrSkip('ingest')) {
    Job::log('ingest', 'previous run still going, skipped');
    exit(0);
}
$ag = AdGuard::fromEnv();
if ($ag === null) {
    Job::log('ingest', 'ADGUARD_URL is not set, skipped');
    exit(0);
}
Job::lockDb();
$t0 = microtime(true);
$pdo = Db::open();
$ing = new Ingest($pdo, Psl::load(), new Categoriser($pdo));
try {
    $ing->run($ag);
} catch (Throwable $e) {
    Job::log('ingest', sprintf('FAILED after %d pages, %d new rows: %s', $ing->pages, $ing->inserted, $e->getMessage()));
    exit(1);
}
Job::log('ingest', sprintf('ok pages=%d seen=%d new=%d in %.1fs', $ing->pages, $ing->seen, $ing->inserted, microtime(true) - $t0));
