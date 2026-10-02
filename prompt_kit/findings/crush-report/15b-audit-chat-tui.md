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

## B. Terminal injection and frame geometry

### 15b-09 — The chat status bar is never clipped to the terminal width
- **Severity:** Medium (standalone `Chat` root) / Low (hosted App) · **Confidence:** Verified-by-repro (`r3b.php`, `r4_app.php`)
- **Where:** `Renderer::renderStatusBar()` at `src/Renderer.php:1657-1907`. `$processing` (54 cells idle: `Enter to send · Ctrl+P menu · /exit or ^C to quit`) is always appended. Only the optional segments are width-budgeted. Return is at `:1907` with no `Width::truncate`.
- **Repro:** `Chat::withSize(40,20)` gives a last row of width 54; `withSize(30,10)` also gives 54.
  - In the hosted App, `Tui\Renderer::clipWidth()` cuts it at the frame edge. Inside the chat pane it overruns the pane's inner width: `│ 0% · Enter to send · C │` at 50 columns, where the context figure was already squeezed to `0%`.
  - In-flight text (`⠴ thinking… · Esc Esc to cancel`) behaves the same way.
- **Second geometry defect:** `$contentWidth = max(20, cols - 6)` at `:1294` makes the shell 26 cells wide on terminals under 26 columns. `r3_width.php` gives width 26 > 25 for every row kind at 25 columns.
  - The overlays share this floor. `r17_overlay_width.php`, with the status bar excluded: the palette, `/keys` and Ctrl+R history are 26 cells at 16 and 24 columns, and the slash popup is 18 cells at 16 columns. Their own clipping is otherwise correct: no overlay overran at 32 or 48 columns, and no frame was taller than its rows.
- **Re-measured after the 15b-07 CR fix (`74ae88c2a`):** `r3_width.php` still reports every row kind at 25 columns as 26 cells (`width 26>25`), CR rows included. The CR fix removed the cursor motion, not this floor, so the second geometry defect is unchanged.
- **Fix:** `Width::truncate($bar, $cols)` (ANSI-aware) at `:1907`. Drop or shorten `$processing` segments in priority order. Clamp `contentWidth` to `max(1, cols-6)`.
- **Test:** For cols in {20, 30, 40}, assert every frame row satisfies `Width::string($row) <= $cols`.

### 15b-26 — candy-core `Width::wrap()` never terminates when a 2-cell cluster meets a 1-column budget
- **Severity:** Medium (a hang) · **Confidence:** Verified-by-repro (`timeout 5 php -r '… Width::wrap("文", 1) …'` is killed at the deadline)
- **Where:** `candy-core/src/Util/Width.php:295` (`wrap()`). Its hard-break loop cuts long tokens between grapheme clusters, but a cluster wider than the whole budget (a CJK character or a wide emoji at `$max = 1`) is never emitted, so the loop never advances.
- **Reachability:** sugar-crush's permission modal calls it as `Width::wrap($clean, max(2, $cols))` (`src/Renderer.php:4769`, written for 15b-19), and the modal's inner width is floored at 20, so sugar-crush does not reach it today. Other callers can: `candy-shell/src/Command/PagerCommand.php:56` passes `max(1, $width)`, and `sugar-table/src/Column.php:251` passes the column width unclamped. A 1-column pager or table column fed CJK text hangs the process.
- **Fix:** when the next cluster is wider than `$max`, emit it alone on its own row (an over-wide row is better than a hang), or replace it with a 1-cell placeholder. Either way, each pass of the loop must consume at least one cluster.
- **Test:** `Width::wrap("文", 1)`, `Width::wrap("a文b", 1)` and `Width::wrap("👍🏽", 1)` each return within the test's time budget and contain every input cluster.

### 15b-28 — Bidi overrides and zero-width characters pass every sanitizer as ordinary text
- **Severity:** Low · **Confidence:** Verified-by-reading
- **Where:** `candy-core/src/Util/Sanitize.php` (`untrusted()`, `untrustedForDisplay()`, `visibleControls()`) and candy-shine `Renderer::stripControls()`. None of them touches U+202A–U+202E (embeddings and overrides), U+2066–U+2069 (isolates), U+200B–U+200D, U+2060 or U+FEFF.
- **Failure scenario:** the 15b-07, 15b-08 and 15b-19 fixes close the cursor-motion and C1 routes, but a model-authored tool description, a tool result or a command shown in the permission modal can still carry U+202E. The terminal then displays the rest of the line reversed ("Trojan Source"), so text reads differently from what runs. Zero-width characters make two different names or paths look identical.
- **Fix:** in the display policies, map these codepoints to a visible marker (`<U+202E>`, as `visibleControls()` already does for C1), at least in the permission modal and tool rows. `untrusted()` itself can keep them for paste fidelity.
- **Test:** `visibleControls("rm \u{202E}txt.sh")` shows the marker, and a tool row rendered from the same text contains no raw U+202E.

