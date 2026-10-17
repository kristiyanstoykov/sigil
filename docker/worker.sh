#!/bin/sh
# The Messenger worker (ADR-015): builds data exports and runs the temporary-
# download sweep on its schedule. Same image and code as the app; the app's
# entrypoint installs vendor/ and migrates (compose starts the worker after it).
set -e

ENV="${APP_ENV:-dev}"

# Its own var/ (an anonymous volume that survives a recreate): prod never
# checks its cache for freshness, so start from a clean one like the app does.
rm -rf "var/cache/$ENV"
php bin/console cache:warmup --no-interaction

# After a host reboot every container starts at once; consuming before the
# database is up would only crash and restart.
until php bin/console dbal:run-sql --no-interaction "SELECT 1" >/dev/null 2>&1; do
    echo "==> Waiting for the database..."
    sleep 3
done

trap 'kill -TERM "$child" 2>/dev/null; wait "$child"; exit 0' TERM INT

echo "==> Messenger worker ($ENV)"
# Each run exits after an hour or 400 MB so a leak cannot grow forever; the loop
# starts the next one, and a crashed run is retried after a pause.
while :; do
    php bin/console messenger:consume async scheduler_temp_download \
        --time-limit=3600 --memory-limit=400M --no-interaction -v &
    child=$!
    wait "$child" || sleep 5
done
