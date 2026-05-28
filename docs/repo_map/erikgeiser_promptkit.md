# erikgeiser/promptkit

## Metadata
- **URL:** https://github.com/erikgeiser/promptkit
- **Language:** Go
- **Stars:** ~300-500 (GitHub API unavailable, repo appears moderately popular based on activity)
- **License:** MIT
- **Description:** A collection of common command line prompts for interactive programs. Each prompt comes with sensible defaults, re-mappable key bindings, and extensive customization options.

## Feature List

- **Selection Prompts**
  - Single-item selection with filter and pagination
  - Multi-item selection (select multiple items)
  - Generic type support for any Go type as choice values
  - Custom filtering functions (case-sensitive/insensitive)
  - Page size configuration with auto-pagination
  - Cursor looping (navigate from last to first and vice versa)

- **Text Input Prompts**
  - Single-line text input with editable default values
  - Multi-line textarea with auto-resize
  - Input validation (built-in `ValidateNotEmpty`, custom validators)
  - Hidden mode for password prompts
  - Auto-completion with common prefix detection
  - Character limit support
  - Input width limiting (horizontal scrolling viewport)

- **Confirmation Prompts**
  - Binary yes/no questions
  - Default value support (Yes, No, or Undecided)
  - Multiple built-in template styles (Arrow, Y/N)

- **Common Features**
  - Template-driven rendering using `text/template`
  - Lipgloss styling (ANSI-aware styling)
  - Custom key mappings (fully remappable)
  - Terminal-aware color profiles
  - Text wrapping modes (WordWrap, HardWrap, Truncate)
  - Result templates for final output formatting
  - Integration as bubbletea widgets

## Key Classes and Methods

### Root Package (`promptkit`)

**Source:** `erikgeiser/promptkit/promptkit.go:L1-L115`

- `UtilFuncMap()` — Returns `template.FuncMap` with helpers: `Repeat`, `Len`, `Min`, `Max`, `Add`, `Sub`, `Mul`, `Join`, `ToUpper`, `ToLower`, `TrimPrefix`, `TrimSuffix`
- `WordWrap(input string, width int) string` — Word wrap with forced breaks
- `HardWrap(input string, width int) string` — Hard wrap at column width
- `Truncate(input string, width int) string` — Truncate with ellipsis
- `ErrAborted` — Error returned when user aborts prompt

### Selection Module (`selection`)

**Source:** `erikgeiser/promptkit/selection/`

**Prompt Struct:**
- `Selection[T any]` — Main single-selection prompt config
  - `New[T any](prompt string, choices []T) *Selection[T]` — Factory
  - `RunPrompt() (T, error)` — Execute the prompt
  - Default filter: `FilterContainsCaseInsensitive[T]`
  - Default styles: `DefaultSelectedChoiceStyle`, `DefaultFinalChoiceStyle`

**Model Struct:**
- `Model[T any]` — Bubbletea model for single selection
  - `Init() tea.Cmd` — Initialize model, validate, setup templates
  - `Update(msg tea.Msg) (tea.Model, tea.Cmd)` — Handle key events
  - `View() tea.View` — Render the prompt
  - `Value() (T, error)` — Get selected value
  - `ValueAsChoice() (*Choice[T], error)` — Get choice with metadata

**Multi-Selection:**
- `MultiSelection[T any]` — Multi-selection config
  - `NewMulti[T any](prompt string, choices []T) *MultiSelection[T]`
  - `RunPrompt() ([]T, error)`
  - `MinSelections`, `MaxSelections` constraints
  - `PreSelected` function for pre-selecting items

- `MultiModel[T any]` — Bubbletea model for multi-selection
  - `Values() ([]T, error)` — Get all selected values
  - `ValuesAsChoices() ([]*Choice[T], error)` — Get choices in original order

**Choice:**
- `Choice[T any]` — Wraps a choice with string representation
  - `String string` — Display string
  - `Value T` — The actual value
  - `Index() int` — Position in original slice

