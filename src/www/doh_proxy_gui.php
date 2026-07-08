<?php
/*
 * doh_proxy_gui.php (v1.3.5)
 *
 * Web GUI for encrypted DNS upstreams on pfSense:
 *   - DoH mode: Unbound -> local proxy (/root/doh-proxy) -> https://.../dns-query
 *   - DoT mode: Unbound native forward-tls-upstream, no daemon needed
 *
 * Manages a marker-delimited block in Unbound custom options and refuses
 * to touch any forward-zone it does not own.
 */

require_once("guiconfig.inc");
require_once("services.inc");

define('DOHP_DIR', '/root/doh-proxy');
define('DOHP_CONF', DOHP_DIR . '/config.php');
define('DOHP_PIDFILE', '/var/run/doh-proxy.pid');
// example.com A query, RFC 8484 sample (base64url)
define('DOHP_TEST_QUERY', 'q80BAAABAAAAAAAAB2V4YW1wbGUDY29tAAABAAE');

if (file_exists(DOHP_DIR . '/dot_check.php')) {
	require_once(DOHP_DIR . '/dot_check.php');
}

function dohp_read_config() {
	$defaults = array(
		'mode' => 'doh',
		'doh_url' => '',
		'doh_resolve' => '',
		'dot_host' => '',
		'dot_ips' => array(),
		'dot_port' => 853,
		'listen_host' => '127.0.0.1',
		'listen_port' => 5053,
		'timeout_seconds' => 10,
		'self_test_domain' => 'example.com',
		'debug' => false,
		'log_file' => '/var/log/doh-proxy.log',
	);
	if (file_exists(DOHP_CONF)) {
		$c = include(DOHP_CONF);
		if (is_array($c)) {
			return array_merge($defaults, $c);
		}
	}
	return $defaults;
}

function dohp_write_config($new) {
	$ts = date('Ymd-His');
	safe_mkdir(DOHP_DIR . "/backup/{$ts}");
	@copy(DOHP_CONF, DOHP_DIR . "/backup/{$ts}/config.php");
	file_put_contents(DOHP_CONF, "<?php\nreturn " . var_export($new, true) . ";\n");
}

function dohp_running() {
	if (file_exists(DOHP_PIDFILE) && isvalidpid(DOHP_PIDFILE)) {
		$pid = (int) trim(file_get_contents(DOHP_PIDFILE));
		/* make sure the PID was not reused by an unrelated process */
		$cmd = (string) shell_exec('/bin/ps -p ' . escapeshellarg((string) $pid) . ' -o command= 2>/dev/null');
		if (strpos($cmd, 'doh_proxy.php') !== false) {
			return $pid;
		}
	}
	return false;
}

function dohp_url_host($url) {
	$host = parse_url($url, PHP_URL_HOST);
	return is_string($host) ? $host : '';
}

function dohp_test_doh($url, $pin_ip) {
	$host = dohp_url_host($url);
	$sep = (strpos($url, '?') === false) ? '?' : '&';
	$cmd = '/usr/local/bin/curl -s -o /dev/null -w %{http_code} --max-time 8 ' .
	    '--resolve ' . escapeshellarg($host . ':443:' . $pin_ip) . ' ' .
	    escapeshellarg($url . $sep . 'dns=' . DOHP_TEST_QUERY);
	return trim((string) shell_exec($cmd . ' 2>/dev/null'));
}

function dohp_proxy_start() {
	mwexec(DOHP_DIR . '/stop.sh');
	sleep(1);
	mwexec(DOHP_DIR . '/start.sh');
}

function dohp_proxy_stop() {
	mwexec(DOHP_DIR . '/stop.sh');
}

/* ---- Unbound custom-options management (marker-delimited block) ---------- */

function dohp_unbound_read() {
	$b64 = config_get_path('unbound/custom_options', '');
	$dec = base64_decode($b64, true);
	return ($dec !== false && base64_encode($dec) === $b64) ? $dec : $b64;
}

