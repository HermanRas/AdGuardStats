<?php
declare(strict_types=1);

namespace App;

use PDO;

/**
 * Copies AdGuard's query log into SQLite (Plan section 6).
 *
 * The API pages newest-first. State in `meta`:
 *   ingest_cursor_ns         everything from the start of the log up to here is stored
 *   ingest_pending_top_ns    a walk is in progress: [pending_older_than .. pending_top] is stored
 *   ingest_pending_older     the AdGuard `oldest` value to resume that walk from
 * so a long first backfill that is interrupted (AdGuard's weekly restart) resumes where it stopped
 * instead of starting again. Re-reading overlapping entries is harmless: inserts are OR IGNORE.
 */
final class Ingest
{
    public const PAGES_PER_COMMIT = 10;

    /** @var array<string,int> */ private array $domainIds = [];
    /** @var array<string,int> */ private array $clientIds = [];
    private \PDOStatement $selDomain;
    private \PDOStatement $insDomain;
    private \PDOStatement $upClient;
    private \PDOStatement $insQuery;

    public int $pages = 0;
    public int $seen = 0;
    public int $inserted = 0;

    public function __construct(private PDO $pdo, private Psl $psl, private Categoriser $cat)
    {
        $this->selDomain = $pdo->prepare('SELECT id FROM domains WHERE fqdn = ?');
        $this->insDomain = $pdo->prepare('INSERT INTO domains (fqdn, base, category_id, category_source, first_seen) VALUES (?, ?, ?, ?, ?)');
        $this->upClient = $pdo->prepare('INSERT INTO clients (ckey, ip, name, first_seen, last_seen) VALUES (:k, :ip, :name, :t, :t)
            ON CONFLICT(ckey) DO UPDATE SET
              ip   = CASE WHEN excluded.last_seen >= clients.last_seen THEN excluded.ip ELSE clients.ip END,
              name = CASE WHEN :named AND excluded.last_seen >= clients.last_seen THEN excluded.name ELSE clients.name END,
              first_seen = min(clients.first_seen, excluded.first_seen),
              last_seen  = max(clients.last_seen, excluded.last_seen)
            RETURNING id');
        $this->insQuery = $pdo->prepare('INSERT OR IGNORE INTO queries
            (ts, ts_ns, domain_id, client_id, qtype, proto, status, reason, rule, filter_id, elapsed_ms)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    }

    /** Stores raw API entries. Call inside a transaction. Returns the local days that gained rows. */
    public function store(array $entries): array
    {
        $days = [];
        $latestClient = [];
        foreach ($entries as $e) {
            $r = AdGuard::normalise($e);
            if ($r === null) {
                continue;
            }
            $this->seen++;
            $did = $this->domainId($r['fqdn'], $r['ts']);
            // One client upsert per client per batch, using its newest entry.
            if (!isset($latestClient[$r['ckey']]) || $r['ts'] > $latestClient[$r['ckey']]['ts']) {
                $latestClient[$r['ckey']] = $r;
            }
            $cid = $this->clientIds[$r['ckey']] ??= $this->upsertClient($r);
            $this->insQuery->execute([$r['ts'], $r['ts_ns'], $did, $cid, $r['qtype'], $r['proto'], $r['status'],
                $r['reason'], $r['rule'], $r['filter_id'], $r['elapsed_ms']]);
            if ($this->insQuery->rowCount() > 0) {
                $this->inserted++;
                $days[Rollup::dayOf($r['ts'])] = true;
            }
        }
        foreach ($latestClient as $r) {
            $this->upsertClient($r);
        }
        return array_keys($days);
    }

    private function domainId(string $fqdn, int $ts): int
    {
        if (isset($this->domainIds[$fqdn])) {
            return $this->domainIds[$fqdn];
        }
        $this->selDomain->execute([$fqdn]);
        $id = $this->selDomain->fetchColumn();
        $this->selDomain->closeCursor();
        if ($id === false) {
            $base = $this->psl->base($fqdn);
            [$cid, $src] = $this->cat->categorise($fqdn, $base);
            $this->insDomain->execute([$fqdn, $base, $cid, $src, $ts]);
            $id = (int) $this->pdo->lastInsertId();
        }
        if (count($this->domainIds) > 200000) {
            $this->domainIds = [];
        }
        return $this->domainIds[$fqdn] = (int) $id;
    }

    private function upsertClient(array $r): int
    {
        $this->upClient->execute([':k' => $r['ckey'], ':ip' => $r['ip'], ':name' => $r['cname'], ':t' => $r['ts'], ':named' => $r['named'] ? 1 : 0]);
        $id = (int) $this->upClient->fetchColumn();
        $this->upClient->closeCursor();
        return $id;
    }

    /** Stores entries and rolls up the touched days in one transaction. */
    public function commitBatch(array $entries, array $meta = []): void
    {
        $this->pdo->beginTransaction();
        try {
            Rollup::rebuildDays($this->pdo, $this->store($entries));
            foreach ($meta as $k => $v) {
                Db::setMeta($this->pdo, $k, $v === null ? null : (string) $v);
            }
            Db::setMeta($this->pdo, 'ingest_progress_at', (string) time());
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** One full ingest run against the API. */
    /** @param AdGuard|object $ag anything with page(?string $olderThan, int $limit): array */
    public function run(object $ag, int $pageSize = 500): void
    {
        $cursor = (int) (Db::meta($this->pdo, 'ingest_cursor_ns') ?? 0);
        $pendTop = Db::meta($this->pdo, 'ingest_pending_top_ns');
        $pendOlder = Db::meta($this->pdo, 'ingest_pending_older');
        $newTop = null;

        if ($pendTop !== null) {
            // Finish what's new above the interrupted walk, then resume the walk itself.
            $newTop = $this->walk($ag, null, (int) $pendTop, $pageSize, null);
            $this->pdo->beginTransaction();
            Db::setMeta($this->pdo, 'ingest_pending_top_ns', (string) max((int) $pendTop, $newTop ?? 0));
            $this->pdo->commit();
            $newTop = max((int) $pendTop, $newTop ?? 0);
            $this->walk($ag, $pendOlder, $cursor, $pageSize, $newTop);
        } else {
            $newTop = $this->walk($ag, null, $cursor, $pageSize, 0);
        }

        $this->pdo->beginTransaction();
        if ($newTop !== null && $newTop > $cursor) {
            Db::setMeta($this->pdo, 'ingest_cursor_ns', (string) $newTop);
        }
        Db::setMeta($this->pdo, 'ingest_pending_top_ns', null);
        Db::setMeta($this->pdo, 'ingest_pending_older', null);
        Db::setMeta($this->pdo, 'ingest_ok_at', (string) time());
        $this->pdo->commit();
    }

    /**
     * Walks pages from $olderThan (null = newest) down to entries older than $stopNs.
     * $trackTop: null = don't record progress; 0 = record progress, top taken from the first entry;
     * >0 = record progress with this top. Returns the newest ts_ns seen (null if the log is empty).
     */
    private function walk(object $ag, ?string $olderThan, int $stopNs, int $pageSize, ?int $trackTop): ?int
    {
        $top = null;
        $buf = [];
        $pagesInBuf = 0;
        $done = false;
        while (!$done) {
            $page = $ag->page($olderThan, $pageSize);
            $this->pages++;
            foreach ($page['data'] as $e) {
                $ns = AdGuard::parseTime((string) ($e['time'] ?? '1970-01-01T00:00:00Z'));
                $top ??= $ns;
                if ($ns < $stopNs) {
                    $done = true;
                    break;
                }
                $buf[] = $e;
            }
            if ($page['data'] === [] || $page['oldest'] === '' || $page['oldest'] === $olderThan) {
                $done = true;
            }
            $olderThan = $page['oldest'];
            $pagesInBuf++;
            if ($done || $pagesInBuf >= self::PAGES_PER_COMMIT) {
                $meta = [];
                if ($trackTop !== null && !$done) {
                    $meta = ['ingest_pending_top_ns' => $trackTop ?: $top, 'ingest_pending_older' => $olderThan];
                }
                if ($buf !== [] || $meta !== []) {
                    $this->commitBatch($buf, $meta);
                }
                $buf = [];
                $pagesInBuf = 0;
            }
        }
        return $top;
    }
}
