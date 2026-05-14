# Sugar-Stickers Research: Sticky/Fixed Position Components

## Context

- **Library**: `sugar-stickers` — PHP port of `76creates/stickers`
- **Upstream**: https://github.com/76creates/stickers (Go, 382 stars, MIT)
- **Current Status**: FlexBox and Table components ported; no sticky/fixed position components exist yet
- **Goal**: Research sticky/fixed position terminal UI components across languages to inform implementation

---

## Executive Summary

Sugar-stickers currently provides FlexBox and Table layout components but lacks sticky/fixed position capabilities. Upstream `76creates/stickers` does NOT have sticky components — they exist in other ecosystems (Textual Python, bubbletea Viewport, Ratatui scroll regions). This research covers the landscape and provides a prioritized implementation plan.

---

## 1. Research: Sticky/Fixed Components by Ecosystem

### 1.1 Go: 76creates/stickers (Upstream)

**Source**: https://github.com/76creates/stickers

**Current Components**:
- `FlexBox` — CSS flexbox-like responsive grid (row/column, justify, align, gap, ratio, wrap)
- `Table` — Sortable, filterable, x/y scrollable table with cursor tracking

**Sticky Support**: NONE — No sticky positioning in upstream

**Key Insight**: The upstream does not provide sticky components. Sticky positioning is a feature users request but isn't implemented in the Go library.

```go
// From upstream flexbox.go - no sticky concept
type FlexBox struct {
    style         lipgloss.Style
    width, height int
    fixedRowHeight int
    rows []*Row
    recalculateFlag bool
}
```

---

### 1.2 Go: BubbleTea Viewport

**Source**: https://github.com/charmbracelet/bubbles/blob/master/viewport/viewport.go

**Viewport Model**:
```go
type Model struct {
    width, height int
    KeyMap KeyMap
    SoftWrap bool
    FillHeight bool
    MouseWheelEnabled bool
    MouseWheelDelta int
    yOffset, xOffset int
    horizontalStep int
    YPosition int  // position in terminal window (for high perf rendering)
    Style lipgloss.Style
    LeftGutterFunc GutterFunc
    initialized bool
    lines []string
    longestLineWidth int
}
```

**Sticky Positioning**: Viewport does NOT provide sticky — it provides SCROLLING with a `YPosition` for embedding in larger layouts. The sticky behavior is achieved by composing Viewport within a layout that doesn't scroll certain areas.

**Key Patterns**:
- `YPosition int` — vertical position in terminal for high-performance rendering
- `SetContent(content string)` — sets scrollable content
- `ScrollDown(n int)`, `ScrollUp(n int)` — relative scroll
- `GotoBottom()`, `GotoTop()` — absolute positioning
- `ScrollPercent() float64` — scroll position as percentage

