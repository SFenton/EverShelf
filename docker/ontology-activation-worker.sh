#!/bin/sh
set -u

heartbeat="${ONTOLOGY_ACTIVATION_HEARTBEAT_PATH:-/var/www/html/data/.ontology-activation-worker.heartbeat}"
status_path="${ONTOLOGY_ACTIVATION_STATUS_PATH:-/var/www/html/data/.ontology-activation-worker.status}"
sleep_seconds="${ONTOLOGY_ACTIVATION_WORKER_SLEEP_SECONDS:-2}"
failure_sleep_seconds="${ONTOLOGY_ACTIVATION_WORKER_FAILURE_SLEEP_SECONDS:-30}"
memory_limit="${ONTOLOGY_ACTIVATION_WORKER_MEMORY_LIMIT:-512M}"
stopping=0
child_pid=

case "$sleep_seconds:$failure_sleep_seconds" in
    *[!0-9:]*|:*|*:) echo "Invalid ontology activation worker sleep interval" >&2; exit 2 ;;
esac

write_heartbeat() {
    heartbeat_tmp="${heartbeat}.tmp.$$"
    if ! printf '%s\n' "$(date +%s)" > "$heartbeat_tmp"; then
        echo "Could not write ontology activation heartbeat" >&2
        exit 2
    fi
    mv "$heartbeat_tmp" "$heartbeat"
}

write_status() {
    status_tmp="${status_path}.tmp.$$"
    if ! printf '%s %s\n' "$1" "$(date +%s)" > "$status_tmp"; then
        echo "Could not write ontology activation status" >&2
        exit 2
    fi
    mv "$status_tmp" "$status_path"
}

stop_worker() {
    stopping=1
    if [ -n "$child_pid" ]; then
        kill "$child_pid" 2>/dev/null || true
        wait "$child_pid" 2>/dev/null || true
    fi
}

trap stop_worker TERM INT

write_heartbeat
write_status 0
while [ "$stopping" -eq 0 ]; do
    write_heartbeat
    php -d "memory_limit=${memory_limit}" \
        /var/www/html/scripts/process-ontology-activation.php "$@" &
    child_pid=$!
    wait "$child_pid"
    status=$?
    child_pid=
    write_heartbeat
    write_status "$status"
    if [ "$stopping" -ne 0 ]; then
        break
    fi
    if [ "$status" -eq 0 ]; then
        sleep "$sleep_seconds"
    else
        echo "Ontology activation cycle failed with status ${status}" >&2
        sleep "$failure_sleep_seconds"
    fi
done
