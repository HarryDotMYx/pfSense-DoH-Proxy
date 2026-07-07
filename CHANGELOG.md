# Changelog

## v1.0.0 — 2026-07-07

First stable release.

### Added

- **DoH forwarder daemon** (`doh_proxy.php`)
  - UDP + TCP listener on `127.0.0.1:5053`
  - RFC 8484 `application/dns-message` POST upstream over HTTPS
  - TLS certificate verification always enforced
  - Native-socket fast path with automatic stream-socket fallback
  - Optional bootstrap IP pin (`doh_resolve`, curl `--resolve`) so the proxy
    can reach the DoH server even while DNS itself is down
  - `--self-test` mode performing a real end-to-end query
- **webGUI page** — *Services > DoH Proxy*
  - Endpoint editor with validation and auto-resolve of the pin IP
  - Upstream tested with a real DoH query **before** saving, so a typo
    cannot take down DNS for the whole network (override checkbox available)
  - Timestamped config backup on every save
  - Service status, Save & Restart / Test Only / Restart buttons, log tail
- **Installer** (`install.sh`)
  - Self-test gate before anything DNS-affecting happens
  - Registers the *Services > DoH Proxy* menu entry and a `shellcmd` boot
    autostart via pfSense's own config API (`write_config()`), so both show
    up in the config history and survive config backups/restores
  - Optional Unbound wiring — refuses to touch custom options that already
    contain a `forward-zone`
  - Idempotent; non-interactive mode with `-y` and `--url=`
- **Uninstaller** (`uninstall.sh`) — removes service, GUI page, menu entry,
  boot hook and (only if installer-added) the Unbound block
- **CLI helper** (`set_url.php`) to change the endpoint from the shell
