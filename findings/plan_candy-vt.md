---
status: not-started
phase: 1
updated: 2026-06-30
---

# Implementation Plan: candy-vt

## Goal

Address all 31 findings from the candy-vt code review, organized into phases by severity and dependency order, ensuring thread-safety, immutability contracts, API completeness, and reduced maintenance burden.

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Use eager initialization for static caches instead of locks | PHP 4096-byte string assignment is atomic enough for the actual use case; using `PHP_LOCK_SH` adds process-wide contention | `ref:candy-vt-audit` |
| Make `CellGrid::set()` return `void` instead of `self` | The method mutates in place — returning `$this` falsely advertises immutability; changing to `void` is the minimal fix | `src/CellGrid.php:66-79` |
| Add single source of truth for cursor visibility in ScreenHandler | `$mode->cursorVisible` and `$cursor->visible` must remain in sync; two independent sources of truth invite divergence | `src/Handler/ScreenHandler.php:36-37` + `src/Handler/ModeHandler.php:71-74` |
| Extract `Color::equalsOrBothNull()` utility to eliminate duplication | Null-safe color comparison is repeated identically in 3 places; DRY principle applies | `src/Sgr/Sgr.php:267-288`, `src/Cell/Cell.php:96-114` |
| Use array_slice-based row shifting for scroll performance | Current O(cols²) per row scrolled is unnecessary when PHP array slicing is O(cols) | `src/Parser/CsiHandlerImpl.php:370-386` |

---

## Phase 1: Critical Thread-Safety Fixes [PENDING]

### 1.1 Fix `Transitions::$table` thread-safe lazy init

**File:** `src/Parser/Transitions.php:27-37`

**What:** Replace the non-atomic `??=` lazy initialization with an eager class-level initialization using a static variable in `build()`.

```php
// Current problematic pattern (line 37):
return ord((self::$table ??= self::build())[($state << self::STATE_SHIFT) | $byte]);

// Fix: Initialize eagerly via a private static bool sentinel in build():
private static function build(): string
{
    static $initialized = false;
    if ($initialized) {
        return self::$table ?? self::buildInternal();
    }
    self::$table = self::buildInternal();
    $initialized = true;
    return self::$table;
}

private static function buildInternal(): string
{
    // existing build() body relocated here
}
```

**Why:** PHP's `??=` is not atomic for large string assignments on all platforms. In multi-threaded PHP (Swoole/ReactPHP), two concurrent first calls could cause double construction or torn reads.

**Severity:** High

**Conditions for Success:**
- No concurrent construction issues in threaded environments
- Static `$table` is guaranteed to be initialized exactly once
- `PHPStan level 9` passes with no new errors
- Existing tests in `ParserTest.php` continue to pass

**Investigation Notes:**
- `Transitions::get()` at line 35-37 uses `self::$table ??= self::build()`
- The `build()` method at line 50-238 constructs a 4096-byte string
- All 15 states × 256 bytes = 4096 entries, each packing `(action << 4) | state` into one byte
- `$table` is a private static `?string` declared at line 27

---

### 1.2 Fix `Theme::$fgIndexMap` / `Theme::$bgIndexMap` thread-safe lazy init

**File:** `src/Theme.php:31-35` + `src/Theme.php:295-307`

**What:** Apply the same eager initialization pattern to `Theme::$fgIndexMap` and `$bgIndexMap`. Since these are currently identity maps (index N→N for N in 0..15), the overhead is minimal and can be computed once at class load.

```php
// Fix: Initialize maps at class load time via a static constructor pattern:
private static function buildAnsiMaps(): void
{
    if (self::$fgIndexMap !== []) {
        return;
    }
    // Guard against race by checking both empty states together:
    if (self::$bgIndexMap === []) {
        self::$fgIndexMap = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15];
        self::$bgIndexMap = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15];
    }
}
```

Alternatively, initialize them as static properties directly in the class:

```php
private static array $fgIndexMap = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15];
private static array $bgIndexMap = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15];
```

**Why:** Same race condition as `Transitions::$table` — `??=` is not atomic for array assignments. Currently harmless (identity maps), but the pattern is fragile for future changes.

**Severity:** High

**Conditions for Success:**
- `Theme::fgIndex()` and `Theme::bgIndex()` return correct values
- No double-initialization in concurrent scenarios
- `ThemeTest.php` passes

**Investigation Notes:**
- `fgIndexMap` and `bgIndexMap` are declared at lines 32 and 35
- `buildAnsiMaps()` at line 295 guards with `if (self::$fgIndexMap !== [])` — but this is NOT atomic with the subsequent assignments
- Both arrays are identity maps (0→0, 1→1, ... 15→15) as shown at lines 301-306
- These are used by `Theme::fgIndex(int $slot)` at line 229 and `Theme::bgIndex(int $slot)` at line 241

---

## Phase 2: Immutability Contract Repairs [PENDING]

### 2.1 Make `CellGrid::set()` return `void` instead of `self`

**File:** `src/CellGrid.php:66-79`

**What:** Change the return type of `set()` from `self` to `void` since the method mutates `$this->grid` in place. This is the most minimal fix — removing the false promise of immutability without refactoring the method's internal design.

```php
// Current (lines 66-79):
public function set(int $row, int $col, Cell $cell): self
{
    if ($row < 0 || $row >= $this->rows || $col < 0 || $col >= $this->cols) {
        return $this;
    }
    $this->grid[$row][$col] = $cell;
    $this->minRow = min($this->minRow, $row);
    $this->maxRow = max($this->maxRow, $row);
    $this->minCol = min($this->minCol, $col);
    $this->maxCol = max($this->maxCol, $col);
    return $this;
}

// Fix: Change return type to void and remove the return statement:
public function set(int $row, int $col, Cell $cell): void
{
    if ($row < 0 || $row >= $this->rows || $col < 0 || $col >= $this->cols) {
        return;
    }
    $this->grid[$row][$col] = $cell;
    $this->minRow = min($this->minRow, $row);
    $this->maxRow = max($this->maxRow, $row);
    $this->minCol = min($this->minCol, $col);
    $this->maxCol = max($this->maxCol, $col);
}
```

**Why:** `set()` claims to return `$this` for fluent chaining, but it mutates the grid in place. Callers who chain `->set()` calls are modifying shared state. The `CellGrid` is designed to be mutable (used in the lightweight vcr renderer path), so changing to `void` accurately reflects its design.

**Severity:** High

