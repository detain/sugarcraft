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

### 2026-11-13 — Localization boundary: thrown exceptions localized, assertion guidance English
Pattern: Exceptions THROWN by the harness (bad input, refusal guards) carry `Lang::t()` messages so user-facing failures translate. Guidance prose that only ever appears as a PHPUnit failure text — `Assert::fail()` strings and `TestResult` re-baselining directions — stays English: it is test-author-facing, goes in golden-free diff output, and translating it would churn pins for zero user benefit.
Anti-pattern: Half-migrating one family and leaving sibling throws of the same guard ad hoc — pick the boundary (throw vs assertion-failure) and keep it.
Source: sugar-testing EXEC testing-11
### 2026-11-13 — Three assertion homes, one ruling
Pattern: The lib exposes assertions from three places and each has a fixed job. `Snapshot\Assertions` is the static façade and the house shape for new general-purpose helpers (delegates to PHPUnit statically; it does not extend Assert). `Graphics\GraphicsAssertions` extends `PHPUnit\Framework\Assert` — grandfathered, because inheriting means every failure is already a counted assertion; new members there keep that static-invocation shape. `TestResult::assert*()` instance verbs are value-object convenience with a teatest antecedent — they read off a finished run, so they live on the result, not the façade.
Anti-pattern: Adding the next helper to whichever class you happen to be editing — route by kind: general snapshot check → Assertions; graphics decode check → GraphicsAssertions; result-of-a-run check → TestResult.
Source: sugar-testing EXEC testing-12
