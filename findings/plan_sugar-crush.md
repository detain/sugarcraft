# Implementation Plan: sugar-crush

**Status:** not-started
**Phase:** 1
**Updated:** 2026-06-30

---

## Goal

Address all 13 findings from the sugar-crush audit. However, **the findings file describes a library that does not match the current codebase structure**. The findings reference `src/InputHandler.php`, `src/MouseHandler.php`, `src/Config.php`, and `src/PasteHandler.php` which do not exist. The actual `sugar-crush` is a chat-shell AI coding agent (port of `charmbracelet/crush`), not a bubbletea input handling library.

This plan maps each finding to actual code locations where the described functionality exists, should exist, or needs clarification.

---

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| sugar-crush is crush port, not bubbletea | The actual sugar-crush ports charmbracelet/crush (chat-shell), not bubbletea input handling | `sugar-crush/README.md:L16` |
| Input handling exists in candy-input | EscapeDecoder.php handles escape sequences including mouse/paste | `candy-input/src/EscapeDecoder.php` |
| InputReader in candy-core parses bytes | InputReader.php parses byte streams into typed Msg objects | `candy-core/src/InputReader.php` |
| Debounce/throttle in candy-async | AsyncOps.php provides debounce() and throttle() | `candy-async/src/AsyncOps.php:L159-204` |
| WindowSizeMsg exists in candy-core | Resize events are handled via WindowSizeMsg | `candy-core/src/Msg/WindowSizeMsg.php` |
| Findings describe expected but non-existent library | Files at specified paths do not exist in repository | Investigation 2026-06-30 |

---

## Phase 1: Investigation & Mapping [PENDING]

### 1.1 Finding Discrepancy Analysis

**Severity:** INFORMATIONAL
**Location:** N/A

**What is expected:**
The findings file describes a `sugar-crush` library that:
- Ports `charmbracelet/bubbletea` input handling
- Has `src/InputHandler.php` with EOF detection (HandleCyan/Ctrl+D)
- Has `src/MouseHandler.php:55` with mouse coordinate handling
- Has `src/Config.php` with throttle/debounce timer management
- Has `src/PasteHandler.php` with clipboard handling
- Has resize event handling in InputHandler

**Why this matters:**
- The actual `sugar-crush` in this repository is a chat-shell AI coding agent
- It ports `charmbracelet/crush`, NOT `charmbracelet/bubbletea`
- The files described in the findings do NOT exist at the specified paths
- Input handling for the actual sugar-crush is done through `Tui/KeyboardHandler.php`

**Conditions for resolution:**
- Determine if the findings are for a different/planned library
- Clarify whether bubbletea input handling is a planned port

**Related code locations:**
- `sugar-crush/src/Tui/KeyboardHandler.php` — actual keyboard handler for sugar-crush app
- `candy-input/src/EscapeDecoder.php` — escape sequence decoder (bubbletea-style input)
- `candy-core/src/InputReader.php` — byte stream parser into typed Msg

**Investigation notes:**
- The findings say "sugar-crush ports charmbracelet/bubbletea input handling"
- But `sugar-crush/README.md:L16` says it's a "port of `charmbracelet/crush`"
- `KeyboardHandler.php` handles Ctrl combinations (Ctrl+C, Ctrl+N, Ctrl+G, etc.) but not Ctrl+D EOF
- `candy-input` has the bubbletea input handling (EscapeDecoder, MouseEvent, PasteEvent)

---

## Phase 2: Keyboard/Input Handling [PENDING]

### 2.1 Finding 1 — EOF Detection Doesn't Consume Pending Input

**Severity:** HIGH
**Location (as stated):** `src/InputHandler.php` (DOES NOT EXIST)

**What is expected:**
When HandleCyan (Ctrl+D) is received, any buffered input ahead of Ctrl+D should be consumed before stopping. Pending keystrokes could be lost otherwise.

**Why the change should be done:**
If a user types "hello" then presses Ctrl+D, the "hello" might be lost if the handler immediately stops without draining the buffer.

