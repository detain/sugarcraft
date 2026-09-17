# Code Review: candy-vt

> **SUPERSEDED (2026-09-16, on master `aeb34bee4`) — `src/CellGrid.php` no longer exists.** The vcr renderer was unified onto the single `SugarCraft\Vt\Buffer\Buffer` grid: `CellGrid.php` was deleted by commit `40cfe4e3b` ("…collapse CellGrid into Buffer"), which reached master through **PR #1447 (merge `f7fe7c33b`)** (verified: `git ls-tree f7fe7c33b candy-vt/src/CellGrid.php` is empty; the file was still present at #1445 `45921a3db`). `candy-vt/src/Parser/CsiHandlerImpl.php:92` now holds `private Buffer $grid` and zero `CellGrid` references remain in `candy-vt/src/`. So the items below that treat `CellGrid` as a live file are **historical / resolved by the unification**: **#1** (`CellGrid::set()` immutability), **#22** (no `CellGrid::resize()`), **#28** (`CellGrid` vs `Buffer` duplication) — plus #24 and the summary-table rows for 1/22/28. The original wording is left intact so readers see the history in place. All other, still-live findings in this file are unaffected.
> **RE-DERIVED (2026-09-17, on master `b26b9989f`) — lane r86v5 second banner.** The parser-side rows are moot in this lib: `src/Parser/{Parser,Transitions,HandlerAdapter}.php` no longer exist here (de-fork `9952e3f5c`; the vcr path imports candy-ansi's parser, `candy-vt/src/Terminal.php:7-8`). The w4-vt `61b28175c` + r86u `3514c2d59` waves also overtook several r82 ❗ stamps — corrected per-row below (#1/#22/#28 CellGrid-gone; #2/#5/#18 E725/w4-vt landed; #20 clear() shipped; #24/#25 ruled; #27 unified via `Cell/Cell.php:24` alias; #8 shape moved to `CsiHandlerImpl.php:319`). Still-live after re-derivation: **#6 #10 #11 #13 #14 #19 #21 #30** (+ moved-#8).

## Summary

candy-vt is a well-structured in-memory VT500 terminal emulator that parses ANSI byte streams into a cell grid. The codebase follows the SugarCraft monorepo conventions (PSR-12, immutable+fluent patterns, `final` classes, `declare(strict_types=1)` throughout). The two Terminal entry-points (`SugarCraft\Vt\Terminal` for the vcr renderer path and `SugarCraft\Vt\Terminal\Terminal` for full emulation) are appropriately separated.

Overall code quality is high. The VT500 state machine port (Transitions), SGR handling, scrollback ring buffer, DECAWM, DECOM, DECSTBM, and synchronized-output (DEC 2026) are all implemented correctly against their specs.

---

## Critical Issues

*(none found — no security vulnerabilities, memory leaks causing crashes, or data corruption)*

---

## High Severity Issues

### 1. `CellGrid::set()` is NOT immutable despite returning `$this` ❗ r82: STILL LIVE — src/CellGrid.php:67-79 (set() still returns $self after in-place mutation; API-break decision — flagged) → r86v5: ⏭ superseded — file deleted (`40cfe4e3b` via PR #1447 `f7fe7c33b`); vcr path on `Buffer` (`CsiHandlerImpl.php:92`). r82 STILL-LIVE stamp overtaken.

**File:** `src/CellGrid.php:66-78`

`set()` returns `self` (fluent/builder pattern) but mutates `$this->grid[$row][$col]` in place:

```php
public function set(int $row, int $col, Cell $cell): self
{
    // ... bounds check ...
    $this->grid[$row][$col] = $cell;  // mutates in place!
    // ... dirty tracking ...
    return $this;  // promises immutability
}
```

This is misleading. `CellGrid` is **not** an immutable value object — it is a mutable container. Callers who chain `->set()` calls are modifying shared state, not building new instances.

**Impact:** Low externally (the `$grid` property is private), but violates the stated design contract and could cause subtle bugs if external code treats `CellGrid` as immutable.

**Fix:** Either (a) make `set()` actually return a new instance with the changed cell (true immutability), or (b) change the return type to `void` and remove the fluent return.

---

### 2. `ScreenHandler` cursor visibility has two sources of truth ❗ r82: STILL LIVE — src/Handler/ModeHandler.php:72-74 (still dual write mode+cursor) → r86v5: ✅ landed (E725) — sole write path `ScreenHandler::setCursorVisible()` `src/Handler/ScreenHandler.php:1658-1662`; `ModeHandler.php:52` routes DEC 25 through it; alt-leave re-points the mode mirror `:1736`.

**File:** `src/Handler/ScreenHandler.php:36-37` and `src/Handler/ModeHandler.php:71-74`

`ScreenHandler` holds `$this->cursor->visible` AND `$this->mode->cursorVisible` as separate booleans. They should always be in sync, but they are written in two places independently:

- `ModeHandler::setCursorVisible()` writes to both:
  ```php
  $h->mode = $h->mode->withCursorVisible($set);
  $h->cursor = $h->cursor->withVisible($set);
  ```

However, `ScreenHandler::enterAltScreen()` and `ScreenHandler::leaveAltScreen()` only construct a **new** `Cursor` with `visible: $this->cursor->visible` — they don't independently update `$mode->cursorVisible` after restoration. If `$mode` was mutated separately from `$cursor` at any point, these could diverge.

**Impact:** `Terminal\Terminal::cursor()->visible` and `Terminal\Terminal::mode()->cursorVisible` can theoretically become inconsistent.

---

### 3. `Transitions::$table` static cache is not thread-safe 🔀 r82: moved — parser de-forked onto candy-ansi (9952e3f5c); lazy ??= table now candy-ansi/src/Parser/Transitions.php:37; r82 q18: ⏭️ obsolete in candy-ansi (no shared-memory userland threads; build() pure) — see candy-ansi.md §10

**File:** `src/Parser/Transitions.php:27`

```php
private static ?string $table = null;

public static function get(int $state, int $byte): int
{
    return ord((self::$table ??= self::build())[($state << self::STATE_SHIFT) | $byte]);
}
```

If two threads call `get()` simultaneously before `$table` is initialized, both may call `build()`. While the result is the same (pure function), PHP's `??=` is not atomic — the second caller may see a partially-written string or call `build()` twice. More critically, the 4096-byte string assignment is not atomic on all platforms.

**Impact:** Potential corrupted transition table in multi-threaded PHP (Swoole/ReactPHP) environments. The `Transitions` table is a pure lookup table with no mutable state after construction, so the worst case is double construction or a torn read — not silent data corruption of the table content.

**Fix:** Use `PHP_LOCK_SH` or initialize eagerly at class load time rather than lazily.

---

### 4. `Theme::$fgIndexMap` / `Theme::$bgIndexMap` same thread-safety concern ✅ r82: fixed by rewrite — maps are eager literals src/Theme.php:33-36, buildAnsiMaps gone → r86v5: ✅ confirmed — eager literals `src/Theme.php:33`/`:36`.

**File:** `src/Theme.php:31-35` and `src/Theme.php:295-307`

```php
private static array $fgIndexMap = [];
private static array $bgIndexMap = [];

public static function fgIndex(int $slot): int
{
    self::buildAnsiMaps();  // not thread-safe lazy init
    return self::$fgIndexMap[$slot];
}
```

Same double-checked locking issue as `Transitions::$table`. The maps are currently **identity maps** (index 0→0, 1→1, … 15→15) so even if torn reads occur the behavior is likely harmless, but the pattern is fragile for future changes.

**Fix:** Initialize these maps at class load time or make the build method idempotent.

---

### 5. `Terminal\Terminal::resize()` does not resize the saved alt buffer ❗ r82: STILL LIVE — src/Terminal/Terminal.php:120-127 resize() still touches only active buffer → r86v5: ✅ landed (E725) — `Terminal.php:394-402` → `ScreenHandler::resizeBuffers()` `:1606-1621` resizes `savedBuffer` too.

**File:** `src/Terminal/Terminal.php:118-124` + `src/Handler/ScreenHandler.php:463-475`

When in alt screen mode (DEC 1049), `Terminal::resize()` only resizes the **active** buffer via `$this->handler->buffer = $this->handler->buffer->resize($cols, $rows)`. The saved main buffer (`ScreenHandler::$savedBuffer`) retains its old dimensions.

Real terminals resize both buffers. A downstream consumer exercising resize while in alt screen could get confusing behavior when leaving alt mode — the restored buffer would have different dimensions than the current screen.

**Impact:** Only affects the full `Terminal\Terminal` path. Not a correctness bug (saved state is preserved), but inconsistent with real terminal behavior.

---

### 6. Root `Terminal` lacks `enableAltScreen()`/`disableAltScreen()` API ❗ r82: STILL LIVE — src/Terminal.php has no alt-screen API (roster: new/feed/snapshot/cursor/grid/windowTitle) → r86v5: ❗ confirmed still-live — `src/Terminal.php` roster has no alt-screen API; full path `Terminal/Terminal.php:515`/`:524`.

**File:** `src/Terminal.php:1-97`

The root `SugarCraft\Vt\Terminal` (vcr renderer path) has no alt-screen entry points. The full `SugarCraft\Vt\Terminal\Terminal` has `enableAltScreen()`/`disableAltScreen()`. This is fine internally but means consumers of the vcr path who want to test alt-screen sequences have no public API.

**Impact:** Minor — the vcr path is meant to be lightweight. But for parity, an `enableAltScreen()` method returning a new `Terminal` instance (immutable style) would be appropriate.

---

## Medium Severity Issues

### 7. `Parser::reset()` does not flush in-flight OSC/DCS strings before clearing 🔀 r82: moved — now candy-ansi/src/Parser/Parser.php:138 (re-verify in candy-ansi); r82 q18: ✅ fixed in candy-ansi — reset()=flush()+clear(), flush dispatches OSC/DCS payloads (Parser.php:117-146)

**File:** `src/Parser/Parser.php:82-86`

```php
public function reset(): void
{
    $this->flush();
    $this->clear();
}
```

`flush()` only dispatches if the state is one of `OscString | DcsString | SosString | PmString | ApcString`. But `reset()` is a hard reset — if a caller calls `reset()` mid-sequence (e.g., at a sync point), any accumulated OSC/DCS payload is silently discarded. This is the documented behavior (`clear()` resets params/cmd/stringBuffer), but it could cause data loss for applications that use `reset()` as an error-recovery mechanism.

**Impact:** `Parser::reset()` discards any in-flight string. If `Parser::feed()` was called with partial OSC data followed by `reset()`, the OSC would be lost. This is unlikely in practice since `Terminal\Terminal` calls `flush()` rather than `reset()` at end-of-stream.

---

### 8. `SgrHandler::apply()` uses `array_values(array_map('intval', $params))` unnecessarily ✅ r82: fixed by rewrite — no array_map/array_values left in src/Handler/SgrHandler.php → r86v5: ❗ same shape MOVED to `src/Parser/CsiHandlerImpl.php:319` (`sgr()`); `SgrHandler.php` itself stays clean.

**File:** `src/Handler/SgrHandler.php:175`

```php
[$sgr, $i] = $this->step($p, $i, $params, $sgr);
```

The `$params` passed from `ScreenHandler::csiDispatch()` already contains the parser's params as integers. The `array_values(array_map('intval', ...))` is called redundantly and rebuilds the array with sequential keys. While harmless, it's dead code that adds per-frame allocation overhead.

**Impact:** Minor allocation overhead per SGR frame.

---

### 9. `Transitions::build()` has an unused `$g` variable with static analysis noise 🔀 r82: moved — Transitions now in candy-ansi (9952e3f5c); r82 q18: ⏭️ obsolete — $g used at definition line (Transitions.php:52-53) and no phpstan in lib

**File:** `src/Parser/Transitions.php:52-53`

```php
$g = State::Ground->value;
$t = str_repeat(self::pack(Action::None->value, $g), self::SIZE);
```

`$g` is defined at line 52 but the closures `$set`/`$setMany` don't use it — they receive state values as parameters. `$g` is used directly in `$setRange` calls and in the Anywhere transitions loop. The variable is correct but `phpstan` may warn about it being "used before assignment" in some configurations since the static analyzer doesn't track that `str_repeat` is evaluated eagerly before any closure invocation.

**Impact:** Static analysis noise, not a runtime bug.

---

### 10. Color comparison logic is duplicated across `Sgr::equals()`, `Cell::equals()`, and `Color::equals()` ❗ r82: STILL LIVE — no equalsOrBothNull utility; 4 null-compare sites each in src/Sgr/Sgr.php + src/Cell/Cell.php → r86v5: ❗ confirmed — no `Color::equalsOrBothNull()`; `Sgr.php:270-283` ×2 inline; canonical `Cell.php:361-371` private `colorEquals()` copy (dup narrowed 3→2 sites).

**Files:** `src/Sgr/Sgr.php:267-288`, `src/Cell/Cell.php:96-114`

The null-safe color equality check is implemented three times:

```php
// In Sgr::equals() and Cell::equals():
if ($thisFg === null && $otherFg === null) {
    $fgEqual = true;
} elseif ($thisFg === null || $otherFg === null) {
    $fgEqual = false;
} else {
    $fgEqual = $thisFg->equals($otherFg);
}
```

This is verbose and repeated. A `Color::equalsOrBothNull(Color|null $a, Color|null $b): bool` utility would eliminate the duplication and make the semantics clearer.

**Impact:** Code duplication; slightly harder to maintain. Not a correctness issue.

---

### 11. `Screen::diff()` iterates to max dimension, not actual dimensions ❗ r82: STILL LIVE — src/Screen/Screen.php:59-62 still max-dimension iteration → r86v5: ❗ confirmed — `src/Screen/Screen.php:59-62` unchanged.

**File:** `src/Screen/Screen.php:56-73`

```php
$maxRows = max($this->rows, $other->rows);
$maxCols = max($this->cols, $other->cols);
for ($r = 0; $r < $maxRows; $r++) {
    for ($c = 0; $c < $maxCols; $c++) {
```

This iterates up to the larger screen's dimensions. For a 100×30 screen diffed against a 80×24 screen, it iterates 100×30 = 3000 cells when only the overlapped region (80×24 = 1920) needs comparison. The extra cells are handled by `cell()` returning empty cells, but the iteration is unnecessary.

**Impact:** Minor performance overhead when diffing screens of different sizes. O(max(rows1,rows2) × max(cols1,cols2)) instead of O(min(rows1,rows2) × min(cols1,cols2)) for the overlap plus O(extra) for size differences.

---

### 12. `HandlerAdapter::oscDispatch()` only handles title (OSC 0/1/2) 🔀 r82: moved — HandlerAdapter now candy-ansi/src/Parser/HandlerAdapter.php (re-verify OSC scope there); r82 q18: ✅ fixed for recorded scope — OSC 8 now dispatched w/ id parsing (HandlerAdapter.php:93-102); OSC 4/52 remain by-design (§2.3)

**File:** `src/Parser/HandlerAdapter.php:79-84`

```php
public function oscDispatch(string $data): void
{
    if (preg_match('/^([0-2]);(.+)$/', $data, $m)) {
        $this->osc->title($m[2]);
    }
}
```

The root `HandlerAdapter` only handles `OSC 0`, `OSC 1`, and `OSC 2` for window title. OSC 4 (palette), OSC 8 (hyperlink), OSC 52 (clipboard) are not handled. This is documented, but means consumers using the root `Terminal` for testing ANSI output will get silent no-ops for these sequences.

**Impact:** The vcr renderer path intentionally ignores hyperlinks/palette/clipboard, but this could be surprising if a consumer feeds these sequences expecting them to work.

---

## Low Severity Issues

### 13. `Theme::cubePalette()` is computed twice per theme instance ❗ r82: STILL LIVE — src/Theme.php:63+ cubePalette() still unmemoized, called by every factory → r86v5: ❗ confirmed — `Theme.php:63-75` unmemoized, 6 call sites (`:57`/`:113`/`:145`/`:171`/`:200`/`:229`).

**File:** `src/Theme.php:53-57`

```php
public static function defaultPalette(): array
{
    return [
        0x000000, 0x800000, ...  // 16 colors
    ] + self::cubePalette();  // first call
}
```

Each theme factory (`tokyoNight()`, `dracula()`, etc.) also spreads `self::cubePalette()` into the palette array:

```php
palette: [
    0x15161e, ...  // 16 colors
] + self::cubePalette(),  // second call (same 216-element array)
```

The 216-element cube palette is generated twice per theme instance. Since themes are typically singletons or created once, this is minor waste.

**Impact:** ~432 unnecessary loop iterations per theme instantiation.

---

### 14. `CsiHandlerImpl::scrollUpOne()` is O(cols²) per row scrolled ❗ r82: STILL LIVE — src/Parser/CsiHandlerImpl.php:548+ cell-by-cell scroll still → r86v5: ❗ confirmed — `CsiHandlerImpl.php:982-997` still cell-by-cell on `Buffer::cell()`/`put()`.

**File:** `src/Parser/CsiHandlerImpl.php:370-386`

```php
private function scrollUpOne(): void
{
    for ($r = $top; $r < $bottom; $r++) {
        for ($c = 0; $c < $cols; $c++) {
            $next = $this->grid->get($r + 1, $c);
            $this->grid->set($r, $c, $next);
        }
    }
    // ...
}
```

For a scroll of N rows, this calls `get()` and `set()` N×cols×2 times. A more efficient approach would be to use `array_slice` to shift row references in O(cols) operations rather than O(cols²).

**Impact:** Scrolling large regions is slower than necessary. However, `CsiHandlerImpl` is only used by the lightweight root `Terminal`, not the full emulator, so the impact is limited to that path.

---

### 15. `SgrHandler::step()` silently ignores unknown SGR parameters ⏭️ r82: obsolete — spec-correct silent skip, no functional issue by design

**File:** `src/Handler/SgrHandler.php:92`

```php
default => [$sgr, $i + 1],
```

Unknown SGR parameters are skipped silently. This matches VT spec behavior (ignore unknown SGR), but some consumers might benefit from a debug-mode warning.

**Impact:** No functional issue. Silent no-op per VT spec.

---

### 16. `HandlerAdapter::printChar()` rejects printable bytes below 0x20 🔀 r82: moved — printChar now candy-ansi HandlerAdapter; r82 q18: ✅ fixed — pass-through rationale now documented (HandlerAdapter.php:26-27)

**File:** `src/Parser/HandlerAdapter.php:23-30`

```php
public function printChar(string $rune): void
{
    $byte = $rune[0] ?? '';
    if ($byte !== '' && ord($byte) >= 0x20) {
        $this->csi->printable($rune);
    }
}
```

C0 controls (0x00-0x1F) are rejected here because `printChar` is only called for valid UTF-8 lead bytes or ASCII printables. DEL (0x7F) is also rejected. This is correct — the `execute()` path handles C0 controls — but the comment at line 24-26 doesn't fully explain the Latin-1 pass-through rationale.

**Impact:** No functional issue. Just a comment/documentation gap.

---

### 17. Inconsistent factory method naming: `Terminal::new()` vs `Terminal::create()` ✅ r82: fixed by rewrite — root Terminal::new() only; full path @deprecated create() at src/Terminal/Terminal.php:59-61 → r86v5: ✅ confirmed — root `new()`-only `src/Terminal.php:48`; `@deprecated create()` `Terminal/Terminal.php:73-75`.

**Files:** `src/Terminal.php:45`, `src/Terminal/Terminal.php:51-62`

- Root `Terminal::new()` (canonical factory)
- `Terminal\Terminal::new()` (canonical factory) + `@deprecated create()`

The root `Terminal` does not have a `create()` alias, while `Terminal\Terminal` has both. This inconsistency could confuse consumers who migrate between the two paths.

**Impact:** Minor API confusion. The `@deprecated create()` on the full Terminal exists for BC, which is why the inconsistency is acceptable.

---

### 18. `Terminal\Terminal::__clone()` re-creates a new `Parser` with the cloned handler ❗ r82: STILL LIVE — src/Terminal/Terminal.php:129 __clone has no docblock note yet → r86v5: ✅ landed — `__clone()` docblock present `src/Terminal/Terminal.php:404-424` (committed-state + deep-copy note); r82 stamp stale vs the body's w4-vt ✅.

**File:** `src/Terminal/Terminal.php:126-130`

```php
public function __clone(): void
{
    $this->handler = clone $this->handler;
    $this->parser = new Parser($this->handler);  // new parser
}
```

After cloning, the new `Terminal` has a fresh `Parser` in Ground state. Any in-flight parse state (partial UTF-8 rune, partial CSI/OSC sequence) is lost. This is correct behavior for a snapshot clone, but it's worth noting that `with*()` clone operations reset parse state.

**Impact:** Expected behavior for immutable-with*() builders, but not documented. Could surprise callers who clone a terminal mid-stream.

✅ **Fixed (w4-vt):** parse-state reset now documented on `Terminal::__clone()`; the REAL hazard under this heading is also gone — `clone $handler` was shallow, so the clone wrote THROUGH into the original's `Buffer` grid, `Scrollback` ring and parked alt-screen buffer. `ScreenHandler::__clone()` deep-copies the mutable aggregates (immutable value objects stay shared); pinned by `tests/CloneIsolationTest.php`.

---

## Missing Features

### 19. `ScreenHandler::$focusEvents` has no `Terminal` accessor ❗ r82: STILL LIVE — ScreenHandler.php:55 public focusEvents, no Terminal\Terminal::focusEvents() accessor → r86v5: ❗ confirmed — `ScreenHandler.php:58` public array; no `focusEvents()` accessor in `src/Terminal/Terminal.php` (cf. `clipboardEvents()` `:389`).

**File:** `src/Handler/ScreenHandler.php:54-55`

The full `Terminal\Terminal` class has no `focusEvents()` method. Consumers who want to read accumulated focus events must access `$vt->handler->focusEvents` directly. A public accessor would complete the API.

**Impact:** Missing API completeness for the full Terminal path.

---

### 20. No public API to clear the scrollback buffer ❗ r82: STILL LIVE — src/Screen/Scrollback.php has no clear() (push/all/at/count/maxSize only) → r86v5: ✅ landed — `clear()` present `src/Screen/Scrollback.php:130`; r82 stamp stale vs the body's w4-vt ✅.

**File:** `src/Screen/Scrollback.php`

`Scrollback` has no `clear()` method. The only way to clear scrollback is via `Terminal::withScrollbackSize()` (which creates a new empty Scrollback) or via the `CSI 3 J` erase sequence routed through `EraseHandler`. There's no direct public API to clear the scrollback on the current terminal instance.

**Impact:** Consumers who want to programmatically clear scrollback without a CSI sequence have no clean API.

✅ **Fixed (w4-vt):** `Scrollback::clear(): void` added (ring rewinds to pristine, capacity kept), and `CSI 3 J` is now actually implemented at the `ScreenHandler` level — it drains the ring and leaves the visible screen + cursor untouched (the `EraseHandler` grid path was a dead no-op branch before). xterm's `eraseSavedLines` resource gate documented inline; not modelled (no scrollback-retention setting exists here). Pinned by `tests/Handler/ScrollbackHygieneTest.php`.

---

### 21. `Terminal` (root) has no `flush()` method ❗ r82: STILL LIVE — src/Terminal.php still lacks flush() → r86v5: ❗ confirmed — `src/Terminal.php` still lacks `flush()`.

**File:** `src/Terminal.php`

The full `Terminal\Terminal` has `flush()` to dispatch in-flight string sequences. The root `Terminal` has no `flush()` method — if OSC/DCS data is fed and then the caller wants to force dispatch before taking a snapshot, there's no method for it.

**Impact:** Consumers of the vcr renderer path cannot flush pending OSC sequences programmatically.

---

### 22. No `CellGrid::resize()` — inconsistent with `Buffer::resize()` ❗ r82: STILL LIVE — same as #1 (src/CellGrid.php:67) → r86v5: ⏭ superseded — CellGrid deleted (see #1).

**File:** `src/CellGrid.php`

`CellGrid` has `clear()` and `resize()` methods, but `resize()` is implemented:

```php
public function resize(int $cols, int $rows): self
{
    $newGrid = $this->makeGrid($cols, $rows);
    // ... copy old content ...
    $clone = new self($cols, $rows);
    $clone->grid = $newGrid;  // mutates cloned grid!
    return $clone;
}
```

This correctly creates a new `CellGrid` but then mutates its internal `$grid`. For true immutability, the `set()` method should also return a new instance.

**Impact:** `resize()` promises to return a new instance (true immutable pattern) but `set()` mutates in place. The inconsistency is confusing.

---

### 23. No DCS dispatch implementation in `HandlerAdapter` or `OscHandlerImpl` ✅ r82: fixed by de-fork — DCS dispatch implemented in candy-ansi/src/Parser/HandlerAdapter.php:129

**Files:** `src/Parser/HandlerAdapter.php:86-88`, `src/Parser/OscHandlerImpl.php:21-23`

DCS (Device Control String) dispatch is a no-op in both handler paths. While documented as "deferred to later slices", this means DCS-based sequences are silently ignored. This is fine for the current scope but worth tracking as an unimplemented feature.

**Impact:** None for current scope — DCS is rarely used in terminal output.

---

## Duplicated Logic

### 24. Three nearly identical `with*()` builders across `Sgr`, `Mode`, `CellGrid` ❗ r82: STILL LIVE — mutate() still unused in Sgr/Mode/Cell (grep 0; only root Cursor.php uses it) → r86v5: ⏭️ ruled — plan 9.1 Won't-fix stands; `mutate()` only in root `src/Cursor.php:27` (and `src/Cell.php:126` since r86 weld re-check).

Each `with*()` method follows this exact pattern across `Sgr`, `Mode`, and `Cursor`:

```php
public function withFoo(bool $v): self {
    return new self(
        propA: $this->propA,
        propB: $this->propB,
        // ... all other fields unchanged ...
        foo: $v,
    );
}
```

All 10+ `Sgr::with*()` methods repeat the same 12-line constructor call block. All ~10 `Mode::with*()` methods repeat the same 12-field block. A private `with(string $field, mixed $value)` or a `__set` pattern could reduce this, but would sacrifice explicitness. The `mutate()` helper used in root-namespace value objects (`Cell`, `Cursor`) is a better pattern already — the subdirectory classes don't use it.

**Impact:** Maintenance burden — adding a field to `Sgr` requires updating all 10 `with*()` methods. A `mutate()` helper would reduce this to one line per method.

---

### 25. `Theme` and `Cell` both define identical attribute constants ✅ r82: fixed by rewrite — src/Cell/Cell.php no longer defines ATTR_* constants → r86v5: ⏭️ ruled-not-applicable — CORRECTION: duplication persists 2-file (`src/Theme.php:19-23` ↔ `src/Cell.php:40-44`; `Cell/Cell.php:24` is alias shim); r82 ✅ over-claimed. CALIBER parity intentional.

**Files:** `src/Theme.php:18-22`, `src/Cell/Cell.php:17-21`

Both `Theme` and `Cell/Cell` define `ATTR_BOLD = 1`, `ATTR_ITALIC = 2`, `ATTR_UNDERLINE = 4`, `ATTR_INVERSE = 8`, `ATTR_STRIKETHROUGH = 16`. These are duplicated across two files. While the CALIBER_LEARNINGS notes this is intentional for API surface parity, a single shared constants class would eliminate the duplication.

**Impact:** If values diverge, subtle bugs could arise. Currently safe because they're documented to be identical.

---

## Compatibility Issues

### 26. `candy-vt` has no `candy-async` dependency but is async ecosystem ⏭️ r82: obsolete — pre-1.0 async-adapter idea, not a defect; sync core by design

**File:** `candy-vt/composer.json`

The library is part of a ReactPHP-based ecosystem (`candy-async` is in the monorepo) but `candy-vt` has no async dependencies. The `feed()` → parse → snapshot pipeline is entirely synchronous. For use in an async ReactPHP event loop with PTY input streaming, the current architecture would need to be wrapped in async primitives.

**Impact:** No immediate compatibility issue, but `candy-vt` cannot currently be used in a streaming async PTY pipeline without additional async wrapping.

---

### 27. Two `Cell` classes: `SugarCraft\Vt\Cell` and `SugarCraft\Vt\Cell\Cell` ❗ r82: STILL LIVE — dual Cell classes both present (src/Cell.php + src/Cell/Cell.php); rename breaks public API — FLAGGED, not decided → r86v5: ✅ landed — Cell unified into canonical `src/Cell.php`; `src/Cell/Cell.php:24` `class_alias` shim (façade rule). r82 "FLAGGED, not decided" decided upstream.

**Files:** `src/Cell.php` (vcr renderer path), `src/Cell/Cell.php` (full path)

The root `Cell` uses a simple bitfield attrs and stores `char/fg/bg/attrs`. The subdirectory `Cell\Cell` stores `grapheme/sgr/hyperlink/combining`. These have different field names (`char` vs `grapheme`, `fg` vs embedded `Sgr`). Consumers who confuse the two could get type errors or wrong behavior.

**Impact:** Developer confusion. The README's architecture table clarifies this but the dual naming is inherently confusing.

---

### 28. `CellGrid` and `Buffer` have overlapping responsibilities ❗ r82: STILL LIVE — CellGrid + Buffer both present; unification is public-API-breaking — FLAGGED, not decided → r86v5: ⏭ superseded — CellGrid deleted; single Buffer grid (header banner governs).

**Files:** `src/CellGrid.php`, `src/Buffer/Buffer.php`

`CellGrid` (root, vcr path) and `Buffer` (subdirectory, full path) are two different grid implementations with different APIs:

| Method | `CellGrid` | `Buffer` |
|--------|------------|----------|
| get cell | `get(row,col)` | `cell(row,col)` |
| set cell | `set(row,col,cell): self` | `put(row,col,cell): void` |
| bounds check | returns `Cell::empty()` | returns `Cell::empty()` |
| iteration | none | `each(): Generator` |

`CellGrid::set()` is claimed to be immutable but mutates in place. `Buffer::put()` is correctly void/mutable. This API divergence makes it harder to write code that works across both paths.

**Impact:** Dual-APIs require careful selection of the right grid type. The `Terminal::new()` (root) uses `CellGrid`, while `Terminal\Terminal` uses `Buffer`.

---

## Async Pattern Improvements

### 29. No async/streaming input support — `feed()` is sync only ⏭️ r82: obsolete — sync feed() is the documented v1 design; async wrapping is consumer-side

**File:** `src/Parser/Parser.php:52-58`

```php
public function feed(string $bytes): void
{
    $len = strlen($bytes);
    for ($i = 0; $i < $len; $i++) {
        $this->advance(ord($bytes[$i]));
    }
}
```

`feed()` is synchronous and blocking. For a PTY that streams bytes incrementally (e.g., from a subprocess or network connection), there is no async-compatible API. An async variant using ReactPHP's `Stream` interface would allow incremental parsing without blocking the event loop.

**Potential improvement:**
```php
// Conceptual async interface:
public function feedAsync(string $bytes): \React\Promise\PromiseInterface;
// or
public static function fromAsyncStream(ReadableStreamInterface $stream): \Generator;
```

**Impact:** Limits use in async PTY pipelines. This is a known limitation of the v1 design.

---

### 30. `ScreenHandler::$focusEvents` accumulates but has no async notification ❗ r82: STILL LIVE — focus events still polling-only (ScreenHandler.php:55) → r86v5: ❗ confirmed — no `onFocusEvent`; array-only `:58`/`:395`/`:399`. Pairs with plan 12.3.

**File:** `src/Handler/ScreenHandler.php:54-55`

Focus events (`FocusInMsg`, `FocusOutMsg`) are appended to an array. There is no callback, event emitter, or async signal dispatched when a focus event arrives. Consumers must poll the array or subclass `ScreenHandler` to intercept events.

**Potential improvement:**
```php
// In ScreenHandler constructor:
public function __construct(
    Buffer $buffer,
    ?callable $onFocusEvent = null,
    // ...
) {
    $this->onFocusEvent = $onFocusEvent;
}

// When event fires:
($this->onFocusEvent)(new FocusInMsg());
```

**Impact:** No async notification — polling-only access to focus events.

---

### 31. `Transitions::build()` is eager but could be deferred or pre-computed 🔀 r82: moved — Transitions now candy-ansi; lazy first-use build still at Transitions.php:37 there; r82 q18: ⏭️ feature-idea (as recorded); lazy build retained, cost assessed low in candy-ansi.md §6.1

**File:** `src/Parser/Transitions.php:50-238`

The 4096-byte transition table is built on first call to `Transitions::get()`, not at class load time. For CLI tools that only import the class but never use the parser, this is fine. But for long-running processes that repeatedly create new `Terminal` instances, the lazy build is re-done on first `feed()` call — no caching across instances.

The static `$table` is cached, so subsequent `Terminal` instances in the same process reuse it. But the first instance per process pays the build cost.

**Potential improvement:** Generate the transition table at composer autoload time via a `__static` constructor or pre-computed file. This would move startup cost out of the first `feed()` call.

---

## Recommendations Summary Table

| # | Severity | Category | Location | Issue | Recommendation |
|---|----------|----------|----------|-------|----------------|
| 1 | High | Immutability | `CellGrid::set()` | Mutates in place, returns `$this` | Make `set()` actually immutable or change return to `void` |
| 2 | High | State Divergence | `ScreenHandler` | Two cursor-visibility sources of truth | Add invariant check or single source of truth |
| 3 | High | Thread Safety | `Transitions::$table` | Non-atomic lazy static init | Use `PHP_LOCK_SH` or eager init |
| 4 | High | Thread Safety | `Theme::$fgIndexMap` | Same as above | Same fix |
| 5 | High | Incomplete Feature | `Terminal::resize()` | Saved alt buffer not resized | Resize both buffers in alt mode |
| 6 | High | API Parity | Root `Terminal` | No alt-screen API | Add `enableAltScreen()` returning new Terminal |
| 7 | Medium | Data Loss | `Parser::reset()` | In-flight OSC discarded | Document or add `reset(flushInFlight: true)` option |
| 8 | Medium | Dead Code | `SgrHandler::apply()` | Redundant `array_values(array_map(...))` | Remove the redundant call |
| 9 | Medium | Static Analysis | `Transitions::build()` | `$g` unused in closures, noise | Remove unused variable or document intent |
| 10 | Medium | Duplication | `Sgr::equals()`, `Cell::equals()` | Repeated color comparison | Extract `Color::equalsOrBothNull()` utility |
| 11 | Medium | Performance | `Screen::diff()` | Iterates max dimensions not min | Limit iteration to overlapping region + extras |
| 12 | Medium | Silent No-op | `HandlerAdapter::oscDispatch()` | Ignores non-title OSC | Document scope limitation |
| 13 | Low | Performance | `Theme::cubePalette()` | Computed twice per theme | Cache cube palette as static |
| 14 | Low | Performance | `CsiHandlerImpl::scrollUpOne()` | O(cols²) per row | Use array_slice-based row shifting |
| 15 | Low | Debug | `SgrHandler::step()` | Silent unknown SGR | Optional debug-mode warning |
| 16 | Low | Documentation | `HandlerAdapter::printChar()` | Incomplete comment | Clarify Latin-1 pass-through rationale |
| 17 | Low | API Inconsistency | Factory methods | `new()` vs `create()` naming | Standardize on `new()` across all entry-points |
| 18 | Low | Correctness | `Terminal::__clone()` | Parse state lost on clone | ✅ Documented; clone now deep-copies Buffer/Scrollback (w4-vt) |
| 19 | Missing | API | `Terminal::focusEvents()` | No public accessor | Add `focusEvents(): array` accessor |
| 20 | Missing | API | `Scrollback` | No `clear()` method | ✅ `clear(): void` + wired `CSI 3 J` (w4-vt) |
| 21 | Missing | API | Root `Terminal` | No `flush()` method | Add `flush(): void` |
| 22 | Missing | Immutability | `CellGrid::set()` | Not actually immutable | See issue #1 |
| 23 | Missing | Scope | DCS dispatch | Not implemented | Document as deferred to v2 |
| 24 | Duplication | Maintenance | `Sgr`, `Mode`, `Cursor` | Repeated with*() boilerplate | Extract `mutate()` helper (already done in root classes) |
| 25 | Duplication | Maintenance | `Theme`/`Cell` attrs | Duplicated constants | Extract shared constants class |
| 26 | Compatibility | Async | `candy-vt` | No async deps | Consider adding `candy-async` dep or async adapter |
| 27 | Compatibility | API | Dual `Cell` classes | Two classes with different fields | Better naming or shared interface |
| 28 | Compatibility | API | `CellGrid` vs `Buffer` | Overlapping grid types | Unify or better document when to use which |
| 29 | Async | Design | `feed()` | Sync-only | Add async streaming API |
| 30 | Async | Design | Focus events | Polling-only | Add optional callback/event emitter |
| 31 | Async | Performance | `Transitions` init | Lazy on first use | Pre-compute at autoload time |
