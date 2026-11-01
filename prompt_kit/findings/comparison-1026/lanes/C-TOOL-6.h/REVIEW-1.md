# C-TOOL-6.h — REVIEW-1

VERDICT: FINDINGS — 0 CRITICAL / 0 MAJOR / 1 MINOR / 2 NIT. WELD-ELIGIBLE: YES.

## Q1 — Permission twin (CRITICAL class)
- Chain CONFIRMED on lane blobs: ClientToolProxy::NAME_PREFIX (:50) -> Runtime.php CLIENT_TOOL_PREFIX (:873 derived-const) -> isWriteCapableTool arm (:1461) -> stepRequestedAWrite (:1438).
- PermissionGate::isWriteTool: client__ `return true` arm :1826-1834 sits AFTER user__ (:1823) BEFORE verbatim final `return str_starts_with($call->name,'mcp__');` (:1836). Source-sliced ordering: no re-order.
- evaluateAuto: client__ Ask arm (:1103) returns BEFORE ShellSubjectTools::stepTexts (:1116) and classifier->classify (:1138); breaker neither-strike-nor-safe — 6.e law mirrored.
- Mode matrix (shape trace): default Ask (client__x absent from catalog Read -> isReadOnlyTool false); plan Deny (isWriteTool); dont-ask Deny (evaluateDontAsk); accept-edits Ask (falls to tail); bypass Allow = the one disclosed open door, structurally identical to welded mcp__/user__ twins (decide() bypass fallthrough only rewrites rules-Ask->null; Deny untouched; isNoAskTool = catalog NoAsk only, proxy not catalogued).
- Barrier-only execution (not ParallelSafe) removes any grandchild-bypass path.
- Q1 verdict: NO path where a client__ call executes without gate Ask/grant. CLEAN. Pins EXEC GREEN (Q4).

## Q2 — Channel reuse, no second engine — CLEAN
- ChildChannel diff (eaa3dbd04) entirely additive: client_call/client_result kinds + callClient()/clientCaller() + route/readInbound optional legs. ask()/approve()/existing framing internals zero-touch on the added-line scan; no engine or second channel minted — parent arm rides completeAsync beside ASK (EngineBackend a8cf68313 :4775 consume, :5519 withClientCaller bind), PendingClientCall twins PendingAsk (settle-once, resultFrame). Steer/cancel buffered via existing route().
- Roster append (9e1c141bc): TurnRunner::backendForTurn 2 optional tail params; proxies() reach Bootstrap::filterToolSet ONLY there — every client tool goes through the turn channel; SessionHost registry/sink wiring per design. Fork e2e EXEC'd: ForkChannelClientCallRoundTripTest 7T/40A GREEN at my run (sink, deadline, steer, EOF, ceiling-pause legs execute a real child round trip).
- MINOR (step-local, doc-truth): PendingClientCall class docblock claims "the parent recomputes it [callId] from the frame to prove the payload was not altered in transit" — FALSE: fromFrame only format-validates /[0-9a-f]{16}/ + bounds; nothing recomputes. PendingAsk twin carries no such claim. Fix: drop or reword to "format-validates". src/Backend/PendingClientCall.php class docblock.
- NIT: ChildChannel::callClient builds the frame via describe() before the grandchild-pid refusal check — pure function, harmless; ordering note only.

## Q3 — §1.8 per-class — CLEAN (with foreign report-only reds)
- lang N/A PROVEN: en.php absent from the 22-file diff; sole added "Lang::t" line is a docblock mention (ClientToolProxy "carries no Lang::t"); zero new calls => CliLangCatalogue cascade N/A.
- Census: ReadPathCensus filter 19T/767A GREEN; StderrEmitterCensus 95T/2F = EXACT foreign PtyRelay-6j-debt shapes (roster-move 9 + thirty-eight-vs-47 at :2512); lane touches neither PtyRelay nor the census file — REPORT-ONLY, never cure. No new spawn sites (proc_open absent from added src lines).
- GlobDialect 14T/1F base-carried corpus figure (docblock 310,245 vs derived 311,360) — asserted NEITHER number in lane tests; added-test-line glob-shape scan EMPTY; client__* disabledTools literals non-glob-shaped (SessionHostTest:470 contains-assert); chr(42) house split pattern live.
- TWGR BARE 17T/3947A GREEN; DTH BARE 14T/204A GREEN across the four new test files. Word-numbers: PERMISSIONS.md 19-line extension mirrors 6.e twin rows, no new numerals — judged design-faithful (deviation-3 inside design-named doc file), not scope-creep. ToolDocGenerator.php:176 tail 'mcp__ or client__' per design.

