# sugar-testing CALIBER_LEARNINGS

Accumulated patterns and anti-patterns for this library.

## Self-testing note

sugar-testing is itself a test framework, so its tests are meta — they
assert that it correctly tests other things. Self-test by simulating a
trivial counter Program. Don't introduce real I/O in sugar-testing's own
tests — use fixtures.

## Patterns

- **Counter fixture** — A simple `CounterModel` that increments on `KeyMsg('+')`
  and decrements on `KeyMsg('-')` is sufficient for self-testing
  ProgramSimulator's message dispatch, model update, and view capture.

### 2026-05-28 — ProgramSimulator drives tea programs via scripted input
Pattern: Use `ProgramSimulator::for($program)->send(...)->run()` to drive TEA programs deterministically without real I/O. Chain multiple `->send()` calls for complex input sequences. Set `withFakeCmdRunner()` to decide the message injected per Cmd — it does NOT stop execution; add `withRealCmdRunner(false)` for capture-only (truth-flipped 2026-10-23, docs-fix-1023).
Anti-pattern: Don't introduce real stdin/stdout in self-tests — use memory streams and fixtures instead.
Source: step-04 ai/sugar-testing-new

### 2026-05-28 — assertGoldenAnsi auto-creates golden files when UPDATE_GOLDENS=1
Pattern: First run with `UPDATE_GOLDENS=1` to scaffold a `.golden` fixture; subsequent runs assert byte-exact match. The flag is CREATE-ONLY — a mismatching existing golden still fails and is never rewritten (delete to re-baseline). Golden files live in `tests/fixtures/`.
Anti-pattern: Don't commit golden files that capture non-deterministic output (timestamps, entropy).
Source: step-04 ai/sugar-testing-new

### 2026-10-03 — Decode terminal input with the real InputReader, never a stand-in
Pattern: `ScriptedInput::paste()`/`bytes()` feed bytes through sugar-core's `InputReader` in `READ_SIZE` (4096, the runtime's `fread()` size) reads, then run the runtime's idle recovery (lone-ESC flush, `flushStalePaste()`). Chunking past `MAX_PASTE_BYTES` only appears between reads, so one giant `parse()` call would hide it.
Anti-pattern: `paste()` used to push one raw `PasteMsg` — no envelope, no sanitizing, no chunking — so a model that replaced its value on every PasteMsg, or echoed escapes, passed here and broke on a terminal.
Pattern: `ProgramSimulator::run()` applies subscription output right after the message that fired it and never appends it to the `send()` queue; the old fixed-count drain dropped it and the next `run()` replayed it.

