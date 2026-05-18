## Pre-step 01.07 health check findings

**2026-05-17 — Clean, no showstoppers found.** Details:

- **CI Status**: ✅ `php tools/check-path-repos.php` reports "closure clean" across all 46 libs. CI workflows (`.github/workflows/`) are structurally sound — no obvious issues.
- **Composer validation**: ✅ All key libs (`candy-core`, `candy-pty`, `sugar-bits`, `sugar-charts`, `candy-shell`) pass `composer validate --no-check-all`. Root warns only about the `version` field on the monorepo root — expected/benign.
- **Untracked files**: ✅ Only `.claude/scheduled_tasks.lock` — benign, matches task description.
- **MATCHUPS.md**: ✅ Properly formatted. No duplicate rows. The Python "duplicate Upstream" finding was a false alarm — "Upstream" appears in two table *header* rows (one per section: Charmbracelet libs + Apps), which is correct. 53 data rows, no inconsistencies.
- **PHP syntax**: ✅ Sampled 20 `src/*.php` files — all pass `php -l`. Also verified `PosixBackend.php`, `PosixPump.php`, `RealProcess.php`, `Process.php` individually.
- **Step 07 preconditions**: ⚠️ P3-LO-01 and CC-LO-08 are NOT YET in the done log — step 01.07 is the next logical step (matching `step-07-realprocess-deletion.md`). Pre-step grep was run during this health check; `RealProcess` has **active callers** in `candy-shell/src/Command/SpinCommand.php:71` and `candy-shell/tests/Process/RealProcessTest.php` — deletion is NOT safe. The step file's own logic handles this correctly (keep as deprecated alias if callers exist). No blocker — the step can proceed on its defined path.
- **leftover_updates.md**: ✅ Reviewed. No urgent items missed. Sequencing in that file (P5-LO-01 first, CC-LO-02 second, step 07 fifth) is consistent with current done-log state.
- **Symlinks**: ✅ All `composer.json` files in consumer libs resolve to real files — no broken symlinks. Verified `candy-pty`, `candy-shell`, `sugar-bits`, `sugar-charts`, `sugar-dash`, `candy-sprinkles`, `candy-vt`.
- **candy-shell dep on candy-pty**: ✅ Already satisfied — `candy-shell/composer.json` already has `sugarcraft/candy-pty: dev-master` in `require`.

---

# updates — running notebook across subagents

This file is the shared work-tracker for every subagent in the
leftover-updates rollout. Append-only during a session; the supervisor
prunes stale items between phases.

Sections below are headings every subagent looks for. Leave the
headings present even when empty so nobody has to invent them.

---

## Blockers

(Items that stop the current step until resolved. The supervisor checks
this before spawning the next subagent.)

- **step 01.06** (slim-deprecated-facades): ~~RESOLVED via PR#499~~ — hybrid approach: composition for Pty (138 LOC), Spawn/Child/Master left minimal per revised targets (≤324 total achievable). Original step prescription (`extends Posix\Foo`) was structurally impossible since all Posix classes are `final`.

- **step 01.12** (SignalForwarder tests): ~~RESOLVED via PR#513~~ — NOT a PHP architectural limitation. Two real, narrow candy-pty bugs: (1) `posix_openpt` master fd lacked `FD_CLOEXEC` so the forked child inherited it, keeping the kernel master-side refcount > 0 across parent close; (2) `PosixMasterPty::close()` returned early after `fclose($stream)`, but `fopen('php://fd/N')` dup()s the fd so the original `posix_openpt` fd stayed open. Both required — with only one fix, child still survives 2 s+. With both: `sleep 30` exits ~20 ms after master close. Fixes + the three integration tests (SignalForwarderReactLoop, SIGHUPForwarding, NoControllingTerminal) landed together.

