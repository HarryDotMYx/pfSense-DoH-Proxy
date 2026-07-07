<?php
return [
    // Full DoH endpoint URL, e.g. 'https://dns.example.com/dns-query'.
    // Left empty on purpose: set it during install, via the webGUI
    // (Services > DoH Proxy) or with: php /root/doh-proxy/set_url.php <url>
    'doh_url' => '',

    // Optional bootstrap pin 'host:443:ip' so the proxy can reach the DoH
    // server even when DNS itself is broken. Empty = resolve normally.
    'doh_resolve' => '',

    'listen_host' => '127.0.0.1',
    'listen_port' => 5053,
    'timeout_seconds' => 10,
    'self_test_domain' => 'example.com',
    'debug' => false,
    'log_file' => '/var/log/doh-proxy.log',
];
