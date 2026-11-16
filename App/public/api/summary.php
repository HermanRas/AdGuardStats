<?php
// Stat tiles, per-day series, status share and category share for the current filters (from `daily`).
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use App\{Categories, Http};

Http::guard();
$f = Http::filters();
$pdo = Http::db();
[$where, $p] = $f->dailyWhere();

$totals = ['A' => 0, 'B' => 0, 'D' => 0];
$series = array_fill_keys($f->days(), ['A' => 0, 'B' => 0, 'D' => 0]);
$st = $pdo->prepare("SELECT day, status, sum(n) AS n FROM daily WHERE $where GROUP BY day, status");
$st->execute($p);
foreach ($st as $r) {
    $series[$r['day']][$r['status']] = (int) $r['n'];
    $totals[$r['status']] += (int) $r['n'];
}

$byId = array_column(Categories::all(), null, 'id');
$st = $pdo->prepare("SELECT category_id, sum(n) AS n FROM daily WHERE $where GROUP BY category_id ORDER BY n DESC, category_id");
$st->execute($p);
$cats = [];
$other = 0;
foreach ($st->fetchAll() as $i => $r) {
    $c = $byId[(int) $r['category_id']] ?? $byId[Categories::UNCATEGORISED];
    if ($i < 12) {
        $cats[] = ['slug' => $c['slug'], 'name' => $c['name'], 'colour' => $c['colour'], 'n' => (int) $r['n']];
    } else {
        $other += (int) $r['n'];
    }
}
if ($other > 0) {
    $cats[] = ['slug' => null, 'name' => 'Other', 'colour' => '#4a4a4a', 'n' => $other];
}

Http::json([
    'filters' => $f->toArray(),
    'total' => array_sum($totals),
    'status' => $totals,
    'series' => array_map(static fn ($d, $v) => ['day' => $d] + $v, array_keys($series), $series),
    'categories' => $cats,
]);