- ~~**step 03.05** (sugar-dash canonical primitives): **BLOCKED**~~ — **PARTIALLY RESOLVED (2026-05-18)**: Color class replaced with `class_alias` shim → Core\Util\Color (true duplicate). Five non-duplicates (Style/Theme/Rect/Buffer/Cell) retained with clarifying docblocks + CALIBER_LEARNINGS entries. **Remaining narrow blocker**: `StyleParser` was declared a "true duplicate" in the revised step scope but is NOT drop-in compatible with `\SugarCraft\Sprinkles\StyleParser` — the Sprinkles version returns `list<\SugarCraft\Sprinkles\Cell>` with `\SugarCraft\Sprinkles\Style` (private `$fg`/`$bg`), while sugar-dash tests access `$cell->style->foreground->r` (requires public `readonly ?Color $foreground` on the Dash Style class). Replacing the parser would require rewriting all StyleParserTest assertions to use the Sprinkles Style API — out of scope per step instructions. StyleParser kept as sugar-dash SSOT. **ACTION NEEDED**: If future work wants to eliminate the sugar-dash StyleParser duplication, the test suite for StyleParserTest.php must be rewritten to use Sprinkles\Style API, then StyleParser.php can be deleted.

- **step 03.13** (sugar-dash depends on sugar-charts, kill chart duplication): **BLOCKER — INCORRECT PREMISE**. After thorough code inspection, NONE of the files listed for deletion (Bar.php, Heatmap.php/HeatMapChart.php, OHLC.php, Sparkline.php, Chart.php) are direct duplicates of their sugar-charts counterparts — they are distinct implementations with different rendering approaches and APIs:
  - `Bar.php` in sugar-dash = horizontal UI status bar (content display); sugar-charts Bar = data point for bar charts — **completely different purpose**
  - `Heatmap.php` in sugar-dash = renders directly to ANSI strings; sugar-charts Heatmap = Canvas-based — **different rendering approach**
  - `OHLC.php` in sugar-dash = terminal UI component; sugar-charts OHLCChart = Canvas-based — **completely different architecture**
  - `Sparkline.php` in sugar-dash = RingBuffer for O(1) push, dim-edge padding, no Style dependency; sugar-charts Sparkline = Style-based rendering, min/max, autoMaxValue — **different API and internals**
  - `Chart.php` in sugar-dash = concrete self-contained bar/line chart (ANSI-rendered); sugar-charts Chart = abstract base class with legend/title/label composition — **completely different class hierarchy**
  - `LineChart.php` does not exist in sugar-dash/src/Plot/Chart/

  This is NOT a chart duplication problem. Per CALIBER_LEARNINGS entry 38 [pattern:dual-foundation-ssot]: "The 5 retained types are intentionally distinct from same-named canonical types in candy-sprinkles/candy-core/candy-vt due to different upstream lineage." The same applies here. **The step should be closed as "not actionable"** — there is nothing to delete and no dependency to add. sugar-dash chart components serve dashboard-specific visualization needs; sugar-charts serves canonical chart primitives. The boundary is already correct. Supervisor decision needed: should this step be dropped from the rollout, or should I attempt a different interpretation (e.g., adding sugar-charts as a dep even without deleting anything)?

---

## Carry-forward

(Items discovered during a step that should be tackled later — usually
in a follow-up step or a deferred phase. Each entry: one short line +
the step that surfaced it.)

- (none currently)

---

---

## Cross-phase observations

