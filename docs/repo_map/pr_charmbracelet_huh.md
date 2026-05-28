# Second-Stage Ecosystem Intelligence Report: charmbracelet/huh

## Metadata
- **Report Date:** 2026-05-27
- **Repository:** https://github.com/charmbracelet/huh
- **Language:** Go
- **Stars:** ~7,000+
- **License:** MIT
- **Version Analyzed:** v1.0.0 through v2.0.3
- **Source:** Issues, PRs, Discussions, Commits

---

## 1. Repository Overview

**charmbracelet/huh** is a TUI form library forming part of the Bubble Tea ecosystem. It provides multi-field forms with pages (Groups), diverse field types (Input, Text, Select, MultiSelect, Confirm, FilePicker, Note), validation, theming, keyboard navigation, dynamic reactive forms, and accessibility mode.

**Key Statistics:**
- 76 open issues (as of analysis)
- 60 contributors
- 17 releases
- Latest: v2.0.3 (March 2026)

**Ecosystem Position:** Core form infrastructure for Bubble Tea applications. Thin wrapper around `bubbles/v2/textinput`, `bubbles/v2/viewport`, `bubbles/v2/textarea`. Integrates with `lipgloss/v2` for styling.

---

## 2. Existing SugarCraft Mapping

From `repo_map/charmbracelet_huh.md`:

| huh Feature | SugarCraft Lib | Notes |
|-------------|----------------|-------|
| Form + Groups + Fields | `sugar-forms` (hypothetical) | Core form infrastructure |
| Input / Text fields | `sugar-input` (extends `candy-shine`) | Text with validation |
| Select / MultiSelect | `sugar-select` / `sugar-chips` | Option selection |
| Confirm | `sugar-confirm` | Yes/No prompt |
| FilePicker | `sugar-file-picker` | File/folder browser |
| Theming | `candy-shine` | ANSI styling |
| Spinner | `sugar-spin` | Loading indicator |
| Bubble Tea integration | `candy-shell` | TUI framework |
| Dynamic forms | sugar-forms | `*Func` pattern |
| Validation | `candy-validate` (hypothetical) | Per-field validation |

---

## 3. Previously Identified Gaps

The first-pass analysis identified:
- No auto-layout (requires explicit width/height)
- Complexity for simple use cases
- Limited input types (no date/color/slider)
- Keybindings not customizable per instance
- No built-in wizard/stepper
- Terminal-only

---

## 4. High-Signal Open Issues

### #761: Proposal: Declarative YAML form generator (charm-form)
**Author:** junhinhow (April 2026)
**State:** Open - Feature Proposal

**Summary:** Building CLI forms requires Go knowledge. Proposal for YAML-based form definition that outputs interactive forms powered by huh.

**Key Details:**
```
# YAML spec would define forms, output JSON
charm-form run deploy.yaml
# → Interactive huh form appears
# → Outputs JSON with answers
```

**Supported Field Types:** input, text, select, multiselect, confirm, filepicker

**SugarCraft Relevance:** HIGH - This represents a significant gap. A declarative form definition layer (YAML/JSON) would enable non-programmers to create forms. SugarCraft should consider:
1. A parallel declarative layer (PHP array or JSON config)
2. Low-code form builder pattern
3. Form schema validation

**Strategic Opportunity:** SugarCraft could implement a native PHP associative array form definition (no external YAML dependency) that compiles to form objects.

---

### #769: Select/Multiselect long-option-lines issue
**Author:** onyxraven (April 2026)
**State:** Open

**Summary:** When options exceed field width, truncation creates multi-line items that break viewport scrolling. User built custom truncation because built-in truncates incorrectly.

**Key Quote:**
> "Some things to think about adding as features might be truncation style (eg `...`), and horizontal scroll (ala fzf)"

**SugarCraft Relevance:** MEDIUM - Viewport/scrolling handling will be shared infrastructure. Truncation styles and horizontal scroll would be valuable features.

---

### #740: Set focus to form field if automatically filled
**Author:** dennis-dko (February 2026)
**State:** Open

**Summary:** Form fields filled automatically via env variables are focused but user must tab through all to reach submit.

**SugarCraft Relevance:** HIGH - Pre-populated fields pattern is common (CLI tools with `--name` flags). SugarCraft should:
- Add option to skip auto-filled fields in tab order
- Auto-advance to first empty field on render
- Consider `autofocus` property

---

### #745: Cannot use themes anymore in v2
**Author:** hramrach (March 2026)
**State:** Open

**Summary:** v2 broke theme API completely. Built-in themes now require `bool` parameter, return `*Styles` not `Theme`, and Theme is now an interface.

**Before:**
```go
.WithTheme(huh.ThemeCharm())
```

**After (v2):**
```go
.WithTheme(huh.ThemeCharm(isDark)) // isDark parameter required
// OR implement interface:
type myTheme struct{}
func (t myTheme) Theme(isDark bool) *huh.Styles { ... }
```

**SugarCraft Relevance:** CRITICAL - API breaking changes cause significant migration pain. SugarCraft should:
- Use semantic versioning strictly
- Provide migration tools/scripts
- Maintain backward compatibility layers
- Document breaking changes exhaustively

---

### #718: Add option to skip TTY initialization for fully-hidden forms
**Author:** tetienne (December 2025)
**State:** Open

**Summary:** `Form.Run()` fails with "could not open a new TTY" when all groups are hidden via `.WithHide()`. Users must duplicate hide conditions to guard Run() calls.

