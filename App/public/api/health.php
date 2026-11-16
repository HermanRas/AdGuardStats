<?php
// Container health: 200 when the last successful ingest (or progress during a long backfill, or the
// container start if nothing has run yet) is under 30 min old; 503 otherwise. Never needs auth.
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use App\{Db, Http};

$out = ['ok' => false, 'ingest_age_s' => null, 'schema_version' => 0];
try {
    if (is_file(Db::path())) {
        $pdo = Db::open();
        $out['schema_version'] = (int) (Db::meta($pdo, 'schema_version') ?? 0);
        $last = max(array_map('intval', [Db::meta($pdo, 'ingest_ok_at') ?? 0,
            Db::meta($pdo, 'ingest_progress_at') ?? 0, Db::meta($pdo, 'started_at') ?? 0]));
        $out['ingest_age_s'] = $last > 0 ? time() - $last : null;
        $out['ok'] = $out['schema_version'] > 0 && $last > 0 && time() - $last <= 1800;
    }
} catch (Throwable $e) {
    $out['error'] = 'database error';
}
Http::json($out, $out['ok'] ? 200 : 503);
