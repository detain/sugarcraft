# rename-5-C1 — sugar-boxer → sugar-panels (Procedure R) <!-- @rename-keep -->

State file, updated incrementally per step. History lines mentioning old vocabulary carry `@rename-keep` as written. <!-- @rename-keep -->

## Entry facts (verified)

- tools/renames.json line 21: kind=rename, sugar-boxer → sugar-panels, lib, ns Boxer→Panels, bins {} , no extra_literals. Only renames.json line mentioning boxer. <!-- @rename-keep -->
- Derived (B.4 algebra): pascal `SugarBoxer`→`SugarPanels`, compact `sugarboxer` (zero sites), env prefixes SUGAR_BOXER_/BOXER_ — ZERO sites tree-wide → NO LegacyEnv (honest none, lib has no bin anyway). <!-- @rename-keep -->
- Baseline (rename-baseline.md:68): **201T/456A/0F/0E/0S/0W**. <!-- @rename-keep -->
- Upstream: `treilik/bubbleboxer` (composer description + README:175 design antecedent) — KEPT (B10 law: historical upstream citation; `bubbleboxer` is one token, invisible to all rules). <!-- @rename-keep -->

## Recon map

- CONSUMERS: ZERO. `grep '"sugarcraft/sugar-boxer"' */composer.json` → only the lib itself. No PHP file outside sugar-boxer/ uses `SugarCraft\Boxer`. Root composer.json require :79 + repositories :366 → tool-rewritten (R3). No consumer suites owed. <!-- @rename-keep -->
- ns COLLISION: `SugarCraft\Panels` tree-wide → ZERO matches pre-run. `ls -d *panels*` empty. PROTECT list empty.
- **DASH-INTERNAL `Boxer` (hazard):** `sugar-dash/src/Layout/Boxer/Boxer.php` = `SugarCraft\Dash\Layout\Boxer\Boxer`, unrelated dash widget (address-tree layout). NOT in vocabulary: R1 needs `SugarCraft\` adjacency, R2 pattern is derived brand `SugarBoxer`, bare lowercase `boxer` is C.4-forbidden, paths `Boxer/`, `boxer.php/.tape/.gif/.golden` + `tests/Layout/BoxerTest.php` all invisible to C.2 (segment ≠ SugarBoxer/sugar-boxer). docs/_data/sugar-dash.body.html `<h3>Boxer</h3>` tile + docs/dev/sugar-dash.md mention the dash widget → KEPT untouched. Generic-word audit SURVIVOR (legit). <!-- @rename-keep -->
- GENERIC-WORD census (tree-wide `Boxer`/`boxer` minus lib, minus bubbleboxer): PROJECT_NAMES.md:55 `Boxer` ns-segment cheat-sheet (manual fix → `Panels`, living doc), root CALIBER_LEARNINGS.md:9 `§3.4 Boxer` (historical section cite, invisible), docs/plans/leftover/step-01-boxer-*/step-23-boxer-* filenames (bare lowercase, invisible, historical), candy-layout/tests/GreedySolverMinShareTest.php:39,:71 `SugarBoxer::distribute()` cites → AUTO R2 (correct — names our class), .claude/skills/add-library-checklist/SKILL.md derivation example → AUTO (living doc, stays true). No boxer-shorts/dog/engine prose found. <!-- @rename-keep -->
- Lib-internal bare tokens: `$boxer` vars + README:25 "boxer computes" shorthand → MANUAL R4 cleanup (examples + README prose, cosmetic, output-identical). src/ zero bare occurrences. tests/ zero bare occurrences.
- Lang: NAMESPACE='boxer' → R6 → 'panels'. No test pins the domain string. lang/en.php header comment auto-R3.
- ansi/: NO sugar-boxer wordmark art → R4.9 honest none. <!-- @rename-keep -->
- .vhs: basic/borders/nested tapes (header path cites auto-R3; committed GIFs keep old pixels — followups note, CI re-renders).
- vhs.yml :154/:225 + codecov.yml ×4 rows + root README.md:63 + MATCHUPS row (already under ## Libraries — R4.8 moot) → all AUTO.
- Glued sweeps: `_sugar-boxer`/`_boxer`/`Sugar-boxer` → verify zero after R3 (residue covers). <!-- @rename-keep -->
- findings/sugar-boxer.md + plan_sugar-boxer.md → tool renames `<slug>.md`; plan_<slug>.md ALSO renamed by tool now (dry-run path list confirms — earlier lanes’ lane-mv gap cured). <!-- @rename-keep -->

## Steps

- R0 tag-prep: CONTEXT.md :918,919,979,992,993,994,999,1000,1008 + rename-baseline.md:68 tagged.
- R1 dry-run: clean (75 files/13 paths/R1=25 R2=180 R3=320 R6=1). Tool NOW also renames plan_<slug>.md (B-lane gap cured — dry-run path list).
- R2 remote rename: done — repo now sugarcraft/sugar-panels, verified record printed (description/homepage/topics synced).
- R3 tool run: @ done rc=0 — 74 files/13 paths, R1=25 R2=179 R3=318 R6=1 (dry-run delta = my tagged NOTES lines). Lint clean, zero sugar-dash touches (Boxer widget untouched), residue 0 already. <!-- @rename-keep historical provenance -->
- R4 manual: formerly+ns notices in README; $boxer->panels in README/examples/_data-body (user-facing only; internal test props kept); README:25 + SugarPanels.php:44 + test:687 brand shorthand reworded; Lang docblock truth-flip; PROJECT_NAMES:55 Boxer->Panels; composer desc/keywords honest no-op (upstream bubbleboxer kept); no bins/env/data-paths/Lang-pins (honest none); icon prompt truthful (B12 law).
- R5 wiring: root composer require+repositories already auto-R3'd; `composer update sugarcraft/sugar-panels --no-install` locked; refresh-deps --mode=linked (own-dir anchored) -> 7 symlinked siblings; gen-docs + --check OK (78 files); gen-ansi-readme --check OK.
- R6 gates: residue 0 findings rc=0 (twice); sugar-panels suite EXACT baseline 201T/456A/0F/0E/0S (2x stable, plain pipe); tools/tests floor EXACT 426T/1484A; path-repos rc=0; child-lifetimes rc=0; consumers: none (grep */composer.json -> only sugar-panels itself); sugar-crush filter N/A zero refs.
- R7 commit: renames.json:21 pending->renamed (compact sed, JSON valid, numstat 1/1); gen-docs re-run minted docs/lib/sugar-boxer.html redirect stub <!-- @rename-keep --> (18th, generator self-tags @rename-keep); --check pair green; final residue rc=0 at status=renamed; followups C1 GIF-pixels entry added.