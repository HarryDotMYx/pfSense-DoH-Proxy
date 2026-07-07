#!/bin/sh
#
# pfSense DoH Proxy - installer
# Run this ON the pfSense box as root:  sh install.sh [-y] [--url=https://host/dns-query]
#
set -u

APP_DIR="/root/doh-proxy"
WWW_PAGE="/usr/local/www/doh_proxy_gui.php"
PHP_BIN="/usr/local/bin/php"
SRC_DIR="$(cd "$(dirname "$0")" && pwd)/src"

AUTO_YES=0
DOH_URL=""

usage() {
    echo "Usage: sh install.sh [-y] [--url=https://host/dns-query]"
    echo "  -y          answer yes to all prompts"
    echo "  --url=URL   DoH endpoint to configure (skips the prompt)"
}

for arg in "$@"; do
    case "$arg" in
        -y|--yes) AUTO_YES=1 ;;
        --url=*)  DOH_URL="${arg#--url=}" ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Unknown option: $arg" >&2; usage; exit 1 ;;
    esac
done

ask() {
    if [ "$AUTO_YES" -eq 1 ]; then
        echo "$1 [y/N] y (auto)"
        return 0
    fi
    printf '%s [y/N] ' "$1"
    read -r reply
    case "$reply" in
        [Yy]*) return 0 ;;
        *) return 1 ;;
    esac
}

# --- sanity checks ------------------------------------------------------------
if [ "$(id -u)" -ne 0 ]; then
    echo "ERROR: run this as root." >&2
    exit 1
fi
if [ ! -f /etc/version ] || [ ! -x "$PHP_BIN" ] || [ ! -d /usr/local/www ]; then
    echo "ERROR: this does not look like a pfSense system." >&2
    exit 1
fi
if [ ! -f "$SRC_DIR/doh_proxy.php" ]; then
    echo "ERROR: src/ directory not found next to install.sh." >&2
    exit 1
fi

echo "==> Installing DoH proxy to $APP_DIR"
mkdir -p "$APP_DIR/backup"
cp "$SRC_DIR/doh_proxy.php" "$APP_DIR/doh_proxy.php"
cp "$SRC_DIR/set_url.php"   "$APP_DIR/set_url.php"
cp "$SRC_DIR/start.sh"      "$APP_DIR/start.sh"
cp "$SRC_DIR/stop.sh"       "$APP_DIR/stop.sh"
chmod 755 "$APP_DIR/start.sh" "$APP_DIR/stop.sh"

if [ ! -f "$APP_DIR/config.php" ]; then
    cp "$SRC_DIR/config.sample.php" "$APP_DIR/config.php"
    echo "==> Created new config: $APP_DIR/config.php"
else
    echo "==> Keeping existing config: $APP_DIR/config.php"
fi

# --- DoH endpoint ---------------------------------------------------------------
CURRENT_URL=$("$PHP_BIN" -r '$c = require $argv[1]; echo (string)($c["doh_url"] ?? "");' "$APP_DIR/config.php")

if [ -z "$CURRENT_URL" ] && [ -z "$DOH_URL" ] && [ "$AUTO_YES" -eq 0 ]; then
    printf 'Enter your DoH endpoint URL (e.g. https://dns.example.com/dns-query),\nor press Enter to configure it later in the GUI: '
    read -r DOH_URL
fi

if [ -n "$DOH_URL" ]; then
    "$PHP_BIN" "$APP_DIR/set_url.php" "$DOH_URL" || {
        echo "ERROR: could not set DoH URL." >&2
        exit 1
    }
    CURRENT_URL="$DOH_URL"
fi

# --- self-test ------------------------------------------------------------------
SELF_TEST_OK=0
if [ -n "$CURRENT_URL" ]; then
    echo "==> Running DoH self-test against $CURRENT_URL"
    if "$PHP_BIN" "$APP_DIR/doh_proxy.php" --self-test; then
        SELF_TEST_OK=1
        echo "==> Self-test passed"
    else
        echo "WARNING: self-test failed - check the URL. Unbound will NOT be touched." >&2
    fi
else
    echo "==> No DoH URL configured yet - set it later via Services > DoH Proxy."
fi

# --- webGUI page ----------------------------------------------------------------
echo "==> Installing webGUI page"
cp "$SRC_DIR/www/doh_proxy_gui.php" "$WWW_PAGE"
chmod 644 "$WWW_PAGE"

# --- menu entry + boot autostart (via pfSense config API) -------------------------
echo "==> Registering menu entry and boot autostart"
cat > /tmp/dohp_register.php <<'PHPEOF'
<?php
require_once('config.inc');
$changed = false;

