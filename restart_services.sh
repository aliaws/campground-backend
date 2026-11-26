#!/usr/bin/env bash
#
# Restarts what serves the backend so new code is picked up:
#   - PHP-FPM (reload: finishes running requests, clears OPcache)
#   - queue workers (graceful restart after their current job)
#   - optional Supervisor programs and nginx
#
# Usage:  bash /path/to/campground-backend/restart_services.sh
#
# Settings (environment variables, all optional):
#   PHP_BIN=php                     PHP binary
#   PHP_FPM_SERVICE=php8.4-fpm      FPM service name (found automatically if unset)
#   SUPERVISOR_PROGRAMS="a b"       Supervisor programs to restart (e.g. queue workers)
#   RELOAD_NGINX=1                  also reload nginx
set -euo pipefail

PHP_BIN="${PHP_BIN:-php}"
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

step() { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m%s\033[0m\n' "$*" >&2; }

SUDO=""
if [ "$(id -u)" -ne 0 ] && command -v sudo >/dev/null 2>&1; then
    SUDO="sudo"
fi

cd "$APP_DIR"

# PHP-FPM
FPM="${PHP_FPM_SERVICE:-}"
if [ -z "$FPM" ] && command -v systemctl >/dev/null 2>&1; then
    FPM="$(systemctl list-units --type=service --all --no-legend 'php*-fpm*' 2>/dev/null \
        | awk '{print $1}' | grep -E '^php.*fpm' | sort -V | tail -1 || true)"
fi
if [ -n "$FPM" ]; then
    step "Reloading $FPM"
    $SUDO systemctl reload "$FPM" || $SUDO systemctl restart "$FPM"
else
    warn "No PHP-FPM service found. Set PHP_FPM_SERVICE=php8.4-fpm (or your version) if the site runs on FPM."
fi

# Queue workers stop after their current job; Supervisor / systemd starts them again.
step "Restarting queue workers"
"$PHP_BIN" artisan queue:restart

if [ -n "${SUPERVISOR_PROGRAMS:-}" ]; then
    step "Restarting Supervisor programs: $SUPERVISOR_PROGRAMS"
    # shellcheck disable=SC2086
    $SUDO supervisorctl restart $SUPERVISOR_PROGRAMS
fi

if [ "${RELOAD_NGINX:-0}" = "1" ]; then
    step "Reloading nginx"
    $SUDO nginx -t
    $SUDO systemctl reload nginx
fi

step "Backend services restarted"
