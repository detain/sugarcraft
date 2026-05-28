# sugar-veil — Innovation & Comparison Report

## Metadata
- **SugarCraft Library:** `sugar-veil`
- **Porting Source:** `rmhubbert/bubbletea-overlay` (primary), overlay patterns from `charmbracelet/lipgloss` v2 Layer/Compositor system
- **Status:** v1 Ready
- **Depends on:** `candy-core` (runtime), `candy-sprinkles` (Border/Style), `candy-zone` (Manager), `honey-bounce` (CubicBezier easing)
- **Namespace:** `SugarCraft\Veil`
- **Composer:** `sugarcraft/sugar-veil`

---

## Feature Matrix

| Feature | bubbletea-overlay (upstream) | sugar-veil (PHP port) | Enhancement? |
|---------|----------------------------|----------------------|---------------|
| Positioning | 5 positions (T/R/B/L/Center) | 9 positions (+ 4 corners) | Yes — extended |
| Multi-overlay stack | No | Yes (`VeilStack`) | Yes — new |
| Z-index ordering | No | Yes (int z-index per Veil) | Yes — new |
| Animation | No | Yes (Slide/Fade/Scale + CubicBezier) | Yes — new |
| Backdrop dimming | No | Yes (ANSI SGR dim 0–100) | Yes — new |
| Auto-size | No | Yes (compute from bordered content) | Yes — new |
| Border chrome | No | Yes (candy-sprinkles Border) | Yes — new |
| Click-outside dismiss | No | Yes (candy-zone Manager hit testing) | Yes — new |
| UTF-8 multibyte handling | Via `charmbracelet/x/ansi` | Via `mb_str_split` + byte-level replacement | Parity |
| Immutable pattern | No (Go struct) | Yes (readonly props + mutate()) | Adapted |

---

## Overlay Compositor Architecture

### Core Class: `Veil` (`src/Veil.php`)

The `Veil` class is the fundamental overlay compositor. It is a `final` class with all state held in `readonly` private properties, following the immutable + fluent SugarCraft pattern.

```
SugarCraft\Veil\Veil
├── readonly int $backdropOpacity     // 0–100, backdrop dimming strength
├── readonly ?AnimationKind $animationKind // SLIDE | FADE | SCALE | null
├── readonly int $zIndex              // Stacking order (higher = on top)
├── readonly bool $clickOutsideDismiss // Flag for outside-click dismissal
├── readonly bool $autoSize            // Compute dims from bordered content
├── readonly ?Border $border          // Border chrome wrapper
├── readonly ?Manager $manager        // candy-zone hit-testing manager
```

**Factory:** `Veil::new()` — zero-arg default instance.

**Configuration chain (all return new instance via `mutate()`):**
- `withBackdrop(int $opacity): self` — set backdrop opacity 0–100
- `withAnimation(AnimationKind $kind): self` — set transition animation
- `withZIndex(int $zIndex): self` — set stacking order
- `withClickOutsideDismiss(bool $enabled = true): self` — enable outside-click dismiss
- `withAutoSize(bool $enabled = true): self` — compute dimensions from bordered content
- `withBorder(Border $border): self` — apply terminal border chrome
- `withManager(Manager $manager): self` — inject candy-zone Manager for hit testing

**Rendering methods:**
- `composite(string $foreground, string $background, Position $v, Position $h, int $xOff = 0, int $yOff = 0): string` — merge overlay onto background
- `animate(string $foreground, string $background, Position $v, Position $h, float $progress, int $xOff = 0, int $yOff = 0): string` — composite with animation at given progress

**Accessors:** `zIndex()`, `clickOutsideDismiss()`, `autoSize()`, `border()`, `manager()`, `isClickOutside(MouseMsg $mouse): bool`

### Composite Algorithm (`Veil::composite()` at lines 316–383)

The compositing follows this sequence:

1. **Split lines** — `splitLines()` splits both strings on `\n`, stripping trailing empty line
2. **Apply border chrome first** — if `autoSize` is enabled, `applyBorderChrome()` wraps foreground before dimension measurement
3. **Measure dimensions** — `maxLineWidth()` finds the widest line for each
4. **Apply backdrop dimming** — if `backdropOpacity > 0`, `applyBackdrop()` wraps each background line with ANSI SGR dim codes (`\x1b[2m`); 0–100 maps to 0–3 passes via `\max(0, \min(3, \round($opacity / 33)))`
5. **Resolve position offset** — `Position::xOffset()` and `Position::yOffset()` compute base coordinates
6. **Apply user offsets and clamp** — offsets added, then clamped to `[0, bgWidth-1]` / `[0, bgHeight-1]`
7. **Character-by-character merge** — for each foreground character, `replaceCharAt()` performs byte-level UTF-8 safe replacement in the background line

**UTF-8 multibyte handling** (`Veil::replaceCharAt()` at lines 461–521):
- Iterates byte-by-byte through the line, tracking column position
- For bytes `>= 0x80`, uses byte pattern to determine character width (2–4 bytes)
- When target column is reached, skips the old character bytes and inserts the new one
- Handles the case where target position is beyond line end (pads and appends)

### Stack Class: `VeilStack` (`src/VeilStack.php`)

`VeilStack` is an ordered collection of `Veil` instances that renders them by z-index.

```php
final class VeilStack implements \Countable
{
    private array $veils = [];

    // Fluent additions (all return new instance)
    public function add(Veil $veil): self
    public function clear(): self
    public function removeWhere(\Closure $predicate): self
    public function filter(\Closure $predicate): self

    // Rendering
    public function composite(string $background, Position $v, Position $h, int $xOff = 0, int $yOff = 0): string
    public function compositeAll(string $background): string  // each veil uses its own position

    // Query
    public function sorted(): array        // z-index ascending
    public function all(): array         // insertion order
    public function maxZIndex(): int
    public function minZIndex(): int
    public function isEmpty(): bool
    public function count(): int
}
```

**Z-ordering algorithm** (`VeilStack::composite()` at lines 71–85):
1. Sort veils ascending by z-index via `sorted()`
2. Initialize `$result = $background`
3. For each veil in sorted order: `$result = $veil->composite($result, ...)`
4. Return final composited string

This chaining pattern ensures each subsequent veil layers on top of the previous composite result.

---

## Z-Ordering Implementation

### Per-Veil Z-Index
- Default z-index is `0` (defined in `Veil::__construct()` at line 63)
- `zIndex` is a `readonly int` set at construction time
- `withZIndex(int $zIndex)` returns a new Veil via `mutate()` — original is unchanged
- Negative z-indexes are allowed and work correctly (tested in `VeilStackTest::testSortedOrdersByZIndexAscendingWithNegatives()`)

### VeilStack Sort
- `VeilStack::sorted()` uses `\usort()` with spaceship operator: `\fn(Veil $a, Veil $b): int => $a->zIndex() <=> $b->zIndex()`
- Stable sort — equal z-indexes maintain insertion order
- `maxZIndex()` / `minZIndex()` iterate all veils, tracking extrema

### Render Order = Composite Order
The key insight (documented in CALIBER_LEARNINGS.md): `VeilStack::composite()` feeds each veil's output as the **next** veil's background. So:
- z=0 veil composites onto raw background → result
- z=1 veil composites onto z=0 result → result
- z=2 veil composites onto z=1 result → final

This is why z-index ascending (lowest first) produces correct visual stacking.

### Contrast with Upstream
- **rmhubbert/bubbletea-overlay**: No stacking — single foreground/background pair only
- **charmbracelet/lipgloss v2 Layer/Compositor**: Proper z-sorted layer hierarchy with `Hit()` mouse resolution and `Bounds()` extent computation

---

## Positioning System

### Position Enum (`src/Position.php`)

Nine cases covering all compass points and the center:

```php
enum Position {
    case TOP;
    case RIGHT;
    case BOTTOM;
    case LEFT;
    case CENTER;
    case TOP_RIGHT;
    case BOTTOM_RIGHT;
    case BOTTOM_LEFT;
    case TOP_LEFT;
}
```

### Offset Resolution

