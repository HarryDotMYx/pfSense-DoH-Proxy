#!/bin/sh
#
# pfSense DoH Proxy - webGUI patches (System > General Setup + Dashboard)
#
# Patches two stock pfSense pages so the GUI reflects encrypted DNS while the
# DOH-PROXY forward-zone block is active in Unbound custom options:
#
#  1. /usr/local/www/system.php - the "DNS Server Settings" section shows an
#     "Encrypted DNS - DoH/DoT is currently running" notice (linking to
#     Services > DoH Proxy) and its fields are grayed out. Fields are made
#     readonly + pointer-events:none, NOT "disabled", so the currently saved
#     values still submit on Save and nothing gets wiped.
#
#  2. system_information.widget.php - the Dashboard "DNS server(s)" row shows
#     which upstream is in use, e.g. "DoT: cloudflare-dns.com:853" or
#     "DoH: https://host/dns-query", linking to Services > DoH Proxy.
#
# Detection happens at page-render time, so switching DoH/DoT off in the GUI
# reverts the pages to stock behavior by itself - no need to run this again.
#
# A pfSense upgrade replaces these files and silently removes the patches.
# Re-apply afterwards with:  sh /root/doh-proxy/system_patch.sh apply
#
# Usage: sh system_patch.sh apply|revert
#
set -u

TARGET_SYS="/usr/local/www/system.php"
TARGET_WID="/usr/local/www/widgets/widgets/system_information.widget.php"
APP_DIR="/root/doh-proxy"
PHP_BIN="/usr/local/bin/php"
MARK="DOH-PROXY PATCH"

ANCHOR_SYS_PHP="Form_Section('DNS Server Settings')"
ANCHOR_SYS_JS="checkLastRow();"
# pfSense 2.8 uses get_dns_nameservers(), 2.7 used get_dns_servers()
ANCHOR_WID_1='$dns_servers = get_dns_nameservers'
ANCHOR_WID_2='$dns_servers = get_dns_servers'

if [ ! -f "$TARGET_SYS" ] || [ ! -x "$PHP_BIN" ]; then
    echo "ERROR: $TARGET_SYS or $PHP_BIN not found - is this pfSense?" >&2
    exit 1
fi

TMP_D=$(mktemp -d) || exit 1
trap 'rm -rf "$TMP_D"' EXIT INT TERM

lint_ok() {
    "$PHP_BIN" -l "$1" >/dev/null 2>&1
}

# save a pristine copy of $1 as backup/$2, once
keep_orig() {
    if [ -d "$APP_DIR/backup" ] && [ ! -f "$APP_DIR/backup/$2" ]; then
        cp "$1" "$APP_DIR/backup/$2"
        echo "$(basename "$1"): original saved to $APP_DIR/backup/$2"
    fi
}

# print $1 with the contents of file $2 inserted after the first line
# containing literal $3 (or literal $4, if given)
insert_block() {
    awk -v blk="$2" -v a1="$3" -v a2="${4:-}" '
        { print }
        !done && (index($0, a1) > 0 || (a2 != "" && index($0, a2) > 0)) {
            while ((getline line < blk) > 0) print line
            close(blk)
            done = 1
        }
    ' "$1"
}

# strip the marker-delimited blocks from $1 (php -l gated)
revert_file() {
    if [ ! -f "$1" ] || ! grep -q "$MARK" "$1"; then
        echo "$(basename "$1"): patch not present - nothing to revert"
        return 0
    fi
    sed "/BEGIN ${MARK}/,/END ${MARK}/d" "$1" > "$TMP_D/reverted"
    if ! lint_ok "$TMP_D/reverted"; then
        echo "ERROR: reverted $(basename "$1") fails php -l - leaving it untouched." >&2
        return 1
    fi
    cat "$TMP_D/reverted" > "$1"
    echo "$(basename "$1"): patch removed"
}

# lint-gate $TMP_D/patched and install it over $1
install_patched() {
    if ! lint_ok "$TMP_D/patched"; then
        echo "ERROR: patched $(basename "$1") fails php -l - leaving it untouched." >&2
        return 1
    fi
    cat "$TMP_D/patched" > "$1"
    chmod 644 "$1"
}

