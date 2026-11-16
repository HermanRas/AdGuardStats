<?php
// Clients for the Client selector, most recently seen first.
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use App\Http;

Http::guard();
$pdo = Http::db();
$rows = $pdo->query('SELECT id, name, ip, last_seen FROM clients ORDER BY last_seen DESC')->fetchAll();
Http::json(array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'ip' => $r['ip'],
    'last_seen' => date('Y-m-d H:i', (int) $r['last_seen'])], $rows));
