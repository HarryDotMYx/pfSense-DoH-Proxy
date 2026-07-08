#!/bin/sh
#
# pfSense DoH Proxy - uninstaller
# Run this ON the pfSense box as root:  sh uninstall.sh [-y]
#
set -u

APP_DIR="/root/doh-proxy"
WWW_PAGE="/usr/local/www/doh_proxy_gui.php"
PHP_BIN="/usr/local/bin/php"

AUTO_YES=0
[ "${1:-}" = "-y" ] && AUTO_YES=1

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

if [ "$(id -u)" -ne 0 ]; then
    echo "ERROR: run this as root." >&2
    exit 1
fi

echo "==> Stopping service"
[ -f "$APP_DIR/stop.sh" ] && sh "$APP_DIR/stop.sh" 2>/dev/null || true

echo "==> Removing menu entry, autostart and unbound wiring"
cat > /tmp/dohp_unregister.php <<'PHPEOF'
<?php
require_once('config.inc');
require_once('services.inc');
$changed = false;

$menus = config_get_path('installedpackages/menu', array());
$new = array();
$removed = false;
foreach ($menus as $m) {
	if (is_array($m) && ($m['url'] ?? '') === '/doh_proxy_gui.php') {
		$removed = true;
		continue;
	}
	$new[] = $m;
}
if ($removed) {
	config_set_path('installedpackages/menu', $new);
	$changed = true;
	echo "menu: removed\n";
}

$cmds = config_get_path('system/shellcmd', array());
if (!is_array($cmds)) {
	$cmds = array($cmds);
}
$new = array();
$removed = false;
foreach ($cmds as $c) {
	if ($c === '/root/doh-proxy/start.sh') {
		$removed = true;
		continue;
	}
	$new[] = $c;
}
if ($removed) {
	config_set_path('system/shellcmd', $new);
	$changed = true;
	echo "autostart: removed\n";
}

$b64 = config_get_path('unbound/custom_options', '');
$dec = base64_decode($b64, true);
$txt = ($dec !== false && base64_encode($dec) === $b64) ? $dec : $b64;
$unbound_changed = false;
if (strpos($txt, '# BEGIN DOH-PROXY') !== false) {
	$txt = preg_replace('/\n?# BEGIN DOH-PROXY.*?# END DOH-PROXY\n?/s', "\n", $txt);
	$txt = trim($txt);
	config_set_path('unbound/custom_options', $txt === '' ? '' : base64_encode($txt));
	$changed = true;
	$unbound_changed = true;
	echo "unbound: DOH-PROXY block removed\n";
}

if ($changed) {
	write_config('doh-proxy uninstaller');
}
if ($unbound_changed) {
	services_unbound_configure();
	echo "unbound: resolver reloaded\n";
}
PHPEOF
"$PHP_BIN" /tmp/dohp_unregister.php
rm -f /tmp/dohp_unregister.php

echo "==> Reverting System > General Setup patch"
if [ -f "$APP_DIR/system_patch.sh" ]; then
    sh "$APP_DIR/system_patch.sh" revert || true
elif grep -q "DOH-PROXY PATCH" /usr/local/www/system.php 2>/dev/null; then
    # fallback: strip the marker-delimited blocks in place (php -l gated)
    tmp=$(mktemp)
    sed '/BEGIN DOH-PROXY PATCH/,/END DOH-PROXY PATCH/d' /usr/local/www/system.php > "$tmp"
    if "$PHP_BIN" -l "$tmp" >/dev/null 2>&1; then
        cat "$tmp" > /usr/local/www/system.php
        echo "system.php: patch removed"
    else
        echo "WARNING: reverted system.php fails php -l - leaving it untouched." >&2
    fi
    rm -f "$tmp"
fi

echo "==> Removing GUI page"
rm -f "$WWW_PAGE"

if ask "Remove $APP_DIR (config + backups) too?"; then
    rm -rf "$APP_DIR"
    echo "==> $APP_DIR removed"
else
    echo "==> Kept $APP_DIR"
fi

echo "Done."
