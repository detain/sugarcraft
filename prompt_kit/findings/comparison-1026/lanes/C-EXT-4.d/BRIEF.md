### C-EXT-4.d — Contextual bundle recommendations at launch
- item: C-EXT-4 (sources: CC-new#12 plugin recommendations)
- kind: gap · effort S
- approach: a new `Bootstrap::reportBundleRecommendations()` in the `chat()` launch drain block (~:1524) emits ONE aggregate row from `BundleRecommender`, for example "bundles matching this project: X@Y — candy-code bundles install X@Y". It obeys these limits:
  - silent when no marketplace has been added;
  - shown once per project per marketplace pin, using a stamp in `marketplaces.json`;
  - offline: it never touches the network at launch.
- files.create: none
- files.modify: `candy-code/src/Cli/Bootstrap.php` (`chat()` drain block, new `reportBundleRecommendations`, the launch-cap census map at ~:290-330), `candy-code/lang/en.php` (`bundles.recommend.*`)
- tests: create `candy-code/tests/Cli/BundleRecommendationNoticeTest.php`. Modify `candy-code/tests/Cli/BootstrapLaunchNoticeCapCensusTest.php`.
- docs/guards: the cap census row (1); PLUGINS.md mention is reported for the C-EXT-4.b owner or the weld.
- serialized: [bootstrap:chat() launch drain, reportBundleRecommendations (new), launch-cap census map] [lang:bundles.recommend.]
- deps: C-EXT-4.a, C-EXT-4.b
- phase: normal
- ruling: none
- risks: a stat walk over the root on launch must stay bounded, with a fixed file list.

