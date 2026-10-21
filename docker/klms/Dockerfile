# syntax=docker/dockerfile:1
# Pinned digest (was the floating php:8.4-cli tag) for reproducible builds.
#
# 8.4.27 is the CVE-2026-109618 / CVE-2026-109619 ("PHP2Shell", CVSS 9.2) fix; the
# digest this replaces (php:8.4-cli@sha256:c4723acd…) was PHP 8.4.26. Note the tag
# name moved from the floating `php:8.4-cli` to the explicit `php:8.4.27-cli`, so a
# future upstream 8.4.28 rebuild will NOT silently move this build under us.
# docker/klms/security.ini stays in place on purpose — PHP recommends keeping
# session.upload_progress off even on a patched runtime.
#
# Re-pin when you intentionally want a newer PHP:
#   skopeo inspect --override-os linux --override-arch amd64 \
#       --format '{{.Digest}}' docker://php:8.4.28-cli
# Use skopeo, not `curl` against the Hub tag API: a tag returns HTTP 200 while its
# OCI index is still empty mid-push (that happened with this very release at 15:40
# UTC), and `8.4.xRC1` tags sit on the registry for weeks before any final release.
FROM php:8.4.27-cli@sha256:639f5390cb247618a440a0092bff328e801549fd1ee919b81eaa840a1f2f2a77

# --- system deps for PHP extensions ---
# (no xdebug: this is a prod image)
#
# libgd-dev + --with-external-gd: the php:8.4-cli image ships a BUNDLED
# libgd 2.1.0 compiled WITHOUT webp/jpg (gd_info shows empty), so every
# LiipImagine media-cache URL 500s ("Creating an image in webp format is not
# supported", "Unable to open image ...jpg"). Linking the gd extension
# against the system libgd (2.3.3, built with webp/jpg/freetype/avif — same
# feature set as the VM's php8.4-fpm gd) makes the docker test stack match
# the live site.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev libpng-dev libgd-dev libxml2-dev libicu-dev libcurl4-openssl-dev \
        libzip-dev libonig-dev unzip curl \
    && docker-php-ext-configure gd --with-external-gd \
    && docker-php-ext-install pdo_pgsql pgsql gd intl xml mbstring curl zip bcmath \
    && rm -rf /var/lib/apt/lists/*

# --- composer ---
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Bake code (vendor/ built at image build time).
# .dockerignore keeps node_modules/, tests/, var/ etc. out of the context.
COPY . .
# Minimal placeholder .env so Dotenv::loadEnv('/app/.env') finds the file.
# (Real values are injected at runtime via compose `environment:`; the secret
#  .env stays on the host and is NOT baked in.)
COPY docker/.env.placeholder .env
# COMPOSER_ALLOW_SUPERUSER: since composer 2.10 running as root WITHOUT this
# env silently DISABLES plugins - the symfony/runtime plugin then never
# generates vendor/autoload_runtime.php and every `bin/console` (worker!) dies
# with "Symfony Runtime is missing". The official composer image sets this
# itself; in the php base image we must set it explicitly.
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts \
    && composer dump-autoload --no-dev \
    && test -f vendor/autoload_runtime.php \
    && mkdir -p var/cache var/log

# Writable dirs (also mounted as volumes at runtime; mkdir so the image works standalone)
RUN mkdir -p public/images/news public/images/user public/images/site \
    public/images/sponsor public/images/gallery public/images/beamernews \
    public/media public/uploads/tourney-rules

# --- non-root runtime user ---
# The PHP processes (web + worker) run as an unprivileged user (uid 1000), not
# root. Writable dirs are chowned to it; the runtime named volumes inherit this
# ownership when they are first mounted (empty volume -> image content copied in).
# The first-boot schema bootstrap (doctrine:schema:create) writes var/cache as this
# user, which is why /app/var must be owned by it.
#
# `public` (the whole tree, not just the subdirs) must be appuser-owned: the
# LiipImagine media cache is created at RUNTIME under /app/public/cache, and the
# app cannot mkdir a child of a root-owned /app/public. (Chowning only the
# images/media/uploads subdirs leaves /app/public itself root-owned, so the
# cache dir creation fails with "Permission denied" and every media-cache URL
# 500s.)
RUN useradd -m -u 1000 appuser \
    && chown -R appuser:appuser var public

EXPOSE 8094

COPY docker/klms-entrypoint.sh /klms-entrypoint.sh
COPY docker/klms-router.php /app/docker/klms-router.php
# PHP performance tuning (opcache on for both cli-server and cli SAPIs).
COPY docker/klms/php.ini /usr/local/etc/php/conf.d/99-perf.ini
# Pre-patch mitigation for CVE-2026-109618/109619 ("PHP2Shell"): turns off
# session upload progress — see docker/klms/security.ini for what it does and
# what it does NOT do. Applies to both tiers (web + worker) via conf.d.
COPY docker/klms/security.ini /usr/local/etc/php/conf.d/99-security.ini
# Cheap in-image DB-reachability probe for the healthchecks (see docker/hcdb.php).
COPY docker/hcdb.php /hcdb.php
RUN chmod +x /klms-entrypoint.sh

# One container = one process. Compose runs this image twice with different
# arguments (see compose.yaml): `web` (php -S) and `worker` (messenger:consume).
# No CMD default: a bare `docker run` with no arg exits with a usage error,
# which is the desired behaviour.
USER appuser
ENTRYPOINT ["/klms-entrypoint.sh"]
