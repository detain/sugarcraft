# rename-5-C11 NOTES — candy-mouse → sugar-mouse <!-- @rename-keep -->

Lane: C11 (batch-C line 31, ns Mouse→Mouse UNCHANGED, type=lib, bins {}). candy-mouse absorbed candy-zone in step 1.1 (`SugarCraft\Mouse\Zones` subtree lives inside). Branch `rename/c11` off weld `4d472bc62`, worktree `/home/sites/sugarcraft-c11`. NEVER push master; push branch only. No R8/R9. <!-- @rename-keep lane record, verbatim vocabulary -->

## Derived vocabulary (rename-lib::derive, verified in source)
- R1 skipped (ns unchanged). R2 `CandyMouse`→`SugarMouse` (word-boundary; glued `TextSelectionCandyMouse…` invisible). R3 `candy-mouse`→`sugar-mouse`. R4 `candymouse`→`sugar-mouse` (zero occurrences tree-wide, verified). R5 env prefixes `CANDY_MOUSE_`/`CANDYMOUSE_`→`SUGAR_MOUSE_`. PROTECT: no other entry's old starts with `candy-mouse-`. <!-- @rename-keep lane record, verbatim vocabulary -->
- `candyzone` legacy wire tokens are NOT in this entry's vocabulary (compact of candy-zone) — the 1.1-era @rename-keep tags are safe from both tool and residue gate; verified present pre-staging (hazard 2 discharged).
- `CANDY_MOUSE_DEBUG` exists ONLY in historical repo_map proposal docs — never implemented in code (zero getenv in lib). Plan's "read both in code" shim is N/A: no read site exists. Deviation recorded. <!-- @rename-keep lane record, verbatim vocabulary -->
- Lang R6: NAMESPACE already `'mouse'` (Lang.php:20) — ns unchanged → rewrite is identity; verify post-R3 (restore-protect hazard 4).
- Glued manual sweep owed: `sugar-crush/tests/Tui/TextSelectionCandyMouseTest.php` (file+class+method `testRangeIsCandyMousesOwnSnapshot…`) + `scripts/parallel-tests-durations.tsv:138` row — invisible to R2 (letter-adjacent) and residue (same regex algebra). Will git mv + rename symbols + update durations row in-step. <!-- @rename-keep lane record, verbatim vocabulary -->
- Merge-entry law (plan Part C notes + :990): update batch-1 candy-zone merge entry `new` field → `sugar-mouse` in renames.json in this commit (sed targeted).

## Procedure log

- R0 baseline: pending (worktree created; refresh-deps linked candy-mouse next). Baseline era figure per brief: 343T/916A = rename-baseline.md rows 204/500 + 139/416 (pre + zone-tests counted) — verify live. <!-- @rename-keep lane record, verbatim vocabulary -->
- Tag-prep: curated historical records tagged `@rename-keep` BEFORE R1 (baseline rows, CONTEXT.md, CHANGELOG, php74_branch table, crush_code worklog/RESUME/backlog, rename-1.1/2.2/2.3 NOTES, resume_prompt, crush-report impact, btop prs, monorepo-audit, improvements-2026-05, plan_crush_media, leftover/updates.md step row, crush-dep-surface.txt). Everything else under docs//findings//code/CI = living → tool rewrites (C4 precedent).