**`yOffset(int $fgHeight, int $bgHeight): int`** (lines 28–41):
```php
return match ($this) {
    self::TOP, self::TOP_RIGHT, self::TOP_LEFT        => 0,
    self::BOTTOM, self::BOTTOM_RIGHT, self::BOTTOM_LEFT => $bgHeight - $fgHeight,
    self::CENTER, self::LEFT, self::RIGHT              => (int) \floor(($bgHeight - $fgHeight) / 2),
};
```

**`xOffset(int $fgWidth, int $bgWidth): int`** (lines 47–60):
```php
return match ($this) {
    self::LEFT, self::TOP_LEFT, self::BOTTOM_LEFT      => 0,
    self::RIGHT, self::TOP_RIGHT, self::BOTTOM_RIGHT    => $bgWidth - $fgWidth,
    self::CENTER, self::TOP, self::BOTTOM             => (int) \floor(($bgWidth - $fgWidth) / 2),
};
```

### Offset Fine-Tuning
`xOffset` and `yOffset` parameters to `composite()` allow per-call adjustment after position resolution:
```php
$veil->composite($fg, $bg, Position::BOTTOM, Position::LEFT, xOffset: 2, yOffset: -1);
```

### Comparison with Upstream
| Library | Position Count | Corner Support | Offset Fine-Tuning |
|---------|---------------|----------------|---------------------|
| rmhubbert/bubbletea-overlay | 5 | No | Yes (x/y offset params) |
| sugar-veil | 9 | Yes (4 corners) | Yes (x/y offset params) |
| daltonsw/bubbleup | 6 | Yes (no center) | No |
| charmbracelet/lipgloss Layer | Float 0–1 | N/A (coordinate-based) | N/A (absolute positioning) |

---

## Teardown Lifecycle

### Click-Outside Dismiss Flow

The click-outside dismiss mechanism involves three components working together:

1. **Veil configuration** — `withClickOutsideDismiss(true)->withManager($manager)` enables the feature
2. **Hit testing** — `isClickOutside(MouseMsg $mouse): bool` queries the Manager
3. **Consumer action** — the TUI model calls `isClickOutside()` and removes the veil from the stack

```php
// Example from README (lines 214–224)
$veil = Veil::new()
    ->withClickOutsideDismiss()
    ->withManager($manager);

$outside = $veil->isClickOutside($mouseMsg);
if ($outside) {
    // dismiss the veil — remove from VeilStack via removeWhere()
}
```

**`isClickOutside()` implementation** (`Veil.php` lines 221–227):
```php
public function isClickOutside(\SugarCraft\Core\Msg\MouseMsg $mouse): bool
{
    if (!$this->clickOutsideDismiss || $this->manager === null) {
        return false;
    }
    return $this->manager->anyInBounds($mouse) === null;
}
```

Returns `false` when:
- `clickOutsideDismiss` is `false` (feature disabled)
- `manager` is `null` (no spatial database wired up)

When both are set, delegates to `Manager::anyInBounds()` — returns `null` when click is outside all tracked zones.

### Multiple Veils Sharing a Manager
`Manager` can track multiple veil zones simultaneously. Multiple veils can share the same `Manager` instance for coordinated spatial hit testing:
```php
$manager = Manager::new();
$modal = Veil::new()->withManager($manager)->withClickOutsideDismiss();
$tooltip = Veil::new()->withManager($manager)->withZIndex(5);
```

### VeilStack Removal
Veils are removed via predicates, not direct references:
```php
$stack = $stack->removeWhere(fn(Veil $v): bool => $v->zIndex() === 2);
// or
$stack = $stack->removeWhere(fn(Veil $v): bool => $v->isClickOutside($mouse));
```

---

## Animation System

### AnimationKind Enum (`src/Animation/AnimationKind.php`)
```php
enum AnimationKind {
    case SLIDE;
    case FADE;
    case SCALE;
}
```

### Animation Infrastructure

All animations consume `SugarCraft\Bounce\Easing\CubicBezier` from honey-bounce:
- **Slide** → `CubicBezier::easeOut()`
- **Fade** → `CubicBezier::easeInOut()`
- **Scale** → `CubicBezier::easeOut()`

