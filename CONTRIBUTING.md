# Contributing

Thanks for helping out! A few ground rules keep this project small and safe.

## How to contribute

1. Fork the repo and create a branch.
2. Make your change.
3. Open a pull request — CI (Lint) must be green.

## Ground rules

- **Zero dependencies.** The whole point of this project is that it runs on
  a stock pfSense install: plain PHP + POSIX sh, nothing from Composer,
  no extra packages. PRs adding dependencies will be declined.
- **Don't break the safety model.** Upstreams are tested with a real DNS
  query before anything is saved, TLS verification is always on, and the
  Unbound block is only ever touched between its `# BEGIN DOH-PROXY` /
  `# END DOH-PROXY` markers. Keep it that way.
- **Test on real pfSense** for anything touching `install.sh`,
  `uninstall.sh`, `start.sh` or Unbound handling — CI only catches syntax
  errors; it cannot run pfSense's config API. Say in the PR which version
  you tested on.
- **Update `CHANGELOG.md`** under an *Unreleased* heading if your change is
  user-visible.
- pfSense gotchas worth knowing before you patch:
  - config booleans are empty tags — use `config_path_enabled()`, never
    truthiness on `config_get_path('x/enable')`
  - `unbound` lives at config path `unbound/…`, not `services/unbound/…`
  - never call `service unbound onerestart` — it starts a second, unmanaged
    unbound from the FreeBSD pkg rc script; use
    `services_unbound_configure()` instead

## Releasing (maintainers)

Update `CHANGELOG.md`, then tag: `git tag -a vX.Y.Z -m "vX.Y.Z" && git push origin vX.Y.Z`.
The Release workflow creates the GitHub release automatically with the
notes for that version taken from `CHANGELOG.md`.
