# treilik/bubbleboxer

## Metadata
- URL: https://github.com/treilik/bubbleboxer
- Language: Go
- Stars: 84
- License: MIT
- Description: A layout composer that composes multiple [bubbles](https://github.com/charmbracelet/bubbles) (Charmbracelet TUI components) into a single unified layout using a tree structure.

## Feature List
- **Layout Tree Composition**: Build complex TUI layouts using a tree of nodes, where each node can contain child nodes or leaf references to Bubble models
- **Horizontal/Vertical Stacking**: Nodes can stack children either horizontally (side-by-side with vertical separator `│`) or vertically (stacked with horizontal separator `─`)
- **Recursive Rendering**: The layout tree is rendered recursively, with each node managing its children's rendering
- **Separation of Model and Layout**: Models are stored separately in a `ModelMap` and referenced by address string, allowing independent modification without tree traversal
- **Custom Size Functions**: `SizeFunc` on nodes allows custom width/height allocation per child, with even distribution as default
- **Border Management**: `noBorder` flag controls whether separators are drawn between children; borders are non-recursive (only affect direct children)
- **Tea.Model Interface Compliance**: Implements `tea.Model` interface (`Init()`, `Update()`, `View()`) for integration with the Charmbracelet/bubbletea architecture
- **Window Size Propagation**: `UpdateSize()` recursively sets width/height on all nodes and forwards `WindowSizeMsg` to leaf models
- **Safe Leaf Editing**: `EditLeaf()` provides safe access to leaf models with edit functions that can return errors to prevent invalid state
- **ANSI-aware Line Width**: Uses `github.com/muesli/ansi` to correctly calculate printable rune width for proper character alignment

## Key Classes and Methods

### Boxer (struct)
- `Init()` — Satisfies tea.Model interface, returns nil cmd
- `Update(msg tea.Msg)` — Handles `tea.KeyMsg` (ctrl+c quit) and `tea.WindowSizeMsg`
- `View() string` — Renders the layout tree, returns "waiting for size information" if size not set
- `UpdateSize(size tea.WindowSizeMsg)` — Recursively sets width/height on all nodes in layout tree
- `CreateLeaf(address string, model tea.Model)` — **Only way to create a valid leaf**; creates ModelMap entry and returns Node with address
- `EditLeaf(address string, editFunc func(tea.Model) (tea.Model, error))` — Safe leaf modification; rolls back on error
- `LayoutTree Node` — Root node of the layout tree
- `ModelMap map[string]tea.Model` — Address-to-model mapping for leaves

### Node (struct)
- `Children []Node` — Child nodes in the layout tree
- `VerticalStacked bool` — Orientation flag; true = vertical stack with `─` separators, false = horizontal with `│` separators
- `SizeFunc func(node Node, widthOrHeight int) []int` — Custom size allocator; returns per-child sizes
- `noBorder bool` — Private flag controlling border rendering (must be set via `CreateNoBorderNode()`)
- `address string` — Private leaf identifier (only settable via `CreateLeaf()`)
- `width int`, `height int` — Current computed dimensions
- `render(modelMap map[string]tea.Model)` — Recursively renders node to string slice
- `renderVertical(modelMap)` — Renders vertical-stacked children with horizontal separators
- `renderHorizontal(modelMap)` — Renders horizontal-stacked children with vertical separators
- `updateSize(size tea.WindowSizeMsg, modelMap)` — Recursive size computation
- `IsLeaf()` — Returns true if node has an address (is a leaf)
- `GetAddress()`, `GetWidth()`, `GetHeight()` — Accessors

### Factory/Helper Functions
- `CreateNoBorderNode()` — Constructor for node with `noBorder=true`
- `wrapError()` — Wraps errors with layout context

### Error Types
- `SizeError` — Conveyed when insufficient space for nodes in layout tree
- `NotFoundError` — Conveyed when leaf address not found in ModelMap

## Notable Algorithms / Named Patterns

### Layout Tree Pattern
The core pattern is a **composite tree structure** where:
- Interior nodes define orientation and size distribution
- Leaf nodes are references (via address string) to actual tea.Model instances
- The tree is decoupled from the models themselves (ModelMap pattern)

### Recursive Size Propagation
`updateSize()` traverses the tree top-down:
1. Sets node's raw dimensions from parent
2. Subtracts border space if borders enabled (`length - 1` for separators)
3. Validates remaining space > 0
4. For nodes with `SizeFunc`: calls it to get per-child sizes, validates sum doesn't exceed available
5. For nodes without `SizeFunc`: divides space evenly, distributing remainder via modulo
6. Recurses to children with computed sub-sizes

### String Building with ANSI Width Awareness
`render()` uses `ansi.PrintableRuneWidth()` to correctly measure visual width of strings containing ANSI escape codes (colors, styles). This ensures proper padding alignment.

### Safe Edit Pattern
`EditLeaf()` uses a functional approach where the edit function is applied and if it returns an error, the original model is preserved:
```go
func (b *Boxer) EditLeaf(address string, editFunc func(tea.Model) (tea.Model, error)) error {
    model, ok := b.ModelMap[address]
    if !ok { return NotFoundError(...) }
    model, err := editFunc(model)
    if err != nil { return err }  // original unchanged
    b.ModelMap[address] = model   // commit
    return nil
}
```

## Strengths
- **Clean separation of concerns**: Layout tree and models are independent; models can be modified without touching the tree
- **Charmbracelet integration**: Naturally composes with the bubbles ecosystem (spinner, viewport, textinput, etc.)
- **Customizable sizing**: SizeFunc allows complex weight-based or fixed-size layouts
- **Error propagation**: Errors wrapped with context (which child, what layout) for easy debugging
- **Tea.Model compliance**: Drop-in compatible with existing bubbletea applications
- **MIT licensed**: Permissive, usable in commercial projects
- **Minimal dependencies**: Only depends on bubbletea and ansi libraries

## Weaknesses
- **No recursive noBorder**: `CreateNoBorderNode()` explicitly documents it is not recursive; descendant nodes still render borders
- **Fixed border characters**: `HorizontalSeparator` and `VerticalSeparator` are package-level vars but changing them affects all nodes globally
- **No built-in scrolling/overflow handling**: If a leaf model returns more content than its allocated size, rendering fails rather than clips
- **Manual tree construction**: No builder pattern; constructing complex layouts requires verbose manual Node composition
- **No automatic resize handling**: User must manually call `UpdateSize()` on WindowSizeMsg in their Update loop
- **No flex/gap concept**: Borders consume actual character cells; no CSS-like gap property
- **Single-threaded rendering**: No parallel rendering of independent branches
- **Limited layout algorithms**: Only even-split and custom SizeFunc; no weighted flex or percentage-based

## SugarCraft Mapping

| treilik/bubbleboxer | SugarCraft Equivalent | Notes |
|---------------------|----------------------|-------|
| `Boxer` struct + `LayoutTree` | `candy-core` viewport/layout primitives | Both provide TUI layout composition; bubbleboxer is tree-based, SugarCraft uses termbox-cell approach |
| `Node.VerticalStacked` | `candy-shine` table layout | Vertical/horizontal stacking maps to row/column orientation |
| `CreateLeaf()` + `ModelMap` | `SugarCraft\Core\I18n\T` indirection | Both use address/key lookup pattern separating reference from data |
| `SizeFunc` custom allocation | `honey-bounce` layout weights | Custom size distribution similar to flex-weight or grid-template sizing |
| `EditLeaf()` safe edit pattern | Immutable `with*()` builders | Both provide safe mutation patterns; bubbleboxer uses functional edit, SugarCraft uses copy-on-write |
| `View()` string rendering | `sugar-bits` view rendering | Both produce final string output for terminal display |
| `renderHorizontal()` / `renderVertical()` | Table rendering in `candy-shine` | Horizontal join with separator parallels table cell rendering |
| `UpdateSize()` propagation | `candy-core` resize handling | Both propagate size constraints down a hierarchy |
| `tea.Model` interface compliance | SugarCraft `Model` contract | Both implement Charmbracelet-style Model pattern for TEA architecture |

**Multi-lib mapping**: bubbleboxer combines concepts from:
- `candy-core` (viewport management, term dimensions)
- `candy-shine` (table/grid layout with separators)
- `sugar-bits` (view rendering pipeline)
- `honey-bounce` (flex-weight sizing via SizeFunc)

## Analysis

**treilik/bubbleboxer** is a focused Go library that solves the "multiple TUI components in one view" problem using a composite tree pattern. Unlike SugarCraft's termbox-cell-based approach where all rendering happens into a shared buffer, bubbleboxer maintains the Charmbracelet/bubbletea pattern where each component is an independent `tea.Model` that returns its own string view. The `Boxer` then recursively assembles these strings with appropriate borders and padding.

The architecture is elegant in its simplicity: the `Boxer` struct holds a `LayoutTree` (Node) and a `ModelMap` (string → tea.Model). Nodes with an `address` are leaves that look up their model in the map; nodes without an address are composite nodes that contain other nodes. This separates the **layout** (tree structure) from the **content** (models), allowing models to be modified independently from the layout hierarchy.

The most distinctive feature is the `SizeFunc` - a user-provided function that receives the available dimension and returns per-child sizes. This is more flexible than simple even-split, enabling weight-based layouts like "1/3 for sidebar, 2/3 for main content." The tradeoff is verbosity in construction and the fact that no builder pattern exists.

The library's size is small (~455 lines in boxer.go) making it highly auditable. The use of `ansi.PrintableRuneWidth` for width calculations shows careful attention to ANSI escape code handling, which is a common pitfall in TUI work. The error messages are descriptive with context about which child failed and in what layout orientation.

For SugarCraft, bubbleboxer represents a complementary approach rather than a direct equivalent. Where SugarCraft uses a shared termbox-cell buffer and direct coordinate-based drawing, bubbleboxer uses a string-composition model. The tree-based layout concept maps well to `candy-shine`'s table layout, while the model separation pattern is analogous to SugarCraft's immutable builder pattern. If SugarCraft were to add a bubble-based component library, bubbleboxer would be the natural layout engine.
