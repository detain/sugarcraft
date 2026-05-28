# textualize/textual

## Metadata
- URL: https://github.com/textualize/textual
- Language: Python
- Stars: ~30k+ (estimated based on repo activity and PyPI downloads)
- License: MIT
- Description: A powerful Text User Interface (TUI) framework for Python that combines modern Python patterns with the best web development concepts. Enables building cross-platform terminal applications with a simple, expressive API, and can also serve applications in a web browser.

## Feature List

- **Component-based UI Architecture** — Widget system with inheritance, composition, and DOM tree
- **Reactive State Management** — Declarative state variables with watchers and computed properties
- **Message/Event System** — Async message pump with bubbling, propagation, and handled/unhandled states
- **CSS-like Styling (TCSS)** — Textual CSS with selectors, properties, and themes
- **Layout Engine** — Multiple layout strategies: Vertical, Horizontal, Grid, Dock, and Streams
- **Rich Text Rendering** — Built on `rich` library for ANSI escape sequences, colors, and formatted text
- **40+ Built-in Widgets** — Button, Input, TextArea, DataTable, Tree, ListView, Tabs, Select, Markdown, etc.
- **Key Binding System** — Configurable keyboard shortcuts with action dispatching
- **Command Palette** — Fuzzy search command interface with custom command providers
- **Async/Await Support** — First-class async programming with workers and timers
- **Web Browser Rendering** — Serve Textual apps in the browser via textual-web
- **Testing Framework** — Pilot class for programmatic app testing with asserts and simulation
- **Animation System** — Animator with easing functions and transitions
- **DevTools** — Developer console, logging, and hot-reloading support
- **Theme System** — Built-in themes (nord, dracula, tokyo-night, etc.) and custom theme creation
- **Internationalization** — Message/banner localization support
- **Mouse Support** — Full mouse event handling with widget focus tracking
- **Thread-safe Logging** — Per-app logger with verbosity levels and groups

## Key Classes and Methods

### Core Application
- **App**: `run()`, `run_test()`, `compose()`, `mount()`, `query()`, `query_one()`, `bind()`, `set_focus()` — Central application class managing screens, message pump, and driver
- **Screen**: `stack_screen()`, `pop_screen()`, `dismiss()` — Container for visible widgets with focused widget tracking

### Widget System
- **Widget**: `compose()`, `mount()`, `refresh()`, `layout()`, `render()`, `watch_*()`, `on_*()` — Base class for all UI components
- **DOMNode**: `children`, `parent`, `query()`, `walk_children()` — Document Object Model node with tree traversal

### State & Reactivity
- **Reactive**: `__init__(default, layout, repaint, init, always_update, compute, recompose, bindings, toggle_class)` — Descriptor for reactive state with watchers
- **var()**: Factory for reactive variables with auto-watchers
- **Signal**: `emit()`, `subscribe()` — Event signal for decoupled communication

### Message System
- **MessagePump**: `_parent`, `_running`, `post_message()`, `subscribe()`, `call_next()`, `call_later()` — Async message queue processor
- **Message**: `bubble`, `verbose`, `handler_name` — Base class for all events and messages
- **_dispatch_key**: `dispatch_key()` — Key event dispatcher looking for `key_<name>` methods

### Layout & Rendering
- **Layout**: `arrange()`, `get_content_width()`, `get_content_height()` — Abstract base for layout algorithms
- **WidgetPlacement**: NamedTuple with `region`, `offset`, `margin`, `widget`, `order`, `fixed`, `overlay`, `absolute` — Widget positioning info
- **Compositor**: `reflow()`, `render()`, `update()` — Combines widgets into a single screen
- **_compositor.LayoutUpdate**: `strips`, `region` — Incremental render update for changed regions

### Styling
- **Stylesheet**: `parse()`, `apply()`, `rule()` — CSS parser and applier
- **Styles**: All CSS properties (colors, borders, spacing, etc.)
- **Style**: `background`, `foreground`, `bold`, `italic`, `underline`, `link` — Visual style descriptor
- **Color**: `parse()`, `darken()`, `lighten()`, `from_hsl()`, `from_rgb()` — Color manipulation

### Widgets (Selection)
- **Button**: `press()`, `variant` — Clickable button with primary/warning/reset variants
- **Input**: `value`, `placeholder`, `validate()`, `error` — Text input with validation
- **TextArea**: `text`, `selection`, `edit()` — Multi-line text editor with syntax highlighting
- **DataTable**: `add_column()`, `add_row()`, `sort()`, `fix_columns()` — Tabular data display
- **Tree**: `root`, `reset()`, `expand()`, `collapse()` — Hierarchical tree view
- **ListView**: `items`, `index`, `append()`, `remove()` — Scrollable list container
- **Tabs**: `tabs`, `active`, `add()`, `remove()`, `clear()` — Tabbed interface
- **Select**: `value`, `options`, `open()`, `close()` — Dropdown select widget
- **Markdown**: `text`, `title`, `metadata` — Markdown renderer

