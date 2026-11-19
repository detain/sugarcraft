# sugar-plugin-installer — Caliber Learnings

## Accumulated Learnings

### 2026-11-19 — The installer stages, it never runs or trusts
Pattern: `PluginInstaller` extends `Composer\Installer\LibraryInstaller` and overrides `supports()`, `getInstallPath()`, `install()` and `getPackageBasePath()`. Composer's download, install, update, remove, dump-autoload, show and validate all reach `getInstallPath()`, so it must not throw on a malformed section: it answers with the staged tree that exists. The section is enforced in `download()`, `install()` and `update()`, which Composer runs before anything is written. The scope is read on every call, not at activation. `install()` adds the vendor-collision refusal before the download. `getPackageBasePath()` must stay overridden: LibraryInstaller's default strips a trailing `/<target-dir>` from the install path, and a target-dir that matches the package name would make remove delete the whole bundles root.
Anti-pattern: do not add a write to `trustedBundleChannels`, the channel ledger, or any candy-code store from the installer. Trust is a user-tier decision and stays in candy-code. Do not run anything from the package either.
Source: plan_plugin.md §5.3, §5.5, phase 4 (PL-4a)

### 2026-11-19 — Validate the root section where it is read
Pattern: `InstallerConfig::fromExtra()` refuses a bad `scope`, an unknown key, a non-object section and a bad `root` with `\InvalidArgumentException`. It is called from `configuredPath()`, which `download()`, `install()` and `update()` use, so a malformed section refuses only a sugar-plugin write. `getInstallPath()` catches it and falls back to the existing staged tree, so the read-only and remove paths stay silent.
Anti-pattern: do not fall back to `user` when `scope` is unrecognised. A typo that silently picks the user tier is the failure this check exists to catch.
Source: plan_plugin.md §5.1, §0.2 O5
