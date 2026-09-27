#!/bin/sh
# First-time setup helper for the KLMS-for-SSP docker stack.
#
# DEFAULT (no prompts): generates a WORKING docker/.env:
#   * auto-generated random secrets (openssl rand -hex 32) for APP_SECRET,
#     IDM_APP_SECRET, the IDM API key, and the first admin password
#   * auto-detected SITE_BASE_HOST (host LAN IP + :8094, e.g. 192.168.0.170:8094)
#   * the other sane defaults from .env.example
# The generated secrets are persisted to .env, so re-runs do NOT rotate them
# (an existing .env is reused as-is). .env is gitignored.
#
# The first-run init (IDM API key + default admin user) runs automatically in
# the idm entrypoint — skip-if-exists, so re-runs are a no-op.
#
# INTERACTIVE (optional): `./setup.sh --ask` keeps the old wizard (asks for
# every value, with a brief explanation of each).
#
# After the first run you never need this script again — just:
#   podman-compose -f docker/compose.yaml up -d
set -e
cd "$(dirname "$0")"
ENVFILE=".env"
EXAMPLE=".env.example"

ASK=0
[ "${1:-}" = "--ask" ] && ASK=1

# ask <var> <prompt> <default>  -> sets $var, prints the value
ask() {
  _v=""
  if [ -n "$3" ]; then _v="$3"; fi
  read -r -p "$2 [${_v}]: " _a
  [ -n "$_a" ] && _v="$_a"
  eval "$1=\$_v"
}

# detect_host -> the host's LAN IP + the published port, e.g. 192.168.0.170:8094
detect_host() {
  _ip=$(hostname -I 2>/dev/null | tr ' ' '\n' | grep -E '^192\.168\.|^10\.' | head -n1)
  [ -n "$_ip" ] || _ip=$(ip -4 addr show 2>/dev/null | awk '/inet /{print $2}' | cut -d/ -f1 | grep -E '^192\.168\.|^10\.' | head -n1)
  [ -n "$_ip" ] || _ip=127.0.0.1
  echo "${_ip}:8094"
}

if [ -f "$ENVFILE" ]; then
  echo "Found an existing $ENVFILE — reusing it (secrets are NOT rotated) and starting the stack."
  echo "  podman-compose -f compose.yaml up -d"
  podman-compose -f compose.yaml up -d
  echo "Done. (First boot: the entrypoints run the schema bootstrap + first-run init; later boots are no-ops.)"
  exit 0
fi

if [ "$ASK" = "1" ]; then
  echo "=== KLMS-for-SSP docker setup (interactive) ==="
  echo "No $ENVFILE found. I'll ask for the values and write it for you."
  echo "Press ENTER to accept a shown default. (Ctrl-C to abort.)"
  echo

# --- postgres superuser ---
echo "--- postgres superuser ---"
ask POSTGRES_USER "postgres superuser name" "postgres"
ask POSTGRES_PASSWORD "postgres superuser password" "postgres_test_pw"

# --- two-tier DB users (app user owns the DB; migration user has all rights) ---
echo
echo "--- database users (two-tier: app + migration) ---"
ask KLMS_DB_USER "KLMS app DB user" "klms_dot"
ask KLMS_DB_PASSWORD "KLMS app DB password" "klms_dot_test_pw"
ask KLMS_MIGRATION_DB_PASSWORD "KLMS migration DB password" "klms_dot_mig_test_pw"
ask KLMS_DB_NAME "KLMS database name" "klms_dot"
ask IDM_DB_USER "IDM app DB user" "idm_dot"
ask IDM_DB_PASSWORD "IDM app DB password" "idm_dot_test_pw"
ask IDM_MIGRATION_DB_PASSWORD "IDM migration DB password" "idm_dot_mig_test_pw"
ask IDM_DB_NAME "IDM database name" "idm_dot"

