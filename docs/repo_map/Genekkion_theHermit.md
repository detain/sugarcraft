# Genekkion/theHermit

## Metadata
- **URL:** https://github.com/Genekkion/theHermit
- **Language:** Go
- **Stars:** 15
- **License:** MIT (Copyright (c) 2024 Genekkion)
- **Description:** A quick fix list model for the Charm BubbleTea ecosystem, inspired by Neovim's quick fix lists. It wraps the main view, replacing characters at certain areas with list content, enabling the background view to continue updating while the list is shown.

## Feature List
- Quick fix list overlay for BubbleTea TUI applications
- Wraps existing fullscreen views (list content overlaid on background)
- Background view continues updating while list is displayed
- Keyboard navigation (up/down arrows for cursor movement)
- Numbered or unnumbered list items (toggleable)
- Customizable styling via lipgloss: borders, title, items, selected item
- Window resize handling with auto-centering
- ANSI escape code handling for proper width calculation
- Cursor-offset paging mechanism for scrolling through longer item lists
- Toggle visibility via `SetIsShown(bool)`

## Key Classes and Methods

### `Model` struct (`list/model.go:L8-L47`)
Main model struct with fields for dimensions, styles, items, cursor, offset.

**Constructors:**
- `New(height, width int, items []Item) Model` — L86-117: Creates a Model with specified dimensions and items
- `NewDefault(items []Item) Model` — L50-83: Creates a Model with sensible defaults (81x14, dark theme)

**tea.Model Interface:**
- `Init() tea.Cmd` — L119-121: Returns nil (no initialization needed)
- `Update(message tea.Msg) (tea.Model, tea.Cmd)` — L123-172: Handles WindowSizeMsg and KeyMsg (up/down)

**Setters/Getters (list/misc.go):**
- `SetView(view string)`, `GetView()` — L84-90: Set the underlying view to wrap
- `SetIsShown(isShown bool)`, `GetIsShown()` — L15-21: Toggle list visibility
- `SetItems(items []Item)`, `GetItems()` — L72-74, L68-70: Item management
- `SetCursor(cursor int)`, `Cursor()` — L63-66, L59-61: Cursor with clamping
- `GetSelectedItem() Item` — L55-57: Returns item at current cursor
- `SetHeight()`, `GetHeight()`, `SetWidth()`, `GetWidth()` — L23-37: Dimension getters/setters
- `SetMaxHeight()`, `GetMaxHeight()`, `SetMaxWidth()`, `GetMaxWidth()` — L39-53: Max dimension bounds
- `SetBorder(lipgloss.Border)`, `GetBorder()` — L92-98: Border customization
- `SetBorderForeground()`, `SetBorderBackground()` — L100-107: Border colors
- `SetTitleForeground()`, `SetTitleBackground()`, `SetTitleBold()` — L109-119: Title styling
- `SetItemForeground()`, `SetItemBackground()`, `SetItemBold()` — L121-131: Item styling
- `SetSelectedForeground()`, `SetSelectedBackground()`, `SetSelectedBold()` — L133-143: Selected item styling
- `SetTitle(title string)` — L80-82: Set list title
- `SetIsNumbered(bool)` — L11-13: Toggle numbering

### `Item` interface (`list/item.go:L4-L6`)
Simple interface requiring a `Title()` method — mirrors bubble's list.item interface.

```go
type Item interface {
    Title() string
}
```

### View Rendering (`list/views.go`)

**Border Functions:**
- `topBorder(line *string) string` — L100-149: Renders top border with optional centered title
- `bottomBorder(line *string) string` — L153-170: Renders bottom border
- `middleBorder(line *string, item *Item, index int) string` — L174-234: Renders a single item row
- `middleSpacer(line *string) string` — L237-261: Renders empty spacer rows when items < rows

**Padding Functions:**
- `writeLeftPadding(stringBuilder *strings.Builder, chars *[]string)` — L18-32: Writes left padding accounting for ANSI codes
- `writeRightPadding(stringBuilder *strings.Builder, chars *[]string, line *string)` — L57-96: Writes right padding with ANSI code preservation
- `paddingLength(array []string) int` — L36-42: Helper to calculate visual length
- `isCode(regexMatches [][]int, index int) bool` — L47-54: Checks if an index falls within an ANSI escape code range

**Main View:**
- `View() string` — L264-321: Main rendering function that overlays list onto background view

## Notable Algorithms / Named Patterns

### ANSI-Aware Width Calculation
The library uses `lipgloss.Width()` instead of naive `utf8.RuneCountInString()` to correctly handle ANSI escape sequences when calculating padding and text widths.

**Source:** `list/views.go:L24` — `lipgloss.Width(stringBuilder.String())`

