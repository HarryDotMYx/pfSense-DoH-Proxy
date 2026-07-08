#!/bin/sh
#
# pfSense DoH Proxy - System > General Setup patch
#
# Patches /usr/local/www/system.php so that while the DOH-PROXY forward-zone
# block is active in Unbound custom options, the "DNS Server Settings"
# section shows an "Encrypted DNS - DoH/DoT is currently running" notice
# (linking to Services > DoH Proxy) and its fields are grayed out.
#
# Detection happens at page-render time, so switching DoH/DoT off in the GUI
# re-enables the section by itself - no need to run this script again.
#
# The fields are made readonly + pointer-events:none, NOT "disabled", so the
# currently saved values still submit on Save and nothing gets wiped.
#
# A pfSense upgrade replaces system.php and silently removes this patch.
# Re-apply it afterwards with:  sh /root/doh-proxy/system_patch.sh apply
#
# Usage: sh system_patch.sh apply|revert
#
set -u

TARGET="/usr/local/www/system.php"
APP_DIR="/root/doh-proxy"
PHP_BIN="/usr/local/bin/php"
MARK="DOH-PROXY PATCH"

ANCHOR_PHP="Form_Section('DNS Server Settings')"
ANCHOR_JS="checkLastRow();"

if [ ! -f "$TARGET" ] || [ ! -x "$PHP_BIN" ]; then
    echo "ERROR: $TARGET or $PHP_BIN not found - is this pfSense?" >&2
    exit 1
fi

TMP_D=$(mktemp -d) || exit 1
trap 'rm -rf "$TMP_D"' EXIT INT TERM

lint_ok() {
    "$PHP_BIN" -l "$1" >/dev/null 2>&1
}

revert_patch() {
    if ! grep -q "$MARK" "$TARGET"; then
        echo "system.php: patch not present - nothing to revert"
        return 0
    fi
    sed "/BEGIN ${MARK}/,/END ${MARK}/d" "$TARGET" > "$TMP_D/reverted"
    if ! lint_ok "$TMP_D/reverted"; then
        echo "ERROR: reverted system.php fails php -l - leaving $TARGET untouched." >&2
        return 1
    fi
    cat "$TMP_D/reverted" > "$TARGET"
    echo "system.php: patch removed"
}

apply_patch() {
    if grep -q "$MARK" "$TARGET"; then
        echo "system.php: existing patch found - refreshing"
        revert_patch || return 1
    fi

    if ! grep -qF "$ANCHOR_PHP" "$TARGET" || ! grep -qF "$ANCHOR_JS" "$TARGET"; then
        echo "WARNING: system.php layout not recognized (different pfSense version?)" >&2
        echo "         Skipping this cosmetic patch - everything else keeps working." >&2
        return 0
    fi

    # Keep one pristine copy from before the first patch ever applied.
    if [ -d "$APP_DIR/backup" ] && [ ! -f "$APP_DIR/backup/system.php.orig" ]; then
        cp "$TARGET" "$APP_DIR/backup/system.php.orig"
        echo "system.php: original saved to $APP_DIR/backup/system.php.orig"
    fi

    cat > "$TMP_D/php_block" <<'EOF'
/* BEGIN DOH-PROXY PATCH (managed by /root/doh-proxy; a pfSense upgrade removes it) */
$dohproxy_active = false;
if (config_path_enabled('unbound')) {
	$dohproxy_co = config_get_path('unbound/custom_options', '');
	$dohproxy_dec = base64_decode($dohproxy_co, true);
	if ($dohproxy_dec !== false) {
		$dohproxy_co = $dohproxy_dec;
	}
	if ((strpos($dohproxy_co, '# BEGIN DOH-PROXY') !== false) &&
	    (strpos($dohproxy_co, 'forward-zone:') !== false)) {
		$dohproxy_active = true;
	}
}

if ($dohproxy_active) {
	$section->addInput(new Form_StaticText(
		'Encrypted DNS',
		'<div class="alert alert-info" style="margin-bottom:0;">' .
		'<i class="fa-solid fa-lock"></i> ' .
		'<strong>' . gettext('DoH/DoT is currently running.') . '</strong> ' .
		gettext('The DNS Resolver (Unbound) forwards all queries over an encrypted ' .
			'channel, so the DNS server settings below are not used and have ' .
			'been disabled.') . ' ' .
		'<a href="/doh_proxy_gui.php" class="alert-link">' .
		gettext('Click here to open the DoH/DoT settings') . ' &raquo;</a>' .
		'</div>'
	));
}
/* END DOH-PROXY PATCH */
EOF

    cat > "$TMP_D/js_block" <<'EOF'
	// BEGIN DOH-PROXY PATCH - DNS servers are managed by encrypted forwarding.
<?php if ($dohproxy_active): ?>
	// readonly + pointer-events (not "disabled") so values still submit on Save.
	$('.repeatable').css({'opacity': '0.45', 'pointer-events': 'none'});
	$('.repeatable input').prop('readonly', true);
	$('.addbtn').prop('disabled', true).css('opacity', '0.45');
	$('#dnsallowoverride').closest('.form-group').css({'opacity': '0.45', 'pointer-events': 'none'});
	$('#dnslocalhost').closest('.form-group').css({'opacity': '0.45', 'pointer-events': 'none'});
<?php endif; ?>
	// END DOH-PROXY PATCH
EOF

    awk -v php_block="$TMP_D/php_block" -v js_block="$TMP_D/js_block" \
        -v a_php="$ANCHOR_PHP" -v a_js="$ANCHOR_JS" '
        { print }
        !done_php && index($0, a_php) > 0 {
            while ((getline line < php_block) > 0) print line
            close(php_block)
            done_php = 1
        }
        !done_js && index($0, a_js) > 0 {
            while ((getline line < js_block) > 0) print line
            close(js_block)
            done_js = 1
        }
    ' "$TARGET" > "$TMP_D/patched"

    if ! lint_ok "$TMP_D/patched"; then
        echo "ERROR: patched system.php fails php -l - leaving $TARGET untouched." >&2
        return 1
    fi
    cat "$TMP_D/patched" > "$TARGET"
    chmod 644 "$TARGET"
    echo "system.php: patch applied (Encrypted DNS notice on System > General Setup)"
}

case "${1:-}" in
    apply)  apply_patch ;;
    revert) revert_patch ;;
    *) echo "Usage: sh system_patch.sh apply|revert" >&2; exit 1 ;;
esac
