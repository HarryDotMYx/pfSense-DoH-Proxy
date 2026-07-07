<?php
/*
 * dot_test.php - CLI self-test for DoT mode.
 *
 * Usage: php dot_test.php [host] [ip1,ip2,...] [port]
 * With no arguments the values are read from config.php (mode "dot").
 * Exits 0 if at least one upstream answers over verified TLS.
 */

require_once __DIR__ . '/dot_check.php';

$host = $argv[1] ?? '';
$ips  = isset($argv[2]) ? array_filter(array_map('trim', explode(',', $argv[2]))) : array();
$port = (int) ($argv[3] ?? 853);

if ($host === '') {
	$conf = @include __DIR__ . '/config.php';
	if (!is_array($conf) || empty($conf['dot_host'])) {
		fwrite(STDERR, "No host given and no dot_host in config.php\n");
		exit(1);
	}
	$host = (string) $conf['dot_host'];
	$ips  = is_array($conf['dot_ips'] ?? null) ? $conf['dot_ips'] : array();
	$port = (int) ($conf['dot_port'] ?? 853);
}

if (empty($ips)) {
	foreach (dns_get_record($host, DNS_A) ?: array() as $r) {
		if (!empty($r['ip'])) {
			$ips[] = $r['ip'];
		}
	}
	foreach (dns_get_record($host, DNS_AAAA) ?: array() as $r) {
		if (!empty($r['ipv6'])) {
			$ips[] = $r['ipv6'];
		}
	}
	if (empty($ips)) {
		fwrite(STDERR, "Could not resolve {$host} and no IPs given\n");
		exit(1);
	}
}

$ok = 0;
foreach ($ips as $ip) {
	$detail = '';
	if (dohp_dot_probe($host, $ip, $port, $detail)) {
		echo "PASS {$detail}\n";
		$ok++;
	} else {
		echo "FAIL {$detail}\n";
	}
}

exit($ok > 0 ? 0 : 1);
