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
