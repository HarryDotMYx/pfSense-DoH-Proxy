<?php
/*
 * dot_check.php - shared DoT (DNS over TLS) probe.
 *
 * Opens a verified TLS connection to ip:port with SNI/peer_name = host,
 * sends a real DNS query (length-prefixed, RFC 7858) and expects a
 * plausible DNS answer back. Used by the webGUI, the installer and
 * dot_test.php.
 */

function dohp_build_query(string $name, int $id = 0x2143): string {
	$labels = explode('.', trim($name, '.'));
	$buffer = pack('n6', $id, 0x0100, 1, 0, 0, 0);
	foreach ($labels as $label) {
		$length = strlen($label);
		if ($length === 0 || $length > 63) {
			throw new InvalidArgumentException('invalid DNS label');
		}
		$buffer .= chr($length) . $label;
	}
	$buffer .= "\x00\x00\x01\x00\x01";
	return $buffer;
}

function dohp_dot_probe(string $host, string $ip, int $port, ?string &$detail = null): bool {
	$detail = '';
	$ctx = stream_context_create(array('ssl' => array(
		'peer_name' => $host,
		'verify_peer' => true,
		'verify_peer_name' => true,
		'allow_self_signed' => false,
		'SNI_enabled' => true,
	)));
	$addr = (strpos($ip, ':') !== false) ? "[{$ip}]" : $ip;

	$fp = @stream_socket_client("ssl://{$addr}:{$port}", $errno, $errstr, 8,
	    STREAM_CLIENT_CONNECT, $ctx);
	if ($fp === false) {
		$detail = "TLS connect to {$addr}:{$port} failed: {$errstr}";
		return false;
	}

	stream_set_timeout($fp, 8);
	try {
		$query = dohp_build_query('example.com');
		fwrite($fp, pack('n', strlen($query)) . $query);

		$prefix = fread($fp, 2);
		if (!is_string($prefix) || strlen($prefix) !== 2) {
			$detail = 'no DNS response over TLS';
			return false;
		}
		$len = unpack('nlen', $prefix)['len'];
		$response = '';
		while (strlen($response) < $len) {
			$chunk = fread($fp, $len - strlen($response));
			if ($chunk === '' || $chunk === false) {
				break;
			}
			$response .= $chunk;
		}
		if (strlen($response) < 12) {
			$detail = 'short DNS response over TLS';
			return false;
		}
		if (substr($response, 0, 2) !== substr($query, 0, 2)) {
			$detail = 'DNS response ID mismatch over TLS';
			return false;
		}
	} finally {
		fclose($fp);
	}

	$detail = "OK ({$addr}:{$port}, cert valid for {$host})";
	return true;
}
