#!/usr/bin/env bash
# Server-side Laravel deploy. Intended path on production: /www/playlist.nivessa.com/app/scripts/deploy.sh
# Does not run Composer — vendor stays as on the server unless you update it separately.
#
# Env (optional):
#   DEPLOY_DIR          — app root (default /www/playlist.nivessa.com/app)
#   DEPLOY_BRANCH       — branch (default main)
#   DEPLOY_MIGRATE      — 1 to run migrations
#   DEPLOY_GIT_REMOTE   — remote name (default: origin if present, else erp, else fail)
#   DEPLOY_SYNC_MODE    — ff-only (default) | reset
#                         ff-only: git merge --ff-only — does NOT overwrite uncommitted server
#                                 changes; deploy fails if merge cannot fast-forward.
#                         reset:   git reset --hard — working tree matches GitHub (tracked
#                                 files only; .env and gitignored paths are untouched).
set -euo pipefail

DEPLOY_DIR="${DEPLOY_DIR:-/www/playlist.nivessa.com/app}"
DEPLOY_BRANCH="${DEPLOY_BRANCH:-main}"
DEPLOY_MIGRATE="${DEPLOY_MIGRATE:-0}"
DEPLOY_SYNC_MODE="${DEPLOY_SYNC_MODE:-ff-only}"

cd "$DEPLOY_DIR"

if [ ! -f artisan ]; then
  echo "deploy: artisan not found in $DEPLOY_DIR — wrong directory?"
  exit 1
fi

resolve_git_remote() {
  if [ -n "${DEPLOY_GIT_REMOTE:-}" ] && git remote get-url "${DEPLOY_GIT_REMOTE}" >/dev/null 2>&1; then
    echo "${DEPLOY_GIT_REMOTE}"
  elif git remote get-url origin >/dev/null 2>&1; then
    echo origin
  elif git remote get-url erp >/dev/null 2>&1; then
    echo erp
  else
    echo "deploy: no usable git remote. Add 'origin' or 'erp', or set DEPLOY_GIT_REMOTE. Current remotes:" >&2
    git remote -v >&2
    exit 1
  fi
}

GIT_REMOTE="$(resolve_git_remote)"
echo "deploy: $(date -u) — dir=$DEPLOY_DIR branch=$DEPLOY_BRANCH remote=$GIT_REMOTE sync=$DEPLOY_SYNC_MODE"

# Trust github.com for git-over-SSH (first fetch on a new server fails without this).
mkdir -p "$HOME/.ssh"
chmod 700 "$HOME/.ssh"
ssh-keyscan -t rsa,ecdsa,ed25519 github.com >> "$HOME/.ssh/known_hosts" 2>/dev/null || true

git fetch "$GIT_REMOTE" "$DEPLOY_BRANCH"

if [ "$DEPLOY_SYNC_MODE" = "reset" ]; then
  echo "deploy: DEPLOY_SYNC_MODE=reset — matching GitHub exactly (tracked files only)"
  git reset --hard "${GIT_REMOTE}/${DEPLOY_BRANCH}"
else
  echo "deploy: DEPLOY_SYNC_MODE=ff-only — safe update (fails if server has diverged or dirty tree)"
  git merge --ff-only "${GIT_REMOTE}/${DEPLOY_BRANCH}"
fi

if [ "$DEPLOY_MIGRATE" = "1" ]; then
  echo "deploy: migrate"
  php artisan migrate --force --no-interaction
fi

# Upsert IMAP credentials into .env when passed through from GitHub Secrets.
# This exists so non-SSH operators can rotate the chart-email password by
# editing the GitHub Secret alone — no server shell needed. Values stay
# in .env (never committed to git) and are consumed by config/inventory_check.php.
upsert_env() {
  local key="$1"
  local val="$2"
  if [ -z "${val:-}" ]; then
    return 0
  fi
  if [ ! -w "$DEPLOY_DIR/.env" ]; then
    echo "deploy: .env not writable, skipping env upsert for $key"
    return 0
  fi
  if grep -q "^${key}=" "$DEPLOY_DIR/.env"; then
    # Use a pipe delimiter in sed so passwords containing / don't break the regex
    sed -i.bak "s|^${key}=.*|${key}=${val}|" "$DEPLOY_DIR/.env" && rm -f "$DEPLOY_DIR/.env.bak"
    echo "deploy: updated ${key} in .env"
  else
    echo "${key}=${val}" >> "$DEPLOY_DIR/.env"
    echo "deploy: added ${key} to .env"
  fi
}
upsert_env INVENTORY_CHECK_IMAP_USERNAME "${INVENTORY_CHECK_IMAP_USERNAME:-}"
upsert_env INVENTORY_CHECK_IMAP_PASSWORD "${INVENTORY_CHECK_IMAP_PASSWORD:-}"

# Do not use config:cache or route:cache here — Closure routes break route:cache.
echo "deploy: post-git maintenance v2 — ONLY optimize:clear (no config:cache / route:cache)"
php artisan optimize:clear --no-interaction

php artisan queue:restart 2>/dev/null || true

# Reset FPM OPcache via an in-FPM endpoint. `artisan optimize:clear`
# runs CLI-side and can't touch the OPcache that actually serves the
# live site, so stale compiled Blade bytecode would otherwise keep
# serving old HTML after a deploy. The endpoint auths against APP_KEY
# from .env so only the deploy user (who can read .env) can trigger it.
if [ -r "$DEPLOY_DIR/.env" ]; then
  APP_KEY=$(grep '^APP_KEY=' "$DEPLOY_DIR/.env" | head -1 | cut -d '=' -f 2-)
  APP_KEY="${APP_KEY%\"}"
  APP_KEY="${APP_KEY#\"}"
  if [ -n "$APP_KEY" ]; then
    echo "deploy: resetting FPM OPcache via /opcache-reset.php"
    curl -fsS --max-time 10 --get --data-urlencode "t=${APP_KEY}" \
      "https://playlist.nivessa.com/opcache-reset.php" \
      || echo "deploy: opcache reset request failed (continuing — next request will still work, just slower)"
  else
    echo "deploy: APP_KEY empty in .env, skipping FPM OPcache reset"
  fi
else
  echo "deploy: .env not readable, skipping FPM OPcache reset"
fi

echo "deploy: done"
