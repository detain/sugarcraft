# Implementation Plan: `candy-files` Code Review Findings

**Library:** `candy-files` — TUI file manager port from Charmbracelet's `charmbracelet/charm-files`
**Plan Created:** 2026-06-30
**Status:** not-started

---

## Goal

Address all 25 code review findings in `candy-files` library, organized by priority and dependency order, ensuring improved code quality, security, performance, and testability.

---

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Use `bin2hex(random_bytes(16))` for trash paths | Cryptographically secure, universally unique, no PID predictability | Finding #6 security analysis |
| Extract `withMutatedState()` helper method | Eliminates 8× repeated 16-argument constructor calls, reduces bug risk | Finding #1 code complexity analysis |
| Split `dispatch()` into `dispatchNavigation()`, `dispatchTabManagement()`, `dispatchFileOperations()` | Single responsibility principle, improved testability | Finding #2 code complexity analysis |
| Split `resolveConfirm()` into `resolveTextEntryConfirm()` and `resolveSimpleConfirm()` | Conflating two different state machines (text-entry vs y/n) creates fragile code | Finding #3 code complexity analysis |
| Extract shared `processUndoAction()` method | `reverseAction()` and `redoAction()` have identical `match` structure | Finding #4 code complexity analysis |
| Add concurrency limiting to `copyManyAsync()` | Unlimited parallel promises can exhaust file descriptors on large copies | Finding #9 performance analysis |
| Use `React\Promise\Queue` for concurrency limiting | Native ReactPHP primitive, no external dependencies | `candy-files/src/AsyncOps.php:98-122` |
| Leverage `candy-async`'s `retry()` for resilient copies | Network-mounted and busy disks benefit from transparent retry | Finding #16 async pattern analysis |
| Add `CancellationToken` support for cancellation | `candy-async` already provides this pattern | Finding #15 missing feature |
| Derive `isLink`/`isDir` from `lstat` mode bits | Avoids 2 extra syscalls per file entry | Finding #10 performance analysis |
| Register shutdown function for trash cleanup | Temp directories accumulate indefinitely otherwise | Finding #11 resource leak |
| Add `sugarcraft/candy-async` to `require` section | Path repo exists but dependency is not explicit | Finding #21 compatibility issue |
| Use `Phar::canonicalize()` for path traversal validation | More robust than string-only checking | Finding #7 security analysis |

---

## Phase 1: Security Fixes

### 1.1 Fix predictable trash path (Finding #6)

**File:** `src/Manager.php:448–456`

**Severity:** MODERATE (security)

**What is expected:**
Replace predictable `microtime(true) . uniqid('', true)` with `bin2hex(random_bytes(16))` for trash path generation.

**Why this change should be done:**
Current trash path `sys_get_temp_dir() . '/candyfiles-trash-' . getmypid() . '/' . microtime(true) . '_' . uniqid('', true) . '_' . basename` is predictable. An attacker knowing PID could pre-create symlinks for symlink attacks.

**Conditions for success:**
- Trash path uses `bin2hex(random_bytes(16))` (32 hex chars, 128 bits of entropy)
- No code relies on predictable trash path format
- Unit tests pass with new format

**Related code locations:**
- `src/Manager.php:448-456` - `trashPath()` method
- `src/Manager.php:417-425` - `performDelete()` uses `trashPath()`
- `src/Manager.php:1104-1123` - `redoDelete()` uses `trashPath()`

**Implementation:**
```php
// Replace:
$trashName = sprintf('%s_%s_%s', microtime(true), uniqid('', true), $basename);

// With:
$trashName = sprintf('%s_%s', bin2hex(random_bytes(16)), $basename);
```

---

### 1.2 Add path traversal validation to performRename (Finding #7)

**File:** `src/Manager.php:728`

**Severity:** LOW (security)

**What is expected:**
After validating the new name string, also validate that the resolved destination path stays within bounds using `Phar::canonicalize()` or `realpath()`.

**Why this change should be done:**
Current check `str_contains($newName, '/') || str_contains($newName, '\\') || str_contains($newName, '..')` only validates the name string, not the full resolved path.

**Conditions for success:**
- `Phar::canonicalize()` used to normalize path before joining
- Full resolved path validated to stay within source directory
- Edge cases like `../etc/passwd` rejected

**Related code locations:**
- `src/Manager.php:709-750` - `performRename()` method
- `src/Manager.php:728` - Current path traversal check

