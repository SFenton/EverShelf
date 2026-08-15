#!/bin/sh
# EverShelf container entrypoint.
#
# Starts the cron daemon before handing off to Apache. Upstream expects the host to run
# api/cron_smart_shopping.php on a schedule, which is easy to miss in a Docker deployment
# and leaves the canonical/taxonomy enrichment queue permanently stalled. Running cron in
# the container keeps the image self-sufficient.
set -e

install -d -o www-data -g www-data -m 0775 \
    /var/www/html/data \
    /var/www/html/data/backups
for database_file in \
    /var/www/html/data/evershelf.db \
    /var/www/html/data/evershelf.db-wal \
    /var/www/html/data/evershelf.db-shm \
    /var/www/html/data/evershelf.db.migration.lock
do
    if [ -e "$database_file" ]; then
        chown www-data:www-data "$database_file"
        chmod 0664 "$database_file"
    fi
done

su -s /bin/sh www-data -c \
    'php /var/www/html/scripts/migrate-database.php'

cron

exec apache2-foreground
