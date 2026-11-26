#!/usr/bin/env bash
#
# Backend deploy: pull the deploy branch, install dependencies, run
# migrations, rebuild Laravel's caches, then restart services.
#
# Usage (on the server, from anywhere):
#   bash /path/to/campground-backend/deploy.sh
#
# Settings (environment variables, all optional):
#   BRANCH=main            branch to deploy
#   PHP_BIN=php            PHP binary
#   COMPOSER_BIN=composer  Composer binary
#   SKIP_COMPOSER=1        skip composer install
#   SKIP_RESTART=1         don't run restart_services.sh at the end
#   CLEAR_APP_CACHE=1      also empty the application cache (Redis). Off by
#                          default: it holds Lead Connector token data and
#                          locks, and migrations clear the keys they change.
set -euo pipefail

BRANCH="${BRANCH:-main}"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

step() { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }

cd "$APP_DIR"

step "Pulling $BRANCH in $APP_DIR"
if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    echo "Local changes to tracked files on the server. Commit or discard them first:" >&2
    git status --short --untracked-files=no >&2
    exit 1
fi
git fetch origin "$BRANCH"
git checkout "$BRANCH"
git pull --ff-only origin "$BRANCH"
git log -1 --format='Now at %h  %s  (%cr)'

if [ "${SKIP_COMPOSER:-0}" != "1" ]; then
    step "Installing PHP dependencies"
    "$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction --prefer-dist
fi

step "Running migrations"
"$PHP_BIN" artisan migrate --force

step "Clearing old caches"
"$PHP_BIN" artisan config:clear
"$PHP_BIN" artisan route:clear
"$PHP_BIN" artisan view:clear
"$PHP_BIN" artisan event:clear
"$PHP_BIN" artisan clear-compiled
if [ "${CLEAR_APP_CACHE:-0}" = "1" ]; then
    "$PHP_BIN" artisan cache:clear
fi

step "Building caches"
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache
"$PHP_BIN" artisan event:cache

if [ ! -e public/storage ]; then
    step "Linking public storage"
    "$PHP_BIN" artisan storage:link
fi

if [ "${SKIP_RESTART:-0}" != "1" ]; then
    bash "$APP_DIR/restart_services.sh"
fi

step "Backend deploy finished"
