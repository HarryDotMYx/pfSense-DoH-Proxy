# pfSense DoH Proxy

[![Lint](https://github.com/HarryDotMYx/pfSense-DoH-Proxy/actions/workflows/lint.yml/badge.svg)](https://github.com/HarryDotMYx/pfSense-DoH-Proxy/actions/workflows/lint.yml)
![pfSense](https://img.shields.io/badge/pfSense-2.7.x%20%7C%202.8.x-212121?logo=pfsense&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.x-777BB4?logo=php&logoColor=white)
![Dependencies](https://img.shields.io/badge/dependencies-none-brightgreen)
![License](https://img.shields.io/badge/license-MIT-green)

Encrypted DNS for pfSense — **DoH and DoT** — with a native-looking webGUI page to manage both.

pfSense's built-in DNS Resolver (Unbound) supports DNS-over-TLS, but **not DoH**, and even DoT has to be hand-written into the "Custom options" box. This project gives you one GUI page for both:

- **DoH mode** — a small userspace proxy so Unbound can forward all DNS through any RFC 8484 DoH endpoint
- **DoT mode** — managed natively by Unbound (`forward-tls-upstream`), no daemon at all

```
                LAN clients ──> Unbound (pfSense DNS Resolver, :53)
                                   │  forward-zone "."
                 ┌─────────────────┴──────────────────┐
        DoH mode ▼                                    ▼ DoT mode
   doh-proxy (127.0.0.1:5053)               TLS direct from Unbound
        │  HTTPS POST dns-message                     │  port 853, cert verified
        ▼                                             ▼
   https://your-server/dns-query             tls://your-server
```

No default DNS provider is shipped — the upstream is left empty on purpose and you decide where your DNS goes.

## Features

- **Two modes, one page** — switch between DoH (via local proxy) and DoT (native Unbound) in *Services > DoH Proxy*.
- **Zero dependencies** — plain PHP using what pfSense already ships (php, curl, openssl). No packages, no pkg repo changes.
- **Safe by design** — the upstream is **tested with a real DNS query before saving** (HTTPS POST for DoH, verified-TLS query on port 853 for DoT), so a typo can't take down DNS for the whole network. Every save keeps a timestamped backup.
- **TLS enforced** — certificate verification is always on in both modes.
- **Bootstrap IP pin** — optional pins so the upstream is reachable even when DNS itself is down (no chicken-and-egg). DoT IPs auto-resolve A + AAAA.
- **Marker-managed Unbound config** — the page owns only its own `# BEGIN DOH-PROXY … # END DOH-PROXY` block in Unbound custom options and **refuses to touch any forward-zone it doesn't own**.
- **General Setup awareness** — while encrypted forwarding is active, *System > General Setup* shows an **"Encrypted DNS — DoH/DoT is currently running"** notice linking to the DoH Proxy page, and the unused DNS Server Settings fields are grayed out (values are preserved — readonly, not `disabled`, so nothing gets wiped on Save). Turn DoH/DoT off and the section re-enables itself.
- **Dashboard awareness** — the *System Information* widget's **DNS server(s)** row shows the encrypted upstream in use — 🔒 `DoT: cloudflare-dns.com:853` or 🔒 `DoH: https://host/dns-query` — linking to the DoH Proxy page.
- **Self-test tools** — `php doh_proxy.php --self-test` (DoH) and `php dot_test.php` (DoT).
- **Survives reboots** — boot hook registered via pfSense's own config API (`write_config()`), so it shows up in the config history and syncs with config backups. In DoT mode the boot hook is a no-op (no daemon needed).

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
2. Ask for your upstream — `https://host/dns-query` for DoH, `tls://host` for DoT (leave empty to set it later in the GUI)
3. Run a **self-test** against that upstream
4. Install the webGUI page and register *Services > DoH Proxy*
5. Patch *System > General Setup* (Encrypted DNS notice, unused fields grayed out) and the Dashboard *System Information* widget (DoH/DoT upstream shown under DNS server(s)) — originals are backed up, patches are `php -l` gated
6. Register boot autostart (`shellcmd`)
7. **Optionally** point Unbound at the upstream — only if the self-test passed, and only inside its own marker block (existing manual `forward-zone`s are never touched)
8. Start the daemon (DoH mode) or leave everything to Unbound (DoT mode)

Non-interactive install:

```sh
sh install.sh -y --url=https://dns.example.com/dns-query   # DoH
sh install.sh -y --url=tls://dns.example.com               # DoT
```

### Verified install (recommended)

Each release ships a tarball plus a `SHA256SUMS` file. To verify before running anything as root:

```sh
VER=v1.3.3
curl -sLO https://github.com/HarryDotMYx/pfSense-DoH-Proxy/releases/download/$VER/pfsense-doh-proxy-$VER.tar.gz
curl -sLO https://github.com/HarryDotMYx/pfSense-DoH-Proxy/releases/download/$VER/SHA256SUMS
sha256 -c "$(awk '{print $1}' SHA256SUMS)" pfsense-doh-proxy-$VER.tar.gz   # FreeBSD/pfSense
tar -xzf pfsense-doh-proxy-$VER.tar.gz
sh pfsense-doh-proxy-$VER/install.sh
```

(Checksums come from the same GitHub account as the code, so this protects against corrupted/tampered downloads — not against a full account compromise. Audit `install.sh` yourself if that is in your threat model; it is short on purpose.)

## Configure

- **GUI**: *Services > DoH Proxy* — pick the mode, edit, test, save, restart, watch logs.
- **CLI**: `php /root/doh-proxy/set_url.php https://dns.example.com/dns-query [pin-ip]` (DoH) or `php /root/doh-proxy/set_url.php tls://dns.example.com [ip1,ip2]` (DoT), then apply via the GUI or re-run the installer.
- **Self-test**: `php /root/doh-proxy/doh_proxy.php --self-test` (DoH) / `php /root/doh-proxy/dot_test.php` (DoT)

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
| `/root/doh-proxy/doh_proxy.php` | the proxy daemon (UDP+TCP :5053 → DoH), used in DoH mode |
| `/root/doh-proxy/config.php` | runtime config (mode, DoH and DoT settings) |
| `/root/doh-proxy/set_url.php` | CLI helper to change the upstream (`https://` or `tls://`) |
| `/root/doh-proxy/dot_check.php` | shared DoT probe (verified TLS + real DNS query) |
| `/root/doh-proxy/dot_test.php` | CLI DoT self-test |
| `/root/doh-proxy/start.sh`, `stop.sh` | daemon control (start is a no-op in DoT mode) |
| `/root/doh-proxy/system_patch.sh` | applies/reverts the *General Setup* notice + Dashboard widget status (`apply`/`revert`) |
| `/root/doh-proxy/backup/` | timestamped config backups + pristine `system.php.orig` / `system_information.widget.php.orig` |
| `/usr/local/www/doh_proxy_gui.php` | webGUI page |
| `/var/log/doh-proxy.log` | log |

## Notes & caveats

- **The DoH proxy handles queries sequentially** (single PHP process, one HTTPS round-trip at a time). Unbound caches in front of it, but on a busy network with many uncached lookups the proxy can saturate and clients will see SERVFAILs. **If that happens, switch to DoT mode** — same encryption, handled natively (and multi-threaded) by Unbound. DoH mode is best for small networks or when only HTTPS egress is allowed.
- The proxy binds to `127.0.0.1` only; nothing is exposed to the LAN or WAN.
- pfSense upgrades may remove `/usr/local/www/doh_proxy_gui.php` and will replace `system.php` and the Dashboard widget (silently dropping the Encrypted DNS notices); just re-run the installer afterwards, or `sh /root/doh-proxy/system_patch.sh apply` for the notices alone. Your config in `/root/doh-proxy` survives.

## License

MIT
