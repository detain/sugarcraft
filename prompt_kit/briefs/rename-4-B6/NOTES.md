# B6 sugar-spark → candy-ansi-inspect — execution notes <!-- @rename-keep -->

Entry: tools/renames.json batch-B line 13. kind=rename, type=app, ns Spark→AnsiInspect,
bins bin/sugarspark→bin/candy-ansi-inspect (GLUED compact bin spelling). <!-- @rename-keep -->
Base: master 4f8427290 == origin; foreign untracked oc.7z → /tmp/oc.7z.b6-hold (preflight demanded clean tree). <!-- @rename-keep -->

## R0 recon findings
- Baseline (rename-baseline.md:83): 278T/1412A/0F/0E/0S/0W — tagged @rename-keep in tag-prep 5718a6148.
- Sibling dirs/packages prefixed `sugar-spark`: NONE (`ls -d *spark*` = only the lib itself). <!-- @rename-keep -->
  NO sugar-sparkline lib exists (brief premise checked): sparkline code lives INSIDE sugar-charts <!-- @rename-keep -->
  (`src/Sparkline/Sparkline.php`), sugar-dash (`src/Plot/Chart/Sparkline*.php` + examples/goldens) and <!-- @rename-keep -->
  candy-sql (TimeSeriesCell prose) — ALL SAFE: R3 needs the full `sugar-spark` literal, R2 is Pascal <!-- @rename-keep -->
  `SugarSpark`, R4 is glued `sugarspark`; `Sparkline`/`sparkline` matches none. VERIFIED at R3: zero <!-- @rename-keep -->
  sparkline paths in the staged diff, zero sparkline lines in the (re-)dry-run.
- PROTECT auto-derivation inert (renames.json has exactly 1 spark entry). Reverse-direction law RE-LOGGED:
  batch-E `candy-ansi` → sugar-ansi-parser must PROTECT `candy-ansi-inspect` (R3 suffix + R5 CANDY_ANSI_
  prefix would mangle CANDY_ANSI_INSPECT_BIN / paths). Also: the sibling lib `candy-ansi` (untouched slug)
  shares the word 'ansi' — zero rule fires on it from this entry (verified: only its 4 CONTENT files got
  docblock prose rewrites, no path renames).
- Tracked paths outside lib moved by C.2: docs/_data pair, docs/lib page, repo_map pair, research doc,
  findings/plan_sugar-spark.md (→ plan_candy-ansi-inspect.md), media+docs/img icon pngs, ansi/sugar-spark/ <!-- @rename-keep -->
  162 art files (→ ansi/candy-ansi-inspect/candy-ansi-inspect-*.ansi). <!-- @rename-keep -->
- Composer REQUIRE consumers: root composer.json only (require + repositories). NO sibling lib requires it.
  Docblock/example mentions auto-rewrote: candy-core/README.md, sugar-bits/examples/item-list.php, root
  README.md:85, MATCHUPS :85/:206, candy-ansi/{README,CALIBER_LEARNINGS,src/Parser/Parser,src/Parser/SubparamsAwareHandler}.
- Env: ZERO getenv/$_ENV/putenv reads in src+bin. `SUGARSPARK_BIN` existed ONLY in examples/inspect.sh <!-- @rename-keep -->
  (bash example, not PHP) → tool R5 rewrote it to CANDY_ANSI_INSPECT_BIN (6 R5 sites incl comment); NO LegacyEnv shim.
- User data paths: grep XDG/.config/.local/.cache over src+bin → EMPTY. LegacyPaths: honest none.
- BRAND CLASS: none — all src classes domain-named (Inspector/StreamingInspector/AnsiHandler/Segment/
  SequenceSegment/TextSegment/C0C1/Lang). No Spark* compounds anywhere.
- Lang: NAMESPACE='spark' → R6 → 'ansiinspect' mechanically; NO LangTest exists (11 test files, none asserts <!-- @rename-keep -->
  domain) — docblock prose `'spark'` at Lang.php:13 manually fixed to `'ansiinspect'`.
