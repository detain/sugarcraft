### C-EXT-7.b — `bundles install`: refuse dot names, strip `.git` after clone
- item: C-EXT-7 (sources: PLG-1-P5)
- kind: fix · NIT · effort S
- approach: `Subcommands.php:1692` accepts a leading-dot manifest name, which `BundleDiscovery::scanContainer`'s glob never finds, so the bundle installs invisibly. The git arm keeps `.git` while the path arm strips it (docblock :1606-1612). Fix:
  - refuse names that start with `.`;
  - after a successful clone and manifest validation, remove `<scratch>/.git` with C-SEC-1's no-follow tree removal before the rename;
  - update the docblock.
- files.create: none
- files.modify: `candy-code/src/Cli/Subcommands.php` (`bundlesInstall`), `candy-code/lang/en.php` (`cli.bundles.install.*`), `candy-code/docs/PLUGINS.md` ("Installing and listing")
- tests: modify `candy-code/tests/Bundles/BundleInstallTest.php` (dot-name refusal; a cloned install has no `.git`).
- docs/guards: PLUGINS.md. No new spawn site.
- serialized: [headless] [docs:PLUGINS.md] [lang:cli.bundles.install.]
- deps: C-SEC-1 (same `bundlesInstall`/`copyBundleTree` region, and the removal must be the hardened one)
- phase: normal
- ruling: none
- risks: a manifest-less dot-named directory installed earlier stays invisible; this step only stops new ones.

