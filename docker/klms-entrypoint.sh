#!/bin/sh
# Entrypoint: run ONE process (one container = one process).
#
# The compose stack runs this image as TWO containers (see compose.yaml):
#   - klms        (arg: web)    -> php -S 0.0.0.0:8094 -t /app/public /app/docker/klms-router.php
#   - klms-worker (arg: worker) -> php bin/console messenger:consume async --time-limit=3600
#
# Why two containers and not one supervised process:
#   - the Docker convention is one process per container; the container runtime
#     (restart: unless-stopped) is the supervisor — when the worker exits after
#     its --time-limit, the container is restarted with a fresh worker,
#   - per-tier healthchecks: the web container is probed over HTTP, the worker
#     container over its process list — a dead tier can no longer mask as healthy,
#   - independent scaling/logs: web and worker restarts no longer share a PID 1.
#
# Why php -S and not php-fpm: the php:8.4-cli base image ships no php-fpm
# binary. php -S is single-threaded (fine for a LAN party) and needs the
# router script for correct MIME types on static assets.
set -e

# --- First-time schema bootstrap (no-op on subsequent boots) ---
# On a fresh volume (no marker file), create the schema before starting the
# main process. On subsequent boots the marker file exists and we skip
# schema:create entirely (no console boot, no wasted time).
#
# GATE = the marker file (/app/var/klms-schema.done, on the SHARED klms-var
# volume), NOT a table count. A "0 tables" check is NOT reliable here: the
# messenger transport auto-creates messenger_messages during the console/php -S
# boot, so a fresh DB already has 1+ tables and a pure table-count check would
# skip the bootstrap — leaving the real schema (news, ...) missing. The marker
# is written once, after a successful schema:create, and persists in the named
# volume, so it is the reliable "done" signal (it is wiped together with the
# pgdata volume on `down -v`, so a fresh volume has neither).
#
# RACE FIX (web vs worker): on a fresh volume BOTH containers see no marker and
# would both run doctrine:schema:create at the same time — one fails with a
# duplicate-sequence error (previously swallowed by `|| true`). flock()
# serializes the bootstrap: the lock file lives in /app/var, the SHARED named
# volume both containers mount (a lock in /tmp would NOT work — /tmp is
# per-container). The first container creates the schema and writes the marker;
# the second waits for the lock, sees the marker, and skips. The marker + flock
# makes it idempotent AND race-free.
if [ ! -f /app/var/klms-schema.done ]; then
  echo "First boot: creating database schema (flock: only one of web/worker runs it)..."
  flock /app/var/klms-schema.lock -c '
    if [ ! -f /app/var/klms-schema.done ]; then
      php bin/console doctrine:schema:create 2>&1 | grep -vE "deprecation|Deprecated" || true
      touch /app/var/klms-schema.done
    fi
  '
  echo "Schema bootstrap done."
fi

case "${1:-}" in
  web)
    exec php -S 0.0.0.0:8094 -t /app/public /app/docker/klms-router.php
    ;;
  worker)
    exec php bin/console messenger:consume async --time-limit=3600
    ;;
  *)
    echo "usage: klms-entrypoint.sh {web|worker}" >&2
    exit 1
    ;;
esac
