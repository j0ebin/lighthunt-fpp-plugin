#!/bin/bash
# FPP runs this before fppd stops, and fpp_uninstall.sh and fpp_install.sh run it too. Stops the
# poller whichever user started it (postStart.sh as root, or the settings page as the web user).
# shellcheck source=common.sh
. "$(dirname "$0")/common.sh"

# Tell the settings page not to restart the poller while fppd is stopping. start.sh clears it.
# Never create the data folder here: after an uninstall it must stay gone.
[ -d "$DATA_DIR" ] && touch "$DATA_DIR/stopped" 2>/dev/null

# Match on the command line, not the PID file: a poller started by the page used to leave no PID.
PATTERN="$(printf '%s' "$PLUGIN_DIR/lighthunt-daemon.php" | sed 's/[][\.*^$+?(){}|]/\\&/g')"
PID="$(cat "$DATA_DIR/daemon.lock" 2>/dev/null)"
case "$PID" in
    ''|*[!0-9]*) PID="" ;;
esac
# The PID file is only a hint; a recycled PID is stopped only if it really is our poller.
if [ -n "$PID" ] && ps -p "$PID" -o args= 2>/dev/null | grep -q "lighthunt-daemon.php"; then
    kill "$PID" 2>/dev/null || true
fi
pkill -f "$PATTERN" 2>/dev/null || true
# Give it a moment, then make sure.
for _ in 1 2 3 4 5 6 7 8; do
    pgrep -f "$PATTERN" >/dev/null 2>&1 || exit 0
    sleep 0.25
done
pkill -KILL -f "$PATTERN" 2>/dev/null || true
exit 0
