# P-AGENT.b — DONE, commit 19830f68763f8fe2e9291336b493e454bbc1d00f (branch cc/P-AGENT.b, base cf0cfd0be)

Agent windows: own viewport, rows/chips in window list, Ctrl+X i interrupt-send. 14 files, +818/−50. Author+committer detain@interserver.net verified separately; no Co-Authored-By.

## Gaps closed
1. **Shared viewport** — `WindowViewports` (P-TABS.g's dormant lib) now wired: openAgentView/closeAgentView/sibling-nav stash+restore `s:<tab>` / `a:<runId>` (draft, caret, scroll) through new App fields `windowViewports` + `agentViewByTab`; Chat gains public `draft()/withDraft()`; open view remembered per tab — switchTab closes, records, and re-opens the remembered run when its registry still knows it.
2. **Session-level agent list** — `windowList()` emits every tab's live runs (`forSession`, parked models via `deck->model()`), ⏳ Waiting when that chat's pending ask carries an `AskOrigin` naming the run; strip-chip AND docked-pane row clicks route through `openRunFromWindow()` (switches tab first).
3. **Interrupt send** — `AgentControlMsg::INTERRUPT`, Ctrl+X i chord (leader map), `submitToAgents` passes `MessageMode::Interrupt` to the mailbox; queued row shows `⚡ interrupt` via detail-slot preference (AgentMessageSentMsg untouched); finished runs still take the follow-up route.

## Disclosed deviations
- `src/Chat/Handlers/HandlesRunControl.php` (1-line routing edit, outside files.modify): INTERRUPT could not route otherwise; brief mandates the new verb.
- `App::switchTab` + `deliverAgentControl` + `agentViewSentRows` arms: inside App.php hub, beyond the parenthetical region list; spec-mandated (per-tab memory/row text); sole lane in hub.
- `withDraft(…)->withScrollOffset(…)` order law: `withInputBuf` snaps scroll to draft — reverse order clobbers restored scroll.
- In-step guard flips §1.8: KeyBindingDriftTest (roster+chord closures), KeyBindingRegistryTest 153→154, AgentShellCommandsTest verb roster, AgentComposerTest 4 tests type draft inside view (viewport swap is the new truth).
- lang: `keys.agent-transcript.interrupt` shortened to 45 chars to dodge KeyHelpTest's 49-col ellipsis roster.

## Tests (all targeted, plain pipe)
- NEW `tests/App/AgentWindowViewportTest.php` (5T) + `tests/Chat/AgentInterruptSendTest.php` (4T) — **welder: durations TSV rows owed**.
- Gate: KeyBindingDrift + KeyBindingRegistry + AgentShellCommands + AgentComposer + AgentView + both new files = **OK 308 tests, 3582 assertions**; /tmp/opencode/agentb-gate2.log.

## Foreign/base reds (NOT fixed, proven red at cf0cfd0be)
- `KeyHelpTest` ×5 (footer counts, /skills clip, ellipsis roster session-cycle drift, clipped-field 0>0).

## Incident
Repo-wide stash list was shared with sibling lanes: my WIP stash was popped by another lane mid-gate; fully recovered (dangling-object apply), C-CTX-5.c's mis-popped entry re-registered at stash@{1}, foreign diff archived at /tmp/opencode/cc-cmp-lanes/P-AGENT.b/foreign-stash-residue/. Lane worktree stash@{0} kept as content-verified safety net of the committed state — welder may drop it.

## FIX ROUND 1 — commit 552cc4e71dad8caef72e1ac2a46a2064daeac370 (branch cc/P-AGENT.b, follow-up on 19830f687; 4 files, +31/−7)

- MAJOR-1 FIXED: `tests/Renderer/KeyHelpTest.php` — 'App/AgentWindowViewportTest.php' added to the mutation-domain roster (sorted, first); guard message "a sixth"→"a seventh"; docblock history sentence extended FIVE→SIX naming the new fixture. KeyHelpTest at fix tip: mutation-domain test GREEN, exactly the 5 disclosed base reds remain (63T/5F, names identical to base).
- MINOR-1 FIXED: `src/Tui/KeyboardHandler.php:1049-1051` — refused Ctrl+X i now returns `[$app, null]` (the disarmed app, like the default arm) instead of null. Negative pin appended INSIDE the `agentview.interrupt` drift observation (tests/Commands/KeyBindingDriftTest.php): refused draft → leader false, i swallowed not typed, no control; follow-up 'c' types an ordinary letter and does not reach CANCEL. Mutation proof: reverting to `: null` reddens exactly the agentview.interrupt data set (224T/1F) — cp-restored, php -l clean.
- MINOR-2 FIXED: `src/App/App.php` openRunFromWindow docblock truth-flipped — only the empty id opens nothing; a run the projection no longer carries opens with a null name and the read ladder answers.
- MINOR-3 FIXED (figures re-stamped): the REPORT's declared filter (`--filter '(KeyBindingDrift|KeyBindingRegistry|AgentShellCommands|AgentComposer|AgentView|AgentWindowViewport|AgentInterruptSend)'`, cwd candy-code, plain pipe, env -u LINES -u COLUMNS, -c phpunit.xml) at committed tip 19830f687 = 361 tests / 5055 assertions all green (reviewer's measurement reproduced by this round's re-run at the fix tip as 361T / 5065A, +10A = the new refused-leg pins). Deprecations: 2 environmental, identical at master. The earlier "308/3582" figure was the same filter with exact-suffix class names — superseded by this line; /tmp/opencode/agentb-fix1-gate-final.log.
- MAJOR-2 — NO code change (LEAD ACCEPTED, dated 2026-10-29, recorded verbatim from the fix brief): "INTERRUPT verb cannot route otherwise; hunks disjoint from C-CTX-5.c which merged as 878047472 on master. Welder must rebase-pick onto post-878047472 master and re-run BOTH lanes' gates on merged tree; also welder owes durations.tsv rows for NEW files AgentWindowViewportTest.php + AgentInterruptSendTest.php."
- Laws: author+committer detain@interserver.net (%ae/%ce separate greps ==1 each), zero Co-Authored-By, targeted gates only, no suite-figure.json/durations.tsv/dist commits, no stash (cp-backups under /tmp/opencode/agentb-fix1/), no push, worktree-only.
