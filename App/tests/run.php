<?php
// Plain-PHP tests, no PHPUnit. Run from anywhere (no host PHP needed):
//   docker run --rm -v "$PWD/App":/app -w /app php:8.4-cli php tests/run.php
declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/agstats-test-' . getmypid();
mkdir($tmp);
putenv("DATA_DIR=$tmp");
putenv("DB_PATH=$tmp/t.sqlite");
putenv('TZ=Africa/Johannesburg');
putenv('LOCAL_DOMAINS=MyHome.example, bad domain!');
require __DIR__ . '/../src/bootstrap.php';

use App\{AdGuard, Categories, Categoriser, Cloudflare, Db, Filters, Ingest, Lists, Psl, Rollup};

$fails = 0;
$count = 0;
function check(string $name, mixed $got, mixed $want): void
{
    global $fails, $count;
    $count++;
    if ($got !== $want) {
        $fails++;
        fwrite(STDERR, "FAIL $name\n  got:  " . var_export($got, true) . "\n  want: " . var_export($want, true) . "\n");
    }
}
function throws(string $name, callable $fn): void
{
    try {
        $fn();
        check($name . ' throws', false, true);
    } catch (InvalidArgumentException) {
        check($name . ' throws', true, true);
    }
}

// ---- Status mapping (Plan section 3) ----
foreach (['NotFilteredNotFound' => 'D', 'NotFilteredError' => 'D', 'NotFilteredAllowList' => 'A',
    'FilteredBlackList' => 'B', 'FilteredBlockedService' => 'B', 'FilteredParental' => 'B',
    'FilteredSafeBrowsing' => 'B', 'FilteredInvalid' => 'B', 'FilteredSafeSearch' => 'D',
    'Rewrite' => 'D', 'RewriteEtcHosts' => 'D', 'RewriteRule' => 'D',
    'FilteredSomethingNew' => 'B', 'SomethingElse' => 'D'] as $reason => $st) {
    check("status $reason", AdGuard::statusFor($reason), $st);
}

// ---- Time parsing ----
check('time Z', AdGuard::parseTime('2026-11-16T20:36:22.458309547Z'), 1794861382458309547);
check('time offset', AdGuard::parseTime('2026-11-16T22:36:22.458309547+02:00'), 1794861382458309547);
check('time short fraction', AdGuard::parseTime('2026-11-16T20:36:22.5Z'), 1794861382500000000);
check('time no fraction', AdGuard::parseTime('2026-11-16T20:36:22Z'), 1794861382000000000);

// ---- PSL ----
$psl = Psl::load(Psl::bundledPath());
foreach ([
    'a.b.example.co.uk' => 'example.co.uk', 'example.co.uk' => 'example.co.uk', 'co.uk' => 'co.uk',
    'x.github.io' => 'x.github.io', 'deep.x.github.io' => 'x.github.io', 'WWW.Google.COM.' => 'google.com',
    '192.168.0.1' => '192.168.0.1', 'wpad' => 'wpad', 'printer.lan' => 'printer.lan',
    '4.2.0.192.in-addr.arpa' => 'in-addr.arpa', 'b.a.ip6.arpa' => 'ip6.arpa',
] as $in => $want) {
    check("psl $in", $psl->base($in), $want);
}
$mini = Psl::fromString("// test\nuk\nco.uk\n*.ck\n!www.ck\n");
check('psl wildcard', $mini->base('a.foo.ck'), 'a.foo.ck');
check('psl wildcard deep', $mini->base('b.a.foo.ck'), 'a.foo.ck');
check('psl exception', $mini->base('a.www.ck'), 'www.ck');
check('psl default rule', $mini->base('a.b.example'), 'b.example');

// ---- Matching levels ----
check('levels', Categoriser::levels('a.b.example.co.uk', 'example.co.uk'), ['a.b.example.co.uk', 'b.example.co.uk', 'example.co.uk']);
check('levels base', Categoriser::levels('example.co.uk', 'example.co.uk'), ['example.co.uk']);
check('levels mismatch', Categoriser::levels('foo.bar', 'baz.qux'), ['foo.bar']);

check('local domains', Categoriser::localDomains(), ['myhome.example']);

// ---- List line parsing ----
check('line plain', Lists::parseLine("Ads.Example.com.\n"), 'ads.example.com');
check('line comment', Lists::parseLine('# x'), null);
check('line wildcard', Lists::parseLine('*.example.com'), null);
check('line ip', Lists::parseLine('192.0.2.1'), null);
check('line single label', Lists::parseLine('localhost'), null);