**Implementation:**
```php
// After line 728, add validation:
$dst = Pane::join($pane->cwd, $newName);
$resolvedDst = \Phar::canonicalize($dst);
$resolvedCwd = \Phar::canonicalize($pane->cwd);
if (str_starts_with($resolvedDst, $resolvedCwd) === false) {
    // Path traversal detected - reject
    return $this->withActivePane(fn(Pane $p) =>
        Pane::open($p->cwd, $this->lister, $p->sort, $p->showHidden))
        ->withConfirm(ConfirmState::None, Lang::t('status.rename_failed', ['name' => $srcName]))
        ->withInputBuffer(null);
}
```

---

## Phase 2: Critical Performance & Resource Fixes

### 2.1 Add concurrency limiting to copyManyAsync (Finding #9)

**File:** `src/AsyncOps.php:98–122`

**Severity:** MODERATE (performance)

**What is expected:**
Add `$concurrency` parameter (default 4–8) to `copyManyAsync()` that uses a promise queue to limit concurrent operations.

**Why this change should be done:**
For 1000 files, firing 1000 concurrent promises causes severe performance degradation on spinning disks or network filesystems.

**Conditions for success:**
- Default concurrency of 4-8 established
- Promise queue implementation limits concurrent copies
- All existing tests pass
- New concurrency test added

**Related code locations:**
- `src/AsyncOps.php:98-122` - Current `copyManyAsync()` fires unlimited promises
- `candy-async/src/AsyncOps.php` - Has retry mechanism but no concurrency queue
- ReactPHP has `React\Promise\Queue` for this purpose

**Implementation:**
```php
public function copyManyAsync(array $map, int $concurrency = 4): PromiseInterface
{
    if ($map === []) {
        $deferred = new Deferred();
        $deferred->resolve([]);
        return $deferred->promise();
    }

    // Use a queue-based approach for concurrency control
    $promises = [];
    $results = [];
    $queue = new \SplQueue();
    $active = 0;
    $total = count($map);
    $keys = array_keys($map);

    // Implementation uses React\Promise\Queue pattern
    return $this->copyWithConcurrency($map, $concurrency);
}
```

---

### 2.2 Add trash directory cleanup on shutdown (Finding #11)

**File:** `src/Manager.php:448–456` (plus constructor/start)

**Severity:** MODERATE (resource leak)

**What is expected:**
Register a shutdown function via `register_shutdown_function()` to clean up the trash directory on exit.

**Why this change should be done:**
Trash directories at `sys_get_temp_dir() . '/candyfiles-trash-PID'` accumulate over time, consuming disk space.

**Conditions for success:**
- Shutdown handler registered in `Manager` constructor or `start()`
- Shutdown handler removes `$trashDir` recursively
- Works for both normal exit and fatal error scenarios

**Related code locations:**
- `src/Manager.php:48-68` - `__construct()` method
- `src/Manager.php:73-97` - `start()` factory method
- `src/Manager.php:906-924` - `removePath()` already exists for recursive deletion

**Implementation:**
```php
// In Manager::start() or __construct():
register_shutdown_function(function (): void {
    $trashDir = sys_get_temp_dir() . '/candyfiles-trash-' . getmypid();
    if (is_dir($trashDir)) {
        self::removePath($trashDir);
    }
});
```

---

### 2.3 Optimize FsLister to avoid extra syscalls (Finding #10)

**File:** `src/FsLister.php:32–33`

**Severity:** LOW (performance)

**What is expected:**
Derive `isLink` and `isDir` from `lstat()` mode bits instead of issuing separate `is_link()` and `is_dir()` calls.

**Why this change should be done:**
After `lstat()`, two additional syscalls are made per entry. File type can be extracted directly from mode bits.

**Conditions for success:**
- Mode bits used to determine `isLink` and `isDir`
- Tests pass with real filesystem verification

**Related code locations:**
- `src/FsLister.php:27-33` - Current implementation

**Implementation:**
```php
// Replace is_link() and is_dir() calls:
$mode = $stat['mode'];
$isLink = \S_ISLNK($mode);   // symlink check from mode bits
$isDir  = \S_ISDIR($mode);   // directory check from mode bits
```

---

## Phase 3: Code Complexity Refactoring

### 3.1 Extract withMutatedState() helper (Finding #1)

