# Sugar-Boxer Library Research

**Date:** 2026-05-13
**Context:** ASCII box drawing library comparison for sugar-boxer improvement
**Upstream:** treilik/bubbleboxer (Go)

---

## Executive Summary

Sugar-boxer is a well-architected PHP port of bubbleboxer with a solid foundation. However, it lacks several features common in other box-drawing libraries across Go, Rust, and Python. This research identifies **7 concrete improvements** with prioritized recommendations.

**Current State:** Sugar-boxer implements H/V composition, single-style box borders, padding, and immutable/fluent API. Cell-based rendering handles UTF-8 correctly.

**Key Gaps:** Multiple border styles, text alignment, titles, colors, margins, and dynamic sizing.

---

## 1. Library Comparison

### 1.1 Go: bubbleboxer (Upstream)

**Source:** https://github.com/treilik/bubbleboxer
**License:** MIT | **Language:** Go 1.17+

**Approach:**
- Tree-based layout engine integrated with bubbletea tea.Model
- Nodes hold children + orientation + optional SizeFunc for custom distribution
- Leaf nodes map to tea.Model via address string
- Borders drawn between children (│ and ─ separators)
- No concept of box styles or colors — purely structural

**Key Code Pattern (boxer.go:L96-115):**
```go
// Node is a node in a layout tree or when created with CreateLeaf its a valid leave of the LayoutTree
type Node struct {
    Children []Node
    VerticalStacked bool
    SizeFunc func(node Node, widthOrHeight int) []int
    noBorder bool
    address  string
    width    int
    height   int
}
```

**Strengths:**
- Clean separation of layout tree from content models
- SizeFunc allows custom space distribution algorithms
- Integration with bubbletea ecosystem

**Limitations:**
- Only single border style (hardcoded │ and ─)
- No text alignment control
- No title support
- No colors
- Tightly coupled to tea.Model interface

---

### 1.2 Go: box-cli-maker

**Source:** https://github.com/box-cli-maker/box-cli-maker
**License:** MIT | **Stars:** ~600

**Approach:**
- Single-box renderer with builder pattern
- 9 built-in styles: Single, Double, Round, Bold, SingleDouble, DoubleSingle, Classic, Hidden, Block
- Custom glyphs for all corners and edges
- Title positions: Inside, Top, Bottom
- Content alignment: Left, Center, Right
- Color support via hex/ANSI
- WrapContent with configurable limit

**Key Code Pattern:**
```go
b := box.NewBox().
    Style(box.Single).
    Padding(2, 1).
    TitlePosition(box.Top).
    ContentAlign(box.Center).
    Color(box.Cyan)

out, err := b.Render("Title", "Content")
```

**Strengths:**
- Comprehensive style system
- Color and Unicode handling via go-runewidth
- Explicit error handling

**Relevance to sugar-boxer:**
- Provides design patterns for style enum and custom glyph configuration
- Alignment enum pattern useful for PHP implementation

---

### 1.3 Rust: boxen

**Source:** https://crates.io/crates/boxen
**License:** MIT | **Version:** 0.4.0

**Approach:**
- Single-box renderer with builder pattern
- Multiple border styles via BorderStyle enum
- TextAlignment and TitleAlignment enums
- Float enum for centering in terminal
- Dynamic sizing via closures: `width(|available: usize| available * 40 / 100)`
- Color support via named colors, hex, RGB

**Key Code Pattern:**
```rust
let result = builder()
    .border_style(BorderStyle::Double)
    .padding((2, 4, 2, 4))  // top, right, bottom, left
    .margin(1)
    .text_alignment(TextAlignment::Center)
    .title_alignment(TitleAlignment::Center)
    .float(Float::Center)
    .width(40)
    .title("Greeting")
    .render("Hello, World!")
```

**Strengths:**
- Closure-based dynamic sizing is excellent for responsive layouts
- Comprehensive alignment system (text, title, float)
- Margin support (outer spacing)

**Relevance to sugar-boxer:**
- Dynamic sizing pattern using closures could replace fixed minWidth/minHeight
- Margin concept currently missing from sugar-boxer

---

### 1.4 Rust: tuviv

**Source:** https://crates.io/crates/tuviv
**License:** MIT | **Focus:** Layout

**Approach:**
- Flexbox and Grid layout system
- Child expansion factors (expand(1) vs expand(2))
- Border configuration on box_sizing
- Alignment per-child

