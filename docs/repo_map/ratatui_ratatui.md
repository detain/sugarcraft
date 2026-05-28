# ratatui/ratatui

## Metadata
- URL: https://github.com/ratatui/ratatui
- Language: Rust
- Stars: ~19,600 (20.2k as of recent counts)
- License: MIT
- Description: A Rust crate for cooking up terminal user interfaces (TUIs). It provides a simple and flexible way to create text-based user interfaces in the terminal, which can be used for command-line applications, dashboards, and other interactive console programs.
- Downloads: 26.3M+ on crates.io
- Forks: 624+
- Contributors: 260+

## Feature List

- **Terminal Management**: Full terminal initialization, raw mode, alternate screen, panic restoration via `ratatui::run()` / `ratatui::init()` / `ratatui::restore()`
- **Double-Buffered Rendering**: Internal frame diffing - only changed cells are written to terminal
- **Multiple Backend Support**: Crossterm (cross-platform), Termion (Unix-specific), Termwiz (advanced features)
- **Widget System**: `Widget` and `StatefulWidget` traits for custom widget development
- **Built-in Widgets**: Block, Paragraph, List, Table, Chart, BarChart, Gauge, Sparkline, Canvas, Calendar, Scrollbar, Tabs, and more
- **Layout System**: Cassowary constraint solver-based layout (`Layout`, `Constraint`, `Direction`, `Flex`)
- **Text Rendering**: `Text`, `Line`, `Span` hierarchy with styled text support
- **Style System**: Colors (ANSI 256, RGB, Hex), modifiers (bold, italic, underline, etc.), palette support, `Stylize` trait for fluent styling
- **Stateful Widgets**: `StatefulWidget` trait with associated state types for persistent UI state (e.g., `ListState`, `TableState`)
- **Viewport Management**: Fullscreen, inline, and fixed viewports
- **`no_std` Support**: Embedded target compatibility
- **Macro Utilities**: `ratatui-macros` crate for boilerplate reduction

## Key Classes and Methods

### Core Traits

- **`Widget`**: `fn render(self, area: Rect, buf: &mut Buffer)` — Core trait for renderable elements. Implement this to create custom widgets.
- **`StatefulWidget`**: `fn render(self, area: Rect, buf: &mut Buffer, state: &mut Self::State)` — For widgets that maintain state between frames.

### Terminal

- **`Terminal<B>`**: Main rendering surface. `new()`, `draw()`, `try_draw()`, `flush()`, `swap_buffers()`, `resize()`, `autoresize()`
- **`Frame`**: Mutable view during render pass. `render_widget()`, `render_stateful_widget()`, `set_cursor_position()`, `area()`
- **`DefaultTerminal`**: Type alias for `Terminal<CrosstermBackend<io::Stdout>>`

### Layout

- **`Layout`**: `vertical()`, `horizontal()`, `areas()`, `split()` — Constraint-based layout division
- **`Constraint`**: `Length()`, `Percentage()`, `Ratio()`, `Fill()`, `Min()`, `Max()` — How space is allocated
- **`Rect`**: `{ x, y, width, height }` — Rectangular area representation
- **`Direction`**: `Horizontal`, `Vertical`
- **`Flex`**: `Legacy`, `Auto`, `Centered`, `SpaceBetween`, `SpaceAround` — Space distribution

### Style

- **`Style`**: `new()`, `fg()`, `bg()`, `add_modifier()`, `reset()`
- **`Color`**: `Black`, `Red`, `Green`, `Yellow`, `Blue`, `Magenta`, `Cyan`, `White`, `AnsiValue()`, `Rgb()`, `Indexed()`
- **`Modifier`**: `BOLD`, `DIM`, `ITALIC`, `UNDERLINED`, `REVERSED`, `CROSSED_OUT`, etc. (bitflags)
- **`Stylize`**: Trait providing `.red()`, `.on_blue()`, `.bold()` etc. shorthands

### Text

- **`Text`**: `from()`, `raw()` — Multi-line styled text
- **`Line`**: `from()`, `centered()`, `left_aligned()`, `right_aligned()` — Single line with multiple spans
- **`Span`**: `raw()`, `styled()`, `content()`, `style()` — Styled text fragment

### Widgets (ratatui-widgets)

