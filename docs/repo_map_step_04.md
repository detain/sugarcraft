# Step 04 — Create candy-testing

**Branch:** `ai/candy-testing-new`
**Depends on:** step-00 ✅ (independent — testing tooling)
**Blocks:** step-15 (candy-forms uses it), step-16 (sugar-prompt uses it), step-28 (golden-file rollout)

## Goal

Create the `candy-testing` foundation package — the testing infrastructure SugarCraft pioneers that bubble-tea issue #1654 never solved: `ProgramSimulator` for driving TEA programs with scripted input, `Program::withInput()`/`withOutput()` injection traits, and snapshot-assert helpers (`assertGoldenAnsi`, `assertCellGrid`, golden file management).

Reference: `docs/repo_map_update.md` §327.4 (framework), §345.4 (ProgramSimulator), §387.6 (golden file consolidation), §387.8 (snapshot test pattern), strategic recs §452 ("Lead Where Go Has Not").

## Files expected to be created

- `candy-testing/composer.json`
- `candy-testing/phpunit.xml`
- `candy-testing/README.md`
- `candy-testing/CALIBER_LEARNINGS.md`
- `candy-testing/src/ProgramSimulator.php` — drive a `Program` with scripted messages, capture outputs.
- `candy-testing/src/Concerns/WithTestableIo.php` — trait Programs can use to expose `withInput(resource)` / `withOutput(resource)` hooks (the actual `Program::withInput` API addition is step-09; this trait is the *consumer*-side contract).
- `candy-testing/src/Snapshot/GoldenFile.php` — load/save/compare golden ANSI files.
- `candy-testing/src/Snapshot/Assertions.php` — `assertGoldenAnsi`, `assertCellGrid`, `assertAnsiEquals` static helpers.
- `candy-testing/src/Tape/TapeRecorder.php` — record a Program's output stream to a `.tape` file for VHS playback.
- `candy-testing/src/Input/ScriptedInput.php` — value object: list of `KeyMsg | MouseMsg | Tick | WindowSize` to feed.
- `candy-testing/tests/ProgramSimulatorTest.php`, `GoldenFileTest.php`, `AssertionsTest.php`.
- `candy-testing/tests/fixtures/` — example golden files for self-tests.

## Files expected to be modified

- Root composer.json, MATCHUPS, PROJECT_NAMES, README, docs/index.html, docs/lib/candy-testing.html, codecov.yml.

## Acceptance criteria

- [ ] `ProgramSimulator::for(Program $program): self` factory.
- [ ] `ProgramSimulator::send(Msg $msg): self` — fluent, immutable, queues message.
- [ ] `ProgramSimulator::run(): TestResult` — drains the queue, returns final Model + last view bytes + emitted Cmds.
- [ ] `Assertions::assertGoldenAnsi(string $goldenPath, string $actual)` — auto-creates golden on first run when env var `UPDATE_GOLDENS=1` set; otherwise diffs.
- [ ] `Assertions::assertCellGrid(array $expected, Buffer $actual)` — diffs by cell, prints per-cell mismatch.
- [ ] `Assertions::assertAnsiEquals(string $expected, string $actual)` — byte-exact compare with a readable diff (highlight ESC sequences).
- [ ] `GoldenFile::load(string $path): ?string` — returns null on miss; `save(string $path, string $content): void`.
- [ ] **Depends on candy-core**: yes — `Program`, `Model`, `Msg`, `Cmd` types come from candy-core. Add `"sugarcraft/candy-core": "@dev"` require + path-repo entry via `path-repo-closure`.
- [ ] **Depends on candy-buffer**: yes — for `assertCellGrid`. Add via `path-repo-closure`.
- [ ] ≥95 % coverage.
- [ ] `git status` clean on master.

## Coder brief

