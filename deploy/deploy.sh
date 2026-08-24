#!/usr/bin/env bash
#
# Deploy or update Colewan Drive on the VPS. Run as root:
#
#   bash /var/www/colewan-drive/deploy/deploy.sh
#
# Safe to re-run. provision.sh calls this for the initial deploy, so everything
# that has to happen on every release lives here and nowhere else.

set -euo pipefail

APP_DIR=/var/www/colewan-drive
BRANCH=main
PHP=8.3

say() { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }

[[ $EUID -eq 0 ]] || { echo "Run as root."; exit 1; }
[[ -d "$APP_DIR/.git" ]] || { echo "$APP_DIR is not a git checkout — run provision.sh first."; exit 1; }

cd "$APP_DIR"

# git runs as root here while the tree is owned by www-data, and git refuses to
# operate on another user's repository unless it is marked safe. Without this
# the release-stamp lookup at the end fails with "dubious ownership".
git config --global --get-all safe.directory 2>/dev/null | grep -qx "$APP_DIR" \
    || git config --global --add safe.directory "$APP_DIR"

# Composer and npm need a writable HOME for their caches. Without this they
# fall back to /var/www and litter the web root with dot-directories.
export COMPOSER_HOME=/var/www/.composer
export NPM_CONFIG_CACHE=/var/www/.npm
install -d -o www-data -g www-data "$COMPOSER_HOME" "$NPM_CONFIG_CACHE"

as_app() { runuser -u www-data -- env COMPOSER_HOME="$COMPOSER_HOME" NPM_CONFIG_CACHE="$NPM_CONFIG_CACHE" "$@"; }

# Whatever happens below, do not leave the site stuck behind the maintenance
# page. The trap fires on error and on a Ctrl-C alike.
already_down=$([[ -f storage/framework/down ]] && echo yes || echo no)
restore() {
    [[ $already_down == yes ]] || as_app php$PHP artisan up >/dev/null 2>&1 || true
}
trap restore EXIT

say "Enabling maintenance mode"
as_app php$PHP artisan down --render="errors::503" --retry=60 >/dev/null 2>&1 || true

say "Fetching $BRANCH"
git fetch --prune origin
git reset --hard "origin/$BRANCH"

# git ran as root, so hand the tree back before anything else touches it.
chown -R www-data:www-data "$APP_DIR"

say "Installing PHP dependencies"
as_app composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

# A .env freshly copied from the template has no APP_KEY, and everything below
# — migrate included — fails without one. This lives here rather than in
# provision.sh because artisan cannot run until composer has populated vendor/.
# Guarded so a re-deploy never rotates the key: that would make every existing
# session and password-reset token undecryptable.
if ! grep -qE '^APP_KEY=base64:' .env; then
    say "Generating APP_KEY"
    as_app php$PHP artisan key:generate --force
fi

say "Building front-end assets"
# The Vite toolchain lives in devDependencies, so this is a full install even
# though nothing from it ships. `npm ci` respects package-lock.json exactly.
as_app npm ci --no-audit --no-fund
as_app npm run build

say "Running migrations"
as_app php$PHP artisan migrate --force

say "Rebuilding caches"
as_app php$PHP artisan optimize:clear
as_app php$PHP artisan optimize

say "Fixing permissions"
# storage/ holds every uploaded file and bootstrap/cache holds the compiled
# config; both must stay writable by the FPM pool's user.
chown -R www-data:www-data storage bootstrap/cache
chmod -R u+rwX,go-rwx storage/app/private storage/app/tmp-uploads
chmod 600 .env

say "Reloading PHP-FPM"
# Not optional. The pool sets opcache.validate_timestamps=0, so PHP keeps
# serving the previous release's bytecode until the pool is reloaded — the
# deploy would appear to do nothing at all.
"php-fpm$PHP" -t
systemctl reload "php$PHP-fpm"

say "Restarting the queue worker"
# A running worker holds the old code in memory for the same reason.
systemctl restart colewan-drive-queue.service 2>/dev/null || true

say "Leaving maintenance mode"
as_app php$PHP artisan up
already_down=yes

say "Health check"
# Resolved to loopback so this tests the vhost on this box regardless of where
# public DNS currently points. Before certbot has run the answer is 200; after
# it, port 80 answers 301 to https and -L follows it through. Anything else —
# 500, 502, 404 — means the app did not come back up.
code=$(curl -sS -o /dev/null -L -w '%{http_code}' \
    --resolve "colewan-drive.salidumay.com:80:127.0.0.1" \
    --resolve "colewan-drive.salidumay.com:443:127.0.0.1" \
    http://colewan-drive.salidumay.com/up 2>/dev/null || echo 000)

if [[ $code == 200 ]]; then
    echo "/up -> HTTP 200"
else
    echo "/up -> HTTP $code — check /var/log/nginx/colewan-drive.error.log and journalctl -u php$PHP-fpm"
fi

printf '\n\033[1;32mDeployed.\033[0m %s\n' "$(git log -1 --format='%h %s')"
