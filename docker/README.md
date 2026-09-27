# Docker stack for KLMS-for-SSP + IDM-for-DoT

A podman-compose stack that runs the KLMS-for-SSP fork (branch `main`) and the
IDM-for-DoT fork (branch `develop`) as a self-contained test environment.

## Quick start

**Option 1 — first-time helper (non-interactive, default):**
```bash
cd docker/
./setup.sh
```
If `docker/.env` does not exist, `setup.sh` generates a WORKING one with **no
prompts**: random secrets (`openssl rand -hex 32`) for `APP_SECRET`,
`IDM_APP_SECRET`, the IDM API key and the first admin password, an
auto-detected `SITE_BASE_HOST` (host LAN IP + `:8094`), and the other sane
defaults from `.env.example`. The generated secrets are **persisted** to `.env`,
so re-runs do NOT rotate them (an existing `.env` is reused as-is). `.env` is
gitignored. The first admin's password is printed at the end of the run.

The old interactive wizard (asks for every value) is kept behind `./setup.sh --ask`.

**Option 2 — you already have a `.env`:**
```bash
cd docker/
cp .env.example .env        # test values are fine for local
cd ..

# Build both images (the IDM image is built from the separate IDM clone — see below)
podman build -f docker/idm/Dockerfile -t klms-dot-idm \
    /home/max/.hermes/cache/scratch/klms-dot/IDM-for-DoT
podman-compose build        # builds klms (context = this worktree)

podman-compose up -d
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8094/
```

> **Schema bootstrap (automatic, race-free):** the databases are created by `initdb/setup.sh`
> (runs on first start). The **tables** are created automatically by the entrypoints
> (`klms-entrypoint.sh` / `idm-entrypoint.sh`) on first boot: each checks for a
> **marker file** (`klms-schema.done`, on the shared `klms-var`/`idm-var` volume) and,
> if it is missing, runs `doctrine:schema:create` before starting its process, then
> writes the marker. (A "0 tables" check is NOT used: the messenger transport
> auto-creates `messenger_messages` during the boot, so a fresh DB already has 1+
> tables and a table-count check would skip the bootstrap.) The KLMS image runs as
> **two** containers (web + worker), so on a fresh volume both would see no marker —
> the bootstrap is therefore wrapped in `flock()` on a lock file in the **shared**
> `klms-var` volume, so only ONE of the two containers ever runs
> `doctrine:schema:create` (no duplicate-sequence race). On every later boot the
> marker exists and the step is skipped (a no-op — it never touches the
> `messenger_messages` queue on a running stack). The marker lives in the named
> volume, so it is wiped together with `pgdata` on `down -v` and a fresh volume
> re-bootstraps. So a fresh `up -d` just works; no manual `schema:create` needed.

> **Why the IDM image is built separately:** the IDM fork's code is NOT in the KLMS
> worktree. `podman-compose build` resolves a service's `dockerfile` path *relative to
> its build context*, so it cannot build an image whose Dockerfile lives outside the
> context. The `idm` service therefore references a pre-built `klms-dot-idm` image
> (`image:` instead of `build:`); build it with the `podman build -f` command above.

## First-time setup (clone → admin UI)

A complete, from-scratch run: from a fresh clone to logging into the admin UI and
configuring from there. (The Quick start above is the short form; this is the full
walkthrough. Refine freely.)

### 1. Clone

```bash
git clone https://github.com/Maggsi/KLMS-for-SSP.git && cd KLMS-for-SSP
# The IDM fork's code is NOT in this repo — clone it separately (build context):
git clone https://github.com/mrhund/IDM-for-DoT.git
```

Requires `podman` + `podman-compose` (docker 29 has **no** compose plugin — never
`docker compose`).

### 2. Build the two images

```bash
podman build -f docker/idm/Dockerfile -t klms-dot-idm /path/to/IDM-for-DoT
podman build -f docker/klms/Dockerfile -t klms-dot_klms .
```

### 3. Set up the environment

```bash
cd docker/
./setup.sh
```

