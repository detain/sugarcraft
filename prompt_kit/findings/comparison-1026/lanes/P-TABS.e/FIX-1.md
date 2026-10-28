# P-TABS.e — FIX-1 (round-1 review cures, amend of 8ec8bff21)

Verdict: all 3 MAJORs + 2 NITs cured; every MAJOR pinned with a red-before/green-after proof.

- MAJOR-1 (Chat.php withNoticeDrain docblock): false per-tab queueing sentence replaced with the
  shared-queue truth ("The queue is workspace-global, not per-tab … surfaces in whichever tab
  holds the drain appointment at that moment"), mirroring ARCHITECTURE.md's same-commit wording.
  Grep: "stay QUEUED" / "surface the next time that tab" = 0 hits tree-wide; no guard pinned it.
- MAJOR-2 (App.php handleTabScoped): the documented AgentMessageSentMsg exception implemented —
  parked branch now routes `$inner instanceof AgentMessageSentMsg` to `recordAgentMessage($inner)`
  (global ledger, null cmd) after the open-modal specials, before the generic park route.
  New test: TabScopedRoutingTest::testRunKeyedAgentMessageFromAParkedTabReachesTheGlobalLedger
  (ledger count 1 + agentId, foreground Chat identity ===, parked deck model identity ===).
  RED-BEFORE: fix block removed via /tmp-cp mutation → exactly this test fails at :184
  ("actual size 0 matches expected size 1"); restore md5-clean; 19/19 green after.
- MAJOR-3 (vacuity): BackgroundTabStreamingTest::testParkedTabKeepsItsEventPumpAndDropsTheForegroundOnlyPolls
  extended — fresh-tab factory now carries a test-local NoticeSink workspace with one recorded row
  (so the raw fg-held `code.runtime-notice-poll` is observably present, no process-global lean);
  asserts `assertFalse(has('tab:tab-1:code.runtime-notice-poll'))` + `assertTrue(has('code.runtime-notice-poll'))`.
  MUTATION: both park sites `withNoticeDrain(false)`→`(true)` → red at :96 exactly; restore md5-clean.
- NIT-1 (pendingTabClose docblock): "the confirming press closes, switching tabs or arming a
  different target clears it early, and anything else lets it expire" (matches the pinned clears).
- NIT-2 (armDeck/openTab `''` sentinel): one comment line above handleOpenTab's `open()` — two
  id-less fresh tabs briefly share `''` until first-persist rekey; P-TABS.f note, no behavior change.

Gates (plain pipe, env -u LINES -u COLUMNS, cwd worktree candy-code):
- `TabScopedRouting|BackgroundTabStreaming|TabCloseAndQuit`: 19T/106A (was 18/97) OK.
- tests/App directory: 468T/21,269A OK (was 467/21,260; +1T/+9A = the new leg).
- KeyBindingDrift: 222T/1,800A unchanged.
- LangParity|GlobDialect|DuplicatedTestHelperDrift|SymbolCitation: 44T/20,260A OK.
(The recurring "PHPUnit Deprecations: 2" = foreign AccessorDeprecationTest pair, per REVIEW-1 §4.)

Durations/weld note unchanged: still 3 new test files (no new file added this round).
