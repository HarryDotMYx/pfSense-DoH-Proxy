<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';
$logFile = $config['log_file'];
$debug = (bool) ($config['debug'] ?? false);

function proxy_log(string $message): void {
    global $logFile;
    @file_put_contents($logFile, '[' . date('c') . '] ' . $message . PHP_EOL, FILE_APPEND);
}

function proxy_debug(string $message): void {
    global $debug;
    if ($debug) {
        proxy_log($message);
    }
}

function doh_exchange(string $query, array $config): string {
    $ch = curl_init($config['doh_url']);
    if ($ch === false) {
        throw new RuntimeException('curl init failed');
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $query,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/dns-message',
            'Accept: application/dns-message',
        ],
        CURLOPT_TIMEOUT => (int) $config['timeout_seconds'],
        CURLOPT_CONNECTTIMEOUT => (int) $config['timeout_seconds'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_USERAGENT => 'pfSense-DoH-Proxy/1.0',
    ]);

    if (!empty($config['doh_resolve'])) {
        curl_setopt($ch, CURLOPT_RESOLVE, [(string) $config['doh_resolve']]);
    }

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if (!is_string($response) || $response === '') {
        throw new RuntimeException('empty DoH response' . ($error !== '' ? ': ' . $error : ''));
    }
    if ($httpCode !== 200) {
        throw new RuntimeException('unexpected DoH HTTP status ' . $httpCode);
    }

    return $response;
}

function build_dns_query(string $name, int $id = 0x1234): string {
    $labels = explode('.', trim($name, '.'));
    $buffer = '';
    $buffer .= pack('n6', $id, 0x0100, 1, 0, 0, 0);
    foreach ($labels as $label) {
        $label = (string) $label;
        $length = strlen($label);
        if ($length === 0 || $length > 63) {
            throw new InvalidArgumentException('invalid DNS label in self-test query');
        }
        $buffer .= chr($length) . $label;
    }
    $buffer .= "\x00\x00\x01\x00\x01";
    return $buffer;
}

function read_exact_stream($stream, int $length): ?string {
    $buffer = '';
    while (strlen($buffer) < $length) {
        $chunk = fread($stream, $length - strlen($buffer));
        if ($chunk === '' || $chunk === false) {
            return null;
        }
        $buffer .= $chunk;
    }
    return $buffer;
}

function run_self_test(array $config): int {
    try {
        $query = build_dns_query((string) ($config['self_test_domain'] ?? 'example.com'));
        $response = doh_exchange($query, $config);
        if (strlen($response) < 12) {
            throw new RuntimeException('short DoH response');
        }
        proxy_log('DoH self-test passed against ' . $config['doh_url']);
        return 0;
    } catch (Throwable $e) {
        $message = 'DoH self-test failed: ' . $e->getMessage();
        proxy_log($message);
        fwrite(STDERR, $message . PHP_EOL);
        return 1;
    }
}

if (in_array('--self-test', $argv, true)) {
    exit(run_self_test($config));
}

$listenUri = $config['listen_host'] . ':' . (int) $config['listen_port'];

