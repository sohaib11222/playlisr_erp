#!/usr/bin/env bash
# Server-side Laravel deploy. Intended path on production: /www/playlist.nivessa.com/app/scripts/deploy.sh
# Env (optional): DEPLOY_DIR, DEPLOY_BRANCH, DEPLOY_MIGRATE (0|1)
set -euo pipefail

DEPLOY_DIR="${DEPLOY_DIR:-/www/playlist.nivessa.com/app}"
DEPLOY_BRANCH="${DEPLOY_BRANCH:-main}"
DEPLOY_MIGRATE="${DEPLOY_MIGRATE:-0}"

cd "$DEPLOY_DIR"

if [ ! -f artisan ]; then
  echo "deploy: artisan not found in $DEPLOY_DIR — wrong directory?"
  exit 1
fi

echo "deploy: $(date -u) — dir=$DEPLOY_DIR branch=$DEPLOY_BRANCH"

# Update code only (fast). Fails if merge is required — avoids overwriting uncommitted server edits.
git fetch origin "$DEPLOY_BRANCH"
git merge --ff-only "origin/$DEPLOY_BRANCH"

if [ "$DEPLOY_MIGRATE" = "1" ]; then
  echo "deploy: migrate"
  php artisan migrate --force --no-interaction
fi

echo "deploy: cache (Laravel 5.8 — no view:cache)"
php artisan config:cache
php artisan route:cache

php artisan queue:restart 2>/dev/null || true

echo "deploy: done"
