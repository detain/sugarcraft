# Code Review Findings — sugar-stash

**Library:** sugar-stash (Terminal Git client — port of jesseduffield/lazygit)
**Review Date:** 2026-06-30
**Reviewer:** Code Review
**Files Reviewed:** 15 source files, 14 test files

---

## Executive Summary

 sugar-stash is a well-structured, thoughtfully engineered TUI git client that demonstrates strong adherence to immutability patterns, comprehensive test coverage via fixture-driven testing, and careful attention to security (terminal escape injection prevention via `Renderer::sanitize()`, option injection prevention via `Git::guardRef()`). The primary architectural concern is the synchronous blocking execution model — every git operation blocks via `proc_open()` with no async alternatives — which places it at odds with the ReactPHP async ecosystem it inhabits. Beyond that, the implementation quality is high: code is lean, patterns are consistent, and tests are thorough.

**Overall Assessment:** REQUEST_CHANGES

---

## Critical Issues

### 🔴 C-1: Synchronous blocking git execution blocks the event loop

**Location:** `src/Git.php:243-286` (`run()` and `runPatch()` private methods)

**Description:**
Every git operation in the `Git` class uses `proc_open()` synchronously, blocking the PHP process until the command completes. This happens inside the TUI's update loop (`App::update()`) on every user action that touches git (stage, commit, checkout, etc.). In a ReactPHP-based TUI ecosystem where the event loop must remain responsive to keyboard input and screen rendering, blocking on external process execution degrades the user experience significantly and contradicts the async architecture of sibling libraries in the SugarCraft monorepo.

**Impact:**
- Every git operation (stage, unstage, commit, checkout, etc.) freezes the TUI for the duration of the subprocess
- Under slow git operations (large diffs, network-backed repos), the interface becomes unresponsive
- Cannot be used in scenarios requiring concurrent git operations

**Recommendation:**
Consider introducing an async git driver interface (e.g., `AsyncGitDriver`) that wraps git invocations in ReactPHP promises via `React\ChildProcess\Process` and `react/promise`. The existing `GitDriver` interface would remain the synchronous default, with tests continuing to use the fixture closure-driven implementation. Alternatively, document this as a known limitation and scope the library as strictly a synchronous TUI.

**Confidence:** 95%

---

### 🔴 C-2: Missing candy-fuzzy path repository for transitive dependency

**Location:** `composer.json:94-100`

**Description:**
The `composer.json` lists `sugarcraft/candy-fuzzy: dev-master` as a dependency (required by `StashManager::fuzzyFilter()` at line 98 of `StashManager.php`), and includes the path repository for candy-fuzzy itself. However, the CALIBER_LEARNINGS.md notes that path-repo closure must propagate to the FULL transitive dependency graph. The candy-fuzzy library may itself have path repository requirements that are not fully wired in sugar-stash's `repositories[]` array, which would cause `composer install` to fail or pull the package from packagist instead of the local path.

**Impact:**
- `composer install` may fail if candy-fuzzy is not available as a local path
- If candy-fuzzy has its own path dependencies, those may not resolve correctly

**Recommendation:**
Run `composer validate --no-check-all` (without `--strict`, as noted in AGENTS.md) and verify candy-fuzzy resolves from the local path repository. If candy-fuzzy has its own path deps, propagate those repositories into sugar-stash's `repositories[]` array following the pattern documented in `sugar-charts/composer.json`.

**Confidence:** 85%

---

## Major Issues

### 🟠 M-1: Duplicate `removeDir()` implementation in test files

**Location:**
- `tests/GitApplyIntegrationTest.php:37-45`
- `tests/GitTrapTest.php:27-35` (both files contain identical `removeDir()` method)

**Description:**
The `removeDir()` helper method — a recursive directory removal utility — is copy-pasted verbatim into two integration test files. This is a DRY violation that makes maintenance harder: if the removal logic needs to change (e.g., to use `RecursiveDirectoryIterator` with proper symlink handling, or to add error handling), the fix must be applied in two places.

**Impact:**
- Maintenance burden: changes must be duplicated
- Risk of divergence if one copy is updated and the other is forgotten

**Recommendation:**
Extract `removeDir()` into a shared test utility trait or base class (e.g., `SugarCraft\Stash\Tests\Concerns\RecursiveDirCleanup`) and have both test classes use it via `use`.

**Confidence:** 95%

---

### 🟠 M-2: Interactive rebase state machine builds todo list but never executes it

**Location:** `src/InteractiveRebase.php:61-222`

