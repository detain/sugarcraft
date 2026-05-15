# Terminal Styling Library Research: candy-sprinkles

**Research Date:** 2026-05-13
**Upstream:** charmbracelet/lipgloss (Go)
**Context:** PHP 8.3+ port for SugarCraft monorepo

---

## Executive Summary

candy-sprinkles is a well-structured port of lipgloss v2 with strong parity in core styling features. This research identifies **5 high-value improvements** and **3 medium-value improvements** based on patterns from Go (lipgloss), Rust (ratatui), Python (Rich), and JavaScript (Ink/blessed) ecosystems.

---

## 1. Style Composition

### Current State (Strong)
- `Style::inherit(self $parent)` — child props override parent ✓
- `Style::copy()` — explicit duplication ✓
- Immutable fluent API with `with()` private helper ✓

### Upstream Patterns

**lipgloss v2 (Go):**
```go
// Child properties override, unset properties inherit from parent
warningStyle := lipgloss.NewStyle().
    Foreground(lipgloss.Color("#FFCC00")).
    Inherit(baseStyle)  // Gets background and padding only
```

**Ratatui (Rust):**
```rust
// Style patching — only non-Reset fields overwrite
let patched = base.patch(Style::new().add_modifier(Modifier::BOLD));
```

**Rich (Python):**
```python
# Style combination via addition
combined = Style.parse("bold red") + Style.parse("italic")
```

### Gap Analysis

| Library | Merge Strategy | Patch/Override | Notes |
|---------|---------------|----------------|-------|
| lipgloss v2 | `Inherit()` | Override wins | Child-preserving merge |
| Ratatui | `patch()` | Reset detection | Only overwrites non-default |
| Rich | `+` operator | Both preserved | Span-level styling |
| **candy-sprinkles** | `inherit()` | Child wins | ✓ Implemented |

### Recommendation
**Low priority** — current `inherit()` implementation is solid. Consider adding `patch()`-style method for incremental modifications if users request it.

---

## 2. Color Handling

### Current State (Strong)
- `Color` class with `hex()`, `rgb()`, `ansi()`, `ansi256()` factories ✓
- `CompleteColor` for profile-aware triple (TrueColor/ANSI256/ANSI) ✓
- `AdaptiveColor` for light/dark background switching ✓
- `ColorProfile` enum (Ascii/NoTty/ANSI/ANSI256/TrueColor) ✓

### Upstream Patterns

**lipgloss v2 — Color Profiles:**
```go
profile := colorprofile.Detect(os.Stdout, os.Environ())
complete := lipgloss.Complete(profile)
accent := complete(lipgloss.Color("1"), lipgloss.Color("196"), lipgloss.Color("#FF4444"))
```

**Ratatui — Color Types:**
```rust
Color::Rgb(255, 165, 0)        // TrueColor
Color::Indexed(236)            // 256-color palette
Color::parse("lightblue")      // String parsing
```

**Rich — Theme Color System:**
```python
console = Console(color_system="auto")  # auto-detect
# Also: "standard", "256", "truecolor", "windows", None
```

### Gap Analysis

| Feature | lipgloss v2 | Ratatui | Rich | candy-sprinkles |
|---------|-------------|---------|------|-----------------|
| TrueColor | ✓ | ✓ | ✓ | ✓ |
| 256-color | ✓ | ✓ | ✓ | ✓ |
| ANSI 16 | ✓ | ✓ | ✓ | ✓ |
| String parsing | Limited | ✓ (`FromStr`) | ✓ | Partial (hex only) |
| HSL colors | ✗ | ✓ (palette feature) | ✓ | ✗ |
| Profile detection | ✓ (external pkg) | ✓ | ✓ | ✓ (in candy-core) |

### Missing in candy-sprinkles

1. **String-based color parsing** — `Color::parse("cyan")` or `Color::fromString("lightblue")`
2. **HSL color space** — `Color::hsl($h, $s, $l)` for designer-friendly color manipulation

### Recommendation
**Medium priority**

```php
// Add string parsing
public static function parse(string $color): ?Color
{
    return match (strtolower($color)) {
        'black' => self::ansi(0),
        'red' => self::ansi(1),
        // ... all 16 ANSI names
        default => self::hex($color),  // try hex/rgb
    };
}

// Add HSL support
public static function hsl(float $h, float $s, float $l): self
{
    // Convert HSL to RGB, then construct
}
```

