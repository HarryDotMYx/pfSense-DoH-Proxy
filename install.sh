#!/bin/sh
#
# pfSense DoH Proxy - installer (DoH + DoT)
# Run this ON the pfSense box as root:
#   sh install.sh [-y] [--url=https://host/dns-query | --url=tls://host]
#
set -u

APP_DIR="/root/doh-proxy"
WWW_PAGE="/usr/local/www/doh_proxy_gui.php"
PHP_BIN="/usr/local/bin/php"
SRC_DIR="$(cd "$(dirname "$0")" && pwd)/src"

AUTO_YES=0
UPSTREAM=""

usage() {
    echo "Usage: sh install.sh [-y] [--url=UPSTREAM]"
    echo "  -y              answer yes to all prompts"
    echo "  --url=UPSTREAM  https://host/dns-query (DoH mode)"
    echo "                  tls://host[:port]      (DoT mode, native Unbound)"
}

for arg in "$@"; do
    case "$arg" in
        -y|--yes) AUTO_YES=1 ;;
        --url=*)  UPSTREAM="${arg#--url=}" ;;
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
cp "$SRC_DIR/dot_check.php" "$APP_DIR/dot_check.php"
cp "$SRC_DIR/dot_test.php"  "$APP_DIR/dot_test.php"
cp "$SRC_DIR/start.sh"      "$APP_DIR/start.sh"
cp "$SRC_DIR/stop.sh"       "$APP_DIR/stop.sh"
cp "$SRC_DIR/system_patch.sh" "$APP_DIR/system_patch.sh"
chmod 755 "$APP_DIR/start.sh" "$APP_DIR/stop.sh" "$APP_DIR/system_patch.sh"

if [ ! -f "$APP_DIR/config.php" ]; then
    cp "$SRC_DIR/config.sample.php" "$APP_DIR/config.php"
    echo "==> Created new config: $APP_DIR/config.php"
else
    echo "==> Keeping existing config: $APP_DIR/config.php"
fi

# --- upstream -------------------------------------------------------------------
current_upstream() {
    "$PHP_BIN" -r '$c = require $argv[1]; $m = $c["mode"] ?? "doh"; echo $m === "dot" ? (!empty($c["dot_host"]) ? "tls://" . $c["dot_host"] : "") : (string)($c["doh_url"] ?? "");' "$APP_DIR/config.php"
}

CURRENT=$(current_upstream)

if [ -z "$CURRENT" ] && [ -z "$UPSTREAM" ] && [ "$AUTO_YES" -eq 0 ]; then
    printf 'Enter your encrypted-DNS upstream:\n'
    printf '  DoH:  https://dns.example.com/dns-query\n'
    printf '  DoT:  tls://dns.example.com\n'
    printf 'or press Enter to configure it later in the GUI: '
    read -r UPSTREAM
fi

if [ -n "$UPSTREAM" ]; then
    "$PHP_BIN" "$APP_DIR/set_url.php" "$UPSTREAM" || {
        echo "ERROR: could not set upstream." >&2
        exit 1
    }
    CURRENT=$(current_upstream)
fi

MODE=$("$PHP_BIN" -r '$c = require $argv[1]; echo ($c["mode"] ?? "doh") === "dot" ? "dot" : "doh";' "$APP_DIR/config.php")
echo "==> Mode: $MODE"

# --- self-test ------------------------------------------------------------------
SELF_TEST_OK=0
if [ -n "$CURRENT" ]; then
    echo "==> Running self-test against $CURRENT"
    if [ "$MODE" = "dot" ]; then
        TEST_CMD="$APP_DIR/dot_test.php"
    else
        TEST_CMD="$APP_DIR/doh_proxy.php --self-test"
    fi
    if "$PHP_BIN" $TEST_CMD; then
        SELF_TEST_OK=1
        echo "==> Self-test passed"
    else
        echo "WARNING: self-test failed - check the upstream. Unbound will NOT be touched." >&2
    fi
else
    echo "==> No upstream configured yet - set it later via Services > DoH Proxy."
fi

