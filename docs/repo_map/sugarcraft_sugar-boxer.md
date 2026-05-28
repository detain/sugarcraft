# sugar-boxer Innovation & Comparison Report

## Metadata
- **Library**: `sugarcraft/sugar-boxer`
- **Upstream Primary**: [treilik/bubbleboxer](https://github.com/treilik/bubbleboxer) (Go, 84 stars, MIT)
- **Status**: v1 ready
- **Language**: PHP 8.3+
- **Files**: 3 source files (Boxer.php 408L, Node.php 359L, Lang.php 22L), 26 unit tests, 3 examples
- **Dependencies**: `candy-core` (Width::string), `candy-sprinkles` (Border, Style, Align, VAlign)
- **Key Innovation**: Recursive tree-based box-drawing layout engine with immutable Node composition and ANSI-aware width calculation

---

## 1. Deep Internal Analysis

### 1.1 Architecture Overview

sugar-boxer is a **pure layout renderer** — it takes a tree of `Node` objects and produces an ANSI string for a fixed viewport. Unlike the upstream Go `bubbleboxer` which wraps actual `tea.Model` instances via a `ModelMap` indirection, sugar-boxer operates directly on string content at leaf nodes.

```
SugarBoxer (render engine)
  └─ Node (immutable tree node)
       ├─ LEAF        — content string
       ├─ HORIZONTAL  — side-by-side children with vertical separator
       ├─ VERTICAL   — stacked children with horizontal separator
       └─ NOBORDER   — flat wrapper (no internal separators)
```

### 1.2 LayoutTree Structure (`Node.php`, lines 32–94)

The `Node` class is an immutable value object with 16 readonly properties:

```php
final class Node
{
    public const LEAF       = 'leaf';
    public const HORIZONTAL = 'horizontal';
    public const VERTICAL   = 'vertical';
    public const NOBORDER   = 'noborder';

    // Core tree properties
    public readonly string $kind;           // node type
    public readonly array $children;       // child nodes (empty for LEAF)
    public readonly string $content;       // leaf string content

    // Dimension constraints
    public readonly int $minWidth;
    public readonly int $maxWidth;
    public readonly int $minHeight;
    public readonly int $maxHeight;

    // Visual properties
    public readonly int $padding;         // inner padding (cells)
    public readonly bool $border;          // show/hide box border
    public readonly int $spacing;         // gap between children (cells)
    public readonly ?Border $borderStyle;  // from candy-sprinkles
    public readonly ?Style $style;         // from candy-sprinkles
    public readonly string $title;         // box title text
    /** @var array<int,int,int,int> */
    public readonly array $margin;         // outer spacing (top/right/bottom/left)
    public readonly ?Align $alignH;       // horizontal text alignment
    public readonly ?VAlign $alignV;      // vertical text alignment
}
```

**Factory constructors** (lines 100–121):
```php
Node::leaf(string $content = ''): self
Node::horizontal(Node ...$children): self
Node::vertical(Node ...$children): self
Node::noBorder(Node $child): self   // no internal separators
```

**With* builders** (lines 127–234) implement immutable fluent pattern:
```php
$node->withMinWidth(20)
     ->withMaxWidth(80)
     ->withPadding(1)
     ->withBorder(true)
     ->withSpacing(1)
     ->withBorderStyle(Border::rounded())
     ->withMargin(1, 2, 1, 2)
     ->withAlignH(Align::CENTER);
```

**Sentinel pattern for optional fields** (lines 301–305, 324–338):
The private static `nop(): \stdClass` sentinel distinguishes "do not change" from explicit `null`. This is critical when chaining multiple `with*` calls that each forward only their own changed field while passing sentinels for all others.

**Dimension queries** (lines 241–290):
```php
public function totalWidth(): int   // includes border + padding
public function totalHeight(): int  // includes border + padding
```

### 1.3 Boxer Model (`SugarBoxer.php`, lines 23–408)

The `SugarBoxer` class is the rendering engine:

```php
final class SugarBoxer
{
    public static function new(): self  // zero-arg factory

    // Node factories (delegated to Node statics)
    public function leaf(string $content = ''): Node
    public function horizontal(Node ...$children): Node
    public function vertical(Node ...$children): Node
    public function noBorder(Node $child): Node

    // Main entry point
    public function render(Node $root, int $width, int $height): string
}
```

### 1.4 Recursive Rendering Pipeline

The `render()` method (lines 69–82) creates a 2D cell grid and delegates to `renderNode()`:

```php
public function render(Node $root, int $width, int $height): string
{
    // 2D cell grid: each cell holds one logical character (any byte length)
    // Storing as char-cells avoids byte/multibyte boundary corruption
    $cells = \array_fill(0, $height, \array_fill(0, $width, ' '));
    $this->renderNode($root, 0, 0, $width, $height, $cells);

    $out = [];
    foreach ($cells as $row) {
        $out[] = \implode('', $row);
    }
    return \implode("\n", $out);
}
```

**Why char-cell storage?** PHP's native string indexing operates on bytes, but UTF-8 box-drawing glyphs are 3+ bytes each. Using a 2D array of single-character strings ensures `$cells[$y][$x]` never splits a multibyte glyph.

**Node-type dispatch** (lines 98–111):
```php
switch ($node->kind) {
    case Node::LEAF:       $this->renderLeaf(...);       break;
    case Node::HORIZONTAL: $this->renderHorizontal(...); break;
    case Node::VERTICAL:   $this->renderVertical(...);   break;
    case Node::NOBORDER:   $this->renderNoBorder(...);    break;
}
```

### 1.5 Composite Tree Pattern (Horizontal/Vertical)

**Horizontal rendering** (lines 146–189):
- Children placed side-by-side with vertical separators (`│`)
- Width distributed proportionally by `minWidth` weights
- Outer border drawn first, then children, then vertical dividers between children

```php
private function renderHorizontal(Node $node, int $x, int $y, int $w, int $h, array &$cells): void
{
    $children = $node->children;
    $n = \count($children);
    if ($n === 0) return;

    $b  = $node->border ? 1 : 0;
    $sp = $node->spacing;

    $availableW = $w - $b * 2;
    $availableH = $h - $b * 2;

    // Distribute width proportionally by minWidth weights
    $weights = \array_map(fn(Node $c) => $c->minWidth > 0 ? $c->minWidth : 1, $children);
    $totalWeight = \array_sum($weights);

    $offsets = $this->distribute($availableW, $weights, $totalWeight, $sp, $b);

    // Draw outer border first
    if ($node->border) {
        $this->drawBorder($node->borderStyle, $x, $y, $w, $h, $cells);
    }

    // Render each child
    for ($i = 0; $i < $n; $i++) {
        $child = $children[$i];
        $ox = $x + $offsets[$i];
        $ow = $i === $n - 1
            ? $w - $b - $offsets[$i]
            : $offsets[$i + 1] - $offsets[$i] - $sp;

        $this->renderNode($child, $ox, $y + $b, $ow, $availableH, $cells);

        // Draw vertical separator between children
        if ($i < $n - 1 && $sp === 0) {
            $this->drawVLine($node->borderStyle, $x + $offsets[$i + 1] - 1, $y + $b, $availableH, $cells);
        }
    }
}
```

**Width distribution algorithm** (`distribute()`, lines 385–397):
```php
private function distribute(int $available, array $weights, int $totalWeight, int $spacing, int $borderPad): array
{
    $n = \count($weights);
    $offsets = [0 => $borderPad];

    for ($i = 0; $i < $n - 1; $i++) {
        $share = (int) \round($weights[$i] / $totalWeight * ($available - $spacing * ($n - 1)));
        $share = \max($share, 1);  // minimum 1 cell
        $offsets[] = $offsets[$i] + $share + $spacing;
    }

    return $offsets;
}
```

This is a weighted round-robin that:
1. Computes each child's proportional share of available space
2. Rounds to nearest integer
3. Ensures minimum 1 cell per child
4. Bakes border padding into the first offset

**Vertical rendering** (lines 191–227): Mirrors horizontal but distributes height by `minHeight` weights and draws horizontal separators (`─`) between children.

### 1.6 Size Calculation

The `distribute()` function (lines 385–397) handles weighted size allocation:

```php
$share = (int) \round($weights[$i] / $totalWeight * ($available - $spacing * ($n - 1)));
```

**Key insight**: The `* 1.0` implicit conversion from int to float happens in the `round()` call. The algorithm:
- Subtracts total spacing gaps from available space
- Distributes remaining space proportionally by weight
- Rounds each share to nearest integer
- Ensures minimum 1 cell per child via `max()`

For a 50-cell horizontal layout with 3 children (weights 1, 2, 1) and spacing=0:
- Available after borders = 46 (assuming border=2)
- Total weight = 4
- Child 1: round(1/4 × 46) = round(11.5) = 12
- Child 2: round(2/4 × 46) = round(23) = 23
- Child 3: round(1/4 × 46) = round(11.5) = 11
- Sum = 46 ✓

### 1.7 Border Rendering

**Full border** (`drawBorder()`, lines 318–341):
```php
private function drawBorder(?Border $border, int $x, int $y, int $w, int $h, array &$cells): void
{
    if ($w < 2 || $h < 2) return;

    $b = $border ?? Border::rounded();  // default to rounded

    // Corners
    $this->setChar($x,           $y,           $b->topLeft,     $cells);
    $this->setChar($x + $w - 1,  $y,           $b->topRight,    $cells);
    $this->setChar($x,           $y + $h - 1,  $b->bottomLeft,  $cells);
    $this->setChar($x + $w - 1,  $y + $h - 1,  $b->bottomRight, $cells);

    // Top/bottom edges
    for ($i = 1; $i < $w - 1; $i++) {
        $this->setChar($x + $i, $y,           $b->top,    $cells);
        $this->setChar($x + $i, $y + $h - 1,  $b->bottom, $cells);
    }

    // Left/right edges
    for ($j = 1; $j < $h - 1; $j++) {
        $this->setChar($x,           $y + $j, $b->left,  $cells);
        $this->setChar($x + $w - 1,  $y + $j, $b->right, $cells);
    }
}
```

**Vertical/horizontal separators** (`drawVLine()`, `drawHLine()`, lines 343–357):
```php
private function drawVLine(?Border $border, int $x, int $y, int $h, array &$cells): void
{
    $b = $border ?? Border::rounded();
    for ($j = 0; $j < $h; $j++) {
        $this->setChar($x, $y + $j, $b->left, $cells);
    }
}
```

### 1.8 Nested Grid Handling

Nested layouts are handled through recursive composition. A vertical node can contain horizontal nodes as children, and vice versa:

```php
$boxer->vertical(
    $boxer->horizontal(
        $boxer->leaf("A")->withMinWidth(3),
        $boxer->leaf("B")->withMinWidth(3),
    )->withMinHeight(3),
    $boxer->leaf("C")->withMinHeight(2),
)->withBorder(true)
```

The recursive `renderNode()` naturally handles arbitrary nesting depth. Each level:
1. Computes its available space (subtracting border padding)
2. Distributes space to children
3. Recurses for each child
4. Draws separators between siblings

### 1.9 ANSI-Aware Width (`strWidth()`, lines 404–407)

```php
private function strWidth(string $s): int
{
    return Width::string($s);
}
```

Delegates to `SugarCraft\Core\Util\Width::string()` for grapheme-aware width calculation. This handles:
- ANSI escape sequences (invisible, zero width)
- Wide Unicode characters (CJK, emoji) — width 2
- Combining characters (width 0 or 1 depending on context)

### 1.10 Word Wrapping (`wordWrap()`, lines 266–298)

```php
private function wordWrap(string $text, int $width): array
{
    if ($width <= 0) return [''];

    $result = [];
    foreach (\explode("\n", $text) as $paragraphLine) {
        $words = \preg_split('/\s+/', $paragraphLine) ?: [];
        $current = '';

        foreach ($words as $word) {
            $test = $current === '' ? $word : $current . ' ' . $word;
            if ($this->strWidth($test) <= $width) {
                $current = $test;
            } else {
                if ($current !== '') {
                    $result[] = $current;
                }
                if ($this->strWidth($word) > $width) {
                    // Split oversized word
                    $result = \array_merge($result, $this->splitWord($word, $width));
                } else {
                    $current = $word;
                }
            }
        }

        if ($current !== '') {
            $result[] = $current;
        }
    }

    return $result ?: [''];
}
```

Algorithm:
1. Split on newlines (paragraphs)
2. Split each paragraph on whitespace into words
3. Accumulate words into lines, checking `strWidth()` before adding
4. If adding a word would overflow, finalize current line and start new one
5. Oversized words are split via `splitWord()` using `mb_substr()`

### 1.11 Content Rendering (`renderContent()`, lines 242–259)

```php
private function renderContent(string $text, int $x, int $y, int $w, int $h, array &$cells): void
{
    if ($w <= 0 || $h <= 0) return;

    $wrapped = $this->wordWrap($text, $w);
    $linesToRender = \array_slice($wrapped, 0, $h);

    foreach ($linesToRender as $lineIdx => $line) {
        $lineY = $y + $lineIdx;
        if ($lineY >= \count($cells)) break;

        $chars = \preg_split('//u', $line, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        for ($i = 0; $i < $w; $i++) {
            $char = $chars[$i] ?? ' ';
            $this->setChar($x + $i, $lineY, $char, $cells);
        }
    }
}
```

Note: Uses `preg_split('//u', ...)` with `PREG_SPLIT_NO_EMPTY` for grapheme-level iteration. Combined with the char-cell grid storage, this ensures wide characters are handled correctly:
- Wide char like `中` is 1 grapheme but 2 cells wide
- When written at position $x, only `$cells[$y][$x]` is set — the adjacent cell is not automatically filled
- This is a known limitation (wide chars overflow into next cell without coordination)

---

## 2. Comparison Against Mapped Third-Party Repos

### 2.1 treilik/bubbleboxer (Primary Upstream)

| Aspect | bubbleboxer (Go) | sugar-boxer (PHP) |
|--------|----------------|------------------|
| **Type** | tea.Model wrapper | Pure string renderer |
| **Leaf storage** | `ModelMap` (address → tea.Model) | Raw string in `Node::$content` |
| **Model/View separation** | Yes — Boxer holds `LayoutTree` + `ModelMap` | No — content embedded directly |
| **Custom sizing** | `SizeFunc func(node, dim) []int` | Weighted via `minWidth`/`minHeight` |
| **Safe editing** | `EditLeaf()` with rollback | Immutable `with*()` builders |
| **Window size** | `UpdateSize(WindowSizeMsg)` in Update() | Explicit `render(width, height)` arg |
| **tea.Model compliance** | Yes — Init/Update/View | Not applicable (pure renderer) |
| **Error handling** | `SizeError`, `NotFoundError` | Returns empty on zero-size region |
| **Size** | ~455 lines | 408 lines (Boxer only) |

**Architectural divergence**: bubbleboxer is designed for the BubbleTea ecosystem where each leaf is an independent `tea.Model` that manages its own state and renders via `View()`. The `Boxer` recursively calls `View()` on each leaf model and assembles the results.

sugar-boxer, being a PHP port without the BubbleTea runtime, takes a simpler approach: leaf nodes hold raw string content that is rendered directly. This makes sugar-boxer a **layout engine** rather than a **component framework**.

**What sugar-boxer does better**:
- Simpler mental model — no indirection through ModelMap
- Immutable nodes via `with*()` builders — no accidental mutation
- PHP-native with strict types and readonly properties

**What bubbleboxer does better**:
- Composable with full BubbleTea component ecosystem
- Custom `SizeFunc` allows arbitrary layout algorithms
- tea.Model compliance enables integration with existing BubbleTea apps

### 2.2 charmbracelet/lipgloss

| Aspect | lipgloss (Go) | sugar-boxer (PHP) |
|--------|--------------|------------------|
| **Purpose** | Style definitions for terminal layouts | Box-drawing layout engine |
| **Style model** | Fluent pure-value `Style` type | Delegates to `candy-sprinkles` Style/Border |
| **Borders** | `Style.Border()` with 10 presets | `Node.withBorderStyle()` using same presets |
| **Layout** | Width/Height constraints, margins, padding | Tree-based H/V composition |
| **Text rendering** | `Style.Render()` applies ANSI to strings | `renderContent()` word-wraps into cells |
| **Compositing** | Layer/Compositor/Canvas for z-ordering | No z-ordering (flat grid) |
| **Layout algorithm** | None — position text manually | Recursive tree distribution |
| **Text alignment** | `Align()` via Style | `withAlignH()`/`withAlignV()` on Node |

**Relationship**: lipgloss is the **styling foundation** that bubbleboxer builds upon. sugar-boxer similarly uses `candy-sprinkles` (the lipgloss port) for `Border`, `Style`, `Align`, and `VAlign` types. sugar-boxer does NOT depend on the full styling system — it only references the border character set.

**Key insight**: lipgloss does not have a built-in layout algorithm. It provides tools to style things that are already laid out. sugar-boxer provides the layout algorithm (tree-based H/V distribution) and uses lipgloss-style border characters.

### 2.3 76creates/stickers (FlexBox/Table)

| Aspect | stickers (Go) | sugar-boxer (PHP) |
|--------|--------------|------------------|
| **Type** | Interactive tea.Model components | Pure layout renderer |
| **Layout model** | CSS flexbox-inspired ratio-based grid | Tree-based H/V with weighted distribution |
| **FlexBox children** | Row → Cell hierarchy | Node → children tree |
| **Sizing** | `ratioX`, `ratioY` + `minWidth`/`minHeight` | Weighted via `minWidth`/`minHeight` |
| **Content** | Static string or `ContentGenerator` | Static string only |
| **Separators** | Between cells (via border style) | Between H/V siblings via border chars |
| **Table features** | Sort, filter, cursor, scroll | Not applicable |
| **Dimension propagation** | `SetWidth()`/`SetHeight()` recalculates | `render()` takes explicit dimensions |

**Conceptual overlap**: Both use ratio/weight-based distribution. The `distribute()` algorithm in sugar-boxer is conceptually similar to stickers' `calculateRatio()`:
```go
// stickers flexbox/utils.go
func calculateRatio(distribute int, matrix []int) []int {
    // floor division + remainder distribution
}
```

```php
// sugar-boxer/SugarBoxer.php
private function distribute(int $available, array $weights, int $totalWeight, int $spacing, int $borderPad): array {
    // weighted round-robin
}
```

**Key difference**: stickers' FlexBox is for laying out BubbleTea components with dynamic content generation. sugar-boxer is for laying out text content with box-drawing borders.

### 2.4 charmbracelet/ultraviolet

| Aspect | ultraviolet (Go) | sugar-boxer (PHP) |
|--------|------------------|------------------|
| **Type** | Low-level terminal rendering primitives | High-level layout engine |
| **Cell model** | `Cell` struct (char + style + link + width) | Char-cell grid (`array[y][x]`) |
| **Diffing** | Touched-line tracking + minimal ANSI sequences | Full re-render on each `render()` call |
| **Layout** | Cassowary constraint solver (`layout.Layout`) | Tree-based recursive distribution |
| **Borders** | `Border()` functions (Normal, Rounded, etc.) | Delegates to `candy-sprinkles` Border |
| **Width measurement** | `WcWidth()`, `GraphemeWidth()` | Delegates to `candy-core` Width |
| **Output** | Optimized ANSI escape sequences | Raw box-drawing strings |

**Relationship**: ultraviolet is the **foundational rendering layer** that powers Bubble Tea v2 and Lip Gloss v2. It provides:
- Cell-based rendering with diffing
- Terminal input handling (keyboard/mouse)
- Cassowary constraint layout
- Border character presets

sugar-boxer sits **above** this layer. It uses ultraviolet's border character sets (via candy-sprinkles) and its width measurement utilities, but implements its own tree-based layout algorithm rather than the Cassowary constraint solver.

**SugarCraft architectural note**: In SugarCraft, `candy-core` provides the termbox-cell model and width utilities that ultraviolet provides in Go. sugar-boxer depends on `candy-core` for `Width::string()`.

### 2.5 textualize/textual

| Aspect | textual (Python) | sugar-boxer (PHP) |
|--------|----------------|------------------|
| **Type** | Full TUI framework with 40+ widgets | Pure layout renderer |
| **Layout** | CSS flexbox/grid via stylesheet | Tree-based H/V composition |
| **Styling** | TCSS (Textual CSS) with full property set | Delegates to `candy-sprinkles` |
| **State** | Reactive `var()` + watchers + message pump | Immutable nodes (no runtime state) |
| **Widget system** | 40+ built-in widgets with inheritance | None (pure renderer) |
| **Rendering** | Compositor with dirty-region tracking | Full re-render per `render()` call |
| **Output** | Rich library formatting + ANSI | Raw box-drawing strings |

**Conceptual overlap**: Both support nested box layouts, but with fundamentally different approaches:
- textual uses CSS flexbox/grid layouts with a stylesheet
- sugar-boxer uses recursive tree composition with explicit H/V nodes

**Key insight**: textual is a **complete TUI framework** that handles input, state, layout, and rendering. sugar-boxer is a **layout-only library** that takes a tree of nodes and outputs a string. It is a component within the SugarCraft ecosystem, not a framework.

---

## 3. Comparison With All Remaining Third-Party Repos

### 3.1 charmbracelet/bubbles (Components)

- **bubble-table**: Table component with sorting/filtering. sugar-boxer provides the layout primitives (nested H/V) but no table-specific features.
- **Other components**: TextInput, TextArea, Spinner, etc. — not directly related to layout.

### 3.2 ratatui/ratatui (Rust TUI framework)

- **Layout system**: Ratatui uses Cassowary constraints (like ultraviolet) for layout. sugar-boxer uses tree-based distribution.
- **Widget system**: Ratatui has 18+ widgets; sugar-boxer has none.
- **Rendering**: Ratatui uses buffer diffing; sugar-boxer re-renders fully each time.
- **Key insight**: ratatui's layout is constraint-based; sugar-boxer's layout is hierarchical. The ratatui approach is more flexible but requires the Cassowary solver.

### 3.3 php-tui/php-tui (PHP TUI framework)

- **Cassowary layout**: Yes, full constraint solver ported from Ratatui.
- **Widget system**: 15+ widgets (Paragraph, Block, List, Table, etc.).
- **Comparison**: php-tui is a full framework; sugar-boxer is a single-purpose layout engine. php-tui's `Layout` widget is conceptually similar to sugar-boxer but uses constraints instead of tree distribution.

### 3.4 charmbracelet/harmonica (Animation)

- Not related to layout.

### 3.5 pterm/pterm (Go component library)

- **Box/panel rendering**: Yes, `pterm.Box` and `pterm.Panel`.
- **Comparison**: pterm's Box is a styled rectangle with content. sugar-boxer is a layout engine for composing multiple panels with borders between them. pterm's Panel is simpler (just a grid of boxes) without nested composition.

### 3.6 Other bubble tea libraries

- **bubblelister**: List bubble with cursor navigation — not related to layout.
- **KevM/bubbleo**: Navigation stack, menu, breadcrumb — not related to layout.
- **rmhubbert/bubbletea-overlay**: Modal overlay compositor — conceptually similar to sugar-boxer's nested composition but for overlays.
- **Genekkion/theHermit**: Quick-fix list overlay — not related.

### 3.7 Summary of all layout-related repos

| Repo | Layout Model | Approach |
|------|-------------|----------|
| treilik/bubbleboxer | Composite tree | Recursive View() assembly |
| charmbracelet/lipgloss | None (styling only) | Style application to positioned content |
| 76creates/stickers | Flexbox ratio-based | Row/Cell hierarchy with content generator |
| charmbracelet/ultraviolet | Cassowary constraints | Constraint solver for split-screen |
| textualize/textual | CSS flexbox/grid | Stylesheet + widget tree |
| ratatui/ratatui | Cassowary constraints | Widget trait system |
| php-tui/php-tui | Cassowary constraints | Widget trait system |
| **sugar-boxer** | **Tree-based H/V** | **Recursive weighted distribution** |

---

## 4. Innovation Analysis

### 4.1 What sugar-boxer Does Uniquely

1. **Immutable Node tree with with*() builders**: Unlike Go's bubbleboxer which uses mutable structs and a separate ModelMap, sugar-boxer uses PHP's readonly properties and immutable copy-on-write via a private `with()` helper with sentinel pattern.

2. **Char-cell grid storage**: The 2D `$cells[y][x]` array where each cell holds one logical character (not byte) prevents UTF-8 multibyte glyph corruption that would occur with direct string indexing.

3. **Weighted round-robin distribution**: The `distribute()` function uses integer arithmetic with rounding to allocate space proportionally without floating-point imprecision.

4. **Sentinel pattern for optional fields**: The private `nop(): \stdClass` sentinel distinguishes "preserve existing value" from "explicitly set to null", enabling clean multi-call chaining.

5. **Border style composition**: sugar-boxer uses `candy-sprinkles` Border/Style types as typed state rather than re-implementing border characters, maintaining cross-library consistency.

### 4.2 Known Limitations

1. **No wide-character overflow handling**: When rendering a wide character (CJK/emoji) into the cell grid, only the primary cell is written. The adjacent cell is not automatically filled, causing potential visual artifacts.

2. **No dynamic content generation**: Unlike stickers' `ContentGenerator(func(maxX, maxY) string)`, sugar-boxer leaf nodes hold static strings only.

3. **No mouse/click handling**: Not a component — purely a layout renderer.

4. **Full re-render each call**: No dirty-region tracking or diffing. Every `render()` call produces output from scratch.

5. **No constraint solving**: The tree-based approach cannot express constraints like "this column should be 30% of total width but never less than 10 cells". For that, see ultraviolet's Cassowary solver.

### 4.3 Architectural Insights

**The tree-based approach vs. constraint-based approach**:

- **Tree-based** (sugar-boxer, bubbleboxer): Simple, predictable, easy to understand. Good for hierarchical layouts (sidebar + main + footer). Cannot express complex constraints.
- **Constraint-based** (ultraviolet, ratatui, php-tui): More flexible, can handle competing constraints. Requires solver algorithm. Better for responsive layouts.

**The immutable approach vs. mutable update approach**:

- **Immutable** (sugar-boxer): Safe sharing, no defensive copying, race-condition free. Slight allocation overhead for with*() chains.
- **Mutable** (bubbleboxer): Lower allocation overhead, but requires ModelMap for safe editing.

---

## 5. SugarCraft Ecosystem Position

### 5.1 Dependencies

```
sugar-boxer
  └─ candy-core (Width::string for ANSI-aware width)
  └─ candy-sprinkles (Border, Style, Align, VAlign types)
```

### 5.2 Consumers

sugar-boxer is a **foundational layout primitive** used by other SugarCraft libs that need box-drawing compositions. It sits at the same level as `candy-shine`'s table rendering — both are output producers that render to strings.

### 5.3 Strengths

- Clean separation of layout from rendering
- Immutable nodes enable safe sharing across the monorepo
- Delegates to canonical candy-sprinkles types (Border, Style)
- ANSI-aware width calculation via candy-core
- Zero external dependencies beyond the monorepo
- 26 unit tests covering core functionality

### 5.4 Weaknesses

- No interactive components (unlike stickers)
- No constraint-based layout (unlike ultraviolet)
- No wide-character overflow handling
- Full re-render each call (no diffing)

---

## 6. Test Coverage

**Location**: `/home/sites/sugarcraft/sugar-boxer/tests/SugarBoxerTest.php`

**26 tests** covering:
- Node factory constructors (leaf, horizontal, vertical, noBorder)
- with* builders (padding, border, spacing, dimensions, margin, alignment)
- Total width/height calculation
- Render output (borders, content, separators, nested layouts)
- Content with multiple lines (word wrapping)
- withContent() replacement

**Lang coverage**: 8 tests verifying i18n infrastructure wiring (alignment labels only).

---

## 7. Files Reference

| File | Lines | Purpose |
|------|-------|---------|
| `src/SugarBoxer.php` | 408 | Main rendering engine |
| `src/Node.php` | 359 | Immutable layout tree node |
| `src/Lang.php` | 22 | i18n facade |
| `lang/en.php` | 21 | English translations |
| `tests/SugarBoxerTest.php` | 263 | Unit tests |
| `tests/LangCoverageTest.php` | 68 | i18n coverage |
| `examples/basic.php` | 34 | H/V panel demo |
| `examples/borders.php` | 35 | Border/padding variations |
| `examples/nested.php` | 36 | Complex nested layout |
| `composer.json` | 63 | Package metadata |
| `phpunit.xml` | 14 | PHPUnit configuration |
| `CALIBER_LEARNINGS.md` | 65 | Pattern documentation |

**Total**: ~1,383 lines of library code + tests + examples.