**Conditions for Success:**
- `CellGrid` tests pass (no `set()` fluent chaining assertions)
- All callers of `CellGrid::set()` are updated (those using fluent chaining must be fixed)
- `CsiHandlerImpl::printChar()` at line 66 uses `set()` but doesn't chain — verified safe
- `CsiHandlerImpl::ed()` at line 247 uses `set()` — verified safe
- `CsiHandlerImpl::el()` at lines 269-278 uses `set()` — verified safe
- `CsiHandlerImpl::scrollUpOne()` at lines 378-379 uses `set()` — verified safe

**Investigation Notes:**
- `CellGrid::set()` at lines 66-79
- `CellGrid::resize()` at lines 86-103 creates a new instance via `new self($cols, $rows)` but then mutates `$clone->grid` directly (line 100) — this is actually the correct immutable pattern for `resize()` since it returns a new instance, but the inconsistency with `set()` is worth noting (issue #22 as well)
- `CellGrid` has a `clear()` method at line 81 that correctly returns `new self($this->cols, $this->rows)`

---

### 2.2 Fix `Transitions::build()` unused `$g` variable

**File:** `src/Parser/Transitions.php:52-53`

**What:** Remove the unused `$g` variable or refactor to make its intent clearer for static analysis.

```php
// Current (lines 52-53):
$g = State::Ground->value;
$t = str_repeat(self::pack(Action::None->value, $g), self::SIZE);

// Fix option 1: Remove $g and inline the value, adding a clarifying comment:
$t = str_repeat(self::pack(Action::None->value, State::Ground->value), self::SIZE);
// $g is intentionally inlined here — it represents the Ground state used as
// the default target for most transitions. The $set/$setMany closures receive
// state values as parameters, so $g is not needed as a closure capture.

// Fix option 2: Keep $g but add a suppression comment for phpstan:
/** @var int $g Initial Ground state for the transition table */
$g = State::Ground->value;
$t = str_repeat(self::pack(Action::None->value, $g), self::SIZE);
```

**Why:** `$g` is defined but closures `$set` and `$setMany` don't capture it (they receive state as parameters). `$g` IS used in `$setRange` calls and in the Anywhere transitions loop at lines 73-93. Static analyzers may warn about "used before assignment" because they don't track that `str_repeat` evaluates eagerly before any closure invocation.

**Severity:** Medium

**Conditions for Success:**
- `phpstan` passes with no new warnings about `$g`
- Existing `ParserTest.php` tests pass

**Investigation Notes:**
- Line 52: `$g = State::Ground->value;` — assigns Ground state value
- Line 53: `$t = str_repeat(self::pack(Action::None->value, $g), self::SIZE);` — `$g` is used here to initialize the entire table with "None" action, Ground state
- `$g` is used at line 75: `$setMany($state, [0x18, 0x1A, 0x99, 0x9A], Action::Execute->value, $g);` — passes Ground state as next state
- The closures `$set` and `$setMany` (lines 55-70) take `$state` as a parameter and don't use `$g` directly — they receive state via parameters

---

### 2.3 Fix duplicate color comparison logic

**File:** `src/Sgr/Sgr.php:267-288`, `src/Cell/Cell.php:96-114`

**What:** Add a static utility method `Color::equalsOrBothNull(Color|null $a, Color|null $b): bool` to `Color` and refactor both `Sgr::equals()` and `Cell::equals()` to use it.

```php
// In src/Color/Color.php, add:
public static function equalsOrBothNull(self|null $a, self|null $b): bool
{
    if ($a === null && $b === null) {
        return true;
    }
    if ($a === null || $b === null) {
        return false;
    }
    return $a->equals($b);
}
```

```php
// In src/Sgr/Sgr.php, refactor equals() (lines 267-288):
// Before:
$fgEqual = $thisFg === null && $otherFg === null
    ? true
    : ($thisFg === null || $otherFg === null ? false : $thisFg->equals($otherFg));

// After:
$fgEqual = Color::equalsOrBothNull($thisFg, $otherFg);
$bgEqual = Color::equalsOrBothNull($thisBg, $otherBg);
```

```php
// In src/Cell/Cell.php, refactor equals() (lines 96-114):
// Before: same verbose null-safe check pattern
// After:
$fgEqual = Color::equalsOrBothNull($this->foreground(), $other->foreground());
$bgEqual = Color::equalsOrBothNull($this->background(), $other->background());
```

**Why:** The same 7-line null-safe color comparison is copy-pasted in three places (`Sgr::equals()`, `Cell::equals()`, and implicitly in `Cell::foreground()`/`Cell::background()`). Extracting to `Color::equalsOrBothNull()` eliminates duplication and makes the semantics unambiguous.

**Severity:** Medium

**Conditions for Success:**
- `ColorTest.php` passes (add test for `equalsOrBothNull`)
- `SgrTest.php` passes
- `CellTest.php` passes
- No behavior change — only refactoring

**Investigation Notes:**
- `Sgr::equals()` at lines 235-289 in `src/Sgr/Sgr.php` — color comparison at lines 268-288
- `Cell::equals()` at lines 81-130 in `src/Cell/Cell.php` — color comparison at lines 96-114
- `Color::equals()` at line 73 in `src/Color/Color.php` — simple kind+value comparison
- `Color` is `final readonly class` — static methods are allowed

---

## Phase 3: State Divergence Fixes [PENDING]

### 3.1 Fix dual cursor visibility sources of truth in ScreenHandler

**File:** `src/Handler/ScreenHandler.php:36-37`, `src/Handler/ModeHandler.php:71-74`

**What:** Add an invariant assertion or consolidate to a single source of truth. The cleanest approach is to ensure `ScreenHandler` exposes only `$cursor->visible` as the authority and derives `$mode->cursorVisible` from it (or vice versa).

Looking at the code:
- `ModeHandler::setCursorVisible()` (lines 71-75) writes to both: `$h->mode->withCursorVisible($set)` AND `$h->cursor->withVisible($set)`
- `ScreenHandler::enterAltScreen()` (line 472) creates a new `Cursor` with `visible: $this->cursor->visible` but does NOT update `$mode->cursorVisible`
- `ScreenHandler::leaveAltScreen()` (line 487) restores `$this->savedCursor` (which carries its own `visible`) without updating `$mode->cursorVisible`

**Fix Options (choose one):**
- **Option A:** In `enterAltScreen()` and `leaveAltScreen()`, also sync the cursor's visible state to `$mode->cursorVisible`
- **Option B:** Add a comment noting that `$mode->cursorVisible` is derived from `$cursor->visible` and should only be updated via `ModeHandler::setCursorVisible()`
- **Option C:** Remove `$mode->cursorVisible` and derive it from `$cursor->visible` when needed (changes the `Mode` API)

Option B is the least invasive. Option C is the cleanest long-term.

```php
// Recommended: Option A — sync both in alt screen operations:

// In enterAltScreen() after line 472:
$this->cursor = new Cursor(visible: $this->cursor->visible);
$this->mode = $this->mode->withCursorVisible($this->cursor->visible); // ADD THIS LINE
$this->sgr = Sgr::empty();

// In leaveAltScreen() after restoration (line 487):
$this->cursor = $this->savedCursor ?? $this->cursor;
$this->mode = $this->mode->withCursorVisible($this->cursor->visible); // ADD THIS LINE
```

**Why:** Having two sources of truth (`$mode->cursorVisible` and `$cursor->visible`) that should always be equal invites divergence. If something writes to one without the other, they become inconsistent.

**Severity:** High

**Conditions for Success:**
- After any `enterAltScreen()`/`leaveAltScreen()`/`enterAltScreenNoSave()`/`leaveAltScreenNoSave()`/`enterAltScreenCursorOnly()`/`leaveAltScreenCursorOnly()` — `$handler->mode->cursorVisible === $handler->cursor->visible`
- After any `ModeHandler::setCursorVisible()` call — same invariant holds
- `ScreenHandlerTest.php` passes

**Investigation Notes:**
- `ScreenHandler` line 36: `public Cursor $cursor;`
- `ScreenHandler` line 38: `public Mode $mode;` — both are `public` for test access
- `ModeHandler::setCursorVisible()` at lines 71-75 writes to both simultaneously
- `enterAltScreen()` at lines 463-475: restores cursor with `visible: $this->cursor->visible` but doesn't sync mode
- `leaveAltScreen()` at lines 481-493: restores from `$savedCursor` but doesn't sync mode
- Similar issue in `enterAltScreenNoSave()` (line 500) and `leaveAltScreenNoSave()` (line 515) — these don't save cursor at all, so cursor-visible sync is less critical there
- `enterAltScreenCursorOnly()` (line 532) and `leaveAltScreenCursorOnly()` (line 547) — these specifically save/restore cursor only

---

## Phase 4: API Completeness Additions [PENDING]

### 4.1 Add `Terminal::focusEvents()` accessor

**File:** `src/Terminal/Terminal.php`

**What:** Add a public accessor method to `Terminal\Terminal` for accumulated focus events.

```php
// Add to src/Terminal/Terminal.php after line 116 (after clipboardEvents()):

/**
 * Focus events recorded when DECSET 1004 is active (CSI I / CSI O).
 *
 * @return list<FocusInMsg|FocusOutMsg>
 */
public function focusEvents(): array
{
    return $this->handler->focusEvents;
}
```

**Why:** `ScreenHandler::$focusEvents` is a public array but `Terminal` provides no accessor for it. Consumers using the full Terminal path must access `$vt->handler->focusEvents` directly, breaking encapsulation.

**Severity:** Missing Feature (API)

**Conditions for Success:**
- `FocusEventTest.php` (existing) passes
- New test: `Terminal::focusEvents()` returns accumulated focus events after CSI I/O sequences

**Investigation Notes:**
- `ScreenHandler::$focusEvents` is declared at line 55 as `public array $focusEvents = []`
- Focus events are appended at lines 186 (FocusInMsg) and 190 (FocusOutMsg) in `csiDispatch()`
- `FocusInMsg` and `FocusOutMsg` are value objects in `src/Msg/`

---

### 4.2 Add `Scrollback::clear()` method

**File:** `src/Screen/Scrollback.php`

**What:** Add a `clear(): void` method to reset the scrollback ring buffer.

```php
// Add to src/Screen/Scrollback.php after line 57 (after push()):

/**
 * Clear all entries from the scrollback buffer.
 */
public function clear(): void
{
    $this->head = 0;
    $this->tail = 0;
    $this->count = 0;
    $this->rows = array_fill(0, $this->maxSize, null);
}
```

**Why:** Currently the only way to clear scrollback is via `Terminal::withScrollbackSize()` (which creates a new `Scrollback`) or via `CSI 3 J` (which erases scrollback through `EraseHandler`). There's no direct programmatic API.

**Severity:** Missing Feature (API)

**Conditions for Success:**
- `ScrollbackTest.php` (existing) passes
- New test: `Scrollback::clear()` empties the buffer, `count()` returns 0, `all()` returns `[]`
- Existing scrollback functionality (push, at, all, count) still works after clear

**Investigation Notes:**
- `Scrollback` constructor at line 32 initializes `$rows` with `array_fill(0, $maxSize, null)`
- `push()` at lines 46-58 manages the ring buffer logic
- `all()` at lines 65-82 iterates from oldest to newest

---

### 4.3 Add `flush()` method to root `Terminal`

**File:** `src/Terminal.php`

**What:** Add a `flush(): void` method to the root `SugarCraft\Vt\Terminal` class.

```php
// Add to src/Terminal.php after line 70 (after feed()):

/**
 * Force any in-flight string sequence (OSC/DCS) to dispatch.
 *
 * Useful when consuming partial streams or when forcing dispatch
 * before a snapshot without waiting for a proper terminator.
 */
public function flush(): void
{
    $this->parser->flush();
}
```

**Why:** The full `Terminal\Terminal` has `flush()` (line 74-77) but the root `Terminal` (vcr renderer path) does not. Consumers of the vcr path who feed partial OSC sequences cannot force dispatch.

**Severity:** Missing Feature (API)

**Conditions for Success:**
- `TerminalTest.php` passes (existing tests)
- New test: After feeding partial OSC sequence and calling `flush()`, the title is captured

**Investigation Notes:**
- `Terminal\Terminal::flush()` at `src/Terminal/Terminal.php:74-77` calls `$this->parser->flush()`
- `Parser::flush()` at `src/Parser/Parser.php:65-77` dispatches any in-flight OSC/DCS states
- Root `Terminal` at `src/Terminal.php` has `feed(string $bytes)` at line 65 but no `flush()`

---

### 4.4 Add `enableAltScreen()` / `disableAltScreen()` to root `Terminal`

**File:** `src/Terminal.php`

**What:** Add alt-screen entry points to the root `Terminal` class returning a new instance (immutable style).

```php
// Add to src/Terminal.php after windowTitle() at line 91:

/**
 * Return a new Terminal with alt screen mode enabled (DEC 1049).
 * Not implemented in the vcr renderer path — provided for API parity.
 *
 * @throws \LogicException always — alt screen requires the full emulator path
 */
public function enableAltScreen(): never
{
    throw new \LogicException('Alt screen requires the full Terminal\Terminal path');
}

/**
 * Return a new Terminal with alt screen mode disabled.
 * Not implemented in the vcr renderer path — provided for API parity.
 *
 * @throws \LogicException always — alt screen requires the full emulator path
 */
public function disableAltScreen(): never
{
    throw new \LogicException('Alt screen requires the full Terminal\Terminal path');
}
```

Or if implementing properly (requiring changes to HandlerAdapter and CsiHandlerImpl):

```php
public function enableAltScreen(): self
{
    throw new \LogicException('Alt screen not implemented in the vcr renderer path');
}

public function disableAltScreen(): self
{
    throw new \LogicException('Alt screen not implemented in the vcr renderer path');
}
```

**Why:** The root `Terminal` (vcr renderer path) has no alt-screen entry points while the full `Terminal\Terminal` has `enableAltScreen()` / `disableAltScreen()`. This creates an API asymmetry that could confuse consumers migrating between paths.

**Severity:** High (API Parity)

**Conditions for Success:**
- `TerminalTest.php` passes (throws exceptions as expected)
- Documentation clarifies that vcr path doesn't support alt screen

**Investigation Notes:**
- Root `Terminal` at `src/Terminal.php` has no alt-screen methods
- Full `Terminal\Terminal` at `src/Terminal/Terminal.php:205-217` has `enableAltScreen()` and `disableAltScreen()`
- Root `Terminal` uses `CsiHandlerImpl` which has no alt screen support

---

## Phase 5: Incomplete Feature Fixes [PENDING]

### 5.1 Fix `Terminal::resize()` to also resize saved alt buffer

**File:** `src/Terminal/Terminal.php:118-124`

**What:** In `Terminal::resize()`, when in alt screen mode, also resize the saved main buffer.

```php
// Current (lines 118-124):
public function resize(int $cols, int $rows): void
{
    if ($cols < 1 || $rows < 1) {
        throw new \InvalidArgumentException('cols and rows must be >= 1');
    }
    $this->handler->buffer = $this->handler->buffer->resize($cols, $rows);
}

// Fix: Check if saved buffer exists and resize it too:
public function resize(int $cols, int $rows): void
{
    if ($cols < 1 || $rows < 1) {
        throw new \InvalidArgumentException('cols and rows must be >= 1');
    }
    $this->handler->buffer = $this->handler->buffer->resize($cols, $rows);
    // Real terminals resize both buffers — do the same for the saved main buffer
    if ($this->handler->savedBuffer !== null) {
        $this->handler->savedBuffer = $this->handler->savedBuffer->resize($cols, $rows);
    }
}
```

**Why:** Real terminals resize both the active and saved (main) buffers when in alt screen mode. Currently only the active buffer is resized, so when leaving alt mode the restored buffer would have different dimensions than the current screen.

**Severity:** High

**Conditions for Success:**
- `TerminalTest.php` (or a new test) verifies that resize in alt mode preserves saved buffer dimensions
- Existing resize tests still pass

**Investigation Notes:**
- `Terminal::resize()` at `src/Terminal/Terminal.php:118-124`
- `ScreenHandler::$savedBuffer` is `private ?Buffer` at line 63
- `Buffer::resize()` at `src/Buffer/Buffer.php:48-62` already returns a new instance — it can be used directly
- CALIBER_LEARNINGS.md line 149-151 already documents this issue as known

---

## Phase 6: Dead Code & Performance [PENDING]

### 6.1 Remove redundant `array_values(array_map('intval', ...))` in SgrHandler

**File:** `src/Parser/CsiHandlerImpl.php:175`

**What:** Remove the redundant `array_values(array_map('intval', ...))` call in `sgr()`.

```php
// Current (line 175):
[$this->fg, $this->bg, $this->attrs, $i] = $this->applySgrParam($p, array_values(array_map('intval', $params)), $i);

// Fix — the params are already integers from the parser, so just pass them directly:
[$this->fg, $this->bg, $this->attrs, $i] = $this->applySgrParam($p, $params, $i);
```

Note: `applySgrParam()` at line 183 receives `$params` and passes `$params[$i]` to various places — since the input is already `list<int>`, no conversion is needed.

**Why:** `$params` already contains integers (the parser stores them as ints). The `array_values(array_map('intval', ...))` is dead code that rebuilds the array with sequential keys and adds per-frame allocation overhead.

**Severity:** Medium

**Conditions for Success:**
- `CsiHandlerImplTest.php` passes
- No behavior change — identical values

**Investigation Notes:**
- Line 170: `$p = (int) $params[$i];` — already casting to int
- The `params` in `csiDispatch` come from `Parser::$params` which are built via `param()` method at `Parser.php:168-196` — they are already integers
- `array_values(array_map('intval', $params))` at line 175 rebuilds the array

---

### 6.2 Optimize `Screen::diff()` iteration to overlapping region

**File:** `src/Screen/Screen.php:56-73`

**What:** Change `diff()` to iterate over the overlapping region plus extra cells, rather than always iterating to max dimensions.

```php
// Current (lines 56-73):
public function diff(self $other): array
{
    $changes = [];
    $maxRows = max($this->rows, $other->rows);
    $maxCols = max($this->cols, $other->cols);
    for ($r = 0; $r < $maxRows; $r++) {
        for ($c = 0; $c < $maxCols; $c++) {
            $a = $this->cell($r, $c);
            $b = $other->cell($r, $c);
            if (!$a->equals($b)) {
                $changes[] = ['row' => $r, 'col' => $c, 'prev' => $a, 'next' => $b];
            }
        }
    }
    return $changes;
}

// Fix: Iterate only the overlapped region for both screens, then handle extra rows/cols separately:
public function diff(self $other): array
{
    $changes = [];
    $minRows = min($this->rows, $other->rows);
    $minCols = min($this->cols, $other->cols);

    // Compare overlapped region
    for ($r = 0; $r < $minRows; $r++) {
        for ($c = 0; $c < $minCols; $c++) {
            $a = $this->cell($r, $c);
            $b = $other->cell($r, $c);
            if (!$a->equals($b)) {
                $changes[] = ['row' => $r, 'col' => $c, 'prev' => $a, 'next' => $b];
            }
        }
    }

    // Extra rows in $this but not $other
    for ($r = $minRows; $r < $this->rows; $r++) {
        for ($c = 0; $c < $this->cols; $c++) {
            $a = $this->cell($r, $c);
            $b = Cell::empty();
            if (!$a->equals($b)) {
                $changes[] = ['row' => $r, 'col' => $c, 'prev' => $a, 'next' => $b];
            }
        }
    }

    // Extra cols in $this but not $other (for overlapping rows)
    for ($r = 0; $r < $minRows; $r++) {
        for ($c = $minCols; $c < $this->cols; $c++) {
            $a = $this->cell($r, $c);
            $b = Cell::empty();
            if (!$a->equals($b)) {
                $changes[] = ['row' => $r, 'col' => $c, 'prev' => $a, 'next' => $b];
            }
        }
    }

    // Same pattern for $other extra rows/cols...

    return $changes;
}
```

**Why:** For a 100×30 screen diffed against an 80×24 screen, current code iterates 3000 cells when only 1920 (overlapping) + extras need comparison. Reduces unnecessary iterations.

**Severity:** Medium

**Conditions for Success:**
- `ScreenTest.php` passes (existing `diff()` tests)
- New test: diff of different-sized screens produces same results as before

**Investigation Notes:**
- `Screen::diff()` at lines 56-73
- `Screen::cell()` at lines 43-49 returns `Cell::empty()` for out-of-bounds coordinates
- Extra rows/cols in either screen should show as "changed" (from content to empty or empty to content)

---

### 6.3 Fix `Theme::cubePalette()` computed twice per theme

**File:** `src/Theme.php:51-57` + theme factory methods (lines 90-224)

**What:** Cache the cube palette as a static so it's only computed once per process.

```php
// Add a static cache to cubePalette():
private static function cubePalette(): array
{
    static $cube = null;
    return $cube ??= self::computeCubePalette();
}

private static function computeCubePalette(): array
{
    $cube = [];
    for ($r = 0; $r < 6; $r++) {
        for ($g = 0; $g < 6; $g++) {
            for ($b = 0; $b < 6; $b++) {
                $cube[] = (($r ? $r * 40 + 55 : 0) << 16) | (($g ? $g * 40 + 55 : 0) << 8) | ($b ? $b * 40 + 55 : 0);
            }
        }
    }
    return $cube;
}
```

**Why:** `Theme::cubePalette()` is called twice per theme instantiation (once in `defaultPalette()` and once in each factory method's palette array). The 216-element array is the same every time — caching eliminates ~432 unnecessary loop iterations per theme instantiation.

**Severity:** Low

**Conditions for Success:**
- `ThemeTest.php` passes
- New test: verify same cube palette returned across multiple theme instantiations

**Investigation Notes:**
- `cubePalette()` at lines 62-73 generates 216 colors (6×6×6)
- `defaultPalette()` at line 56 calls `self::cubePalette()` 
- Each factory (tokyoNight at line 112, dracula at line 138, etc.) also calls `self::cubePalette()` in the palette array

---

### 6.4 Optimize `CsiHandlerImpl::scrollUpOne()` to O(cols) per row

**File:** `src/Parser/CsiHandlerImpl.php:370-386`

**What:** Use `array_slice`-based row shifting instead of nested loops.

```php
// Current (lines 370-386):
private function scrollUpOne(): void
{
    $top = $this->scrollTop;
    $bottom = $this->scrollBottom;
    $cols = $this->grid->cols;

    for ($r = $top; $r < $bottom; $r++) {
        for ($c = 0; $c < $cols; $c++) {
            $next = $this->grid->get($r + 1, $c);
            $this->grid->set($r, $c, $next);
        }
    }

    for ($c = 0; $c < $cols; $c++) {
        $this->grid->set($bottom, $c, Cell::empty());
    }
}

// Fix: Use array_slice on the grid rows directly (since grid is a 2D array):
private function scrollUpOne(): void
{
    $top = $this->scrollTop;
    $bottom = $this->scrollBottom;

    // Shift rows up by one position within the scroll region
    // (O(cols) array copy instead of O(cols²) cell-by-cell copy)
    $grid = &$this->grid->grid; // Access the private grid property
    for ($r = $top; $r < $bottom; $r++) {
        $grid[$r] = $grid[$r + 1];
    }
    $grid[$bottom] = array_fill(0, $this->grid->cols, Cell::empty());

    // Note: This directly modifies the grid array — requires CellGrid to expose
    // the grid array or use a different approach. The alternative is to use
    // Buffer-style put() approach.
}
```

**Alternative approach** — since `CellGrid` is used in the vcr renderer path and `CellGrid::grid` is private, the most practical fix is to refactor `CsiHandlerImpl` to use a `Buffer`-style grid:

**Better approach for vcr path:** Refactor `CsiHandlerImpl` to use `Buffer` (from `candy-buffer`) instead of `CellGrid` for the scroll operations, or add a `CellGrid::scrollRegionUp()` method that does the efficient shift.

```php
// In CellGrid, add:
public function scrollRegionUp(int $top, int $bottom): void
{
    // Shift rows up — O(cols) per row instead of O(cols²)
    for ($r = $top; $r < $bottom; $r++) {
        $this->grid[$r] = $this->grid[$r + 1];
    }
    $this->grid[$bottom] = array_fill(0, $this->cols, Cell::empty());
    // Invalidate dirty region
    $this->minRow = $top;
    $this->maxRow = $bottom;
    $this->minCol = 0;
    $this->maxCol = $this->cols - 1;
}
```

Then in `scrollUpOne()`:
```php
private function scrollUpOne(): void
{
    $this->grid->scrollRegionUp($this->scrollTop, $this->scrollBottom);
}
```

**Why:** Current `scrollUpOne()` calls `get()` and `set()` N×cols×2 times per scroll. For large scroll regions, this is O(cols²) per row scrolled. Using array slicing reduces to O(cols) per row.

**Severity:** Low

**Conditions for Success:**
- `CsiHandlerImplTest.php` passes (scroll-related tests)
- Performance: benchmark before/after for large scroll operations

**Investigation Notes:**
- `scrollUpOne()` at lines 370-386 in `src/Parser/CsiHandlerImpl.php`
- `CellGrid::get()` at lines 58-64 — returns `Cell::empty()` for OOB
- `CellGrid::set()` at lines 66-79 — mutates in place (see issue #1)
- `scrollUp()` at lines 357-368 calls `scrollUpOne()` in a loop

---

## Phase 7: Documentation & Comments [PENDING]

### 7.1 Clarify `HandlerAdapter::printChar()` comment

**File:** `src/Parser/HandlerAdapter.php:23-30`

**What:** Improve the comment explaining Latin-1 pass-through.

```php
// Current (lines 23-26):
// Accept any character except C0 controls (0x00-0x1F) and DEL (0x7F).
// This passes through Latin-1 printables (0xA0-0xFF) and all valid
// UTF-8 lead bytes (>= 0xC2) for multi-byte runes.

// Fix: Add more context about why Latin-1 is passed through:
/**
 * Print a character to the grid.
 *
 * Accepts any printable character:
 * - ASCII printable (0x20-0x7E)
 * - Latin-1 printable (0xA0-0xFF) — passed through as single-byte UTF-8
 * - UTF-8 lead bytes (0xC2-0xF4) — multi-byte runes assembled by Parser
 *
 * Rejects: C0 controls (0x00-0x1F), DEL (0x7F), and invalid UTF-8 lead bytes.
 * C0 controls are handled by execute() instead — they move the cursor,
 * change modes, or control flow rather than printing characters.
 */
public function printChar(string $rune): void
```

**Why:** The current comment doesn't fully explain the Latin-1 pass-through rationale — that Latin-1 bytes (0xA0-0xFF) are valid UTF-8 when interpreted as single-byte sequences representing characters U+00A0-U+00FF.

**Severity:** Low

**Conditions for Success:**
- Code readability improvement — no functional change
- Existing tests pass

---

### 7.2 Document `Terminal::__clone()` parse state loss

**File:** `src/Terminal/Terminal.php:126-130`

**What:** Add a doc comment noting that cloning resets parse state.

```php
/**
 * Clone the terminal with a fresh parser.
 *
 * After cloning, the new Terminal has a new Parser in Ground state.
 * Any in-flight parse state (partial UTF-8 rune, partial CSI/OSC
 * sequence) is lost. This is the correct behavior for a snapshot
 * clone — use this when capturing terminal state for later comparison.
 *
 * @return void
 */
public function __clone(): void
{
    $this->handler = clone $this->handler;
    $this->parser = new Parser($this->handler);
}
```

**Why:** `with*()` clone operations reset parse state implicitly (via `new Parser()`). This is expected for immutable-with*() builders but worth documenting so callers aren't surprised when in-flight parse data is lost on clone.

**Severity:** Low

**Conditions for Success:**
- Code readability improvement
- No functional change

---

### 7.3 Document `HandlerAdapter::oscDispatch()` scope limitation

**File:** `src/Parser/HandlerAdapter.php:79-84`

**What:** Add a doc comment noting that only OSC 0/1/2 (window title) are handled.

```php
/**
 * Handle OSC dispatch for window title (OSC 0, 1, 2).
 *
 * This root HandlerAdapter only handles OSC 0, 1, and 2 which set
 * the window title. Other OSC sequences (OSC 4 palette, OSC 8
 * hyperlink, OSC 52 clipboard) are silently ignored.
 *
 * For full OSC support, use the full ScreenHandler path via
 * SugarCraft\Vt\Terminal\Terminal.
 *
 * @param string $data OSC payload after the command number and semicolon
 */
public function oscDispatch(string $data): void
```

**Why:** Consumers using the root `Terminal` for testing ANSI output could be surprised that hyperlinks/palette/clipboard sequences are silently ignored. Documentation clarifies this is intentional for the lightweight vcr path.

**Severity:** Medium

**Conditions for Success:**
- No functional change
- `OscHandlerImplTest.php` passes

---

### 7.4 Standardize factory method naming

**File:** `src/Terminal.php:45` vs `src/Terminal/Terminal.php:51-62`

**What:** Either add a `create()` alias to root `Terminal` for consistency, or deprecate `create()` in `Terminal\Terminal` and rely only on `new()`. The recommendation is Option B — keep only `new()` everywhere.

```php
// In src/Terminal/Terminal.php (lines 56-62):
// Remove @deprecated create() method, keep only new():
public static function new(int $cols = 80, int $rows = 24, ?int $scrollbackSize = null): self
{
    return new self($cols, $rows, null, null, null, $scrollbackSize ?? 1000);
}
```

And in `src/Terminal.php` (line 45):
```php
public static function new(int $cols = 80, int $rows = 24, ?Theme $theme = null): self
```

Both classes now have consistent `new()` factory — the only factory method.

**Why:** The root `Terminal` has `new()` only. `Terminal\Terminal` has `new()` (canonical) + `@deprecated create()` (for BC). For consistency, consolidate to `new()` only. The `@deprecated create()` was likely added for compatibility with existing code — removing it now (pre-1.0) is acceptable.

**Severity:** Low

**Conditions for Success:**
- All callers updated (search for `Terminal::create(` in codebase)
- Existing tests pass

---

## Phase 8: Missing Features — Async Design [PENDING]

### 8.1 Document DCS dispatch as deferred to v2

**File:** `src/Parser/HandlerAdapter.php:86-88`, `src/Parser/OscHandlerImpl.php:21-23`

**What:** Add prominent doc comments noting DCS dispatch is not implemented and is targeted for v2.

```php
// In src/Parser/HandlerAdapter.php:
/**
 * DCS (Device Control String) dispatch — not implemented.
 *
 * @todo v2 — implement DCS dispatch for device control sequences
 */
public function dcsDispatch(int $final, array $params, int $prefix, int $intermediate, string $data): void
{
    // No-op — DCS dispatch is scoped to later slices (v2).
}
```

**Why:** DCS-based sequences (e.g., terminal identification, device status) are rarely used in typical terminal output. Documenting this as intentionally deferred prevents confusion.

**Severity:** Missing Feature (Scope)

**Conditions for Success:**
- No functional change — just documentation

---

### 8.2 Document async streaming limitation

**File:** `src/Parser/Parser.php` (feed method) and `src/Terminal.php`

**What:** Add doc comments noting that `feed()` is synchronous only and async support is a future consideration.

```php
// In src/Parser/Parser.php, feed() method:
/**
 * Feed a chunk of bytes through the state machine. Safe to call
 * repeatedly; in-flight sequences carry across calls.
 *
 * @note This is a synchronous, blocking method. For async streaming
 * input (e.g., from a PTY in a ReactPHP event loop), wrap feed()
 * in an async scheduler or use a generator-based approach.
 *
 * @param string $bytes Raw bytes to parse
 */
public function feed(string $bytes): void
```

**Why:** The finding notes that `candy-vt` cannot currently be used in a streaming async PTY pipeline without additional async wrapping. Documenting this limitation helps consumers understand the current scope.

**Severity:** Async Pattern Improvement

**Conditions for Success:**
- No functional change

---

## Phase 9: Duplication & Maintenance Burden [PENDING]

### 9.1 Extract `mutate()` helper for `Sgr`, `Mode`, `Cursor`

**File:** `src/Sgr/Sgr.php`, `src/Mode/Mode.php`, `src/Cursor/Cursor.php`

**What:** Apply the same `mutate()` pattern used in root `Cell` (`src/Cell.php:39-47`) to reduce `with*()` boilerplate in `Sgr`, `Mode`, and `Cursor`.

Looking at `Cell::mutate()` at lines 39-47:
```php
private function mutate(array $changes): self
{
    return new self(
        char: $changes['char'] ?? $this->char,
        fg: $changes['fg'] ?? $this->fg,
        bg: $changes['bg'] ?? $this->bg,
        attrs: $changes['attrs'] ?? $this->attrs,
    );
}
```

This pattern, applied to `Sgr`, would reduce each `with*()` method from 12 lines to 1 line. However, the existing `with*()` methods are explicit about which field changes — the `mutate()` approach trades explicitness for brevity.

**Recommendation:** Do NOT apply this change. The current explicit `with*()` pattern is clearer for readers — the redundancy is acceptable and the explicitness aids maintenance. The CALIBER_LEARNINGS.md explicitly notes this is intentional (line 429-449).

**Severity:** Duplication (Maintenance)

**Decision:** Won't fix — current pattern is documented as intentional.

---

### 9.2 Extract shared attribute constants class

**File:** `src/Theme.php:18-22`, `src/Cell/Cell.php:17-21`, `src/Cell.php:17-21`

**What:** Extract shared constants into a single class (e.g., `SugarCraft\Vt\Attr`) that both `Theme` and `Cell` reference.

```php
// Create src/Attr.php:
namespace SugarCraft\Vt;

final class Attr
{
    public const BOLD = 1 << 0;
    public const ITALIC = 1 << 1;
    public const UNDERLINE = 1 << 2;
    public const INVERSE = 1 << 3;
    public const STRIKETHROUGH = 1 << 4;
}
```

Then in `Theme.php` and `Cell.php`, remove the duplicate definitions and reference `Attr::*` instead.

**Why:** Both `Theme` and `Cell/Cell` define identical `ATTR_BOLD`, `ATTR_ITALIC`, etc. constants. While the CALIBER_LEARNINGS notes this is intentional for API surface parity, having a single source of truth would reduce maintenance burden.

**Severity:** Duplication (Maintenance)

**Conditions for Success:**
- All references to `Cell::ATTR_*` and `Theme::ATTR_*` updated to use `Attr::*`
- Existing tests pass
- `phpstan` passes

**Investigation Notes:**
- `Theme` at line 18-22 defines: `ATTR_BOLD = 1`, `ATTR_ITALIC = 2`, `ATTR_UNDERLINE = 4`, `ATTR_INVERSE = 8`, `ATTR_STRIKETHROUGH = 16`
- Root `Cell.php` at lines 17-21 defines identical constants
- `Cell/Cell.php` at lines 17-21 also defines them (for full path)
- `CsiHandlerImpl` at lines 187-191 uses `Cell::ATTR_*` constants

---

## Phase 10: Compatibility Issues [PENDING]

### 10.1 Add `candy-async` dependency to `candy-vt`

**File:** `candy-vt/composer.json`

**What:** Add `sugarcraft/candy-async` as a `require` dependency (not just a path repository).

```json
// In composer.json, add to "require":
"sugarcraft/candy-async": "dev-master"

// The path repository already exists at line 82-86:
{
    "type": "path",
    "url": "../candy-async",
    "options": {
        "symlink": true
    }
}
```

But it's not in `require`, so `candy-async` is only available for development/testing, not runtime use.

**Why:** `candy-vt` is part of a ReactPHP-based async ecosystem but has no async dependencies. Adding `candy-async` signals the intent and enables async usage patterns.

**Severity:** Compatibility

**Conditions for Success:**
- `composer validate` passes
- `composer update` succeeds
- No version conflicts introduced

**Investigation Notes:**
- `composer.json` shows `candy-async` is already in the path repositories (lines 82-86) but NOT in `require` (lines 27-31)
- `candy-async` IS in `require-dev` of `candy-testing`

---

## Phase 11: Silent No-ops & Edge Cases [PENDING]

### 11.1 Add optional debug-mode warning for unknown SGR params

**File:** `src/Handler/SgrHandler.php:92`

**What:** Add a debug-mode warning when unknown SGR parameters are silently skipped.

```php
// Current line 92:
default => [$sgr, $i + 1],

// Fix — add a debug log:
default => [
    $sgr,
    $i + 1,
    // @codeCoverageIgnoreStart
    // Debug-mode warning for unknown SGR parameter — matches VT spec (ignore unknown)
    // This is silent by default; set $_ENV['VT_DEBUG_SGR']=1 to see warnings
    // @codeCoverageIgnoreEnd
],
```

Or add an optional warning:
```php
default => [
    $sgr,
    $i + 1,
    $_ENV['VT_DEBUG_SGR'] ?? false
        ? error_log("Unknown SGR param: $p at index $i")
        : null,
],
```

**Why:** Unknown SGR params are skipped silently per VT spec. Some consumers might benefit from a debug-mode warning to identify problematic sequences.

**Severity:** Low

**Conditions for Success:**
- No change to default behavior (still silent)
- Debug mode can be enabled via environment variable
- Existing `SgrHandlerTest.php` passes

---

## Phase 12: Remaining Items [PENDING]

### 12.1 No `CellGrid::resize()` inconsistency (see 2.1)

**Related to:** Issue #22 — `CellGrid::resize()` creates a new instance but `CellGrid::set()` mutates in place. Already addressed in Phase 2.

---

### 12.2 Document `Parser::reset()` OSC discard behavior

**File:** `src/Parser/Parser.php:82-86`

**What:** Add a doc comment noting that `reset()` discards in-flight OSC/DCS data.

```php
/**
 * Reset the parser to ground state, clearing all accumulated state.
 *
 * @note This calls flush() first, which only dispatches if the state
 * is OscString|DcsString|SosString|PmString|ApcString. Any other state
 * (e.g., partial OSC with no terminator yet) is silently discarded
 * by clear(). If you need to recover partial sequence data, use
 * flush() alone rather than reset().
 */
public function reset(): void
{
    $this->flush();
    $this->clear();
}
```

**Why:** `reset()` is a hard reset — if called mid-sequence, any accumulated string payload is silently discarded. This is documented in the finding but not in the code.

**Severity:** Medium

**Conditions for Success:**
- No functional change
- Developers understand the discard-on-reset behavior

---

### 12.3 Add `focusEvents()` callback support to ScreenHandler

**File:** `src/Handler/ScreenHandler.php:54-55`

**What:** Add an optional callback to `ScreenHandler` that fires when focus events arrive.

```php
// In ScreenHandler constructor, add:
private ?\Closure $onFocusEvent = null;

public function __construct(
    Buffer $buffer,
    ?Cursor $cursor = null,
    ?Sgr $sgr = null,
    ?Mode $mode = null,
    ?Scrollback $scrollback = null,
    ?\Closure $onFocusEvent = null,  // ADD THIS
) {
    // ... existing init ...
    $this->onFocusEvent = $onFocusEvent;
}

// When focus events are added (lines 186, 190):
if ($this->mode->reportFocusEvents) {
    $this->focusEvents[] = new FocusInMsg();
    ($this->onFocusEvent)(new FocusInMsg());  // ADD THIS
    return;
}
```

**Why:** Focus events (`FocusInMsg`, `FocusOutMsg`) are appended to an array but there's no callback/event emitter. Consumers must poll the array. An optional callback would enable real-time notification.

**Severity:** Async Pattern Improvement

**Conditions for Success:**
- Backward compatible — callback is optional (null by default)
- Existing tests pass
- New test: callback is invoked when focus events fire

---

### 12.4 Pre-compute `Transitions` table at autoload time

**File:** `src/Parser/Transitions.php`

**What:** Generate the transition table at composer autoload time using a PHP pre-generation script or a `__static` constructor pattern.

```php
// In Transitions.php, add a static initializer:
final class Transitions
{
    private const SIZE = 4096;
    // ... existing constants ...

    private static ?string $table = null;

    // Static initialization on first access — already lazy, but ensure it's only called once
    private static function ensureInitialized(): string
    {
        return self::$table ??= self::build();
    }
}
```

The key improvement is using a static boolean guard in `build()` as shown in Phase 1.1, rather than relying on `??=` which is not atomic for strings.

**Why:** The finding notes the 4096-byte transition table is built on first call to `Transitions::get()`, not at class load time. Moving startup cost out of the first `feed()` call is desirable. However, the lazy init is already cached in `$table` — subsequent instances in the same process reuse it. The real issue was only thread-safety, not lazy vs eager.

**Severity:** Async Pattern Improvement (Performance)

**Decision:** Won't fix separately — the thread-safety fix in Phase 1.1 addresses the underlying concern.

---

### 12.5 Document dual Cell classes

**File:** `src/Cell.php` (root), `src/Cell/Cell.php` (subdirectory)

**What:** Improve the README and doc comments to clarify the distinction between the two Cell classes.

```php
// In src/Cell.php, add to the class docblock:
/**
 * A single cell in the terminal grid for the vcr renderer path.
 *
 * Readonly value object — char, foreground (0-255), background (0-255),
 * and an attribute bitfield (bold/italic/underline/inverse/strikethrough).
 *
 * NOTE: This is distinct from SugarCraft\Vt\Cell\Cell used by the full
 * emulator path. The root Cell uses simple scalar fields (char, fg, bg, attrs)
 * while Cell\Cell uses grapheme + Sgr + hyperlink + combining marks.
 * Consumers should not confuse the two — use Cell for the vcr renderer path
 * and Cell\Cell for the full Terminal\Terminal path.
 */
```

**Why:** Two `Cell` classes with different field names (`char` vs `grapheme`, `fg` vs embedded `Sgr`) could confuse consumers. The README already has an architecture table clarifying this, but the code itself should also document it.

**Severity:** Compatibility

**Conditions for Success:**
- No functional change
- Developers understand which Cell class to use in which context

---

### 12.6 Document `CellGrid` vs `Buffer` when-to-use

**File:** `src/CellGrid.php`, `src/Buffer/Buffer.php`

**What:** Add cross-reference doc comments to clarify when to use which.

```php
// In src/CellGrid.php, add to the class docblock:
/**
 * 2D cell grid with dirty-region tracking for the vcr renderer path.
 *
 * Use CellGrid when:
 * - Building lightweight vcr renderer snapshots
 * - Only need basic cell get/set/clear operations
 * - Don't need scrollback, hyperlinks, or SGR per cell
 *
 * For the full terminal emulator path, use Buffer (src/Buffer/Buffer.php)
 * instead — it supports hyperlinks, SGR per cell, scrollback, and iteration.
 */
```

```php
// In src/Buffer/Buffer.php, add cross-reference:
/**
 * Internal 2D cell grid for the full terminal emulator path.
 *
 * Use Buffer when:
 * - Building the full Terminal\Terminal emulator
 * - Need hyperlinks, SGR, and combining marks per cell
 * - Need scrollback ring buffer integration
 *
 * For the lightweight vcr renderer path, use CellGrid (../CellGrid.php) instead.
 */
```

**Why:** `CellGrid` and `Buffer` have overlapping responsibilities but different APIs. Documentation helps consumers choose correctly.

**Severity:** Compatibility

---

## Notes

- **2026-06-30:** Implementation plan created from code review findings in `findings/candy-vt.md`. All 31 items are addressed across 12 phases organized by severity and dependency order.
- Thread-safety fixes (Phases 1.1 and 1.2) should be completed first as they affect shared static state.
- Immutability repairs (Phase 2) must be completed before API additions that depend on accurate contracts.
- The async streaming improvements (Phase 8) are documented as future work — not implementing in this plan since they require broader architectural decisions.
- The `mutate()` helper extraction (Phase 9.1) is explicitly declined — current explicit pattern is documented as intentional per CALIBER_LEARNINGS.
