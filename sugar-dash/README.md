# SugarCraft\Dash\Grid

A comprehensive TUI grid rendering library for PHP 8.3+, ported from the Charmbracelet ecosystem (bubbletea, bubble-grid, lipgloss). Provides 200+ components for building rich terminal user interfaces.

## Installation

```bash
composer require sugarcraft/sugar-dash
```

## Core Concepts

### Interfaces & Contracts

| Type | Description | Key Methods |
|------|-------------|-------------|
| `Item` | Anything that can be placed in a StackedGrid and rendered as a string | `render(): string` |
| `Sizer` | An Item that knows its own dimensions (extends Item) | `setSize(int $width, int $height): Sizer`, `render(): string` |

### Configuration Classes

| Type | Description | Key Properties |
|------|-------------|----------------|
| `Options` | Grid-level configuration options | `$fitScreen: bool` (default: true) |
| `ItemOptions` | Per-item placement options within StackedGrid | `$column: int` (0-based), `$expandVertical: bool` |
| `ItemWithOptions` | Internal pairing of Item + ItemOptions | `$item: Item`, `$options: ItemOptions` |

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
| `FlowchartNodeType` | `Process`, `Decision`, `StartEnd`, `InputOutput`, `Connector`, `Data` |
| `EdgeStyle` | `Solid`, `Dashed`, `Dotted`, `Bold` |
| `NetworkShape` | `Circle`, `Square`, `Diamond`, `Hexagon`, `Star` |
| `WaterfallBarType` | `Positive`, `Negative`, `Total`, `Subtotal` |

---

## Layout & Structural Components

### Layout Containers

| Type | Description | Key Methods/Factories |
|------|-------------|----------------------|
| `StackedGrid` | Multi-column stacked grid layout with items in columns | `addItem(Item, ItemOptions)`, `new(Options)` |
| `GridLayout` | CSS Grid-style layout with rows/columns/gaps | `columns(int, items)`, `rows(int, items)`, `withGap()`, `withItem()` |
| `FlexLayout` | Flexbox-style layout with direction/wrap/justify | `row()`, `column()`, `withJustify()`, `withAlignItems()`, `withGap()` |
| `VStack` | Vertical stack with alignment and spacing | `new(...items)`, `spaced(int, ...items)`, `centered(...items)`, `right(...items)` |
| `HStack` | Horizontal stack with spacing and alignment | `new(...items)`, `spaced(int, ...items)`, `centered(...items)` |
| `ZStack` | Layered stack (items on top of each other) | `new(...items)`, `left(...items)`, `right(...items)`, `top(...items)`, `bottom(...items)` |
| `Stack` | Basic vertical stack | `new(...items)`, `spaced(int, ...items)` |

### Border & Frame Components

| Type | Description | Key Methods/Factories |
|------|-------------|----------------------|
| `Frame` | Bordered frame wrapping any Item with title/padding | `new(Item)`, `withBorder()`, `withBorderColor()`, `withPadding()`, `withTitle()` |
| `Panel` | Panel with header/content/footer sections | `new(Item|string)`, `titled(Item|string, string)`, `withContent()`, `withHeader()`, `withFooter()`, `withStyle()` |
| `BoxDrawing` | Unicode box-drawing frame generator | `new(?string)`, `titled(string)`, `double()`, `rounded()`, `bold()`, `withStyle()`, `withBorderColor()`, `withBgColor()` |
| `BorderText` | Text with ASCII-art border characters | `new(Item|string)`, `withBorders(Item|string)`, `withBorderColor()`, `withTextColor()` |
| `Divider` | Horizontal or vertical divider line | `new(?string)`, `h(?string)`, `v(?string)`, `withStyle()`, `withLabel()`, `withColor()` |

### Spacing & Layout Helpers

