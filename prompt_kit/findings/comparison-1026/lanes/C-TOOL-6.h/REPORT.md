# C-TOOL-6.h — Client-hosted tools: engine + host core (`client__*` proxies over the turn channel)

Lane /home/sites/cc-cmp-C-TOOL-6.h, branch cc/C-TOOL-6.h, base b45117223. Incremental report; each section stamped with the commit that carries it.

## Grounding (pre-first-commit)

- Branch re-verified `cc/C-TOOL-6.h` @ b45117223 ("weld: C-TOOL-6.e — close-record"); picks 0; porcelain = untracked lanes dir only.
- Design block read verbatim from prompt_kit/findings/comparison-1026/design-tool.md:1108; prior incarnation's NOTES.md treated as unverified notes; misplaced REVIEW-1.md (other step) ignored.
- Substrate re-derived from live disk: ChildChannel (585 L, frame table + TO_PARENT/TO_CHILD + ask()/approver()/route()/readInbound()), PendingAsk twin (askId hash, fromFrame field validation, settle-once), EngineBackend mutate()/ctor (all props promoted; mutate = `new self(...get_object_vars+changes)` :830), turnTools bind sites (:3537/:3564/:3620), completeAsync parent loop ($pendingAsks/$settleAsk/$handleAsk/$resetTimeout idle-pause :4419/$finalize/$teardown/$sendToChild), child setup :5320-5342, completeAsyncBlocking :6085, Runtime prefix consts (:848/:860) + isWriteCapableTool (:1442), PermissionGate evaluateAuto (:1063-1093 arms) + isWriteTool (:1788-1812 verbatim `mcp__` literal last) + decide() rules-before-mode, TurnRunner::backendForTurn (:295), SessionHost (:133/:339/:2625/:579/:622), Bootstrap::filterToolSet (:9859), UserToolSpec validator model, ForkChannelAskRoundTripTest (incl. testTheFrameVocabularyIsD1 TO_PARENT/TO_CHILD verbatim pin :196-197 — update rides the kinds commit as a necessary consequence), ForkChannelIdleTimerSuspendedTest (ScaledClockLoop pattern), InteractiveTurnHarness (reused, not duplicated), BuiltInToolCorpus (DYNAMIC_TOOL_CLASSES map class=>evidence-test; instances() match-arm fixture law; consumers iterate classNames()/instances() through createTool()).

## Build state

