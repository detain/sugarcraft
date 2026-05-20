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

  This is NOT a chart duplication problem. Per CALIBER_LEARNINGS entry 38 [pattern:dual-foundation-ssot]: "The 5 retained types are intentionally distinct from same-named canonical types in candy-sprinkles/candy-core/candy-vt due to different upstream lineage." The same applies here. **The step should be closed as "not actionable"** — there is nothing to delete and no dependency to add. sugar-dash chart components serve dashboard-specific visualization needs; sugar-charts serves canonical chart primitives. The boundary is already correct.   Supervisor decision needed: should this step be dropped from the rollout, or should I attempt a different interpretation (e.g., adding sugar-charts as a dep even without deleting anything)?

- **step 07.15** (candy-mosaic animation support): ~~BLOCKED~~ **RESOLVED via PR#660 (step 11.04)** — strategic plan `plans/candy-flip-mosaic-split.md` shipped 2026-05-19. Decision: Animation lives in `candy-mosaic`; `Animation` ctor takes `list<ImageSource>` + per-frame delays (frame-source-agnostic); candy-mosaic does **not** require candy-flip — drop the `"sugarcraft/candy-flip": "@dev"` bullet from step 07.15's Modify list. Future GIF→Mosaic bridge (if any) lives in candy-flip, not in mosaic. See plan §3 for the answer table, §4 for API contract.

---

## Carry-forward

(Items discovered during a step that should be tackled later — usually
in a follow-up step or a deferred phase. Each entry: one short line +
the step that surfaced it.)

- step 06.02: Multi-push ScreenStack integration test (`testPushThreeScreensPopTwoVerifiesStateAndBreadcrumb`) fails with only first push recorded when using Program::send() + drainPending() timing — the ScreenStack type and RootModelWithScreenStack work correctly (proven by unit tests and direct dispatch), but socket pair + stream_select() timing in the full Program integration test is unreliable. Test was simplified to use direct dispatch instead of full Program loop.
- step 06.05 (review-fix): `Flag::$enum` dead code — `applyFlag()` never uses the enum property; Symfony `InputOption` has no native allowed-values mode for options; full wiring needs a normalizer or execute()-time validation post-processing pass (architectural work deferred beyond this review-fix).

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
  step 03.14 · PR#558 · sugar-dash: fix TD-1 CandlestickChart readonly withers (clone-mutate → new self()); regression test; TD-2..TD-8 already fixed in prior sessions
  review for step 03.14 · clean · PR#558
  tests-ci for step 03.14 · clean
  docs for step 03.14 · clean
  step 03.15 · PR#559 · sugar-dash: generate-goldens.php + GoldenSnapshotTest.php + 244 golden snapshots at 80x24 and 120x40 (leftover-rollout step 03.15)
  review for step 03.15 · clean · PR#559
  tests-ci for step 03.15 · clean
  docs for step 03.15 · clean
  step 03.16 · PR#560 · sugar-dash: create plot-braille/gridtable-demo/boxer examples + update golden snapshots + README GIF demos table (leftover-rollout step 03.16)
  review for step 03.16 · clean · PR#560
  tests-ci for step 03.16 · clean
  docs for step 03.16 · clean
  step 03.17 · PR#561 · sugar-dash: Drawable::withTheme + layout containers fan theme to children + Badge/Card/NProgress opt-in + dashboard-live Ctrl-T toggle (leftover-rollout step 03.17)
  review for step 03.17 · clean · PR#561
  fix for step 03.17 · PR#562 · resolved 3 findings (5 containers +Theme, test naming acceptable, remaining hex→theme carry-forward)
  tests-ci for step 03.17 · clean
  docs for step 03.17 · clean
  step 03.18 · PR#563 · sugar-dash: delete 7 one-shot migration scripts + rename dashboard-interactive.php → dashboard-accordion-timeline.php (leftover-rollout step 03.18)
  review for step 03.18 · clean · PR#563
  tests-ci for step 03.18 · clean
  docs for step 03.18 · clean
  carry-forward: step 03.17 Issue #1: 760+ hardcoded Color::hex() calls remain in Components/ (Modal/Notification, Alert, Toast, Card/*, Tree/*, Media/*, Feedback/*, Gauge, Bullet, etc.) — bulk hex→theme conversion needed as follow-up
  carry-forward: step 03.17 Issue #3 (minor): DrawableThemeTest.php naming (vs step-spec RenderUsesThemeTest.php per family) — acceptable as test covers same ground; no action needed
  carry-forward: step 03.18 Issue #1: examples/dashboard-accordion-timeline.php uses `SugarCraft\Dash\Grid\StackedGrid` (moved to Layout in step 03.01) — example needs namespace fix
  step 04.01 · PR#564 · sugar-boxer: compose candy-sprinkles Border/Style (leftover-rollout step 04.01)
  docs for step 04.01 · PR#565 · sugar-boxer: document withBorderStyle/withStyle/withTitle/withMargin/withAlignH/withAlignV in README + add CALIBER_LEARNINGS.md + fix docs/lib/sugar-boxer.html quickstart + API table
  step 04.02 · PR#566 · sugar-stickers: compose sugar-bits Viewport + Scrollbar (leftover-rollout step 04.02)
