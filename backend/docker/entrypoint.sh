#!/bin/sh
set -e

cd /app

[ -f .env ] || cp .env.example .env
grep -q '^APP_KEY=base64' .env || php artisan key:generate --force

echo "Menunggu database..."
until pg_isready -h "${DB_HOST:-db}" -p "${DB_PORT:-5432}" -U "${DB_USERNAME:-pos}" >/dev/null 2>&1; do
  sleep 1
done

php artisan migrate --force
php artisan db:seed --force

echo "Backend siap di port 8000"
exec php artisan serve --host=0.0.0.0 --port=8000
