# Security Policy

This project runs as root on a firewall and rewrites your resolver
configuration — security reports are taken seriously.

## Supported versions

Only the latest release is supported. Please update before reporting.

## Reporting a vulnerability

Please **do not open a public issue** for security problems.

- Preferred: use GitHub's private reporting — *Security* tab →
  *Report a vulnerability*.
- Alternative: email `hello@azhan.my`.

You should get a response within a few days. Please include your pfSense
version, the mode in use (DoH/DoT) and steps to reproduce.

## Scope notes

- The proxy binds to `127.0.0.1` only and never exposes a listener to
  LAN/WAN.
- TLS certificate verification is always enforced; a bypass of that
  verification is considered a vulnerability.
- The webGUI page relies on pfSense's own authentication (`guiconfig.inc`);
  anything reachable on that page without a valid pfSense session is a
  vulnerability.
