# EthanEFung/bubble-datepicker

## Metadata
- URL: https://github.com/EthanEFung/bubble-datepicker
- Language: Go
- Stars: ~40 (estimate based on repo activity and age)
- License: MIT
- Description: A custom interactive datepicker bubble component for Bubbletea (Charmbracelet's TUI framework), inspired by the jQuery Datepicker widget.

## Feature List
- Interactive monthly calendar view with date selection
- Keyboard navigation (arrow keys, Tab/Shift+Tab for focus switching, Ctrl+C/Q to quit)
- Three focus zones: HeaderMonth, HeaderYear, and Calendar
- Customizable styling via lipgloss (Charmbracelet's styling library)
- Configurable key bindings via `KeyMap` struct
- State management following Bubbletea's `tea.Model` interface (Init/Update/View)
- Month/year navigation (Next/Last Month/Year, Week navigation)
- Day-by-day navigation (Tomorrow/Yesterday)
- Date selection state tracking (SelectDate/UnselectDate)
- Focus/blur functionality for component-level input control
- Two-way data binding support with other bubbles (e.g., textinput)

## Key Classes and Methods
- `Focus` (type int): Enum for focus states — `FocusNone`, `FocusHeaderMonth`, `FocusHeaderYear`, `FocusCalendar`
- `KeyMap`: Struct holding key bindings (`Up`, `Down`, `Left`, `Right`, `FocusPrev`, `FocusNext`, `Quit`)
- `Styles`: Struct holding lipgloss styles (`Header`, `Date`, `HeaderText`, `Text`, `SelectedText`, `FocusedText`)
- `Model`: Main struct satisfying `tea.Model` — fields: `Time`, `KeyMap`, `Styles`, `Focused`, `Selected`

### Key Methods
- `New(time.Time) Model`: Factory constructor
- `Init() tea.Cmd`: Interface method (returns nil)
- `Update(tea.Msg) (Model, tea.Cmd)`: Main update logic, handles key events
- `View() string`: Renders monthly calendar grid as string
- `SetFocus(Focus)`: Sets focus zone
- `Blur()`: Sets `Focused = FocusNone`
- `SetTime(time.Time)`: Sets the displayed date
- `SelectDate()` / `UnselectDate()`: Toggle selection state
- `LastWeek()` / `NextWeek()`: Move ±7 days
- `Yesterday()` / `Tomorrow()`: Move ±1 day
- `LastMonth()` / `NextMonth()`: Move ±1 month
- `LastYear()` / `NextYear()`: Move ±1 year

## Notable Algorithms / Named Patterns
- **Bubbletea Component Pattern**: Follows Charmbracelet's `tea.Model` interface convention (Init/Update/View) — the canonical pattern for all Bubbletea bubbles
- **Focus State Machine**: Three-tier focus system (HeaderMonth → HeaderYear → Calendar) navigated via Tab/Shift+Tab, each focus zone accepts different key inputs
- **Calendar Grid Algorithm**: The `View()` method computes a 7-column grid spanning multiple weeks by: (1) finding first day of month, (2) walking backward to last Sunday, (3) walking forward to first Sunday of next month, (4) rendering each day with appropriate styles based on selection state
- **Immutable Style Derivation**: Uses `lipgloss.Style.Copy().Inherit()` pattern to create variant styles without mutating originals

## Strengths
- Clean, well-structured implementation following Go idioms and Bubbletea conventions
- Comprehensive key binding support (vim-style hjkl + arrow keys + standard bindings)
- Proper separation of concerns: styles, keymaps, and state are separate structs
- Good test coverage with table-driven tests covering all navigation methods
- Good documentation with usage examples (basic, list integration, textinput integration)
- MIT licensed, simple dependency tree
- Uses lipgloss for consistent Charmbracelet ecosystem styling

## Weaknesses
- Single-file implementation (~360 lines) — no internal package organization
- No programmatic date limit (min/max date constraints)
- Limited view customization (no custom day formatter, fixed week headers "Su Mo Tu...")
- No mouse interaction support (keyboard-only)
- Uses `time.Time` for state which is mutable (mutations via `SetTime` etc.)
- No built-in localization/i18n for month/day names
- `FocusNone` doesn't actually prevent time mutations in `updateUp/updateDown/updateLeft/updateRight` — only blocks focus-zone-switching
- No support for date ranges (select start/end dates)
- Generated `focus_string.go` file committed (should be gitignored or generated at build)

## SugarCraft Mapping
This is a **Bubbletea port candidate** — a Go TUI framework that maps directly to the Charmbracelet ecosystem that SugarCraft ports.

| EthanEFung/bubble-datepicker | SugarCraft Lib | Notes |
|---|---|---|
| Datepicker component | `sugar-bits` (foundation components) | The datepicker is a TUI "bubble" component like textinput, list, viewport — all bubbles in the Charmbracelet ecosystem |
| Calendar grid rendering | `sugar-bits` `View()` rendering | Snapshot testing pattern applies here — calendar output is fixed-width text grid |
| Focus state machine (HeaderMonth/Year/Calendar) | `sugar-bits` focus handling | SugarCraft follows same Elm-style Model/Update/View with focused sub-components |
| `KeyMap` + key bindings | `candy-core` key handling | Charmbracelet's `bubbles/key` package — same pattern |
| `Styles` with lipgloss | `sugar-bits` styling | Lipgloss is used in both ecosystems |
| Two-way data binding example | `sugar-bits` component integration | The `textinput` example demonstrates compositing bubbles, same as SugarCraft components |

**Not a direct port target** — this is a user-contributed Bubbletea component, not upstream from Charmbracelet. SugarCraft ports from Charmbracelet directly (like `bubbletea` → would be `sugar-tea` if it existed). This repo is useful as a **reference implementation** for TUI component patterns.

## Analysis

**EthanEFung/bubble-datepicker** is a focused, single-purpose Go library that implements a monthly calendar datepicker as a component (bubble) for the Charmbracelet Bubbletea TUI framework. The implementation is clean and idiomatic, following Bubbletea's `tea.Model` interface pattern of `Init()`/`Update()`/`View()` methods. The architecture uses a three-tier focus system (HeaderMonth → HeaderYear → Calendar) to manage which key inputs are accepted at any given time, with vim-style navigation keys (hjkl + arrows) plus Tab for focus traversal.

The calendar grid algorithm in `View()` is the most complex part — it walks backward from the first of the month to find the starting Sunday, then walks forward to find the first Sunday after month end, building a 7-column grid where each cell is styled based on whether it's in the current month, is selected, or is focused. The use of `lipgloss` for styling is consistent with the Charmbracelet ecosystem. The library includes three examples showing integration with other bubbles (basic standalone, list integration, textinput with two-way binding) and has reasonable test coverage.

The main limitation is scope — this is a very simple datepicker with no date range support, no min/max constraints, no mouse interaction, no localization, and no programmatic access to individual day cells. It also has a design quirk where `FocusNone` doesn't actually prevent the date from being mutated via navigation keys, only prevents focus zone switching. That said, for its narrow use case it works correctly and provides a solid reference for building Bubbletea bubble components in SugarCraft.