**SugarCraft Relevance:** MEDIUM - Forms that may have no visible fields are a real use case (CI environments, scripted tools). SugarCraft should detect when all fields/groups are hidden and return gracefully.

---

### #770: V2 Regression: Blurred styles no longer apply on input fields
**Author:** seanamos (April 2026)
**State:** Open

**Summary:** In v1, blurred input field styles could be customized. In v2, only focused styles are applied (incomplete port).

**Code Evidence:**
```go
// v2 field_input.go - only sets focused styles
i.textinput.PlaceholderStyle = styles.TextInput.Placeholder
// Missing: blurred styles not set

// v1 had all styles set properly
i.textinput.TextStyle = styles.TextInput.Text
```

**SugarCraft Relevance:** HIGH - Maintaining full style coverage for all field states is essential. SugarCraft must ensure:
- Focused, blurred, disabled, error, readonly states all styled
- Regression test coverage for style application

---

## 5. Important Closed Issues

### #429: Select options disappear when other options wrap
**Author:** seeseemelk (October 2024)
**State:** Closed (March 2025)
**Labels:** bug, select

**Root Cause:** Viewport class assumes lines don't wrap when calculating visible content. Scroll position calculations broken with wrapped lines.

**Fix:** PR #569 addressed multi-line items and scrolling.

**Lesson:** Viewport assumption of no-wrapping is a fundamental architectural constraint. SugarCraft must account for wrapped content in scrollable areas.

---

