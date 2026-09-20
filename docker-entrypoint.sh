#!/bin/sh
set -e

echo "==> Installing PHP dependencies..."
composer install --no-interaction --prefer-dist --optimize-autoloader

echo "==> Building Tailwind CSS..."
php bin/console tailwind:build --no-interaction 2>/dev/null || echo "  (skipped)"

# Schema, root key, CA, seal, buckets - everything a fresh checkout needs to
# sign a document. Idempotent, so it runs on every start. See docker/bootstrap.sh.
sh docker/bootstrap.sh dev

echo "==> Warming up cache..."
php bin/console cache:warmup --no-interaction 2>/dev/null || echo "  (skipped)"

echo "==> Symfony dev server running at http://localhost:8000"
exec php -S 0.0.0.0:8000 -t public
