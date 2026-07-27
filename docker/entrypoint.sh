#!/bin/sh
# EverShelf container entrypoint.
#
# Starts the cron daemon before handing off to Apache. Upstream expects the host to run
# api/cron_smart_shopping.php on a schedule, which is easy to miss in a Docker deployment
# and leaves the canonical/taxonomy enrichment queue permanently stalled. Running cron in
# the container keeps the image self-sufficient.
set -e

cron

exec apache2-foreground