**Description:**
The `InteractiveRebase` class manages the full state machine for building an interactive rebase todo list (selecting N commits, cycling actions per commit, dropping commits). However, there is no `GitDriver` method to actually execute the built todo list, and no code path in `App.php` that would dispatch the rebase sequence once the user confirms. The `cycleAction()` method cycles through `Pick → Reword → Edit → Squash → Drop → Pick`, but without an execution path, this is UI-only.

The `RebaseAction` enum includes an `Edit` action, but editing a commit during a rebase requires spawning an editor and waiting for user interaction, which has no implementation.

**Impact:**
- Interactive rebase is visually complete but functionally a no-op
- User can build a todo list but cannot execute it

**Recommendation:**
Either:
1. Implement `GitDriver::interactiveRebase(list<RebaseCommit> $commits): void` that sequences git rebase operations, or
2. If out of scope for v1, remove the `RebaseAction::Edit` case and document the limitation, or
3. Add a `// TODO:` comment marking the execution path as unimplemented with a link to an issue

**Confidence:** 90%

---

## Minor Issues

### 🟡 N-1: No per-lib `.github/workflows/ci.yml`

**Location:** `sugar-stash/` (no `.github/` subdirectory)

**Description:**
The library has no `.github/workflows/ci.yml` of its own. CI discovery in this monorepo is handled dynamically via `scripts/affected-libs.php` (per AGENTS.md), which may rely on the root `.github/workflows/ci.yml`. While this works for the monorepo CI pipeline, a per-lib workflow would enable:
- lib-specific test matrix
- independent CI status reporting on the lib's GitHub repo
- proper integration with GitHub's PR review UI showing per-lib status

**Impact:**
- CI must be triggered through monorepo root; standalone lib testing requires extra setup
- Codecov and other PR integrations may not show per-lib status

**Recommendation:**
Add `sugar-stash/.github/workflows/ci.yml` following the pattern used in other libs in the monorepo. See `candy-core/.github/workflows/ci.yml` as a reference.

**Confidence:** 85%

---

### 🟡 N-2: No per-lib `codecov.yml`

**Location:** `sugar-stash/` (no `codecov.yml`)

**Description:**
The library has no `codecov.yml` flag/component configuration of its own. Codecov reporting relies on the root `codecov.yml` for aggregation. A per-lib codecov configuration would allow independent coverage tracking and per-lib coverage requirements.

**Impact:**
- Coverage reports aggregate at monorepo level only
- Cannot enforce per-lib coverage thresholds independently

**Recommendation:**
Add `sugar-stash/codecov.yml` following the pattern documented in AGENTS.md.

**Confidence:** 85%

---

### 🟡 N-3: `DiffViewer::withHunkCursor()` cursor clamping edge case

**Location:** `src/DiffViewer.php:82-96`

**Description:**
The `withHunkCursor()` method clamps the provided `$cursor` index to `[0, max]` where `max = count($this->hunkStarts) - 1`. However, when `hunkStarts` is empty, `$max` becomes `-1`, and the expression `max >= 0 ? $max : 0` correctly returns `0`. But the subsequent line `$lineIdx = ($max >= 0 && isset($this->hunkStarts[$newIdx]))` correctly handles the empty case by falling back to `0`. This appears correct but the logic is complex enough to benefit from an explicit early-return guard for the zero-hunk case.

**Impact:**
- Correct in practice, but the complexity makes future maintenance risky

**Recommendation:**
Add an early return for empty hunks at the top of `withHunkCursor()`:
```php
if ($this->hunkStarts === []) {
    return new self(lines: $this->lines, hunkCursor: 0, path: $this->path, hunkStarts: [], header: $this->header);
}
```

**Confidence:** 80%

---

### 🟡 N-4: `HistoryManager` undo/redo immutability is correct but push could be more explicit

**Location:** `src/HistoryManager.php:30-36`

**Description:**
`HistoryManager::push()` returns a new instance with the new entry appended to `undoStack` and `redoStack` cleared. This is correct immutable behavior. However, the `undo()` and `redo()` methods use `array_pop()` on a copy of the stack array (`$newUndoStack = $this->undoStack; array_pop($newUndoStack)`), which is correct but the intent would be clearer if used array spread syntax (`[..., $entry]`) consistently with `push()`.

**Impact:**
- Minor readability improvement; behavior is correct

**Recommendation:**
Refactor `undo()` and `redo()` to use spread syntax for consistency with `push()`:
```php
// undo:
$newManager = new self(
    undoStack: array_slice($this->undoStack, 0, -1),
    redoStack: [...$this->redoStack, $entry],
);
```

