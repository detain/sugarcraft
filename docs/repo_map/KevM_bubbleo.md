# KevM/bubbleo

## Metadata
- **URL**: https://github.com/KevM/bubbleo
- **Language**: Go
- **Stars**: 69
- **License**: MIT License
- **Description**: BubbleO is a collection of components for the excellent terminal UI tool [bubbletea](https://github.com/charmbracelet/bubbletea). Includes: NavStack, Breadcrumbs, and Menu.

## Feature List

- **NavStack**: Stack-based hierarchical navigation system for managing component transitions
- **Menu**: List-based menu component that wraps bubble/list, pushing selected choices onto the navigation stack
- **Breadcrumb**: Visual breadcrumb trail showing current position in the navigation hierarchy
- **Shell**: Composite component encapsulating NavStack and Breadcrumb for unified navigation experience
- **Window**: Dimension management for layout calculations with offset support (TopOffset, SideOffset)
- **Styles**: Default lipgloss styling presets for consistent visual theming
- **Closable Interface**: Resource cleanup pattern when items are popped from navigation stack
- **Message-driven Navigation**: Push/Pop navigation via tea.Msg messages (PushNavigation, PopNavigation)
- **tea.Sequence Support**: Ordered command execution for coordinated pop-before-push operations

## Key Classes and Methods

### navstack.Model
- `New(w *window.Model)` — Creates a new navigation stack with window dimensions
- `Push(item NavigationItem) tea.Cmd` — Pushes item onto stack, calls Closable.Close() on previous top, sends WindowSizeMsg to new top
- `Pop() tea.Cmd` — Pops top item, calls Closable.Close() if implemented, sends WindowSizeMsg to new top, or Quit if empty
- `Clear() error` — Pops all items, aggregates Closable.Close() errors
- `Top() *NavigationItem` — Returns pointer to topmost navigation item
- `StackSummary() []string` — Returns list of titles for breadcrumb rendering
- `Update(msg tea.Msg) tea.Cmd` — Routes messages to topmost item, handles PushNavigation/PopNavigation internally

### navstack.NavigationItem
- `Title string` — Display title for breadcrumbs
- `Model tea.Model` — The actual component model
- `Init() tea.Cmd` — Delegates to Model.Init()
- `Update(msg tea.Msg)` — Delegates to Model.Update()
- `View() string` — Delegates to Model.View()

### navstack.Messages
- `PushNavigation{Item NavigationItem}` — Message to push onto stack
- `PopNavigation{}` — Message to pop from stack
- `ReloadCurrent{}` — Message to reload current item
- `PushNavigationCmd(item) / PopNavigationCmd()` — Command constructors via utils.Cmdize

### menu.Model
- `New(title string, choices []Choice, selected *Choice)` — Creates menu with choices
- `SetChoices(choices []Choice, selected *Choice)` — Updates menu choices and selection
- `SelectChoice(choice Choice) (Model, tea.Cmd)` — Returns command to push choice onto navstack
- `SetStyles(s MenuStyles)` — Customizes lipgloss styles (passthrough to bubble/list)
- `SetShowTitle(display bool)` — Shows/hides menu title
- `SetSize(w tea.WindowSizeMsg)` — Updates menu dimensions
- `handleKeyMsg()` — Handles enter/esc/?/q keys

### menu.Choice
- `Title string` — Display title
- `Description string` — Item description
- `Model tea.Model` — Model to push onto stack when selected

### breadcrumb.Model
- `New(n *navstack.Model)` — Creates breadcrumb linked to navstack
- `View() string` — Renders "title1 > title2 > title3" trail using lipgloss frame

### shell.Model
- `New()` — Creates shell with window, navstack, and breadcrumb wired together
- `Init() tea.Cmd` — Calculates frame offsets and sends initial WindowSizeMsg
- `Update(msg tea.Msg)` — Delegates to Navstack.Update()
- `View() string` — Renders breadcrumb + navstack using lipgloss

### window.Model
- `New(width, height, topOffset, sideOffset int)` — Creates window with dimensions
- `GetWindowSizeMsg() tea.WindowSizeMsg` — Returns size minus offsets for child components

### styles.Styles
- `ListTitleStyle` — Margin-left, foreground color 230, bold
- `BreadCrumbFrameStyle` — Foreground color 229, margin 1
- `HelpStyle` — Padding 1,2

### utils.Utils
- `Cmdize[T any](t T) tea.Cmd` — Generic utility to wrap any value in a tea.Cmd

## Notable Algorithms / Named Patterns

### Stack-based Hierarchical Navigation
The core pattern is a classic stack data structure where:
1. Components are pushed onto the stack via `PushNavigation` message
2. The topmost item's `Update()` and `View()` receive all traffic
3. Popping sends the item below a `WindowSizeMsg` to recalculate layout
4. If stack empties, `tea.Quit` is automatically sent

### Closable Interface Pattern
```go
type Closable interface {
    Close() error
}
```
Items implementing `Closable` have `Close()` called when popped, enabling resource cleanup (e.g., closing files, canceling goroutines, releasing handles).

### Sequential Command Ordering
Uses `tea.Sequence(pop, cmd)` instead of `tea.Batch` to ensure pop executes before subsequent command, ensuring proper state: pop first → navstack updates → new top gets message.

### Cmdize Pattern
```go
func Cmdize[T any](t T) tea.Cmd {
    return func() tea.Msg { return t }
}
```
Type-safe wrapper converting any value into a `tea.Cmd` without type assertions.

### Offset-based Layout
The `window.Model` tracks offsets (TopOffset, SideOffset) to allow parent components (breadcrumbs) to reserve screen space before child components render. Subtracts offsets from incoming `WindowSizeMsg` before passing to children.

### Tea.Model Interface
All components implement `tea.Model` (Init/Update/View) from [charmbracelet/bubbletea](https://github.com/charmbracelet/bubbletea), following the Elm-inspired architecture.

## Strengths

- **Clean separation of concerns**: NavStack, Breadcrumb, Menu, Shell are distinct packages with single responsibilities
- **Composability**: Shell wraps NavStack + Breadcrumb; Menu pushes to NavStack; components can be used independently
- **Resource cleanup**: Closable interface ensures proper teardown when items are popped
- **Elm architecture**: Follows proven tea.Model pattern from bubbletea ecosystem
- **Message-driven**: Clean message-based navigation that integrates with bubbletea's message passing
- **Theming support**: Styles struct allows customization via lipgloss
- **Example-rich**: Two comprehensive examples (simple, deeper) demonstrate multi-level navigation
- **MIT license**: Permissive licensing for integration
- **Minimal dependencies**: Only depends on bubbletea, bubbles, lipgloss

## Weaknesses

- **Single-threaded stack**: No parallel navigation paths, only push/pop linear navigation
- **No back button management**: ESC key handling is implementation-dependent (menu has it, shell doesn't natively)
- **Limited error handling**: Closable.Close() errors are collected but not surfaced to user
- **No state persistence**: Navigation state is lost on quit; no save/restore mechanism
- **Tight bubbletea coupling**: Hard dependency on tea.Model interface limits use with other TUI frameworks
- **No animation/transition support**: Direct View() output, no animated stack transitions
- **Window size propagation**: Relies on tea.WindowSizeMsg being properly forwarded through Update chain

## SugarCraft Mapping

The architecture maps well to SugarCraft's TUI component philosophy:

| bubbleo Component | SugarCraft Library | Mapping Rationale |
|-------------------|---------------------|-------------------|
| `navstack.Model` (stack management, push/pop) | `sugar-prompt` or `candy-shine` | Navigation model with state machine patterns, message-driven updates |
| `menu.Model` (list selection) | `sugar-bits` (base components) | Menu/select widget building on bubbles/list |
| `breadcrumb.Model` (trail rendering) | `sugar-bits` or `candy-shine` | View component rendering hierarchical context |
| `shell.Model` (composite shell) | `candy-shell` (shell wrapper) | Composite component managing child views |
| `window.Model` (dimension management) | `candy-core` | Layout/dimension tracking utilities |
| `styles`, `lipgloss` usage | `candy-shine` | Lipgloss-based styling/theme system |
| `tea.Model` interface pattern | All SugarCraft libs | Immutable + fluent `Model` pattern with Init/Update/View |
| `Closable` interface | Resource cleanup patterns | Similar to disposal/cleanup patterns in view components |
| `Cmdize` utility | `SugarCraft\Core\Utils` | Message/command factory utilities |

**Many-to-many mapping notes**:
- `navstack` → `sugar-prompt` (navigation metaphor), `candy-shell` (view composition)
- `menu` → `sugar-bits` (list widget), `sugar-charts` (item selection from collections)
- `breadcrumb` → could be standalone breadcrumb lib or part of `sugar-prompt`
- `shell` → `candy-shell` wraps nav + breadcrumb exactly as bubbleo's shell wraps navstack + breadcrumb

## Analysis

**KevM/bubbleo** is a focused Go library providing navigational primitives for the bubbletea TUI framework. The central insight is the NavStack—a stack data structure where components are pushed and popped, with only the top component receiving Update/View calls. This pattern elegantly solves hierarchical navigation in terminal applications where users drill down through menus, sub-menus, and detail views, then pop back up the hierarchy.

The library demonstrates excellent composition: NavStack handles the stack mechanics, Breadcrumb visualizes the path, Menu provides the selection interface, and Shell unites them. The Closable interface is particularly well-designed—resources attached to a view (file handles, database connections, subscription handles) are cleanly released when the view is popped, preventing memory leaks in long-running applications. The use of `tea.Sequence` for ordered command execution ensures that navigation state is consistent before passing messages to the next component.

The main limitation is the purely linear navigation—there's no support for multi-path navigation, tabs, or parallel navigation stacks. The responsibility for routing and key handling falls on each component's Update method, which can lead to boilerplate. However, for applications that genuinely have a hierarchical drill-down pattern (configuration wizards, file browsers, multi-step forms), bubbleo provides a clean, well-tested foundation. The two examples (simple single-level, deeper three-level) comprehensively demonstrate the patterns, making it easy to understand how to build custom components that integrate with the navigation stack.
