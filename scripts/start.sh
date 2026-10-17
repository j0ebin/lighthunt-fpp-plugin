#!/bin/sh
# Starts the poller in the background and returns at once. Safe to run twice: the poller takes a
# lock and exits if another copy is already running.
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
# Installs from 0.1.0 left the data folder owned by root, which stops pairing. FPP runs this as root
# when fppd starts, so a restart hands the folder back to the media folder's owner.
MEDIADIR="$(cd "$PLUGIN_DIR/../.." && pwd)"
DATA_DIR="$MEDIADIR/plugindata/lighthunt-fpp-plugin"
mkdir -p "$DATA_DIR" 2>/dev/null && chown "$(stat -c '%u:%g' "$MEDIADIR")" "$DATA_DIR" 2>/dev/null
PHP="$(command -v php || echo /usr/bin/php)"
nohup "$PHP" "$PLUGIN_DIR/lighthunt-daemon.php" >/dev/null 2>&1 &
exit 0
