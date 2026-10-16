# Rename lane C5 — candy-hermit → sugar-quickpick <!-- @rename-keep -->

Entry: tools/renames.json batch-C line 25. ns Hermit→QuickPick, type **lib** (prefix flip app-family→component-family), bins {} — composer.json VERIFIED no `bin`. Branch `rename/c5` @ 4d472bc62, worktree /home/sites/sugarcraft-c5. Baseline (rename-baseline.md:40): **159T/601A/0F/0E/0S rc0**. <!-- @rename-keep -->

## Derived vocabulary
- R1 `SugarCraft(\+)Hermit\b` → `SugarCraft\QuickPick` (single+double-backslash spellings handled by tool).
- R2 pascal: `CandyHermit` → `SugarQuickpick` (lowercase-p brand — A3 precedent: brand SugarDatepicker vs FQN SugarCraft\DatePicker; asymmetry approved).
- R3 `candy-hermit` → `sugar-quickpick`; R4 `candyhermit` → none expected (verify dry-run).
- Env: ZERO `getenv/ENV` reads in the lib — CANDY_HERMIT_*/HERMIT_* honestly none → NO LegacyEnv shim (B12 precedent).
- i18n: no src/Lang.php → R6 lang-key sweep N/A (verify: `lang/` absent).
- No bins → no bin/ renames, no BIN literal rewrites.
- Collision gate PASSED: repo-wide grep — zero existing `QuickPick` classes/FQNs outside plan+renames.json (candy-forms has none). No STOP.

## Lane-manual (tool cannot do) — FIRST bare-ns_old-class case
The lib's main class is bare `Hermit` (`src/Hermit.php`, `SugarCraft\Hermit\Hermit`) — tool rules rewrite only the namespace segment, leaving `SugarCraft\QuickPick\Hermit`. Precedents candy-mail/candy-sql/candy-kv/candy-codeshot all had multi-letter class names already; A3's DatePicker class pre-existed. So:
- `git mv candy-hermit/src/Hermit.php` (post-dir-rename `sugar-quickpick/`) → `src/QuickPick.php`. <!-- @rename-keep -->
- sed case-sensitive `(?<![A-Za-z0-9_])Hermit(?![a-z0-9])` → `QuickPick` **confined to the lib dir** (src/tests/examples/README/.vhs tapes/CALIBER_LEARNINGS): class `Hermit`, test files HermitTest/HermitRankerTest/HermitSigwinchTest/HermitFilterUtf8Test renamed likewise. Lowercase `$hermit` vars + `/tmp/hermit_history.jsonl` data file stay (B10 var-name precedent). <!-- @rename-keep -->
- `docs/_data/candy-hermit.body.html` — bare Hermit prose in LIVE site source → rename file + manual sed same vocab. <!-- @rename-keep -->
- PROJECT_NAMES.md: DELETE weak-table row `**CandyHermit** … doesn't say fuzzy-find` (:176) + ADD Strong-table row **SugarQuickpick** (B7 CandyKv precedent: was-CandyHermit rationale, @rename-keep tag); :208 `(CandyHermit alternative)` auto-rides R2.
- Historical docs (findings/hermit audit, docs/repo_map, docs/research, docs/plans/leftover, CONTEXT.md, ansi art): **NO bare sed of bare-`Hermit`** — upstream prose "The Hermit is a Go library" would corrupt. Slug tokens (`candy-hermit`) ride the tool (R3); bare `Hermit` there is not residue (residue tool uses same algebra). <!-- @rename-keep -->

## Consumers
Zero libs require the package; only root composer.json require+repositories lines (tool-rewritten) + phpunit.xml suite + codecov.yml + vhs.yml ×2 + media/icons path + docs tiles. Cross-lib COMMENT mentions (sugar-crush Renderer.php:2004 + RendererTest.php:357, candy-core AtomicJsonFile.php:9, candy-ssh-launcher Picker.php:97, candy-sprinkles Bar/*, candy-ansi CALIBER_LEARNINGS, fuzzy docs) auto-rewrite via R3 — gate affected filters in R6. <!-- @rename-keep -->

## Progress log
- [x] Recon + collision census + precedent reads (B7 9d15c247d, B12 a352e4b8f PROJECT NAMES shape).
- [ ] R0 tag-prep: CONTEXT.md :941/:970 + rename-baseline.md:40 `@rename-keep` tags.
- [ ] R1 dry-run review (expect findings: dir rename list, per-rule hit counts; check plan_candy-hermit.md + repo_map glued file mv, search-index.json). <!-- @rename-keep -->
- [ ] R2 rename-remote.sh candy-hermit sugar-quickpick. <!-- @rename-keep -->
- [ ] R3 tool --apply; git mv misses handled manually (B12 law: tool may not mv plan_<slug>.md / docs/repo_map/sugarcraft_<old>.md). <!-- @rename-keep -->
- [ ] R4 lane-manual set (class rename sed, PROJECT_NAMES rows, formerly-notice, glued sweeps `_hermit`/`_candy-hermit`, cap-hyphen `Candy-Hermit`). <!-- @rename-keep -->
- [ ] R5 root composer + `composer update sugarcraft/sugar-quickpick --no-install` + refresh-deps linked (lib + sugar-crush consumers) + gen-docs + --check + gen-ansi --check.
- [ ] R6 residue 0 → suites (lib 159/601 exact; tools/tests 426/1484; crush targeted filters for comment-rewrite files; path-repos + child-lifetimes rc0).
- [ ] R7 commit + push rename/c5 only. NO R8/R9 (no packagist create, no CI watch — end-of-campaign batch owed).