# --- klms app ---
echo
echo "--- KLMS app ---"
ask KLMS_APP_ENV "KLMS env (prod|dev)" "prod"
ask KLMS_APP_SECRET "KLMS app secret (random string; used to sign sessions/CSRF)" "$(openssl rand -hex 32)"
ask KLMS_IDM_URL "KLMS -> IDM URL (internal docker network)" "http://idm:8095"
ask KLMS_IDM_APIKEY "KLMS -> IDM API key (shared secret between the two apps)" "$(openssl rand -hex 32)"
ask KLMS_IDM_AUTH "KLMS -> IDM auth header value" "idm_auth_change_me"
ask KLMS_MAILER_DSN "mail DSN (null://null = no mail; e.g. smtp://user:pass@host:587)" "null://null"
ask KLMS_MAILER_DEFAULT_SENDER_NAME "mail sender name" "KLMS"
ask KLMS_MAILER_DEFAULT_SENDER_EMAIL "mail sender email" "noreply@example.test"
ask KLMS_SITE_BASE_SCHEME "site base scheme (http|https)" "http"
ask KLMS_SITE_BASE_HOST "site base host (what you browse to, e.g. 192.168.0.170:8094)" "$(detect_host)"
ask KLMS_RECAPTCHA3_ENABLED "reCAPTCHA v3 enabled (0|1)" "0"
ask KLMS_MESSENGER_TRANSPORT_DSN "messenger transport DSN (doctrine://default = DB queue)" "doctrine://default"
ask KLMS_SENTRY_DSN "Sentry DSN (empty = no error reporting)" ""
ask KLMS_CARTO_BASEMAP_API_KEY "CARTO basemap API key (empty = none)" ""

# --- idm app ---
echo
echo "--- IDM app ---"
ask IDM_APP_ENV "IDM env (prod|dev)" "prod"
ask IDM_APP_SECRET "IDM app secret (random string)" "$(openssl rand -hex 32)"

# --- first-run init (created by the idm entrypoint, skip-if-exists) ---
echo
echo "--- first-run init (IDM API key + default admin user) ---"
ask KLMS_IDM_APIKEY_NAME "IDM API key name" "klms"
ask KLMS_ADMIN_EMAIL "default admin email" "admin@example.test"
ask KLMS_ADMIN_PASSWORD "default admin password (random if you accept the default)" "$(openssl rand -hex 16)"
ask KLMS_ADMIN_NICKNAME "default admin nickname" "admin"
else
# --- NON-INTERACTIVE: generate everything (defaults from .env.example) ---
echo "=== KLMS-for-SSP docker setup (non-interactive) ==="
echo "No $ENVFILE found — generating one with random secrets and sane defaults."

POSTGRES_USER=postgres
POSTGRES_PASSWORD=postgres_test_pw
KLMS_DB_USER=klms_dot
KLMS_DB_PASSWORD=klms_dot_test_pw
KLMS_MIGRATION_DB_PASSWORD=klms_dot_mig_test_pw
KLMS_DB_NAME=klms_dot
IDM_DB_USER=idm_dot
IDM_DB_PASSWORD=idm_dot_test_pw
IDM_MIGRATION_DB_PASSWORD=idm_dot_mig_test_pw
IDM_DB_NAME=idm_dot
KLMS_APP_ENV=prod
KLMS_APP_SECRET=$(openssl rand -hex 32)
KLMS_IDM_URL=http://idm:8095
KLMS_IDM_APIKEY=$(openssl rand -hex 32)
KLMS_IDM_AUTH=idm_auth_change_me
KLMS_MAILER_DSN=null://null
KLMS_MAILER_DEFAULT_SENDER_NAME=KLMS
KLMS_MAILER_DEFAULT_SENDER_EMAIL=noreply@example.test
KLMS_SITE_BASE_SCHEME=http
KLMS_SITE_BASE_HOST=$(detect_host)
KLMS_RECAPTCHA3_ENABLED=0
KLMS_MESSENGER_TRANSPORT_DSN=doctrine://default
KLMS_SENTRY_DSN=
KLMS_CARTO_BASEMAP_API_KEY=
IDM_APP_ENV=prod
IDM_APP_SECRET=$(openssl rand -hex 32)
KLMS_IDM_APIKEY_NAME=klms
KLMS_ADMIN_EMAIL=admin@example.test
KLMS_ADMIN_PASSWORD=$(openssl rand -hex 16)
KLMS_ADMIN_NICKNAME=admin
fi

