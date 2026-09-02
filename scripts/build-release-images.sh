#!/bin/sh
set -eu

root="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
cd "$root"

if [ -n "$(git status --porcelain)" ]; then
    echo "Release images require a clean worktree" >&2
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

version="$(
    sed -n 's/^[[:space:]]*"version":[[:space:]]*"\([^"]*\)".*/\1/p' \
        manifest.json \
        | head -n 1
)"
case "$version" in
    ''|*[!0-9.]*)
        echo "Application version is invalid" >&2
        exit 2
        ;;
esac

short_revision="$(printf '%s' "$revision" | cut -c1-12)"
app_tag="evershelf:${version}-${short_revision}"
bridge_tag="evershelf-cookidoo-bridge:${revision}"

docker build \
    --build-arg "VCS_REF=${revision}" \
    --tag "$app_tag" \
    --tag "evershelf:${revision}" \
    .
docker build \
    --build-arg "VCS_REF=${revision}" \
    --tag "$bridge_tag" \
    cookidoo-bridge

for image in "$app_tag" "$bridge_tag"; do
    image_revision="$(
        docker image inspect \
            --format '{{ index .Config.Labels "org.opencontainers.image.revision" }}' \
            "$image"
    )"
    if [ "$image_revision" != "$revision" ]; then
        echo "Image revision mismatch for ${image}" >&2
        exit 2
    fi
done

printf '%s\n' \
    "EVERSHELF_IMAGE=${app_tag}" \
    "COOKIDOO_BRIDGE_IMAGE=${bridge_tag}" \
    "EVERSHELF_BUILD_SHA=${revision}"