- MISLABEL FOUND (unique to this entry): `findings/sugar-charts-sparkline.md` carries header "# Audit: sugar-spark" <!-- @rename-keep -->
  + 2 body mentions while its CONTENT audits `src/Sparkline.php` — the sugar-charts Sparkline port (sugar-charts/
  src/Sparkline/Sparkline.php exists; the inspector lib has no such file). The slug refs are a historical
  name-confusion (spark↔sparkline), NOT references to this app. Mechanically rewriting them would mint FALSE
  claims about candy-ansi-inspect ("ports charmbracelet/spark, generates sparkline charts"). RULING: tagged the
  3 lines @rename-keep BEFORE R3 (excluded from rewrite) + recorded for human triage in rename-followups.md.
- ansi/: 162 art files paint the wordmark (paths renamed, content C.3-excluded) → followups line; ansi.json
  key + 2 art DESCRIPTION strings (`SugarSpark` R2, `sugar-spark` R3) rewrote mechanically — gen-ansi-readme <!-- @rename-keep -->
  regen keeps README.md in step (art files: `sugar-spark-*.ansi` filenames in README/ansi.json → `candy-ansi-inspect-*`). <!-- @rename-keep -->
- vhs.yml: BOTH hand-lists auto-rewrote (:147 all= + :225 matrix). inspect.tape drives ./examples/inspect.sh ✓.
- MATCHUPS row already under `## Apps`; index.html tile under `#apps` → R4.8 MOOT.
- PROJECT_NAMES.md: ZERO spark mentions (unlike B4/B5) — no rationale rewrite owed. Honest record.
- Capital-hyphen `Sugar-spark` prose form: ZERO occurrences tree-wide → no living-doc hand-fix needed (B5 law applied).
- Generic-word 'spark' landscape: NO Apache Spark/spark-sql citations; bare `spark`/`sparks`/`sparkline` live in
  ansi/README+ansi.json art descriptions (forge imagery) and sugar-charts/sugar-dash code — no rule fires on bare <!-- @rename-keep -->
  words. Audit executed at R6 (B4/B5 method).
- codecov.yml rows (flag + component) auto-rewrote via R3. `scripts/__pycache__/generate_icons.cpython-313.pyc`
  is a TRACKED binary (NUL bytes → C.3 content-excluded; residue NUL-skip) — pre-existing repo dirt, untouched.

## R1 dry-run
- Post tag-prep: 99 files, 172 paths, R1=60 R2=30 R3=1078 R4=108 R5=6 R6=1. Eye-reviewed: dir move, bin move via
  bins map, ns pair, NO tool/self/plan hits, zero sparkline path targets. Mislabel tagging → re-dry-run delta exact:
  98 files, R3 1078→1075 (−3), zero 'sparkline' anywhere in plan. Report: /tmp/rename-sugar-spark{,2}.txt. <!-- @rename-keep -->

## R2 remote rename
- `scripts/rename-remote.sh sugar-spark candy-ansi-inspect`: GitHub rename SUCCEEDED, then topics 422 (B5 family) — <!-- @rename-keep -->
  repo carried 19 topics incl stale brand `candycore` + noise `readable`/`development`; script adds 8 composer
  keywords (4 new: escape/inspector/debug/sugarcraft) → would exceed 20. Cure: `gh repo edit --remove-topic candycore`,
  then `--remove-topic readable --remove-topic development`, re-ran script → idempotent convergence.
  Verified: name candy-ansi-inspect, description 'ANSI escape-sequence inspector — port of charmbracelet/sequin.',
  topics=20 EXACT at cap, old sugar-spark URL resolves (redirect live). Note: repo already carried well-chosen <!-- @rename-keep -->
  inspector-flavored topics (ansi-inspector/csi-decoder/osc-decoder/sequence-parser/sequin-port/sgr-parser/terminal-debug).

