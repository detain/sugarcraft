# Overview

**sugar-veil** is SugarCraft's overlay/modal compositing library — a PHP port of `rmhubbert/bubbletea-overlay` (primary) with overlay patterns from `charmbracelet/lipgloss` v2 Layer/Compositor system. It provides string-based foreground-over-background compositing with positioning, z-index stacking, animations, backdrop dimming, border chrome, and click-outside dismissal via candy-zone.

**Biggest opportunity areas:** Cell-buffer-based rendering (lipgloss v2 Canvas), self-contained mouse hit-testing (bubblezone), proper event/animation tick integration, compositing-based layout primitives.

**Biggest missing capabilities:** No true cell buffer (string-based only), no built-in mouse hit-testing tied to rendered bounds, no animation tick loop, no composable layout system, no style-preserving text wrap integration.

---

# Internal Capability Summary

## Architecture

`Veil` is the core overlay compositor — a `final readonly` class with a `mutate()` helper for immutable fluent chaining. `VeilStack` provides ordered multi-overlay rendering sorted by z-index. `Position` enum provides 9 anchor positions. `AnimationKind` + `Slide`/`Fade`/`Scale` provide transition animations via honey-bounce `CubicBezier`.

```
SugarCraft\Veil\Veil (final class, readonly props)
├── backdropOpacity: int (0-100)
├── animationKind: ?AnimationKind (SLIDE|FADE|SCALE|null)
├── zIndex: int (stacking order)
├── clickOutsideDismiss: bool
├── autoSize: bool
├── border: ?Border (candy-sprinkles)
├── manager: ?Manager (candy-zone)
└── methods: withBackdrop(), withAnimation(), withZIndex(),
            withClickOutsideDismiss(), withAutoSize(),
            withBorder(), withManager(), composite(), animate()

SugarCraft\Veil\VeilStack (immutable stack)
├── veils: array
├── methods: add(), clear(), removeWhere(), filter(),
            composite(), compositeAll(), sorted(), all()
```

## Current Features

- **9 positioning anchors**: TOP, BOTTOM, LEFT, RIGHT, CENTER + 4 corners (TOP_RIGHT, BOTTOM_RIGHT, BOTTOM_LEFT, TOP_LEFT)
- **Per-call X/Y offset fine-tuning**: Applied after position resolution, clamped to background bounds
- **Z-index stacking**: `VeilStack` sorts ascending by z-index, feeds each veil's output as next veil's background
- **Animation**: SLIDE (offset deltas from anchor), FADE (easing available, terminal-limited), SCALE (center-out line reveal)
- **Backdrop dimming**: 0-100 maps to 0-3 ANSI SGR `\x1b[2m` passes via `Ansi::FAINT`
- **Auto-size**: Computes dimensions from border-wrapped content before measuring
- **Border chrome**: Wraps content via `candy-sprinkles` `Style::border()`
- **Click-outside dismiss**: Delegates to `candy-zone` `Manager::anyInBounds()`
- **UTF-8 handling**: Byte-level replacement with column tracking for multibyte chars
- **Framework-agnostic**: `composite(fg, bg, v, h, xOff, yOff)` accepts raw strings

## Strengths

1. **Immutable/fluent pattern**: All `with*()` returns new instance, all state readonly
2. **Multi-overlay stacking**: VeilStack enables layered modals/tooltips simultaneously
3. **Animation infrastructure**: CubicBezier easing for Slide/Scale, easing accessible for external Fade
4. **9-position system**: More complete than upstream's 5 positions
5. **Self-contained positioning**: Works with any string-producing framework
6. **Good test coverage**: 438-line VeilTest, 158-line VeilStackTest, animation tests

## Weaknesses

1. **No cell buffer**: String-based O(n*m) character replacement — slow for large overlays
2. **No true alpha**: FADE animation returns foreground unchanged; terminal limitation
3. **No self-contained hit testing**: Relies on external candy-zone Manager, not own rendered bounds
4. **No animation tick**: `animate($progress)` accepts 0-1 float but consumer drives timing
5. **No layout system**: No proportional sizing, flex/grid, or viewport-aware positioning
6. **No built-in model integration**: Consumer manually wires update/view cycles
7. **No event system**: No `UserEvent` equivalents for veil show/hide/dismiss callbacks

---

# Relevant External Repositories