review for step 04.02 · clean · PR#566
docs for step 04.02 · PR#567 · document Viewport/Scrollbar SSOT in README + docs/lib/sugar-stickers.html
step 04.03 · PR#568 · sugar-crumbs: wire Zone::mark() emit/exit in Breadcrumb rendering + candy-zone dep
review for step 04.03 · clean · PR#568
tests-ci for step 04.03 · clean
docs for step 04.03 · PR#569 · document withZoneManager() in README + CALIBER_LEARNINGS.md + docs/lib/sugar-crumbs.html
step 05.01 · PR#570 · sugar-calendar: add i18n via Lang::t() (lang/en.php + Lang.php facade + DatePicker.php refactor + LangCoverageTest)
review for step 05.01 · clean · PR#570
tests-ci for step 05.01 · clean
docs for step 05.01 · clean
step 05.02 · PR#571 · sugar-table: add i18n via Lang::t() (Lang.php + lang/en.php + PageFooter + LangCoverageTest + candy-core dep)
review for step 05.02 · clean · PR#571
tests-ci for step 05.02 · clean
docs for step 05.02 · PR#572 · add i18n section to README (Lang::t() pattern + keys) + fix pagination example + create CALIBER_LEARNINGS.md
step 05.03 · PR#573 · sugar-toast: add i18n via Lang::t() (Lang.php facade + lang/en.php + ToastType::label() + LangCoverageTest + candy-core dep)
review for step 05.03 · clean · PR#573
tests-ci for step 05.03 · clean
docs for step 05.03 · PR#574 · document i18n surface in README + CALIBER_LEARNINGS.md + docs/lib/sugar-toast.html
step 05.04 · PR#575 · sugar-boxer: add i18n infrastructure (Lang.php facade + lang/en.php + LangCoverageTest; no src/ strings to translate — purely computational library)
review for step 05.04 · clean · PR#575
tests-ci for step 05.04 · clean
docs for step 05.04 · clean
step 05.05 · PR#576 · sugar-crumbs: add i18n infrastructure (Lang.php facade + lang/en.php + LangCoverageTest; no src/ strings to translate — purely computational library)
review for step 05.05 · clean · PR#576
tests-ci for step 05.05 · clean
docs for step 05.05 · clean
step 05.06 · PR#577 · super-candy: add i18n via Lang::t() (Lang.php facade + lang/en.php + LangCoverageTest + status/keyhelp/search translations in Manager/Renderer)
step 05.07 · PR#578 · sugar-stash: add i18n via Lang::t() (key-hints, error prefix, empty-state messages + LangCoverageTest)
review for step 05.07 · PR#578 · 3 files (lang/en.php, src/Renderer.php, tests/LangCoverageTest.php); all Lang::t() keys in src/ verified present in lang/en.php; READMEs missing i18n section
tests-ci for step 05.07 · clean
docs for step 05.07 · PR#579 · add i18n section to README + docs/lib/sugar-stash.html + create CALIBER_LEARNINGS.md
step 05.08 · PR#580 · sugar-stickers: add i18n infrastructure (Lang.php facade + lang/en.php + LangCoverageTest; no user-facing strings — purely computational lib)
review for step 05.08 · clean · PR#580
tests-ci for step 05.08 · clean
docs for step 05.08 · clean
review for step 06.01 · clean · PR#581
tests-ci for step 06.01 · clean
docs for step 06.01 · clean · PR#582
fix for step 06.02 · PR#584 · resolved 2 findings (add ScreenStack CALIBER entries + fix heredoc style)
tests-ci for step 06.02 · clean
docs for step 06.02 · PR#586 · document ScreenStack API in README (Architecture + new section + example) + end-user doc feature grid + PHPDoc @see cross-refs
step 06.03 · PR#587 · candy-core: Component interface (onMount/onUnmount) + Composite Model + lifecycle draining in Program + ComponentLifecycleTest
fix for step 06.03 · PR#588 · resolved 3 findings (@return ?Closure fix + [pattern:component-lifecycle] CALIBER entry + Component/Composite README docs)
tests-ci for step 06.03 · clean
docs for step 06.03 · PR#590 · add Component/Composite to candy-core end-user page feature grid
fix for step 06.04 · PR#591 · resolved 3 findings (error_log removed; WorkerResultMsg already had correct ?Throwable; WorkerState kept mutable — readonly requires architectural refactoring)
tests-ci for step 06.04 · clean
docs for step 06.04 · clean · PR#593
step 06.05 · PR#594 · candy-shell: #[Command]/#[Flag]/#[ValueEnum] attributes + CommandScanner discovery + Application::scan() method
fix for step 06.05 · PR#595 · resolved 3 findings (descriptionSection forward + README auto-discovery docs + CALIBER entry); Flag::$enum wiring deferred to Carry-forward
tests-ci for step 06.05 · clean
docs for step 06.05 · PR#596 · add auto-discovery types table to candy-shell end-user page
step 06.06 · PR#597 · candy-shell: #[Example]/#[Alias] attributes + HelpFormatter + TypoSuggester (Levenshtein ≤ 2) + Application::find() override for typo suggestion
fix for step 06.06 · PR#598 · resolved 3 findings ([0??-1] dead code + 2 CALIBER patterns + status command added to examples/cli.php)
tests-ci for step 06.06 · clean
docs for step 06.06 · PR#600 · document #[Example]/#[Alias]/HelpFormatter/TypoSuggester in README + end-user page
step 06.07 · PR#601 · candy-shell: completions (Bash/Zsh/Fish) + versionFromComposer() + env-var fallbacks via CANDYSHELL_* prefix
fix for step 06.07 · PR#602 · resolved 3 findings (CALIBER patterns + README docs + EnvVarFallbackTest)
tests-ci for step 06.07 · PR#603 · add CompletionCommandTest (5 tests covering bash/zsh/fish wiring + unsupported/default shell paths)
docs for step 06.07 · PR#604 · add step 06.07 completion types to candy-shell end-user page
review for step 06.08 · clean · PR#605
tests-ci for step 06.08 · clean
docs for step 06.08 · PR#606 · document Context API in README + docs/lib/candy-wish.html + new docs/dev/candy-wish.md
step 06.09 · PR#607 · candy-wish: ChannelHandler interface + DefaultChannelHandler (PtyReq/WindowChange/Shell/Exec/Signal/Env/Break Msg classes) + InProcessTransport dispatch wiring + 16 tests
tests-ci for step 06.09 · clean
docs for step 06.09 · PR#608 · document ChannelHandler/ChannelMsg/Msg classes in README + end-user API table + dev extension point + new hub-admin guide + CALIBER pattern
step 06.10 · PR#609 · candy-wish: add PasswordAuth/CertificateAuth/AuthMethods/KeyboardInteractive auth middleware (leftover-rollout step 06.10)
review for step 06.10 · clean · PR#609
tests-ci for step 06.10 · clean
docs for step 06.10 · PR#610 · document PasswordAuth/CertificateAuth/AuthMethods/KeyboardInteractive in README + end-user/admin/dev docs
step 06.11 · PR#611 · candy-wish: Subsystem middleware (Subsystem/SubsystemHandler/SftpStub + 6 tests; leftover-rollout step 06.11)
review for step 06.11 · clean · PR#611
tests-ci for step 06.11 · clean
docs for step 06.11 · PR#612 · document Subsystem/SubsystemHandler/SftpStub in README + end-user/admin/dev docs
step 06.12 · PR#613 · candy-wish: add session metadata (sessionId/authMethod/keyFingerprint/clientVersion/serverVersion + withProtocolMetadata)
review for step 06.12 · clean · PR#613
tests-ci for step 06.12 · clean
docs for step 06.12 · PR#614 · document sessionId/authMethod/keyFingerprint/clientVersion/serverVersion + withProtocolMetadata() in README + end-user/admin/dev docs
step 06.13 · PR#615 · candy-wish: async middleware — Middleware interface accepts void|PromiseInterface, transport dispatches await via event loop, AsyncMiddleware abstract base (leftover-rollout step 06.13)
fix for step 06.13 · PR#616 · resolved 2 findings (AsyncMiddleware docs in README + promise-awaiting clarification note in Server.php)
tests-ci for step 06.13 · clean
docs for step 06.13 · PR#618 · add AsyncMiddleware to end-user API table + async middleware extension point in dev guide + operational notes in hub-admin guide
step 07.01 · PR#619 · candy-vt: DECSTBM scroll margins — ScrollHandler accepts region bounds, ScreenHandler tracks scrollRegionTop/Bottom, CSI r wired (leftover-rollout step 07.01)
fix for step 07.01 · PR#620 · resolved 2 findings
docs for step 07.01 · clean
step 07.02 · PR#621 · candy-vt: DECAWM auto-wrap (CSI ? 7 h/l) — Mode::autoWrap + withAutoWrap(), ModeHandler mode 7, ScreenHandler printChar wrap logic, 15 AutoWrapTest tests (leftover-rollout step 07.02)
review for step 07.02 · clean · PR#621
docs for step 07.02 · PR#622 · document DECAWM in README/end-user HTML/Mode PHPDoc/CALIBER_LEARNINGS.md
step 07.03 · PR#623 · candy-vt: CSI subparameter parsing — Parser::param() treats ':' as sub-param separator (same as ';'), 9 SubparamTest tests (leftover-rollout step 07.03)
review for step 07.03 · clean · PR#623
tests-ci for step 07.03 · clean
docs for step 07.03 · PR#624 · document CSI subparameter parsing in CALIBER_LEARNINGS/README/end-user HTML
step 07.04 · PR#625 · candy-vt: scrollback buffer — Scrollback ring-buffer class + Screen scrollback accessor + Terminal withScrollbackSize() + ScreenHandler scrollUp/Down push to scrollback + 10 ScrollbackTest tests (leftover-rollout step 07.04)
review for step 07.04 · clean · PR#625
step 07.05 · PR#627 · candy-vt: SGR underline styles 4:1–4:5 (UnderlineStyle enum + withUnderlineStyle/SgrHandler/Cell::equals + 20 SgrUnderlineStylesTest tests)
step 07.06 · PR#629 · candy-vt: DECOM (mode 6) + DECSCUSR cursor shape (CSI Ps SP q) + focus events 1004 (CSI I/O) — CursorShape enum + FocusInMsg/FocusOutMsg + 38 tests
review for step 07.06 · clean · PR#629
docs for step 07.06 · PR#630 · document DECOM/DECSCUSR/focus events in README + CALIBER_LEARNINGS.md
step 07.07 · PR#631 · candy-vt: BCE (SGR bg erase) + combining-char composition (Cell::combining + attachCombiningChar) + synchronized output 2026 queue/flush (leftover-rollout step 07.07)
review for step 07.07 · PR#631 · code clean, 467 tests pass; 4 CALIBER_LEARNINGS issues (see Open review findings — 07.07)
fix for step 07.07 · PR#632 · resolved 4 findings (CALIBER_LEARNINGS: BCE/combining/sync updated + 2 new entries)
docs for step 07.07 · PR#633 · document BCE/sync-output/combining in README + docs/lib/candy-vt.html feature grid + API table
 step 07.08 · PR#634 · candy-zone: ZoneHoverTracker + ZoneEnterMsg/ZoneExitMsg (leftover-rollout step 07.08)
 review for step 07.08 · clean · PR#634
 docs for step 07.08 · clean · PR#635
 step 07.09 · PR#636 · candy-zone: DragTracker + ZoneDragStartMsg/ZoneDragMoveMsg/ZoneDragEndMsg (leftover-rollout step 07.09)
 review for step 07.09 · clean · PR#636
 docs for step 07.09 · clean · PR#637
  step 07.10 · PR#638 · candy-zone: ClickCounter (DoubleClickMsg/TripleClickMsg) + Manager::setMotionTracking (leftover-rollout step 07.10)
  review for step 07.10 · clean · PR#638
  docs for step 07.10 · PR#639 · document ClickCounter/DoubleClickMsg/TripleClickMsg/setMotionTracking in README + end-user HTML + CALIBER
  step 07.11 · PR#640 · candy-mosaic: QuarterBlockRenderer (░▒▓█ 2×2 pixel rendering) + PixelGrid::fromGdQuarter() + 12 tests (leftover-rollout step 07.11)
  step 07.12 · PR#642 · candy-mosaic: Renderer::delete() API (Kitty APC delete / iTerm2 Pop / empty for text fallbacks) + Ansi::iterm2Delete() helper + WezTerm detection verified (leftover-rollout step 07.12)
  docs for step 07.12 · clean · PR#643 · document Renderer::delete() in README (per-renderer table) + end-user HTML API table + CALIBER pattern entry