**Conditions for success:**
- Ctrl+D with preceding buffered input: buffer is drained and processed first
- Only a bare Ctrl+D (no preceding input) triggers EOF/quit

**Actual code location:**
- `sugar-crush/src/Tui/KeyboardHandler.php:81-94` — handles Ctrl+key combinations
- `candy-core/src/InputReader.php` — parses byte stream (could drain buffer here)

**Investigation notes:**
- `KeyboardHandler.php` handles Ctrl+C (CancelCmd), Ctrl+G (GroupInputCmd), etc.
- Ctrl+D is NOT currently handled (line 81-94 shows match cases for ctrl+n, ctrl+c, ctrl+g, ctrl+k, ctrl+s, ctrl+a, ctrl+p, ctrl+,)
- EOF behavior would need to be added to handle Ctrl+D specifically
- The `stopPropagation()` mentioned in findings is not visible in current code

---

### 2.2 Finding 10 — Missing Resize Event Handler

**Severity:** MEDIUM
**Location (as stated):** `src/InputHandler.php` (DOES NOT EXIST)

**What is expected:**
Upstream bubbletea handles window resize events. These should be routed to appropriate handlers.

**Why the change should be done:**
Terminal resizes need to trigger layout recalculation and full frame repaint.

**Conditions for success:**
- Window resize event is detected and dispatched
- UI re-renders correctly at new dimensions

**Actual code location:**
- `candy-core/src/Msg/WindowSizeMsg.php` — exists and represents resize event
- `sugar-crush/src/Chat.php:53,212` — has resize detection and resets diff state
- `sugar-crush/src/Tui/Renderer.php:30-35` — `setSize()` is called on resize

**Investigation notes:**
- `WindowSizeMsg` exists in candy-core and is used by multiple libraries
- `Chat.php:212` shows resize detection logic
- `Renderer::setSize()` caches terminal size
- The actual sugar-crush app DOES handle resize via WindowSizeMsg

---

## Phase 3: Mouse Handling [PENDING]

### 3.1 Finding 2 — Mouse Event Coordinates Off-By-One at Viewport Edges

**Severity:** HIGH
**Location (as stated):** `src/MouseHandler.php:55` (DOES NOT EXIST)

**What is expected:**
Mouse coordinates are 0-indexed internally but terminal cells start at 1. The boundary check at `col < 1 || col > width` may reject valid edge clicks (col=1 or col=width).

**Why the change should be done:**
Users cannot click on the first column or last column of the terminal.

**Conditions for success:**
- Clicking at column 1 is accepted (not rejected)
- Clicking at column = width is accepted (not rejected)
- Coordinates are correctly converted to 0-indexed internally

**Actual code location:**
- `candy-input/src/EscapeDecoder.php:268-331` — handles SGR 1006 mouse decoding
- `candy-input/src/Event/MouseEvent.php:35-47` — MouseEvent is 1-based per docstring
- `candy-core/src/InputReader.php:569-617` — `decodeSgrMouse()` also handles mouse

**Investigation notes:**
- `candy-input/src/Event/MouseEvent.php:35-36` says:
  ```
  @param int          $x         Column (1-based, matches terminal coordinates)
  @param int          $y         Row (1-based)
  ```
- The docstring explicitly states 1-based, matching terminal coordinates
- Need to verify if the boundary check mentioned in findings exists in EscapeDecoder or InputReader
- Looking at `EscapeDecoder.php:268-331`, I don't see a boundary check like `col < 1 || col > width`

---

## Phase 4: Async/Timer Handling [PENDING]

### 4.1 Finding 3 — Debounce Timer Not Reset on Config Change

**Severity:** MEDIUM
**Location (as stated):** `src/Config.php` (DOES NOT EXIST)

**What is expected:**
When `throttle` or `debounce` settings change via `withThrottle()`/`withDebounce()`, the existing pending timer should be cancelled. Old timer could still fire with stale config.

**Why the change should be done:**
Changing debounce settings mid-operation could cause stale timers to fire with old configuration.

