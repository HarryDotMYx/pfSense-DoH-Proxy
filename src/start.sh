#!/bin/sh
PID_FILE="/var/run/doh-proxy.pid"
APP_DIR="/root/doh-proxy"
LOG_FILE="/var/log/doh-proxy.log"

# In DoT mode Unbound talks TLS directly - the daemon is not needed.
MODE=$(/usr/local/bin/php -r '$c = @include "/root/doh-proxy/config.php"; echo is_array($c) ? ($c["mode"] ?? "doh") : "doh";' 2>/dev/null)
if [ "$MODE" = "dot" ]; then
    exit 0
fi

# No upstream configured yet? Then there is nothing useful to start.
DOH_URL=$(/usr/local/bin/php -r '$c = @include "/root/doh-proxy/config.php"; echo is_array($c) ? (string) ($c["doh_url"] ?? "") : "";' 2>/dev/null)
if [ -z "$DOH_URL" ]; then
    echo "[$(date -Iseconds)] start.sh: no doh_url configured - not starting the daemon." >> "$LOG_FILE"
    exit 0
fi

# Single-flight guard: the boot shellcmd and a GUI save can race; only one
# starter may proceed. A crashed starter cannot wedge us: a lock older than
# 2 minutes is cleared for the next attempt.
LOCK_DIR="/var/run/doh-proxy.start.lock"
if ! mkdir "$LOCK_DIR" 2>/dev/null; then
    find "$LOCK_DIR" -maxdepth 0 -mmin +2 -exec rmdir {} \; 2>/dev/null
    exit 0
fi
trap 'rmdir "$LOCK_DIR" 2>/dev/null' EXIT INT TERM

if [ -f "$PID_FILE" ] && kill -0 "$(cat "$PID_FILE")" 2>/dev/null; then
    exit 0
fi

# Append to the log (history survives restarts) but keep it from growing
# without bound: above ~1 MB, keep only the last 500 lines.
if [ -f "$LOG_FILE" ] && [ "$(stat -f %z "$LOG_FILE" 2>/dev/null || echo 0)" -gt 1048576 ]; then
    tail -n 500 "$LOG_FILE" > "$LOG_FILE.tmp" && mv "$LOG_FILE.tmp" "$LOG_FILE"
fi

rm -f "$PID_FILE"
nohup /usr/local/bin/php "$APP_DIR/doh_proxy.php" >> "$LOG_FILE" 2>&1 &
echo $! > "$PID_FILE"

# Readiness check follows listen_host/listen_port from config.php.
LISTEN=$(/usr/local/bin/php -r '$c = @include "/root/doh-proxy/config.php"; $h = is_array($c) ? ($c["listen_host"] ?? "127.0.0.1") : "127.0.0.1"; $p = is_array($c) ? ($c["listen_port"] ?? 5053) : 5053; echo "$h:$p";' 2>/dev/null)
[ -n "$LISTEN" ] || LISTEN="127.0.0.1:5053"

ready=0
for _ in $(jot 20 2>/dev/null || seq 1 20); do
    if sockstat -4 -l | grep -qF "$LISTEN"; then
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
