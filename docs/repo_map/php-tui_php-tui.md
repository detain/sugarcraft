# php-tui/php-tui

## Metadata
- URL: https://github.com/php-tui/php-tui
- Language: PHP (port of Rust's Ratatui)
- Stars: Unknown (gh API unavailable)
- License: MIT
- Description: Comprehensive TUI library heavily influenced by Ratatui. A PHP port of the Rust TUI crate that provides widgets, terminal control, and layout algorithms for building rich command-line interfaces.

## Feature List
- **Widget System**: Full port of Ratatui widgets including Paragraph, Block, List, Table, Chart, BarChart, Sparkline, Gauge, Scrollbar, Tabs, Canvas, Grid, Buffer
- **Terminal Backend**: Pluggable backend architecture with PhpTermBackend for ANSI terminal control
- **Layout Engine**: Cassowary constraint solver algorithm for flex-style layouts via `php-tui/cassowary`
- **Style System**: Foreground/background colors, text modifiers (bold, italic, underline, reverse, etc.), RGB and ANSI color support
- **Text Rendering**: Multi-styled text with spans, lines, Unicode wide-char handling, and text wrapping
- **Canvas/Drawing**: Shape painters for primitives (circle, rectangle, line, points, map) and sprite rendering
- **Image Support**: ImageMagick extension for rendering images in the terminal
- **BDF Font Rendering**: Bitmap font support via bundled BDF extension
- **Mouse Support**: Mouse capture and event handling via crossterm-style events
- **Multiple Viewports**: Fullscreen, inline, and fixed-position viewport modes
- **Buffer Diffing**: Efficient double-buffering with cell-based diffing to minimize terminal redraws
- **Event System**: Keyboard (CharKeyEvent, CodedKeyEvent) and mouse event handling

## Key Classes and Methods

### Core Entry Point
- `DisplayBuilder`: Fluent builder for creating Display instances with extensions, backends, and viewports
  - `DisplayBuilder::default(?Backend)` — Creates a display with CoreExtension pre-loaded
  - `DisplayBuilder::fullscreen()` / `inline(int)` / `fixed(int,int,int,int)` — Viewport modes
  - `DisplayBuilder::addExtension()` / `addShapePainter()` / `addWidgetRenderer()` — Extend the display

### Display & Rendering
- `Display`: Main render loop coordinator
  - `Display::draw(Widget)` — Render a widget and flush to terminal
  - `Display::flush()` — Commit buffer changes to backend
  - `Display::clear()` — Clear the viewport
  - `Display::insertBefore(int, Widget)` — Prepend content in inline mode

- `Backend` (interface): Terminal abstraction
  - `Backend::size()` — Get terminal dimensions as `Area`
  - `Backend::draw(BufferUpdates)` — Render buffer updates
  - `Backend::cursorPosition()` — Query cursor position (blocking)
  - `Backend::moveCursor()` / `appendLines()` / `clearRegion()`

- `Buffer`: In-memory grid of cells representing the terminal content
  - `Buffer::diff(Buffer)` — Compute minimal updates between two buffers
  - `Buffer::setStyle()` — Apply style to area
  - `Buffer::putString()` / `putLine()` / `putSpan()` — Write text with styling
  - `Buffer::empty(Area)` / `filled(Area, Cell)` — Create buffers

- `BufferUpdates`: Collection of `BufferUpdate` (position + cell) for efficient redraw

- `Cell`: Single terminal cell with char, fg color, bg color, and modifiers mask

- `Area`: Rectangular region defined by position + width + height

### Layout System
- `Layout`: Flex-like layout using Cassowary constraint solver
  - `Layout::default()` — Creates layout with CassowaryConstraintSolver
  - `Layout::direction(Direction)` — Vertical or horizontal
  - `Layout::constraints(Constraint[])` — Length, percentage, min, max constraints
  - `Layout::split(Area)` — Solve constraints and return `Areas`

- `Constraint` (abstract): Constraint types for layout
  - `Constraint::length(int)` — Fixed pixel length
  - `Constraint::percentage(int)` — Percentage of available space
  - `Constraint::min(int)` / `Constraint::max(int)` — Bounded

### Widgets (all implement `Widget` interface)
- `ParagraphWidget`: Rich text with alignment and wrapping
- `BlockWidget`: Container with borders, titles, and padding
- `ListWidget`: Scrollable list with highlight spacing
- `TableWidget`: Tabular data with rows/cells
- `ChartWidget`: Line/scatter charts with axes and datasets
- `BarChartWidget`: Bar chart with groups and labels
- `SparklineWidget`: Compact inline chart
- `GaugeWidget`: Progress indicator
- `ScrollbarWidget`: Scrollbar with orientation control
- `TabsWidget`: Tab navigation bar
- `CanvasWidget`: Low-level pixel/vector drawing surface
- `GridWidget`: Grid-based layout container for multiple widgets
- `BufferWidget`: Raw buffer display widget

### Widget Rendering
- `WidgetRenderer` (interface): `render(WidgetRenderer, Widget, Buffer, Area)`
- `AggregateWidgetRenderer`: Combines multiple renderers
- `NullWidgetRenderer`: No-op renderer for outermost context
- Per-widget renderers: `ParagraphRenderer`, `BlockRenderer`, `ListRenderer`, `TableRenderer`, etc.

### Style System
- `Style`: Foreground/background colors, underline, additive/subtractive modifiers
  - `Style::default()`, `Style::fg(Color)`, `Style::bg(Color)`, `Style::addModifier()`
  - `Style::patchStyle(Style)` — Merge styles
  - `Style::atPosition(FractionalPosition)` — Apply gradient at position

- `Color`: Interface with implementations
  - `AnsiColor`: 256-color ANSI palette (Black, Red, Green, Yellow, Blue, Magenta, Cyan, White + light/dark variants + Reset)
  - `RgbColor`: 24-bit RGB with r, g, b components
  - `LinearGradient`: Multi-stop color gradients

- `Modifier`: Bitmask constants (BOLD, ITALIC, UNDERLINED, REVERSED, DIM, HIDDEN, SLOWBLINK, RAPIDBLINK, CROSSEDOUT)

### Text System
- `Text`: Collection of lines with utility constructors
- `Line`: Single row with spans
- `Span`: Styled substring
- `SpanParser`: Parse `<fg=red>`-style markup into spans
- `Title`: Block title with position

### Shapes & Canvas
- `Shape` (interface): `paint(CanvasContext, Area)`
- `CircleShape`, `RectangleShape`, `LineShape`, `PointsShape`, `MapShape`, `SpriteShape`, `ClosureShape`
- `ShapePainter` (interface): `paint(Shape, CanvasContext, Area)`
- `AggregateShapePainter`: Combines multiple painters
- `CanvasContext`: Drawing context for shapes
- `CanvasGrid`: Pixel grid for canvas rendering

### Extensions
- `CoreExtension`: Built-in widgets and shapes (CirclePainter, LinePainter, RectanglePainter, etc.)
- `BdfExtension`: Bitmap font text rendering
- `ImageMagickExtension`: Image rendering via PHP's imagick
- `DisplayExtension` (interface): `widgetRenderers()` / `shapePainters()`

### Terminal Integration (php-tui/term)
- `Terminal`: Wraps php://stdin/stdout with action queueing
- `Actions`: Static factory for terminal actions (cursorHide, alternateScreenEnable, moveCursor, etc.)
- `Events`: Async event stream from terminal (key, mouse, resize events)
- `KeyCode`: Enumeration of special keys (Tab, BackTab, Enter, etc.)

## Notable Algorithms / Named Patterns

### Cassowary Constraint Solver
The layout system uses the Cassowary algorithm (via `php-tui/cassowary`) to solve flex-style constraints. The `CassowaryConstraintSolver` bridges to the external Cassowary library. This is the same algorithm used by Apple Auto Layout and Rust's Ratatui.

**Source:** `src/Bridge/Cassowary/CassowaryConstraintSolver.php:L13-L60`

### Double Buffering with Diff
The `Display` maintains two buffers and computes a diff on `flush()` to determine the minimal set of cells that changed. Only changed cells are sent to the terminal backend, minimizing redraw work.

**Source:** `src/Display/Buffer.php:L96-L116` (the `diff()` method)

### Visitor Pattern for Widget Rendering
Widget renderers use a visitor-like pattern where `WidgetRenderer::render()` receives itself as the "outer" renderer, allowing nested widget rendering (e.g., a List containing Paragraphs).

**Source:** `src/Widget/WidgetRenderer.php:L10-L13`

### Extension/Plugin Architecture
The `DisplayExtension` interface allows adding widget renderers and shape painters without modifying core code. The `CoreExtension` is the default, but `BdfExtension` and `ImageMagickExtension` add optional functionality.

**Source:** `src/Display/DisplayExtension.php`

### Bitmask Modifiers
Text modifiers use PHP integer bitmask operations for efficient storage and application of multiple modifiers (bold+italic+underline simultaneously).

**Source:** `src/Style/Style.php:L19-L21` and `src/Bridge/PhpTerm/PhpTermBackend.php:L209-L232`

### Unicode Width-Aware Text
Text rendering accounts for Unicode character width (East Asian wide characters take 2 cells) using `mb_strwidth()`.

**Source:** `src/Display/Buffer.php:L112` and `src/Display/Buffer.php:L166`

## Strengths
- **Faithful Ratatui Port**: Nearly complete port of the Rust Ratatui API, giving PHP developers access to a mature, well-designed TUI architecture
- **Comprehensive Widget Suite**: 15+ widgets covering most CLI app needs (lists, tables, charts, gauges, etc.)
- **Extensible Architecture**: Extension system allows adding custom widgets, renderers, and shape painters
- **Efficient Rendering**: Double-buffering with cell-level diffing minimizes terminal I/O
- **Constraint-Based Layout**: Cassowary solver enables responsive layouts that adapt to terminal resize
- **Rich Styling**: Full ANSI color support (256-color palette + RGB) with modifier combinations
- **Good PHP Practices**: Strict typing, PHP 8.1+ features, comprehensive interfaces, clean separation of concerns
- **Active Maintenance**: Regular updates, changelog, CI/CD, and documentation

## Weaknesses
- **External Dependency on Cassowary**: Layout requires `php-tui/cassowary` as a separate package
- **External Dependency on php-tui/term**: Terminal control abstracted to a separate `php-tui/term` package
- **No Windows Support**: Explicitly stated limitation — developer doesn't have Windows access
- **Synchronous Event Loop**: While the demo uses blocking `usleep()`, documentation notes using async libraries (Amp/ReactPHP) — but async integration is not built-in
- **Large API Surface**: 40+ classes/interfaces across src/ and lib/ can be overwhelming for new users
- **BDF Font Handling**: Bundled `lib/bdf/` with embedded bitmap data (MapData.php is 152KB) adds weight
- **ImageMagick Extension Required**: Image support requires PHP's imagick extension, not always available

## SugarCraft Mapping

### candy-core (Terminal/Display Foundation)
php-tui maps directly to the core TUI foundation. The `DisplayBuilder` → `Display` → `Backend` architecture, the `Buffer`/`Cell`/`Area` model, and the `Widget`/`WidgetRenderer` interface pattern are all foundational elements that `candy-core` would provide.

Key classes to port:
- `DisplayBuilder`, `Display`, `Backend` interface
- `Buffer`, `Cell`, `Area`, `BufferUpdates`, `BufferUpdate`
- `Widget` interface, `WidgetRenderer` interface
- `Viewport`, `Viewport\Fullscreen`, `Viewport\Inline`, `Viewport\Fixed`
- `ClearType`, `ViewportType`

### candy-sprinkles (Styling & Decoration)
The style system maps to `candy-sprinkles`:
- `Style`, `Styleable` trait, `Color` hierarchy (`AnsiColor`, `RgbColor`, `LinearGradient`)
- `Modifier` bitmask constants
- Text styling (`Text`, `Line`, `Span`, `SpanParser`, `Title`)
- Borders and alignment (`Borders`, `BorderType`, `Borders`, `HorizontalAlignment`, `VerticalAlignment`)

### honey-bounce (Layout Algorithm)
The Cassowary constraint solver integration maps to `honey-bounce`:
- `Layout`, `Constraint` hierarchy, `ConstraintSolver` interface
- `Direction`, `Margin`

### Leaf Widget Libraries
Individual widget extensions map to leaf libraries:
- `sugar-bits`: `ParagraphWidget`, `ParagraphRenderer` (text rendering)
- `candy-sprinkles`: `BlockWidget` (borders, titles, padding)
- `sugar-charts`: `ChartWidget`, `BarChartWidget`, `SparklineWidget`, `TableWidget`
- `sugar-prompt`: `ListWidget`, `TabsWidget`, `ScrollbarWidget`
- `candy-shine`: `GaugeWidget`, `CanvasWidget`, image rendering

## Analysis

php-tui is a comprehensive, production-quality port of Rust's Ratatui library for PHP. It provides a complete TUI framework including terminal I/O abstraction, a widget system with 15+ built-in widgets, a flexible layout engine based on the Cassowary constraint solver, and rich styling with ANSI colors and text modifiers.

The architecture follows a clean separation of concerns: the `Backend` interface abstracts terminal control (currently implemented via `php-tui/term`), the `Display` class manages the render loop and double-buffering, `WidgetRenderer` implementations handle drawing each widget type to a `Buffer`, and the `Buffer` class provides cell-level manipulation with Unicode-aware text operations. The extension system allows the core to remain lean while adding optional functionality like BDF fonts and image support.

The library is well-suited for building rich interactive CLI applications in PHP, competing with solutions like `psy/psysh` (which also builds TUIs) but offering a more general-purpose, widget-based approach. For SugarCraft, the port would involve mapping each component to the appropriate sub-package: core display/buffer infrastructure to `candy-core`, styling to `candy-sprinkles`, layout to `honey-bounce`, and individual widget groups to leaf libraries. The Cassowary algorithm is particularly notable as it's the same algorithm used by Apple's Auto Layout, enabling truly responsive terminal layouts that adapt gracefully to terminal resizing.
