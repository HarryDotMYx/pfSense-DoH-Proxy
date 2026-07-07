<?php
/*
 * doh_proxy_gui.php
 *
 * Web GUI for the custom DoH proxy in /root/doh-proxy.
 * Edits config.php, restarts the service and shows status/logs.
 */

require_once("guiconfig.inc");

define('DOHP_DIR', '/root/doh-proxy');
define('DOHP_CONF', DOHP_DIR . '/config.php');
define('DOHP_PIDFILE', '/var/run/doh-proxy.pid');
// example.com A query, RFC 8484 sample (base64url)
define('DOHP_TEST_QUERY', 'q80BAAABAAAAAAAAB2V4YW1wbGUDY29tAAABAAE');

function dohp_read_config() {
	$defaults = array(
		'doh_url' => '',
		'doh_resolve' => '',
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

function dohp_running() {
	if (file_exists(DOHP_PIDFILE) && isvalidpid(DOHP_PIDFILE)) {
		return (int) trim(file_get_contents(DOHP_PIDFILE));
	}
	return false;
}

function dohp_url_host($url) {
	$host = parse_url($url, PHP_URL_HOST);
	return is_string($host) ? $host : '';
}

function dohp_test_upstream($url, $pin_ip) {
	$host = dohp_url_host($url);
	$cmd = '/usr/local/bin/curl -s -o /dev/null -w %{http_code} --max-time 8 ' .
	    '--resolve ' . escapeshellarg($host . ':443:' . $pin_ip) . ' ' .
	    escapeshellarg($url . '?dns=' . DOHP_TEST_QUERY);
	return trim((string) shell_exec($cmd . ' 2>/dev/null'));
}

function dohp_restart() {
	mwexec(DOHP_DIR . '/stop.sh');
	sleep(1);
	// start.sh waits for the listen port, then restarts unbound
	mwexec(DOHP_DIR . '/start.sh');
}

$conf = dohp_read_config();

$pconfig = array();
$pconfig['doh_url'] = $conf['doh_url'];
$pconfig['pin_ip'] = '';
$pconfig['timeout'] = $conf['timeout_seconds'];
$pconfig['debug'] = !empty($conf['debug']);

if (!empty($conf['doh_resolve'])) {
	$parts = explode(':', $conf['doh_resolve']);
	$pconfig['pin_ip'] = end($parts);
}

$input_errors = array();
$savemsg = '';
$testmsg = '';

if ($_POST) {
	if (isset($_POST['restart'])) {
		dohp_restart();
		$savemsg = gettext("Service restarted.");
	} elseif (isset($_POST['save']) || isset($_POST['testbtn'])) {
		$pconfig['doh_url'] = trim($_POST['doh_url'] ?? '');
		$pconfig['pin_ip'] = trim($_POST['pin_ip'] ?? '');
		$pconfig['timeout'] = trim($_POST['timeout'] ?? '10');
		$pconfig['debug'] = isset($_POST['debug']);
		$skiptest = isset($_POST['skiptest']);

		$host = dohp_url_host($pconfig['doh_url']);
		if (empty($pconfig['doh_url']) ||
		    parse_url($pconfig['doh_url'], PHP_URL_SCHEME) !== 'https' ||
		    empty($host)) {
			$input_errors[] = gettext("DoH URL must be a valid https:// URL, e.g. https://dns.example.com/dns-query");
		}
		if (!empty($pconfig['pin_ip']) && !is_ipaddr($pconfig['pin_ip'])) {
			$input_errors[] = gettext("Pinned IP must be a valid IP address.");
		}
		if (!is_numericint($pconfig['timeout']) ||
		    $pconfig['timeout'] < 1 || $pconfig['timeout'] > 60) {
			$input_errors[] = gettext("Timeout must be between 1 and 60 seconds.");
		}

		// Auto-resolve the pin IP when left empty
		if (!$input_errors && empty($pconfig['pin_ip'])) {
			$ip = gethostbyname($host);
			if ($ip == $host || !is_ipaddr($ip)) {
				$input_errors[] = sprintf(gettext('Could not resolve "%s" - enter the pinned IP manually.'), $host);
			} else {
				$pconfig['pin_ip'] = $ip;
			}
		}

		if (!$input_errors && !$skiptest) {
			$code = dohp_test_upstream($pconfig['doh_url'], $pconfig['pin_ip']);
			if ($code === '200') {
				$testmsg = sprintf(gettext('Upstream test OK (HTTP %1$s from %2$s via %3$s).'),
				    $code, $host, $pconfig['pin_ip']);
			} else {
				$msg = sprintf(gettext('Upstream test FAILED (HTTP "%1$s" from %2$s via %3$s).'),
				    $code, $host, $pconfig['pin_ip']);
				if (isset($_POST['save'])) {
					$msg .= ' ' . gettext('Nothing was saved. Check "Skip upstream test" to save anyway.');
				}
				$input_errors[] = $msg;
			}
		}

		if (!$input_errors && isset($_POST['save'])) {
			// Backup, then write the new config
			$ts = date('Ymd-His');
			safe_mkdir(DOHP_DIR . "/backup/{$ts}");
			@copy(DOHP_CONF, DOHP_DIR . "/backup/{$ts}/config.php");

			$new = $conf;
			$new['doh_url'] = $pconfig['doh_url'];
			$new['doh_resolve'] = $host . ':443:' . $pconfig['pin_ip'];
			$new['timeout_seconds'] = (int) $pconfig['timeout'];
			$new['debug'] = (bool) $pconfig['debug'];

			file_put_contents(DOHP_CONF, "<?php\nreturn " . var_export($new, true) . ";\n");
			dohp_restart();
			$savemsg = gettext("Configuration saved and service restarted.");
			if ($testmsg) {
				$savemsg .= ' ' . $testmsg;
				$testmsg = '';
			}
			$conf = dohp_read_config();
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

$pid = dohp_running();
if ($pid) {
	print_info_box(sprintf(gettext('DoH proxy is running (PID %1$d) on %2$s:%3$d — upstream: %4$s'),
	    $pid, $conf['listen_host'], $conf['listen_port'], $conf['doh_url']), 'success', false);
} else {
	print_info_box(gettext('DoH proxy is NOT running.'), 'danger', false);
}

$form = new Form(false);

$section = new Form_Section('DoH Upstream');

$section->addInput(new Form_Input(
	'doh_url',
	'*DoH URL',
	'text',
	$pconfig['doh_url']
))->setHelp('Full DoH endpoint URL, e.g. %s', '<code>https://dns.example.com/dns-query</code>');

$section->addInput(new Form_Input(
	'pin_ip',
	'Pinned IP',
	'text',
	$pconfig['pin_ip']
))->setHelp('IP address used to reach the DoH server without depending on DNS (bootstrap). Leave empty to auto-resolve on save.');

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

$section->addInput(new Form_Checkbox(
	'skiptest',
	'Skip upstream test',
	'Save even if the upstream test fails',
	false
))->setHelp('By default the upstream is tested with a real DoH query before saving, so a typo cannot take down DNS for the whole network. Only skip this if the upstream is temporarily unreachable and you still want to save.');

$form->add($section);

$form->addGlobal(new Form_Button(
	'save',
	'Save & Restart',
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
<?php
include("foot.inc");