**SoftWrap v2 PR** (https://github.com/charmbracelet/bubbles/pull/823):
- Improved soft wrapping with virtual offset tracking
- Fixed scrolling through wrapped lines

---

### 1.3 Rust: Ratatui Scroll Regions

**Source**: https://github.com/ratatui/ratatui/pull/2121, https://github.com/joshka/tui-scrollview

**ScrollableBackend Trait** (breaking change in progress):
```rust
pub trait ScrollableBackend {
    fn scroll_region_down(&mut self, rows: u16) -> Result<(), Self::Error>;
    fn scroll_region_up(&mut self, rows: u16) -> Result<(), Self::Error>;
}
```

**ratatui-interact** (https://crates.io/crates/ratatui-interact):
Provides `ScrollableContent` widget with focus support, keyboard/mouse navigation.

**tui-scrollview** (https://github.com/joshka/tui-scrollview):
```rust
use tui_scrollview::{ScrollView, ScrollViewState};
// StatefulWidget for scrollable content
let content_size = Size::new(100, 30);
let mut scroll_view = ScrollView::new(content_size);
scroll_view.render_widget(Paragraph::new(content), area);
```

**Sticky/Fixed**: NOT directly supported. Ratatui uses terminal scroll regions (CSI codes) for optimized scrolling but sticky must be implemented via layout composition.

**rat-scrolled** (https://crates.io/crates/rat-scrolled):
Scroll traits and widgets for ratatui. Provides:
- `ScrollArea` for layout combining Block+Scroll
- `ScrollState` for offset management
- View/Clipper for widgets that don't handle scrolling internally

---

### 1.4 Python: Textual Docking & Scroll

**Source**: https://github.com/Textualize/textual/blob/main/docs/guide/layout.md

**Docking (Sticky Equivalent)**:
```python
# Dock a widget to edge - removes from layout, fixes position
widget.styles.dock = "top"  # or "right", "bottom", "left"
```

**Key Properties**:
- Docked widgets do NOT scroll out of view
- Ideal for sticky headers, footers, sidebars
- Multiple widgets to same edge → overlap (z-order by compose order)
- Automatic scrollbar management via `overflow-y: auto`

**ScrollView Base Class**:
```python
class ScrollView(Widget):
    # Line API widget - handles own scrolling
    def scroll_to(x=None, y=None, *, animate=True, speed=None, duration=None):
        """Scroll to absolute coordinate"""
    
    def scroll_end(animate=True):
        """Scroll to end of content"""
    
    def scroll_home(animate=True):
        """Scroll to start of content"""
```

**Pin to Bottom Pattern** (Issue #5671):
```python
# Common for chat UIs - scroll to bottom unless user scrolled up
class VerticalScrollPinToBottom(VerticalScroll):
    async def update_with_scrolling(self, callback):
        # Run callback that increases content height
        callback()
        if self.max_scroll_y - self.scroll_target_y <= 20:
            self.scroll_end(animate=False)
```

**Log Widget Implementation** (builtin):
```python
# Textual's Log widget handles pin-to-bottom automatically
# Uses scroll_end when new content arrives and user is at bottom
```

---

## 2. Comparison: Viewport Handling

| Feature | BubbleTea Viewport | Ratatui ScrollView | Textual ScrollView |
|---------|-------------------|-------------------|-------------------|
| Soft wrap | ✅ v2 (improved) | Via style | Via CSS `overflow-x: auto` |
| Horizontal scroll | ✅ | ✅ (tui-scrollview) | ✅ (`overflow-x: auto`) |
| Gutter support | ✅ (`LeftGutterFunc`) | ❌ | ❌ (line numbers via custom widget) |
| Mouse wheel | ✅ configurable | Via rat-event | Built-in |
| High perf rendering | ✅ (`YPosition`) | ✅ (Buffer writes) | ✅ (Line API) |
| Scroll animation | ❌ | ❌ | ✅ (`animate=True`, `duration`) |
| Scrollbars | ❌ (external scrollbar component) | ❌ (widget only) | ✅ (CSS-driven auto-show) |
| Pin to bottom | Manual | Manual | Built-in (Log widget) |

---

## 3. Comparison: Sticky Positioning

| Feature | BubbleTea | Ratatui | Textual | Desired (Sugar) |
|---------|-----------|---------|---------|----------------|
| Sticky headers | Manual composition | Manual composition | ✅ `dock: top` | ✅ |
| Sticky footers | Manual composition | Manual composition | ✅ `dock: bottom` | ✅ |
| Sticky sidebars | Manual composition | Manual composition | ✅ `dock: left/right` | ✅ |
| Z-order control | Manual | Manual | ✅ (compose order) | ✅ |
| Sticky from edge | N/A | N/A | ✅ | ✅ |

**Key Insight**: ALL ecosystems implement sticky via LAYOUT COMPOSITION, not as a special component mode. The non-scrolling region is simply placed outside the scrolling container.

---

## 4. Comparison: Scroll Synchronization

| Feature | BubbleTea | Ratatui | Textual |
|---------|-----------|---------|---------|
| Sync multiple scrollviews | Manual | Manual | Manual |
| Scroll % reporting | `ScrollPercent()` | `ScrollViewState.offset` | `scroll_target_y / max_scroll_y` |
| Scroll to widget | Manual | Via scroll_to | `scroll_to_widget()` |
| Auto-scroll on content add | Manual | Manual | Log widget auto-handles |
| Scroll position state | Internal | `ScrollViewState` | Widget state |

---

## 5. Gap Analysis: What Sugar-Stickers Needs

### Current State
- ✅ FlexBox (layout container)
- ✅ Table (sort, filter, cursor)
- ❌ Viewport (scrollable content region)
- ❌ Sticky positioning (fixed to edge)

### Missing Features (from upstream + cross-ecosystem analysis)

1. **Viewport Component**
   - Scrollable content region with configurable height/width
   - Soft wrap support
   - Scroll position tracking (`yOffset`, `scrollPercent`)
   - Mouse wheel scrolling
   - Keyboard navigation (up/down/pgup/pgdn)

2. **Sticky Positioning**
   - Dock API: `sticky(Top|Bottom|Left|Right)`
   - Sticky means "fixed position, does not scroll with content"
   - Essential for headers, footers, sidebars in scrollable views

3. **Scroll Synchronization**
   - Ability to sync scroll position between components
   - `scrollToBottom()`, `scrollToTop()` methods
   - Scroll percentage reporting

---

## 6. Recommendations & Prioritization

### Priority 1: Viewport Component (High Value, Medium Effort)

**Why**: Core building block for scrollable UIs. Enables terminal apps with scrollable content (logs, lists, long text).

**Implementation Approach**:
```php
namespace SugarCraft\Stickers\Viewport;

final class Viewport {
    public int $width;
    public int $height;
    public bool $softWrap = false;
    public int $yOffset = 0;
    public int $xOffset = 0;
    
    public function __construct(int $width, int $height) { ... }
    
    public function setContent(string $content): self { ... }
    public function scrollDown(int $n = 1): self { ... }
    public function scrollUp(int $n = 1): self { ... }
    public function scrollToTop(): self { ... }
    public function scrollToBottom(): self { ... }
    public function scrollPercent(): float { ... }
    public function render(): string { ... }
}
```

**Effort**: ~3-4 hours

**Sources**:
- BubbleTea Viewport: https://github.com/charmbracelet/bubbles/blob/master/viewport/viewport.go
- PHP implementation would need ANSI-aware line breaking for soft wrap

### Priority 2: Sticky Positioning via FlexBox Extension (High Value, Low Effort)

**Why**: Achieves sticky UI without new component. Extends existing FlexBox with sticky row/column support.

**Implementation Approach**:
```php
// Extend FlexBox with sticky support
enum Sticky {
    case None;
    case Top;
    case Bottom;
    case Left;
    case Right;
}

final class FlexItem {
    // ... existing properties ...
    public Sticky $sticky = Sticky::None;
    public int $stickyOffset = 0;  // for offset from edge
}
```

**Effort**: ~2 hours

**Note**: True sticky requires two-pass rendering:
1. First pass: calculate sticky dimensions
2. Second pass: render sticky regions fixed, then scrollable content

### Priority 3: Scrollbar Integration (Medium Value, Medium Effort)

**Why**: Visual scroll position indicator. Important for UX in long lists.

**Implementation Approach**:
```php
final class Scrollbar {
    public Orientation $orientation = Orientation::Vertical;
    public ScrollbarStyle $style = ScrollbarStyle::SlimBar();
    
    public function render(int $contentLength, int $viewportSize, int $offset): string { ... }
}
```

**Effort**: ~2-3 hours

### Priority 4: Scroll Synchronization (Low Value, High Effort)

**Why**: Advanced feature for synchronized views (e.g., split-pane with synced scroll)

**Implementation Approach**: Observer pattern — `ScrollSubject` interface that components implement to notify observers of scroll position changes.

**Effort**: ~3-4 hours (defer to later phase)

---

## 7. Effort Summary

| Feature | Priority | Effort | Value |
|---------|----------|--------|-------|
| Viewport | P1 | 3-4h | High |
| Sticky via FlexBox | P1 | 2h | High |
| Scrollbar | P2 | 2-3h | Medium |
| Scroll Sync | P3 | 3-4h | Low |

**Total Recommended Phase 1**: 5-7 hours

---

## 8. Key Sources

1. **BubbleTea Viewport**: https://github.com/charmbracelet/bubbles/blob/master/viewport/viewport.go
2. **BubbleTea Viewport v2 PR**: https://github.com/charmbracelet/bubbles/pull/823
3. **Upstream stickers (Go)**: https://github.com/76creates/stickers
4. **Ratatui scroll regions PR**: https://github.com/ratatui/ratatui/pull/2121
5. **tui-scrollview**: https://github.com/joshka/tui-scrollview
6. **ratatui-interact**: https://crates.io/crates/ratatui-interact
7. **rat-scrolled**: https://crates.io/crates/rat-scrolled
8. **Textual layout docs**: https://github.com/Textualize/textual/blob/main/docs/guide/layout.md
9. **Textual scroll_to_widget PR**: https://github.com/Textualize/textual/pull/483
10. **Textual pin-to-bottom issue**: https://github.com/Textualize/textual/issues/5671
11. **Textual Log widget**: https://github.com/Textualize/textual/blob/main/src/textual/widgets/_log.py

---

## 9. Implementation Notes

### ANSI-Aware Line Breaking (for SoftWrap)

The BubbleTea Viewport v2 handles soft wrapping by tracking "virtual offset" — the offset from the "real" line index. PHP implementation needs similar logic:

```php
// Track wrapped lines per original line
private function calculateWrappedLines(string $content, int $width): array {
    $lines = explode("\n", $content);
    $wrapped = [];
    foreach ($lines as $line) {
        $wrappedLines = [];
        while (strlen($line) > $width) {
            $wrappedLines[] = substr($line, 0, $width);
            $line = substr($line, $width);
        }
        $wrappedLines[] = $line;
        $wrapped = array_merge($wrapped, $wrappedLines);
    }
    return $wrapped;
}
```

### Two-Pass Sticky Rendering

For sticky positioning in layouts:

1. **Measure pass**: Calculate dimensions of all sticky and non-sticky children
2. **Render pass**: 
   - Render sticky children at fixed positions (top/bottom/left/right edges)
   - Calculate remaining viewport for non-sticky content
   - Render non-sticky content with scroll

### Scroll Position State

```php
// Viewport state for scroll position
$yOffset: int = 0          // vertical scroll position
$contentHeight: int        // total content height in lines
$viewportHeight: int       // visible viewport height
$scrollPercent: float      // 0.0 to 1.0

// Methods
atTop(): bool              // yOffset == 0
atBottom(): bool           // yOffset >= contentHeight - viewportHeight
canScrollUp(): bool        // yOffset > 0
canScrollDown(): bool      // yOffset < contentHeight - viewportHeight
```

---

*Research compiled: 2025-05-13*
*Related: sugar-stickers (current), sugar-bits (foundation)*
