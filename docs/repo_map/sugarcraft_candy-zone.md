# SugarCraft/candy-zone — Innovation & Comparison Report

## Overview

**candy-zone** is the SugarCraft port of [lrstanley/bubblezone](https://github.com/lrstanley/bubblezone), providing mouse-zone tracking for TUI applications. It solves the fundamental problem of determining which interactive region (button, list item, panel) a mouse click landed on, without requiring every component to manually track its own screen position.

The core insight is elegant: wrap every interactive region in invisible ANSI escape sequence markers, scan the fully-rendered view at the root to record each region's screen coordinates, then simply ask "is this mouse event inside this named zone?" at event-handling time. This shifts the coordinate calculation burden from every component to a single scan pass.

**Key statistics:**
- **7 source files** in `src/` (Manager, Zone, Zones, ZoneHoverTracker, DragTracker, ClickCounter, MsgZoneInBounds)
- **8 Message subclasses** (ZoneEnterMsg, ZoneExitMsg, ZoneDragStartMsg, ZoneDragMoveMsg, ZoneDragEndMsg, DoubleClickMsg, TripleClickMsg)
- **6 test files** with 40+ test methods covering all trackers and edge cases
- **Status:** 🟢 Complete port, fully tested against upstream semantics
- **Upstream:** lrstanley/bubblezone (880+ stars, MIT License)

---

## Current Architecture

### Zone Marking Mechanism

candy-zone uses **APC (Application Program Command)** escape sequences to mark zones:

```
Start: ESC _ "candyzone:S:<id>" ESC \
End:   ESC _ "candyzone:E:<id>" ESC \
```

Format in `Manager.php` (lines 27-30):
```php
private const APC_PREFIX = "\x1b_";
private const APC_ST     = "\x1b\\";
private const TAG_START  = 'candyzone:S:';
private const TAG_END    = 'candyzone:E:';
```

The `mark()` method (`Manager.php:109-118`) wraps content:
```php
public function mark(string $id, string $content): string
{
    if (!$this->enabled) {
        return $content;
    }
    $fullId = $this->idPrefix . $id;
    return self::APC_PREFIX . self::TAG_START . $fullId . self::APC_ST
         . $content
         . self::APC_PREFIX . self::TAG_END . $fullId . self::APC_ST;
}
```

**Why APC rather than CSI?** APC (`ESC _ ... ESC \`) is a private use sequence that terminals ignore for display purposes. Crucially, it does not affect cursor positioning or width calculations, making it ideal for embedding invisible metadata in the output stream.

### Single-Pass State-Machine Scanner

`Manager::scan()` (`Manager.php:127-234`) performs a single left-to-right pass over the rendered view string:

```php
public function scan(string $rendered): string
{
    $clean = '';
    $row = 1;
    $col = 1;
    /** @var array<string, array{int,int,int,int}> $open id => [startCol,startRow,maxCol,maxRow] */
    $open = [];

    $len = strlen($rendered);
    $i = 0;
    while ($i < $len) {
        $b = $rendered[$i];

        // APC zone marker?
        if ($b === "\x1b" && ($rendered[$i + 1] ?? '') === '_') {
            $end = strpos($rendered, self::APC_ST, $i + 2);
            if ($end === false) {
                $clean .= substr($rendered, $i);
                break;
            }
            $payload = substr($rendered, $i + 2, $end - $i - 2);
            if (str_starts_with($payload, self::TAG_START)) {
                $id = substr($payload, strlen(self::TAG_START));
                $open[$id] = [$col, $row, $col, $row];
            } elseif (str_starts_with($payload, self::TAG_END)) {
                $id = substr($payload, strlen(self::TAG_END));
                if (isset($open[$id])) {
                    [$startCol, $startRow, $maxCol, $maxRow] = $open[$id];
                    // End marker sits *after* the last visible cell; back the end up by one.
                    $endCol = max($startCol, $col - 1);
                    $endRow = $row;
                    if ($endRow > $startRow && $col === 1) {
                        $endCol = $maxCol;
                        $endRow = $row - 1;
                    } else {
                        $endCol = max($endCol, $maxCol);
                        $endRow = max($endRow, $maxRow);
                    }
                    $this->zones[$id] = new Zone($id, $startCol, $startRow, $endCol, $endRow);
                    unset($open[$id]);
                }
            }
            $i = $end + strlen(self::APC_ST);
            continue;
        }

        // CSI sequence: pass through unchanged, no width.
        if ($b === "\x1b" && ($rendered[$i + 1] ?? '') === '[') {
            $j = $i + 2;
            while ($j < $len) {
                $c = ord($rendered[$j]);
                $j++;
                if ($c >= 0x40 && $c <= 0x7e) {
                    break;
                }
            }
            $clean .= substr($rendered, $i, $j - $i);
            $i = $j;
            continue;
        }

        // OSC sequence: pass through unchanged.
        if ($b === "\x1b" && ($rendered[$i + 1] ?? '') === ']') {
            $j = $i + 2;
            while ($j < $len) {
                if ($rendered[$j] === "\x07") { $j++; break; }
                if ($rendered[$j] === "\x1b" && ($rendered[$j + 1] ?? '') === '\\') { $j += 2; break; }
                $j++;
            }
            $clean .= substr($rendered, $i, $j - $i);
            $i = $j;
            continue;
        }

        if ($b === "\n") {
            foreach ($open as $id => $bounds) {
                [$sCol, $sRow, $maxCol, $maxRow] = $bounds;
                $open[$id] = [$sCol, $sRow, max($maxCol, $col - 1), max($maxRow, $row)];
            }
            $clean .= $b;
            $row++;
            $col = 1;
            $i++;
            continue;
        }

        // Plain visible character — measure grapheme width.
        $cluster = self::nextGrapheme($rendered, $i);
        $col    += Width::string($cluster);
        $clean  .= $cluster;
        $i      += strlen($cluster);
    }

    return $clean;
}
```

**Key behaviors:**
- APC markers are stripped (not counted toward width)
- CSI sequences (SGR color, cursor movement) pass through unchanged with zero width
- OSC sequences (title, clipboard) pass through unchanged with zero width
- Newlines track the rightmost column for each open zone before resetting column counter
- Grapheme width uses `Width::string()` from candy-core (supports wide East-Asian chars, emoji, zero-width combining marks)
- End column calculation has special logic for single-line vs. multi-line zones

### Zone Representation

`Zone.php` (61 lines) stores the discovered bounding box:

```php
final class Zone
{
    public function __construct(
        public readonly string $id,
        public readonly int $startCol,
        public readonly int $startRow,
        public readonly int $endCol,
        public readonly int $endRow,
    ) {}

    public function inBounds(MouseMsg $msg): bool
    {
        return $msg->x >= $this->startCol && $msg->x <= $this->endCol
            && $msg->y >= $this->startRow && $msg->y <= $this->endRow;
    }

    public function pos(MouseMsg $msg): array
    {
        return [$msg->x - $this->startCol, $msg->y - $this->startRow];
    }

    public function width(): int  { return $this->endCol - $this->startCol + 1; }
    public function height(): int { return $this->endRow - $this->startRow + 1; }

    public function isZero(): bool
    {
        return $this->startCol === 0
            && $this->startRow === 0
            && $this->endCol === 0
            && $this->endRow === 0;
    }
}
```

Coordinates are **1-based terminal cells** matching `MouseMsg::$x` / `MouseMsg::$y`.

### AABB Collision Detection

`Zone::inBounds()` uses the standard axis-aligned bounding box (AABB) test:

```
mouse.X ∈ [startCol, endCol] ∧ mouse.Y ∈ [startRow, endRow]
```

This is the same approach used by upstream bubblezone and ratatui. It handles rectangular regions correctly but produces false positives for organic shapes (the entire rectangular bounding box is clickable, not just the actual content area).

### Prefix-Based ID Generation

`Manager::newPrefix()` (`Manager.php:55-62`) creates an isolated namespace:

```php
public static function newPrefix(string $prefix = ''): self
{
    $m = new self();
    $m->idPrefix = $prefix !== ''
        ? $prefix
        : (string) (++self::$prefixCounter) . '-';
    return $m;
}
```

Two components with the same literal ID (e.g., `"item-0"`) can coexist when each uses its own prefixed manager. The prefix is prepended to every ID before storage and lookup.

### Mouse Event Routing

`Manager::anyInBounds()` (`Manager.php:284-295`) iterates all zones and returns the first hit:

```php
public function anyInBounds(Msg $mouse): ?Zone
{
    if (!$mouse instanceof MouseMsg) {
        return null;
    }
    foreach ($this->zones as $zone) {
        if ($zone->inBounds($mouse)) {
            return $zone;
        }
    }
    return null;
}
```

`Manager::anyInBoundsAndUpdate()` (`Manager.php:311-318`) wraps this in the TEA pattern:

```php
public function anyInBoundsAndUpdate(Model $model, Msg $mouse): array
{
    $zone = $this->anyInBounds($mouse);
    if ($zone !== null && $mouse instanceof MouseMsg) {
        return $model->update(new MsgZoneInBounds($zone, $mouse));
    }
    return $model->update($mouse);
}
```

---

## Feature Inventory

### Core: Manager

| Method | Description |
|--------|-------------|
| `newGlobal()` | Create global shared manager |
| `newPrefix(?string)` | Create prefixed manager for nested component isolation |
| `mark(id, content)` | Wrap content with APC zone markers |
| `scan(rendered)` | Record zone positions, strip markers, return clean frame |
| `get(id)` | Retrieve zone by id (null if not found) |
| `all()` | Get all recorded zones |
| `clear(?id)` | Drop one zone or all zones |
| `setEnabled(bool)` | Toggle zone tracking on/off |
| `isEnabled()` | Check if tracking is enabled |
| `close()` | Drop all zones + disable manager |
| `anyInBounds(MouseMsg)` | Return first zone under the mouse |
| `anyInBoundsAndUpdate(Model, Msg)` | Dispatch hit as MsgZoneInBounds through TEA model |
| `setMotionTracking(bool)` | Return CSI 1003 h/l escape sequence for SGR mouse mode 1003 |
| `prefix()` | Read-only prefix accessor |
| `newPrefix()` | Static factory for prefixed manager |

### Core: Zone

| Method | Description |
|--------|-------------|
| `inBounds(MouseMsg)` | AABB collision test |
| `pos(MouseMsg)` | Relative mouse position (0-based) as [col, row] |
| `width()` / `height()` | Zone dimensions in cells |
| `isZero()` | Detect uninitialized / degenerate zone |

### Core: Zones Facade

Static facade (`Zones.php`) mirroring upstream's package-level functions:
- `mark()`, `scan()`, `get()`, `all()`, `clear()`, `close()`
- `setEnabled()`, `isEnabled()`, `newPrefix()`, `anyInBounds()`, `anyInBoundsAndUpdate()`
- `setDefaultManager()` for swapping in custom managers (useful in tests)

### Tracker: ZoneHoverTracker

State machine tracking cursor hover across `MouseMsg` events (`ZoneHoverTracker.php`, 140 lines):

```php
public function update(MouseMsg $msg): array
{
    $hit = $this->manager->anyInBounds($msg);

    if ($hit === null) {
        if ($this->currentZoneId !== null) {
            $exited = $this->manager->get($this->currentZoneId);
            if ($exited !== null) {
                return [$this->mutate(null), new ZoneExitMsg($exited)];
            }
        }
        return [$this, null];
    }

    if ($hit->id === $this->currentZoneId) {
        return [$this, null]; // Still inside same zone — no transition.
    }

    // Zone boundary crossing detected.
    if ($this->currentZoneId !== null) {
        $exited = $this->manager->get($this->currentZoneId);
        if ($exited !== null) {
            return [$this->mutate(null), new ZoneExitMsg($exited)];
        }
    }

    return [$this->mutate($hit->id), new ZoneEnterMsg($hit)];
}
```

**Two-step boundary crossing:** Moving directly from zone A to zone B emits exit for A first; caller calls `update()` again to receive enter for B. This lets the Program animate the exit before routing the enter.

### Tracker: DragTracker

Tracks press → move → release drag sequences (`DragTracker.php`, 191 lines):

- `Press` inside a zone → `ZoneDragStartMsg` (originZone = currentZone at start)
- `Motion` while dragging → `ZoneDragMoveMsg` when cursor crosses zone boundary (origin fixed, current updates)
- `Release` while dragging → `ZoneDragEndMsg` (originZone = where drag started, currentZone = where released)

Origin zone is **fixed for the entire drag** and never changes. Current zone updates on every boundary crossing.

### Tracker: ClickCounter

Tracks double/triple click streaks (`ClickCounter.php`, 152 lines):

- Configurable click interval (default 500ms)
- Streak resets when: interval expires, cursor moves to different zone, non-press event
- Emits `DoubleClickMsg` on second press, `TripleClickMsg` on third press

---

## Strengths

### 1. Elegant Problem Decomposition

The zone marker approach cleanly separates concerns:
- **Components** only need to call `mark(id, content)` — no coordinate math
- **Root view** calls `scan(frame)` once — zone bounds are discovered automatically
- **Event handlers** call `anyInBounds(mouse)` or `get(id)->inBounds(mouse)` — simple API

This is fundamentally superior to manual coordinate tracking, which requires every component to know its screen position.

### 2. Zero-Width Markers Are Layout-Safe

APC escape sequences are ignored by terminals for display and are stripped before `Width::string()` measurement. This means zone markers don't break layout calculations — a critical property for integration with styled output from `candy-sprinkles` / lipgloss.

### 3. Synchronous Scan Simplifies Reasoning

The PHP port uses a synchronous `scan()` without a background worker goroutine. While this differs from upstream's channel-based approach, it eliminates concurrency complexity and race conditions. The zone map is ready immediately after `scan()` returns.

### 4. Comprehensive Tracker Suite

Three layered trackers provide increasingly sophisticated behavior:
- Raw `anyInBounds()` for simple click routing
- `ZoneHoverTracker` for enter/exit events (tooltips, highlights)
- `DragTracker` for drag-and-drop sequences
- `ClickCounter` for double/triple click detection

### 5. Full Edge Case Coverage

Tests cover:
- Wide East-Asian characters (CJK counts as 2 cells)
- Zero-width graphemes (ZWSP, combining marks don't inflate zone width)
- Multi-line zones (end-of-line tracking for open zones)
- End marker without start (silently ignored)
- Rescan replaces previous bounds (layout changes detected)
- Prefixed managers don't collide
- Disabled mode skips all operations

### 6. Prefix Isolation for Nested Components

`Manager::newPrefix()` enables multiple CandyZone-aware components to coexist without ID collisions. The `nested-components.php` example demonstrates two lists both using `"item-0"` without conflict.

### 7. Integration with candy-core TEA

`anyInBoundsAndUpdate()` follows the TEA pattern `[Model, ?Cmd]`, dispatching `MsgZoneInBounds` through the model's `update()` method — idiomatic for the SugarCraft runtime.

---

## Weaknesses

### 1. Only AABB Collision Detection

Like upstream bubblezone, candy-zone uses axis-aligned bounding box collision. For rectangular UI (buttons, list items, panels), this is correct. For organic shapes (circular buttons, ASCII art borders), the empty corners of the bounding box are also clickable — no native support for precise hit testing.

**Workaround:** Manually pad zones to exclude non-interactive regions, or use a smaller inner zone and accept the outer margin as inert.

### 2. No Native Overlapping Zone Deduplication

When two zones visually overlap (e.g., a button inside a panel), `anyInBounds()` returns the **first** hit (iterated in insertion order, not z-order). There is no automatic deduplication or z-order handling.

**Workaround:** Caller must implement deduplication if overlapping interactive elements are needed.

### 3. Scan Must Run on Full Root Frame

`scan()` must be called on the complete root view, not on individual sub-trees. Nested zones' screen coordinates depend on the full layout context. This is a fundamental limitation of the marker-based approach.

**Implication:** Every render cycle must scan the entire frame, not just changed regions. For very large terminal frames with many zones, this could be a performance consideration.

### 4. Byte-Offset Grapheme Parsing Without intl Extension

`Manager::nextGrapheme()` (`Manager.php:324-342`) falls back to UTF-8 byte counting when `grapheme_extract()` is unavailable:

```php
private static function nextGrapheme(string $s, int $i): string
{
    if (function_exists('grapheme_extract')) {
        $next = 0;
        $cluster = grapheme_extract($s, 1, GRAPHEME_EXTR_COUNT, $i, $next);
        if (is_string($cluster) && $cluster !== '') {
            return $cluster;
        }
    }
    // Fallback to UTF-8 byte parsing
    $b = ord($s[$i]);
    $bytes = match (true) {
        ($b & 0x80) === 0    => 1,
        ($b & 0xe0) === 0xc0 => 2,
        ($b & 0xf0) === 0xe0 => 3,
        ($b & 0xf8) === 0xf0 => 4,
        default              => 1,
    };
    return substr($s, $i, $bytes);
}
```

This fallback only handles single-byte graphemes correctly. Actual grapheme clusters (like `ö` as `o` + combining diaeresis, or emoji with skin tone modifiers) would be split into separate "characters" in the fallback path, potentially miscounting width.

**Recommendation:** The `ext-intl` extension (which provides `grapheme_extract`) should be declared as a recommended dependency in `composer.json`.

### 5. Static Singleton in Zones Facade

The `Zones` facade (`Zones.php`) uses a static `?Manager $default` singleton. While `setDefaultManager()` exists for test cleanup, this pattern:
- Makes parallel testing harder (shared global state)
- Can cause subtle state leakage between tests

The README documents this: `Zones::setDefaultManager(null)` flushes state in teardown.

### 6. APC Sequence Not Universally Supported

While most modern terminals ignore APC sequences, some legacy or minimal terminals may not handle them correctly. The README notes markers are "APC escape sequences (ESC _ ... ESC \)" that "terminals ignore them."

### 7. Iteration-Based Invalidation Not Implemented

Upstream bubblezone uses `time.Now().Nanosecond()` as an iteration marker to automatically evict stale zones from previous renders. candy-zone's `scan()` directly replaces all zone bounds for the same ID — which achieves the same effect but without the automatic stale-zone cleanup for IDs that no longer appear in the view.

---

## Comparison with Upstream bubblezone (Go)

| Feature | bubblezone (Go) | candy-zone (PHP) |
|---------|-----------------|------------------|
| Marker format | CSI `\x1B[<num>z` | APC `\x1b_ candyzone:S: <id> \x1b\\` |
| Zone storage | Goroutine + channel | Synchronous array |
| Width measurement | `go-runewidth.RuneWidth()` | `Width::string()` from candy-core |
| ID generation | Atomic `int64` counter | Static `int` counter |
| Iteration invalidation | `time.Now().Nanosecond()` | Direct replacement on rescan |
| Motion tracking | SGR mode 1003 via `SetMotionTracking()` | Same CSI sequences |
| Hover tracking | `NewZoneHoverTracker()` | `ZoneHoverTracker` |
| Drag tracking | `NewZoneDragTracker()` | `DragTracker` |
| Click tracking | `NewZoneClickTracker()` | `ClickCounter` |

**Key architectural difference:** bubblezone uses a background goroutine (`zoneWorker()`) with a channel (`setChan`) to decouple scanning from zone storage. candy-zone computes everything synchronously inside `scan()`. This means:
- bubblezone: scan() is non-blocking, zones appear after worker processes them
- candy-zone: scan() returns immediately with complete zone map

For single-threaded PHP, the synchronous approach is actually more natural and avoids concurrency bugs.

---

## Comparison with textualize/textual (Python)

Textual uses a fundamentally different approach to mouse event routing. Its compositor maintains a **spatial map** (`_spatial_map.SpatialMap`) — an R-tree-like structure for O(log n) widget lookup by screen position:

```python
# Textual's spatial map enables efficient hit detection
widget = self._compositor._spatial_map.hit_test(mouse_x, mouse_y)
```

This is more sophisticated than bubblezone's flat zone array iteration (O(n)). However, Textual's approach requires the compositor to know the screen position of every widget, which is computed during the layout pass. Textual widgets declare their size via CSS; the layout engine assigns actual coordinates.

**candy-zone advantage:** Marker-based approach works without a layout engine — any rendered string with markers is scannable.

**textual advantage:** O(log n) lookup vs O(n) iteration, plus proper z-ordering for overlapping widgets.

---

## Comparison with ratatui (Rust)

ratatui delegates event handling to backend libraries (crossterm) and provides no built-in zone tracking. Widget click handling is typically implemented manually using the widget's `Rect` (x, y, width, height):

```rust
if mouse_pos.x >= block.x && mouse_pos.x < block.x + block.width
    && mouse_pos.y >= block.y && mouse_pos.y < block.y + block.height
{
    // clicked on block
}
```

This is the same AABB pattern but **inline** in each widget's event handler — the opposite of bubblezone's centralized marker approach. ratatui apps must implement their own coordinate tracking for nested components.

**ratatui's strength:** No invisible markers needed, simpler for single-component apps.
**bubblezone/candy-zone strength:** Works without explicit coordinate tracking in components.

---

## Integration with candy-core and candy-sprinkles

### candy-core Integration

candy-zone uses `SugarCraft\Core\Msg\MouseMsg` for coordinates:

```php
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;

// Create a synthetic mouse event for testing
$click = new MouseMsg(5, 1, MouseButton::Left, MouseAction::Press);
```

The `MouseMsg` class (`candy-core/src/Msg/MouseMsg.php`) is the standard bubbletea mouse event type, with 1-based coordinates matching terminal reporting.

### candy-sprinkles Integration

Zone markers interact cleanly with styled output from candy-sprinkles:

```php
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;

$btn = $mgr->mark('btn:ok', Style::new()->padding(0, 2)->border(Border::rounded())->render('OK'));
// scan() strips markers before measuring width, so lipgloss calculations are unaffected
$clean = $mgr->scan($btn);
```

The README explicitly notes: "lipgloss.Width() (CandySprinkles) and CandyZone interact cleanly: scan() strips markers before measurement."

---

## Test Coverage Analysis

### ManagerTest (347 lines)

Tests core Manager functionality:
- `testMarkWrapsContentWithApcMarkers` — verifies marker format
- `testScanStripsMarkersFromOutput` — clean output verification
- `testScanRecordsSimpleZone` — zone bounds computation
- `testZoneWidthAndHeight` — dimension accessors
- `testInBoundsHits` — corner and interior hit detection
- `testRelativePosition` — `pos()` relative coordinates
- `testTwoSideBySideZones` — multi-zone positioning
- `testZoneAcrossLines` — multi-line zone bounds
- `testAnsiStylingDoesNotShiftZone` — ANSI sequences ignored
- `testCjkWideCharCountsAsTwoCells` — wide character handling
- `testZeroWidthGraphemeDoesNotInflateZone` — ZWSP/combining marks
- `testRescanReplacesPreviousBounds` — dynamic layout updates
- `testSetEnabledFalseSkipsMarkers` — disabled mode
- `testNewPrefixGeneratesUniqueIds` — prefix isolation
- `testAnyInBoundsAndUpdateRoutesHitToModelAsMsgZoneInBounds` — TEA integration

### ZoneTest (88 lines)

Tests Zone class:
- Corner and interior hit testing
- Relative position calculation (including negative for out-of-bounds)
- Width/height computation
- `isZero()` detection of degenerate zones

### ZonesTest (87 lines)

Tests the static facade:
- Lazy default manager construction
- `setDefaultManager()` override
- Mark/scan round-trip
- Clear (all and targeted)

### ZoneHoverTrackerTest (236 lines)

Tests hover state machine:
- Zone enter/exit detection
- Same-zone motion produces no message
- Two-step boundary crossing (exit then enter)
- Cursor in no zone stays null
- `currentZone()` accessor

### DragTrackerTest (253 lines)

Tests drag sequence tracking:
- Press outside zone: no drag start
- Press inside zone: `ZoneDragStartMsg`
- Motion within same zone: no message
- Motion crossing boundary: `ZoneDragMoveMsg` (origin fixed, current updates)
- Release: `ZoneDragEndMsg`
- Cross-zone drag: origin stays fixed, current follows cursor

### ClickCounterTest (203 lines)

Tests multi-click tracking:
- Single click: no message, streak = 1
- Second click within interval: `DoubleClickMsg`
- Third click within interval: `TripleClickMsg`
- Fourth+ click: no message (streak continues internally)
- Outside zone: streak reset
- Zone change: streak reset
- Motion events: no-op
- Interval expiry: streak reset

---

## Notable Implementation Details

### Grapheme Width Handling

`Manager::nextGrapheme()` prefers `grapheme_extract()` (intl extension) with a UTF-8 byte-parsing fallback. The fallback only handles single-unit graphemes — multi-unit graphemes (emoji with modifiers, composed characters) may be split incorrectly.

**Test coverage:** `testZeroWidthGraphemeDoesNotInflateZone` verifies ZWSP (U+200B) doesn't inflate zone width. `testCjkWideCharCountsAsTwoCells` verifies Japanese characters count as 2 cells.

### End Column Calculation

When an end marker appears at the start of a new line (`col === 1` after increment), the scanner uses the **previous row's** rightmost column as the end column:

```php
if ($endRow > $startRow && $col === 1) {
    $endCol = $maxCol;
    $endRow = $row - 1;
}
```

This handles the common case of a zone ending with a newline character.

### Prefix Collision Prevention

`Manager::$prefixCounter` is a **class-level static** incremented per manager instantiation, not per prefix string. This means:

```php
$a = Manager::newPrefix(); // prefix "1-"
$b = Manager::newPrefix(); // prefix "2-"
```

Each manager gets a unique prefix regardless of whether the caller passes an explicit string.

---

## Recommendations

### 1. Declare ext-intl as Recommended Dependency

The `grapheme_extract()` function should be available in all environments running TUI apps. Add to `composer.json`:

```json
"suggest": {
    "ext-intl": "Required for correct grapheme cluster handling in scan()"
}
```

### 2. Consider Z-Order Support for Overlapping Zones

The current design returns the first hit in insertion order. For overlapping zones (button inside panel), this may not match the desired z-order. Consider adding an optional z-index parameter to `mark()` and sorting by z before iteration in `anyInBounds()`.

### 3. Spatial Map for O(log n) Hit Testing

For apps with many zones (100+), the O(n) iteration in `anyInBounds()` could become a bottleneck. Consider adding an optional R-tree-like spatial index (similar to textual's `_spatial_map.SpatialMap`) for O(log n) lookup. This would be especially valuable for `candy-sprinkles` Canvas overlay systems.

### 4. Snapshot Testing for Zone Bounds

The scanner's handling of complex cases (nested ANSI, multi-line zones, wide characters) would benefit from snapshot tests that assert the exact zone bounds for known inputs. This would guard against regressions as the codebase evolves.

---

## Conclusion

candy-zone is a well-executed, complete port of bubblezone that faithfully replicates the upstream design while adapting to PHP's synchronous execution model. Its marker-based approach elegantly solves the mouse event routing problem without requiring components to track their own screen coordinates. The layered tracker suite (hover, drag, click) provides progressively sophisticated behavior on top of the core `anyInBounds()` primitive.

The main limitations — AABB-only collision detection, no z-ordering for overlapping zones, and O(n) zone iteration — are inherited from upstream and are acceptable tradeoffs for the design's simplicity. The `ext-intl` fallback concern and static facade singleton are addressable improvements.

The port is production-ready and integrates cleanly with candy-core's TEA runtime and candy-sprinkles' styling system.
