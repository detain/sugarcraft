# 15b — Audit: sugar-crush interactive UI state machine and rendering

Scope: `src/Chat.php`, `src/Renderer.php`, `src/App/`, `src/Tui/`, `src/Commands/`, `src/CommandParser.php`, the `*Msg.php` classes, `src/Attachment*.php`.
Excluded: everything in `99-synthesis.md` Part II.

Repro scripts are in `/home/sites/crush-research-repos/_audit-scratch/15b/`. They use `harness.php`, a recording `Backend`, a synchronous Cmd runner and helpers to type and press Enter.

Fixed findings have been removed from this appendix. **One finding remains open.**

---

## Open finding

### 15b-14 — sugar-crush has no i18n: every user-facing string is hard-coded
- **Severity:** Low (convention gap) · **Confidence:** Verified-by-reading · **Status:** deferred by decision until after the roadmap
- **Where:** `grep -rl 'Lang::t' src/` finds no PHP file. There is no `lang/` directory. `Renderer.php:987-992` acknowledges this.
- **Conflict:** CLAUDE.md requires `Lang::t()`. This is recorded for completeness; it is a large job and not a defect in any one string.

---

## Checked and dropped

- **Documented and intentional:** `/HELP`, `/clear all`, `/exit now` and unknown `/foo` fall through to the model (`docs/COMMANDS.md` "Two guards…"). The held queue after Esc Esc goes out after the next prompt (`InFlightInputQueueTest::testAQueueHeldThroughACancelGoesOutOnTheNextSettle`).
- **A parked compaction whose summarization rejects:** `buildSummarizationRequest()` maps the rejection to a `HistoryCompactedMsg` carrying the error and the parked submission; `applyModelCompaction()` then compacts on the heuristic and dispatches the parked turn. `EngineBackend::completeAsync()` rejects on cancellation, worker failure and provider error. Esc Esc remains the release route if the promise never settles. The only residual hazard is generic: a backend that **throws synchronously** inside the `Cmd::promise` factory escapes candy-core's `futureTick` callback, and Chat has no `ExceptionMsg` arm. No shipped backend does this.
- **`App::feedChat()` keeping only the last Cmd:** replaying the key sequence `runRegistryCommand()` synthesizes for every `CommandRegistry` row shows no key before Enter returns a Cmd, so nothing is dropped today. The contract is undocumented and fragile; a cursor-blink Cmd from the input widget would be lost.
- **`--continue` after `/clear`:** `/clear` keeps the session id and saves an empty transcript on purpose; resuming the pre-clear conversation would undo the user's `/clear`. `/rewind` still reaches the checkpoints.
- **`backgroundStatuses` growth:** `BackgroundSupervisor::getSession()` is an in-memory array read, so the per-tick cost per finished session is negligible.
- **Stale mouse zones after a resize:** zones are recorded from the frame actually painted, so a click is hit-tested against what the user saw.
- **Skills pane, agent panes, MCP panel and diff box with hostile text:** clean. `SkillsPane`'s `Width::truncate()` strips escapes; `AgentSplitColumn`, `AgentDashboardPane`, `FilesPane` and `ToolsPane` go through `PaneLabel`; `McpPanel` sanitizes; `renderDiff()` splits on CR and truncates per row.

---

## Coverage

**Audited:** Chat turn machinery (submit, queue, dispatch, every `route()` arm, Escape cancel, the permission flow, tool-event pumping, backend completion, titler and suggestions, parked and model compaction, persistence, session switching, subscriptions, token estimation); Chat input and commands (`CommandParser`, `dispatchCommand()`, custom-command expansion, slash popup, palette dispatch, input history, OSC 52, paste); Chat mouse handling; `src/Commands/`; `src/Renderer.php` (overlay width fuzzed at 16-48 columns and 8-24 rows); the hosted App and TUI panes, `PaneLabel`, `KeyboardHandler` and the `KeyBindingRegistry` roster; the candy-core seams relied on; persistence (`EnhancedSessionStore`, `SessionStore::listSessions()`, `PromptHistory`).

**Read only for reachability or skimmed (no defects claimed):** Chat's local tool path (`invokeTool()`, `forkToolCalls()`, `gateToolCall()`, `collectToolResult()`), unreachable in production because `Bootstrap::chat()` passes no `tools:`; `/memory`, `/bg`, `/fork`, `/websearch`, `/budget`, `/pane`, `/layout`; `intraExchangeTruncation()` and `compactNow()`; `SessionPicker`, `SessionTabs`, `SplitLayout`, `PaneDragController`, `TextSelection`, `DiffGutter`, `McpPanel`, `StallDetector`, `TerminalBackground`, `MenuBar`, `MultiplexerSplitPane`, `Theme.php` and `Palette/*` (scanned for blocking I/O and unsanitized external text only).

**Not audited:** pixel-level PaneDrag/SplitLayout geometry under resize; `KeyboardHandler`'s full chord table against the docs (pinned by `KeyBindingDriftTest`); `renderKeyHelp()` content; `/workflow run` execution internals (report 15e's scope).