**KeyMap:**
- `KeyMap` — Key bindings for single selection
  - `Down`, `Up`, `Select`, `Abort`, `ClearFilter`, `ScrollDown`, `ScrollUp`
  - `NewDefaultKeyMap()` — Sensible defaults
  - `validateKeyMap()` — Validates minimum required bindings

- `MultiKeyMap` — Key bindings for multi-selection
  - `Toggle` replaces `Select` for marking/unmarking

### TextInput Module (`textinput`)

**Source:** `erikgeiser/promptkit/textinput/`

**TextInput Prompt:**
- `TextInput` — Single-line text input config
  - `New(prompt string) *TextInput` — Factory
  - `RunPrompt() (string, error)` — Execute prompt
  - `Validate func(string) error` — Custom validation
  - `AutoComplete func(string) []string` — Auto-completion suggestions
  - `Hidden bool`, `HideMask rune` — Password mode

- `Model` — Bubbletea model for text input
  - `Value() (string, error)` — Get input value

**TextArea (Multi-line):**
- `TextArea` — Multi-line text input config
  - `NewArea(prompt string) *TextArea` — Factory
  - `RunPrompt() (string, error)` — Execute
  - `Height int` — Fixed height (0 = auto-expand)
  - `ShowLineNumbers bool` — Line number display

- `AreaModel` — Bubbletea model for textarea
  - Auto-resize behavior with `prepareAutoResize()` and `autoResizeInput()`

**KeyMap:**
- `KeyMap` — Full set of text editing key bindings
  - Movement: `MoveBackward`, `MoveForward`, `JumpToBeginning`, `JumpToEnd`
  - Deletion: `DeleteBeforeCursor`, `DeleteUnderCursor`, `DeleteWordBeforeCursor`, `DeleteAllAfterCursor`, `DeleteAllBeforeCursor`
  - Other: `AutoComplete`, `Paste`, `Clear`, `Reset`, `Submit`, `Abort`

**Auto-completion:**
- `AutoCompleteFromSlice(choices []string) func(string) []string` — Case-insensitive
- `CaseSensitiveAutoCompleteFromSlice` — Case-sensitive variant
- `commonPrefix(suggestions []string) string` — Find common prefix for auto-complete

### Confirmation Module (`confirmation`)

**Source:** `erikgeiser/promptkit/confirmation/`

- `Confirmation` — Yes/No prompt config
  - `New(prompt string, defaultValue Value) *Confirmation`
  - `RunPrompt() (bool, error)` — Returns true for Yes, false for No

- `Model` — Bubbletea model for confirmation

- `Value` — `*bool` type alias with constants
  - `Yes`, `No`, `Undecided` — Possible states

- `KeyMap` — Confirmation key bindings
  - `Yes`, `No` — Direct yes/no keys
  - `SelectYes`, `SelectNo` — Arrow key selection
  - `Toggle` — Toggle between options
  - `Submit`, `Abort`

**Built-in Templates:**
- `TemplateArrow` — Shows `▸Yes  No` style indicator
- `TemplateYN` — Classic `[Y/n]` style

## Notable Algorithms / Named Patterns

### Generic Type-Safe Prompts
```go
// Source: erikgeiser/promptkit/selection/prompt.go:L214
func New[T any](prompt string, choices []T) *Selection[T]
```
Uses Go 1.18+ generics to provide type-safe selection without interface{} boxing.

### BubbleTea Model Pattern
```go
// Source: erikgeiser/promptkit/selection/model.go:L54-L97
func (m *Model[T]) Init() tea.Cmd { ... }
func (m *Model[T]) Update(msg tea.Msg) (tea.Model, tea.Cmd) { ... }
func (m *Model[T]) View() tea.View { ... }
```
Implements the standard Charmbracelet BubbleTea MVC pattern (Model/Update/View).

