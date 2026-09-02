#!/bin/sh
set -eu

root="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
cd "$root"

if [ -n "$(git status --porcelain)" ]; then
    echo "Exact-SHA Compose builds require a clean worktree" >&2
    exit 2
fi

revision="$(git rev-parse HEAD)"
case "$revision" in
    *[!0-9a-f]*|'')
        echo "Could not resolve an exact source revision" >&2
        exit 2
        ;;
esac
if [ "${#revision}" -ne 40 ] && [ "${#revision}" -ne 64 ]; then
    echo "Source revision must be 40 or 64 hexadecimal characters" >&2
    exit 2
fi

EVERSHELF_BUILD_SHA="$revision" \
    exec docker compose up -d --build "$@"
