# C-EXT-2.e — Command frontmatter model/agent/subtask — DONE (lane commit on cc/C-EXT-2.e, base 63f2de5d9, unpushed)

Verdict: the dormant command pair is LIVE. `subtask`/`agent` wrap the expansion in a fenced Task-delegation directive (one implementation, `TurnController::wrapCommandDelegation()`, applied at Chat::submit + SessionHost::admitExpanded; -p rides Chat); `model` pins exactly the command turn via `TurnController::commandTurnModel()` — the Task rule re-derived (alias/whitespace/served-model/single-model fallbacks → session model + notice row), applied at the launch-backend hoist only (`scheduleBackendCompletion`/`SessionHost::dispatch`), never stored, so restoration is structural. `FrontmatterKeyAudit::INERT[COMMAND]` emptied; COMMANDS.md rows flipped in-step; CommandSpec gains `agent`.

## Gates (cwd candy-code, env -u LINES -u COLUMNS, plain pipe)
- CommandLoader|FrontmatterKeyAudit|InertFrontmatter|BootstrapLaunchNotice: 99T/949A OK
- TurnController|SessionHostCommands: 60T/232A OK
- CustomCommand|ChatSubmit|MidTurnSpendCap: 102T/445A OK
- DocFigureProseDrift|CommandDocsDrift|LangParity|SymbolCitationDrift: 113T/23570A OK
- SessionHostTest|TurnRunner|InertFrontmatterDrift|FrontmatterKeyAuditTest: 77T/336A OK
- StderrEmitterCensus|DuplicatedTestHelperDrift|GlobDialectDifferential|ReadmeSuiteFigureDrift: 126T/21381A — ONLY the known staleness pair red (2 of 126; 25,537 pinned vs 26,597 live; ~1,042 pre-existing master drift + my 18; welder re-pin duty, lane forbade touching suite-figure.json/README)
- Focused trio re-run: 37T/125A OK. Red-before proven: base TurnController swap → routing 14E; base Chat swap → override-test 3F; restores cmp-identical. 2x "PHPUnit Deprecations" are baseline.

## New test files (weld owes durations.tsv rows)
- candy-code/tests/Host/TurnControllerCommandRoutingTest.php (14T)
- candy-code/tests/Chat/CustomCommandModelOverrideTest.php (4T)

## Deviations (disclosed)
1. Wrap/pin applied AFTER the empty-expansion refusal at both call sites (inside expandCustomCommand a wrapped empty expansion would resurrect the refused-prompt bypass).
2. Parked-compaction route DROPS the pin with a truth notice (HistoryCompactedMsg carries no model; DTO out of step files) — new key host.turn.command_model_parked.
3. BootstrapLaunchNoticeRoutingTest fixture flipped in-step (`subtask` → unknown `subtaskx`, inert pair retired; did-you-mean still earned).
4. host.turn.command_delegation re-quoted to a double-quoted string so the fence/newlines are real (single-quote literal-\n caught by failing pins).
5. lang keys en-only per ruling (strict 11-locale parity lands at C-TUI-6.h); E646: no timeouts added anywhere.
