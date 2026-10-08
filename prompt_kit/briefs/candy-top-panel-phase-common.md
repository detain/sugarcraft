# candy-top panel phases (P-B..P-E) — shared brief

Repo: /home/sites/sugarcraft (PHP 8.3 monorepo). Lib: candy-top/ (namespace SugarCraft\Top\). btop reference
source (ported from): /home/sites/btop (btop_draw.cpp, btop_input.cpp, btop_shared.cpp, linux/btop_collect.cpp).
Plan: /home/sites/sugarcraft/plan_top.md (§3 architecture, §4 phase table, Wave U section). Upstream-PR notes:
prompt_kit/findings/btop-upstream-prs.md. Conventions: AGENTS.md + candy-top/CALIBER_LEARNINGS.md (READ the
"panel seam" section first — it is the contract).

## Hard rules
- NEVER run git stash/checkout/reset/clean/commit/add. The supervisor commits. Other agents are editing other
  panels in the same working tree concurrently — touch ONLY your own files plus the shared-file edits below.
- Don't touch sugar-crush, candy-core, or App.php. A phase is: your own Panel file(s) under candy-top/src/Panel/
  (subdir per box is fine, e.g. src/Panel/Cpu/), your own view/helper files, your own Fake source updates under
  src/Source/Fake/ (only your box's fake), your own tests, and your own fixtures dir tests/fixtures/panels/<box>/.
- If App.php or the seam genuinely can't express something, STOP and report it rather than editing App.php.
- Shared files — edit with ONE small Edit call each, re-reading right before, anchored on your own unique line:
  * candy-top/src/Panel/Panels.php: replace ONLY your box's line(s) in standard() (NOT placeholders()).
  * candy-top/lang/en.php: add your strings in your own block `// ---- <box> panel (phase P-X) ----` appended
    just before the closing `];` (re-read first; another phase may have appended its block).
  * candy-top/CALIBER_LEARNINGS.md: append your own `## P-X <box> panel` section at the end.
  * candy-top/composer.json: only if you need a new sugarcraft/* require (e.g. sugar-charts); say so in report.
  * tests/Panel/PanelsTest.php: only adjust assertions about YOUR box's class.
- Do NOT touch tests/fixtures/frames/* or Panels::placeholders() (frozen chrome goldens).
- Library primitives to use (already shipped, see their READMEs): sugar-dash DualSampleGraph (incl. block2
  symbols, graph_bg underlay, invert), BrailleCanvas gradient, Meter (position gradient), NetAutoScale,
  GradientStore/ThemeGradientStore, DistanceFade, ProcRowComposer; candy-sprinkles Border::withTitle/junctions;
  sugar-bits TextEdit/OptionRow. Theme colors via candy-top/src/Theme. Render into the Region you get in paint().
- TEA purity: no file/clock reads in update()/paint(); sampling only inside collect() Cmds. Read options from
  PanelContext->config every call (never cache config). Option-changing keys return PanelResult $set (synchronous).
- #1008: on UNMEASURED (-1.0 / Sentinel) keep showing the last good value instead of n/a. #1858: hidden boxes
  never collect/paint (App already guarantees; don't break it). Clamp every graph width/height to >=1
  (DualSampleGraph throws <1) — test the tiny-box case.
- i18n: every user-visible string via Lang::t. strict_types, final classes, immutable with*/mutate, one type per
  file (php tools/check-one-type-per-file.php must pass).
- Tests: per-panel paint goldens (plain grid + at least one SGR golden) under tests/fixtures/panels/<box>/;
  goldens must FAIL when missing unless CANDY_TOP_UPDATE_GOLDENS=1 (same rule as FrameSnapshotTest); behaviour
  tests for every key; tiny-size and resize tests; fake sources deterministic.
- Byte fidelity: where a btop oracle exists (prompt_kit/tools/btop-{graph,netscale,theme}-oracle.cpp) reuse its
  findings; compare your layout with btop_draw.cpp line by line and cite lines in doc comments.
- Run `cd candy-top && vendor/bin/phpunit` before reporting. If a failure is clearly in ANOTHER phase's
  concurrently-edited files, mention it and move on. Also run php tools/check-one-type-per-file.php and
  php tools/check-path-repos.php --no-lib-path-repos from repo root.
- Optional live smoke: `php candy-top/bin/candy-top` (and --fake) in a tmux session at 120x40; kill it after.
- Report: files created/changed (exact list — the supervisor stages exactly these), what each btop feature maps
  to, test counts, deviations from btop, anything deferred.
