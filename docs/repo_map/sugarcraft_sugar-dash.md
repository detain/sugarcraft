# SugarCraft/sugar-dash: Innovation & Comparison Report

## Metadata

- **Package**: `sugarcraft/sugar-dash`
- **Description**: Dashboard TUI library -- column grid layout, framed panels, status bar, tabs, and more. Ports patterns from bubble-grid, bubbletea-tilelayout, go-tealeaves, bubbleboxer, lattice, Homedash, and termui.
- **Status**: 🟢 v1 ready
- **Primary Upstream**: [charmbracelet/bubble-grid](https://github.com/charmbracelet/bubble-grid) (shahar3/bubble-grid fork)
- **Additional Upstreams**: rasjonell/dashbrew, 76creates/stickers (FlexBox), tealeaves/bubbletea-tilelayout, treilik/bubbleboxer
- **Subdirectory**: `sugar-dash/`
- **Composer Package**: `sugarcraft/sugar-dash`
- **Namespace**: `SugarCraft\Dash\`
- **PHP**: ^8.3
- **Dependencies**: `sugarcraft/candy-core`, `sugarcraft/candy-sprinkles`, `sugarcraft/candy-pty`
- **Dev Dependencies**: `sugarcraft/candy-vcr`, `phpunit/phpunit` ^10.5

---

## 1. Grid Layout Architecture

### 1.1 StackedGrid (`src/Layout/Grid/StackedGrid.php`)

The centerpiece of sugar-dash's dashboard system. A multi-column stacked grid that mirrors the bubble-grid pattern from the Go original.

**File**: `/home/sites/sugarcraft/sugar-dash/src/Layout/Grid/StackedGrid.php`

**Core Architecture**:
- Items placed into columns (0-based index) via `addItem(Item, ItemOptions)`
- Items within the same column are stacked vertically, sharing column width
- Items in different columns placed side-by-side with equal column widths
- Supports nested grids (StackedGrid can contain another StackedGrid as an item)
- Implements `Sizer` interface for explicit dimension propagation

**Key Code Pattern** (lines 112-129):
```php
private function groupByColumn(): array
{
    $columns = [];
    foreach ($this->items as $itemWithOpts) {
        $col = $itemWithOpts->options->column;
        if (!isset($columns[$col])) {
            $columns[$col] = [];
        }
        $columns[$col][] = $itemWithOpts;
    }
    ksort($columns);
    return array_values($columns);
}
```

**Height Allocation Algorithm** (lines 134-186):
```
expandHeight = (totalHeight - fixedHeight) / expandingCount
```
- Partition items into `expanding` (expandVertical=true) and `fixed` (expandVertical=false)
- Calculate fixed items' natural heights first
- Distribute remaining height among expanding items with floor division
- Last expanding item gets the remainder (prevents off-by-one drift)

**Responsive Behavior**:
- Collapses to single column when terminal width < 90 cells (Breakpoint::narrow())
- When collapsed, all items flattened into one column and rendered vertically
- `fitScreen` option controls whether columns share equal width (true) or use natural size (false)

**Narrow Terminal Handling** (lines 80-89):
```php
if (Breakpoint::narrow($this->width)) {
    $flattened = [];
    foreach ($columns as $colItems) {
        foreach ($colItems as $itemWithOpts) {
            $flattened[] = $itemWithOpts;
        }
    }
    return $this->renderColumn($flattened, $this->width);
}
```

### 1.2 Options & ItemOptions (`src/Layout/Grid/Options.php`, `ItemOptions.php`)

**Options** (20 lines) -- single readonly class with `fitScreen: bool`

**ItemOptions** (26 lines) -- per-item placement:
- `column: int` (0-based) -- which column to place item in
- `expandVertical: bool` -- whether item expands to fill remaining vertical space

### 1.3 Comparison with Upstream bubble-grid

| bubble-grid (Go) | sugar-dash (PHP) | Notes |
|---|---|---|
| `grid.NewStackedGrid()` | `new StackedGrid(new Options())` | PHP needs explicit options object due to named parameters |
| `grid.ItemOptions{Column: 0}` | `new ItemOptions(column: 0)` | PHP readonly class |
| `FitScreen(true)` option | `Options(fitScreen: true)` | Same behavior |
| `g.AddItem(framedItem, grid.ItemOptions{Column: 0})` | `$grid->addItem($item, new ItemOptions(column: 0))` | PHP void return vs Go chain |
| Nested grids supported | `if ($item instanceof self)` check | Same pattern |

**Differences from Go Original**:
1. **PHP immutability**: Go bubble-grid uses pointer receivers with mutation; sugar-dash uses readonly properties and returns new clones
2. **No interface{}**: PHP's lack of generics means `Sizer` interface check instead of type assertion
3. **Breakpoint collapse**: sugar-dash adds responsive collapse at 90 cells (Homedash convention)
4. **ItemWithOptions wrapper**: Internal pairing class not needed in Go's struct-based approach

---

## 2. Panel System

### 2.1 Frame (`src/Layout/Frame.php`)

A bordered frame that wraps any `Item`, providing configurable borders, title, padding, and vertical alignment.

**Core Features**:
- Configurable border characters (rounded, normal, thick, double, block) via `Border` from candy-sprinkles
- Optional title displayed on top border
- Per-side border color overrides via `Color`
- Configurable padding (all sides or per-side)
- Vertical alignment within the bordered content area
- Fluent setters for all properties

**File**: `/home/sites/sugarcraft/sugar-dash/src/Layout/Frame.php` (296 lines)

**Border Style Characters** (uses `Border` from candy-sprinkles):
```php
// Mirrors tealeaves/bubbletea-tilelayout pattern
$border = ($this->border ?? Border::rounded())->withTitle($this->title);
```

**Dimension Calculation** (lines 137-148):
```php
public function getInnerSize(): array
{
    $w = $this->width ?? 0;
    $h = $this->height ?? 0;
    if ($w <= 0 || $h <= 0) {
        return [0, 0];
    }
    // Only subtract the frame border characters (2 cells each axis)
    return [max(0, $w - 2), max(0, $h - 2)];
}
```

### 2.2 Panel (`src/Layout/Panel.php`)

A panel component with header and footer sections, distinct from Frame in purpose.

**Core Features**:
- Optional title header with border
- Main content area
- Optional footer with border
- Customizable border style (single, double, rounded, bold, empty)
- Customizable border and title colors

**File**: `/home/sites/sugarcraft/sugar-dash/src/Layout/Panel.php` (489 lines)

**Border Character Sets** (lines 92-102):
```php
private function getStyleChars(): array
{
    return match ($this->style) {
        'double' => ['╔', '╗', '╚', '╝', '═', '║'],
        'rounded' => ['╭', '╮', '╰', '╯', '─', '│'],
        'single' => ['┌', '┐', '└', '┘', '─', '│'],
        'bold' => ['┏', '┓', '┗', '┛', '━', '┃'],
        'empty' => [' ', ' ', ' ', ' ', ' ', ' '],
        default => ['┌', '┐', '└', '┘', '─', '│'],
    };
}
```

### 2.3 BoxDrawing (`src/Layout/BoxDrawing.php`)

Unicode box-drawing frame generator with style and color customization.

---

## 3. Layout System Architecture

### 3.1 Layout Enums

Defined across multiple files in `src/Layout/`:

| Enum | Values | File |
|------|--------|------|
| `LayoutDirection` | `Horizontal`, `Vertical` | `LayoutDirection.php` |
| `SplitDirection` | `Horizontal`, `Vertical` | `SplitDirection.php` |
| `AlignItems` | `Start`, `End`, `FlexStart`, `FlexEnd`, `Center`, `Stretch`, `Baseline` | `AlignItems.php` |
| `FlexDirection` | `Row`, `Column`, `RowReverse`, `ColumnReverse` | `FlexDirection.php` |
| `FlexWrap` | `NoWrap`, `Wrap`, `WrapReverse` | `FlexWrap.php` |
| `HAlign` | `Left`, `Right`, `Center` | `HAlign.php` |
| `VAlign` | `Top`, `Middle`, `Bottom` | `VAlign.php` |
| `JustifyContent` | `Start`, `End`, `FlexStart`, `FlexEnd`, `Center`, `SpaceBetween`, `SpaceAround`, `SpaceEvenly` | `JustifyContent.php` |

### 3.2 Core Layout Containers

#### HStack (`src/Layout/HStack.php`, 284 lines)
- Horizontal stack layout
- `withSpacing(int)` gap between items
- `withAlignment(HAlign)` horizontal alignment
- `withItems(array)` replaces all items
- `withAppended(Item)` / `withPrepended(Item)` add items
- Factory methods: `new(...items)`, `spaced(int, ...items)`, `centered(...items)`

#### VStack (`src/Layout/VStack.php`, 251 lines)
- Vertical stack layout with alignment options
- Similar API to HStack but for vertical stacking
- Factory methods: `new(...items)`, `spaced(int, ...items)`, `centered(...items)`, `right(...items)`

#### ZStack (`src/Layout/ZStack.php`, 300 lines)
- Layered stack (items on top of each other)
- Items rendered bottom-to-top (last item visible by default)
- `withAlignment(HAlign)` and `withVAlignment(VAlign)` for positioning within shared dimensions
- Factory methods: `new(...items)`, `left(...)`, `right(...)`, `top(...)`, `bottom(...)`

#### Stack (`src/Layout/Stack.php`)
- Basic vertical stack (simpler than VStack)
- Factory methods: `new(...items)`, `spaced(int, ...items)`

### 3.3 Advanced Layouts

#### FlexLayout (`src/Layout/FlexLayout.php`, 710 lines)
CSS Flexbox-inspired layout with:
- Flex directions: Row, Column, RowReverse, ColumnReverse
- FlexWrap: NoWrap, Wrap, WrapReverse
- JustifyContent (main axis distribution)
- AlignItems (cross axis alignment)
- Gap between items
- Responsive wrapping behavior
- Natural-size rendering when no dimensions set

**Flex Distribution Algorithm** (lines 362-384):
```php
private function calculateRowItemWidths(int $totalWidth, array $itemSizes): array
{
    $itemWidths = array_column($itemSizes, 'width');
    $totalGapWidth = $this->gap * max(0, count($this->items) - 1);
    $contentWidth = array_sum($itemWidths);

    if ($contentWidth + $totalGapWidth >= $totalWidth) {
        return $itemWidths;  // Use natural widths when content fits
    }

    $extraSpace = $totalWidth - $contentWidth - $totalGapWidth;

    return match ($this->justify) {
        JustifyContent::Start, JustifyContent::FlexStart => $itemWidths,
        JustifyContent::End, JustifyContent::FlexEnd => $itemWidths,
        JustifyContent::Center => $itemWidths,
        JustifyContent::SpaceBetween => $this->distributeSpaceBetween($itemWidths, $extraSpace),
        JustifyContent::SpaceAround => $this->distributeSpaceAround($itemWidths, $extraSpace),
        JustifyContent::SpaceEvenly => $this->distributeSpaceEvenly($itemWidths, $extraSpace),
    };
}
```

#### GridLayout (`src/Layout/GridLayout.php`, 452 lines)
CSS Grid-style layout with:
- Configurable rows and columns
- Gap support between cells (columnGap, rowGap)
- Spanning support (items can span multiple cells via GridItem)
- Two factory methods: `columns(int, items)` and `rows(int, items)`
- `withItem(Item)` / `withItems(array)` / `withColumns(int)` / `withRows(int)` / `withGap(int)`

#### Layout (`src/Layout/Layout.php`, 604 lines)
Advanced layout algorithm supporting:
- Horizontal and vertical layouts
- Flex-like distribution (grow/shrink via flex weights)
- Gap/spacing support
- Horizontal and vertical alignment
- Natural dimension calculation
- Complex multi-pass size calculation (flex distribution, then proportional shrinking)

**Flex Weight Algorithm** (lines 245-315):
1. Calculate total weight from flex children
2. First pass: assign fixed sizes and minimums
3. Second pass: distribute remaining space to flex children based on weight ratios
4. Third pass: if still not fitting, shrink proportionally with scale factor

### 3.4 Tile Layout (Constraint-Based)

#### TileLayout (`src/Layout/Tile/TileLayout.php`, 261 lines)
Based on bubbletea-tilelayout pattern with constraint-based sizing.

**Tile System Components**:
- `Tile` -- named tile with Size and content
- `Size` -- width/height constraints (fixedWidth, fixedHeight, weight, minWidth, minHeight, optional, minSizeFit)
- `Constraint` -- how a child should be sized (Fixed, Flex, Fit)
- `ConstraintKind` -- enum for constraint types
- `Resolver` -- 5-phase constraint resolution algorithm

#### Constraint (`src/Layout/Tile/Constraint.php`, 89 lines)
```php
final class Constraint
{
    public function __construct(
        public readonly ConstraintKind $kind = ConstraintKind::Fixed,
        public readonly int $fixedSize = 0,
        public readonly float $flexWeight = 0.0,
        public readonly int $minSize = 0,
        public readonly int $maxSize = -1,  // -1 = unbounded
        public readonly bool $optional = false,
        public readonly bool $minSizeFit = false,
    ) {}

    public static function fixed(int $size): self { ... }
    public static function flex(float $weight = 1.0): self { ... }
    public static function fit(): self { ... }
}
```

#### Resolver (`src/Layout/Tile/Resolver.php`, 348 lines)
5-phase constraint resolution algorithm:
1. **Phase 1**: Resolve Fixed and Fit children -- subtract their sizes from remaining
2. **Phase 2+3**: Distribute remaining to Flex children with cumulative rounding, then clamp and redistribute until stable
3. **Phase 4**: Check optional children -- remove any below minSize
4. **Retry** loop if any removed until stable

**Key Algorithm** (lines 104-108):
```php
// Phase 1: Resolve Fixed and Fit children
$remaining = self::resolveFixedAndFit($remaining, $work, $hinters, $sizes, $active, $horizontal);

// Phase 2+3: Distribute remaining to Flex with clamping loop
self::distributeFlexWithClamping($remaining, $work, $sizes, $active);
```

---

## 4. Component Composition

### 4.1 Tabs (`src/Components/Tabs/Tabs.php`, 318 lines)

Tabbed interface component with:
- Multiple tabs with labels and content
- Active tab highlighting with color
- Tab separator customization
- Keyboard navigation via selected tab index

**Factory**: `Tabs::new(array $tabs)` with tabs array format `['label' => string, 'content' => Item]`

**Rendering** (lines 96-144):
```php
private function renderTabBar(int $selectedIndex): string
{
    $parts = [];
    foreach ($this->tabs as $i => $tab) {
        $label = $tab['label'];
        $isActive = ($i === $selectedIndex);
        $tabStr = $this->renderTabLabel($label, $isActive);
        $parts[] = $tabStr;

        if ($i < count($this->tabs) - 1) {
            $sepColor = '';
            if ($this->inactiveColor !== null) {
                $sepColor = $this->inactiveColor->toFg(ColorProfile::TrueColor);
            }
            $parts[] = $sepColor . $this->separator . Ansi::reset();
        }
    }
    // ...
}
```

### 4.2 StatusBar (`src/Components/StatusBar/StatusBar.php`)

Status bar with left/right zones and configurable separators.

### 4.3 Screen (`src/Layout/Screen.php`, 238 lines)

Terminal screen management:
- Enter/leave alternate screen buffer
- Show/hide cursor
- Clear screen (full or partial)
- Save/restore cursor position
- Screen size detection
- Mouse tracking enable/disable

**ANSI Escape Sequences**:
```php
public function render(): string
{
    $result = '';
    if ($this->alternateScreen) {
        $result .= Ansi::altScreenEnter();
    }
    if (!$this->showCursor) {
        $result .= Ansi::cursorHide();
    }
    if ($this->enableMouse) {
        $result .= Ansi::mouseAllOn();
    }
    $result .= Ansi::eraseScreen();
    $result .= Ansi::cursorTo(1, 1);
    // ... render content ...
    return $result;
}
```

### 4.4 Dashboard Building Patterns

**Example: dashboard.php** (27 lines):
```php
$grid = new StackedGrid(new Options(fitScreen: true));

$grid->addItem(
    Frame::new(
        VStack::centered(
            Text::new('SugarDash Dashboard'),
            Text::new('Welcome to the TUI')
        )
    )->withPadding(1),
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->addItem(
    Frame::new(Text::new('Column 2 Panel'))->withPadding(1),
    new ItemOptions(column: 1)
);

$grid->setSize(80, 24);
echo $grid->render();
```

**Example: dashboard-status.php** (111 lines):
- Demonstrates Spinner, Skeleton, NProgress, Progress, ProgressBar, ProgressRing, Gauge
- NotificationQueue with Toast/Alert/ModalNotification
- HStack layout for multiple components in a row

---

## 5. Module System

### 5.1 Module Interface (`src/Module/Module.php`, 42 lines)

Elm-architecture pattern aligned with `SugarCraft\Core\Model`:

```php
interface Module extends Model
{
    public function name(): string;  // Unique identifier
    public function minSize(): array;  // [width, height] minimum terminal size
}
```

**Core Model Methods** (inherited):
- `init(): ?Closure` -- invoked once on startup, may return initial Cmd
- `update(Msg): array{0: Module, 1: ?Cmd}` -- receives messages, returns next module and optional command
- `view(): string` -- renders current module to string

### 5.2 BaseModule (`src/Module/BaseModule.php`)

Abstract helper with:
- `withState(array): static` for immutable state updates
- Default `update()` returns `[self, null]`

### 5.3 Built-in Modules

| Module | Path | Description |
|--------|------|-------------|
| ClockModule | `Modules/Clock/ClockModule.php` | Single-line digital clock |
| SystemModule | `Modules/System/SystemModule.php` | CPU/mem/disk stats |
| UptimeModule | `Modules/Uptime/UptimeModule.php` | System uptime display |
| GreetingModule | `Modules/Greeting/GreetingModule.php` | Time-of-day greeting |
| GenericModule | `Modules/Generic/GenericModule.php` | Arbitrary shell command runner |
| WeatherModule | `Modules/Weather/WeatherModule.php` | Live weather from wttr.in with 30min cache |

---

## 6. Plugin System

### 6.1 Plugin Architecture

JSON-based protocol for extending dashboards with external binaries.

**Core Classes**:
- `Request` -- Plugin request DTO
- `Response` -- Plugin response DTO
- `PluginSdk` -- Plugin runner loop
- `ExternalModule` -- Wraps binary into Module interface
- `Discovery` -- Plugin discovery from filesystem

### 6.2 Request/Response Protocol

```php
// Request from dashboard to plugin
$request = new Request($method, $params, $id);

// Response from plugin to dashboard
$response = new Response($id, $result, $error);
```

---

## 7. Foundation Interfaces

### 7.1 Item Interface (`src/Foundation/Item.php`)

Anything that can be rendered as a string:

```php
interface Item
{
    public function render(): string;
}
```

### 7.2 Sizer Interface (`src/Foundation/Sizer.php`)

An Item that knows its own dimensions:

```php
interface Sizer extends Item
{
    public function setSize(int $width, int $height): Sizer;
}
```

**Pattern**: Grid propagates allocated dimensions to Sizers via `setSize()` before calling `render()`, allowing components to adjust output to available space.

### 7.3 Drawable Interface (`src/Foundation/Drawable.php`)

Universal draw contract with `getRect()`, `setRect()`, `draw(Buffer)` -- used for canvas-based rendering.

---

## 8. Event & Focus System

### 8.1 Event Classes (`src/Events/`)

| Class | Description |
|-------|-------------|
| `Event` | Base event class |
| `EventHandler` | Event handler callback |
| `EventDispatcher` | Event dispatcher |
| `FocusEvent` | Focus event |
| `KeyEvent` | Keyboard event |
| `MouseEvent` | Mouse event |
| `PasteEvent` | Paste event |
| `ResizeEvent` | Resize event |
| `Focus` | Focus state management |

### 8.2 Key System (`src/Keys/`)

| Class | Description |
|-------|-------------|
| `Key` | Key representation |
| `KeyAction` | Key action |
| `KeyMap` | Key mapping |
| `KeyRegistry` | Key registry |
| `Category` | Key category |

---

## 9. Dual-SSOT Foundation Primitives

**Critical Architectural Note** (from README):

> Five Foundation primitives (`Style`/`Theme`/`Rect`/`Buffer`/`Cell`) plus `StyleParser` are intentionally distinct from same-named canonical types in `candy-sprinkles`/`candy-core`/`candy-vt`. The lineage differs (charmbracelet/inline-termui for sugar-dash, lipgloss/ratatui/VT-emulator for the others) and the API shapes diverge.

| sugar-dash Class | candy-sprinkles Class | candy-core Class |
|---|---|---|
| `SugarCraft\Dash\Foundation\Style` | `SugarCraft\Sprinkles\Style` | -- |
| `SugarCraft\Dash\Foundation\Theme` | `SugarCraft\Sprinkles\Theme` | -- |
| `SugarCraft\Dash\Foundation\Rect` | -- | `SugarCraft\Core\Rect` |
| `SugarCraft\Dash\Foundation\Buffer` | -- | `SugarCraft\Vt\Buffer\Buffer` |
| `SugarCraft\Dash\Foundation\Cell` | -- | `SugarCraft\Vt\Cell\Cell` |
| `SugarCraft\Dash\Foundation\StyleParser` | `SugarCraft\Sprinkles\StyleParser` | -- |

---

## 10. Dependencies Analysis

### 10.1 Internal SugarCraft Dependencies

| Dependency | Role in sugar-dash |
|---|---|
| `sugarcraft/candy-core` | Elm-arch `Model`, ANSI utilities, Width, Color |
| `sugarcraft/candy-sprinkles` | `Border`, `Style`, `Layout`, `Position`, `VAlign` |
| `sugarcraft/candy-pty` | PTY primitives for any terminal interactions |

### 10.2 Dependency Graph (transitive closure)

All path-repo entries in `composer.json`:
```
sugar-dash
├── candy-core (path repo)
├── candy-sprinkles (path repo)
│   └── candy-core (implicit)
└── candy-pty (path repo)
    └── candy-core (implicit)
```

---

## 11. Testing Architecture

### 11.1 Test Organization

```
sugar-dash/tests/
├── GoldenSnapshotTest.php          # Snapshot testing base
├── Components/
│   ├── Card/
│   │   ├── CoverTest.php
│   │   ├── MetricsGridTest.php
│   │   └── ...
│   ├── Nav/
│   │   ├── NavbarTest.php
│   │   ├── ScrollbarTest.php
│   │   └── ...
│   ├── Tabs/
│   │   ├── TabsTest.php
│   │   └── TabsVerticalTest.php
│   └── ...
├── Events/
│   ├── EventTest.php
│   └── KeyMapTest.php
├── Foundation/
│   ├── BufferTest.php
│   ├── CellTest.php
│   └── ...
├── Grid/
│   ├── BarTest.php
│   └── TransformerTest.php
├── Layout/
│   ├── BoxerTest.php
│   ├── CenterTest.php
│   ├── FlexLayoutTest.php
│   ├── FrameTest.php
│   ├── HStackTest.php
│   ├── PanelTest.php
│   ├── VStackTest.php
│   ├── WindowTest.php
│   └── ZStackTest.php
├── Plot/
│   ├── Chart/
│   │   ├── AreaChartTest.php
│   │   ├── DonutTest.php
│   │   └── ...
│   └── ...
└── ...
```

### 11.2 Snapshot Testing

Uses `SugarCraft\Vcr` for recording/replaying render sessions.

### 11.3 Grid Tests

Key test files for the grid system:
- `tests/Layout/Grid/StackedGridTest.php` -- main grid tests
- `tests/Layout/Grid/StackedGridResponsiveTest.php` -- responsive behavior tests

---

## 12. Notable Implementation Patterns

### 12.1 Wither Pattern (Immutable Setters)

Every component uses `with*()` methods that return new instances:

```php
public function withPadding(int $n): self
{
    return new self(
        content: $this->content,
        border: $this->border,
        borderColor: $this->borderColor,
        padding: [$n, $n, $n, $n],
        title: $this->title,
        verticalAlign: $this->verticalAlign,
    );
}
```

### 12.2 Clone-then-mutate for setSize

Because PHP readonly properties cannot be directly modified, `setSize()` clones then mutates:

```php
public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
{
    $clone = clone $this;
    $clone->width = $width;
    $clone->height = $height;
    return $clone;
}
```

### 12.3 SizeHint/SizeHinter Pattern

Components that can suggest their preferred size implement `SizeHinter` interface for constraint-based layout.

### 12.4 Theme Fan-Down

`Drawable` components implement `withTheme(Theme)` that recursively applies theme to children:

```php
public function withTheme(Theme $theme): self
{
    $themedItems = [];
    foreach ($this->items as $item) {
        if ($item instanceof Drawable) {
            $themedItems[] = $item->withTheme($theme);
        } else {
            $themedItems[] = $item;
        }
    }
    return new self(/* ... */);
}
```

---

## 13. Comparison with Mapped Third-Party Repos

### 13.1 vs bubble-grid (shahar3/bubble-grid)

| Aspect | bubble-grid (Go) | sugar-dash (PHP) |
|--------|-----------------|-----------------|
| Grid API | `NewStackedGrid()` + `AddItem()` | `new StackedGrid()` + `addItem()` |
| Sizer pattern | `Sizer` interface | `Sizer` interface (identical) |
| Frame | `frame.NewFrame()` | `Frame::new()` |
| Dimension propagation | Pointer receivers mutate | Readonly + clone pattern |
| Nested grids | Supported | Supported |
| FitScreen option | Yes | Yes |
| Responsive collapse | No | Yes (Breakpoint::narrow at 90 cells) |
| Theme fan-down | No | Yes (Drawable interface) |

### 13.2 vs dashbrew (rasjonell/dashbrew)

| Aspect | dashbrew (Go) | sugar-dash (PHP) |
|--------|----------------|-----------------|
| Architecture | Single CLI app | Composable library |
| Layout | Recursive flexbox containers | Multiple layout systems |
| Components | 6 fixed types (text, list, todo, chart, histogram, table) | 200+ components |
| Config | JSON declarative | PHP imperative |
| Data sources | Shell commands, HTTP API | Any PHP code |
| Action system | fire_and_forget + replace_pane | Event-driven module system |
| Navigation | AABB bounding box neighbor finding | External to library |

### 13.3 vs stickers (76creates/stickers)

| Aspect | stickers (Go) | sugar-dash (PHP) |
|--------|---------------|------------------|
| Flexbox | Ratio-based sizing | Multiple approaches |
| Table | Built on FlexBox | Separate table components |
| Layout algorithm | Integer ratio distribution | Various (flex weights, constraints) |
| Scroll | Viewport-based | Component-level |
| Style inheritance | StylePassing chain | Theme fan-down |

---

## 14. Strengths

1. **Comprehensive layout system**: 8+ distinct layout strategies (StackedGrid, HStack, VStack, ZStack, FlexLayout, GridLayout, Layout, TileLayout)
2. **Immutable patterns**: Consistent use of readonly properties and wither methods
3. **Constraint-based layout**: Tile/Constraint system from tealeaves for sophisticated sizing
4. **Theme system**: Consistent Drawable interface with theme fan-down
5. **Responsive design**: Built-in breakpoint system for narrow/medium/wide terminals
6. **Module architecture**: Elm-pattern modules with clear init/update/view contract
7. **Deep component library**: 200+ components organized into 13 namespaces
8. **Plugin system**: JSON-based external binary integration
9. **VHS demos**: 40+ animated GIF demos in `.vhs/` directory
10. **Dual-SSOT awareness**: Clear documentation of distinct primitive types

---

## 15. Architectural Gaps & Future Opportunities

1. **Constraint enum system**: Ratatui-style `Constraint::Min()`, `Constraint::Max()`, `Constraint::Length()`, `Constraint::Percentage()`, `Constraint::Ratio()`, `Constraint::Fill()` -- currently handled via separate Constraint class
2. **StatefulWidget separation**: Components like Tabs, List, Table mix state and rendering; external state objects (SelectionState) would improve testability
3. **Reactive properties**: No reactive mechanism for automatic re-render on state change
4. **Component lifecycle**: No mount/unmount hooks for initialization/cleanup
5. **Block wrapper**: Borders/padding/title rendering duplicated in Frame, Panel, Card; Ratatui-style Block wrapper would DRY this up
6. **Streaming data**: No support for streaming/chunked data updates (vs bubble-grid's append mode)
7. **Bubble sort in Table**: Table sorting uses bubble sort (O(n²)) -- could use PHP's built-in sorting
8. **Gap distribution**: FlexLayout's space-around and space-evenly implementations are simplified/stubbed
9. **Event handling**: Components are pure render-only; events handled externally in candy-core runtime
10. **Composition pattern**: No `compose()` generator interface for declarative child declaration

---

## 16. File Reference Index

### Core Grid System
- `/home/sites/sugarcraft/sugar-dash/src/Layout/Grid/StackedGrid.php` -- Main grid layout
- `/home/sites/sugarcraft/sugar-dash/src/Layout/Grid/Options.php` -- Grid options
- `/home/sites/sugarcraft/sugar-dash/src/Layout/Grid/ItemOptions.php` -- Per-item placement
- `/home/sites/sugarcraft/sugar-dash/src/Layout/Grid/ItemWithOptions.php` -- Internal pairing

### Layout Containers
- `/home/sites/sugarcraft/sugar-dash/src/Layout/HStack.php`
- `/home/sites/sugarcraft/sugar-dash/src/Layout/VStack.php`
- `/home/sites/sugarcraft/sugar-dash/src/Layout/ZStack.php`
- `/home/sites/sugarcraft/sugar-dash/src/Layout/Stack.php`
- `/home/sites/sugarcraft/sugar-dash/src/Layout/FlexLayout.php`
- `/home/sites/sugarcraft/sugar-dash/src/Layout/GridLayout.php`
- `/home/sites/sugarcraft/sugar-dash/src/Layout/Layout.php`

### Panel/Frame System
- `/home/sites/sugarcraft/sugar-dash/src/Layout/Frame.php`
- `/home/sites/sugarcraft/sugar-dash/src/Layout/Panel.php`

### Tile Layout
- `/home/sites/sugarcraft/sugar-dash/src/Layout/Tile/TileLayout.php`
- `/home/sites/sugarcraft/sugar-dash/src/Layout/Tile/Constraint.php`
- `/home/sites/sugarcraft/sugar-dash/src/Layout/Tile/Resolver.php`
- `/home/sites/sugarcraft/sugar-dash/src/Layout/Tile/Size.php`
- `/home/sites/sugarcraft/sugar-dash/src/Layout/Tile/Tile.php`

### Foundation Interfaces
- `/home/sites/sugarcraft/sugar-dash/src/Foundation/Item.php`
- `/home/sites/sugarcraft/sugar-dash/src/Foundation/Sizer.php`
- `/home/sites/sugarcraft/sugar-dash/src/Foundation/Drawable.php`

### Module System
- `/home/sites/sugarcraft/sugar-dash/src/Module/Module.php`
- `/home/sites/sugarcraft/sugar-dash/src/Module/BaseModule.php`

### Components
- `/home/sites/sugarcraft/sugar-dash/src/Components/Tabs/Tabs.php`
- `/home/sites/sugarcraft/sugar-dash/src/Components/StatusBar/StatusBar.php`

### Screen & Output
- `/home/sites/sugarcraft/sugar-dash/src/Layout/Screen.php`

---

## 17. Summary

sugar-dash is a mature, comprehensive TUI dashboard library that successfully ports multiple Go TUI patterns to PHP. Its architecture demonstrates sophisticated understanding of layout algorithms (flexbox, CSS grid, constraint-based), component composition patterns ( Elm architecture modules), and PHP-specific immutable coding practices (readonly properties, wither methods, clone-then-mutate).

The library's primary strength is its breadth -- over 200 components across 13 namespaces -- while maintaining consistent patterns for sizing, theming, and event handling. The StackedGrid system, derived from bubble-grid, provides an intuitive column-based dashboard layout, while the various stack implementations (H/V/Z) offer flexible composition. The Tile/Constraint system from tealeaves enables sophisticated constraint-based sizing.

Primary areas for future enhancement include:
1. Constraint enum system (Ratatui-style) for more declarative sizing
2. StatefulWidget separation for better testability
3. Reactive properties for automatic re-rendering
4. Block wrapper component to DRY up border/padding rendering

The dual-SSOT awareness (documented distinction between sugar-dash primitives and other SugarCraft libraries' primitives) reflects mature architectural thinking about the relationship between different ports in the ecosystem.
