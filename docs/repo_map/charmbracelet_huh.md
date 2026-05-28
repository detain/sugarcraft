# charmbracelet/huh

## Metadata
- **URL:** https://github.com/charmbracelet/huh
- **Language:** Go
- **Stars:** ~4.9k+ (based on public repo metrics)
- **License:** MIT
- **Description:** A simple, powerful library for building interactive forms and prompts in the terminal. Part of the Charmbracelet ecosystem (Bubble Tea, Lip Gloss, etc.).

## Feature List
- **Multi-field form system** with pages (Groups) and fields
- **Field types:** Input (single-line text), Text (multi-line), Select (single choice), MultiSelect (multiple choice), Confirm (yes/no), FilePicker (file/folder selection), Note (display-only)
- **Dynamic forms** - fields can react to changes in other fields via `*Func()` methods with binding-based invalidation
- **Validation** - per-field validation functions with inline error display
- **Theming** - 5 built-in themes (Charm, Dracula, Catppuccin, Base16, Default) with full Lip Gloss style customization
- **Accessibility mode** - screen-reader-friendly prompts via `WithAccessible(true)`
- **Keyboard navigation** - vim-style (j/k), full keybinding system with help display
- **Filterable lists** - type-ahead filtering in Select/MultiSelect via `/`
- **Standalone or embedded** - can run forms standalone or integrate into Bubble Tea applications
- **Standalone spinner** - separate spinner package for indicating background activity
- **Auto-suggestions** for Input fields with autocomplete
- **External editor integration** for Text fields (opens $EDITOR)
- **Dark/light background detection** - automatic theme adaptation

## Key Classes and Methods

### Core Form Types

**Form** (`form.go`):
- `NewForm(groups ...*Group) *Form` — creates a new form
- `WithAccessible(bool) *Form` — enables screen-reader mode
- `WithTheme(Theme) *Form` — sets the form theme
- `WithKeyMap(*KeyMap) *Form` — sets keybindings
- `WithWidth(int) *Form` / `WithHeight(int) *Form` — dimensions
- `WithTimeout(time.Duration) *Form` — form timeout
- `Run() error` / `RunWithContext(context.Context) error` — executes the form
- `Get(string) any` / `GetString(string) string` / `GetInt(string) int` / `GetBool(string) bool` — retrieve field values by key
- State constants: `StateNormal`, `StateCompleted`, `StateAborted`

**Group** (`group.go`):
- `NewGroup(fields ...Field) *Group` — creates a group (form page)
- `Title(string) *Group` / `Description(string) *Group`
- `WithHide(bool) *Group` / `WithHideFunc(func() bool) *Group` — conditional group display
- `WithShowHelp(bool) *Group` / `WithShowErrors(bool) *Group`
- `UpdateFieldPositions() *Form` — updates field position state

**Field Interface** (all field types implement):
- `Value(*T) *Field` — bind to a variable
- `Key(string) *Field` — identifier for retrieval
- `Title(string) *Field` / `TitleFunc(func() string, bindings any) *Field`
- `Description(string) *Field` / `DescriptionFunc(...)`
- `Validate(func(T) error) *Field`
- `WithTheme(Theme) Field` / `WithKeyMap(*KeyMap) Field`
- `WithWidth(int) Field` / `WithHeight(int) Field`
- `WithPosition(FieldPosition) Field`
- `Run() error` / `RunAccessible(io.Writer, io.Reader) error`
- `Error() error` / `Skip() bool` / `Zoom() bool`
- `KeyBinds() []key.Binding` / `Focus() tea.Cmd` / `Blur() tea.Cmd`

### Field Types

**Input** (`field_input.go`) — single-line text:
- `NewInput() *Input`
- `Placeholder(string) *Input` / `PlaceholderFunc(...)`
- `EchoMode(EchoMode) *Input` — `EchoModeNormal`, `EchoModePassword`, `EchoModeNone`
- `CharLimit(int) *Input`
- `Suggestions([]string) *Input` / `SuggestionsFunc(...)`
- `Inline(bool) *Input` — title on same line as input
- Wraps `bubbles/v2/textinput.Model`

**Text** (`field_text.go`) — multi-line textarea:
- `NewText() *Text`
- `Lines(int) *Text` / `CharLimit(int) *Text`
- `ShowLineNumbers(bool) *Text`
- `ExternalEditor(bool) *Text` / `Editor(...string) *Text`
- Wraps `bubbles/v2/textarea.Model`

**Select[T comparable]** (`field_select.go`) — single selection:
- `NewSelect[T comparable]() *Select[T]`
- `Options(...Option[T]) *Select[T]` / `OptionsFunc(func() []Option[T], bindings any)`
- `Height(int) *Select[T]`
- `Filtering(bool) *Select[T]` — enable `/` filtering
- `Inline(bool) *Select[T]` — horizontal single-line selector
- Generic over any `comparable` type
- Wraps `bubbles/v2/viewport.Model`