## Q4 — Pins EXEC'd — ALL GREEN at my live counts
- Tests: ClientToolRegistryTest 39T/91A · ClientToolProxyTest 8T/23A · ClientToolPermissionTest 6T/22A · ForkChannelClientCallRoundTripTest 7T/40A · SessionHostTest 18T/83A · TurnPathContractTest 9T/68A · Channel-filter 157T/5688A (builder 76T/1562A narrower scope — no mismatch) · Permission-filter 2332T/13192A/1F (sole F = Q6 foreign item) · Corpus-filter 57T/338A/1F (sole F = GlobDialect base-carried) — EXACT match to builder claims, none vacuous.
- DelegatesToEngine: instanceof-membership pin at ClientToolProxyTest:124 (assertInstanceOf) — executor-filter leg UNCONFIRMED by pin, builder's own honest label CONFIRMED accurate (no fabrication).
- UNCONFIRMED-BY-RUN (honest denial, 5.e model): check-one-type-per-file rc0 (reviewer map denies bare php exec; builder ran rc0 62libs/3369files); mutations NOT executed (targeted pins all exec-green with substantive bodies; weld battery is the safety net).

## Q5 — Inventory + trio + collisions — CLEAN
- 22 files == design-declared + 4 disclosed necessary-consequences (judged: all test-anchor extensions in touched classes, no undeclared src files).
- Trio+dist ZERO PROVEN: git diff b45117223...HEAD over docs/protocol/candy-code.v1.schema.json + docs/SERVER.md + candy-code-web/src-web/protocol/generated.ts + candy-code-web/dist = 0 bytes (UNOWED per builder claim). Weld-only pair ZERO: suite-figure.json + scripts/parallel-tests-durations.tsv + dist name-status count = 0.
- Collisions: 6.h file set contains NO src/Support, ProcessContainment, Bash*, BackgroundShellRegistry.php (6.a regions) and NO canvas/serve files (6.g regions) — disjoint by construction; Registry.php of 6.a absent entirely, so no same-file union risk for welders.

## Q6 — Weld-readiness: ELIGIBLE YES
- Foreign roster adjudicate-only: GlobDialect 1F base-carried; StderrEmitterCensus 2F PtyRelay-6j-debt; NEW: ForeignAgentPresetWiringTest::testNoSourceFileOutsideAgentReadsAnAgentsPermissionMode 1F — offenders Config/StatusLineSession.php + Host/Commands/SchedulesCommand.php, both OUTSIDE the 22-file diff and lane src adds zero permissionMode reads (grep proof) ⇒ base-carried foreign; welder adjudicates (never-edit here). staleness pair NEVER-edit + carry disclose. BashSandbox|BubblewrapHardening pairing 39T/192A GREEN as expected (db1441a61 carried).
- §7 LIST (durations rows at REVIEWER live counts): tests/Host/ClientTools/ClientToolRegistryTest.php 39T/91A · tests/Host/ClientTools/ClientToolProxyTest.php 8T/23A · tests/Permissions/ClientToolPermissionTest.php 6T/22A · tests/Backend/ForkChannelClientCallRoundTripTest.php 7T/40A (60T net-new across 4 new files) + carry on RuntimeTest/SessionHostTest/Wither/AskRoundTrip/BuiltInToolCorpus edits; suite-figure +~54–70T-class carry disclose at battery re-pin.
- Welder WARN: read the EIGHT explicit picks by SHA onto wherever master stands — NEVER a b45117223..tip range onto moved master; en.php N/A; TWGR/census unions BY TEXT; trio/dist UNOWED — prove per-pick absence (my 0-byte proofs stand).
- Fix-lane items: MINOR docblock clause above (one-line, weld-time acceptable); NITs leave.