**File:** `src/Manager.php:235–250, 269–293, 760–777, 781–789, 847–854, 959–978, 1013–1020`

**Severity:** MODERATE (code quality)

**What is expected:**
Extract a private helper `withMutatedState(array $overrides): self` that applies selective overrides to constructor arguments, reducing repetition across 8 `with*` methods.

**Why this change should be done:**
Every `with*` method constructs `Manager` with 16 arguments, modifying only 1–2 values. This is error-prone and verbose.

**Conditions for success:**
- Helper method extracted
- All `with*` methods use the helper
- Tests pass without modification

**Related code locations:**
- `src/Manager.php:229-250` - `withActive()`
- `src/Manager.php:255-294` - `withActivePane()`
- `src/Manager.php:758-767` - `withConfirm()`
- `src/Manager.php:769-778` - `withStatus()`
- `src/Manager.php:780-789` - `withInputBuffer()`
- `src/Manager.php:845-854` - `withSearch()`
- `src/Manager.php:958-965` - `closeTab()`
- `src/Manager.php:973-980` - `switchTab()`
- `src/Manager.php:995-1002` - `duplicateTab()`
- `src/Manager.php:1013-1020` - `withUndoRedoStacks()`

**Implementation:**
```php
/**
 * Apply selective overrides to Manager state.
 * @param array<string, mixed> $overrides Key-value pairs of fields to override
 */
private function withMutatedState(array $overrides): self
{
    $args = [
        'left' => $this->left,
        'right' => $this->right,
        'activeIdx' => $this->activeIdx,
        'status' => $this->status,
        'confirm' => $this->confirm,
        'lister' => $this->lister,
        'searchQuery' => $this->searchQuery,
        'searchResults' => $this->searchResults,
        'searchCursor' => $this->searchCursor,
        'tabs' => $this->tabs,
        'tabIndex' => $this->tabIndex,
        'showTabBar' => $this->showTabBar,
        'undoStack' => $this->undoStack,
        'redoStack' => $this->redoStack,
        'pendingOpDest' => $this->pendingOpDest,
        'pendingOpType' => $this->pendingOpType,
        'inputBuffer' => $this->inputBuffer,
    ];

    return new self(...array_merge($args, $overrides));
}
```

---

### 3.2 Split dispatch() into smaller methods (Finding #2)

**File:** `src/Manager.php:166–227`

**Severity:** MODERATE (code quality)

**What is expected:**
Extract groups of match arms into private methods: `dispatchNavigation()`, `dispatchTabManagement()`, `dispatchFileOperations()`.

**Why this change should be done:**
60-line `match` statement handling navigation, tab management, search, undo/redo, confirm dialogs, copy/move/delete/rename all in one method creates high cognitive load.

**Conditions for success:**
- `dispatchNavigation()` handles Up/Down/k/j, Home/g/End/G, Enter/Right, Left/h, Tab
- `dispatchTabManagement()` handles t, Ctrl+w, Ctrl+Tab, Ctrl+Shift+Tab
- `dispatchFileOperations()` handles d, c, m, R, r
- `dispatch()` becomes a clean routing method

**Related code locations:**
- `src/Manager.php:166-227` - Current `dispatch()` method

**Implementation:**
```php
private function dispatch(KeyMsg $msg): self
{
    // Search mode intercepts all keys (before dispatch)
    if ($this->searchQuery !== null) {
        return $this->handleSearchKey($msg);
    }

    return match (true) {
        // Navigation group
        $msg->type === KeyType::Char && $msg->rune === '/'
            => $this->search(''),
        $msg->type === KeyType::Tab
            => $this->withActive(1 - $this->activeIdx),
        $this->isNavigationKey($msg)
            => $this->dispatchNavigation($msg),
        // Tab management
        $this->isTabManagementKey($msg)
            => $this->dispatchTabManagement($msg),
        // File operations
        $this->isFileOperationKey($msg)
            => $this->dispatchFileOperations($msg),
        // Undo/Redo
        $msg->type === KeyType::Char && $msg->rune === 'u'
        $msg->ctrl && $msg->rune === 'z'
            => $this->undo(),
        $msg->ctrl && $msg->rune === 'y'
            => $this->redo(),
        default => $this,
    };
}
```

---

### 3.3 Split resolveConfirm() into two methods (Finding #3)

**File:** `src/Manager.php:343–401`

**Severity:** MODERATE (code quality)

