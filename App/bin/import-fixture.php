<?php
// Loads a saved query log page (and optionally local category lists) with no AdGuard and no network.
// Used by CI and for local demos.
// Usage: import-fixture.php <querylog.json> [<lists-dir>]
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use App\{Categoriser, Db, Ingest, Job, Lists, Psl, Rollup};

[$file, $listsDir] = [$argv[1] ?? '', $argv[2] ?? null];
if (!is_file($file)) {
    fwrite(STDERR, "usage: import-fixture.php <querylog.json> [<lists-dir>]\n");
    exit(2);
}
Job::lockDb();
$pdo = Db::open();
Db::migrate($pdo);
if ($listsDir !== null) {
    $c = (new Lists($pdo))->loadFromDir($listsDir, 1);
    Job::log('fixture', "lists loaded: {$c['total']} domains");
}
$json = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
$ing = new Ingest($pdo, Psl::load(), new Categoriser($pdo));
$ing->commitBatch($json['data'] ?? $json, ['ingest_ok_at' => time()]);
$pdo->beginTransaction();
(new Categoriser($pdo))->recategoriseAll();
Rollup::rebuildAll($pdo);
$pdo->commit();
Job::log('fixture', "ok seen={$ing->seen} new={$ing->inserted}");
