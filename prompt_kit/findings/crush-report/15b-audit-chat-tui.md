# 15b — Audit: sugar-crush interactive UI state machine and rendering

Scope: `src/Chat.php`, `src/Renderer.php`, `src/App/`, `src/Tui/`, `src/Commands/`, `src/CommandParser.php`, the `*Msg.php` classes, `src/Attachment*.php`.
Status: **Final.** What was and was not audited is listed in [Coverage](#coverage).
Excluded: everything in `99-synthesis.md` Part II. Where a finding adds evidence to a known item, it says so.

Repro scripts are in `/home/sites/crush-research-repos/_audit-scratch/15b/`. They use `harness.php`, a recording `Backend`, a synchronous Cmd runner and helpers to type and press Enter. Run them with `php <script>`. Local vendor was in **linked** mode (symlinked siblings), so the results reflect the current monorepo source.

Confidence labels:
- **Verified-by-repro**: a script reproduced the failure.
- **Verified-by-reading**: the code path was traced, but no script was run.
- **Suspected**: the check is not finished.

---

## A. Turn state machine and queue

Every finding here has been fixed, the last (15b-34) in wave 9; see **Fixed since audit**.

## B. Terminal injection and frame geometry

Every finding here has been fixed: 15b-26, 15b-28 and 15b-29 in wave 8A, and 15b-30 and 15b-31 in wave 9; see **Fixed since audit**.

## C. Commands and parsing

Every finding here has been fixed, the last (15b-24, 15b-25) in wave 9; see **Fixed since audit**.

## D. Estimation, i18n, dormant wiring (lower priority)

### 15b-14 — sugar-crush has no i18n: every user-facing string is hard-coded
- **Severity:** Low (convention gap) · **Confidence:** Verified-by-reading
- **Where:** `grep -rl 'Lang::t' src/` finds no PHP file. There is no `lang/` directory. `Renderer.php:987-992` acknowledges this.
- **Conflict:** CLAUDE.md requires `Lang::t()`. This is recorded for completeness; it is a large job and not a defect in any one string.

### 15b-15 — Message attachments are dead weight
- **Severity:** Low · **Confidence:** Verified-by-reading
- **Where:** `Message::attachFile()` / `attachImage()` at `src/Message.php:241,261` have no callers. `EngineBackend::toTypedMessages()` drops `attachments`, and `Chat.php:15822` only copies them.
- **Fix:** Per the "wire, don't delete" rule, add `@file` / paste-image attachment in the input box and map it to `UserMessage::withAttachment()` in `toTypedMessages()`.

## E. Repository-supplied and model-supplied text in overlays and panes

Both findings here (15b-17, 15b-27) were fixed, in waves 7 and 8A; see **Fixed since audit**.

## F. Custom commands, session commands and persistence

Both findings here (15b-20, 15b-21) were fixed in wave 4; see **Fixed since audit**.

---

## Summary table (sorted by severity)

| ID | Sev | Conf | Title |
|---|---|---|---|
| 15b-14 | Low | Reading | No i18n in sugar-crush |
| 15b-15 | Low | Reading | Attachments dormant and dropped on the wire |

**Checked and dropped:**
- **Documented and intentional:** `/HELP`, `/clear all`, `/exit now` and unknown `/foo` fall through to the model (`docs/COMMANDS.md` "Two guards…"). The held queue after Esc Esc goes out after the next prompt (`InFlightInputQueueTest::testAQueueHeldThroughACancelGoesOutOnTheNextSettle`).
- **Lead 1, a parked compaction whose summarization rejects:**
  - `buildSummarizationRequest()` maps the rejection to `HistoryCompactedMsg($id, [], $e->getMessage(), null, $parkedSubmission)` (`src/Chat.php:10848`). `applyModelCompaction()` then compacts on the heuristic and dispatches the parked turn.
  - `EngineBackend::completeAsync()` rejects (it does not resolve an error Message) on cancellation, worker failure and provider error (`:1334`, `:1415`, `:1821`, `:1827`, `:2020`).
  - Esc Esc remains the release route if the promise never settles.
  - The only residual hazard is generic, not specific to this route: a backend that **throws synchronously** inside the `Cmd::promise` factory escapes candy-core's `futureTick` callback (`Program::scheduleCmd()`), and Chat has no `ExceptionMsg` arm. No shipped backend does this.
- **Lead 2, `App::feedChat()` keeping only the last Cmd:** `r11_feedchat_cmds.php` replays the exact key sequence `runRegistryCommand()` synthesizes for every `CommandRegistry` row (slash rows and Ctrl+P palette rows) into an idle Chat. No key before Enter returns a Cmd, so nothing is dropped today. The contract is undocumented and fragile; for example, a cursor-blink Cmd from the input widget would be lost.
- **Lead 5, `--continue` after `/clear`:** `/clear` keeps the session id and saves an empty transcript on purpose. `loadTranscript()` honouring that empty transcript over older checkpoints is correct; resuming the pre-clear conversation would undo the user's `/clear`. `/rewind` still reaches the checkpoints.
- **Lead 6:** confirmed and folded into 15b-10 (`r13_stream_cost.php`).
- **Lead 7, `backgroundStatuses` growth:** `BackgroundSupervisor::getSession()` is an in-memory array read (`src/Sessions/BackgroundSupervisor.php:134-137`), so the per-tick cost per finished session is negligible.
- **Stale mouse zones after a resize** (next step 4 of the checkpoint): candy-core renders on its framerate tick and zones are recorded from the frame actually painted. A click is hit-tested against what the user saw, which is the correct semantics; a resize and a click inside the same tick (≈16 ms) is not a realistic sequence.
- **Skills pane, agent panes, MCP panel and diff box with hostile text:** clean. `SkillsPane`'s `Width::truncate()` strips escapes (verified with an OSC 52 directory name and description, `r18_skill_pane.php`). `AgentSplitColumn`, `AgentDashboardPane`, `FilesPane` and `ToolsPane` go through `PaneLabel`. `McpPanel` sanitizes. `renderDiff()` splits on CR and truncates per row.

**Existing suites rerun** (local vendor, guarded): `InFlightInputQueueTest` (25 tests), `SessionStartHookWireTest` (14), `AutomaticCompactionModelSummaryTest` (37) and `SlashMenuTabCompletionTest` (15) are all green. None of them pins the behaviour reported in 15b-01, 15b-16 or 15b-18. `RendererTest`'s three tab-strip tests check presence and brackets only, not width.

---

## Coverage

**Audited:**
- **Chat turn machinery:** `submit()`, the queue and refusal paths, `dispatchTurn()`, every `route()` arm listed in 15b-01…06, the Escape cancel arm, the permission flow (`beginToolCalls()` … `handlePermissionKey()`), tool-event pumping and placeholder replacement, backend completion, the titler and suggestions, the parked and model compaction routes (`scheduleParkedCompaction()`, `buildSummarizationRequest()`, `applyModelCompaction()`, `compactionChanges()`, `messagesFromWire()`), `persistTranscript()` and `switchToSession()`, `/clear`, palette New session, `subscriptions()` and the background/runtime pumps, and token estimation.
- **Chat input and commands:** `CommandParser`; `dispatchCommand()` with `/rewind`, `/rename`, `/branch`, `/theme`, `/workflow` (dispatch and help), `/help` and the `mcp auth` routing; `expandCustomCommand()`, `commandDirective()` and `refuseCommandShell()`; the slash popup model (`slashMenuMatches()` and related); the palette dispatch (`runSelectedPaletteAction()`, `runRootPaletteAction()`, `runSelectedPaletteActionWhileInFlight()`); input-history recall and recording; `cappedOsc52()` and `relayWidgetCmd()`; and the paste arm.
- **Chat mouse handling:** text selection (`trackTextSelection()`), `SystemClipboard`, and zone lifecycle (`scanRoot()`, `maskImageMarkers()`).
- **`src/Commands/`:** `CommandLoader` (tiers, symlink containment, control-plane reservation) and `CommandSpec` (`fromFile()`, `expandTemplate()`, `runShellSubstitution()`, `includeFile()`).
- **`src/Renderer.php`:** `renderView()`, the status bar, history, assistant, streaming and tool rows, the tool body, the diff, `renderToolImage()`, the slash menu, the session picker entry, the session tab strip, `renderAgentView()`, `renderPermissionPrompt()` and `wrapPermissionText()`, `fitToPane()`, and the `untrusted()` wrapper. Overlay width was fuzzed at 16-48 columns and 8-24 rows (`r17`).
- **Hosted App and TUI:** `App::view()`, `consumeShellCmd()`, `runRegistryCommand()`, `feedChat()`; `Tui\Renderer::renderView()`; `ChatPane`, `FilesPane`, `SkillsPane`, `AgentsPane`, `AgentOutputPane` and the agent split/dashboard sanitization; `PaneLabel`; `KeyboardHandler` (claims, ownership, handle); and the `KeyBindingRegistry` roster.
- **candy-core seams relied on:** `Cmd::promise()`/`AsyncCmd` dispatch, `Program::renderFrame()` image resolution, and `ImageOverlay`; candy-mosaic `ImageLayer` id allocation.
- **Persistence:** `EnhancedSessionStore::saveTranscript()`, `internMessages()` and `loadTranscript()` (cost measured); `SessionStore::listSessions()` memoisation; `PromptHistory` file mode.

**Read only for reachability or skimmed (no defects claimed):**
- `invokeTool()`, `forkToolCalls()`, `gateToolCall()` and `collectToolResult()`. This is Chat's local tool path and is unreachable in production because `Bootstrap::chat()` passes no `tools:`. That is also why 15b-19 is latent.
- `/memory` (known #14), `/sessions` picker internals beyond `sanitizeSessionRows()`, `/bg` and `/fork` (known #30), `/websearch` (known #22), `/budget`, and the `/pane` / `/layout` handlers.
- `intraExchangeTruncation()` and `compactNow()` (mostly covered by known #15-#20).
- `handlePaletteKey()`'s cluster caret helpers.
- `selectPaletteProvider()` and `selectPaletteTheme()` (known #32).
- `SessionPicker`, `SessionTabs` (`Tui/`), `SplitLayout`, `PaneDragController`, `TextSelection` extraction, `DiffGutter`, `McpPanel`, `StallDetector`, `TerminalBackground`, `MenuBar`, `MultiplexerSplitPane`, `Theme.php` and `Palette/*`. These were scanned for blocking I/O and unsanitized external text only; nothing was found beyond the items above.

**Not audited:**
- Pixel-level PaneDrag/SplitLayout geometry under resize.
- `KeyboardHandler`'s full chord table against the docs (pinned separately by `KeyBindingDriftTest`).
- `renderKeyHelp()` content.
- `/workflow run` execution internals (WorkflowEngine is in report 15e's scope).

**Repro scripts** (`/home/sites/crush-research-repos/_audit-scratch/15b/`, each run with `php <script>` and autoloaded via `sugar-crush/vendor/autoload.php`):

| Script | Shows |
|---|---|
| `harness.php` | Shared helpers: `RecBackend`, `runCmd` / `pump` / `type` / `enter` / `dumpHist` |
| `md_inject.php` | U+009B passes CandyShine (15b-08) |
| `r1_wire.php` | Command output and notices on the wire (15b-03) |
| `r2_stale_placeholder.php` | Placeholder after cancel; result mis-attached (15b-02) |
| `r3_width.php` / `r3b.php` | Status bar overwidth; 25-column overflow; CR in frame (15b-07, 15b-09) |
| `r4_app.php` (+ `prov.php`) | Hosted App frame with CR (15b-07) |
| `r5_cmdparse.php` | `mcp auth` prefix capture (15b-11) |
| `r6_hook_bypass.php` | Hook bypass on the parked route (15b-01) |
| `r7_perf.php` / `r7b.php` | History render cost (15b-10) |
| `r8_menu_draft.php` | Draft erased by menu (15b-05) |
| `r9_toolinj.php` | Per-field tool-row injection matrix (15b-07, 15b-08) |
| `r10_forged_marker.php` | Forged image marker gives a second paint; PUA glyphs blanked (15b-17) |
| `r11_feedchat_cmds.php` | No intermediate Cmd in any registry key sequence (lead 2, dropped) |
| `r12_persist_cost.php` | Transcript save cost, branch re-intern (15b-21) |
| `r13_stream_cost.php` | Streaming partial render cost (15b-10) |
| `r14_cmd_shell.php` | 10 s blocking `` !`…` ``; orphaned grandchild (15b-20) |
| `r15_overlay_inject.php` | OSC 52 / CSI 2J / CR from a project command file in the "/" popup (15b-16) |
| `r16_wordwrap_utf8.php` | Byte-wrapped permission text; CR survives (15b-19) |
| `r17_overlay_width.php` | Overlay width and height fuzz (15b-09) |
| `r18_skill_pane.php` | Skills pane strips hostile names (dropped) |
| `r19_tabstrip.php` / `r19b_tabstrip_noevil.php` | Tab strip width and escapes, standalone and hosted (15b-18) |
| `r20_rewind_args.php` | `/rewind help` rewinds; `/rename:x` stores `:x` (15b-22) |
| `dbg.php` / `dbg2.php` | Scratch only |

---

## Fixed since audit

These findings were fixed on master after the audit. Their sections and table rows were removed; the repro and coverage lists above still name them.

- **15b-01** UserPromptSubmit hook skipped on the parked 85% compaction route — fixed on master in `9c13a918e`. Residual: if the summary later refuses the turn (spend cap or the 95% tier), the hook has already seen the prompt.
- **15b-16** A cloned repository's command-file description wrote OSC 52 and screen clears to the terminal on "/" — fixed on master in `cb3dee7fd`.
- **15b-02** After a double-Escape cancel, tool placeholders stayed "running" forever and later same-id results landed on the old row — fixed on master in `855e42673` (the cancel arm maps every pending row to the "interrupted" row `reviveCheckpointMessage()` builds, with an error tool result under the same id; `replaceToolRunningPlaceholder()` and `finishToolCalls()` search newest first, and each result claims only its own rows). Residual: the healed row reused the `INTERRUPTED_TOOL_CALL` text ("…interrupted by restart") even after a user cancel (R15); fixed since in `bdd1f6eae` (wave 9: the cancel heal writes the new `CANCELLED_TOOL_CALL`, "Tool call interrupted: the user cancelled the turn", through one shared `interruptedToolCallMessage()` builder; checkpoint and resume revival keep the restart text, and `isInterruptedResult()` matches both). A per-placeholder generation stamp was deferred; it is moot given the heal and the existing generation guards.
- **15b-06** Switching session kept the compaction thrash counter — fixed on master in `b16819b13` (`switchToSession()` and palette New session share `sessionChangeResets()`; `lastActivityAt` goes to null).
- **15b-07** Raw CR reached the terminal from user/system rows, tool names and descriptions and expanded tool output — fixed on master in `74ae88c2a` (new candy-core `Sanitize::untrustedForDisplay()` maps CRLF and lone CR to LF; the Renderer's `untrusted()` wrapper uses it, and one-line rows go through a new `oneLine()` before truncation).
- **15b-08** UTF-8-encoded C1 controls passed every sanitizer — fixed on master in `af42238fc` (candy-core `Sanitize::untrusted()` and candy-shine `Renderer::stripControls()` remove `\xC2[\x80-\x9F]`). Lone raw C1 bytes in candy-shine (15b-29) and bidi and zero-width characters (15b-28) were fixed later, in wave 8A.
- **15b-11** Any prompt starting "mcp auth" was captured by the MCP command — fixed on master in `373e7d953` (both sites use `isBareMcpAuthCommand()`, `/^mcp\s+auth(?:\s|$)/`; `docs/COMMANDS.md` states the whole-word rule).
- **15b-19** The latent permission modal wrapped by bytes and kept CR — fixed on master in `e4fd37010` (new candy-core `Sanitize::visibleControls()` renders every control byte visibly in caret or `<U+…>` notation; CR maps to LF, zone sentinels are spelled out, and the text wraps by cells with `Width::wrap()`). Found while fixing it: `Width::wrap()` hangs at a 1-column budget (15b-26), and an invalid-UTF-8 argument is described as empty (15b-27).
- **15b-22** `/rewind help` (any non-numeric argument) performed a rewind, and `/name:arg` reached handlers with a literal `:` — fixed on master in `0d094ff25` (`/rewind` accepts only an empty or `ctype_digit` count ≥ 1; the raw-text handlers take `Chat::commandArgument()`, which drops one space or `:` separator). Residual: `/pane`, `/layout` and `/mcp` still split the whole draft on whitespace (documented in `docs/COMMANDS.md`; 15b-24), and the command table's `/rewind` *Takes* column still shows `—` (15b-25).
- **15b-23** Positional `$N` splitting: an apostrophe swallowed the rest of the line and `""` shifted the arguments — fixed on master in `0147c5f7a` + `1173b2ada` (a quote opens a span only at a token start, an unterminated quote stays literal, and an empty quoted span yields an empty token). Residual: `/model ""` now answers "Could not switch to provider ''" instead of opening the palette, because the user typed an explicit empty name.
- **15b-10** Every frame re-rendered the whole history through CandyShine — fixed on master in `05f86a2a9` (exact memos in `src/Renderer.php`: settled CandyShine bodies in an LRU per width and content hash, scoped to one theme object; incremental streaming through CandyShine's `SectionScanner`, re-rendering only the open tail; per-row SGR transitions in `balanceSgr()`; the line count of collapsed tool bodies; the tool-zone dedup is a keyed lookup and labels are styled once per frame; 529 frames of a differential corpus are byte-identical to the old Renderer). At 120×40: 50/200/800 exchanges 182/698/2949 → 15/52/211 ms per frame, 300 warm markdown exchanges 965 → 38 ms (target was under 50), a 200K streaming partial 2197 → 81 ms. Residual: `r13_stream_cost.php` as written (headings straight after a closing fence) was only partly faster, 207/2526 → 112/1120 ms at 20K/200K, because `SectionScanner` found no boundary there (15b-31), and the memo worked around two candy-shine bugs (15b-30, 15b-31). Both are fixed since in wave 9 (`d35d99ed1`, `af6a5cce1`): the frame is now 8/34/70 ms at 20K/100K/200K, and the memo holds a candy-shine `SectionStream` with both workarounds dropped.
- **15b-12** Session titling fell back to the main, tool-armed backend — fixed on master in `37ff6d54f` (titling is skipped when `titleBackend` is null, the same gate prompt suggestions use; no other `?? backend` fallback exists).
- **15b-04** UserPromptSubmit and SessionStart hook chains ran synchronously inside `update()` — fixed on master in `f1b6862e9` (script turn hooks run off `update()` in a forked child, and the turn is dispatched from the resolved `TurnHooksResolvedMsg`).
- **15b-20** A custom command's `` !`…` `` ran synchronously inside `update()` for up to 10 s, and on timeout its grandchildren survived — fixed on master in `2826f5cf3` (the expansion runs off `update()` through a forked child, `forkedPayloadCmd()`, and resolves to `CustomCommandExpandedMsg`; on timeout `ProcessContainment::killTree()` plus a SIGKILL to the process group also catch `&` background jobs; gate checks made in the child are replayed into the session gate).
- **15b-21** `/branch` and any first save of a long history froze the TUI for seconds, one autocommitted INSERT per message — fixed on master in `698a1efff` (with 15e SES-2: save, checkpoint and restore each run in one `BEGIN IMMEDIATE` transaction, and a fork copies the blobs, so the first save on a `/branch` re-interns nothing). Measured at 800 messages: first save 6519 → 233 ms, first save on a branch 5996 → 17 ms; the fork itself takes about 470 ms (the fsync of the copied blobs). Residual: persistence still ran synchronously from `Chat::update()` (`persistTranscript()`) (R2); fixed since in `14f682fad` (wave 10: `persistTranscript()` hands its snapshot to a new `Session\DebouncedTranscriptWriter`, flushed by a keyed `crush.transcript-flush` subscription tick every 0.5 s while a snapshot is pending, not a resetting debounce; synchronous flushes on a session switch, before `/branch` and `/fork`, at shutdown and in the writer's destructor; a snapshot pending for more than four windows is written by the next change for hosts that never run `subscriptions()`; a read-only session never saves). Remaining: a SIGKILL can lose at most one 0.5 s window, and `dispatchTurn()`'s submit-time checkpoint is still synchronous (once per prompt).
- **15b-09** The chat status bar was never clipped to the terminal width, and the content width (with every overlay) was floored at 20 plus chrome — fixed on master in `66d0651ac` (the status-bar hint shortens step by step, keeping the "Ctrl+P menu" click zone longest; a `fitStatusBar()` backstop strips zone markers before it cuts, so a cut never splits one; the content-width floor is `max(1, cols-6)`, the image box and diff box floors drop to 1, and the slash popup is capped at the terminal width; at 6 columns or fewer `clipFrameToCols()` cuts the bordered shell, with every `Width::wrap` budget kept at 2 or more for 15b-26). The new width test exposed a second bug, fixed in the same commit: Veil counted zone markers as screen cells, so rows under an overlay were split at the wrong column and overflowed; zones are now lifted out before compositing and put back afterwards. Measured: `r3b` last row 54 → 38 cells at 40 columns and 54 → 29 at 30; `r3_width` at 25 columns 45 over-wide rows → 0; `r17_overlay_width` 21 over-wide cases → 0. Residual: `src/Commands/TranscriptTable.php` copied the old `max(20, cols-6)` floor, and the permission modal's inner width was floored at 20, so below 26 columns it lost its right border (R4); fixed since in `3de2c30a4` (wave 9: the modal's inner width is `max(1, min(60, cols-6))` and keeps its right border from 7 columns up; `TranscriptTable::paneWidth()` is `max(1, cols-6)`) and `dac19d6f4` (Chat's `/help` listing floors at 1, and its heading is clipped too).
- **15b-18** The session tab strip was neither width-clipped nor sanitized — fixed on master in `01cae6d21` (each name goes through `Sanitize::untrustedForDisplay()` and `PaneLabel::safe()`, which removes escapes and control bytes, folds CR/LF/TAB to a space and drops Private-Use characters, and an empty name falls back to the cleaned id; names are capped at 20 cells with an ellipsis, the current tab is always shown, tabs that do not fit collapse into `… +N`, only visible tabs get click zones, and the strip stays one row). Measured at 80 columns: 8 long names 383 → 73 cells; a hostile name 402 → 69 cells with no OSC 52, `\e[2J` or CR; hosted App at 100 columns 433 → 100 cells.
- **15b-05** Menu-bar and shell commands erased the user's draft, then mid-turn refused with "Your draft is still in the box" — fixed on master in `ecca2b606` + `c1e836427` (new `Chat::runCommand()` and `Chat::runPaletteAction()` run a command without touching the draft, and `App::runRegistryCommand()` uses them instead of feeding synthetic Backspace, Delete and Enter keys). Still open nearby: Chat's own Ctrl+A arm still types `/agents` into the box (15b-34).
- **15b-17** Model or tool text containing U+E002+n painted a copy of on-screen image n and blanked Nerd Font glyphs — fixed on master in `b38bf8403` (`ImageOverlay::marker()` is a zero-width authenticating OSC, `ESC ] candy-image ; <id> ESC \`, plus the U+E002+id cell, and `resolve()` paints only that pair; untrusted text cannot carry the escape because every untrusted sink deletes ESC, and an escape whose cell a layout pass cut off is dropped; bare Private-Use codepoints are left alone, so Powerline and Nerd Font glyphs survive; `Program::renderFrame()` resolves every frame, so a marker on a frame with no image layer never reaches the terminal; `r10_forged_marker.php`: `paints=2` before, `paints=1` after, and U+E002, U+E0B0 and U+F115 survive in the line). Marker rows stay 1 cell wide (the OSC is zero-width to `Width`, `truncateAnsi`, `wrapAnsi` and candy-mouse `Scan`). Residual: stale marker prose in candy-core and ChatPane (15b-32).
- **15b-26** candy-core `Width::wrap()` never terminated when a 2-cell cluster met a 1-column budget — fixed on master in `758f098c1` (when `truncate()` fits nothing, the leading cluster is emitted alone on an over-wide row, as `wrapAnsi()` does, so every pass consumes at least one cluster; `WidthWrapOverWideClusterTest` runs each case under a SIGALRM deadline).
- **15b-28** Bidi overrides and zero-width characters passed every sanitizer — fixed on master in `531a0941f` + `dd4e4aa05` + `e3f6756ac` (new public `Sanitize::markInvisibleFormatting()` marks U+202A–202E, U+2066–2069, U+200B, U+2060 and U+FEFF always, and ZWNJ, ZWJ, LRM, RLM and ALM only at the start, after ASCII or after another such mark, so emoji ZWJ sequences, Persian and Indic ZWNJ and RTL marks still work; `untrustedForDisplay()` applies it and `visibleControls()` marks all of them; candy-shine `stripControls()` applies it too, covering assistant markdown and code blocks). `untrusted()` and `untrustedForMarkedFrames()` are unchanged for paste fidelity. Behaviour change: display policies now emit `<U+XXXX>` markers for these codepoints, a leading BOM included.
- **15b-29** candy-shine `stripControls()` kept lone raw 0x80–0x9F bytes — fixed on master in `dd4e4aa05` (lone C1 bytes outside well-formed UTF-8 are removed before the C0 sweep, so no `\xC2\x9B` pair can be spliced together). On master `render("a\x9B2Jb")` actually threw CommonMark's `UnexpectedEncodingException`; with sanitising on (the default), `render()` and `renderSection()` now also scrub before the parse, removing lone C1 and repairing other malformed UTF-8 to U+FFFD, and `stream() === render()` still holds.
- **15b-27** The permission modal showed an empty value for an argument that was not valid UTF-8 — fixed on master in `a0f07cd5a` (`Message::describeToolCall()` encodes with `JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE`, falls back to `visibleControls()` and never to `''`, and re-escapes C1 and bidi/zero-width characters so the label stays inert; a non-string `0` is no longer dropped by `?:`). The headless prompt got the same treatment in `e1acd6f0f` (15e lead 6, R17).
- **15b-03** Command output, mid-turn notices and background and runtime notices went to the model as real turns — fixed on master in `2a3a8f91c` (`Message::$uiOnly`, filtered at every wire encoder, in the titler and in the suggester), `5d2aaae34` (wave 8B: every compaction call site reads agent-visible rows only through `compactionWire()`, on both sides of the exchange-key alignment; `messagesFromWire()` and `intraExchangeTruncation()` put the UI-only rows back through `withUiOnlyRowsRestored()`, in place from the first preserved row on and verbatim ahead of the summary for the condensed region; the 95% refusal's "each further attempt drops the oldest" is made explicit by `Chat::blockedAttempts()` and `ContextCompactor::withRecentPreserveReducedBy()`, because with the filter it would otherwise have been a wedge only `/clear` escaped) and `6fddd0d3a` (N1: notices stay inline, by decision, and a UI-only `Role::System` row renders as `notice: …` in the dim `systemLabel` colour plus italic). Left visible on purpose: `/websearch` results (known #22), hook `additionalContext`, the 70% reminder and the permission-refusal note. Behaviour change: UI-only rows are no longer condensed by compaction, and a session blocked at 95% gets out on the second retry, or with `/compact` and one retry (before, the first retry escaped by accident).
- **15b-30** candy-shine `stream()` broke its "equals `render()`" law when the text had link reference definitions — fixed on master in `d35d99ed1` (wave 9: new `candy-shine/src/Render/SectionStream.php` is the one incremental section renderer that `stream()`, `Writer` and sugar-crush's frame memo share; definitions are carried forward into each section's parse, first one winning, and a section that still holds an unresolved reference is held back until a later definition resolves it or `finish()`) and `af6a5cce1` (sugar-crush drops its whole-render fallback for `LINK_REFERENCE_DEFINITION`).
- **15b-31** candy-shine `SectionScanner::finish()` dropped the closed section before a trailing heading, and no boundary was found after a closing fence — fixed on master in `d35d99ed1` (wave 9: `finish()` returns the closed section with the unterminated heading; a heading straight after a closing fence is a boundary; every scanner boundary is now a proposal the parser confirms, which also fixed three law breaks found by probing — an outdented fence close inside a list item, a multi-line HTML comment across a blank line, and a backtick in a fence's info string; `r13_stream_cost.php` is 8/34/70 ms per frame at 20K/100K/200K, was 113/557/1150) and `af6a5cce1` (sugar-crush drops its byte-offset tail cut).
- **15b-34** Ctrl+A ran `/agents` by typing it into the input box, wiping an idle draft — fixed on master in `0f0366592` (wave 9: Ctrl+A calls `runCommand('/agents')` idle and mid-turn, the mid-turn arm still closes overlays, the draft is never replaced, and the mid-turn notice says "Your draft was not touched").
- **15b-24** `/pane:x`, `/layout:x` and `/mcp:x` colon spellings were not handled — fixed on master in `ec6848667` (wave 9: new `Chat::commandTokens()` splits `commandArgument()`'s result; `/pane`, `/layout` and `/mcp` (through `parseMcpArgs()`) use it, and the `docs/COMMANDS.md` caveat is gone).
- **15b-25** The registry-derived command table showed `/rewind` as taking no argument — fixed on master in `90fbd4de4` (wave 9: `/rewind` carries the hint `[n]`; the new `CommandsTableTakesColumnDriftTest` derives every *Takes* and description cell from `CommandRegistry::all()`, and caught a second drift: the `/mcp` row now carries `<list|add|remove|login> [server]`) and `207dfbab1` (a slash-popup test no longer uses `/rewind` as its hintless row).
- **15b-32** Stale comments: launch notices "re-sent every turn", Doctor's old mosaic idiom, and the one-codepoint image marker — fixed on master in `904d365c9` (wave 9: the Bootstrap and `SessionStore` rationales give the transcript-clutter reason, `DetectsCapabilities` points at `ToolResult::mosaic()`, candy-core `Sanitize`/`View` and `ChatPane` describe the two-part marker, and `docs/SETTINGS.md` no longer says launch notices reach the model).
- **15b-33** A `/fork` docblock named `SessionStore::forkSession()` as the transcript copy — fixed on master in `67440e5ec` (wave 9: the `@see` cites `EnhancedSessionStore::forkSession()`).
- **15b-13** The token proxy counted codepoints/4, underestimating CJK and emoji 3-6× — fixed on master in `8341a37c1` (script-weighted `TokenEstimate` for Chat's estimate, the 85/95% tiers and the status bar), `eb8d3b2a5` (wave 8B: the compactor counts the same way) and `2dac6ebc2` (wave 9: the two chars/4 comments in `Usage.php` describe `TokenEstimate`).
- **15b-35** The status bar showed the static default model id, not the model the SGLang server serves — fixed on master in `42bbb28c5` (wave 10: a new `Providers\ReportsServedModel` interface, implemented by `SglangProvider`, I/O-free `servedModel()` plus `noteServedModel()`; the forked turn child puts `servedModel` on its result frame on success and failure, and `settleFromResultFrame()` notes it on the parent's shared provider; `Tui\Renderer::modelLabel()` prefers the hosted Chat backend's served model, then the App provider's, then `App::$model`, and the shell status bar and the Settings pane's Model row read it). Residual: the hosted Chat's own status bar (`src/Renderer.php::renderStatusBar()`) has no model segment, and every production launch hosts a Chat, so today the visible change is the Settings pane row; a lowest-priority model segment is scheduled for wave 11. `Bootstrap::openSession()` still records the static id on the session row.
