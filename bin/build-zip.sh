#!/usr/bin/env bash
#
# Builds the release ZIP.
#
# The archive is the contents of jotform-bridge/ under a single jotform-bridge/
# top-level directory and nothing else: no Composer manifest, no dev vendor
# directory, no tests, no prompts. The plugin ships its own PSR-4 autoloader, so
# the unpacked result runs as-is — no `composer install`, no `npm run build`.
#
# Usage: bin/build-zip.sh [output-directory]

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="jotform-bridge"
SOURCE="${ROOT}/${SLUG}"
OUTPUT_DIR="${1:-${ROOT}/dist}"

if [[ ! -f "${SOURCE}/${SLUG}.php" ]]; then
    echo "error: ${SOURCE}/${SLUG}.php not found" >&2
    exit 1
fi

VERSION="$(grep -m1 '^ \* Version:' "${SOURCE}/${SLUG}.php" | awk '{print $3}')"

if [[ -z "${VERSION}" ]]; then
    echo "error: could not read the version from the plugin header" >&2
    exit 1
fi

ARCHIVE="${OUTPUT_DIR}/${SLUG}-${VERSION}.zip"
STAGING="$(mktemp -d)"
trap 'rm -rf "${STAGING}"' EXIT

mkdir -p "${OUTPUT_DIR}"
rm -f "${ARCHIVE}"

# Copy the plugin, then drop everything that is developer-only. rsync is not
# assumed to be present, so the exclusions happen after the copy.
cp -R "${SOURCE}" "${STAGING}/${SLUG}"

find "${STAGING}/${SLUG}" \
    \( -name '.DS_Store' -o -name '.gitkeep' -o -name '*.map' -o -name '.git*' \) \
    -print -delete >/dev/null

rm -rf \
    "${STAGING}/${SLUG}/vendor" \
    "${STAGING}/${SLUG}/node_modules" \
    "${STAGING}/${SLUG}/composer.json" \
    "${STAGING}/${SLUG}/composer.lock" \
    "${STAGING}/${SLUG}/package.json" \
    "${STAGING}/${SLUG}/tests"

# A file the plugin cannot run without would be a packaging bug, so the two
# entry points are verified rather than assumed.
for required in "${SLUG}.php" "src/Autoloader.php" "src/api.php" "readme.txt"; do
    if [[ ! -f "${STAGING}/${SLUG}/${required}" ]]; then
        echo "error: ${required} is missing from the package" >&2
        exit 1
    fi
done

(cd "${STAGING}" && zip -qr "${ARCHIVE}" "${SLUG}")

echo "Built ${ARCHIVE}"
unzip -l "${ARCHIVE}" | tail -1