// ---- Filters ----
$f = Filters::fromQuery([], '2026-11-16');
check('filter default week', [$f->from, $f->to, $f->preset], ['2026-11-10', '2026-11-16', 'week']);
$f = Filters::fromQuery(['preset' => 'month'], '2026-11-16');
check('filter month', $f->from, '2026-10-18');
$f = Filters::fromQuery(['from' => '2026-11-01', 'to' => '2026-11-03', 'client' => '7', 'status' => 'B', 'category' => 'telemetry', 'q' => 'Google'], '2026-11-16');
check('filter custom', [$f->from, $f->to, $f->client, $f->status, $f->category, $f->q], ['2026-11-01', '2026-11-03', 7, 'B', 2, 'google']);
check('filter days', $f->days(), ['2026-11-01', '2026-11-02', '2026-11-03']);
check('filter single from', Filters::fromQuery(['from' => '2026-11-05'])->to, '2026-11-05');
throws('filter bad preset', fn () => Filters::fromQuery(['preset' => 'year']));
throws('filter bad date', fn () => Filters::fromQuery(['from' => '2026-02-30']));
throws('filter reversed', fn () => Filters::fromQuery(['from' => '2026-11-05', 'to' => '2026-11-01']));
throws('filter too long', fn () => Filters::fromQuery(['from' => '2024-01-01', 'to' => '2026-01-01']));
throws('filter client', fn () => Filters::fromQuery(['client' => '1 OR 1=1']));
throws('filter status', fn () => Filters::fromQuery(['status' => 'X']));
throws('filter category', fn () => Filters::fromQuery(['category' => 'nope']));
throws('filter q', fn () => Filters::fromQuery(['q' => '<script>']));

// ---- Rollup day bounds (SAST, no DST) ----
check('day bounds', Rollup::dayBounds('2026-11-11'), [1794348000, 1794434400]);   // 2026-11-10T22:00Z .. +24h
check('day of', Rollup::dayOf(intdiv(AdGuard::parseTime('2026-11-10T22:00:00Z'), 1_000_000_000)), '2026-11-11');
check('day of before midnight', Rollup::dayOf(intdiv(AdGuard::parseTime('2026-11-10T21:59:59Z'), 1_000_000_000)), '2026-11-10');

// ---- Database: migrate, lists, fixture ingest, idempotency, categories, rollup ----
$pdo = Db::open();
check('migrate', Db::migrate($pdo), 1);
check('migrate again', Db::migrate($pdo), 1);
check('categories seeded', (int) $pdo->query('SELECT count(*) FROM categories')->fetchColumn(), count(Categories::all()));
$counts = (new Lists($pdo))->loadFromDir(__DIR__ . '/fixtures/lists', 1);
check('lists total', $counts['total'], 9);   // both-lists.example once; IP and wildcard skipped

$fixture = json_decode((string) file_get_contents(__DIR__ . '/fixtures/querylog.json'), true);
$ing = new Ingest($pdo, $psl, new Categoriser($pdo));
$ing->commitBatch($fixture['data']);
check('ingest new', $ing->inserted, 20);
$ing->commitBatch($fixture['data']);
check('ingest twice adds nothing', $ing->inserted, 20);
check('rows', (int) $pdo->query('SELECT count(*) FROM queries')->fetchColumn(), 20);

