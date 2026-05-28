# php-school/cli-menu

## Metadata
- **URL:** https://github.com/php-school/cli-menu
- **Language:** PHP (>=8.2)
- **Stars:** ~1,944
- **License:** MIT
- **Description:** A command line menu helper in PHP — build interactive TTY menus with colors, borders, checkboxes, radio items, submenus, input dialogs, and ASCII art.

## Feature List
- **Interactive TTY menus** with keyboard navigation (arrow keys, vim-style `j/k`, Enter to select)
- **Builder pattern** (`CliMenuBuilder`) for fluent menu construction
- **Item types:** `SelectableItem`, `CheckboxItem`, `RadioItem`, `StaticItem`, `LineBreakItem`, `AsciiArtItem`, `SubMenuItem`, `SplitItem`
- **Submenus** — menus nested inside menus, each with independent styling
- **Split items** — multiple items on the same row (horizontal layout within vertical list)
- **Styled output** — foreground/background ANSI colors (standard 8 + 256-color + bright variants), borders, padding, margin, title separator
- **Checkbox/radio toggle state** with custom markers (UTF-8 supported)
- **Custom control mapping** — rebind keypresses to arbitrary callables
- **Auto keyboard shortcuts** — parse `[X]` in item text to trigger items
- **Dialogues** — `Flash` (one-shot message), `Confirm` (yes/no prompt), `CancellableConfirm`
- **Input prompts** — `Text`, `Number` (with up/down increment), `Password` (asterisk echo), `Custom`
- **Validation** for all input types with custom validators
- **Menu redraw** — mutate menu state (add/remove items, change style) and re-render
- **Item extra** — arbitrary right-hand-side text per item (e.g., "[COMPLETED]")
- **Disabled items** — visually dimmed, skipped during navigation
- **Auto-centering** — `setMarginAuto()` to center menu in terminal
- **Terminal awareness** — width shrinking when menu exceeds terminal size
- **Style propagation** — submenus inherit parent styles unless overridden
- **Integrations** — Symfony Console wrapper, Laravel console menu, Laravel Artisan UI

## Key Classes and Methods

### Core
- **`CliMenu`**: The main menu runtime. `open()` starts the event loop, `close()` tears it down.
  - `open()`, `close()`, `redraw(bool $clear = false)`, `draw()`
  - `addItem()`, `addItems()`, `setItems()`, `removeItem()`, `getItems()`
  - `moveSelectionVertically()`, `moveSelectionHorizontally()` — navigation logic
  - `executeCurrentItem()` — invokes the selected item's callable
  - `getSelectedItem()`, `getSelectedItemIndex()`, `setSelectedItem()`
  - `getStyle()`, `setStyle()`, `setItemStyle()`, `getItemStyle()`, `getStyleLocator()`
  - `flash()`, `confirm()`, `cancellableConfirm()` — dialogue builders
  - `askText()`, `askNumber()`, `askPassword()` — input builders
  - `addCustomControlMapping()`, `removeCustomControlMapping()`
  - `propagateStyles()` — propagates style to items and sub-menus
  - `importStyles()` — copy style state from another menu (for sub-menus)

- **`MenuStyle`**: The visual style of the menu (CSS-box-model-like: width, margin, border, padding, colors).
  - `setFg()`, `setBg()` — foreground/background colors with 256-color and fallback support
  - `setWidth()`, `setMargin()`, `setMarginAuto()`, `setPadding()`, `setBorder()`
  - `getContentWidth()`, `getRightHandPadding()` — content dimension calculations
  - `generateColoursSetCode()` — builds ANSI SGR escape sequences
  - `getDisabledItemText()` — applies dim + bright-color for disabled items
  - `getBorderTopRows()`, `getBorderBottomRows()`, `getPaddingTopBottomRows()`
  - `hasChangedFromDefaults()` — detects explicit style changes

- **`CliMenuBuilder`**: Fluent builder for constructing `CliMenu` instances.
  - `setTitle()`, `addItem()`, `addCheckboxItem()`, `addRadioItem()`, `addStaticItem()`, `addLineBreak()`, `addAsciiArt()`, `addSubMenu()`, `addSplitItem()`
  - `setForegroundColour()`, `setBackgroundColour()`, `setBorder()`, `setPadding()`, `setMarginAuto()`
  - `disableDefaultItems()`, `enableAutoShortcuts()`, `modifySelectableStyle()`, `modifyCheckboxStyle()`, `modifyRadioStyle()`
  - `build()` — returns the configured `CliMenu` instance

