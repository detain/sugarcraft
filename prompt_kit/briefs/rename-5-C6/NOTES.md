# rename-5-C6 — candy-lister → sugar-treelist (lib, ns Lister→TreeList, bins {}) <!-- @rename-keep -->

State file, updated incrementally per step. History lines mentioning old vocabulary carry `@rename-keep` as written. <!-- @rename-keep -->

## Verdict
See ``## Verdict (R7 close)`` below.

## Entry facts (verified)

- tools/renames.json line 26: kind=rename, candy-lister → sugar-treelist, lib, ns Lister→TreeList, bins {} , no extra_literals, no protect. <!-- @rename-keep -->
- Derived (RenameLib::derive): pascal CandyLister→SugarTreelist, compact candylister→sugartreelist (zero sites), env CANDY_LISTER_/CANDYLISTER_→SUGAR_TREELIST_ (zero sites), PROTECT auto = none. <!-- @rename-keep -->
- Baseline suite (rename-baseline.md:44, now tagged): **162T/386A/0F/0E/0S/rc0**. tools/tests floor **426T/1484A**. <!-- @rename-keep -->

## Recon map

- FACADE law shape: `src/ScoringProfile.php` is a class_alias re-export of `SugarCraft\Fuzzy\ScoringProfile`; tests carry ONE AliasResolutionTest + genuine lib-local suites (Model/Filter/FuzzyMatch-delegating shim…). Baseline 162T rides the rename EXACT. <!-- @rename-keep -->
- CONSUMERS of `SugarCraft\Lister\`: ZERO outside the lib (grep). `sugarcraft/candy-lister` requires: root composer.json (require+repositories) only. Cross-lib mentions are doc-comment prose: candy-fuzzy src×1 + README/tests/CALIBER, candy-buffer Cell docblocks, sugar-stickers test docblock, hermit/textual repo_maps — all tool-rewritten, comment-only ⇒ their suites unmovable-by-semantics; disclosed not re-run (new worktree has no vendor for them; zero executable lines changed). <!-- @rename-keep -->
- NEW-SIDE collision `TreeList`: grep `SugarCraft\.*\TreeList` / `class TreeList` → ZERO. Substring hits `gitWorktreeList`/`worktreeList` in sugar-crush are Worktree+List method names — no segment collision, no rule reaches them (rules only ever rewrite OLD vocabulary; future lanes' PROTECT concern, not C6).
- GENERIC word `lister` survivors (legit, KEPT): candy-files own `DirectoryLister`/`$lister` property + tests; sugar-crush StreamingDirectoryListerTest + `sugarcrush_lister_test_` tempdir; sugar-crush BaseSystemPromptTest fixture skill named `'lister'` ("Lists things."); `docs/plans/leftover/**/step-10-lister-filter-fuzzymatch.md` FILENAME (bare word, no rule); upstream `bubblelister` name everywhere (R2–R4 cannot match it — keywords/links preserved per R4.2).
- ENV: zero CANDY_LISTER_*/LISTER_* sites tree-wide → no LegacyEnv (lib has no bin; R4.5 app-only) — honest none.
- DATA paths: lib → R4.4 N/A. Bins: none. ansi art: no ansi/candy-lister/ dir; ansi/sugarcraft/*.ansi wordmark bytes mention lister — content-excluded (C.3) → followups note. <!-- @rename-keep -->
- LANG: R6 'lister'→'treelist' domain; keys `list.*` domain-neutral survive (B10 pattern); LangTest:35 expected-raw-key 'lister.nonexistent.key' → MANUAL → 'treelist.nonexistent.key' (R4.6); Lang.php:13 docblock `'lister' namespace baked in` → truth-flipped to 'treelist' (word-form invisible to all rules).
- CAPITAL-HYPHEN `Candy-lister`: zero sites. GLUED `_candy-lister`: `plan_candy-lister.md` cites ×8 (findings_resume_plan 7+1 backtick-bare, plan.md ×1) + `sugarcraft_candy-lister.md` pointer ×1 (update_sugarcraft_sugar-treelist.md:245) → manual sweep, targets verified to exist post-rename. (Tool DID rename findings/plan_candy-lister.md path this era — B7/B8-era defect cured.)
- MATCHUPS row :63 already under `## Libraries` (type=lib) → R4.8 moot; mapping row :166 (Inspirational reference projects) auto-R2/R3.
- vhs.yml :152 all= + :225 matrix → auto; YAML re-verified. codecov.yml ×6 → auto. .vhs tapes comment lines auto; committed GIFs keep old pixels (regen loop, B12 precedent).
- Historical files auto-rewritten per batch-B precedent (monorepo-audit/backlog/leftover/resume_prompt carry rewritten old-lib names — verified batch-B did the same for sugar-tick: 0 residual 'sugar-tick' in those files). TAGGED instead (session work-logs): CONTEXT.md ×7 lines (926,927,942,987,995,997,1008) + rename-baseline.md:44 → tag-prep commit aa91ab70c BEFORE R1. <!-- @rename-keep historical provenance -->
- Root `scripts/__pycache__/generate_icons.cpython-313.pyc` is TRACKED and contains old-vocab bytes — binary content-excluded from tool AND residue (isContentExcluded NUL rule); stale-by-design vs the rewritten .py, batch-B precedent left siblings stale too. Disclosed.

## Steps

- R0 tag-prep @aa91ab70c: CONTEXT.md ×7 + baseline row tagged.
- R1 dry-run: 104 content files / 10 path renames / R1=62 R2=22 R3=248 R6=1; R4=0 R5=0 (no compact/env sites — matches recon); eye-review clean. NO STOP condition (ns≠ns and R1 non-zero).
- R2 `rename-remote.sh candy-lister sugar-treelist` rc=0: repo renamed, description/homepage verified via record (description upstream-name `treilik/bubblelister` intact). <!-- @rename-keep -->
- R3 tool rc=0 EXACT vs dry-run; `php -l` sweep over sugar-treelist/*.php clean (zero failures).
- R4 manual set: formerly+namespace notices (sugar-treelist/README.md under H1, B8/B10 format, tagged); `treelist` keyword added; LangTest domain fix; Lang.php docblock truth-flip; glued-cite sweep ×10; NO LegacyEnv/LegacyPaths (honest none); MATCHUPS section already correct.
- R5: `composer update sugarcraft/sugar-treelist --no-install` (lock: candy-lister removed, sugar-treelist locked); refresh-deps --mode=linked --libs=sugar-treelist,candy-core serial (linked=2, guard rc=0); gen-docs + --check OK; gen-ansi-readme --check OK (3595 pieces agree). <!-- @rename-keep -->
- R6 gates: residue --old=candy-lister → **0 findings**; sugar-treelist suite **162T/386A EXACT** rc0 (timeout 600, plain pipe, cwd=sugar-treelist); tools/tests **426T/1484A EXACT**; child-lifetimes rc=0; path-repos rc=0; vhs.yml YAML valid. <!-- @rename-keep -->
- R7: renames.json:26 pending→renamed (compact-form python replace, assert-1, JSON re-validated); gen-docs re-run mints 18th redirect stub docs/lib/candy-lister.html (self-tagged); final gen-docs --check OK. <!-- @rename-keep -->

## Verdict (R7 close)
DONE R1–R7 on branch rename/c6 (NOT pushed to master; no R8/R9 — weld owed). Figures: suite 162/386 EXACT · tools 426/1484 EXACT · residue 0 · path-repos/child-lifetimes/gen-docs/gen-ansi rc0. Deviations: none beyond disclosed map (stub count now 18; pyc stale-by-design; consumer suites comment-only not re-linked).