# Changelog

## v1.3.5 — 2026-07-08

Fifth review pass: one real (IPv6 corner-case) bug and one misleading
message.

### Fixed

- **IPv6 pin IPs no longer mangled in the GUI** — the Pinned IP field was
  filled by splitting `host:443:ip` on every colon and taking the last
  piece, so an IPv6 pin like `2606:4700:4700::1111` displayed as `1111`
  (and failed validation on the next save). Now split with a limit so the
  address survives intact. (curl itself accepts IPv6 in `--resolve` both
  bracketed and unbracketed — verified live — so stored pins always worked;
  only the display/re-save path was broken.)
- **"Restart Service" tells the truth** — in DoH mode it now reports an
  error when the daemon did not actually come up (e.g. no DoH URL
  configured yet) instead of always claiming "Service restarted."

## v1.3.4 — 2026-07-08

Fourth review pass. All minor robustness items; no exploitable issues.

### Fixed

- **Unbound block replacement is backreference-proof** — the GUI and the
  installer now use `preg_replace_callback`, so `$1`/`\1` sequences in
  config values can never be interpreted as regex replacement
  backreferences and corrupt the marker block.
- **`set_url.php` validates the hostname shape** (`parse_url` alone is
  lenient) so odd characters cannot reach the Unbound block via the CLI —
  now matches the webGUI's `is_hostname` strictness.
- **`start.sh` refuses to start the daemon when `doh_url` is empty**
  (previously it bound the port and every query just failed).
- **GUI status no longer trusts a reused PID** — `dohp_running()` verifies
  the process command line actually is `doh_proxy.php`, matching the
  `stop.sh` guard from v1.3.3.
- **DoT probe pins TLS 1.2+ explicitly** (`crypto_method`) instead of
  relying on system defaults.
- README: note that restoring a config backup onto a fresh pfSense brings
  back the menu/boot hook/Unbound block but not `/root/doh-proxy` — re-run
  the installer after a restore.

## v1.3.3 — 2026-07-08

Third review pass: closes every remaining known nit. No security issues.

### Fixed

- **Oversized UDP answers now follow RFC 1035 truncation** — a DoH response
  larger than 1232 bytes (safe EDNS default) is no longer blindly relayed
  over UDP (where it could be silently cut off at the socket); the daemon
  replies with the query echoed back with QR+TC set, so the client retries
  over TCP and gets the full answer. Matters for large (e.g. DNSSEC)
  responses in DoH mode.
- **Non-DNS datagrams are dropped, not relayed** — UDP packets and TCP
  payloads shorter than a DNS header (12 bytes) are ignored instead of
  being forwarded upstream (which produced HTTP 400 log spam).
- **`stop.sh` verifies the PID belongs to the daemon** (`ps -o command`
  contains `doh_proxy.php`) before killing, guarding against a stale
  pidfile whose PID number was reused by an unrelated process.
- **DoT probe verifies the DNS response ID** matches the query ID.
- **`start.sh` single-flight lock** — the boot shellcmd and a GUI save can
  no longer double-start the daemon (mkdir lock; stale locks older than
  2 minutes clear themselves, so a crashed starter cannot wedge startup).

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