- S1 (registry + spec + proxy + relay seam + PendingClientCall + corpus wiring + registry/proxy tests + Runtime CLIENT_TOOL_PREFIX brought forward — required so the corpus sweep classifies the client__probe fixture green at this commit): COMMITTED 3086cda450aadfcfcd24db9ccda4175b92f595d2 (cat-file -t = commit, same shell; %ae/%ce separate greps each count 1; zero Co-Authored). 10 files, +1337/−3. Gates at S1: ClientToolRegistryTest|ClientToolProxyTest filter Tests: 47, Assertions: 114 OK; corpus family (BuiltInToolCorpusTest|BuiltInToolTest|ToolDescriptionSentenceFloorTest|ToolSchemaEncodingTest) Tests: 342, Assertions: 1274 OK; RuntimeTest write/drift/corpus filter Tests: 23, Assertions: 36 OK; TreeWideGuardRosterTest bare Tests: 17, Assertions: 3943 OK; DuplicatedTestHelperDriftTest bare Tests: 14, Assertions: 204 OK; BinCandyCodeWiringTest Tests: 1333, Assertions: 7934 OK; check-one-type-per-file 62 libs 3369 files 0 problems. Foreign-red adjudications (cure-NONE, all on the brief's roster or same-debt family): (a) GlobDialectDifferentialTest::testTheCorpusFigureInTheDocBlockIsTheDerivedOne 1F — PROVEN base-carried: all seven new files moved aside, the failure reproduces byte-identical (docblock quotes 310,245 vs derived 560x556=311,360); my literals contribute zero corpus delta; assert-NEITHER-number law honored (PathGlob.php untouched). (b) StderrEmitterCensus 2F (DirectFwriteStderrRoster + InheritedCensus 38-vs-47) — the whole delta is src/Background/PtyRelay.php's 9 fwrite(STDERR sites with an empty roster line ("the roster says nothing, the scan counts 9"); same PtyRelay-6j-debt family the roster names as SeamCallSiteCensus 2F; my files write zero stderr. AccessorDeprecation :168/:202 deprecation pair seen as PHPUnit Deprecations: 2 in every run — roster foreign.
- S2 (ChildChannel kinds + callClient/clientCaller + frame-table docblock + the D1 vocabulary pin update): next.

## S2 COMPLETE — commit eaa3dbd044f128b062e2ee4955f52093387ca4ee (cat-file -t = commit, same shell)
`C-TOOL-6.h: client_call/client_result frames on the turn channel` — 2 files +145/−16.
ChildChannel: CLIENT_CALL/CLIENT_RESULT consts appended to TO_PARENT/TO_CHILD; docblock frame
table rows + client-round-trip prose; no-deadline section names callClient(); heldResults map
(same hold-not-drop law + MAX_HELD_REPLIES bound as heldReplies); clientCaller() closure factory
beside approver(); callClient() blocking like ask() — grandchild refusal, closed→error, no grants
memo (every call round-trips), unreadable content fails closed, EOF = error never success;
send() now refuses CLIENT_CALL beside ASK (round trips ride their builders). readInbound/route
gained $awaitingCall/$result legs; CLIENT_RESULT arm mirrors ASK_REPLY arm.
Necessary-consequence deviation (design omits file): ForkChannelAskRoundTripTest::
testTheFrameVocabularyIsD1 verbatim D1 pin updated in the SAME commit (only tree-wide consumer
of TO_PARENT/TO_CHILD — grep proven). %ae/%ce separate greps = 1/1, zero Co-Authored.
Gate: (ChildChannelTest|ChildChannelVerdictTest|ForkChannelAskRoundTripTest):: → Tests: 27,
Assertions: 140 OK (real-fork pins green with new kinds live-but-unconsumed; Deprecations: 2 =
roster foreign AccessorDeprecation pair). Next: S3 EngineBackend sink/caller/pending-call loop.

## S3 COMPLETE — commit a8cf68313a5fa35a17810375e2178c556e3c7be7 (cat-file -t = commit, same shell)
`C-TOOL-6.h: engine sink, caller bind, and the parent-side pending-call loop` — 2 files +202/−4.
EngineBackend: clientCallSink/clientCaller promoted ctor params (docblock-legible), mutate()
carries via get_object_vars law; withClientCallSink()/withClientCaller() beside
withPermissionApprover; turnTools binds RelaysClientToolCalls at all THREE approver sites
(progress-inner :3558 arm, plain arm, DelegatesToEngine bound-copy arm — proxy rides the third);
completeAsync parent loop: $pendingClientCalls + per-call $clientDeadlineTimers,
$cancelPendingClientCalls wired into $teardown AND $finalize (every leftover settles error),
$settleClientCall (unset+timer-cancel+client_result frame+idle-clock re-check),
$handleClientCall (unreadable frame → error result; NO sink → 'no client is attached to serve
<name>' at once; sink gets (pending, answer-closure), deadline timer armed with descriptor's
1..600 value resolving 'the client did not answer within N s' — per-TOOL ceiling, E646 untouched);
$resetTimeout pauses idle ceiling while pendingClientCalls non-empty beside pendingAsks; $consume
routes ChildChannel::CLIENT_CALL in frame order beside ASK; child setup binds
->withClientCaller($channel->clientCaller()) beside the interactive approver block (every fork,
channel always exists; unforked turns keep caller null ⇒ proxy refusal leg).
Necessary-consequence deviation #2 (design omits file): EngineBackendWitherPreservesStateTest
withers() roster + populated() gained both params SAME commit — its sweep testSweepCoversEvery
PublicWither is the house guard demanding it (was red, now green).
Gate: (8 channel/fork/wither classes):: → Tests: 76, Assertions: 1562 OK (Deprecations: 2 =
roster foreign). My new parent machinery still unexercised until S7 round-trip test ships.

S5 committed: a49711e18dcc37cbae50b86aa1788640d419cbae "C-TOOL-6.h: client__* is write class
— gate arms and the permission twin" (2 files +176; cat-file -t=commit same shell; %ae/%ce
separate counts 1/1 detain; zero Co-Authored). PermissionGate::evaluateAuto gained the client__
Ask arm directly after the user__ arm, BEFORE ShellSubjectTools::stepTexts + the classifier
(classifier would see only JSON args — no shell text — and could Allow + reset the breaker;
Ask-before-classify is neither strike nor safe call, breaker stays put — 6.e law mirrored).
PermissionGate::isWriteTool gained the client__ `return true` arm after user__ and BEFORE the
verbatim final `return str_starts_with($call->name, 'mcp__');` (:1826 era). NEW
tests/Permissions/ClientToolPermissionTest.php 7 tests: mode matrix (default Ask / plan Deny /
auto Ask / dont-ask Deny / accept-edits Ask / bypass Allow), auto-breaker-untouched pin (real
Bash strike first), single-rule grant pin (Allow client__browser_context allows ONE tool;
client__other still Ask — rules beat mode), Deny-beats-Bypass pin, stepRequestedAWrite legs
(client__* true, mcp__* true, not_client__real false = prefix-not-substring law), literal-mcp
source-shape pin extended: both NAME_PREFIX arms assertLessThan the mcp__ line position.
Permission-twin trace (no bypass path): prefix const src/Runtime.php CLIENT_TOOL_PREFIX
(=ClientToolProxy::NAME_PREFIX) → isWriteCapableTool arm (Runtime :1442-era) →
stepRequestedAWrite :1425; gate arm PermissionGate::isWriteTool client__→true (before literal
mcp__) → evaluatePlan :1324 Deny; evaluateAuto client__→Ask (before classifier :1103) →
decide() rules layer evaluateRules first (Allow client__x grants one tool; Deny beats Bypass).
Gates: (ClientToolPermissionTest|UserToolPermissionTest|PermissionGateAutoModeTest|
ReadOnlyAutoAllowTest):: → Tests: 118, Assertions: 245 OK; RuntimeTest::(testStepRequestedAWrite
|testTheWriteToolRosterDoesNotDriftFromThePermissionGate) → Tests: 23, Assertions: 36 OK.

S4 committed: 9e1c141bc72ce26c07b2d963673f7f3389069315 "C-TOOL-6.h: session-hosted tool
registry, pending-call hands, turn roster append" (2 files +170; cat-file -t=commit same shell;
%ae/%ce separate counts 1/1 detain; zero Co-Authored). SessionHost: readonly ClientToolRegistry
created in ctor body (one per host, registrations die with the connection — forget path),
$pendingClientCalls map (callId → pending+clientKey+registrationId, lazy-settled-prune),
registerClientTools/forgetClientTools (forgets resolve that client's open calls as errors —
child blocked on one learns the truth instead of waiting its deadline)/resolveClientCall
(first answer wins; stale-or-wrong registrationId and settled-call all read same: false)/
onClientCall(?\Closure) hook for 6.i; private clientCallSink() records the open call under the
owner's CURRENT registrationId and notifies the listener, answering at once when no
registration owns the name (roster fixed at dispatch, client forgot mid-turn). TurnRunner::
backendForTurn gained ?ClientToolRegistry + ?\Closure $clientCallSink tail params (Chat's
6-arg call unaffected) — appends Bootstrap::filterToolSet($registry->proxies()) via withTools
at turn start (roster fixed per dispatch = "registration takes effect at the NEXT turn";
disabledTools patterns drop them through the shared filter) + binds the sink. Master read:
origin/master 7e042da7b is an ancestor of my base b45117223 (main-tree master ahead of remote
refs by 103 unpushed-at-cut welds — expected per W33 close line; lane has picks → NEVER rebase,
welder orders). Gate: (TurnPathContractTest|SessionHostTest):: → Tests: 22, Assertions: 130 OK
(Deprecations: 2 = roster foreign; TurnPathContractTest's methodBody pin on backendForTurn
checks only the withoutInteractiveUser absence — unaffected).
Next: S6 docs (ToolDocGenerator :176 tail, PERMISSIONS.md rows + gen-tool-docs --write,
ToolDocDriftTest), then S7 round-trip + SessionHostTest rows.