# --- derived: DATABASE_URL (built from the user/password/db name above) ---
KLMS_DATABASE_URL="postgresql://${KLMS_DB_USER}:${KLMS_DB_PASSWORD}@postgres:5432/${KLMS_DB_NAME}?serverVersion=16&charset=utf8"
IDM_DATABASE_URL="postgresql://${IDM_DB_USER}:${IDM_DB_PASSWORD}@postgres:5432/${IDM_DB_NAME}?serverVersion=16&charset=utf8"

# --- write .env ---
cat > "$ENVFILE" <<EOF
# Generated by setup.sh — edit freely. (gitignored)
# Re-running setup.sh REUSES this file — the secrets below are NOT rotated.
POSTGRES_USER=${POSTGRES_USER}
POSTGRES_PASSWORD=${POSTGRES_PASSWORD}
KLMS_DB_USER=${KLMS_DB_USER}
KLMS_DB_PASSWORD=${KLMS_DB_PASSWORD}
KLMS_MIGRATION_DB_PASSWORD=${KLMS_MIGRATION_DB_PASSWORD}
KLMS_DB_NAME=${KLMS_DB_NAME}
IDM_DB_USER=${IDM_DB_USER}
IDM_DB_PASSWORD=${IDM_DB_PASSWORD}
IDM_MIGRATION_DB_PASSWORD=${IDM_MIGRATION_DB_PASSWORD}
IDM_DB_NAME=${IDM_DB_NAME}
KLMS_APP_ENV=${KLMS_APP_ENV}
KLMS_APP_SECRET=${KLMS_APP_SECRET}
KLMS_DATABASE_URL=${KLMS_DATABASE_URL}
KLMS_IDM_URL=${KLMS_IDM_URL}
KLMS_IDM_APIKEY=${KLMS_IDM_APIKEY}
KLMS_IDM_AUTH=${KLMS_IDM_AUTH}
KLMS_MAILER_DSN=${KLMS_MAILER_DSN}
KLMS_MAILER_DEFAULT_SENDER_NAME=${KLMS_MAILER_DEFAULT_SENDER_NAME}
KLMS_MAILER_DEFAULT_SENDER_EMAIL=${KLMS_MAILER_DEFAULT_SENDER_EMAIL}
KLMS_SITE_BASE_SCHEME=${KLMS_SITE_BASE_SCHEME}
KLMS_SITE_BASE_HOST=${KLMS_SITE_BASE_HOST}
KLMS_RECAPTCHA3_ENABLED=${KLMS_RECAPTCHA3_ENABLED}
KLMS_MESSENGER_TRANSPORT_DSN=${KLMS_MESSENGER_TRANSPORT_DSN}
KLMS_SENTRY_DSN=${KLMS_SENTRY_DSN}
KLMS_CARTO_BASEMAP_API_KEY=${KLMS_CARTO_BASEMAP_API_KEY}
IDM_APP_ENV=${IDM_APP_ENV}
IDM_APP_SECRET=${IDM_APP_SECRET}
IDM_DATABASE_URL=${IDM_DATABASE_URL}
# --- first-run init (created by the idm entrypoint, skip-if-exists) ---
KLMS_IDM_APIKEY_NAME=${KLMS_IDM_APIKEY_NAME}
KLMS_ADMIN_EMAIL=${KLMS_ADMIN_EMAIL}
KLMS_ADMIN_PASSWORD=${KLMS_ADMIN_PASSWORD}
KLMS_ADMIN_NICKNAME=${KLMS_ADMIN_NICKNAME}
EOF
chmod 600 "$ENVFILE"
echo
echo "Wrote $ENVFILE (mode 600)."
echo "  SITE_BASE_HOST:  ${KLMS_SITE_BASE_HOST}  (auto-detected — edit if wrong)"
echo "  first admin:    ${KLMS_ADMIN_EMAIL} / ${KLMS_ADMIN_PASSWORD}  (created on first boot; keep this password!)"
echo "Starting the stack — first boot runs the schema bootstrap + first-run init (both idempotent)."
podman-compose -f compose.yaml up -d
echo
echo "Done. First boot may take a minute (schema creation + first-run init). Then open:"
echo "  http://${KLMS_SITE_BASE_HOST}/"
echo "  (subsequent boots: podman-compose -f compose.yaml up -d)"