**What is expected:**
Split `resolveConfirm()` into `resolveTextEntryConfirm()` (handles `RenameSelected` state) and `resolveSimpleConfirm()` (handles y/n confirmations for Delete, Copy, Move).

**Why this change should be done:**
Method conflates two fundamentally different interaction models: text-entry sub-mode vs simple y/n confirmations.

**Conditions for success:**
- `resolveTextEntryConfirm()` handles Backspace, Enter, Escape, Char for RenameSelected
- `resolveSimpleConfirm()` handles y/n for DeleteSelected, CopySelected, MoveSelected
- `resolveConfirm()` becomes a clean routing method

**Related code locations:**
- `src/Manager.php:343-401` - Current `resolveConfirm()` method

**Implementation:**
```php
private function resolveConfirm(KeyMsg $msg): array
{
    // RenameSelected uses a text-entry sub-mode
    if ($this->confirm === ConfirmState::RenameSelected) {
        return $this->resolveTextEntryConfirm($msg);
    }

    // Single-key y/n confirmation for delete/copy/move
    return $this->resolveSimpleConfirm($msg);
}
```

---

### 3.4 Extract shared processUndoAction() (Finding #4)

**File:** `src/Manager.php:1079–1092` and `src/Manager.php:1177–1191`

**Severity:** MODERATE (code quality)

**What is expected:**
Extract to a shared `processUndoAction(UndoAction $action, bool $isReverse): void` that routes to the appropriate implementation method based on `$isReverse`.

**Why this change should be done:**
`reverseAction()` and `redoAction()` have identical `match` on `UndoActionType` with same case list, differing only in which implementation method they call.

**Conditions for success:**
- Single `processUndoAction()` method handles both reverse and redo
- `reverseAction()` delegates to `processUndoAction($action, true)`
- `redoAction()` delegates to `processUndoAction($action, false)`
- All implementation methods unchanged

**Related code locations:**
- `src/Manager.php:1079-1092` - `redoAction()`
- `src/Manager.php:1177-1191` - `reverseAction()`

**Implementation:**
```php
private function processUndoAction(UndoAction $action, bool $isReverse): int
{
    $errors = 0;
    match ($action->type) {
        UndoActionType::Delete => $errors = $isReverse
            ? $this->reverseDelete($action->items)
            : $this->redoDelete($action->items),
        UndoActionType::Move => $errors = $isReverse
            ? $this->reverseMove($action->items)
            : $this->redoMove($action->items),
        UndoActionType::Rename => $errors = $isReverse
            ? $this->reverseRename($action->items)
            : $this->redoRename($action->items),
        UndoActionType::Insert => $errors = $isReverse
            ? $this->reverseDelete($action->items)
            : $this->redoInsert($action->items),
        UndoActionType::Copy, UndoActionType::Modify, UndoActionType::Custom => $errors = 0,
    };
    return $errors;
}
```

---

## Phase 4: Async Pattern Improvements

### 4.1 Add copyWithRetry() using candy-async retry (Finding #16)

**File:** `src/AsyncOps.php`

**Severity:** MODERATE (async pattern)

**What is expected:**
Add `copyWithRetry(int $attempts = 3, float $delay = 0.5)` method that wraps `copyAsync()` with `AsyncOps::retry()` from `candy-async`.

**Why this change should be done:**
Network-mounted filesystems or heavily-loaded disks benefit from transparent retry with exponential backoff.

**Conditions for success:**
- `copyWithRetry()` method added to `AsyncOps`
- Uses `SugarCraft\Async\AsyncOps::retry()` with exponential backoff
- CancellationToken support for aborting retries
- Test verifies retry behavior

**Related code locations:**
- `candy-files/src/AsyncOps.php:27-42` - `copyAsync()` method
- `candy-async/src/AsyncOps.php:86-148` - `retry()` implementation
- `candy-async/src/CancellationToken.php` - CancellationToken class

**Implementation:**
```php
use SugarCraft\Async\AsyncOps;

/**
 * Copy with retry support for transient failures.
 */
public function copyWithRetry(
    string $src,
    string $dst,
    int $attempts = 3,
    float $baseBackoffSeconds = 0.5,
    ?\SugarCraft\Async\CancellationToken $token = null,
): PromiseInterface
{
    return AsyncOps::retry(
        fn() => $this->copyAsync($src, $dst),
        $attempts,
        $baseBackoffSeconds,
        $token,
    );
}
```

