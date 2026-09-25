PrestaShop Docker stack
=======================

Docker Compose stack for a PrestaShop shop, usable for local development and
for simple production deployments (a single server, better than shared
hosting). Maintained by [BillMySales](https://www.billmysales.com).

| Component  | Image                                         | Default version      |
|------------|-----------------------------------------------|----------------------|
| Web server | `caddy:<ver>-alpine`                          | 2.11                 |
| PrestaShop | built from `image/` (`php:<ver>-fpm-alpine`)  | 9.1.5 Classic / PHP 8.5 |
| Database   | `mariadb`                                     | 12.3 (LTS)           |
| Mailpit    | `axllent/mailpit` (optional, dev)             | v1.31                |

Why an own image: the vendor image (`prestashop/prestashop`) is built on an
old PHP base (8.5.0 at the time of writing, 10 patch releases behind) and is
1.7 GB. `image/Dockerfile` follows the vendor's recipe
([PrestaShop/docker](https://github.com/PrestaShop/docker)) on the official
`php:<ver>-fpm-alpine` image: current PHP, 542 MB, and the official
[PrestaShop Classic](https://github.com/PrestaShopCorp/prestashop-classic)
distribution zip, verified with SHA-256. PHP 8.5 is PrestaShop 9.1's
recommended version. The image adds `icu-data-full` (Alpine's ICU has
English locale data only, unlike the vendor's Debian image): the back
office's Symfony number, money and date fields follow the employee's
language (`9.990,50` in Spanish, not `9,990.50`).

Requirements
------------

- Docker Engine 24+ with the Compose v2 plugin (`docker compose`, 2.24+).
- Development: ports 8102, 8402 and 8025 free on the host.
- Production: a server with ports 80 and 443 reachable, and a DNS record for
  the shop's domain pointing to it.
- The first `up` builds the image (a few minutes: PHP extensions are compiled).

Quick start (development)
-------------------------

```shell
cp .env.dev.example .env
docker compose up -d
docker compose logs -f setup   # wait for "==> Done"
```

- Shop: http://localhost:8102
- Back office: http://localhost:8102/admin-dev/ (`admin@example.com` / `admin12345`)
- Mailpit (every email the shop sends): http://localhost:8025

Production
----------

```shell
cp .env.prod.example .env
# Fill in PS_URL, SITE_ADDRESS, DB_PASSWORD, DB_ROOT_PASSWORD,
# PS_ADMIN_EMAIL, PS_ADMIN_PASSWORD, PS_FOLDER_ADMIN and the SMTP_* values.
docker compose up -d
```

- With `SITE_ADDRESS` set to the domain, Caddy gets a Let's Encrypt certificate
  and renews it automatically (certificates live in the `caddy_data` volume).
- Behind an existing Traefik (no host ports), use `overrides/traefik.yaml`
  (see [Overrides](#overrides)).
- Compose refuses to start while a required value is missing.
- Use a hard-to-guess back office directory (`PS_FOLDER_ADMIN`).
- The `backup` profile is enabled by default in the production template.

Services
--------

| Service      | Profile   | Role                                                          |
|--------------|-----------|---------------------------------------------------------------|
| `db`         |           | MariaDB, data in the `db_data` volume.                        |
| `prestashop` |           | PHP-FPM + PrestaShop (port 9000, internal), files in `ps_data`.|
| `caddy`      |           | Web server and TLS, the only published ports (80, 443).       |
| `setup`      |           | One-shot job (`scripts/setup.sh`), runs on every `up`.        |
| `backup`     | `backup`  | DB dump + shop files archive on a schedule.                   |
| `mailpit`    | `mailpit` | Development SMTP server that catches all mail.                |
| `console`    | `tools`   | PrestaShop's `bin/console`, not started by `up`.              |

Optional services are enabled with `COMPOSE_PROFILES` in `.env`, e.g.
`COMPOSE_PROFILES=backup`.

PrestaShop's core doesn't need a cron job or Redis, so the stack has neither.
Modules that need scheduled tasks (e.g. currency rates) can be triggered from
the host's cron with `docker compose exec prestashop php <script>`.

### What `setup` does

- Copies PrestaShop from the image to the `ps_data` volume if it is empty.
- If the shop isn't installed: runs the official CLI installer (no demo
  products) with `PS_LANGUAGE`, `PS_COUNTRY`, `PS_TIMEZONE` and `PS_SHOP_NAME`,
  then deletes the `install` directory.
- Renames the back office directory to `PS_FOLDER_ADMIN` (change it any time).
- On every run, sets the shop domain and SSL from `PS_URL` and, when
  `SMTP_HOST` is set, the SMTP settings from `SMTP_*` (`scripts/configure.php`).
- Applies one-time initial settings (friendly URLs) and stores
  `DOCKER_STACK_INITIALIZED`; later changes in the back office are kept.
- Clears the cache only when something changed.

Common commands
---------------

```shell
docker compose ps                                  # every service "healthy", setup "Exited (0)"
docker compose logs -f caddy prestashop            # web server and PHP logs
docker compose run --rm console prestashop:module list
docker compose run --rm console prestashop:config get PS_SHOP_NAME
docker compose exec db mariadb -u prestashop -p prestashop   # SQL shell
docker compose down                                # stop, keep data
docker compose down -v                             # stop and DELETE all data
```

Backups
-------

With the `backup` profile, the `backup` service writes
`<timestamp>-db.sql.gz` and `<timestamp>-files.tar.gz` (the whole shop
directory except caches and logs) to the `backups` volume (or `./data/backups`
with `overrides/local-dirs.yaml`) at start and then every
`BACKUP_INTERVAL_HOURS`, and deletes files older than `BACKUP_KEEP_DAYS`.
Files are readable by their owner only.

```shell
docker compose run --rm --no-deps backup now                  # back up now
docker compose run --rm --no-deps backup list                 # list timestamps
docker compose stop prestashop                      # recommended while restoring
docker compose run --rm --no-deps backup restore <timestamp>  # restore DB and shop files
docker compose start prestashop
```

`--no-deps` keeps the command from starting `setup` first (with damaged
data `setup` fails and the restore would never run); the database must
be running (`docker compose up -d db` if the stack is down).

Overrides
---------

Optional compose files in `overrides/`, enabled with `COMPOSE_FILE` in `.env`
(several are combined with `:`). Each file documents its variables.

```shell
COMPOSE_FILE=compose.yaml:overrides/traefik.yaml:overrides/local-dirs.yaml
```

| File                         | Purpose                                                           |
|------------------------------|-------------------------------------------------------------------|
| `overrides/traefik.yaml`     | Publish through an existing Traefik on a shared external network: |
|                              | no host ports, Traefik terminates TLS (`TRAEFIK_HOST`, ...).      |
| `overrides/local-dirs.yaml`  | Database, shop files, Caddy and backups in local directories      |
|                              | (`DATA_DIR`, default `./data`) instead of named volumes.          |
| `overrides/module.yaml`      | Mount a module from a local directory, editable live              |
|                              | (`MODULE_PATH`, `MODULE_NAME`).                                   |

A local `compose.override.yaml` (gitignored) is also loaded automatically by
Docker Compose, for changes specific to one machine.

Configuration
-------------

Every variable is documented in `.env.prod.example`. Main groups:

- **Site and network**: `PS_URL`, `SITE_ADDRESS`, `HTTP_BIND`, `HTTP_PORT`,
  `HTTPS_PORT`.
- **Credentials and back office**: `DB_PASSWORD`, `DB_ROOT_PASSWORD`,
  `PS_ADMIN_EMAIL`, `PS_ADMIN_PASSWORD`, `PS_FOLDER_ADMIN` (required).
- **Shop** (first install only): `PS_SHOP_NAME`, `PS_LANGUAGE`, `PS_COUNTRY`,
  `PS_TIMEZONE`.
- **Versions**: `PS_VERSION` + `PS_SHA256` (Classic distribution), `PHP_VERSION`,
  `PS_IMAGE`, `CADDY_VERSION`, `MARIADB_VERSION`.
- **PHP**: `PS_DEV_MODE`, `PHP_MEMORY_LIMIT`, `UPLOAD_MAX_SIZE` (PHP and
  Caddy), `PHP_FPM_MAX_CHILDREN` and the rest of the FPM pool.
- **Mail**: `SMTP_HOST`, `SMTP_PORT`, `SMTP_SECURE`, `SMTP_USER`,
  `SMTP_PASSWORD`, `SMTP_FROM` (shop email address).
- **Resources and logs**: `*_MEMORY_LIMIT` per service, `LOG_MAX_SIZE`,
  `LOG_MAX_FILE` (Docker log rotation).

Files:

| File                                        | Purpose                                              |
|---------------------------------------------|------------------------------------------------------|
| `image/Dockerfile`, `image/opcache.ini`     | PrestaShop image.                                    |
| `config/caddy/Caddyfile`                    | Web server, TLS, rewrites, security headers, blocked paths. |
| `config/prestashop/defines_custom.inc.php`  | Debug mode from `PS_DEV_MODE` (copied by `setup`).   |
| `config/php/php.ini`                        | PHP limits, from env vars.                           |
| `config/php/fpm-pool.conf`                  | PHP-FPM pool sizing, from env vars.                  |
| `scripts/setup.sh`, `scripts/configure.php` | Install and environment (domain, SSL, SMTP).         |
| `scripts/backup.sh`                         | Backups and restore.                                 |

Notes:

- `PS_VERSION` only matters when the `ps_data` volume is created; afterwards
  PrestaShop is upgraded from the back office (the Update Assistant module).
  Rebuilding the image with a new `PHP_VERSION` updates PHP for an existing shop.
- The shop domain and SSL come from `PS_URL`: changing the domain or port only
  needs `docker compose up -d`.
- `.htaccess` files are ignored; the equivalent rules (friendly image URLs,
  webservice API, uploads through the front controller, back office routing,
  blocked directories) are in the Caddyfile, translated from PrestaShop's
  official nginx configuration.
- From inside the containers, the host machine is reachable as
  `host.docker.internal`.

Security
--------

- Client IP headers: PHP gets only the real client IP (as Caddy sees it) in
  `REMOTE_ADDR`, `X-Forwarded-For` and `X-Real-IP`, and no `Client-Ip`,
  `Cf-Connecting-Ip` or `X-Forwarded-Port` (a client could forge them): PrestaShop reads
  `X-Forwarded-For` when the peer is a private address.
- No default secrets: compose fails if the required passwords are missing. The
  development template uses public passwords; never use it on a server.
- PHP errors are never shown to visitors (`display_errors` off unless
  `PHP_DISPLAY_ERRORS=On`, only in the development template); they go to
  `docker compose logs`.
- Production defaults: debug off, back office in a custom directory, the
  `install` directory removed, PHP version not exposed, source directories,
  templates, logs, dotfiles and repository files blocked, no PHP execution in
  `img` and `upload`, `X-Content-Type-Options`, `X-Frame-Options` and
  `Referrer-Policy` headers.
- PHP gets the real client IP in `REMOTE_ADDR` (logs, login protection) also
  behind Traefik or another proxy on a private network.
- Only Caddy (and Mailpit in development) publishes ports; the database is
  internal. `HTTP_BIND` defaults to `127.0.0.1`.
- Not included: a web application firewall, login rate limiting, or off-site
  backup copies.

Validation
----------

What was checked for this stack (2026-09-24):

- Clean start (`down -v` + `up -d`, image already built) in about 50 s: every
  service `healthy`, `setup` `Exited (0)`; a second run makes no changes.
- Shop `200` in Spanish with CLP, Chile and `America/Santiago`; back office
  login; webservice `401` without credentials; friendly product and category
  image URLs; theme and back office assets; blocked paths `403`.
- A setting changed in the back office survives `setup`; changing `PS_URL`
  moves the shop to the new domain.
- SMTP delivered to Mailpit; backup, retention and restore.
- HTTPS with `SITE_ADDRESS=localhost` (Caddy internal CA, HTTP/2);
  production defaults (debug off, random back office directory).
- Overrides: Traefik v3.6 routing with no host ports and HTTPS links, local
  directories (including backups), a module mounted, installed and served.
- Not tested: issuing a real Let's Encrypt certificate (needs a public domain).

Resource usage
--------------

Idle, after a few requests: Caddy ~18 MiB, PHP-FPM ~220 MiB, MariaDB
~170 MiB, backup ~7 MiB between runs.

License
-------

[MIT](LICENSE).