Custom easing can be injected via constructor; null falls back to defaults.

### Slide Animation (`src/Animation/Slide.php`)

Returns **offset deltas** rather than modifying the foreground string. The animation factor = `1.0 - easedProgress` so at progress=0 the overlay is fully offset (off-screen) and at progress=1 it's at its final position.

```php
public function apply(string $foreground, float $progress, Position $v, Position $h): array
{
    $eased = $this->easing()->evaluate($progress);
    $factor = 1.0 - $eased;  // 1.0 → 0.0 as progress 0→1

    // Anchor detection determines which direction to slide FROM
    if ($this->isLeftAnchor($v, $h)) {
        $hOffset = (int) \round($factor * $fgWidth);   // positive → slides in from left
    } elseif ($this->isRightAnchor($v, $h)) {
        $hOffset = -(int) \round($factor * $fgWidth);  // negative → slides in from right
    }
    // ... similar for vertical
}
```

The returned `verticalOffset` / `horizontalOffset` are **added** to the composite call's offset parameters:
```php
// Veil::animate() at lines 241–262
if ($this->animationKind !== null && $progress < 1.0) {
    $result = $this->applyAnimation($foreground, $progress, $vertical, $horizontal);
    $animFg = $result['foreground'];
    $animXOffset = $xOffset + $result['horizontalOffset'];
    $animYOffset = $yOffset + $result['verticalOffset'];
}
return $this->composite($animFg, $background, $vertical, $horizontal, $animXOffset, $animYOffset);
```

### Fade Animation (`src/Animation/Fade.php`)

**Terminal limitation acknowledged**: True per-character alpha blending is not reliably supported in terminal emulators. `Fade::apply()` returns the foreground unchanged. The easing calculation is still performed so callers can implement their own opacity handling via `opacity(float $progress): int` (returns 0–100).

### Scale Animation (`src/Animation/Scale.php`)

Reveals lines from the center of the foreground outward as progress increases:
```php
$visibleCount = (int) \max(1, \round($eased * $totalLines));
$center = $totalLines / 2;
$startLine = (int) \floor($center - $visibleCount / 2);
$scaledLines = \array_slice($lines, $startLine, $visibleCount);
```

---

## Viewable Interface

Unlike upstream `rmhubbert/bubbletea-overlay` which uses a `Viewable` interface (`View() string`), sugar-veil takes a simpler approach: `composite()` accepts raw strings. The veil itself does not implement `tea.Model` or any equivalent interface — it is a pure renderer utility.

This design choice makes sugar-veil **framework-agnostic**:
- No dependency on `candy-core` for the compositing itself
- Works with any TUI framework (or even non-TUI contexts)
- The candy-core dependency is only needed for `MouseMsg` type hint in `isClickOutside()`

Consumers render their models first, then call `Veil::composite()` on the resulting strings:
```php
// SugarCraft model produces strings
$bg = $model->view();
$fg = $modalModel->view();

// Composite in final view
return $veil->composite($fg, $bg, Position::CENTER, Position::CENTER);
```

---

## Integration with candy-core, candy-sprinkles, candy-zone, honey-bounce

### candy-core
- **MouseMsg** type for `isClickOutside()` parameter
- **Width** utility (`SugarCraft\Core\Util\Width`) for `lineWidth()` / `strWidth()`
- **Ansi** utility (`SugarCraft\Core\Util\Ansi`) for `applyBackdrop()` (SGR codes)

### candy-sprinkles
- **Border** — terminal border chrome (`withBorder()` accepts `Border` instance)
- **Style** — `Style::new()->border($border)->render($content)` for `applyBorderChrome()`

### candy-zone
- **Manager** — spatial database for click-outside hit testing (`withManager()` / `isClickOutside()`)

### honey-bounce
- **CubicBezier** easing — used by all three animation types (Slide, Fade, Scale)
- Default easing: `CubicBezier::easeOut()` for Slide/Scale, `CubicBezier::easeInOut()` for Fade

