# AdGuard Stats: a read-only sidecar for AdGuard Home. Apache + mod_php serves the UI and JSON API;
# supercronic runs the ingest / list / prune jobs from App/crontab. Built and published to GHCR by
# .github/workflows/image.yml as ghcr.io/hermanras/adguardstats. Runtime data lives in /data (volume).
# Build from the repo root:  docker build -f Docker/Dockerfile -t adguardstats .
# (Docker/Dockerfile.dockerignore limits the context to App/ and Docker/, minus tests.)
FROM php:8.4-apache

LABEL org.opencontainers.image.source="https://github.com/HermanRas/AdGuardStats"
LABEL org.opencontainers.image.description="AdGuard Stats: dashboard and categorised query log for AdGuard Home"

# supercronic, pinned and checksummed (sha256 from the v0.2.51 release assets).
ARG SUPERCRONIC_VERSION=v0.2.51
ARG TARGETARCH
RUN set -eux; \
    arch="${TARGETARCH:-amd64}"; \
    case "$arch" in \
      amd64) sum=cc232108374e8e2b537045f2d5eaa1229f619db314c3ba6a14d38b35dc0aa123 ;; \
      arm64) sum=4ae22576a78223d8ccdf5f3b3660a5cbb5288416bf0ca96afc4a36d195932b94 ;; \
      *) echo "unsupported arch $arch"; exit 1 ;; \
    esac; \
    curl -fsSL -o /usr/local/bin/supercronic \
      "https://github.com/aptible/supercronic/releases/download/${SUPERCRONIC_VERSION}/supercronic-linux-${arch}"; \
    echo "${sum}  /usr/local/bin/supercronic" | sha256sum -c -; \
    chmod +x /usr/local/bin/supercronic

# Apache: serve /app/public only, quiet, no health-check noise in the access log.
COPY Docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY Docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
RUN a2enmod headers >/dev/null \
 && echo 'ServerName adguardstats' > /etc/apache2/conf-available/servername.conf \
 && a2enconf servername >/dev/null \
 && sed -ri 's/^ServerTokens .*/ServerTokens Prod/; s/^ServerSignature .*/ServerSignature Off/' /etc/apache2/conf-available/security.conf

ENV TZ=Africa/Johannesburg \
    DB_PATH=/data/stats.sqlite \
    DATA_DIR=/data

WORKDIR /app
COPY Docker/entrypoint.sh ./
COPY App/ ./
RUN chmod +x entrypoint.sh && chmod -R a+rX /app && mkdir -p /data && chown www-data:www-data /data

VOLUME /data
EXPOSE 80
HEALTHCHECK --interval=60s --timeout=5s --start-period=60s --retries=3 \
  CMD curl -fs -o /dev/null http://localhost/api/health.php || exit 1

ENTRYPOINT ["/app/entrypoint.sh"]
