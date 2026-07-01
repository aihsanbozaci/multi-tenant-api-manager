# =============================================================================
# Multi-Tenant API Manager — PHP 8.3-FPM Production Dockerfile
#
# Extension rationale:
#   pdo_mysql   — Laravel MySQL database driver
#   mbstring    — String handling (required by Laravel core)
#   xml         — XML processing (required by several Laravel packages)
#   curl        — HTTP client (Guzzle, Laravel HTTP facade)
#   zip         — Composer package extraction
#   bcmath      — Big-number arithmetic (UUID generation helpers)
#   pcntl       — Process control (required by queue workers for signals)
#   opcache     — PHP opcode cache (significant throughput improvement)
#   redis       — phpredis C extension (REDIS_CLIENT=phpredis in .env)
#               → Much faster than Predis (pure PHP); required by our config.
# =============================================================================

FROM php:8.3-fpm

# -----------------------------------------------------------------------------
# System-level dependencies
# Installed in a single RUN layer and cache cleaned immediately to keep the
# image layer as small as possible.
# -----------------------------------------------------------------------------
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        curl \
        libpng-dev \
        libonig-dev \
        libxml2-dev \
        libzip-dev \
        libcurl4-openssl-dev \
        unzip \
        zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# -----------------------------------------------------------------------------
# PHP extensions — compiled from source via docker-php-ext-install
# -----------------------------------------------------------------------------
RUN docker-php-ext-install \
        pdo_mysql \
        mbstring \
        xml \
        curl \
        zip \
        bcmath \
        pcntl \
        opcache

# -----------------------------------------------------------------------------
# phpredis — C extension (PECL)
# Required because .env sets REDIS_CLIENT=phpredis.
# Predis (pure PHP) would work but has ~10x higher latency for the hot path.
# -----------------------------------------------------------------------------
RUN pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /tmp/pear

# -----------------------------------------------------------------------------
# OPcache configuration
# Tuned for a containerised environment where code is immutable at runtime.
# -----------------------------------------------------------------------------
RUN { \
    echo 'opcache.enable=1'; \
    echo 'opcache.memory_consumption=128'; \
    echo 'opcache.interned_strings_buffer=8'; \
    echo 'opcache.max_accelerated_files=10000'; \
    echo 'opcache.revalidate_freq=0'; \
    echo 'opcache.validate_timestamps=0'; \
    echo 'opcache.save_comments=1'; \
    echo 'opcache.fast_shutdown=1'; \
} > /usr/local/etc/php/conf.d/opcache.ini

# -----------------------------------------------------------------------------
# PHP-FPM configuration
# Listens on port 9000 (TCP) so Nginx can reach it across Docker networks.
# www.conf pool is kept; we just ensure the listen address is explicit.
# -----------------------------------------------------------------------------
RUN { \
    echo '[www]'; \
    echo 'listen = 0.0.0.0:9000'; \
    echo 'pm = dynamic'; \
    echo 'pm.max_children = 20'; \
    echo 'pm.start_servers = 4'; \
    echo 'pm.min_spare_servers = 2'; \
    echo 'pm.max_spare_servers = 6'; \
    echo 'pm.max_requests = 500'; \
} > /usr/local/etc/php-fpm.d/zz-app.conf

# -----------------------------------------------------------------------------
# Composer — copy from official image for reproducible installs
# -----------------------------------------------------------------------------
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# -----------------------------------------------------------------------------
# Working directory
# -----------------------------------------------------------------------------
WORKDIR /var/www/html

# -----------------------------------------------------------------------------
# Application files
# Copy composer manifests first to leverage Docker layer caching:
# vendor/ only rebuilds when composer.json or composer.lock changes.
# -----------------------------------------------------------------------------
COPY composer.json composer.lock ./

RUN composer install \
        --no-scripts \
        --no-autoloader \
        --no-interaction \
        --prefer-dist \
    && composer clear-cache

# Copy remaining application files
COPY . .

# Generate optimised autoloader now that all files are present
RUN composer dump-autoload --optimize --no-interaction

# -----------------------------------------------------------------------------
# Permissions
# Storage and cache directories must be writable by www-data (PHP-FPM user).
# -----------------------------------------------------------------------------
RUN chown -R www-data:www-data /var/www/html/storage \
    && chown -R www-data:www-data /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage \
    && chmod -R 775 /var/www/html/bootstrap/cache

# Run PHP-FPM as www-data
USER www-data

# Expose PHP-FPM port
EXPOSE 9000

CMD ["php-fpm"]