1. **Invoke `scaffold-library`** with slug `candy-testing`, namespace `SugarCraft\Testing\`, role "Test harness for TEA programs (ProgramSimulator, golden file assertions)".
2. **Add deps** with `path-repo-closure`:
   - `sugarcraft/candy-core: @dev` (Program / Msg / Model)
   - `sugarcraft/candy-buffer: @dev` (Buffer for cellGrid asserts)
3. **Implement ProgramSimulator**:
   - Construct with a Program instance.
   - `send(Msg)` enqueues; supports `Tick(seconds)` to advance virtual time.
   - `run()` calls `Program::init()` to get initial Cmd, then loops: pull next Msg, call `update($model, $msg)`, accumulate `$view = $program->view($model)`, drain Cmds (record but don't execute side-effecting ones — provide a `ProgramSimulator::withFakeCmdRunner(callable)` hook).
   - Return a `TestResult` value object with `model()`, `view()`, `cmds()`, `output()` (the cumulative ANSI bytes).
4. **Implement Assertions** as static methods (PHPUnit-style):
   - `assertGoldenAnsi`: read golden file, compare; on env `UPDATE_GOLDENS=1` write actual; on miss print "no golden, set UPDATE_GOLDENS=1".
   - `assertCellGrid`: walk both buffers cell-by-cell, build a printable diff highlighting mismatches.
   - `assertAnsiEquals`: normalise `\x1b` representation when printing the diff so reviewers can read it.
5. **Implement GoldenFile**: simple file IO with `.golden` extension; supports directory-relative paths.
6. **Implement TapeRecorder**: wrap the Program's output stream; emit a `.tape` file using VHS syntax (`Type "..."`, `Sleep ...`, etc.).
7. **Implement ScriptedInput**: builder for sequences (`->key('a')->key('Enter')->ticks(5)->mouse(...)`).
8. **Self-test**: `candy-testing/tests/` use ProgramSimulator on a trivial Program (e.g., a counter that increments on each `KeyMsg('+')`). Assert the view matches a golden file in `tests/fixtures/`.
9. Run phpunit + check-path-repos.

## Tester brief

candy-testing is itself a test framework, so its tests are meta — assert it correctly tests other things.

- ProgramSimulator: drive a simple counter Program through 5 increments, assert model state, assert view byte snapshot, assert Cmds captured.
- assertGoldenAnsi: with golden present + matching, passes; with golden missing + UPDATE_GOLDENS=0, fails with clear message; with UPDATE_GOLDENS=1, creates the golden.
- assertCellGrid: 3×3 buffer with one cell diff — diff message highlights row/col.
- assertAnsiEquals: SGR sequence mismatch produces a readable diff (ESC printed as `ESC` not `^[`).
- GoldenFile: load/save round-trip preserves bytes exactly (no newline injection).
- TapeRecorder: produces a syntactically valid VHS tape (validate by running `vhs --help` or parsing the tape header).

## Scribe brief

- README: pitch "the test harness Go never built" — reference bubble-tea issue #1654. Quickstart code: `ProgramSimulator::for($program)->send(KeyMsg::char('q'))->run()`.
- CALIBER_LEARNINGS: "Self-test by simulating a trivial counter Program. Don't introduce real I/O in candy-testing's own tests — use fixtures."
- MATCHUPS: 🚀 (no upstream parallel — pioneering).
- PROJECT_NAMES Candy table.
- Docs/index.html tile + docs/lib/candy-testing.html (emphasise the strategic positioning).

## Ship brief

- **PR title**: `candy-testing: ProgramSimulator + golden-file assertions (TEA test harness)`
- **PR body**:
  ```
  ## Summary
  - New foundation lib candy-testing pioneers what bubble-tea issue #1654 has had open for 6+ years.
  - ProgramSimulator drives a Program with scripted Msgs; captures model/view/cmds.
  - Snapshot helpers: assertGoldenAnsi, assertCellGrid, assertAnsiEquals.
  - TapeRecorder emits VHS-compatible .tape files.
  - Unblocks the snapshot-test rollout in step-28 across 9 rendering libs.

  ## Test plan
  - [x] vendor/bin/phpunit in candy-testing (≥95% coverage)
  - [x] Self-tests use ProgramSimulator on a counter fixture
  - [x] UPDATE_GOLDENS=1 environment behavior verified
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_04.md, docs/repo_map_update.md §327.4, §345.4, §452
  ```
- Commit subject: `candy-testing: ProgramSimulator + golden-file infrastructure`.