**Key Code Pattern:**
```rust
let flexbox = Flexbox::new(Orientation::Horizontal, false)
    .child(
        Filler::new(" ".styled().bg_red())
            .fixed_size(16, 8)
            .centered()
            .to_flex_child()
            .expand(1),
    )
```

**Relevance to sugar-boxer:**
- Expansion factor concept could enhance proportional distribution
- Centered() alignment helper pattern

---

### 1.5 Rust: text_block_layout

**Source:** https://crates.io/crates/text_block_layout
**License:** MIT | **Version:** 1.2.2

**Approach:**
- Join text blocks vertically or horizontally
- Padding and fill options
- Overlapping boxes with transparency

**Relevance to sugar-boxer:**
- Similar core concept (block joining)
- Transparency character concept for layered rendering

---

### 1.6 Python: ascii_magic

**Source:** https://github.com/LeandroBarone/python-ascii_magic
**License:** MIT

**Approach:**
- Image to ASCII art conversion
- Color support via Front/Back enums
- Multiple output formats (terminal, HTML, image file)

**Note:** This is image-to-ASCII, NOT box drawing. Not directly relevant.

---

### 1.7 Python: boxes

**Source:** https://boxes.thomasjensen.com/
**License:** GPLv3

**Approach:**
- Command-line filter for drawing ASCII boxes
- Configurable box designs stored in config file
- Elastic sizing (adjusts to content)
- Text positioning regular expressions

**Strengths:**
- Design customization via configuration file
- Elastic/adaptive sizing

**Relevance to sugar-boxer:**
- Elastic sizing concept for auto-fit to content

---

## 2. Feature Gap Analysis

| Feature | bubbleboxer | box-cli-maker | boxen (Rust) | tuviv | sugar-boxer | Status |
|---------|-------------|---------------|--------------|-------|-------------|--------|
| Multiple border styles | ❌ | ✅ | ✅ | ❌ | ❌ | **Missing** |
| Text alignment | ❌ | ✅ Left/Center/Right | ✅ | ✅ | ❌ | **Missing** |
| Box titles | ❌ | ✅ Top/Bottom/Inside | ✅ | ❌ | ❌ | **Missing** |
| Colors | ❌ | ✅ | ✅ | ✅ | ❌ | **Missing** |
| Padding | ❌ (internal) | ✅ | ✅ | ❌ | ✅ | ✅ Implemented |
| Margin (outer) | ❌ | ✅ | ✅ | ❌ | ❌ | **Missing** |
| No-border mode | ✅ | ❌ | ❌ | ❌ | ✅ | ✅ Implemented |
| H/V composition | ✅ | ❌ (single box) | ❌ | ✅ Flexbox | ✅ | ✅ Implemented |
| Dynamic sizing | SizeFunc | ❌ | ✅ Closures | ✅ | minWidth/minHeight | Partial |
| Proportional distribution | Even split | N/A | N/A | expand() | weights | ✅ Implemented |

---

## 3. Specific Improvements

### 3.1 Box Styles Enum

**Priority:** HIGH
**Effort:** Medium (2-3 days)

Add a `BoxStyle` enum supporting multiple border character sets:

```php
enum BoxStyle: string
{
    case Single = 'single';
    case Double = 'double';
    case Round  = 'round';
    case Bold   = 'bold';
    case SingleDouble = 'single-double';
    case DoubleSingle = 'double-single';
    case Classic = 'classic';  // ASCII: +-+
    case Hidden  = 'hidden';
    case Block   = 'block';

    public function characters(): BoxCharacters
    {
        return match($this) {
            self::Single => new BoxCharacters('╭', '╮', '╰', '╯', '─', '│', '┬', '┤', '├', '┴', '┼'),
            self::Double => new BoxCharacters('╔', '╗', '╚', '╝', '═', '║', '╦', '╣', '╠', '╩', '╬'),
            self::Round  => new BoxCharacters('╭', '╮', '╰', '╯', '─', '│', '┬', '┤', '├', '┴', '┼'),
            // ... etc
        };
    }
}
```

**Reference:** box-cli-maker's style system provides the complete character mapping.

---

### 3.2 Text Alignment

**Priority:** HIGH
**Effort:** Medium (1-2 days)

Add horizontal and vertical text alignment to Node:

```php
enum HorizontalAlign: string
{
    case Left   = 'left';
    case Center = 'center';
    case Right  = 'right';
}

enum VerticalAlign: string
{
    case Top    = 'top';
    case Middle = 'middle';
    case Bottom = 'bottom';
}
```

