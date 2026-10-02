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

### 15b-03 — UI-only rows go to the model: command output, mid-turn notices, background and runtime notices
- **Severity:** Medium-High · **Confidence:** Verified-by-repro (`r1_wire.php`)
- This is new evidence for known #22, which names only `/websearch`, and for #2: SGLang hoists System rows into message 0.
- **Where:**
  - `EngineBackend::toTypedMessages()` at `src/Backend/EngineBackend.php:2071-2083` maps **every** history row to a wire message. There is no ui-only filter.
  - Producers:
    - The `*Response()` helpers at `src/Chat.php:9489, 10354, 12011, 12051, 12464, 16005`, and others, each push `Message::user($inputText), Message::assistant($response)`.
    - `/help` pushes an assistant row.
    - `enqueuePrompt()` at `:7533` and `refuseInFlightCommand()`.
    - `pumpBackgroundSessions()` and `pumpRuntimeNotices()` at `:14609`. These carry git stderr and model-authored tool names from `MinimaxXmlFallbackToolCallParser` warnings.
    - `handlePaletteNewSession()` at `:14182` seeds a new session with an assistant message.
    - The backend error path at `:9402`, `Message::assistant('_[error: …]_')`, becomes the assistant's own words.
- **Repro:** This sequence: `/help`, `/permissions`, prompt 1, mid-turn `/budget` (refused), mid-turn prompt 2 (queued). The wire for prompt 2 is:
  ```
  0 assistant "Slash commands (25): …"            ← /help output, as if the model said it
  1 user      "/permissions"
  2 assistant "No permission gate is attached …"
  3 user      "first question"
  4 system    "/budget is a command, and commands do not run while a turn is in flight …"
  5 system    "Queued (1 waiting) — sent as soon as this turn finishes: second question"
  6 assistant "answer one"
  7 user      "second question"
  ```
  Mid-turn notices are also written **between** a user turn and its answer (rows 4-5).
- **Impact:**
  - Wastes tokens.
  - On SGLang, every notice is hoisted into the system prompt, so the cache prefix breaks on every notice.
  - The model is told it said things it did not say, such as error strings and `/help` text.
  - A transcript can start with an assistant row, which strict providers reject.
  - The session titler's `$userTurns !== 1` check (`:9020-9026`) counts command echoes, so a session whose first input was `/permissions` is never titled.
- **Fix:** Add a `uiOnly` / `agentVisible=false` flag on `Message` (synthesis 1.B) and set it on every notice and command-echo producer above. Filter in `toTypedMessages()` and in the titler and suggestion prompts. Keep notices ordered after the settled answer, or render them in a separate notice stream.
- **Test:** Drive `/help` → prompt through a recording backend. Assert that the backend history contains only the user prompt.
- **Partly fixed on master in `2a3a8f91c`.** New `Message::$uiOnly` (with `withUiOnly()`, `Message::notice()` and `Message::agentVisible()`) round-trips through `jsonSerialize()`/`fromArray()` and survives every wither, checkpoint revival and the compaction rebuild, and is never put on the wire. Every command echo and output, `/help`, the queued, refusal and hook-blocked notices, launch, runtime and background notices, palette rows, status notices and backend error strings are flagged. The filter runs at Chat's turn dispatch, in the titler (which now counts agent-visible user turns), the suggester, `EngineBackend::toTypedMessages()` and `CommandBackend::encodeHistory()` (shared by `StreamingCommandBackend`); the token estimate skips UI-only rows. Left visible on purpose: `/websearch` results (known #22), hook `additionalContext`, the 70% reminder, and the permission-refusal note (the model's only record of the refusal). **Remaining:**
  - the compaction summary's input is not filtered, because filtering only one side breaks the exchange-key alignment;
  - notice order is unchanged: notices still render, interleaved, between a prompt and its answer in the transcript (they are off the wire).