**Conditions for success:**
- Calling `withDebounce(100)` then `withDebounce(200)` cancels any pending 100ms timer
- New 200ms timer is started
- No stale timer fires with old configuration

**Actual code location:**
- `candy-async/src/AsyncOps.php:159-175` — `debounce()` creates timer closures
- `candy-forms/src/Field/Input.php:248-449` — async suggestions with debounce
- `candy-core/src/Program.php` — uses `Loop::addTimer()`

**Investigation notes:**
- `AsyncOps::debounce()` at line 159-175 creates a closure that captures `$timer` by reference
- When called again, it cancels the existing timer before scheduling a new one (line 168-170)
- This appears to already handle timer cancellation correctly
- However, the findings mention a `Config` class with `withThrottle()`/`withDebounce()` methods — no such class exists in sugar-crush

---

### 4.2 Finding 7 — Timer Resource Not Explicitly Closed

**Severity:** MEDIUM
**Location (as stated):** `src/Config.php` (DOES NOT EXIST)

**What is expected:**
`Loop::addTimer()` returns a `Timer` object. If the handler is destroyed mid-timer, the timer should be cancelled in `__destruct()`.

**Why the change should be done:**
Abandoned timer references could prevent garbage collection or fire after cleanup.

**Conditions for success:**
- Timer references are stored
- `__destruct()` calls `cancel()` on pending timers

**Actual code location:**
- `candy-async/src/AsyncOps.php` — debounce/throttle use timers
- `candy-core/src/Program.php:310` — mentions block until loop stops

**Investigation notes:**
- `AsyncOps::debounce()` stores timer in closure variable, not object property
- Closure-scoped timers are garbage collected when closure is garbage collected
- No explicit `__destruct()` with timer cancellation found in sugar-crush

---

## Phase 5: Paste Handling [PENDING]

### 5.1 Finding 4 — Paste Handler Assumes Clipboard Is Available

**Severity:** MEDIUM
**Location (as stated):** `src/PasteHandler.php` (DOES NOT EXIST)

**What is expected:**
`PasteHandler` calls `$clipboard->get()` synchronously. If clipboard is empty or unavailable, this could throw. Should wrap in try/catch and fall back to empty string.

**Why the change should be done:**
Paste could fail silently instead of crashing.

**Conditions for success:**
- Empty clipboard returns empty string, not exception
- Unavailable clipboard is handled gracefully
- Pasted content is still processed correctly

**Actual code location:**
- `candy-input/src/EscapeDecoder.php:542-591` — handles paste events
- `candy-input/src/Event/PasteEvent.php` — PasteEvent with MAX_SIZE truncation
- `candy-core/src/InputReader.php:60-76` — handles paste via PasteStartMsg/PasteEndMsg

**Investigation notes:**
- `EscapeDecoder.php` uses `PasteEvent::truncate()` to cap at 1 MiB
- `PasteEvent.php:26-33` does length check but no clipboard access
- The clipboard access mentioned in findings ($clipboard->get()) is not visible in these files
- Clipboard operations may be in a separate handler not found in current investigation

---

### 5.2 Finding 11 — No Paste Start/End Event Support

**Severity:** LOW
**Location (as stated):** `src/PasteHandler.php` (DOES NOT EXIST)

**What is expected:**
Only `PasteMsg` handled. No `PasteStartMsg`/`PasteEndMsg`.

**Why the change should be done:**
Bracketed paste mode sends start/end markers that should be handled separately.

**Conditions for success:**
- `PasteStartMsg` is dispatched when paste begins
- `PasteEndMsg` is dispatched when paste ends
- `PasteMsg` contains the pasted content

**Actual code location:**
- `candy-core/src/Msg/PasteStartMsg.php` — EXISTS
- `candy-core/src/Msg/PasteEndMsg.php` — EXISTS
- `candy-core/src/Msg/PasteMsg.php` — EXISTS
- `candy-core/src/InputReader.php:104-109` — emits PasteStartMsg
- `candy-input/src/EscapeDecoder.php:31-45, 542-591` — handles paste buffering

