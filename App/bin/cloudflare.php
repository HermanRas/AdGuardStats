<?php
// Every 30 min: optional Cloudflare Intel lookups for Uncategorised base domains (Plan section 4, Phase 8).
// Does nothing unless CF_ENABLED=1. The lookup itself is not built yet (Phase 8).
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use App\Job;

if (env('CF_ENABLED', '0') !== '1') {
    exit(0);
}
Job::log('cloudflare', 'CF_ENABLED=1 but Cloudflare enrichment is not implemented yet (Phase 8), skipped');
