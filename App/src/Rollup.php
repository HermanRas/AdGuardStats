<?php
declare(strict_types=1);

namespace App;

use PDO;

/** The `daily` rollup: counts per local day, client, category and status. Charts read only this. */
final class Rollup
{
    public static function dayOf(int $ts): string
    {
        return date('Y-m-d', $ts);
    }

    /** [start, end) unix seconds of a local day. */
    public static function dayBounds(string $day): array
    {
        $tz = new \DateTimeZone(date_default_timezone_get());
        $start = new \DateTimeImmutable($day . ' 00:00:00', $tz);
        return [$start->getTimestamp(), $start->modify('+1 day')->getTimestamp()];
    }

    /** Rebuilds the given days (Y-m-d) from `queries`. Run inside the caller's transaction if any. */
    public static function rebuildDays(PDO $pdo, array $days): void
    {
        $del = $pdo->prepare('DELETE FROM daily WHERE day = ?');
        $ins = $pdo->prepare('INSERT INTO daily (day, client_id, category_id, status, n)
            SELECT ?, q.client_id, d.category_id, q.status, count(*)
            FROM queries q JOIN domains d ON d.id = q.domain_id
            WHERE q.ts >= ? AND q.ts < ?
            GROUP BY q.client_id, d.category_id, q.status');
        foreach (array_unique($days) as $day) {
            [$s, $e] = self::dayBounds($day);
            $del->execute([$day]);
            $ins->execute([$day, $s, $e]);
        }
    }

    /**
     * Rebuilds every day that still has raw queries. Older days (raw rows pruned) keep their rollup,
     * so the history survives retention.
     */
    public static function rebuildAll(PDO $pdo): int
    {
        $r = $pdo->query('SELECT min(ts) AS lo, max(ts) AS hi FROM queries')->fetch();
        if ($r['lo'] === null) {
            return 0;
        }
        $days = [];
        for ($d = self::dayOf((int) $r['lo']); $d <= self::dayOf((int) $r['hi']); $d = date('Y-m-d', strtotime("$d +1 day"))) {
            $days[] = $d;
        }
        self::rebuildDays($pdo, $days);
        return count($days);
    }
}
