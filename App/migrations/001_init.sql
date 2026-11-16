-- Initial schema (Plan section 5). Times are unix seconds UTC unless noted; `daily.day` is the local day.

CREATE TABLE categories (
    id      INTEGER PRIMARY KEY,
    slug    TEXT NOT NULL UNIQUE,
    name    TEXT NOT NULL,
    colour  TEXT NOT NULL
);

-- `ckey` identifies a client: AdGuard's ClientID (DoT/DoH) when it has one, else the IP.
CREATE TABLE clients (
    id          INTEGER PRIMARY KEY,
    ckey        TEXT NOT NULL UNIQUE,
    ip          TEXT NOT NULL,
    name        TEXT NOT NULL,
    first_seen  INTEGER NOT NULL,
    last_seen   INTEGER NOT NULL
);

CREATE TABLE domains (
    id               INTEGER PRIMARY KEY,
    fqdn             TEXT NOT NULL UNIQUE,
    base             TEXT NOT NULL,
    category_id      INTEGER NOT NULL DEFAULT 18,
    category_source  TEXT NOT NULL DEFAULT 'none',   -- override|hagezi:<list>|ut1:<cat>|cloudflare:<cat>|none
    first_seen       INTEGER NOT NULL
);
CREATE INDEX domains_base ON domains (base);
CREATE INDEX domains_category ON domains (category_id);

CREATE TABLE base_cf (
    base           TEXT PRIMARY KEY,
    cf_checked_at  INTEGER NOT NULL,
    cf_raw         TEXT
);

CREATE TABLE queries (
    id          INTEGER PRIMARY KEY,
    ts          INTEGER NOT NULL,
    ts_ns       INTEGER NOT NULL,
    domain_id   INTEGER NOT NULL,
    client_id   INTEGER NOT NULL,
    qtype       TEXT NOT NULL,
    proto       TEXT NOT NULL,
    status      TEXT NOT NULL CHECK (status IN ('A', 'B', 'D')),
    reason      TEXT NOT NULL,
    rule        TEXT,
    filter_id   INTEGER,
    elapsed_ms  REAL,
    UNIQUE (ts_ns, client_id, domain_id, qtype)
);
CREATE INDEX queries_ts ON queries (ts);
CREATE INDEX queries_client_ts ON queries (client_id, ts_ns);
CREATE INDEX queries_domain ON queries (domain_id);

CREATE TABLE daily (
    day          TEXT NOT NULL,
    client_id    INTEGER NOT NULL,
    category_id  INTEGER NOT NULL,
    status       TEXT NOT NULL,
    n            INTEGER NOT NULL,
    PRIMARY KEY (day, client_id, category_id, status)
) WITHOUT ROWID;

CREATE TABLE list_domains (
    domain       TEXT PRIMARY KEY,
    category_id  INTEGER NOT NULL,
    source       TEXT NOT NULL
) WITHOUT ROWID;

CREATE TABLE overrides (
    domain       TEXT PRIMARY KEY,
    category_id  INTEGER NOT NULL,
    note         TEXT
);
