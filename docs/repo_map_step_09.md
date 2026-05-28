# Step 09 — candy-core foundation enhancements

**Branch:** `ai/candy-core-foundations`
**Depends on:** step-04 (candy-testing exists — for the WithTestableIo trait integration)
**Blocks:** step-15 (candy-forms uses ProgressReporter), step-23 (consumers of withLogger/withExceptionHandler)

## Goal

Enhance `candy-core` with the foundation primitives §326.1 calls for: `ProgramOptions::builder()` (16-param ctor → builder), `Program::withLogger()`, `Program::withExceptionHandler()`, exposed `$lastFrameDuration` (enables adaptive framerate per §387), `ProgressReporter` interface (§345.10), and `UndoActionType` enum (§345.8 — replaces string-based undo routing across super-candy/candy-mines/candy-tetris).

Reference: `docs/repo_map_update.md` §327.1 (Core enhancements), §345.8 (UndoActionType), §345.10 (ProgressReporter), §387.10 (god-class precursor).

## Files expected to be created

- `candy-core/src/ProgramOptions/ProgramOptionsBuilder.php`
- `candy-core/src/ProgressReporter.php` — interface.
- `candy-core/src/Progress/CallbackProgressReporter.php` — default impl wrapping a callable.
- `candy-core/src/Progress/SilentProgressReporter.php` — no-op.
- `candy-core/src/Undo/UndoActionType.php` — enum (Delete, Move, Rename, Copy, Custom).
- `candy-core/src/Undo/UndoAction.php` — readonly (type: UndoActionType, payload: array, label: string).

## Files expected to be modified

- `candy-core/src/ProgramOptions.php` — keep existing ctor; add `static builder(): ProgramOptionsBuilder`.
- `candy-core/src/Program.php` — add `withLogger(LoggerInterface): self`, `withExceptionHandler(callable): self`, expose `lastFrameDuration(): float` getter.
- `candy-core/src/Util/Width.php` — confirm `Width::of(string): int` exists; if not, add it (candy-buffer step-02 needs this).
- `candy-core/tests/ProgramOptionsBuilderTest.php`, `ProgressReporterTest.php`, `UndoActionTypeTest.php`.

## Acceptance criteria

- [ ] `ProgramOptions::builder()` returns a fluent builder; every existing ctor param has a `with*()` setter. Builder's `build(): ProgramOptions` validates required fields and throws `\InvalidArgumentException` on missing required.
- [ ] Existing `new ProgramOptions(...)` continues to work unchanged (back-compat).
- [ ] `Program::withLogger(LoggerInterface): self` — immutable, returns new Program with logger injected. Default logger is PSR-3 `NullLogger`.
- [ ] `Program::withExceptionHandler(callable(\Throwable): void): self` — fires on uncaught exception in update/view; default re-throws.
- [ ] `Program::lastFrameDuration(): float` — seconds elapsed for the most recent render cycle; 0.0 before first render.
- [ ] `ProgressReporter::report(int $current, int $total, ?string $label = null): void` — interface.
- [ ] `CallbackProgressReporter::new(callable): self`.
- [ ] `UndoActionType` enum with 5 cases minimum (Delete, Move, Rename, Copy, Custom).
- [ ] `UndoAction::new(UndoActionType $type, array $payload, string $label): self` readonly.
- [ ] `Width::of(string $s): int` exists and handles ASCII (1 per char), CJK (2), zero-width (0), emoji ZWJ sequences (composite width).
- [ ] No semver break: every previously-passing candy-core test continues to pass.
- [ ] ≥95 % coverage on the new types.
- [ ] `git status` clean on master.

## Coder brief

1. **Confirm current ProgramOptions signature** — delegate to an Explore agent: "Open `candy-core/src/ProgramOptions.php` and list every ctor parameter with its type + default. Confirm the count is around 16."
2. **Build the Builder** — `with*()` per param, all fluent + immutable returning new builder instances. `build()` returns `ProgramOptions` via the existing ctor. Validate required fields.
3. **withLogger / withExceptionHandler** on Program: use the `mutate()` helper. Default logger is `Psr\Log\NullLogger`. Default exception handler is `static fn(\Throwable $t) => throw $t`.
4. **$lastFrameDuration**: in the render loop, capture `microtime(true)` before/after the view+flush cycle; store on the Program instance. Expose via `lastFrameDuration()` accessor.
5. **ProgressReporter**: interface + 2 impls. Used by super-candy / sugar-post / others in step-25/35.
6. **UndoActionType + UndoAction**: replaces string-based routing (super-candy currently does `str_starts_with($desc, 'delete ')`). Consumers migrate in step-25.
7. **Width::of**: if it already exists, no-op. If not, port from upstream `charmbracelet/x/cellbuf` width handling. Use `Symfony\Component\String` or `mb_strwidth` as starting point but extend for emoji ZWJ.
8. Run phpunit + check-path-repos.

## Tester brief

- ProgramOptionsBuilder: every with* + build() round-trip; missing required field throws; immutability (consecutive `with*()` calls).
- Program::withLogger: returns new instance; default is NullLogger; calling withLogger doesn't affect the original.
- Program::withExceptionHandler: thrown exception in update/view calls the handler; default re-throws.
- $lastFrameDuration: starts at 0.0; >0 after first render; updates after each render.
- ProgressReporter: callback impl invokes the callable with right args; silent impl is no-op (assertable via spy).
- UndoActionType enum: 5+ cases; `UndoAction` is readonly.
- Width::of: ASCII string "abc" → 3; CJK "中文" → 4; emoji "🎉" → 2; zero-width joiner "👨‍👩‍👧‍👦" → 2 (family emoji is one grapheme cluster, displayed as one glyph but logically 2-cell width in most terminals).

## Scribe brief

- `candy-core/README.md`: add a section on the Builder + new Program helpers + ProgressReporter + UndoActionType. Code example for each.
- `candy-core/CALIBER_LEARNINGS.md`: "ProgramOptions kept 16-arg ctor for back-compat; new code uses Builder. UndoActionType replaces 4+ ad-hoc string-prefix detection schemes."
- MATCHUPS unchanged — candy-core's parity is unaffected.
- Docblocks on new public classes citing the relevant repo_map sections.

## Ship brief

- **PR title**: `candy-core: ProgramOptions builder + ProgressReporter + UndoActionType + Program helpers`
- **PR body**:
  ```
  ## Summary
  - ProgramOptions::builder() relieves the 16-param ctor.
  - Program::withLogger / withExceptionHandler / lastFrameDuration enable production logging + adaptive framerate.
  - New ProgressReporter interface (consumed in steps 25 + 35).
  - New UndoActionType enum (replaces string-based routing in steps 25 + 32).
  - Width::of added/confirmed for downstream candy-buffer (step-02) use.
  - Fully back-compatible: existing ctor + API surface untouched.

  ## Test plan
  - [x] vendor/bin/phpunit in candy-core (≥95% coverage on new types, existing tests still pass)
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_09.md, docs/repo_map_update.md §327.1, §345.8, §345.10
  ```
- Commit subject: `candy-core: ProgramOptions builder + Program helpers + UndoActionType`.