**MultiSelect[T comparable]** (`field_multiselect.go`) — multi selection:
- `NewMultiSelect[T comparable]() *MultiSelect[T]`
- `Limit(int) *MultiSelect[T]` — max selections
- `Filterable(bool) *MultiSelect[T]`
- `SelectAll` / `SelectNone` keybindings

**Confirm** (`field_confirm.go`) — yes/no:
- `NewConfirm() *Confirm`
- `Affirmative(string) *Confirm` / `Negative(string) *Confirm`
- `WithButtonAlignment(lipgloss.Position) *Confirm`

**FilePicker** (`field_filepicker.go`) — file/folder browser:
- `NewFilePicker() *FilePicker`
- `AllowedTypes(...string) *FilePicker` — filter by extension
- `ShowHidden(bool) *FilePicker`
- `CurrentDirectory(string) *FilePicker`
- Height/filtering/keybinding support

**Note** (`field_note.go`) — display-only:
- `NewNote() *Note`
- `Next(bool) *Note` / `NextLabel(string) *Note`

### Support Types

**Option[T comparable]** (`option.go`):
- `NewOption(key string, value T) Option[T]`
- `NewOptions[T comparable](values ...T) []Option[T]` — convenience factory
- `.Selected(bool) Option[T]` — chainable

**Theme** (`theme.go`):
- `Theme` interface: `Theme(isDark bool) *Styles`
- `ThemeFunc(func(isDark bool) *Styles)` — adapter
- Predefined: `ThemeCharm`, `ThemeDracula`, `ThemeBase16`, `ThemeCatppuccin`
- `ThemeBase(bool) *Styles` — base styles to inherit

**Styles** (`theme.go`):
- `Styles` struct: `Form`, `Group`, `FieldSeparator`, `Blurred`, `Focused`, `Help`
- `FormStyles`: `Base lipgloss.Style`
- `GroupStyles`: `Base`, `Title`, `Description`
- `FieldStyles`: text input styles, select styles, multi-select styles, confirm styles, etc.

**KeyMap** (`keymap.go`):
- `NewDefaultKeyMap() *KeyMap`
- Nested keymaps for each field type: `Input`, `Text`, `Select`, `MultiSelect`, `Confirm`, `FilePicker`, `Note`
- Supports vim-style navigation (j/k/h/l), tab/shift-tab, enter, filtering (/), etc.

**Spinner** (`spinner/spinner.go`) — standalone package:
- `New() *Spinner`
- `Type(Type) *Spinner` — `Line`, `Dots`, `MiniDot`, `Jump`, `Points`, `Pulse`, `Globe`, `Moon`, `Monkey`, `Meter`, `Hamburger`, `Ellipsis`
- `Title(string) *Spinner`
- `Action(func()) *Spinner` / `ActionWithErr(func(context.Context) error) *Spinner`
- `Context(context.Context) *Spinner`
- `WithAccessible(bool) *Spinner`

**Eval[T]** (`eval.go`) — dynamic evaluation with caching:
- Used for `TitleFunc`, `DescriptionFunc`, `OptionsFunc`, etc.
- `shouldUpdate() (bool, uint64)` — checks if bindings changed
- `loadFromCache() bool` — load cached value
- `update(T)` — update value and clear loading state

**Accessor[T]** (`accessor.go`) — value binding:
- `Accessor[T]` interface: `Get() T`, `Set(T)`
- `PointerAccessor[T]` — wraps `*T`
- `EmbeddedAccessor[T]` — inline value storage

## Notable Algorithms / Named Patterns

- **Selector Pattern** (`internal/selector/selector.go`) — generic selection state machine for navigating groups and fields
- **Bubble Tea Model Pattern** — all components implement `tea.Model` interface (`Init()`, `Update(tea.Msg)`, `View() string`) for integration with Bubble Tea
- **ViewHook Pattern** (`internal/compat/compat.go`) — middleware for intercepting view rendering
- **Eval/Cache Invalidation** — hash-based binding change detection to conditionally update dynamic content
- **Viewport Scrolling** — scroll management for long lists (ensureCursorVisible algorithm)
- **Fluent Builder Pattern** — all types use method chaining for configuration
- **Tea.Batch / Tea.Sequence** — command batching for concurrent operations
- **Accessible Mode** — terminal prompt fallback that doesn't redraw the screen