function dohp_unbound_block($mode, $conf) {
	if ($mode === 'dot') {
		$port = (int) ($conf['dot_port'] ?? 853);
		$host = $conf['dot_host'];
		$lines = array(
			'# BEGIN DOH-PROXY',
			'forward-zone:',
			'  name: "."',
			'  forward-tls-upstream: yes',
		);
		foreach ((array) $conf['dot_ips'] as $ip) {
			$lines[] = "  forward-addr: {$ip}@{$port}#{$host}";
		}
		$lines[] = '# END DOH-PROXY';
	} else {
		$addr = ($conf['listen_host'] ?? '127.0.0.1') . '@' . ($conf['listen_port'] ?? 5053);
		$lines = array(
			'# BEGIN DOH-PROXY',
			'server:',
			'  do-not-query-localhost: no',
			'forward-zone:',
			'  name: "."',
			"  forward-addr: {$addr}",
			'# END DOH-PROXY',
		);
	}
	return implode("\n", $lines);
}

/* Returns true when applied; false when refused (foreign forward-zone). */
function dohp_unbound_apply($mode, $conf, &$msg) {
	$txt = dohp_unbound_read();
	$block = dohp_unbound_block($mode, $conf);

	if (strpos($txt, '# BEGIN DOH-PROXY') !== false) {
		/* callback form so "$1"/"\1" in config values are never treated
		 * as replacement backreferences */
		$new = preg_replace_callback('/# BEGIN DOH-PROXY.*?# END DOH-PROXY/s',
		    function ($m) use ($block) { return $block; }, $txt, 1);
	} elseif (strpos($txt, 'forward-zone') !== false) {
		$msg = gettext('Unbound custom options already contain a forward-zone that is not managed by this page - Unbound was left untouched. Remove your manual block if you want this page to manage it.');
		return false;
	} else {
		$new = (trim($txt) === '') ? $block : rtrim($txt) . "\n" . $block;
	}

	config_set_path('unbound/custom_options', base64_encode($new));
	write_config("DoH Proxy GUI: set Unbound upstream ({$mode} mode)");
	services_unbound_configure();
	$msg = sprintf(gettext('Unbound updated and reloaded (%s mode).'), strtoupper($mode));
	return true;
}

function dohp_unbound_managed() {
	return strpos(dohp_unbound_read(), '# BEGIN DOH-PROXY') !== false;
}

/* ---- page logic ----------------------------------------------------------- */

$conf = dohp_read_config();

$pconfig = array(
	'mode' => in_array($conf['mode'], array('doh', 'dot'), true) ? $conf['mode'] : 'doh',
	'doh_url' => $conf['doh_url'],
	'pin_ip' => '',
	'timeout' => $conf['timeout_seconds'],
	'debug' => !empty($conf['debug']),
	'dot_host' => $conf['dot_host'],
	'dot_ips' => implode(', ', (array) $conf['dot_ips']),
	'dot_port' => $conf['dot_port'],
);
if (!empty($conf['doh_resolve'])) {
	/* format is host:443:ip - limit 3 so an IPv6 pin (which itself
	 * contains colons) survives intact in the third element */
	$parts = explode(':', $conf['doh_resolve'], 3);
	$pconfig['pin_ip'] = $parts[2] ?? '';
}

$input_errors = array();
$savemsg = '';
$testmsg = '';
$unbound_notice = '';

