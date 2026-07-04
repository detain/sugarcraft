# SugarReadline — Caliber Learnings

## Accumulated patterns and gotchas

> Fill in as lessons are learned during implementation and testing.

## Key learnings

### 2026-05-30 — InputDriver is injectable for tests; production defaults to STDIN
Pattern: Accept an `InputDriver` in the constructor, defaulting to `StreamInputDriver::fromStdin()`. This makes the readline loop testable by injecting a driver over a fixture stream.
Anti-pattern: Reaching for `STDIN` directly in the run loop — hard to test, couples to the global TTY.
Source: step-21 ai/sugar-readline-input

### 2026-05-31 — Vim keybindings via shared candy-forms VimKeyHandler
Anti-pattern: Do NOT add new vim keybindings to per-lib branching logic (ViMode or TextInput vimMode flags). Always add new bindings to `VimAction` enum + `VimKeyHandler` so candy-forms, sugar-prompt, sugar-bits, and sugar-readline all benefit at once.
Source: step-24 ai/vim-mode-shared

### 2026-06-29 — Emacs word-delete/transpose O(n) replay deferred
Step 5 fixed emacs word operations to use handleKeyDirect instead of handleKey, eliminating infinite re-entry recursion. The O(n) replay (per-keystroke clone) for deleteWordBefore/deleteWordAfter/transposeChars was consciously deferred: adding a public TextPrompt::withBuffer() mutator would widen the API surface and risk breaking the immutability contract. The replay is O(word-length) per op, not per-keystroke-of-session, so the cost is bounded and acceptable until a dedicated perf pass can benchmark the impact.
Source: step-6 sugar-readline-fix (Wave 4 remediation)

### 2026-07-01 — Phase 6 implementation: atomic history writes, maxHistory, permissions
Items 2.1, 2.3, 2.4 implemented:
- FileHistory now uses atomic write via temp file + rename (crash-safe)
- InMemoryHistory enforces maxHistory limit with eviction of oldest entries
- History files are chmod 0600 (owner read/write only) to protect sensitive commands
- InMemoryHistory constructor accepts maxHistory param; FileHistory forwards it
Source: ai/sugar-readline-phase6

## Deferred Items (complex features)

### ~~Ctrl+R reverse history search (Finding 1)~~ — DONE 2026-07-04
Implemented: `HistoryInterface::search()` (read-only, does not disturb navigation position) + a search state machine in TextPrompt (`startHistorySearch()`, intercepts keys in `handleKey()` BEFORE mode delegation so vi/emacs bindings can't hijack the search). Enter accepts the match without submitting; Escape/Ctrl+G cancels back to the pre-search line.

### ~~Vi cursor off-by-one at line end (Finding 2)~~ — DONE 2026-07-04
`$` and Escape→normal now clamp via `ViMode::clampToLastChar()` (one `Key::Left` when cursor >= length on non-empty buffer). No TextPrompt change needed.

### ~~Emacs incremental search (Finding 12)~~ — DONE 2026-07-04
EmacsMode Ctrl+R/Ctrl+S start the shared TextPrompt search machine in reverse/forward direction; no search state lives in the mode itself.

## Future work (deferred pre-1.0)

### Vi text objects (Finding 13)
Text objects like `ci"` / `da{` / `yi(` are deliberately deferred pre-1.0: they require new VimAction enum cases + VimKeyHandler support in candy-forms FIRST, per CALIBER_LEARNINGS 2026-05-31: "Do NOT add new vim keybindings to per-lib branching logic. Always add new bindings to VimAction enum + VimKeyHandler." Once candy-forms grows Inner/Around text-object actions, ViMode only needs to consume them via `consumeAction()`.

### Integration with Terminfo (Finding 16)
Using terminfo for terminal capability queries would require FFI bindings to the curses terminfo database. This is a significant integration effort.

### Integration with Ncurses (Finding 16)
Using ncurses for lower-level terminal control would similarly require FFI bindings and significant architectural changes to the Readline loop.
