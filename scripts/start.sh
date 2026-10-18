#!/bin/sh
# Starts the poller in the background and returns at once. Safe to run twice: the poller takes a
# lock and exits if another copy is already running.
#
# FPP runs this as root (postStart.sh); the settings page runs it as the web user. Either way the
# poller itself runs as the media folder's owner (fpp), so the lock file, the PID and the status
# files all belong to one user and preStop.sh can find it.
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
MEDIADIR="$(cd "$PLUGIN_DIR/../.." && pwd)"
DATA_DIR="$MEDIADIR/plugindata/lighthunt-fpp-plugin"
PHP="$(command -v php || echo /usr/bin/php)"
DAEMON="$PLUGIN_DIR/lighthunt-daemon.php"

if [ "$(id -u)" = "0" ]; then
    # Only root creates the data folder (and hands it to the media owner, the 0.1.1 fix).
    OWNER="$(stat -c %U "$MEDIADIR" 2>/dev/null)"
    mkdir -p "$DATA_DIR" 2>/dev/null && chown -R "$(stat -c '%u:%g' "$MEDIADIR")" "$DATA_DIR" 2>/dev/null
    rm -f "$DATA_DIR/stopped"
    # A log left by a root-run poller (0.1.x) must stay writable by the poller's user.
    LOG_FILE="$MEDIADIR/logs/plugin-lighthunt-fpp-plugin.log"
    [ -f "$LOG_FILE" ] && chown "$(stat -c '%u:%g' "$MEDIADIR")" "$LOG_FILE" 2>/dev/null
    if [ -n "$OWNER" ] && [ "$OWNER" != "root" ] && command -v runuser >/dev/null 2>&1; then
        nohup runuser -u "$OWNER" -- "$PHP" "$DAEMON" >/dev/null 2>&1 &
    elif [ -n "$OWNER" ] && [ "$OWNER" != "root" ] && command -v su >/dev/null 2>&1; then
        nohup su -s /bin/sh "$OWNER" -c 'exec "$0" "$1"' "$PHP" "$DAEMON" >/dev/null 2>&1 &
    else
        nohup "$PHP" "$DAEMON" >/dev/null 2>&1 &
    fi
else
    # Not root: never create anything. No data folder means the plugin was uninstalled.
    [ -d "$DATA_DIR" ] || exit 0
    nohup "$PHP" "$DAEMON" >/dev/null 2>&1 &
fi
exit 0