### #655: don't run validation on blur
**Author:** leandergangso (May 2025)
**State:** Closed (merged PR #725)
**Labels:** bug

**Summary:** Validation on blur prevented backward navigation (Shift+Tab) because validation errors blocked field transitions.

**Before Fix:** Blur triggered validation, which blocked going back to previous fields if current field had error.

**After Fix (PR #725):** Validation only runs on explicit advance (Enter), not on blur. This allows Shift+Tab to work regardless of field validity.

**SugarCraft Relevance:** CRITICAL - Validation must not block backward navigation. Validation timing is a key UX decision:
- Validate on advance only (huh's new behavior) - more permissive
- Validate on blur - stricter but blocks back-nav
- SugarCraft should default to validate-on-advance with option for stricter mode

---

### #281: Keypresses ignored upon reaching 2nd form
**Author:** patricklatorre (June 2024)
**State:** Closed (February 2025)
**Labels:** bug

**Summary:** Running multiple forms sequentially, first keystrokes on second form are ignored. Bug only on Windows.

**Root Cause:** Windows sends `\t` and `\r` runes with KeyTab/KeyEnter messages. Text input processed these before huh could handle them.

**Workaround:** Clean key messages on Windows:
```go
//go:build windows
func CleanKeys(msg tea.Msg) tea.Msg {
    switch msg := msg.(type) {
    case tea.KeyMsg:
        if slices.Contains(cleanKeyTypes, msg.Type) {
            msg.Runes = []rune{} // Remove runes
        }
    }
    return msg
}
```

**SugarCraft Relevance:** HIGH - Cross-platform keyboard handling is notoriously difficult. SugarCraft must:
- Test extensively on Windows, macOS, Linux
- Handle edge cases with modifier keys
- Consider terminal type detection

---

### #191: Shift-tab functionality impossible with dynamic forms
**Author:** iloveicedgreentea (April 2024)
**State:** Closed (PR #285)
**Labels:** bug

**Summary:** Dynamic forms with validation block Shift+Tab because blur triggers validation.

**User Pain Point:**
> "If the validation for a field fails, you can't move off the field. This runs counter to the way HTML forms work and is a pretty frustrating experience for users."

**PR #285:** Skip validation when going to previous field.

**SugarCraft Relevance:** CRITICAL - HTML form behavior is user expectation. SugarCraft should validate only on advance, never on blur/backward nav.

---

## 6. Recurring Pain Points

### A. Validation Blocking Navigation
**Frequency:** 5+ issues (#[655, #191, #120, #315, #540])
**Pattern:** Validation on blur/change blocks backward navigation
**Impact:** Poor UX, users trapped on invalid fields

**Maintainer Response:** PR #725 fixed by removing blur validation. But this was a long-standing issue.

**SugarCraft Directive:** Design validation to never block backward navigation. Validate on advance/submit only.

---

### B. Terminal.app Rendering on Mac
**Frequency:** 3+ issues (#[631, #341])
**Pattern:** Line duplication, screen corruption in macOS Terminal.app
**Root Cause:** Specific commit (fd4724c71710a7786d9aa5de74d1c1613284546f) introduced rendering bug

**Manifestations:**
- Lines duplicate and accumulate on each navigation
- Window resize causes wild behavior
- Persisted across multiple versions

**SugarCraft Relevance:** MEDIUM - Terminal-specific rendering bugs are endemic. SugarCraft should:
- Test on macOS Terminal, iTerm2, kitty, Windows Terminal, VS Code terminal
- Have escape hatch for dumb terminals (TERM=dumb → accessible mode)

---

### C. Multi-Form Keypress Interference
**Frequency:** 2+ issues (#[278, #281])
**Pattern:** Internal messages (`nextGroupMsg`, `nextFieldMsg`) not scoped to specific form instances
**Impact:** Multiple forms in same TUI interfere with each other

**User Workaround:** None clean - had to call `form.NextField(); form.PrevField()` in Init()

**SugarCraft Relevance:** MEDIUM - Forms should be isolated. SugarCraft should:
- Namespace all internal messages with form instance ID
- Avoid global state in form system

---

### D. Viewport Line Wrapping
**Frequency:** 4+ issues (#[429, #485, #573, #769])
**Pattern:** Viewport assumes single-line content; breaks with wrapped text
**Impact:** Options disappear, scroll position wrong, content cut off

**Root Cause:** Viewport height calculation: `cursorPos / viewportHeight` assumes each option = 1 line.

**SugarCraft Relevance:** HIGH - Wrapped content handling is fundamental. SugarCraft's viewport/ scrolling components must account for dynamic line heights.

---

### E. Theme API Breaking Changes (v2)
**Frequency:** Ongoing (#[745, #770, #320])
**Pattern:** Theme changed from struct to interface; all functions changed signatures
**Impact:** Large migration burden, custom themes break

**Specific Breaks:**
1. `Theme` struct → `ThemeFunc` interface
2. `huh.ThemeCharm()` → `huh.ThemeCharm(isDark bool)`
3. Return type `*Theme` → `*Styles`
4. Field-level `WithAccessible()` removed

**SugarCraft Relevance:** CRITICAL - Avoid breaking APIs. If changes needed:
- Provide migration tools
- Maintain deprecated layer
- Version with clear upgrade guides

---

## 7. Frequently Requested Features

### A. Filterable for Select (Discussion #559)
**Status:** Approved, implemented in MultiSelect, requested for Select
**User Need:** Type-ahead filtering via `/` key should be toggleable
**Alternative:** Users currently use MultiSelect with limit=1 for single selection with filtering

**SugarCraft Relevance:** MEDIUM - Filterable select is common pattern. SugarSelect should have `withFilterable()` method.

---

### B. Circular Selection (Issue #356)
**Status:** Closed (implemented March 2025)
**Feature:** Navigation wraps at ends - going down from last option shows first option
**Implementation:** Added in v2

**SugarCraft Relevance:** LOW - Simple feature, already implemented upstream.

---

### C. Layout System (PRs #214, #274)
**Status:** Implemented
**Features:**
- `LayoutDefault` - one group at a time
- `LayoutColumns(n)` - split groups into columns
- `LayoutGrid(rows, cols)` - 2D grid
- `LayoutStack` - vertical stack for narrow terminals

**API Evolution:**
```go
// Initial proposal
form.WithColumns(2)

// Final API
form.WithLayout(huh.LayoutColumns(2))
form.WithLayout(huh.LayoutGrid(2, 2))
form.WithLayout(huh.LayoutAuto) // auto-fit
```

**SugarCraft Relevance:** HIGH - Layout system is valuable for forms. SugarCraft should support:
- Column layouts
- Grid layouts
- Auto-layout based on terminal width
- Stack for narrow terminals

---

### D. Dynamic Forms with `*Func()` Pattern (PR #233)
**Status:** Implemented (July 2024)
**Pattern:**
```go
huh.NewSelect[string]().
    TitleFunc(func() string {
        return "State" // or "Province" for Canada
    }, &country).
    OptionsFunc(func() []huh.Option[string] {
        return fetchStatesForCountry(country)
    }, &country)
```

**SugarCraft Relevance:** HIGH - Reactive/dynamic forms are essential. SugarCraft should support:
- `withTitleFunc(callable, binding)`
- `withOptionsFunc(callable, binding)`
- `withDescriptionFunc(callable, binding)`
- Binding invalidation with hash-based cache

---

### E. YAML/Declarative Forms (Issue #761)
**Status:** Proposal stage
**External POC:** https://github.com/junhinhow/charm-form

**SugarCraft Relevance:** HIGH - Declarative form definition would lower barrier to entry. SugarCraft could support:
```php
// Native PHP array config (no YAML dependency)
$form = Form::fromArray([
    'fields' => [
        ['type' => 'input', 'key' => 'name', 'title' => 'Your Name'],
        ['type' => 'select', 'key' => 'country', 'options' => ['US', 'CA']],
    ]
]);
```

---

### F. Skip Pre-filled Fields (Issue #740)
**Status:** Requested, not implemented
**Need:** Auto-advance past fields with default values from env vars/flags

**SugarCraft Relevance:** MEDIUM - Common for CLI tools. Should add:
```php
// Skip fields that are pre-filled
->withSkipPrefilled(true)
```

---

## 8. Important PRs

### #609: feat!: v2
**Author:** caarlos0 (merged March 2026)
**Scope:** 78 files, +1545/-1236 lines

**Major Changes:**
1. Module path: `github.com/charmbracelet/huh` → `charm.land/huh/v2`
2. Theme: struct → interface with `ThemeFunc`
3. Spinner: merged into main package
4. Bubble Tea v2 + Lip Gloss v2
5. View hooks for form rendering interception
6. Field-level `WithAccessible()` removed

**Migration Guide:** `UPGRADE_GUIDE_V2.md` (322 lines)

**SugarCraft Lesson:** Major version bumps cause massive pain. Minimize breaking changes or provide comprehensive migration tooling.

---

### #573: fix: height and width calculation improvements, wrapping
**Author:** caarlos0 (merged March 2025)
**Scope:** 19 files, +226/-135 lines

**What Fixed:**
- Uses height of tallest group for entire form
- Proper line joining with lipgloss
- Avoids empty lines when not needed
- Properly accounts for title/description in heights
- Frame size in width calculations
- Help width properly set
- Fields use their height/width in rendering
- Note styles fixed
- Constant width on inline select

**Related Issues:** #[275, #486, #489]

**SugarCraft Relevance:** HIGH - Height/width calculation is notoriously tricky in TUIs. SugarCraft will need robust dimension calculation.

---

### #233: feat: Dynamic Inputs
**Author:** maaslalani (merged July 2024)
**Scope:** 23 files, +1525/-314 lines

**Introduced:** `*Func()` pattern for reactive form content

**Key Pattern:**
```go
TitleFunc(func() string { ... }, &binding)
OptionsFunc(func() []Option[T] { ... }, &binding)
DescriptionFunc(func() string { ... }, &binding)
```

**Caveat:** Initial implementation only supported `string` generic type (int/bool didn't work). Fixed in PR #312.

**SugarCraft Relevance:** HIGH - The binding + cache invalidation pattern is key for reactive forms.

---

### #214: feat: multicolumn layout forms
**Author:** adamdotdevin (closed July 2024)
**Scope:** 7 files, +301/-6 lines

**Initial Proposal:** `form.WithColumns(count)`

**Evolution to Layout API:**
```go
form.WithLayout(huh.LayoutColumns(2))
form.WithLayout(huh.LayoutGrid(2, 2))
form.WithLayout(huh.LayoutStack)
```

**Maintainer Design Input:**
> "We were thinking about adding different layouts... make the API more 'layout' based to allow swapping later on"

**SugarCraft Relevance:** MEDIUM - Layout abstraction allows future expansion. SugarCraft should use similar pattern.

---

### #725: fix(input): do not run validation on blur
**Author:** nelvko (merged January 2026)
**Scope:** 12 files, +466/-150

**Fix:** Remove validation from `Input.Blur()` so Shift+Tab works

**Key Quote:**
> "Remove validation from Input.Blur() and rely on existing validation logic already present in the Update handler for Next and Submit key bindings."

**SugarCraft Relevance:** CRITICAL - Validation timing is a fundamental UX decision. Validate on advance, not on blur.

---

## 9. Architectural Changes

### A. v2 Theme Refactoring

**Before (v1):**
```go
type Theme struct {
    Form FormStyles
    Group GroupStyles
    FieldSeparator lipgloss.Style
    Blurred FieldStyles
    Focused FieldStyles
    Help help.Styles
}
```

**After (v2):**
```go
type ThemeFunc func(isDark bool) *Styles
```

**Built-in Themes:**
```go
func ThemeCharm(isDark bool) *Styles
func ThemeDracula(isDark bool) *Styles
func ThemeCatppuccin(isDark bool) *Styles
func ThemeBase16(isDark bool) *Styles
```

**Migration Impact:**
- All custom themes must implement `ThemeFunc` signature
- All theme function calls need `isDark` parameter
- Users had to rewrite theme code

**SugarCraft Lesson:** Changing fundamental types causes massive pain. Provide deprecated compatibility layer or version gradually.

---

### B. Bubble Tea v2 Integration

**Changes:**
- `tea.Model` interface unchanged but types updated
- `tea.Cmd`, `tea.Msg` now from `charm.land/bubbletea/v2`
- View rendering changed (new `tea.View` type)

**SugarCraft Relevance:** MEDIUM - If SugarCraft implements TEA-like pattern, version synchronization with upstream will be critical.

---

### C. Spinner Module Merge

**Before:** Separate module at `github.com/charmbracelet/huh/spinner`
**After:** Merged into main package at `charm.land/huh/v2/spinner`

**Rationale (maintainer):**
> "spinner is not its own module anymore - it had little reason to"

**SugarCraft Relevance:** LOW - Module organization decision.

---

### D. View Hooks (v2 Feature)

**Purpose:** Intercept form view before rendering

```go
form.WithViewHook(func(v tea.View) tea.View {
    v.AltScreen = true
    return v
})
```

**Use Cases:**
- Dynamic terminal feature control
- Custom view transformations
- Integration with larger Bubble Tea apps

**SugarCraft Relevance:** MEDIUM - Rendering hooks provide extensibility. SugarCraft should consider `ViewHook` or `Renderer` interface.

---

## 10. Performance Discussions

### A. Height/Width Calculation Complexity
**Issues:** #[485, #573, #429, #275, #486]

**Pain Points:**
- Off-by-one errors in content overflow
- Offset calculations wrong for wrapped content
- Group height calculation didn't account for titles/descriptions
- Frame size not considered in width

**Complexity Evidence:** PR #485 had 29 commits addressing:
- `set group with size msg`
- `handle WindowSizeMsg + base height on rendered content`
- `render prefix before newlines`
- `update viewport on resize`
- `calculate offset from previous field heights`
- `add calculate wrapping helper`
- `stack buttons in narrow window`

**SugarCraft Relevance:** HIGH - Robust dimension calculation will be fundamental. Budget significant engineering time for this.

---

### B. Viewport Scrolling with Wrapped Content
**Issues:** #[429, #644 (bubbles)]

**Root Cause:** Viewport calculates visible lines as `cursorPos / viewportHeight`, assuming each item = 1 line.

**Workaround:** None clean - requires viewport rewrite.

**SugarCraft Relevance:** CRITICAL - If SugarCraft wraps text in scrollable views, must implement proper multi-line item support.

---

## 11. Extensibility Discussions

### A. Layout API Extensibility (PR #214)

**Initial API:**
```go
form.WithColumns(2)
```

**Refined API:**
```go
type Layout interface {
    View(f *Form) string
    GroupWidth(f *Form, group *Group, width int) int
}
```

**Implementations:**
- `LayoutDefault` - one at a time
- `LayoutColumns(n)` - side by side
- `LayoutGrid(rows, cols)` - 2D grid
- `LayoutStack` - vertical for narrow terminals

**Extensibility Pattern:** Interface + concrete implementations

**SugarCraft Relevance:** HIGH - SugarCraft layout should use interface-based design for future expansion.

---

### B. View Hooks (v2)

**Pattern:**
```go
WithViewHook(func(v tea.View) tea.View {
    // Transform view before rendering
    return v.WithAltScreen(true)
})
```

**SugarCraft Relevance:** MEDIUM - Rendering interception allows advanced customization. SugarCraft should consider `Renderer` interface.

---

### C. KeyMap Per-Instance vs Global

**Issue:** #272
**Problem:** Setting KeyMap on field gets overwritten by group/form defaults

**User Need:**
1. Custom KeyMap per field instance
2. Different KeyMap when field is "zoomed" (e.g., FilePicker)
3. Ability to add help keybinds (e.g., "back" for FilePicker)

**Maintainer Response:**
> "you can use the help bubble provided by charm to create your own"

**SugarCraft Relevance:** MEDIUM - KeyMap priority needs clear rules. SugarCraft should:
- Respect field-level KeyMap over group/form defaults
- Document override behavior clearly

---

## 12. API/UX Complaints

### A. Theme Customization is Difficult

**Issue #119:** Export theme.copy
**Issue #320:** Theme functions should accept renderer for SSH/Wish
**Issue #630:** Confirm option selection unclear for colorblind users

**User Code That Doesn't Work:**
```go
func createHuhTheme() huh.Theme {
    baseTheme := huh.ThemeBase()
    customErr := lipgloss.NewStyle().SetString("---")
    baseTheme.Focused.ErrorIndicator = customErr
    // ... doesn't work because ThemeBase returns pointer to internal state
}
```

**SugarCraft Directive:** Make theming straightforward:
- Document mutability expectations
- Provide theme builder/copy utilities
- Ensure styles apply to ALL field states

---

### B. WithKeyMap Returns Field Not Specific Type

**Issue #272:**
```go
// Problem: WithKeyMap returns Field, not *Input
huh.NewInput().WithKeyMap(keymap).Placeholder("") // FAILS
// Field has no Placeholder method
```

**User Request:** Return chainable specific type from field configurators

**SugarCraft Relevance:** MEDIUM - PHPfluent interfaces should return specific types for chaining.

---

### C. Validation on Blur Blocks Backward Nav

**Issue #655:**
> "If a field validation is used it soft-locks the program"

**SugarCraft Directive:** NEVER run validation on blur. Only validate on explicit advance (Enter/Submit).

---

### D. Focus Not Given to First Field in Bubble Tea Integration

**Discussion #594:**
When transitioning from another tea.Model to huh.Form, initial input doesn't have focus.

**Workaround:**
```go
func (s ServerLanding) Init() tea.Cmd {
    return tea.Batch(
        s.form.Init(),
        s.form.NextField(),
        s.form.PrevField(),
    )
}
```

**SugarCraft Relevance:** MEDIUM - Form initialization timing matters. SugarCraft should ensure first field focuses properly.

---

## 13. Migration Problems

### A. v2 Theme Breaking Change

**Complaint:** Cannot use themes anymore in v2
**Issue:** #745

**Errors:**
```
not enough arguments in call to huh.ThemeCharm
  have ()
  want (bool)

cannot use huh.ThemeCharm() as huh.Theme value
  *huh.Styles does not implement huh.Theme
```

**User Migration Path:**
```go
// Before
.WithTheme(huh.ThemeCharm())

// After - implement interface
type myTheme struct{}
func (t myTheme) Theme(isDark bool) *huh.Styles {
    return huh.ThemeCharm(isDark)
}
```

**SugarCraft Directive:** Avoid type system breaking changes. Use adapters/deprecation layers.

---

### B. Import Path Changes

**Before:** `github.com/charmbracelet/huh`
**After:** `charm.land/huh/v2`

**Impact:** All imports must change; `go get` doesn't auto-update

**SugarCraft Directive:** If changing package name, provide script to update imports.

---

### C. Field-Level WithAccessible Removed

**Before:**
```go
huh.NewInput().WithAccessible(true)
```

**After:**
```go
huh.NewForm(...).WithAccessible(true) // Only form level
```

**SugarCraft Relevance:** LOW - PHP doesn't have this issue.

---

## 14. Clever Fixes & Workarounds

### A. Windows Key Cleaning

**Problem:** Windows sends runes with Tab/Enter keys
**Solution:**
```go
//go:build windows
func CleanKeys(msg tea.Msg) tea.Msg {
    switch msg := msg.(type) {
    case tea.KeyMsg:
        if slices.Contains(cleanKeyTypes, msg.Type) {
            msg.Runes = []rune{} // Remove \t, \r runes
        }
    }
    return msg
}
```

**SugarCraft Relevance:** HIGH - Platform-specific keyboard handling is always needed. SugarCraft should:
- Detect platform
- Handle platform-specific key quirks
- Test on Windows extensively

---

### B. Multiple Form Keypress Ignored Workaround

**Problem:** First keystroke on second form ignored
**Workaround:** Call NextField/PrevField in Init
```go
func (m Model) Init() tea.Cmd {
    return tea.Batch(
        m.form.Init(),
        m.form.NextField(),
        m.form.PrevField(),
    )
}
```

**SugarCraft Relevance:** MEDIUM - Sequential forms pattern exists. SugarCraft should ensure proper focus handling.

---

### C. Dumb Terminal Auto-Accessible

**Code:**
```go
if os.Getenv("TERM") == "dumb" {
    f.WithWidth(defaultWidth)
    f.WithAccessible(true)
}
```

**SugarCraft Relevance:** LOW - Nice pattern for CI/limited terminals.

---

## 15. Community Workarounds

### A. Skip Validation for Backward Nav

**Issue:** Users disabled validation entirely to allow Shift+Tab

**Better Solution:** PR #725 (validate on advance only)

---

### B. Workaround for TTY Error on Hidden Forms

**Issue:** Form.Run() fails when all fields hidden

**Workaround:** Duplicate hide conditions
```go
if name == "" {
    form.Run() // fails if hidden
}
// Must check before calling
if name == "" {
    // call Run
} else {
    // skip entirely
}
```

**SugarCraft Directive:** SugarCraft should detect "all hidden" state and return early without TTY init.

---

### C. Custom Truncation for Long Options

**Issue:** Built-in truncation broken for long options

**User Solution:** Custom truncation function
```go
// User built their own because built-in didn't work
func truncateMiddle(s string, maxWidth int) string {
    if len(s) <= maxWidth {
        return s
    }
    // Truncate middle, keep start and end
}
```

**SugarCraft Directive:** Implement proper truncation:
- Start truncation (...)
- Middle truncation (...kept...)
- End truncation (...)
- Horizontal scroll option

---

## 16. Maintainer Guidance Patterns

### A. "Yes is Forever" - Feature Addition Philosophy

**Quote (bashbunni):**
> "If we commit to this feature, that's a feature we will need to maintain for the lifespan of this project. So, since 'Yes is forever', we are carefully considering new features."

**Pattern:** Convert potential features to discussions before committing to implementation.

**SugarCraft Directive:** Be conservative about adding features. Consider:
- Is this in scope?
- Will we maintain it indefinitely?
- Can it be implemented externally?

---

### B. Issues → Discussions Conversions

**Pattern:** Feature requests often converted to discussions to "keep backlog clear"

**Rationale:** Maintainers don't want to commit until ready to implement.

**SugarCraft Directive:** Use discussions for feature brainstorming; issues only for bugs and firm feature requests.

---

### C. Wait for Complete Solutions

**Pattern:** PRs not merged until:
- Tests added
- Examples updated
- All edge cases handled

**Example:** PR #214 layout feature sat for 2 months while working through details.

**SugarCraft Directive:** Don't merge incomplete features. Require:
- Tests
- Documentation
- Examples

---

## 17. Rejected Ideas Worth Revisiting

### A. LayoutAuto (PR #214)
**Status:** Not implemented - "couldn't figure out"
**Note:** Contributor struggled with algorithm for auto-layout

**SugarCraft Opportunity:** Implement auto-layout based on terminal dimensions.

---

### B. Confirm Checkbox Indicators (Issue #630)
**Status:** Partially addressed via theme customization
**Request:** Better default indicators for colorblind users

**SugarCraft Opportunity:** Use more distinct indicators by default (parentheses, brackets) rather than just color.

---

### C. Field-Level WithAccessible (v2)
**Status:** Removed in favor of form-level only
**Rationale:** Simpler, more consistent

**SugarCraft Directive:** Keep configuration at appropriate scope level.

---

## 18. Problems Likely Relevant To SugarCraft

### CRITICAL (Will directly impact SugarCraft)

1. **Validation Timing** - Must validate on advance, never blur
2. **Backward Navigation** - Shift+Tab must always work regardless of field state
3. **Theme Application** - All field states (focused, blurred, error, disabled) must be styleable
4. **Viewport Wrapping** - Multi-line content handling in scrollable views
5. **Keyboard Handling** - Platform-specific quirks (especially Windows)
6. **Dimension Calculation** - Robust height/width accounting for all elements
7. **Cross-Platform Testing** - Must test on Windows, macOS, Linux, various terminals

### HIGH (Should prioritize)

1. **Dynamic/Reactive Forms** - `*Func()` binding pattern
2. **Layout System** - Columns, Grid, Stack, Auto
3. **Pre-filled Field Handling** - Skip or auto-advance
4. **TTY Initialization** - Early exit when all hidden
5. **Form Isolation** - Multiple forms in same app shouldn't interfere
6. **Declarative Form Definition** - Array/JSON config for non-OOP usage

### MEDIUM (Nice to have)

1. **View Hooks** - Rendering interception
2. **Filterable Select** - Type-ahead with `/`
3. **Custom Truncation** - Multiple styles (start, middle, end)
4. **Horizontal Scroll** - For long option text
5. **Dumb Terminal Mode** - Auto-detect and use accessible mode

---

## 19. Features SugarCraft Should Consider

### A. Native PHP Array Form Definition

```php
// No YAML dependency - pure PHP
$form = Form::fromConfig([
    'fields' => [
        ['type' => 'input', 'key' => 'name', 'title' => 'Your Name'],
        ['type' => 'select', 'key' => 'country', 'options' => ['US', 'CA']],
    ],
    'theme' => 'tokyo-night',
    'layout' => 'columns:2',
]);
```

**Rationale:** Issue #761 shows demand for declarative forms. PHP arrays are native, no YAML parsing needed.

---

### B. Strict vs Permissive Validation Modes

```php
// Permissive (default) - validate on advance only
$form->withValidationMode(ValidationMode::Permissive);

// Strict - validate on blur
$form->withValidationMode(ValidationMode::Strict);
```

**Rationale:** Different use cases have different needs. Default to permissive (better UX).

---

### C. Field State Styling

```php
$theme = Theme::tokyoNight();
$theme->input->focused->borderColor('#7aa2f7');
$theme->input->blurred->borderColor('#414868');
$theme->input->error->borderColor('#f7768e');
$theme->input->disabled->opacity(0.5);
```

**Rationale:** Issue #770 shows blurred styles missing. Must style all states.

---

### D. Terminal-Aware Layout

```php
// Auto-select layout based on terminal dimensions
$form->withLayout(Layout::auto());

// Force specific layout
$form->withLayout(Layout::columns(2));
$form->withLayout(Layout::grid(2, 3));
$form->withLayout(Layout::stack());
```

**Rationale:** PR #214 shows value of layout system. Auto-layout is the hardest to implement but most valuable.

---

### E. Early Exit for Hidden Forms

```php
// SugarCraft should detect and return early
if ($form->allFieldsHidden()) {
    return; // No TTY initialization
}
```

**Rationale:** Issue #718 shows this is a real pain point.

---

## 20. Architectural Lessons

### A. Viewport Assumptions Break with Wrapped Content

**Lesson:** Assumptions about single-line content cause cascading bugs.

**SugarCraft Directive:** Design viewport to handle multi-line content from the start.

---

### B. Theme Type Changes Cause Massive Pain

**Lesson:** Changing fundamental types affects all users.

**SugarCraft Directive:** 
- Avoid changing core types post-stable
- If needed, provide deprecated layer with migration path
- Version with clear upgrade guides

---

### C. Validation Timing is a Fundamental UX Decision

**Lesson:** Validate-on-blur blocks navigation; validate-on-advance is more permissive.

**SugarCraft Directive:** Default to validate-on-advance. Document clearly.

---

### D. Platform-Specific Code is Inevitable

**Lesson:** Keyboard handling differs across platforms; workarounds required.

**SugarCraft Directive:** 
- Isolate platform-specific code
- Test on Windows, macOS, Linux
- Have escape hatches (dumb terminal mode)

---

### E. Layout Should Be Interface-Based

**Lesson:** PR #214 evolved from `WithColumns(n)` to `Layout` interface for extensibility.

**SugarCraft Directive:**
```php
interface Layout {
    public function render(Form $form): string;
    public function calculateWidth(Form $form, Group $group, int $width): int;
}
```

---

## 21. Defensive Design Lessons

### A. Never Block Backward Navigation

```php
// WRONG - blocks Shift+Tab
public function blur(): void {
    if ($this->validator->validate($this->value)->hasErrors()) {
        throw new ValidationException();
    }
}

// CORRECT - validate on advance only
public function submit(): void {
    $this->validator->validate($this->value);
}
```

---

### B. Apply Styles to All States

```php
// Focus/blur styles both set
$input->withFocusStyles($focusedStyles);
$input->withBlurStyles($blurredStyles);
$input->withErrorStyles($errorStyles);
$input->withDisabledStyles($disabledStyles);
```

---

### C. Namespace Internal Messages

```php
// Prevent interference between forms
class Form {
    private string $instanceId;
    
    private function nextField(): void {
        $this->send(new NextFieldMsg($this->instanceId));
    }
    
    private function handleNextField(Mixed $msg): void {
        if ($msg instanceof NextFieldMsg && $msg->instanceId === $this->instanceId) {
            // Only handle if for this form
        }
    }
}
```

---

### D. Test on Multiple Platforms

**Essential Test Matrix:**
- Windows 10/11 (cmd, PowerShell, Windows Terminal)
- macOS (Terminal.app, iTerm2, kitty)
- Linux (gnome-terminal, konsole, kitty, tmux, screen)
- Various terminal emulators (VS Code, JetBrains, etc.)

---

## 22. Ecosystem Trends

### A. Move to Vanity Domains

**Trend:** `github.com/charmbracelet/` → `charm.land/...`
**Rationale:** Better package management, version control

**SugarCraft Relevance:** Use `sugarcraft/` namespace consistently.

---

### B. Versioned Module Paths

**Trend:** `module/v2`, `module/v3` for major versions
**Rationale:** Allow parallel version consumption

**SugarCraft Directive:** Use `/v1`, `/v2` in composer package names for major versions.

---

### C. Declarative Configuration Demand

**Trend:** YAML form definitions (charm-form proposal)
**Driver:** Lower barrier for non-programmers

**SugarCraft Directive:** Offer both OOP and declarative (array) form definitions.

---

### D. TEA Pattern Maturation

**Trend:** Bubble Tea v2 with new renderer ("cursed renderer")
**Improvements:**
- Better keyboard handling
- Declarative views
- Built-in color downsampling

**SugarCraft Directive:** If implementing TEA-like pattern, learn from Bubble Tea v2's lessons.

---

## 23. Strategic Opportunities

### A. PHP Native Declarative Forms

**Opportunity:** SugarCraft can offer array-based form definition without YAML dependency.

**Implementation:**
```php
Form::fromArray([
    'title' => 'User Registration',
    'groups' => [
        ['title' => 'Personal Info', 'fields' => [
            ['type' => 'input', 'name' => 'email', 'required' => true],
            ['type' => 'password', 'name' => 'pass', 'minLength' => 8],
        ]],
        ['title' => 'Preferences', 'fields' => [
            ['type' => 'select', 'name' => 'theme', 'options' => ['dark', 'light']],
        ]],
    ],
    'theme' => 'dracula',
    'layout' => 'columns:2',
]);
```

---

### B. Superior Validation UX

**Opportunity:** SugarCraft can default to validate-on-advance only, unlike huh's original design.

**Implementation:**
```php
// Shows error but allows backward navigation
$field->withValidator(fn($v) => $v !== '' ?: 'Required')
    ->withValidationTiming(ValidationTiming::OnAdvance);
```

---

### C. Better Blurred State Styling

**Opportunity:** Fix the v2 regression where blurred styles don't apply.

**Implementation:**
```php
// Ensure all state styles are applied
class InputField {
    public function render(): string {
        $styles = match($this->state) {
            State::Focused => $this->theme->input->focused,
            State::Blurred => $this->theme->input->blurred,
            State::Error => $this->theme->input->error,
            State::Disabled => $this->theme->input->disabled,
        };
        // ... render with styles
    }
}
```

---

### D. Auto-Layout Algorithm

**Opportunity:** Implement `LayoutAuto` that huh couldn't.

**Algorithm:**
```php
function calculateAutoLayout(Form $form, int $terminalWidth): Layout {
    $groupWidths = [];
    foreach ($form->getGroups() as $group) {
        $groupWidths[] = estimateGroupWidth($group);
    }
    
    $totalWidth = array_sum($groupWidths);
    
    if ($totalWidth <= $terminalWidth) {
        return new ColumnsLayout(count($form->getGroups()));
    }
    
    // Stack for narrow terminals
    return new StackLayout();
}
```

---

### E. TTY-Less Mode

**Opportunity:** Properly handle forms where all fields are hidden.

**Implementation:**
```php
class Form {
    public function run(): void {
        if ($this->allGroupsHidden()) {
            $this->collectHiddenResults();
            return; // No TTY init needed
        }
        // ... normal run
    }
}
```

---

## 24. Cross-Ecosystem Pattern Matches

### A. HTML Form Behavior as Reference

**Pattern:** Users compare to HTML forms expecting:
- Tab/Shift+Tab navigation works regardless of field validity
- Validation messages don't block navigation
- Browser handles focus correctly

**SugarCraft Directive:** Use HTML form behavior as the user expectation baseline.

---

### B. React Hooks Pattern for Dynamic Forms

**Pattern:** huh's `*Func()` with binding mirrors React's `useState` + derived state

**SugarCraft Equivalent:**
```php
// Similar to React hooks
class ReactiveField {
    private array $bindings = [];
    
    public function withBinding(string $key, &$value): self {
        $this->bindings[$key] = &$value;
        return $this;
    }
    
    public function invalidateOn(string ...$keys): self {
        // Re-render when these bindings change
    }
}
```

---

### C. Elm Architecture (TEA) Lessons

**Pattern:** Bubble Tea is Go implementation of Elm TEA

**SugarCraft Consideration:** If implementing reactive forms:
- Model = form state
- Update = state transitions
- View = rendering

---

## 25. High ROI Recommendations

### Immediate (High Impact, Low Effort)

1. **Validation Timing** - Change to validate-on-advance immediately. This is the #1 user complaint.

2. **Blur Style Application** - Ensure blurred styles are applied to ALL fields (the v2 regression).

3. **Backward Navigation** - Ensure Shift+Tab always works regardless of field state.

4. **All Hidden Detection** - Add early exit when all groups/fields are hidden.

### Short-Term (High Impact, Medium Effort)

5. **Layout System** - Implement interface-based layout (Columns, Grid, Stack, Auto).

6. **Dynamic Forms** - Implement `*Func()` pattern with binding-based invalidation.

7. **Cross-Platform Testing** - Establish test matrix for Windows, macOS, Linux.

### Medium-Term (High Impact, High Effort)

8. **Declarative Forms** - PHP array-based form definition without YAML.

9. **Auto-Layout** - Implement algorithm that huh couldn't (LayoutAuto).

10. **Viewport with Wrapped Content** - Handle multi-line items in scrollable views.

### Long-Term (Strategic Value)

11. **View Hooks** - Extensibility via rendering interception.

12. **Multi-Form Isolation** - Namespace all internal messages.

13. **Accessible Mode** - Screen-reader friendly prompts.

---

## Appendix: Issue Priority Matrix

| Issue | Impact | Effort | Priority |
|-------|--------|--------|----------|
| Validation timing (blur) | Critical | Low | P0 |
| Blur styles missing | High | Low | P0 |
| Backward nav blocked | Critical | Low | P0 |
| All hidden detection | Medium | Medium | P1 |
| Layout system | High | High | P1 |
| Dynamic forms | High | High | P1 |
| Cross-platform testing | High | High | P1 |
| Declarative forms | Medium | High | P2 |
| Auto-layout | Medium | High | P2 |
| Viewport wrapping | High | High | P2 |
| View hooks | Medium | Medium | P2 |

---

## References

- Repository: https://github.com/charmbracelet/huh
- Previous analysis: `repo_map/charmbracelet_huh.md`
- Upgrade guide: `UPGRADE_GUIDE_V2.md`
- Discussions: #559, #594
- Issues: #761, #769, #740, #745, #718, #719, #770, #429, #655, #281, #191, #356, #214, #233, #120, #272, #278, #315, #286, #422, #630, #320, #119, #341, #631

---

*Report generated: 2026-05-27*
*Analysis scope: Issues, PRs, Discussions, Commits through 2026-05*