| Repo | Relevance | Major applicable concepts | Priority |
|------|-----------|--------------------------|----------|
| `charmbracelet/lipgloss` | HIGH | Layer/Compositor/Canvas cell-buffer compositing, Hit(x,y) layer resolution, Bounds() extent, Position float 0-1 system | P0 |
| `lrstanley/bubblezone` | HIGH | Zero-width ANSI markers, spatial hit testing, Mark/Scan/Get pattern, iteration-based zone invalidation | P0 |
| `charmbracelet/bubbletea` | HIGH | Elm architecture for view updates, Cmd/Batch pattern, mouse events (SGR mode), concurrent commands | P1 |
| `charmbracelet/x/ansi` | HIGH | ECMA-48 parser, state machine ANSI parsing, ECMA-48 compliance for width/height | P1 |
| `textualize/textual` | MEDIUM | SpatialMap (R-tree) for O(log n) hit detection, CSS layout (flex/grid), reactive state, message bubbling | P1 |
| `rmhubbert/bubbletea-overlay` | HIGH | Original upstream compositing algorithm, Viewable interface pattern, early return optimizations | P0 |
| `Genekkion/theHermit` | MEDIUM | Quick-fix list overlay, ANSI-aware padding, background-view-updates-while-overlay-shown pattern | P2 |
| `treilik/bubblelister` | LOW | Viewport-relative cursor offset, concurrent search, sort.Interface pattern | P2 |
| `Evertras/bubble-table` | LOW | Event system (UserEvent), style precedence cascade, visible row caching | P2 |
| `daltonsw/bubbleup` | MEDIUM | Toast/alert overlay with position, animation, auto-dismiss | P1 |
| `charmbracelet/pop` | LOW | Bubble Tea FSM state machine, context-sensitive keybindings | P2 |
| `pr_charmbracelet_lipgloss` | HIGH | Lipgloss v2 Layer/Compositor/Canvas, flexbox layout proposal (charm-layout) | P0 |

---

# Feature Gap Analysis

## Critical

### 1. No Cell Buffer / Canvas
**Title:** String-based compositing is O(n*m) and lacks true cell-level rendering
**Description:** sugar-veil performs character-by-character replacement on strings. lipgloss v2's `Canvas` (`uv.Screen` cell buffer) enables O(1) cell writes, proper Unicode width tracking per cell, and damage-tracking partial redraws.
**Why it matters:** Performance degrades for overlays > 100 cells or at high animation framerates. String concatenation per-frame is memory-intensive.
**Source:** `docs/repo_map/charmbracelet_lipgloss.md` — Canvas/Compositor/Layer system
**Source:** `docs/repo_map/charmbracelet_x.md` — cellbuf package with Cell/Line/Buffer types
**Implementation ideas:**
- Create `SugarCraft\Veil\Canvas` class holding a 2D array of cells (rune + style)
- `Canvas::compose(Veil, x, y)` performs O(1) cell writes instead of string scanning
- Render via `Canvas::render()` outputting ANSI string
- Track dirty regions for partial redraw
**Complexity:** High — requires understanding ultraviolet's cell model
**Expected impact:** Major performance improvement for animated/large overlays

### 2. No Self-Contained Mouse Hit Testing
**Title:** `isClickOutside()` requires external candy-zone Manager, not veil's own bounds
**Description:** bubblezone uses zero-width ANSI markers (`\x1B[<number>z`) wrapped around content, then a single-pass scanner records coordinates during render. lipgloss v2's `Compositor.Hit(x, y)` returns topmost layer at coordinates.
**Why it matters:** sugar-veil's `isClickOutside(MouseMsg)` requires the consumer to create and wire a Manager. No self-contained mechanism exists that uses the veil's own rendered dimensions.
**Source:** `docs/repo_map/lrstanley_bubblezone.md` — Mark/Scan/Get pattern, zero-width ANSI marker technique
**Source:** `docs/repo_map/charmbracelet_lipgloss.md` — `Compositor.Hit(x, y)` for layer hit resolution
**Implementation ideas:**
- Option A (bubblezone pattern): `Veil::mark(string $content): string` injects zero-width markers; consumer calls `Veil::scan(string $rendered)` to record bounds; `Veil::hit(int $x, int $y): bool` checks bounds
- Option B (lipgloss pattern): Integrate with candy-zone's `Manager` as the primary hit-testing backend, add `Veil::bounds(): Rectangle` returning rendered extent
- Option C: Build lightweight R-tree/spatial map for O(log n) hit testing across multiple veils
**Complexity:** Medium — requires ANSI marker injection + scanning infrastructure
**Expected impact:** Enables self-contained click detection without external Manager wiring