apply_system() {
    if grep -q "$MARK" "$TARGET_SYS"; then
        echo "system.php: existing patch found - refreshing"
        revert_file "$TARGET_SYS" || return 1
    fi

    if ! grep -qF "$ANCHOR_SYS_PHP" "$TARGET_SYS" || ! grep -qF "$ANCHOR_SYS_JS" "$TARGET_SYS"; then
        echo "WARNING: system.php layout not recognized (different pfSense version?)" >&2
        echo "         Skipping this cosmetic patch - everything else keeps working." >&2
        return 0
    fi

    keep_orig "$TARGET_SYS" "system.php.orig"

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

    insert_block "$TARGET_SYS" "$TMP_D/php_block" "$ANCHOR_SYS_PHP" > "$TMP_D/step1"
    insert_block "$TMP_D/step1" "$TMP_D/js_block" "$ANCHOR_SYS_JS" > "$TMP_D/patched"
    install_patched "$TARGET_SYS" || return 1
    echo "system.php: patch applied (Encrypted DNS notice on System > General Setup)"
}

apply_widget() {
    if [ ! -f "$TARGET_WID" ]; then
        echo "WARNING: $TARGET_WID not found - skipping the Dashboard widget patch." >&2
        return 0
    fi
    if grep -q "$MARK" "$TARGET_WID"; then
        echo "widget: existing patch found - refreshing"
        revert_file "$TARGET_WID" || return 1
    fi

    if ! grep -qF "$ANCHOR_WID_1" "$TARGET_WID" && ! grep -qF "$ANCHOR_WID_2" "$TARGET_WID"; then
        echo "WARNING: system_information widget layout not recognized (different pfSense version?)" >&2
        echo "         Skipping this cosmetic patch - everything else keeps working." >&2
        return 0
    fi

    keep_orig "$TARGET_WID" "system_information.widget.php.orig"

    cat > "$TMP_D/wid_block" <<'EOF'
					/* BEGIN DOH-PROXY PATCH (managed by /root/doh-proxy; a pfSense upgrade removes it) */
					$dohw_label = '';
					if (config_path_enabled('unbound')) {
						$dohw_co = config_get_path('unbound/custom_options', '');
						$dohw_dec = base64_decode($dohw_co, true);
						if ($dohw_dec !== false) {
							$dohw_co = $dohw_dec;
						}
						if ((strpos($dohw_co, '# BEGIN DOH-PROXY') !== false) &&
						    (strpos($dohw_co, 'forward-zone:') !== false)) {
							$dohw_c = @include '/root/doh-proxy/config.php';
							if (is_array($dohw_c) && (($dohw_c['mode'] ?? 'doh') === 'dot') && !empty($dohw_c['dot_host'])) {
								$dohw_label = 'DoT: ' . $dohw_c['dot_host'] . ':' . ($dohw_c['dot_port'] ?? 853);
							} elseif (is_array($dohw_c) && !empty($dohw_c['doh_url'])) {
								$dohw_label = 'DoH: ' . $dohw_c['doh_url'];
							} else {
								$dohw_label = gettext('DoH/DoT forwarding active');
							}
						}
					}
					if ($dohw_label !== '') {
						echo '<li><i class="fa-solid fa-lock text-success"></i> ' .
						    '<a href="/doh_proxy_gui.php" title="' .
						    gettext('Encrypted DNS is active - open the DoH/DoT settings') . '">' .
						    htmlspecialchars($dohw_label) . '</a></li>';
					}
					/* END DOH-PROXY PATCH */
EOF

    insert_block "$TARGET_WID" "$TMP_D/wid_block" "$ANCHOR_WID_1" "$ANCHOR_WID_2" > "$TMP_D/patched"
    install_patched "$TARGET_WID" || return 1
    echo "widget: patch applied (encrypted upstream shown under Dashboard DNS server(s))"
}

case "${1:-}" in
    apply)
        rc=0
        apply_system || rc=1
        apply_widget || rc=1
        exit "$rc"
        ;;
    revert)
        rc=0
        revert_file "$TARGET_SYS" || rc=1
        revert_file "$TARGET_WID" || rc=1
        exit "$rc"
        ;;
    *)
        echo "Usage: sh system_patch.sh apply|revert" >&2
        exit 1
        ;;
esac