### Drivers & Platform
- **Driver**: Abstract base for terminal drivers (Linux, Windows, macOS, Web)
- **linux_driver.LinuxDriver**: Non-inline terminal driver
- **linux_inline_driver.LinuxInlineDriver**: Inline terminal with direct mode
- **web_driver.WebDriver**: Browser-based rendering driver
- **_xterm_parser**: Terminal escape sequence parser

### Testing & Development
- **Pilot**: `press()`, `click()`, `hover()`, `pause()`, `resize_terminal()` — Test automation helper
- **await_complete.AwaitComplete**: Async context manager for mount/removal completion
- **Logger**: `info()`, `debug()`, `warning()`, `error()`, `verbose`, `event` — App logging

### Bindings & Actions
- **Binding**: `key`, `action`, `description`, `show`, `priority` — Key binding configuration
- **Binding.Group**: `description`, `compact` — Group bindings for display
- **actions.py**: `ActionParseResult`, `parse()`, `run()` — Action parsing and execution

## Notable Algorithms / Named Patterns

### Elm Architecture (Model-Update-View)
Textual implements a variant of the Elm architecture where:
- **Model** = Reactive state via `Reactive` descriptors and `var()`
- **Update** = Message handlers via `on_*` decorators and `watch_*` methods
- **View** = `compose()` method yielding widgets and `render()` producing visuals

```python
# Example pattern from examples/calculator.py
class CalculatorApp(App):
    numbers = var("0")  # Reactive state
    show_ac = var(True)
    
    def watch_numbers(self, value: str) -> None:  # Watcher
        self.query_one("#numbers", Digits).update(value)
    
    @on(Button.Pressed, ".number")  # Message handler
    def number_pressed(self, event: Button.Pressed) -> None:
        self.numbers = self.value = self.value.lstrip("0") + number
```

### Message Pump Pattern
Async message queue with bubbling and bubbling hierarchy:
- Messages bubble up through DOM tree (`bubble=True` default)
- Handlers can mark messages as handled to stop propagation
- Decorated handlers (`@on()`, `key_<name>`) auto-register

### Spatial Map for Hit Testing
The compositor uses a `_spatial_map.SpatialMap` for efficient widget lookup by screen position:
- R-tree-like spatial indexing for O(log n) hit detection
- Enables efficient mouse event routing to widgets

### CSS Layout Algorithm
Textual CSS (TCSS) implements:
- Box model with margin, border, padding, content
- Flexbox-like layouts via `display: flex`, `flex-*` properties
- Grid layouts via `display: grid`, `grid-*` properties
- Dock layouts via `dock: top|left|bottom|right|left|1..`

### Compositor Double-Buffering
The compositor:
1. Calculates widget placements via layout algorithms
2. Renders widgets to strips (horizontal lines)
3. Composites strips with control sequences
4. Updates only dirty regions (not full redraws)

### Lazy Widget Loading
Widgets are lazy-loaded via `__getattr__` in `widgets/__init__.py` to improve startup time.

## Strengths

- **Modern Python API** — Uses Python 3.9+ features: async/await, dataclasses, type hints, rich repr
- **Well-designed Reactivity** — Reactive state is declarative and automatic, similar to Vue/Solid
- **Comprehensive Widget Library** — 40+ built-in widgets covering most UI needs
- **CSS-based Styling** — Familiar styling paradigm for web developers, with hot-reload
- **Async-First Design** — Native async/await throughout, not bolted on
- **Excellent Testing Support** — Pilot class enables pixel-perfect testing without screenshots
- **Multi-Platform** — Supports Linux, Windows, macOS, and web browser
- **Rich Integration** — Built on the excellent `rich` library for terminal formatting
- **Good Documentation** — Comprehensive guide, API docs, and examples
- **Active Development** — Regular releases, responsive to issues
- **Command Palette** — Extensible fuzzy search interface
- **DevTools** — Built-in developer console and logging

## Weaknesses

- **Python Only** — Cannot be used from other languages (no C extension or bindings)
- **Heavy Rich Dependency** — Tight coupling to `rich` library (~10MB dependency)
- **Complex Internal Architecture** — Large code base (50k+ lines in main modules) with many interconnected classes
- **Performance on Large UIs** — Python GIL can limit performance for CPU-intensive apps
- **No Native GUI Support** — Terminal only (or web browser), no native desktop widgets
- **Startup Time** — Lazy widget loading helps but import time still noticeable
- **Windows Performance** — Historically slower than Linux/macOS due to ConPTY overhead
- **Async Complexity** — Async-first can be challenging for beginners
- **Limited Mobile/Touch** — No touch event support beyond basic mouse emulation

