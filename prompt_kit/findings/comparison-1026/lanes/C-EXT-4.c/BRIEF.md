### C-EXT-4.c — `/<bundle>:<cmd>` namespacing
- item: C-EXT-4 (sources: GAP-CMD-10)
- kind: gap · effort S
- approach:
  - **Root cause.** `ForeignCommandDiscovery::bundleDirs()` (:143-155) and `walk()` (:217-229) key bundle rows by bare file name. Two bundles that both ship `review.md` collapse last-write-wins.
  - **Fix.** Bundle rows are keyed `<bundle>:<file>`. `TurnController::resolveCustomCommand()` (:404-437) tries the whole name before the colon-argument form, so `/<bundle>:<cmd>` resolves directly. The flat alias is kept only when no other row claims that name. The bundle name is threaded through `bundleDirs()` onto the spec.
- files.create: none
- files.modify: `candy-code/src/Commands/ForeignCommandDiscovery.php` (`bundleDirs`, `walk`), `candy-code/src/Commands/CommandLoader.php` (name stamp), `candy-code/docs/PLUGINS.md`, `candy-code/docs/COMMANDS.md`
- tests: create `candy-code/tests/Commands/BundleCommandNamespaceTest.php`. Modify `candy-code/tests/Commands/ForeignCommandDiscoveryTest.php`, `candy-code/tests/Bundles/BundleSurfaceWiringTest.php`.
- docs/guards: gen-command-docs if the table lists file rows.
- serialized: [docs:PLUGINS.md] [docs:COMMANDS.md] [hot:candy-code/src/Commands/ForeignCommandDiscovery.php]
- deps: C-SEC-3 (rewrites the bundle-tier provenance in the same method)
- phase: normal
- ruling: none
- risks: a user's muscle-memory flat names survive only while they are unclaimed.