| Type | Description | Key Methods/Factories |
|------|-------------|----------------------|
| `Spacer` | Empty space filler with optional fill character | `new(int $width, int $height)`, `dotted(int)`, `dashed(int)`, `vertical(int, int)` |
| `LayoutItem` | Item with flex properties for layouts | `flex(Item, int)`, `fixed(Item)` |
| `Shadow` | Drop shadow effect wrapping any Item | `new(Item)`, `withStyle()`, `withColor()`, `withOffset()`, `withHeavy()`, `withNoShadow()` |
| `Segment` | 7-segment digital display | `new(string)`, `withDigitWidth()`, `withOnColor()`, `withOffColor()` |

---

## Data Visualization (Charts)

### Chart Components

| Type | Description | Key Methods/Factories |
|------|-------------|----------------------|
| `Chart` | Bar/line chart with axes, labels, grid | `new(dataPoints, type)`, `withDataPoints()`, `withType()`, `withColor()`, `withGrid()`, `withShowValues()` |
| `AreaChart` | Area chart for time series data | `new(series)`, `withShowGrid()`, `withShowLegend()`, `withMaxValue()`, `withStacked()` |
| `Area` | Stacked area chart with gradient fills | `new(dataPoints)`, `sample(int)`, `withDataPoints()`, `withStacked()`, `withShowLegend()` |
| `AreaPoint` | Area chart data point: `label: string`, `value: float`, `y0: float|null` |
| `Bar` | Horizontal status bar with colors | `new(string)`, `withContent()`, `withForeground()`, `withBackground()`, `withAlign()`, `withBorders()` |
| `CandlestickChart` | Financial candlestick chart | `new()`, `withCandle()`, `addCandle()`, `withShowGrid()`, `withShowVolume()` |
| `Candlestick` | Single OHLC candlestick: `label`, `open`, `high`, `low`, `close` | `bullish()`, `bearish()`, `isBullish()` |
| `Donut` | Donut chart with proportional segments | `new(data)`, `mocha(data)`, `withSize()`, `withCenterLabel()`, `withShowPercentage()` |
| `Gauge` | Horizontal progress bar / gauge | `new(float $ratio)`, `withWidth()`, `withFilledColor()`, `withEmptyColor()`, `withPercentage()` |
| `GaugeChart` | Circular gauge chart |
| `GaugeCircle` | Circular gauge visualization |
| `HeatMapChart` | 2D heatmap with color gradient | `new(data)`, `sample()`, `withRowLabels()`, `withColumnLabels()`, `withLowColor()`, `withHighColor()` |
| `Heatmap` | Heat map visualization with legend | `new(data)`, `sample()`, `withLegend()`, `withValues()`, `withLowColor()`, `withHighColor()` |
| `HeatmapCalendar` | GitHub-style calendar heatmap | `new(data)`, `sample()`, `withLowColor()`, `withHighColor()`, `withEmptyChar()` |
| `RadarChart` | Radar/spider chart for multi-axis data | `new(labels, series)`, `withSize()`, `withGridLines()`, `withShowLabels()` |
| `Sankey` | Sankey diagram for flow visualization | `new()`, `addNode()`, `addFlow()`, `withHorizontal()`, `withShowLabels()` |
| `SankeyNode` | Node in Sankey diagram: `id`, `label`, `value`, `color` |
| `SankeyFlow` | Flow connection: `source`, `target`, `value`, `color` |
| `Sparkline` | Inline sparkline chart | `new(data)`, `withData()`, `withWidth()`, `withHeight()`, `withDataPoints()`, `withFill()` |
| `SparklineBar` | Bar-style sparkline |
| `SparklineArea` | Area-style sparkline |
| `SparkArea` | Spark area chart |
| `Sunburst` | Sunburst chart visualization |
| `Treemap` | Treemap chart visualization |
| `TreemapLeaf` | Leaf node in treemap |
| `TreeViz` | Tree visualization |
| `Waterfall` | Waterfall chart |
| `WaterfallItem` | Waterfall bar item |
| `FunnelChart` | Funnel chart visualization |
| `Funnel` | Funnel visualization component |
| `Partition` | Partition chart |
| `PartitionSegment` | Partition segment |
| `Bullet` | Bullet chart |
| `NProgress` | Progress bar component |
| `Meter` | Meter/gauge component |
| `Rating` | Star rating display |
| `OHLC` | Open-High-Low-Close data |
| `OHLCPoint` | OHLC data point |
| `TableChart` | Table-based chart |
| `TableBordered` | Bordered table |
| `TableZebra` | Zebra-striped table |
| `MetricsGrid` | Grid of metric displays |
| `ProgressList` | List of progress items |

