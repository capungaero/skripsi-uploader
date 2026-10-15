#!/bin/sh
set -e

ROLE="${APP_ROLE:-app}"
DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-5432}"
READY_MARKER=/var/www/html/storage/app/.booted

echo "Waiting for database ${DB_HOST}:${DB_PORT}..."
i=0
until php -r "exit(@fsockopen('${DB_HOST}', ${DB_PORT}) ? 0 : 1);" >/dev/null 2>&1; do
  i=$((i + 1))
  if [ "$i" -ge 60 ]; then
    echo "Database never became reachable after 2 minutes, continuing anyway."
    break
  fi
  sleep 2
done

if [ "$ROLE" = "app" ]; then
  if [ -z "$APP_KEY" ]; then
    echo "APP_KEY is not set. Refusing to boot with a throwaway key (it would make DB-encrypted secrets unrecoverable). Set APP_KEY in the compose .env." >&2
    exit 1
  fi

  mkdir -p storage/app/private storage/app/public storage/app/staging storage/app/ditolak \
    storage/framework/cache/data storage/framework/sessions storage/framework/testing storage/framework/views \
    storage/logs bootstrap/cache
  chown -R www-data:www-data storage bootstrap/cache

  php artisan migrate --force --no-interaction
  php artisan storage:link --force || true

  touch "$READY_MARKER"
  exec apache2-foreground
fi

echo "Waiting for the app container to finish migrating..."
i=0
until [ -f "$READY_MARKER" ]; do
  i=$((i + 1))
  if [ "$i" -ge 150 ]; then
    echo "Timed out waiting for ${READY_MARKER}; starting anyway."
    break
  fi
  sleep 2
done

case "$ROLE" in
  worker)
    exec php artisan queue:work database --queue=ai,cloud,notify,dspace,kliring --sleep=3 --tries=3 --max-time=3600
    ;;
  scheduler)
    exec sh -c 'while true; do php artisan schedule:run --no-interaction; sleep 60; done'
    ;;
  *)
    echo "Unknown APP_ROLE: $ROLE" >&2
    exec "$@"
    ;;
esac
