# Implementation Plan: sugar-readline

**Status:** not-started  
**Phase:** 1  
**Updated:** 2026-06-30

---

## Goal

Address all 16 findings from the sugar-readline audit covering bugs, edge cases, performance issues, memory leaks, security concerns, missing features, and async improvements.

---

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Add history search state machine to TextPrompt | Ctrl+R history search is standard readline feature not currently implemented | Finding 1 |
| Fix Vi mode `$` cursor to land ON last char, not past it | Vi muscle memory: cursor should be on last char in normal mode | Finding 2 |
| Tab completion already handles empty prefix via suggestion() guard | Verified: TextPrompt.php:411-413 returns null for empty buffer | Finding 3 |
| Use atomic file write (temp + rename) for history persistence | Prevents data loss on crash; flock alone doesn't guarantee atomicity | Finding 4 |
| History search O(n) acceptable for now; defer Trie indexing | 10k entries × O(n) per keystroke is bounded; can optimize later | Finding 5 |
| Add maxHistory enforcement to InMemoryHistory::push() | Prevent unbounded memory growth in long sessions | Finding 7 |
| Explicitly chmod history file to 0600 | Default 0644 allows group read; sensitive commands may be in history | Finding 9 |
| Incremental search is low-priority missing feature | Standard Emacs feature but complex to implement correctly | Finding 12 |
| Vi text objects require significant VimKeyHandler extension | Must go through candy-forms VimKeyHandler per CALIBER_LEARNINGS | Finding 13 |
| Verify bracketed paste mode is wired through candy-input | Readline.php has onPaste handler; needs end-to-end verification | Finding 14 |
| Defer sync history save to background tick | Blocking I/O on every Enter could cause input lag; needs ReactPHP integration | Finding 16 |

---

## Phase 1: Critical Bugs & Edge Cases [PENDING]

### 1.1 Finding 1 — History Search (Ctrl+R) Empty History Crash

**Severity:** MEDIUM  
**Location:** `src/TextPrompt.php`

**What is expected:**
- When history is empty, pressing Ctrl+R should either show a "no history" message and exit search mode, or beep/visual feedback and stay in search mode waiting for input
- The current implementation doesn't have a Ctrl+R handler at all — history navigation only works via Up/Down arrows

**Why the change should be done:**
- User experience: pressing Ctrl+R with no history enters an undefined state
- Could cause undefined behavior or infinite loops if `currentIndex` goes negative

**Conditions for success:**
- `Ctrl+R` with empty history shows visual feedback (e.g., "[no history]") and stays in search mode waiting for input
- `Ctrl+R` with populated history enters incremental search mode
- `Ctrl+G` or `Escape` cancels search mode
- `Enter` accepts current match and exits search
- `Up/Down` navigate through matches

**Related code locations:**
- `src/TextPrompt.php:334-389` — `navigateHistory()` handles Up/Down only
- `src/TextPrompt.php:212-258` — `handleKey()` dispatches keys to mode or direct handler
- `src/Readline.php:337-380` — `symbolicKey()` maps Ctrl+R to 'ctrl_r'
- `src/Key.php` — symbolic key constants (no Ctrl+R defined)
- `src/History/HistoryInterface.php` — no search method exists

**Investigation notes:**
- TextPrompt uses `navigateHistory()` for Up/Down, but there is NO history search (Ctrl+R) implemented
- The `History\InMemoryHistory` and `History\FileHistory` classes only support sequential navigation via `getPrevious()`/`getNext()`
- No `search()` method exists in `HistoryInterface`

---

### 1.2 Finding 2 — Vi Mode Cursor Off-By-One at Line End

**Severity:** MEDIUM  
**Location:** `src/Mode/ViMode.php:178-180`

**What is expected:**
- Type "abc", press Escape to enter normal mode, cursor should be positioned ON the 'c' (position 2, 0-indexed), NOT after 'c' (position 3)
- In vi normal mode, `$` moves cursor to the last character (not past it)

**Why the change should be done:**
- Vi muscle memory: users expect cursor to be on the last character when they press `$`
- Currently cursor is positioned one past the last character

**Conditions for success:**
- Type "abc" (3 chars), enter normal mode, verify cursor is at position 2 (on 'c'), not 3
- Type "a" (1 char), enter normal mode, verify cursor is at position 0 (on 'a'), not 1
- Type "" (empty), enter normal mode, verify cursor stays at 0

**Related code locations:**
- `src/Mode/ViMode.php:178-180` — `$` keybinding calls `prompt->handleKeyDirect(Key::End)` which moves to position after last char
- `src/TextPrompt.php:493-501` — `moveCursorTo()` clamps to `self::charCount($this->buffer)` which is position AFTER the last char
- `src/TextPrompt.php:432-459` — view() rendering: `before` = chars 0..cursor-1, `under` = char at cursor

