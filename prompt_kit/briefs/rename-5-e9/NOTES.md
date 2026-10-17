# rename-5-e9 NOTES — candy-layout → sugar-constraints <!-- @rename-keep -->

Lane: e9 (batch-E renames.json line 44, kind=rename, lib, ns Layout→Constraints WORD CHANGES, bins {}). Branch `rename/e9` off base `6709f44bd` (D R8/R9 closeout), worktree `/home/sites/sugarcraft-e9`. NEVER write master; push only rename/e9. No R2-remote/R8/R9 in-lane (brief law; serial post-weld phase owns remotes — batch-D precedent). <!-- @rename-keep -->

## Entry facts (verified)

- renames.json:44 confirmed: old candy-layout → new **sugar-constraints**, ns_old Layout, ns_new Constraints, type lib, bins {}, no extra_literals/protect. Derived: Pascal CandyLayout→SugarConstraints, compact candylayout → ZERO sites tree-wide (honest none); env CANDY_LAYOUT_/CANDYLAYOUT_ ZERO sites; NO src/Lang.php (honest none, no R6); no .vhs/ no examples/ → VHS-matrix-exempt (absent from vhs.yml ✓); no user data paths (lib, not app). <!-- @rename-keep -->
- Pre-existence census CLEAN: `SugarCraft\Constraints` 0 files, `SugarConstraints` 0, `sugar-constraints` only in plan_rename.md + renames.json (both tool-excluded).
- Consumers (composer requires sugarcraft/candy-layout): candy-sprinkles, candy-sql, sugar-crush, sugar-forms, sugar-panels (+ root repositories url entry; root require NO). **DEVIATION from brief: sugar-dashboard is NOT a consumer** (no require, no ns ref) — disk truth over brief guess; brief's "boxer-era" note applied to sugar-panels (correct).
- NO docs/repo_map/*candy-layout* files exist (unlike mosaic era) — repo_map step files carry only R1/R3 content hits (auto).
- Lib-internal class names KEPT per C4/D1 law (bare ns-word classes): `LayoutSolver`, `DockLayout`, `Constraint\*`, `Direction`, `Region`, `Expression`, `CassowarySolver` (deprecated→GreedySolver delegation seam per v2 findings erratum — plan text carries no Phase-7 bullet for layout, findings narrative is history, not rename instructions). Consumer `{@see LayoutSolver}` cites stay true. <!-- @rename-keep -->
- Baselines RE-MEASURED at base 6709f44bd (drift vs brief figures disclosed): **candy-layout 293T/3315A** (brief said ~157/882 — v2-era; rename-baseline.md:43 already carried 293/3315 EXACT — brief figure was stale, baseline row is authoritative); tools/tests 426T/1484A EXACT; sugar-panels 201/456; candy-sprinkles 865/2896; sugar-forms 2343/4564; candy-sql 1580/11900 (required ONE refresh-deps --mode=linked relink — copied master vendor carried pre-D-weld sugarcraft/syringe symlinks, `SugarCraft\Cli\Frame` not found at first run = stale-vendor Part-A-10, not a real failure); crush --filter 'Layout|Box|Flex' 189T/8865A (brief-era 80/6692 = suite growth drift, shape gate).
- Env fact: fresh worktree has NO vendor dirs; `cp -a` of master's per-lib vendor (same depth /home/sites/X) + targeted `refresh-deps --mode=linked --libs=` is sufficient — refresh-deps' "escaping=6 WANTED LINKED" line on candy-sql is a STATUS-DEFAULT mislabel (no per-lib want config; reads as PROBLEMS while actually relinking 26/26 ✓). <!-- @rename-keep -->

## Self-lock law (obeyed)

Zero `@rename-keep` sentinels pre-existing in candy-layout/**, findings/{candy-layout,plan_candy-layout}.md, docs/_data/candy-layout.*, docs/lib/candy-layout.html, media/icons (verified before tool run — path plan intact: findings pair renamed to sugar-constraints.md/plan_sugar-constraints.md ✓).

## Tag-prep ledger (commit 0e90abd0a — 17 lines / 9 files)

CONTEXT.md 916,917; rename-baseline.md:43; crush-dep-surface.txt:135; crush_code_hardening_backlog 20859; crush_code_worklog 6277,11619; steps/J1_COMPOSER_REPOS.md:31; plans_cleaning.md:18; php74_branch.md 358,377,526,541; sugar_issues.md 1142,1144,1792,2117 (audit-evidence lines w/ file:line cites = historical, D1 monorepo-audit precedent). B12/C10/C1 NOTES lines already carried tags ✓ untouched. JUDGED-REWRITE (tool rewrote): CLAUDE/README/PROJECT_NAMES/MATCHUPS/index, use_libs.txt (C10 law), plan_crush_media.md (D1 law), ALL repo_map* trackers incl supervisor/updates/followups/steps (D5 house precedent), findings pair, codecov.yml (flag+component ×6 lines), root composer url entry, all PHP. <!-- @rename-keep -->

## Steps

- R1 dry-run: 131 files/187 paths pre-tag → **122/187 (R1=207 R2=24 R3=1187) post-tag** — deltas = exactly the 17 tagged lines. Read: dir move, _data pair, icon pair, findings pair, ansi 187 path renames (content excluded), codecov/composer/CI flips all as predicted. rc=0. <!-- @rename-keep -->
- R3 tool run rc=0; 74 changed PHP php -l clean.
- R4 manual sweeps: (a) **glued pointer** `findings/plan_candy-layout.md` cite in sugar-constraints/src/Expression.php:21 (R3 `_`-lookbehind blind) → plan_sugar-constraints.md — ONLY glued/underscore site tree-wide (`_candy-layout`/`candy_layout`/`CANDY_LAYOUT` = 0 elsewhere); (b) **as-alias family sweep** (ns-word in identifiers): `as LayoutConstraint/LayoutDirection/LayoutRegion` → `as ConstraintsConstraint/ConstraintsDirection/ConstraintsRegion` + `toLayout→toConstraints`, `toLayoutConstraints→toConstraintsConstraints`, `fromLayoutRegions→fromConstraintsRegions`, test names `testToLayout*/testRoundTrip*Layout*` swept — files candy-sprinkles/src/Layout/{Solver,RegionBridge,Fill,Max,Min,Length,Percentage,Ratio}.php, tests/Layout/RegionBridgeTest.php, sugar-panels/src/SugarPanels.php (mechanical Layout→Constraints word swap, D1 ImageHalfBlockRenderer precedent; double-plural `ConstraintsConstraints` accepted over invented `Solver*` naming); (c) **Camel-join blind (head-side)**: `testDistributeMatchesCandyLayoutWithMinShare` → …SugarConstraints…; `testTheCandyLayoutQuickstart…` → testTheSugarConstraints… (R2 blocked by letter-before-brand — C13 ToCandyFuzzy precedent); (d) crush-dep-surface.php:28 map VALUE 'Layout'→'Constraints' (key auto); (e) README Formerly + Namespace-changed notices (sugar-panels house format). Generic prose `layout` words + `$layoutRegions` locals + `FlexLayoutTest`/`SugarCraft\Sprinkles\Layout` sub-ns + local `Layout` node-tree prose in panels = KEPT (belong to their own domains, not lib vocabulary). <!-- @rename-keep -->
- R5 wiring: renames.json status flip targeted sed (my line only) → `renamed`; gen-docs AFTER flip (99 files, stub minted docs/lib/candy-layout.html redirect + page docs/lib/sugar-constraints.html; --check 61 pages/38 stubs rc0); gen-ansi-readme regenerated (--check 3595 pieces agree); pruned 5 dangling `vendor/sugarcraft/candy-layout` symlinks + refresh-deps --mode=linked sugar-constraints,candy-sprinkles,sugar-panels,sugar-forms,candy-sql,sugar-crush → 6/6 ok. <!-- @rename-keep -->
- R6 gates (on committed tip af5a320e8): residue --old=candy-layout **0 findings**; sugar-constraints **293T/3315A EXACT**; tools/tests **426T/1484A EXACT** (after fix-commit af5a320e8 — see blindspot); panels 201/456 EXACT; sprinkles 865/2896 EXACT; forms 2343/4564 EXACT; sql 1580/11900 EXACT; crush --filter 'Layout|Box|Flex' 189T/8865A OK; path-repos rc0; child-lifetimes rc0; gen-docs --check rc0; gen-ansi --check rc0. Author law: %ae==%ce==detain@interserver.net ×3 commits, no trailers. <!-- @rename-keep -->

## NEW blindspot (campaign-level)

**Hardcoded ns-prefix STRLEN literals.** `tools/tests/DocsQuickstartTest.php:271` built its snippet autoloader with `substr($c, 18)` = strlen('SugarCraft\Layout\'). R1 rewrote the adjacent ns strings but a bare NUMBER cannot match any rule — class-not-found only at runtime of the generated temp script. Any ns-widening lane must grep consumer code for `substr(…, <N>)` / `strlen('<ns>')` / offset math next to rewritten ns strings. Recommendation: add to the per-lane manual-sweep checklist.

## Followups (not this lane)

- ansi/sugar-constraints/** art CONTENT (151 wordmark files carry `CandyLayout`/`candy-layout` bytes — C.3 excluded) → rename-followups.md per C.4/D1 law; one .ansi even embeds `SugarCraft\Layout\` + `sugarcraft/candy-layout` text.
- Observed at base: **sugar-image/README.md lacks its Formerly notice** (D1 claimed it; absent at 6709f44bd) — for D-weld/orchestrator awareness, not e9 fix.
- sugar-panels/.phpunit.cache stale old-method-name keys — untracked runtime artifact, self-heals.

## Commits

tag-prep `0e90abd0a` → rename `e5f9c506c` (307 files) → prefix-length fix `af5a320e8` → gate-evidence (this). Push: rename/e9 only.
