# Changelog

## v1.3.2 — 2026-07-08

Full-repo re-review: no new security issues; six correctness/robustness
fixes.

### Fixed

- **DoH daemon reuses one curl handle** for the process lifetime, so the
  HTTPS connection stays alive between queries — one TLS handshake instead
  of one per lookup (a transport error drops the handle so the next query
  reconnects cleanly). Meaningful speedup for the sequential daemon.
- **`start.sh` no longer truncates the log on every start** — it appends,
  and trims the file to the last 500 lines once it exceeds ~1 MB.
- **`start.sh` readiness check follows `listen_host`/`listen_port` from
  `config.php`** instead of hardcoding `127.0.0.1:5053`.
- **GUI DoH upstream test** now uses `&` when the endpoint URL already has
  a query string (previously produced a malformed double-`?` URL).
- **`set_url.php` applies the same URL character hardening as the webGUI**
  (rejects whitespace, quotes, backslashes, backticks, angle brackets).
- **CI now lints `src/system_patch.sh`** (sh -n + ShellCheck) — it was
  missing from the hardcoded file list since v1.2.0.

## v1.3.1 — 2026-07-08

### Security

- **Fixed a stored self-XSS in the webGUI status box** — a saved DoH URL
  containing HTML (e.g. `https://host/<script>…`) was rendered unescaped in
  the status/info boxes. Exploitation required an authenticated webGUI user
  with access to the DoH Proxy page, so on single-admin installs this was
  self-XSS only; on installs with multiple restricted-privilege users it
  could have been an escalation path. All config-derived values shown in
  info boxes are now escaped with `htmlspecialchars()`, and the DoH URL
  validator additionally rejects whitespace, quotes, backslashes, backticks
  and angle brackets.

### Added

- **Release checksums** — the release workflow now attaches a source tarball
  and a `SHA256SUMS` file to every release; README gained a "Verified
  install" section.
- The GUI mode selector now warns that the DoH proxy is sequential and can
  saturate on a busy LAN (prefer DoT unless only HTTPS egress is allowed).

## v1.3.0 — 2026-07-08

### Added

- **Dashboard "DNS server(s)" status** — while the DOH-PROXY forward-zone is
  active, the *System Information* widget shows the encrypted upstream as the
  first list entry with a green lock: `DoT: host:port` (DoT mode) or
  `DoH: https://…` (DoH mode), read live from `/root/doh-proxy/config.php`
  and linking to *Services > DoH Proxy*. Same marker-managed, `php -l` gated,
  render-time-detected mechanism as the General Setup notice; `system_patch.sh
  apply|revert` now handles both pages (widget anchor supports both pfSense
  2.8 `get_dns_nameservers()` and 2.7 `get_dns_servers()`; unrecognized
  layouts are skipped with a warning).

## v1.2.0 — 2026-07-08

### Added

- **General Setup "Encrypted DNS" notice** (`system_patch.sh`) — while the
  DOH-PROXY forward-zone is active in Unbound, *System > General Setup* shows
  an alert **"DoH/DoT is currently running"** linking to *Services > DoH
  Proxy*, and the unused DNS Server Settings fields (server rows, add button,
  override checkbox, resolution behavior) are grayed out. Details:
  - fields become readonly + `pointer-events: none`, **not** `disabled`, so
    the saved values still submit on Save and nothing gets wiped
  - detection is at page-render time — turning DoH/DoT off re-enables the
    section automatically, no re-patch needed
  - marker-managed (`BEGIN/END DOH-PROXY PATCH`) and `php -l` gated on both
    apply and revert; a pristine `system.php.orig` is kept in
    `/root/doh-proxy/backup/`
  - installer applies it, uninstaller reverts it (with a standalone fallback
    if `/root/doh-proxy` was already deleted)
  - pfSense upgrades replace `system.php` and drop the patch — re-apply with
    `sh /root/doh-proxy/system_patch.sh apply`
  - if a future pfSense version changes the page layout, the patch skips
    itself with a warning instead of failing the install

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

### Fixed

- **`start.sh` no longer runs `service unbound onerestart`** — on pfSense
  that invokes the FreeBSD pkg rc script and silently starts a second,
  unmanaged unbound on `127.0.0.1:53`. It now calls pfSense's own
  `services_unbound_configure()` instead.

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