### 15b-34 — Ctrl+A still runs `/agents` by typing it into the input box: an idle draft is wiped, and the mid-turn refusal says the draft is still in the box
- **Severity:** Low · **Confidence:** Verified-by-reading (found while fixing 15b-05 in wave 6)
- **Where:** `src/Chat.php:2491-2492`: the Ctrl+A arm is `$this->withInputBuf('/agents')->submit()`. Mid-turn it is routed (`:8742`) to `refuseInFlightCommand()` (`:8541`), whose notice ends "Your draft is still in the box: press Enter again once the turn finishes".
- **Detail:** since 15b-05's fix (`ecca2b606`, `c1e836427`), menu-bar rows, Ctrl+N and the provider picker go through `Chat::runCommand()` / `Chat::runPaletteAction()` and never touch the draft. Ctrl+A was not moved: when idle it replaces whatever the user had typed with `/agents` and submits it, so the draft is lost. Mid-turn the draft is not moved, but the refusal names `/agents` and tells the user to press Enter again later, which would send the draft, not `/agents`.
- **Fix:** make the Ctrl+A arm call `runCommand('/agents')`, which leaves the draft alone and refuses mid-turn with wording that fits a command the user did not type.
- **Test:** an idle Chat with a draft; Ctrl+A opens the agents view and `inputBuf` is unchanged. Mid-turn, Ctrl+A's notice does not say the draft holds `/agents`.

## B. Terminal injection and frame geometry

15b-26, 15b-28 and 15b-29 were fixed in wave 8A; see **Fixed since audit**.

### 15b-30 — candy-shine `Renderer::stream()` breaks its "equals `render()`" law when the text has link reference definitions
- **Severity:** Low · **Confidence:** Verified-by-repro (found while fixing 15b-10 in wave 3; re-checked with `Renderer::plain()`: `stream()` gives `See [x][a].…`, `render()` gives the resolved hyperlink)
- **Where:** `candy-shine/src/Renderer.php:261` (`stream()`). It renders each closed section on its own, so a reference-style link (`[text][foo]`) in one section and its definition (`[foo]: https://…`) in another never meet.
- **Failure scenario:** a streamed answer that uses reference links renders the link as literal `[text][foo]` text (the definition itself is consumed, unresolved) until the answer settles and is rendered whole, so the transcript reflows at the end of the turn. Any other `stream()` consumer gets output that differs from `render()` of the same text. sugar-crush works around it (`05f86a2a9`) by rendering any partial that contains a link reference definition whole, which gives up the incremental speed-up for those answers.
- **Fix:** collect link reference definitions across sections (a pre-scan of the whole text, or re-rendering the sections that used an undefined reference once a definition arrives), or make `SectionScanner` refuse to split a text containing one.
- **Test:** `implode('', iterator_to_array(stream([$t])))` equals `render($t)` for `$t = "See [x][a].\n\n# H\n\n[a]: https://e.x\n"`; then drop the sugar-crush workaround and keep its memo test green.

### 15b-31 — candy-shine `SectionScanner::finish()` drops the closed section when the unterminated last line is a heading; no boundary is found after a closing fence
- **Severity:** Low · **Confidence:** Verified-by-repro (found while fixing 15b-10 in wave 3; re-checked: `stream(["Intro\n\n# F"])` gives `["# F"]`, `render()` gives both)
- **Where:** `candy-shine/src/Render/SectionScanner.php:64` (`finish()`), and its section-boundary rule.
- **Failure scenario:**
  - `stream(["Intro\n\n# F"])` yields only the heading: the closed "Intro" section is thrown away when the last line has no newline and is a heading. sugar-crush works around it (`05f86a2a9`) by cutting the tail by byte offset instead of trusting `finish()`.
  - A heading right after a closing code fence, with no blank line between, is never treated as a boundary, so a long partial in that shape has no closed sections and re-renders whole every frame. This is the residual of 15b-10: `r13_stream_cost.php` (headings straight after fences) went only from 207/2526 ms to 112/1120 ms per frame at 20K/200K, while partials with a blank line before each heading went from 2197 ms to 81 ms at 200K.
- **Fix:** make `finish()` emit the pending closed section before the trailing heading, and widen the boundary rule to a heading that follows a closing fence (and other unambiguous block starts), so long partials without blank-line headings also stream incrementally.
- **Test:** `stream(["Intro\n\n# F"])` yields both sections; `r13_stream_cost.php` at 200K renders in under 100 ms per frame.

## C. Commands and parsing

