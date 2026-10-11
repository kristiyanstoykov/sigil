#!/bin/sh
# The Messenger worker (ADR-015): builds data exports and runs the temporary-
# download sweep on its schedule. Same image and code as the app; the app's
# entrypoint has already installed vendor/ and migrated by the time this starts
# (compose waits for the app to be healthy).
set -e

ENV="${APP_ENV:-dev}"

# Its own var/ (an anonymous volume that survives a recreate): prod never
# checks its cache for freshness, so start from a clean one like the app does.
rm -rf "var/cache/$ENV"
php bin/console cache:warmup --no-interaction

echo "==> Messenger worker ($ENV)"
# Exits after an hour or 400 MB so a leak cannot grow forever; compose restarts it.
exec php bin/console messenger:consume async scheduler_temp_download \
    --time-limit=3600 --memory-limit=400M --no-interaction -v
