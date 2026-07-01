---
status: phase-1-complete
phase: 1
updated: 2026-06-30
---

# Implementation Plan — sugar-stash Code Review Findings

## Goal

Address all 8 code review findings (2 critical, 2 major, 4 minor) from the sugar-stash code review, implementing fixes in dependency order with verifications at each step.

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Run `php tools/check-path-repos.php` to verify C-2 before declaring it fixed | The finding raised a potential transitive closure gap but candy-fuzzy has no deps itself; verification needed | `sugar-stash.md:39-53` |
| M-1 fix is prerequisite for all other changes | Duplicate code cleanup should happen first to avoid propagating the pattern | `sugar-stash.md:59-75` |
| N-1 + N-2 are independent infrastructure additions | Can be done in parallel after M-1 | `sugar-stash.md:104-139` |
| C-1 requires design decision before implementation | Async vs documented limitation is architectural; scope it separately | `sugar-stash.md:20-33` |
| M-2 needs explicit scope decision (implement vs TODO) | Interactive rebase execution is either unimplemented by design or needs full GitDriver method | `sugar-stash.md:79-98` |
| N-3, N-4 are low-risk refinements | Can be addressed in final cleanup phase | `sugar-stash.md:143-185` |

## Phase 1: Verification & Minor Infrastructure [COMPLETE]

- [x] **1.1 Run path-repo closure check** ✅ (2026-06-30)
  - Command: `php tools/check-path-repos.php`
  - Expected: clean exit (0) confirming candy-fuzzy path-repo is sufficient
  - Notes: candy-fuzzy has NO `sugarcraft/*` dependencies (leaf lib), so its closure is empty. sugar-stash's `repositories[]` already includes candy-fuzzy path-repo at lines 94-100. The finding was a "may have" concern that does not materialize.

- [x] **1.2 Create `sugar-stash/.github/workflows/ci.yml`** ✅ (2026-06-30)
  - Location: `sugar-stash/.github/workflows/ci.yml` (new file)
  - What: Add per-lib CI workflow following the monorepo pattern (see root `.github/workflows/ci.yml` lines 1-516 for reference)
  - Why: Enables independent CI status reporting, proper PR integration, and lib-specific test matrix
  - Reference pattern: root `ci.yml` uses `scripts/affected-libs.php` for dynamic matrix discovery
  - Conditions for success: File is valid YAML, references correct PHP version and phpunit.xml path
  - Investigation notes: No other lib in the monorepo has a per-lib CI workflow — sugar-stash would be the first. This is a pioneering change. The root workflow already handles all libs dynamically.

- [x] **1.3 Create `sugar-stash/codecov.yml`** ✅ (2026-06-30)
  - Location: `sugar-stash/codecov.yml` (new file)
  - What: Add per-lib codecov configuration
  - Why: Enables independent coverage thresholds and per-lib coverage tracking
  - Note: sugar-stash already has its flag and component defined in root `codecov.yml` (lines 111-113 for flag, lines 362-367 for component). A per-lib file would allow lib-specific overrides (e.g., higher threshold).
  - Conditions for success: Valid YAML, references `coverage.xml` from the expected path

## Phase 2: Test Infrastructure Fix [PENDING]

- [ ] **2.1 Extract `removeDir()` into shared test trait**
  - Locations:
    - `sugar-stash/tests/GitApplyIntegrationTest.php:37-45`
    - `sugar-stash/tests/GitGuardTest.php:27-35` (NOTE: finding incorrectly said GitTrapTest.php — actual file is GitGuardTest.php)
  - What:
    1. Create `sugar-stash/tests/Concerns/RecursiveDirCleanup.php` trait
    2. Move `removeDir()` method into the trait
    3. Update both `GitApplyIntegrationTest.php` and `GitGuardTest.php` to `use RecursiveDirCleanup`
  - Why: DRY violation — identical code in two places makes maintenance harder
  - Conditions for success:
    - `vendor/bin/phpunit -c sugar-stash/phpunit.xml` passes
    - Both test classes still clean up their temp directories correctly
  - Code to extract (identical in both files):
    ```php
    private function removeDir(string $dir): void
    {
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
    ```
  - Investigation notes: The `RecursiveDirectoryIterator` with proper symlink handling would be better, but that would be an enhancement beyond the finding's scope. The finding recommends this exact pattern (`SugarCraft\Stash\Tests\Concerns\RecursiveDirCleanup` trait).

## Phase 3: DiffViewer & HistoryManager Refinements [PENDING]

- [ ] **3.1 Add early-return guard to `DiffViewer::withHunkCursor()`**
  - Location: `sugar-stash/src/DiffViewer.php:82-96`
  - What: Add explicit early return for empty hunks:
    ```php
    public function withHunkCursor(int $cursor): self
    {
        if ($this->hunkStarts === []) {
            return new self(lines: $this->lines, hunkCursor: 0, path: $this->path, hunkStarts: [], header: $this->header);
        }
        // ... existing logic
    }
    ```
  - Why: Current logic handles empty case correctly but is complex. Early guard improves readability and maintenance.
  - Conditions for success:
    - `vendor/bin/phpunit -c sugar-stash/phpunit.xml --filter DiffViewer` passes
    - Existing hunk count = 0 edge case still works
  - Investigation notes: The existing logic at lines 84-88 already handles empty hunks: `$max = count($this->hunkStarts) - 1` gives `-1`, then `max(0, min($cursor, $max >= 0 ? $max : 0))` correctly returns `0`. The proposed guard is a readability improvement only.

