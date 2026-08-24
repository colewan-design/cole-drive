# Deploying Colewan Drive on the VPS

This app was built against shared hosting. This document covers what had to
change to run it on the VPS at `187.124.138.58`, and how to deploy it.

## The server it is going onto

Ubuntu 24.04, 2 vCPU, 7.8 GB RAM, 96 GB disk with 91 GB free, no swap.
nginx, PHP 8.3 and 8.4 FPM, MariaDB 10.11, PostgreSQL and Redis 7.0 are all
already running, along with composer, node 22 and certbot.

It is **not an empty box**. Five other sites live on it — goodboy, portfolio,
project-tracker, uniglobal, vault and xponent-global — and five of those are
Laravel apps sharing a single PHP-FPM pool that allows **five workers in
total**. Everything below is shaped by that: nothing in `deploy/` edits
`php.ini`, the shared `www` pool, or any existing vhost.

## What was actually blocking it

Four things would have stopped this app from working here, regardless of how it
was deployed.

**PHP has no sqlite3 extension.** The app defaults to `DB_CONNECTION=sqlite`
and `php8.3 -m` has no `sqlite3` on this server. Not a preference — the app
cannot boot as configured. Production runs on MariaDB (`colewan_drive`), and
sessions, cache and queue move to Redis so a slow upload transaction never
blocks a page load. Pointing all four at one SQLite file is where
`database is locked` comes from once requests overlap.

**Upload limits are 500x too small.** The app validates uploads at 1 GB.
The server's `upload_max_filesize` is 2M, `post_max_size` is 8M,
`max_input_time` is 60s, and nginx's default `client_max_body_size` is 1M.
Every upload over 2 MB would have died before Laravel saw it. These are raised
in a **dedicated FPM pool** rather than in `php.ini`, so the other five sites
don't inherit a 1 GB request cap they have no use for.

**Downloads would have taken the box down.** `Storage::download()` streams
every byte through the PHP worker. With five workers shared across five apps, a
couple of concurrent 1 GB downloads is most of the pool, held for the length of
the transfer — the other four sites stop responding. `FileController@download`
now emits `X-Accel-Redirect` and nginx serves the file itself: the worker is
released as soon as the header is sent, and Range requests (resumable
downloads) work without any code. This site also gets its own pool so its
uploads cannot starve the shared one.

**DNS points at the old host.** `colewan-drive.salidumay.com` resolves to
Hostinger shared-hosting addresses — several A records and several AAAA
records — none of which is this VPS. See the cutover section; this is the one
with a trap in it.

## Layout

```
deploy/nginx/colewan-drive.conf              → /etc/nginx/sites-available/
deploy/php-fpm/colewan-drive.conf            → /etc/php/8.3/fpm/pool.d/
deploy/systemd/colewan-drive-queue.service   → /etc/systemd/system/
deploy/systemd/colewan-drive-scheduler.*     → /etc/systemd/system/
deploy/env.production.example                → /var/www/colewan-drive/.env
deploy/provision.sh                          first-time setup, run once
deploy/deploy.sh                             every release
```

## First deploy

```bash
ssh root@187.124.138.58
git clone https://github.com/colewan-design/cole-drive.git /var/www/colewan-drive
bash /var/www/colewan-drive/deploy/provision.sh
```

`provision.sh` installs any missing PHP extensions, creates the database and a
user with a generated password, writes `.env`, installs the pool / vhost /
units, validates both nginx and FPM config before reloading them, then calls
`deploy.sh` and enables the background services.

It deliberately stops short of TLS, because that needs DNS first.

## DNS cutover

The record set currently points at Hostinger shared hosting — multiple A
records on `145.79.x.x` and multiple AAAA records on `2a02:4780:…`. **Delete
all of them**, then add:

```
A     colewan-drive   187.124.138.58
AAAA  colewan-drive   2a02:4780:59:353b::1      (or no AAAA at all)
```

The trap is leaving any of the old records behind. A stray A record round-robins
half your traffic back to the old host; a stray AAAA sends every IPv6-capable
client there. Either way you get a confusing half-migrated state where the site
looks fine in your browser — and **certbot's validation follows the same
records**, so the certificate request fails for reasons the error message will
not explain.

Confirm before continuing:

```bash
getent hosts colewan-drive.salidumay.com     # expect 187.124.138.58 / 2a02:4780:59:353b::1
```

Then issue the certificate:

```bash
certbot --nginx -d colewan-drive.salidumay.com
```

Certbot rewrites the vhost with the TLS block and the http→https redirect, the
same way the other sites on this box were set up.

## Moving the existing data across

