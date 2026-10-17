# rename-4-B10 — sugar-tick → candy-codetime (Procedure R) <!-- @rename-keep -->

State file, updated incrementally per step. History lines mentioning old vocabulary carry `@rename-keep` as written. <!-- @rename-keep -->

## Entry facts (verified)

- tools/renames.json line 17: kind=rename, sugar-tick → candy-codetime, app, ns Tick→CodeTime, bins {"bin/sugar-tick":"bin/candy-codetime"}, no extra_literals. <!-- @rename-keep -->
- Derived (RenameLib::derive): pascal SugarTick→CandyCodetime, compact sugartick→candycodetime (R4 replaces bare compact run with HYPHENATED new slug `candy-codetime` outside python paths), env prefixes SUGAR_TICK_/SUGARTICK_→CANDY_CODETIME_, PROTECT auto = none (no `sugar-tick-*` entries). <!-- @rename-keep -->
- Tool exclusions (verified in source): plan_rename.md, tools/renames.json, tools/rename-lib.php, check-residue, its 2 tests, tools/tests/fixtures/rename/, docs/old/, .remember/, .logs/, .codenomad/, vendor/node_modules/.git, composer.lock+package-lock.json basenames; binary ext content-excluded but PATHS rename. prompt_kit NOT excluded → CONTEXT.md lines need tagging.
- Baseline suite (rename-baseline.md:87): **144T/349A/0F/0E/0S/0W**. <!-- @rename-keep -->

## Recon map

- CONSUMERS: NO PHP consumers of `SugarCraft\Tick\` outside the lib (grep: zero). `sugarcraft/sugar-tick` in root composer.json (require+repositories), findings/plan_sugar-tick.md, docs/_data pair, docs/lib page, docs/index.html, repo_map pair, composer.lock — all auto/R5 territory. No consumer suites owed beyond tools/tests gate. <!-- @rename-keep -->
- `CodeTime`/`codetime` pre-existence: ABSENT tree-wide except plan_rename.md + renames.json entry. PROTECT check `ls -d *codetime*` empty.
- ENV: `SUGARTICK_DIR` read at bin:52 (user-facing, README:30 documents it) → R5 → CANDY_CODETIME_DIR + LegacyEnv::import shim in bin (plan note mandates shim). Zero SUGAR_TICK_ underscore sites. tests/CliTest.php:67 sets SUGARTICK_DIR for child env — auto-rewrites, stays green. <!-- @rename-keep -->
- DATA PATH: Store::defaultDir = `$XDG_DATA_HOME/sugar-tick` else `~/.local/share/sugar-tick` (Store.php:47-51); bin dbPath `$store->dir/sugar-tick.db`. R3 rewrites literals → new dir. R4.4: LegacyPaths::migrate(old,new) at top of bin after autoload, STDERR notices, old-path line @rename-keep. Plan entry note mandates migration (NOT upstream-compat keep).
- BRAND strings: Renderer.php:50 `->render(' SugarTick ')` wordmark; README H1 `# SugarTick`; root README.md:96 table cell `**[SugarTick](sugar-tick/)**`; tests assert contains 'SugarTick' ×2 (RendererTest:27, DashboardTest:221) — all auto via R2. docs/_data/MATCHUPS/index.html SugarTick titles auto via R2. <!-- @rename-keep -->
- SugarTrackIgnore (src/Ignore/ + test): NOT brand-of-slug — no rule matches (`SugarTrack` ≠ `SugarTick`). LEAVE IT (plan note). <!-- @rename-keep -->
- CAPITAL-HYPHEN `Sugar-tick` (B5 law): 3 living docs — docs/research/libraries/sugar-tick-research.md, docs/repo_map/update_sugarcraft_sugar-tick.md, docs/repo_map/sugarcraft_sugar-tick.md → MANUAL fix (paths themselves rename via R3; content word survives both rules + residue → must hand-edit). <!-- @rename-keep -->
- Lang: NAMESPACE='tick' → R6 → 'codetime'; keys are `cli.*` domain-neutral (survive); no LangTest exists → no pins.
- ansi/: NO sugar-tick wordmark art → R4.9 honest none. <!-- @rename-keep -->
- .vhs: dashboard.tape + committed dashboard.gif (stale brand after rename → followups line; tape drives examples/dashboard.php).
- vhs.yml :150 (all= list) + :225 (matrix) carry `sugar-tick` literal → R3 auto. codecov.yml :119-120/:388-393 auto. media/icons/sugar-tick.png path-rename (binary content-safe). <!-- @rename-keep -->
- MATCHUPS row + index.html tile: verify under Apps section already (type=app) → R4.8 likely moot; CHECK.
- PROJECT_NAMES.md: ZERO tick mentions → owed nothing.
- Glued `_<old>` sweep: zero `_sugar-tick`/`_sugartick` sites. findings/plan_sugar-tick.md path auto-renames; no content refs to that filename found in lib docs. <!-- @rename-keep -->
- CONTEXT.md sugar-tick lines: 911, 957(tagged), 967, 968(tagged), 980(tagged), 985(tagged), 990(tagged), 1009, 1010, 1012 — UNTAGGED: 911, 967, 1009, 1010, 1012 → tag in tag-prep commit. baseline row :87 → tag. <!-- @rename-keep -->

