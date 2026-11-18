<?php
// Container start-up: create/upgrade the schema, refresh the category taxonomy and seed overrides.
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use App\{Categoriser, Cloudflare, Db, Job, Rollup};

Job::lockDb();
$pdo = Db::open();
$v = Db::migrate($pdo);
Db::setMeta($pdo, 'started_at', (string) time());   // health.php's grace period before the first ingest

// A changed taxonomy or seed list applies to existing domains now, not at the next weekly list update.
$hash = md5(md5_file(APP_ROOT . '/config/seed-overrides.php') . md5_file(APP_ROOT . '/config/categories.php') . env('LOCAL_DOMAINS'));
if (Db::meta($pdo, 'config_hash') !== $hash) {
    $pdo->beginTransaction();
    $cfChanged = Cloudflare::reclassifyCache($pdo);   // cached Cloudflare answers follow a new mapping too
    $changed = (new Categoriser($pdo))->recategoriseAll();
    $days = $changed > 0 ? Rollup::rebuildAll($pdo) : 0;
    Db::setMeta($pdo, 'config_hash', $hash);
    $pdo->commit();
    Job::log('migrate', "config changed: cloudflare_remapped=$cfChanged recategorised=$changed rollup_days=$days");
}
Job::log('migrate', "schema_version=$v db=" . Db::path());
