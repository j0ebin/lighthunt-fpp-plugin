#!/bin/bash
# FPP runs this after fppd starts. Starting is quick and backgrounded; nothing waits here.
exec /bin/bash "$(dirname "$0")/start.sh"