---

### 4.2 Add CancellationToken support to copyManyAsync (Finding #15)

**File:** `src/AsyncOps.php`

**Severity:** MODERATE (missing feature)

**What is expected:**
Add optional `CancellationToken` support to `copyManyAsync()` using `candy-async`'s cancellation pattern.

**Why this change should be done:**
Once `copyManyAsync()` is called, there is no mechanism to cancel it. `candy-async` provides `CancellationToken` support.

**Conditions for success:**
- `CancellationToken|null $token = null` parameter added
- Token checked between each copy operation
- Cancellation aborts remaining operations cleanly

**Related code locations:**
- `src/AsyncOps.php:98-122` - Current implementation
- `candy-async/src/AsyncOps.php:86-148` - CancellationToken usage in retry

**Implementation:**
```php
public function copyManyAsync(
    array $map,
    int $concurrency = 4,
    ?\SugarCraft\Async\CancellationToken $token = null,
): PromiseInterface
```

---

### 4.3 Add missing Loop import (Finding #17)

**File:** `src/AsyncOps.php:32`

**Severity:** MINOR (code quality)

**What is expected:**
Add `use React\EventLoop\Loop;` to imports and use `Loop::futureTick()` without the prefix.

**Why this change should be done:**
Currently uses `\React\EventLoop\Loop::futureTick(...)` which works but is inconsistent with other imports.

**Conditions for success:**
- Import added
- All `Loop::futureTick()` calls use short form
- Tests pass

**Related code locations:**
- `src/AsyncOps.php:32` - Current fully-qualified call
- `src/AsyncOps.php:55` - Also uses fully-qualified call

**Implementation:**
```php
// Add to imports at top of file:
use React\EventLoop\Loop;

// Change:
\React\EventLoop\Loop::futureTick(...)
// To:
Loop::futureTick(...)
```

---

## Phase 5: Missing Features

### 5.1 Add progress reporting for async copy (Finding #13)

**File:** `src/Manager.php:631–670`

**Severity:** MODERATE (missing feature)

**What is expected:**
Add a progress callback parameter or emit streaming results so the TUI can display a progress bar for large copy operations.

**Why this change should be done:**
`performCopyAsync()` performs asynchronous file copies without emitting progress events. Large directory copies give no user feedback until completion.

**Conditions for success:**
- Progress callback parameter added
- Each completed file triggers callback with count/total
- TUI can display progress bar during copy

**Related code locations:**
- `src/Manager.php:631-670` - `performCopyAsync()` method
- `src/AsyncOps.php:98-122` - `copyManyAsync()` could emit progress

**Implementation:**
```php
/**
 * @param callable(int $completed, int $total): void|null $onProgress
 */
private function performCopyAsync(callable $onProgress = null): array
{
    // ... build copiedItems ...

    $asyncOps = new AsyncOps();
    $cmd = Cmd::promise(static function () use ($asyncOps, $copiedItems, $names, $dst, $onProgress): \React\Promise\PromiseInterface {
        // Track progress and call $onProgress after each completion
    });
}
```

---

### 5.2 Document partial copy failure behavior (Finding #14)

**File:** `src/Manager.php:585–620`

**Severity:** MODERATE (missing feature)

**What is expected:**
Document the known limitation that if copying 5 files and file 3 fails, files 1–2 are already copied with no cleanup. Add clear documentation or transaction-like pattern.

**Why this change should be done:**
If copying 5 files and file 3 fails, files 1–2 are already copied and the undo stack records all 5 as "copied". The partial copy remains on disk.

**Conditions for success:**
- Clear documentation added to method PHPDoc
- User-facing message mentions potential for partial copies on failure
- Known limitation explicitly documented

**Related code locations:**
- `src/Manager.php:585-620` - `performCopy()` (sync version)
- `src/Manager.php:631-670` - `performCopyAsync()` (async version)

**Implementation:**
```php
/**
 * Async copy - runs through AsyncOps to avoid blocking the TUI event loop
 * during the potentially slow file I/O of a large recursive copy.
 *
 * Note: If a multi-file copy fails partway through, already-copied files
 * remain on disk with no automatic cleanup. This is a known limitation.
 * The undo stack records all intended copies (not just completed ones).
 *
 * @return array{0: self, 1: \Closure}
 */
private function performCopyAsync(): array
```

---

## Phase 6: Code Quality Fixes