### Template-Based Styling
```go
// Source: erikgeiser/promptkit/selection/model.go:L99-L128
func (m *Model[T]) initTemplate() (*template.Template, error) {
    tmpl := template.New("view")
    tmpl.Funcs(termenv.TemplateFuncs(m.ColorProfile))
    tmpl.Funcs(m.ExtendedTemplateFuncs)
    tmpl.Funcs(promptkit.UtilFuncMap())
    // ... custom funcs: IsScrollDownHintPosition, Selected, Unselected
    return tmpl.Parse(m.Template)
}
```
Template functions are composed from multiple sources (termenv, promptkit, custom).

### Auto-Pagination with Binary Search
```go
// Source: erikgeiser/promptkit/selection/model.go:L258-L288
func (m *Model[T]) forceUpdatePageSizeForHeight() {
    maxAcceptablePageSize := len(m.choices)
    // Try preferred page size first
    m.PageSize = maxAcceptablePageSize
    // If it doesn't fit, brute force a fitting page size
    for m.PageSize = 1; m.PageSize <= maxAcceptablePageSize; m.PageSize++ {
        // ... check if view fits, if not decrement and use that
    }
}
```

### Common Prefix Auto-Complete
```go
// Source: erikgeiser/promptkit/textinput/autocomplete.go:L78-L99
func commonPrefix(suggestions []string) string {
    sort.Strings(suggestions) // O(n*log(n))
    first, last := suggestions[0], suggestions[len(suggestions)-1]
    // Only compare first and last after sort
    for i := 0; i < len(first); i++ {
        if last[i] != first[i] {
            return first[:i]
        }
    }
    return first
}
```
Uses sorting to reduce prefix comparison to just first and last elements.

### Choice String Representation Priority
```go
// Source: erikgeiser/promptkit/selection/choice.go:L23-L40
func newChoice[T any](item T) *Choice[T] {
    switch i := any(item).(type) {
    case Choice[T]:  // Explicit Choice type
    case *Choice[T]: // Pointer to Choice
    case string:     // Native string
    case fmt.Stringer: // Anything with String() method
    default:         // Fallback to fmt.Sprintf
    }
}
```
Priority order for choosing display string: explicit field > string > Stringer interface > default formatting.

### Filtered and Paged Choices
```go
// Source: erikgeiser/promptkit/selection/model.go:L396-L418
func (m *Model[T]) filteredAndPagedChoices() ([]*Choice[T], int) {
    choices := []*Choice[T]{}
    for _, choice := range m.choices {
        if m.Filter != nil && !m.Filter(m.filterInput.Value(), choice) {
            continue // Skip filtered out
        }
        available++
        if m.PageSize > 0 && (len(choices) >= m.PageSize || ignored < m.scrollOffset) {
            ignored++ // Skip for pagination
            continue
        }
        choices = append(choices, choice)
    }
    return choices, available
}
```

## Strengths

- **Generic Type Safety:** Go generics provide compile-time type safety without runtime interface{} boxing, unlike most other prompt libraries
- **BubbleTea Integration:** Built on Charmbracelet's excellent bubbletea framework, providing solid TUI foundation
- **Extensive Customization:** Templates, keymaps, styles, validation, filtering, pagination — every aspect is customizable
- **Template System:** Uses Go's `text/template` with rich helper functions, enabling complex custom UIs
- **Multiple Prompt Types:** Single selection, multi-selection, text input, textarea, confirmation — comprehensive coverage
- **Clean Separation of Config and Model:** `Selection`/`Model` split mirrors MVC pattern, easy to embed as widget
- **Auto-Completions:** Smart common-prefix detection for single matches, multiple suggestions for ambiguous
- **Auto-Pagination:** Automatically adjusts page size based on terminal height
- **Well-Documented:** README with examples, inline documentation on all public types
- **MIT License:** Permissive, commercial-friendly

## Weaknesses

