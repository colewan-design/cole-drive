#!/usr/bin/env bash
#
# First-time setup for Colewan Drive on the VPS. Run once, as root:
#
#   bash deploy/provision.sh
#
# Idempotent: every step checks for what it is about to create, so a re-run
# after a failure picks up where it stopped rather than clobbering state.
#
# This box already serves five other sites. Nothing here edits shared config —
# no changes to php.ini, to the shared www FPM pool, or to any existing vhost.
# The only shared surface touched is `nginx -t && systemctl reload nginx` at the
# end, and the config is validated before the reload.

set -euo pipefail

APP_DIR=/var/www/colewan-drive
APP_REPO=https://github.com/colewan-design/cole-drive.git
APP_DOMAIN=colewan-drive.salidumay.com
DB_NAME=colewan_drive
DB_USER=colewan_drive
PHP=8.3
REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

say() { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m !! %s\033[0m\n' "$*"; }

[[ $EUID -eq 0 ]] || { echo "Run as root."; exit 1; }

say "Checking PHP $PHP extensions"
# gd/intl/zip/bcmath are already present on this box; sqlite3 is deliberately
# absent and stays that way — production runs on MariaDB.
missing=()
for ext in mysql redis mbstring xml curl zip bcmath gd intl opcache fpm; do
    dpkg -s "php$PHP-$ext" >/dev/null 2>&1 || missing+=("php$PHP-$ext")
done
if ((${#missing[@]})); then
    say "Installing: ${missing[*]}"
    apt-get update -qq
    apt-get install -y "${missing[@]}"
else
    echo "All required extensions present."
fi

say "Preparing $APP_DIR"
if [[ ! -d "$APP_DIR/.git" ]]; then
    git clone "$APP_REPO" "$APP_DIR"
else
    echo "Repository already cloned."
fi

# Half-finished 1 GB uploads land here instead of /tmp, and on the same
# filesystem as storage so PHP's final move is a rename, not a copy.
install -d -o www-data -g www-data -m 0750 "$APP_DIR/storage/app/tmp-uploads"
install -d -o www-data -g www-data -m 0750 "$APP_DIR/storage/app/private/uploads"

say "Creating MariaDB database and user"
if mysql -N -e "SELECT 1 FROM mysql.user WHERE user='$DB_USER' AND host='localhost'" | grep -q 1; then
    echo "User $DB_USER already exists; leaving its password alone."
    DB_PASS=""
else
    DB_PASS="$(openssl rand -base64 30 | tr -d '/+=' | head -c 32)"
    mysql -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    mysql -e "CREATE USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
    mysql -e "GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';"
    mysql -e "FLUSH PRIVILEGES;"
    echo "Database and user created."
fi

say "Writing .env"
if [[ -f "$APP_DIR/.env" ]]; then
    echo ".env already exists; leaving it untouched."
else
    cp "$REPO_DIR/deploy/env.production.example" "$APP_DIR/.env"
    [[ -n "$DB_PASS" ]] && sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=$DB_PASS|" "$APP_DIR/.env"
    chown www-data:www-data "$APP_DIR/.env"
    # Readable by its owner only: it holds APP_KEY and the database password.
    chmod 600 "$APP_DIR/.env"
    echo ".env written from deploy/env.production.example."
fi

say "Installing PHP-FPM pool"
install -m 0644 "$REPO_DIR/deploy/php-fpm/colewan-drive.conf" \
    "/etc/php/$PHP/fpm/pool.d/colewan-drive.conf"

say "Installing nginx site"
install -m 0644 "$REPO_DIR/deploy/nginx/colewan-drive.conf" \
    /etc/nginx/sites-available/colewan-drive
ln -sfn /etc/nginx/sites-available/colewan-drive /etc/nginx/sites-enabled/colewan-drive

say "Installing systemd units"
install -m 0644 "$REPO_DIR/deploy/systemd/colewan-drive-queue.service" /etc/systemd/system/
install -m 0644 "$REPO_DIR/deploy/systemd/colewan-drive-scheduler.service" /etc/systemd/system/
install -m 0644 "$REPO_DIR/deploy/systemd/colewan-drive-scheduler.timer" /etc/systemd/system/
systemctl daemon-reload

say "Validating and reloading php-fpm"
# Catches a typo in the new pool before it can take the shared FPM service down
# with it — a bad pool file stops php-fpm entirely, and five other sites run on
# this same service.
"php-fpm$PHP" -t
systemctl reload "php$PHP-fpm"

say "Validating and reloading nginx"
nginx -t
systemctl reload nginx

say "Running the application deploy"
bash "$REPO_DIR/deploy/deploy.sh"

say "Enabling background services"
systemctl enable --now colewan-drive-queue.service
systemctl enable --now colewan-drive-scheduler.timer

cat <<NEXT

$(printf '\033[1;32mProvisioned.\033[0m') Two things are still needed, in this order:

  1. DNS. $APP_DOMAIN still points at Hostinger shared hosting.
     Delete every existing A and AAAA record for it, then add:

        A     colewan-drive   187.124.138.58
        AAAA  colewan-drive   2a02:4780:59:353b::1     (or no AAAA at all)

     Leaving any old record behind is the trap: traffic round-robins back to
     the shared host, and certbot's own validation follows the same records.

  2. TLS, once DNS has propagated (getent hosts $APP_DOMAIN):

        certbot --nginx -d $APP_DOMAIN

     Certbot rewrites the vhost with the TLS block and redirect, matching the
     other sites here.

NEXT

if [[ -n "$DB_PASS" ]]; then
    warn "Database password was generated and written to $APP_DIR/.env — it is not stored anywhere else."
fi
