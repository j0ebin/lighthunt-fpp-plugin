#!/bin/sh
# Starts the poller in the background and returns at once. Safe to run twice: the poller takes a
# lock and exits if another copy is already running.
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PHP="$(command -v php || echo /usr/bin/php)"
nohup "$PHP" "$PLUGIN_DIR/lighthunt-daemon.php" >/dev/null 2>&1 &
exit 0
