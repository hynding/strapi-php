#!/bin/sh
# Prepares the mounted repository for a dev container, then runs the given command.
set -e

cd /app

# Derive DATABASE_HOST / DATABASE_PORT from DATABASE_CLIENT so the compose service names work
# without any extra configuration (the example's config/database.php reads these).
case "${DATABASE_CLIENT:-sqlite}" in
  postgres) : "${DATABASE_HOST:=postgres}"; : "${DATABASE_PORT:=5432}" ;;
  mysql)    : "${DATABASE_HOST:=mysql}";    : "${DATABASE_PORT:=3306}" ;;
  mariadb)  : "${DATABASE_HOST:=mariadb}";  : "${DATABASE_PORT:=3306}" ;;
esac
export DATABASE_HOST DATABASE_PORT

if [ ! -f vendor/autoload.php ] || [ "${COMPOSER_INSTALL:-auto}" = "always" ]; then
  echo "[strapi] composer install"
  composer install --no-interaction --prefer-dist
fi

if [ -n "$APP_ROOT" ] && [ -d "$APP_ROOT" ]; then
  if [ ! -f "$APP_ROOT/.env" ] && [ -f "$APP_ROOT/.env.example" ]; then
    echo "[strapi] creating $APP_ROOT/.env from .env.example"
    cp "$APP_ROOT/.env.example" "$APP_ROOT/.env"
  fi
  mkdir -p "$APP_ROOT/.tmp" "$APP_ROOT/public/uploads"
fi

# Wait for the database when one is configured.
if [ "${DATABASE_CLIENT:-sqlite}" != "sqlite" ]; then
  echo "[strapi] waiting for ${DATABASE_HOST}:${DATABASE_PORT}"
  i=0
  until php -r 'exit(@fsockopen($argv[1], (int) $argv[2], $e, $m, 1) ? 0 : 1);' "$DATABASE_HOST" "$DATABASE_PORT" 2>/dev/null; do
    i=$((i + 1))
    [ "$i" -ge 60 ] && { echo "[strapi] database not reachable after 60s"; exit 1; }
    sleep 1
  done
fi

exec "$@"
