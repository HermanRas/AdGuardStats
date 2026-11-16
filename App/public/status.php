<?php
// Status: ingest, lists, Cloudflare, database size and coverage. Server-rendered, read-only.
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use App\{Categories, Db, Http};

Http::guard();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
$page = 'status';

$e = [Http::class, 'e'];
$ago = static function (?string $ts): string {
    if ($ts === null || $ts === '' || (int) $ts === 0) {
        return 'never';
    }
    $s = time() - (int) $ts;
    $rel = $s < 90 ? "$s s" : ($s < 5400 ? round($s / 60) . ' min' : ($s < 172800 ? round($s / 3600) . ' h' : round($s / 86400) . ' d'));
    return date('Y-m-d H:i', (int) $ts) . " ($rel ago)";
};
$n = static fn ($v) => number_format((int) $v);

$ready = is_file(Db::path());
if ($ready) {
    $pdo = Db::open();
    $meta = $pdo->query('SELECT key, value FROM meta')->fetchAll(PDO::FETCH_KEY_PAIR);
    $count = static fn (string $sql) => (int) $pdo->query($sql)->fetchColumn();
    $rows = [
        'queries' => $count('SELECT count(*) FROM queries'),
        'domains' => $count('SELECT count(*) FROM domains'),
        'clients' => $count('SELECT count(*) FROM clients'),
        'list_domains' => $count('SELECT count(*) FROM list_domains'),
        'overrides' => $count('SELECT count(*) FROM overrides'),
    ];
    $span = $pdo->query('SELECT min(ts) AS lo, max(ts) AS hi FROM queries')->fetch();
    $dbBytes = array_sum(array_map(static fn ($f) => is_file($f) ? filesize($f) : 0, [Db::path(), Db::path() . '-wal', Db::path() . '-shm']));
    $weekAgo = date('Y-m-d', strtotime('-6 days'));
    $st = $pdo->prepare('SELECT sum(n) FILTER (WHERE category_id = ?) AS u, sum(n) AS t FROM daily WHERE day >= ?');
    $st->execute([Categories::UNCATEGORISED, $weekAgo]);
    $unc = $st->fetch();
    $bySource = $pdo->query("SELECT CASE WHEN instr(category_source, ':') THEN substr(category_source, 1, instr(category_source, ':') - 1)
        ELSE category_source END AS src, count(*) AS n FROM domains GROUP BY src ORDER BY n DESC")->fetchAll();
    $listCounts = json_decode($meta['lists_counts'] ?? '[]', true) ?: [];
    $cf = ['checked' => $count('SELECT count(*) FROM base_cf'),
        'remaining' => $count("SELECT count(DISTINCT base) FROM domains WHERE category_id = " . Categories::UNCATEGORISED
            . ' AND base NOT IN (SELECT base FROM base_cf)')];
}
$ingestAge = $ready ? time() - max((int) ($meta['ingest_ok_at'] ?? 0), (int) ($meta['ingest_progress_at'] ?? 0)) : PHP_INT_MAX;
require __DIR__ . '/../src/views/header.php';
?>
<main class="wrap">
<?php if (!$ready): ?>
  <div class="card"><p>The database has not been created yet. It appears a few seconds after the container starts.</p></div>
<?php else: ?>
  <div class="status-grid">
    <section class="card">
      <h2>Ingest</h2>
      <dl class="kv">
        <dt>Last good run</dt><dd class="<?= $ingestAge <= 1800 ? 'ok' : 'bad' ?>"><?= $e($ago($meta['ingest_ok_at'] ?? null)) ?></dd>
        <?php if (isset($meta['ingest_pending_top_ns'])): ?>
        <dt>Backfill</dt><dd>in progress, back to <?= $e($meta['ingest_pending_older'] ?? '?') ?></dd>
        <?php endif; ?>
        <dt>Newest query</dt><dd><?= $e($ago($span['hi'] === null ? null : (string) $span['hi'])) ?></dd>
        <dt>Oldest query</dt><dd><?= $span['lo'] === null ? '–' : $e(date('Y-m-d H:i', (int) $span['lo'])) ?></dd>
        <dt>AdGuard</dt><dd><?= $e(env('ADGUARD_URL', 'not set')) ?></dd>
        <dt>Retention</dt><dd><?= (int) env('RETENTION_DAYS', '180') ?> days of raw queries; daily totals kept</dd>
      </dl>
    </section>
    <section class="card">
      <h2>Database</h2>
      <dl class="kv">
        <dt>Size</dt><dd><?= $e(number_format($dbBytes / 1048576, 1)) ?> MB</dd>
        <dt>Schema</dt><dd>v<?= (int) ($meta['schema_version'] ?? 0) ?></dd>
        <?php foreach ($rows as $k => $v): ?>
        <dt><?= $e(str_replace('_', ' ', ucfirst($k))) ?></dt><dd><?= $n($v) ?></dd>
        <?php endforeach; ?>
        <dt>Last prune</dt><dd><?= $e($ago($meta['prune_ok_at'] ?? null)) ?></dd>
      </dl>
    </section>
    <section class="card">
      <h2>Coverage</h2>
      <dl class="kv">
        <dt>Uncategorised, last 7 days</dt>
        <dd><?= $unc['t'] ? $e(number_format(100 * $unc['u'] / $unc['t'], 1)) . '% of ' . $n($unc['t']) . ' queries' : '–' ?></dd>
      </dl>
      <table class="plain" style="margin-top:12px">
        <tr><th>Domains categorised by</th><th class="n">Domains</th></tr>
        <?php foreach ($bySource as $r): ?>
        <tr><td><?= $e($r['src']) ?></td><td class="n"><?= $n($r['n']) ?></td></tr>
        <?php endforeach; ?>
      </table>
    </section>
    <section class="card">
      <h2>Category lists</h2>
      <dl class="kv">
        <dt>Loaded</dt><dd><?= $e($ago($meta['lists_loaded_at'] ?? null)) ?></dd>
        <dt>Last update job</dt><dd><?= $e($ago($meta['lists_ok_at'] ?? null)) ?></dd>
        <?php if (!empty($meta['lists_error'])): ?>
        <dt>Last error</dt><dd class="bad"><?= $e($meta['lists_error']) ?></dd>
        <?php endif; ?>
        <dt>PSL</dt><dd><?= is_file(App\Psl::refreshedPath()) ? 'refreshed ' . $e(date('Y-m-d', filemtime(App\Psl::refreshedPath()))) : 'bundled copy' ?></dd>
      </dl>
      <?php if ($listCounts): ?>
      <table class="plain" style="margin-top:12px">
        <tr><th>Source list</th><th class="n">Domains</th></tr>
        <?php foreach ($listCounts as $src => $c): ?>
        <tr><td><?= $e($src) ?></td><td class="n"><?= $n($c) ?></td></tr>
        <?php endforeach; ?>
      </table>
      <?php endif; ?>
    </section>
    <section class="card">
      <h2>Cloudflare</h2>
      <dl class="kv">
        <dt>Enabled</dt><dd><?= env('CF_ENABLED', '0') === '1' ? 'yes' : 'no' ?></dd>
        <dt>Base domains sent</dt><dd><?= $n($cf['checked']) ?></dd>
        <dt>Uncategorised, unchecked</dt><dd><?= $n($cf['remaining']) ?></dd>
      </dl>
    </section>
  </div>
<?php endif; ?>
</main>
</body>
</html>
