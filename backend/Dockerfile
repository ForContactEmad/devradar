# syntax=docker/dockerfile:1

# ─────────────────────────────────────────────────────────────────────────────
# DevRadar backend — Laravel 12 / PHP 8.3
#
# ONE IMAGE, FOUR SERVICES. api, worker, worker-ingestion and scheduler all
# run this image and differ only in their command. Building four near-identical
# images would mean four chances for them to drift apart, and a worker running
# different code from the API is the kind of bug that takes a day to find.
#
# Targets:
#   base    - PHP with the extensions the app needs
#   vendor  - production dependencies, cached on composer.json alone
#   dev     - adds dev dependencies; source arrives as a bind mount
#   runtime - source baked in, optimised autoloader, non-root
#
# NO SECRETS. Nothing is COPYed that could contain one (.dockerignore excludes
# .env) and no ARG or ENV carries a credential. Configuration is injected at
# run time, so the same image is safe to push to a registry.
# ─────────────────────────────────────────────────────────────────────────────

FROM php:8.3-cli-alpine AS base

# pdo_pgsql for Postgres, redis for the queue and cache, pcntl so
# `queue:work` can handle signals and shut down cleanly mid-job, opcache
# because every artisan invocation pays for parsing otherwise.
#
# Build dependencies are added and removed in one layer; leaving $PHPIZE_DEPS
# in the image would roughly double its size and ship a compiler.
RUN apk add --no-cache postgresql-libs \
    && apk add --no-cache --virtual .build-deps postgresql-dev linux-headers $PHPIZE_DEPS \
    && docker-php-ext-install pdo pdo_pgsql pcntl opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Unprivileged user created in the base so every target shares the same uid.
# 1000 matches the usual first desktop user, which keeps bind-mounted files
# writable in development without a chown dance.
RUN addgroup -g 1000 devradar \
    && adduser -u 1000 -G devradar -s /bin/sh -D devradar


# ─────────────────────────────────────────────────────────────────────────────
FROM base AS vendor

# Manifests only, so this layer is cached until dependencies change rather
# than on every source edit.
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction


# ─────────────────────────────────────────────────────────────────────────────
FROM base AS dev

ENV APP_ENV=local

COPY composer.json composer.lock* ./
# WITH dev dependencies: this is the target that runs the test suite, and an
# image that cannot run its own tests is a poor development environment.
RUN composer install --no-scripts --no-autoloader --prefer-dist --no-interaction \
    && chown -R devradar:devradar /app

USER devradar
EXPOSE 8000

# Source is bind-mounted by compose rather than COPYed: the point of this
# target is that an edit is live without a rebuild.
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]


# ─────────────────────────────────────────────────────────────────────────────
FROM base AS runtime

ENV APP_ENV=production

COPY --from=vendor --chown=devradar:devradar /app/vendor ./vendor
COPY --chown=devradar:devradar . .

# Optimised classmap. Done as the app user so the generated files are not
# root-owned inside an image that runs unprivileged.
USER devradar
RUN composer dump-autoload --optimize --no-dev --no-interaction

EXPOSE 8000

# Uses the PHP already in the image rather than adding curl for the sake of a
# health probe. Hits liveness, not readiness: a database outage must not make
# every container look dead and cause a restart storm that prolongs it.
HEALTHCHECK --interval=15s --timeout=5s --start-period=20s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8000/api/v1/health") ? 0 : 1);'

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