# --- webGUI page ----------------------------------------------------------------
echo "==> Installing webGUI page"
cp "$SRC_DIR/www/doh_proxy_gui.php" "$WWW_PAGE"
chmod 644 "$WWW_PAGE"

# --- General Setup notice + Dashboard widget ---------------------------------------
# While the DOH-PROXY forward-zone is active, System > General Setup shows an
# "Encrypted DNS" notice (unused DNS Server Settings fields grayed out) and the
# Dashboard "DNS server(s)" row shows the DoH/DoT upstream in use.
echo "==> Patching System > General Setup and Dashboard widget (Encrypted DNS)"
sh "$APP_DIR/system_patch.sh" apply || \
    echo "WARNING: could not patch the stock pages - cosmetic only, everything else works." >&2

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

# --- optional: point Unbound at the upstream ---------------------------------------
if [ "$SELF_TEST_OK" -eq 1 ] && ask "Point Unbound (DNS Resolver) at this upstream now?"; then
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
$mode = ($c['mode'] ?? 'doh') === 'dot' ? 'dot' : 'doh';

if ($mode === 'dot') {
	$port = (int) ($c['dot_port'] ?? 853);
	$host = (string) $c['dot_host'];
	$lines = array('# BEGIN DOH-PROXY', 'forward-zone:', '  name: "."', '  forward-tls-upstream: yes');
	foreach ((array) ($c['dot_ips'] ?? array()) as $ip) {
		$lines[] = "  forward-addr: {$ip}@{$port}#{$host}";
	}
	$lines[] = '# END DOH-PROXY';
} else {
	$addr = ($c['listen_host'] ?? '127.0.0.1') . '@' . ($c['listen_port'] ?? 5053);
	$lines = array('# BEGIN DOH-PROXY', 'server:', '  do-not-query-localhost: no',
	    'forward-zone:', '  name: "."', "  forward-addr: {$addr}", '# END DOH-PROXY');
}
$block = implode("\n", $lines);

$b64 = config_get_path('unbound/custom_options', '');
$dec = base64_decode($b64, true);
$txt = ($dec !== false && base64_encode($dec) === $b64) ? $dec : $b64;

if (strpos($txt, '# BEGIN DOH-PROXY') !== false) {
	$txt = preg_replace('/# BEGIN DOH-PROXY.*?# END DOH-PROXY/s', $block, $txt, 1);
} elseif (strpos($txt, 'forward-zone') !== false) {
	echo "unbound: custom options already contain a forward-zone - NOT touching them.\n";
	echo "         To use this upstream, merge the following into DNS Resolver custom options yourself:\n";
	echo preg_replace('/^/m', '           ', $block) . "\n";
	exit(0);
} else {
	$txt = (trim($txt) === '') ? $block : rtrim($txt) . "\n" . $block;
}

config_set_path('unbound/custom_options', base64_encode($txt));
write_config("doh-proxy installer: unbound forward-zone ({$mode} mode)");
services_unbound_configure();
echo "unbound: forward-zone applied ({$mode} mode), resolver reloaded\n";
PHPEOF
    "$PHP_BIN" /tmp/dohp_unbound.php
    rm -f /tmp/dohp_unbound.php
fi

# --- start/stop the daemon ------------------------------------------------------------
if [ "$MODE" = "dot" ]; then
    echo "==> DoT mode: Unbound talks TLS directly, stopping the daemon (not needed)"
    sh "$APP_DIR/stop.sh" 2>/dev/null || true
elif [ -n "$CURRENT" ]; then
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
    echo "==> Service not started (no upstream yet)."
fi

echo ""
echo "======================================================================"
echo " Done!"
echo "   GUI  : Services > DoH Proxy in the pfSense webGUI"
echo "   CLI  : $PHP_BIN $APP_DIR/set_url.php <https://...|tls://...> [ips]"
echo "   Logs : /var/log/doh-proxy.log"
if [ "$SELF_TEST_OK" -ne 1 ]; then
    echo "   NOTE : upstream is not configured/working yet - open the GUI to set it."
fi
echo "======================================================================"