**Effort:** 4-6 hours

---

## 3. Typography (Bold, Italic, Underline, etc.)

### Current State (Excellent)
- All standard attributes: bold, italic, underline, strikethrough, faint, blink, reverse, overline, invisible ✓
- `UnderlineStyle` enum (single, double, curly, dotted, dashed, none) ✓
- `UnderlineColor` for colored underlines ✓

### Upstream Patterns

**Rich (Python) — String markup:**
```python
console.print("[bold red]Warning:[/bold red] something happened")
console.print("Hello [link=https://example.com]World[/link]!")
console.print("[on blue]Text on blue background[/on blue]")
```

**lipgloss v2 — Transform:**
```go
// Custom text transformation at render time
wave := lipgloss.NewStyle().
    Transform(func(s string) string {
        // Every other character uppercase
    })
```

### Gap Analysis

| Feature | lipgloss v2 | Ratatui | Rich | candy-sprinkles |
|---------|-------------|---------|------|-----------------|
| Bold | ✓ | ✓ (`Modifier::BOLD`) | ✓ | ✓ |
| Italic | ✓ | ✓ (`Modifier::ITALIC`) | ✓ | ✓ |
| Underline styles | ✓ (curly, double, etc.) | ✓ (via decorator) | ✓ (`underline2`) | ✓ |
| Strikethrough | ✓ | ✓ | ✓ | ✓ |
| Blink | ✓ | ✓ | ✓ | ✓ |
| Reverse | ✓ | ✓ | ✓ | ✓ |
| Faint/Dim | ✓ | ✓ | ✓ | ✓ |
| Overline | ✓ | ✓ | ✓ | ✓ |
| Hidden/Invisible | ✓ | ✗ | ✓ | ✓ |
| **Markup string** | ✗ | ✗ | ✓ | ✗ |
| **Transform hook** | ✓ | ✗ | ✗ | ✓ |
| **Hyperlink** | ✓ (OSC 8) | ✗ | ✓ | ✓ (OSC 8) |

### Missing in candy-sprinkles

1. **Markup/string-based style parsing** — `Style::parse("[bold red]text[/]")`
2. **Blink rapid** — Rich has `blink2` for rapid flash

### Recommendation
**Low priority for markup** — requires tokenizer and is complex. The `transform()` hook already exists.

---

## 4. Layout Properties (Margin, Padding, Border)

### Current State (Strong)
- `padding(int ...$sides)` — CSS-like 1/2/4 arg shorthand ✓
- `margin(int ...$sides)` — same ✓
- `pad()` and `mg()` short aliases ✓
- `border()` with per-side booleans ✓
- `borderForeground()`/`borderBackground()` with per-side variants ✓
- `BorderForegroundBlend()` for gradient borders ✓
- `paddingChar` and `marginChar` for custom fill ✓

### Upstream Patterns

**lipgloss v2:**
```go
// CSS shorthand
style := lipgloss.NewStyle().Padding(2)           // all sides
style := lipgloss.NewStyle().Padding(1, 4)        // vertical, horizontal
style := lipgloss.NewStyle().Padding(1, 2, 3, 4)  // top, right, bottom, left

// Custom fill characters
style := lipgloss.NewStyle().
    PaddingChar('.').
    MarginChar('~')
```

**Ratatui — Constraint-based Layout:**
```rust
let [header, body, footer] = Layout::vertical([
    Constraint::Length(1),
    Constraint::Min(10),
    Constraint::Fill(1),
])
.margin(1)
.spacing(1)
.areas(frame.area());
```

**Rich — Padding utility:**
```python
from rich.padding import Padding
padded = Padding("Text", (1, 2), style="cyan")
```

### Gap Analysis

| Feature | lipgloss v2 | Ratatui | Rich | candy-sprinkles |
|---------|-------------|---------|------|-----------------|
| CSS shorthand padding | ✓ | N/A | N/A | ✓ |
| Custom padding char | ✓ | N/A | ✓ | ✓ |
| Custom margin char | ✓ | N/A | ✗ | ✓ |
| Margin background | ✓ | N/A | ✗ | ✓ |
| Per-side border colors | ✓ | ✓ | ✓ | ✓ |
| Border title anchors | ✓ | ✓ | ✓ | ✓ |
| **Constraint layout** | ✗ | ✓ | ✗ | Partial (Solver) |
| **Spacing between items** | ✗ | ✓ | ✓ | ✗ |

