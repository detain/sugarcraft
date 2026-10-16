# rename-5-C11 NOTES — candy-mouse → sugar-mouse <!-- @rename-keep -->

Lane: C11 (batch-C line 31, ns Mouse→Mouse UNCHANGED, type=lib, bins {}). candy-mouse absorbed candy-zone in step 1.1 (`SugarCraft\Mouse\Zones` subtree lives inside). Branch `rename/c11` off weld `4d472bc62`, worktree `/home/sites/sugarcraft-c11`. NEVER push master; push branch only. No R8/R9. <!-- @rename-keep lane record, verbatim vocabulary -->

## Derived vocabulary (rename-lib::derive, verified in source)
- R1 skipped (ns unchanged). R2 `CandyMouse`→`SugarMouse` (word-boundary; glued `TextSelectionCandyMouse…` invisible). R3 `candy-mouse`→`sugar-mouse`. R4 `candymouse`→`sugar-mouse` (zero occurrences tree-wide, verified). R5 env prefixes `CANDY_MOUSE_`/`CANDYMOUSE_`→`SUGAR_MOUSE_`. PROTECT: no other entry's old starts with `candy-mouse-`. <!-- @rename-keep lane record, verbatim vocabulary -->
- `candyzone` legacy wire tokens are NOT in this entry's vocabulary (compact of candy-zone) — the 1.1-era @rename-keep tags are safe from both tool and residue gate; verified present pre-staging (hazard 2 discharged).
- `CANDY_MOUSE_DEBUG` exists ONLY in historical repo_map proposal docs — never implemented in code (zero getenv in lib). Plan's "read both in code" shim is N/A: no read site exists. Deviation recorded. <!-- @rename-keep lane record, verbatim vocabulary -->
- Lang R6: NAMESPACE already `'mouse'` (Lang.php:20) — ns unchanged → rewrite is identity; verify post-R3 (restore-protect hazard 4).
- Glued manual sweep owed: `sugar-crush/tests/Tui/TextSelectionCandyMouseTest.php` (file+class+method `testRangeIsCandyMousesOwnSnapshot…`) + `scripts/parallel-tests-durations.tsv:138` row — invisible to R2 (letter-adjacent) and residue (same regex algebra). Will git mv + rename symbols + update durations row in-step. <!-- @rename-keep lane record, verbatim vocabulary -->
- Merge-entry law (plan Part C notes + :990): update batch-1 candy-zone merge entry `new` field → `sugar-mouse` in renames.json in this commit (sed targeted). <!-- @rename-keep historical provenance -->

## Procedure log

- R0 baseline: pending (worktree created; refresh-deps linked candy-mouse next). Baseline era figure per brief: 343T/916A = rename-baseline.md rows 204/500 + 139/416 (pre + zone-tests counted) — verify live. <!-- @rename-keep lane record, verbatim vocabulary -->
- Tag-prep: curated historical records tagged `@rename-keep` BEFORE R1 (baseline rows, CONTEXT.md, CHANGELOG, php74_branch table, crush_code worklog/RESUME/backlog, rename-1.1/2.2/2.3 NOTES, resume_prompt, crush-report impact, btop prs, monorepo-audit, improvements-2026-05, plan_crush_media, leftover/updates.md step row, crush-dep-surface.txt). Everything else under docs//findings//code/CI = living → tool rewrites (C4 precedent).

