# ojs/Dockerfile
#
# Builds a runnable container for Open Journal Systems (OJS) 3.5.x.
# OJS itself is NOT written by us — it is pulled from the official PKP
# GitHub release tag at build time. This Dockerfile only packages it
# with the PHP/Apache runtime it needs.

ARG PHP_VERSION=8.2
FROM php:${PHP_VERSION}-apache-bookworm AS base

# OJS 3.5 requires PHP 8.2+, so this build arg is deliberately pinned
# to the version PKP tests against (see docs/architecture.md for the
# compatibility rationale).
ARG OJS_VERSION=3_5_0-2

LABEL org.opencontainers.image.title="OJS (Open Journal Systems)" \
      org.opencontainers.image.description="Containerized OJS ${OJS_VERSION}, packaged by this project. OJS is developed by the Public Knowledge Project (PKP), not by us." \
      org.opencontainers.image.source="https://github.com/pkp/ojs"

# --- System packages & PHP extensions OJS requires ---
# gd, mbstring, pdo_mysql are documented OJS requirements.
# curl/dom/xml are needed by Composer-managed OJS dependencies.
# zip/unzip are needed to unpack the release tarball.
#
# The exact extension list below was cross-checked against the
# platform requirements pkp/ojs's own `composer check-platform-reqs`
# reports on its main branch (ext-curl, ext-bcmath, ext-xml,
# ext-xmlwriter, ext-simplexml, ext-zip, ext-zlib, plus PDO/mysqli for
# DB access, gd for image handling, mbstring/intl for i18n). Several
# of PKP's listed extensions (ctype, phar, session, spl, tokenizer,
# xmlwriter, simplexml) ship built-in/enabled-by-default in the
# official php:8.2-apache image and don't need explicit installation —
# curl does NOT ship enabled by default, which is the one gap this
# project's earlier draft had and is fixed here.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libzip-dev \
        libxml2-dev \
        libonig-dev \
        libcurl4-openssl-dev \
        unzip \
        curl \
        git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        gd \
        mbstring \
        pdo_mysql \
        mysqli \
        zip \
        xml \
        dom \
        intl \
        bcmath \
        curl \
        opcache \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# --- Fetch the official OJS release tarball (not a fork, not a mirror) ---
WORKDIR /tmp
RUN curl -fsSL -o ojs.tar.gz \
        "https://pkp.sfu.ca/ojs/download/ojs-${OJS_VERSION}.tar.gz" \
    && mkdir -p /var/www/html \
    && tar -xzf ojs.tar.gz --strip-components=1 -C /var/www/html \
    && rm ojs.tar.gz

WORKDIR /var/www/html

# --- File permissions ---
# OJS needs the web server user (www-data) to own the public/, cache/,
# and files/ directories it writes to at runtime. Everything else stays
# read-only-by-default, which is a small but real hardening step.
RUN mkdir -p /var/www/files \
    && chown -R www-data:www-data /var/www/html /var/www/files \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \;

# --- PHP runtime configuration ---
# No secrets live here. Values that differ per environment (DB host,
# DB password, base URL) are injected via environment variables and
# rendered into config.inc.php by docker-entrypoint.sh at container
# start, never baked into the image.
COPY php.ini-production-overrides.ini /usr/local/etc/php/conf.d/zz-ojs-overrides.ini
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
COPY healthcheck.sh /usr/local/bin/healthcheck.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh /usr/local/bin/healthcheck.sh

VOLUME ["/var/www/files", "/var/www/html/public"]

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD /usr/local/bin/healthcheck.sh

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