### Missing in candy-sprinkles

1. **Inter-item spacing in Layout::joinHorizontal/joinVertical** — Ratatui has `spacing()`, Rich has `column_spacing`
2. **Layout Constraint Solver** — already has `Solver` class but could be enhanced

### Recommendation
**Medium priority**

```php
// Enhancement to Layout::joinHorizontal/joinVertical
public static function joinHorizontal(float $pos, int $spacing = 0, string ...$blocks): string
{
    // Add $spacing cells between blocks
}

// Add to Style
public function spacing(int $cells): self { /* ... */ }
```

**Effort:** 2-3 hours

---

## 5. Theme Systems

### Current State (Minimal)
- `Palette` class with 16 ANSI color constants ✓
- No formal theme/class registry

### Upstream Patterns

**Rich Theme System (Python):**
```python
from rich.theme import Theme

custom_theme = Theme({
    "info": "dim cyan",
    "warning": "magenta",
    "danger": "bold red",
    "success": "green",
})

console = Console(theme=custom_theme)
console.print("[warning]Low disk space[/]")
console.print("[danger]Critical error![/]")

# Load from file
theme = Theme.read("/path/to/theme.ini")
```

**Rich Theme INI format:**
```ini
[styles]
info = dim cyan
warning = magenta
danger = bold red
success = green
```

**Ratatui — StyleSheets:**
```rust
// Convention over configuration — standard widget styles
widget.style(Style::new().fg(Color::Cyan));
```

### Recommendation
**High priority** — Theme system would significantly improve DX

```php
/**
 * Theme — Named style registry
 *
 * Mirrors Rich's Theme class.
 */
final class Theme
{
    /** @var array<string, Style> */
    private array $styles = [];

    public function __construct(
        private readonly bool $inherit = true,  // inherit built-in styles
    ) {}

    public static function builtIn(): self { /* ... */ }

    public function add(string $name, Style $style): self
    {
        return new self(/* ... */);
    }

    public function get(string $name): ?Style { /* ... */ }

    public static function fromFile(string $path): self { /* ... */ }
}

// Usage
$theme = Theme::builtIn()
    ->add('warning', Style::new()->foreground(Palette::yellow())->italic())
    ->add('error', Style::new()->foreground(Palette::red())->bold());

echo $theme->get('warning')->render('Low disk space');
```

**Effort:** 6-8 hours

---

## 6. Additional Features from Research

### A. Rich-Style String Markup
**Value:** High for DX
**Effort:** High (requires tokenizer)

```php
// Desired usage
echo Style::parseMarkup("[bold cyan]Hello[/] [italic]World[/]");

// Also for inline styling within render
$style->render("[bold]Important[/] message");
```

### B. Blinking Text Variants
**Value:** Low (terminal support inconsistent)
**Effort:** 1 hour

Rich has `blink` (slow) and `blink2` (rapid). Currently only `blink` implemented.

### C. Border Gradient Blend Fix
**Value:** Medium (already partially implemented)
**Effort:** 2 hours

Current `borderForegroundBlend()` only takes 2 colors and interpolates to 4 sides. lipgloss v2 takes 5 colors directly. Consider aligning API.

```php
// Current (2 colors → 4 sides interpolated)
->borderForegroundBlend(Color $start, Color $end)

// lipgloss v2 (5 colors for 4 sides + wrap-around)
->BorderForegroundBlend(c1, c2, c3, c4, c5)
```

### D. HSL Color Space
**Value:** Medium for theming
**Effort:** 3-4 hours

```php
public static function hsl(float $hue, float $saturation, float $lightness): Color
{
    // HSL to RGB conversion
}
```

---

## Prioritized Recommendations

### High Priority

| # | Feature | Rationale | Effort |
|---|---------|-----------|--------|
| 1 | **Theme class** | Major DX improvement for application authors | 6-8h |
| 2 | **String color parsing** | `Color::parse("cyan")` convenience | 2-3h |
| 3 | **Spacing in Layout joins** | Common requirement for dashboards | 2-3h |

### Medium Priority

| # | Feature | Rationale | Effort |
|---|---------|-----------|--------|
| 4 | **HSL color space** | Designer-friendly color manipulation | 3-4h |
| 5 | **BorderGradientBlend API alignment** | Match lipgloss v2 with 5-color API | 2h |
| 6 | **Markup parser** | Rich-style inline styling | 10-15h |

### Low Priority (Nice to Have)

| # | Feature | Rationale | Effort |
|---|---------|-----------|--------|
| 7 | **Style::patch()** | Ratatui-style incremental modification | 3-4h |
| 8 | **Rapid blink variant** | Feature parity with Rich | 1h |

---

## Implementation Order

1. **Theme class** — Most impactful, start here
2. **String color parsing** — Quick win, improves ergonomics
3. **Layout spacing** — Common need, straightforward
4. **HSL colors** — Useful for theme authors
5. **BorderGradientBlend alignment** — Nice to have

---

## References

### Documentation Sources

- **lipgloss v2:** https://github.com/charmbracelet/lipgloss (Context7 ID: `/charmbracelet/lipgloss`)
- **ratatui:** https://github.com/ratatui/ratatui (Context7 ID: `/ratatui/ratatui`)
- **Rich:** https://rich.readthedocs.io/en/stable/style.html
- **Ink:** https://github.com/vadimdemedes/ink
- **blessed-contrib:** https://github.com/yaronn/blessed-contrib

### Key Code References

**candy-sprinkles current implementation:**
- `src/Style.php` — Main style class (1170+ lines)
- `src/Border.php` — Border definitions
- `src/Color.php` (in candy-core) — Color utilities
- `src/Palette.php` — ANSI color constants
- `src/AdaptiveColor.php` — Light/dark switching
- `src/CompleteColor.php` — Profile-aware colors
- `src/Layout.php` — Layout primitives
- `src/Layout/Layout.php` — Constraint-based layout

### Upstream lipgloss v2 Patterns

**Style inheritance:**
- Source: `context7` lipgloss docs — "Inherit Styles from Base Styles"
- Pattern: `child.Inherit(parent)` — only unset properties inherit

**Color profiles:**
- Source: `context7` lipgloss docs — "Profile-Aware Color with Lip Gloss"
- Pattern: `lipgloss.Complete(profile)(ansi16, ansi256, truecolor)`

**Transform:**
- Source: `context7` lipgloss docs — "Text Transformations with Lip Gloss"
- Pattern: `Transform(func(s string) string)`

### Ratatui Patterns

**Style patching:**
- Source: `context7` ratatui docs — "Styling Text with Style and Stylize"
- Pattern: `base.patch(Style::new().add_modifier(Modifier::BOLD))`

**Layout constraints:**
- Source: `context7` ratatui docs — "Layout"
- Pattern: `Layout::vertical([...constraints]).margin(1).spacing(1).areas(rect)`

### Rich Patterns

**Theme system:**
- Source: `rich.readthedocs.io` — "Style Themes"
- Pattern: `Theme({ "info": "dim cyan", ... })` with `Theme.read()` from file

**Markup:**
- Source: `rich.readthedocs.io` — "Console Markup"
- Pattern: `[bold red]text[/bold red]` inline styling

---

## Appendix: Feature Comparison Matrix

| Feature | lipgloss v2 | ratatui | Rich | Ink | blessed | candy-sprinkles |
|---------|-------------|---------|------|-----|---------|-----------------|
| TrueColor | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| 256-color | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| ANSI 16 | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Bold/Italic/Underline | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Underline styles | ✓ | ✓ | ✓ | ✗ | ✗ | ✓ |
| Margin/Padding | ✓ | Widget-level | ✓ | ✓ | ✓ | ✓ |
| Borders | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Border gradients | ✓ | ✓ | ✗ | ✗ | ✗ | ✓ |
| Adaptive colors | ✓ | ✗ | ✓ | ✗ | ✗ | ✓ |
| Complete colors | ✓ | ✗ | ✗ | ✗ | ✗ | ✓ |
| Theme system | ✗ | Partial | ✓ | ✗ | ✗ | **Missing** |
| String markup | ✗ | ✗ | ✓ | ✗ | ✗ | **Missing** |
| CSS shorthand | ✓ | N/A | N/A | ✓ | ✗ | ✓ |
| Layout constraints | ✗ | ✓ | ✗ | Flexbox | Grid | Partial |
| Hyperlinks | ✓ | ✗ | ✓ | ✓ | ✗ | ✓ |
| Transform hook | ✓ | ✗ | ✗ | ✗ | ✗ | ✓ |
| Style inheritance | ✓ | ✓ (patch) | ✓ (+ op) | React props | ✗ | ✓ |