if ($_POST) {
	if (isset($_POST['restart'])) {
		if ($conf['mode'] === 'dot') {
			services_unbound_configure();
			$savemsg = gettext("Unbound restarted.");
		} else {
			dohp_proxy_start();
			if (dohp_running()) {
				$savemsg = gettext("Service restarted.");
			} else {
				$input_errors[] = gettext('Service did not start - is a DoH URL configured? Check /var/log/doh-proxy.log.');
			}
		}
	} elseif (isset($_POST['save']) || isset($_POST['testbtn'])) {
		$mode = ($_POST['mode'] ?? 'doh') === 'dot' ? 'dot' : 'doh';
		$pconfig['mode'] = $mode;
		$pconfig['doh_url'] = trim($_POST['doh_url'] ?? '');
		$pconfig['pin_ip'] = trim($_POST['pin_ip'] ?? '');
		$pconfig['timeout'] = trim($_POST['timeout'] ?? '10');
		$pconfig['debug'] = isset($_POST['debug']);
		$pconfig['dot_host'] = trim($_POST['dot_host'] ?? '');
		$pconfig['dot_ips'] = trim($_POST['dot_ips'] ?? '');
		$pconfig['dot_port'] = trim($_POST['dot_port'] ?? '853');
		$skiptest = isset($_POST['skiptest']);

		$dot_ips = array();

		if ($mode === 'doh') {
			$host = dohp_url_host($pconfig['doh_url']);
			if (empty($pconfig['doh_url']) ||
			    parse_url($pconfig['doh_url'], PHP_URL_SCHEME) !== 'https' ||
			    empty($host) ||
			    preg_match('/[\s<>"\'\\\\`]/', $pconfig['doh_url'])) {
				$input_errors[] = gettext("DoH URL must be a valid https:// URL, e.g. https://dns.example.com/dns-query");
			}
			if (!empty($pconfig['pin_ip']) && !is_ipaddr($pconfig['pin_ip'])) {
				$input_errors[] = gettext("Pinned IP must be a valid IP address.");
			}
			if (!is_numericint($pconfig['timeout']) ||
			    $pconfig['timeout'] < 1 || $pconfig['timeout'] > 60) {
				$input_errors[] = gettext("Timeout must be between 1 and 60 seconds.");
			}
			if (!$input_errors && empty($pconfig['pin_ip'])) {
				$ip = gethostbyname($host);
				if ($ip == $host || !is_ipaddr($ip)) {
					$input_errors[] = sprintf(gettext('Could not resolve "%s" - enter the pinned IP manually.'), $host);
				} else {
					$pconfig['pin_ip'] = $ip;
				}
			}
			if (!$input_errors && !$skiptest) {
				$code = dohp_test_doh($pconfig['doh_url'], $pconfig['pin_ip']);
				if ($code === '200') {
					$testmsg = sprintf(gettext('DoH upstream test OK (HTTP %1$s from %2$s via %3$s).'),
					    htmlspecialchars($code), htmlspecialchars($host), $pconfig['pin_ip']);
				} else {
					$msg = sprintf(gettext('DoH upstream test FAILED (HTTP "%1$s" from %2$s via %3$s).'),
					    htmlspecialchars($code), htmlspecialchars($host), $pconfig['pin_ip']);
					if (isset($_POST['save'])) {
						$msg .= ' ' . gettext('Nothing was saved. Check "Skip upstream test" to save anyway.');
					}
					$input_errors[] = $msg;
				}
			}
		} else { /* dot */
			if (empty($pconfig['dot_host']) || !is_hostname($pconfig['dot_host'])) {
				$input_errors[] = gettext("DoT hostname must be a valid hostname, e.g. dns.example.com");
			}
			if (!is_port($pconfig['dot_port'])) {
				$input_errors[] = gettext("DoT port must be a valid port (default 853).");
			}
			if ($pconfig['dot_ips'] !== '') {
				foreach (explode(',', $pconfig['dot_ips']) as $ip) {
					$ip = trim($ip);
					if ($ip === '') {
						continue;
					}
					if (!is_ipaddr($ip)) {
						$input_errors[] = sprintf(gettext('"%s" is not a valid IP address.'), htmlspecialchars($ip));
					} else {
						$dot_ips[] = $ip;
					}
				}
			}
			if (!$input_errors && empty($dot_ips)) {
				foreach ((array) @dns_get_record($pconfig['dot_host'], DNS_A) as $r) {
					if (!empty($r['ip'])) {
						$dot_ips[] = $r['ip'];
					}
				}
				foreach ((array) @dns_get_record($pconfig['dot_host'], DNS_AAAA) as $r) {
					if (!empty($r['ipv6'])) {
						$dot_ips[] = $r['ipv6'];
					}
				}
				if (empty($dot_ips)) {
					$input_errors[] = sprintf(gettext('Could not resolve "%s" - enter the IP(s) manually.'), $pconfig['dot_host']);
				} else {
					$pconfig['dot_ips'] = implode(', ', $dot_ips);
				}
			}
			if (!$input_errors && !$skiptest) {
				if (!function_exists('dohp_dot_probe')) {
					$input_errors[] = gettext('dot_check.php is missing from /root/doh-proxy - re-run the installer.');
				} else {
					$pass = array();
					$fail = array();
					foreach ($dot_ips as $ip) {
						$detail = '';
						if (dohp_dot_probe($pconfig['dot_host'], $ip, (int) $pconfig['dot_port'], $detail)) {
							$pass[] = $detail;
						} else {
							$fail[] = $detail;
						}
					}
					if (empty($pass)) {
						$msg = gettext('DoT upstream test FAILED:') . ' ' . implode('; ', $fail);
						if (isset($_POST['save'])) {
							$msg .= ' ' . gettext('Nothing was saved. Check "Skip upstream test" to save anyway.');
						}
						$input_errors[] = $msg;
					} else {
						$testmsg = gettext('DoT upstream test OK:') . ' ' . implode('; ', $pass);
						if (!empty($fail)) {
							$testmsg .= ' | ' . gettext('Failed:') . ' ' . implode('; ', $fail);
						}
					}
				}
			}
		}

		if (!$input_errors && isset($_POST['save'])) {
			$new = $conf;
			$new['mode'] = $mode;
			if ($mode === 'doh') {
				$host = dohp_url_host($pconfig['doh_url']);
				$new['doh_url'] = $pconfig['doh_url'];
				$new['doh_resolve'] = $host . ':443:' . $pconfig['pin_ip'];
				$new['timeout_seconds'] = (int) $pconfig['timeout'];
				$new['debug'] = (bool) $pconfig['debug'];
			} else {
				$new['dot_host'] = $pconfig['dot_host'];
				$new['dot_ips'] = $dot_ips;
				$new['dot_port'] = (int) $pconfig['dot_port'];
			}
			dohp_write_config($new);
			$conf = dohp_read_config();

			$unb = '';
			dohp_unbound_apply($mode, $conf, $unb);
			$unbound_notice = $unb;

			if ($mode === 'doh') {
				dohp_proxy_start();
			} else {
				dohp_proxy_stop();
			}

			$savemsg = gettext("Configuration saved.");
			if ($testmsg) {
				$savemsg .= ' ' . $testmsg;
				$testmsg = '';
			}
		}
	}
}

