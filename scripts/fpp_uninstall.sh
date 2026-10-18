#!/bin/bash
# Runs when the plugin is removed. Leaves nothing behind outside the plugin folder: no key, no
# status file, no log, no process. Safe to run again.
# shellcheck source=common.sh
. "$(dirname "$0")/common.sh"

# Stop the poller first, so nothing is left running to recreate the files below.
/bin/bash "$PLUGIN_DIR/scripts/preStop.sh" || true
rm -rf "$DATA_DIR"
rm -f "$LOG_FILE"
exit 0
