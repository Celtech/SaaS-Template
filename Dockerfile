FROM dunglas/frankenphp:1-php8.4 AS base

LABEL org.opencontainers.image.title="SaaS Template"

RUN apt-get update && apt-get install -y --no-install-recommends \
    acl \
    git \
    libicu-dev \
    libpq-dev \
    libzip-dev \
    unzip \
    && rm -rf /var/lib/apt/lists/*

RUN install-php-extensions \
    apcu \
    intl \
    opcache \
    pdo_pgsql \
    redis \
    zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_HOME=/tmp/.composer
ENV SERVER_NAME=":80"

WORKDIR /app

# ==============================================================
# DEV stage — source is bind-mounted at runtime, not copied in
# ==============================================================
FROM base AS dev

ENV APP_ENV=dev
ENV APP_DEBUG=1

RUN install-php-extensions xdebug

COPY docker/php/conf.d/app.ini $PHP_INI_DIR/conf.d/app.ini
COPY docker/php/conf.d/app.dev.ini $PHP_INI_DIR/conf.d/app.dev.ini

EXPOSE 80 443 443/udp

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]

# ==============================================================
# PROD build — installs deps and warms cache
# ==============================================================
FROM base AS prod_build

ENV APP_ENV=prod
ENV APP_DEBUG=0

COPY composer.json composer.lock symfony.lock ./

RUN composer install \
    --no-dev \
    --no-scripts \
    --no-progress \
    --optimize-autoloader \
    --prefer-dist

COPY . .

RUN composer dump-autoload --optimize --no-dev \
    && php bin/console cache:warmup

# ==============================================================
# PROD runtime
# ==============================================================
FROM base AS prod

ENV APP_ENV=prod
ENV APP_DEBUG=0

COPY docker/php/conf.d/app.ini $PHP_INI_DIR/conf.d/app.ini
COPY docker/php/conf.d/app.prod.ini $PHP_INI_DIR/conf.d/app.prod.ini
COPY docker/Caddyfile /etc/caddy/Caddyfile

COPY --from=prod_build /app /app

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -f http://localhost/health || exit 1

EXPOSE 80 443 443/udp

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
