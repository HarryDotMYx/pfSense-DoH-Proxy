<?php
/*
 * set_url.php - set the DoH endpoint from the command line.
 *
 * Usage: php set_url.php https://dns.example.com/dns-query [pin-ip]
 *
 * If pin-ip is omitted the hostname is resolved once and pinned to the
 * answer; pass an explicit IP to override, or edit config.php to remove
 * the pin entirely.
 */

$conf_file = __DIR__ . '/config.php';
$conf = file_exists($conf_file) ? require $conf_file : [];
if (!is_array($conf)) {
	$conf = [];
}

$url = $argv[1] ?? '';
$pin = $argv[2] ?? '';

$host = parse_url($url, PHP_URL_HOST);
if ($url === '' || parse_url($url, PHP_URL_SCHEME) !== 'https' || !is_string($host) || $host === '') {
	fwrite(STDERR, "Usage: php set_url.php https://host/dns-query [pin-ip]\n");
	exit(1);
}

if ($pin !== '' && filter_var($pin, FILTER_VALIDATE_IP) === false) {
	fwrite(STDERR, "Invalid pin IP: {$pin}\n");
	exit(1);
}

if ($pin === '') {
	$ip = gethostbyname($host);
	if ($ip !== $host && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
		$pin = $ip;
	} else {
		fwrite(STDERR, "WARNING: could not resolve {$host}; continuing without an IP pin.\n");
	}
}

// Keep a timestamped backup, same layout the webGUI uses
if (file_exists($conf_file)) {
	$ts = date('Ymd-His');
	@mkdir(__DIR__ . "/backup/{$ts}", 0755, true);
	@copy($conf_file, __DIR__ . "/backup/{$ts}/config.php");
}

$conf['doh_url'] = $url;
$conf['doh_resolve'] = ($pin !== '') ? "{$host}:443:{$pin}" : '';

file_put_contents($conf_file, "<?php\nreturn " . var_export($conf, true) . ";\n");
echo "doh_url set to {$url}" . (($pin !== '') ? " (pinned to {$pin})" : " (no IP pin)") . "\n";
echo "Restart the service to apply: sh " . __DIR__ . "/stop.sh; sh " . __DIR__ . "/start.sh\n";
