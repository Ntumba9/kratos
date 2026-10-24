#!/bin/sh
# KRATOS CORE initialisation (docker compose service "core-init").
# Idempotent: safe to run on every `docker compose up`.
set -eu

cd /var/www/html

if [ ! -f .env ]; then
  cp .env.example .env
  echo "core-init: created .env from .env.example"
fi

composer install --no-interaction --prefer-dist --no-progress

if ! grep -Eq '^APP_KEY=base64:' .env; then
  php artisan key:generate --force
fi

# Schema changes run as the owner role; the app itself connects as kratos_app.
php artisan migrate --database=pgsql_owner --force
# Baseline (idempotent): RBAC catalogue and built-in detection rules.
php artisan db:seed --database=pgsql_owner --force

if [ "${KRATOS_DEMO_MODE:-false}" = "true" ]; then
  php artisan db:seed --database=pgsql_owner --class=Database\\Seeders\\DemoSeeder --force
fi

php artisan config:clear >/dev/null
echo "core-init: complete"
