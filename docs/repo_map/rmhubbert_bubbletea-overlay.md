# rmhubbert/bubbletea-overlay

## Metadata
- **URL:** https://github.com/rmhubbert/bubbletea-overlay
- **Language:** Go
- **Stars:** Unknown (gh command failed due to no auth; repo appears small with v0.6.0 from Dec 2025)
- **License:** MIT
- **Description:** A component for Charm's Bubble Tea TUI framework that aims to simplify creating and managing overlays and modal windows in TUI applications.

## Feature List
- **Compositing Engine:** Merges foreground and background views into a single composited view string
- **Positioning System:** Five-position placement (Top, Right, Bottom, Left, Center) for both X and Y axes
- **Offset Fine-Tuning:** X/Y offset parameters to adjust positioning with pixel-level control
- **tea.Model Integration:** Implements the Bubble Tea Model interface (Init, Update, View lifecycle)
- **Viewable Interface:** Accepts any type implementing `View() string`, not limited to `tea.Model`
- **ANSI-Aware Rendering:** Uses `charmbracelet/x/ansi` for proper terminal ANSI sequence handling during compositing
- **Edge Case Handling:** Gracefully handles empty strings, single-line content, out-of-bounds offsets (clamping), and size mismatches
- **Debug Logging:** Optional DEBUG environment variable triggers file-based logging via `tea.LogToFile`
- **Compositing Algorithm:** Based on [Superfile's overplace.go](https://github.com/yorukot/superfile/blob/main/src/pkg/string_function/overplace.go)

## Key Classes and Methods

### `Model` struct (model.go:27-34)
```go
type Model struct {
    Foreground Viewable  // The overlay content
    Background Viewable // The base content
    XPosition  Position  // Horizontal: Top, Right, Bottom, Left, Center
    YPosition  Position  // Vertical: Top, Right, Bottom, Left, Center
    XOffset    int       // Fine-tune X position
    YOffset    int       // Fine-tune Y position
}
```

### `New()` (model.go:37-46)
Factory function that creates and returns a pointer to a new overlay Model:
```go
func New(fore Viewable, back Viewable, xPos Position, yPos Position, xOff int, yOff int) *Model
```

### `Position` type (model.go:11-19)
Enum-like constants for positioning:
```go
const (
    Top Position = iota + 1
    Right
    Bottom
    Left
    Center
)
```

### `Viewable` interface (model.go:21-23)
Allows any type with a `View() string` method to be used:
```go
type Viewable interface {
    View() string
}
```

### `Composite()` function (composite.go:14-74)
Core compositing function that merges foreground onto background:
```go
func Composite(fg, bg string, xPos, yPos Position, xOff, yOff int) string
```
- Returns background if foreground is empty
- Returns foreground if background is empty
- Returns foreground if it completely covers background (both width and height)
- Clamps offsets to prevent out-of-bounds placement
- Uses line-by-line iteration with ANSI-aware truncation

### `offsets()` function (composite.go:78-117)
Calculates actual X/Y offsets based on position enum values:
- **Left:** x = 0
- **Center:** x = (bgWidth/2) - (fgWidth/2)
- **Right:** x = bgWidth - fgWidth
- Same logic for Y axis with Top/Center/Bottom

### `clamp()` function (composite.go:120-132)
Clamps a value between bounds, handles inverted bounds:
```go
func clamp(v, lower, upper int) int
```

### `lines()` function (composite.go:136-139)
Normalizes line endings (\r\n → \n) and splits string into slice:
```go
func lines(s string) []string
```

### `Init()`, `Update()`, `View()` (model.go:49-79)
tea.Model interface implementation:
- `Init()` returns nil (no startup commands)
- `Update()` returns self unchanged (overlay doesn't process updates)
- `View()` delegates to `Composite()` or returns single view

## Notable Algorithms / Named Patterns

### **Compositing Algorithm**
Line-by-line foreground/background merging based on Superfile's implementation:
1. Split both strings into lines
2. For each background line:
   - If outside foreground vertical range → write background line as-is
   - If within foreground range → write left padding, then foreground line, then right padding from background
3. Uses `ansi.Truncate()` and `ansi.TruncateLeft()` for ANSI-safe substring operations
4. Uses `lipgloss.Size()` for measuring rendered dimensions

### **Center Bias Algorithm**
When centering with even dimensions, offsets bias toward top-left (documented in test comments):
- Center calculation uses integer division which truncates
- When centering odd×odd → 2,2 (top-left bias)
- When centering even×even → 2,3 (top-left bias in height)

### **Out-of-Bounds Clamping**
```go
x = clamp(x, 0, bgWidth-fgWidth)
y = clamp(y, 0, bgHeight-fgHeight)
```
Prevents foreground from rendering outside background boundaries.

### **tea.Model Delegate Pattern**
The Manager model in the example demonstrates proper update delegation:
- Manages foreground and background update cycles externally
- Calls `overlay.New()` once at initialization
- Switches between direct background view and overlay view based on state

## Strengths
- **Clean, focused API:** Single responsibility (compositing) with minimal surface area
- **Comprehensive tests:** Table-driven tests covering all position/offset combinations and edge cases (644 lines in composite_test.go)
- **Flexibility:** `Viewable` interface allows any `View()` implementer, not just `tea.Model`
- **ANSI correctness:** Uses charmbracelet/x/ansi for proper Unicode/ANSI sequence handling
- **Edge case handling:** Nil checks, empty strings, out-of-bounds offsets all handled gracefully
- **Well-documented:** README provides clear usage examples and positioning documentation
- **Debug support:** Optional DEBUG mode for troubleshooting positioning issues
- **MIT Licensed:** Permissive open-source license
- **No external runtime dependencies:** Only Go standard library + bubbletea/lipgloss ecosystem

## Weaknesses
- **Two-layer limitation:** Only supports foreground/background (no stacked overlays)
- **No animation support:** No built-in transitions for overlay show/hide
- **Offset design quirk:** Offsets added after position calculation may produce unexpected results for Center positioning (intentional but non-obvious)
- **Lipgloss v2 competition:** README acknowledges Lipgloss v2 has compositing built-in, making this potentially obsolete for v2 users
- **No transparency support:** Cannot create semi-transparent overlays
- **Debug logging limitations:** Writes to `debug.log` file; not suitable for CI/automated environments
- **Small footprint:** Single-maintainer project, limited community engagement
- **No concurrent safety documentation:** No mention of thread-safety or lack thereof for concurrent use

## SugarCraft Mapping

### Primary Mapping
| bubbletea-overlay Feature | SugarCraft Library | Notes |
|---------------------------|-------------------|-------|
| Overlay compositing | `sugar-bits` or new `sugar-overlay` | Could be a standalone overlay/modal library |
| tea.Model integration | `candy-core` | Core TUI framework port (if it exists) |
| Positioning system | `sugar-bits` | If sugar-bits has layout/position utilities |
| ANSI-aware string ops | `candy-core` | FFI/raw terminal handling |

### Analysis
This library represents a **modal/overlay system** for TUI applications. In SugarCraft terms:

- **Could map to:** A new `sugar-overlay` library (or `sugar-modal`) focused on overlay positioning and compositing
- **Depends on:** `candy-core` (for TUI framework) and `sugar-bits` (for rendering primitives)
- **Alternative:** Could be merged into `sugar-bits` if SugarCraft has a component-focused layout system

The **key value** this provides is the compositing algorithm using `lipgloss.Size()` to measure rendered dimensions, then combining foreground/background line-by-line with proper ANSI handling. This pattern could be valuable for:
- Modal dialogs
- Tooltips
- Context menus
- Help overlays
- Stackable HUD elements

## Analysis

bubbletea-overlay is a focused, well-engineered utility library that solves a specific problem in Bubble Tea applications: the compositing of two views into a single visual output for creating modal windows and overlays. The implementation is remarkably compact (~300 lines of core code across 4 files) while providing comprehensive test coverage through table-driven tests that cover every combination of positions, offsets, and edge cases.

The architecture demonstrates good Go practices: small interfaces (only `Viewable` required), clear separation of concerns (compositing logic separate from tea.Model wrapper), and thoughtful edge case handling. The decision to not call `Update()` on child models is explicit and documented, forcing consumers to manage update cycles externally—this keeps the library focused but requires slightly more boilerplate in the parent model (as seen in the Manager example).

The compositing algorithm itself is the heart of the library, using line-by-line merging with ANSI-aware truncation to correctly handle styled terminal output. The implementation properly handles the tricky case of ANSI escape sequences that span visible character boundaries. The centering algorithm's bias toward top-left when dimensions are even is a subtle but intentional design decision that matches common terminal UI expectations.

The main strategic concern is the roadmap: Lipgloss v2 (released Feb 2026) includes compositing built-in, which may reduce the long-term value of this library for projects already migrating to v2. However, the author explicitly commits to maintaining it for Bubbletea/Lipgloss v1 users and direct users outside the Bubble Tea ecosystem. For SugarCraft, this library's pattern—particularly the compositing algorithm and Viewable interface design—could inform a PHP-native overlay system, though PHP's lack of native ANSI handling would require a different approach.