## S6 f69538669 — client__* in the permission docs and the tools:classes tail
- ToolDocGenerator.php:176 write-capable tail now reads "and anything starting `mcp__` or `client__`".
- docs/PERMISSIONS.md: plan row names every `client__*` Deny; auto row names `client__*` Ask (never classified); new `| `client__*` | — (asks) | always: the call runs in the user's own client… |` row after `user__*`; breaker paragraph extended to `mcp__*/user__*/client__*`; rules-first example gained `Allow client__browser_context` (extension inside the design-named doc file, disclosed as deviation #3).
- `php tools/gen-tool-docs.php --write` moved ONLY the tools:classes write-capable line (verified by diff). Gates: ToolDocDriftTest:: 5T/17A OK; (PermissionsIntroDriftTest|ReadmeRosterDriftTest|DocFigureProseDriftTest|TrustKeyDocumentationDriftTest|PlanModeToolRosterTest):: 130T/4979A OK.

## S7a 2f4373c8c — the client-call round trip in a real fork
- NEW tests/Backend/ForkChannelClientCallRoundTripTest.php: Tests: 7, Assertions: 40 (live, per-file). Pins EXEC'd green: sink round trip (name/toolCallId/arguments/deadlineSeconds + answer back as ToolFinished, PermissionAsked EMPTY); deadline → "the client did not answer within 1 s" isError; steer overtaking the reply buffered (acknowledgedSteers==[id=>2]); parent EOF → "the turn ended before the client answered" + isClosed + writes==1; idle ceiling paused (ScaledClockLoop 200s virtual > 120s ceiling, answered "late page"); re-arm polarity RuntimeException 'without progress'; no-sink → "no client is attached to serve client__browse" at once. Deprecations:2 proven foreign (same pair on untouched sibling file).

## S7b 5fae61054 — session-host pins
- SessionHost resolveClientCall WHY-comment (comparison logic unchanged from S4). NEW 5 tests in SessionHostTest (now Tests: 18, Assertions: 83 whole-file): next-turn roster reaches (backendForTurn + registry → tools() has client__browse; empty registry no-op); disabledTools `client__`+chr(42) split drops proxies under HomeSandboxTrait writeUserConfig; first-answer-wins + wrong/stale registrationId refused (stored-id semantics — the re-registered client's NEW id cannot spend the old call; retirement path is forgetClientTools); forget fails open calls ("the client that hosts this tool is gone" isError); name-no-client-holds answered at once. TurnPathContractTest:: 9T/68A OK.

## Lane closeout (final sweep, this pass)
- Picks (8): S1 3086cda45, S2 eaa3dbd04, S3 a8cf68313, S5 a49711e18, S4 9e1c141bc, S6 f69538669, S7a 2f4373c8c, S7b 5fae61054 (HEAD). Each cat-file -t=commit in its citing shell; %ae/%ce separate greps 1/1 detain; zero Co-Authored trailers; explicit-path commits.
- 22 files, +2523/−32 vs base — exactly the design's files.create/modify plus 4 disclosed necessary-consequence files (ForkChannelAskRoundTripTest pin, EngineBackendWitherPreservesStateTest roster, BuiltInToolCorpus wiring, RuntimeTest rows are design-named).
- §7 NEW test files for battery durations rows (live per-file counts): ClientToolRegistryTest 39T/91A; ClientToolProxyTest 8T/23A; ClientToolPermissionTest 6T/22A; ForkChannelClientCallRoundTripTest 7T/40A. SessionHostTest (+5, now 18T/83A), RuntimeTest (+3 provider rows), ForkChannelAskRoundTripTest (pin amended), EngineBackendWitherPreservesStateTest (roster) are MODIFIED files. suite-figure.json + parallel-tests-durations.tsv bytes-diff = 0 across b45117223..HEAD (git diff byte count proven).
- TRIO UNOWED PROVEN: git diff b45117223 HEAD over candy-code.v1.schema.json + SERVER.md + generated.ts + dist = 0 bytes (no RPC/schema surface added — the protocol door is C-TOOL-6.i).
- LANG N/A PROVEN: my src diff adds zero Lang::t calls (only a docblock sentence noting their absence); en.php diff = 0 bytes; mirrors untouched.
- Guards: TreeWideGuardRosterTest:: 17T/3947A OK; DuplicatedTestHelperDriftTest:: 14T/204A OK; check-one-type-per-file 62 libs/3369 files/0 problems.
- Census family (ReadPathCensus|StderrEmitterCensus):: 114T/24425A with ONLY the two roster-foreign PtyRelay-6j-debt reds (StderrEmitterCensus :testTheDirectFwriteStderrRosterIsUnchanged + testTheInheritedCensusStillAgreesWithTheScan); my diff adds 0 stderr writes, 0 path readers, 0 spawns (residue grep 0; `if (false`/`&& false`/`|| true` clean at every commit).
- GlobDialect 1F base-carried (adjudicated: all 7 new files moved aside, failure byte-identical — docblock 310,245 vs derived 560×556=311,360); assert-NEITHER-number honored; my test literals use the chr(42) split for `client__`-pattern (no corpus delta contributed).
- EXEC-vs-UNCONFIRMED: every pin executed green this session. One disclosed UNCONFIRMED leg (S1): EngineExecutor's DelegatesToEngine sub-agent-withholding filter is interface-membership-pinned (AskUserToolTest precedent) — no tree-wide test EXECUTES the executor filter itself; grep-proven absent, honest disclosure.
- Permission-twin trace (prefix→arm→Ask→grant, no bypass path): ClientToolProxy::NAME_PREFIX 'client__' → Runtime.php CLIENT_TOOL_PREFIX const (beside USER_TOOL_PREFIX) + isWriteCapableTool arm str_starts_with(CLIENT_TOOL_PREFIX) → stepRequestedAWrite true; PermissionGate::isWriteTool client__ `return true` arm BEFORE verbatim final `return str_starts_with($call->name, 'mcp__');` (literal pinned intact); evaluateAuto client__ Ask arm BEFORE classifier (no shell text; breaker neither-strike-nor-safe); grant leg decide()→evaluateRules beats mode — `Allow client__x` single-name pinned (Allow x, Ask other); plan/dont-ask Deny via isWriteTool; Deny-beats-Bypass pinned. No path reaches execute() without either an Allow rule, Bypass-mode (pinned Ask-fallthrough exception absent for client__ since evaluateAuto-Ask never runs under Bypass — Bypass allows, the one disclosed open door, mirroring MCP/user precedent), or interactive approval.
- Collisions: zero — C-TUI-6.a (Windows process layer) no shared region touched; cc/C-TOOL-6.g untouched. Master read-only: origin/master 7e042da7b is ANCESTOR of base b45117223 (base ahead by design — welder orders picks; lane never rebased).
LANE DONE — ready for review. porcelain: untracked lanes evidence dir only.
