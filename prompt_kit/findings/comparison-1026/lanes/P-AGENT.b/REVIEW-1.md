# P-AGENT.b REVIEW-1 (r1, reviewer fresh, tip 19830f687, base cf0cfd0be)

VERDICT: FINDINGS — 2 MAJOR / 3 MINOR (all step-local) / 0 CRITICAL. Loop continues: fix round needed.

## Verified honest/correct (re-derived on disk, not from REPORT)
- Scope: 14 files match the diff (+818/−50); all App.php beyond-region arms are spec-mandated and App.php has no other in-flight claimant → wave-safe. HandlesRunControl.php is the one out-of-block file (MAJOR-2).
- §1.8 flips honest: AgentShellCommandsTest VERBS +interrupt (correct position), KeyBindingRegistryTest 153→154 live, KeyBindingDrift five→six + new real agentview.interrupt observation (leader down/up, draft sent not typed, mailbox mode Interrupt only run-2) + send/group-input rows re-shaped to the new viewport-ownership truth (not weakened). AgentComposerTest 4 flips same polarity. AgentViewTest needed no flip — its 80T are green at tip.
- Features all production-reachable: viewports (WindowViewports wired from dormant P-TABS.g lib, stash/restore keys s:/a:, ordering withDraft→withScrollOffset honored), per-tab remembered view (switchTab park/prune/reopen, non-null Chat proven), windowList every-tab children + Waiting (public-readonly chain verified: PermissionRequestMsg->pendingAsk->origin->agentId), Ctrl+X i end-to-end (MessageMode::Interrupt → AgentInbox skipsUnstartedCalls → MailboxTurnInbox pending(); finished run → followUp route; default Steer keeps every other caller byte-behavioral).
- lang/en.php: exactly 2 keys, correct alphabetical spots, description 45 chars ≤49. docs/AGENTS.md truth-matches shipped behavior. §1.9: no glob-shaped/fabricated literals (\u{26A1}/\u{29D7} escapes fine). Tests 5T+4T non-vacuous (mutation-reasoning checked per test; tearDown pins TuiRenderer::setSize(200,60), restores env, unlinks logs).
- KeyHelpTest: the claimed 5 base-reds confirmed identical at main-tree master (slash-help /skills clip, two footer, scroll roster, clipped 0>0) — not attributable to the lane.
- Deprecations: 2 in every run is environmental (identical at master, phpunit.xml untouched).

## FINDINGS

### MAJOR-1 — lane introduces a 6th KeyHelpTest red; REPORT's "×5 base red" is false at tip
`candy-code/tests/Renderer/KeyHelpTest.php:3205-3232` rosters exactly the 5 files CONSTRUCTING `PermissionRequestMsg`; the new fixture at `tests/App/AgentWindowViewportTest.php:266` makes it 6 → `testTheGuardMutationDomainIsTheFilesThatBuildAPermissionRequestMsg` red at tip, green at master (measured both). §1.8: the roster must move in this lane's commit. Fix: add `'App/AgentWindowViewportTest.php'` to the expected array (sorted position first) and flip the guard message's word "a sixth" → "a seventh"; re-run KeyHelpTest — expect exactly the 5 pre-existing reds back.

### MAJOR-2 — out-of-block file (wave-concurrency): src/Chat/Handlers/HandlesRunControl.php
Not in the block's files.modify; named by concurrent lane C-CTX-5.c ([hot:], tick verdict ~:297-388). Code is correct and functionally required (INTERRUPT must route to submitToAgents, not controlAgents); hunks disjoint from C-CTX-5.c's. C-CTX-5.c has since MERGED to master (878047472). Fix is NOT code removal: lead date-stamps the deviation into the brief; welder picks/rebases onto post-878047472 master (auto-merge expected) and re-runs P-AGENT.b's gate AND C-CTX-5.c's HandlesRunControl-touching filter on the merged tree.

### MINOR-1 (step-local) — leader not consumed on refused Ctrl+X i
`src/Tui/KeyboardHandler.php:1049-1051`: `'i' => … : null` discards the `$app` disarmed at :1027; caller :570-574 only uses non-null, so with empty/slash/non-target draft the leader STAYS armed and 'i' falls through to generic handling (typed into composer; next key is a leader chord, e.g. 'c' → CANCEL). All sibling arms return arrays; docblock says "consumed whatever follows it". Fix: `: [$app, null]` + negative pin: open view, empty draft, Ctrl+X then i → assert leader false, draft got 'i' (or no send), no INTERRUPT msg.

### MINOR-2 (step-local) — docblock lie
`src/App/App.php:877` openRunFromWindow: "A run the projection does not carry opens nothing" contradicts the $tab===null branch, which opens with name: null (only $runId==='' opens nothing). Fix prose to match.

### MINOR-3 (step-local) — REPORT gate figure stale
Claimed "OK 308 tests, 3582 assertions" (agentb-gate2.log, 14:22, pre-commit amid the disclosed stash incident) is NOT reproducible at the committed tip: same declared filter measures **361 tests / 5055 assertions, all green** (this review's run, 9.4s, cwd candy-code, env -u LINES -u COLUMNS, plain pipe, -c phpunit.xml; per-class 224/34/15/9/80/5/4). Also spot-ran DuplicatedTestHelperDrift|TreeWideGuardRoster|ReadmeSuiteFigureDrift at tip: only the expected staleness pair red (closeout re-pin duty; durations rows already disclosed as welder-owed). Fix: re-run the gate at tip and re-state figures in REPORT.

## Phantom/hash law
All SHAs cited above re-read from refs: tip 19830f68763f8fe2e9291336b493e454bbc1d00f, base cf0cfd0be, master 04fcdb2d3, C-CTX-5.c pick 878047472 — each verified via git log/rev-parse on disk this session.