### 15b-24 — `/pane:x`, `/layout:x` and `/mcp:x` colon spellings are not handled
- **Severity:** Low · **Confidence:** Verified-by-reading (found while fixing 15b-22)
- **Where:** the `/pane`, `/layout` and `/mcp` arms in `src/Chat.php`. The 15b-22 fix (`0d094ff25`) moved the raw-text handlers onto `Chat::commandArgument()`, which accepts a space or `:` after the name. These three still split the whole draft on whitespace, so `/pane:dock left` arrives as the tokens `["/pane:dock", "left"]`.
- **Failure scenario:** `CommandParser` routes `/pane:dock left` to the `/pane` arm (a name ends at `:`), but the arm reads its sub-command from the wrong token and answers with usage or an unknown sub-command. `docs/COMMANDS.md` now documents the limitation ("for those three use the space spelling"), so this is a consistency gap, not a silent wrong action.
- **Fix:** tokenise `commandArgument()`'s result instead of the whole draft in these three arms, then drop the caveat from `docs/COMMANDS.md`.
- **Test:** `/pane:dock left`, `/layout:<name>` and `/mcp:list` each behave exactly like their space spellings.

### 15b-25 — The registry-derived command table shows `/rewind` as taking no argument
- **Severity:** Low (docs) · **Confidence:** Verified-by-reading
- **Where:** `src/Commands/CommandRegistry.php:282` (`CommandSpec::new('rewind', 'Restore chat state from an earlier checkpoint', 'Session')`, no argument hint), rendered into `docs/COMMANDS.md:304` as `| /rewind | ✓ | | — | … |`.
- **Detail:** since 15b-22's fix, `/rewind` accepts an optional step count (`/rewind 3`, `/rewind:3`) and refuses anything else, as the prose below the table says. The table's *Takes* column is the row's own `argumentHint` and still shows `—`, so the table and the prose disagree.
- **Fix:** give the registry row an argument hint such as `[n]` (it also shows in the slash popup) and update the table row to match.
- **Test:** a doc assertion that every table row's *Takes* cell equals its registry row's `argumentHint` (or `—`), which would also have caught this one.

## D. Estimation, i18n, dormant wiring (lower priority)

### 15b-13 — The token proxy counts codepoints/4, so CJK and emoji text is underestimated 3-6×
- **Severity:** Low-Medium · **Confidence:** Verified-by-reading
- **Where:** `src/Chat.php:14738` `ceil(mb_strlen($msg->content) / 4)`. Calibration is clamped to [1.0, 3.0].
- **Effect:** CJK text runs at roughly 1-1.5 tokens per character. Even fully calibrated, the 70/85/95% tiers fire far too late for CJK users, which leads to provider overflow errors. This is separate from known #21, which concerns the system prompt and tool schemas.
- **Fix:** Count bytes/3, or weight by script (wide characters ≈1 token). A tokenizer-backed estimate would be better.
- **Test:** 10k CJK characters must estimate to at least 8k.
- **Partly fixed on master in `8341a37c1`** (Chat side). New `src/Util/TokenEstimate.php` is a script-weighted proxy: ASCII and Latin ¼ token (the old figure), other alphabets ½, CJK, kana, Hangul and symbols 1, astral and emoji 2, and bytes/3 for invalid UTF-8; 10k CJK characters now estimate 10,010 (was 2,510). Chat's 85% and 95% tiers and the status bar use it. **Remaining:**
  - `src/Context/ContextCompactor.php:1194` still counts `mb_strlen / 4`, so the 70% reminder still fires late for CJK;
  - stale "chars/4" comments remain in `Renderer.php` (`:2169`, `:2356`), `Usage.php` (`:17`, `:335`), `Backend/ReportsContextWindow.php:52` and `Util/TokenTracker.php:47`.

### 15b-14 — sugar-crush has no i18n: every user-facing string is hard-coded
- **Severity:** Low (convention gap) · **Confidence:** Verified-by-reading
- **Where:** `grep -rl 'Lang::t' src/` finds no PHP file. There is no `lang/` directory. `Renderer.php:987-992` acknowledges this.
- **Conflict:** CLAUDE.md requires `Lang::t()`. This is recorded for completeness; it is a large job and not a defect in any one string.

