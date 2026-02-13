#!/bin/sh
set -e

echo "=== [Swoole] Waiting for database... ==="
until php artisan db:monitor --databases=pgsql 2>/dev/null; do
  echo "Waiting for PostgreSQL..."
  sleep 2
done

echo "=== [Swoole] Running migrations... ==="
php artisan migrate --force --no-interaction

echo "=== [Swoole] Seeding database... ==="
php artisan db:seed --force --no-interaction 2>/dev/null || echo "Seeding skipped (already seeded or error)"

echo "=== [Swoole] Caching config & routes... ==="
php artisan config:cache
php artisan route:cache

echo "=== [Swoole] Starting Octane (Swoole)... ==="
exec php artisan octane:start --server=swoole --host=0.0.0.0 --port=8000 --workers=auto --task-workers=auto