- **`SplitItemBuilder`**: Nested builder for `SplitItem` rows.
  - `addItem()`, `addCheckboxItem()`, `addRadioItem()`, `addSubMenu()`, `addStaticItem()`
  - `setGutter()` — spacing between items on the same row

- **`Frame`**: Represents the current screen — accumulates rows of output, implements `Countable`.
  - `newLine()`, `addRow()`, `addRows()`, `getRows()`

### Menu Items (all implement `MenuItemInterface`)
- **`SelectableItem`**: Basic selectable menu entry with custom marker and optional item extra.
  - `getRows()`, `getText()`, `canSelect()`, `getSelectAction()`, `showItemExtra()`, `hideItemExtra()`
  - Delegates rendering to `SelectableItemRenderer`

- **`CheckboxItem`**: Toggle checkbox with checked/unchecked state and custom markers.
  - `getChecked()`, `setChecked()`, `setUnchecked()`, `toggle()`

- **`RadioItem`**: Mutually exclusive radio selection — selecting one unselects others at the same menu level.
  - Same toggle API as `CheckboxItem`

- **`StaticItem`**: Non-selectable label/heading, respects word-wrap within content width.
  - Does not implement `canSelect() === false`

- **`LineBreakItem`**: Non-selectable separator that repeats a string across the full menu width.
  - Constructed with a character/string and optional repeat count

- **`AsciiArtItem`**: Renders ASCII art with optional alignment (`POSITION_CENTER`, `POSITION_LEFT`, `POSITION_RIGHT`) and fallback text if too wide.

- **`MenuMenuItem`**: Sub-menu entry — triggers opening of a nested `CliMenu` when selected.

- **`SplitItem`**: Horizontal row container that holds multiple items side-by-side.
  - `getItems()`, `addItem()`, `canSelectIndex()`, `setSelectedItemIndex()`, `getSelectedItem()`, `getGutter()`
  - Implements `PropagatesStyles` to cascade style to child items

### Style System
- **`MenuStyle`**: Menu-level visual styling (see above)
- **`ItemStyle`**: Interface for per-item-type styling
- **`SelectableStyle`**: Marker customization (`setSelectedMarker()`, `setUnselectedMarker()`) for `SelectableItem`/`MenuMenuItem`
- **`CheckboxStyle`**: Checkbox markers (`setCheckedMarker()`, `setUncheckedMarker()`) for `CheckboxItem`
- **`RadioStyle`**: Radio markers for `RadioItem`
- **`DefaultStyle`**: Default no-op style for static/break/ascii/split items
- **`Locator`**: Maps `MenuItem` classes to `ItemStyle` subclasses; allows custom style registration

### Input / Dialogue
- **`Input`**: Interface for input collectors
- **`InputIO`**: Handles TTY character reading for inputs (cursor movement, backspace, validation loop)
- **`InputResult`**: Wraps the collected input value; `fetch()` returns the raw string
- **`Text`**: Free-text input with non-empty validation
- **`Number`**: Integer input with up/down key increment and numeric regex validation
- **`Password`**: Password input with asterisk echo and minimum-length validation (configurable)
- **`Flash`**: Overlay message displayed on top of the menu, dismissed on any key
- **`Confirm`**: Yes/no confirmation dialogue with configurable button text
- **`CancellableConfirm`**: Confirm dialog with a separate Cancel button

### Actions
- **`ExitAction`**: Closes the entire menu tree
- **`GoBackAction`**: Closes the current sub-menu and returns to parent

### Terminal / Utilities
- **`TerminalFactory`**: Creates `Terminal` from system (from `php-school/terminal` package)
- **`ColourUtil`**: Validates terminal color support, maps named colors to ANSI codes, generates 256-color fallbacks
- **`StringUtil`**: String width calculation (multibyte-aware), ANSI strip, wordwrap
- **`Collection`**: Simple collection/fluent filter/map utility (`collect()`, `each()`, `mapWithKeys()`)
- **`ArrayUtils`**: `max()` utility for array of arrays

## Notable Algorithms / Named Patterns

- **Builder Pattern** — `CliMenuBuilder` + `SplitItemBuilder` provide a fluent, step-by-step API for menu construction. All setter methods return `$this` for chaining.

- **Strategy Pattern** — Each `MenuItemInterface` implements its own `getRows()` rendering strategy. `SplitItem` aggregates multiple items and distributes width evenly.

- **Composite Pattern** — `SplitItem` is a composite of `MenuItemInterface` children, each with their own style.

- **Observer/Event-like Pattern** — Item selection triggers a callable (action). The menu event loop dispatches `InputCharacter` events to handlers.

- **Box Model Layout** — `MenuStyle` models menu dimensions like CSS box model: margin (outer spacing) → border → padding → content width. Width calculations subtract borders and padding from total width.