**Investigation notes:**
- `Key::End` moves cursor to `self::charCount($this->buffer)` which equals position 3 for "abc" (chars at 0,1,2)
- In `view()`, cursor at position 3 means `under` = '' and `after` = ''
- For vi `$` behavior, cursor should go to position `length - 1` when buffer is non-empty
- This affects both `$` keybinding (line 178-180) and potentially auto-enter-normal-mode on Escape when already at end

---

### 1.3 Finding 3 — Tab Completion Empty Prefix

**Severity:** LOW  
**Location:** `src/TextPrompt.php:409-420`

**What is expected:**
- When buffer is empty and Tab is pressed, no completions should be shown
- Should return empty array/no completion rather than flooding with all completions

**Why the change should be done:**
- User experience: pressing Tab with no input shows all completions (visual flood)
- Should either show nothing or show a "no input" indicator

**Conditions for success:**
- Set completions ['banana', 'mango', 'apple'], press Tab with empty buffer, verify no completion shown
- Same completions, type 'b' then Tab, verify 'banana' completion works

**Related code locations:**
- `src/TextPrompt.php:609-620` — `applyCompletion()` calls `suggestion()`
- `src/TextPrompt.php:409-420` — `suggestion()` already checks `$this->buffer === ''` and returns null
- `src/TextPrompt.php:250` — Tab key mapped to `applyCompletion()`

**Investigation notes:**
- `suggestion()` at line 411-413 already returns null if buffer is empty
- `applyCompletion()` at line 612 checks if hint is null or equals buffer, returns early
- **This finding MAY already be fixed; verification needed**

---

## Phase 2: History & File Issues [PENDING]

### 2.1 Finding 4 — History Save Race Condition

**Severity:** MEDIUM  
**Location:** `src/History/FileHistory.php:48-55, 145`

**What is expected:**
- History writes should be atomic: write to temp file, then rename
- If process crashes during write, the original history file is not corrupted
- Multiple concurrent instances don't corrupt the file

**Why the change should be done:**
- Currently `fwrite()` with `flock(LOCK_EX)` handles concurrent access but doesn't guarantee atomicity on crash
- The `clear()` method at line 145 uses bare `file_put_contents()` with no locking at all

**Conditions for success:**
- While writing history, simulate crash (SIGKILL), verify original history file is intact
- Two processes writing same history file — no corruption, all entries present

**Related code locations:**
- `src/History/FileHistory.php:48-55` — current write uses `fopen()` + `flock(LOCK_EX)` + `fwrite()` + `fclose()`
- `src/History/FileHistory.php:145` — `clear()` uses `file_put_contents()` with no locking

**Investigation notes:**
- Current implementation uses `flock(LOCK_EX)` which provides exclusive locking during write
- BUT: crash during `fwrite()` could leave partial data in file
- Solution: write to temp file (`history.tmp.<pid>`), `fflush()`, `rename()` to atomically replace
- `clear()` at line 145 uses bare `file_put_contents($this->filePath, '')` — no locking at all

---

### 2.2 Finding 5 — History Search O(n)

**Severity:** LOW  
**Location:** `src/History/InMemoryHistory.php`

**What is expected:**
- For 10,000+ history entries, search should not lag
- Consider prefix indexing or Trie structure

**Why the change should be done:**
- Linear scan on every keystroke could cause input lag with large history
- However, implementation of Ctrl+R history search itself is also needed (Finding 1)

**Conditions for success:**
- With 10,000 history entries, search keystroke responds in <50ms

**Related code locations:**
- `src/History/InMemoryHistory.php` — entries stored as flat array, newest-first
- `src/History/HistoryInterface.php` — no search interface exists

**Investigation notes:**
- No history search (Ctrl+R) exists yet (Finding 1)
- When implementing search, consider:
  - Reverse array iteration (newest first) for recent-first search
  - Trie for prefix-based autocomplete
  - Lazy indexing (build on first search, maintain incrementally)

---

### 2.3 Finding 7 — History Array Grows Unboundedly

**Severity:** MEDIUM  
**Location:** `src/History/InMemoryHistory.php:27-39`

**What is expected:**
- `InMemoryHistory::push()` should enforce a `maxHistory` limit
- When limit is reached, oldest entries should be removed

**Why the change should be done:**
- Long-running sessions could consume unbounded memory
- No `maxHistory` property exists currently

**Conditions for success:**
- Set maxHistory to 100, push 200 entries, verify only last 100 are present
- Verify navigation (getPrevious/getNext) still works correctly after truncation

**Related code locations:**
- `src/History/InMemoryHistory.php:27-39` — `push()` has no bounds checking
- `src/History/HistoryInterface.php` — no maxHistory parameter in interface
- `src/History/FileHistory.php` — extends InMemoryHistory, no maxHistory