### 6.1 Simplify dropLast() fallback (Finding #18)

**File:** `src/Manager.php:895–903`

**Severity:** LOW (code quality)

**What is expected:**
Use only `mb_substr($s, 0, -1, 'UTF-8')` since it is purpose-built for this operation, removing the regex fallback.

**Why this change should be done:**
The regex `/.$/us` with `/u` flag handles UTF-8 properly. The `??` fallback suggests uncertainty about regex behavior, and `mb_substr($s, 0, -1)` uses byte-based offset which is ambiguous.

**Conditions for success:**
- Only `mb_substr($s, 0, -1, 'UTF-8')` used
- Fallback removed
- Tests pass

**Related code locations:**
- `src/Manager.php:895-903` - Current implementation with regex fallback

**Implementation:**
```php
private static function dropLast(string $s): string
{
    if ($s === '') {
        return '';
    }
    return mb_substr($s, 0, -1, 'UTF-8');
}
```

---

### 6.2 Remove unused self-assignment in CopyCompletedMsg handling (Finding #19)

**File:** `src/Manager.php:116`

**Severity:** MINOR (code quality)

**What is expected:**
Replace `$msg = $msg;` self-assignment with a comment explaining why the variable is explicitly ignored.

**Why this change should be done:**
Self-assignment `$msg = $msg;` is intentional but unusual. The variable is not used because state is managed by the command itself.

**Conditions for success:**
- Comment added explaining state management
- Self-assignment removed

**Related code locations:**
- `src/Manager.php:112-121` - Current handling

**Implementation:**
```php
if ($msg instanceof CopyCompletedMsg) {
    // State already updated by the Cmd — variable intentionally unused
    // Refresh pane and finalize undo state
    return $this
        ->withActivePane(fn(Pane $p) => Pane::open($p->cwd, $this->lister, $p->sort, $p->showHidden))
        ->withConfirm(ConfirmState::None, '');
}
```

---

### 6.3 Extract magic numbers to named constants (Finding #20)

**File:** `src/Manager.php:40`

**Severity:** LOW (code quality)

**What is expected:**
Extract tab cycling literals (`"\t"`, `" "`) into named constants like `TAB_CYCLE_DELIMITER`.

**Why this change should be done:**
Various string literals used for tab cycling could use named constants for improved readability.

**Conditions for success:**
- `TAB_CYCLE_DELIMITER` constant added for `"\t"`
- Constants used in `dispatch()` method

**Related code locations:**
- `src/Manager.php:40` - `UNDO_LIMIT` constant exists
- `src/Manager.php:192` - Space used for selection toggle
- `src/Manager.php:217-222` - Tab key literals used

**Implementation:**
```php
private const TAB_CYCLE_DELIMITER = "\t";
private const SELECT_TOGGLE_KEY = ' ';
```

---

## Phase 7: Compatibility Fixes

### 7.1 Add candy-async to require section (Finding #21)

**File:** `candy-files/composer.json:61–66`

**Severity:** MINOR (compatibility)

**What is expected:**
Add `"sugarcraft/candy-async": "dev-master"` to the `require` section since the path repository exists but the dependency is not explicit.

**Why this change should be done:**
The `candy-async` path repository is listed in `repositories` but `sugarcraft/candy-async` is not listed in `require`. The library uses `candy-async` concepts but the dependency is not explicit, risking silent breakage.

**Conditions for success:**
- `sugarcraft/candy-async` added to `require` section
- `composer validate` passes
- Tests pass with new dependency

**Related code locations:**
- `candy-files/composer.json:32-37` - Current `require` section
- `candy-files/composer.json:60-66` - Path repo for candy-async exists

**Implementation:**
```json
{
    "require": {
        "php": ">=8.3",
        "sugarcraft/candy-core": "dev-master",
        "sugarcraft/candy-sprinkles": "dev-master",
        "sugarcraft/candy-async": "dev-master",
        "react/promise": "^3.3"
    }
}
```

---

### 7.2 Make Manager constructor private (Finding #22)

**File:** `src/Manager.php:48–66`

**Severity:** MODERATE (compatibility)

**What is expected:**
Consider making the constructor `private` and forcing callers through `Manager::start()` or `Manager::builder()` to ensure all state is consistently initialized.

**Why this change should be done:**
The constructor takes 16 parameters and is `public`, which could be misused with incorrect argument ordering. The builder exists but is not enforced.