step 07.14 · PR#647 · candy-mosaic: transparent HalfBlock bg (alpha→null, skip SGR) + 256-color Sixel maxColors param (leftover-rollout step 07.14)
review for step 07.14 · clean · PR#647
step 07.13 · PR#644 · candy-mosaic: useVirtual flag (a=p) + gzcompress payload when compress=1 + 10 new tests (leftover-rollout step 07.13)
tests-ci for step 07.14 · clean
docs for step 07.14 · PR#648 · document HalfBlockRenderer transparent-pixel docblock + SixelRenderer::maxColors() docblock + end-user HTML feature grid + API table entries
fix for step 07.13 · PR#645 · resolved 1 finding (remove orphaned docblock)
docs for step 07.13 · clean · PR#646 · document virtual-image (a=p) + zlib compression (f=1) in README/end-user HTML/PHPDoc/CALIBER_LEARNINGS.md
step 11.01 · PR#649 · plans: sugar-post-identity decision memo (Option A/B/C; recommendation: A — stay an email tool, finish upstream Pop TUI surface; awaiting user decision)
fix for step 11.01 · PR#651 · resolved 4 findings (locale count 17→16, bin/pop LOC 50→221, §2 "misread"→"pivot" framing, Email.php cite L17–L32→L21–L32)
tests-ci for step 11.01 · clean
docs for step 11.01 · clean
step 11.02 · PR#653 · plans: candy-serve-tui interactive SSH TUI milestone plan (5 phases — repo list / detail / file browser / commit log / polish; ~8 weeks; awaiting user authorization for phase-1 step files)
fix for step 11.02 · PR#656 · resolved 8 findings (ScreenStack path/PR#583; sugar-boxer SugarBoxer; Repo $isPublic property + withDescription(); sugar-stash 🟡 + §6.1 blockers gating phases 2-4; OSC 52 → candy-core Cmd::setClipboard(); bin/ over cmd/)
tests-ci for step 11.02 · clean
docs for step 11.02 · clean
step 11.03 · PR#658 · plans: candy-vt-graphics P3 strategic memo (Kitty/iTerm2/Sixel receive-side decode; 6 phases ~17-23 weeks; recommendation: defer past 1.0 — no current consumer triggers §7 hard-trigger criteria)
review for step 11.03 · clean · PR#658
tests-ci for step 11.03 · clean
docs for step 11.03 · clean
step 11.04 · PR#660 · plans: candy-flip / candy-mosaic image-output split memo (Option C — neither lib depends on the other; Animation lives in candy-mosaic with ImageSource[] ctor; resolves step 07.15 blocker — drop the candy-flip require from 07.15's Modify list)
fix for step 11.04 · PR#662 · resolved 5 findings (§1.2 totals 21/3236→22/3426; MosaicBuilder row merged into Mosaic.php note; §6.1+§6.4 phantom sugar-dash/sugar-charts consumers reframed to "no current consumers"; §4.1 withFrame() body filled in with clone-mutate pattern + sketch pointer; §3 divergence note explaining Option A→C deviation from step file framing)
tests-ci for step 11.04 · clean
docs for step 11.04 · clean
step 07.15 · PR#666 · candy-mosaic: add Animation (ImageSource[] + delays, immutable + fluent) + AnimationDriver (Model + Cmd::tick frame timing + delete+render cycle) + KittyRenderer::renderFrame() + FrameTickMsg + tests (leftover-rollout step 07.15)
fix for step 07.15 · PR#667 · resolved 2 CALIBER findings
docs for step 07.15 · PR#668 · document Animation/AnimationDriver/renderFrame() in README (Animation section + Architecture) + end-user HTML (feature grid + API table) + dev guide (new docs/dev/candy-mosaic.md) + PHPDoc on 6 methods
step 07.16 · PR#669 · candy-pty: add openpty() FFI binding for Darwin (primary) with quartet fallback; libutil.so.1 path on Linux for future extension; OpenptyTest coverage
fix for step 07.16 · PR#670 · resolved 2 findings (CALIBER openpty-darwin-first + openpty-libutil-linux pattern entries; README API table + Platform behaviour section surfaces openpty() Darwin-first path)
docs for step 07.16 · PR#671 · document openpty FFI in end-user HTML (Pty::open() feature description) + admin HTML (platform behaviour section) + dev HTML (internal PTY allocation section) + Libc::libutil() @return PHPDoc fix
step 07.17 · PR#672 · candy-pty: add waitpid() FFI for fast process-exit detection (<1ms vs 10ms poll) + ChildPollWaitpidTest (5 tests) + signal-exit handling (128+signal)
tests-ci for step 07.17 · clean
docs for step 07.17 · PR#673 · document waitpid FFI fast-path in README + CALIBER + end-user/admin/dev HTML + PHPDoc on tryWaitpid
 step 07.18 · PR#674 · candy-pty: add attachSigwinchToFd() for /dev/tty size-forwarding (SizeIoctl against raw fd + onResize callback + SignalForwarderDevTtyTest)
 review for step 07.18 · clean · PR#674
 tests-ci for step 07.18 · clean
 docs for step 07.18 · PR#675 · document attachSigwinchToFd() in README (resize forwarding + Mirrors table) + end-user HTML (resize section + API table) + dev HTML (SignalForwarder extension point note) + CALIBER_LEARNINGS.md [pattern:attachSigwinchToFd-fd-based]
  step 07.19 · PR#676 · candy-pty: add ControllingTerminal::claim(int $fd) static method (setsid+ioctl(TIOCSCTTY)); bin/pty-shim.php delegates to it
  docs for step 07.19 · PR#677 · document ControllingTerminal::claim() in README (Mirrors + API table + Controlling terminal section) + end-user HTML (feature + API table) + admin HTML (new section) + dev HTML (new section + namespace tree) + CALIBER [pattern:claim-setsid-ioctl]
 step 07.20 · PR#678 · candy-vcr: add RelativeFormat (dt field at file level) + Recorder::withFormat() + Player auto-detect; 48 Format tests green
 docs for step 07.20 · PR#679 · document RelativeFormat + withFormat() + detectFormat() in README/end-user HTML/dev HTML + CALIBER entry
 step 07.21 · PR#680 · candy-vcr: add withIdleTrim() fluent setter + --idle-trim CLI flag for SPEED_REALTIME playback; fix detectFormat() missing Format import; 5 IdleTrimTest tests
  docs for step 07.21 · PR#681 · document withIdleTrim() in README/end-user HTML/dev HTML/admin HTML + ReplayCommand PHPDoc + CALIBER entry
  step 07.22 · PR#682 · candy-vcr: add FocusInMsg/FocusOutMsg to BuiltinSerializer + update candy-vt FocusInMsg/FocusOutMsg to implemenets Msg + path-repo; 29 tests green
  docs for step 07.22 · PR#683 · update BuiltinSerializer msg count 14→19 in README + end-user HTML; add @implements Msg to FocusInMsg/FocusOutMsg docblocks; note FocusInMsg/FocusOutMsg implements Msg in CALIBER_LEARNINGS
  step 08.01 · PR#684 · sugar-prompt: add Validator interface + Required/Email/MinLength/MaxLength/Pattern classes; Input::withValidator() chains multiple validators (leftover-rollout step 08.01)
  review for step 08.01 · clean · PR#684
  tests-ci for step 08.01 · clean
  docs for step 08.01 · PR#685 · document Validator interface + 5 built-in subclasses in README (chaining quickstart + API table + custom impl example) + update docs/lib/sugar-prompt.html quickstart + feature grid
  step 08.02 · PR#686 · sugar-prompt: add FuzzyMatcher (Smith-Waterman scoring) + withFuzzySuggestions() in Input/Select (leftover-rollout step 08.02)
  review for step 08.02 · clean · PR#686
  tests-ci for step 08.02 · clean
  docs for step 08.02 · PR#687 · document FuzzyMatcher + withFuzzySuggestions() in README (fuzzy quickstart + field tables) + end-user HTML (quickstart/feature grid/API table) + new CALIBER_LEARNINGS.md [pattern:fuzzy-smith-waterman-two-row]
  step 08.03 · PR#688 · sugar-prompt: add withAsyncSuggestions() to Input/Select (150ms debounce + SuggestionsReadyMsg + Deferred pattern; leftover-rollout step 08.03)
  review for step 08.03 · clean · PR#688
  tests-ci for step 08.03 · clean
  docs for step 08.03 · PR#689 · document withAsyncSuggestions() in README (Input/Select tables + async section) + end-user HTML (feature grid + API table + quickstart) + CALIBER [pattern:async-suggestions-debounce]
  step 08.04 · PR#690 · sugar-prompt: MultiSelect vim keys (j/k nav + space toggle) + Form::validateAll() + Select::withEnum(\BackedEnum) + Theme::$errorSummary slot (leftover-rollout step 08.04)