---

## Form & Input Components

| Type | Description | Key Methods/Factories |
|------|-------------|----------------------|
| `Checkbox` | Checkbox group with single/multi-select | `new(options)`, `withSelectedIndex()`, `withOptionChecked()`, `withMultiSelect()`, `withCheckedColor()` |
| `Input` | Text input field | `new(?string)`, `labeled()`, `password()`, `withValue()`, `withPlaceholder()`, `withLabel()`, `withError()`, `withBorderColor()` |
| `Select` | Dropdown select component | `new(options)`, `withSelectedIndex()`, `withOptions()`, `withSelectedColor()` |
| `Textarea` | Multi-line text area |
| `Slider` | Slider control |
| `Toggle` | Toggle switch |
| `SwitchComponent` | Switch component |
| `Radio` | Radio button |
| `Label` | Form label |
| `Chip` | Chip/tag component |
| `ChipGroup` | Group of chips |
| `ComboBox` | Combo box |
| `DatePicker` | Date picker |
| `ColorPicker` | Color picker |
| `Dropdown` | Dropdown menu |

---

## Feedback & Status Components

| Type | Description | Key Methods/Factories |
|------|-------------|----------------------|
| `Alert` | Alert/message box (info, warning, error, success) | `new(string)`, `info()`, `warning()`, `error()`, `success()`, `withMessage()`, `withTitle()`, `withBorderColor()` |
| `Badge` | Badge/tag component | `new(string)`, `success()`, `warning()`, `error()`, `info()`, `withStyle()`, `withSize()`, `withIcon()` |
| `BadgeGroup` | Group of badges |
| `LoadingText` | Animated loading text |
| `Modal` | Modal dialog |
| `Notification` | Toast notification |
| `Progress` | Progress indicator |
| `ProgressBar` | Progress bar |
| `ProgressRing` | Circular progress indicator |
| `Skeleton` | Loading skeleton |
| `Spinner` | Loading spinner |
| `StatusIndicator` | Status indicator dot |
| `Toast` | Toast message |
| `Tooltip` | Tooltip popup |
| `Popover` | Popover content |
| `NProgress` | npm-style progress bar |
| `Marquee` | Scrolling marquee text |
| `Cursor` | Terminal cursor |
| `Buffer` | Terminal buffer |

---

## Navigation Components

| Type | Description | Key Methods/Factories |
|------|-------------|----------------------|
| `Breadcrumb` | Breadcrumb navigation | `new(items)`, `fromPath()`, `withItems()`, `withSeparator()`, `withActiveIndex()` |
| `Tabs` | Tabbed interface | `new(tabs)`, `withSelectedIndex()`, `withActiveColor()`, `withTabs()` |
| `TabsVertical` | Vertical tab navigation |
| `Pagination` | Pagination controls |
| `PaginationSimple` | Simple pagination |
| `Stepper` | Step progress indicator |
| `Navbar` | Navigation bar |
| `Sidebar` | Sidebar navigation |
| `Menu` | Menu component |

---

## Layout Helper Components