**Conditions for success:**
- Constructor changed to `private`
- All call sites use `start()` or `builder()->build()`
- Tests pass (some tests may need updating)

**Related code locations:**
- `src/Manager.php:48-68` - Current public constructor
- `src/Manager.php:73-97` - `start()` factory exists
- `src/Manager.php:107-110` - `builder()` exists
- `tests/ManagerTest.php:34` - Test uses `Manager::start()`
- `src/Manager/ManagerBuilder.php:196-221` - `build()` calls constructor directly

**Implementation:**
```php
private function __construct(
    // ... same parameters ...
) {
    $this->lister = $lister ?? FsLister::lister();
}
```

**Note:** `ManagerBuilder::build()` calls the constructor directly, so it must be refactored to use `start()` or the constructor access adjusted.

---

## Phase 8: Test Coverage Improvements

### 8.1 Add testUndoCopyIsNoOp() (Finding #23)

**File:** `tests/ManagerTest.php`

**Severity:** LOW (test coverage)

**What is expected:**
Add a test that explicitly documents the known limitation that copy undo is a no-op.

**Why this change should be done:**
`testCanUndoAfterDelete` verifies delete undo. There is no test documenting the known limitation that copy undo is a no-op.

**Conditions for success:**
- `testUndoCopyIsNoOp()` test added
- Test verifies that undoing a copy does NOT delete the destination
- Comment documents this as an intentional behavior

**Related code locations:**
- `tests/ManagerTest.php:418-472` - `testCanUndoAfterDelete()` reference
- `src/Manager.php:1033-1042` - Copy undo returns no-op message

**Implementation:**
```php
public function testUndoCopyIsNoOp(): void
{
    // Copy operations are recorded for history but cannot be undone
    // because the original file is preserved. This is intentional behavior.
    $m = $this->start();
    // ... navigate and arm copy ...
    // ... confirm copy ...
    // ... undo ...
    // Verify destination file still exists
    $this->assertFileExists($destPath);
}
```

---

### 8.2 Add AsyncOpsTest failure scenario tests (Finding #24)

**File:** `tests/AsyncOpsTest.php`

**Severity:** LOW (test coverage)

**What is expected:**
Add test case that simulates a partial failure (e.g., source file disappears mid-copy) and verifies the resulting state is clean and predictable.

**Why this change should be done:**
Tests exist for success cases but there are no tests for what happens when a copy partially fails.

**Conditions for success:**
- Test simulates source file disappearing mid-copy
- Verifies partial results returned correctly
- No unhandled exceptions or inconsistent state

**Related code locations:**
- `tests/AsyncOpsTest.php:155-179` - `testCopyManyAsyncCopiesMultipleFiles()` reference
- `src/AsyncOps.php:98-122` - Current implementation

**Implementation:**
```php
public function testCopyManyAsyncPartialFailure(): void
{
    // Create some files, then delete one mid-operation
    $file1 = $this->tmpDir . '/file1.txt';
    $file2 = $this->tmpDir . '/file2.txt';
    file_put_contents($file1, 'content1');
    file_put_contents($file2, 'content2');

    $map = [
        $file1 => $this->tmpDir . '/copy1.txt',
        '/nonexistent/file' => $this->tmpDir . '/copy_fail.txt',
        $file2 => $this->tmpDir . '/copy2.txt',
    ];

    $promise = $this->ops->copyManyAsync($map);
    // ... wait for promise ...
    // Verify file1 and file2 copied, nonexistent failed
}
```

---

### 8.3 Add concurrent async operations integration test (Finding #25)

**File:** `tests/AsyncOpsTest.php`

**Severity:** LOW (test coverage)

**What is expected:**
Add an integration test that queues 50+ concurrent copy operations and verifies they complete without exhausting file descriptors or causing deadlock.

**Why this change should be done:**
No test verifies behavior when many async operations are queued simultaneously, particularly to validate that concurrency limits function correctly.

**Conditions for success:**
- Test queues 50+ concurrent copy operations
- All complete without error
- File descriptors not exhausted

**Related code locations:**
- `tests/AsyncOpsTest.php:149-179` - Reference test for copyManyAsync

