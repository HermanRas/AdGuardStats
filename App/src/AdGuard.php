<?php
declare(strict_types=1);

namespace App;

/** AdGuard Home query log API client, plus the pure helpers that turn a log entry into a row. */
final class AdGuard
{
    /** Plan section 3. Anything not listed falls back by prefix: Filtered* = Block, else Default. */
    public const STATUS = [
        'NotFilteredNotFound' => 'D',
        'NotFilteredError' => 'D',
        'NotFilteredAllowList' => 'A',
        'FilteredBlackList' => 'B',
        'FilteredBlockedService' => 'B',
        'FilteredParental' => 'B',
        'FilteredSafeBrowsing' => 'B',
        'FilteredInvalid' => 'B',
        'FilteredSafeSearch' => 'D',
        'Rewrite' => 'D',
        'RewriteEtcHosts' => 'D',
        'RewriteRule' => 'D',
    ];

    public function __construct(private string $url, private string $user, private string $pass, private int $timeout = 30)
    {
        $this->url = rtrim($url, '/');
    }

    public static function fromEnv(): ?self
    {
        $url = env('ADGUARD_URL');
        return $url === '' ? null : new self($url, env('ADGUARD_USER'), env('ADGUARD_PASS'));
    }

    public static function statusFor(string $reason): string
    {
        return self::STATUS[$reason] ?? (str_starts_with($reason, 'Filtered') ? 'B' : 'D');
    }

    /** One page, newest first. @return array{data: list<array>, oldest: string} */
    public function page(?string $olderThan, int $limit = 500): array
    {
        $q = ['limit' => $limit];
        if ($olderThan !== null && $olderThan !== '') {
            $q['older_than'] = $olderThan;
        }
        $ch = curl_init($this->url . '/control/querylog?' . http_build_query($q));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $this->user . ':' . $this->pass,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        if ($body === false || $code !== 200) {
            throw new \RuntimeException("AdGuard querylog: HTTP $code $err");
        }
        $json = json_decode((string) $body, true);
        if (!is_array($json) || !isset($json['data']) || !is_array($json['data'])) {
            throw new \RuntimeException('AdGuard querylog: unexpected response');
        }
        return ['data' => $json['data'], 'oldest' => (string) ($json['oldest'] ?? '')];
    }

    /** RFC 3339 with up to nanoseconds ("2026-11-16T20:36:22.458309547Z") to integer nanoseconds. */
    public static function parseTime(string $t): int
    {
        if (!preg_match('/^(\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d)(?:\.(\d{1,9}))?\d*(Z|[+-]\d\d:\d\d)$/', $t, $m)) {
            throw new \InvalidArgumentException("bad time '$t'");
        }
        $sec = (new \DateTimeImmutable($m[1] . ($m[3] === 'Z' ? '+00:00' : $m[3])))->getTimestamp();
        return $sec * 1_000_000_000 + (int) str_pad($m[2] ?? '', 9, '0');
    }

    /** A query log entry to the fields we store, or null if it can't be used. */
    public static function normalise(array $e): ?array
    {
        $name = Psl::normalise((string) ($e['question']['name'] ?? ''));
        $ip = (string) ($e['client'] ?? '');
        if ($ip === '' || !isset($e['time'])) {
            return null;
        }
        $tsNs = self::parseTime((string) $e['time']);
        $clientId = (string) ($e['client_id'] ?? '');
        $infoName = trim((string) ($e['client_info']['name'] ?? ''));
        $reason = (string) ($e['reason'] ?? 'NotFilteredNotFound');
        $rule = $e['rules'][0]['text'] ?? ($e['rule'] ?? null);
        $filterId = $e['rules'][0]['filter_list_id'] ?? ($e['filterId'] ?? null);
        $proto = (string) ($e['client_proto'] ?? '');

        return [
            'ts_ns' => $tsNs,
            'ts' => intdiv($tsNs, 1_000_000_000),
            'fqdn' => $name === '' ? '.' : $name,
            'qtype' => (string) ($e['question']['type'] ?? '?'),
            'proto' => $proto === '' ? 'dns' : $proto,
            'ckey' => $clientId !== '' ? $clientId : $ip,
            'ip' => $ip,
            'cname' => $infoName !== '' ? $infoName : ($clientId !== '' ? $clientId : $ip),
            'named' => $infoName !== '' || $clientId !== '',
            'reason' => $reason,
            'status' => self::statusFor($reason),
            'rule' => $rule === null || $rule === '' ? null : (string) $rule,
            'filter_id' => $filterId === null ? null : (int) $filterId,
            'elapsed_ms' => isset($e['elapsedMs']) ? round((float) $e['elapsedMs'], 3) : null,
        ];
    }
}
