# pfSense DoH Proxy

![pfSense](https://img.shields.io/badge/pfSense-2.7.x%20%7C%202.8.x-212121?logo=pfsense&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.x-777BB4?logo=php&logoColor=white)
![Dependencies](https://img.shields.io/badge/dependencies-none-brightgreen)
![License](https://img.shields.io/badge/license-MIT-green)

A tiny DNS-over-HTTPS (DoH) forwarder for pfSense, with a native-looking webGUI page to manage it.

pfSense's built-in DNS Resolver (Unbound) supports DNS-over-TLS out of the box, but **not DoH**. This project adds a small userspace proxy so Unbound can forward all DNS through any DoH endpoint **you choose** — your own server, or a public one.

```
LAN clients ──> Unbound (pfSense DNS Resolver, :53)
                  │  forward-zone "."
                  ▼
        doh-proxy (127.0.0.1:5053, plain-PHP daemon)
                  │  HTTPS POST application/dns-message
                  ▼
        Your DoH server (https://.../dns-query)
```

No default DoH provider is shipped — the endpoint is left empty on purpose and you decide where your DNS goes.

## Features

- **Zero dependencies** — plain PHP using what pfSense already ships (php, curl). No packages, no pkg repo changes.
- **webGUI page** (*Services > DoH Proxy*) using pfSense's own theme and login:
  - change the DoH URL, bootstrap IP pin, timeout and debug flag
  - the upstream is **tested with a real DoH query before saving**, so a typo can't take down DNS for the whole network
  - every save keeps a timestamped backup of the previous config
  - service status, restart button and live log tail
- **TLS enforced** — certificate verification is always on (`CURLOPT_SSL_VERIFYPEER` + hostname check).
- **Bootstrap IP pin** — optional `host:443:ip` pin (curl `--resolve`) so the proxy can reach the DoH server even when DNS itself is down (no chicken-and-egg).
- **Self-test mode** — `php doh_proxy.php --self-test` performs a real query end to end.
- **Survives reboots** — installer registers a `shellcmd` boot hook via pfSense's own config API (`write_config()`), so it shows up in the config history and syncs with config backups.
- Careful installer: it **refuses to touch Unbound** if your custom options already contain a `forward-zone`, and never overwrites an existing proxy config.

## Requirements

- pfSense CE 2.7.x / 2.8.x (FreeBSD, PHP 8) — tested on 2.8.1
- Root SSH access
- A DoH endpoint (RFC 8484 `application/dns-message`, e.g. AdGuard Home, dnsdist, Cloudflare, or your own)

## Install

SSH into pfSense as root, then:

```sh
curl -sL -o /tmp/doh-proxy.tar.gz https://github.com/HarryDotMYx/pfSense-DoH-Proxy/archive/refs/heads/main.tar.gz
tar -xzf /tmp/doh-proxy.tar.gz -C /tmp
sh /tmp/pfSense-DoH-Proxy-main/install.sh
```

The installer will:

1. Copy the proxy to `/root/doh-proxy` (existing `config.php` is kept)
2. Ask for your DoH URL (leave empty to set it later in the GUI)
3. Run a **self-test** against that URL
4. Install the webGUI page and register *Services > DoH Proxy*
5. Register boot autostart (`shellcmd`)
6. **Optionally** point Unbound at the proxy — only if the self-test passed, and only if you don't already have a custom `forward-zone`
7. Start the service

Non-interactive install:

```sh
sh install.sh -y --url=https://dns.example.com/dns-query
```

## Configure

- **GUI**: *Services > DoH Proxy* — edit, test, save, restart, watch logs.
- **CLI**: `php /root/doh-proxy/set_url.php https://dns.example.com/dns-query [pin-ip]` then restart with `sh /root/doh-proxy/stop.sh; sh /root/doh-proxy/start.sh`.
- **Self-test**: `php /root/doh-proxy/doh_proxy.php --self-test`

If the installer skipped Unbound wiring (because you already had a `forward-zone`), merge this into *Services > DNS Resolver > Custom options* yourself:

```
server:
  do-not-query-localhost: no
forward-zone:
  name: "."
  forward-addr: 127.0.0.1@5053
```

## Uninstall

```sh
sh uninstall.sh
```

Removes the service, GUI page, menu entry, boot hook and (if the installer added it) the Unbound `forward-zone` block. Asks before deleting `/root/doh-proxy` with your config and backups (`-y` to skip prompts).

## Files

| Path | Purpose |
|---|---|
| `/root/doh-proxy/doh_proxy.php` | the proxy daemon (UDP+TCP :5053 → DoH) |
| `/root/doh-proxy/config.php` | runtime config |
| `/root/doh-proxy/set_url.php` | CLI helper to change the endpoint |
| `/root/doh-proxy/start.sh`, `stop.sh` | service control (start also restarts Unbound) |
| `/root/doh-proxy/backup/` | timestamped config backups |
| `/usr/local/www/doh_proxy_gui.php` | webGUI page |
| `/var/log/doh-proxy.log` | log |

## Notes & caveats

- The proxy handles queries sequentially (single PHP process). Unbound caches aggressively in front of it, so this is fine for home/small-office use — it is not built for high-QPS environments.
- The proxy binds to `127.0.0.1` only; nothing is exposed to the LAN or WAN.
- If you change `listen_port` in the config, update the readiness check in `start.sh` too.
- pfSense upgrades may remove `/usr/local/www/doh_proxy_gui.php`; just re-run the installer afterwards. Your config in `/root/doh-proxy` survives.

## License

MIT