## R3 tool run
- rc=0: 98 files, **173** paths (172 + the lib dir itself — B5 shape), replacement counts byte-identical to re-dry-run.
- `sugar-spark/` gone → `candy-ansi-inspect/`; `bin/candy-ansi-inspect` present. <!-- @rename-keep -->
- php -l: all changed existing PHP clean + bin lints (file_exists filter per B5 gotcha). JSON valid (root/lib).
- Collision gates: zero sparkline PATHS in diff; candy-ansi sibling = 4 content-only docblock prose rewrites, zero paths.

## R4 manual followups (all landed pre-R7)
- examples/inspect.sh: R4 minted INVALID bash fn name `candy-ansi-inspect()` (+export -f +6 `| candy-ansi-inspect`
  pipes) → `run_ansi_inspect()` ×8 sites (B5-h law). Comment prose kept truthful (binary name in `command -v` narrative).
- src/Lang.php:13 docblock `'spark'` → `'ansiinspect'` (B1 law). <!-- @rename-keep -->
- tests/CliTest.php tempnam prefixes 'spark'→'inspect', 'spark-empty'→'inspect-empty' (B5-g law; recon initially
  caught :134, post-R3 sweep caught :102 too — grep bare `'spark` in tests before claiming done).
- Glued `_sugar-spark` path refs swept (A1 blindspot law): docs/repo_map/update_sugarcraft_candy-ansi-inspect.md :149, <!-- @rename-keep -->
  findings/plan.md :197. Zero `_sugar-spark` remain outside tagged history + .ansi art. <!-- @rename-keep -->
- README Formerly + Namespace-changed blockquotes under `# CandyAnsiInspect` H1 (B2 format, tagged).
- composer.json description de-framed 'PHP port of charmbracelet/sequin' → '…modeled on the charmbracelet/sequin Go tool'
  (B3/B5 pattern); keywords keep sequin + sugarcraft + ansi/inspector; raw em-dash matches dominant house style (1-line diff).
