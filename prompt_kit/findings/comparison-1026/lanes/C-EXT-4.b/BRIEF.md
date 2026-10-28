# BRIEF — C-EXT-4.b (wave 16)

## `bundles marketplace add/list/remove/update`, `install name@mkt`, `update`, `search`, `recommend`
- area: ext · kind: gap · effort M · effort:  · phase: normal
- item: C-EXT-4 (sources: GAP-PLG-02, GAP-SKL-09)
- deps: C-EXT-4.a, C-EXT-7.b, C-SEC-1, C-SEC-3
- serialized: [headless] [docs:PLUGINS.md] [docs:README.md] [lang:cli.bundles.]
- ruling: none

## Files to create
none

## Files to modify
`candy-code/src/Cli/Subcommands.php` (`bundles`, new verb methods, completion roster), `candy-code/lang/en.php` (`cli.bundles.*`), `candy-code/docs/PLUGINS.md` ("Installing and listing" → a Marketplaces section), `candy-code/README.md` (:97 row, the :2131 CLI block)

## Tests
create `candy-code/tests/Bundles/BundleMarketplaceCliTest.php` (with the clone seam `$bundlesInstallSpawn`), `candy-code/tests/Bundles/BundleUpdateTest.php`. Modify `candy-code/tests/Bundles/BundleInstallTest.php`.

## Docs / guards
`ParsedArgs::SUBCOMMAND_FLAGS` if a flag is added; a DocFigure subcommand fence if one pins `bundles`.

## Approach
`Subcommands::bundles()` (:1464) gains these verbs:
- **`marketplace add <git-url|path>`.** Clones with the EXISTING `bundlesCloneDefault()` spawn into a scratch dir, validates, pins the SHA, strips `.git` and renames into place.
- **`marketplace list`** and **`marketplace remove`**.
- **`marketplace update [<name>]`.** Re-clones, prints added and removed entries, and moves the pin.
- **`install <plugin>@<marketplace>`.** A relative source copies through C-SEC-1's hardened `copyBundleTree`; a git source uses the existing clone arm. It writes a `BundleSource` record.
- **`update [<bundle>]`.** Re-installs from the recorded `BundleSource` into a scratch dir, then swaps: the old copy moves aside and is removed with no-follow.
- **`search <q>`** and **`recommend`**, with text or `--output-format json`.
- The step also updates the completion roster (`Subcommands.php:2586`) and `cli.bundles.usage`.
- There is no new spawn site, so the child-lifetime roster does not change. Verify that.

## Risks
the update swap must never follow links. It depends on C-SEC-1's removal.