### 15b-15 — Message attachments are dead weight
- **Severity:** Low · **Confidence:** Verified-by-reading
- **Where:** `Message::attachFile()` / `attachImage()` at `src/Message.php:241,261` have no callers. `EngineBackend::toTypedMessages()` drops `attachments`, and `Chat.php:15822` only copies them.
- **Fix:** Per the "wire, don't delete" rule, add `@file` / paste-image attachment in the input box and map it to `UserMessage::withAttachment()` in `toTypedMessages()`.

### 15b-32 — Stale comments: launch notices "re-sent every turn", and Doctor's old mosaic idiom
- **Severity:** Info (comments) · **Confidence:** Verified-by-reading (found during wave 3)
- **Where:**
  - `src/Cli/Bootstrap.php:412` and `src/Session/SessionStore.php:555` justify keeping per-entry launch rows out of the transcript because each would be "a list the model is re-sent every turn".
  - `src/Tools/Concerns/DetectsCapabilities.php:38` cites `Doctor::execute()`'s `self::$mosaic ??=` as the house idiom for a lazy capability probe.
  - Added in wave 7: since 15b-17's fix (`b38bf8403`) an image marker is a zero-width authenticating OSC plus the U+E002+id cell, but `candy-core/src/Util/Sanitize.php` and `candy-core/src/View.php` still describe the marker as just "U+E002 + id" (now only its cell half), and `src/Tui/Components/ChatPane.php:84` says a marker leaks when the images are dropped, which `Program::renderFrame()` no longer allows.
- **Detail:** since 15b-03's fix (`2a3a8f91c`), launch notices are `uiOnly` rows and never reach the model, so the token-cost half of those two rationales is no longer true (the transcript-clutter half still is). Since F-T6's fix (`977179c1e`), Doctor reads the boot-time `ToolResult::mosaic()` probe and no longer has a `??=` probe, so the docblock points readers at code that does not exist.
- **Fix:** reword the two rationales to the transcript-clutter reason, point the `DetectsCapabilities` docblock at `ToolResult::mosaic()` (and the boot-time warm-up), and describe the two-part marker in the candy-core and ChatPane docs.
- **Test:** none needed beyond review.

### 15b-33 — A `/fork` docblock still names `SessionStore::forkSession()` as the transcript copy
- **Severity:** Info (comment) · **Confidence:** Verified-by-reading (found during wave 4)
- **Where:** `src/Chat.php:13269`, in the docblock of the `/fork` handler: "The transcript copy is {@see SessionStore::forkSession()}, the same call `/branch` makes".
- **Detail:** since 15e SES-2's fix (`698a1efff`), the copy that carries the conversation is `EnhancedSessionStore::forkSession()`, which copies the transcript, checkpoints, blobs and meta in one transaction around `SessionStore::forkSession()`'s row copy. The `@see` points readers at the method that copies only the `sessions` row and the legacy tables.
- **Fix:** re-point the `@see` at `EnhancedSessionStore::forkSession()`.
- **Test:** none needed beyond review.

## E. Repository-supplied and model-supplied text in overlays and panes

Both findings here (15b-17, 15b-27) were fixed, in waves 7 and 8A; see **Fixed since audit**.

## F. Custom commands, session commands and persistence

Both findings here (15b-20, 15b-21) were fixed in wave 4; see **Fixed since audit**.

---

## Summary table (sorted by severity)

