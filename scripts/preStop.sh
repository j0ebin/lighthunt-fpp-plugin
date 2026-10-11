#!/bin/bash
# FPP runs this before fppd stops. Stops the poller started by postStart.sh.
# shellcheck source=common.sh
. "$(dirname "$0")/common.sh"

PID_FILE="$DATA_DIR/daemon.lock"
[ -f "$PID_FILE" ] || exit 0
PID="$(cat "$PID_FILE" 2>/dev/null)"
case "$PID" in
    ''|*[!0-9]*) exit 0 ;;
esac
# Only stop the process if it really is our poller (a recycled PID must not be touched).
if ps -p "$PID" -o args= 2>/dev/null | grep -q "lighthunt-daemon.php"; then
    kill "$PID" 2>/dev/null || true
fi
exit 0
