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

# An update must replace a poller that is already running, or it keeps running the old code.
PATTERN="$(printf '%s' "$PLUGIN_DIR/lighthunt-daemon.php" | sed 's/[][\.*^$+?(){}|]/\\&/g')"
WAS_RUNNING=0
if pgrep -f "$PATTERN" >/dev/null 2>&1; then
    WAS_RUNNING=1
    /bin/bash "$PLUGIN_DIR/scripts/preStop.sh" || true
fi

mkdir -p "$DATA_DIR"
# FPP runs this as root, but its web pages run as the media folder's owner (fpp) and must write here.
# Older installs left root-owned files in it (the lock above all), so hand over the contents too.
chown -R "$(stat -c '%u:%g' "$MEDIADIR")" "$DATA_DIR"
[ -f "$LOG_FILE" ] && chown "$(stat -c '%u:%g' "$MEDIADIR")" "$LOG_FILE" 2>/dev/null
chmod 0755 "$DATA_DIR" 2>/dev/null || true
chmod +x "$PLUGIN_DIR"/scripts/*.sh 2>/dev/null || true

if [ "$WAS_RUNNING" = "1" ]; then
    /bin/bash "$PLUGIN_DIR/scripts/start.sh" || true
fi
echo "Light Hunt plugin installed. Open Status/Control > Light Hunt to pair it with your display."
exit 0
