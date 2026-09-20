#!/bin/sh
# Bring one Symfony environment to a working state, idempotently: schema, root
# wrapping key, CA, delivery seal, storage buckets. Run on every container
# start for dev (var/ is an anonymous volume, so the cert PEMs vanish on each
# recreate while the tokens survive on their named volume) and by hand for
# test - `docker compose exec app sh docker/bootstrap.sh test` - before a first
# local phpunit run. CI runs the same steps in ci.yml; keep them in lockstep.
set -e

ENV="${1:-dev}"
console() { php bin/console -e "$ENV" --no-interaction "$@"; }

echo "==> [$ENV] Database schema"
console doctrine:database:create --if-not-exists
console doctrine:migrations:migrate --allow-no-migration

if [ "$ENV" != "test" ]; then
    # The test env wraps KEKs in-app (EnvRootKeyWrapper) and needs no token.
    echo "==> [$ENV] Root wrapping key (PKCS#11 token)"
    console sigil:root-key:init
fi

echo "==> [$ENV] Certificate authority and delivery seal"
console sigil:ca:init --if-missing
console sigil:seal:init --if-missing

if [ "$ENV" != "test" ]; then
    # MinIO is only `service_started`, not healthy, when the app comes up; a
    # bucket check that loses that race is not worth failing the boot for.
    echo "==> [$ENV] Object storage buckets"
    for i in 1 2 3 4 5; do
        console sigil:storage:init && break
        [ "$i" = 5 ] && echo "  (storage init skipped - is MinIO up? run: php bin/console sigil:storage:init)" || sleep 2
    done
fi
