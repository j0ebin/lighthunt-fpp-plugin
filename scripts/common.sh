#!/bin/bash
# shellcheck disable=SC2034
# Sourced by the other scripts. Finds this plugin's folders the way FPP's own scripts do.
PLUGIN_NAME="lighthunt-fpp-plugin"
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
if [ -z "$FPPDIR" ] && [ -f /opt/fpp/scripts/common ]; then
    # shellcheck disable=SC1091
    . /opt/fpp/scripts/common
fi
# <media>/plugins/<repoName> is the install folder, so media is two levels up.
MEDIADIR="${MEDIADIR:-$(cd "$PLUGIN_DIR/../.." && pwd)}"
LOGDIR="${LOGDIR:-$MEDIADIR/logs}"
DATA_DIR="$MEDIADIR/plugindata/$PLUGIN_NAME"
LOG_FILE="$LOGDIR/plugin-$PLUGIN_NAME.log"