$pgtitle = array(gettext("Services"), gettext("DoH Proxy"));
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}
if ($savemsg) {
	print_info_box($savemsg, 'success');
} elseif ($testmsg) {
	print_info_box($testmsg, 'success');
}
if ($unbound_notice) {
	print_info_box($unbound_notice, dohp_unbound_managed() ? 'success' : 'warning', false);
}

/* status box */
if ($conf['mode'] === 'dot') {
	$managed = dohp_unbound_managed();
	$running = function_exists('is_service_running') ? is_service_running('unbound') : true;
	$cls = ($managed && $running) ? 'success' : 'warning';
	print_info_box(sprintf(gettext('Mode: DoT (native Unbound) — upstream: %1$s:%2$d [%3$s] — Unbound: %4$s, custom options %5$s'),
	    htmlspecialchars($conf['dot_host']), (int) $conf['dot_port'],
	    htmlspecialchars(implode(', ', (array) $conf['dot_ips'])),
	    $running ? gettext('running') : gettext('NOT running'),
	    $managed ? gettext('managed by this page') : gettext('NOT managed (manual forward-zone present)')), $cls, false);
	$pid = dohp_running();
	if ($pid) {
		print_info_box(sprintf(gettext('Note: the DoH proxy daemon is still running (PID %d) but is not needed in DoT mode. It will stop on the next Save.'), $pid), 'info', false);
	}
} else {
	$pid = dohp_running();
	if ($pid) {
		print_info_box(sprintf(gettext('Mode: DoH — proxy running (PID %1$d) on %2$s:%3$d — upstream: %4$s'),
		    $pid, htmlspecialchars($conf['listen_host']), (int) $conf['listen_port'],
		    htmlspecialchars($conf['doh_url'])), 'success', false);
	} else {
		print_info_box(gettext('Mode: DoH — proxy is NOT running.'), 'danger', false);
	}
}

$form = new Form(false);

