# sugar-boxer — Update Report

## Metadata
- **Package**: `sugarcraft/sugar-boxer`
- **Upstream**: [treilik/bubbleboxer](https://github.com/treilik/bubbleboxer) (Go, 84 stars, MIT)
- **Status**: v1 ready
- **Language**: PHP 8.3+
- **Files**: 3 source files (Boxer.php 408L, Node.php 359L, Lang.php 22L), 26 unit tests, 3 examples
- **Dependencies**: `candy-core` (Width::string), `candy-sprinkles` (Border, Style, Align, VAlign)
- **Report Date**: 2026-05-27

---

# Overview

sugar-boxer is a **pure layout renderer** — a recursive tree-based box-drawing layout engine that takes a tree of immutable `Node` objects and produces an ANSI string for a fixed viewport. It ports [treilik/bubbleboxer](https://github.com/treilik/bubbleboxer) from Go, adapting it from a `tea.Model` wrapper into a standalone PHP layout engine.

**Ecosystem Positioning**: sugar-boxer is a foundational layout primitive at the same level as `candy-shine`'s table rendering — both are output producers that render to strings. It sits between the low-level cell-grid foundation (candy-core) and the full widget layer (sugar-bits). Unlike the upstream bubbleboxer (which wraps tea.Model instances), sugar-boxer is a pure string renderer focused purely on H/V panel composition with box-drawing borders.

**Biggest Opportunity Areas**:
1. **Buffer diffing** — currently full re-render every call; external repos (ultraviolet, ratatui, php-tui) use touched-line diffing for bandwidth optimization
2. **Wide-character overflow handling** — CJK/emoji characters write to one cell without filling adjacent cells
3. **Constraint-based layout** — external repos (ultraviolet, ratatui, php-tui) use Cassowary constraint solver for more flexible layouts
4. **ContentGenerator pattern** — upstream stickers has `func(maxX, maxY) string` per cell for adaptive content

**Biggest Missing Capabilities**:
1. No dirty-region tracking or diffing
2. No constraint-based layout (tree-only)
3. No wide-character overflow handling
4. No dynamic content generation
5. No mouse/click handling (not applicable — pure renderer)
6. No interactive resize handling

---

# Internal Capability Summary

## Current Architecture

```
SugarBoxer (render engine, 408 lines)
  └─ Node (immutable tree node, 359 lines)
       ├─ LEAF        — content string
       ├─ HORIZONTAL  — side-by-side children with vertical separator
       ├─ VERTICAL   — stacked children with horizontal separator
       └─ NOBORDER   — flat wrapper (no internal separators)
```

**Rendering Pipeline**:
1. `render(Node, width, height)` creates a 2D `$cells[y][x]` char-cell grid
2. `renderNode()` dispatches on node kind to type-specific renderers
3. Each renderer: computes available space → distributes to children → recurses → draws separators
4. Output: `implode("\n", array of joined rows)`

**Node Properties** (16 readonly properties):
- Core: `kind`, `children[]`, `content`
- Dimensions: `minWidth`, `maxWidth`, `minHeight`, `maxHeight`
- Visual: `padding`, `border`, `spacing`, `borderStyle`, `style`, `title`, `margin`
- Alignment: `alignH`, `alignV`

**Key Design Patterns**:

1. **Immutable with*() builders** — private `with()` helper with sentinel `nop(): \stdClass` pattern distinguishes "preserve" from explicit null, enabling clean multi-call chaining

2. **Char-cell grid storage** — 2D array of single-character strings avoids UTF-8 multibyte glyph corruption that would occur with direct string indexing

3. **Weighted round-robin distribution** — `distribute()` function uses integer arithmetic with rounding to allocate space proportionally without floating-point imprecision

4. **Sprinkles composition** — Node carries optional `candy-sprinkles` types (`Border`, `Style`, `Align`, `VAlign`) as typed state rather than subclassing or reimplementing

## Current Features

- ✅ H/V composition (arbitrary nesting)
- ✅ Box-drawing borders (10 border styles via candy-sprinkles)
- ✅ No-border mode (flat adjacent panels)
- ✅ Per-panel padding (clamped to prevent content-area collapse)
- ✅ Width/Height hints (`minWidth`, `minWidth`, `minHeight`, `maxHeight`)
- ✅ Dynamic dimension calculation (compute total from children)
- ✅ Leaf content (any string)
- ✅ Title text (rendered in top border)
- ✅ Outer margin (top/right/bottom/left)
- ✅ Text alignment (horizontal via `alignH`, vertical via `alignV`)
- ✅ ANSI-aware width calculation (via `candy-core` Width::string)
- ✅ Word wrapping (with oversize-word splitting via `mb_substr`)
- ✅ i18n infrastructure (Lang facade)

## APIs

| Method | Description |
|--------|-------------|
| `SugarBoxer::new()` | Zero-arg factory |
| `$boxer->leaf(string)` | Leaf node with string content |
| `$boxer->horizontal(Node ...)` | Horizontal (row) layout |
| `$boxer->vertical(Node ...)` | Vertical (column) layout |
| `$boxer->noBorder(Node)` | Flat layout without separators |
| `$boxer->render(Node, int, int)` | Render to ANSI string |
| `Node::leaf/horizontal/vertical/noBorder()` | Static constructors |
| `->withMinWidth/MaxWidth/MinHeight/MaxHeight(int)` | Dimension hints |
| `->withPadding(int)` | Inner padding |
| `->withBorder(bool)` | Show/hide border |
| `->withSpacing(int)` | Gap between children |
| `->withBorderStyle(?Border)` | Border character set |
| `->withStyle(?Style)` | ANSI style |
| `->withTitle(string)` | Box title |
| `->withMargin(int, ...)` | Outer margin |
| `->withAlignH(Align)` | Horizontal text alignment |
| `->withAlignV(VAlign)` | Vertical text alignment |

## Extension Systems

None — sugar-boxer is a pure renderer, not a component framework. Extension would require wrapping at the consumer level.

## Strengths

1. **Clean separation of layout from rendering** — layout algorithm is decoupled from the cell-grid rendering
2. **Immutable nodes** — safe sharing across the monorepo, no defensive copying needed
3. **PHP-native** — strict types, readonly properties, no Go interop complexity
4. **Delegates to canonical types** — uses `candy-sprinkles` Border/Style/Align/VAlign consistently
5. **ANSI-aware width** — `Width::string()` handles ANSI escapes, wide chars, combining chars
6. **Zero external deps** — only depends on monorepo libs
7. **Good test coverage** — 26 unit tests covering core functionality
8. **Sentinel pattern** — cleanly handles nullable fields in with* chains

## Weaknesses

1. **No dirty-region tracking** — full re-render on every `render()` call
2. **No constraint-based layout** — tree-based approach cannot express constraints like "30% but never less than 10"
3. **Wide-character overflow** — CJK/emoji writes to one cell; adjacent cell not automatically filled
4. **No dynamic content** — leaf nodes hold static strings only
5. **No mouse/click handling** — not applicable (pure renderer) but worth noting
6. **Separator placement limited** — vertical/horizontal separators always at child boundaries
7. **No auto-sizing algorithm** — `render()` requires explicit width/height; no auto-fit to content
8. **No nested border styling** — each node has one border style; no mixed border styles in a single panel

---

# Relevant External Repositories

| Repo | Relevance | Major applicable concepts | Priority |
|------|-----------|------------------------|----------|
| [treilik/bubbleboxer](https://github.com/treilik/bubbleboxer) | Primary upstream | tea.Model wrapper, ModelMap indirection, EditLeaf with rollback | Critical |
| [charmbracelet/ultraviolet](docs/repo_map/charmbracelet_ultraviolet.md) | Foundational layer | Cell-based diffing, touched-line tracking, Cassowary constraints, wide-char overflow handling | Critical |
| [charmbracelet/lipgloss](docs/repo_map/charmbracelet_lipgloss.md) | Styling foundation | Style system, border presets, layout helpers (Place, Join) | High |
| [76creates/stickers](docs/repo_map/76creates_stickers.md) | Layout component | FlexBox ratio algorithm, ContentGenerator, dimension locking | High |
| [ratatui/ratatui](docs/repo_map/ratatui_ratatui.md) | Full TUI framework reference | Cassowary layout, Widget/StatefulWidget traits, buffer diffing, immediate-mode rendering | High |
| [php-tui/php-tui](docs/repo_map/php-tui_php-tui.md) | PHP TUI reference | Cassowary layout, widget renderer visitor pattern, double-buffering with diff | High |
| [textualize/textual](docs/repo_map/textualize_textual.md) | Full TUI framework reference | CSS flexbox layout, reactive state, message pump, compositor with dirty-region | Medium |

---

# Feature Gap Analysis

## Critical

### 1. Buffer Diffing / Dirty-Region Tracking
**Title**: Full re-render on every call; no optimization for unchanged regions

**Description**: sugar-boxer recomputes and outputs the entire layout on every `render()` call. External repos (ultraviolet, ratatui, php-tui) track touched lines and only emit delta ANSI sequences.

**Why it matters**: For SSH connections or slow terminals, full re-render wastes bandwidth. The touched-line approach sends only changed cells via optimized escape sequences (ECH for erase, REP for repeat, ICH/DCH for insert/delete).

**Source**: `charmbracelet_ultraviolet.md` — "Cell-based diffing renderer — only redraws what changed; optimizes cursor movement; uses ECH/REP/ICH/DCH escape sequences when available"

**Source**: `ratatui_ratatui.md` — "Double-Buffered Rendering: Internal frame diffing - only changed cells are written to terminal"

**Implementation ideas**:
1. Wrap `SugarBoxer::render()` output in a diffing layer that compares previous frame's cell grid to current
2. Track which cells changed and emit only the minimal ANSI sequence delta
3. Consider a `Renderer` interface with `render()` and `diff(previousGrid)` methods

**Estimated complexity**: Medium — requires new abstraction layer and ANSI escape sequence knowledge

**Expected impact**: High for network use cases; moderate for local terminal use

### 2. Wide-Character Overflow Handling
**Title**: CJK/emoji characters overflow into adjacent cells without fill

**Description**: When rendering a wide character (CJK, emoji) into the cell grid, only the primary cell is written. The adjacent cell is not automatically filled with a blank, causing potential visual artifacts when the next character is written.

**Source**: `charmbracelet_ultraviolet.md` — "Wide Character Handling — When overwriting part of a wide character (emoji, CJK), fills remaining width with blank cells to avoid rendering artifacts. Placeholder zero-width cells for wide char trailers are handled symmetrically."

**Implementation ideas**:
1. After `setChar()`, check if the character is wide (via `Width::string() > 1`) and fill subsequent cell(s) with empty string or ANSI "clear" sequence
2. When overwriting a wide character from a previous frame, must clear both cells
3. Add `clearCell()` method that handles both single and wide char clearing

**Estimated complexity**: Low — mostly additive logic in `setChar()`

**Expected impact**: High for international users; visible improvement for any terminal with CJK/emoji content

## High Value

### 3. Constraint-Based Layout (Cassowary Solver)
**Title**: Tree-based layout cannot express competing constraints

**Description**: sugar-boxer uses weighted round-robin distribution which is simple but cannot express constraints like "column A should be 30% of total width but never less than 10 cells". External repos (ultraviolet, ratatui, php-tui) use Cassowary constraint solver.

**Why it matters**: Complex responsive layouts need constraint solving — e.g., a sidebar that should be 20% width but minimum 20 cells and maximum 40 cells, with remaining space going to main content.

**Source**: `charmbracelet_ultraviolet.md` — "Cassowary constraint-based layout solver — partitions screen space using Len, Min, Max, Percent, Ratio, Fill constraints with priority-based conflict resolution"

**Source**: `ratatui_ratatui.md` — "Layout System: Cassowary constraint solver-based layout (Layout, Constraint, Direction, Flex)"

**Implementation ideas**:
1. Port the Cassowary algorithm (via `honey-bounce` or new `candy-constraint` lib)
2. Add `withConstraint()` methods to Node for Min/Max/Percent/Ratio constraints
3. Before rendering, run solver to compute actual dimensions from constraints
4. Keep tree-based layout as fallback for simple cases

**Estimated complexity**: High — Cassowary is non-trivial algorithmically

**Expected impact**: High for complex layouts; enables truly responsive H/V compositions

### 4. ContentGenerator Pattern
**Title**: Leaf nodes hold static strings only; no per-cell adaptive content

**Description**: sugar-boxer leaf nodes hold raw strings. Upstream stickers has `ContentGenerator(func(maxX, maxY) string)` that recomputes content based on actual allocated space at render time, enabling adaptive text truncation/wrapping without pre-measuring.

**Why it matters**: For content that should adapt to available space (e.g., a file path that truncates with ellipsis when narrow, shows full path when wide), ContentGenerator avoids pre-measuring and allows the leaf itself to decide how to fill its allocated area.

**Source**: `76creates_stickers.md` — "Content generators: Cells can hold either static content (SetContent) or dynamic content via SetContentGenerator(func(maxX, maxY) int) string) for per-cell adaptive text wrapping/display"

**Implementation ideas**:
1. Add `Node::withContentGenerator(callable): self` that accepts `Closure(int $maxX, int $maxY): string`
2. Store as `?Closure` in Node alongside `content`
3. At render time, if `contentGenerator !== null`, call it with `(pcw, pch)` to get actual content string
4. Keep `content` as precomputed fallback for when generator not set

**Estimated complexity**: Low — mostly additive to Node and renderLeaf()

**Expected impact**: Medium — ergonomic improvement for adaptive content scenarios

### 5. Dimension Locking / Fixed Size
**Title**: No equivalent to stickers' LockRowHeight/LockColumnWidth

**Description**: In stickers, `LockRowHeight()` disables vertical scaling for a specific row, forcing it to maintain a fixed height regardless of available space. sugar-boxer has no equivalent — all dimension hints are soft.

**Why it matters**: For dashboard-style layouts where a header or footer panel should remain a fixed size while the main content area expands/shrinks, dimension locking is essential.

**Implementation ideas**:
1. Add `withFixedWidth(int)` / `withFixedHeight(int)` methods to Node
2. These set a hard constraint that overrides weighted distribution for that axis
3. In `distribute()`, treat nodes with fixed size as having weight 0 and size equal to fixed value

**Estimated complexity**: Low — additive logic in distribute()

**Expected impact**: Medium — common pattern for dashboard-like UIs

## Medium

### 6. Auto-Sizing / Content-Fit
**Title**: render() requires explicit width/height; no auto-fit to content

**Description**: sugar-boxer's `render()` takes explicit viewport dimensions. It does not compute required dimensions from content (like CSS `fit-content`). External layout systems can query "how big does this need to be" before rendering.

**Why it matters**: For cases where the layout should adapt to content size rather than viewport size (e.g., a dialog that sizes to fit its content).

**Implementation ideas**:
1. Add `Boxer::measure(Node): [width, height]` that computes total dimensions without rendering
2. Node already has `totalWidth()` and `totalHeight()` but these use minWidth/minHeight, not actual measured content
3. Add `Node::measureContent(): [int, int]` that calls strWidth() on content strings

**Estimated complexity**: Low — `totalWidth()`/`totalHeight()` already exist but use min* hints not actual content

**Expected impact**: Medium — enables layout-first-then-size approaches

### 7. Mixed Border Styles Within Panels
**Title**: Single border style per node; no mixed inner/outer borders

**Description**: Each node has one `borderStyle` that applies to its entire perimeter. External libraries like ratatui's Block support `inner` and `outer` border types separately for complex panel compositions.

**Why it matters**: For compound borders like double-line outer with single-line inner separators, the current single-style approach requires multiple nodes with different border styles manually composed.

**Implementation ideas**:
1. Add `withInnerBorderStyle(?Border)` alongside existing `borderStyle`
2. For compound borders, use `withBorderStyle()` for outer and `withInnerBorderStyle()` for inner separators

**Estimated complexity**: Low — additive to Node and renderHorizontal/renderVertical

**Expected impact**: Low-medium — niche use case

## Low Priority

### 8. Title Positioning
**Title**: Title is drawn but positioning within top border is fixed

**Description**: While Node supports `title`, the actual title rendering position within the top border is implicit and not configurable (typically centered or left-aligned). External libs support title position options.

**Implementation ideas**:
1. Add `withTitlePosition(TitlePosition)` enum (Left, Center, Right)
2. In `drawBorder()`, when title is set, draw title text at the specified position within top edge

**Estimated complexity**: Low

**Expected impact**: Low — cosmetic improvement

### 9. Gap/Grid Layout Mode
**Title**: Only H/V tree distribution; no CSS grid-style two-dimensional layout

**Description**: sugar-boxer only supports linear H/V composition. CSS grid provides two-dimensional placement where items can span multiple rows/columns. Not critical but a gap versus stickers FlexBox.

**Implementation ideas**:
1. Consider adding `Grid` node type with explicit row/column span
2. Or add to `sugar-stickers` as a separate component

**Estimated complexity**: High

**Expected impact**: Low — niche use case

---

# Algorithm / Performance Opportunities

## Current Approach vs External

### Layout Algorithm

**sugar-boxer**: Weighted round-robin via `distribute()`
```php
$share = (int) \round($weights[$i] / $totalWeight * ($available - $spacing * ($n - 1)));
$share = \max($share, 1);
```

**stickers (Go)**: Two-pass floor division + remainder round-robin
```go
func calculateRatio(distribute int, matrix []int) []int {
    distribution, remainder := distributeToMatrix(distribute, combinedRatio, matrix)
    return distributeRemainder(remainder, matrixMaxRatio)
}
```

**ratatui/php-tui**: Cassowary constraint solver
```php
$layout->constraints([
    Constraint::percentage(20),
    Constraint::min(20),
    Constraint::max(40),
    Constraint::fill(),
])
```

**Tradeoffs**:
- Round-robin vs floor-robin: sugar-boxer and stickers both distribute integer space; sugar-boxer uses single-pass rounding, stickers uses two-pass (floor + remainder round-robin)
- Weighted vs constraint-based: Weighted is simpler and faster O(n) but cannot express competing constraints; Cassowary is O(n²) but handles constraints with priority

**Applicability**: The `distribute()` function is already adequate for simple cases. The gap is in complex constraint scenarios, not basic weighted distribution.

### Rendering Diffing

**sugar-boxer**: Full string re-render every frame
```php
public function render(Node $root, int $width, int $height): string {
    $cells = \array_fill(0, $height, \array_fill(0, $width, ' '));
    $this->renderNode($root, 0, 0, $width, $height, $cells);
    // ... join cells into string
}
```

**ultraviolet**: Touched-line tracking + minimal ANSI sequences
```go
func (rb *RenderBuffer) TouchLine(firstCell, lastCell int)
func (tr *TerminalRenderer) Render() {
    // diffs previous vs current buffer, emits only delta sequences
}
```

**Tradeoffs**: Full re-render is simpler but wastes bandwidth; diffing requires tracking state across frames and computing minimal sequences. Diffing is critical for SSH but overkill for local terminal use.

**Applicability**: This is a significant architectural decision. A diffing layer could wrap sugar-boxer without modifying the core engine.

---

# Architecture Improvements

## 1. Separation of Layout from Rendering

**Current**: `SugarBoxer` mixes layout computation and cell-grid rendering in the same class.

**Opportunity**: Consider separating `LayoutEngine` (tree → rectangle assignment) from `Renderer` (rectangles → string). This would enable:
- Reusing layout engine with different rendering backends
- Easier testing of layout algorithm independent of cell-grid
- Future support for different output formats (ANSI, HTML, etc.)

**Implementation**:
```php
interface LayoutEngine {
    public function layout(Node $root, int $width, int $height): LayoutResult;
}

interface Renderer {
    public function render(LayoutResult): string;
}
```

## 2. Node Visitor Pattern

**Current**: Node dispatch is via `kind` string constants and a switch in `renderNode()`.

**Opportunity**: Use a proper Visitor pattern for extensibility:
- Add new node types without modifying the main switch
- Enable custom rendering per node type via visitor implementation
- Easier to add debug/trace rendering

**Implementation**: Add `accept(Visitor $v)` to Node and `visitLeaf/visitHorizontal/etc()` to Visitor interface.

## 3. Virtual Cell Grid

**Current**: `render()` creates a full `$cells` 2D array in memory.

**Opportunity**: For very large viewports, consider a virtual/streaming approach that writes directly to output without materializing the full grid. This is a micro-optimization but could matter for large dashboards.

---

# API / Developer Experience Improvements

## 1. Node::withContent() Creates New LEAF

**Current**:
```php
public function withContent(string $content): self {
    return new self(self::LEAF, content: $content);
}
```
This creates a LEAF node but loses all parent properties (border, padding, etc.).

**Opportunity**: `withContent()` should preserve parent properties while only changing the content. This is more ergonomic for updating leaf content in an existing styled node.

## 2. Convenient Dimension Factories

**Current**: Setting multiple dimensions requires chaining:
```php
$node->withMinWidth(20)->withMinHeight(10)->withPadding(1)
```

**Opportunity**: Add a single `withDimensions()` method:
```php
$node->withDimensions(minWidth: 20, minHeight: 10, padding: 1)
```

## 3. Builder Pattern for SugarBoxer

**Current**: `SugarBoxer::new()` creates empty boxer; layout built via static `Node::*` factories.

**Opportunity**: Consider a more fluid builder API:
```php
$layout = SugarBoxer::build(function($b) {
    $b->vertical(function($v) {
        $v->horizontal(function($h) {
            $h->leaf('Left');
            $h->leaf('Right');
        });
        $v->leaf('Bottom');
    });
});
```

## 4. Debug Output

**Current**: No debug/dump utilities.

**Opportunity**: Add `Node::debugPrint()` that returns a tree representation of the node hierarchy for debugging layout issues.

---

# Documentation / Cookbook Opportunities

## 1. Layout Recipes

**Opportunity**: Document common layout patterns:
- Sidebar + main content layout
- Header + body + footer layout
- Multi-panel dashboard layout
- Nested border panels with different styles
- Grid of equal-sized panels

## 2. Integration Guides

**Opportunity**: Document how sugar-boxer integrates with other SugarCraft libs:
- Using sugar-boxer with `candy-core` TTY loop
- Composing sugar-boxer layouts inside sugar-bits Viewport
- Combining sugar-boxer with sugar-stickers FlexBox

## 3. Performance Tips

**Opportunity**: Document rendering performance characteristics:
- When to use `minWidth` vs explicit sizing
- Tradeoffs of deep nesting
- When to cache render results vs re-render

---

# UX / TUI Improvements

## 1. Title Rendering Options

**Current**: Title text is drawn with border but positioning is implicit.

**Opportunity**: Add title alignment within top border:
```php
$node->withTitle('My Panel')
     ->withTitleAlign(Align::CENTER); // or LEFT/RIGHT
```

## 2. Interactive Resize Preview

**Not applicable**: sugar-boxer is a pure renderer, not an interactive component. However, when used within a TUI app, sugar-boxer could support a "preview mode" that highlights panel boundaries and dimensions during resize operations.

---

# Testing / Reliability Improvements

## 1. Snapshot Tests for Render Output

**Current**: Tests verify internal state and some output but not full render snapshots.

**Opportunity**: Add snapshot tests (using `phpunit snapshot assertions`) that verify exact SGR byte output for known layouts. This catches regressions in border characters, spacing, etc.

## 2. Fuzz Testing for Dimension Calculations

**Current**: Unit tests cover known dimension scenarios.

**Opportunity**: Add fuzz testing that generates random node trees and viewport dimensions, verifying:
- No cell writes outside grid bounds
- All children receive at least 1 cell of space
- Total allocated space equals available space minus gaps

## 3. Wide Character Test Coverage

**Current**: Limited explicit testing of CJK/emoji content.

**Opportunity**: Add explicit test cases for wide character rendering, verifying:
- Wide chars don't overflow into next content area
- Wide chars in bordered vs borderless nodes
- Mixed narrow + wide content in same leaf

---

# Ecosystem / Integration Opportunities

## 1. Integration with sugar-stickers FlexBox

**Opportunity**: sugar-stickers FlexBox provides ratio-based layout; sugar-boxer provides box-drawing. A `FlexBox::withBoxer()` could render FlexBox cells with box borders.

**Source**: `sugarcraft_sugar-stickers.md` — "No HorizontalFlexBox: Upstream's HorizontalFlexBox (column-first layout) not implemented" and "No ContentGenerator: Upstream Go Cell.SetContentGenerator not ported"

## 2. Integration with sugar-bits Viewport

**Opportunity**: sugar-bits Viewport provides scrollable regions. sugar-boxer could be rendered inside a Viewport for scrollable box layouts.

**Source**: `sugarcraft_sugar-bits.md` — Viewport provides "Scrollable text region with setWidth(), setHeight(), mouse wheel support"

## 3. Integration with candy-shell

**Opportunity**: candy-shell handles TTY initialization. sugar-boxer could provide a `Shell::box()` helper that initializes the terminal, renders a box layout, and waits for resize events.

---

# Notable PRs / Issues / Discussions

## From Upstream (treilik/bubbleboxer)

### ModelMap Indirection
**Issue**: bubbleboxer wraps each leaf in a `ModelMap` that holds `tea.Model` instances. This enables integration with the broader Bubbletea ecosystem but adds complexity.

**Lesson for sugar-boxer**: sugar-boxer correctly chose a simpler approach — raw string content — given PHP's lack of a Bubbletea runtime. Don't over-engineer for a use case that doesn't exist.

**Source**: `sugarcraft_sugar-boxer.md` §1.1

### SizeFunc for Custom Sizing
**Discussion**: bubbleboxer supports `SizeFunc func(node, dim) []int` for custom layout algorithms beyond weighted distribution.

**Lesson**: This could be ported as `withSizeFunc()` on Node, but it's a lower priority than the constraint-based layout approach since Cassowary already solves the general case.

**Source**: `sugarcraft_sugar-boxer.md` §2.1

## From External Repos

### ultraviolet's Style Diff Algorithm
**PR**: `charmbracelet/ultraviolet` implements `StyleDiff()` that computes minimal SGR sequence to transition between styles.

**Lesson**: sugar-boxer currently renders each cell with the node's `Style` but doesn't track previous frame's style for delta optimization. If diffing is added, style transitions would benefit from similar optimization.

**Source**: `charmbracelet_ultraviolet.md` — "Style Diff Algorithm — Computes minimal SGR sequence to transition from one style to another"

### ratatui's Widget Trait Pattern
**Discussion**: ratatui's `Widget` trait with `impl Widget for &str` allows ergonomic string rendering without explicit wrapping.

**Lesson**: sugar-boxer could benefit from a similar trait — `Drawable` — that accepts strings directly:
```php
$boxer->render('Hello World', 40, 10); // auto-wrap as leaf
```

**Source**: `ratatui_ratatui.md` — "impl<W: Widget> Widget for &W { ... } impl Widget for &str { ... }"

### textual's CSS Layout
**Discussion**: textual implements full CSS flexbox via TCSS stylesheet.

**Lesson**: While a full CSS layout engine is out of scope for sugar-boxer, the Justify/Align concepts map to sugar-boxer's `spacing` and `alignH/alignV`. The CSS flexbox model could inspire future layout directions.

**Source**: `textualize_textual.md` — "CSS Layout Algorithm — Box model, flexbox-like layouts via display: flex"

---

# Recommended Roadmap

## Immediate Wins (0-2 weeks)

1. **Wide-character overflow handling** — Fix `setChar()` to fill adjacent cells when writing wide chars
2. **Auto-sizing / measure()** — Add `Boxer::measure(Node): [int, int]` using actual content width
3. **ContentGenerator** — Add `Node::withContentGenerator(Closure): self` for adaptive content
4. **Fixed dimension locking** — Add `withFixedWidth()/withFixedHeight()` for hard size constraints

## Medium-Term (1-3 months)

5. **Buffer diffing layer** — Wrap `render()` with a diffing renderer that only emits changed cells
6. **Constraint-based layout** — Port Cassowary solver (via honey-bounce or new lib) with Min/Max/Percent/Ratio constraints on Node
7. **Mixed border styles** — Add `withInnerBorderStyle()` for compound border panels
8. **Snapshot tests** — Add pixel-accurate SGR byte tests for known layouts

## Major Architectural (3-6 months)

9. **Layout/Renderer separation** — Refactor into `LayoutEngine` + `Renderer` interfaces
10. **Visitor pattern** — Replace kind-switch dispatch with proper Visitor pattern
11. **Node debug utilities** — `debugPrint()`, layout tree visualization

## Experimental (6+ months)

12. **CSS grid layout** — Two-dimensional placement with row/column spanning
13. **Streaming renderer** — Virtual grid that writes directly to output stream without materializing full array
14. **Async rendering** — Leverage ReactPHP for non-blocking render of large layouts

---

# Priority Matrix

| Opportunity | Impact | Complexity | Risk | Priority |
|-------------|--------|------------|------|----------|
| Wide-character overflow handling | High | Low | Low | Immediate |
| Auto-sizing / measure() | Medium | Low | Low | Immediate |
| ContentGenerator pattern | Medium | Low | Low | Immediate |
| Fixed dimension locking | Medium | Low | Low | Immediate |
| Buffer diffing layer | High | Medium | Medium | Medium-term |
| Constraint-based layout | High | High | Medium | Medium-term |
| Mixed border styles | Low | Low | Low | Medium-term |
| Snapshot tests | Medium | Low | Low | Medium-term |
| Layout/Renderer separation | Medium | High | Medium | Major |
| Visitor pattern | Medium | Medium | Low | Major |
| Node debug utilities | Low | Low | Low | Major |
| CSS grid layout | Medium | High | High | Experimental |
| Streaming renderer | Low | Medium | Medium | Experimental |
| Async rendering | Low | High | High | Experimental |

---

# Final Strategic Assessment

sugar-boxer is a well-implemented, focused layout engine that correctly adapts its upstream Go library for PHP's context. The core H/V composition model with weighted distribution is sound and serves most use cases admirably. The most significant gaps versus external repos are:

1. **Rendering efficiency** — Full re-render every frame versus touched-line diffing (shared with ratatui/ultraviolet/php-tui gap)
2. **Layout expressiveness** — Tree-based weighted distribution versus constraint-based Cassowary solving (shared with ratatui/php-tui gap)
3. **Content adaptation** — Static leaf strings versus ContentGenerator (unique to sugar-boxer gap vs stickers)

The immediate wins (wide-char handling, ContentGenerator, dimension locking) address real ergonomic and correctness issues without major architectural change. The medium-term investments (diffing, constraints) are more significant but would substantially close the gap with leading TUI layout systems.

The key strategic decision is whether to evolve sugar-boxer toward:
- **Full constraint-based layout** (Cassowary solver) — more powerful but higher complexity
- **Incremental improvements** (diffing, content generator) — lower complexity with targeted impact

Given that sugar-boxer is v1-ready and serves as a foundational primitive, the recommendation is to pursue the immediate wins first, then evaluate whether the Cassowary investment is warranted based on consumer needs.

The immutable Node pattern with with*() builders is a strength — it enables safe sharing and predictable state. The sentinel `nop()` pattern is a clever solution to PHP's lack of ability to distinguish "not passed" from "explicitly null" in variadic contexts.

The char-cell grid approach is correct and avoids the UTF-8 multibyte pitfall. The wide-character overflow issue is the one correctness bug in the current implementation and should be fixed immediately.

Overall, sugar-boxer is a solid v1 that correctly positions itself as a pure layout renderer in the SugarCraft ecosystem. Its simplicity is a feature, not a limitation — it does one thing well. The improvements outlined above would enhance that foundation without changing the fundamental nature of the library.