## Steps

- (pre-R1) refresh-deps RACE INCIDENT: two parallel refresh-deps invocations raced; second reported guard rc=1 with path-repos listed across ~50 manifests + status `sugar-tick linked 12/12 *** WANTED PUBLISHED ***`. Investigating serial cleanup before any commits. <!-- @rename-keep -->
- tag-prep:
- R1 dry-run:
- R2 remote rename:
- R3 tool run:
- R4 manual:
- R5 wiring:
- R6 gates:
- R7 commit:

## R4-finish → R7 closure ledger (2026-10-16, appended as written)
- push.gif never existed in git (ls-tree f6c141f0c: only dashboard.gif+dashboard.tape) — README dangling ref PRE-EXISTING; followups entry honest. <!-- @rename-keep -->
- Capital-hyphen `Sugar-tick`→`Candy-codetime` ×10 (research 5, repo_map 1, repo_map update 4). <!-- @rename-keep -->
- Glued-path sweep: 5 `sugarcraft_sugar-tick.md` cites + `plan_sugar-tick.md` pointer rewritten (renamed targets exist under new names). <!-- @rename-keep -->
- R5: root composer require+repositories auto (tool), lock updated, refresh-deps full linked serial, gen-docs --check 0, gen-ansi-readme --check 0.
- R6: residue 0 findings; suite EXACT 144T/349A rc=0; tools/tests EXACT 426T/1484A; path-repos rc=0; child-lifetimes rc=0 (60/28/8); candy-code consumers ZERO refs.
- R7: renames.json line 17 status pending→renamed (compact `"status":"pending"` format — space-form sed silently no-ops; first space-form sed silently no-ops && chain died pre-stage = correct fail-closed, compact form then numstat 1/1 verified); <!-- @rename-keep -->
  gen-docs re-run minted docs/lib/sugar-tick.html redirect stub (tagged, self-declared); final residue gate 0 at status=renamed. <!-- @rename-keep -->
- Deviations: composer.json keywords dup 'sugarcraft' pre-existing → 'codetime'; none other.

## R9 fire (weld 2026-10-16)
- create candy-codetime: HTTP 406 (sugarcraft/sugar-tick already exists — old reg holds URL). Recorded, no retry per batch-B law; end-of-campaign verification pass. <!-- @rename-keep -->
- R8 note: gh repo already renamed (sugar-tick → candy-codetime 301s); split-sync legacy refs owed final ls-remote peek at campaign close. <!-- @rename-keep -->
