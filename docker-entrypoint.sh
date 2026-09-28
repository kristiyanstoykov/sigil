#!/bin/sh
set -e

ENV="${APP_ENV:-dev}"

if [ "$ENV" = "prod" ]; then
    echo "==> Installing PHP dependencies (no dev packages)..."
    composer install --no-interaction --prefer-dist --no-dev --classmap-authoritative

    # var/ outlives the container, and prod never checks its cache for freshness.
    rm -rf var/cache/prod

    # Prod serves compiled assets from public/assets; there is no dev asset server.
    echo "==> Building and compiling assets..."
    php bin/console tailwind:build --minify --no-interaction
    php bin/console asset-map:compile --no-interaction
else
    echo "==> Installing PHP dependencies..."
    composer install --no-interaction --prefer-dist --optimize-autoloader

    echo "==> Building Tailwind CSS..."
    php bin/console tailwind:build --no-interaction 2>/dev/null || echo "  (skipped)"
fi

# Schema, root key, CA, seal, bucket - everything a fresh checkout needs to
# sign a document. Idempotent, so it runs on every start. See docker/bootstrap.sh.
sh docker/bootstrap.sh "$ENV"

echo "==> Warming up cache..."
php bin/console cache:warmup --no-interaction 2>/dev/null || echo "  (skipped)"

echo "==> Symfony ($ENV) on FrankenPHP, site ${SERVER_NAME:-:8000}"
exec frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile
