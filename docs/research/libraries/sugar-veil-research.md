# Sugar-Veil Overlay Research Plan

**Date:** 2026-05-13
**Researcher:** Research Agent
**Upstream:** [rmhubbert/bubbletea-overlay](https://github.com/rmhubbert/bubbletea-overlay)
**Current Version:** PHP port of v0.6.x composite logic

---

## Executive Summary

Sugar-veil currently provides basic string compositing (foreground over background at positions). Research across 5 TUI ecosystems reveals **significant opportunities** for enhancement: backdrop/dimming effects, animation system, z-index stacking, and event handling patterns.

---

## 1. Current Implementation Analysis

**Source:** `/home/sites/sugarcraft/sugar-veil/src/`

### What We Have

| Component | File | Capabilities |
|-----------|------|-------------|
| `Veil` | `src/Veil.php` | `composite()` method - composites fg string over bg string at given position |
| `Position` | `src/Position.php` | 9 positions (TOP, BOTTOM, LEFT, RIGHT, CENTER + 4 corners) with xOffset/yOffset |

### Current Limitations

```php
// Current API - stateless, pure rendering only
$output = $veil->composite($fg, $bg, Position::CENTER, Position::CENTER);
```

| Feature | Status |
|---------|--------|
| Overlay positioning | ✅ 9 positions + x/y offsets |
| Backdrop/dim effects | ❌ Not supported |
| Z-index/stacking | ❌ Single overlay only |
| Event handling | ❌ Pure string output |
| Animation/transitions | ❌ No animation |
| Multiple overlays | ❌ Sequential compositing required |
| Click-outside dismiss | ❌ Not implemented |
| Border chrome | ❌ Caller provides styled content |
| Auto-sizing | ❌ Fixed at caller-provided sizes |

---

## 2. Cross-Language Research Findings

### 2.1 Go: bubbletea-overlay (Upstream)

**Source:** [rmhubbert/bubbletea-overlay](https://github.com/rmhubbert/bubbletea-overlay) v0.6.7

```go
// Upstream API - Model-based with tea.Model lifecycle
overlayModel := overlay.New(fgModel, bgModel, xPosition, yPosition, xOffset, yOffset)

// Direct composite function
output := overlay.Composite(foregroundString, backgroundString, xPosition, yPosition, xOffset, yOffset)
```

**Key Insight:** The upstream wraps two `tea.Model` instances and handles compositing in `View()`. It does NOT call `Update()` on child models — caller manages lifecycle. Sugar-veil already matches this pattern.

**Position enum (Go):**
```go
const (
    Top       Position = iota  // 0
    Right                      // 1
    Bottom                     // 2
    Left                       // 3
    Center                     // 4
)
```

### 2.2 Rust: Ratatui + tui-widgets Popup

**Sources:**
- [ratatui/ratatui](https://ratatui.rs) - Core library
- [ratatui/tui-widgets](https://github.com/ratatui/tui-widgets/tree/main/tui-popup) - Popup widget
- [jharsono/tui-overlay](https://github.com/jharsono/tui-overlay) - Third-party composable overlay

#### Ratatui Popup Pattern

```rust
use tui_popup::{Popup, PopupState};

fn render_popup(frame: &mut Frame) {
    let popup = Popup::new("Content")
        .title("Popup")
        .style(Style::new().white().on_blue());
    frame.render_widget(popup, frame.area());
}

// Stateful version with mouse drag
fn render_stateful_popup(frame: &mut Frame, state: &mut PopupState) {
    frame.render_stateful_widget(popup, frame.area(), state);
}
```

#### tui-overlay (Composable Overlay)

**Source:** [jharsono/tui-overlay](https://docs.rs/tui-overlay/latest/tui_overlay/)

This is the most feature-complete overlay system found:

```rust
use tui_overlay::{Anchor, Backdrop, Easing, Overlay, OverlayState, Slide};

let overlay = Overlay::new()
    .anchor(Anchor::Right)          // 9-point positioning
    .slide(Slide::Right)            // Animation direction
    .width(Constraint::Percentage(30))
    .backdrop(Backdrop::new(Color::Rgb(0, 0, 0)));

let mut state = OverlayState::new()
    .with_duration(Duration::from_millis(150))
    .with_easing(Easing::EaseOut);

state.open();

// Render pattern:
frame.render_widget(main_view, area);                    // 1. Main UI
frame.render_stateful_widget(overlay, area, &mut state); // 2. Overlay (backdrop + chrome)
if let Some(inner) = state.inner_area() {                // 3. Body content
    frame.render_widget(detail_panel, inner);
}
```

**Pattern Matrix from tui-overlay:**

| Pattern | Anchor | Slide | Width | Height | Backdrop |
|---------|--------|-------|-------|--------|----------|
| Side drawer | `Right` | `Right` | `30%` | `100%` | yes |
| Bottom drawer | `Bottom` | `Bottom` | `100%` | `40%` | yes |
| Partial bottom drawer | `BottomLeft` | `Bottom` | `60%` | `40%` | yes |
| Modal | `Center` | — | `60%` | `50%` | yes |
| Popover | `BottomRight` | `Bottom` | `40` | `20` | no |
| Notification | `TopRight` | `Top` | `40` | `3` | no |
| Toast | `BottomRight` | `Bottom` | `40` | `3` | no |

### 2.3 Python: Textual

**Source:** [textualize/textual](https://textual.textualize.io)

#### ModalScreen Pattern

```python
from textual.screen import ModalScreen
from textual.widgets import Button, Label
from textual.containers import Grid

class QuitScreen(ModalScreen):
    def compose(self) -> ComposeResult:
        yield Grid(
            Label("Are you sure you want to quit?"),
            Button("Quit", variant="error", id="quit"),
            Button("Cancel", variant="primary", id="cancel"),
        )

    def on_button_pressed(self, event: Button.Pressed) -> None:
        if event.button.id == "quit":
            self.app.exit()
        else:
            self.app.pop_screen()
```

**Key Features:**

1. **Screen Stack:** Modal screens push/pop onto stack, only top is active
2. **Backdrop via Alpha:** `background: $primary 30%;` - translucent background shows underlying screen
3. **Layer System:** `layers: below above;` - widgets can be assigned to named layers

```css
/* CSS layer system */
Screen {
    layers: base dialog;
}
#modal-widget {
    layer: dialog;  /* Rendered above 'base' layer widgets */
}
```

### 2.4 JavaScript: Ink + Blessed

**Ink (React-like):**
```jsx
import { useAnimation } from 'ink';

const Spinner = () => {
    const { frame } = useAnimation({ interval: 80 });
    const characters = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
    return <Text>{characters[frame % characters.length]}</Text>;
};
```

**Blessed:**
```javascript
// OverlayImage for rendering images
var icon = blessed.image({
    parent: box,
    top: 0, left: 0,
    type: 'overlay',  // Overlay type
    width: 'shrink', height: 'shrink',
    file: __dirname + '/icon.png'
});

// Blessed uses "curses-like" approach withCSR and BCE for efficient redraws
```

### 2.5 Go: tview

**Source:** [rivo/tview](https://github.com/rivo/tview/wiki/Modal)

```go
modal := tview.NewModal().
    SetText("Do you want to quit the application?").
    AddButtons([]string{"Quit", "Cancel"}).
    SetDoneFunc(func(buttonIndex int, buttonLabel string) {
        if buttonLabel == "Quit" {
            app.Stop()
        }
    })

// Pages for modal overlay management
pages.AddPage("modal", modal, true, true)  // transient, modal
```

---

## 3. Feature Comparison Matrix

| Feature | bubbletea-overlay | tui-overlay | textual ModalScreen | ink | tview |
|---------|-------------------|-------------|---------------------|-----|-------|
| **Positioning** | | | | | |
| 9-point anchor | ✅ | ✅ | ✅ (CSS layers) | ✅ Flexbox | ✅ |
| Percentage-based | ✅ | ✅ | ✅ | ✅ | ❌ |
| Absolute offset | ✅ | ✅ | ✅ | ✅ | ❌ |
| **Backdrop** | | | | | |
| Dimming layer | ❌ | ✅ | ✅ (alpha bg) | ❌ | ❌ |
| Blur effect | ❌ | ❌ | ❌ | ❌ | ❌ |
| **Stacking** | | | | | |
| Z-index | N/A | N/A | ✅ (layers) | N/A | N/A |
| Multiple overlays | ✅ (sequential) | ✅ | ✅ (screen stack) | ✅ | ✅ (Pages) |
| **Event Handling** | | | | | |
| Click-outside dismiss | ❌ | ✅ (hit test) | ✅ (ModalScreen) | ❌ | ❌ |
| Focus capture | N/A | N/A | ✅ | ✅ | ✅ |
| Mouse drag | ❌ | ❌ | ✅ | ✅ | ✅ |
| **Animation** | | | | | |
| Slide in/out | ❌ | ✅ | ❌ | ✅ (useAnimation) | ❌ |
| Fade | ❌ | ❌ | ✅ (CSS) | ✅ | ❌ |
| Easing curves | ❌ | ✅ | N/A | ✅ | N/A |

---

## 4. Specific Improvements for Sugar-Veil

### 4.1 Backdrop/Dimming Effect

**Reference:** tui-overlay's `Backdrop::new(Color::Black)` + textual's `background: $primary 30%`

**Implementation Concept:**
```php
final class Backdrop
{
    public function __construct(
        public readonly Color $color,
        public readonly float $alpha = 0.3,  // 0.0 = transparent, 1.0 = opaque
    ) {}

    /**
     * Apply dimming to background lines within the overlay area.
     */
    public function apply(string $background, Rect $area): string
    {
        // For each line in the overlay area, blend the background with the backdrop color
        // ANSI doesn't support true alpha - useSGR "dim" attribute or specific color
    }
}
```

**ANSI Approach:** Use SGR codes for dimming:
- `\x1b[2m` - Dim (not widely supported)
- Use foreground color with reduced intensity
- Or use platform-specific true color with alpha simulation

**Effort:** Medium (2-3 days)
**Priority:** High - enables modal dialogs

### 4.2 Animation System

**Reference:** Ink's `useAnimation` hook, tui-overlay's `Slide` + `Easing`

**Implementation Concept:**
```php
enum SlideDirection
{
    case Top;
    case Bottom;
    case Left;
    case Right;
    case None;  // Instant appear
}

enum Easing
{
    case Linear;
    case EaseIn;
    case EaseOut;
    case EaseInOut;
}

final class Animation
{
    public function __construct(
        public readonly Duration $duration,
        public readonly Easing $easing = Easing::EaseOut,
        public readonly SlideDirection $slide = SlideDirection::None,
    ) {}

    /**
     * Calculate offset at given progress (0.0 to 1.0)
     */
    public function offsetAt(float $progress, int $totalDistance): int;
}
```

**Usage Pattern:**
```php
$overlay = Overlay::new()
    ->withPosition(Position::CENTER, Position::CENTER)
    ->withAnimation(new Animation(
        Duration::milliseconds(150),
        Easing::EaseOut,
        SlideDirection::Bottom
    ))
    ->withBackdrop(new Backdrop(Color::Black, 0.5));
```

**Effort:** Medium-High (3-4 days)
**Priority:** High - modern TUI expectation

### 4.3 Z-Index/Stacking

**Reference:** textual's CSS `layers: base dialog;` system

**Implementation Concept:**
```php
/**
 * Stack manages multiple overlays with z-ordering.
 * Higher z-index = rendered on top.
 */
final class Stack
{
    /** @var array<int, StackedOverlay> */
    private array $overlays = [];

    public function add(Overlay $overlay, int $zIndex = 0): self { ... }
    public function remove(string $id): self { ... }
    public function raise(string $id): self { ... }
    public function lower(string $id): self { ... }

    /**
     * Composite all overlays in z-order onto background
     */
    public function composite(string $background): string { ... }
}

final readonly class StackedOverlay
{
    public function __construct(
        public string $id,
        public Overlay $overlay,
        public int $zIndex,
        public Rect $area,  // Computed position
    ) {}
}
```

**Effort:** Medium (2-3 days)
**Priority:** Medium - enables complex UI compositions

### 4.4 Event Handling - Click Outside to Dismiss

**Reference:** tui-overlay's `overlay_rect()` for hit testing

**Implementation Concept:**
```php
final class OverlayState
{
    private bool $open = false;
    private ?Rect $rect = null;

    public function open(): void { $this->open = true; }
    public function close(): void { $this->open = false; }

    /**
     * Check if a point (x, y) is within the overlay area
     */
    public function containsPoint(int $x, int $y): bool
    {
        return $this->open
            && $this->rect !== null
            && $x >= $this->rect->left && $x <= $this->rect->right
            && $y >= $this->rect->top && $y <= $this->rect->bottom;
    }

    /**
     * Check if a point is OUTSIDE the overlay (for click-outside-dismiss)
     */
    public function isOutsidePoint(int $x, int $y): bool
    {
        return !$this->containsPoint($x, $y);
    }
}
```

**Effort:** Low-Medium (1-2 days)
**Priority:** Medium - common UX pattern

### 4.5 Auto-Sizing & Content-Aware Dimensions

**Reference:** tui-popup's `KnownSize` trait, Ratatui's auto-centering

**Implementation Concept:**
```php
final class Overlay
{
    private ?int $width = null;     // null = auto (fit content)
    private ?int $height = null;    // null = auto (fit content)
    private Constraint $widthConstraint = Constraint::Length(0);
    private Constraint $heightConstraint = Constraint::Length(0);

    public function withAutoSize(): self
    {
        return $this->withWidth(null)->withHeight(null);
    }

    public function withWidthConstraint(Constraint $constraint): self
    {
        // Constraint::Percentage(50), Constraint::Length(40), etc.
    }

    /**
     * Compute actual dimensions from content
     */
    private function computeDimensions(string $content): Dimensions
    {
        $lines = Veil::splitLines($content);
        return new Dimensions(
            Veil::maxLineWidth($lines),
            count($lines)
        );
    }
}

enum Constraint
{
    case Length(int $chars);
    case Percentage(int $percent);
    case Ratio(int $numerator, int $denominator);
}
```

**Effort:** Medium (2-3 days)
**Priority:** Medium - improves developer ergonomics

### 4.6 Border Chrome / Frame Decoration

**Reference:** Ratatui's `Block` widget with borders, tui-overlay's "chrome"

**Implementation Concept:**
```php
final class Frame
{
    public function __construct(
        public readonly BorderStyle $border = BorderStyle::Rounded,
        public readonly ?string $title = null,
        public readonly Style $borderStyle = Style::default(),
        public readonly Style $titleStyle = Style::default(),
        public readonly int $padding = 1,
    ) {}

    /**
     * Wrap content with border frame
     */
    public function wrap(string $content): string { ... }
}

enum BorderStyle
{
    case None;
    case Single;
    case Double;
    case Rounded;
    case Bold;
    case Heavy;
    case Ascii;
}
```

**Effort:** Low (1-2 days)
**Priority:** Medium - common for modals/dialogs

---

## 5. Prioritized Recommendations

### Tier 1: Essential Enhancements (Do First)

| # | Improvement | Effort | Priority | Rationale |
|---|-------------|--------|----------|-----------|
| 1 | **Backdrop/Dimming** | 2-3 days | High | Enables true modal dialogs; foundation for other features |
| 2 | **Animation System** | 3-4 days | High | Modern TUI expectation; differentiates from upstream |
| 3 | **Border Frame Decorations** | 1-2 days | High | Common use case; low effort, high impact |

### Tier 2: Important Enhancements

| # | Improvement | Effort | Priority | Rationale |
|---|-------------|--------|----------|-----------|
| 4 | **Auto-sizing with Constraints** | 2-3 days | Medium | Developer ergonomics; matches Ratatui patterns |
| 5 | **Stack/Multiple Overlays** | 2-3 days | Medium | Enables complex compositions (sidebar + modal + toast) |
| 6 | **Click-outside Dismiss** | 1-2 days | Medium | Common UX pattern; enables auto-dismiss modals |

### Tier 3: Nice to Have

| # | Improvement | Effort | Priority | Rationale |
|---|-------------|--------|----------|-----------|
| 7 | **Z-index Management** | 2-3 days | Low | Layer system like textual; complex for marginal gain |
| 8 | **Mouse Drag Positioning** | 2-3 days | Low | Desktop-oriented; not all TUIs support mouse |

---

## 6. Recommended Architecture Evolution

### Phase 1: Enhance Veil (Pure Compositor)

Keep `Veil` as a stateless string compositor, add:

```php
// New methods on Veil
public function compositeWithBackdrop(
    string $foreground,
    string $background,
    Position $vertical,
    Position $horizontal,
    Color $backdropColor,
    float $backdropAlpha = 0.3,
    int $xOffset = 0,
    int $yOffset = 0,
): string

public function compositeWithFrame(
    string $foreground,
    string $background,
    Position $vertical,
    Position $horizontal,
    ?string $title = null,
    BorderStyle $border = BorderStyle::Rounded,
    int $xOffset = 0,
    int $yOffset = 0,
): string
```

### Phase 2: New Overlay Class

```php
namespace SugarCraft\Veil;

/**
 * Stateful overlay with animation, backdrop, and event handling.
 * Maintains its own rendered state between updates.
 */
final class Overlay
{
    public function __construct(
        private Position $vertical,
        private Position $horizontal,
        private ?Animation $animation = null,
        private ?Backdrop $backdrop = null,
        private ?Frame $frame = null,
        private int $xOffset = 0,
        private int $yOffset = 0,
    ) {}

    public function open(): void;
    public function close(): void;
    public function isOpen(): bool;

    /**
     * Get the current composited output string
     */
    public function view(string $background, string $foreground): string;

    /**
     * Check if point is within overlay (for event handling)
     */
    public function containsPoint(int $x, int $y): bool;
}
```

### Phase 3: Stack Class

```php
/**
 * Manages multiple overlays with z-ordering
 */
final class Stack
{
    public function add(Overlay $overlay, int $zIndex = 0): string;  // returns ID
    public function remove(string $id): void;
    public function get(string $id): ?Overlay;

    /**
     * Render all overlays in z-order
     */
    public function composite(string $background): string;
}
```

---

## 7. Implementation Order

```
Week 1-2: Backdrop + Frame decorations on Veil
          → Veil::compositeWithBackdrop()
          → Veil::compositeWithFrame()
          → Frame class + BorderStyle enum

Week 3-4: Animation system
          → Animation class + Easing enum
          → SlideDirection enum
          → Veil::compositeAnimated() OR new AnimatedOverlay class

Week 5-6: Overlay stateful class
          → Overlay class with open/close/view
          → Point containment for event handling
          → Click-outside dismiss support

Week 7-8: Stack for multiple overlays
          → Stack class
          → Z-index management
          → Sequential compositing with z-order
```

---

## 8. Considerations & Constraints

### ANSI Limitation
- True alpha blending not supported in ANSI terminals
- Workaround: Use "dim" SGR codes or simulated alpha via color halving
- Some terminals (iTerm2, WezTerm, Kitty) support true color with transparency via extensions

### PHP Nature
- Sugar-veil is **stateless** (pure string compositor) by design
- Stateful features (animation, events) should be optional layer on top
- Don't break the simple `Veil::composite()` API

### Compatibility
- Maintain backward compatibility with current Position enum values
- New features should be additive (new methods, new classes)
- UTF-8 handling already correct via `mb_str_split`

---

## 9. References

| Source | URL | Key Takeaway |
|--------|-----|--------------|
| bubbletea-overlay | https://github.com/rmhubbert/bubbletea-overlay | Upstream API design |
| tui-overlay | https://github.com/jharsono/tui-overlay | Most complete Rust overlay |
| tui-popup | https://github.com/ratatui/tui-widgets/tree/main/tui-popup | Popup widget pattern |
| Ratatui | https://ratatui.rs | Centered rect helpers, layout constraints |
| Textual | https://textual.textualize.io/guide/screens | ModalScreen, layers, alpha backdrop |
| Ink | https://github.com/vadimdemedes/ink | useAnimation hook |
| tview | https://github.com/rivo/tview/wiki/Modal | Pages for modal management |
| Blessed | https://github.com/chjj/blessed | OverlayImage, CSR optimization |

---

## 10. Appendix: Upstream Composite.go (Current Sugar-Veil Reference)

```go
// From: https://raw.githubusercontent.com/rmhubbert/bubbletea-overlay/main/composite.go
func Composite(fg, bg string, xPos, yPos Position, xOff, yOff int) string {
    if fg == "" { return bg }
    if bg == "" { return fg }
    if strings.Count(fg, "\n") == 0 && strings.Count(bg, "\n") == 0 {
        return fg  // Simple case: single line
    }

    fgWidth, fgHeight := lipgloss.Size(fg)
    bgWidth, bgHeight := lipgloss.Size(bg)

    if fgWidth >= bgWidth && fgHeight >= bgHeight {
        return fg
    }

    x, y := offsets(fg, bg, xPos, yPos, xOff, yOff)
    x = clamp(x, 0, bgWidth-fgWidth)
    y = clamp(y, 0, bgHeight-fgHeight)

    fgLines := lines(fg)
    bgLines := lines(bg)
    var sb strings.Builder

    for i, bgLine := range bgLines {
        if i > 0 { sb.WriteByte('\n') }
        if i < y || i >= y+fgHeight {
            sb.WriteString(bgLine)
            continue
        }

        pos := 0
        if x > 0 {
            left := ansi.Truncate(bgLine, x, "")
            pos = ansi.StringWidth(left)
            sb.WriteString(left)
            if pos < x {
                sb.WriteString(whitespace(x - pos))
                pos = x
            }
        }

        fgLine := fgLines[i-y]
        sb.WriteString(fgLine)
        pos += ansi.StringWidth(fgLine)

        right := ansi.TruncateLeft(bgLine, pos, "")
        sb.WriteString(right)
    }
    return sb.String()
}
```

---

*Research complete. Next step: Implementation planning with prioritized backlog.*
