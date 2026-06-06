# WP Task Processor — Docker Image
# Extends official WordPress image with Redis PHP extension + WP-CLI

FROM wordpress:6.5-apache

LABEL maintainer="WP Swings <dev@wpswings.com>"
LABEL description="WP Task Processor plugin environment"

# ── System dependencies ────────────────────────────────────────────────────────
RUN apt-get update && apt-get install -y --no-install-recommends \
    less \
    git \
    unzip \
    procps \
    libicu-dev \
    && docker-php-ext-install intl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# ── PHP Redis extension (phpredis — faster than Predis) ───────────────────────
RUN pecl install redis \
    && docker-php-ext-enable redis

# ── PHP configuration tweaks ───────────────────────────────────────────────────
RUN echo "upload_max_filesize = 64M"   >> /usr/local/etc/php/conf.d/wtp.ini \
    && echo "post_max_size = 64M"       >> /usr/local/etc/php/conf.d/wtp.ini \
    && echo "memory_limit = 256M"       >> /usr/local/etc/php/conf.d/wtp.ini \
    && echo "max_execution_time = 300"  >> /usr/local/etc/php/conf.d/wtp.ini \
    && echo "max_input_time = 300"      >> /usr/local/etc/php/conf.d/wtp.ini

# ── WP-CLI ────────────────────────────────────────────────────────────────────
RUN curl -sO https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x wp-cli.phar \
    && mv wp-cli.phar /usr/local/bin/wp

# ── Enable Apache mod_rewrite for pretty permalinks ───────────────────────────
RUN a2enmod rewrite

# ── WordPress configuration ────────────────────────────────────────────────────
COPY docker/wp-config-extra.php /docker-entrypoint-d/99-wtp-config.php

# ── Healthcheck ────────────────────────────────────────────────────────────────
HEALTHCHECK --interval=15s --timeout=5s --retries=3 \
    CMD curl -sf http://localhost/wp-json || exit 1

EXPOSE 80
