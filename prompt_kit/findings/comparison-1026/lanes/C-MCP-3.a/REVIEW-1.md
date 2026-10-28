# C-MCP-3.a — Round-1 Review (REVIEW-1)

Lane: cc/C-MCP-3.a "answer MCP server requests inline — ping/roots/elicitation/progress seam"
Tip: 5773218ab0a3102231d7b0d38aa4efcdd27bbcbc (one commit over master 5ed26cf92), worktree /home/sites/cc-cmp-C-MCP-3.a
Reviewer: cc-cmp step reviewer, 2026-10-28. READ-ONLY respected: all mutation harness lived in /tmp/opencode/cc-cmp-lanes/C-MCP-3.a/harness (awk-copied files, --bootstrap class-predefinition override); worktree porcelain verified clean before and after every mutation run; no git stash/checkout used.

VERDICT: CLEAN (0 CRITICAL / 0 MAJOR / 0 MINOR; 2 NITs queued).

## 1. Authenticity (twin-collision duties) — PASS
(a) Exactly ONE commit; porcelain 0; %ae and %ce both detain@interserver.net (separate greps); %an "Joe Huss"; zero Co-Authored-by trailers (git show full headers).
(b) Coherence: full src-side diff read — single consistent design, no duplicated method bodies, no half-fused hunks. builder-2's claimed contributions are visibly integral: the $root→$resolved guard with its disclosure comment, the SdkWireShapeTest additions, and the deadline-extended-BEFORE-writeLine ordering (writeLine refuses at remaining<=0, so pre-write extension is load-bearing — this IS the "deadline-ordering bug the original writer fixed" shape).
(c) Gates reproduced by reviewer on the exact committed state (below) — all expected figures hit.
(d) 4 mutations run by reviewer via /tmp cp-harness (below) — each reddens its named pins.
Conclusion: one authentic, coherent commit; the twin adoption left no seams.

## 2. Scope — PASS
File set == the 13 BRIEF-declared files (git diff --name-only 5ed26cf92..tip): candy-code docs/MCP.md + src/MCP/{AnswersServerRequests,McpClient,McpElicitation,McpServerRequests,StdioMcpServer}.php + tests/MCP/{McpElicitationTest,McpServerRequestsTest}.php; sugar-mcp-client README.md + src/StdioMcpServer.php + tests/{Fixtures/server_request_server.php,StdioMcpServerSdkWireShapeTest.php,StdioMcpServerServerRequestTest.php}. No suite-figure.json, no durations.tsv, no dist/, no generated-protocol/SERVER.md/generated.ts (regen-then-restore honored; welder regenerates on merged tree). No Skills/BuiltIn or DocFigure/AGENTS_AUTHORING touches.
WELD OWES: 2 durations.tsv rows — candy-code/tests/MCP/McpElicitationTest.php and McpServerRequestsTest.php (lib-suite test files stay out of the tsv).

## 3. Completeness / stubs — PASS
No TODO/FIXME/not-implemented. The per-call elicitation APPROVER desk (AskUser UI) is intentionally unwired: producer search shows zero callers passing $serverRequests — this is the C-MCP-3.b sibling lane (design-mcp.md deps), documented in McpClient::callTool, McpServerRequests, README and docs/MCP.md. Today's production shape (ping answered, roots=spawn root, elicitation→cancel, progress absorbed, sampling→-32601) is complete and honest for this seam — not a fabricated path.

## 4. Focus areas — all PASS
1. Roots trust boundary: roots/list returns exactly ONE root = configured spawn root; empty→process cwd (what the server already inherits — no new leak class); HOME/unconfigured paths never constructed; basename()-derived name only. Pinned: testRootsListExposesExactlyTheConfiguredSpawnRoot + testEmptyProjectRootFallsBackToTheProcessWorkingDirectory.
2. Elicitation→AskUser bridge: no renderer in this commit; server schema strings travel verbatim "sanitised by whatever displays them" (3.b rides the existing marked-frame law). Type validation refuses malformed schemas (-32602) and invalid answers (-32603); sentinels not denial/glob-shaped. Decline/cancel map to wire actions exactly per elicitation spec.
3. Sampling refused: matches plan §6.1 (only ping/roots/elicitation/progress in scope); pinned by name (testSamplingAndUnknownMethodsAreRefusedMethodNotFound).
4. E646: pause refunds only handler wall-time to the per-CALL tool deadline (existing bounded-call accounting, default still unbounded per E646 law — no blanket timeout added, no new timers). A stuck handler can pin the read loop only while the injected approver runs; production default is instant-cancel, and the per-call override's semantics are 3.b's duty. requestAs copies $deadline by value — extension is confined to one exchange. Judged compliant.
5. Wire-id int law: requesterReply echoes int ids via withWireId(int) (McpMessage emits typed id); unaddressable ids → -32600 Invalid Request with null id (documented convention). Pinned by fixture `=== 101` identity check (genuine).
6. -32601/-32602/-32603 semantics correct; the -32603/-32602 messages ("Unacceptable elicitation answer:", "Unusable elicitation schema:") read truthfully, are not denial-prefix-shaped, and DenialPrefixRosterTest is green — retitle dodge legitimate.
7. Byte-exact `{}` default: (object) [] → {} pinned twice (SdkWireShape McpMessage-level + configured-map-rides-handshake via strict fixture); nested-map cast nit is theoretical (product passes no nested empties).
8. No src spawns: fixture server lives under lib tests/ (autoload-dev not scanned); ChildLifetimesToolTest green; the 23-test tools gate (incl. CheckPathRepos) green.
9. In-step guards: GlobDialect corpus 131,765 undrifted (green); DuplicatedTestHelperDrift, ChildWallClock, OneTypePerFile, StackedDocComment, BareCitation all green; one-type-per-file holds.
10. Non-vacuity: base-swap proof — master StdioMcpServer under the lane's lib test → 7 errors "Unknown named parameter $capabilities" (tests genuinely exercise new surface).