if (extension_loaded('sockets')) {
    function read_exact_socket(Socket $socket, int $length): ?string {
        $buffer = '';
        while (strlen($buffer) < $length) {
            $chunk = @socket_read($socket, $length - strlen($buffer), PHP_BINARY_READ);
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $buffer .= $chunk;
        }
        return $buffer;
    }

    function write_all_socket(Socket $socket, string $payload): bool {
        $written = 0;
        $length = strlen($payload);
        while ($written < $length) {
            $result = @socket_write($socket, substr($payload, $written));
            if ($result === false || $result === 0) {
                return false;
            }
            $written += $result;
        }
        return true;
    }

    $udpSocket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    $tcpSocket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if ($udpSocket === false || $tcpSocket === false) {
        proxy_log('Native socket creation failed, falling back to stream sockets');
    } else {
        @socket_set_option($tcpSocket, SOL_SOCKET, SO_REUSEADDR, 1);
        $udpBound = @socket_bind($udpSocket, (string) $config['listen_host'], (int) $config['listen_port']);
        $tcpBound = @socket_bind($tcpSocket, (string) $config['listen_host'], (int) $config['listen_port']);
        $tcpListening = $tcpBound && @socket_listen($tcpSocket, 128);
        if (!$udpBound || !$tcpListening) {
            proxy_log('Native socket bind/listen failed, falling back to stream sockets');
            @socket_close($udpSocket);
            @socket_close($tcpSocket);
        } else {
            @socket_set_nonblock($udpSocket);
            @socket_set_nonblock($tcpSocket);
            proxy_log('DoH proxy started on ' . $listenUri . ' using ' . $config['doh_url'] . ' (native sockets)');

            while (true) {
                $read = [$udpSocket, $tcpSocket];
                $write = null;
                $except = null;
                $changed = @socket_select($read, $write, $except, 1, 0);
                if ($changed === false) {
                    usleep(200000);
                    continue;
                }
                if ($changed === 0) {
                    continue;
                }

                foreach ($read as $server) {
                    if ($server === $udpSocket) {
                        $query = '';
                        $peer = '';
                        $peerPort = 0;
                        $bytes = @socket_recvfrom($udpSocket, $query, 65535, 0, $peer, $peerPort);
                        if ($bytes === false || $bytes === 0 || $query === '') {
                            continue;
                        }
                        proxy_debug('UDP query from ' . $peer . ':' . $peerPort . ' len=' . strlen($query));
                        try {
                            $response = doh_exchange($query, $config);
                            @socket_sendto($udpSocket, $response, strlen($response), 0, $peer, $peerPort);
                        } catch (Throwable $e) {
                            proxy_log('UDP exchange failed: ' . $e->getMessage());
                        }
                        continue;
                    }

                    if ($server === $tcpSocket) {
                        $client = @socket_accept($tcpSocket);
                        if ($client === false) {
                            continue;
                        }
                        @socket_set_block($client);
                        $timeout = ['sec' => (int) $config['timeout_seconds'], 'usec' => 0];
                        @socket_set_option($client, SOL_SOCKET, SO_RCVTIMEO, $timeout);
                        @socket_set_option($client, SOL_SOCKET, SO_SNDTIMEO, $timeout);
                        try {
                            $prefix = read_exact_socket($client, 2);
                            if ($prefix !== null) {
                                $length = unpack('nlen', $prefix);
                                $query = read_exact_socket($client, (int) $length['len']);
                                if ($query !== null) {
                                    proxy_debug('TCP query len=' . strlen($query));
                                    $response = doh_exchange($query, $config);
                                    write_all_socket($client, pack('n', strlen($response)) . $response);
                                }
                            }
                        } catch (Throwable $e) {
                            proxy_log('TCP exchange failed: ' . $e->getMessage());
                        } finally {
                            @socket_close($client);
                        }
                    }
                }
            }
        }
    }
}

$udp = @stream_socket_server('udp://' . $listenUri, $udpErrno, $udpErrstr, STREAM_SERVER_BIND);
if ($udp === false) {
    proxy_log('UDP stream_socket_server failed: [' . $udpErrno . '] ' . $udpErrstr);
    exit(1);
}

$tcp = @stream_socket_server('tcp://' . $listenUri, $tcpErrno, $tcpErrstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
if ($tcp === false) {
    proxy_log('TCP stream_socket_server failed: [' . $tcpErrno . '] ' . $tcpErrstr);
    exit(1);
}

stream_set_blocking($udp, false);
stream_set_blocking($tcp, false);

proxy_log('DoH proxy started on ' . $listenUri . ' using ' . $config['doh_url']);

while (true) {
    $read = [$udp, $tcp];
    $write = null;
    $except = null;
    $changed = @stream_select($read, $write, $except, 1, 0);
    if ($changed === false) {
        usleep(200000);
        continue;
    }
    if ($changed === 0) {
        continue;
    }

    $handledAny = false;
    foreach ($read as $server) {
        if ($server === $udp) {
            $peer = null;
            $query = @stream_socket_recvfrom($udp, 65535, 0, $peer);
            if ($query === false || $query === '') {
                continue;
            }
            $handledAny = true;
            proxy_debug('UDP query from ' . ($peer ?? 'unknown') . ' len=' . strlen($query));
            try {
                $response = doh_exchange($query, $config);
                @stream_socket_sendto($udp, $response, 0, (string) $peer);
            } catch (Throwable $e) {
                proxy_log('UDP exchange failed: ' . $e->getMessage());
            }
            continue;
        }

        if ($server === $tcp) {
            $client = @stream_socket_accept($tcp, 0);
            if ($client === false) {
                continue;
            }
            $handledAny = true;
            stream_set_blocking($client, true);
            stream_set_timeout($client, (int) $config['timeout_seconds']);
            try {
                $prefix = read_exact_stream($client, 2);
                if ($prefix !== null) {
                    $length = unpack('nlen', $prefix);
                    $query = read_exact_stream($client, (int) $length['len']);
                    if ($query !== null) {
                        proxy_debug('TCP query len=' . strlen($query));
                        $response = doh_exchange($query, $config);
                        fwrite($client, pack('n', strlen($response)) . $response);
                    }
                }
            } catch (Throwable $e) {
                proxy_log('TCP exchange failed: ' . $e->getMessage());
            } finally {
                fclose($client);
            }
        }
    }

    if (!$handledAny) {
        usleep(100000);
    }
}
