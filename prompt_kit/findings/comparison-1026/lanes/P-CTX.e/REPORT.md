# P-CTX.e — Shell wiring: docked Context pane + clickable status-bar meter

**Commit:** a0c6e06b2 on cc/P-CTX.e (base c2477fe71). 19 files, +773/−48. Author/committer detain@interserver.net verified (separate %ae/%ce greps = 1), zero Co-Authored trailers. Never pushed.

## Landed (per brief)
- `Pane::Context` dockable (icon ◔ U+25D4 — Width::of=1, mb_strwidth=1 measured; UCD16 EAW 25D2..25E1;N adjudicated), in `tabCycle()` (8) and `MenuBar::PANE_TABS`; `Tui\Renderer::renderPane()` arm through `keyed()` over `ContextPane::layout(App,w,rows)` (new adapter: `model()`/`heldCategories()` over `TurnRunner::heldContext` + `Chat::contextTokens/contextTokenLimit/inFlight/contextLedgerView()->freedTokens` + a cachePercent walk cross-reffing `Renderer::cacheIndicator`).
- App: `contextPaneExpanded`/`contextDrill` fields + `contextPaneToggle()`/`contextPaneDrill()`; `dispatchChromeClick` siderow arm (header expands, category rows drill/undrill, `raw` runs `/context --raw` via runCommand); pane keys Enter/Space expand-then-drill, Esc/← back (claims() gates Space/Left for focused+docked Context; Left at foot consumed as no-op so it cannot leak to nav).
- ctxmeter: `Renderer::CTXMETER_ZONE` wraps the status-bar context segment (gated on `Chat::mouseClicksEnabled()` like every other zone — PaneClickTest pins that), `Chat::handlePointer` answers it with `DockPaneMsg('toggle','context')` (claimed region only: single hunk @ :6549). A fresh dock carries `measureContextCmd()` — required surgically fixing `App::update()`: my first splice accidentally re-terminated the withoutEngineCmd disjunction (SelectPaneMsg/UserInputMsg flowed into applyDockCommand — TypeError); final shape: batch arm restored contiguous, DockPaneMsg rides its own arm AFTER it (SelectPaneMsg subclass stays swallowed).
- Auto-dock §6.1 ON: Context in `AUTO_ENABLE_PANES`, activity gate = cols ≥ `AUTO_DOCK_CONTEXT_MIN_COLS`(120) AND held measurement exists; PaneAutoShow machinery honours a hand close for good.
- KeyBindingRegistry: 15th group "Context pane" (`contextPane.expand` 'Enter / Space', `contextPane.drill` 'Enter / Space', `contextPane.back' 'Esc / ←') + `mouse.context-meter`; 147→151 rows, KeyBindingRegistryTest + KeyBindingDriftTest moved IN-STEP (3 keyboard observations + meter observation, arrow-glyph count 21→22). lang: `tui.pane.label.context`, `keys.context.context-pane`, `keys.contextPane.*`, `keys.mouse.context-meter`; README pane-docking paragraph (seven panes, auto-show sentence) + `/context` section box sentence.

## Tests
Created `tests/Tui/ContextPaneShellTest.php` (7T/665A): frame-never-over-wide sweep (60–160 cols × collapsed/expanded/drilled), category click drills, collapsed-header click expands, meter toggles dock both ways + measurement closure carried, auto-show docks once on resize-past-floors, hand close ends it for good, narrow (119) and readability-refused (130, chat band 66<80 on default files-left dock) stay pending. In-step: PaneTest (11 cases, 8-tab cycle/glyph, ◔ whitelist row), PaneReverseCycleTest (previous-ring pair), KeyboardHandlerTest (11 panes, budget 4180, histogram re-measured), MenuBarCompactStripTest (8 tabs; density ladders absorbed), MenuBarMouseTest (120→128 pins re-measured for 8th tab), KeyBindingRegistryTest, KeyBindingDriftTest.

## DURATIONS (gate adds)
- `candy-code/tests/Tui/ContextPaneShellTest.php` (NEW file).

## Gates (cwd candy-code, plain pipe)
`--filter 'ContextPane|Pane|MenuBar|KeyBinding|KeyboardHandler|ShellContrast|StatusBar|Dock|Chrome|Menu|TurnRunner|Context|GlobDialect|DuplicatedTestHelperDrift|StderrEmitterCensus|Readme|DocFigure'` → 3592T/101882A: reds = ReadmeSuiteFigureDrift staleness pair (merge-owned) + ShellContrastTest::testNoFileUnderSrcTuiNamesAColourItself — PROVEN foreign-inherited: the two offenders (`AgentColor.php:93`, `WindowStrip.php:362` Color::hex) exist verbatim at base c2477fe71 (P-TABS.a lanes); `git diff -- src/` adds zero `Color::` lines. P-TABS welder owes the COLOUR_CONSTRUCTORS_ALLOWED adjudication row.

## Deviations (disclosed)
1. Brief lang prefix `tui.pane.title.context` family does not exist (box title is welded `tui.context.title`); used house families `tui.pane.label.context` + `keys.context.context-pane` + `keys.contextPane.*`.
2. Brief said PaneTest carries the tabCycle↔PANE_TABS pin — it is dynamic in PaneReverseCycleTest (reflection), auto-followed; PaneTest got the count/docblock/glyph in-step flips instead.
3. Row ids `contextPane.expand/drill/back` (+`mouse.context-meter`) — 4 live rows, all observation-pinned.
4. §6.1 ≥120 gate is necessary-not-sufficient: the existing auto-dock readability floor (chat band ≥80) binds first — on the default files-left dock the box lands at ~160 cols; refusals never settle, so a later resize retries (tested). No src change to the doctrine.
5. `update()` DockPaneMsg de-swallowed (see above) — behaviour otherwise identical (every other applyDockCommand arm returned null before and does now).

## Seam notes
- App auto-dock runs on WindowSizeMsg transitions too (discovered while testing) — consistent with the settled/pending doctrine, exercised in test 5.
- `Chat::measureContextCmd` never null when a chat exists; App-side carriers are null-safe (`$this->chat?`).

## Round 2 — review fixes (commit e570ec06a, branch cc/P-CTX.e, base era c2477fe71)
Disk-verified; NEVER pushed (owner law). Files touched: candy-code/tests/Renderer/KeyHelpTest.php + src/App/App.php + src/Tui/Components/ContextPane.php (src = docblocks only).

[MAJOR] KeyHelpTest offset/height pins re-armed to the 15-group/151-row list (MEASURED, not arithmetic):
- :1148 provider → [[100, 30, 155], [100, 185, 0]] (100x30 overflow 149→155; box now fits only at 185 rows, not 179).
- :1211/1223 non-scrolling-footer fixture 179→185 rows + changelog comment re-armed (174→180 content lines; 147→151 live rows, 14→15 headers, 13→14 separators).
- Sweep found TWO further in-step breaks the lane's own edits caused, both pinned green here: (1) `testScrollingThroughItShowsEveryLiveBinding` — the box's description room is KEY_HELP_COLS(64) − widest chord(14) − 1 = 49; P-CTX.e pushed `mouse.side-row` (57) and `mouse.context-meter` (50) over it, so a full-string "never painted" assert was wrong; rewrote to assert each row surfaces in full-or-ellipsis-clipped form plus an overflow roster pin `['mouse.side-row','mouse.context-meter']`. (2) `testWithAPromptUpNeitherKeyReachesItsOverlay` — the new clickable status-bar meter marks exactly one zone (`ctxmeter`) even at a live prompt; flipped the "marks no click zones at all" pin to `['ctxmeter']` (refusal still enforced downstream by Chat::refuseMouseDispatch, docblock corrected).
- Gate `--filter 'KeyHelp'` → 64T/96598A, 0F/0E (was 4 failures). Deprecations 2 = pre-existing.

[MINOR] App.php:2819-2821 — `SelectPaneMsg` implements Msg (not extends); docblock now says it rides the batch withoutEngineCmd arm (:2808), a focus chooser, not a docking mutation.
[MINOR] App.php:2180-2181 — inverted polarity corrected: `($this->cols ?? 0) >= 120` REFUSES on null width (0 never reaches 120), opposite of AUTO_DOCK_MIN_CHAT_COLS' stand-down; offer stays pending, retried on resize.
[MINOR] ContextPane.php:26-30 — "arrives with P-CTX.e, which WILL adapt" → "arrived with P-CTX.e, which adapted" (this IS P-CTX.e).

Gates (plain pipe, env -u LINES -u COLUMNS, cwd worktree candy-code): KeyHelp 64T/0F/0E; --filter 'ContextPane|Pane|MenuBar|KeyboardHandler|KeyBinding' 1188T/36053A/0F/0E; five-guard 133T → ONLY the ReadmeSuiteFigureDrift staleness pair (25,537 pinned vs 26,032 live — merge-owned).
