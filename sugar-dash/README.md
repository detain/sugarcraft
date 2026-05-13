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

| Type | Description | Key Methods/Factories | GIF |
|------|-------------|----------------------|-----|
| `StackedGrid` | Multi-column stacked grid layout with items in columns | `addItem(Item, ItemOptions)`, `new(Options)` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/stacked-grid.gif) |
| `GridLayout` | CSS Grid-style layout with rows/columns/gaps | `columns(int, items)`, `rows(int, items)`, `withGap()`, `withItem()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/grid-layout.gif) |
| `FlexLayout` | Flexbox-style layout with direction/wrap/justify | `row()`, `column()`, `withJustify()`, `withAlignItems()`, `withGap()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/flex-layout.gif) |
| `VStack` | Vertical stack with alignment and spacing | `new(...items)`, `spaced(int, ...items)`, `centered(...items)`, `right(...items)` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/vstack.gif) |
| `HStack` | Horizontal stack with spacing and alignment | `new(...items)`, `spaced(int, ...items)`, `centered(...items)` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/hstack.gif) |
| `ZStack` | Layered stack (items on top of each other) | `new(...items)`, `left(...items)`, `right(...items)`, `top(...items)`, `bottom(...items)` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/zstack.gif) |
| `Stack` | Basic vertical stack | `new(...items)`, `spaced(int, ...items)` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/stack.gif) |

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

---

## Data Visualization (Charts)

### Chart Components

| Type | Description | Key Methods/Factories | GIF |
|------|-------------|----------------------|-----|
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
| `Sankey` | Sankey diagram for flow visualization | `new()`, `addNode()`, `addFlow()`, `withHorizontal()`, `withShowLabels()` | |
| `SankeyNode` | Node in Sankey diagram: `id`, `label`, `value`, `color` | | |
| `SankeyFlow` | Flow connection: `source`, `target`, `value`, `color` | | |
| `Sparkline` | Inline sparkline chart | `new(data)`, `withData()`, `withWidth()`, `withHeight()`, `withDataPoints()`, `withFill()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/sparkline.gif) |
| `SparklineBar` | Bar-style sparkline | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/sparkline-bar.gif) |
| `SparklineArea` | Area-style sparkline | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/sparkline-area.gif) |
| `SparkArea` | Spark area chart | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/spark-area.gif) |
| `Sunburst` | Sunburst chart visualization | | |
| `Treemap` | Treemap chart visualization | | |
| `TreemapLeaf` | Leaf node in treemap | | |
| `TreeViz` | Tree visualization | | |
| `Waterfall` | Waterfall chart | | |
| `WaterfallItem` | Waterfall bar item | | |
| `FunnelChart` | Funnel chart visualization | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/funnel-chart.gif) |
| `Funnel` | Funnel visualization component | | |
| `Partition` | Partition chart | | |
| `PartitionSegment` | Partition segment | | |
| `Bullet` | Bullet chart | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/bullet.gif) |
| `NProgress` | Progress bar component | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/nprogress.gif) |
| `Meter` | Meter/gauge component | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/meter.gif) |
| `Rating` | Star rating display | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/rating.gif) |
| `OHLC` | Open-High-Low-Close data | | |
| `OHLCPoint` | OHLC data point | | |
| `TableChart` | Table-based chart | | |
| `TableBordered` | Bordered table | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/table-bordered.gif) |
| `TableZebra` | Zebra-striped table | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/table-zebra.gif) |
| `MetricsGrid` | Grid of metric displays | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/metrics-grid.gif) |
| `ProgressList` | List of progress items | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/progress-list.gif) |

---

## Form & Input Components

| Type | Description | Key Methods/Factories | GIF |
|------|-------------|----------------------|-----|
| `Checkbox` | Checkbox group with single/multi-select | `new(options)`, `withSelectedIndex()`, `withOptionChecked()`, `withMultiSelect()`, `withCheckedColor()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/checkbox.gif) |
| `Input` | Text input field | `new(?string)`, `labeled()`, `password()`, `withValue()`, `withPlaceholder()`, `withLabel()`, `withError()`, `withBorderColor()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/input.gif) |
| `Select` | Dropdown select component | `new(options)`, `withSelectedIndex()`, `withOptions()`, `withSelectedColor()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/select.gif) |
| `Textarea` | Multi-line text area | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/textarea.gif) |
| `Slider` | Slider control | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/slider.gif) |
| `Toggle` | Toggle switch | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/toggle.gif) |
| `SwitchComponent` | Switch component | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/switch-component.gif) |
| `Radio` | Radio button | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/radio.gif) |
| `Label` | Form label | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/label.gif) |
| `Chip` | Chip/tag component | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/chip.gif) |
| `ChipGroup` | Group of chips | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/chip-group.gif) |
| `ComboBox` | Combo box | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/combo-box.gif) |
| `DatePicker` | Date picker | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/date-picker.gif) |
| `ColorPicker` | Color picker | | |
| `Dropdown` | Dropdown menu | | |

