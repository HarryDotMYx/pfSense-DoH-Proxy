<?php
return [
    // 'doh' = DNS over HTTPS via the local proxy daemon.
    // 'dot' = DNS over TLS, handled natively by Unbound (no daemon).
    'mode' => 'doh',

    // --- DoH settings -------------------------------------------------------
    // Full DoH endpoint URL, e.g. 'https://dns.example.com/dns-query'.
    // Left empty on purpose: set it during install, via the webGUI
    // (Services > DoH Proxy) or with: php /root/doh-proxy/set_url.php <url>
    'doh_url' => '',

    // Optional bootstrap pin 'host:443:ip' so the proxy can reach the DoH
    // server even when DNS itself is broken. Empty = resolve normally.
    'doh_resolve' => '',

    // --- DoT settings -------------------------------------------------------
    // e.g. 'dns.example.com'; certificate is verified against this name.
    'dot_host' => '',
    // IPs of the DoT server (v4 and/or v6). Empty = auto-resolve on save.
    'dot_ips' => [],
    'dot_port' => 853,

    // --- daemon settings (DoH mode) ------------------------------------------
    'listen_host' => '127.0.0.1',
    'listen_port' => 5053,
    'timeout_seconds' => 10,
    'self_test_domain' => 'example.com',
    'debug' => false,
    'log_file' => '/var/log/doh-proxy.log',
];
