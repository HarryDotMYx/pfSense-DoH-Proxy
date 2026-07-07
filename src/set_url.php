<?php
/*
 * set_url.php - set the encrypted-DNS upstream from the command line.
 *
 * DoH:  php set_url.php https://dns.example.com/dns-query [pin-ip]
 * DoT:  php set_url.php tls://dns.example.com[:port] [ip1,ip2,...]
 *
 * DoH: if pin-ip is omitted the hostname is resolved once and pinned.
 * DoT: if the IP list is omitted, A + AAAA records are resolved and used.
 */

$conf_file = __DIR__ . '/config.php';
$conf = file_exists($conf_file) ? require $conf_file : [];
if (!is_array($conf)) {
	$conf = [];
}

$url = $argv[1] ?? '';
$extra = $argv[2] ?? '';

$scheme = parse_url($url, PHP_URL_SCHEME);
$host = parse_url($url, PHP_URL_HOST);

if ($url === '' || !is_string($host) || $host === '' ||
    !in_array($scheme, ['https', 'tls'], true)) {
	fwrite(STDERR, "Usage: php set_url.php https://host/dns-query [pin-ip]\n");
	fwrite(STDERR, "       php set_url.php tls://host[:port] [ip1,ip2,...]\n");
	exit(1);
}

// Keep a timestamped backup, same layout the webGUI uses
if (file_exists($conf_file)) {
	$ts = date('Ymd-His');
	@mkdir(__DIR__ . "/backup/{$ts}", 0755, true);
	@copy($conf_file, __DIR__ . "/backup/{$ts}/config.php");
}

if ($scheme === 'https') {
	$pin = $extra;
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
	$conf['mode'] = 'doh';
	$conf['doh_url'] = $url;
	$conf['doh_resolve'] = ($pin !== '') ? "{$host}:443:{$pin}" : '';
	$applied = "mode=doh, doh_url={$url}" . (($pin !== '') ? " (pinned to {$pin})" : " (no IP pin)");
} else { /* tls:// */
	$port = parse_url($url, PHP_URL_PORT);
	$port = is_int($port) ? $port : 853;
	$ips = array_filter(array_map('trim', explode(',', $extra)));
	foreach ($ips as $ip) {
		if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
			fwrite(STDERR, "Invalid IP: {$ip}\n");
			exit(1);
		}
	}
	if (empty($ips)) {
		foreach ((array) @dns_get_record($host, DNS_A) as $r) {
			if (!empty($r['ip'])) {
				$ips[] = $r['ip'];
			}
		}
		foreach ((array) @dns_get_record($host, DNS_AAAA) as $r) {
			if (!empty($r['ipv6'])) {
				$ips[] = $r['ipv6'];
			}
		}
		if (empty($ips)) {
			fwrite(STDERR, "Could not resolve {$host}; pass the IP list explicitly.\n");
			exit(1);
		}
	}
	$conf['mode'] = 'dot';
	$conf['dot_host'] = $host;
	$conf['dot_ips'] = array_values($ips);
	$conf['dot_port'] = $port;
	$applied = "mode=dot, host={$host}:{$port}, ips=" . implode(',', $ips);
}

file_put_contents($conf_file, "<?php\nreturn " . var_export($conf, true) . ";\n");
echo "{$applied}\n";
echo "Apply via the webGUI (Services > DoH Proxy) or re-run the installer to update Unbound.\n";