**Investigation notes:**
- `InMemoryHistory::push()` unconditionally prepends to `$this->history`
- Would need to add `maxHistory` property and trim array after push
- Consider adding `withMaxHistory(int $limit)` builder method

---

### 2.4 Finding 9 — History File Permissions 0644

**Severity:** LOW  
**Location:** `src/History/FileHistory.php:21-29, 145`

**What is expected:**
- History files should use 0600 permissions (owner read/write only)
- Sensitive commands may be in history

**Why the change should be done:**
- Default 0644 allows group read
- Could leak sensitive commands to other users on shared systems

**Conditions for success:**
- New history file created with 0600 permissions
- Existing history file after clear() has 0600

**Related code locations:**
- `src/History/FileHistory.php:21-29` — constructor uses `touch()` without setting permissions
- `src/History/FileHistory.php:145` — `clear()` uses `file_put_contents()` without explicit chmod

**Investigation notes:**
- `touch()` at line 26 creates file with default umask permissions
- Should add `chmod(0600)` after `touch()` in constructor
- After `file_put_contents()` in `clear()`, should also `chmod(0600)`

---

## Phase 3: Missing Features [PENDING]

### 3.1 Finding 12 — No Incremental Search (Emacs Mode)

**Severity:** LOW  
**Location:** `src/Mode/EmacsMode.php`

**What is expected:**
- Ctrl+S should start incremental search forward through history
- Ctrl+R should search backward (may reuse history search from Finding 1)
- As user types, matching history entry is shown
- Enter accepts match, Ctrl+G/Escape cancels

**Why the change should be done:**
- Standard readline feature expected by Emacs users
- Ctrl+S is common for forward history search

**Conditions for success:**
- Press Ctrl+S, type "git", matching history entries containing "git" are shown
- Up/Down navigate matches
- Enter accepts current match
- Ctrl+G cancels and restores original buffer

**Related code locations:**
- `src/Mode/EmacsMode.php` — no Ctrl+S handling
- `src/Readline.php:337-380` — `symbolicKey()` maps Ctrl+S to 'ctrl_s'
- `src/TextPrompt.php` — no search mode state

**Investigation notes:**
- `symbolicKey()` at line 342-357 handles Ctrl modifier but Ctrl+S specifically would be 'ctrl_s'
- Would need to add state to track search mode and search query
- Could reuse infrastructure from Finding 1 (history search state machine)

---

### 3.2 Finding 13 — No Vi Text Objects

**Severity:** MEDIUM  
**Location:** `src/Mode/ViMode.php`

**What is expected:**
- Text objects like `ci"` (change inside quotes), `da{` (delete around braces), `yi(` (yank inside parens)
- These are complex motions used in vi editing

**Why the change should be done:**
- Power users expect vi text objects in vi mode
- Missing feature compared to full readline functionality

**Conditions for success:**
- `ci"` — change text inside quotes when cursor is inside quotes
- `da{` — delete around braces when cursor is inside/adjacent to braces
- `yi(` — yank inside parentheses

**Related code locations:**
- `src/Mode/ViMode.php` — handles vi keybindings via VimKeyHandler
- `candy-forms/src/Vim/VimKeyHandler.php` — maps keys to VimAction
- `candy-forms/src/Vim/VimAction.php` — available vim actions

**Investigation notes:**
- Per CALIBER_LEARNINGS (2026-05-31): "Do NOT add new vim keybindings to per-lib branching logic. Always add new bindings to VimAction enum + VimKeyHandler"
- This means changes should go through candy-forms, not sugar-readline directly
- VimAction enum would need new actions for text objects (InnerTextObject, AroundTextObject, etc.)

---

### 3.3 Finding 14 — No Bracketed Paste Mode

**Severity:** LOW  
**Location:** `src/Readline.php:185-205`, `candy-input/`