### 15b-29 — candy-shine `Renderer::stripControls()` does not strip lone raw 0x80–0x9F bytes
- **Severity:** Low · **Confidence:** Verified-by-reading
- **Where:** `candy-shine/src/Renderer.php:785-792`. The pattern is `/[\x00-\x08\x0b-\x1f\x7f]|\xC2[\x80-\x9F]/`, which removes the UTF-8-encoded C1 codepoints (the 15b-08 fix, `af42238fc`) but not a lone 8-bit C1 byte such as a bare `\x9B`.
- **Failure scenario:** markdown text that carries a raw `\x9B` (8-bit CSI) passes CandyShine unchanged. Terminals that honour 8-bit C1 (for example xterm with 8-bit controls enabled) execute it. sugar-crush's own paths are covered, because candy-core `Sanitize::untrusted()` step 1 already removes every 0x80–0x9F byte outside a well-formed UTF-8 sequence before the text reaches CandyShine, so this matters for CandyShine's other consumers.
- **Fix:** remove 0x80–0x9F bytes that are not part of a well-formed UTF-8 sequence, the way `Sanitize::untrusted()` step 1 does. A plain byte-class strip would corrupt valid multi-byte characters, whose continuation bytes fall in that range.
- **Test:** CandyShine renders `"a\x9B2Jb"` without the `\x9B` byte, and still renders `→` and `👍` intact.

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
- **Detail:** since 15b-03's fix (`2a3a8f91c`), launch notices are `uiOnly` rows and never reach the model, so the token-cost half of those two rationales is no longer true (the transcript-clutter half still is). Since F-T6's fix (`977179c1e`), Doctor reads the boot-time `ToolResult::mosaic()` probe and no longer has a `??=` probe, so the docblock points readers at code that does not exist.
- **Fix:** reword the two rationales to the transcript-clutter reason, and point the `DetectsCapabilities` docblock at `ToolResult::mosaic()` (and the boot-time warm-up).
- **Test:** none needed beyond review.

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