$menus = config_get_path('installedpackages/menu', array());
$found = false;
foreach ($menus as $m) {
	if (is_array($m) && ($m['url'] ?? '') === '/doh_proxy_gui.php') {
		$found = true;
		break;
	}
}
if (!$found) {
	$menus[] = array('name' => 'DoH Proxy', 'section' => 'Services', 'url' => '/doh_proxy_gui.php');
	config_set_path('installedpackages/menu', $menus);
	$changed = true;
	echo "menu: added (Services > DoH Proxy)\n";
} else {
	echo "menu: already present\n";
}

$cmds = config_get_path('system/shellcmd', array());
if (!is_array($cmds)) {
	$cmds = array($cmds);
}
if (!in_array('/root/doh-proxy/start.sh', $cmds, true)) {
	$cmds[] = '/root/doh-proxy/start.sh';
	config_set_path('system/shellcmd', $cmds);
	$changed = true;
	echo "autostart: shellcmd added\n";
} else {
	echo "autostart: already present\n";
}

if ($changed) {
	write_config('doh-proxy installer: menu entry and autostart');
}
PHPEOF
"$PHP_BIN" /tmp/dohp_register.php
rm -f /tmp/dohp_register.php

# --- optional: point Unbound at the proxy ------------------------------------------
if [ "$SELF_TEST_OK" -eq 1 ] && ask "Point Unbound (DNS Resolver) at the DoH proxy now?"; then
    cat > /tmp/dohp_unbound.php <<'PHPEOF'
<?php
require_once('config.inc');
require_once('services.inc');

/* pfSense stores booleans as empty tags: <enable></enable> means ENABLED,
 * so test for key presence, not truthiness. */
if (!config_path_enabled('unbound')) {
	echo "unbound: DNS Resolver is not enabled - skipped. Wire your resolver manually.\n";
	exit(0);
}

$c = require '/root/doh-proxy/config.php';
$addr = ($c['listen_host'] ?? '127.0.0.1') . '@' . ($c['listen_port'] ?? 5053);

$b64 = config_get_path('unbound/custom_options', '');
$dec = base64_decode($b64, true);
$txt = ($dec !== false && base64_encode($dec) === $b64) ? $dec : $b64;

if (strpos($txt, 'forward-zone') !== false) {
	echo "unbound: custom options already contain a forward-zone - NOT touching them.\n";
	echo "         To use the proxy, merge this into DNS Resolver custom options yourself:\n";
	echo "           server:\n";
	echo "             do-not-query-localhost: no\n";
	echo "           forward-zone:\n";
	echo "             name: \".\"\n";
	echo "             forward-addr: {$addr}\n";
	exit(0);
}

$block = "# BEGIN DOH-PROXY\n"
	. "server:\n"
	. "  do-not-query-localhost: no\n"
	. "forward-zone:\n"
	. "  name: \".\"\n"
	. "  forward-addr: {$addr}\n"
	. "# END DOH-PROXY";

$txt = (trim($txt) === '') ? $block : rtrim($txt) . "\n" . $block;
config_set_path('unbound/custom_options', base64_encode($txt));
write_config('doh-proxy installer: unbound forward-zone');
services_unbound_configure();
echo "unbound: forward-zone added, resolver reloaded\n";
PHPEOF
    "$PHP_BIN" /tmp/dohp_unbound.php
    rm -f /tmp/dohp_unbound.php
fi

# --- start the service ---------------------------------------------------------------
if [ -n "$CURRENT_URL" ]; then
    echo "==> Starting DoH proxy"
    sh "$APP_DIR/stop.sh" 2>/dev/null || true
    sleep 1
    sh "$APP_DIR/start.sh"
    LISTEN=$("$PHP_BIN" -r '$c = require $argv[1]; echo ($c["listen_host"] ?? "127.0.0.1") . ":" . ($c["listen_port"] ?? 5053);' "$APP_DIR/config.php")
    if sockstat -4 -l | grep -q "$LISTEN"; then
        echo "==> Service is listening on $LISTEN"
    else
        echo "WARNING: service does not appear to be listening on $LISTEN - check /var/log/doh-proxy.log" >&2
    fi
else
    echo "==> Service not started (no DoH URL yet)."
fi

echo ""
echo "======================================================================"
echo " Done!"
echo "   GUI  : Services > DoH Proxy in the pfSense webGUI"
echo "   CLI  : $PHP_BIN $APP_DIR/set_url.php <url> [pin-ip]"
echo "   Logs : /var/log/doh-proxy.log"
if [ "$SELF_TEST_OK" -ne 1 ]; then
    echo "   NOTE : DoH URL is not configured/working yet - open the GUI to set it."
fi
echo "======================================================================"