| Type | Description | Key Methods/Factories |
|------|-------------|----------------------|
| `Card` | Card container component |
| `Cover` | Cover layout |
| `Jumbotron` | Jumbotron hero section |
| `CTA` | Call-to-action component |
| `Avatar` | User avatar |
| `AvatarGroup` | Group of avatars |
| `Profile` | User profile card |
| `Pricing` | Pricing table |
| `Testimonial` | Testimonial quote |
| `Features` | Feature grid |
| `EmptyState` | Empty state placeholder |
| `Footer` | Page footer |
| `Header` | Page header |
| `ListComponent` | List renderer |
| `Stat` | Single stat display |
| `Stats` | Statistics display |
| `Metric` | Metric display |
| `ActivityFeed` | Activity feed |
| `Comment` | Comment component |
| `LogViewer` | Log file viewer |
| `Console` | Terminal console |
| `CommandPalette` | Command palette |
| `Editor` | Text editor |
| `Terminal` | Terminal emulator |
| `Log` | Log entry |
| `Screen` | Terminal screen |
| `Viewport` | Viewport component |
| `Window` | Window frame |
| `Wizard` | Multi-step wizard |
| `WizardStep` | Wizard step |
| `Drawer` | Drawer panel |
| `Accordion` | Accordion/collapsible |
| `Calendar` | Calendar view |
| `Clock` | Digital clock |
| `Timer` | Countdown timer |
| `Stopwatch` | Stopwatch |
| `Video` | Video player |
| `Audio` | Audio player |
| `Image` | Image display |
| `Picture` | Picture frame |
| `Icon` | Icon display |
| `QRCode` | QR code |
| `Barcode` | Barcode |

---

## Text Components

| Type | Description | Key Methods/Factories |
|------|-------------|----------------------|
| `Text` | Word-wrapped text content | `new(string)`, `withMaxWidth()`, `withTrim()`, `withHorizontalAlign()` |
| `Code` | Code block with syntax highlighting |
| `Kbd` | Keyboard key display |
| `Paragraph` | Paragraph text |
| `Markdown` | Markdown rendering |
| `FigletText` | ASCII art text (FIGlet style) |
| `ASCIIBanner` | ASCII banner text |
| `Emoji` | Emoji display |
| `HexDump` | Hex dump viewer |
| `Highlight` | Syntax highlighted code |
| `DotMatrix` | Dot matrix display |
| `Pictogram` | Pictogram display |
| `Boxer` | Boxing text effect |

---

## Diagram & Visualization Components

| Type | Description | Key Methods/Factories |
|------|-------------|----------------------|
| `ClassDiagram` | UML class diagram |
| `Dendrogram` | Dendrogram/tree diagram |
| `DendrogramNode` | Node in dendrogram |
| `Flowchart` | Flowchart diagram |
| `FlowchartNode` | Node in flowchart |
| `Gantt` | Gantt chart |
| `Graph` | Graph visualization |
| `HexDump` | Hex dump view |
| `Ladder` | Ladder diagram |
| `Leaderboard` | Leaderboard display |
| `MindMap` | Mind map visualization |
| `Network` | Network diagram |
| `NetworkNode` | Node in network |
| `OrgChart` | Organization chart |
| `PERT` | PERT chart |
| `Sequence` | Sequence diagram |
| `Timeline` | Timeline display |
| `TimelineViz` | Timeline visualization |
| `TimelineNode` | Node in timeline |
| `Tree` | Tree structure |
| `TreeNode` | Tree node |
| `WordCloud` | Word cloud visualization |
| `Diff` | Diff view |
| `Bubble` | Bubble chart |
| `BubblePoint` | Bubble chart point |
| `Canvas` | Drawing canvas |
| `Scrollbar` | Custom scrollbar |

---

## Event Types

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
| `Key` | Key representation |
| `KeyAction` | Key action |
| `KeyMap` | Key mapping |
| `State` | Application state |

---

## Theme & Styling

| Type | Description | Key Methods |
|------|-------------|-------------|
| `Theme` | Theme management with pre-defined themes | `dark()`, `dracula()`, `oneDark()`, `githubDark()`, `light()`, `foreground()`, `background()`, `primary()`, `bar()`, `text()` |

---

## Usage Example

```php
use SugarCraft\Dash\Grid\{StackedGrid, Frame, VStack, Text, Panel, Options, ItemOptions};

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

## License

MIT License - See LICENSE file for details.