- **ANSI SGR (Select Graphic Rendition)** — Colors and text styles are encoded as ANSI escape sequences (`\033[7m` for reverse/invert, `\033[0m` for reset). Color codes support 8 standard colors, 256-color palette (`38;5;n` / `48;5;n`), and bright variants (+60 offset).

- **Non-canonical Terminal Mode** — The menu disables canonical mode (`icanon`), echo back (`echo`), and cursor to take full control of the TTY for real-time character-by-character input without line buffering.

- **Cycle Detection in Navigation** — `moveSelectionVertically()` counts loop iterations against total item count; if a full cycle occurs with no selectable item found, navigation stops.

- **Width Shrinkage Algorithm** — `maybeShrinkWidth()` compares `(requestedWidth + margin*2)` against terminal width; if exceeded, shrinks width to `terminalWidth - (margin*2)`.

- **Auto-center Algorithm** — `calculateMarginAuto()` computes `floor((terminalWidth - menuWidth) / 2)` as the left margin.

- **Item Extra Distribution** — `SplitItem::calculateItemExtra()` finds the widest `itemExtra` string among items that display it; this width is used to evenly divide content width among split items.

- **Style Propagation** — `propagateStyles()` clones `ItemStyle` objects from the parent menu's `Locator` into child items that haven't explicitly changed their style.

## Strengths

- **Comprehensive feature set** — covers virtually every common CLI menu pattern: simple selections, checkbox toggles, radio groups, submenus, split rows, input prompts, and styled dialogues in a single library.
- **Polished TTY control** — properly manages the terminal (non-canonical mode, cursor hiding, ANSI SGR sequences) providing a clean, flicker-free render loop.
- **Builder API** — clean, fluent, type-safe construction API that guides users toward correct usage.
- **Deep customization** — every visual aspect is configurable: colors (with 256-color support and fallback), borders (per-side), padding (per-side), markers (custom UTF-8), shortcuts.
- **Style separation** — `MenuStyle` (menu-level) and `ItemStyle` (item-type-level) are cleanly separated concerns.
- **Sub-menu inheritance** — submenus inherit parent styles by default but can override independently.
- **Integrations** — official wrappers for Symfony Console and Laravel, demonstrating real-world ecosystem value.
- **Well-tested** — CI on GitHub Actions + AppVeyor (Windows), code coverage tracked via Codecov, PHPStan static analysis.
- **PHP 8.2+ only** — modern codebase taking advantage of constructor property promotion, typed properties, named arguments.
- **Validation-first inputs** — all inputs validate before returning; custom validators via any callable.

## Weaknesses

- **No async/stream support** — entirely synchronous blocking I/O; cannot integrate with ReactPHP or Swoole event loops without a complete rewrite of the input reading loop.
- **No Windows native support** — relies on `ext-posix` for TTY introspection; AppVeyor CI is used for Windows testing but the library acknowledges platform limitations.
- **No menu search/filter** — no built-in type-ahead filtering or fuzzy search for long menus.
- **No mouse support** — purely keyboard-driven; no click-to-select or mouse event handling.
- **Monolithic event loop** — the `display()` method in `CliMenu` is a monolithic `while` loop; inputs like `Number` (`InputIO`) also contain embedded while loops. Extracting this into a proper state machine or event emitter would improve extensibility.
- **Limited accessibility** — no screen reader / aria support; no configuration for screen refresh rate.
- **Tight coupling to `php-school/terminal`** — depends on an external terminal abstraction package; if that package has issues, cli-menu is affected.
- **No built-in internationalization** — menus output raw text; no `gettext`/`Intl` integration for translations.
- **Deprecated/inactive maintenance** — the repo shows a last commit age that suggests limited active maintenance; despite this, the API is stable and feature-complete.

## SugarCraft Mapping

This library maps primarily to the **candy-shell** tier (framework/system layer) and partially to **sugar-bits** (components):

- **`candy-shell`**: The overall terminal/menu rendering engine (`CliMenu`, `MenuStyle`, `Frame`) directly corresponds to the TTY rendering subsystem in SugarCraft. The `CliMenuBuilder` is the sugar equivalent of the builder pattern used in `candy-shell`.

- **`sugar-bits`**: The individual `MenuItem` types (`SelectableItem`, `CheckboxItem`, `RadioItem`, `StaticItem`, etc.) map to discrete TUI component implementations — each one is a renderable, selectable piece of UI similar to what `sugar-bits` components would be.

- **`candy-core`**: The style system (`MenuStyle`, `ItemStyle`, `Locator`) and ANSI SGR color handling map to the styling/internals of `candy-core`.