- [ ] **3.2 Refactor `HistoryManager::undo()` and `redo()` to use spread syntax**
  - Location: `sugar-stash/src/HistoryManager.php:45-82`
  - What: Refactor to use spread syntax consistent with `push()`:
    ```php
    // undo() — replace array_pop approach with:
    $newManager = new self(
        undoStack: array_slice($this->undoStack, 0, -1),
        redoStack: [...$this->redoStack, $entry],
    );

    // redo() — replace array_pop approach with:
    $newManager = new self(
        undoStack: [...$this->undoStack, $entry],
        redoStack: array_slice($this->redoStack, 0, -1),
    );
    ```
  - Why: Consistency with `push()` which uses spread syntax (`[...$this->undoStack, $entry]`). Current code uses `array_pop()` on a copy which works but is inconsistent.
  - Conditions for success:
    - `vendor/bin/phpunit -c sugar-stash/phpunit.xml --filter HistoryManager` passes
    - Behavior is identical (immutable — returns new instance)
  - Investigation notes: Current undo() at lines 52-53 uses `$newUndoStack = $this->undoStack; array_pop($newUndoStack)` — this is correct but the spread+slices approach is more idiomatic for immutable patterns.

## Phase 4: Interactive Rebase Scope Decision [PENDING]

- [ ] **4.1 Decide scope: implement execution path OR add TODO comment**
  - Location: `sugar-stash/src/InteractiveRebase.php` + `sugar-stash/src/App.php:211-241`
  - Finding: The `InteractiveRebase` class builds a todo list (cycling through Pick→Reword→Edit→Squash→Drop) but `App::update()` never dispatches execution. No `GitDriver` method exists to run the built todo list.
  - Investigation notes:
    - `InteractiveRebase::markDone()` (line 193-196) exists but is never called
    - `App::update()` lines 211-241 handle: Escape (cancel), Enter (confirm count during selectingN), digits, Up/Down navigation, Space/l (cycleAction), d (dropCurrent)
    - No key binding exists to trigger execution after building the todo list
    - `RebaseAction::Edit` case exists but would require spawning an editor (complex)
  - Option A (implement): Add `GitDriver::interactiveRebase(list<RebaseCommit> $commits): void` and wire Enter key to execute when not selectingN
  - Option B (TODO): Add `// TODO: implement executeInteractiveRebase()` comment with issue link
  - Option C (scope limit): Remove `RebaseAction::Edit` and document limitation
  - Conditions for success (if implementing): `FixtureGit` updated, App tests updated, GitDriver interface updated
  - Recommendation: Option B (TODO) for v1 — implement the full execution path in a future iteration

## Phase 5: Critical Async Architecture [PENDING]

- [ ] **5.1 Design decision: async git driver OR documented limitation**
  - Location: `sugar-stash/src/Git.php:243-286`
  - Finding: All git operations use `proc_open()` synchronously, blocking the event loop. This is at odds with the ReactPHP ecosystem.
  - Investigation notes:
    - `Git::run()` at lines 243-263: synchronous `proc_open()` with blocking `proc_close()`
    - `Git::runPatch()` at lines 265-286: same pattern for stdin-writing variant
    - `GitDriver` interface at `src/GitDriver.php` — no async methods exist
    - `FixtureGit` in `tests/AppTest.php:14-96` — fixture implementation has no async methods
    - The `Renderer::sanitize()` method (O-1 positive observation) shows security is taken seriously
  - Option A (async): Introduce `AsyncGitDriver` interface with `React\ChildProcess\Process` + promises, keep `Git` as sync default
  - Option B (document limitation): Mark library as "synchronous TUI" in README, document that async git operations are out of scope for v1
  - Recommendation: Option B for now — document the limitation, plan async for v2. The library already works correctly as a sync TUI.
  - Conditions for success (if documenting): README updated with synchronous-only note, GitDriver interface gains a `@deprecated async operations planned for v2` doc comment

## Phase 6: Final Verification [PENDING]

- [ ] **6.1 Run full test suite for sugar-stash**
  - Command: `cd sugar-stash && composer install && vendor/bin/phpunit`
  - Expected: All tests pass
  - Confirms: All refactorings (M-1, N-3, N-4) work correctly

- [ ] **6.2 Run path-repo check one final time**
  - Command: `php tools/check-path-repos.php`
  - Confirms: C-2 is definitively resolved (candy-fuzzy needs no additional path repos)

## Notes

- 2026-06-30: The finding file incorrectly references `GitTrapTest.php` at line 63 — the actual file containing the duplicate `removeDir()` is `GitGuardTest.php` at lines 27-35.
- 2026-06-30: sugar-stash already has candy-fuzzy in its `repositories[]` array at lines 94-100 of `composer.json`, and candy-fuzzy itself has zero `sugarcraft/*` dependencies, making C-2 a non-issue that should be verified with `check-path-repos.php`.
- 2026-06-30: Root `codecov.yml` already defines `sugar-stash` flag (line 111-113) and component (line 362-367), so N-2 is partially addressed. Per-lib `codecov.yml` would allow lib-specific threshold overrides.
- 2026-06-30: No lib in the monorepo currently has a per-lib `.github/workflows/ci.yml` — sugar-stash would be the first to add one (N-1).