Modify `renderContent()` to respect alignment:

```php
private function renderContent(
    string $text,
    int $x, int $y, int $w, int $h,
    array &$cells,
    HorizontalAlign $hAlign = HorizontalAlign::Left,
    VerticalAlign $vAlign = VerticalAlign::Top,
): void {
    $wrapped = $this->wordWrap($text, $w);

    // Calculate vertical offset
    $lineCount = \count($wrapped);
    $vOffset = match($vAlign) {
        VerticalAlign::Top => 0,
        VerticalAlign::Middle => \max(0, (int)(($h - $lineCount) / 2)),
        VerticalAlign::Bottom => \max(0, $h - $lineCount),
    };

    // ... render with calculated offsets
}
```

**Reference:** boxen uses `TextAlignment` enum; box-cli-maker uses `ContentAlign`.

---

### 3.3 Box Titles

**Priority:** MEDIUM
**Effort:** Medium (2-3 days)

Add title support to Node/Leaf:

```php
final class Node
{
    // ... existing properties
    public readonly ?string $title;
    public readonly TitlePosition $titlePosition;

    public function withTitle(
        string $title,
        TitlePosition $position = TitlePosition::Top,
    ): self { /* ... */ }
}

enum TitlePosition: string
{
    case Inside = 'inside';
    case Top    = 'top';
    case Bottom = 'bottom';
}
```

**Rendering:** Draw title text in border position when space permits:

```php
// In drawBorder()
if ($node->title !== null && $h >= 2) {
    $titleX = /* calculated based on titlePosition and alignment */;
    $this->renderTitle($node->title, $titleX, $y, $w, $cells);
}
```

**Reference:** box-cli-maker's `TitlePosition` enum and `Render` method with title parameter.

---

### 3.4 Color Support

**Priority:** MEDIUM
**Effort:** High (3-4 days)

Colors are complex because sugar-boxer currently outputs plain strings. Options:

**Option A:** ANSI escape sequences embedded in output
```php
final class AnsiColor
{
    public const RESET    = "\033[0m";
    public const BOLD     = "\033[1m";
    public const RED      = "\033[31m";
    public const GREEN    = "\033[32m";
    // ... 256-color and truecolor support
}
```

**Option B:** Separate color-aware rendering (SugarBoxerColor subclass)

**Recommendation:** Option A with helper methods on SugarBoxer:
```php
public function renderColored(Node $root, int $width, int $height, Style $style): string
{
    // $style contains border_color, text_color, background_color
}
```

**Integration:** Requires coordination with sugar-bits (candy-core already has Width utility for Unicode width calculation).

**Reference:** boxen and box-cli-maker both embed ANSI codes in output.

---

### 3.5 Margin Support

**Priority:** LOW
**Effort:** Small (1 day)

Add outer margin spacing:
```php
public function withMargin(int $cells): self
{
    return $this->with(margin: $cells);
}
```

**Implementation:** Simply offset the render start position by margin cells.

**Reference:** boxen has `margin(1)` builder method.

---

### 3.6 Dynamic Sizing with Callbacks

**Priority:** LOW
**Effort:** Medium (2-3 days)

Replace static minWidth/minHeight with callable:
```php
public readonly int|callable(int $available): int $minWidth;
```

**Implementation complexity:** High due to PHP's type system. Consider as v2 feature.

**Reference:** boxen uses `|available: usize| available * 40 / 100` closures.

---

### 3.7 Custom Glyph Override

**Priority:** MEDIUM
**Effort:** Small (1-2 days)

Allow custom box characters:
```php
public function withCustomGlyphs(BoxCharacters $glyphs): self
{
    return $this->with(glyphs: $glyphs);
}
```

**Use case:** Alternative character sets, Braille, Unicode symbols.

**Reference:** box-cli-maker supports custom corners and edges.

---

## 4. Prioritized Recommendations

### Phase 1: High Impact, Low Effort (Do First)

| # | Feature | Effort | Impact | Description |
|---|---------|--------|--------|-------------|
| 1 | Box Styles Enum | 2-3 days | HIGH | Multiple visual themes (single, double, round, bold) |
| 2 | Text Alignment | 1-2 days | HIGH | Left/Center/Right within boxes |
| 3 | Custom Glyph Override | 1-2 days | MEDIUM | Full character set customization |

### Phase 2: Medium Impact, Medium Effort