- **Pre-1.0 Instability:** API may change significantly before v1.0.0 (as noted in README)
- **Dependency on BubbleTea:** Heavy dependency on charm.land/bubbletea, which itself depends on several other packages
- **No Async Input:** Uses synchronous terminal I/O via bubbletea — no support for async operations
- **Limited Terminal Support:** Relies on termenv for ANSI escape sequences; some terminals may have issues
- **No Input History:** TextInput lacks history/recall functionality common in shells
- **No Mouse Support:** Selection and other prompts don't support mouse interaction
- **Single-Threaded:** All prompts run synchronously in a single tea.Program
- **No Built-in Help:** Users must remember keybindings or implement custom help views
- **Integer Type Constraint:** Uses `int` for dimensions which may cause issues on 32-bit systems

## SugarCraft Mapping

| PromptKit Feature | SugarCraft Library | Notes |
|-----------------|---------------------|-------|
| Selection prompt (`Selection[T]`) | `sugar-prompt` | Core selection TUI component, maps to `SugarCraft\Prompt\Selection` |
| Multi-selection prompt (`MultiSelection[T]`) | `sugar-prompt` | Multi-select variant |
| TextInput prompt (`TextInput`) | `sugar-prompt` | Text input component |
| TextArea prompt (`TextArea`) | `sugar-prompt` | Multi-line text area |
| Confirmation prompt (`Confirmation`) | `sugar-prompt` | Yes/no prompt |
| Template-based rendering | `sugar-prompt` | Uses template system similar to SugarCraft's view templates |
| KeyMap/keybindings | `sugar-prompt` | Key binding configuration |
| Lipgloss styling | `candy-shine` | Style system for terminal colors/formatting |
| BubbleTea Model pattern | `candy-core` | MVC pattern implementation |
| WrapMode (WordWrap/HardWrap/Truncate) | `sugar-bits` | Text wrapping utilities |

**Mapping Notes:**
- **sugar-prompt** would be the primary target — this library is essentially a prompt/question framework
- The generic `Selection[T]` / `MultiSelection[T]` pattern could inform SugarCraft's type-safe component design
- Template-based rendering is similar to how SugarCraft uses PHP templates for rendering
- The Bubbletea `Model` interface pattern (Init/Update/View) mirrors the `Model` contract mentioned in AGENTS.md for TUI widgets

## Analysis

**Erik Geiser's PromptKit** is a sophisticated Go library for building interactive command-line prompts. It stands out from similar libraries (like `survey`, `goyes`, or `promptui`) through its use of Go generics for type-safe prompts without runtime type assertions. The library provides four main prompt types: selection (single and multi), text input (single and multi-line), and confirmation — all built on top of the excellent Charmbracelet Bubbletea TUI framework.

The architecture follows a clean MVC pattern where each prompt type has a `*Prompt` config struct containing all customization options, and a separate `*Model` struct implementing the Bubbletea `tea.Model` interface with `Init()`, `Update()`, and `View()` methods. This separation allows prompts to be used standalone via `RunPrompt()` or embedded as widgets within larger Bubbletea applications. The use of Go's `text/template` for rendering provides enormous flexibility — users can completely redefine prompt appearance through templates while the library provides helper functions for styling, wrapping, and formatting.

The library excels in customization depth: every aspect from key bindings to color profiles to text wrapping behavior is configurable. The built-in filtering, pagination, and auto-completion features are well-implemented, with pagination even auto-adjusting based on terminal height. Notable implementation details include the clever `commonPrefix` algorithm using sorted array endpoints, the auto-pagination binary search, and the choice type string representation priority chain.

For SugarCraft, this library would map most directly to `sugar-prompt` — a prompt/question TUI component. The generic `Selection[T]` pattern is particularly interesting as it demonstrates how to achieve compile-time type safety with Go generics in a TUI context. The template-based rendering approach could inform SugarCraft's view system, and the clean separation between configuration and model objects could serve as a reference for the immutable `with*()` pattern mentioned in the codebase conventions.