**Implementation:**
```php
public function testCopyManyAsyncWithHighConcurrency(): void
{
    $count = 50;
    $map = [];
    for ($i = 0; $i < $count; $i++) {
        $src = $this->tmpDir . "/src_$i.txt";
        $dst = $this->tmpDir . "/dst_$i.txt";
        file_put_contents($src, "content $i");
        $map[$src] = $dst;
    }

    $promise = $this->ops->copyManyAsync($map);
    // ... wait and verify all complete ...
}
```

---

### 8.4 Document TOCTOU race as acceptable (Finding #8)

**File:** `src/FsLister.php:27–28`

**Severity:** LOW (documentation)

**What is expected:**
Add a code comment documenting that the TOCTOU race between `scandir` and `lstat` is an acceptable trade-off for a TUI file manager.

**Why this change should be done:**
Between `scandir` and `lstat`, files could be modified, deleted, or created. This is a fundamental race condition inherent to any filesystem listing.

**Conditions for success:**
- Comment added explaining acceptable trade-off
- Notes narrow window and limited impact

**Related code locations:**
- `src/FsLister.php:27-28` - Current implementation

**Implementation:**
```php
// Note: TOCTOU race between scandir and lstat is acceptable here.
// The window is narrow (~1 syscall) and impact is limited to stale
// display data that will refresh on next keypress.
$full = rtrim($path, '/') . '/' . $name;
$stat = @lstat($full);
```

---

## Phase 9: Architecture Duplication

### 9.1 Document duplication with candy-async (Finding #5)

**Files:** `src/AsyncOps.php` vs `candy-async/src/AsyncOps.php`

**Severity:** MINOR (architecture)

**What is expected:**
Document that `candy-files/AsyncOps` and `candy-async/AsyncOps` serve different purposes and should not be merged at this time.

**Why this change should be done:**
`candy-files/AsyncOps` provides file-specific operations (copy, move, rename). `candy-async/AsyncOps` provides generic async utilities (retry, debounce, throttle). Merging would add file-system concerns to the generic async library.

**Conditions for success:**
- Comment in `candy-files/src/AsyncOps.php` clarifying relationship
- `candy-async` noted as providing `retry()` for use by `copyWithRetry()`

**Related code locations:**
- `candy-files/src/AsyncOps.php` - File operations
- `candy-async/src/AsyncOps.php` - Generic utilities

**Implementation:**
```php
/**
 * Async file operations via React\Promise.
 *
 * For generic async utilities (retry, debounce, throttle), see
 * SugarCraft\Async\AsyncOps. This class provides file-specific
 * operations that build on those primitives.
 *
 * @uses SugarCraft\Async\AsyncOps::retry() for copyWithRetry()
 */
final class AsyncOps
```

---

## Summary: Implementation Priority Order

### High Priority (Security & Performance)
1. **1.1** Predictable trash path (#6) - Security issue
2. **2.1** copyManyAsync concurrency limiting (#9) - Prevents resource exhaustion

### Medium Priority (Code Quality & Missing Features)
3. **3.1** withMutatedState() helper (#1)
4. **3.2** dispatch() split (#2)
5. **3.3** resolveConfirm() split (#3)
6. **3.4** processUndoAction() extraction (#4)
7. **4.1** copyWithRetry() (#16)
8. **4.2** CancellationToken support (#15)
9. **7.2** Constructor privacy (#22)

### Low Priority (Incremental Improvements)
10. **2.2** Trash cleanup on shutdown (#11)
11. **2.3** FsLister syscall optimization (#10)
12. **6.1** dropLast() simplification (#18)
13. **6.2** Unused self-assignment removal (#19)
14. **6.3** Magic number constants (#20)
15. **8.1** testUndoCopyIsNoOp() (#23)
16. **8.2** AsyncOpsTest failure scenarios (#24)
17. **8.3** Concurrent async test (#25)
18. **8.4** TOCTOU documentation (#8)

### Minor (Compatibility & Documentation)
19. **1.2** Path traversal validation (#7)
20. **4.3** Loop import (#17)
21. **5.1** Progress reporting (#13)
22. **5.2** Partial failure documentation (#14)
23. **7.1** candy-async dependency (#21)
24. **9.1** Architecture documentation (#5)

---

## Notes

- **2026-06-30:** Plan created based on code review findings from `findings/candy-files.md`
- All changes must maintain backward compatibility unless explicitly breaking
- Tests must pass after each change
- Consider bundling related changes into PRs per the AGENTS.md guidelines (2-4 items per PR)
- Dependency order: Security fixes first, then performance, then refactoring, then features