| # | Feature | Effort | Impact | Description |
|---|---------|--------|--------|-------------|
| 4 | Box Titles | 2-3 days | MEDIUM | Top/Bottom/Inside title positioning |
| 5 | Margin Support | 1 day | MEDIUM | Outer spacing around boxes |

### Phase 3: High Impact, High Effort (Consider Later)

| # | Feature | Effort | Impact | Description |
|---|---------|--------|--------|-------------|
| 6 | Color Support | 3-4 days | MEDIUM | ANSI color escape sequences |
| 7 | Dynamic Sizing | 2-3 days | LOW | Callable dimension functions |

---

## 5. Code Architecture Recommendations

### 5.1 Style Configuration Pattern

```php
/**
 * Immutable box style configuration.
 */
final readonly class BoxStyleConfig
{
    public function __construct(
        public BoxCharacters $chars,
        public HorizontalAlign $hAlign = HorizontalAlign::Left,
        public VerticalAlign $vAlign = VerticalAlign::Top,
        public ?string $title = null,
        public TitlePosition $titlePosition = TitlePosition::Inside,
        public ?AnsiColor $borderColor = null,
        public ?AnsiColor $textColor = null,
        public ?AnsiColor $backgroundColor = null,
        public int $margin = 0,
    ) {}

    public static function single(): self { /* ... */ }
    public static function double(): self { /* ... */ }
    public static function round(): self { /* ... */ }
    // etc
}
```

### 5.2 SugarBoxer Enhancement

```php
final class SugarBoxer
{
    // Existing constants remain for default style

    public function render(Node $root, int $width, int $height): string
    {
        // Current implementation
    }

    public function renderStyled(Node $root, int $width, int $height, BoxStyleConfig $style): string
    {
        // Style-aware rendering
    }

    public function renderWithColors(Node $root, int $width, int $height, Style $style): string
    {
        // Color-aware rendering
    }
}
```

---

## 6. Testing Recommendations

Based on existing `sugar-bits` and `candy-core` patterns:

1. **Snapshot tests** for each BoxStyle rendering
2. **Alignment tests** with known input/output pairs
3. **Coercion tests** for edge cases:
   - Tiny viewport with padding
   - Empty content
   - Oversized titles
   - Mixed Unicode content

---

## 7. Dependencies

**No new dependencies required.** sugar-boxer already depends on:
- `sugarcraft/candy-core` for `Width` utility (Unicode width handling)

Color support will not require new dependencies if using raw ANSI codes.

---

## 8. Conclusion

Sugar-boxer is a solid port of bubbleboxer with excellent architecture. The primary improvements needed are:

1. **Box styles** (HIGH) — Currently hardcoded single style
2. **Text alignment** (HIGH) — No left/center/right control
3. **Titles** (MEDIUM) — Missing box title feature
4. **Colors** (MEDIUM) — No color support
5. **Margin** (LOW) — Outer spacing absent

The recommended implementation approach:
- Add `BoxStyle` enum with character mappings
- Extend `Node` with alignment and title properties
- Implement style-aware rendering in `SugarBoxer`
- Add snapshot tests for each new feature

**Total estimated effort:** 8-12 days for Phase 1+2 features.

---

## Appendix: Character Mappings by Style

| Style | TL | TR | BL | BR | H | V | CR | CL | CA | CB | XX |
|-------|----|----|----|----|---|---|----|----|----|----|----|
| Single | ╭ | ╮ | ╰ | ╯ | ─ | │ | ┬ | ┤ | ├ | ┴ | ┼ |
| Double | ╔ | ╗ | ╚ | ╝ | ═ | ║ | ╦ | ╣ | ╠ | ╩ | ╬ |
| Round | ╭ | ╮ | ╰ | ╯ | ─ | │ | ┬ | ┤ | ├ | ┴ | ┼ |
| Bold | ┏ | ┓ | ┗ | ┛ |━ | ┃ | ┳ | ┫ | ┣ | ┻ | ╋ |
| Classic | + | + | + | + | - | | | + | + | + | + | + |
| Hidden | (space) | (space) | (space) | (space) | (space) | (space) | (space) | (space) | (space) | (space) | (space) |

---

## References

- bubbleboxer: https://github.com/treilik/bubbleboxer
- box-cli-maker: https://github.com/box-cli-maker/box-cli-maker
- boxen (Rust): https://crates.io/crates/boxen
- tuviv (Rust): https://crates.io/crates/tuviv
- text_block_layout (Rust): https://crates.io/crates/text_block_layout