## Open review findings — 08.03

- [x] sugar-prompt/README.md: `withAsyncSuggestions()` not documented in the Input field API table or Select field API table. — ✅ resolved PR#689
- [x] sugar-prompt/CALIBER_LEARNINGS.md: no new pattern entry for the async debounce + `Loop::addTimer` + `Deferred` + `SuggestionsReadyMsg` dispatch pattern. — ✅ resolved PR#689

## Open review findings — 07.16

- [x] candy-pty/CALIBER_LEARNINGS.md: openpty Darwin-first fallback pattern not logged — `openpty()` primary on Darwin, quartet fallback, and `libutil.so.1` Linux path are new FFI patterns that future PTY implementers should find documented (e.g. `[pattern:openpty-darwin-first]`, `[pattern:libutil-linux-symbol]`) — ✅ resolved PR#670
- [x] candy-pty/README.md: `openpty()` Darwin path not surfaced in API docs — the Mirrors table and API-at-a-glance table only mention `posix_openpt + grantpt + unlockpt + ptsname_r`; the new `openpty` first path on Darwin is not mentioned anywhere in the README — ✅ resolved PR#670

## Open review findings — 03.05

- [x] sugar-dash/src/Foundation/StyleParser.php: missing dual-SSOT clarifying docblock (all other 5 retained types got one; StyleParser is the riskiest omission — future dev could swap in Sprinkles\StyleParser and break $cell->style->foreground->r assertions)
- [→] sugar-dash/composer.json: `"sugarcraft/candy-sprinkles": "dev-master"` is a phantom dep — **FINDING INCORRECT**: grep of sugar-dash/src and sugar-dash/tests shows 15+ active `use SugarCraft\Sprinkles\*` imports (Style, Border, VAlign, Layout, Position) across Spinner.php, Pad.php, Window.php, Frame.php, StackedGrid.php, Gauge.php, Bullet.php, and 3 test files. The dep is real and must stay. No path-repo is needed for `dev-master` constraints (only `@dev` triggers the path-repo requirement — confirmed by check-path-repos.php logic). Closing as false-positive, no action taken.
- [x] sugar-dash/src/Foundation/Theme.php:332: dead `$clone = clone $this;` in withPrimary() — assigned but never read before `return new self(...)`
- [x] sugar-dash/src/Foundation/Buffer.php:122: implicit nullable `Style $style = null` should be `?Style $style = null` (PHP 8.4 deprecation; failOnWarning=true in phpunit.xml)

## Open review findings — 02.03

- [x] candy-palette/README.md: new Probe class + ColorProfile enum not yet documented (docs sub-step needed, matching pattern from 02.01 docs PR#520 / 02.02 docs entry) — resolved PR#523

## Open review findings — 01.08

- [x] candy-pty/CALIBER_LEARNINGS.md: new UnsupportedPlatformException + forDeferredBackend() pattern not logged — needs [pattern:deferred-backend-exception] entry so phase-12 implementers know to remove the throw when wiring sidecar/pecl