| ID | Sev | Conf | Title |
|---|---|---|---|
| 15b-03 | Med-High | Repro | Command output, mid-turn notices and background/runtime notices go to the model as real turns. Partly fixed (`2a3a8f91c`: `Message::$uiOnly`, filtered at every wire encoder); remaining: compaction input unfiltered, notices still interleave between a prompt and its answer |
| 15b-13 | Low-Med | Reading | Token proxy chars/4 underestimates CJK 3-6×. Partly fixed (`8341a37c1`: script-weighted `TokenEstimate` for Chat's estimate, 85/95% tiers, status bar); remaining: `ContextCompactor` still chars/4 (70% reminder late for CJK), stale comments |
| 15b-34 | Low | Reading | Ctrl+A still types `/agents` into the box: an idle draft is wiped; the mid-turn refusal says the draft is still in the box (residual of 15b-05) |
| 15b-14 | Low | Reading | No i18n in sugar-crush |
| 15b-15 | Low | Reading | Attachments dormant and dropped on the wire |
| 15b-24 | Low | Reading | `/pane:x`, `/layout:x`, `/mcp:x` colon spellings not handled (documented) |
| 15b-25 | Low (docs) | Reading | Registry-derived command table shows `/rewind` *Takes* as `—` |
| 15b-30 | Low | Repro | candy-shine `stream()` ≠ `render()` when the text has link reference definitions (sugar-crush renders such partials whole) |
| 15b-31 | Low | Repro | candy-shine `SectionScanner::finish()` drops the closed section before a trailing heading; no boundary after a closing fence (residual of 15b-10) |
| 15b-32 | Info | Reading | Stale comments: launch notices "re-sent every turn" (Bootstrap, SessionStore); DetectsCapabilities cites Doctor's removed `??=` probe; candy-core Sanitize/View and ChatPane still describe the one-codepoint image marker (since wave 7) |
| 15b-33 | Info | Reading | `/fork` docblock (`Chat.php:13269`) still names `SessionStore::forkSession()` as the transcript copy (stale since SES-2) |

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
- **15b-02** After a double-Escape cancel, tool placeholders stayed "running" forever and later same-id results landed on the old row — fixed on master in `855e42673` (the cancel arm maps every pending row to the "interrupted" row `reviveCheckpointMessage()` builds, with an error tool result under the same id; `replaceToolRunningPlaceholder()` and `finishToolCalls()` search newest first, and each result claims only its own rows). Residual: the healed row reuses the `INTERRUPTED_TOOL_CALL` text ("…interrupted by restart") even after a user cancel. A per-placeholder generation stamp was deferred; it is moot given the heal and the existing generation guards.
- **15b-06** Switching session kept the compaction thrash counter — fixed on master in `b16819b13` (`switchToSession()` and palette New session share `sessionChangeResets()`; `lastActivityAt` goes to null).
- **15b-07** Raw CR reached the terminal from user/system rows, tool names and descriptions and expanded tool output — fixed on master in `74ae88c2a` (new candy-core `Sanitize::untrustedForDisplay()` maps CRLF and lone CR to LF; the Renderer's `untrusted()` wrapper uses it, and one-line rows go through a new `oneLine()` before truncation).
- **15b-08** UTF-8-encoded C1 controls passed every sanitizer — fixed on master in `af42238fc` (candy-core `Sanitize::untrusted()` and candy-shine `Renderer::stripControls()` remove `\xC2[\x80-\x9F]`). Lone raw C1 bytes in candy-shine (15b-29) and bidi and zero-width characters (15b-28) were fixed later, in wave 8A.
- **15b-11** Any prompt starting "mcp auth" was captured by the MCP command — fixed on master in `373e7d953` (both sites use `isBareMcpAuthCommand()`, `/^mcp\s+auth(?:\s|$)/`; `docs/COMMANDS.md` states the whole-word rule).
- **15b-19** The latent permission modal wrapped by bytes and kept CR — fixed on master in `e4fd37010` (new candy-core `Sanitize::visibleControls()` renders every control byte visibly in caret or `<U+…>` notation; CR maps to LF, zone sentinels are spelled out, and the text wraps by cells with `Width::wrap()`). Found while fixing it: `Width::wrap()` hangs at a 1-column budget (15b-26), and an invalid-UTF-8 argument is described as empty (15b-27).
- **15b-22** `/rewind help` (any non-numeric argument) performed a rewind, and `/name:arg` reached handlers with a literal `:` — fixed on master in `0d094ff25` (`/rewind` accepts only an empty or `ctype_digit` count ≥ 1; the raw-text handlers take `Chat::commandArgument()`, which drops one space or `:` separator). Residual: `/pane`, `/layout` and `/mcp` still split the whole draft on whitespace (documented in `docs/COMMANDS.md`; 15b-24), and the command table's `/rewind` *Takes* column still shows `—` (15b-25).
- **15b-23** Positional `$N` splitting: an apostrophe swallowed the rest of the line and `""` shifted the arguments — fixed on master in `0147c5f7a` + `1173b2ada` (a quote opens a span only at a token start, an unterminated quote stays literal, and an empty quoted span yields an empty token). Residual: `/model ""` now answers "Could not switch to provider ''" instead of opening the palette, because the user typed an explicit empty name.
- **15b-10** Every frame re-rendered the whole history through CandyShine — fixed on master in `05f86a2a9` (exact memos in `src/Renderer.php`: settled CandyShine bodies in an LRU per width and content hash, scoped to one theme object; incremental streaming through CandyShine's `SectionScanner`, re-rendering only the open tail; per-row SGR transitions in `balanceSgr()`; the line count of collapsed tool bodies; the tool-zone dedup is a keyed lookup and labels are styled once per frame; 529 frames of a differential corpus are byte-identical to the old Renderer). At 120×40: 50/200/800 exchanges 182/698/2949 → 15/52/211 ms per frame, 300 warm markdown exchanges 965 → 38 ms (target was under 50), a 200K streaming partial 2197 → 81 ms. Residual: `r13_stream_cost.php` as written (headings straight after a closing fence) is only partly faster, 207/2526 → 112/1120 ms at 20K/200K, because `SectionScanner` finds no boundary there (15b-31); the memo works around two candy-shine bugs (15b-30, 15b-31).
- **15b-12** Session titling fell back to the main, tool-armed backend — fixed on master in `37ff6d54f` (titling is skipped when `titleBackend` is null, the same gate prompt suggestions use; no other `?? backend` fallback exists).
- **15b-04** UserPromptSubmit and SessionStart hook chains ran synchronously inside `update()` — fixed on master in `f1b6862e9` (script turn hooks run off `update()` in a forked child, and the turn is dispatched from the resolved `TurnHooksResolvedMsg`).
- **15b-20** A custom command's `` !`…` `` ran synchronously inside `update()` for up to 10 s, and on timeout its grandchildren survived — fixed on master in `2826f5cf3` (the expansion runs off `update()` through a forked child, `forkedPayloadCmd()`, and resolves to `CustomCommandExpandedMsg`; on timeout `ProcessContainment::killTree()` plus a SIGKILL to the process group also catch `&` background jobs; gate checks made in the child are replayed into the session gate).
- **15b-21** `/branch` and any first save of a long history froze the TUI for seconds, one autocommitted INSERT per message — fixed on master in `698a1efff` (with 15e SES-2: save, checkpoint and restore each run in one `BEGIN IMMEDIATE` transaction, and a fork copies the blobs, so the first save on a `/branch` re-interns nothing). Measured at 800 messages: first save 6519 → 233 ms, first save on a branch 5996 → 17 ms; the fork itself takes about 470 ms (the fsync of the copied blobs). Residual: persistence still runs synchronously from `Chat::update()` (`persistTranscript()`); moving it to a debounced `Cmd` is not done.
- **15b-09** The chat status bar was never clipped to the terminal width, and the content width (with every overlay) was floored at 20 plus chrome — fixed on master in `66d0651ac` (the status-bar hint shortens step by step, keeping the "Ctrl+P menu" click zone longest; a `fitStatusBar()` backstop strips zone markers before it cuts, so a cut never splits one; the content-width floor is `max(1, cols-6)`, the image box and diff box floors drop to 1, and the slash popup is capped at the terminal width; at 6 columns or fewer `clipFrameToCols()` cuts the bordered shell, with every `Width::wrap` budget kept at 2 or more for 15b-26). The new width test exposed a second bug, fixed in the same commit: Veil counted zone markers as screen cells, so rows under an overlay were split at the wrong column and overflowed; zones are now lifted out before compositing and put back afterwards. Measured: `r3b` last row 54 → 38 cells at 40 columns and 54 → 29 at 30; `r3_width` at 25 columns 45 over-wide rows → 0; `r17_overlay_width` 21 over-wide cases → 0. Residual: `src/Commands/TranscriptTable.php` still copies the old `max(20, cols-6)` floor (nothing overflows, because the pane fitter wraps its output); the permission modal's inner width is still floored at 20, so below 26 columns it loses its right border (it does not overflow).
- **15b-18** The session tab strip was neither width-clipped nor sanitized — fixed on master in `01cae6d21` (each name goes through `Sanitize::untrustedForDisplay()` and `PaneLabel::safe()`, which removes escapes and control bytes, folds CR/LF/TAB to a space and drops Private-Use characters, and an empty name falls back to the cleaned id; names are capped at 20 cells with an ellipsis, the current tab is always shown, tabs that do not fit collapse into `… +N`, only visible tabs get click zones, and the strip stays one row). Measured at 80 columns: 8 long names 383 → 73 cells; a hostile name 402 → 69 cells with no OSC 52, `\e[2J` or CR; hosted App at 100 columns 433 → 100 cells.
- **15b-05** Menu-bar and shell commands erased the user's draft, then mid-turn refused with "Your draft is still in the box" — fixed on master in `ecca2b606` + `c1e836427` (new `Chat::runCommand()` and `Chat::runPaletteAction()` run a command without touching the draft, and `App::runRegistryCommand()` uses them instead of feeding synthetic Backspace, Delete and Enter keys). Still open nearby: Chat's own Ctrl+A arm still types `/agents` into the box (15b-34).
- **15b-17** Model or tool text containing U+E002+n painted a copy of on-screen image n and blanked Nerd Font glyphs — fixed on master in `b38bf8403` (`ImageOverlay::marker()` is a zero-width authenticating OSC, `ESC ] candy-image ; <id> ESC \`, plus the U+E002+id cell, and `resolve()` paints only that pair; untrusted text cannot carry the escape because every untrusted sink deletes ESC, and an escape whose cell a layout pass cut off is dropped; bare Private-Use codepoints are left alone, so Powerline and Nerd Font glyphs survive; `Program::renderFrame()` resolves every frame, so a marker on a frame with no image layer never reaches the terminal; `r10_forged_marker.php`: `paints=2` before, `paints=1` after, and U+E002, U+E0B0 and U+F115 survive in the line). Marker rows stay 1 cell wide (the OSC is zero-width to `Width`, `truncateAnsi`, `wrapAnsi` and candy-mouse `Scan`). Residual: stale marker prose in candy-core and ChatPane (15b-32).
- **15b-26** candy-core `Width::wrap()` never terminated when a 2-cell cluster met a 1-column budget — fixed on master in `758f098c1` (when `truncate()` fits nothing, the leading cluster is emitted alone on an over-wide row, as `wrapAnsi()` does, so every pass consumes at least one cluster; `WidthWrapOverWideClusterTest` runs each case under a SIGALRM deadline).
- **15b-28** Bidi overrides and zero-width characters passed every sanitizer — fixed on master in `531a0941f` + `dd4e4aa05` + `e3f6756ac` (new public `Sanitize::markInvisibleFormatting()` marks U+202A–202E, U+2066–2069, U+200B, U+2060 and U+FEFF always, and ZWNJ, ZWJ, LRM, RLM and ALM only at the start, after ASCII or after another such mark, so emoji ZWJ sequences, Persian and Indic ZWNJ and RTL marks still work; `untrustedForDisplay()` applies it and `visibleControls()` marks all of them; candy-shine `stripControls()` applies it too, covering assistant markdown and code blocks). `untrusted()` and `untrustedForMarkedFrames()` are unchanged for paste fidelity. Behaviour change: display policies now emit `<U+XXXX>` markers for these codepoints, a leading BOM included.
- **15b-29** candy-shine `stripControls()` kept lone raw 0x80–0x9F bytes — fixed on master in `dd4e4aa05` (lone C1 bytes outside well-formed UTF-8 are removed before the C0 sweep, so no `\xC2\x9B` pair can be spliced together). On master `render("a\x9B2Jb")` actually threw CommonMark's `UnexpectedEncodingException`; with sanitising on (the default), `render()` and `renderSection()` now also scrub before the parse, removing lone C1 and repairing other malformed UTF-8 to U+FFFD, and `stream() === render()` still holds.
- **15b-27** The permission modal showed an empty value for an argument that was not valid UTF-8 — fixed on master in `a0f07cd5a` (`Message::describeToolCall()` encodes with `JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE`, falls back to `visibleControls()` and never to `''`, and re-escapes C1 and bidi/zero-width characters so the label stays inert; a non-string `0` is no longer dropped by `?:`). The headless prompt got the same treatment in `e1acd6f0f` (15e lead 6, R17).
