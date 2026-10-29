# C-MCP-3.c REVIEW-1 (round 1) — verdict: FINDINGS (1 MAJOR, 0 CRITICAL)

Lane: branch cc/C-MCP-3.c @ dbd11a501 (base 74acd2472, ancestor of master; detain A/C; tree clean).
Judged as final tree (salvage-lane provenance irrelevant per task).

## Gate re-derivations (cwd=candy-code, env -u LINES -u COLUMNS, plain pipe)

| Gate | Builder claim | Reviewer re-run | Status |
|---|---|---|---|
| tests/MCP dir | OK 864/3222, 1 skip | **OK 864/3222, Skipped: 1 — exact** | PASS |
| DocFigure+Glob+TreeWide | OK 171/10309 | precise-class filter OK 123/8065; loose `DocFigure\|Glob\|TreeWide` 426/11549 OK — builder figure matches neither spelling, green under both | PASS (count-report NIT) |
| Readme+Env+ConfigWriteProducer | OK 62/39304 | `ReadmeRosterDriftTest\|EnvRosterDriftTest\|ConfigWriteProducerDocumentationDriftTest` → **OK 62/39304 exact** | PASS (claim true but see MAJOR: filter excluded the broken guard) |

## MAJOR-1 — lane ships 4 red tests in ReadmeJsonErrorContractDriftTest (in-step guard law §1.8)

- `candy-code/src/MCP/HttpMcpServer.php:542` and `candy-code/src/MCP/SseMcpServer.php:910`: the new `onInboundFrames` fallback
  `?? ['error' => ['code' => McpServerRequests::METHOD_NOT_FOUND, 'message' => 'Method not found']]`
  is a shape-B `'error' => [` envelope with NO `'type' => <literal>` entry.
- `tests/Config/ReadmeJsonErrorContractDriftTest.php::producerSites()` scans ALL of src/ and is fail-loud by design
  ("give this test a way to read the new shape rather than letting it skip"). Isolated run: Tests: 19, Failures: 4
  (testTheSchemaBlockUnionIsExactlyTheShippedErrorTypeSet, testTheEnumeratingSentenceAgreesWithTheExitCodeEachBranchReturns,
  testTheExitCodeTableRowsNameExactlyTheTypesThatReachThem, testTheOutsiderTypesAreNamedWhereverTheyAreCounted),
  every message naming src/MCP/HttpMcpServer.php.
- Lane-attribution PROVEN: base 74acd2472 has zero array-shaped `'error' => [` literals in either file
  (`git show … | grep -F` — only scalar `'error' => '…'`, which the token gate ignores); the two above are the only ones tree-wide.
  Builder's gate-3 filter trio simply never ran this guard.
- This is the JSON-RPC protocol error object, not the CLI stdout contract — semantically it must not join the README union.
  Cure shape: teach the guard to read the new shape in the SAME commit as the code — a rostered, adjudication-commented
  exception keyed by the two exact sites (house pattern, cf. ForeignAgentPresetWiringTest cure), NOT a silent skip;
  ideally one shared shape note covering both transports. Re-run the guard isolated; expect 19/19.

## Classified foreign reds (NOT lane, do not charge the lane)

- `ChatTest::testTheEnvironmentSwitchTurnsSuggestionsOff` (Closure not null, ChatTest.php:4317): byte-identical
  Chat.php/TitleService.php/InterfaceSettings.php/ChatTest.php at base vs tip (empty `git diff`), and the SAME test fails
  isolated in the main tree @ 6a318e151. Standing foreign red; welder/battery carries.
- `ReadmeSuiteFigureDriftTest` staleness pair: pin 25,537 vs live 27,589 — gap +2,052 dwarfs the lane's ~+35, so already
  red at the branch cut. Welder re-pin duty (lane files are NOT staged for it — correct per §1).

## Core judgments (all five) — PASS