`setup.sh` generates `docker/.env` **with no prompts** (random secrets via
`openssl rand -hex 32` for `APP_SECRET`/`IDM_APP_SECRET`/the IDM API key/the
first admin password, an auto-detected `SITE_BASE_HOST`, the other sane defaults
from `.env.example`), writes it (mode 600), and runs `podman-compose up -d`.
The generated secrets are persisted, so re-runs do NOT rotate them. The first
admin's password is printed at the end — keep it. (Prefer to be asked for each
value? `./setup.sh --ask`. Prefer manual? `cp .env.example .env`, edit,
`podman-compose up -d`.)

### 4. First boot (automatic)

On first boot postgres runs `initdb/setup.sh` (DBs + two-tier roles), the
entrypoints **auto-create the schema** (no marker file → `doctrine:schema:create` →
start; `flock`-guarded so only one of the two KLMS containers runs it), and the
**idm entrypoint runs the first-run init** (IDM API key + default admin user —
both guarded by skip-if-exists checks, so re-runs are a no-op). A fresh `up -d`
just works. Give it a minute, then:

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://<SITE_BASE_HOST>/   # → 200
```

### 5. Your first admin (already created — just log in)

The first-run init (step 4) already created the IDM API key (value =
`KLMS_IDM_APIKEY` from `docker/.env`) and the default admin user
(`KLMS_ADMIN_EMAIL` / `KLMS_ADMIN_PASSWORD` from `docker/.env`, `--confirmed`).
A fresh stack has **no users** and no self-signup, so `app:user:create` is the
only way in — the entrypoint just runs it for you, guarded by
`app:apikeys:list` / `app:user:list` so it is idempotent.

> **Manual alternative (if you skip the first-run init vars):**
> ```bash
> # (a) the API key KLMS uses to reach IDM — value MUST MATCH docker/.env KLMS_IDM_APIKEY
> podman exec klms-dot_idm_1 php bin/console app:apikeys:create klms <your-api-key>
> # (b) your admin user (--confirmed so it's not stuck in the email-verification flow)
> podman exec klms-dot_idm_1 php bin/console app:user:create you@example.com <password> <nickname> --confirmed
> ```
> The API key is the linchpin: the same value lives in `docker/.env`
> (`KLMS_IDM_APIKEY`) and must exist in IDM's `api_key` table, or login fails.

### 6. Log in to the admin UI

Open `http://<SITE_BASE_HOST>/` → the login form (field is `username`, i.e. your
email) → enter the email + password from step 5 → you land in the admin UI at
`/admin`.

### 7. Configure from there

From the admin UI set the site's real values (title, logo, mail sender if using a
real `MAILER_DSN`, recaptcha, …) via the Settings (Einstellungen) screens, then do
normal CMS administration (pages, users, news).

### The two things that bite a first-timer
1. **The API key must match in both places** (`docker/.env` and IDM) — the #1
   "login fails" cause. The first-run init handles this for you (it creates the
   IDM key with the exact `KLMS_IDM_APIKEY` value from `.env`); if you create it
   manually, keep the two values in sync.
2. **The first user is created via the IDM console** — a fresh instance has no
   self-signup, so `app:user:create` is the only way in. The first-run init runs
   it for you (guarded by skip-if-exists); if you skip it, run it manually.

> **Production note:** this is the bigboy test stack (port 8094, `null://null`
> mail). For a real deploy, point `SITE_BASE_HOST`/`MAILER_DSN` at production values
> and put a reverse proxy (nginx/Traefik) in front — swapping `php -S` for
> `php-fpm` is only an entrypoint + service change.

## Services

| Service     | Port | Purpose                                   |
|-------------|------|-------------------------------------------|
| postgres    | 5432 | `klms_dot` + `idm_dot` databases         |
| idm         | 8095 | IDM-for-DoT REST API (internal only)     |
| klms        | 8094 | KLMS-for-SSP web tier (php -S)          |
| klms-worker | —    | messenger worker (internal only)         |

The `idm` and `klms-worker` ports are **not** exposed on the host — KLMS reaches
the IDM over the internal docker network (`http://idm:8095`). Only `klms` (8094)
is published.

