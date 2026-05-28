# Bdeering1/console-menu

## Metadata
- URL: https://github.com/Bdeering1/console-menu
- Language: Rust
- Stars: Unknown (GitHub API unavailable for unauthenticated requests)
- License: MIT
- Description: A simple yet powerful library for creating beautiful console menus in Rust with ANSI color support, vim-style keybindings, and nested menu capability.

## Feature List
- Interactive console menu rendering with arrow key navigation
- Vim-style keybindings (h/j/k/l for left/down/up/right movement)
- 8-bit ANSI escape code colors for foreground, background, title, selected option, and message
- Pre-defined color constants module (WHITE, BLUE, GREEN, RED, etc.)
- Optional title displayed above menu options
- Optional footer message displayed below menu options
- Automatic pagination for menus exceeding terminal height
- Nested menu support via callback functions
- Exit on action toggle (immediate exit vs. stay-open after selection)
- Cursor hide/show management on entry/exit
- Centered menu layout calculation
- Bell/bold/underline text styling for titles

## Key Classes and Methods
- `Menu`: Main interactive menu struct
  - `Menu::new(options: Vec<MenuOption>, props: MenuProps) -> Self` — Constructor that initializes menu state, calculates pagination, and determines max width
  - `show(&mut self)` — Entry point that hides cursor, clears screen, draws initial menu, and starts the navigation loop
  - `run_navigation(&mut self, stdout: &Term)` — Main event loop handling arrow keys, vim keys, Enter, Escape/q/Backspace
  - `set_page(&mut self, page: usize)` — Updates pagination state when user navigates between pages
  - `draw(&self, stdout: &Term)` — Renders current menu state with ANSI colors and padding
  - `exit(&self, stdout: &Term)` — Clears screen and restores cursor visibility
  - Private helpers: `apply_bold()`, `switch_fg()`, `apply_bg()`

- `MenuOption`: Individual menu item
  - `MenuOption::new(label: &str, action: impl FnMut() + 'static) -> Self` — Factory creating an option with string label and boxed closure callback
  - Fields: `label: String`, `action: Box<dyn FnMut()>`

- `MenuProps<'a>`: Configuration struct for menu appearance and behavior
  - Fields: `title: &'a str`, `message: &'a str`, `exit_on_action: bool`, `bg_color: u8`, `fg_color: u8`, `title_color: Option<u8>`, `selected_color: Option<u8>`, `msg_color: Option<u8>`
  - `MenuProps::default() -> Self` — Returns defaults: gray bg (8), white fg (15), exit_on_action=true, msg_color Some(7)

- `color` module: Pre-defined 8-bit ANSI color constants
  - `WHITE=15`, `LIGHT_GRAY=7`, `GRAY=8`, `BLUE=32`, `GREEN=35`, `PURPLE=99`, `RED=160`, `ORANGE=208`, `YELLOW=220`, `BLACK=233`, `DARK_GRAY=236`

## Notable Algorithms / Named Patterns
- **ANSI 8-bit color escape codes**: Uses `\x1b[38;5;{color}m` for foreground and `\x1b[48;5;{color}m` for background, ensuring widespread terminal compatibility
- **Pagination algorithm**: `options_per_page = (terminal_height - 6)`, `num_pages = ((options.len() - 1) / options_per_page) + 1`; calculates centered page windows with `page_start = selected_page * options_per_page`
- **Centered layout calculation**: `indent = terminal_width / 2 - (menu_width + 4) / 2` and `vertical_pad = terminal_height / 2 - (options_per_page + extra_lines) / 2`
- **String padding**: `pad_left()` uses `{: >width$}` format, `pad_right()` uses `{: <width$}` format for visual alignment
- **Boxed dynamic dispatch**: `Box<dyn FnMut()>` allows arbitrary callbacks while maintaining type erased storage

## Strengths
- **Simplicity**: Single-file library with a clean, minimal API surface
- **Ergonomic builder pattern**: `MenuOption::new()` and `MenuProps::default()` with struct update syntax make usage intuitive
- **Theming flexibility**: Full control over fg/bg colors for menu, title, selected item, and footer message via 8-bit ANSI colors
- **Navigation support**: Dual keybindings (arrows + vim-style) accommodate both casual users and power users
- **Nested menus**: Callbacks can execute `nested_menu.show()`, enabling hierarchical menu structures
- **Pagination**: Handles terminals of any height by automatically splitting large option sets across pages
- **Single dependency**: Only depends on `console` crate (v0.15.7), keeping the crate lightweight
- **Self-contained rendering**: Uses raw ANSI escape sequences without external terminal capability detection

## Weaknesses
- **No multi-line labels**: Labels are single-line strings only; no support for descriptions, icons, or sublabels per option
- **All-or-nothing exit behavior**: `exit_on_action` exits immediately after callback OR stays open regardless — no hybrid mode
- **No menu positioning**: Menu is always centered; no API for left/right/top/bottom alignment
- **No keyboard shortcut hints**: No built-in way to show key indicators (e.g., "Press 1 for option 1")
- **No callback result handling**: Actions are fire-and-forget `FnMut()` with no return value captured by the menu
- **8-bit color limitation**: While stated as compatibility feature, modern terminals support 24-bit true color which this lib doesn't expose
- **No menu persistence/state**: Cannot serialize/deserialize menu state; purely runtime
- **Single selection model**: No multi-select or checkbox-style options
- **Hardcoded escape sequences**: Uses literal ANSI codes rather than a proper terminal abstraction

## SugarCraft Mapping
- **candy-core**: The core rendering and event loop patterns (`show()`, `run_navigation()`, `draw()`) map to how `candy-core` handles TUI loop infrastructure. The ANSI color handling via the `color` module parallels `candy-core`'s theme/color support.
- **sugar-bits**: This library is most directly comparable to `sugar-bits` — it's a single component (a menu/selector widget) rather than a full application framework. The `Menu` struct is analogous to a `Component` in SugarCraft's `Component` trait.
- **honey-bounce**: Not directly relevant — this library has no animation/timing physics.

## Analysis

Bdeering1/console-menu is a lightweight Rust TUI library focused specifically on interactive console menus. It provides a clean abstraction over ANSI escape codes via the `console` crate, offering developers a simple `Menu` struct that accepts a vector of `MenuOption` items (each with a label and callback) and a `MenuProps` configuration object for theming. The library handles its own event loop via `show()`, blocking until the user selects an option or exits.

The rendering approach is straightforward: on each keypress, the entire menu is cleared and redrawn using buffered stdout with raw ANSI escape sequences. Colors are specified as 8-bit values (0-255) to maximize terminal compatibility, with a curated `color` module providing named constants. The layout is always centered within the terminal, calculated from the terminal dimensions at show-time.

The navigation system supports both arrow keys and vim-style bindings (hjkl + bw for page movement), plus Enter to confirm and Escape/q/Backspace to exit. Pagination is automatic when the number of options exceeds the calculated `options_per_page` value. Nested menus are supported by calling `show()` recursively from within a callback.

Compared to SugarCraft's architecture, this library would fit best as a `sugar-bits` component — it's a self-contained UI widget rather than a framework. The key differences are that SugarCraft uses the Bubble Tea model's `update()`/`view()` separation and immutable state with `with*()` builders, whereas this Rust library uses mutable state directly in the `Menu` struct. The Rust library is also much simpler — it lacks SugarCraft's sophisticated event handling, component composition, and state management patterns.

In summary, this is a competent but minimal single-purpose menu library suitable for simple CLI tools, whereas SugarCraft's approach with Charmbracelet's bubbletea provides a more comprehensive and extensible TUI architecture suitable for complex applications.
