#!/usr/bin/env bash
# Example one-off host setup for AdGuard Stats, for AdGuard Home run with docker compose.
# Adjust the paths and names below to your install, read it through, then run it as a sudo-capable user:
#   - creates $STATS{,/data} owned by you
#   - generates the `stats` password ON THE HOST and writes it only to /opt/adguardstats/.env (600)
#   - adds user `stats` (bcrypt) to AdGuardHome.yaml while AdGuard is stopped (it rewrites its
#     config on exit), with a backup, then starts AdGuard and checks the login works.
# Run:  bash add-stats-user.sh
# Safe to re-run: skips the user if it already exists. DNS is down for a few seconds.
set -euo pipefail

AG=${AG:-/opt/adguardhome}            # AdGuard's compose folder (with conf/AdGuardHome.yaml)
CONF=$AG/conf/AdGuardHome.yaml
STATS=${STATS:-/opt/adguardstats}     # where the sidecar's compose file, .env and data/ live
NET=${NET:-web}                       # docker network shared by AdGuard and the sidecar
SERVICE=${SERVICE:-adguardhome}       # AdGuard's compose service / container name
TZ_NAME=${TZ_NAME:-Africa/Johannesburg}
OWNER=$(id -un)
YQ="docker run --rm --user root -v $AG/conf:/w mikefarah/yq:4"

sudo -v   # ask for the password once, up front

sudo install -d -o "$OWNER" -g "$(id -gn)" -m 750 "$STATS" "$STATS/data"

if sudo $YQ -e '.users[] | select(.name == "stats")' /w/AdGuardHome.yaml >/dev/null 2>&1; then
  echo "user 'stats' already in AdGuardHome.yaml - not changing it"
  [ -f "$STATS/.env" ] || { echo "but $STATS/.env is missing; remove the user or write .env by hand"; exit 1; }
else
  PASS=$(openssl rand -base64 32 | tr -dc 'A-Za-z0-9' | cut -c1-32)
  HASH=$(docker run --rm httpd:2.4-alpine htpasswd -nbB -C 10 stats "$PASS" | cut -d: -f2)

  ( umask 077; cat > "$STATS/.env" <<EOF
ADGUARD_URL=http://$SERVICE:80
ADGUARD_USER=stats
ADGUARD_PASS=$PASS
TZ=$TZ_NAME
RETENTION_DAYS=180
LOCAL_DOMAINS=
CF_ENABLED=0
CF_ACCOUNT_ID=
CF_API_TOKEN=
EOF
  )
  echo "wrote $STATS/.env (600)"

  cd "$AG"
  docker compose stop "$SERVICE"
  sudo cp -p "$CONF" "$CONF.bak-pre-stats"
  sudo docker run --rm --user root -v "$AG/conf:/w" -e H="$HASH" mikefarah/yq:4 \
    -i '.users += [{"name": "stats", "password": strenv(H)}]' /w/AdGuardHome.yaml
  docker compose up -d "$SERVICE"
  echo "added user 'stats' (backup: $CONF.bak-pre-stats)"
fi

# Check: log in as stats over the shared network and read the query-log settings.
set -a; . "$STATS/.env"; set +a
for i in $(seq 1 20); do
  out=$(docker run --rm --network "$NET" curlimages/curl -s -w '\n%{http_code}' \
        -u "$ADGUARD_USER:$ADGUARD_PASS" "$ADGUARD_URL/control/querylog/config" || true)
  [ "${out##*$'\n'}" = 200 ] && break; sleep 1
done
echo "querylog config: ${out%$'\n'*}"
[ "${out##*$'\n'}" = 200 ] && echo "OK: stats can log in" || { echo "FAIL: login answered ${out##*$'\n'}"; exit 1; }
echo "${out%$'\n'*}" | grep -q '"anonymize_client_ip":false' || echo "WARNING: anonymize_client_ip is on - client IPs will be hidden"
