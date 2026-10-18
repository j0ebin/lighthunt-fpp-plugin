#!/bin/bash
# Starts the poller in the background and returns at once. Safe to run twice: the poller takes a
# lock and exits if another copy is already running.
#
# FPP runs this as root (postStart.sh); the settings page runs it as the web user. Either way the
# poller itself runs as the media folder's owner (fpp), so the lock file, the PID and the status
# files all belong to one user and preStop.sh can find it. Whatever the poller prints goes to the
# plugin log.
# shellcheck source=common.sh
. "$(dirname "$0")/common.sh"
PHP="$(command -v php || echo /usr/bin/php)"
DAEMON="$PLUGIN_DIR/lighthunt-daemon.php"
[ -d "$LOGDIR" ] || LOG_FILE=/dev/null

if [ "$(id -u)" = "0" ]; then
    # Only root creates the data folder (and hands it to the media owner, the 0.1.1 fix).
    OWNER="$(stat -c %U "$MEDIADIR" 2>/dev/null)"
    mkdir -p "$DATA_DIR" 2>/dev/null && chown -R "$(stat -c '%u:%g' "$MEDIADIR")" "$DATA_DIR" 2>/dev/null
    rm -f "$DATA_DIR/stopped"
    # The poller's user must own the log: this shell opens it below, so make it first and hand it over.
    if [ "$LOG_FILE" != /dev/null ]; then
        touch "$LOG_FILE" 2>/dev/null
        chown "$(stat -c '%u:%g' "$MEDIADIR")" "$LOG_FILE" 2>/dev/null
    fi
    if [ -n "$OWNER" ] && [ "$OWNER" != "root" ] && command -v runuser >/dev/null 2>&1; then
        nohup runuser -u "$OWNER" -- "$PHP" "$DAEMON" </dev/null >> "$LOG_FILE" 2>&1 &
    elif [ -n "$OWNER" ] && [ "$OWNER" != "root" ] && command -v su >/dev/null 2>&1; then
        nohup su -s /bin/sh "$OWNER" -c 'exec "$0" "$1"' "$PHP" "$DAEMON" </dev/null >> "$LOG_FILE" 2>&1 &
    else
        nohup "$PHP" "$DAEMON" </dev/null >> "$LOG_FILE" 2>&1 &
    fi
else
    # Not root: never create anything. No data folder means the plugin was uninstalled.
    [ -d "$DATA_DIR" ] || exit 0
    # A log an older root-run poller left behind may not be writable; run without it rather than not at all.
    if [ -e "$LOG_FILE" ] && [ ! -w "$LOG_FILE" ]; then LOG_FILE=/dev/null; fi
    nohup "$PHP" "$DAEMON" </dev/null >> "$LOG_FILE" 2>&1 &
fi
exit 0