---

## Key Implementation Details

### Backdrop Dimming Algorithm (`Veil::applyBackdrop()` at lines 391–418)

Converts 0–100 opacity to ANSI SGR dim passes:
```php
$dimPasses = (int) \round($this->backdropOpacity / 33); // 0-100 → 0-3 passes
$dimPasses = \max(0, \min(3, $dimPasses));
```

Each pass wraps a line: `$dimCode . $line . $resetCode`. Multiple passes nest cleanly for stronger dimming. Uses `Ansi::FAINT` (SGR code 2) which is "reduced intensity."

### UTF-8 Character Replacement (`Veil::replaceCharAt()` at lines 461–521)

The algorithm tracks column position byte-by-byte:
- For ASCII bytes (`< 0x80`): single column, advance 1 byte
- For multibyte (`>= 0x80`): determine width from byte pattern, include full character
- When target column reached: skip old char bytes, insert new char, concatenate remainder
- Beyond line end: pad with spaces and append

### Backdrop Applied to Background Before Compositing

`Veil::composite()` applies backdrop dimming **before** computing position offsets (line 342–344):
```php
if ($this->backdropOpacity > 0) {
    $bgLines = $this->applyBackdrop($bgLines);
}
```

This means the dimmed background is what gets overlaid, not the original.

### Auto-Size Computes Dimensions from Bordered Content

When `autoSize` is `true`, the border is applied **before** measuring dimensions (lines 329–331):
```php
if ($this->autoSize) {
    $foreground = $this->applyBorderChrome($foreground);
}
$fgLines  = $this->splitLines($foreground);
$fgHeight = \count($fgLines);
$fgWidth  = $this->maxLineWidth($fgLines);
```

Without `autoSize`, foreground dimensions are measured raw and the border is NOT accounted for.

---

## Comparison: sugar-veil vs. Upstream

### vs. rmhubbert/bubbletea-overlay

| Aspect | bubbletea-overlay | sugar-veil |
|--------|------------------|-----------|
| Positions | 5 | 9 (adds 4 corners) |
| Multi-overlay | No | Yes (VeilStack) |
| Animation | No | Yes (Slide/Fade/Scale + CubicBezier) |
| Backdrop dimming | No | Yes (ANSI SGR) |
| Border chrome | No | Yes (candy-sprinkles) |
| Click-outside dismiss | No | Yes (candy-zone) |
| Auto-size | No | Yes |
| Z-index | No | Yes (per-Veil int) |
| Architecture | tea.Model wrapper + standalone Composite() | Standalone string compositor |
| UTF-8 handling | Via `charmbracelet/x/ansi` | Native `mb_str_split` + byte-level |
| Language paradigm | Go struct (mutable) | PHP immutable (readonly + mutate) |

### vs. Genekkion/theHermit

The Hermit's quick-fix list overlay has a different focus (list navigation with background updating). sugar-veil's comparable features:
- **Background continues updating** — veils are composited fresh each frame, background string can be re-rendered
- **ANSI-aware** — both handle ANSI escape codes correctly
- **Center positioning** — Hermit centers vertically; sugar-veil supports 9 positions

Key difference: The Hermit is a `tea.Model` component; sugar-veil is a string utility usable by any layer.

### vs. daltonsw/bubbleup

Bubbleup is an alert/toast overlay library. sugar-veil provides the **general-purpose compositor** that could be used to implement bubbleup-style alerts.

| Aspect | bubbleup | sugar-veil |
|--------|----------|-----------|
| Alert types | Info/Warning/Error/Debug (built-in + custom) | None (raw string compositor) |
| Positions | 6 | 9 |
| Z-index | No | Yes |
| Animation | Color blend only (LAB) | Slide/Fade/Scale + CubicBezier |
| Multiple concurrent | No (single alert) | Yes (VeilStack) |
| Click dismiss | No | Yes (click-outside) |
| Render pattern | `Render(content string) string` | `composite(fg, bg, ...) string` |
| Immutable | Yes (With*() methods) | Yes (with*() + mutate()) |

### vs. charmbracelet/lipgloss v2 Layer/Compositor