$statusCounts = $pdo->query('SELECT status, count(*) FROM queries GROUP BY status ORDER BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
check('status counts', $statusCounts, ['A' => 1, 'B' => 7, 'D' => 12]);

$cat = $pdo->query('SELECT d.fqdn, c.slug || "|" || d.category_source FROM domains d JOIN categories c ON c.id = d.category_id')->fetchAll(PDO::FETCH_KEY_PAIR);
foreach ([
    'ads.tracker.example' => 'ads-tracking|ut1:publicite',
    'metric.gstatic.com' => 'infrastructure|override',
    'telemetry.vendor.example' => 'telemetry|hagezi:native.samsung',
    'cdn.both-lists.example' => 'telemetry|hagezi:native.samsung',     // HaGeZi beats UT1
    'bad.phish.example' => 'malware|hagezi:tif',                       // most specific level first
    'www.google.com' => 'search|override',                              // override beats a list
    'printer.lan' => 'infrastructure|override',                         // override above the base
    'x.myhome.example' => 'infrastructure|override',
    '4.2.0.192.in-addr.arpa' => 'infrastructure|override',
    'a.b.shop.co.uk' => 'shopping|ut1:shopping',
    'mail.social.example' => 'social|ut1:social_networks',
    'unknown-thing.example' => 'uncategorised|none',
    'me.github.io' => 'uncategorised|none',
] as $fqdn => $want) {
    check("category $fqdn", $cat[$fqdn] ?? null, $want);
}

$clients = $pdo->query('SELECT ckey, name FROM clients ORDER BY ckey')->fetchAll(PDO::FETCH_KEY_PAIR);
check('clients', $clients, ['192.0.2.10' => 'Laptop', '192.0.2.11' => 'Phone', '192.0.2.13' => '192.0.2.13', 'kid' => 'kid']);

$daily = $pdo->query('SELECT day, sum(n) FROM daily GROUP BY day ORDER BY day')->fetchAll(PDO::FETCH_KEY_PAIR);
check('daily by local day', $daily, ['2026-11-10' => 6, '2026-11-11' => 10, '2026-11-12' => 4]);

// ---- Cloudflare enrichment (no network: results are faked) ----
$cfc = static fn (string $n) => ['id' => 1, 'name' => $n, 'super_category_id' => 1];
check('cf classify content', Cloudflare::classify(['content_categories' => [$cfc('Technology'), $cfc('Video Streaming')]]),
    ['app_category_id' => Categories::idForSlug('video'), 'source' => 'cloudflare:Video Streaming',
        'categories' => ['Technology', 'Video Streaming']]);
check('cf classify risk beats content', Cloudflare::classify(['content_categories' => [$cfc('Gaming')],
    'risk_types' => [$cfc('Phishing')]])['source'], 'cloudflare:Phishing');
check('cf classify inherited', Cloudflare::classify(['inherited_content_categories' => [$cfc('Webmail')]])['source'],
    'cloudflare:Webmail');
check('cf classify unmapped flag', Cloudflare::classify(['content_categories' => [$cfc('CIPA Filter')]])['app_category_id'], null);
check('cf classify broad bucket', Cloudflare::classify(['content_categories' => [$cfc('Technology')]])['source'], 'cloudflare:Technology');
check('cf classify specific beats broad', Cloudflare::classify(['content_categories' => [$cfc('Technology'), $cfc('Business'), $cfc('Trackers/Analytics')]])['source'], 'cloudflare:Trackers/Analytics');
check('cf classify super-category stays broad', Cloudflare::classify(['content_categories' => [$cfc('Information Security'), $cfc('Internet Communication')]])['app_category_id'], Categories::idForSlug('technology'));
check('cf classify business beats technology', Cloudflare::classify(['content_categories' => [$cfc('Technology'), $cfc('Business')]])['source'], 'cloudflare:Business');
check('cf classify empty', Cloudflare::classify([])['app_category_id'], null);
foreach (['example.com' => true, 'x.github.io' => true, 'co.uk' => true, 'printer.lan' => false, 'in-addr.arpa' => false,
    '192.0.2.1' => false, 'wpad' => false, 'thing.example' => false, 'a_b.com' => false] as $b => $want) {
    check("cf eligible $b", Cloudflare::eligible($b), $want);
}
$cfNow = strtotime('2026-11-13 12:00');
check('cf pick', Cloudflare::pick($pdo, 100, 90, $cfNow), ['me.github.io']);   // .example bases are not sent
$ghId = (int) $pdo->query("SELECT id FROM domains WHERE fqdn = 'me.github.io'")->fetchColumn();
check('cf apply', Cloudflare::apply($pdo, new Categoriser($pdo), ['me.github.io' => ['content_categories' => [$cfc('Personal Blogs')]]], $cfNow),
    [1, (int) $pdo->query("SELECT count(DISTINCT date(ts, 'unixepoch', '+2 hours')) FROM queries WHERE domain_id = $ghId")->fetchColumn()]);
check('cf apply category', $pdo->query("SELECT category_source FROM domains WHERE id = $ghId")->fetchColumn(), 'cloudflare:Personal Blogs');
$socialDaily = (int) $pdo->query('SELECT coalesce(sum(n), 0) FROM daily WHERE category_id = ' . Categories::idForSlug('social'))->fetchColumn();
$socialRaw = (int) $pdo->query('SELECT count(*) FROM queries q JOIN domains d ON d.id = q.domain_id WHERE d.category_id = '
    . Categories::idForSlug('social'))->fetchColumn();
check('cf apply rollup follows', $socialDaily, $socialRaw);
check('cf daily total unchanged', (int) $pdo->query('SELECT sum(n) FROM daily')->fetchColumn(), 20);
check('cf pick after: nothing left', Cloudflare::pick($pdo, 100, 90, $cfNow), []);
check('cf pick after recheck window', Cloudflare::pick($pdo, 100, 90, $cfNow + 91 * 86400), ['me.github.io']);
check('cf remaining', Cloudflare::remaining($pdo, 90, $cfNow), 0);
check('cf recategorise keeps cf answer', (new Categoriser($pdo))->recategoriseAll(), 0);
// A cached answer saved under an older mapping (name known, no category) is re-mapped on config change.
$pdo->exec("UPDATE base_cf SET cf_raw = '{\"app_category_id\":null,\"source\":null,\"categories\":[\"Technology\",\"Trackers/Analytics\"]}' WHERE base = 'me.github.io'");
check('cf reclassify stale cache', Cloudflare::reclassifyCache($pdo), 1);
check('cf reclassify again is a no-op', Cloudflare::reclassifyCache($pdo), 0);
(new Categoriser($pdo))->recategoriseAll();
check('cf reclassified domain', $pdo->query("SELECT category_source FROM domains WHERE fqdn = 'me.github.io'")->fetchColumn(), 'cloudflare:Trackers/Analytics');

// ---- Ingest walk over a paged fake API: full run, re-run, interrupted backfill resume ----
final class FakeAdGuard
{
    public int $calls = 0;
    public ?int $failAfter = null;
    public function __construct(public array $entries) {}
    public function page(?string $olderThan, int $limit): array
    {
        if ($this->failAfter !== null && $this->calls >= $this->failAfter) {
            throw new RuntimeException('AdGuard went away');
        }
        $this->calls++;
        $rows = array_values(array_filter($this->entries, fn ($e) => $olderThan === null
            || AdGuard::parseTime($e['time']) < AdGuard::parseTime($olderThan)));
        $page = array_slice($rows, 0, $limit);
        return ['data' => $page, 'oldest' => count($rows) > $limit ? end($page)['time'] : ''];
    }
}
$walkDb = function (): PDO {
    $p = Db::open(':memory:');
    Db::migrate($p);
    return $p;
};
$all = $fixture['data'];   // newest first, 20 entries
$older = array_slice($all, 8);
$p = $walkDb();
$i = new Ingest($p, $psl, new Categoriser($p));
$i->run(new FakeAdGuard($older), 3);
check('walk first run', $i->inserted, 12);
$i->run(new FakeAdGuard($all), 3);
check('walk second run picks up newer only', $i->inserted, 20);
check('walk cursor', (int) Db::meta($p, 'ingest_cursor_ns'), AdGuard::parseTime($all[0]['time']));
$fake = new FakeAdGuard($all);
$i->run($fake, 3);
check('walk third run adds nothing', $i->inserted, 20);
check('walk third run reads one page', $fake->calls, 1);

$p = $walkDb();
$i = new Ingest($p, $psl, new Categoriser($p));
$fake = new FakeAdGuard($older);
$fake->failAfter = 11;     // dies on page 12 of a 12-entry backfill at 1 entry per page
try {
    $i->run($fake, 1);
} catch (RuntimeException) {
}
check('interrupted backfill saved progress', Db::meta($p, 'ingest_pending_top_ns') !== null, true);
check('interrupted backfill rows', (int) $p->query('SELECT count(*) FROM queries')->fetchColumn(), 10);
$fake = new FakeAdGuard($all);   // meanwhile 8 newer entries arrived
$i->run($fake, 1);
check('resume completes', (int) $p->query('SELECT count(*) FROM queries')->fetchColumn(), 20);
check('resume did not restart the backfill', $fake->calls < 20, true);
check('resume cleared pending', Db::meta($p, 'ingest_pending_top_ns'), null);
check('resume cursor', (int) Db::meta($p, 'ingest_cursor_ns'), AdGuard::parseTime($all[0]['time']));

// ---- Clean up ----
Lists::rmrf($tmp);
echo ($fails ? "FAILED $fails of $count checks\n" : "ok, $count checks passed\n");
exit($fails ? 1 : 0);
