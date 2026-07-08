#!/bin/sh
PID_FILE="/var/run/doh-proxy.pid"

if [ -f "$PID_FILE" ]; then
    pid=$(cat "$PID_FILE")
    # Only kill if the PID really is our daemon - guards against a stale
    # pidfile whose PID number was reused by an unrelated process.
    if [ -n "$pid" ] && ps -p "$pid" -o command= 2>/dev/null | grep -q 'doh_proxy\.php'; then
        kill "$pid" 2>/dev/null || true
    fi
    rm -f "$PID_FILE"
fi
