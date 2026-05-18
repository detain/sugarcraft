# SugarCraft\Dash

A comprehensive TUI component library for PHP 8.3+, ported from the Charmbracelet ecosystem (bubbletea, bubble-grid, lipgloss). Provides 200+ components organized into 13 namespaces for building rich terminal user interfaces.

## Installation

```bash
composer require sugarcraft/sugar-dash
```

## Namespace Structure (13 Namespaces)

| Namespace | Description |
|-----------|-------------|
| `Foundation\` | Pure interfaces + low-level primitives (Item, Sizer, Style, Theme, Color, Rect, Drawable, Buffer, Cell) |
| `Layout\` | Layout primitives (Stack, VStack, HStack, ZStack, FlexLayout, GridLayout, Frame, Panel, Split, Spacer, Window, etc.) |
| `Components\` | UI components (Modal, Select, Toast, Tabs, StatusBar, Form, Feedback, Nav, Card, Calendar, Tree, Table, etc.) |
| `Plot\` | Charts and plotting (Chart, Sparkline, Gauge, Donut, Heatmap, RadarChart, TreeViz, Graph, etc.) |
| `Module\` | Module interface + base implementations |
| `Registry\` | Registry pattern for modules |
| `Plugin\` | Plugin system with JSON protocol |
| `Modules\` | Built-in modules (Clock, System, Weather, etc.) |
| `Events\` | Input/event plumbing (Event, KeyEvent, MouseEvent, FocusEvent, etc.) |
| `Keys\` | Key registry and mappings |
| `Position\` | ANSI-aware geometry helpers |
| `Output\` | Extracted helpers (truncate, render bar) |
| `State\` | State management |

---

## Foundation Namespace

### Interfaces & Contracts

| Type | Description | Key Methods |
|------|-------------|-------------|
| `Item` | Anything that can be rendered as a string | `render(): string` |
| `Sizer` | An Item that knows its own dimensions (extends Item) | `setSize(int $width, int $height): Sizer`, `render(): string` |
| `Drawable` | Universal draw contract with GetRect/SetRect/Draw | `getRect(): Rect`, `setRect(Rect): void`, `draw(Buffer): void` |

### Configuration Classes

| Type | Description | Key Properties |
|------|-------------|----------------|
| `Options` | Grid-level configuration options | `$fitScreen: bool` (default: true) |
| `ItemOptions` | Per-item placement options within StackedGrid | `$column: int` (0-based), `$expandVertical: bool` |
| `ItemWithOptions` | Internal pairing of Item + ItemOptions | `$item: Item`, `$options: ItemOptions` |

### Low-Level Primitives

| Type | Description | Key Methods |
|------|-------------|-------------|
| `Cell` | Single terminal cell (rune + Style) — sugar-dash SSOT, distinct from `\SugarCraft\Vt\Cell\Cell` | |
| `Buffer` | Cell grid buffer for drawing — sugar-dash SSOT, distinct from `\SugarCraft\Vt\Buffer\Buffer` | `getCell(x,y)`, `setCell(x,y,Cell)`, `fill(rect,Cell)` |
| `Rect` | Rectangle geometry (rectmath bounds model: minX/minY/maxX/maxY) — distinct from `\SugarCraft\Core\Rect` (offset+size model) | `contains()`, `intersect()`, `dx()`, `dy()` |
| `Style` | Terminal styling (inline foreground/background Color slots) — sugar-dash SSOT, distinct from `\SugarCraft\Sprinkles\Style` (lipgloss padding/margin/borders) | `fg()`, `bg()`, `bold()`, etc. |
| `StyleParser` | Parses `[text](fg:red,bg:blue)` into Dash Cell arrays — sugar-dash SSOT, NOT drop-in compatible with `\SugarCraft\Sprinkles\StyleParser` | |
| `Color` | Backward-compat alias for `\SugarCraft\Core\Util\Color` (true duplicate, replaced by `class_alias` shim — prefer Core import in new code) | |
| `Theme` | Pre-defined theme palettes (10 colour slots + helpers) — sugar-dash SSOT, distinct from `\SugarCraft\Sprinkles\Theme` (13 slots, readonly only) | `dark()`, `dracula()`, `oneDark()`, `githubDark()`, `light()` |

> **Dual-SSOT note.** Five Foundation primitives (`Style`/`Theme`/`Rect`/`Buffer`/`Cell`) plus `StyleParser` are intentionally distinct from same-named canonical types in `candy-sprinkles`/`candy-core`/`candy-vt`. The lineage differs (charmbracelet/inline-termui for sugar-dash, lipgloss/ratatui/VT-emulator for the others) and the API shapes diverge. Only `Color` was a true duplicate and is now a `class_alias` to `\SugarCraft\Core\Util\Color`. See `CALIBER_LEARNINGS.md` entries `[pattern:dual-foundation-ssot]`, `[pattern:dual-style-ssot]`, `[pattern:dual-theme-ssot]`, `[pattern:dual-rect-models]`, `[pattern:dual-buffer-roles]`, `[pattern:dual-cell-shapes]`.

---

## Layout Namespace

### Layout Enums

| Type | Values |
|------|--------|
| `LayoutDirection` | `Horizontal`, `Vertical` |
| `SplitDirection` | `Horizontal`, `Vertical` |
| `AlignItems` | `Start`, `End`, `FlexStart`, `FlexEnd`, `Center`, `Stretch`, `Baseline` |
| `FlexDirection` | `Row`, `Column`, `RowReverse`, `ColumnReverse` |
| `FlexWrap` | `NoWrap`, `Wrap`, `WrapReverse` |
| `HAlign` | `Left`, `Right`, `Center` |
| `VAlign` | `Top`, `Middle`, `Bottom` |
| `JustifyContent` | `Start`, `End`, `FlexStart`, `FlexEnd`, `Center`, `SpaceBetween`, `SpaceAround`, `SpaceEvenly` |

### Layout Containers

| Type | Description | Key Methods/Factories | GIF |
|------|-------------|----------------------|-----|
| `StackedGrid` | Multi-column stacked grid layout with items in columns | `addItem(Item, ItemOptions)`, `new(Options)` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/stacked-grid.gif) |
| `GridLayout` | CSS Grid-style layout with rows/columns/gaps | `columns(int, items)`, `rows(int, items)`, `withGap()`, `withItem()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/grid-layout.gif) |
| `FlexLayout` | Flexbox-style layout with direction/wrap/justify | `row()`, `column()`, `withJustify()`, `withAlignItems()`, `withGap()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/flex-layout.gif) |
| `VStack` | Vertical stack with alignment and spacing | `new(...items)`, `spaced(int, ...items)`, `centered(...items)`, `right(...items)` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/vstack.gif) |
| `HStack` | Horizontal stack with spacing and alignment | `new(...items)`, `spaced(int, ...items)`, `centered(...items)` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/hstack.gif) |
| `ZStack` | Layered stack (items on top of each other) | `new(...items)`, `left(...items)`, `right(...items)`, `top(...items)`, `bottom(...items)` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/zstack.gif) |
| `Stack` | Basic vertical stack | `new(...items)`, `spaced(int, ...items)` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/stack.gif) |
| `Split` | Split view with two panes | `new(...items)`, `horizontal()`, `vertical()` | |

### Border & Frame Components

| Type | Description | Key Methods/Factories | GIF |
|------|-------------|----------------------|-----|
| `Frame` | Bordered frame wrapping any Item with title/padding | `new(Item)`, `withBorder()`, `withBorderColor()`, `withPadding()`, `withTitle()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/frame.gif) |
| `Panel` | Panel with header/content/footer sections | `new(Item|string)`, `titled(Item|string, string)`, `withContent()`, `withHeader()`, `withFooter()`, `withStyle()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/panel.gif) |
| `BoxDrawing` | Unicode box-drawing frame generator | `new(?string)`, `titled(string)`, `double()`, `rounded()`, `bold()`, `withStyle()`, `withBorderColor()`, `withBgColor()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/box-drawing.gif) |
| `BorderText` | Text with ASCII-art border characters | `new(Item|string)`, `withBorders(Item|string)`, `withBorderColor()`, `withTextColor()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/border-text.gif) |
| `Divider` | Horizontal or vertical divider line | `new(?string)`, `h(?string)`, `v(?string)`, `withStyle()`, `withLabel()`, `withColor()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/divider.gif) |