**Confidence:** 80%

---

## Positive Observations

### 🟢 O-1: Renderer::sanitize() is comprehensive and well-tested

The `sanitize()` method at `src/Renderer.php:23-30` strips all C0/C1 control characters, DEL, and bare ESC bytes from untrusted git output before rendering. It is applied consistently at every point where external data (file paths, branch names, log subjects, stash entries, worktree info) flows into the terminal output. The `RendererTest::testSanitizesControlBytes()` test provides concrete verification with `\x07` bell character injection.

### 🟢 O-2: GitGuard pattern effectively prevents option injection

The `guardRef()` method at `src/Git.php:235-240` rejects any ref/branch/path argument that begins with `-`, preventing git option injection in all 9 call sites (checkout, merge, cherry-pick, stash apply/drop, worktree add/remove, create branch). The `GitGuardTest.php` provides comprehensive test coverage for all 9 guard scenarios.

### 🟢 O-3: Immutability is pervasive and correctly implemented

All state containers use `readonly` properties (PHP 8.1+), all mutation methods return new instances (`with*()` pattern), and the `App` class uses a private `withAll()` helper with null-coalescing to safely construct new instances. The `HistoryManager` maintains true immutability with separate undo/redo stacks.

### 🟢 O-4: FixtureGit test double is comprehensive

`tests/AppTest.php:14-96` defines `FixtureGit` implementing the full `GitDriver` interface with public array/log properties for assertions. This enables behavior-driven testing without spawning real git repositories, and covers all 30+ `GitDriver` methods.

### 🟢 O-5: Inline text collection pattern is clean and well-documented

The inline multi-character text collection pattern (commit message, branch name, merge target, cherry-pick ref) via `collectingXxx: bool` + `xxx: string` accumulator is consistently applied across all input modes, keeps the model fully immutable, and is documented in `CALIBER_LEARNINGS.md` as `[pattern:inline-text-collection]`.

### 🟢 O-6: DiffViewer hunk cursor implementation is correct

`DiffViewer::fromRawDiff()` correctly parses hunk headers (`@@ -N,N +N,N @@`) into line indices stored in `hunkStarts`. `withHunkCursor()` correctly clamps the cursor index, and `currentHunkLines()` correctly extracts the slice between consecutive hunk starts. The `Renderer::diffOverlay()` correctly applies `reverse()` highlighting to the selected hunk block.

---

## Philosophy Compliance

### The 5 Laws of Elegant Defense (code-philosophy)

| Checkpoint | Status | Notes |
|------------|--------|-------|
| Law 1: Data guides, never commands | ✅ PASS | Immutable state objects (`StashEntry`, `WorktreeEntry`, `RebaseCommit`) guide logic; mutations return new instances |
| Law 2: Fail explicitly, never silently | ✅ PASS | `Git::guardRef()` throws `InvalidArgumentException`; git errors throw `RuntimeException`; empty stash/log/branch shows explicit "(empty)" message |
| Law 3: Single responsibility per class | ✅ PASS | `Git` wraps CLI only; `GitDriver` defines interface; `Renderer` is pure view; state managers each own one domain |
| Law 4: No global state | ✅ PASS | All state flows through `App` model constructor; `GitDriver` is injected; no static mutable state |
| Law 5: Tests prove correctness | ✅ PASS | 14 test files with comprehensive coverage; `FixtureGit` enables behavioral assertions; `LangCoverageTest` prevents silent translation gaps |

---

## Severity Summary

| Severity | Count | Issues |
|----------|-------|--------|
| 🔴 Critical | 2 | C-1 (blocking exec), C-2 (candy-fuzzy path closure) |
| 🟠 Major | 2 | M-1 (duplicate removeDir), M-2 (interactive rebase no execution) |
| 🟡 Minor | 4 | N-1 (no per-lib CI), N-2 (no per-lib codecov), N-3 (DiffViewer edge case), N-4 (HistoryManager refactor) |
| 🟢 Positive | 6 | O-1 through O-6 |

---

## Recommended Fix Order

1. **C-2** (candy-fuzzy path closure) — unblocks `composer install`
2. **M-1** (duplicate removeDir) — trivial extraction to shared trait
3. **N-1, N-2** (missing CI/codecov) — infrastructure, low risk
4. **C-1** (blocking exec) — significant architectural decision needed (async vs. documented limitation)
5. **M-2** (interactive rebase no execution) — requires design decision on scope
6. **N-3, N-4** (refinements) — low priority, can be addressed in cleanup passes
