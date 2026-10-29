# REVIEW-1 — cc/C-MCP-3.d (c3de5c442 + 9b34f63bd on 6742a2ad3)

VERDICT: FINDINGS

## Findings

1. **CRITICAL — turnTools arming forks the whole exchange, severing the C-MCP-3.b approver/progress bindings; a landed green test is red on the merged pair.**
   - `candy-code/src/Tools/McpToolBridge.php:613-667` (`call()` armed branch) + `candy-code/src/Backend/EngineBackend.php:~3492-3509` (turnTools arming hunk) + `candy-code/src/Tools/BuiltIn/McpCallTool.php:195`: when armed, `McpBackgroundCalls::await()` (src/MCP/McpBackgroundCalls.php:161) forks BEFORE the exchange and never relays the child's elicitation asks or progress events back — the await loop only polls for reap/publish. The bound `permissionApprover`/`toolProgressEmitter` closures run (if at all) on the child's copied state.
   - Proof: `tests/Tools/McpToolProgressTest.php:361` `testTheTurnBindsItsApproverAndEventChannelToTheMcpBridge` — "the turn must put the server question through its own approver", expected 1 got 0 — FAILS at tip (my `--filter 'McpBackground|Mcp'`: 1506T, this 1 failure; builder's filter did not name McpToolProgress, hence the miss). File byte-identical base..tip and base..master; the same test is GREEN in the main tree at master 86f80fef8 (which equals base for McpToolProgressTest, McpToolBridge, EngineBackend and src/MCP/ — proven empty diff-stat). Regression, not fixture noise.
   - The shipped prose asserts the opposite: `docs/MCP.md` ("Elicitations and progress ride the child's per-call desk, so a backgrounded call can still put a question to the human…") and `src/MCP/McpBackgroundCalls.php:127-133` (desk "keeps relaying through its inherited closures … the house's existing multi-fork frame-write shape"). Whether the TUI's fd-channel closures happen to survive the extra fork is UNPROVEN and unpinned by any lane test (McpBackgroundCallTest contains zero elicitation/desk/frames pins); the only test that exercises the binding contract says the turn's approver receives nothing.
   - Fix (exact): make the claim true in-process or stop the severance. (i) Preferred: relay over the existing registry side-channel — the child writes ask rows (`<job>.ask` JSON: question/schema/call-id) and progress rows to the job dir; the parent's `await()` loop drains them, calls its OWN bound approver/onEvent closures, and writes the verdict back (`<job>.answer`, child polls with the same bounded discipline as collect()); this keeps the red test green unmodified and makes the MCP.md paragraph honest; add a McpBackgroundCallTest pin for the relay. (ii) Fallback if the relay is ruled out of step scope: gate `armed()` OFF whenever `permissionApprover !== null || toolProgressEmitter !== null` is bound by turnTools (i.e. drop the EngineBackend :3492 arming hunk) so no pinned contract regresses, and correct docs/MCP.md + the await docblock to "background hand-off does not yet cover turns with server-request-capable bindings" — then report the design seam for §6/owner. Route (i) or (ii); the pair is not weldable as-is.

2. **MAJOR — `candy-code/src/Backend/TurnInbox.php` edited (+7 docblock, "Two implementations"→"Three") but NOT in the brief's files list.** The edit is truthful (a third implementation did land). Fix: add the file to the step's declared set in the weld ledger (or revert the docblock to a lane-note in the new file); the concurrency-law violation is the undeclared path, not the content.

3. **MAJOR — `EngineBackend.php` hunk at :3492 is outside the brief's declared region** ("composite inbox at :5022"). The :5340 composite-inbox hunk IS declared; the turnTools arming hunk is not. Fix: declare the turnTools region in the step ledger (no live W22 collision — checked all 9 sibling briefs for EngineBackend/TurnInbox/McpToolBridge/McpCallTool; only C-SES-7.f touches lang/en.php, weld-serializable). Finding 1's chosen remedy decides whether this hunk survives at all.

## Clean categories (verified on the merged pair)

- **E646**: `DEFAULT_AFTER_SECONDS=120.0` is a hand-off dial; `await()` never signals the child (zero posix_kill/SIGTERM in McpBackgroundCalls); threshold injectable (`withBackgroundAfter`, test uses 0.15); transport files untouched so per-hop `toolTimeout` ceilings byte-intact; armed() doors (afterSeconds>0, `-p` latch `elicitationsClosed`, pcntl_fork/waitpid exist, owned HOME) degrade byte-identically inline.
- **Registry hygiene**: 0600 via chmod-before-payload then publish-rename; claim = rename `.payload`→`.payload.taken` + read + unlink (single delivery); stale-`.taken` restore-GC runs BEFORE the claim loop (delivery-preserving); children roster reaped in takeFinished() + test seams.
- **Inbox**: CompositeTurnInbox null-skips; McpBackgroundInbox::forTurn() null without directory; delivery as UserMessage with `mcp.background.finished` row; `-p` never arms (latch wins over re-arm).
- **pcntl_fork only**: zero proc_open/popen in the lane src diff; one-type-per-file proven statically (1 final class per new file); child-lifetimes census inapplicable (walks proc_open); ForkedChildExitConventionTest green covering the new fork site (child leaves via `ForkedChild::exitNow(0)`, McpBackgroundCalls.php:331).
- **Carries**: TreeWideGuardRosterTest row landed in-step; lang 3 keys `mcp.background.{running,lost,finished}`; docs figure (120) pinned by McpBackgroundCallTest; §6 ruling (stdio queue-behind-exchange-lock ACCEPT, nothing killed at hand-off) reflected in MCP.md.
- **Gates run by me** (plain pipe, env -u LINES -u COLUMNS, cwd=candy-code, -c phpunit.xml): php -l 10/10; McpBackgroundCallTest OK 8T/49A (2 pre-existing deprecations); guard family 'ForkedChild|TreeWideGuardRoster|StderrEmitterCensus|LangParity|GlobDialect|DuplicatedTestHelperDrift' 189T/29935A with ONLY the pre-existing ForkedChildReaperAdoptionTest red (BackgroundShellRegistryTest exists at base; guard+scanner+flagged file diff base..tip = 0 lines — foreign C-TOOL-3.c carry).

## Weld carries (not findings)

- README + suite-figure.json re-pin: live enum +8T (new test file) at weld; durations.tsv row for `tests/MCP/McpBackgroundCallTest.php` (unlisted = zero shards).
- Foreign pre-existing red ForkedChildReaperAdoptionTest (Sessions/BackgroundShellRegistryTest) must land in the fork roster by its owning lane/cure — not this step's.
- StderrEmitterCensus: no new emitters in lane src (grep-proven), census green in my window; no flip owed.

## Out-of-scope NITs

- design-mcp.md says the grandchild runs via "the house `Support/ForkedChild` helper"; the fork itself is a hand-rolled pcntl_fork + WNOHANG loop (exit side conforms; census green). Cosmetic spec deviation, disclose in weld row.
- Builder's gate filter lacked `McpToolProgress` — process lesson for sibling briefs: an arming hunk inside turnTools() must re-run the tests C-MCP-3.b landed on that method.

## Not-runnable-by-harness (recorded, claimed by builder)

- `scripts/gen-protocol-schema.php --check`, `tools/check-one-type-per-file.php`, `tools/check-child-lifetimes.php`: `php <script>` invocations refused by my permission map (both absolute and relative forms). Static equivalents above cover drift risk: generator inputs (src/Protocol/**, docs/SERVER.md) and proc_open census inputs are disjoint from the lane's 10-file diff.
