#!/bin/sh
# EverShelf container entrypoint.
#
# Starts the cron daemon before handing off to Apache. Upstream expects the host to run
# api/cron_smart_shopping.php on a schedule, which is easy to miss in a Docker deployment
# and leaves the canonical/taxonomy enrichment queue permanently stalled. Running cron in
# the container keeps the image self-sufficient.
set -e

runtime_timezone="${TZ:-UTC}"
case "$runtime_timezone" in
    ""|/*|*".."*|*[!A-Za-z0-9_+./-]*)
        echo "Invalid TZ value: $runtime_timezone" >&2
        exit 1
        ;;
esac
zoneinfo_path="/usr/share/zoneinfo/$runtime_timezone"
if [ ! -f "$zoneinfo_path" ]; then
    echo "TZ does not resolve to a zoneinfo file: $runtime_timezone" >&2
    exit 1
fi
ln -snf "$zoneinfo_path" /etc/localtime
printf '%s\n' "$runtime_timezone" > /etc/timezone
cron_file=/etc/cron.d/evershelf
cron_tmp="$(mktemp)"
{
    printf 'TZ=%s\nCRON_TZ=%s\n' \
        "$runtime_timezone" \
        "$runtime_timezone"
    grep -v -E '^(TZ|CRON_TZ)=' "$cron_file" || true
} > "$cron_tmp"
chown root:root "$cron_tmp"
chmod 0644 "$cron_tmp"
mv "$cron_tmp" "$cron_file"

install -d -o www-data -g www-data -m 0775 \
    /var/www/html/data \
    /var/www/html/data/backups \
    /var/www/html/data/rate_limits
for database_file in \
    /var/www/html/data/evershelf.db \
    /var/www/html/data/evershelf.db-wal \
    /var/www/html/data/evershelf.db-shm \
    /var/www/html/data/evershelf.db.migration.lock \
    /var/www/html/data/canonical_queue.lock \
    /var/www/html/data/.canonical-queue-worker.lock \
    /var/www/html/data/foodon_lookup_cache.json.lock \
    /var/www/html/data/usda_fdc_lookup_cache.json.lock
do
    case "$database_file" in
        *.lock)
            if [ ! -e "$database_file" ]; then
                : > "$database_file"
            fi
            ;;
    esac
    if [ -e "$database_file" ]; then
        chown www-data:www-data "$database_file"
        chmod 0664 "$database_file"
    fi
done

su -s /bin/sh www-data -c \
    'php /var/www/html/scripts/migrate-database.php'

cron

exec apache2-foreground
