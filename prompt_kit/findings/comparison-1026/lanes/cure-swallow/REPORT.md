# cure-swallow — APPROVE: all four §1.8 chain-debts red→green, 4 commits on cc/cure-swallow

Base master e497506dd; branch cc/cure-swallow at /home/sites/cc-cmp-cure-swallow; NEVER pushed.
+0T/+0A (all edits inside existing methods/arrays) — staleness pair unchanged.

## Per-item red → green

1. tests/Cli/LoginSubcommandTest.php:363-376 (testTheTerminalIsHandedBackAfterAThrownRead)
   - RED pre: SwallowingCatchCensusTest::testNoTestSwallowsTheFailureStandingNextToIt —
     'Cli/LoginSubcommandTest.php:366 catch(\RuntimeException) [offender]'.
   - FIX: capture-then-assert house idiom (HttpMcpServerTest:806-819 precedent): try body
     no longer asserts (fail() removed; capture() is assertion-free), catch records only,
     ob-unwind moved after block, assertNotNull+assertSame($sentinel) after.
   - GREEN: --filter SwallowingCatch 12T/4835A rc0; --filter Login 76T/1535A rc0.
   - Commit d51c9a4a9 (cure: SwallowingCatchCensus — … captures then asserts).

2. src/Cli/Subcommands.php openLoginTtyHandle() `new Tty(\STDIN)`
   - RED pre: TtyStreamArgumentCensusTest::testNoTtyIsBuiltWithAnInjectedTermiosAndTheProcessesOwnStdin
     :281 — derive wants 'src/Cli/Subcommands.php: constant:\STDIN'.
   - VERIFIED real stream + safe: login key read path; \STDIN is PHP descriptor-0 resource
     (cannot be null); raw-mode = stty raw -echo; handle restored in loginKeyLine()'s finally
     on every exit path; suite never reaches it ($loginKeyRead/$loginTty seams, pinned by
     testThePasteSeamLeavesTheTtyHandleUntouched).
   - FIX: roster row added in scanner order with dated adjudication comment (2026-10-27).
   - GREEN: --filter TtyStreamArgumentCensus 46T rc0. Commit e1bada52e.

3. src/Session/EnhancedSessionStore.php::usageRollups (2693-) inner ROLLBACK swallow
   - RED pre: SwallowingCatchSrcCensusTest::testTheBareEmptySrcCatchesAreExactlyTheRosterRows
     :133 — 'usageRollups (1 bare-empty catch)'.
   - Guard offers roster door ("roster a row with a one-line justification, or fix"); took it:
     failed ROLLBACK must not replace the original error rethrown via `throw $e` two lines
     later — same polity as the SessionStore::immediateTransaction row. Dated adjudication
     in the justification string. Docblock prose eleven→twelve (4 sites, truth-fix).
   - GREEN: --filter SwallowingCatchSrcCensus rc0 (inside SwallowingCatch gate);
     --filter UsageRecords 21T/102A rc0 (21T-class as briefed). Commit 43ec78994.

4. tests/Agents/McpDispatcherPresetScopeTest.php:266-271 (testTheSharedLaunchClientStaysUnscopedAfterTheScopedGrantResolves)
   - RED pre: same census — 'Agents/McpDispatcherPresetScopeTest.php:269 catch(\RuntimeException) [offender]'.
   - FIX: capture-then-assert; fail() replaced by post-block assertNotNull (original message),
     message claim after. Behavior: still red if callTool does NOT throw / wrong message.
   - GREEN: --filter McpDispatcherPresetScope 6T/34A rc0. Commit 9c9937b3f.

## Gates (cwd worktree candy-code, plain pipe, env -u LINES -u COLUMNS)

- SwallowingCatch: 12T/4835A OK (2 pre-edit failures → 0)
- TtyStreamArgumentCensus: 46T OK · UsageRecords: 21T OK · McpDispatcherPresetScope: 6T OK · Login: 76T OK
- Five-guard: 133T, ONLY staleness pair red (pinned 25,537 vs live 26,082 — foreign-chain
  carry, +0T from this lane, closeout re-pin duty).
- PtyRunToolTest untouched per brief (owner-hazard carry).

## Authors

All 4: %ae=1 %ce=1 detain greps, zero Co-Authored trailers, plain -m, no caliber.
