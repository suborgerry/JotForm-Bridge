#!/usr/bin/env bash
#
# Downloads the WordPress the integration suite runs against.
#
# The suite needs a real WordPress: a real options table, a real REST server, a
# real nonce. It does not need a database server. WordPress core plus the
# official SQLite database integration drop-in gives all of that from a plain
# curl, with no Docker, no MySQL and no wp-env — which is what makes it
# runnable on a laptop and in CI with the same command.
#
# Nothing here is shipped. The download lives in .wordpress/, which is ignored
# by git, and the plugin is symlinked into it so the suite always tests the
# working tree rather than a copy that can drift.
#
# Usage: bin/install-wp.sh [--force]
#
#   JFB_WP_DIR       where WordPress is unpacked   (default: .wordpress)
#   JFB_WP_VERSION   "latest" or e.g. "6.4.3"      (default: latest)

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WP_DIR="${JFB_WP_DIR:-${ROOT}/.wordpress}"
WP_VERSION="${JFB_WP_VERSION:-latest}"
SLUG="jotform-bridge"
FORCE="${1:-}"

link_plugin() {
    mkdir -p "${WP_DIR}/wp-content/plugins"
    rm -rf "${WP_DIR}/wp-content/plugins/${SLUG}"
    ln -s "${ROOT}/${SLUG}" "${WP_DIR}/wp-content/plugins/${SLUG}"
}

if [[ "${FORCE}" == "--force" ]]; then
    rm -rf "${WP_DIR}"
fi

if [[ -f "${WP_DIR}/wp-includes/version.php" && -f "${WP_DIR}/wp-content/db.php" ]]; then
    # Already there. The symlink is refreshed anyway: the repository may have
    # been moved or cloned somewhere else since it was made.
    link_plugin

    echo "WordPress is already installed in ${WP_DIR}."
    exit 0
fi

for command in curl tar unzip; do
    if ! command -v "${command}" >/dev/null 2>&1; then
        echo "error: ${command} is required and was not found" >&2
        exit 1
    fi
done

if [[ "${WP_VERSION}" == "latest" ]]; then
    WP_ARCHIVE="https://wordpress.org/latest.tar.gz"
else
    WP_ARCHIVE="https://wordpress.org/wordpress-${WP_VERSION}.tar.gz"
fi

STAGING="$(mktemp -d)"
trap 'rm -rf "${STAGING}"' EXIT

echo "Downloading ${WP_ARCHIVE} ..."
curl -fsSL "${WP_ARCHIVE}" -o "${STAGING}/wordpress.tar.gz"

echo "Downloading the SQLite database integration drop-in ..."
curl -fsSL "https://downloads.wordpress.org/plugin/sqlite-database-integration.zip" \
    -o "${STAGING}/sqlite.zip"

rm -rf "${WP_DIR}"
mkdir -p "${WP_DIR}"

# The archive holds a single wordpress/ directory; strip it.
tar -xzf "${STAGING}/wordpress.tar.gz" -C "${WP_DIR}" --strip-components=1

mkdir -p "${WP_DIR}/wp-content/plugins"
unzip -q "${STAGING}/sqlite.zip" -d "${WP_DIR}/wp-content/plugins"

SQLITE_DIR="${WP_DIR}/wp-content/plugins/sqlite-database-integration"

if [[ ! -f "${SQLITE_DIR}/db.copy" ]]; then
    echo "error: the SQLite integration is missing db.copy" >&2
    exit 1
fi

# db.copy is a template: it carries two placeholders the plugin's own installer
# fills in, and without them the drop-in cannot find its implementation.
sed \
    -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#${SQLITE_DIR}#g" \
    -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#g" \
    "${SQLITE_DIR}/db.copy" > "${WP_DIR}/wp-content/db.php"

link_plugin

WP_INSTALLED_VERSION="$(grep -m1 "^\$wp_version" "${WP_DIR}/wp-includes/version.php" | cut -d"'" -f2)"

echo "WordPress ${WP_INSTALLED_VERSION} is ready in ${WP_DIR}."
