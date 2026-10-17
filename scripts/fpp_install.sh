#!/bin/bash
set -e
# Runs when the plugin is installed or updated. Nothing to download or build: Falcon Player already
# ships PHP and curl. Safe to run again.
# shellcheck source=common.sh
. "$(dirname "$0")/common.sh"

if ! command -v php >/dev/null 2>&1; then
    echo "The Light Hunt plugin needs PHP, which Falcon Player normally includes." >&2
    exit 1
fi

mkdir -p "$DATA_DIR"
# FPP runs this as root, but its web pages run as the media folder's owner (fpp) and must write here.
chown "$(stat -c '%u:%g' "$MEDIADIR")" "$DATA_DIR"
chmod 0775 "$DATA_DIR" 2>/dev/null || true
chmod +x "$PLUGIN_DIR"/scripts/*.sh 2>/dev/null || true
echo "Light Hunt plugin installed. Open Status/Control > Light Hunt to pair it with your display."
exit 0