**Investigation notes:**
- All three message types exist in candy-core
- `InputReader.php:104-109` shows PasteStartMsg being emitted when CSI 200~ is seen
- `EscapeDecoder.php` has `PASTE_START = "\x1b[200~"` and `PASTE_END = "\x1b[201~"` constants
- Paste start/end IS handled in the actual codebase

---

## Phase 6: Performance [PENDING]

### 6.1 Finding 5 — Event Handler Chain O(n) Lookup Per Event

**Severity:** LOW
**Location (as stated):** `src/InputHandler.php` (DOES NOT EXIST)

**What is expected:**
Handlers stored in array, linear scan to find matching handler. For small N (<10 handlers), this is fine.

**Why this is noted:**
For large numbers of handlers, this could become a bottleneck.

**Conditions for success:**
- If handler count stays small (<10), no change needed
- If scale is a concern, consider hash-based lookup

**Actual code location:**
- `sugar-crush/src/Tui/KeyboardHandler.php` — handler dispatch via match statement

**Investigation notes:**
- `KeyboardHandler.php:83` uses `match ($key)` for Ctrl key dispatch — O(1)
- No linear array scan found in current implementation
- The findings may describe a different structure than what's in the actual codebase

---

## Summary Table

| Finding | Severity | Status | Actual Location |
|---------|----------|--------|-----------------|
| 1. EOF detection | HIGH | NEEDS CLARIFICATION | `Tui/KeyboardHandler.php` (no Ctrl+D handler) |
| 2. Mouse coords off-by-one | HIGH | VERIFY | `candy-input/src/EscapeDecoder.php` (1-based confirmed) |
| 3. Debounce timer not reset | MEDIUM | APPEARS FIXED | `candy-async/src/AsyncOps.php:L168-170` cancels timer |
| 4. Paste clipboard throws | MEDIUM | CANNOT VERIFY | No clipboard access in current files |
| 5. Event lookup O(n) | LOW | NOT AN ISSUE | match statement is O(1) |
| 6. N+1 issues | N/A | N/A | None detected |
| 7. Timer not closed | MEDIUM | ACCEPTABLE | Closure-scoped, GC handles |
| 8. Security | N/A | N/A | No concerns |
| 9. Complexity | N/A | N/A | Appropriate |
| 10. Resize handler | MEDIUM | EXISTS | `WindowSizeMsg` exists, used in Chat.php |
| 11. Paste start/end | LOW | EXISTS | PasteStartMsg/PasteEndMsg exist |
| 12. PHP 8.3 compat | N/A | N/A | Fully compatible |
| 13. Async design | N/A | N/A | Appropriate |

---

## Critical Discrepancy

**The findings file describes a `sugar-crush` library that ports `charmbracelet/bubbletea` input handling with files that do not exist at the specified paths.**

The actual `sugar-crush` in this repository is a chat-shell AI coding agent that ports `charmbracelet/crush` (a different project). It does have input handling via `Tui/KeyboardHandler.php`, but the files and functionality described in the findings (InputHandler.php, MouseHandler.php, Config.php, PasteHandler.php with HandleCyan/debounce timers/clipboard access) do not exist.

**Recommended actions:**
1. Verify if the findings are for a different/planned library (e.g., a future bubbletea input port)
2. If implementing bubbletea input handling, it should likely live in `candy-input` (which already has EscapeDecoder, MouseEvent, PasteEvent, ResizeEvent)
3. If modifying the existing sugar-crush app, the issues should be mapped to actual files like `Tui/KeyboardHandler.php`

---

## Verification

To verify the actual sugar-crush codebase:

```bash
cd sugar-crush && composer install && vendor/bin/phpunit
```

Tests should pass for the actual application. The findings describe functionality that doesn't exist in the current structure.

---

## Notes

- 2026-06-30: Initial investigation revealed the findings file describes files that do not exist. The actual `sugar-crush` app uses different structure (Tui/KeyboardHandler.php, not InputHandler.php). The bubbletea input handling functionality described IS present in `candy-input` library, but under different class names (EscapeDecoder, not InputHandler/MouseHandler).
