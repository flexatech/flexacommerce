#!/usr/bin/env bash
# Build a production zip of flexacommerce into ./build/.
set -euo pipefail

PLUGIN_SLUG="flexacommerce"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BUILD_DIR="${ROOT_DIR}/build"
STAGE_DIR="${BUILD_DIR}/${PLUGIN_SLUG}"

cd "${ROOT_DIR}"

# Read plugin version from the main file.
VERSION="$(grep -E '^[[:space:]]*\*[[:space:]]*Version:' "${PLUGIN_SLUG}.php" | head -1 | sed -E 's/.*Version:[[:space:]]*([^[:space:]]+).*/\1/')"
if [[ -z "${VERSION}" ]]; then
    echo "Could not read Version from ${PLUGIN_SLUG}.php" >&2
    exit 1
fi
echo "Building ${PLUGIN_SLUG} v${VERSION}"

# No composer step: the plugin has no runtime composer dependencies and the
# bootstrap falls back to its own autoloader when vendor/ is absent.

# Admin bundle.
(
    cd client
    pnpm install --frozen-lockfile
    pnpm build
)
if [[ ! -f assets/dist/.vite/manifest.json ]]; then
    echo "assets/dist/.vite/manifest.json is missing; the client build failed." >&2
    exit 1
fi

# Stage.
rm -rf "${BUILD_DIR}"
mkdir -p "${STAGE_DIR}"

EXCLUDES=()
if [[ -f .distignore ]]; then
    while IFS= read -r line; do
        line="${line%%#*}"
        line="${line## }"
        line="${line%% }"
        [[ -z "${line}" ]] && continue
        EXCLUDES+=(--exclude="${line#/}")
    done < .distignore
fi

rsync -a "${EXCLUDES[@]}" --exclude="build" --exclude=".git" "${ROOT_DIR}/" "${STAGE_DIR}/"

cd "${BUILD_DIR}"
ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"
rm -f "${ZIP_NAME}"
zip -rq "${ZIP_NAME}" "${PLUGIN_SLUG}"
echo "Built ${BUILD_DIR}/${ZIP_NAME}"

# Drop the staging tree; only the zip needs to stay in build/.
rm -rf "${STAGE_DIR}"