Uploads live in `storage/app/private/uploads` on the old host, and the file
table lives in its SQLite database. Copy the files first:

```bash
# from the old shared host
rsync -avz storage/app/private/uploads/ \
    root@187.124.138.58:/var/www/colewan-drive/storage/app/private/uploads/

# on the VPS
chown -R www-data:www-data /var/www/colewan-drive/storage/app/private
```

For the rows, prefer exporting the old `files` table into MariaDB — that keeps
each file's original name, which only ever existed in the database. If the
SQLite file is gone or unreadable, `files:rebuild` reconstructs the table from
what is on disk:

```bash
runuser -u www-data -- php8.3 /var/www/colewan-drive/artisan files:rebuild --dry-run
```

It reuses each stored filename's UUID as the row's UUID, so **share links handed
out before the move keep working**. What it cannot recover is original
filenames; it falls back to the stored name, and the dashboard's rename tool
fixes those individually. Run without `--dry-run` to apply.

Finally, create the login if the database is new:

```bash
runuser -u www-data -- php8.3 /var/www/colewan-drive/artisan db:seed
```

The seeder's password is `changeme123`. Change it at `/profile` immediately.

## Routine deploys

```bash
bash /var/www/colewan-drive/deploy/deploy.sh
```

Maintenance mode on, `git reset --hard origin/main`, composer install, `npm ci`
and vite build, migrate, rebuild caches, fix permissions, reload FPM, restart
the queue worker, maintenance mode off, health check. A trap lifts maintenance
mode even if a step fails partway.

The FPM reload is not cosmetic: the pool sets `opcache.validate_timestamps=0`,
so without it PHP keeps serving the previous release's bytecode and the deploy
looks like it did nothing.

## Verifying it works

```bash
curl -I https://colewan-drive.salidumay.com/up          # 200

# the whole point — nginx should serve the bytes, not PHP
curl -sI https://colewan-drive.salidumay.com/d/<uuid> | grep -i -E 'content-length|accept-ranges'
```

`Accept-Ranges: bytes` on a download response is the signal that
`X-Accel-Redirect` took effect. If PHP were still streaming it, that header
would be absent. A resumable download is the user-visible version of the same
check:

```bash
curl -r 0-1023 -o /dev/null -w '%{http_code}\n' https://colewan-drive.salidumay.com/d/<uuid>   # 206
```

Then upload something over 100 MB through the UI. That exercises nginx's body
limit, the pool's `post_max_size`, and `max_input_time` in one go — the three
settings that most often disagree.

## When something is wrong

| Symptom | Where to look |
| --- | --- |
| 413 on upload | `client_max_body_size` in the vhost |
| Upload dies silently, empty `$_FILES` | `post_max_size` must exceed `upload_max_filesize` |
| Large upload fails after ~1 min | `max_input_time` in the pool |
| Download returns 404, nothing in the Laravel log | `xaccel_prefix` and the vhost's `location ^~` disagree |
| Download returns the PHP source | the `^~` modifier was dropped — the `\.php$` regex is winning the match |
| Deploy appears to change nothing | php-fpm was not reloaded (`opcache.validate_timestamps=0`) |
| 502 | `journalctl -u php8.3-fpm`, and check the socket path matches the pool |
| `artisan tinker` refuses to start | www-data's `$HOME` (`/var/www`) is not writable by it; run `runuser -u www-data -- env HOME=/tmp php8.3 artisan tinker` |

Logs: `/var/log/nginx/colewan-drive.error.log`,
`/var/log/php8.3-fpm-colewan-drive.log`, `storage/logs/laravel-*.log`,
`journalctl -u colewan-drive-queue`.

## Rollback

```bash
cd /var/www/colewan-drive
git reset --hard <previous-sha>
bash deploy/deploy.sh
```

Migrations are not rolled back automatically — check whether the release you
are leaving added any before assuming this is enough.

## Deliberately not done

**`FileController@index` is not scoped to the current user.** It lists every
row in the table, and `update`/`destroy` accept any `File` without an ownership
check. Today that is harmless: registration is disabled, `routes/auth.php` has
no register route, and the seeder creates one account. It becomes a real hole
the moment a second user exists, so scope those three methods before adding
one.

**No trusted-proxy configuration.** nginx talks to PHP over FastCGI here, not
as an HTTP proxy, and `fastcgi_params` already passes the scheme through
correctly. If Cloudflare or another proxy is ever put in front, `TrustProxies`
has to be configured in `bootstrap/app.php` or every client IP in the logs
becomes the proxy's.

**`disk_total_space()` reports the whole 96 GB volume**, which is shared with
the five other sites. The dashboard's storage figure is therefore the server's
free space, not a quota for this app.
