# Rename B11: candy-freeze → candy-codeshot (Procedure R) <!-- @rename-keep -->

State file, updated incrementally per step. History lines mentioning old vocabulary carry `@rename-keep` as written. <!-- @rename-keep -->
Concurrent lane (b9/b10/b12 run in parallel worktrees; batch E hazard note: candy-ansi→sugar-ansi-parser must PROTECT candy-ansi-inspect — unaffected here).

## Entry facts (verified)

- tools/renames.json line 18: kind=rename, <!-- @rename-keep --> candy-freeze → candy-codeshot, app, ns Freeze→CodeShot, bins {"bin/candyfreeze":"bin/candy-codeshot"} (GLUED compact bin). No extra_literals, no compact_ident_new → derive: pascal CandyFreeze→CandyCodeshot, compact candyfreeze→candycodeshot, env old CANDY_FREEZE_/CANDYFREEZE_ → CANDY_CODESHOT_ (all no-ops, see ENV). <!-- @rename-keep -->
- Baseline (rename-baseline.md:38): 350T/752A/0F/0E/0S/0W — tagged @rename-keep in tag-prep.
- `CodeShot`/`codeshot` pre-existence: ZERO outside plan_rename.md/tools lines → collision clear.
- Sibling-prefix: `ls -d candy-freeze*` = only the lib. PROTECT auto-derivation inert. Reverse note: future candy-codeshot-* entries must PROTECT candy-codeshot.
- CONSUMERS: root composer.json only (require :51, repositories :156). NO lib requires sugarcraft/candy-freeze <!-- @rename-keep --> — sugar-dash does NOT depend on freeze (brief premise checked: dash's 2F baseline pair is dash-own, untouched by this lane).
- Docblock-only external mentions: candy-ansi/src/Parser/SubparamsAwareHandler.php:67,:93 + candy-ansi/tests/Support/PullSubparamsRecorder.php:12 + candy-ansi-inspect/src/Inspector.php:246 — all `SugarCraft\Freeze\SgrStateHandler` cross-refs, comment-only → mechanical R1; run candy-ansi + candy-ansi-inspect suites at R6. <!-- @rename-keep -->
- ENV: ZERO getenv/putenv/ENV_PREFIX in src+bin; CANDY_FREEZE_/CANDYFREEZE_ zero tree-wide → LegacyEnv honest none; R5 rule count expected 0. <!-- @rename-keep -->
- DATA PATHS: zero XDG/.config/.local/.cache/HOME derivation in src+bin (outputs go to user-supplied paths behind confine guard) → LegacyPaths honest none.
- GLUED FN: bin `candyfreeze_confine_path` (def :44, calls :178/:186/:194) — R4 lookahead (?![A-Za-z0-9_]) blind → manual R4 → candy_codeshot_confine_path (B4 `_candy_scriptui_main` precedent). <!-- @rename-keep -->
- GLUED `_candy-freeze` content refs (A1 blindspot): findings/findings_resume_plan.md :799/:806/:1280 + findings/plan.md:187 (plan-file cites) → sed in R4. <!-- @rename-keep -->
- CAPITAL-HYPHEN `Candy-freeze` prose: docs/repo_map/sugarcraft_candy-freeze.md:79 (living sentence) → manual R4 (B5 law-3). <!-- @rename-keep -->
- BARE-FREEZE FILE: examples/freeze_to_png.php — brand-derived filename, invisible to all rules + residue → git mv to codeshot_to_png.php in R4 + fix self-header + living refs (findings/plan :585/:715/:739/:744, findings/candy-freeze :330, repo_map tree diagram :67). <!-- @rename-keep -->
- BRAND shorthand comments "Freeze deliberately…" (src/Theme.php:73, tests/ThemePaletteParityTest.php:27) → living prose → R4 fix. <!-- @rename-keep -->
- LanguageDetectorTest.php:45 fixture INPUT string `namespace SugarCraft\Freeze;` → mechanical R1 rewrite, assert 'php' survives (any namespace token detects php).
- Lang: NAMESPACE='freeze' → 'codeshot' mechanical R6; MANUAL: LangTest.php:16 assertSame('freeze'), :69 raw-key fallback literal 'freeze.nonexistent.translation.key', :75 prose. Lang keys are unprefixed domain keys (cli.*) — no content churn.
- ansi/: NO candy-freeze wordmark art (no ansi/candy-freeze dir; ansi.json zero freeze keys — python-verified). No followups owed for art.
- .vhs/: ansi-input.tape + screenshot.tape (+2 gifs) exist; tapes drive ./bin/candyfreeze → mechanical R4; GIF content stale-brand → followups line (CI re-renders). vhs.yml both hand-lists (:146 all=, :225 matrix) → mechanical R3.
- codecov.yml flag+component rows (:74/:298) → mechanical. root README:83, MATCHUPS:83/:160, index.html tiles+demo cards → mechanical; MATCHUPS:160 upstream link text `freeze`/URL stays (bare word, invisible, truthful).
- PROJECT_NAMES.md :138 rationale row (`candy + freeze … literal.`) → rewrite honest in R4; :215 SherbetSnap "(CandyFreeze alternative)" → pascal mechanical.
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
- [ ] R4 followups (glued fn, bare-freeze example mv, Lang pins, capital-hyphen, glued path refs, README Formerly, PROJECT_NAMES, followups line)
- [ ] R5 wiring + regen checks
- [ ] R6 gates (residue 0 &&-staged, suite exact, candy-ansi/-inspect consumers, tools/tests 426/1484, path-repos, generic-freeze audit)
- [ ] R7 commit status=renamed + push branch rename/b11 ONLY (no R8/R9 — weld agent)

## Log

(step findings appended here incrementally)
