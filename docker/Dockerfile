# Development image for strapi-php: FrankenPHP (PHP 8.3) in worker mode + Composer + Node 20
# (Node is only needed to build the upstream @strapi/admin bundle with `strapi build`).
#
#   docker compose -f docker-compose.dev.yml up            # getstarted on http://localhost:1337 (SQLite)
#   docker compose -f docker-compose.dev.yml --profile postgres up
#
# The repository is bind-mounted at /app, so edits on the host are picked up live.
FROM dunglas/frankenphp:1-php8.3

ARG WITH_NODE=1

RUN install-php-extensions pdo_mysql pdo_pgsql intl gd zip opcache pcntl \
    && apt-get update \
    && apt-get install -y --no-install-recommends git unzip ca-certificates curl gnupg \
    && if [ "$WITH_NODE" = "1" ]; then \
         mkdir -p /etc/apt/keyrings \
         && curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg \
         && echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_20.x nodistro main" > /etc/apt/sources.list.d/nodesource.list \
         && apt-get update && apt-get install -y --no-install-recommends nodejs; \
       fi \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Development php.ini: errors on screen, no opcache timestamps caching problems
RUN cp "$PHP_INI_DIR/php-development.ini" "$PHP_INI_DIR/php.ini" \
    && printf 'opcache.enable=1\nopcache.validate_timestamps=1\nopcache.revalidate_freq=0\nmemory_limit=512M\nupload_max_filesize=256M\npost_max_size=256M\n' > "$PHP_INI_DIR/conf.d/zz-strapi-dev.ini"

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    SERVER_NAME=":1337" \
    APP_ROOT=/app/examples/getstarted

COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/strapi-entrypoint
RUN chmod +x /usr/local/bin/strapi-entrypoint

WORKDIR /app
EXPOSE 1337

ENTRYPOINT ["strapi-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