### 3. No Animation Tick / Time Integration
**Title:** Animation progress must be driven externally; no internal timing
**Description:** `Veil::animate($fg, $bg, $v, $h, $progress)` accepts a 0-1 float but provides no timing. The consumer implements the animation loop (e.g., via candy-core's `Tick` subscription).
**Why it matters:** Every consumer re-implements the same tick loop pattern. No built-in easing curve application over time.
**Source:** `docs/repo_map/charmbracelet_bubbletea.md` — `tea.Every` / `tea.Tick` for time-based subscriptions
**Implementation ideas:**
- Add `VeilAnimator` class that accepts a start time + duration + easing, computes progress from current time
- Or provide `AnimationLoop` helper using `candy-core` `Tick` subscription
- `animateWithTiming($fg, $bg, $v, $h, int $startMs, int $durationMs, ?CubicBezier $easing): string`
**Complexity:** Medium
**Expected impact:** Reduces boilerplate for common animation pattern

## High Value

### 4. No Layer/Mouse Interaction (Lipgloss v2 Hit)
**Title:** lipgloss v2's Compositor.Hit() returns topmost layer at mouse coordinates
**Description:** lipgloss v2's `Compositor.Hit(x, y)` iterates layers from top to bottom, returning the first whose bounds contain (x, y). This enables mouse interaction with layered overlays without external hit-testing.
**Why it matters:** Enables modal dialogs, tooltips, and popups that respond to mouse clicks based on their rendered position.
**Source:** `docs/repo_map/charmbracelet_lipgloss.md` — Layer struct with `Draw(scr, area)`, `Compositor.Hit()`, `Bounds()`
**Implementation ideas:**
- Add `Compositor` class holding a `VeilStack` + spatial index
- `Compositor::hit(int $x, int $y): ?Veil` returns topmost veil at coordinates
- `Compositor::bounds(): Rectangle` returns overall composite extent
**Complexity:** Medium
**Expected impact:** Enables mouse-aware layered overlays natively

### 5. No Layout System / Proportional Sizing
**Title:** No flex/grid/proportional layout for overlay positioning
**Description:** lipgloss v2's pending `charm-layout` proposal includes `Flex` (proportional sizing, gap), `Grid` (N columns, auto-row distribution), and `Responsive` (recalculates on resize). sugar-veil uses fixed anchor positions only.
**Why it matters:** Complex TUIs need dashboard-style layouts with proportional regions, not just centered overlays.
**Source:** `docs/repo_map/pr_charmbracelet_lipgloss.md` Issue #644 — flexbox/grid layout proposal
**Implementation ideas:**
- Add `VeilLayout` class with flex row/column directions, proportional sizing (e.g., `flex(3, 7)` for 30/70 split)
- Add `VeilGrid` with N columns and automatic row distribution
- Position veils by computed layout rather than fixed anchors
**Complexity:** High — requires layout algorithm implementation
**Expected impact:** Enables complex dashboard-style TUI layouts

### 6. No Style-Preserving Text Wrap Integration
**Title:** ANSI styles lost when text wraps across lines
**Description:** lipgloss has a 3-year-old bug (#85) where formatting is lost when styled text wraps in a constrained box. The fix requires `cellbuf.Wrap` with style state preservation.
**Why it matters:** Any overlay with wrapped multiline content that has inline styles loses formatting.
**Source:** `docs/repo_map/pr_charmbracelet_lipgloss.md` Issue #85
**Implementation ideas:**
- Integrate `candy-sprinkles` Style wrapping with `mb_substr`/word-wrap logic
- Track active style state across line breaks, reapply after wrapping
- Use `mb_strwidth()` for width measurement (potentially more correct than Go's uniseg per issue #666)
**Complexity:** Medium
**Expected impact:** Correct rendering of styled wrapped text in overlays

## Medium

### 7. No Viewable/Renderable Interface
**Title:** No minimal interface for renderable content
**Description:** bubbletea-overlay's PR #19 changed from requiring `tea.Model` to requiring only `View() string` — enabling framework-agnostic use. sugar-veil takes raw strings but could benefit from a `Renderable` interface.
**Why it matters:** Enables sugar-veil to work with any framework's renderable type through interface contamination.
**Source:** `docs/repo_map/pr_rmhubbert_bubbletea-overlay.md` — PR #19 Viewable interface
**Implementation ideas:**
```php
interface Renderable {
    public function render(): string;
}
```
- Veil methods accept `Renderable|string` for foreground/background
**Complexity:** Low
**Expected impact:** Framework-agnostic interoperability

### 8. No Concurrent Search in VeilStack
**Title:** VeilStack operations are O(n) for filtering/removal
**Description:** bubblelister's `GetIndex` uses goroutines for parallel equality search. VeilStack's `removeWhere`/`filter` iterate all veils.
**Why it matters:** For large stacks (10+ veils), parallel search could improve responsiveness.
**Source:** `docs/repo_map/treilik_bubblelister.md` — concurrent GetIndex with goroutines
**Implementation ideas:**
- Use ReactPHP parallel execution for `findWhere()` across veil collection
- Add `VeilStack::findWhere(\Closure): ?Veil` with parallel execution
**Complexity:** Medium — requires ReactPHP integration
**Expected impact:** Minor; only noticeable with many veils

### 9. No Z-Index Collision Handling
**Title:** Equal z-index tie-breaking is implicit (insertion order)
**Description:** When two veils share the same z-index, `usort` stable sort preserves insertion order but this is implicit and undocumented behavior.
**Why it matters:** Consumers relying on tie-breaking may get unexpected results if implementation changes.
**Source:** `docs/repo_map/sugarcraft_sugar-veil.md` — CALIBER_LEARNINGS notes stable sort
**Implementation ideas:**
- Document that equal z-index maintains insertion order
- Or add explicit tie-breaking via insertion sequence number
**Complexity:** Low
**Expected impact:** Documentation fix only

### 10. No Built-in Toast/Notification Pattern
**Title:** daltonsw/bubbleup provides toast/alert overlay with auto-dismiss
**Description:** bubbleup is a toast notification library with Info/Warning/Error/Debug types, positioning, and animation. sugar-veil could provide a higher-level `Toast` class built on `Veil`.
**Why it matters:** Toast notifications are a common overlay use case; sugar-toast exists but could use sugar-veil as its compositing layer.
**Source:** `docs/repo_map/sugarcraft_sugar-veil.md` — notes sugar-toast uses sugar-veil pattern
**Implementation ideas:**
- `ToastVeil extends Veil` with auto-dismiss timer, toast type (info/warn/error), built-in styling
- `ToastStack` managing multiple toasts with staggered positioning
**Complexity:** Medium
**Expected impact:** Higher-level API for common pattern

## Low Priority

### 11. No Fuzzy Filtering for VeilStack
**Title:** VeilStack has no built-in filtering/searching
**Description:** bubble-table has `FilterFunc` with fuzzy matching. VeilStack's `filter()` requires a predicate but has no built-in fuzzy search.
**Source:** `docs/repo_map/Evertras_bubble-table.md` — filterFuncFuzzy
**Implementation ideas:**
- Add `VeilStack::findById(string $id): ?Veil` — veils need ID tracking
- Add `VeilStack::fuzzyFind(string $query): array` — fuzzy match veil content
**Complexity:** Low
**Expected impact:** Minor

### 12. No Border Gradient / Color Blend Support
**Title:** lipgloss v2 has BorderForegroundBlend with CIELAB interpolation
**Description:** lipgloss v2's `BorderForegroundBlend(...)` uses CIELAB-interpolated colors around all four sides. sugar-veil's border is a simple candy-sprinkles Border.
**Source:** `docs/repo_map/charmbracelet_lipgloss.md`
**Implementation ideas:**
- Add `BorderGradient` class using CIELAB color blending
- Expose via `Veil::withBorderGradient(BorderGradient $gradient)`
**Complexity:** Medium — requires CIELAB color math
**Expected impact:** Visual polish only

### 13. No Hyperlink OSC 8 Support in Veils
**Title:** lipgloss supports clickable hyperlinks via OSC 8
**Description:** lipgloss v2 supports `Hyperlink(url, params)` in styles, generating OSC 8 clickable hyperlinks. sugar-veil's composite passes through ANSI but doesn't generate hyperlinks.
**Source:** `docs/repo_map/charmbracelet_lipgloss.md`
**Implementation ideas:**
- Ensure hyperlink OSC 8 sequences pass through `composite()` unchanged
- Add `withHyperlink()` helper that wraps content in OSC 8 codes
**Complexity:** Low
**Expected impact:** Minor

### 14. No Multi-Character Border Corners
**Title:** lipgloss v2 allows multi-character border corners
**Description:** lipgloss v2 Issue #605 requests multi-character corner support. sugar-veil relies on candy-sprinkles Border which may have similar limitations.
**Source:** `docs/repo_map/pr_charmbracelet_lipgloss.md` Issue #605
**Implementation ideas:**
- Audit candy-sprinkles Border implementation for corner width handling
- Add multi-corner support if missing
**Complexity:** Low
**Expected impact:** Minor

### 15. No SGR Sequence Stripping for Non-TTY Output
**Title:** lipgloss auto-strips styles for non-TTY output
**Description:** lipgloss v2 detects terminal color profile and strips all styles when output is not a TTY (piped/paged). sugar-veil passes through SGR codes unconditionally.
**Source:** `docs/repo_map/charmbracelet_lipgloss.md` — automatic color degradation
**Implementation ideas:**
- Add `Veil::stripAnsi(string): string` using `preg_replace` for SGR sequences
- `composite()` could auto-detect TTY and strip if needed
**Complexity:** Low
**Expected impact:** Better behavior for piped output

---

# Algorithm / Performance Opportunities

## 1. Cell Buffer vs String Compositing
**Current:** String-based character replacement — for each character in foreground, scan to target column, replace bytes. O(n*m) where n=fg chars, m=bg lines.
**External:** lipgloss v2 Canvas uses 2D cell buffer with O(1) cell writes. bubblezone uses single-pass state machine scanner.

**Why external is better:** Cell buffer eliminates repeated string scanning. State machine scanner processes each byte once.

**Tradeoffs:** Cell buffer requires tracking per-cell style state (more memory). String approach is simpler and works with any string-producing framework.

**Applicability:** If sugar-veil adds Canvas, the Veil compositor would use O(1) cell writes instead of string scanning — major performance win for large overlays or high-frequency compositing (animations).

## 2. Spatial Index for Hit Testing
**Current:** `isClickOutside()` delegates to external candy-zone Manager. For multiple veils, each veil's hit test is O(zones) linear scan.
**External:** bubblezone uses zero-width ANSI markers + iteration-based invalidation + atomic prefix counters.

**Why external is better:** Single-pass scan records all zone bounds during render. O(1) lookup per mouse event via map access.

**Tradeoffs:** bubblezone's approach requires modifying the rendered output (injecting markers). sugar-veil currently doesn't modify content.

**Applicability:** Adding Mark/Scan/Get pattern would enable O(1) veil bounds lookup and self-contained hit testing.

## 3. UTF-8 Width Calculation
**Current:** `replaceCharAt()` uses byte-level pattern matching to determine character width (2-4 bytes for multibyte).
**External:** lipgloss uses `displaywidth.String()` from `clipperhouse/displaywidth`, Go's `uniseg`, and `go-runewidth.RuneWidth()`. bubblezone uses `printableRuneWidth()` with the 0x40-0x5A / 0x61-0x7A terminator rule.

**Why external is better:** These libraries handle edge cases: emoji with modifiers, ZWJ sequences, CJK width, combining characters.

**Tradeoffs:** PHP's `mb_strwidth()` may handle some cases better (per lipgloss issue #666 noting Go's uniseg issues). However, ANSI handling requires filtering escape sequences first.

**Applicability:** Consider using `mb_strwidth()` + ANSI filtering for more correct width measurement. Worth investigating via `context7` for PHP Unicode width libraries.

## 4. CubicBezier Animation Easing
**Current:** Slide uses `easeOut()`, Fade uses `easeInOut()`, Scale uses `easeOut()` from honey-bounce.
**External:** textuaalize/textual has an `Animator` class with configurable easing functions and transitions. lipgloss uses no built-in animation (no easing).

**Why external is better:** textual's Animator supports configurable duration, automatic tick loop, and transition states.

**Tradeoffs:** sugar-veil's approach works well and is framework-agnostic.

**Applicability:** Animation timing integration (item 3 in Feature Gap) would close this gap.

---

# Architecture Improvements

## 1. Add Canvas/Cell Buffer Layer
Add `Canvas` class mirroring lipgloss v2's approach:
```php
final class Canvas {
    private array $cells; // y => x => Cell
    private int $width;
    private int $height;

    public function __construct(int $width, int $height);
    public function resize(int $width, int $height): void;
    public function clear(): void;
    public function compose(Veil $veil, int $x, int $y): self;
    public function render(): string;
    public function damage(): array; // dirty regions
}
```
This enables O(1) cell writes and proper damage tracking.

## 2. Add Compositor Class
Add `Compositor` mirroring lipgloss v2's Layer/Compositor:
```php
final class Compositor {
    private VeilStack $layers;
    private ?Canvas $canvas;

    public function __construct(VeilStack $layers, ?Canvas $canvas = null);
    public function addLayer(Veil $veil): self;
    public function hit(int $x, int $y): ?Veil;
    public function bounds(): Rectangle;
    public function render(): string;
}
```

## 3. Add Renderable Interface
```php
interface Renderable {
    public function render(): string;
}
```
Accept `Renderable|string` in `Veil::composite()` and `VeilStack::composite()`.

## 4. Add Veil ID System
Add `id: ?string` to `Veil` for programmatic identification:
```php
public function withId(string $id): self;
public function id(): ?string;
```
Enables `VeilStack::getById(string $id): ?Veil`.

---

# API / Developer Experience Improvements

## 1. Builder/Config Object for Complex Veils
Current API requires chaining many `with*()` calls:
```php
$veil = Veil::new()
    ->withBackdrop(30)
    ->withZIndex(10)
    ->withAnimation(AnimationKind::SLIDE)
    ->withClickOutsideDismiss()
    ->withManager($manager)
    ->withAutoSize()
    ->withBorder(Border::new()->style(Border::STYLE_ROUND));
```
A config object would reduce chaining:
```php
$veil = Veil::configured(
    backdrop: 30,
    zIndex: 10,
    animation: AnimationKind::SLIDE,
    clickOutsideDismiss: true,
    manager: $manager,
    autoSize: true,
    border: Border::new()->style(Border::STYLE_ROUND)
);
```

## 2. Relative Positioning API
Document clearly or refactor offset behavior. Option:
```php
$veil->composite($fg, $bg, Position::CENTER->offset(5, -2), Position::LEFT->offset(0, 0));
// vs current:
$veil->composite($fg, $bg, Position::CENTER, Position::LEFT, xOffset: 5, yOffset: -2);
```

## 3. Veil ID for Programmatic Access
```php
$modal = Veil::new()->withId('modal')->withZIndex(10);
$tooltip = Veil::new()->withId('tooltip')->withZIndex(5);

$stack = $stack->removeWhere(fn(Veil $v) => $v->id() === 'modal');
// vs current:
$stack = $stack->removeWhere(fn(Veil $v) => $v->zIndex() === 10);
```

## 4. Animation Tick Helper
```php
$animator = new VeilAnimator(
    veil: $veil,
    fg: $fg,
    bg: $bg,
    position: Position::CENTER,
    durationMs: 300,
    easing: CubicBezier::easeOut()
);

foreach ($animator->tick() as $frame) {
    echo $frame;
}
```

---

# Documentation / Cookbook Opportunities

## 1. Modal Dialog Pattern
Complete example showing:
- Veil with backdrop dim + border + click-outside dismiss
- Coordinator pattern for managing modal state
- Keyboard dismiss (Escape key)
- Focus trapping within modal

## 2. Toast Notification Stack
Show `ToastStack` with:
- Multiple concurrent toasts
- Staggered positioning (bottom-right, stacking upward)
- Auto-dismiss with countdown
- swipe-to-dismiss via mouse

## 3. Tooltip System
Show tooltip overlay:
- Attached to cursor position with offset
- Dynamic content via closure
- Multi-target sharing single VeilStack

## 4. Animated Transitions Cookbook
Show each animation type with:
- SLIDE: Direction variants, anchor detection
- FADE: Terminal limitations, custom opacity handling
- SCALE: Center-out reveal for confirmations

## 5. Layered HUD Example
Show complex layered TUI:
- Base application view
- Bottom HUD bar (Veil with zIndex: 0)
- Modal dialog (Veil with zIndex: 10)
- Tooltip overlay (Veil with zIndex: 20)
- All using shared VeilStack

---

# UX / TUI Improvements

## 1. Better Backdrop Dim Visuals
Current: ANSI SGR code 2 (faint) — not universally supported.
Improvement: Consider SGR code 8 (conceal) or background color overlay for more visible dimming. Per lipgloss issue discussions, some terminals interpret "faint" differently.

## 2. Shadow/Bloom Effect
Add optional shadow behind overlays:
```php
$veil = Veil::new()->withShadow(offset: 2, color: '#00000080');
```
Uses ANSI shadow characters or offset rendering.

## 3. Rounded Corner Rendering
Current border uses candy-sprinkles Border characters. Add `BorderStyle::ROUND` variant with proper corner joining for overlays.

## 4. Backdrop Blur Simulation
Since terminals can't blur, simulate with repeated dim passes + striping pattern. Document limitation clearly.

## 5. Terminal Capability Detection
Auto-detect support for:
- True color vs 256-color for backdrop tinting
- Faint SGR code support
- Mouse reporting modes

Adapt rendering accordingly.

---

# Testing / Reliability Improvements

## 1. Golden/Snapshot Tests
Add golden file tests for composite output:
```php
public function testCompositeGolden(): void
{
    $output = $this->veil->composite($this->fg, $this->bg, Position::CENTER, Position::CENTER);
    $this->assertEqualsGolden('centered_overlay.golden', $output);
}
```
**Source:** `docs/repo_map/charmbracelet_x.md` — golden package for snapshot testing
**Source:** `docs/repo_map/charmbracelet_bubbletea.md` — teatest for fixture-based assertions

## 2. Table-Driven Combinatorial Tests
Extend existing tests for all position × offset combinations (as bubbletea-overlay composite_test.go does with 644 lines):
```php
$data = [];
foreach (Position::cases() as $v) {
    foreach (Position::cases() as $h) {
        foreach (range(-5, 5) as $xOff) {
            foreach (range(-5, 5) as $yOff) {
                $data[] = [$v, $h, $xOff, $yOff];
            }
        }
    }
}
```
**Source:** `docs/repo_map/pr_rmhubbert_bubbletea-overlay.md` — v0.5.1 fixed offset/position coverage bugs

## 3. Fuzz Tests for Unicode/ANSI Input
Add fuzz testing for:
- Emoji input (including ZWJ sequences, modifier emojis)
- Mixed ANSI SGR + Unicode
- Very long lines (edge case for width calculation)
- Empty strings, single-byte, mixed width chars

## 4. Performance Benchmarks
Add benchmarks for:
- Composite with small vs large overlays
- Composite with 1 vs 10 vs 50 veils
- String approach vs (future) cell buffer approach

## 5. Animation Interpolation Tests
Test cubic bezier interpolation correctness:
- Verify easeOut starts fast, ends slow
- Verify easeInOut combines both
- Test extreme progress values (0.0, 1.0, negative, >1.0)

---

# Ecosystem / Integration Opportunities

## 1. sugar-toast Integration
sugar-toast (floating notifications) already exists and uses sugar-veil pattern. Formalize integration:
```php
// sugar-toast should use sugar-veil VeilStack internally
class ToastStack {
    private VeilStack $veils;
    private Manager $manager; // shared hit-testing
}
```

## 2. candy-core Tick Integration
Add first-class support for animated veils in candy-core's update loop:
```php
// In a candy-core Model
$veilModel = new AnimatedVeilModel(
    veil: $veil,
    fg: fn() => $this->modalView(),
    bg: fn() => $this->view(),
    startTime: null
);

// In update():
case TickMsg $msg:
    return $this->tick($msg); // drives animation progress
```

## 3. candy-sprinkles Style Integration
Deepen integration with candy-sprinkles:
- `Veil::withStyle(Style)` that applies style to foreground before compositing
- `Veil::withBackgroundStyle(Style)` that applies style to background region
- Support for lipgloss-style `Style.Inherit()` for layer precedence

## 4. candy-zone Manager as First-Class Dependency
Instead of requiring consumer to create Manager, sugar-veil could:
- Create internal Manager by default
- Expose `Veil::manager(): Manager` for sharing across components
- Add `Veil::shareManagerWith(Veil $other)` fluent method

## 5. PHP-FFI Alternative Backend
For high-performance scenarios, an FFI-based cell buffer using `charmbracelet/ultraviolet` bindings could provide:
- O(1) cell writes
- Proper damage tracking
- uv.Screen compatibility

This would be a new optional `sugar-veil-ffi` package.

---

# Notable PRs / Issues / Discussions

## From rmhubbert/bubbletea-overlay

### PR #19: Viewable Interface (HIGH RELEVANCE)
**Summary:** Relaxed from requiring `tea.Model` to requiring only `View() string`.
**Lesson:** SugarCraft should define minimal `Renderable` interface, not require specific framework types.
**Source:** `docs/repo_map/pr_rmhubbert_bubbletea-overlay.md`

### v0.5.1: Offset/Position Combination Bugs
**Summary:** Position + offset interaction produced wrong results for certain combinations; inverted bounds not handled.
**Lesson:** sugar-veil needs comprehensive table-driven tests for all position × offset combinations.
**Source:** `docs/repo_map/pr_rmhubbert_bubbletea-overlay.md`

### v0.3.0: Center Bias Fix
**Summary:** Centering algorithm had integer division truncation issues causing inconsistent centering for even dimensions.
**Lesson:** Even-dimension centering behavior must be explicitly documented and tested.
**Source:** `docs/repo_map/pr_rmhubbert_bubbletea-overlay.md`

## From charmbracelet/lipgloss

### Issue #644: Flexbox/Grid Layout Engine Proposal (HIGH RELEVANCE)
**Summary:** Community proposal for `charm-layout` with Flex and Grid primitives.
**Lesson:** sugar-veil needs layout primitives beyond fixed anchor positions.
**Source:** `docs/repo_map/pr_charmbracelet_lipgloss.md`

### Issue #85: Formatting Lost on Linewrap (HIGH RELEVANCE)
**Summary:** 3-year-old bug where ANSI styles are lost when text wraps in a constrained box.
**Lesson:** Style-preserving text wrap must be implemented correctly from the start in sugar-veil if it wraps styled content.
**Source:** `docs/repo_map/pr_charmbracelet_lipgloss.md`

### Issue #666: Width Mismatch (MEDIUM RELEVANCE)
**Summary:** `Width()` returns one value but rendering that many characters produces different output length for certain Unicode.
**Lesson:** `mb_strwidth()` may be more correct than Go's uniseg; worth investigating.
**Source:** `docs/repo_map/pr_charmbracelet_lipgloss.md`

## From lrstanley/bubblezone

### Zero-Width ANSI Markers Pattern (HIGH RELEVANCE)
**Summary:** Uses `\x1B[<number>z` private-use CSI sequences that terminals ignore and lipgloss.Width() skips.
**Lesson:** This enables self-contained hit testing without external Manager dependency.
**Source:** `docs/repo_map/lrstanley_bubblezone.md`

---

# Recommended Roadmap

## Immediate Wins (1-2 weeks)

1. **Add `Renderable` interface** — minimal, enables framework-agnostic use
2. **Add `Veil::withId(string)`** — programmatic veil identification
3. **Add `VeilStack::getById(string)`** — O(1) veil lookup
4. **Document z-index tie-breaking** — clarify stable sort insertion-order preservation
5. **Add golden/snapshot tests** — cover all positions and corner cases

## Medium-Term (1-2 months)

6. **Add self-contained hit testing (bubblezone pattern)** — Mark/Scan/Get within Veil itself
7. **Add `Compositor` class** — lipgloss v2-style layer compositing with Hit()
8. **Add `Canvas` class** — cell buffer for O(1) cell writes
9. **Add animation tick helper** — VeilAnimator with timing integration
10. **Add `ToastVeil` higher-level abstraction** — auto-dismiss, toast types, built-in styling
11. **Add table-driven combinatorial tests** — all position × offset combinations
12. **Add fuzz tests for Unicode/ANSI edge cases**

## Major Architectural Upgrades (3-6 months)

13. **Add `VeilLayout` / flex layout system** — proportional sizing, gap, responsive
14. **Add `VeilGrid` layout** — N-column grid with auto-row distribution
15. **Add style-preserving text wrap** — fix style loss on line breaks
16. **Add shadow/bloom effects** — enhanced overlay visuals
17. **Add R-tree spatial indexing** — O(log n) hit testing across many veils

## Experimental (6+ months)

18. **FFI-based cell buffer** — optional high-performance backend using ultraviolet bindings
19. **WebAssembly rendering** — canvas-based remote TUI rendering
20. **GPU-accelerated compositing** — for very large overlay scenarios (unlikely in PHP)

---

# Priority Matrix

| Opportunity | Impact | Complexity | Risk | Priority |
|------------|--------|------------|------|----------|
| Add Renderable interface | Medium | Low | Low | **IMMEDIATE** |
| Add Veil ID system | Medium | Low | Low | **IMMEDIATE** |
| Golden/snapshot tests | High | Medium | Low | **IMMEDIATE** |
| Self-contained hit testing (bubblezone) | High | Medium | Medium | **MEDIUM-TERM** |
| Canvas cell buffer | High | High | Medium | **MEDIUM-TERM** |
| Compositor with Hit() | High | Medium | Low | **MEDIUM-TERM** |
| Animation tick helper | Medium | Medium | Low | **MEDIUM-TERM** |
| Flex/grid layout | High | High | Low | **MEDIUM-TERM** |
| ToastVeil abstraction | Medium | Medium | Low | **MEDIUM-TERM** |
| Style-preserving wrap | High | Medium | Medium | **MEDIUM-TERM** |
| R-tree spatial indexing | Medium | High | Low | **LONG-TERM** |
| FFI cell buffer backend | High | Very High | High | **EXPERIMENTAL** |
| Shadow/bloom effects | Low | Medium | Low | **LONG-TERM** |
| Fuzzy filtering | Low | Low | Low | **LOW** |
| Border gradient blends | Low | Medium | Low | **LONG-TERM** |

---

# Final Strategic Assessment

**sugar-veil** is a well-architected PHP port that significantly exceeds its primary upstream (`rmhubbert/bubbletea-overlay`) in functionality: it adds 9-position support (vs 5), multi-overlay stacking with z-index, animation, backdrop dimming, auto-size, and click-outside dismissal. The immutable + fluent pattern and framework-agnostic string-based compositing are sound design choices that enable broad interoperability.

However, as a **string-overlay utility**, it faces inherent limitations that the external ecosystem has addressed with more sophisticated approaches:

1. **lipgloss v2's Canvas model** (`docs/repo_map/charmbracelet_lipgloss.md`) provides O(1) cell writes, proper Unicode handling per-cell, and damage-tracking partial redraws. sugar-veil's O(n*m) string scanning becomes a bottleneck for large or frequently-animated overlays.

2. **bubblezone's Mark/Scan/Get pattern** (`docs/repo_map/lrstanley_bubblezone.md`) enables self-contained mouse hit testing via zero-width ANSI markers, eliminating the need for external Manager wiring. sugar-veil's reliance on candy-zone is a dependency that other components may not want to introduce.

3. **lipgloss v2's Layer/Compositor system** provides z-sorted layer hierarchy with `Hit()` mouse resolution and `Bounds()` extent computation. sugar-veil's `Compositor` class would fill this gap.

4. **textualize/textual's spatial map** (`docs/repo_map/textualize_textual.md`) uses R-tree-like indexing for O(log n) hit detection. For applications with many interactive overlay regions, this would significantly improve performance over linear scanning.

The **most important immediate addition** is the `Renderable` interface (minimal effort, immediate interoperability benefit) followed by self-contained hit testing via the bubblezone pattern (medium effort, major UX improvement). The **most impactful long-term addition** is the cell buffer/Canvas which would fundamentally improve performance for high-frequency compositing scenarios.

The **strategic positioning** of sugar-veil should be as the **foundation overlay system** for SugarCraft, competing with lipgloss v2's Layer/Compositor while maintaining framework-agnosticism. It should not try to replace bubblezone (hit testing) but rather integrate with it or adopt its patterns. The sugar-toast library should be the canonical user of sugar-veil, demonstrating the stack pattern.

The **ecosystem gap** sugar-veil uniquely fills is providing overlay/modal compositing without requiring the full Bubble Tea/lipgloss stack — making it usable by any PHP TUI framework or even non-TUI contexts that need string overlay compositing.

**Key risks:**
- PHP's lack of native cell buffer support means Canvas would need significant new infrastructure
- Animation tick integration requires candy-core Tick subscription patterns
- Style-preserving text wrap is complex and requires careful implementation
- FFI-based ultraviolet bindings are experimental and high-complexity

**Key opportunities:**
- bubblezone-style Mark/Scan/Get is implementable in pure PHP
- `Renderable` interface is a 1-day implementation with immediate value
- Veil ID + VeilStack lookup improvements are low-risk incremental changes
- The flexbox/grid layout gap is a genuine user pain point that sugar-veil could solve ahead of the Go ecosystem (lipgloss's charm-layout is still a proposal)