### Spacing & Layout Helpers

| Type | Description | Key Methods/Factories | GIF |
|------|-------------|----------------------|-----|
| `Spacer` | Empty space filler with optional fill character | `new(int $width, int $height)`, `dotted(int)`, `dashed(int)`, `vertical(int, int)` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/spacer.gif) |
| `LayoutItem` | Item with flex properties for layouts | `flex(Item, int)`, `fixed(Item)` | |
| `Shadow` | Drop shadow effect wrapping any Item | `new(Item)`, `withStyle()`, `withColor()`, `withOffset()`, `withHeavy()`, `withNoShadow()` | |
| `Segment` | 7-segment digital display | `new(string)`, `withDigitWidth()`, `withOnColor()`, `withOffColor()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/segment.gif) |
| `Window` | Window frame with title bar | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/window.gif) |
| `Screen` | Terminal screen container | | |
| `Viewport` | Scrollable viewport | | |
| `Sidebar` | Sidebar navigation panel | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/sidebar.gif) |
| `Breakpoint` | Static helpers for responsive breakpoints — `narrow()`/`medium()`/`wide()`/`pick()` (default thresholds 90/140, Homedash convention) | `narrow(int $width, int $threshold = 90): bool`, `medium(int $width, int $narrow = 90, int $wide = 140): bool`, `wide(int $width, int $threshold = 140): bool`, `pick(int $width, array $thresholds): string` | |
| `Pad` | Padding wrapper (formerly Boxer) | | |

---

## Plot Namespace (Charts & Visualization)

### Chart Components

| Type | Description | Key Methods/Factories | GIF |
|------|-------------|----------------------|-----|
| `Plot` | Line/scatter chart with braille plotting | `new(dataPoints, type)`, `withDataPoints()`, `withType()`, `withColor()`, `withGrid()` | |
| `Chart` | Bar/line chart with axes, labels, grid | `new(dataPoints, type)`, `withDataPoints()`, `withType()`, `withColor()`, `withGrid()`, `withShowValues()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/chart.gif) |
| `AreaChart` | Area chart for time series data | `new(series)`, `withShowGrid()`, `withShowLegend()`, `withMaxValue()`, `withStacked()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/area-chart.gif) |
| `Area` | Stacked area chart with gradient fills | `new(dataPoints)`, `sample(int)`, `withDataPoints()`, `withStacked()`, `withShowLegend()` | |
| `AreaPoint` | Area chart data point: `label: string`, `value: float`, `y0: float|null` | | |
| `Bar` | Horizontal status bar with colors | `new(string)`, `withContent()`, `withForeground()`, `withBackground()`, `withAlign()`, `withBorders()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/bar.gif) |
| `CandlestickChart` | Financial candlestick chart | `new()`, `withCandle()`, `addCandle()`, `withShowGrid()`, `withShowVolume()` | |
| `Candlestick` | Single OHLC candlestick: `label`, `open`, `high`, `low`, `close` | `bullish()`, `bearish()`, `isBullish()` | |
| `Donut` | Donut chart with proportional segments | `new(data)`, `mocha(data)`, `withSize()`, `withCenterLabel()`, `withShowPercentage()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/donut.gif) |
| `Gauge` | Horizontal progress bar / gauge | `new(float $ratio)`, `withWidth()`, `withFilledColor()`, `withEmptyColor()`, `withPercentage()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/gauge.gif) |
| `GaugeChart` | Circular gauge chart | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/gauge-chart.gif) |
| `GaugeCircle` | Circular gauge visualization | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/gauge-circle.gif) |
| `HeatMapChart` | 2D heatmap with color gradient | `new(data)`, `sample()`, `withRowLabels()`, `withColumnLabels()`, `withLowColor()`, `withHighColor()` | |
| `Heatmap` | Heat map visualization with legend | `new(data)`, `sample()`, `withLegend()`, `withValues()`, `withLowColor()`, `withHighColor()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/heatmap.gif) |
| `HeatmapCalendar` | GitHub-style calendar heatmap | `new(data)`, `sample()`, `withLowColor()`, `withHighColor()`, `withEmptyChar()` | |
| `RadarChart` | Radar/spider chart for multi-axis data | `new(labels, series)`, `withSize()`, `withGridLines()`, `withShowLabels()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/radar-chart.gif) |
| `Sparkline` | Inline sparkline chart | `new(data)`, `withData()`, `withWidth()`, `withHeight()`, `withDataPoints()`, `withFill()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/sparkline.gif) |
| `SparklineBar` | Bar-style sparkline | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/sparkline-bar.gif) |
| `SparklineArea` | Area-style sparkline | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/sparkline-area.gif) |
| `SparkArea` | Spark area chart | | |
| `FunnelChart` | Funnel chart visualization | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/funnel-chart.gif) |
| `Funnel` | Funnel visualization component | | |
| `Bullet` | Bullet chart | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/bullet.gif) |
| `Meter` | Meter/gauge component | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/meter.gif) |
| `Rating` | Star rating display | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/rating.gif) |
| `OHLC` | Open-High-Low-Close data | | |
| `OHLCPoint` | OHLC data point | | |
| `Waterfall` | Waterfall chart | | |
| `WaterfallItem` | Waterfall bar item | | |
| `MetricsGrid` | Grid of metric displays | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/metrics-grid.gif) |

### Graph & Network Visualization

| Type | Description | Key Methods/Factories | GIF |
|------|-------------|----------------------|-----|
| `Sankey` | Sankey diagram for flow visualization | `new()`, `addNode()`, `addFlow()`, `withHorizontal()`, `withShowLabels()` | |
| `SankeyNode` | Node in Sankey diagram: `id`, `label`, `value`, `color` | | |
| `SankeyFlow` | Flow connection: `source`, `target`, `value`, `color` | | |
| `Sunburst` | Sunburst chart visualization | | |
| `Treemap` | Treemap chart visualization | | |
| `TreemapLeaf` | Leaf node in treemap | | |
| `TreeViz` | Tree visualization | | |
| `Network` | Network diagram | | |
| `NetworkNode` | Node in network | | |
| `NetworkShape` | `Circle`, `Square`, `Diamond`, `Hexagon`, `Star` | | |
| `MindMap` | Mind map visualization | | |
| `OrgChart` | Organization chart | | |
| `ClassDiagram` | UML class diagram | | |
| `Flowchart` | Flowchart diagram | | |
| `FlowchartNode` | Node in flowchart | | |
| `FlowchartNodeType` | `Process`, `Decision`, `StartEnd`, `InputOutput`, `Connector`, `Data` | | |
| `Dendrogram` | Dendrogram/tree diagram | | |
| `DendrogramNode` | Node in dendrogram | | |
| `Gantt` | Gantt chart | | |
| `PERT` | PERT chart | | |
| `Timeline` | Timeline display | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/timeline.gif) |
| `TimelineViz` | Timeline visualization | | |
| `TimelineNode` | Node in timeline | | |
| `Sequence` | Sequence diagram | | |
| `Graph` | Graph visualization | | |
| `Bubble` | Bubble chart | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/bubble.gif) |
| `BubblePoint` | Bubble chart point | | |
| `Leaderboard` | Leaderboard display | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/leaderboard.gif) |
| `WordCloud` | Word cloud visualization | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/word-cloud.gif) |
| `DotMatrix` | Dot matrix display | | |
| `Pictogram` | Pictogram display | | |
| `Partition` | Partition chart | | |
| `PartitionSegment` | Partition segment | | |
| `Diff` | Diff view | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/diff.gif) |
| `Ladder` | Ladder diagram | | |
| `Canvas` | Drawing canvas | | |
| `Scrollbar` | Custom scrollbar | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/scrollbar.gif) |

---

## Components Namespace

### Components\Form (Form & Input)

| Type | Description | Key Methods/Factories | GIF |
|------|-------------|----------------------|-----|
| `Input` | Text input field | `new(?string)`, `labeled()`, `password()`, `withValue()`, `withPlaceholder()`, `withLabel()`, `withError()`, `withBorderColor()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/input.gif) |
| `Textarea` | Multi-line text area | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/textarea.gif) |
| `Checkbox` | Checkbox group with single/multi-select | `new(options)`, `withSelectedIndex()`, `withOptionChecked()`, `withMultiSelect()`, `withCheckedColor()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/checkbox.gif) |
| `Radio` | Radio button | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/radio.gif) |
| `Toggle` | Toggle switch | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/toggle.gif) |
| `SwitchComponent` | Switch component | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/switch-component.gif) |
| `Slider` | Slider control | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/slider.gif) |
| `Select` | Dropdown select component | `new(options)`, `withSelectedIndex()`, `withOptions()`, `withSelectedColor()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/select.gif) |
| `ComboBox` | Combo box | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/combo-box.gif) |
| `Dropdown` | Dropdown menu | | |
| `DatePicker` | Date picker | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/date-picker.gif) |
| `ColorPicker` | Color picker | | |
| `Label` | Form label | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/label.gif) |
| `Chip` | Chip/tag component | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/chip.gif) |
| `ChipGroup` | Group of chips | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/chip-group.gif) |
| `Editor` | Text editor | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/editor.gif) |
| `CommandPalette` | Command palette | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/command-palette.gif) |
| `Cursor` | Terminal cursor | | |