- **`Block`**: `new()`, `bordered()`, `title()`, `title_top()`, `title_bottom()`, `padding()`, `border_type()`, `border_style()`, `inner()`, `style()`, `shadow()`
- **`Paragraph`**: `new()`, `alignment()`, `wrap()`, `scroll()`, `style()`
- **`List`**: `new()`, `block()`, `highlight_symbol()`, `highlight_style()`, `direction()`, `repeat_highlight_symbol()`
- **`Table`**: `new()`, `rows()`, `header()`, `footer()`, `widths()`, `column_spacing()`, `block()`, `highlight_symbol()`, `row_highlight_style()`, `column_highlight_style()`, `cell_highlight_style()`
- **`Chart`**: `new()`, `block()`, `data()`, `x_axis()`, `y_axis()`, `hidden_x_axis_labels()`, `label_symbol()`, `merge_data()`
- **`BarChart`**: `new()`, `block()`, `data()`, `bar_width()`, `bar_gap()`, `bar_set()`, `value_labels()`,
- **`Gauge`**: `new()`, `block()`, `gauge_style()`, `ratio()`, `label()`, `line_gauge()`
- **`Sparkline`**: `new()`, `block()`, `data()`, `max()`, `style()`
- **`Canvas`**: `new()`, `block()`, `paint()`, `x_axis_labels()`, `y_axis_labels()`
- **`Scrollbar`**: `new()`, `symbols()`, `track()`, `thumb()`, `orientation()`, `begin_style()`, `end_style()`
- **`Tabs`**: `new()`, `block()`, `tabs()`, `select()`, `style()`, `highlight_style()`
- **`Calendar`**: `Monthly` — `new()`, `show_month()`, `show_weekday()`
- **`Clear`**: Clears area — `render()`
- **`Fill`**: Fills area with symbol/style — `new()`, `symbol()`, `style()`
- **`RatatuiLogo`**, **`RatatuiMascot`**: Branding widgets

### State Types

- **`ListState`**: `select()`, `selected()`, `offset()`
- **`TableState`**: `select()`, `selected()`, `offset()`, `scroll_to()`
- **`ScrollbarState`**: `position()`, `scroll_amount()`

### Initialization (ratatui crate)

- **`run()`**: `fn run(F) -> Result<()>` — Initialize, run app, restore on exit
- **`init()`**: `fn init() -> DefaultTerminal` — Manual terminal init
- **`restore()`**: `fn restore()` — Restore terminal state
- **`init_with_options()`**: For custom `Viewport` configuration

## Notable Algorithms / Named Patterns

### Immediate Mode Rendering with Buffer Diffing

Ratatui uses immediate-mode rendering where apps redraw the entire UI each frame. A diff is computed between the current and previous buffer, and only changed cells are written to the terminal. This is highly efficient and ensures the display is always consistent with app state.

```rust
// From ratatui-core/src/terminal.rs
// Rendering pipeline per Terminal::draw():
// 1. Check terminal size changed (autoresize)
// 2. Create Frame backed by current buffer
// 3. Run user's render callback to populate buffer
// 4. Diff current vs previous buffer, write changes
// 5. Apply cursor state
// 6. Swap buffers for next frame
// 7. Flush backend
```

### Cassowary Constraint Solver (Layout)

The layout system uses the Cassowary constraint solver algorithm (via the `kasuari` crate) to resolve conflicting layout constraints. This enables sophisticated responsive layouts where multiple constraints can be satisfied with priorities.

```rust
// From ratatui-core/src/layout.rs
// Constraint types resolve via Cassowary:
// - Length(n) - Fixed pixel value
// - Percentage(p) - Relative to container
// - Ratio(n, d) - Aspect ratio
// - Fill(n) - Remaining space
// - Min(n) / Max(n) - Bounds
```

### Widget/StatefulWidget Trait Pattern

Custom widgets implement `Widget` (stateless) or `StatefulWidget` (with state). Widget crates are encouraged to implement `Widget` for `&W` (references) to allow storing widget instances.

```rust
// From ratatui-core/src/widgets/widget.rs
impl<W: Widget> Widget for &W { ... }
impl Widget for &str { ... }
impl Widget for String { ... }
```

### Stylize Trait Pattern

The `Stylize` trait provides fluent style shorthands that return styled versions of types:

```rust
// From ratatui-core/src/style.rs
"hello".red().on_blue().bold()  // -> Span
Text::from("hello").red().blue()  // -> Text
Block::bordered().red()  // -> Block
```

### Buffer Cell Representation

```rust
// From ratatui-core/src/buffer/buffer.rs
pub struct Cell {
    symbol: &'static str,
    style: Style,
}
pub struct Buffer {
    area: Rect,
    content: Vec<Cell>,
}
```

## Strengths

1. **Modular Architecture**: Separated into `ratatui-core`, `ratatui-widgets`, and backend crates for better compilation times and API stability
2. **Pure Rust**: No C dependencies, memory-safe, `no_std` support for embedded targets
3. **Excellent Performance**: Sub-millisecond rendering, immediate-mode with efficient buffer diffing
4. **Rich Widget Set**: 18+ built-in widgets covering most TUI needs
5. **Multiple Backend Support**: Crossterm (default, cross-platform), Termion (Unix), Termwiz (advanced)
6. **Active Maintenance**: Forked from tui-rs in 2023, regular releases (124 releases), 260 contributors
7. **Excellent Documentation**: Website with tutorials, recipes, API docs, and 34+ example apps
8. **Strong Community**: Discord, Matrix, Forum, 3700+ crates built with Ratatui
9. **Flexible Styling**: Multiple styling approaches (Style struct, Stylize trait, palette crate integration)
10. **Responsive Layouts**: Constraint-based layout system adapts to any terminal size
11. **Ergonomic API**: Builder pattern, fluent setters, `Widget` implementations for common types

