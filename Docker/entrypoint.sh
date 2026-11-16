#!/bin/sh
# Container start: make /data writable for www-data, apply migrations, start supercronic
# (jobs run as www-data) in the background, then run Apache in the foreground.
set -eu

mkdir -p /data
chown www-data:www-data /data
# Files left by an older root-run job (or a host copy) must stay writable for the jobs.
find /data -maxdepth 1 ! -user www-data -exec chown www-data:www-data {} + 2>/dev/null || true

as_www() { setpriv --reuid=www-data --regid=www-data --init-groups "$@"; }

as_www php /app/bin/migrate.php

# First start: load the category lists in the background if none are loaded yet
# (LISTS_ON_START=0 turns this off, e.g. in CI where there is no network for the downloads).
if [ "${LISTS_ON_START:-1}" = 1 ]; then
    as_www php -d memory_limit=512M /app/bin/update-lists.php --if-empty &
fi

# -passthrough-logs: job output goes to docker logs as-is; -quiet: no supercronic chatter.
as_www supercronic -quiet -passthrough-logs /app/crontab &

exec apache2-foreground
