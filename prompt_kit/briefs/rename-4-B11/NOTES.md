# Rename B11: candy-freeze → candy-codeshot (Procedure R) <!-- @rename-keep -->

State file, updated incrementally per step. History lines mentioning old vocabulary carry `@rename-keep` as written. <!-- @rename-keep -->
Concurrent lane (b9/b10/b12 run in parallel worktrees; batch E hazard note: candy-ansi→sugar-ansi-parser must PROTECT candy-ansi-inspect — unaffected here).

## Entry facts (verified)

- tools/renames.json line 18: kind=rename, <!-- @rename-keep --> candy-freeze → candy-codeshot, app, ns Freeze→CodeShot, bins {"bin/candyfreeze":"bin/candy-codeshot"} (GLUED compact bin). No extra_literals, no compact_ident_new → derive: pascal CandyFreeze→CandyCodeshot, compact candyfreeze→candycodeshot, env old CANDY_FREEZE_/CANDYFREEZE_ → CANDY_CODESHOT_ (all no-ops, see ENV). <!-- @rename-keep -->
- Baseline (rename-baseline.md:38): 350T/752A/0F/0E/0S/0W — tagged @rename-keep in tag-prep.
- `CodeShot`/`codeshot` pre-existence: ZERO outside plan_rename.md/tools lines → collision clear.
- Sibling-prefix: `ls -d candy-freeze*` = only the lib <!-- @rename-keep -->. PROTECT auto-derivation inert. Reverse note: future candy-codeshot-* entries must PROTECT candy-codeshot.
- CONSUMERS: root composer.json only (require :51, repositories :156). NO lib requires sugarcraft/candy-freeze <!-- @rename-keep --> — sugar-dash does NOT depend on freeze (brief premise checked: dash's 2F baseline pair is dash-own, untouched by this lane).
- Docblock-only external mentions: candy-ansi/src/Parser/SubparamsAwareHandler.php:67,:93 + candy-ansi/tests/Support/PullSubparamsRecorder.php:12 + candy-ansi-inspect/src/Inspector.php:246 — all `SugarCraft\Freeze\SgrStateHandler` cross-refs, comment-only → mechanical R1; run candy-ansi + candy-ansi-inspect suites at R6. <!-- @rename-keep -->
- ENV: ZERO getenv/putenv/ENV_PREFIX in src+bin; CANDY_FREEZE_/CANDYFREEZE_ zero tree-wide → LegacyEnv honest none; R5 rule count expected 0. <!-- @rename-keep -->
- DATA PATHS: zero XDG/.config/.local/.cache/HOME derivation in src+bin (outputs go to user-supplied paths behind confine guard) → LegacyPaths honest none.
- GLUED FN: bin `candyfreeze_confine_path` (def :44, calls :178/:186/:194) — R4 lookahead (?![A-Za-z0-9_]) blind → manual R4 → candy_codeshot_confine_path (B4 `_candy_scriptui_main` precedent). <!-- @rename-keep -->
- GLUED `_candy-freeze` content refs (A1 blindspot): findings/findings_resume_plan.md :799/:806/:1280 + findings/plan.md:187 (plan-file cites) → sed in R4. <!-- @rename-keep -->
- CAPITAL-HYPHEN `Candy-freeze` prose: docs/repo_map/sugarcraft_candy-freeze.md:79 (living sentence) → manual R4 (B5 law-3). <!-- @rename-keep -->
- BARE-FREEZE FILE: examples/freeze_to_png.php — brand-derived filename, invisible to all rules + residue → git mv to codeshot_to_png.php in R4 + fix self-header + living refs (findings/plan :585/:715/:739/:744, findings/candy-freeze :330, repo_map tree diagram :67). <!-- @rename-keep -->
- BRAND shorthand comments "Freeze deliberately…" (src/Theme.php:73, tests/ThemePaletteParityTest.php:27) → living prose → R4 fix. <!-- @rename-keep -->
- LanguageDetectorTest.php:45 fixture INPUT string `namespace SugarCraft\Freeze;` <!-- @rename-keep --> → mechanical R1 rewrite, assert 'php' survives (any namespace token detects php).
- Lang: NAMESPACE='freeze' → 'codeshot' mechanical R6; MANUAL: LangTest.php:16 assertSame('freeze'), :69 raw-key fallback literal 'freeze.nonexistent.translation.key', :75 prose. Lang keys are unprefixed domain keys (cli.*) — no content churn.
- ansi/: NO candy-freeze wordmark art (no ansi/candy-freeze dir; <!-- @rename-keep --> ansi.json zero freeze keys — python-verified). No followups owed for art.
- .vhs/: ansi-input.tape + screenshot.tape (+2 gifs) exist; tapes drive ./bin/candyfreeze → mechanical R4 <!-- @rename-keep -->; GIF content stale-brand → followups line (CI re-renders). vhs.yml both hand-lists (:146 all=, :225 matrix) → mechanical R3.
- codecov.yml flag+component rows (:74/:298) → mechanical. root README:83, MATCHUPS:83/:160, index.html tiles+demo cards → mechanical; MATCHUPS:160 upstream link text `freeze`/URL stays (bare word, invisible, truthful).
- PROJECT_NAMES.md :138 rationale row (`candy + freeze … literal.`) → rewrite honest in R4; :215 SherbetSnap "(CandyFreeze alternative)" → pascal mechanical. <!-- @rename-keep -->
- COMPOSER: entry mandate = KEEP "port of charmbracelet/freeze" in description AND keyword `freeze` (upstream name). No de-frame this entry (unlike B3/B5/B8).
- scripts/: generate_icons.py:117 dict key mechanical, ice-pop prompt prose generic-keep; set-org-default-master.sh:17 + bootstrap-org-repos.sh:26 name lists mechanical.
- docs/plans ledgers (crush_code_RESUME/triage/leftover steps): living-surface mechanical rewrites (B3/B8 precedent); leftover step FILENAMES carry bare `freeze` (step-13-freeze-…/step-14-freeze-…) → invisible + not residue → stay (bare word, upstream-derived topic naming; recorded).
- Tag-prep: rename-baseline.md:38, CONTEXT.md :945 :951 (untagged historical lines), rename-4-B6/NOTES.md:100.
- oc.7z: absent in this worktree (untracked, main-tree only) — no hold dance needed.

## Steps

- [x] recon
- [ ] tag-prep + notes + R1 dry-run eye-review
- [ ] R2 remote rename
- [ ] R3 tool run + php -l sweep
- [x] R4 followups — LANDED (see R4 section)
- [ ] R5 wiring + regen checks
- [x] R6 gates — ALL EXACT (see R6 section)
- [ ] R7 commit status=renamed + push branch rename/b11 ONLY (no R8/R9 — weld agent)

## R1 dry-run — LANDED
- Post tag-prep 517bc8f01: plan **136 files, 11 paths, R1=102 R2=27 R3=410 R4=120 R6=1, R5=0** rc=0 (report /tmp/rename-candy-freeze.txt) <!-- @rename-keep -->. R5=0 confirms honest-none env. Eye-review PASS: dir move + bin move under candy-codeshot/bin listed; ns pair fires; my NOTES listed as rewritable (re-verify post-R3, B4 law).
- pr_charmbracelet_freeze.md 64 hits INSPECTED: all "Sugarcraft Port"/"Implications for candy-freeze" mapping lines <!-- @rename-keep --> = living port-analysis, mechanical correct (upstream charmbracelet/freeze bare-word URLs untouched). php74_branch/plan_crush_media/leftover steps = live path tables, mechanical.

## R2 remote — LANDED
- rename-remote.sh run 1: GitHub rename SUCCEEDED, died on topics HTTP 422 (repo at 20-cap incl stale `candycore` + noise showoff/xterm/drop-shadow — B5/B6 family).
- Run 2 (before trim) FALSELY refused "candy-codeshot already exists and is not candy-freeze" <!-- @rename-keep --> — transient old-name redirect resolution race post-rename; run 3 after trimming 4 topics + re-run = idempotent convergence ("rename skipped, re-syncing metadata"), verified record name=candy-codeshot, description kept "port of charmbracelet/freeze." per entry mandate, old URL 301s. Topics=20 exact.

## Log

(step findings appended here incrementally)

## R4 manual repairs — LANDED (@ in commit e481272f6)
- Glued fn `candyfreeze_confine_path` → `candy_codeshot_confine_path` (bin, 4 sites).
- Glued `plan_candy-freeze.md` refs (findings_resume_plan ×3, plan.md ×1) → plan_candy-codeshot.md.
- `examples/freeze_to_png.php` git-mv → `codeshot_to_png.php` + self-header + 7 living refs (findings ×5, repo_map, plan). <!-- @rename-keep -->
- LangTest: method rename testNamespaceIsFreeze→IsCodeShot, :16/:69/:75 pins → 'codeshot'. Suite green proves src/Lang.php NAMESPACE flip consistent (lang keys unprefixed).
- Brand prose: Theme.php:73, ThemePaletteParityTest:27, repo_map:79 (Candy-freeze→Candy-codeshot), leftover README Phase-08 heading (Freeze→Codeshot), candy-git DiffHighlighter docblock `Freeze\CodeHighlighter` → `CodeShot\` <!-- @rename-keep --> (survivor of ns rule — short-form not FQN).
- CliTest temp-fixture prefixes `/candyfreeze_*` → `/codeshot_*` (lookahead-blind `candyfreeze_` glue).
- README Formerly+Namespace blockquotes (tagged); PROJECT_NAMES:138 rationale rewritten honest (SVG confirmed truthful — composer desc + README); followups: B11 GIF section; docs/_data CLI example fixed.
- NOTES history: tool rewrote 7 as-written lines (ls-d ref, fixture strings, /tmp report name, refusal msg quote, ansi dir names, SherbetSnap cite, tape bin path) — all restored + tagged (B4 law re-proven ×7).
- Regenerated scripts/__pycache__/generate_icons.cpython-313.pyc (tracked, carried old string post-.py rewrite).
- Survivors ruled LEGIT untagged: ansi/sugarcraft/*.ansi grid art (corpus content-excluded, carries MANY retired names incl sugar-post/sugar-spark — prior lanes left silently); docs/old/ tool-excluded archive; bare-word 'freeze' keyword, 'Freeze the elapsed time' verb (minesweeper), upstream repo_map/pr citations.

## R5 — LANDED (commit 588b490ef)
- Root lock relocated cleanly (1 entry, ns SugarCraft\\CodeShot, bin/candy-codeshot, zero old-vocab lines; rest = brace reflow + content-hash + reference).
- refresh-deps linked: codeshot OK (0 per-lib locks), later candy-core/candy-ansi/inspect too.
- path-repos rc0; gen-docs 74 files (40/21) + check rc0 (61 pages + 13 stubs — 14th stub arrives with R7 flip); gen-ansi-readme --check rc0.

## R6 gates — ALL EXACT
- residue --old=candy-freeze: 0 findings, rc0 (∧ before staging). <!-- @rename-keep -->
- candy-codeshot suite: OK 350T/752A rc0 — baseline shape EXACT.
- consumers (comment-only edits, no composer deps): candy-ansi OK 360T/901A EXACT; candy-ansi-inspect OK 278T/1412A EXACT.
- tools/tests OK 426T/1484A EXACT (via candy-core vendor after its relink; earlier fail = my own bash `&` grouping swallowed the cd — harness lesson: `cd X; ( wd ) &` NOT `cd X && ( wd ) &`).
- child-lifetimes rc0. Branch diff 166 files (+969/−907); generic-freeze audit line-by-line: all +/- freeze↔codeshot pairs legit.