(Patterns or surprises that span phases — e.g. "every i18n step needs
to add a path-repo entry for sugar-wishlist". Put one-liners here so
later steps don't rediscover.)

- Posix\* classes are `final` per project convention — any plan that says "extend Posix\Foo" must use composition instead. Reviewed during step 01.06.

---

## Done log

(One line per completed real step. Helps the supervisor and any
late-joining session see what already shipped.)

step 01.01 · PR#490 · plans: add x-windows.md stub plan + MATCHUPS.md TODO
review for step 01.01 · clean · PR#490
step 01.02 · PR#491 · PARTIAL — add .gitignore + @dev→dev-master + CI cache keys; composer.lock deletion NOT executed (see open findings)
fix for step 01.02 · PR#492 · resolved 3 findings
step 01.03 · PR#493 · candy-pty: split onIdle from onSigwinch; de-TODO recorder-tap comment
fix for step 01.03 · PR#494 · resolved 3 findings
tests-ci for step 01.03 · clean
step 01.04 · PR#496 · candy-pty: add PumpOptions::sshDefault() named constructor
review for step 01.04 · clean · PR#496
docs for step 01.04 · PR#497 · document PumpOptions::sshDefault() in README + docs/lib/candy-pty.html
step 01.05 · PR#498 · candy-core: drop stty shell-outs from PosixBackend
review for step 01.05 · clean · PR#498
step 01.06 · PR#499 · candy-pty: slim Pty facade via composition (Spawn/Child/Master left at minimal sizes; original step prescription was structurally impossible)
candy-core-gitignore · PR#500 · candy-core: add composer.lock to .gitignore (untracked 72KB lock file issue)
path-repo-5-libs · PR#501 · sugar-bits/sugar-charts/sugar-dash/candy-sprinkles/candy-vt: add path-repo entries for local sugarcraft/* deps (leftover 01.02)
step 01.07 · PR#502 · candy-shell: RealProcess kept as deprecated alias; Process interface aligned with candy-pty/Contract
review for step 01.07 · clean · PR#502
docs for step 01.07 · clean · PR#502
tests-ci for step 01.07 · PR#503 · add stdoutBytes/stderrBytes forwarding tests to FakeProcess
step 01.08 · PR#504 · candy-pty: add SUGARCRAFT_PTY_BACKEND env var for backend selection
fix for step 01.08 · PR#505 · add deferred-backend-exception CALIBER entry
docs for step 01.08 · PR#506 · document SUGARCRAFT_PTY_BACKEND in end-user/admin/dev docs
step 01.09 · PR#507 · candy-pty: PtyPool ReactPHP test + MultiPump example + Expect withRecorder
review for step 01.09 · clean · PR#507
docs for step 01.09 · clean
 step 01.10 · PR#508 · candy-vcr: RecordCommand polish — SIGINT rescue + env-allow-secrets + cassette doc + ShirleyHtopTest
 review for step 01.10 · clean · PR#508
 tests-ci for step 01.10 · PR#509 · add testFilteredHostEnvWithEmptyStringSkipsAllFiltering for --env-allow-secrets empty-regex path
 docs for step 01.10 · PR#510 · document --env-allow-secrets in end-user hub-admin dev docs + CALIBER entries
review for step 01.10 · clean · PR#508
tests-ci for step 01.10 · PR#509 · add testFilteredHostEnvWithEmptyStringSkipsAllFiltering for --env-allow-secrets empty-regex path
 step 01.11 · PR#511 · tools: add --fix flag to check-path-repos.php
 review for step 01.11 · clean · PR#511
 docs for step 01.11 · PR#512 · document --fix in CONTRIBUTING.md + docblock
step 01.12 · PR#513 · candy-pty: SIGHUP delivery fix (FD_CLOEXEC on master fd + libc close after fclose) + SignalForwarderReactLoop/SIGHUPForwarding/NoControllingTerminal integration tests; resolved blocker (was two narrow bugs, not a PHP architectural gap)
tests-ci for step 01.12 · clean
 docs for step 01.12 · PR#515 · improve PHPDoc on PosixMasterPty::close() + PosixPtySystem::open(); log fd-dup-close-after-fclose and fd-cloexec-on-master in CALIBER
step 01.13 · PR#516 · candy-mosaic + candy-core: TtyDetect static helper (TermiosFactory::open->isAtty) + WezTerm detection fix (Kitty only, not both Kitty+iTerm2) + tests
review for step 01.13 · clean · PR#516
docs for step 01.13 · clean
step 01.14 · PR#517 · candy-core: Editor + Open use PosixProcess (leftover-rollout step 01.14)
review for step 01.14 · clean · PR#517
step 02.01 · PR#519 · candy-sprinkles: Theme class (dark/light/dracula/tokyoNight/oneDark/githubDark/solarized* + with* + adaptive)
docs for step 02.01 · PR#520 · document Theme in README/end-user docs/PHPDoc/CALIBER_LEARNINGS.md
step 02.02 · PR#521 · candy-sprinkles: StyleParser SSOT port from sugar-dash (inline [text](fg:red) syntax)
review for step 02.02 · clean · PR#521
docs for step 02.02 · clean
step 02.03 · PR#522 · candy-palette: Probe class (colorProfile/isNoColor/isForceColor/reducedMotion) + ColorProfile enum (NoTTY/Ascii/Ansi/Ansi256/TrueColor)
review for step 02.03 · clean · PR#522
docs for step 02.03 · PR#523 · document Probe + ColorProfile in README + add CALIBER_LEARNINGS.md
step 02.04 · PR#524 · sugar-dash: Module aligned with Core Model (update returns [Module,?Cmd]) + LegacyModuleAdapter for compat
docs for step 02.04 · clean · PR#525
step 03.01 · PR#526 · sugar-dash: Grid reorg part 1 — move Foundation primitives + Layout enums from Grid/ (Options, ItemOptions, ItemWithOptions, StackedGrid, JustifyContent, AlignItems to Layout/; delete duplicate Grid/Buffer; update 91 example imports)
review for step 03.01 · clean · PR#526
docs for step 03.01 · clean · PR#527
step 03.02 · PR#528 · sugar-dash: Grid reorg part 2 — delete Grid chart duplicates (keep Plot/), move Features/Transformer to Card, delete Graph (canonical is Plot/Graph/), backward-compat re-exports for ChartDataPoint/WaterfallItem/WaterfallBarType; Grid Sparkline/SparkArea retained due to Plot API incompatibility
review for step 03.02 · clean · PR#528
docs for step 03.02 · clean
step 03.03 · PR#529 · sugar-dash: Grid reorg part 3 — events/Keys/State/Foundation moves + delete empty Grid/ dir (Event/Focus/Key* to Events/Keys/State; EdgeStyle/Segment to Foundation; Progress/ProgressRing to Plot/Chart; BC stubs for all moved files; chart duplicates deleted)
fix for step 03.03 · PR#530 · resolved 2 findings (Key files wrong dir + Grid/ 44 files) + PHP 8.4 type alias compat fix
fix for step 03.03 · clean · PR#530
docs for step 03.03 · clean · PR#531
step 03.04 · PR#532 · sugar-dash: fix ExternalModule proc_get_status pipes bug + migrate to PosixProcess + integration test
review for step 03.04 · clean · PR#532
tests-ci for step 03.04 · clean
docs for step 03.04 · clean
step 03.05 · PR#533 · sugar-dash: Color.php replaced with class_alias shim → Core\Util\Color; Style/Theme/Rect/Buffer/Cell docblocks added (dual-SSOT clarified); StyleParser kept (not drop-in compatible — see Blockers); 6 CALIBER_LEARNINGS entries
fix for step 03.05 · PR#534 · resolved 3 findings (StyleParser docblock + dead $clone in withPrimary + explicit nullable in setString); Finding 2 closed as false-positive (candy-sprinkles IS actively used in 15+ src/test files)
tests-ci for step 03.05 · clean
docs for step 03.05 · PR#535 · sugar-dash README + docs/dev/sugar-dash.md: dual-SSOT primitives section documenting Foundation\Style/Theme/Rect/Buffer/Cell/StyleParser distinctions vs canonical siblings; Color flagged as class_alias
step 03.06 · PR#536 · sugar-dash: built-in modules rewritten to Core\Model contract (immutable state, Cmd::tick for periodic refresh; Clock/System/Uptime/Generic all return [Module, ?Cmd]; Greeting static)
docs for step 03.06 · PR#537 · fix broken code example in sugar-dash dev guide (non-existent Core\Msg\TickMsg import + Msg::tick() call replaced with Clock\TickMsg + anonymous Msg)
review for step 03.06 · clean · PR#536
step 03.07 · PR#538 · sugar-dash: dashboard-live.php interactive demo + VHS tape + README update
fix for step 03.07 · PR#539 · resolved 1 finding (dashboard-live already covered by sugar-dash in all=(...) matrix at line 83; VHS matrix is lib-level, not demo-level; acceptance criterion #4 satisfied)
docs for step 03.07 · PR#540 · document dashboard-live architecture in docs/dev/sugar-dash.md (Boxer+FocusManager+DashboardModel pattern, per-panel tick routing, keyboard handling)
 step 03.08 · PR#541 · sugar-dash: WeatherModule with wttr.in fetch + 30min cache + fallback + 15 tests (leftover-rollout step 03.08)
review for step 03.08 · clean · PR#541
 step 03.10 · PR#545 · sugar-dash: Breakpoint helper (narrow/medium/wide/pick) + StackedGrid collapse-to-single-column at width < 90; COLUMNS env var in dashboard-live
 review for step 03.10 · clean · PR#545
  tests-ci for step 03.10 · clean
  docs for step 03.10 · PR#546 · sugar-dash: document Breakpoint in README + dev docs (narrow/medium/wide/pick, thresholds 90/140, StackedGrid collapse)
  step 03.11 · PR#555 · sugar-dash: Plot::draw(Buffer) writes cells directly — BrailleCanvas::cells() generator + rewrite draw() to write Cell objects via $buffer->grid mutation (matching Buffer::draw() pattern); Buffer::$grid made public; 7 new tests in PlotDrawIntoBufferTest.php; 5136 tests green
  review for step 03.11 · clean · PR#555
  tests-ci for step 03.11 · clean
  docs for step 03.11 · clean
  step 03.12 · PR#556 · sugar-dash: split State/State.php — TransitionType/StateNode/StateTransition/StateMachine to Components/Tree/ (PSR-4 one-class-per-file); add State/Persistence.php (atomic tmp+rename); BC class_alias re-exports; persistState/restoreState wired into FocusManager/Boxer/StackedGrid; 5141 tests green
  review for step 03.12 · clean · PR#556
  tests-ci for step 03.12 · clean
  docs for step 03.12 · clean · PR#557

## Open review findings — 03.05

- [x] sugar-dash/src/Foundation/StyleParser.php: missing dual-SSOT clarifying docblock (all other 5 retained types got one; StyleParser is the riskiest omission — future dev could swap in Sprinkles\StyleParser and break $cell->style->foreground->r assertions)
- [→] sugar-dash/composer.json: `"sugarcraft/candy-sprinkles": "dev-master"` is a phantom dep — **FINDING INCORRECT**: grep of sugar-dash/src and sugar-dash/tests shows 15+ active `use SugarCraft\Sprinkles\*` imports (Style, Border, VAlign, Layout, Position) across Spinner.php, Pad.php, Window.php, Frame.php, StackedGrid.php, Gauge.php, Bullet.php, and 3 test files. The dep is real and must stay. No path-repo is needed for `dev-master` constraints (only `@dev` triggers the path-repo requirement — confirmed by check-path-repos.php logic). Closing as false-positive, no action taken.
- [x] sugar-dash/src/Foundation/Theme.php:332: dead `$clone = clone $this;` in withPrimary() — assigned but never read before `return new self(...)`
- [x] sugar-dash/src/Foundation/Buffer.php:122: implicit nullable `Style $style = null` should be `?Style $style = null` (PHP 8.4 deprecation; failOnWarning=true in phpunit.xml)

## Open review findings — 02.03

- [x] candy-palette/README.md: new Probe class + ColorProfile enum not yet documented (docs sub-step needed, matching pattern from 02.01 docs PR#520 / 02.02 docs entry) — resolved PR#523

## Open review findings — 01.08

- [x] candy-pty/CALIBER_LEARNINGS.md: new UnsupportedPlatformException + forDeferredBackend() pattern not logged — needs [pattern:deferred-backend-exception] entry so phase-12 implementers know to remove the throw when wiring sidecar/pecl
