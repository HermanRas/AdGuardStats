<?php
declare(strict_types=1);

namespace App;

use PDO;

/**
 * Optional Cloudflare Intel enrichment (Plan section 4): looks up base domains that no override or list
 * matched, and caches the answer in `base_cf`. Categoriser uses it as the last resort before
 * Uncategorised. Only base domains are ever sent; never clients or timestamps.
 *
 * API: GET /accounts/{id}/intel/domain/bulk?domain=a&domain=b, token permission "Intel Read".
 */
final class Cloudflare
{
    public const API = 'https://api.cloudflare.com/client/v4';
    /** Domains per bulk request (kept small; Cloudflare does not publish the bulk limit). */
    public const CHUNK = 10;

    public int $requests = 0;

    public function __construct(private string $accountId, private string $token, private int $timeout = 30,
        private int $pauseMs = 300)
    {
    }

    public static function fromEnv(): ?self
    {
        $acct = env('CF_ACCOUNT_ID');
        $token = env('CF_API_TOKEN');
        if ($acct === '' || $token === '' || !preg_match('/^[0-9a-f]{32}$/i', $acct)) {
            return null;
        }
        return new self($acct, $token);
    }

    /**
     * Looks up base domains in bulk. Returns domain => Cloudflare result item; a domain Cloudflare
     * left out of its answer maps to [].
     * @param list<string> $domains
     * @return array<string,array>
     */
    public function lookup(array $domains): array
    {
        $out = [];
        foreach (array_chunk($domains, self::CHUNK) as $i => $chunk) {
            if ($i > 0) {
                usleep($this->pauseMs * 1000);   // throttle well under the API rate limit
            }
            $qs = implode('&', array_map(static fn ($d) => 'domain=' . rawurlencode($d), $chunk));
            $items = $this->get('/accounts/' . $this->accountId . '/intel/domain/bulk?' . $qs);
            foreach ($chunk as $d) {
                $out[$d] = [];
            }
            foreach ($items as $item) {
                $d = strtolower(rtrim((string) ($item['domain'] ?? ''), '.'));
                if (isset($out[$d])) {
                    $out[$d] = $item;
                }
            }
        }
        return $out;
    }

    private function get(string $path): array
    {
        $this->requests++;
        $ch = curl_init(self::API . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->token, 'Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        $json = is_string($body) ? json_decode($body, true) : null;
        if ($code !== 200 || !is_array($json) || empty($json['success']) || !is_array($json['result'] ?? null)) {
            $msg = is_array($json) && isset($json['errors'][0]['message'])
                ? $json['errors'][0]['code'] . ' ' . $json['errors'][0]['message'] : $err;
            throw new \RuntimeException(trim("Cloudflare Intel: HTTP $code $msg"));
        }
        return $json['result'];
    }

    /**
     * Picks the app category for one Cloudflare result. Every risk type and content category (own, then
     * inherited) is a candidate; the one listed earliest in config/categories.php wins, so security
     * risks beat content.
     * @return array{app_category_id:?int, source:?string, categories:list<string>}
     */
    public static function classify(array $item): array
    {
        $names = [];
        foreach (['risk_types', 'content_categories', 'inherited_risk_types', 'inherited_content_categories'] as $k) {
            foreach ((array) ($item[$k] ?? []) as $c) {
                if (is_array($c) && isset($c['name']) && is_string($c['name'])) {
                    $names[] = $c['name'];
                }
            }
        }
        return self::classifyNames(array_values(array_unique($names)));
    }

    /**
     * Maps stored Cloudflare category names to an app category: the name listed earliest in
     * config/categories.php wins.
     * @param list<string> $names
     * @return array{app_category_id:?int, source:?string, categories:list<string>}
     */
    public static function classifyNames(array $names): array
    {
        foreach (Categories::mapping('cloudflare') as $name => $cid) {
            if (in_array($name, $names, true)) {
                return ['app_category_id' => $cid, 'source' => 'cloudflare:' . $name, 'categories' => $names];
            }
        }
        return ['app_category_id' => null, 'source' => null, 'categories' => $names];
    }

    /**
     * Re-applies the current mapping to every cached answer (after config/categories.php changes), so
     * domains already looked up follow the new mapping without asking Cloudflare again. Returns how
     * many cached answers changed. Run inside the caller's transaction.
     */
    public static function reclassifyCache(PDO $pdo): int
    {
        $upd = $pdo->prepare('UPDATE base_cf SET cf_raw = ? WHERE base = ?');
        $changed = 0;
        foreach ($pdo->query('SELECT base, cf_raw FROM base_cf')->fetchAll() as $r) {
            $old = json_decode((string) $r['cf_raw'], true);
            if (!is_array($old)) {
                continue;
            }
            $new = self::classifyNames(array_values(array_filter((array) ($old['categories'] ?? []), 'is_string')));
            if ($new['app_category_id'] !== ($old['app_category_id'] ?? null) || $new['source'] !== ($old['source'] ?? null)) {
                $upd->execute([json_encode($new, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), $r['base']]);
                $changed++;
            }
        }
        return $changed;
    }

    /** A base domain worth sending: a public-looking name, not an IP, reverse zone or local name. */
    public static function eligible(string $base): bool
    {
        return preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]*[a-z0-9]$/', $base) === 1
            && !preg_match('/\.(arpa|local|lan|home|internal|localdomain|localhost|test|invalid|example|corp|intranet|private)$/', $base);
    }