Lipgloss v2 introduced a `Layer`/`Compositor`/`Canvas` system with z-ordering and mouse hit-testing. This is the closest upstream architectural equivalent to sugar-veil's multi-overlay stack.

| Aspect | lipgloss Layer/Compositor | sugar-veil VeilStack |
|-------|--------------------------|---------------------|
| Z-ordering | Yes (z float per Layer) | Yes (int zIndex per Veil) |
| Hit testing | Yes (Hit(x,y) returns topmost Layer) | Yes (isClickOutside via Manager) |
| Bounds computation | Yes (Bounds() returns Rectangle) | Via Manager (candy-zone) |
| Animation | No built-in | Yes (Slide/Fade/Scale) |
| Position system | Absolute X/Y coordinates | 9 position anchors + offsets |
| Cell buffer | Yes (Canvas implements uv.Screen) | No (string-based) |
| Framework coupling | lipgloss-only | Framework-agnostic |

Lipgloss v2's Layer system is a proper graphics compositor with cell buffers; sugar-veil is a string-overlay utility that can be used anywhere.

---

## Test Coverage

### VeilTest (`tests/VeilTest.php`, 438 lines)
- `testNew()`, `testSplitLines()`, `testSplitLinesIgnoresTrailingNewline()`
- `testMaxLineWidth()`, `testMaxLineWidthStripsAnsi()`
- `testLineWidth()`, `testLineWidthWithAnsi()`
- `testCompositeCentered()`, `testCompositeTopLeft()`, `testCompositeBottomRight()`
- `testCompositeWithOffset()`, `testCompositeClampStaysInBounds()`
- `testCompositePreservesBackgroundUnaffectedArea()`, `testCompositeMultiline()`
- `testCompositeReplacesOnlyForegroundCells()`
- `testEmptyBackground()`, `testEmptyForeground()`
- `testPositionYOffset()`, `testPositionXOffset()`
- z-index: `testZIndexDefaultsToZero()`, `testWithZIndex()`, `testZIndexIsImmutable()`, `testZIndexNegative()`
- click-outside-dismiss: `testClickOutsideDismissDefaultsToFalse()`, `testWithClickOutsideDismiss()`, `testClickOutsideDismissImmutable()`, `testIsClickOutsideReturnsFalseWhenDismissDisabled()`, `testIsClickOutsideReturnsFalseWhenManagerNotSet()`
- auto-size: `testAutoSizeDefaultsToFalse()`, `testWithAutoSize()`, `testAutoSizeImmutable()`
- border chrome: `testBorderDefaultsToNull()`, `testWithBorder()`, `testBorderImmutable()`, `testApplyBorderChromeReturnsContentUnchangedWhenNoBorder()`, `testApplyBorderChromeWrapsContentWithBorder()`, `testApplyBorderChromeWithThickBorder()`
- manager: `testManagerDefaultsToNull()`, `testWithManager()`, `testManagerIsImmutable()`, `testWithManagerReturnsNewInstance()`
- autoSize behavior: `testCompositeAutoSizeComputesDimensionsFromBorderedContent()`, `testCompositeAutoSizeWithoutBorderBehavesNormally()`, `testCompositeAutoSizeFalseUsesRawContentDimensions()`
- combination: `testChainingAllNewMethods()`, `testWithZIndexReturnsNewInstance()`

### VeilStackTest (`tests/VeilStackTest.php`, 158 lines)
- `testNew()`, `testAdd()`, `testClear()`, `testRemoveWhere()`
- `testSortedOrdersByZIndexAscending()`, `testSortedOrdersByZIndexAscendingWithNegatives()`
- `testCompositeRendersInZIndexOrder()` — key test verifying z-ordering correctness
- `testMaxZIndex()`, `testMinZIndex()`, `testEmptyStackMaxZIndex()`, `testEmptyStackMinZIndex()`
- `testFilter()`, `testFilterPreservesOriginal()`, `testAllReturnsAllVeils()`

### VeilAnimationTest (`tests/VeilAnimationTest.php`)
Tests animation integration — likely covers `animate()` method with progress values.

