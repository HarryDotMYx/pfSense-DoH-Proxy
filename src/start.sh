#!/bin/sh
PID_FILE="/var/run/doh-proxy.pid"
APP_DIR="/root/doh-proxy"
LOG_FILE="/var/log/doh-proxy.log"
SERVICE_BIN="/usr/sbin/service"

if [ -f "$PID_FILE" ] && kill -0 "$(cat "$PID_FILE")" 2>/dev/null; then
    exit 0
fi

rm -f "$PID_FILE"
nohup /usr/local/bin/php "$APP_DIR/doh_proxy.php" > "$LOG_FILE" 2>&1 &
echo $! > "$PID_FILE"

ready=0
for _ in $(jot 20 2>/dev/null || seq 1 20); do
    if sockstat -4 -l | grep -q '127.0.0.1:5053'; then
        ready=1
        break
    fi
    sleep 1
done

if [ "$ready" -eq 1 ]; then
    echo "[$(date -Iseconds)] DoH proxy ready, restarting unbound." >> "$LOG_FILE"
    "$SERVICE_BIN" unbound onerestart >/dev/null 2>&1 || true
else
    echo "[$(date -Iseconds)] DoH proxy did not become ready during boot wait window." >> "$LOG_FILE"
fi