- rename-1.1-zone-merge.md:63 historical line tagged (true history: zone-merge lane's sugar-spark revert record). <!-- @rename-keep -->
- NOTES.md re-authored (tool touched skeleton's untagged lines — B4 law).
- PROJECT_NAMES: nothing owed (no row). lang/*.php + tape + bin comment all coherent post-R3/R4.

## R5 wiring
- `composer update sugarcraft/candy-ansi-inspect --no-install`: lock = clean swap (removing sugar-spark dev-master 8fb0cbd / <!-- @rename-keep -->
  locking candy-ansi-inspect dev-master e311d97), dist url path `candy-ansi-inspect`, source URL split repo ✓.
- refresh-deps `--mode=linked --libs=candy-ansi-inspect`: linked 4/4 symlinked, escaping=0, committed-tree guard rc0.
  Relative vendor symlinks survived the dir move untouched (depth-stable ../../../) <!-- @rename-keep -->
- autoload_psr4: zero Spark, 2× AnsiInspect ✓. gen-docs: 71 files (libs 40 / apps 21), --check OK 61 pages + 10 redirect
  stubs (sugar-spark stub minted at R7 after status flip). gen-ansi-readme --check: 3595 pieces agree ✓. <!-- @rename-keep -->

## R6 verification
- Residue `--old=sugar-spark`: initial 12 findings ALL = own NOTES.md history lines → tagged them all → **0 rc=0** ✓. <!-- @rename-keep -->
- Suite EXACT-shape: **278T/1412A OK (0.745s)** = baseline ✓. Watchdog + plain pipe + env -u LINES/COLUMNS.
- Sparkline untouched proof: `git diff --cached --name-only -M | grep -ci sparkline` = **0** ✓; `ls -d *spark*` → no match.
- Consumer sanity: candy-ansi suite **360T/901A OK** (its 4 changed files are docblock-comment-only; lint clean ×3 incl
  candy-freeze/SgrStateHandler.php auto-rewrite). No composer REQUIRE consumers exist. <!-- @rename-keep -->
- tools/tests **426T/1484A OK (36s)** — floor EXACT ✓. path-repos --no-lib-path-repos rc=0 ✓.
- Diffstat: 284 files; rename-detection `{sugar-spark => candy-ansi-inspect}` through lib tree + docs pair + repo_map pair <!-- @rename-keep -->
  + research + findings plan + icon png ✓.
- Generic-word audit (B4/B5 method): changed bare-spark lines = ONLY `SugarCraft\Spark\*` namespace tokens (R1 rewrites) <!-- @rename-keep -->
  + own NOTES lines + ONE docs/plans/crush_code_worklog.md:11678 t3 history row — lib-column identifier renamed, its
  `spark FULL 269T/1380A` suite-column abbreviation + figures preserved (mechanical rename of a reference, claim intact —
  same entity; accepted, no falsification). sugar-charts/sugar-dash sparkline prose+code lines: byte-untouched ✓. <!-- @rename-keep -->
- No completions file existed for `sugarspark` (git ls-files zero) — bin sweep vacuous-clean; `.opencode/`+`.caliber/` <!-- @rename-keep -->
  hits are gitignored session artifacts; `.ansi` art wordmark content = logged followup ✓.

## R7 commit
- renames.json line 13 targeted sed → status=renamed; gen-docs re-run minted redirect stub docs/lib/sugar-spark.html <!-- @rename-keep -->
  (11th; stub body self-carries @rename-keep for its Moved: title) + --check green (61 pages + 11 stubs).
- **Discipline slip caught by own gate:** staged → residue ran BEFORE commit but my R6 NOTES prose added 7 new untagged
  lines; the `echo rc=$?` in the chain masked rc=1 and the commit landed (005e0ca78). Fixed: tagged all 12 history lines →
  residue 0 → amended (unpushed) → 580d028c2. LAW: gate rc must short-circuit (`&&`), never echo-masked; NOTES lines get
  tagged AS WRITTEN, not in a later pass.

## R8 push + remote-done
- `git pull --rebase` absorbed FOREIGN `f67c043ec vhs: regenerate demo GIFs` → **all chain SHAs moved** (A1 law re-proven
  3rd campaign entry): tag-prep dc94da476, skeleton a4ae044fd, mislabel e3d9967ea, R7 **812d53243** (pushed, 0 ahead).
- Split sync verified before flip: master=f1c1b124d (composer name sugarcraft/candy-ansi-inspect), legacy/sugar-spark + <!-- @rename-keep -->
  sugar-spark-final both at 40bf3e835, old GitHub URL 301s. <!-- @rename-keep -->
- remote-done commit c3b8956b3 pushed (renames.json staged explicitly per B5 gotcha).

## R9 packagist
- B5 cure **did not engage**: `create candy-ansi-inspect` → HTTP 202 first try (no 406). Interpretation: packagist's
  stored sugar-spark registration was not holding the renamed URL at crawl time (unlike sugar-glow where it was); <!-- @rename-keep -->
  B3 law still honored (CI-sync landed first). Nuance logged in abandon-checklist: the 406 is conditional, don't
  pre-emptively force-update the old slug — try create, only run the cure on actual 406.
- One forced bare-slug `update candy-ansi-inspect` (job 57a4af9fa) → check: dev-master present, reference
  f1c1b124d1d3a2a96fbf2ceb3c006a4b9c680ea3 == LIVE split refs/heads/master EXACT (first poll; no 10-poll stall).
- Abandon line appended (full house prose; thin first pass committed in f34d65176 → heredoc python indent error on the
  upgrade attempt meant the published commit carried the bare line — fixed in the tracker commit; amend-of-pushed NOT done).
- published commit f34d65176 pushed.

## Tracker
- plan_rename.md B6 ✅ line appended before **Done when:** with full SHAs/gates; committed here alongside checklist fix-up.
- oc.7z restored from /tmp/oc.7z.b6-hold → back as foreign untracked. Final: master == origin, tree clean except oc.7z.