## Weaknesses

1. **No Built-in Event Handling**: Event handling is delegated to backend libraries (crossterm event module) - requires users to piece together input handling
2. **No Built-in Animation Framework**: Animations must be manually implemented via state and timers
3. **Rust Only**: Cannot be used from other languages (no bindings)
4. **Async Complexity**: No first-class async support; async apps need manual integration with Tokio/etc.
5. **Terminal Compatibility**: Relies on terminal's ANSI support; legacy terminals may have issues
6. **Learning Curve**: Cassowary layout system and buffer diffing concepts can be non-obvious to newcomers
7. **No Built-in Navigation/Focus System**: Complex apps need to implement their own focus management
8. **Maintenance Burden**: With 124 releases, staying up-to-date requires effort

## SugarCraft Mapping

| ratatui Component | SugarCraft Library | Notes |
|-----------------|-------------------|-------|
| `ratatui-core` (Widget/StatefulWidget traits, Buffer, Layout, Style, Text, Symbols) | `candy-core` | Core TUI framework - models, buffer, rendering primitives |
| `ratatui-widgets` (Block, Paragraph, List, Table, Chart, Gauge, etc.) | `sugar-bits` | Component library - all the visual widgets |
| `ratatui-crossterm` backend | `candy-shell` | Terminal I/O and backend abstraction |
| `Layout` + Cassowary constraints | `candy-shine` | Layout system for positioning |
| `Stylize` trait | `sugar-prompt` | Text styling helpers (Stylize trait mirrors styled() pattern) |
| `ratatui-macros` | `sugar-bits` (likely) | Declarative macros for boilerplate |
| `Terminal::draw` + `Frame` pattern | `candy-core` (Model) | The terminal draw loop mirrors the Model::view() pattern |
| `StatefulWidget` + State types | `candy-core` (State) | Stateful widgets with `*State` types mirror React state |
| `Text`/`Line`/`Span` hierarchy | `sugar-bits` (Text rendering) | Styled text primitives |
| Buffer diffing for efficient render | `candy-core` (render optimization) | Only redraw changed cells |
| `ratatui::run()` initialization | `candy-shell` (Tty initialization) | Terminal setup/teardown helpers |

**Many-to-Many Mapping:**
- `ratatui` as a whole → Maps to multiple SugarCraft libs in combination
- `ratatui-core` → `candy-core` (foundation) + `sugar-bits` (text/layout)
- `ratatui-widgets` → `sugar-bits` (visual components)
- Backend abstraction → `candy-shell` (TTY I/O)

## Analysis

Ratatui is a mature, well-architected Rust TUI library that provides a comprehensive foundation for building terminal user interfaces. Originally a fork of the abandoned `tui-rs` crate, Ratatui has evolved into a modular workspace with `ratatui-core` providing stable contracts for widget authors, `ratatui-widgets` containing the built-in widget set, and multiple backend implementations supporting different terminal capabilities. The architectural decision to split the crate in version 0.30.0 demonstrates thoughtful API stability governance - widget library authors can depend on `ratatui-core` without worrying about frequent breaking changes from widget additions.

The library's design philosophy centers on immediate-mode rendering with buffer diffing - every frame the application redraws everything, and Ratatui computes the minimal diff to the terminal. This approach is simple to reason about and highly efficient. The Cassowary constraint solver-based layout system provides sophisticated responsive behavior, automatically adapting to terminal resizes without explicit handling. The widget trait pattern (`Widget` / `StatefulWidget`) is clean and extensible, with sensible defaults like `impl Widget for &str` and `impl Widget for String` allowing string widgets without explicit wrapping.

Compared to Charmbracelet'sBubble Tea (which SugarCraft ports), Ratatui takes a more direct approach - it provides the rendering primitives and layout system but delegates event handling to backend libraries. This is both a strength (flexibility) and weakness (boilerplate for input handling). The `Stylize` trait provides excellent ergonomic styling shortcuts that feel similar to Bubble Tea's styled strings. The 19.6k GitHub stars and 26M+ downloads demonstrate strong community adoption and longevity.

The main gaps for a PHP port like SugarCraft would be: the immediate-mode rendering pattern (PHP would use retained mode with diffing), the lack of built-in event handling (needs ReactPHP integration), and the lack of async support (needs first-class ReactPHP/Swoole integration). The constraint-based layout algorithm could be directly ported, and the widget trait pattern could inspire the `Model::view()` contract in SugarCraft.