## Security hardening

- **Non-root PHP processes:** both PHP images (`klms` + `idm`) create an
  unprivileged user (`appuser`, uid 1000) and run as it (`USER appuser` before
  the entrypoint). The writable dirs are chowned to that user in the image
  (`/app/var`, plus `/app/public/{images,media,uploads}` for klms); the runtime
  named volumes inherit this ownership on first mount (empty volume → image
  content copied in), so the first-boot schema bootstrap
  (`doctrine:schema:create`, which writes `var/cache`) and static-file serving
  work as the non-root user.
- **Dropped capabilities:** every service in `compose.yaml` sets
  `cap_drop: [ALL]` + `security_opt: [no-new-privileges:true]`.
- **Postgres keeps root init:** the `postgres` service does **not** set a `user:`
  override — the postgres image drops to the `postgres` user itself via a
  non-setuid gosu invoked by root; forcing a user would break data-dir init.
  It still gets `cap_drop: [ALL]` + `security_opt`, **plus a minimal `cap_add`**
  (`CHOWN, FOWNER, DAC_OVERRIDE, SETUID, SETGID`) because `cap_drop: [ALL]`
  otherwise strips the caps that entrypoint's root-init needs (chown the data
  dir + gosu's setuid/setgid).
- Deliberately **not** done (over-engineering for this stack): read-only rootfs
  and a custom seccomp profile.

The `klms` image is run as **two containers** (one process per container — no
supervisor inside the container):
1. `klms` — `php -S 0.0.0.0:8094 -t /app/public /app/docker/klms-router.php` (web tier)
2. `klms-worker` — `php bin/console messenger:consume async --time-limit=3600` (the worker)

