<?php
// One page of the query log, newest first, for the current filters. Keyset paging:
// pass the previous answer's `next_before` as `before`.
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use App\{Categories, Http};

Http::guard();
$f = Http::filters();
$limit = 50;
[$where, $p] = $f->queryWhere();

$before = $_GET['before'] ?? '';
if (is_string($before) && $before !== '') {
    if (!preg_match('/^(\d{1,19})-(\d{1,19})$/', $before, $m)) {
        Http::json(['error' => 'before must be a cursor from a previous page'], 400);
    }
    $where .= ' AND (q.ts_ns < ? OR (q.ts_ns = ? AND q.id < ?))';
    array_push($p, (int) $m[1], (int) $m[1], (int) $m[2]);
}

$pdo = Http::db();
$st = $pdo->prepare("SELECT q.id, q.ts_ns, q.ts, q.qtype, q.proto, q.status, q.reason, q.rule, q.filter_id,
        q.elapsed_ms, d.fqdn, d.base, d.category_id, d.category_source, c.id AS client_id, c.name AS client_name, c.ip AS client_ip
    FROM queries q JOIN domains d ON d.id = q.domain_id JOIN clients c ON c.id = q.client_id
    WHERE $where ORDER BY q.ts_ns DESC, q.id DESC LIMIT " . ($limit + 1));
$st->execute($p);
$rows = $st->fetchAll();

$more = count($rows) > $limit;
$rows = array_slice($rows, 0, $limit);
$byId = array_column(Categories::all(), null, 'id');
$out = [];
foreach ($rows as $r) {
    $c = $byId[(int) $r['category_id']] ?? $byId[Categories::UNCATEGORISED];
    $out[] = [
        'time' => date('Y-m-d H:i:s', (int) $r['ts']),
        'fqdn' => $r['fqdn'], 'base' => $r['base'], 'qtype' => $r['qtype'], 'proto' => $r['proto'],
        'client' => ['id' => (int) $r['client_id'], 'name' => $r['client_name'], 'ip' => $r['client_ip']],
        'category' => ['slug' => $c['slug'], 'name' => $c['name'], 'colour' => $c['colour'], 'source' => $r['category_source']],
        'status' => $r['status'], 'reason' => $r['reason'], 'rule' => $r['rule'], 'filter_id' => $r['filter_id'],
        'elapsed_ms' => $r['elapsed_ms'],
    ];
}
$last = end($rows);
Http::json([
    'rows' => $out,
    'next_before' => $more && $last ? $last['ts_ns'] . '-' . $last['id'] : null,
]);
