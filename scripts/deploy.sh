#!/usr/bin/env bash
# Server-side Laravel deploy. Intended path on production: /www/playlist.nivessa.com/app/scripts/deploy.sh
# Does not run Composer — vendor stays as on the server unless you update it separately.
#
# Env (optional):
#   DEPLOY_DIR          — app root (default /www/playlist.nivessa.com/app)
#   DEPLOY_BRANCH       — branch (default main)
#   DEPLOY_MIGRATE      — 1 to run migrations
#   DEPLOY_GIT_REMOTE   — remote name (default: origin if present, else erp, else fail)
#   DEPLOY_SYNC_MODE    — reset (default) | ff-only
#                         reset:   git reset --hard after fetch — server tracked files match
#                                 GitHub exactly (best for CI deploys). .env stays; run
#                                 `php artisan optimize:clear` after (this script does).
#                         ff-only: git merge --ff-only — fails if server diverged or merge
#                                 cannot fast-forward (stricter ops).
set -euo pipefail

DEPLOY_DIR="${DEPLOY_DIR:-/www/playlist.nivessa.com/app}"
DEPLOY_BRANCH="${DEPLOY_BRANCH:-main}"
DEPLOY_MIGRATE="${DEPLOY_MIGRATE:-0}"
DEPLOY_SYNC_MODE="${DEPLOY_SYNC_MODE:-reset}"

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

# Laravel bootstrap/cache/*.php is often present as *untracked* on the server
# (artisan wrote it before those paths were tracked, or a partial clone). Both
# `git merge` and `git reset --hard` can refuse to touch those paths. `rm -f`
# always clears them; the next git step restores tracked copies from GitHub.
mkdir -p bootstrap/cache
echo "deploy: remove bootstrap cache manifests that may block git (untracked or stale)"
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php bootstrap/cache/events.php bootstrap/cache/connector_module.php
git clean -fdq -- bootstrap/cache/ 2>/dev/null || true

# Trust github.com for git-over-SSH (first fetch on a new server fails without this).
mkdir -p "$HOME/.ssh"
chmod 700 "$HOME/.ssh"
ssh-keyscan -t rsa,ecdsa,ed25519 github.com >> "$HOME/.ssh/known_hosts" 2>/dev/null || true

git fetch "$GIT_REMOTE" "$DEPLOY_BRANCH"

if [ "$DEPLOY_SYNC_MODE" = "ff-only" ]; then
  echo "deploy: DEPLOY_SYNC_MODE=ff-only — safe update (fails if server has diverged or non-FF)"
  git merge --ff-only "${GIT_REMOTE}/${DEPLOY_BRANCH}"
else
  echo "deploy: DEPLOY_SYNC_MODE=reset — working tree = ${GIT_REMOTE}/${DEPLOY_BRANCH} (tracked files)"
  git reset --hard "${GIT_REMOTE}/${DEPLOY_BRANCH}"
fi

if [ "$DEPLOY_MIGRATE" = "1" ]; then
  echo "deploy: migrate"
  php artisan migrate --force --no-interaction
fi

# NOTE: do NOT write to .env from this script. The server's .env is
# manually managed by the sysadmin; the app's real secrets (including
# INVENTORY_CHECK_IMAP_*) are set directly on the server, not synced
# from GitHub. A previous revision attempted an upsert here and broke
# the deploy pipeline twice (BLOG_API_KEY and later IMAP_PASSWORD).
# If you need a new env var on the server, ask the sysadmin to add it
# to /www/playlist.nivessa.com/app/.env manually.

# Do not use config:cache or route:cache here — Closure routes break route:cache.
echo "deploy: post-git maintenance v2 — ONLY optimize:clear (no config:cache / route:cache)"
php artisan optimize:clear --no-interaction

# One-time: load the 6/23 distributor order quantities into events. Guarded by
# a flag file so it runs exactly once, then never again. Idempotent even if it
# did re-run (it sets the same values).
if [ ! -f "$DEPLOY_DIR/storage/app/.seeded-orders-0623f" ]; then
  echo "deploy: seeding event orders + street dates (one-time, revised plan)"
  php artisan events:seed-orders --no-interaction \
    && touch "$DEPLOY_DIR/storage/app/.seeded-orders-0623f"
fi

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

# Recycle PHP-FPM workers so new code actually goes live. optimize:clear and
# the OPcache endpoint above don't reliably refresh already-running FPM workers
# — changed controller/middleware classes keep serving stale bytecode until the
# workers restart. SIGKILL the workers we own; the root-owned FPM master
# respawns fresh ones immediately, and they recompile from the new code on disk.
# This is exactly what the manual "FPM kill workers" job did — now it runs on
# every deploy, so no manual recycle step is ever needed again.
ME=$(whoami)
echo "deploy: recycling php-fpm workers owned by $ME"
pkill -9 -u "$ME" -f 'php-?fpm.*pool' 2>/dev/null || true
sleep 3
for i in 1 2 3 4 5; do
  CODE=$(curl -s -o /dev/null -w "%{http_code}" -m 15 https://playlist.nivessa.com/login || echo TIMEOUT)
  echo "deploy: post-recycle smoke test attempt $i: HTTP $CODE"
  [ "$CODE" = "200" ] && break
  sleep 3
done

echo "deploy: done"