---

### Components\Feedback (Non-Modal Feedback)

| Type | Description | Key Methods/Factories | GIF |
|------|-------------|----------------------|-----|
| `Alert` | Alert/message box (info, warning, error, success) | `new(string)`, `info()`, `warning()`, `error()`, `success()`, `withMessage()`, `withTitle()`, `withBorderColor()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/alert.gif) |
| `Badge` | Badge/tag component | `new(string)`, `success()`, `warning()`, `error()`, `info()`, `withStyle()`, `withSize()`, `withIcon()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/badge.gif) |
| `BadgeGroup` | Group of badges | | |
| `LoadingText` | Animated loading text | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/loading-text.gif) |
| `Skeleton` | Loading skeleton | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/skeleton.gif) |
| `Spinner` | Loading spinner | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/spinner.gif) |
| `Toast` | Toast message | `new()`, `info()`, `success()`, `warning()`, `error()`, `fromNotification()`, `fromQueue()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/toast.gif) |
| `NotificationQueue` | Dual-ring queue: active `items[max 20]` + `history[max 50]` | `new()`, `push()`, `dismiss()`, `current()`, `recent()`, `all()`, `history()`, `count()`, `historyCount()` | |
| `Level` | Toast level enum: `Info`, `Warning`, `Error`, `Success` | `icon()`, `isError()`, `isHighlighted()` | |
| `Notification` | Toast notification DTO: `message`, `level`, `title` | `info()`, `warning()`, `error()`, `success()` | |
| `Tooltip` | Tooltip popup | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/tooltip.gif) |
| `Popover` | Popover content | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/popover.gif) |
| `NProgress` | npm-style progress bar | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/nprogress.gif) |
| `Marquee` | Scrolling marquee text | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/marquee.gif) |
| `EmptyState` | Empty state placeholder | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/empty-state.gif) |

### Components\Modal (Modal Dialogs)

| Type | Description | Key Methods/Factories | GIF |
|------|-------------|----------------------|-----|
| `Modal` | Modal dialog | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/modal.gif) |
| `Notification` | Toast notification | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/notification.gif) |
| `Progress` | Progress indicator | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/progress.gif) |
| `ProgressBar` | Progress bar | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/progress-bar.gif) |
| `ProgressRing` | Circular progress indicator | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/progress-ring.gif) |
| `Drawer` | Drawer panel | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/drawer.gif) |
| `Wizard` | Multi-step wizard | | |
| `WizardStep` | Wizard step | | |

---

### Components\Nav (Navigation)

| Type | Description | Key Methods/Factories | GIF |
|------|-------------|----------------------|-----|
| `Tabs` | Tabbed interface | `new(tabs)`, `withSelectedIndex()`, `withActiveColor()`, `withTabs()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/tabs.gif) |
| `TabsVertical` | Vertical tab navigation | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/tabs-vertical.gif) |
| `Breadcrumb` | Breadcrumb navigation | `new(items)`, `fromPath()`, `withItems()`, `withSeparator()`, `withActiveIndex()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/breadcrumb.gif) |
| `Pagination` | Pagination controls | | |
| `PaginationSimple` | Simple pagination | | |
| `Stepper` | Step progress indicator | | |
| `Navbar` | Navigation bar | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/navbar.gif) |
| `Menu` | Menu component | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/menu.gif) |
| `Ladder` | Ladder diagram | | |
| `Sequence` | Sequence diagram | | |
| `Scrollbar` | Custom scrollbar | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/scrollbar.gif) |

### Components\StatusBar (Status Bar)

| Type | Description | Key Methods/Factories | GIF |
|------|-------------|----------------------|-----|
| `StatusBar` | Status bar with left/right zones | `new()`, `withLeft()`, `withRight()`, `withSeparator()` | |
| `StatusIndicator` | Status indicator dot | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/status-indicator.gif) |

---

### Components\Card (Card & Content Components)

| Type | Description | Key Methods/Factories | GIF |
|------|-------------|----------------------|-----|
| `Card` | Card container component | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/card.gif) |
| `Header` | Page header | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/header.gif) |
| `Footer` | Page footer | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/footer.gif) |
| `Cover` | Cover layout | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/cover.gif) |
| `Jumbotron` | Jumbotron hero section | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/jumbotron.gif) |
| `CTA` | Call-to-action component | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/cta.gif) |
| `Profile` | User profile card | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/profile.gif) |
| `Testimonial` | Testimonial quote | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/testimonial.gif) |
| `Pricing` | Pricing table | | |
| `Features` | Feature grid | | |
| `Accordion` | Accordion/collapsible | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/accordion.gif) |
| `Comment` | Comment component | | |
| `ActivityFeed` | Activity feed | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/activity-feed.gif) |
| `Leaderboard` | Leaderboard display | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/leaderboard.gif) |

### Components\Media (Media Components)

| Type | Description | Key Methods/Factories | GIF |
|------|-------------|----------------------|-----|
| `Image` | Image display | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/image.gif) |
| `Picture` | Picture frame | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/picture.gif) |
| `Avatar` | User avatar | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/avatar.gif) |
| `AvatarGroup` | Group of avatars | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/avatar-group.gif) |
| `Icon` | Icon display | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/icon.gif) |
| `QRCode` | QR code | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/qr-code.gif) |
| `Barcode` | Barcode | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/barcode.gif) |
| `Video` | Video player | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/video.gif) |
| `Audio` | Audio player | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/audio.gif) |
| `FigletText` | ASCII art text (FIGlet style) | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/figlet-text.gif) |
| `ASCIIBanner` | ASCII banner text | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/ascii-banner.gif) |
| `Emoji` | Emoji display | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/emoji.gif) |
| `Marquee` | Scrolling marquee text | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/marquee.gif) |

### Components\Calendar (Calendar & Date Components)

| Type | Description | Key Methods/Factories | GIF |
|------|-------------|----------------------|-----|
| `Calendar` | Calendar view | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/calendar.gif) |
| `ListComponent` | List renderer | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/list-component.gif) |

### Components\System (System/Console Components)

| Type | Description | Key Methods/Factories | GIF |
|------|-------------|----------------------|-----|
| `Console` | Terminal console | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/console.gif) |
| `Terminal` | Terminal emulator | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/terminal.gif) |
| `Log` | Log entry | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/log.gif) |
| `LogViewer` | Log file viewer | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/log-viewer.gif) |
| `HexDump` | Hex dump viewer | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/hex-dump.gif) |
| `Clock` | Digital clock | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/clock.gif) |
| `Timer` | Countdown timer | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/timer.gif) |
| `Stopwatch` | Stopwatch | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/stopwatch.gif) |

---

### Components\Tree (Tree Structure Components)

| Type | Description | Key Methods/Factories | GIF |
|------|-------------|----------------------|-----|
| `Tree` | Tree structure | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/tree.gif) |
| `TreeNode` | Tree node | | |
| `StateMachine` | UML-style state machine diagram | `new()`, `addState()`, `addTransition()`, `addGuard()`, `withInitialState()` | |
| `StateNode` | Node in a state machine (id/label/isInitial/isFinal/entryActions/exitActions) | | |
| `StateTransition` | Transition between states (from/to/label/trigger/action/guard/type) | | |
| `TransitionType` | Enum: `Normal`, `Guard`, `Internal` | | |

### Components\Table (Table Components)

| Type | Description | Key Methods/Factories | GIF |
|------|-------------|----------------------|-----|
| `TableChart` | Table-based chart | | |
| `TableBordered` | Bordered table | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/table-bordered.gif) |
| `TableZebra` | Zebra-striped table | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/table-zebra.gif) |
| `Stat` | Single stat display | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/stat.gif) |
| `Stats` | Statistics display | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/stats.gif) |
| `Metric` | Metric display | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/metric.gif) |
| `ProgressList` | List of progress items | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/progress-list.gif) |

### Components\Text (Text Components)

| Type | Description | Key Methods/Factories | GIF |
|------|-------------|----------------------|-----|
| `Text` | Word-wrapped text content | `new(string)`, `withMaxWidth()`, `withTrim()`, `withHorizontalAlign()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/text.gif) |
| `Paragraph` | Paragraph text | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/paragraph.gif) |
| `Code` | Code block with syntax highlighting | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/code.gif) |
| `Kbd` | Keyboard key display | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/kbd.gif) |
| `Markdown` | Markdown rendering | | |
| `Highlight` | Syntax highlighted code | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/highlight.gif) |
| `Diff` | Diff view | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/diff.gif) |

---

## Events Namespace (Input/Event Plumbing)

| Type | Description | Key Properties |
|------|-------------|----------------|
| `Event` | Base event class |
| `EventHandler` | Event handler callback |
| `EventDispatcher` | Event dispatcher |
| `FocusEvent` | Focus event |
| `KeyEvent` | Keyboard event |
| `MouseEvent` | Mouse event |
| `PasteEvent` | Paste event |
| `ResizeEvent` | Resize event |
| `Focus` | Focus state management |

## Keys Namespace (Key Registry)

| Type | Description | Key Properties |
|------|-------------|----------------|
| `Key` | Key representation |
| `KeyAction` | Key action |
| `KeyMap` | Key mapping |

## State Namespace (State Management)

| Type | Description | Key Methods |
|------|-------------|-------------|
| `State` | Application state diagram | |
| `Persistence` | Atomic tmp+rename state save/load | `save(path, data)`, `load(path): ?array` |

> **Note:** `TransitionType`, `StateNode`, `StateTransition`, and `StateMachine` moved to `Components\Tree\` namespace (PSR-4 one-class-per-file). The `State\*` classes are retained as `@internal` backward-compatibility re-exports via `class_alias`.

## Position Namespace (ANSI-Aware Geometry)

| Type | Description | Key Methods |
|------|-------------|-------------|
| `Center` | Calculate centered position |
| `HAlign` | `Left`, `Right`, `Center` |
| `VAlign` | `Top`, `Middle`, `Bottom` |

## Output Namespace (Extracted Helpers)

| Type | Description | Key Methods |
|------|-------------|-------------|
| `Truncate` | String truncation with ANSI awareness |
| `RenderBar` | Bar rendering helper |
| `WrapCells` | Cell-aware text wrapping |

## Module Namespace (Module Interface)

| Type | Description | Key Methods |
|------|-------------|-------------|
| `Module` | Elm-arch interface aligned with `Core\Model`: `init(): ?Closure`, `update(Msg): array{0:Module,1:?Cmd}`, `view(): string`, plus `name(): string`, `minSize(): array{0:int,1:int}` |
| `BaseModule` | Abstract helper — `withState(array): static` for immutable state, default `update()` returns `[self,null]` |
| `LegacyModule` | Deprecated array-state interface — superseded by `Module` |
| `LegacyModuleAdapter` | `@internal` wrapper that adapts `LegacyModule` to the `Module` contract |
| `ModuleConfig` | Module configuration |
| `ImagePlacer` | Optional interface for image placements |
| `ImagePlacement` | Image placement data |
| `TickEpoch` | Focus-regain epoch counter |

## Registry Namespace (Module Registry)

| Type | Description | Key Methods |
|------|-------------|-------------|
| `Registry` | Static register/get/list/reset for modules; auto-wraps `LegacyModule` via `LegacyModuleAdapter` |

## Plugin Namespace (Plugin System)

| Type | Description | Key Methods |
|------|-------------|-------------|
| `Request` | Plugin request DTO |
| `Response` | Plugin response DTO |
| `PluginSdk` | Plugin runner loop |
| `ExternalModule` | Wraps binary into Module interface |
| `Discovery` | Plugin discovery from filesystem |

## Modules Namespace (Built-in Modules)

All built-in modules extend `BaseModule` and use `withState()` for immutable state updates.

| Type | Description |
|------|-------------|
| `Clock\ClockModule` | Single-line clock |
| `System\SystemModule` | CPU/mem/disk stats |
| `Uptime\UptimeModule` | System uptime |
| `Greeting\GreetingModule` | Time-of-day greeting |
| `Generic\GenericModule` | Arbitrary shell command runner |
| `Weather\WeatherModule` | Live weather from wttr.in + 30min cache + stale fallback |
| `Weather\WttrInClient` | wttr.in J1 JSON API client implementing `HttpClient` |
| `Weather\HttpClient` | Interface for weather fetch — allows test doubles |
| `Weather\WeatherSnapshot` | Readonly DTO: `tempC`, `condition`, `location`, `fetchedAt` |

---

## Usage Example

```php
use SugarCraft\Dash\Layout\{Frame, VStack, Panel};
use SugarCraft\Dash\Layout\{StackedGrid, Options, ItemOptions};
use SugarCraft\Dash\Components\Text;

