# syntax=docker/dockerfile:1
# ============================================================================
# OpenEMR Dockerfile
# ============================================================================
# The application version is NOT hardcoded in this file. It is read from
# version.php at build time and asserted against the OPENEMR_IMAGE_VERSION
# build argument, so an image can never be tagged with a version that
# disagrees with the code it contains. See the openemr-source stage.
# ============================================================================
# This Dockerfile builds a production-ready OpenEMR container image with:
#   - Apache web server for serving OpenEMR
#   - PHP 8.5 with all required extensions
#   - OpenEMR application code with dependencies installed
#   - Automated setup and configuration scripts
#   - Support for SSL/TLS certificates
#   - Multi-stage build support for coverage testing (kcov target)
#
# Build Targets:
#   - base: Default production image
#   - kcov: Coverage testing image with kcov instrumentation
#   - final: Alias for base (for consistency)
# ============================================================================

# ============================================================================
# BASE IMAGE CONFIGURATION
# ============================================================================
# Alpine Linux version - centralized setting for easy updates
# Note: This is NOT meant to be used as a build argument (unlike the flex series)
ARG ALPINE_VERSION=3.23
FROM alpine:${ALPINE_VERSION} AS base

# PHP version configuration
# Note: This is NOT meant to be used as a build argument (unlike the flex series)
# Important: When updating PHP version, also update php.ini file to match
ARG PHP_VERSION=8.5
ENV PHP_VERSION=${PHP_VERSION}
# Create abbreviated version (e.g., "8.5" -> "85") for package naming
ARG PHP_VERSION_ABBR=${PHP_VERSION//./}
ENV PHP_VERSION_ABBR=${PHP_VERSION_ABBR}

# ============================================================================
# SYSTEM PACKAGE INSTALLATION
# ============================================================================
# Update Alpine packages to latest versions for security patches
RUN apk --no-cache upgrade

# Install system packages required for OpenEMR and Apache
# These packages provide web server, database clients, build tools, and utilities
# Packages: apache2 (HTTP server), apache2-proxy (proxy module), apache2-ssl (SSL/TLS),
# apache2-utils (utilities), bash (shell), certbot (Let's Encrypt), curl (HTTP client),
# dcron (scheduled tasks), git (version control), imagemagick (image processing),
# mariadb-client (database client), mariadb-connector-c (DB connector), ncurses (terminal),
# nodejs/npm (JavaScript runtime), openssl/openssl-dev (cryptography), perl (interpreter),
# rsync (file sync), shadow (user management), su-exec (lightweight privilege-drop
# tool used by run_php_as_apache to invoke OpenEMR CLI scripts as the apache user
# without an intermediate shell — busybox `su` rescans options across the whole
# arg list, which breaks argv-passthrough), tar (archives)
RUN apk add --no-cache \
    apache2 \
    apache2-proxy \
    apache2-ssl \
    apache2-utils \
    bash \
    certbot \
    curl \
    dcron \
    git \
    imagemagick \
    mariadb-client \
    mariadb-connector-c \
    ncurses \
    nodejs \
    npm \
    openssl \
    openssl-dev \
    perl \
    rsync \
    shadow \
    su-exec \
    tar

# Install PHP and all required extensions for OpenEMR
# OpenEMR requires a comprehensive set of PHP extensions for full functionality
# Core: php, php-apache2 (Apache integration)
# Database: php-mysqli, php-pdo, php-pdo_mysql (MySQL support)
# XML/Data: php-dom, php-xml, php-xmlreader, php-xmlwriter, php-xsl, php-simplexml, php-soap
# Graphics: php-gd, php-pecl-imagick (image processing)
# Crypto/Encoding: php-openssl, php-sodium, php-iconv, php-mbstring, php-intl
# Utilities: php-curl, php-json, php-bcmath, php-calendar, php-ctype, php-fileinfo
# Performance: php-opcache, php-pecl-apcu, php-redis, php-session
# Other: php-fpm, php-phar, php-zip, php-zlib, php-ldap, php-sockets, php-tokenizer
RUN apk add --no-cache \
    php${PHP_VERSION_ABBR} \
    php${PHP_VERSION_ABBR}-apache2 \
    php${PHP_VERSION_ABBR}-bcmath \
    php${PHP_VERSION_ABBR}-calendar \
    php${PHP_VERSION_ABBR}-ctype \
    php${PHP_VERSION_ABBR}-curl \
    php${PHP_VERSION_ABBR}-dom \
    php${PHP_VERSION_ABBR}-fileinfo \
    php${PHP_VERSION_ABBR}-fpm \
    php${PHP_VERSION_ABBR}-gd \
    php${PHP_VERSION_ABBR}-iconv \
    php${PHP_VERSION_ABBR}-intl \
    php${PHP_VERSION_ABBR}-ldap \
    php${PHP_VERSION_ABBR}-mbstring \
    php${PHP_VERSION_ABBR}-mysqli \
    php${PHP_VERSION_ABBR}-openssl \
    php${PHP_VERSION_ABBR}-pdo \
    php${PHP_VERSION_ABBR}-pdo_mysql \
    php${PHP_VERSION_ABBR}-pecl-apcu \
    php${PHP_VERSION_ABBR}-pecl-imagick \
    php${PHP_VERSION_ABBR}-phar \
    php${PHP_VERSION_ABBR}-pecl-redis \
    php${PHP_VERSION_ABBR}-session \
    php${PHP_VERSION_ABBR}-simplexml \
    php${PHP_VERSION_ABBR}-soap \
    php${PHP_VERSION_ABBR}-sockets \
    php${PHP_VERSION_ABBR}-sodium \
    php${PHP_VERSION_ABBR}-tokenizer \
    php${PHP_VERSION_ABBR}-xml \
    php${PHP_VERSION_ABBR}-xmlreader \
    php${PHP_VERSION_ABBR}-xmlwriter \
    php${PHP_VERSION_ABBR}-xsl \
    php${PHP_VERSION_ABBR}-zip \
    php${PHP_VERSION_ABBR}-zlib

# ============================================================================
# APACHE CONFIGURATION
# ============================================================================
# Fix Apache to listen on all interfaces (0.0.0.0) instead of localhost only
# This is required for Docker containers to accept external connections
RUN sed -i 's/^Listen 80$/Listen 0.0.0.0:80/' /etc/apache2/httpd.conf
# Set a default ServerName so apache does not emit AH00558 ("could not reliably
# determine the server's fully qualified domain name") on every start. Deployments
# with a real hostname should override this via their own vhost/config.
RUN printf '\nServerName localhost\n' >> /etc/apache2/httpd.conf

# ============================================================================
# USER AND PERMISSIONS CONFIGURATION
# ============================================================================
# Set Apache user UID to 1000 to ensure consistent permissions across
# shared volumes when using multiple containers (OpenEMR, nginx, php-fpm)
# This prevents permission conflicts in multi-container deployments
RUN usermod -u 1000 apache

# ============================================================================
# PHP CONFIGURATION
# ============================================================================
# Create symlink for PHP binary to support PHP 8+ on Alpine 3.13+
# Note: This workaround may be removable in future Alpine versions
# The symlink ensures 'php' command points to the correct versioned binary
RUN ln -sf /usr/bin/php${PHP_VERSION_ABBR} /usr/bin/php

# Install Composer (PHP dependency manager) for OpenEMR package installation
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer

# ============================================================================
# MULTI-STAGE BUILD: OpenEMR Source and Dependencies
# ============================================================================
# Stage 1: Fetch OpenEMR source code via git clone
# Use git clone instead of GitHub archive because OpenEMR's .gitattributes
# marks tests/, phpunit*.xml, and other dev files as export-ignore, which
# excludes them from archives but not from clones.
# Supports branches or tags via OPENEMR_VERSION build argument.
# Examples:
#   - Branch: --build-arg OPENEMR_VERSION=master
#   - Tag:    --build-arg OPENEMR_VERSION=v7.0.5
ARG OPENEMR_VERSION=master
FROM base AS openemr-source
# FORK: source comes from this repository via the build context rather than an
# upstream git clone. The clone URL in docker/release/Dockerfile is hardcoded to
# openemr/openemr with no build-arg override, and this fork lives on a private
# remote the build container cannot reach. See .dockerignore for exclusions.
WORKDIR /
COPY . /openemr
RUN rm -rf /openemr/.git

# Derive the application version from version.php and fail the build if it
# disagrees with OPENEMR_IMAGE_VERSION. An image whose tag does not match its
# own contents is a traceability hole, so make that drift a build error rather
# than something discovered after deployment. Update the default below (and
# the image tag) whenever version.php changes.
ARG OPENEMR_IMAGE_VERSION=8.2.0-dev
# The single quotes around the php snippet are deliberate: PHP must receive
# $v_major etc. literally rather than having the shell expand them.
# hadolint ignore=SC2016
RUN set -eu; \
    detected="$(php -r 'require "/openemr/version.php"; echo $v_major . "." . $v_minor . "." . $v_patch . $v_tag;')"; \
    echo "version.php reports: ${detected}"; \
    if [ "${detected}" != "${OPENEMR_IMAGE_VERSION}" ]; then \
        echo "ERROR: OPENEMR_IMAGE_VERSION=${OPENEMR_IMAGE_VERSION} does not match version.php (${detected})."; \
        echo "       Rebuild with --build-arg OPENEMR_IMAGE_VERSION=${detected} and tag the image to match."; \
        exit 1; \
    fi; \
    printf '%s' "${detected}" > /openemr-version

# Stage 2: Install PHP dependencies (Composer)
# Separate stage allows Docker to cache this layer independently
FROM base AS openemr-composer
COPY --from=openemr-source /openemr /openemr
WORKDIR /openemr
# Use Docker buildkit cache mount for Composer cache (requires DOCKER_BUILDKIT=1)
# id= is required by some builders (e.g. Railway's Railpack) that reject an
# unnamed cache mount even though it's optional per the BuildKit spec itself.
RUN --mount=type=cache,id=composer-cache,target=/root/.composer/cache \
    composer install --no-dev --optimize-autoloader \
    && composer dump-autoload --optimize --apcu

# Stage 3: Build frontend assets (npm)
# Separate stage allows Docker to cache npm dependencies independently
FROM base AS openemr-assets
COPY --from=openemr-source /openemr /openemr
WORKDIR /openemr
RUN apk add --no-cache build-base nodejs npm
# Use Docker buildkit cache mount for npm cache (requires DOCKER_BUILDKIT=1)
# id= is required by some builders (e.g. Railway's Railpack) that reject an
# unnamed cache mount even though it's optional per the BuildKit spec itself.
RUN --mount=type=cache,id=npm-cache,target=/root/.npm \
    npm install --unsafe-perm \
    && npm run build \
    && cd ccdaservice \
    && npm install --unsafe-perm \
    && cd .. \
    && rm -fr node_modules \
    && apk del --no-cache build-base

# Stage 4: Final assembly - combine everything in base image
# Combine everything and set permissions
FROM base AS production

# Standard OCI metadata. The version is the same value asserted against
# version.php in the openemr-source stage, so `docker inspect` reports a version
# that provably matches the code in the image.
ARG OPENEMR_IMAGE_VERSION=8.2.0-dev
LABEL org.opencontainers.image.title="OpenEMR" \
      org.opencontainers.image.description="OpenEMR electronic health records and practice management" \
      org.opencontainers.image.version="${OPENEMR_IMAGE_VERSION}" \
      org.opencontainers.image.licenses="GPL-3.0-or-later" \
      org.opencontainers.image.url="https://www.open-emr.org" \
      org.opencontainers.image.source="https://github.com/openemr/openemr"

# Record the asserted version inside the image as well, so it can be read at
# runtime without depending on labels surviving a registry round-trip.
COPY --from=openemr-source /openemr-version /etc/openemr-version

# Copy OpenEMR source
COPY --from=openemr-source /openemr /tmp/openemr
# Copy Composer dependencies
COPY --from=openemr-composer /openemr/vendor /tmp/openemr/vendor
COPY --from=openemr-composer /openemr/composer.json /tmp/openemr/composer.json
COPY --from=openemr-composer /openemr/composer.lock /tmp/openemr/composer.lock
# Copy built assets
COPY --from=openemr-assets /openemr/public /tmp/openemr/public
COPY --from=openemr-assets /openemr/ccdaservice /tmp/openemr/ccdaservice

# ============================================================================
# FORK: RELOCATE THE DEMO SEED, AND DROP docker/ FROM THE WEB ROOT
# ============================================================================
# `COPY . /openemr` brings the whole repo -- including docker/ -- into the tree
# that becomes DocumentRoot. Those files end up mode 400 owned by apache, so
# Apache can serve them, and openemr.conf only denies sites/*/documents and
# bin/. That would publish the demo database dump (users_secure bcrypt hashes,
# the entire globals table) at /docker/release/seed/*.sql.gz, plus a readable
# copy of openemr.sh and auto_configure.php under /docker/release/.
#
# (The top-level openemr.sh / auto_configure.php copied in later are a separate
# thing and are already protected by root-owned 500/000 permissions.)
#
# Move the seed to /opt -- outside DocumentRoot -- and delete docker/ entirely.
# root:apache 0440 lets the boot entrypoint (root) read it for the restore, and
# the apache-run admin "reset demo data" action read it without a redeploy.
RUN mkdir -p /opt/openemr-seed \
    && mv /tmp/openemr/docker/release/seed/*.sql.gz /opt/openemr-seed/ \
    && rm -rf /tmp/openemr/docker \
    && chown -R root:apache /opt/openemr-seed \
    && chmod 0750 /opt/openemr-seed \
    && chmod 0440 /opt/openemr-seed/*.sql.gz

RUN cd /tmp \
    # =========================================================================
    # PRE-SET FILE PERMISSIONS DURING BUILD (major startup optimization)
    # =========================================================================
    # Set secure permissions now so runtime only needs to handle exceptions
    # Directories: 500 (read + execute for owner)
    && find openemr -type d -exec chmod 500 {} + \
    # Files: 400 (read-only for owner)
    && find openemr -type f -exec chmod 400 {} + \
    # Exceptions that need to be writable during setup:
    # - sqlconf.php: Written during auto-configuration
    && chmod 666 openemr/sites/default/sqlconf.php \
    # - sites/default directory: Needs write access for setup
    && chmod 700 openemr/sites/default \
    # - documents directory: Needs write access for uploads
    && find openemr/sites/default/documents -type d -exec chmod 700 {} + \
    && find openemr/sites/default/documents -type f -exec chmod 600 {} + \
    # Set ownership to apache user for proper file access
    && chown -R apache:apache openemr/ \
    # Move OpenEMR to web root directory
    && mv openemr /var/www/localhost/htdocs/ \
    # Create SSL certificate directories
    && mkdir -p /etc/ssl/certs /etc/ssl/private \
    # Disable Apache logging to reduce disk usage (logs handled by Docker)
    && sed -i 's/^ *CustomLog/#CustomLog/' /etc/apache2/httpd.conf \
    && sed -i 's/^ *ErrorLog/#ErrorLog/' /etc/apache2/httpd.conf \
    && sed -i 's/^ *CustomLog/#CustomLog/' /etc/apache2/conf.d/ssl.conf \
    && sed -i 's/^ *TransferLog/#TransferLog/' /etc/apache2/conf.d/ssl.conf

# ============================================================================
# WORKING DIRECTORY AND VOLUMES
# ============================================================================
# Set working directory to OpenEMR installation
WORKDIR /var/www/localhost/htdocs/openemr

# SSL/Let's Encrypt certs at /etc/letsencrypt and /etc/ssl used to be
# declared as an image VOLUME here so a plain `docker run` (no explicit
# volume/bind mount) still got an anonymous volume and certs survived a
# container recreation. Removed because Railway's builder (Railpack) rejects
# any VOLUME instruction outright -- persistent storage there has to be a
# Railway Volume, declared per-service, not baked into the image. A compose
# target that wants the old persist-across-restarts behavior back needs to
# declare its own named volume for these two paths explicitly (ssl.sh
# regenerates a self-signed cert on boot if missing either way, so the
# practical effect of not doing so is just a new self-signed cert on every
# container recreation, not a functional break).

# ============================================================================
# APACHE AND PHP CONFIGURATION FILES
# ============================================================================
# Set Apache log directory environment variable
ENV APACHE_LOG_DIR=/var/log/apache2

# Copy PHP configuration file with OpenEMR-optimized settings
COPY docker/release/php.ini /etc/php${PHP_VERSION_ABBR}/php.ini

# Copy Apache virtual host configuration for OpenEMR
COPY docker/release/openemr.conf /etc/apache2/conf.d/

# ============================================================================
# OPENEMR SCRIPTS AND UTILITIES
# ============================================================================
# Copy main startup and configuration scripts
# - openemr.sh: Main container startup script (handles setup, upgrades, Apache)
# - ssl.sh: SSL/TLS certificate management script
# - xdebug.sh: XDebug configuration script (for development)
# - auto_configure.php: Automated OpenEMR installation script
COPY docker/release/openemr.sh docker/release/ssl.sh docker/release/xdebug.sh docker/release/auto_configure.php /var/www/localhost/htdocs/openemr/

# Copy admin unlock utilities (for password recovery)
COPY docker/release/utilities/unlock_admin.php docker/release/utilities/unlock_admin.sh /root/

# Set script permissions:
# - Executable scripts: 500 (read and execute for owner only)
# - PHP scripts: 000 (no access) - prevents accidental execution until enabled
RUN chmod 500 openemr.sh ssl.sh xdebug.sh /root/unlock_admin.sh \
    && chmod 000 auto_configure.php /root/unlock_admin.php

# ============================================================================
# FORK: RAILWAY BOOT WRAPPER
# ============================================================================
# railway-entrypoint.sh becomes the platform startCommand. It restores the demo
# seed, writes sqlconf.php, rotates the admin password, then `exec`s the stock
# openemr.sh -- so the image stays byte-identical to what runs anywhere else.
#
# harden.php is NOT in /root: it runs dropped to the apache user via su-exec,
# and /root is 0700 root:root, so apache could not even traverse it. /opt with
# root:apache 0750 lets apache read it while staying unwritable and outside
# DocumentRoot.
COPY docker/release/railway/railway-entrypoint.sh /root/railway-entrypoint.sh
COPY docker/release/railway/harden.php /opt/openemr-railway/harden.php
RUN chmod 0500 /root/railway-entrypoint.sh \
    && chown -R root:apache /opt/openemr-railway \
    && chmod 0750 /opt/openemr-railway \
    && chmod 0440 /opt/openemr-railway/harden.php

# ============================================================================
# UPGRADE SYSTEM
# ============================================================================
# Copy upgrade scripts and version tracking file
# These scripts handle filesystem upgrades when moving between OpenEMR versions
# The docker-version file tracks the installed version for upgrade detection
COPY docker/release/upgrade/docker-version \
     docker/release/upgrade/fsupgrade-1.sh \
     docker/release/upgrade/fsupgrade-2.sh \
     docker/release/upgrade/fsupgrade-3.sh \
     docker/release/upgrade/fsupgrade-4.sh \
     docker/release/upgrade/fsupgrade-5.sh \
     docker/release/upgrade/fsupgrade-6.sh \
     docker/release/upgrade/fsupgrade-7.sh \
     docker/release/upgrade/fsupgrade-8.sh \
     docker/release/upgrade/fsupgrade-9.sh \
     docker/release/upgrade/fsupgrade-10.sh \
     docker/release/upgrade/fsupgrade-11.sh \
     /root/

# Set upgrade scripts as executable (read and execute for owner only)
RUN chmod 500 \
    /root/fsupgrade-1.sh \
    /root/fsupgrade-2.sh \
    /root/fsupgrade-3.sh \
    /root/fsupgrade-4.sh \
    /root/fsupgrade-5.sh \
    /root/fsupgrade-6.sh \
    /root/fsupgrade-7.sh \
    /root/fsupgrade-8.sh \
    /root/fsupgrade-9.sh \
    /root/fsupgrade-10.sh \
    /root/fsupgrade-11.sh

# ============================================================================
# APACHE RUNTIME DIRECTORY
# ============================================================================
# Create Apache runtime directory to prevent premature process termination
# Apache requires this directory for PID files and shared memory
RUN mkdir -p /run/apache2

# ============================================================================
# DEVELOPMENT TOOLS LIBRARY
# ============================================================================
# Copy shared library of utility functions used by OpenEMR scripts
# This library provides database operations, configuration helpers, etc.
COPY docker/release/utilities/devtoolsLibrary.source /root/

# ============================================================================
# SWARM MODE SUPPORT
# ============================================================================
# Prepare directories for Docker Swarm/orchestration mode
# These directories contain templates that are restored when containers start
# with empty volumes, enabling multi-container deployments
RUN mkdir /swarm-pieces \
    && rsync --owner --group --perms --delete --recursive --links /etc/ssl /swarm-pieces/ \
    && rsync --owner --group --perms --delete --recursive --links /var/www/localhost/htdocs/openemr/sites /swarm-pieces/

# ============================================================================
# CONTAINER STARTUP
# ============================================================================
# Set default command to run OpenEMR startup script
# This script handles database setup, configuration, and Apache startup
CMD [ "./openemr.sh" ]

# Expose HTTP and HTTPS ports
EXPOSE 80 443