### ANSI Escape Code Detection
Uses regex `colorRegex = regexp.MustCompile("\033\\[[0-9;]+m")` (L14) to find and skip ANSI codes when extracting character positions for padding.

**Source:** `list/views.go:L14, L66` — `colorRegex.FindAllStringIndex()`

### Cursor Clamping
```go
model.cursor = max(min(cursor, len(model.items)-1), 0)
```
Ensures cursor never goes below 0 or above the last item index.

**Source:** `list/misc.go:L64`

### Overlay Positioning Algorithm
The list is centered vertically in the window using:
```go
midPoint1 := model.windowHeight/2 - model.height/2 + 1
midPoint2 := midPoint1 + model.height
```

**Source:** `list/views.go:L279-280`

### Offset-Based Pagination
For scrolling through longer lists, the model maintains an `offset` field and slices visible items:
```go
items = model.items[model.offset : model.offset+model.height-1]
```

**Source:** `list/views.go:L286-289`

## Strengths
- Clean API that follows BubbleTea's conventions (tea.Model interface)
- Proper ANSI/UTF-8 handling for international character width calculation
- Good separation of concerns (model, views, item interface)
- Customizable styling via lipgloss (border, title, items, selected state)
- Auto-centering with window resize handling
- Cursor clamping prevents index out-of-bounds errors
- Toggle-able numbering for items
- Lightweight (single package, few dependencies beyond lipgloss)
- Actively maintained and contributed to charmbracelet/bubbles

## Weaknesses
- Currently tested with **fullscreen views only** — not flexible for child component dimensions (explicitly documented as TODO)
- **No fuzzy filtering** (noted as "Coming Soon")
- **No pagination** — uses simple offset-based scrolling
- **No status messages** or activity spinner
- **Minimal testing** — no visible test files in the repository
- **Limited documentation** — single README with basic example
- **No integration** with charmbracelet/bubbles' list component — standalone implementation
- **No help menu** generation
- Missing features that standard bubble list has (delegate system, filtering, etc.)

## SugarCraft Mapping

| theHermit Feature | SugarCraft Library | Notes |
|-------------------|---------------------|-------|
| List overlay/quick fix popup | `sugar-list` | SugarCraft's list component; would need overlay/wrapper pattern |
| Item interface (`Title()`) | `sugar-bits` | Mirrors the component/item protocol in SugarCraft TUI |
| Border rendering + styling | `candy-shine` | Lipgloss-based styling system; border primitives |
| Overlay rendering (centered on background) | `candy-shell` | Viewport/window management, fullscreen overlay |
| Background view preservation | `sugar-bits` (renderer) | BubbleTea's concurrent view update pattern |
| Keyboard navigation (up/down) | `sugar-prompt` | Input handling, cursor movement |
| tea.Model interface (Init/Update/View) | `candy-core` | Core TUI component contract |

**Mapping Notes:**
- The Hermit is a **wrapper/overlay pattern** that doesn't directly map to a single SugarCraft lib — it would span multiple:
  - `sugar-bits` for the item/component model
  - `candy-shine` for lipgloss-based styling  
  - A new `sugar-overlay` or `sugar-quickfix` lib for the overlay rendering mechanism
- The background-view-continues-updating pattern is similar to how `candy-sprinkles` handles layered rendering
- No direct mapping to `honey-*` libs (math/physics/motion) — purely UI/UX

## Analysis

The Hermit is a Go library providing a quick fix list overlay for the Charm BubbleTea TUI framework, inspired by Neovim's quick fix window. The core innovation is its **view wrapping technique** — instead of replacing the entire view like a typical modal, it overlays list content onto specific regions of the existing view, allowing the background to continue updating in real-time. This is achieved by splitting the background view into lines, then overwriting the characters at the list's bounding box region while preserving the original content around it.

The implementation demonstrates thoughtful handling of ANSI escape codes, which is critical for any terminal application dealing with styled text. The library uses regex pattern matching to detect ANSI codes and excludes them from character position calculations when computing padding — a common source of bugs in TUI development. The `lipgloss.Width()` function is used throughout instead of naive string length, ensuring proper handling of double-width Unicode characters and ANSI sequences.

The library is relatively small (4 source files, ~640 lines) but focused. It fills a specific niche that the official charmbracelet/bubbles list component doesn't address: the quick fix popup pattern for fullscreen applications. The author (Genekkion) has also contributed to the official bubbles repository, indicating familiarity with the ecosystem. While currently limited to fullscreen views and lacking features like fuzzy filtering, the architecture is clean and extensible. For a SugarCraft port, this would likely map to a combination of the existing list handling (sugar-bits), styling (candy-shine), and potentially a new overlay/renderer component for the overlay mechanism.
