<?php
// Every 30 min: optional Cloudflare Intel lookups for base domains no override or list matched
// (Plan section 4). Does nothing unless CF_ENABLED=1. Looks up CF_BATCH bases (default 100), most
// queried first, stores the answers in base_cf, re-categorises those domains and their rollup days.
// Usage: cloudflare.php [--dry-run]   (--dry-run prints the bases it would send, no API call)
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use App\{Categoriser, Cloudflare, Db, Job};

$opts = getopt('', ['dry-run']);
if (env('CF_ENABLED', '0') !== '1') {
    exit(0);
}
if (!Job::lockOrSkip('cloudflare')) {
    Job::log('cloudflare', 'previous run still going, skipped');
    exit(0);
}
$cf = Cloudflare::fromEnv();
if ($cf === null) {
    Job::log('cloudflare', 'CF_ENABLED=1 but CF_ACCOUNT_ID (32 hex chars) or CF_API_TOKEN is missing, skipped');
    exit(1);
}
if (!is_file(Db::path())) {
    exit(0);
}
$t0 = microtime(true);
$pdo = Db::open();
$batch = max(1, min(1000, (int) env('CF_BATCH', '100')));
$recheck = max(1, (int) env('CF_RECHECK_DAYS', '90'));
$bases = Cloudflare::pick($pdo, $batch, $recheck);
if (isset($opts['dry-run'])) {
    echo implode("\n", $bases), $bases ? "\n" : '';
    exit(0);
}
if (!$bases) {
    exit(0);   // nothing left; picks up again when new unmatched domains arrive
}

try {
    $results = $cf->lookup($bases);
} catch (Throwable $e) {
    Db::setMeta($pdo, 'cf_error', date('c') . ' ' . $e->getMessage());
    Job::log('cloudflare', sprintf('FAILED after %d requests: %s', $cf->requests, $e->getMessage()));
    exit(1);
}

Job::lockDb();
[$changed, $days] = Cloudflare::apply($pdo, new Categoriser($pdo), $results);
$found = count(array_filter($results, static fn ($r) => Cloudflare::classify($r)['app_category_id'] !== null));
Db::setMeta($pdo, 'cf_error', null);
Db::setMeta($pdo, 'cf_ok_at', (string) time());
Job::log('cloudflare', sprintf('ok sent=%d categorised=%d domains_changed=%d rollup_days=%d requests=%d in %.1fs',
    count($bases), $found, $changed, $days, $cf->requests, microtime(true) - $t0));
