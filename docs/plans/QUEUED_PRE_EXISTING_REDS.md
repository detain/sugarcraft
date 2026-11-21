# Queued pre-existing reds (PD-fix Tier 4)

Corrected record of the four reds queued under the owner rule in commit
4f0cfe9d2. That commit message gave one suspected cause, "stale sibling vendor
(sugar-colorprofile missing from sugar-dashboard/vendor/sugarcraft)", which
matches only the first row below. The commit is not rewritten; this file is the
tracked list of the verified causes. Verified 2026-11-20 against the source and
vendor autoload files in this tree. No vendor directory was changed.

| Red | Verified cause | Fix kind |
| --- | --- | --- |
| sugar-dashboard `Foundation\ColorModeDetectDivergenceTest` (2 tests) | `sugar-dashboard/composer.json` requires `sugarcraft/sugar-colorprofile`, but `sugar-dashboard/vendor/sugarcraft/` has no link for it, so `ColorProfile::detect()` falls back to Ascii. | Vendor refresh of sugar-dashboard (`php scripts/refresh-deps.php --mode=linked`). |
| sugar-dashboard `Module\ModuleSpecConformanceTest::testGenericModuleRunsCommand` | Test bug, not vendor. `GenericModule::update()` returns `Cmd::batch($tick, $run)` on a tick, so `$cmd()` yields a sugar-core `BatchMsg`, which has no `promise` property. The test reads `$cmd()->promise`, gets null, and `Await::until()` throws a TypeError at `sugar-async/src/Await.php:42`. | Test change. A vendor refresh does not fix it. |
| sugar-style `DocumentationFidelityTest::testClicolorIsConsultedAndTheRosterSaysHowItRanks` | The `sugar-colorprofile` link exists and `vendor/composer/autoload_psr4.php` maps `SugarCraft\ColorProfile\`, but `vendor/composer/autoload_static.php` (loaded by `autoload_real.php`) has no such prefix. `class_exists()` returns false and `ColorProfile::detect()` returns Ascii. | Stale sugar-style autoload. Regenerating sugar-style's autoload is the likely fix (not yet run). A sugar-dashboard vendor refresh does not fix it. |

Items 2 and 3 stay red after a sugar-dashboard vendor refresh. They are not
closed by the stale-vendor follow-up that commit 4f0cfe9d2 describes.
