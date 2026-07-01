# Code Review Findings: `candy-files`

**Library:** `candy-files` — TUI file manager port from Charmbracelet's `charmbracelet/charm-files`
**Review Date:** 2026-06-29
**Reviewer:** Automated Code Review
**Severity Scale:** HIGH / MODERATE / LOW / MINOR

---

## Duplicated Logic / Code Complexity

### 1. Manager.php: Massive constructor argument lists repeated everywhere

**File:** `src/Manager.php`
**Lines:** 235–250, 269–293, 280–293, 760–777, 781–789, 847–854, 959–978, 1013–1020
**Severity:** MODERATE

Every `with*` method (`withActive`, `withActivePane`, `withConfirm`, `withStatus`, `withInputBuffer`, `withSearch`, `withUndoRedoStacks`) constructs a new `Manager` instance with the same ~16 arguments, modifying only 1–2 values per call. This pattern is error-prone and verbose.

**Recommendation:** Extract a private helper such as `withMutatedState(array $overrides): self` that applies selective overrides to the internal state array, or introduce a Named Constructor pattern to reduce repetition across the eight `with*` methods.

---

### 2. Manager.php: dispatch() method is 60 lines of match arms

**File:** `src/Manager.php:166–227`
**Severity:** MODERATE

The `dispatch()` method is a single 60-line `match` statement handling navigation, tab management, search, undo/redo, confirm dialogs, copy/move/delete/rename, and refresh — all in one method.

**Recommendation:** Extract groups into private methods: `dispatchNavigation()`, `dispatchTabManagement()`, `dispatchFileOperations()`. Each method would receive the `Manager` state it needs, reducing cognitive load and improving testability.

---

### 3. Manager.php: resolveConfirm() mixes two different state machines

**File:** `src/Manager.php:343–401`
**Severity:** MODERATE

This method conflates two fundamentally different flows:

1. **RenameSelected** — text-entry sub-mode with an input buffer
2. **Simple y/n confirmations** for Delete, Copy, and Move operations

These are different interaction models combined in one method.

**Recommendation:** Split into `resolveTextEntryConfirm()` (handles `RenameSelected` state) and `resolveSimpleConfirm()` (handles y/n confirmations for destructive operations).

---

### 4. reverseAction() and redoAction() have nearly identical structure

**File:** `src/Manager.php:1079–1092` and `src/Manager.php:1177–1191`
**Severity:** MODERATE

Both methods use an identical `match` on `UndoActionType` with the same case list, differing only in which implementation method they call.

**Recommendation:** Extract to a shared `processUndoAction(UndoAction $action, bool $isReverse): void` that routes to the appropriate implementation method based on `$isReverse`.

---

### 5. Duplicated AsyncOps with candy-async

**Files:** `src/AsyncOps.php` vs `candy-async/src/AsyncOps.php`
**Severity:** MODERATE

| Library | Provides |
|---------|----------|
| `candy-files/AsyncOps` | `copyAsync`, `moveAsync`, `renameAsync`, `copyManyAsync`, `moveManyAsync` |
| `candy-async/AsyncOps` | `withTimeout`, `retry`, `debounce`, `throttle` |

The file async operations in `candy-files` do not leverage `candy-async`'s `retry` mechanism for resilient copies.

**Recommendation:** Consider adding a `copyWithRetry()` that wraps `copyAsync()` with `AsyncOps::retry()` from `candy-async` to handle transient filesystem errors on network-mounted or busy disks.

---

## Security Issues

### 6. Predictable trash path allows symlink attacks

**File:** `src/Manager.php:448–456`
**Severity:** MODERATE

```php
private function trashPath(string $originalPath): ?string
{
    $trashDir = sys_get_temp_dir() . '/candyfiles-trash-' . getmypid();
    $basename = basename($originalPath);
    $trashName = sprintf('%s_%s_%s', microtime(true), uniqid('', true), $basename);
    $trashDir = $trashDir . '/' . $trashName;
    return $trashDir;
}
```

The trash path is predictable: `PID + microtime + uniqid + basename`. An attacker with knowledge of the PID could pre-create a symlink at the expected trash path before deletion, potentially causing deletion of an arbitrary file.