    /**
     * Base domains to look up next: every subdomain is unmatched by overrides and lists (Uncategorised,
     * or only Cloudflare-categorised), never checked or checked more than $recheckDays ago. Most queried
     * in the last 30 days first.
     * @return list<string>
     */
    public static function pick(PDO $pdo, int $limit, int $recheckDays, ?int $now = null): array
    {
        $now ??= time();
        $st = $pdo->prepare("WITH recent AS (
                SELECT domain_id, count(*) AS n FROM queries WHERE ts >= :since GROUP BY domain_id)
            SELECT d.base, coalesce(sum(r.n), 0) AS n
            FROM domains d LEFT JOIN recent r ON r.domain_id = d.id
            LEFT JOIN base_cf c ON c.base = d.base
            WHERE c.base IS NULL OR c.cf_checked_at < :stale
            GROUP BY d.base
            HAVING sum(d.category_source <> 'none' AND d.category_source NOT LIKE 'cloudflare:%') = 0
            ORDER BY n DESC, d.base");
        $st->execute([':since' => $now - 30 * 86400, ':stale' => $now - $recheckDays * 86400]);
        $out = [];
        while (count($out) < $limit && ($r = $st->fetch())) {
            if (self::eligible((string) $r['base'])) {
                $out[] = (string) $r['base'];
            }
        }
        $st->closeCursor();
        return $out;
    }

    /** How many eligible base domains are still waiting (same rule as pick(), no limit). */
    public static function remaining(PDO $pdo, int $recheckDays, ?int $now = null): int
    {
        return count(self::pick($pdo, PHP_INT_MAX, $recheckDays, $now));
    }

    /**
     * Stores looked-up results, re-categorises the domains under those bases and rebuilds the rollup
     * for the days they appear in. Run under the db lock. Returns [domains changed, days rebuilt].
     * @param array<string,array> $results base => Cloudflare item ([] = nothing known)
     * @return array{0:int,1:int}
     */
    public static function apply(PDO $pdo, Categoriser $cat, array $results, ?int $now = null): array
    {
        $now ??= time();
        $up = $pdo->prepare('INSERT INTO base_cf (base, cf_checked_at, cf_raw) VALUES (?, ?, ?)
            ON CONFLICT (base) DO UPDATE SET cf_checked_at = excluded.cf_checked_at, cf_raw = excluded.cf_raw');
        $before = $pdo->prepare('SELECT id, category_id FROM domains WHERE base = ?');
        $changedIds = [];
        $pdo->beginTransaction();
        try {
            foreach ($results as $base => $item) {
                $raw = self::classify($item);
                $up->execute([$base, $now, json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)]);
                $before->execute([$base]);
                $old = $before->fetchAll(PDO::FETCH_KEY_PAIR);
                if ($cat->recategoriseAll($base) > 0) {
                    $before->execute([$base]);
                    foreach ($before->fetchAll(PDO::FETCH_KEY_PAIR) as $id => $cid) {
                        if ((int) $cid !== (int) ($old[$id] ?? -1)) {
                            $changedIds[] = (int) $id;
                        }
                    }
                }
            }
            $days = self::daysFor($pdo, $changedIds);
            Rollup::rebuildDays($pdo, $days);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return [count($changedIds), count($days)];
    }

    /** Local days (Y-m-d) that hold queries for these domains, from 15-minute buckets (exact for any TZ offset). */
    private static function daysFor(PDO $pdo, array $domainIds): array
    {
        $days = [];
        foreach (array_chunk($domainIds, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $st = $pdo->prepare("SELECT DISTINCT ts / 900 FROM queries WHERE domain_id IN ($in)");
            $st->execute($chunk);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $b) {
                $days[Rollup::dayOf((int) $b * 900)] = true;
                $days[Rollup::dayOf((int) $b * 900 + 899)] = true;
            }
        }
        $days = array_keys($days);
        sort($days);
        return $days;
    }
}