---

## Feedback & Status Components

| Type | Description | Key Methods/Factories | GIF |
|------|-------------|----------------------|-----|
| `Alert` | Alert/message box (info, warning, error, success) | `new(string)`, `info()`, `warning()`, `error()`, `success()`, `withMessage()`, `withTitle()`, `withBorderColor()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/alert.gif) |
| `Badge` | Badge/tag component | `new(string)`, `success()`, `warning()`, `error()`, `info()`, `withStyle()`, `withSize()`, `withIcon()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/badge.gif) |
| `BadgeGroup` | Group of badges | | |
| `LoadingText` | Animated loading text | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/loading-text.gif) |
| `Modal` | Modal dialog | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/modal.gif) |
| `Notification` | Toast notification | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/notification.gif) |
| `Progress` | Progress indicator | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/progress.gif) |
| `ProgressBar` | Progress bar | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/progress-bar.gif) |
| `ProgressRing` | Circular progress indicator | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/progress-ring.gif) |
| `Skeleton` | Loading skeleton | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/skeleton.gif) |
| `Spinner` | Loading spinner | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/spinner.gif) |
| `StatusIndicator` | Status indicator dot | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/status-indicator.gif) |
| `Toast` | Toast message | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/toast.gif) |
| `Tooltip` | Tooltip popup | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/tooltip.gif) |
| `Popover` | Popover content | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/popover.gif) |
| `NProgress` | npm-style progress bar | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/nprogress.gif) |
| `Marquee` | Scrolling marquee text | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/marquee.gif) |
| `Cursor` | Terminal cursor | | |
| `Buffer` | Terminal buffer | | |

---

## Navigation Components

| Type | Description | Key Methods/Factories | GIF |
|------|-------------|----------------------|-----|
| `Breadcrumb` | Breadcrumb navigation | `new(items)`, `fromPath()`, `withItems()`, `withSeparator()`, `withActiveIndex()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/breadcrumb.gif) |
| `Tabs` | Tabbed interface | `new(tabs)`, `withSelectedIndex()`, `withActiveColor()`, `withTabs()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/tabs.gif) |
| `TabsVertical` | Vertical tab navigation | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/tabs-vertical.gif) |
| `Pagination` | Pagination controls | | |
| `PaginationSimple` | Simple pagination | | |
| `Stepper` | Step progress indicator | | |
| `Navbar` | Navigation bar | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/navbar.gif) |
| `Sidebar` | Sidebar navigation | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/sidebar.gif) |
| `Menu` | Menu component | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/menu.gif) |

---

## Layout Helper Components

