# C-SES-9.b — Serve /status prints live spend and cap — CLOSED

Lane: cc/C-SES-9.b @ /home/sites/cc-cmp-C-SES-9.b (base b985fbe51)
Commit: b187b2e6f1852dca22d34bf9ec4b01d4f0f95d8a — author+committer Joe Huss <detain@interserver.net> (%ae/%ce greps separately = 1/1, zero Co-Authored trailers). NEVER pushed.

## Implementation (exactly the brief's four touchpoints)
- src/Host/Commands/CommandContext.php — added `use SugarCraft\Code\Util\TokenTracker;`; private ctor gains `public readonly ?TokenTracker $spendTracker,` + `public readonly ?float $spendCap,` after scheduleRegistry; new() gains `?TokenTracker $spendTracker = null, ?float $spendCap = null,` forwarding both; two @param doc lines. All other call sites byte-identical (named defaulted params).
- src/Host/SessionHost.php — commandContext() fills `spendTracker: $this->spend, spendCap: $this->workspace->maxCostUsd`.
- src/Host/Commands/StatusHostCommand.php — facts(): `$tracker = $this->tracker ?? $context->spendTracker; $cap = $this->cap ?? $context->spendCap;`; spend row uses them; constructor still wins (Chat.php:12292 TUI path byte-identical behavior).
- tests/Host/WebTwoHostCommandsTest.php — NEW testServeStatusPrintsLiveSpendAndCap: seeds the durable ledger via appendUsageRecord(Usage::new(totalTokens: 500, costUsd: 0.02, unpricedModel: 'mystery')) BEFORE building the host (seed rides resumedTokenTracker), host on maxCostUsd 5.0, runCommand('status'), locates the 'Spend:' header row and asserts the NEXT row contains '≥$' and 'cap $5.0000' and NOT 'not measured'.

## Regression proof
- RED pre-fix (src reverted to HEAD via cp-backup harness, test kept): 1F — 'Failed asserting that … Live spend: not measured … does not contain "not measured"' at the exact defect shape.
- GREEN post-fix: WebTwoHostCommandsTest 22T/76A OK (the 2 PHPUnit deprecations are class-ambient — reproduced on an existing test alone at pristine tree).

## Gates (cwd=worktree candy-code, plain pipe, env -u LINES -u COLUMNS)
- Adjacent battery (all Status*/CommandContext-consuming classes: WebTwoHostCommands, StatusCommandTest, ProjectTierRefusalInventory, Handoff, Context, Generate, Hooks, McpAuthPanelRoot, RenderMode, SendTo, Usage, ContextRawDump, HooksHostCommandLiveChain, LoopSchedulesCommand, PlayHost, SkillsHost, SessionHostLoopFire, ShareHostCommandBuffer): 195T/1854A/2F.
- The 2F are BASE-INHERITED: ProjectTierRefusalInventoryTest::testTheDotPathEnumerationIsDerivedFromSrc + testBothCensusFiguresThisDocBlockQuotes fail IDENTICALLY at pristine b985fbe51 (verified by full file-revert run: same 2F, same :667) — foreign AgentMemory.php dot-paths, zero overlap with this step; disclosed, not cured (out of declared regions).

## Deviations
1. StatusHostCommand class docblock truth-flip (the 'per-Chat state, not CommandContext state' sentence becomes false with the context fallback) — minimal rewrite inside the declared file; disclosed per plan §1.8 doc-code same-commit law.
2. No new test FILE (added a method to the brief's named existing file) — durations.tsv untouched, gate adds nothing.

## Wire/protocol surface
None: /status stays human page text (CommandResult::reply content); no DTO, protocol or dist touch; SERVER.md unaffected (docs are no-guard per brief).