## Strengths
- **Excellent Go idioms** — follows standard Bubble Tea patterns, proper error handling, context support
- **Generics** — Select and MultiSelect are generic over any `comparable` type
- **Comprehensive theming** — Lip Gloss provides full style customization
- **Dynamic forms** — reactively updates fields when bindings change
- **First-class accessibility** — dedicated accessible mode for screen readers
- **Keyboard-driven UX** — vim-style navigation with help display
- **Validation** — per-field validation with inline error display
- **Spinner package** — standalone loading indicator reusable independently
- **Clean separation** — Groups/Fields/Form hierarchy, internal packages hidden
- **Bubble Tea integration** — Form implements `tea.Model`, can embed in larger apps
- **Well-documented** — README has tutorial, field reference, dynamic forms example
- **Active ecosystem** — part of Charmbracelet which includes Bubble Tea, Lip Gloss, etc.

## Weaknesses
- **Go-only** — not cross-language, SugarCraft would need full PHP port
- **No auto-layout** — requires explicit width/height configuration
- **Complexity for simple use cases** — requires understanding Bubble Tea concepts even for basic prompts
- **Limited input types** — no date picker, color picker, slider, etc. (only text-based)
- **Keybindings not customizable per instance** — only global KeyMap
- **No built-in wizard/stepper** — groups act as pages but no built-in progress indication
- **Terminal-only** — no web/GUI alternative

## SugarCraft Mapping

| huh Feature | SugarCraft Lib | Notes |
|-------------|----------------|-------|
| Form + Groups + Fields | `sugar-forms` (hypothetical) | Core form infrastructure; Group = page, Field = input widget |
| Input / Text fields | `sugar-input` (extends `candy-shine`) | Single-line and multi-line text with validation |
| Select / MultiSelect | `sugar-select` / `sugar-chips` | Option selection with filterable lists |
| Confirm | `sugar-confirm` | Yes/No prompt, could merge into sugar-input |
| FilePicker | `sugar-file-picker` | File/folder browser |
| Theming | `candy-shine` | Lip Gloss analog for ANSI styling |
| Spinner | `sugar-spin` | Loading indicator |
| Bubble Tea integration | `candy-shell` | TUI framework foundation |
| KeyMap / Keyboard | `candy-core` | Keyboard handling utilities |
| Dynamic forms | sugar-forms | *Func pattern with binding invalidation |
| Validation | `candy-validate` (hypothetical) | Per-field validation |
| Eval/Cache | sugar-forms internal | Reactive update caching |

**Many-to-Many Mapping:**
- `huh/form.go` → `sugar-forms` (form runner, group management)
- `huh/group.go` → `sugar-forms`
- `huh/field_input.go` → `sugar-input`
- `huh/field_text.go` → `sugar-input` (text area)
- `huh/field_select.go` → `sugar-select`
- `huh/field_multiselect.go` → `sugar-chips` (multi-select)
- `huh/field_confirm.go` → `sugar-confirm`
- `huh/field_filepicker.go` → `sugar-file-picker`
- `huh/field_note.go` → `sugar-note` (display only)
- `huh/theme.go` → `candy-shine`
- `huh/keymap.go` → `candy-core` (keyboard handling)
- `huh/spinner/spinner.go` → `sugar-spin`
- `huh/field_filepicker.go` + `examples/filepicker/` → `sugar-file-picker`
- `huh/eval.go` → sugar-forms internal reactive system

## Analysis

**charmbracelet/huh** is a meticulously crafted TUI form library that exemplifies Go's strength in building terminal user interfaces. The library demonstrates several key architectural decisions that make it successful: first, its tight integration with Bubble Tea (the broader TUI framework from Charm) means it inherits a robust message-passing architecture, viewport management, and keyboard handling without reinventing the wheel. Second, its use of generics for Select and MultiSelect allows type-safe bindings to any comparable value type, not just strings. Third, the dynamic forms system with Eval/Cache provides an elegant reactive programming model where field content can depend on other fields' values.

The design philosophy prioritizes composability and consistency. Every field type follows the same pattern: constructor with `New*()`, configuration via fluent `*Func()` methods for dynamic content and regular `*()` methods for static content, and a consistent implementation of the Field interface. This predictability makes the library learnable and extensible. The theming system using Lip Gloss styles allows complete visual customization while maintaining sensible defaults.

The main trade-off is that this library is deeply tied to Go and the Bubble Tea ecosystem. Porting to PHP would require significant architectural decisions: the TEA (Terminal Emulator Architecture) pattern with message passing doesn't translate directly to PHP's synchronous execution model. SugarCraft would likely need to implement an event loop abstraction and potentially use ReactPHP for async operations. Additionally, the library's power and flexibility (dynamic forms, accessible mode, filtering, etc.) adds complexity that may exceed the needs of simpler CLI prompt use cases. The mapping shows SugarCraft would benefit from splitting concerns: a core form runner, individual field packages, and a theming system—similar to how huh organizes its code across multiple files and packages. The filepicker in particular shows a gap in SugarCraft's current offering that would need addressing for feature parity.