$section = new Form_Section('Upstream Mode');
$section->addInput(new Form_Select(
	'mode',
	'*Mode',
	$pconfig['mode'],
	array(
		'doh' => gettext('DNS over HTTPS (DoH) — via local proxy daemon'),
		'dot' => gettext('DNS over TLS (DoT) — native Unbound, no daemon'),
	)
))->setHelp('Both modes verify the server certificate and are tested with a real DNS query before saving. ' .
	'Note: the DoH proxy handles queries sequentially and can saturate on a busy LAN — ' .
	'prefer DoT unless only HTTPS egress is allowed.');
$form->add($section);

$section = new Form_Section('DoH Settings');
$section->addClass('dohp-doh');
$section->addInput(new Form_Input(
	'doh_url',
	'DoH URL',
	'text',
	$pconfig['doh_url']
))->setHelp('Full DoH endpoint URL, e.g. %s', '<code>https://dns.example.com/dns-query</code>');
$section->addInput(new Form_Input(
	'pin_ip',
	'Pinned IP',
	'text',
	$pconfig['pin_ip']
))->setHelp('IP used to reach the DoH server without depending on DNS (bootstrap). Leave empty to auto-resolve on save.');
$section->addInput(new Form_Input(
	'timeout',
	'Timeout',
	'number',
	$pconfig['timeout'],
	['min' => 1, 'max' => 60]
))->setHelp('Upstream query timeout in seconds.');
$section->addInput(new Form_Checkbox(
	'debug',
	'Debug',
	'Enable debug logging',
	$pconfig['debug']
));
$form->add($section);

$section = new Form_Section('DoT Settings');
$section->addClass('dohp-dot');
$section->addInput(new Form_Input(
	'dot_host',
	'DoT hostname',
	'text',
	$pconfig['dot_host']
))->setHelp('TLS certificate is verified against this name, e.g. %s', '<code>dns.example.com</code>');
$section->addInput(new Form_Input(
	'dot_ips',
	'Server IP(s)',
	'text',
	$pconfig['dot_ips']
))->setHelp('Comma-separated IPv4/IPv6 addresses. Leave empty to auto-resolve A + AAAA on save.');
$section->addInput(new Form_Input(
	'dot_port',
	'Port',
	'number',
	$pconfig['dot_port'],
	['min' => 1, 'max' => 65535]
))->setHelp('Default 853.');
$form->add($section);

$section = new Form_Section('Safety');
$section->addInput(new Form_Checkbox(
	'skiptest',
	'Skip upstream test',
	'Save even if the upstream test fails',
	false
))->setHelp('By default the upstream is tested with a real DNS query before saving, so a typo cannot take down DNS for the whole network. Only skip this if the upstream is temporarily unreachable and you still want to save.');
$form->add($section);

$form->addGlobal(new Form_Button(
	'save',
	'Save & Apply',
	null,
	'fa-solid fa-floppy-disk'
))->addClass('btn-primary');

$form->addGlobal(new Form_Button(
	'testbtn',
	'Test Only',
	null,
	'fa-solid fa-vial'
))->addClass('btn-info');

$form->addGlobal(new Form_Button(
	'restart',
	'Restart Service',
	null,
	'fa-solid fa-arrows-rotate'
))->addClass('btn-warning');

print($form);

$logfile = $conf['log_file'];
$logtail = '';
if (file_exists($logfile)) {
	$logtail = (string) shell_exec('/usr/bin/tail -n 20 ' . escapeshellarg($logfile));
}
?>
<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title"><?=gettext("Recent log")?> (<?=htmlspecialchars($logfile)?>)</h2>
	</div>
	<div class="panel-body">
		<pre style="max-height: 300px; overflow-y: auto;"><?=htmlspecialchars($logtail !== '' ? $logtail : gettext('(log is empty)'))?></pre>
	</div>
</div>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	function dohp_toggle_mode() {
		var mode = $('#mode').val();
		$('.dohp-doh').toggle(mode === 'doh');
		$('.dohp-dot').toggle(mode === 'dot');
	}
	$('#mode').on('change', dohp_toggle_mode);
	dohp_toggle_mode();
});
//]]>
</script>
<?php
include("foot.inc");
