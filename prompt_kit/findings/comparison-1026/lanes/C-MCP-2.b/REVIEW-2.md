# REVIEW-2 — lane C-MCP-2.b (tip 4c1c89e1e, parent 4b771634d, base for r1 was ec48dc189)

VERDICT: FINDINGS — 0 CRITICAL / 0 MAJOR / 1 MINOR (step-local) / 2 NIT. Round-1 MAJOR-1 cured on disk and provably correct; weldable after a one-command amend that re-lands the lost trait arm.

## Disk-verified cures (blob-grepped at 4c1c89e1e, phantom law honored)
1. MAJOR-1 (REVIEW-1.md: schema advertised command.list sessionId optional while the resolver throws invalidParams) — FIXED: MethodSchemas.php:276 now `'command.list' => [$sessionOnly, ...]` with `$sessionOnly = Schema::object($sid, ['sessionId'])` (:49), byte-same spelling as the lane's own mcp.resources arm; source enum carries 'mcp'. Registry-order arm of ProtocolSchemaDriftTest (names ↔ schema keys array equality) green → placement order-matches Dispatcher regen.
2. Deny-exec pin committed: testCommandExecRefusesADeniedServersPromptRow asserts kind `command_not_found` — matches CommandMethods::exec (row miss → builtin miss → RpcError::notFound(..., 'command_not_found')); invalidParams is NOT the shape and is not pinned. Non-vacuous: the blocked fixture genuinely CARRIES a review prompt row (listPrompts returns data; prompt()/readResource() throw) and exclusion rides the real gate setDenyPatterns → McpClient.php:680/:962/:1019 serverDenied; FIX-1.md MUT-A (neuter isServerAllowed → pin REDS) documented.
3. Started-with-no-rows pin committed: McpMethodsTest:144 testAStartedClientWithNoResourceAnswerStillAnswersStarted — reflection-seeded started client, zero resource answers → status 'started' + servers []; the second enum leg, disjoint from the cold-pin shape.

## FINDINGS
### MINOR (step-local) — NIT-1 trait arm lost from the commit, live only as uncommitted worktree dirt
`git diff 5b3c8cfe8 4c1c89e1e` = exactly `McpMethodsTest.php | 10 deletions` — the tip is the fixer-reported FINAL 5b3c8cfe8 minus the McpLaunchEnabledTrait arm (+import, use, arm/restore calls), which REVIEW-1 NIT-1 asked for and which sits UNCOMMITTED in /home/sites/cc-cmp-C-MCP-2.b (porcelain ≠ 12-file claim: ` M candy-code/tests/Protocol/McpMethodsTest.php`). Battery-safety analysis: NOT red under CANDY_CODE_MCP_DISABLE=1 (shard prefix at scripts/parallel-tests.sh:243) — the gated MCP_DISABLED decision keeps the per-root path key (McpDecision.php:121-126), seed and peek derive identically in-process — so content stays NIT-grade; the defect is the landed-state mismatch + dirty tree. Fix: `git add candy-code/tests/Protocol/McpMethodsTest.php && git commit --amend --no-edit` (result provably equals 5b3c8cfe8's tree; sibling CommandMethodsMcpPromptsTest already carries the arm committed).

### NIT — commit footer conflates two twin fixer briefs
The footer's "MAJOR-2/MINOR-1 phantom" refers to items that exist in the concurrent fixer's dispatch (FIX-1.md adjudicates them: ConformanceTest.php:870 fork-skip and commands.readOnly-claim — neither is in REVIEW-1.md, which carries exactly MAJOR-1 + NIT-1); "NITs list not on disk" was true for that twin (REVIEW-1.md lives in the main tree, not the worktree). Footer also says "MAJOR-1 gate verified structural" while the commit actually contains the $sessionOnly schema cure — the cure is right, the message under-describes it. No code consequence.

### NIT — dispatched r2 expectation "~21T+" for 'McpMethods|CommandMethodsMcpPrompts' matches no inventory: committed blobs hold 5 + 8 tests → 13T is the honest full count. Brief arithmetic, not a lane gap.

## Gates (cwd candy-code, plain pipe, env -u LINES -u COLUMNS; run with the arm present — committed shape adds zero tests, same outcome by the key-consistency analysis)
- 'McpMethods|CommandMethodsMcpPrompts': OK 13T/99A (both new legs observed in the run).
- 'CommandMethods|SessionHostCommands|ServedProjectCommand': OK 30T/168A.
- MCP family 'McpPanel|McpPromptCommands|McpClient': OK 188T/734A/1S (family grew on rebased parent; r1's exact 179/671 era figure superseded by lineage, skip = same canary).
- 'ProtocolSchemaDriftTest|ServerProtocolDocumentationDrift': 12T/331A, sole failure = the file-vs-derived staleness arm — EXPECTED by the §1.8 restore-3 trio law (schema JSON/SERVER.md/generated.ts byte-clean at base; confirmed absent from the commit); all validity arms (refs resolve, every method has a schema, fixtures fit) green → the amended schema regens green and the committed src change is the sole drift. My permission map refused `php scripts/gen-protocol-schema.php` (same refusal REVIEW-1 logged) — validity legs are the sanctioned proxy.

## Hygiene / posture (all PASS)
ONE commit on master (parent == current master tip 4b771634d; brief's cc14518d5 base moved by a sibling weld — correct rebase); %ae and %ce SEPARATE greps both detain@interserver.net; 0 trailers; diff == declared 12 files +1055/−53; no suite-figure/durations/dist/generated files; mcp.resources is Scope::Read memo-only peek (no-serve-without-trust unchanged); CommandMethods list/exec read the same customCommands() map (structural list/exec parity); declare(strict_types=1), final, bare accessor SessionHost::customCommands().
Gate duties carried (not findings): durations.tsv 2 rows, suite-figure +~12T re-pin, welder trio regen, dormantRoster mcp.resources row when C-WEB-3.g lands.
