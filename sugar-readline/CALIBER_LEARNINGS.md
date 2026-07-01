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

### Ctrl+R reverse history search (Finding 1)
Requires a new state machine in TextPrompt to track search mode, query, and current match. The history stores (InMemoryHistory, FileHistory) only support sequential navigation via getPrevious()/getNext(); a search() method would need to be added to HistoryInterface. This is a significant feature requiring design work.

### Vi cursor off-by-one at line end (Finding 2)
Vi mode's `$` key currently moves cursor to position = length (past last char). For proper vi behavior, `$` should move to length-1 (on last char). This requires changes to ViMode keybindings and potentially the moveCursorTo() logic in TextPrompt.

### Emacs incremental search (Finding 12)
Ctrl+S forward search and Ctrl+R backward search with incremental matching is a complex state machine. Would need to extend EmacsMode with search state similar to what Finding 1 requires for TextPrompt.

### Vi text objects (Finding 13)
Text objects like ci" (change inside quotes) require VimAction enum additions in candy-forms per CALIBER_LEARNINGS 2026-05-31: "Do NOT add new vim keybindings to per-lib branching logic. Always add new bindings to VimAction enum + VimKeyHandler."

### Integration with Terminfo (Finding 16)
Using terminfo for terminal capability queries would require FFI bindings to the curses terminfo database. This is a significant integration effort.

### Integration with Ncurses (Finding 16)
Using ncurses for lower-level terminal control would similarly require FFI bindings and significant architectural changes to the Readline loop.