### 15b-27 — The permission modal shows an empty value for a tool argument that is not valid UTF-8
- **Severity:** Low · **Confidence:** Verified-by-reading
- **Where:** `Message::describeToolCall()` at `src/Message.php:189-213`: `"{$key}: " . json_encode($rendered)` (and `json_encode($value) ?: ''` for non-string values), with no `JSON_INVALID_UTF8_SUBSTITUTE`.
- **Failure scenario:** a model-authored argument containing one invalid byte (for example a Latin-1 `caf\xe9` in a Bash command) makes `json_encode()` return `false`, which concatenates as an empty string. The call is described as `Bash(command: )`, so a permission prompt built from this description asks the user to approve a command it does not show. Latent while the modal is unreachable (known #1), like 15b-19 was.
- **Fix:** encode with `JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE`, and fall back to `Sanitize::visibleControls()` of the raw value rather than to `''`.
- **Test:** `describeToolCall()` of a call with `["command" => "caf\xe9"]` contains `caf` followed by U+FFFD.

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

---

## Summary table (sorted by severity)

| ID | Sev | Conf | Title |
|---|---|---|---|
| 15b-03 | Med-High | Repro | Command output, mid-turn notices and background/runtime notices go to the model as real turns. Partly fixed (`2a3a8f91c`: `Message::$uiOnly`, filtered at every wire encoder); remaining: compaction input unfiltered, notices still interleave between a prompt and its answer |
| 15b-04 | Medium | Reading | UserPromptSubmit/SessionStart hooks run synchronously inside update() (up to 60 s freeze) |
| 15b-05 | Medium | Repro | Menu and shell commands erase the draft, then claim "draft still in the box" |
| 15b-09 | Medium/Low | Repro | Status bar not clipped to cols; content width (and every overlay) floored at 20+chrome |
| 15b-20 | Medium | Repro | Custom-command `` !`…` `` blocks update() up to 10 s; timed-out grandchildren survive |
| 15b-21 | Medium | Repro | `/branch` re-interns every message with one autocommitted INSERT each: 4.3 s freeze at 800 messages |
| 15b-26 | Medium | Repro | candy-core `Width::wrap()` loops forever when a 2-cell cluster meets a 1-column budget (latent in sugar-crush; reachable via candy-shell pager, sugar-table) |
| 15b-13 | Low-Med | Reading | Token proxy chars/4 underestimates CJK 3-6×. Partly fixed (`8341a37c1`: script-weighted `TokenEstimate` for Chat's estimate, 85/95% tiers, status bar); remaining: `ContextCompactor` still chars/4 (70% reminder late for CJK), stale comments |
| 15b-17 | Low-Med | Repro | U+E002+n in model or tool text paints a copy of on-screen image n where the text chooses; Nerd Font glyphs blanked |
| 15b-18 | Low-Med | Repro | Session tab strip unclipped (383 cells at 80 cols, standalone root) and unsanitized |
| 15b-14 | Low | Reading | No i18n in sugar-crush |
| 15b-15 | Low | Reading | Attachments dormant and dropped on the wire |
| 15b-24 | Low | Reading | `/pane:x`, `/layout:x`, `/mcp:x` colon spellings not handled (documented) |
| 15b-25 | Low (docs) | Reading | Registry-derived command table shows `/rewind` *Takes* as `—` |
| 15b-27 | Low (latent) | Reading | `describeToolCall()` shows an empty value for an invalid-UTF-8 argument |
| 15b-28 | Low | Reading | Bidi overrides and zero-width characters pass every sanitizer |
| 15b-29 | Low | Reading | candy-shine `stripControls()` keeps lone raw 0x80–0x9F bytes |
| 15b-30 | Low | Repro | candy-shine `stream()` ≠ `render()` when the text has link reference definitions (sugar-crush renders such partials whole) |
| 15b-31 | Low | Repro | candy-shine `SectionScanner::finish()` drops the closed section before a trailing heading; no boundary after a closing fence (residual of 15b-10) |
| 15b-32 | Info | Reading | Stale comments: launch notices "re-sent every turn" (Bootstrap, SessionStore); DetectsCapabilities cites Doctor's removed `??=` probe |

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
- **15b-08** UTF-8-encoded C1 controls passed every sanitizer — fixed on master in `af42238fc` (candy-core `Sanitize::untrusted()` and candy-shine `Renderer::stripControls()` remove `\xC2[\x80-\x9F]`). Still open nearby: lone raw C1 bytes in candy-shine (15b-29), and bidi and zero-width characters (15b-28).
- **15b-11** Any prompt starting "mcp auth" was captured by the MCP command — fixed on master in `373e7d953` (both sites use `isBareMcpAuthCommand()`, `/^mcp\s+auth(?:\s|$)/`; `docs/COMMANDS.md` states the whole-word rule).
- **15b-19** The latent permission modal wrapped by bytes and kept CR — fixed on master in `e4fd37010` (new candy-core `Sanitize::visibleControls()` renders every control byte visibly in caret or `<U+…>` notation; CR maps to LF, zone sentinels are spelled out, and the text wraps by cells with `Width::wrap()`). Found while fixing it: `Width::wrap()` hangs at a 1-column budget (15b-26), and an invalid-UTF-8 argument is described as empty (15b-27).
- **15b-22** `/rewind help` (any non-numeric argument) performed a rewind, and `/name:arg` reached handlers with a literal `:` — fixed on master in `0d094ff25` (`/rewind` accepts only an empty or `ctype_digit` count ≥ 1; the raw-text handlers take `Chat::commandArgument()`, which drops one space or `:` separator). Residual: `/pane`, `/layout` and `/mcp` still split the whole draft on whitespace (documented in `docs/COMMANDS.md`; 15b-24), and the command table's `/rewind` *Takes* column still shows `—` (15b-25).
- **15b-23** Positional `$N` splitting: an apostrophe swallowed the rest of the line and `""` shifted the arguments — fixed on master in `0147c5f7a` + `1173b2ada` (a quote opens a span only at a token start, an unterminated quote stays literal, and an empty quoted span yields an empty token). Residual: `/model ""` now answers "Could not switch to provider ''" instead of opening the palette, because the user typed an explicit empty name.
- **15b-10** Every frame re-rendered the whole history through CandyShine — fixed on master in `05f86a2a9` (exact memos in `src/Renderer.php`: settled CandyShine bodies in an LRU per width and content hash, scoped to one theme object; incremental streaming through CandyShine's `SectionScanner`, re-rendering only the open tail; per-row SGR transitions in `balanceSgr()`; the line count of collapsed tool bodies; the tool-zone dedup is a keyed lookup and labels are styled once per frame; 529 frames of a differential corpus are byte-identical to the old Renderer). At 120×40: 50/200/800 exchanges 182/698/2949 → 15/52/211 ms per frame, 300 warm markdown exchanges 965 → 38 ms (target was under 50), a 200K streaming partial 2197 → 81 ms. Residual: `r13_stream_cost.php` as written (headings straight after a closing fence) is only partly faster, 207/2526 → 112/1120 ms at 20K/200K, because `SectionScanner` finds no boundary there (15b-31); the memo works around two candy-shine bugs (15b-30, 15b-31).
- **15b-12** Session titling fell back to the main, tool-armed backend — fixed on master in `37ff6d54f` (titling is skipped when `titleBackend` is null, the same gate prompt suggestions use; no other `?? backend` fallback exists).
