# Implementation Plan: candy-testing Library Fixes

**Status:** Not Started  
**Phase:** 1  
**Updated:** 2026-06-30

---

## Goal

Fix all critical bugs, high-severity issues, medium/low-severity improvements, missing features, and refactoring opportunities identified in the candy-testing code review.

---

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Add `getModel()` method to `Program` class | Eliminates Reflection-based encapsulation violation in `ProgramSimulator` | `ref:candy-testing-review` |
| VHS requires proper theme name escaping | Theme names with special characters break VHS syntax | `ref:candy-testing-review` |
| KeyType enum has 120+ cases but only 7 mapped | TUI apps use F-keys, Home/End, Delete, media keys | `ref:candy-testing-review` |
| `?? $bytes` null coalescing is confusing but safe | `preg_replace_callback` returns `null` on error | `ref:candy-testing-review` |
| `array_shift()` O(n) is negligible for <1000 messages | Performance concern is theoretical | `ref:candy-testing-review` |
| Extract `TemporaryDirectory` trait to eliminate duplication | Same cleanup code in 4 test files | `ref:candy-testing-review` |

---

## Phase 1: Critical Bug Fixes [PENDING]

- [ ] **1.1 TapeRecorder Dracula theme** — Fix `tests/TapeRecorderTest.php:71` to use `'Dracula'` without leading space. The test currently passes `' Dracula'` (with leading space) which suggests either a test data bug or escaping issue in `header()` method. Investigate root cause and fix accordingly.
  
  **Expected:** Theme string `'Dracula'` produces `Set Theme "Dracula"` in VHS output  
  **File:** `candy-testing/tests/TapeRecorderTest.php:71`  
  **Related:** `candy-testing/src/Tape/TapeRecorder.php:67` — header() method concatenation

- [ ] **1.2 Reflection encapsulation violation** — Add `getModel(): Model` method to `Program` class to provide a stable API for test utilities. Update `ProgramSimulator::getModelFromProgram()` to use `$this->program->getModel()` instead of Reflection-based private property access.
  
  **Expected:** `Program::getModel()` returns `$this->model`; Reflection is no longer used  
  **Files:** `candy-core/src/Program.php:39-41`, `candy-testing/src/ProgramSimulator.php:242-256`

- [ ] **1.3 preg_replace_callback null coalescing clarity** — Replace `?? $bytes` with explicit `is_string()` check in `Assertions::escapeAnsi()`. The current `?? $bytes` pattern is confusing since `preg_replace_callback` returns `null` (not `false`) on error, and with a valid regex this never triggers.
  
  **Expected:** Code intent is clear; `is_string()` check handles null return explicitly  
  **File:** `candy-testing/src/Snapshot/Assertions.php:184-188`

---

## Phase 2: High Severity Issues [PENDING]

- [ ] **2.1 Incomplete KeyType coverage in keyMsgToVhs()** — Extend `TapeRecorder::keyMsgToVhs()` to handle more KeyType cases:
  - F1-F12 function keys
  - Home, End, PageUp, PageDown
  - Delete, Insert
  - Keypad variants (Kp0-KpBegin)
  - Media keys (MediaPlay, MuteVolume, etc.)
  - Lock keys (CapsLock, NumLock, ScrollLock, etc.)
  
  **Expected:** Returns non-null for at least F1-F12, Home, End, PageUp, PageDown, Delete, Insert  
  **File:** `candy-testing/src/Tape/TapeRecorder.php:175-196`  
  **Reference:** `candy-core/src/KeyType.php:16-317` — full enum with 120+ cases

- [ ] **2.2 Incomplete keyMsgToVhs test coverage** — Add test cases for all newly supported KeyType values. Tests currently only cover character keys, Enter, Escape, Backspace, Tab, and arrow keys. Most key types return `null` without ever being tested.
  
  **Expected:** Test count increases from ~15 to ~30+ key type tests  
  **File:** `candy-testing/tests/TapeRecorderTest.php:187-284`

- [ ] **2.3 O(n) array_shift queue operations** — Replace `array_shift()` with `SplQueue` or maintain an index pointer. `array_shift()` re-indexes the entire numeric array which is O(n).
  
  **Expected:** Uses `SplQueue` or index pointer for O(1) shift operation  
  **File:** `candy-testing/src/ProgramSimulator.php:139-144`

- [ ] **2.4 escapeVhsRune manual loop could use addcslashes()** — Replace manual character-by-character loop with `addcslashes($rune, '\\"')`. The current implementation only escapes `\` and `"` which is exactly what `addcslashes()` does.
  
  **Expected:** Single `addcslashes()` call replaces ~15 line loop  
  **File:** `candy-testing/src/Tape/TapeRecorder.php:207-221`

---

## Phase 3: Medium Severity Issues [PENDING]

- [ ] **3.1 ScriptedInput readonly array immutability documentation** — Add doc-comment explaining that PHP's `readonly` keyword only prevents re-assignment of the property, not mutation of array contents. Note that `push()` always creates a new instance, maintaining true immutability.
  
  **Expected:** Class doc-comment clarifies immutability guarantee  
  **File:** `candy-testing/src/Input/ScriptedInput.php:31-42`

- [ ] **3.2 Inefficient diffAnsi string processing** — Refactor `Assertions::diffAnsi()` to split strings into lines first, then escape ANSI for display. Currently calls `escapeAnsi()` on entire strings before line splitting.
  
  **Expected:** Split first, then escape for display  
  **File:** `candy-testing/src/Snapshot/Assertions.php:147-171`

