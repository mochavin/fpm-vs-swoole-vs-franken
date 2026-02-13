#!/bin/bash
set -e

cd /app

echo "=== [FrankenPHP] Waiting for database... ==="
until php artisan db:monitor --databases=pgsql 2>/dev/null; do
  echo "Waiting for PostgreSQL..."
  sleep 2
done

echo "=== [FrankenPHP] Running migrations... ==="
php artisan migrate --force --no-interaction

echo "=== [FrankenPHP] Seeding database... ==="
php artisan db:seed --force --no-interaction 2>/dev/null || echo "Seeding skipped (already seeded or error)"

echo "=== [FrankenPHP] Caching config & routes... ==="
php artisan config:cache
php artisan route:cache

echo "=== [FrankenPHP] Starting Octane (FrankenPHP)... ==="
exec php artisan octane:frankenphp --host=0.0.0.0 --port=8000 --workers=auto