**Recommendation:** Use `tempnam()` or `sys_get_temp_dir() . '/' . bin2hex(random_bytes(16))` for truly unpredictable trash paths.

---

### 7. Path traversal check incomplete in performRename

**File:** `src/Manager.php:728`
**Severity:** LOW

```php
if (str_contains($newName, '/') || str_contains($newName, '\\') || str_contains($newName, '..')) {
```

This validates the new name string but does not confirm that the full resolved destination path remains within the intended directory.

**Recommendation:** Use `Phar::canonicalize()` or `realpath()` after joining the path components to validate that the final resolved path stays within bounds. Note that `\` is not the only path separator on Windows (PHP on Windows also accepts `/`), though the current check handles both.

---

### 8. TOCTOU race condition in FsLister

**File:** `src/FsLister.php:27–28`
**Severity:** LOW — Acceptable

```php
$full = rtrim($path, '/') . '/' . $name;
$stat = @lstat($full);
```

Between `scandir` and `lstat`, files could be modified, deleted, or created. This is a fundamental race condition inherent to any filesystem listing.

**Recommendation:** Document this as an acceptable trade-off for a TUI file manager. The window is narrow and the impact is limited to stale display data.

---

## Performance Issues

### 9. copyManyAsync() fires unlimited parallel operations

**File:** `src/AsyncOps.php:98–122`
**Severity:** MODERATE

```php
foreach ($map as $src => $dst) {
    $promises[] = $this->copyAsync($src, $dst);
}
return \React\Promise\all($promises);
```

For 1000 files, this fires 1000 concurrent promises. On spinning disks or network filesystems, this can cause severe performance degradation or resource exhaustion.

**Recommendation:** Add a `$concurrency` parameter (default `4–8`) that uses `React\Promise\Queue` or a similar concurrency limiter to cap the number of simultaneous copies.

---

### 10. FsLister makes multiple syscalls per file

**File:** `src/FsLister.php:32–33`
**Severity:** LOW

```php
$isLink = \is_link($full);
$isDir  = \is_dir($full);
```

After `lstat()`, two additional syscalls are made per entry. The file type can be extracted directly from the `lstat` result's mode bits (`S_IFMT`), avoiding the extra calls.

**Recommendation:** Derive `isLink` and `isDir` from the `lstat` mode bits instead of issuing separate syscalls.

---

## Memory / Resource Issues

### 11. Trash directory never cleaned up

**File:** `src/Manager.php:448–456`
**Severity:** MODERATE

Trash directories are created at `sys_get_temp_dir() . '/candyfiles-trash-PID'` but are never removed on exit. Over time, these accumulate and consume disk space.

**Recommendation:** Register a shutdown function via `register_shutdown_function()` to clean up the trash directory on exit, or use a dedicated trash directory that gets purged on startup.

---

### 12. AsyncOps creates a new Deferred for every operation

**File:** `src/AsyncOps.php:29–41` and similar
**Severity:** LOW

Each async operation creates a new `React\Promise\Deferred` object. For very high-frequency operations this could cause GC pressure, but for typical file operations the impact is negligible.

**Recommendation:** No immediate action needed. Monitor if profiling shows abnormal memory behavior in high-throughput scenarios.

---

## Missing Features / Improvements

### 13. No progress reporting for async copy

**File:** `src/Manager.php:631–670`
**Severity:** MODERATE

The `performCopyAsync()` method performs asynchronous file copies without emitting any progress events. Large directory copies give no user feedback until completion.

**Recommendation:** Add a progress callback parameter or emit streaming results so the TUI can display a progress bar for large copy operations.

---

### 14. No error recovery for partial copy failure

**File:** `src/Manager.php:585–620`
**Severity:** MODERATE

If copying 5 files and file 3 fails, files 1–2 are already copied and the undo stack records all 5 as "copied". The partial copy remains on disk with no cleanup.

**Recommendation:** Either wrap all copies in a transaction-like pattern (rollback on failure) or clearly document this as a known limitation — users should be aware that a failed multi-file copy may leave partial data on disk.

---

### 15. No way to cancel async copy mid-operation

**File:** `src/AsyncOps.php`
**Severity:** MODERATE

Once `copyManyAsync()` is called, there is no mechanism to cancel it. The `candy-async` library provides `CancellationToken` support which is not used here.

**Recommendation:** Add optional `CancellationToken` support to `copyManyAsync()`. The `candy-async` library already provides this pattern.

---

## Async Pattern Issues

### 16. AsyncOps does not leverage candy-async's retry mechanism

**Files:** `src/AsyncOps.php` vs `candy-async/src/AsyncOps.php`
**Severity:** MODERATE

`candy-async/AsyncOps::retry()` provides exponential backoff retry logic. The file copy operations in `candy-files` have no retry capability. Network-mounted filesystems or heavily-loaded disks could benefit from transparent retry.

**Recommendation:** Add a `copyWithRetry(int $attempts = 3, float $delay = 0.5)` method that wraps `copyAsync()` using `candy-async`'s `retry()` utility.

---

### 17. Loop::futureTick used but not imported

**File:** `src/AsyncOps.php:32`
**Severity:** MINOR

```php
\React\EventLoop\Loop::futureTick(...)
```

The `use React\EventLoop\Loop` import is missing. The fully-qualified name works but is inconsistent with other imports in the file.

**Recommendation:** Add `use React\EventLoop\Loop;` to the imports and call `Loop::futureTick(...)` without the prefix.

---

## Code Quality Issues

### 18. dropLast() fallback has edge cases

**File:** `src/Manager.php:895–903`
**Severity:** LOW

```php
private static function dropLast(string $s): string
{
    if ($s === '') {
        return '';
    }
    $out = preg_replace('/.$/us', '', $s);
    return $out ?? mb_substr($s, 0, -1);
}
```

The regex `/.$/us` with the `/u` flag should handle UTF-8 properly. The `??` fallback suggests uncertainty about the regex behavior. The fallback `mb_substr($s, 0, -1)` uses byte-based offset `-1` which is ambiguous for multibyte strings.

**Recommendation:** Use only `mb_substr($s, 0, -1, 'UTF-8')` since it is purpose-built for this operation, or validate the regex behavior thoroughly and remove the fallback entirely.

---

### 19. Unused self-assignment in CopyCompletedMsg handling

**File:** `src/Manager.php:116`
**Severity:** MINOR

```php
if ($msg instanceof CopyCompletedMsg) {
    $msg = $msg;  // unused for now, state already updated by the Cmd
```

The self-assignment is intentional but unusual. The variable is not used because state is managed by the command itself.

**Recommendation:** Replace with a comment explaining why the variable is explicitly ignored: `// state already updated by the Cmd`, or remove the branch if no future use is planned.

---

### 20. Magic numbers not named

**File:** `src/Manager.php:40`
**Severity:** LOW

```php
private const UNDO_LIMIT = 50;
```

The `UNDO_LIMIT` constant is properly named. However, various string literals such as `"\t"` and `" "` used for tab cycling could use named constants for improved readability.

**Recommendation:** Extract tab and space literals into named constants (e.g., `TAB_CYCLE_DELIMITER`) to make the cycling logic self-documenting.

---

## Compatibility Issues

### 21. candy-async dependency is path repo but not declared in requires

**File:** `candy-files/composer.json:61–66`
**Severity:** MINOR

```json
{
    "type": "path",
    "url": "../candy-async",
    "options": {"symlink": true}
}
```

The `candy-async` path repository is listed in `repositories` but `sugarcraft/candy-async` is not listed in the `require` section of `composer.json`. The library uses `candy-async` concepts but the dependency is not explicit.

**Recommendation:** Add `"sugarcraft/candy-async": "dev-master"` (or appropriate version) to the `require` section to make the dependency explicit and prevent silent breakage if the path repo is ever removed.

---

### 22. Manager constructor accepts 16 parameters

**File:** `src/Manager.php:48–66`
**Severity:** MODERATE

The constructor takes 16 parameters. While a builder (`Manager::start()` / `Manager::builder()`) exists, the raw constructor is `public` and could be misused with incorrect argument ordering.

**Recommendation:** Consider making the constructor `private` and forcing callers through `Manager::start()` or `Manager::builder()` to ensure all state is consistently initialized.

---

## Test Coverage Gaps

### 23. Tests do not verify undo of copy is a no-op

**File:** `tests/ManagerTest.php`
**Severity:** LOW

The test `testCanUndoAfterDelete` verifies delete undo. There is no test documenting the known limitation that copy undo is a no-op (the operation records the copy but undoing it does not remove the destination).

**Recommendation:** Add a test `testUndoCopyIsNoOp()` that explicitly documents this behavior, or add a comment in the existing test referencing this known limitation.

---

### 24. AsyncOpsTest does not test failure scenarios deeply

**File:** `tests/AsyncOpsTest.php`
**Severity:** LOW

Tests exist for success cases but there are no tests for what happens when a copy partially fails (some files copied, some failed).

**Recommendation:** Add a test case that simulates a partial failure (e.g., source file disappears mid-copy) and verifies the resulting state is clean and predictable.

---

### 25. No integration test for concurrent async operations

**File:** `tests/AsyncOpsTest.php`
**Severity:** LOW

There is no test that verifies behavior when many async operations are queued simultaneously, particularly to validate that concurrency limits (if added per recommendation #9) function correctly.

**Recommendation:** Add an integration test that queues 50+ concurrent copy operations and verifies they complete without exhausting file descriptors or causing deadlock.

---

## Summary by Severity

### HIGH
No issues identified that would cause bugs or security issues in normal use.

### MODERATE

| # | Category | Issue |
|---|----------|-------|
| 1 | Code Complexity | Manager constructor argument list repetition across 8 `with*` methods |
| 2 | Code Complexity | `dispatch()` method too large (60-line match statement) |
| 3 | Code Complexity | `resolveConfirm()` conflates two different state machines |
| 4 | Code Complexity | `reverseAction()` and `redoAction()` have duplicated structure |
| 6 | Security | Predictable trash path allows symlink attacks |
| 9 | Performance | `copyManyAsync()` fires unlimited parallel operations |
| 11 | Resource Leak | Trash directory never cleaned up |
| 13 | Missing Feature | No progress reporting for async copy |
| 14 | Missing Feature | No error recovery for partial copy failure |
| 15 | Missing Feature | No cancellation support for async operations |
| 16 | Async Pattern | AsyncOps does not leverage `candy-async` retry mechanism |
| 22 | Compatibility | Manager constructor is public with 16 parameters |

### LOW

| # | Category | Issue |
|---|----------|-------|
| 7 | Security | Path traversal check incomplete in `performRename` |
| 8 | Security | TOCTOU race in `FsLister` (documented as acceptable) |
| 10 | Performance | `FsLister` makes multiple syscalls per file |
| 18 | Code Quality | `dropLast()` fallback edge cases |
| 19 | Code Quality | Unused self-assignment in `CopyCompletedMsg` handling |
| 20 | Code Quality | Magic numbers not named (tab cycling literals) |
| 23 | Test Coverage | No test verifying copy undo is a no-op |
| 24 | Test Coverage | AsyncOpsTest missing failure scenario tests |
| 25 | Test Coverage | No integration test for concurrent async operations |

### MINOR

| # | Category | Issue |
|---|----------|-------|
| 5 | Architecture | Duplicated async concepts with `candy-async` |
| 12 | Resource | Deferred creation per operation (acceptable for file ops) |
| 17 | Code Quality | `Loop::futureTick` import missing |
| 21 | Compatibility | `candy-async` path repo not in `require` |

---

## Recommendations Priority Order

1. **High Priority:** Address the predictable trash path issue (#6) — this is a genuine security concern.
2. **High Priority:** Add concurrency limiting to `copyManyAsync()` (#9) — prevents resource exhaustion in production.
3. **Medium Priority:** Extract `withMutatedState()` helper (#1) — reduces maintenance burden and bug risk across 8 methods.
4. **Medium Priority:** Split `dispatch()` into smaller methods (#2) — improves testability and readability.
5. **Medium Priority:** Split `resolveConfirm()` (#3) — separates two distinct interaction models.
6. **Medium Priority:** Add trash cleanup on shutdown (#11) — prevents disk space leaks in long-running sessions.
7. **Medium Priority:** Make `Manager` constructor private (#22) — enforces consistent state initialization.
8. **Low Priority:** Document TOCTOU race as acceptable (#8) — prevents future confusion.
9. **Low Priority:** Add missing tests (#23–#25) — improves confidence in edge case handling.