The worker exits after its `--time-limit`; `restart: unless-stopped` brings a
fresh one up (the same "one worker" invariant as the VM's supervisor setup).
Each container has its own healthcheck: the web tier is probed over HTTP, the
worker over its process list — a dead tier can no longer mask as healthy.

## Performance tuning (cheap wins)

Applied in this worktree (branch `perf-cheap-wins`):

- **opcache for the messenger worker** — `klms-entrypoint.sh` runs the worker as
  `php -d opcache.enable_cli=1 bin/console …`: opcache is OFF by default under the
  cli SAPI, so without this the worker re-compiled the whole Symfony+vendor
  codebase for *every* message.
- **`opcache.memory_consumption=256`** (web + worker) via `docker/klms/php.ini`
  (copied to `/usr/local/etc/php/conf.d/99-perf.ini` by the klms Dockerfile —
  the klms image serves both the web tier and the worker) so the codebase is
  not evicted from the cache.
- **`PHP_CLI_SERVER_WORKERS=4`** on the `klms` web service — `php -S` is
  single-threaded by default and stalls on a blocked request; a few workers
  remove head-of-line blocking while keeping the one-process-per-container
  design (the built-in server's workers are threads, not extra processes).
- **Light postgres tuning** — passed as `-c` flags on the postgres command in
  `compose.yaml` (the official postgres image keeps its main config at
  `$PGDATA/postgresql.conf` and has no `conf.d` include_dir, so a mounted
  conf.d file would be a silent no-op): `shared_buffers=256MB`,
  `effective_cache_size=1GB`, `work_mem=16MB`, `maintenance_work_mem=64MB`;
  `max_connections` left at default.

The `php-fpm`/nginx switch was deliberately **not** done — over-engineering for
LAN traffic.
## Health checks

Every service has a `start_period` so a **slow first boot does not trip the
retry counter and mark the container `unhealthy` while it is still starting**:
during `start_period`, probe failures do **not** count toward `retries` — the
container stays `starting` (not `unhealthy`) until the period elapses.

- `postgres` — `start_period: 30s` (covers `initdb/setup.sh` role/DB creation).
- `idm` / `klms` / `klms-worker` — `start_period: 60s` (covers
  `doctrine:schema:create` + Symfony console boot on first boot).

The `klms` and `idm` probes are a stronger **"app + DB up"** signal: the HTTP
probe proves the web tier answers **and** `php /hcdb.php` proves the database is
reachable. The `klms-worker` probe greps the raw `/proc/[0-9]*/cmdline` (pgrep
is not in the `php:8.4-cli` image) for `messenger:consume` **and** runs the same
`/hcdb.php` DB check — so a **ZOMBIE worker** (process alive but DB
unreachable) is caught.

`/hcdb.php` is a small PHP probe **baked into both images** (one `COPY` per
Dockerfile; it reads `DATABASE_URL` and runs `SELECT 1` via `pdo_pgsql` — no
new deps). It is a *file* on purpose: `podman-compose` interpolates bare `$VAR`
in the compose healthcheck string (a bare `$p`/`$ok` would be replaced with the
env value or empty, silently breaking the probe) — keeping the `$` in the file
sidesteps that.

## DB users (two-tier)

`initdb/setup.sh` (runs on first start, idempotent) creates, per app:
- **`<app>_dot`** — the application user. **Owns** the database: read/write on all
  tables *and* can CREATE runtime tables (e.g. `messenger_messages`). `DATABASE_URL`
  uses this user.
- **`<app>_dot_mig`** — the migration user, **all rights**, for `doctrine:schema` /
  migrations.

Passwords come from the environment (`.env` / compose), never hardcoded in the image.

## Known quirks

- **`public/media/header_dot_bg.jpg` is not in the repo.** A placeholder is required
  before `yarn encore prod` will succeed (the SCSS references it). Create a 1×1 JPEG
  at that path before building the frontend.
- **The fork's composer lockfile requires PHP ≥ 8.4** (despite `composer.json` saying
  `^8.3` — the locked doctrine/qr-code deps hard-require `^8.4`). Use the `php:8.4-cli`
  base image.
- **`--classmap-authoritative` must NOT be used** for `composer dump-autoload`. The
  console app references dev-only bundles (`DoctrineFixturesBundle`) that are excluded by
  `--no-dev`; an authoritative classmap blocks their (dev-only) autoloading and breaks
  `bin/console` boot.
- **The `php:8.4-cli` base image ships no php-fpm binary.** The plan's original
  `php-fpm -D` lines are removed; the PHP built-in server (`php -S`) is the web tier.
  For a production deploy, swap in an `nginx` service + `php:8.4-fpm` (the VM's current
  setup) — only the entrypoint and a service change.
- **`docker/.env` is gitignored** — never commit real secrets.

## Deploying to the SSP VM

The VM currently runs the fork natively (php8.4-fpm + apache). To deploy this docker
stack to the VM:

1. Copy this worktree's `docker/` directory (and the IDM clone) to the VM.
2. On the VM, create `docker/.env` with real values:
   - `KLMS_DATABASE_URL` → use the real `klms_dot` password from `/root/klms-secrets.txt`
   - `KLMS_IDM_URL` → `http://idm:8095` (docker network)
   - `KLMS_MAILER_DSN` → copy from the VM's existing `/var/www/klms-dot/.env.local`
   - `KLMS_IDM_APIKEY` / `KLMS_IDM_AUTH` → from the VM's `.env.local`
3. Build both images (see Quick start), `podman-compose up -d`.
4. The schema is created automatically on first boot (see "Schema bootstrap
   (automatic, race-free)"); no manual `schema:create` needed.
5. If you set `KLMS_ADMIN_EMAIL`/`KLMS_ADMIN_PASSWORD` in the VM's `.env`, the
   first-run init creates that admin + the API key automatically (skip-if-exists).
   Otherwise create them manually (see step 5 of the walkthrough above).
6. Point Traefik at the container port (or a reverse proxy on the VM).

> **Postgres init scripts only run on first start** (empty `pgdata` volume). To re-init
> with new passwords: `podman-compose down -v` then `up -d`.

## Stopping / cleaning up

```bash
podman-compose down          # stop containers (volumes preserved)
podman-compose down -v      # stop + delete volumes (data lost)
```
