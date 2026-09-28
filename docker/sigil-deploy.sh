#!/bin/sh
# Deploys one tested commit of master to this host. Installed as
# /usr/local/bin/sigil-deploy and run as the forced command of the GitHub
# Actions key (the SHA arrives in SSH_ORIGINAL_COMMAND), or by hand:
#   sigil-deploy <sha>              refuses a commit older than the deployed one
#   sigil-deploy <sha> --rollback   deploys an older commit on purpose
set -eu

REPO="${SIGIL_REPO:-$HOME/sigil}"
BRANCH=master
DOMAIN=sigil-app.com

if [ -n "${SSH_ORIGINAL_COMMAND+x}" ]; then
    # Over SSH only a bare SHA is accepted; rollback stays a manual act.
    sha="$SSH_ORIGINAL_COMMAND"
    rollback=""
else
    sha="${1:-}"
    rollback="${2:-}"
fi

if ! printf '%s' "$sha" | grep -Eqx '[0-9a-f]{40}'; then
    echo "usage: sigil-deploy <40-hex commit sha> [--rollback]" >&2
    exit 64
fi
if [ -n "$rollback" ] && [ "$rollback" != "--rollback" ]; then
    echo "unknown option: $rollback" >&2
    exit 64
fi

exec 9>/tmp/sigil-deploy.lock
flock -w 900 9 || { echo "another deploy is still running" >&2; exit 75; }

cd "$REPO"
dc() { docker compose -f compose.yaml -f compose.prod.yaml "$@"; }

git fetch --quiet origin "$BRANCH"
if ! git merge-base --is-ancestor "$sha" "origin/$BRANCH"; then
    echo "refusing $sha: not on origin/$BRANCH" >&2
    exit 1
fi
if [ -z "$rollback" ] && ! git merge-base --is-ancestor HEAD "$sha"; then
    echo "refusing $sha: older than the deployed $(git rev-parse HEAD) (use --rollback)" >&2
    exit 1
fi

echo "==> Deploying $sha (was $(git rev-parse HEAD))"
# No --force: a local edit to a tracked file stops the deploy instead of vanishing.
git checkout --quiet -B "$BRANCH" "$sha"

# Recreate the app even when the image is unchanged: the code is bind-mounted,
# so only a fresh start re-runs the entrypoint (composer, assets, migrations, cache).
dc build app
dc up -d --no-deps --force-recreate app
dc up -d --remove-orphans

echo "==> Waiting for https://$DOMAIN/login"
tries=0
until curl -fsS -o /dev/null --max-time 10 --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN/login"; do
    tries=$((tries + 1))
    if [ "$tries" -ge 36 ]; then
        echo "site did not answer within 3 minutes" >&2
        dc logs --tail 50 app >&2
        exit 1
    fi
    sleep 5
done

docker image prune -f >/dev/null
echo "==> Deployed $sha"
