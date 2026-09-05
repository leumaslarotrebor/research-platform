#!/bin/bash
# laravel-api/docker-entrypoint.sh
set -euo pipefail

: "${DB_HOST:?DB_HOST must be set}"
: "${DB_DATABASE:?DB_DATABASE must be set}"
: "${DB_USERNAME:?DB_USERNAME must be set}"
: "${DB_PASSWORD:?DB_PASSWORD must be set}"

echo "[entrypoint] Waiting for MySQL at ${DB_HOST}..."
for i in $(seq 1 30); do
  if mysqladmin ping -h"${DB_HOST}" -u"${DB_USERNAME}" -p"${DB_PASSWORD}" --silent 2>/dev/null; then
    echo "[entrypoint] MySQL is reachable."
    break
  fi
  sleep 2
  if [ "$i" -eq 30 ]; then
    echo "[entrypoint] ERROR: MySQL never became reachable." >&2
    exit 1
  fi
done

# Laravel's dotenv loader (vlucas/phpdotenv) never overrides variables
# that are already set in the process environment — so real secrets
# injected via Kubernetes Secrets / docker-compose env vars always win.
# .env.example here only fills in non-sensitive placeholders/defaults
# so artisan commands that expect a .env file to exist don't fail.
if [ ! -f /var/www/html/.env ]; then
  echo "[entrypoint] No .env found, seeding placeholders from .env.example (real values still come from container env vars)."
  cp /var/www/html/.env.example /var/www/html/.env
fi

php artisan key:generate --force
php artisan config:cache
php artisan migrate --force

exec "$@"