**What is expected:**
- Modern terminals send bracketed paste (ESC[200~...ESC[201~)
- sugar-readline should detect and handle this for clean paste handling

**Why the change should be done:**
- Bracketed paste mode prevents terminal from interpreting paste contents
- Without it, tabs/newlines in pasted text may be mishandled

**Conditions for success:**
- Paste multi-line text, all lines are inserted correctly
- No Tab key expansions occur during paste
- Very long pastes (1000+ chars) work without hanging

**Related code locations:**
- `src/Readline.php:185-205` — `PasteEvent` handling exists
- `candy-input/src/` — input driver that decodes bracketed paste sequences

**Investigation notes:**
- `Readline::onPaste()` at line 118-123 registers paste handler
- `PasteEvent` is dispatched at line 185 when `event instanceof PasteEvent`
- Pasted text is fed char-by-char via `handleChar()` in loop (line 192-201)
- bracketed paste mode may need to be enabled via terminal query sequence
- candy-input driver may need to send ESC[?2004h to enable

---

## Phase 4: Async/Performance Improvements [PENDING]

### 4.1 Finding 16 — Synchronous History Save

**Severity:** MEDIUM  
**Location:** `src/TextPrompt.php:260-277`, `src/History/FileHistory.php:35-58`

**What is expected:**
- History save should not block the main input loop
- Could be deferred to background tick or use async I/O

**Why the change should be done:**
- Blocking file I/O on every Enter could cause visible input lag
- ReactPHP event loop is available in the monorepo

**Conditions for success:**
- Type "hello" + Enter, measure time to return to prompt — should be <10ms
- With 10,000 history entries, Enter still responds quickly

**Related code locations:**
- `src/TextPrompt.php:260-277` — `submit()` calls `$clone->historyOriginal->push($clone->buffer)`
- `src/History/FileHistory.php:35-58` — `push()` does synchronous file write

**Investigation notes:**
- `submit()` at line 272 calls `historyOriginal->push()` synchronously before setting `submitted=true`
- `FileHistory::push()` at line 48-55 opens file, locks, writes, unlocks, closes — all blocking
- Could defer to a background queue that flushes periodically
- Would need ReactPHP integration or at minimum `defer()` to yield to event loop

---

## Phase 5: Documentation [PENDING]

### 5.1 Finding 11 — Dual Keybinding Engine Complexity

**Severity:** LOW  
**Location:** `src/Mode/ViMode.php`, `src/Mode/EmacsMode.php`, `src/Mode/ModeInterface.php`

**What is expected:**
- Documentation explaining the dual engine architecture
- Clear separation of concerns between ViMode and EmacsMode

**Why the change should be done:**
- Helps future contributors understand the codebase
- Debugging requires understanding both state machines

**Conditions for success:**
- README or inline docs clearly explain when to use ViMode vs EmacsMode
- Each mode's entry points are documented

**Investigation notes:**
- This is a LOW severity informational finding
- No code changes required, only documentation
- Could add docblocks explaining the two-mode architecture

---

## Summary Table

| Finding | Severity | Status | Implementation Note |
|---------|----------|--------|---------------------|
| 1. History search (Ctrl+R) empty history | MEDIUM | PENDING | New state machine in TextPrompt |
| 2. Vi cursor off-by-one at line end | MEDIUM | PENDING | Fix $ positioning in ViMode |
| 3. Tab completion empty prefix | LOW | VERIFY | May already be fixed (suggestion() guard) |
| 4. History save race condition | MEDIUM | PENDING | Atomic write (temp+rename) in FileHistory |
| 5. History search O(n) | LOW | DEFERRED | Document as known issue; Trie later if needed |
| 6. N+1 issues | N/A | N/A | No issues detected |
| 7. History unbounded growth | MEDIUM | PENDING | Add maxHistory to InMemoryHistory |
| 8. Edit buffer memory leaks | N/A | N/A | No memory leaks detected |
| 9. History file permissions | LOW | PENDING | chmod 0600 in FileHistory |
| 10. Security beyond perms | N/A | N/A | No concerns beyond permissions |
| 11. Dual engine complexity | LOW | DOCS | Add architecture documentation |
| 12. No incremental search (Emacs) | LOW | PENDING | Add Ctrl+S to EmacsMode |
| 13. No Vi text objects | MEDIUM | PENDING | Extend VimAction in candy-forms |
| 14. No bracketed paste | LOW | VERIFY | Verify candy-input integration |
| 15. PHP 8.3 compatibility | N/A | N/A | Fully compatible |
| 16. Sync history save | MEDIUM | PENDING | Defer to background tick |

---

## Verification

After implementation, run:

```bash
cd sugar-readline && composer install && vendor/bin/phpunit
```

All existing tests should pass, and new tests should cover:
- History search with empty/non-empty history
- Vi mode cursor positioning at line end
- Tab completion edge cases
- History file atomic write
- maxHistory enforcement
- File permissions (0600)
- Ctrl+S incremental search (if implemented)
- Vi text objects (if implemented)
- Bracketed paste (if implemented)
- Async history save performance

---

## Notes

- 2026-06-30: The findings file (`findings/sugar-readline.md`) references `src/History.php`, `src/ViEngine.php`, `src/Completion.php`, `src/InputHandler.php` — these files don't exist in the current codebase structure. The current structure uses:
  - `src/History/FileHistory.php` (not `src/History.php`)
  - `src/Mode/ViMode.php` (not `src/ViEngine.php`)
  - Tab completion is in `src/TextPrompt.php` (not `src/Completion.php`)
  - Input handling is in `src/Readline.php` (not `src/InputHandler.php`)
  
  Some findings may be based on an older version of the codebase or have incorrect paths. Verification against actual code is recommended before implementation.