// Create a stacked grid layout
$grid = new StackedGrid(new Options(fitScreen: true));

// Add items to columns
$grid->addItem(
    Frame::new(
        VStack::centered(
            Text::new('Welcome to SugarDash'),
            Text::new('Build beautiful TUIs')
        )
    )->withPadding(1),
    new ItemOptions(column: 0, expandVertical: true)
);

// Add a panel to the second column
$grid->addItem(
    Panel::titled(
        Text::new('This is a panel'),
        'Dashboard'
    ),
    new ItemOptions(column: 1)
);

// Set size and render
$grid->setSize(80, 24);
echo $grid->render();
```

## Testing

```bash
cd sugar-dash && composer install && vendor/bin/phpunit
```

## GIF Demos

| Demo | Description |
|------|-------------|
| ![Dashboard Live](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/dashboard-live.gif) | **Interactive dashboard** — Clock/System/Weather panels with keyboard focus rotation (Tab/arrows), q/Ctrl-C quit |
| ![Dashboard Layout](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/dashboard-layout.gif) | Layout containers demo |
| ![Dashboard Charts](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/dashboard-charts.gif) | Charts demo |
| ![Dashboard Form](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/dashboard-form.gif) | Form demo |
| ![Dashboard Nav](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/dashboard-nav.gif) | Navigation demo |
| ![Dashboard Status](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/dashboard-status.gif) | Status indicators demo |
| ![Dashboard Text](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/dashboard-text.gif) | Text components demo |
| ![Dashboard Time](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/dashboard-time.gif) | Time components demo |
| ![Dashboard UI](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/dashboard-ui.gif) | UI components demo |
| ![Dashboard Complex](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/dashboard-complex.gif) | Complex layout demo |
| ![Dashboard Data](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/dashboard-data.gif) | Data display demo |
| ![Dashboard Devtools](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/dashboard-devtools.gif) | Devtools demo |
| ![Dashboard Interactive](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/dashboard-interactive.gif) | Interactive components demo |
| ![Dashboard Media](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/dashboard-media.gif) | Media components demo |
| ![Dashboard Metrics](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/dashboard-metrics.gif) | Metrics display demo |
| ![Boxer](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/boxer.gif) | **Three-panel Boxer layout** — horizontal split with three named leaves and visual focus indicator |
| ![GridTable](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/gridtable.gif) | **Sortable/filterable GridTable** — pagination, column sort, text filter across 25 rows |
| ![Plot Braille](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/plot-braille.gif) | **Plot marker comparison** — side-by-side MarkerDot vs MarkerBraille rendering |

## Example Demos

The `examples/` directory contains standalone demo files that showcase individual components and combinations:

| Demo | Description |
|------|-------------|
| `dashboard-live.php` | **Interactive dashboard** — the headline demo. Program event loop, raw mode, Clock/System/Weather modules, Boxer layout, FocusManager, per-panel tick, keyboard navigation (Tab/arrows), quit (q/Ctrl-C). Run with `php examples/dashboard-live.php` |
| `dashboard-showcase.php` | Multi-component server dashboard with gauges, charts, timeline, breadcrumb, avatar group |
| `dashboard-complex.php` | Full-featured analytics dashboard with charts, stats, funnel, sparkline |
| `dashboard-interactive.php` | Accordion and timeline components |
| `dashboard-metrics.php` | Key statistics and status indicators |
| `dashboard-status.php` | Spinners, progress bars, gauges, alerts |
| `dashboard-charts.php` | Chart components including area, donut, radar, heatmap |
| `dashboard-form.php` | Form components demo |
| `dashboard-ui.php` | UI components demo |
| `dashboard-nav.php` | Navigation components demo |
| `dashboard-text.php` | Text components demo |
| `dashboard-time.php` | Time components demo |
| `dashboard-media.php` | Media components demo |
| `dashboard-data.php` | Data display components demo |
| `dashboard-devtools.php` | Devtools components demo |
| `dashboard-layout.php` | Layout containers demo |

## License

MIT License - See LICENSE file for details.
