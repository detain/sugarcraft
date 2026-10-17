# rename-5-e10 NOTES — candy-sprinkles → sugar-style <!-- @rename-keep -->

Lane: e10 (batch-E line 43, ns Sprinkles→Style, type=lib, bins {}). Branch `rename/e10` off base `6709f44bd`, worktree `/home/sites/sugarcraft-e10`. NEVER push master; push branch only. No R2-remote, no R8/R9, NO packagist (brief law; weld/serial phase owns remotes). <!-- @rename-keep -->

## Entry facts (verified)

- tools/renames.json line 43: kind=rename, candy-sprinkles → **sugar-style**, lib, ns Sprinkles→Style, bins {}, no extra_literals/protect, status pending. Derived: pascal CandySprinkles→SugarStyle, compact candysprinkles, env CANDY_SPRINKLES_/CANDYSPRINKLES_ → SUGAR_STYLE_ (ZERO sites tree-wide — checked, honest none, no LegacyEnv). R6 Lang domain: candy-sprinkles/src/Lang.php NAMESPACE='sprinkles' → tool rewrites to 'style'; lang/en.php keys bare. <!-- @rename-keep -->
- Pre-existence census: `SugarCraft\Style` namespace ZERO files; no composer PSR-4 prefix claimant of `SugarCraft\Style\` (python census over */composer.json) — clean.
- Direct consumers (composer require): 24 libs — candy-codetime, candy-files, candy-flappy, candy-git, candy-log, candy-minesweeper, candy-scriptui, candy-sprinkles(self), candy-sql, candy-starter, candy-tetris, candy-top, sugar-charts, sugar-cli, sugar-crush, sugar-dashboard, sugar-forms, sugar-gallery, sugar-markdown, sugar-overlay, sugar-panels, sugar-quickpick, sugar-table, sugar-widgets (+ root composer.json). Widest E blast alongside vt (e7). <!-- @rename-keep -->
- Token census at base: 544 files (excl lib-self + ansi art) carry candy-sprinkles/CandySprinkles/candysprinkles/SugarCraft\Sprinkles shapes. <!-- @rename-keep -->

## HAZARD 1 — Spinner\Style / Style-word collision (plan Phase-7) — RULINGS

- sugar-widgets `src/Spinner/Style.php` = class_alias `SugarCraft\Forms\Spinner\Style` → `SugarCraft\Widgets\Spinner\Style`; AliasesTest row present. Distinct FQNs from new `SugarCraft\Style\Style` — no FQN/autoload collision (structurally proven above).
- Dual-import census (files importing Sprinkles\Style + another Style symbol): 4 PAIR files import `SugarCraft\Sprinkles\Style` UNaliased next to plural `Styles` classes (Log\Styles, Forms\TextInput\Styles, Widgets\Help\Styles, Widgets\Table\Styles) — Style≠Styles, no import-name collision, post-R1 stays fine. <!-- @rename-keep -->
- minesweeper/tetris/charts pair `SugarCraft\Sprinkles\Style as SprinklesStyle` with `SugarCraft\Buffer\Style` (unaliased or `as BufferStyle`) — e8 (buffer→CellBuffer) co-rename runs CONCURRENTLY in its own lane; my tree keeps Buffer ns intact; weld merges both. No cross-lane edit overlap (e10 never touches buffer ns lines). <!-- @rename-keep -->
- CONSUMER ALIAS RULINGS (brief-mandated `as *Sprinkles*` hunt): `SprinklesStyle`→**`SugarStyle`** (mechanical ns-word sub would yield `StyleStyle`, pathological — brand name is the tool's own R2 canonical rewrite of CandySprinkles); `SprinklesTheme`→`StyleTheme`; `SprinklesTable`→`StyleTable`; `SprinklesStatusBar`→`StyleStatusBar` (ns-word substitution, reads naturally, matches sibling `MarkdownTheme` convention in sugar-crush/src/Theme.php). Files: candy-minesweeper/src/Renderer.php, candy-tetris/src/Renderer.php(+RendererTest comment), sugar-charts/src/Buffer/BufferHelper.php(+test ~15 sites), candy-codetime Theme+test, sugar-crush src/Theme.php+SkillSourceTest+ShellContrastTest+TerminalBackgroundSprinklesAgreementTest+TerminalBackgroundTest comments, sugar-markdown/src/Renderer.php, sugar-dashboard StatusBar. <!-- @rename-keep -->
- KEPT per D1/C4 bare-symbol precedent + C.4 bare-word law: lowercase `$sprinkles`/`$sprinklesTheme` locals/params (bare word, NOT residue — matches no rule), test method/class names embedding bare ns-word: `TerminalBackgroundSprinklesAgreementTest` (file+class — KEPT; keeps scripts/parallel-tests-durations.tsv row stable = zero K=8 shard drift), `testInnerIsConsumedFromSprinkles`, `testStatusBarBytesAreProducedByTheSprinklesPrimitive`, `testWithColorProfileRendersExactlyAsSprinklesProfile`, `sprinklesFor()`, `step-11/12-sprinkles-*.md` filenames, prose "Sprinkles flex sizing" in .ansi art (content-excluded).
- RENAMED brand-joins (full brand inside identifier = R2-lookbehind-blind, D1 testDelegatesToCandyMosaic precedent): `testStylingProducedByCandySprinklesPassesTheGuard` → `...BySugarStyle...` (sugar-gallery/tests/AnsiGuardTest.php:214). <!-- @rename-keep -->

## HAZARD 2 — foundation blast

- Gate set: own suite EXACT vs base; sugar-widgets (collision machinery) + 2 UI consumers overlay/panels; extra collision evidence sugar-charts+forms+gallery(alias-test)+log+markdown+sql; crush K=8 sharded conservation; tools/tests 426/1484; path-repos/child-lifetimes/gen-docs --check/gen-ansi --check rc0.

## Self-lock law (rename-lib.php keepsItsName)

NEVER @rename-keep-tag ANYWHERE in: candy-sprinkles/** (all), findings/candy-sprinkles.md, findings/plan_candy-sprinkles.md, docs/research/libraries/candy-sprinkles-research.md, docs/repo_map/sugarcraft_candy-sprinkles.md, docs/repo_map/update_sugarcraft_candy-sprinkles.md, docs/_data/candy-sprinkles.{json,body.html}, docs/lib/candy-sprinkles.html, ansi/candy-sprinkles/**. README Formerly/Namespace notices added POST-move to sugar-style/README.md.

## Tag-prep set (history → @rename-keep, 34 untagged lines / 13 files)

prompt_kit/CONTEXT.md ×4, rename-baseline.md ×1, rename-4-B4/NOTES ×1, rename-5-C5/NOTES ×1, crush-dep-surface.txt ×1, crush_code_hardening_backlog ×8, worklog ×6, backlog_triage ×1, concurrency ×1, leftover/updates.md ×6, monorepo-audit-2026-05-27 ×4, improvements-2026-05 ×1. (D7/NOTES + resume_prompt already tagged.) JUDGED-REWRITE living: all code, consumer READMEs/CALIBER, docs/plans/leftover phase-*/step-*, repo_map/**, research/**, MATCHUPS/index.html/AGENTS/CLAUDE/README root, use_libs.txt, ansi.json+README (regen), docs/js/search-index.json, .vhs/.tape/.php glue, UPSTREAM_OPPORTUNITIES.md.

## Manual blindspots (pre-planned, verify post-R3)

- glued: `plan_candy-sprinkles.md` content pointers; `sugarcraft_candy-sprinkles.md` / `update_sugarcraft_candy-sprinkles.md` pointers in OTHER repo_map files (underscore lookbehind blocks R3 content; path self-rename handled by rewritePath relaxation). <!-- @rename-keep -->
- Cap-Hyphen: `CANDY-SPRINKLES` wordmark text in ansi.json description ×1 + ansi/README regen row — art content frozen; description describes literal wordmark bytes → KEPT (rewriting would lie while art is frozen); uppercase matches no rule → not residue. Disclose to rename-followups.md.
- .vhs post-`\n`: candy-scriptui/.vhs/filter.tape line 7 `CandySprinkles` preceded by literal `\n` (letter n blocks R2 lookbehind) → manual → `SugarStyle`. <!-- @rename-keep -->
- suffixed compound names: none beyond above (census: no candy_sprinkles underscore variants, no env prefixes).
- prompt_kit/tools/crush-dep-surface.php map row `'candy-sprinkles' => 'Sprinkles'` — KEY auto-R3, VALUE bare 'Sprinkles' = manual sweep (D1 'Mosaic'→'Image' precedent) → 'Style'… CAREFUL: value semantics = ns segment; SugarCraft\{Value}? verify consumer usage before flipping. <!-- @rename-keep -->

## Baselines (RE-MEASURED at base in this worktree, linked, plain pipe, -u LINES -u COLUMNS)

- PENDING (measured pre-rename commit; record exact figures + drift vs prompt_kit/briefs/rename-baseline.md catalog here).

## Steps

- (pending) tag-prep commit
- (pending) R1 dry-run / R3 tool / R4 manual / R5 wiring / R6 gates / R7 commits
- R2 remote rename: SKIPPED per brief law (no remote ops in lanes).
