<?php
// Dashboard + query log. The page is a static shell; assets/app.js fills it from /api/* using the URL's
// filters (?preset=week | ?from=…&to=…, &client=, &status=, &category=, &q=).
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use App\{Categories, Http};

Http::guard();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
$cats = array_map(static fn ($c) => ['slug' => $c['slug'], 'name' => $c['name'], 'colour' => $c['colour']], Categories::all());
require __DIR__ . '/../src/views/header.php';
?>
<main class="wrap" id="app">
  <section class="tiles" aria-label="Totals">
    <div class="tile"><span class="tile-label">Total queries</span><span class="tile-value" id="t-total">–</span></div>
    <div class="tile tile-A"><span class="tile-label"><i class="dot dot-A"></i>Allow</span><span class="tile-value" id="t-A">–</span><span class="tile-sub" id="t-A-n"></span></div>
    <div class="tile tile-B"><span class="tile-label"><i class="dot dot-B"></i>Block</span><span class="tile-value" id="t-B">–</span><span class="tile-sub" id="t-B-n"></span></div>
    <div class="tile tile-D"><span class="tile-label"><i class="dot dot-D"></i>Default</span><span class="tile-value" id="t-D">–</span><span class="tile-sub" id="t-D-n"></span></div>
  </section>

  <section class="charts">
    <div class="card card-wide">
      <h2>Queries per day <small>click a segment to filter by day and status</small></h2>
      <div class="chart-box chart-bars"><canvas id="c-days" aria-label="Queries per day by status" role="img"></canvas></div>
    </div>
    <div class="card">
      <h2>Status <small>click to filter</small></h2>
      <div class="chart-box chart-round"><canvas id="c-status" aria-label="Share by status" role="img"></canvas></div>
      <ul class="legend" id="l-status"></ul>
    </div>
    <div class="card">
      <h2>Categories <small>click to filter</small></h2>
      <div class="chart-box chart-round"><canvas id="c-cats" aria-label="Share by category" role="img"></canvas></div>
      <ul class="legend" id="l-cats"></ul>
    </div>
  </section>

  <section class="card log-card">
    <div class="log-head">
      <h2>Query log</h2>
      <div class="chips" id="chips"></div>
      <input type="search" id="q" placeholder="Search domain" maxlength="100" autocomplete="off" spellcheck="false" aria-label="Search domain">
    </div>
    <div class="table-scroll">
      <table class="log">
        <thead><tr><th>Date-Time</th><th>Domain</th><th>Client</th><th>Category</th><th>Status</th></tr></thead>
        <tbody id="log-body"></tbody>
      </table>
    </div>
    <p class="log-foot"><span id="log-info"></span> <button type="button" id="more" class="btn" hidden>Load more</button></p>
  </section>
</main>
<script type="application/json" id="categories"><?= json_encode($cats, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="/vendor/chart.umd.min.js"></script>
<script src="/assets/app.js?v=<?= filemtime(__DIR__ . '/assets/app.js') ?>"></script>
</body>
</html>