## SugarCraft Mapping

Textual is a **sister project** to the Charmbracelet ecosystem (bubbletea, lipgloss, etc.) that SugarCraft ports. It has architectural overlap with multiple SugarCraft libs:

| textual Component | SugarCraft Lib | Mapping Rationale |
|---|---|---|
| `App`, `Widget`, `Screen`, `DOMNode`, `MessagePump` | `candy-core/` | Core TUI runtime — both provide the Elm-architecture base (Model/View/Update loop) |
| `Reactive`, `var()`, `watch_*()` | `candy-core/` + `sugar-bits/` | Reactive state — sugar-bits has reactive components (TextInput, etc.) |
| Widget library (Button, Input, Tree, etc.) | `sugar-bits/` | Prebuilt UI components |
| TCSS Stylesheet, CSS properties | `candy-sprinkles/` | Declarative styling + layout |
| `Layout`, `VerticalLayout`, `HorizontalLayout`, `GridLayout` | `candy-sprinkles/` + `sugar-boxer/` | Layout algorithms |
| `Color`, HSL/HSV/Lab color spaces | `candy-palette/` | Color manipulation |
| `Content`, `Text`, `Span`, `Style` | `candy-shine/` | Rich text rendering |
| `markdown`, `MarkdownViewer` widgets | `candy-shine/` | Markdown rendering |
| `Tree`, `DirectoryTree` widgets | `candy-lister/` | Tree listing component |
| `Select`, `OptionList`, `SelectionList` | `sugar-prompt/` | Selection widgets |
| `Input`, `TextArea`, `MaskedInput` | `candy-forms/` | Form input components |
| `DataTable` | `sugar-table/` | Tabular data display |
| `sparkline`, `ProgressBar` | `sugar-charts/` | Chart widgets |
| `Tabs`, `TabbedContent`, `TabPane` | `sugar-stickers/` | Tab container widgets |
| `Switch`, `Checkbox`, `RadioButton`, `RadioSet` | `sugar-bits/` | Toggle/selection controls |
| `Button` | `sugar-bits/` | Button widget |
| `Toast`, `Notification` | `sugar-toast/` | Toast notifications |
| `_xterm_parser` | `candy-vt/` | Terminal escape sequence parsing |
| `timer.Timer` | `candy-core/` | Timer functionality |
| `worker.Worker`, `WorkerManager` | `candy-core/` | Background workers |
| `command.CommandPalette`, `Provider` | `candy-kit/` | Command palette system |
| `binding.Binding` | `candy-shell/` | Key binding configuration |
| `Pilot` (testing) | `candy-vcr/` | Testing infrastructure |
| `Logger` + `log()` | `candy-log/` | Logging system |
| Web driver/rendering | _no equivalent_ | Browser-based TUI (unique to textual) |
| `signal.Signal` | _partial in candy-core_ | Event signals |

**Notable Gaps in SugarCraft:**
- No web-based TUI rendering equivalent to textual-web
- No built-in command palette equivalent (textual's `command.py` is extensive)
- No Tree-sitter syntax highlighting integration

## Analysis

**Textual** is a modern, well-engineered Python TUI framework that represents the current state-of-the-art for terminal UI development in Python. Where **bubbletea** (Go) pioneered the Elm architecture for TUIs, **textual** has successfully adapted these patterns for Python while leveraging Python's async capabilities and integrating deeply with the excellent **rich** library for terminal formatting. The framework's architecture is centered on a **message pump** that processes async messages through a tree of **DOM nodes**, with **reactive** state descriptors providing automatic UI updates. The **CSS-based styling system** (TCSS) brings web-developer familiarity to terminal UI development, while the **compositor** enables efficient partial screen updates.

The framework's **strengths** include its comprehensive **widget library** (40+ built-in widgets), excellent **testing support** via the Pilot class, first-class **async/await** integration, and the ability to **serve apps in a web browser** via textual-web. Its **weaknesses** center on being Python-only, having a complex internal architecture that can be challenging to debug or extend, and historical performance issues on Windows. The tight coupling to the **rich** library is both a strength (excellent text formatting) and a weakness (large dependency, version coupling).

For **SugarCraft**, textual represents a well-designed reference implementation for several patterns already being ported from the Charmbracelet Go ecosystem. The reactive system in `candy-core` maps to textual's `Reactive` descriptors, the CSS layout in `candy-sprinkles` maps to textual's layout engine, and the widget components in `sugar-bits` map to textual's built-in widget library. The key architectural insight from textual is the **separation of the message pump from the widget tree**, combined with **declarative reactive state** with automatic watcher injection. SugarCraft's ports of the Charmbracelet ecosystem are complementary rather than competing — textual could serve as a **reference implementation** for future SugarCraft architectural improvements.
