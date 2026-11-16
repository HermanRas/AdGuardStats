<?php
declare(strict_types=1);

namespace App;

/**
 * The dashboard's filter state, parsed and validated from the query string (Plan section 7).
 * Every endpoint builds its WHERE clause from here; nothing else touches user input.
 *   preset=today|week|month  or  from=YYYY-MM-DD&to=YYYY-MM-DD
 *   client=<id>  status=A|B|D  category=<slug>  q=<domain text, log only>
 */
final class Filters
{
    public const MAX_DAYS = 400;

    public string $from;
    public string $to;
    public ?string $preset = null;
    public ?int $client = null;
    public ?string $status = null;
    public ?int $category = null;
    public ?string $categorySlug = null;
    public ?string $q = null;

    /** @throws \InvalidArgumentException on any bad parameter (endpoints answer 400) */
    public static function fromQuery(array $get, ?string $today = null): self
    {
        $f = new self();
        $today ??= date('Y-m-d');
        $s = static fn (string $k): ?string => isset($get[$k]) && is_string($get[$k]) && $get[$k] !== '' ? $get[$k] : null;

        $preset = $s('preset');
        $from = $s('from');
        $to = $s('to');
        if ($from === null && $to === null) {
            $preset ??= 'week';
        }
        if ($preset !== null) {
            $days = ['today' => 0, 'week' => 6, 'month' => 29][$preset]
                ?? throw new \InvalidArgumentException('preset must be today, week or month');
            $f->preset = $preset;
            $f->to = $today;
            $f->from = date('Y-m-d', strtotime("$today -$days days"));
        } else {
            $f->from = self::date($from ?? $to, 'from');
            $f->to = self::date($to ?? $from, 'to');
            if ($f->from > $f->to) {
                throw new \InvalidArgumentException('from is after to');
            }
            if ((strtotime($f->to) - strtotime($f->from)) / 86400 >= self::MAX_DAYS) {
                throw new \InvalidArgumentException('range is longer than ' . self::MAX_DAYS . ' days');
            }
        }

        if (($c = $s('client')) !== null) {
            if (!ctype_digit($c) || strlen($c) > 9) {
                throw new \InvalidArgumentException('client must be a client id');
            }
            $f->client = (int) $c;
        }
        if (($st = $s('status')) !== null) {
            if (!in_array($st, ['A', 'B', 'D'], true)) {
                throw new \InvalidArgumentException('status must be A, B or D');
            }
            $f->status = $st;
        }
        if (($cat = $s('category')) !== null) {
            $f->category = Categories::idForSlug($cat) ?? throw new \InvalidArgumentException('unknown category');
            $f->categorySlug = $cat;
        }
        if (($q = $s('q')) !== null) {
            $q = strtolower(trim($q));
            if (strlen($q) > 100 || !preg_match('/^[a-z0-9._*-]*$/', $q)) {
                throw new \InvalidArgumentException('q may only contain letters, digits, dots and dashes');
            }
            $f->q = $q === '' ? null : $q;
        }
        return $f;
    }

    private static function date(string $v, string $name): string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        if ($d === false || $d->format('Y-m-d') !== $v) {
            throw new \InvalidArgumentException("$name must be YYYY-MM-DD");
        }
        return $v;
    }

    /** Every day in the range, oldest first. */
    public function days(): array
    {
        $out = [];
        for ($d = $this->from; $d <= $this->to; $d = date('Y-m-d', strtotime("$d +1 day"))) {
            $out[] = $d;
        }
        return $out;
    }

    /** WHERE for the `daily` table (alias-free). The text search does not apply to charts. */
    public function dailyWhere(): array
    {
        $w = ['day >= ?', 'day <= ?'];
        $p = [$this->from, $this->to];
        if ($this->client !== null) { $w[] = 'client_id = ?'; $p[] = $this->client; }
        if ($this->status !== null) { $w[] = 'status = ?'; $p[] = $this->status; }
        if ($this->category !== null) { $w[] = 'category_id = ?'; $p[] = $this->category; }
        return [implode(' AND ', $w), $p];
    }

    /** WHERE for `queries q JOIN domains d`. */
    public function queryWhere(): array
    {
        [$s] = Rollup::dayBounds($this->from);
        [, $e] = Rollup::dayBounds($this->to);
        $w = ['q.ts_ns >= ?', 'q.ts_ns < ?'];
        $p = [$s * 1_000_000_000, $e * 1_000_000_000];
        if ($this->client !== null) { $w[] = 'q.client_id = ?'; $p[] = $this->client; }
        if ($this->status !== null) { $w[] = 'q.status = ?'; $p[] = $this->status; }
        if ($this->category !== null) { $w[] = 'd.category_id = ?'; $p[] = $this->category; }
        if ($this->q !== null) {
            $w[] = "q.domain_id IN (SELECT id FROM domains WHERE fqdn LIKE ? ESCAPE '\\')";
            $p[] = '%' . str_replace('*', '%', addcslashes($this->q, '%_\\')) . '%';
        }
        return [implode(' AND ', $w), $p];
    }

    /** The validated state, for echoing back to the UI. */
    public function toArray(): array
    {
        return array_filter([
            'from' => $this->from, 'to' => $this->to, 'preset' => $this->preset, 'client' => $this->client,
            'status' => $this->status, 'category' => $this->categorySlug, 'q' => $this->q,
        ], static fn ($v) => $v !== null);
    }
}
