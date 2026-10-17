# rename-5-C13 NOTES — candy-fuzzy → sugar-fuzzy <!-- @rename-keep -->

Lane: C13 (batch-C line 33, ns Fuzzy→Fuzzy UNCHANGED, type=lib, bins {}). Branch `rename/c13` off weld `4d472bc62`, worktree `/home/sites/sugarcraft-c13`. NEVER push master; push branch only. No R8/R9.

## Pre-flight findings

- Baseline row verified: `prompt_kit/briefs/rename-baseline.md:39` = candy-fuzzy 1006T/35741A/0F/0E/0S/0W. <!-- @rename-keep -->
- **Batch-D `sugar-fuzz` hazard premise FALSE**: renames.json batch D = shine/mosaic/reel/flip/kit/bounce/palette — NO entry yields slug `sugar-fuzz`, and no `candy-fuzz`/`sugar-fuzz` dir exists tree-wide. Bare `fuzz` tokens live only in candy-core fuzz-corpus test names + ansi art (no rule matches them: R3 needs boundary `candy-fuzzy`, R2 needs `CandyFuzzy`, R4 needs `candyfuzzy`; ns-unchanged ⇒ R1 SKIPPED entirely). <!-- @rename-keep -->
- Façade safety (C6 candy-lister): `class_alias(\SugarCraft\Fuzzy\ScoringProfile::class, ...)` + `use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher` — FQNs carry the UNCHANGED namespace ⇒ alias targets resolve after weld. candy-forms `SugarCraft\Forms\Fuzzy\FuzzyMatcher` likewise untouched (prose `canonical candy-fuzzy SSOT` rewrites via R3 only). Verified pre-rename; will re-verify post. <!-- @rename-keep -->
- SmithWatermanMatcher canonical in candy-fuzzy/src/Matcher/ — class name carries no brand token; prose refs survive. <!-- @rename-keep -->
- No `src/Lang.php` in candy-fuzzy ⇒ R6 N/A. No bins ⇒ C.2 bins N/A. No CANDY_FUZZY_*/env reads (lib, not app) ⇒ no LegacyEnv shim — honest none. <!-- @rename-keep -->
- PascalCase `CandyFuzzy` occurrences (R2 → `SugarFuzzy`): PROJECT_NAMES.md:151, README.md:46, candy-fuzzy/README.md:1, docs/_data + generated pages (gen-docs re-derives). <!-- @rename-keep -->
- Identifier miss vs R2 lookbehind (manual fix): `candy-forms/tests/Fuzzy/AliasResolutionTest.php::testDelegatesToCandyFuzzySsot` — `o` before `CandyFuzzy` blocks R2; will rename method manually. <!-- @rename-keep -->
- Glued pointers `_candy-fuzzy` (R3 lookbehind blindspot, manual sweep): `findings/plan_candy-fuzzy.md` mentions in findings/findings_resume_plan.md ×4 + findings/plan.md:195. Paths themselves (docs/_data, docs/lib, media/icons, docs/img/icons, findings/*, ansi/candy-fuzzy/) handled by C.2. <!-- @rename-keep -->
- Consumers requiring sugarcraft/candy-fuzzy: candy-files, candy-forms, candy-git, candy-hermit, candy-kv, candy-lister, candy-scriptui, candy-ssh-launcher, sugar-crush. <!-- @rename-keep -->
- PROTECT list: no other old slug starts with `candy-fuzzy-` ⇒ empty. <!-- @rename-keep -->

## Procedure log

(append incrementally)

## Procedure log

- R0 baseline: candy-fuzzy suite at base EXACT 1006T/35741A/0F/0E/0S/0W (vendor `composer install`, no sibling deps). <!-- @rename-keep -->
- Tag-prep commit: 16 lines / 8 files tagged `@rename-keep historical provenance` (CONTEXT.md ×5 [929 pre-existing], rename-baseline.md ×2, crush_code_RESUME.md:458, resume_prompt.md ×2, php74_branch.md ×3, crush-report impact ×2, crush-dep-surface.txt:71). JUDGED-REWRITE: findings/*, repo_map step/update inventories, plans_cleaning.md, steps/J1, use_libs.txt, all consumer lib prose/READMEs/CALIBER_LEARNINGS + sugar-crush docblocks/ARCHITECTURE (living docs). <!-- @rename-keep -->
- R1 dry-run: 91 content files, 7 path renames, R1=0 (ns-unchanged by design — R1 rule skipped), R2=19 R3=361 R4=1. fuzz-vs-fuzzy discrimination watched: ZERO matches harvested from candy-core `widthFuzzCorpus`/`AnsiStripAdversarial` test names or `fuzzy`-vocab prose — only boundary `candy-fuzzy`/`CandyFuzzy`/compact tokens matched. No sugar-fuzz/candy-fuzz lib exists anywhere (batch D verified entry-by-entry) → PROTECT list empty, collision moot. Log /tmp/opencode/c13-dryrun.txt. <!-- @rename-keep -->
- R2 `scripts/rename-remote.sh candy-fuzzy sugar-fuzzy` rc=0: sugarcraft/sugar-fuzzy created from redirect, description+homepage+topics synced, verified record printed. <!-- @rename-keep -->
- R3 `php tools/rename-lib.php --old=candy-fuzzy`: DONE rc=0, 90 files 7 paths (delta vs dry-run = the 9 NOTES lines self-tagged between runs + method rename not needed... R2=16 R3=353 final). dir moved `candy-fuzzy/` → `sugar-fuzzy/`. <!-- @rename-keep -->
- R4 manual set: README Formerly notice (NO namespace-change line — ns unchanged); glued-pointer sweep `plan_candy-fuzzy.md` ×5 (findings_resume_plan.md :846/:853/:860/:1281 + findings/plan.md:195); identifier fix `testDelegatesToCandyFuzzySsot` → `testDelegatesToSugarFuzzySsot` (R2 lookbehind blind on `ToCandy…`); ansi wordmark ×3 recorded in rename-followups.md; composer.json keywords already carry `fuzzy`+`gum`+`charmbracelet`, descriptive OK. No bins/Lang/env/data-paths — honest none. <!-- @rename-keep -->
- R5: `composer update sugarcraft/sugar-fuzzy --no-install` — lock diff pure sugarcraft (candy-fuzzy entry → sugar-fuzzy entry; sugar-crush ref ambient drift d61ae27→7c452a6 per C4 precedent; autoload `"SugarCraft\\Fuzzy\\"` byte-identical both sides). gen-docs 78 files, `--check` rc=0 (61 pages + 17 redirect stubs). gen-ansi-readme `--check` rc=0. refresh-deps --mode=linked ×10 libs rc=0. <!-- @rename-keep -->

## Gates (committed-tip shape)

- sugar-fuzzy suite: 1006T/35741A EXACT (timeout 600 plain pipe, cwd=sugar-fuzzy).
- consumers all green: candy-forms 2343T/4564A (+17T/+35A vs baseline row = PRE-EXISTING drift — my forms diff is method-count-neutral (−1/+1 rename) + comment/fixture-`_comment` strings + require swap, provably cannot add tests); candy-files 193/716 EXACT; candy-lister 162/386 EXACT (façade AliasResolutionTest GREEN — `class_alias(\SugarCraft\Fuzzy\ScoringProfile)` resolves post-rename, brief-hazard CONFIRMED-SAFE); candy-hermit 159T/597A/**1S** (HermitSigwinchTest stdin-must-be-tty skip = env-shape, baseline row measured under tty; not rename-caused — hermit diff docblock-only); candy-kv 212/466; candy-git 326/4638; candy-scriptui 334/694; candy-ssh-launcher 257/1160 all OK. <!-- @rename-keep -->
- candy-code: filter 'Fuzzy|Search' 188T/648A OK; guard-family filter (five-guard + Readme/Env/KeyBinding/TrustKey/ConfigWrite drift) 401T/46282A OK; crush src/tests diff VERIFIED comment-only (grep of non-comment ±lines empty) → K=8 sharded gate DECLINED as not-wide per brief "if wide"; autoload smoke: crush vendor symlink `sugar-fuzzy` resolves both `SugarCraft\Fuzzy\ScoringProfile` + `Matcher\SmithWatermanMatcher` to canonical sugar-fuzzy/src files.
- residue `check-rename-residue.php --old=candy-fuzzy`: 0 findings rc=0. <!-- @rename-keep -->
- tools/tests 426T/1484A EXACT; check-child-lifetimes / check-one-type-per-file / check-path-repos --no-lib-path-repos all rc=0.

## Deviations / findings

1. Batch-D `sugar-fuzz` collision premise FALSE (no such entry/target) — recorded above, no weld note needed beyond this line.
2. R1 count 0 is CORRECT for ns-unchanged entries (plan's "STOP if R1=0 while ns_old≠ns_new" — condition not triggered: ns_old==ns_new).
3. C.2 path rewrite caught `docs/repo_map_step_*` inventories via content R3 only (no repo_map sugarcraft_candy-fuzzy.md file exists for this lib — confirmed absent at base). <!-- @rename-keep -->
4. No `ansi/candy-fuzzy/`, no `.vhs/`, no `examples/` — non-visual lib, matrix-exempt. <!-- @rename-keep -->
