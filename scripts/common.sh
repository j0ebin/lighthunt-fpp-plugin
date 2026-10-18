#!/bin/bash
# shellcheck disable=SC2034
# Sourced by the other scripts. Finds this plugin's folders the way FPP's own scripts do.
PLUGIN_NAME="lighthunt-fpp-plugin"
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
# <media>/plugins/<repoName> is the install folder, so media is two levels up.
MEDIADIR="${MEDIADIR:-$(cd "$PLUGIN_DIR/../.." && pwd)}"
# FPP's own `common` gives LOGDIR as it does for its scripts. It is written for FPP's scripts, so keep
# our folders as they were and keep any failure in it from ending ours.
FPPDIR="${FPPDIR:-/opt/fpp}"
if [ -f "$FPPDIR/scripts/common" ]; then
    _lh_dir="$PLUGIN_DIR"
    _lh_media="$MEDIADIR"
    case $- in *e*) _lh_e=1 ;; esac
    set +e
    # shellcheck disable=SC1091
    . "$FPPDIR/scripts/common" >/dev/null 2>&1
    PLUGIN_DIR="$_lh_dir"
    MEDIADIR="$_lh_media"
    [ -z "$_lh_e" ] || set -e
fi
LOGDIR="${LOGDIR:-$MEDIADIR/logs}"
DATA_DIR="$MEDIADIR/plugindata/$PLUGIN_NAME"
LOG_FILE="$LOGDIR/plugin-$PLUGIN_NAME.log"
