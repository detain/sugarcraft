# C-TUI-3.g — Away recap wiring + `/recap` — STATUS: DONE

Verdict: SHIPPED. Focus-gated away recap + on-demand `/recap`, all touched guards green in-step. Branch `cc/C-TUI-3.g` at /home/sites/cc-cmp-C-TUI-3.g, base master 364a52876.

## Commits (cat-file'd same-shell, both type=commit)
- `56d082b04ce58e557cff53182c3b4ee8d9b31664` — C-TUI-3.g: away recap wiring + /recap (11 files, +727/−2)
- `28df4f6db5d53106e21006ff0ede1efab6261d03` — C-TUI-3.g: docs roster + in-step guards (6 files, +44/−39)
Both: %ae and %ce separate greps = 1 `detain@interserver.net` each, zero Co-Authored-By, explicit-path staging, tree clean at close.

## Files (17 total vs base)
create: `src/Tui/AwayTracker.php` · `builtin-commands/3510-recap.php` · `tests/Chat/AwayRecapTest.php` (NEW, 13T) · `tests/Tui/AwayTrackerTest.php` (NEW, 9T)
modify: `src/Chat.php` (programOptions reportFocus:true, handleRecapCommand, READ_ONLY_COMMANDS += recap) · `src/Chat/Handlers/HandlesRunControl.php` (FocusLostMsg/FocusGainedMsg arms + awayRecapOnFocusBack) · `src/Config/Settings/Definitions/InterfaceSettings.php` (sessions.awayRecap Bool default-true, Narrowing/Layered/ProjectSettable/Live, readerSymbol AwayTracker::recapEnabled) · `src/Config/LayeredSettings.php` (both lists) · `src/Config/Settings/UiSettings.php` (KEYS) · `lang/en.php` (cmd.recap.description, settings.sessions.awayRecap.label/.help, chat.recap.spend_cap) · guards/docs: `tests/Chat/RouteTraitParityTest.php`, `tests/Chat/ApplySettingsTest.php`, `tests/Commands/CommandRegistryTest.php`, `README.md`, `docs/COMMANDS.md`, `docs/SETTINGS.md`, `docs/ARCHITECTURE.md`.
Never touched: suite-figure.json, parallel-tests-durations.tsv, docs/protocol/*, generated.ts, dist (verified: 0 forbidden files in `git diff --name-only 364a52876..HEAD`).

## New test files → durations rows owed by gate/welder
- `candy-code/tests/Chat/AwayRecapTest.php`
- `candy-code/tests/Tui/AwayTrackerTest.php`

## Auto-arm semantics (as designed, recap WITHOUT echo row)
FocusLostMsg stamps AwayTracker; FocusGainedMsg consumes the stamp (absence ends regardless), then fires only when elapsed ≥ 180 s, not inFlight, empty draft, sessions.awayRecap on, titleBackend present, spend cap not reached, ≥ 3 agent-visible user turns — via `Cmd::promise(BtwHostCommand::call($titleBackend, RecapHostCommand::PROMPT, $history, $currentSessionId, framing: RecapHostCommand::FRAMING, failureKey:'host.recap.failed'))`. One recap per absence (pinned). RecapHostCommand existed on master and is REUSED, not recreated.

## Gate figures (targeted only, cwd=candy-code, plain pipe; PtyRun exclusion honored — not in any filter)
- Final combined (14 families incl. AwayRecap/AwayTracker/Btw/HostedRuntimeNoticeWake/RouteTraitParity/DocFigure/ReadmeRoster/CommandDocs/SettingsSchemaDoc/LangParity/CommandRegistry/SlashDispatch/PaletteCatalog/SettingsPane): **Tests 239, Assertions 9122, 0F**, OK-but-issues 2 (house PHPUnit Deprecations, base-present).
- Doc-guard septet re-run on committed tree: **Tests 184, Assertions 10479, 0F**, same 2 deprecations.
- AwayRecapTest 13T green · AwayTrackerTest 9T/15A green · RouteTraitParityTest 4T/44A green.

## Deviations from design block (reasons)
1. `CommandRegistryTest::testFilterIsCaseInsensitiveAndKeepsPrefixMatches` flipped in-step ('re'/'RE' expected lists gain recap, sorts first) — roster-driven pin, §1.8 law.
2. `ApplySettingsTest:346` session-tier pinned array re-derived 75→81 from live SettingsWriter::sessionKeys(): my sessions.awayRecap + FIVE foreign keys folded (promptCache.keepWarm, autoReviewScope, redactToolOutput, requireReadBeforeWrite, diffStyle) — the pin was stale at base; re-derive was the only in-step cure.
3. `RouteTraitParityTest` roster gained the two Focus rows per design, plus foreign folds KeepWarmPingedMsg + RemoteFeedPumpMsg (dated comments) — same stale-roster class.
4. `ARCHITECTURE.md` 'dispatch arms for 66'→67 — DocFigure arm counts slashCommands(); new spec file forces it.
5. Auto path uses `BtwHostCommand::call(...)` with RecapHostCommand's PROMPT/FRAMING/failureKey — this IS RecapHostCommand's own effect shape; the design's "runs RecapHostCommand without the echo row" is satisfied by skipping runHostCommand's echo/row wrapper, same as the class's internal composition.

## Standing reds / seams (NOT widened, reported)
- `ApplySettingsTest::testEveryLiveKeyTheViewCanSaveHasAnApplyArm` RED: "promptCache.keepWarm is badged live but nothing applies it" — FOREIGN pre-existing at base; fix lives in Chat::applySettings (outside declared regions).
- `tests/Renderer/KeyHelpTest` 5 reds (+1 load-sensitive risky) — PROVEN foreign: fail identically with my spec file removed AND in a pristine base-364a52876 probe worktree (removed after).
- Fold seam (from lead's own e6418b595 disclosure, unchanged): the resolved SideQuestionAnsweredMsg fold at `HandlesTurnCompletion.php` renders the **btw-voice** heading for the auto recap row; recap-voiced TUI row needs a HandlesTurnCompletion touch = outside this lane's files. The fold is uiOnly (agentVisible unchanged) and usage is accounted to titleBackend.
- `COMPARISON.md:299` '64 built-ins' is stale (unguarded numeral) — left, welder/closeout call.
