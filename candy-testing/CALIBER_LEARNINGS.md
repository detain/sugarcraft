# candy-testing CALIBER_LEARNINGS

Accumulated patterns and anti-patterns for this library.

## Self-testing note

candy-testing is itself a test framework, so its tests are meta — they
assert that it correctly tests other things. Self-test by simulating a
trivial counter Program. Don't introduce real I/O in candy-testing's own
tests — use fixtures.

## Patterns

- **Counter fixture** — A simple `CounterModel` that increments on `KeyMsg('+')`
  and decrements on `KeyMsg('-')` is sufficient for self-testing
  ProgramSimulator's message dispatch, model update, and view capture.

### 2026-05-28 — ProgramSimulator drives tea programs via scripted input
Pattern: Use `ProgramSimulator::for($program)->send(...)->run()` to drive TEA programs deterministically without real I/O. Chain multiple `->send()` calls for complex input sequences. Override `withFakeCmdRunner()` to intercept side-effecting commands.
Anti-pattern: Don't introduce real stdin/stdout in self-tests — use memory streams and fixtures instead.
Source: step-04 ai/candy-testing-new

### 2026-05-28 — assertGoldenAnsi auto-creates golden files when UPDATE_GOLDENS=1
Pattern: First run with `UPDATE_GOLDENS=1` to scaffold a `.golden` fixture; subsequent runs assert byte-exact match. Golden files live in `tests/fixtures/`.
Anti-pattern: Don't commit golden files that capture non-deterministic output (timestamps, entropy).
Source: step-04 ai/candy-testing-new