- [ ] **3.3 file_put_contents 0-byte check clarification** — Both `TapeRecorder::save()` and `GoldenFile::save()` should handle 0-byte writes as error cases (not just `=== false`). For tape/golden files, 0 bytes written is almost certainly wrong.
  
  **Expected:** Explicit handling of 0-byte write case  
  **Files:** `candy-testing/src/Tape/TapeRecorder.php:154-167`, `candy-testing/src/Snapshot/GoldenFile.php:46-58`

- [ ] **3.4 ProgramSimulator maxCycles documentation** — Add doc-comment explaining why 10,000 is the limit, guidance on when to adjust, and impact on stress tests with many tick messages.
  
  **Expected:** Doc-comment explains limit rationale  
  **File:** `candy-testing/src/ProgramSimulator.php:197-207`

- [ ] **3.5 ProgramSimulatorTest confusing method naming** — Add `withCaptureOnlyMode(bool $captureOnly)` method (keeping `withRealCmdRunner` for BC). Update test name from `testDefaultRunnerDoesNotExecuteCmds` to reflect actual behavior.
  
  **Expected:** Clear method names that reflect behavior  
  **Files:** `candy-testing/src/ProgramSimulator.php:99-104`, `candy-testing/tests/ProgramSimulatorTest.php:200-226`

---

## Phase 4: Low Severity Issues [PENDING]

- [ ] **4.1 Extract TemporaryDirectory trait for test cleanup** — Create `TemporaryDirectoryTrait` in test directory. Move `setUp()`/`tearDown()` temp directory logic to trait. Use in `AssertionsTest`, `TapeRecorderTest`, `GoldenFileTest`, and any other test files with same pattern.
  
  **Expected:** Single trait used by all test classes needing temp directory cleanup  
  **Files:** `candy-testing/tests/AssertionsTest.php:18-43`, `candy-testing/tests/TapeRecorderTest.php:27-46`, `candy-testing/tests/GoldenFileTest.php:18-44`

- [ ] **4.2 GoldenFile 0755 permissions** — Use 0644 for files written via `file_put_contents()`. 0755 allows group/other users to read/execute which may not be appropriate for golden files containing test data.
  
  **Expected:** Files use 0644 permissions  
  **File:** `candy-testing/src/Snapshot/GoldenFile.php:46-58`

---

## Phase 5: Missing Features [PENDING]

- [ ] **5.1 AsyncProgramSimulator for ReactPHP support** — Create `AsyncProgramSimulator` interface/class that works with ReactPHP event loop. Support `React\Promise\PromiseInterface` in fakeCmdRunner. Add async assertion helpers (`await()`, `eventually()`).
  
  **Expected:** Async testing support for ReactPHP-based ecosystem  
  **Files:** New `candy-testing/src/AsyncProgramSimulator.php`

- [ ] **5.2 Command assertion helpers** — Add assertion helpers to `TestResult`:
  - `assertCmdCount(int $expected): void`
  - `assertCmdContains(callable $filter): void`
  - `assertNoCmds(): void`
  
  **Expected:** Fluent assertion methods for command inspection  
  **File:** `candy-testing/src/TestResult.php:1-30`

- [ ] **5.3 Subscription timing control** — Add control over `pumpSubscriptions()` behavior:
  - Option to control number of `produce()` calls
  - Method to inspect subscription state
  - Method to test subscription errors
  
  **Expected:** Tests can verify subscription behavior under different scenarios  
  **File:** `candy-testing/src/ProgramSimulator.php:171-186`

- [ ] **5.4 Mouse wheel support** — Add `wheel()` method to `ScriptedInput` for mouse wheel events (common in TUI navigation with scrollable views).
  
  **Expected:** `ScriptedInput::wheel()` method exists  
  **File:** `candy-testing/src/Input/ScriptedInput.php:199-211`

- [ ] **5.5 Paste/clipboard support** — Add `paste()` and `clipboard()` methods to `ScriptedInput`. Map to `PasteMsg` and `ClipboardMsg` from `Msg` interface.
  
  **Expected:** Methods to generate paste/clipboard messages  
  **Files:** `candy-core/src/Msg/` — PasteMsg, ClipboardMsg definitions

- [ ] **5.6 Keyboard enhancements/IME support** — Add `KeyboardEnhancementsMsg` helper method to `ScriptedInput`. Support for IME composition events is important for internationalization.
  
  **Expected:** `ScriptedInput` can generate `KeyboardEnhancementsMsg`  
  **File:** `candy-testing/src/Input/ScriptedInput.php:1-241`

---

## Phase 6: Refactoring Opportunities [PENDING]

- [ ] **6.1 $outputBytes streaming** — Refactor `ProgramSimulator` output concatenation to avoid creating many intermediate strings. Consider using `finalViewResult` and concatenating directly, or generator pattern for streaming.
  
  **Expected:** Reduced memory allocations for large outputs  
  **File:** `candy-testing/src/ProgramSimulator.php:157`

---

## Conditions for Completion

- All phases marked `[COMPLETE]`
- All tasks marked `[x]` (checked)
- All existing tests pass
- No regression in functionality

---

## Notes

- **2026-06-30**: Implementation plan created based on `findings/candy-testing.md` code review
- All fixes should maintain backward compatibility unless explicitly breaking
- Tests must pass after each change before moving to next task
- Consider grouping related changes into single commits per PR guidance in AGENTS.md
- Bundle 2-4 items per PR following ship-as-you-go cadence