1. **GET channel opt-in + reachability**: `stream:true` strict-true at `McpClient::buildServer` http arm; absent ⇒ no
   connector touch (`testWithoutADeskTheReplyStreamWalkIsUnchanged`) and a 405 costs the channel, not the session
   (start() try/catch ⇒ streamParser=null, session live; pinned by `testAGetRefusalCostsTheChannelNotTheSession`).
   End-to-end is REAL: pumpStream called at `dispatch()` head (exchange-start drain) AND via `McpClient::pumpStderr`
   (elseif SseMcpServer|HttpMcpServer arm) reached from 7 McpClient entry points (:1088/:1150/:1240/:1289/:1307/:1356/:1390).
   Owner-only pump, 1ms beginWait, EOF/Throwable ⇒ closeStream, never reconnects; closeStream on handshake re-open and stop();
   non-opener fork-retention honored (retainedStreams[], C-MCP-6.a law).
2. **Server-request answer loop**: http+sse arms unconditionally wire `(new McpServerRequests(stdioSpawnCwd(...)))->handler()`;
   `callTool` gates `AnswersServerRequests + $serverRequests !== null ⇒ callToolAnsweringRequests`; production per-call desks
   pass at src/Tools/McpCallTool.php:188 and src/Tools/McpToolBridge.php:592. Forged/unexpected ids are NOT silent: default-deny
   METHOD_NOT_FOUND pinned in pre-existing McpServerRequestsTest.php:169; pinned `testAPingOnTheGetChannelIsAnsweredByASessionBearingPost`
   + `testTheIdlePumpAnswersTrafficThatArrivedBetweenCalls` cover mid-call and between-call; `testATransportWithoutADeskStillPassesServerRequestsOver`
   pins "invent nothing, not even a refusal" on the no-desk path.
3. **SSRF/fd hygiene**: no proc_open/fork added ⇒ no ChildLifetimes/containment census row needed. Bounded everywhere:
   MAX_HEAD_BYTES 65536, MAX_LINE_BYTES 8192, MAX_STREAM_BYTES_PER_WAIT 8388608 flood cap, deadline-driven waits, bounded
   connect + CANCEL_NOTICE_TIMEOUT_SECONDS delivery POSTs; verify_peer+peer_name/SNI on tls; redirect refusal = origin law;
   401/403 ⇒ HttpMcpAuthRequiredException; answers POSTed only to the locked origin. No blanket request timeout — E646 clean.
4. **SseLineParser stream mode**: split-frame + hostile-length pins live in pre-existing SseMcpServerTest.php:435/:455
   (malformed chunk size; mid-chunk death) — they now exercise the MOVED reader through the transport and are green inside
   the reproduced 864/3222 gate-1 run.
5. **cwd deviation security posture**: ACCEPTABLE. `stdioSpawnCwd` (pre-existing since 2e1ef6430/e71d398aa, unchanged here)
   refuses non-string/non-absolute/non-dir values loudly. The http/sse reuse is new in this commit but the desk only
   ADVERTISES a roots/list URI — no file read through the project-tier key, so no PathJail surface is crossed. Keys rows
   (http +stream,+cwd; sse +cwd) were guard-forced and disclosed; DocFigure census provider widened ['stdio']→['http','sse','stdio']
   in-step = legitimate §1.8 flip. NIT: the throw message says "stdio cwd must be…" while now serving three arms — reword at fix.

## Scope audit

11 landed files: 9 declared; 2 undeclared-but-justified — McpServerRequests.php (+static clientCapabilities) /
AnswersServerRequests.php (docblock-only), not claimed by any other W22 lane (C-MCP-3.d's list omits them; originals welded
e71d398aa), plus the in-step 7-line flip of existing HttpMcpServerStreamableHttpTest.php (capabilities byte pin — REQUIRED by
the handshake change, new pin keeps the `{}`-vs-`[]` empty-map law). stdioClientCapabilities() duplication untouched (pre-existing
seam, outside this lane). §1.9 fixture shapes clean (no glob-shaped or `**Refused:**`-shaped literals in the new tests).

## Welder carries

MAJOR-1 guard cure (in-lane fix round, re-review required) · durations.tsv rows for HttpMcpServerGetStreamTest.php +
SseMcpServerServerRequestTest.php · suite-figure/README re-pin at closeout (carries lane's ~+35T + foreign +2k staleness +
ChatTest standing red re-probe).