### Animation Tests (`tests/Animation/`)
- `FadeTest.php` — opacity calculation, easing
- `SlideTest.php` — offset direction based on anchor
- `ScaleTest.php` — center-out line reveal

---

## Notable Design Decisions

### 1. String-Based Compositing (Not Cell Buffer)

Unlike lipgloss v2's `Canvas` (which implements `uv.Screen` cell buffer), sugar-veil operates entirely on strings. This trades performance for simplicity and framework-agnosticism:
- No dependency on ultraviolet for cell buffers
- Works with any string-producing framework
- Performance is O(n*m) character writes; acceptable for typical TUI overlay sizes

### 2. Animation Returns Offset Deltas (Slide)

Slide animation returns `$verticalOffset` / `$horizontalOffset` offsets rather than a modified foreground string. This:
- Keeps the animation logic decoupled from the compositing logic
- Allows animation to be applied via the existing offset mechanism
- Makes Slide compatible with any foreground content

### 3. Backdrop Dimming Applied to Background Before Offset Calculation

The backdrop is dimmed **before** position offsets are computed. This ensures the dimmed area exactly matches the visible background, not the potentially-clipped overlay region.

### 4. No tea.Model / Model Interface

sugar-veil intentionally does not implement `candy-core`'s `Model` interface (unlike upstream bubbletea-overlay which wraps `tea.Model`). This is a deliberate design choice:
- Veils are stateless renderers, not stateful components
- The consumer's model manages state; veils just composite strings
- Prevents circular dependencies if the overlay content needs the main model

### 5. Manager Injection for Hit Testing

The `Manager` is injected via `withManager()`, not created internally. This:
- Allows multiple veils to share the same spatial database
- Keeps the Manager lifecycle under consumer control
- Avoids creating hidden internal state

---

## Weaknesses and Limitations

1. **No true alpha blending for FADE** — terminal emulators don't support per-character alpha; `Fade::apply()` returns foreground unchanged. The easing calculation is available but the visual effect is terminal-dependent.

2. **Single-threaded string compositing** — character-by-character replacement is O(n*m) where n=fg chars, m=bg lines. For very large overlays, a cell buffer approach (like lipgloss v2 Canvas) would be more efficient.

3. **No built-in model integration** — unlike upstream bubbletea-overlay's tea.Model wrapper, sugar-veil provides no `Model` interface implementation. Consumers must manually wire update/view cycles.

4. **No hit testing for visual bounds** — `isClickOutside()` relies on an external `Manager` from candy-zone. There is no self-contained click detection that uses the veil's own rendered dimensions.

5. **No Z-index collision handling** — when two veils share the same z-index, insertion order determines stacking. There is no explicit tie-breaking.

6. **Backdrop dim uses SGR code 2** — "faint" is not universally supported across terminal emulators. Some may interpret it as dimmer text color rather than reduced intensity.

7. **Animation progress must be driven externally** — `animate()` accepts a `float $progress` but does not perform the animation timing itself. Consumers implement the tick loop.

---

## Related SugarCraft Libraries

| Library | Relationship |
|---------|--------------|
| `candy-core` | Provides `Model` interface + `MouseMsg` + `Width` + `Ansi` utilities |
| `candy-sprinkles` | Provides `Border` and `Style` for border chrome |
| `candy-zone` | Provides `Manager` for spatial hit testing |
| `honey-bounce` | Provides `CubicBezier` easing for animations |
| `sugar-toast` | Uses sugar-veil pattern for floating notifications |
| `candy-sprinkles` Layer/Compositor | Related but more sophisticated cell-buffer approach |

---

## References

- **Source:** `/home/sites/sugarcraft/sugar-veil/`
- **Upstream:** https://github.com/rmhubbert/bubbletea-overlay
- **Primary Report:** `/home/sites/sugarcraft/repo_map/rmhubbert_bubbletea-overlay.md`
- **Examples:** `examples/basic.php`, `examples/multiple-overlays.php`
- **VHS Demos:** `.vhs/basic.tape`, `.vhs/multiple.tape`
