# sugar-plugin-installer — Caliber Learnings

## Accumulated Learnings

### 2026-11-19 — The installer stages, it never runs or trusts
Pattern: `PluginInstaller` extends `Composer\Installer\LibraryInstaller` and overrides only `supports()` and `getInstallPath()`. Composer's download, update and remove all go through `getInstallPath()`, so one override redirects install, update and remove together. The scope is read there on every call, not at activation.
Anti-pattern: do not add a write to `trustedBundleChannels`, the channel ledger, or any candy-code store from the installer. Trust is a user-tier decision and stays in candy-code. Do not run anything from the package either.
Source: plan_plugin.md §5.3, §5.5, phase 4 (PL-4a)

### 2026-11-19 — Validate the root section where it is read
Pattern: `InstallerConfig::fromExtra()` refuses a bad `scope`, an unknown key, a non-object section and a bad `root` with `\InvalidArgumentException`. It is called from `getInstallPath()`, so only a sugar-plugin install fails. A malformed section stays silent for every other Composer command.
Anti-pattern: do not fall back to `user` when `scope` is unrecognised. A typo that silently picks the user tier is the failure this check exists to catch.
Source: plan_plugin.md §5.1, §0.2 O5
