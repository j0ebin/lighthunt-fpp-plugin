#!/bin/sh
# FPP runs this after fppd starts. Starting is quick and backgrounded; nothing waits here.
exec /bin/sh "$(dirname "$0")/start.sh"
