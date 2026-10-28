# C-EXT-2.e round-1 review — Command frontmatter model/agent/subtask

VERDICT: FINDINGS — 0 CRITICAL / 0 MAJOR / 1 MINOR (step-local) / 1 NIT. Materially sound; one disclosure gap on a rare route.

## Disk ground truth
- Worktree /home/sites/cc-cmp-C-EXT-2.e, branch cc/C-EXT-2.e, tip ef5080a62 (cat-file-verified), single commit atop merge-base 63f2de5d9. Porcelain clean (before and after my gate/red-before work). Author/committer Joe Huss <detain@interserver.net> (separate %ae/%ce greps), zero trailers.
- 13 files, +921/-79: docs/COMMANDS.md, lang/en.php, src/Chat.php, src/Commands/CommandSpec.php, src/Host/SessionHost.php, src/Host/TurnController.php, src/Support/FrontmatterKeyAudit.php; tests: 2 new (TurnControllerCommandRoutingTest 14T, CustomCommandModelOverrideTest 4T) + 4 modified. Exactly the brief's declared set plus the in-step-owed BootstrapLaunchNoticeRoutingTest flip. NO forbidden files (suite-figure.json / parallel-tests-durations.tsv / dist untouched).

## Claims verified
1. ONE delegation implementation: TurnController::wrapCommandDelegation() consumed at Chat::submit AND SessionHost::admitExpanded (both call sites pass the spec; MCP fetch-refusal leg passes null with empty expansion — consistent). -p rides Chat. Directive is fenced (lang value is double-quoted with real \n fences); model leg rides nested command_delegation.model key.
2. Model pin: commandTurnModel() re-derives the Task rule — hand-compared against TaskTool::chooseModel (TaskTool.php:2537-2589): same order and conditions (trim/empty→null, equals-session→null, whitespace/ctrl→not_an_id, CLAUDE_MODEL_ALIASES with str_contains(session,alias) rescue, ReportsServedModel equality, servesOneModel single_model, else pin). Fallback emits session-model + notice. Applied only at the launch-backend hoist (scheduleBackendCompletion / SessionHost::dispatch withModel clone) — clone never stored, so restoration is structural. CustomCommandModelOverrideTest pins clone identity + session backend untouched + next turn === original.
3. FrontmatterKeyAudit::INERT['COMMAND'] emptied; COMMANDS.md rows flipped Live + "Five keys/three import-floor"; docblocks flipped; same commit (in-step law satisfied). InertFrontmatterDocumentationDriftTest derives bidirectionally and needed no edit — passes green (observation, not a finding).
4. CommandSpec gains `agent` promoted readonly; every construction site tree-wide uses named args (or ≤3 positional) — insertion before $tier is positionally safe (grep census).
5. 8 en-only host.turn.command_* keys — correct per plan §6.3-h (11-locale parity lands at C-TUI-6.h). Not faulted.

## Deviations judged
- Wrap/pin after the empty-expansion refusal: ACCEPT — wrapping an empty expansion is meaningless; refusal precedes any directive.
- Parked-compaction drops the pin with command_model_parked truth notice (DTO out of step files): ACCEPT the pin-drop handling (truthful; the serve route has no park at all — checked). But see MINOR-1 for the notice asymmetry.
- BootstrapLaunchNoticeRoutingTest subtask→subtaskx: ACCEPT — legit in-step: with the inert pair retired only an unknown key earns the routing row; the did-you-mean arm stays exercised.
- Serve-path pin observed via child-written model log (completeAsync forks): NON-VACUOUS — drain is the settle barrier, missing writes yield [] (fail), wrong model fails; red-before swap FFFF→... confirms the Chat legs, and the serve test passed only at tip.

## Gates reproduced (cwd candy-code in the lane worktree, plain pipe, env -u LINES -u COLUMNS; PtyRunToolTest never in a filter)
- CommandLoader|FrontmatterKeyAudit|InertFrontmatter|BootstrapLaunchNotice → 99T/949A OK (exact)
- TurnController|SessionHostCommands → 60T/232A OK (exact)
- CustomCommand|ChatSubmit|MidTurnSpendCap → 102T/445A OK (exact)
- DocFigureProseDrift|CommandDocsDrift|LangParity|SymbolCitationDrift → 113T/23570A OK (exact)
- SessionHostTest|TurnRunner|InertFrontmatter|FrontmatterKeyAuditTest → 77T/336A OK (builder's REPORT filter text "InertFrontmatterDrift" matches nothing; figures reproduce only with the InertFrontmatter segment — text lossy, numbers true)
- five-guard StderrEmitterCensus|DuplicatedTestHelperDrift|GlobDialectDifferential|ReadmeSuiteFigureDrift → 126T/21381A, Failures: 2 = the allowed staleness pair only (25,537 pinned vs 26,597 live ≈ 1,042 pre-existing master drift + 18 lane delta — welder closeout duty, NOT a finding)
- Red-before: base-TurnController swap → routing 14T/14E (undefined commandTurnModel); base-Chat swap → override 3F with the named clone/notice/directive claims each firing, EchoBackend polarity test green as designed. Restored via git show HEAD:path; porcelain clean re-verified.
- "PHPUnit Deprecations: 2" appears uniformly in every filter including non-lane ones — bootstrap-level, not a lane finding.

## Findings
- MINOR-1 (step-local) src/Chat.php:9950-9952 vs :10047 — the fallback notice is lost on the parked route. When commandTurnModel decides [null, notice] (un-servable model → session-model swap announced) AND the turn parks behind >85% compaction, submit() returns scheduleParkedCompaction()'s array before reaching the `if ($commandModelNotice !== null)` append; scheduleParkedCompaction only writes command_model_parked when $oneTurnModel !== null (:14248), so the parked prompt runs on the session model in silence. Pin case is truthful; fallback case is not. Intersection is rare and safe-side (no wrong model ever dialed), hence MINOR. Exact fix: pass $commandModelNotice into scheduleParkedCompaction (7th param) and prepend it to the returned turn's messages alongside the park/notice rows (or splice it into $parked at the call site before `return $parked;`), mirroring the SessionHost dispatch splice. Serve/TUI -p parity unaffected (no park route on the host side — verified).
- NIT tests/Chat/CustomCommandModelOverrideTest.php:117 — `$this->assertNull(null, ...)` is vacuously true; the real "first submit settled" premise is already load-bearing at :103 (red-before proved it). Drop the line (or assert `assertInstanceOf(Chat::class, $next)` if the intent was fixture shape).

## New-issue hunt (negative results)
- Prompt-cache byte-stability: directive rides the user-turn content; no change to cached-prefix assembly — layout untouched.
- Injection: agent/model names interpolate only into the fenced directive (same trust class as the template body, already repo-sourced); project-tier command gating (C-SEC-5.a/c doors) untouched by the diff.
- Cross-tenant parity: TUI Chat, -p, and serve SessionHost all consume the same two helpers; MCP prompt rows carry no model/subtask/agent on either surface — never wraps/pins, consistent.
- Pin leakage to later turns: none — one-turn clone, structural restore, pinned by test (next turn === session backend).
- Unknown-model handling: fail-closed to session model WITH notice (except the parked-route gap above) — never dials an unservable id.
- Test-literal hazards: GlobDialect + DuplicatedTestHelperDrift green; no denial-shaped literals introduced.
- E646: zero timers added.

## Weld owes
- durations.tsv rows for the 2 new test files; suite-figure/README re-pin (staleness pair); lang parity for the 8 keys lands at C-TUI-6.h per §6.3-h.