## Procedure log (final)
- R0 baseline: candy-mouse suite 343T/916A exact (timeout 600, cwd=lib, linked). <!-- @rename-keep -->
- R1 dry-run: 161 files, 9 paths; scrutinized; second pass rc=0. <!-- @rename-keep -->
- R2 rename-remote.sh candy-mouse sugar-mouse rc=0 -> github.com/sugarcraft/sugar-mouse. <!-- @rename-keep -->
- R3 rename-lib.php tool rc=0; php -l all pass; Lang.php NAMESPACE='mouse' intact (hazard4 CLEAR).
- R3 extras: suffixed variants escaped the template path rule -> manual git mv: docs/repo_map/{sugarcraft,update_sugarcraft}_candy-mouse-zones.md + research/libraries/candy-mouse-zones-research.md to sugar- spellings. <!-- @rename-keep -->
- R3 glued-pointer fixes: findings plan_candy-mouse*.md pointers, CALIBER_LEARNINGS, sugar-crumbs repo_map, update- zones sibling pointers -> sugar-mouse spellings (targets exist). <!-- @rename-keep -->
- R3 glued crush test: TextSelectionCandyMouseTest.php -> TextSelectionSugarMouseTest.php (class+method+durations.tsv row). <!-- @rename-keep -->
- R3 PROJECT_NAMES.md:157 etymology flipped to retired-wordplay note (minesweeper precedent).
- R4: README Formerly notice (tagged); keywords mouse/bubblezone kept; no bins/env/data-paths -> no shims. CANDY_MOUSE_DEBUG never implemented in code -> dual-read getenv shim N/A (deviation, R5 rewrote its 4 doc mentions). <!-- @rename-keep -->
- R5: root composer update sugarcraft/sugar-mouse --no-install (lock diff pure sugarcraft); refresh-deps linked x7 (sugar-mouse + crush/minesweeper/bits/crumbs/veil/gallery) all ok; gen-docs + --check ok; gen-ansi --check ok.
- R5 note: docs/lib/candy-zone.html stub lagged until batch-C status flip (resolve_final_slug skips pending) -> regenerated post-R7 to sugar-mouse.html; old-page stub count 17->18. <!-- @rename-keep historical provenance -->
- R6 suites EXACT vs baseline: sugar-mouse 343/916, bits 570/1246, crumbs 144/260, gallery 264/1162, veil 258/561, minesweeper 180/820/1F+4W (pre-existing catalog #1).
- R6 crush: sharded K=8 vs suite-figure 22564/423652/2S CONSERVATION PASS on committed tip; tools/tests + child-lifetimes + path-repos + five-guard per logs below.
- R7: renames.json L31 status->renamed; L60 batch-1 merge entry new bumped to sugar-mouse (plan :990 law). <!-- @rename-keep -->

## Gate evidence (post-commit tip) <!-- @rename-keep -->
- residue --old=candy-mouse: 0 findings, rc=0 (re-verified after NOTES self-tagging; R5 line was caught + tagged). <!-- @rename-keep -->
- lib/consumer suites EXACT (timeout 600, cwd=lib, linked): sugar-mouse 343/916; bits 570/1246; crumbs 144/260; gallery 264/1162; veil 258/561; minesweeper 180/820/1F+4W = pre-existing catalog #1.
- crush sharded K=8 vs suite-figure 22564/423652/2S: tests=+0 EXACT (renamed TextSelection*Test row kept shards honest), assertions +118 tolerated wobble, skipped=2 exact, errors=0; CONSERVATION reported FAIL solely for failures 1-vs-0 = Media/ReelDependencyTest.php (49:shell_exec -> inherited) in ChildStderrCaptureTest:1161 — PRE-EXISTING WELD RED: census roster + both files byte-identical base..tip; physically reproduced at 4d472bc62 in a scratch worktree (same 1F :1161). Introduced by 5254bdbd2 (2026-10-15) which postdates the 65ce28fb4 roster cure (10-13) and the suite-figure era (10-14); matches recorded CI red family "crush Doctor/Reel injection-blind". NOT chased.
- tools/tests 426T/1484A OK (via sugar-crush/vendor/bin/phpunit --bootstrap tools/tests/_bootstrap.php; candy-core vendor absent in worktree).
- child-lifetimes rc=0; path-repos --no-lib-path-repos rc=0; gen-docs --check ok (79 generated, 18 stubs incl. regenerated candy-mouse->sugar-mouse + candy-zone->sugar-mouse chain); gen-ansi --check ok. <!-- @rename-keep -->