- **`honey-bounce`**: The `Frame` class and the animation-like redraw loop could leverage spring physics for smooth transitions between menu states.

### Mapping Details

| php-school/cli-menu Class | SugarCraft Equivalent | Notes |
|---|---|---|
| `CliMenu` | `SugarCraft\Shell\Menu` | Main menu runtime + event loop |
| `MenuStyle` | `SugarCraft\Core\Style` | Box-model styling (margin/border/padding/colors) |
| `CliMenuBuilder` | `SugarCraft\Shell\MenuBuilder` | Fluent builder for menu construction |
| `SplitItem` | `SugarCraft\Bits\SplitRow` | Horizontal layout within vertical list |
| `SelectableItem` | `SugarCraft\Bits\SelectableItem` | Basic selectable row |
| `CheckboxItem` | `SugarCraft\Bits\CheckboxItem` | Toggle checkbox row |
| `RadioItem` | `SugarCraft\Bits\RadioItem` | Mutually-exclusive radio row |
| `StaticItem` | `SugarCraft\Bits\LabelItem` | Non-selectable text heading |
| `LineBreakItem` | `SugarCraft\Bits\SeparatorItem` | Full-width divider |
| `AsciiArtItem` | `SugarCraft\Bits\AsciiArtItem` | Centered/block ASCII art |
| `MenuMenuItem` (submenu) | `SugarCraft\Bits\SubMenuItem` | Opens nested menu |
| `Flash` / `Confirm` | `SugarCraft\Shell\Dialogue` | Overlay prompts |
| `Text` / `Number` / `Password` | `SugarCraft\Bits\Input` | Text/number/password input rows |
| `SelectableItemRenderer` | `SugarCraft\Core\Renderer` | Raw ANSI SGR byte rendering |
| `ColourUtil` | `SugarCraft\Core\Ansi\ColourUtil` | ANSI color code generation |
| `Terminal` | `SugarCraft\Core\Terminal` | TTY abstraction |
| `NonCanonicalReader` | `SugarCraft\Core\Terminal\RawMode` | Raw TTY input reading |

## Analysis

`php-school/cli-menu` is a mature, full-featured library for building interactive command-line menus in PHP. At its core, it models a menu as a collection of `MenuItemInterface` objects rendered into an ANSI-colored TTY frame, driven by a blocking event loop that reads single characters from a non-canonical terminal. The architecture follows a clear separation: `CliMenu` is the runtime orchestrator, `MenuStyle` defines the visual presentation (colors, borders, padding, margin), individual `MenuItem` subclasses each implement their own rendering strategy via `getRows()`, and the `CliMenuBuilder` provides a clean fluent API for assembling menus programmatically.

The rendering pipeline is particularly well-designed: each item returns an array of strings (rows) via `getRows($style, $selected)`, which `CliMenu::drawMenuItem()` then wraps with margin, border, padding, and ANSI color codes to produce the final SGR byte stream. The `Frame` class acts as a buffer, accumulating these rows before writing them all at once to the terminal, then clearing everything below the menu with `clearDown()`. This double-buffering approach minimizes terminal flicker. The `MenuStyle` class encodes the CSS-box-model concept for CLI: width is the outer dimension, from which borders and padding are subtracted to yield the content width, which is then distributed to menu items including split items.

The library's approach to input is notably low-level: it reads raw bytes via `NonCanonicalReader` from `php-school/terminal` and maps them to logical controls (`InputCharacter::UP`, `DOWN`, `ENTER`, etc.). Default vim-style mappings (`j/k` for up/down, `l/m` for right/left) are built-in and can be disabled or extended. The input loop in `CliMenu::display()` is a classic state-machine-while-loop: read character, map to control, switch on control type, mutate menu state, redraw. For inputs like `Number` and `Password`, a separate `InputIO` class runs its own inner while-loop to collect characters with its own validation. The split-item horizontal navigation is particularly interesting: when LEFT/RIGHT is pressed and the current item is a `SplitItem`, it delegates to `moveSelectionHorizontally()` which cycles through the split item's children.

The style propagation system ensures submenus feel consistent: when a submenu is created via `addSubMenu()`, `propagateStyles()` clones the parent's `ItemStyle` instances into child items that haven't explicitly customized their style. The `Locator` class maintains a registry mapping item class names to style class names, making the system extensible — new item types can be registered with `registerItemStyle()`. Overall, this library provides a professional-grade foundation for building polished CLI tools with interactive menus, and its architecture (builder, composite items, style locator, ANSI rendering) maps cleanly onto the SugarCraft candy-shell and sugar-bits tiers.
