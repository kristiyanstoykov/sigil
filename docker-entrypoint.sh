#!/bin/sh
set -e

echo "==> Installing PHP dependencies..."
composer install --no-interaction --prefer-dist --optimize-autoloader

echo "==> Building Tailwind CSS..."
php bin/console tailwind:build --no-interaction 2>/dev/null || echo "  (skipped)"

echo "==> Warming up cache..."
php bin/console cache:warmup --no-interaction 2>/dev/null || echo "  (skipped)"

echo "==> Symfony dev server running at http://localhost:8000"
exec php -S 0.0.0.0:8000 -t public
