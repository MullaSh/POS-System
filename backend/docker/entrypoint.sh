#!/bin/sh
set -e

cd /app

[ -f .env ] || cp .env.example .env

# `php artisan serve` tidak meneruskan semua variabel lingkungan ke proses
# server, jadi nilai dari docker-compose ditulis ke .env.
for var in APP_URL DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD; do
  value=$(printenv "$var" || true)
  if [ -n "$value" ]; then
    if grep -q "^${var}=" .env; then
      sed -i "s|^${var}=.*|${var}=${value}|" .env
    else
      echo "${var}=${value}" >> .env
    fi
  fi
done

grep -q '^APP_KEY=base64' .env || php artisan key:generate --force

echo "Menunggu database..."
until pg_isready -h "${DB_HOST:-db}" -p "${DB_PORT:-5432}" -U "${DB_USERNAME:-pos}" >/dev/null 2>&1; do
  sleep 1
done

php artisan config:clear >/dev/null
php artisan migrate --force
php artisan db:seed --force

echo "Backend siap di port 8000"
exec php artisan serve --host=0.0.0.0 --port=8000
