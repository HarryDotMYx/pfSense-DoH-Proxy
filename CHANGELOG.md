# Changelog

## v1.1.0 — 2026-07-07

DoH **and** DoT: the page now manages both encrypted-DNS modes.

### Added

- **DoT mode** — Unbound-native `forward-tls-upstream` (no daemon needed):
  - mode selector in the webGUI (*DoH via proxy* / *DoT native*)
  - DoT settings: hostname (cert is verified against it), IPs (auto-resolve
    A + AAAA when left empty) and port
  - real DoT probe before saving: verified TLS handshake on port 853 plus an
    actual DNS query over the connection (`dot_check.php`, `dot_test.php`)
- **Marker-managed Unbound block** — the GUI and installer now write the
  `# BEGIN DOH-PROXY … # END DOH-PROXY` block in Unbound custom options
  directly (replace-in-place when it exists, append when absent) and still
  refuse to touch any foreign `forward-zone`
- `set_url.php` accepts `tls://host[:port]` to switch to DoT from the CLI
- Installer `--url=` accepts both `https://…` and `tls://…` upstreams

### Changed

- `start.sh` is a no-op in DoT mode, so the boot hook works for both modes
- Saving in DoT mode stops the daemon (not needed); saving in DoH mode
  starts it
- "Save & Restart" button renamed to "Save & Apply" (it now also updates
  Unbound); "Restart Service" restarts the daemon (DoH) or Unbound (DoT)

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
