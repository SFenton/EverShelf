FROM php:8.2-apache-bookworm

ARG VCS_REF
LABEL org.opencontainers.image.revision="${VCS_REF}"
RUN printf '%s' "${VCS_REF}" \
      | grep -Eq '^[0-9a-f]{40}([0-9a-f]{24})?$' \
    || { echo "VCS_REF must be an exact 40- or 64-character commit SHA" >&2; exit 1; }

# Install required PHP extensions + Tesseract OCR for offline expiry date reading
RUN apt-get update && apt-get upgrade -y && apt-get install -y \
    libsqlite3-dev \
    libcurl4-openssl-dev \
    libonig-dev \
    libgd-dev \
    tesseract-ocr \
    tesseract-ocr-ita \
    tesseract-ocr-eng \
    cron \
    && docker-php-ext-install pdo_sqlite curl mbstring gd \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Apache rebuilds supplementary groups after dropping privileges, so record the
# host socket group in /etc/group rather than relying only on Compose group_add.
ARG ONTOLOGY_SOCKET_GID=1000
RUN set -eux; \
    ontology_group="$(getent group "${ONTOLOGY_SOCKET_GID}" | cut -d: -f1 || true)"; \
    if [ -z "${ontology_group}" ]; then \
        ontology_group=evershelf-ontology; \
        groupadd --gid "${ONTOLOGY_SOCKET_GID}" "${ontology_group}"; \
    fi; \
    usermod --append --groups "${ontology_group}" www-data

# Enable Apache mod_rewrite and mod_headers
RUN a2enmod rewrite headers

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . /var/www/html/
RUN printf '%s\n' "${VCS_REF}" > /var/www/html/.build-revision

# Create persistent runtime directories with proper permissions
RUN mkdir -p /var/www/html/data/backups /var/www/html/data/logs \
    && chown -R www-data:www-data /var/www/html/data \
    && chmod -R 775 /var/www/html/data

# Create .env from example if it doesn't exist (will be overridden by volume mount)
RUN [ ! -f /var/www/html/.env ] && cp /var/www/html/.env.example /var/www/html/.env || true

# Apache configuration: serve from app root
RUN echo '<Directory /var/www/html>\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>\n\
<Directory /var/www/html/data>\n\
    AllowOverride None\n\
    Require all denied\n\
</Directory>\n\
# Traefik / reverse-proxy: treat forwarded HTTPS as on so .htaccess does not redirect-loop\n\
SetEnvIf X-Forwarded-Proto "https" HTTPS=on' > /etc/apache2/conf-available/evershelf.conf \
    && a2enconf evershelf

# Background jobs: cron drives the canonical/taxonomy enrichment queue, so it has to run
# in the container rather than relying on host crontab configuration.
COPY docker/evershelf-cron /etc/cron.d/evershelf
COPY docker/entrypoint.sh /usr/local/bin/evershelf-entrypoint
COPY docker/ontology-activation-worker.sh \
    /usr/local/bin/evershelf-ontology-activation-worker
RUN chmod 0644 /etc/cron.d/evershelf \
    && chmod 0755 /usr/local/bin/evershelf-entrypoint \
        /usr/local/bin/evershelf-ontology-activation-worker

# Expose port 80
EXPOSE 80

# Health check
HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
    CMD ["php", "/var/www/html/scripts/container-health.php"]

CMD ["evershelf-entrypoint"]
