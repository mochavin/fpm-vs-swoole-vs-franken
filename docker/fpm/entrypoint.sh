#!/bin/sh
set -e

echo "=== [FPM] Waiting for database... ==="
until php artisan db:monitor --databases=pgsql 2>/dev/null; do
  echo "Waiting for PostgreSQL..."
  sleep 2
done

echo "=== [FPM] Running migrations... ==="
php artisan migrate --force --no-interaction

echo "=== [FPM] Seeding database... ==="
php artisan db:seed --force --no-interaction 2>/dev/null || echo "Seeding skipped (already seeded or error)"

echo "=== [FPM] Caching config & routes... ==="
php artisan config:cache
php artisan route:cache

echo "=== [FPM] Starting PHP-FPM... ==="
exec php-fpm
