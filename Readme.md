# AdGuard Stats

A read-only sidecar for [AdGuard Home](https://github.com/AdguardTeam/AdGuardHome) that adds a categorised dashboard and query log. It copies AdGuard's query log into its own SQLite database every 5 minutes, gives every domain a category, and shows what your network is doing, styled to match AdGuard's own UI.

- **Dashboard:** stat tiles, per-day stacked bars (**Allow / Block / Default**), a status donut and a category pie, for Today, Week, Month or any date range, for all clients or just one.
- **Click to filter:** click a bar segment, a donut slice or a pie slice and the log below filters to match. Every filter lives in the URL, so views can be bookmarked and the back button works.
- **Query log:** time, base domain (hover for the full name, type and protocol), client, category and status (hover for AdGuard's reason and the matching rule). Includes a domain search and "Load more".
- **Categories:** from the [UT1 blacklists](https://dsi.ut-capitole.fr/blacklists/) and [HaGeZi](https://github.com/hagezi/dns-blocklists) lists, refreshed weekly, plus manual overrides and optional Cloudflare lookups for what is left. 18 categories: Ads & Tracking, Telemetry, Social, Video & Streaming, Gaming, and others.
- **Keeps history:** raw queries are kept for `RETENTION_DAYS` (default 180); the daily totals are kept forever. A weekly database backup is kept.
- **Read-only:** it never changes anything in AdGuard.

Stack: PHP 8.4 + Apache, SQLite (WAL), [supercronic](https://github.com/aptible/supercronic) for the scheduled jobs, and vendored Chart.js. One container, no build step, no external CDN.

## How it works

```
AdGuard Home ──GET /control/querylog (every 5 min)──► adguardstats container ──► /data/stats.sqlite
                                                       ├─ Apache + PHP: dashboard, log, JSON API
UT1 + HaGeZi + Public Suffix List (weekly) ──────────► └─ supercronic: ingest, list update, prune
```

| Status  | AdGuard reasons |
|---------|-----------------|
| Allow   | `NotFilteredAllowList` (an explicit `@@` allow rule, including exceptions inside block lists) |
| Block   | `FilteredBlackList`, `FilteredBlockedService`, `FilteredParental`, `FilteredSafeBrowsing`, `FilteredInvalid` |
| Default | `NotFilteredNotFound`, `NotFilteredError`, and answers that were changed rather than blocked (`Rewrite*`, `FilteredSafeSearch`) |

Each domain is matched from its most specific name up to its base domain (eTLD+1, from the Public Suffix List). At each level a manual override wins over HaGeZi, which wins over UT1. If nothing matched, the Cloudflare answer for the base domain is used (when enabled); anything left over is *Uncategorised*.

| Job | When (local time) | What |
|---|---|---|
| `bin/ingest.php` | every 5 min | Pulls new log entries. A long first backfill that is interrupted resumes where it stopped. |
| `bin/update-lists.php` | Monday 03:00, and on first start | Downloads the lists (about 7M domains), re-categorises everything, rebuilds the daily totals. If a download fails, the old lists are kept. |
| `bin/cloudflare.php` | every 30 min | Only with `CF_ENABLED=1`: looks up the next `CF_BATCH` unmatched base domains, most queried first, and re-categorises them. Does nothing once none are left. |
| `bin/prune.php` | daily 03:30 | Applies retention and `PRAGMA optimize`; runs `VACUUM` monthly and a backup on Mondays (keeps the last two). |

## Requirements

- AdGuard Home with the **query log enabled** and **"Anonymize client IP" off**. AdGuard's own log retention sets how far back the first run can backfill.
- An AdGuard login for the sidecar. AdGuard has no read-only role, so add a dedicated user so it can be revoked on its own. [Docker/ops/add-stats-user.sh](Docker/ops/add-stats-user.sh) shows one way to do it (it stops AdGuard briefly to edit `AdGuardHome.yaml`).
- Docker, and a network shared with the AdGuard container.
- About 400 MB of disk for the lists, plus about 100 bytes per stored query.

## Run it

1. Create a folder (e.g. `/opt/adguardstats`), copy [Docker/docker-compose.yml](Docker/docker-compose.yml) into it, and create `.env` from [Docker/.env.example](Docker/.env.example) with `chmod 600 .env`.
2. Edit the compose file's network to the one your AdGuard container is on (the default is an external network named `web`).
3. Run `docker compose pull && docker compose up -d`.

On first start the container creates the database, downloads the lists in the background (1 to 2 minutes), and backfills the query log within 5 minutes. It changes the owner of `./data` to `www-data` (uid 33).

**Put it behind your reverse proxy on a private network only** (LAN or VPN, the same way you expose the AdGuard admin UI). It shows your household's or organisation's browsing history. Never publish it on a public hostname. For a second layer, set `APP_BASIC_AUTH`.

Update with `docker compose pull && docker compose up -d`. To pin or roll back a version, set `STATS_TAG=sha-<commit>`.

### Configuration (`.env`)

| Variable | Default | |
|---|---|---|
| `ADGUARD_URL` | | AdGuard's address as the container sees it, e.g. `http://adguardhome:80`. Empty = ingest skipped. |
| `ADGUARD_USER` / `ADGUARD_PASS` | | The sidecar's AdGuard login. |
| `TZ` | `Africa/Johannesburg` | Time zone for day buckets and the job schedule. |
| `RETENTION_DAYS` | `180` | Days of raw queries to keep. Daily totals are kept forever. |
| `LOCAL_DOMAINS` | | Comma-separated domains of your own (e.g. `home.example.com`), categorised as Infrastructure together with their subdomains. |
| `APP_BASIC_AUTH` | | Optional `user:bcrypt-hash`, e.g. from `htpasswd -nbB user pass`. |
| `CF_ENABLED` | `0` | `1` turns on Cloudflare category lookups for domains the lists leave out (see below). |
| `CF_ACCOUNT_ID` / `CF_API_TOKEN` | | Your Cloudflare account ID and an API token with only the **Account › Intel › Read** permission. |
| `CF_BATCH` / `CF_RECHECK_DAYS` | `100` / `90` | Base domains looked up per run (every 30 min), and days before a domain is asked again. |
| `LISTS_ON_START` | `1` | `0` skips the first-start list download (CI, offline demos). |

### Overrides

[App/config/seed-overrides.php](App/config/seed-overrides.php) assigns categories to well-known platforms the lists leave out: connectivity checks, NTP, Google/Apple/Microsoft services, CDNs and the like. An entry covers the domain and all its subdomains. When the file changes, the next container start re-categorises existing data.

### Cloudflare lookups (optional)

Domains that no override or list matches can be looked up in [Cloudflare's domain intelligence](https://developers.cloudflare.com/api/resources/intel/subresources/domains/), which is free on any Cloudflare account. Create an API token with only **Account › Intel › Read**, set `CF_ENABLED=1`, `CF_ACCOUNT_ID` and `CF_API_TOKEN`, and restart. Every 30 minutes the job sends up to `CF_BATCH` base domains (never clients or times), in bulk requests of 10. Local names, IPs and reverse zones are never sent. Run `docker exec -u www-data adguardstats php /app/bin/cloudflare.php --dry-run` to see what the next run would send.

Cloudflare's category names map onto the app's categories in the `cloudflare` section of [App/config/categories.php](App/config/categories.php); a security risk (malware, phishing, ...) wins over a content category. Broad ones such as *Technology* or *Business* are left unmapped, so those domains stay Uncategorised; the Status page lists the most common unmapped names so you can add them.

### Logs and health

- `docker logs adguardstats` shows one line per job run, e.g. `[ingest] ok pages=1 seen=58 new=58 in 0.4s`.
- The container health check (`/api/health.php`) fails when no ingest has succeeded for 30 minutes.
- `/status.php` shows ingest state, list versions and sizes, database size, and the share of queries still uncategorised.

## Development

```
App/      the application (copied to /app in the image): config/ migrations/ src/ bin/ public/ crontab
          tests/ (plain-PHP tests and fixtures; not in the image)
Docker/   Dockerfile, Dockerfile.dockerignore, docker-compose.yml, entrypoint.sh, apache.conf, php.ini,
          .env.example, ops/ (example AdGuard user setup)
.github/  CI: lint, tests, image build, fixture smoke test, push to GHCR on main
```

No PHP needed on the host; everything runs in containers:

```sh
docker run --rm -v "$PWD/App":/app -w /app php:8.4-cli php tests/run.php     # tests
docker build -f Docker/Dockerfile -t adguardstats .
docker run --rm -d --name ags -p 8090:80 -e LISTS_ON_START=0 adguardstats    # http://localhost:8090
docker cp App/tests/fixtures ags:/tmp/fixtures                                 # demo data, no AdGuard needed
docker exec -u www-data ags php /app/bin/import-fixture.php /tmp/fixtures/querylog.json /tmp/fixtures/lists
```

The fixture covers 2026-11-10 to 2026-11-12, so open `http://localhost:8090/?from=2026-11-10&to=2026-11-12`.

The JSON API (`GET` only) is `/api/summary.php`, `/api/log.php`, `/api/clients.php` and `/api/health.php`. All of them take the dashboard's filters: `preset=today|week|month` or `from`/`to` (`YYYY-MM-DD`), plus `client`, `status=A|B|D`, `category=<slug>` and `q`. Bad parameters return `400`.

## Credits and licences

- Category data: [UT1 blacklists](https://dsi.ut-capitole.fr/blacklists/) (Université Toulouse Capitole, CC BY-SA 4.0) and [HaGeZi DNS blocklists](https://github.com/hagezi/dns-blocklists) (GPL-3.0). Both are downloaded at runtime and are not included here.
- [Public Suffix List](https://publicsuffix.org/) (MPL-2.0); a copy is bundled as a fallback.
- [Chart.js](https://www.chartjs.org/) 4.5.1 (MIT), vendored in `App/public/vendor/`.
- Not affiliated with AdGuard. The colours follow AdGuard Home's UI so the two feel alike.
