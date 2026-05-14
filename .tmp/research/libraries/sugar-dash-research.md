# sugar-dash External Libraries Research

**Research Date:** 2026-05-13
**Library:** sugar-dash (SugarCraft TUI Dashboard)
**Source:** `/home/sites/sugarcraft/sugar-dash/src/`

---

## Summary of Findings

### 1. Layout System Comparison

#### sugar-dash Current Approach
- **`Layout.php`** — Flex-like HStack/VStack with `flex` weights, gap, and alignment
- **`GridLayout.php`** — CSS Grid-style with columns/rows, gap support, cell-based placement
- **`TileLayout.php`** — Bubble Tea tile layout pattern with `Size` constraints (min/max/fixed)
- **`Split.php`** — Ratio-based pane splitting with optional dividers
- **`Boxer/Boxer.php`** — Deprecated wrapper around `Pad` (deprecated pattern)

**Key interfaces:** `Item` (render), `Sizer` (setSize + getInnerSize), `Drawable` (setRect + draw to Buffer)

#### Ratatui (Rust) — Superior Layout System
**Source:** [ratatui/layout](https://docs.rs/ratatui/latest/ratatui/layout/)

Ratatui uses a **Constraint-based layout** with a Cassowary constraint solver:

```rust
// Constraint enum with priority ordering
pub enum Constraint {
    Min(u16),      // 1. Priority: minimum size
    Max(u16),      // 2. Priority: maximum size
    Length(u16),   // 3. Priority: fixed length
    Percentage(u16),  // 4. Priority: relative to space
    Ratio(u32, u32),  // 5. Priority: ratio distribution
    Fill(u16),     // 6. Priority: takes remaining space
}

// Flex enum for excess space distribution
pub enum Flex {
    Legacy,        // Put excess in last element
    Start,         // Align to start
    End,           // Align to end
    Center,        // Center items
    SpaceBetween,  // Equal space between
    SpaceAround,   // Equal space around
    SpaceEvenly,   // Equal space everywhere
}

// Usage pattern
let layout = Layout::default()
    .constraints([
        Constraint::Percentage(20),
        Constraint::Ratio(1, 5),
        Constraint::Length(2),
        Constraint::Min(2),
        Constraint::Max(2),
    ])
    .split(rect);
```

**Why it's better:**
1. **Declarative constraints** — sizes expressed as rules, not raw values
2. **Priority-based resolution** — Min/Max before Length/Percentage prevents overflow/underflow
3. **Flex distribution** — SpaceBetween/SpaceAround handle gaps elegantly
4. **Composable** — Constraints can be mixed (e.g., `Constraint::from_percentages([25, 50, 25])`)

#### tview (Go) — Grid and Flex Layouts
**Source:** [tview Grid](https://github.com/rivo/tview/wiki/Grid), [tview Flex](https://github.com/rivo/tview/wiki/Flex)

```go
// Grid with responsive layouts
grid := tview.NewGrid().
    SetRows(3, 0, 3).      // Header, content, footer
    SetColumns(30, 0, 30). // Left sidebar, main, right sidebar
    SetBorders(true)

// Different layouts for different screen sizes
grid.AddItem(menu, 0, 0, 0, 0, 0, 0, false)  // Hidden on narrow
grid.AddItem(menu, 1, 0, 1, 1, 0, 100, true) // Shown when > 100 cols
```

#### Lipgloss (Go) — Style-Based Layout
**Source:** [lipgloss](https://context7.com/charmbracelet/lipgloss/llms.txt)

```go
// CSS-like styling with layout
style := lipgloss.NewStyle().
    Width(40).
    Height(10).
    Align(lipgloss.Center, lipgloss.Center). // H, V alignment
    Padding(1, 2, 3, 4).  // Top, Right, Bottom, Left
    Margin(2).
    Border(lipgloss.RoundedBorder()).
    BorderForeground(lipgloss.Color("#874BFD"))

// Copy style and override
warningStyle := style.Background(lipgloss.Color("#FF5733"))
```

**Key insight:** Lipgloss combines styling AND sizing in one declarative chain — no separate layout computation pass.

#### Textual (Python) — CSS Layout
**Source:** [textual layout](https://textual.textualize.io/guide/reactivity)

```python
# CSS-like layout in Python
class MyApp(App):
    CSS = """
    Screen {
        layout: grid;
        grid-size: 2 1;
        grid-columns: 1fr 2fr;
    }
    """
```

**Key insight:** Textual uses actual CSS for layout, making it vastly more powerful for complex UIs.

---

### 2. Component Architecture Comparison

#### sugar-dash Current Pattern

```php
// Each component implements Sizer interface
final class GaugeChart implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly float $value = 0.0,
        // ... other props
    ) {}

    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    public function render(): string { /* ... */ }
    public function getInnerSize(): array { /* ... */ }

    // Wither pattern for immutability
    public function withValue(float $value): self { /* ... */ }
}
```

**Issues identified:**
1. **Verbose withers** — Every property copied manually in each wither
2. **Clone-then-mutate** — readonly properties cause runtime errors (documented in CALIBER_LEARNINGS.md)
3. **No state management** — Components are pure render-only; no concept of selection/focus state
4. **Missing lifecycle** — No mount/unmount, no event hooks

#### Ratatui StatefulWidget Pattern
**Source:** [StatefulWidget](https://docs.rs/ratatui/latest/ratatui/widgets/trait.StatefulWidget.html)

```rust
// Stateful widget separates state from rendering
struct Events {
    items: Vec<String>,
    state: ListState,  // UI state (selection, scroll position)
}

impl Events {
    pub fn next(&mut self) {
        let i = match self.state.selected() {
            Some(i) => if i >= self.items.len() - 1 { 0 } else { i + 1 },
            None => 0,
        };
        self.state.select(Some(i));
    }
}

// In render loop:
frame.render_stateful_widget(list, area, &mut events.state);
```

**Key insight:** State is managed OUTSIDE the widget, passed in during render. Widgets are pure render functions with external state.

#### Textual Reactive Pattern
**Source:** [textual reactivity](https://textual.textualize.io/guide/reactivity)

```python
class Counter(Widget):
    counter = reactive(0)  # Auto-triggers watch on change

    def watch_counter(self, counter_value: int):
        self.query_one(Label).update(str(counter_value))

# Watch external objects
self.watch(self.query_one(Counter), "counter", update_progress)
```

**Key insight:** `reactive` decorator auto-calls `watch_<attr>` when attribute changes. `watch()` on external objects enables cross-widget state sync.

#### Ink (React for CLI) Component Pattern
**Source:** [ink](https://context7.com/vadimdemedes/ink/llms.txt)

```jsx
import React, {useState, useEffect} from 'react';
import {render, Box, Text} from 'ink';

// Components are just React components
const Counter = () => {
    const [counter, setCounter] = useState(0);

    useEffect(() => {
        const timer = setInterval(() => {
            setCounter(c => c + 1);
        }, 100);
        return () => clearInterval(timer);
    }, []);

    return <Text color="green">{counter} tests passed</Text>;
};

// Static for persistent output
<Static items={tests}>
    {test => <Box key={test.id}><Text>✔ {test.title}</Text></Box>}
</Static>
```

**Key insight:** Full React patterns (hooks, state, effects) work in CLI. `Static` component for persistent scrollback.

#### Blessed (Node.js) Event Pattern
**Source:** [blessed events](https://github.com/chjj/blessed/blob/master/README.md)

```javascript
// Event-driven component model
var box = blessed.box({
    border: { type: 'line' },
    content: 'Hello {bold}world{/bold}!',
});

// Mouse and keyboard events
box.on('click', function(data) {
    box.setContent('Clicked!');
    screen.render();
});

box.key('enter', function(ch, key) {
    box.setContent('Enter pressed!');
    screen.render();
});

// Enable input
box.enableMouse();
box.enableKeys();
```

**Key insight:** Events are on elements, not a central dispatcher. `screen.render()` is explicit.

---

### 3. Event Handling Comparison

#### sugar-dash Current: None
Sugar-dash components are **pure render-only** — they have no event handling built in. Events are handled externally (in the candy-core runtime).

#### Ratatui: External Loop + StatefulWidget
**Source:** [ratatui backend](https://docs.rs/ratatui/latest/ratatui/prelude/backend/trait.Backend.html)

```rust
loop {
    terminal.draw(|f| {
        // Render with state
        f.render_stateful_widget(list, area, &mut state);
    })?;

    // Handle input externally
    if let Event::Key(key) = event.read()? {
        match key.code {
            KeyCode::Down => events.next(),
            KeyCode::Up => events.previous(),
            _ => {}
        }
    }
}
```

**Key insight:** Event handling is 100% external; widget just renders.

#### Textual: Message Passing
**Source:** [textual events](https://textual.textualize.io/guide/app)

```python
def on_mount(self) -> None:
    self.screen.styles.background = "darkblue"

def on_key(self, event: events.Key) -> None:
    if event.key.isdecimal():
        self.screen.styles.background = self.COLORS[int(event.key)]
```

**Key insight:** Events auto-dispatch to `on_<event_type>` methods. `on_mount` for initialization.

#### Blessed: Element-Level Events
```javascript
screen.on('keypress', function(ch, key) {
    if (key.name === 'q') process.exit(0);
});

screen.on('mouse', function(data) {
    if (data.action === 'mousemove') {
        program.move(data.x, data.y);
    }
});
```

---

### 4. Data Visualization Comparison

#### sugar-dash (`src/Grid/`)
- `GaugeChart.php` — Bar-style gauge with color zones
- `TableBordered.php` — Box-drawing table with headers
- `TableChart.php` — Tabular chart

#### Ratatui Widgets
**Source:** [ratatui widgets](https://docs.rs/ratatui/latest/ratatui/widgets/index.html)

```rust
// Sparkline - compact line chart
Sparkline::default()
    .block(Block::bordered().title("Sparkline"))
    .data(&[0, 2, 3, 4, 1, 4, 10])
    .max(5)
    .direction(RenderDirection::RightToLeft);

// Gauge - progress indicator
Gauge::default()
    .block(Block::bordered().title("Progress"))
    .gauge_style(Style::new().white().on_black())
    .percent(20);

// Table - tabular data with selection
let table = Table::new(rows)
    .flex(Flex::Legacy)  // How to distribute extra space
    .block(Block::bordered());
```

**Key insight:** Ratatui's `Block` wraps any widget with borders/padding — composable decoration.

#### bubble-grid (Go) — Stacked Grid
**Source:** [bubble-grid](https://github.com/shahar3/bubble-grid)

```go
// Grid-based layout with frames
g := grid.NewStackedGrid()
g.AddItem(framedItem, grid.ItemOptions{Column: 0})
g.AddItem(framedItem2, grid.ItemOptions{Column: 1})

// Fit to screen automatically
grid.NewStackedGrid(grid.FitScreen(true))
```

---

### 5. Styling/Theming Comparison

#### sugar-dash: Inline Color Objects
```php
private readonly ?Color $color = null;
$output .= $this->color->toFg(ColorProfile::TrueColor) . $text . Ansi::reset();
```

#### Lipgloss (Go): Declarative Styles
**Source:** [lipgloss styling](https://context7.com/charmbracelet/lipgloss/llms.txt)

```go
style := lipgloss.NewStyle().
    Bold(true).
    Foreground(lipgloss.Color("#FAFAFA")).
    Background(lipgloss.Color("#7D56F4")).
    PaddingTop(2).PaddingLeft(4).PaddingRight(4).PaddingBottom(2).
    Width(40).
    Align(lipgloss.Center).
    Border(lipgloss.RoundedBorder()).
    BorderForeground(lipgloss.Color("#874BFD"))

// Render with automatic color downsampling
lipgloss.Println(style.Render("Hello, World!"))
```

**Key features:**
1. **Fluent API** — Chainable style methods
2. **Auto color downsampling** — `lipgloss.Println` handles 256-color vs truecolor automatically
3. **Copy by assignment** — `warningStyle := style.Background(...)` creates independent copy

#### Textual: CSS Theming
```python
DEFAULT_CSS = """
Counter { height: auto; }
MyWidget {
    background: $primary;
    color: $text;
}
"""
```

---

## Specific Improvements Found

### Improvement 1: Constraint-Based Layout System

**Problem:** sugar-dash `Layout` uses raw flex weights (`flex > 0`) with manual calculation. Ratatui's constraint system is more declarative and handles edge cases better.

**Proposed Pattern (PHP):**

```php
/**
 * Constraint types for layout allocation.
 * Mirrors ratatui Constraint enum with priority-based resolution.
 */
enum Constraint {
    case Min(int $value);
    case Max(int $value);
    case Length(int $value);
    case Percentage(int $percent);  // 0-100
    case Ratio(int $numerator, int $denominator);
    case Fill(int $weight);         // Takes remaining space
}

/**
 * How to distribute excess space.
 */
enum Flex {
    case Legacy;      // Put excess in last element
    case Start;
    case End;
    case Center;
    case SpaceBetween;
    case SpaceAround;
    case SpaceEvenly;
}
```

**Source:** [ratatui Constraint](https://docs.rs/ratatui/latest/ratatui/layout/enum.Constraint.html)

---

### Improvement 2: StatefulWidget Separation Pattern

**Problem:** sugar-dash components mix state and rendering. The `Spinner` uses `microtime()` pseudo-random frame selection internally, making testing difficult.

**Proposed Pattern:**

```php
/**
 * External state for widgets that need selection/scroll state.
 * Mirrors ratatui ListState pattern.
 */
final class SelectionState
{
    private ?int $selectedIndex = null;
    private int $offset = 0;

    public function select(?int $index): void { /* ... */ }
    public function selected(): ?int { return $this->selectedIndex; }
    public function offset(): int { return $this->offset; }
}

/**
 * Stateless render function - state passed in externally.
 * Mirrors ratatui Widget/StatefulWidget separation.
 */
final class ListComponent implements \SugarCraft\Dash\Foundation\Sizer
{
    /**
     * @param list<string> $items
     */
    public function __construct(
        private readonly array $items,
    ) {}

    public function renderWithState(SelectionState $state): string
    {
        // Pure rendering based on state
    }
}
```

**Source:** [ratatui StatefulWidget](https://docs.rs/ratatui/latest/ratatui/widgets/trait.StatefulWidget.html)

---

### Improvement 3: Reactive Properties with Watch

**Problem:** sugar-dash has no reactive mechanism — state changes require manual re-render orchestration.

**Proposed Pattern:**

```php
/**
 * Reactive property that auto-calls watcher on change.
 * Mirrors textual reactive + watch pattern.
 */
final class Reactive
{
    /** @var list<callable(mixed, mixed): void> */
    private array $watchers = [];

    public function __construct(
        private mixed $value,
    ) {}

    public function set(mixed $value): void
    {
        $old = $this->value;
        $this->value = $value;
        foreach ($this->watchers as $watcher) {
            $watcher($old, $value);
        }
    }

    public function get(): mixed { return $this->value; }

    public function watch(callable $callback): void
    {
        $this->watchers[] = $callback;
    }
}
```

**Source:** [textual reactive](https://textual.textualize.io/guide/reactivity)

---

### Improvement 4: Lifecycle Hooks (Mount/Unmount)

**Problem:** sugar-dash has no component lifecycle — no init, mount, unmount events.

**Proposed Pattern:**

```php
/**
 * Interface for lifecycle-aware components.
 */
interface LifecycleAware
{
    /** Called when component is first added to a layout */
    public function onMount(): void;

    /** Called when component is removed from layout */
    public function onUnmount(): void;
}
```

**Source:** [textual mount](https://textual.textualize.io/api/events)

---

### Improvement 5: Block Wrapper for Composable Borders

**Problem:** sugar-dash components like `TableBordered` manually render borders. Ratatui's `Block` wraps any widget.

**Proposed Pattern:**

```php
/**
 * Block wrapper that adds borders, padding, title to any content.
 * Mirrors ratatui Block widget.
 */
final class Block implements \SugarCraft\Dash\Foundation\Sizer
{
    public function __construct(
        private readonly ?Item $child = null,
        private readonly ?string $title = null,
        private readonly int $paddingTop = 0,
        private readonly int $paddingRight = 0,
        private readonly int $paddingBottom = 0,
        private readonly int $paddingLeft = 0,
        private readonly ?BorderStyle $borderStyle = null,
        private readonly ?Color $borderColor = null,
    ) {}

    // Example: Wrap any component with borders
    $borderedTable = Block::new(
        child: $table,
        title: 'Users',
        padding: 1,
        borderStyle: BorderStyle::Rounded,
    );
}
```

**Source:** [ratatui Block](https://docs.rs/ratatui/latest/ratatui/widgets/block/struct.Block.html)

---

### Improvement 6: FlexBox Layout API

**Problem:** sugar-dash `Layout::horizontal()` / `Layout::vertical()` requires manual gap management.

**Proposed Pattern (closer to Ink/React):**

```php
/**
 * Flexbox-style layout with justifyContent and alignItems.
 * Mirrors ink Box component.
 */
final class FlexBox implements \SugarCraft\Dash\Foundation\Sizer
{
    public function __construct(
        /** @var list<LayoutItem> */
        private readonly array $children,
        private readonly FlexDirection $direction = FlexDirection::Row,
        private readonly JustifyContent $justifyContent = JustifyContent::Start,
        private readonly AlignItems $alignItems = AlignItems::Start,
        private readonly int $gap = 0,
    ) {}

    public static function row(array $children = []): self
    {
        return new self($children, FlexDirection::Row);
    }

    public static function column(array $children = []): self
    {
        return new self($children, FlexDirection::Column);
    }
}

// Usage
FlexBox::row([
    FlexBox::column([$sidebar, $nav])->withGap(2),
    $mainContent->withFlexGrow(1),
])->withJustifyContent(JustifyContent::SpaceBetween);
```

**Source:** [ink Box](https://context7.com/vadimdemedes/ink/llms.txt)

---

### Improvement 7: Better Spinner Implementation

**Problem:** Current `Spinner::getCurrentFrame()` uses `microtime()` directly, making animation frame-dependent on system time.

**Proposed Pattern:**

```php
/**
 * Spinner with explicit frame advancement.
 * User controls when to tick, making it testable and deterministic.
 */
final class Spinner implements \SugarCraft\Dash\Foundation\Sizer
{
    private int $currentFrameIndex = 0;

    public function __construct(
        private readonly array $frames = ['|', '/', '-', '\\'],
        private readonly ?Color $color = null,
        private readonly string $message = '',
    ) {}

    /** Advance to next frame (call this in your event loop) */
    public function tick(): void
    {
        $this->currentFrameIndex = ($this->currentFrameIndex + 1) % count($this->frames);
    }

    public function render(): string
    {
        $frame = $this->frames[$this->currentFrameIndex] ?? '';
        // ... rest of render
    }
}
```

**Source:** [bubbletea spinner](https://github.com/charmbracelet/bubbles#spinner)

---

### Improvement 8: Component Composition with `compose()`

**Problem:** sugar-dash constructs component trees imperatively via `withChild()` chains.

**Proposed Pattern:**

```php
/**
 * Components declare children via compose().
 * Mirrors textual compose pattern.
 */
interface Composable
{
    /** Yield child components */
    /**
     * @return \Generator<Item>
     */
    public function compose(): \Generator;
}

// Usage
final class Card implements Composable, \SugarCraft\Dash\Foundation\Sizer
{
    public function compose(): \Generator
    {
        yield new Header($this->title);
        yield new Body($this->content);
        yield new Footer($this->actions);
    }
}
```

**Source:** [textual compose](https://textual.textualize.io/api/app)

---

## Recommended Implementations

### Priority 1: Constraint-Based Layout System

**Why:** This is foundational — better layout leads to cleaner composition throughout.

**Implementation:**
1. Create `Constraint` enum with Min/Max/Length/Percentage/Ratio/Fill variants
2. Create `Flex` enum with Legacy/Start/End/Center/SpaceBetween/SpaceAround/SpaceEvenly
3. Create `ConstraintLayout` class that resolves constraints against available space
4. Update `Layout.php` to use Constraint internally or replace with ConstraintLayout

**Effort:** High (new class, algorithm implementation, tests)

---

### Priority 2: StatefulWidget Separation for Selectable Components

**Why:** Many components (List, Table, Tabs) need selection state that should be external.

**Implementation:**
1. Create `SelectionState` class with `select()`, `selected()`, `offset()`
2. Create `ListComponent` that takes items + SelectionState
3. Update existing components to support `renderWithState()` variant

**Effort:** Medium (new state class, update ~5 components)

---

### Priority 3: Reactive Properties

**Why:** Enables cleaner state propagation without manual subscription management.

**Implementation:**
1. Create `Reactive` value class with `get()`, `set()`, `watch()`
2. Add `ReactiveProperty` attribute for auto-watcher naming convention
3. Update components to use Reactive for properties that trigger re-renders

**Effort:** Medium (new class, update components to use it)

---

### Priority 4: Block Wrapper Component

**Why:** DRYs up border/padding/title rendering that's currently duplicated in each component.

**Implementation:**
1. Create `Block` class mirroring ratatui Block
2. Update `TableBordered`, `Card`, `Panel` to use Block internally or wrap with Block
3. Deprecate manual border rendering in individual components

**Effort:** Low-Medium (new class, refactor ~10 components)

---

### Priority 5: Improved Spinner with Explicit Tick

**Why:** Current time-based animation is hard to test and control.

**Implementation:**
1. Add `tick()` method to Spinner
2. Add `setFrame(int $index)` for testing
3. Keep backward compat: `getCurrentFrame()` still works but tests can control

**Effort:** Low (small change to existing class)

---

### Priority 6: Component Lifecycle Interface

**Why:** Enables proper init/cleanup for components that need resources.

**Implementation:**
1. Create `LifecycleAware` interface with `onMount()`, `onUnmount()`
2. Update Layout to call lifecycle methods when adding/removing children
3. Document which components should implement (Cursor, Editor with terminal modes)

**Effort:** Low (interface + layout update)

---

### Priority 7: FlexBox Layout API

**Why:** More intuitive API than current Layout with flex weights.

**Implementation:**
1. Create `FlexBox` class with `JustifyContent` and `AlignItems` enums
2. Deprecate `Layout` in favor of FlexBox for new code
3. Keep `Layout` for backward compatibility

**Effort:** Medium (new class, deprecation path)

---

## Effort Estimates

| Priority | Item | Effort | Risk |
|----------|------|--------|------|
| 1 | Constraint-Based Layout | 2-3 weeks | High (algorithm complexity) |
| 2 | StatefulWidget Separation | 1-2 weeks | Medium (API changes) |
| 3 | Reactive Properties | 1 week | Low (additive) |
| 4 | Block Wrapper | 3-5 days | Low (refactor) |
| 5 | Improved Spinner | 1 day | Very Low |
| 6 | Lifecycle Interface | 2-3 days | Low |
| 7 | FlexBox Layout | 1-2 weeks | Medium (new API design) |

---

## Key Sources

- [Ratatui Layout](https://docs.rs/ratatui/latest/ratatui/layout/) — Constraint-based layout in Rust
- [Tview Grid/Flex](https://github.com/rivo/tview/wiki/) — Go TUI library with responsive layouts
- [Lipgloss](https://github.com/charmbracelet/lipgloss) — Style + layout in Go
- [Textual](https://textual.textualize.io/) — Python TUI with CSS layout and reactive state
- [Ink](https://github.com/vadimdemedes/ink) — React for CLI
- [Blessed](https://github.com/chjj/blessed) — Node.js terminal UI
- [bubble-grid](https://github.com/shahar3/bubble-grid) — Stacked grid for Bubble Tea
- [bubbletea-tilelayout](https://github.com/mko88/bubbletea-tilelayout) — Tile layout for Bubble Tea

---

## Appendix: sugar-dash Current Architecture

### Directory Structure
```
sugar-dash/src/
├── Components/
│   ├── Nav/        (Breadcrumb, Menu, Navbar, Pagination, etc.)
│   ├── Form/       (Checkbox, Input, Slider, Switch, Textarea, etc.)
│   ├── Feedback/   (Alert, EmptyState, LoadingText, Skeleton, Spinner)
│   ├── Table/      (TableBordered, TableChart, TableZebra)
│   ├── Tabs/       (TabPane, Tabs, TabsVertical)
│   ├── Toast/      (Hint, NotificationQueue, Toast, Tooltip)
│   ├── Tree/       (Flowchart, Tree, TreeNode, TreeViz)
│   └── ...more
├── Foundation/
│   ├── Item.php    (interface: render())
│   ├── Sizer.php   (interface: setSize(), getInnerSize())
│   ├── Drawable.php (interface: setRect(), draw(Buffer))
│   ├── Color.php, Style.php, Cell.php, Rect.php
├── Grid/
│   ├── Card.php, Footer.php, GaugeChart.php, QRCode.php
│   ├── Terminal.php, Tag.php, KeyEvent.php
├── Layout/
│   ├── Layout.php, GridLayout.php, FlexLayout.php
│   ├── HStack.php, VStack.php, ZStack.php
│   ├── Split.php, Sidebar.php, Panel.php
│   ├── Tile/ (TileLayout, Tile, Resolver, Size, Constraint, Direction)
│   └── Boxer/ (Boxer, Node, SizeFunc, Address)
```

### Key Patterns Observed

1. **Wither pattern** — All components use `with*()` methods for immutability
2. **Sizer interface** — Components that know their size implement `setSize()` + `getInnerSize()`
3. **Render returns string** — All components render to ANSI string
4. **No events** — Components are passive; events handled externally
5. **Color objects** — `Color::hex()`, `->toFg()`, `->toBg()`, `Ansi::reset()`
