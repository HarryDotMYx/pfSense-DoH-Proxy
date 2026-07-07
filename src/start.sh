#!/bin/sh
PID_FILE="/var/run/doh-proxy.pid"
APP_DIR="/root/doh-proxy"
LOG_FILE="/var/log/doh-proxy.log"

# In DoT mode Unbound talks TLS directly - the daemon is not needed.
MODE=$(/usr/local/bin/php -r '$c = @include "/root/doh-proxy/config.php"; echo is_array($c) ? ($c["mode"] ?? "doh") : "doh";' 2>/dev/null)
if [ "$MODE" = "dot" ]; then
    exit 0
fi

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
    echo "[$(date -Iseconds)] DoH proxy ready, reconfiguring unbound." >> "$LOG_FILE"
    # NOTE: never use "service unbound onerestart" here - on pfSense that
    # invokes the FreeBSD pkg rc script and starts a SECOND, unmanaged
    # unbound on 127.0.0.1:53. Use pfSense's own configure function.
    /usr/local/bin/php -r 'require_once("config.inc"); require_once("services.inc"); services_unbound_configure();' >/dev/null 2>&1 || true
else
    echo "[$(date -Iseconds)] DoH proxy did not become ready during boot wait window." >> "$LOG_FILE"
fi