| Type | Description | Key Methods/Factories | GIF |
|------|-------------|----------------------|-----|
| `Card` | Card container component | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/card.gif) |
| `Cover` | Cover layout | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/cover.gif) |
| `Jumbotron` | Jumbotron hero section | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/jumbotron.gif) |
| `CTA` | Call-to-action component | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/cta.gif) |
| `Avatar` | User avatar | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/avatar.gif) |
| `AvatarGroup` | Group of avatars | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/avatar-group.gif) |
| `Profile` | User profile card | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/profile.gif) |
| `Pricing` | Pricing table | | |
| `Testimonial` | Testimonial quote | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/testimonial.gif) |
| `Features` | Feature grid | | |
| `EmptyState` | Empty state placeholder | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/empty-state.gif) |
| `Footer` | Page footer | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/footer.gif) |
| `Header` | Page header | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/header.gif) |
| `ListComponent` | List renderer | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/list-component.gif) |
| `Stat` | Single stat display | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/stat.gif) |
| `Stats` | Statistics display | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/stats.gif) |
| `Metric` | Metric display | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/metric.gif) |
| `ActivityFeed` | Activity feed | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/activity-feed.gif) |
| `Comment` | Comment component | | |
| `LogViewer` | Log file viewer | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/log-viewer.gif) |
| `Console` | Terminal console | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/console.gif) |
| `CommandPalette` | Command palette | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/command-palette.gif) |
| `Editor` | Text editor | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/editor.gif) |
| `Terminal` | Terminal emulator | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/terminal.gif) |
| `Log` | Log entry | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/log.gif) |
| `Screen` | Terminal screen | | |
| `Viewport` | Viewport component | | |
| `Window` | Window frame | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/window.gif) |
| `Wizard` | Multi-step wizard | | |
| `WizardStep` | Wizard step | | |
| `Drawer` | Drawer panel | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/drawer.gif) |
| `Accordion` | Accordion/collapsible | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/accordion.gif) |
| `Calendar` | Calendar view | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/calendar.gif) |
| `Clock` | Digital clock | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/clock.gif) |
| `Timer` | Countdown timer | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/timer.gif) |
| `Stopwatch` | Stopwatch | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/stopwatch.gif) |
| `Video` | Video player | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/video.gif) |
| `Audio` | Audio player | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/audio.gif) |
| `Image` | Image display | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/image.gif) |
| `Picture` | Picture frame | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/picture.gif) |
| `Icon` | Icon display | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/icon.gif) |
| `QRCode` | QR code | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/qr-code.gif) |
| `Barcode` | Barcode | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/barcode.gif) |

---

## Text Components

| Type | Description | Key Methods/Factories | GIF |
|------|-------------|----------------------|-----|
| `Text` | Word-wrapped text content | `new(string)`, `withMaxWidth()`, `withTrim()`, `withHorizontalAlign()` | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/text.gif) |
| `Code` | Code block with syntax highlighting | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/code.gif) |
| `Kbd` | Keyboard key display | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/kbd.gif) |
| `Paragraph` | Paragraph text | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/paragraph.gif) |
| `Markdown` | Markdown rendering | | |
| `FigletText` | ASCII art text (FIGlet style) | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/figlet-text.gif) |
| `ASCIIBanner` | ASCII banner text | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/ascii-banner.gif) |
| `Emoji` | Emoji display | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/emoji.gif) |
| `HexDump` | Hex dump viewer | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/hex-dump.gif) |
| `Highlight` | Syntax highlighted code | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/highlight.gif) |
| `DotMatrix` | Dot matrix display | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/dot-matrix.gif) |
| `Pictogram` | Pictogram display | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/pictogram.gif) |
| `Boxer` | Boxing text effect | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/boxer.gif) |

---

## Diagram & Visualization Components

| Type | Description | Key Methods/Factories | GIF |
|------|-------------|----------------------|-----|
| `ClassDiagram` | UML class diagram | | |
| `Dendrogram` | Dendrogram/tree diagram | | |
| `DendrogramNode` | Node in dendrogram | | |
| `Flowchart` | Flowchart diagram | | |
| `FlowchartNode` | Node in flowchart | | |
| `Gantt` | Gantt chart | | |
| `Graph` | Graph visualization | | |
| `HexDump` | Hex dump view | | |
| `Ladder` | Ladder diagram | | |
| `Leaderboard` | Leaderboard display | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/leaderboard.gif) |
| `MindMap` | Mind map visualization | | |
| `Network` | Network diagram | | |
| `NetworkNode` | Node in network | | |
| `OrgChart` | Organization chart | | |
| `PERT` | PERT chart | | |
| `Sequence` | Sequence diagram | | |
| `Timeline` | Timeline display | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/timeline.gif) |
| `TimelineViz` | Timeline visualization | | |
| `TimelineNode` | Node in timeline | | |
| `Tree` | Tree structure | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/tree.gif) |
| `TreeNode` | Tree node | | |
| `WordCloud` | Word cloud visualization | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/word-cloud.gif) |
| `Diff` | Diff view | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/diff.gif) |
| `Bubble` | Bubble chart | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/bubble.gif) |
| `BubblePoint` | Bubble chart point | | |
| `Canvas` | Drawing canvas | | |
| `Scrollbar` | Custom scrollbar | | ![](https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/scrollbar.gif) |

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

## GIF Demos

| Demo | Description |
|------|-------------|
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

## License

MIT License - See LICENSE file for details.