## 5. Gates (reviewer-run, plain pipe, env -u LINES -u COLUMNS, cwd per line)
- sugar-mcp-client full suite: OK (244 tests, 949 assertions) — matches claim; baseline 234/918 → +10T/+31A exact.
- candy-code 'McpServerRequests|McpElicitation': OK 29T/102A (in 27-29T/90-102A window).
- candy-code combined 'McpServerRequests|McpElicitation|StdioMcpServer|McpClient|SdkWireShape': OK 223T/741A/1S; + lib 13T/65A ServerRequest/SdkWireShape files = 236T/806A/1S as claimed — both halves green.
- candy-code tests/MCP: OK 849T/3117A/1S — exact.
- guards 'DocFigureProseDrift|GlobDialect|DuplicatedTestHelperDrift|ChildWallClockBudget|DenialPrefixRoster|StderrEmitterCensus|ReadmeRosterDrift|EnvRosterDrift': 289T/75499A with 1 failure = DocFigure 'thirty-seven' (FOREIGN — lane touches neither the test nor the built-in dir; standing-red list). Sub-family rerun without DocFigure: OK 56T/12277A.
- tools harness (sugar-mcp-client/vendor/bin/phpunit --bootstrap tools/tests/_bootstrap.php --no-configuration): ChildLifetimesToolTest+CheckPathReposTest OK 23T/101A; OneTypePerFile+StackedDocComment+BareCitation OK 20T/118A. (Bare `php tools/*.php` is outside this reviewer's bash map; the tool's own test-harness invocations exercise the real rc0 path.)
- 2 PHPUnit deprecations in filtered candy-code runs trace to AccessorDeprecationTest's non-public providers (tests/Config/AccessorDeprecationTest.php:168/:202, master-era file, dataset-name filter bleed) — standing foreign.

## 6. Mutation table (reviewer-run via /tmp awk copies + --bootstrap; worktree untouched throughout — porcelain re-verified clean after runs)
| Mutation | Reddened |
|---|---|
| M-a deadline-pause removal (both `$deadline += ...` guards → false) | exactly testCallDeadlineIsPausedWhileAHandlerRuns ("a thinking handler must not cost the call its budget") |
| M-b interception neuter (readResponse `if ($message->method !== null)` → false) | 7/7 ServerRequestTest |
| M-c ping-arm drop (default ping falls to -32601) | exactly testDefaultAnswersAreEmptyObjectPingAndMethodNotFound |
| M-d cancel→accept flip (null-approver path) | testNobodyToAskIsACancelNeverAGuess + testElicitationWithoutAnApproverCancelsInsteadOfGuessing + testRespondRunsTheWholeRoundTripFromWireParams |
| base-swap (master lib under lane test) | 7 errors Unknown named parameter $capabilities |
All discriminate one-or-named-flip; no pin is vacuous.

## 7. Docs / lang
README 'Server requests' section + Phase-2 rewrite + docs/MCP.md stdio bullet are truthful against the code and mirror §6.1's lock-hold ruling; (C-MCP-N) markers match welded-master precedent. No lang keys added: JSON-RPC method/error strings are protocol wire values (exempt); no user-facing prose is hardcoded in this commit (UI text arrives with 3.b's AskUser bridge) — compliant.

## NITs (non-blocking, welder/closer may pick up)
1. candy-code/docs/MCP.md new stdio bullet: "declined-with-cancel unless the caller passed per-call answers" — the wire action is exactly 'cancel'; "declined-with-cancel" blends the two action names. Cure: "answered with cancel".
2. sugar-mcp-client/tests/Fixtures/server_request_server.php header claims "Self-terminates after 30 seconds" but the 30s check only runs after a blocking fgets returns — the real guard is EOF-on-parent-death plus the 5s bounded awaits. Cure: reword the comment.

## Standing foreign (report-only, NOT lane findings)
ReadmeSuiteFigureDrift staleness pair (~26.7k live vs pinned 25,537 — W13 welder re-pin duty); DocFigure 'thirty-seven' word-map; KeyHelpTest /skills + plugins-usage; AccessorDeprecation provider deprecations.
