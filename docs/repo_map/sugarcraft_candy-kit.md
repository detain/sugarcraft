# SugarCraft/candy-kit

## Metadata
- **Package:** `sugarcraft/candy-kit`
- **Namespace:** `SugarCraft\Kit`
- **Description:** Opinionated CLI presentation helpers: StatusLine, Banner, Section, Stage, HelpText. PHP port of the Go charmbracelet/fang ecosystem.
- **PHP:** `^8.3`
- **Status:** 🟢 v1 ready
- **Upstream:** `charmbracelet/fang` (primary), `charmbracelet/gum` (style commands), `charmbracelet/log` (colored output)

---

## Overview

CandyKit provides a set of focused, single-responsibility CLI presentation primitives that turn ordinary command-line output into consistent, branded terminal output. It is library-only — drop it into any Composer project without requiring Symfony Console or any other framework.

The package implements 7 components: `Theme`, `StatusLine`, `Banner`, `Section`, `Stage`, `HelpText`, and `Logo`. Each is a `final` class with static factory methods, no instance state beyond configuration, and output that composes cleanly with the rest of the SugarCraft stack via `candy-sprinkles` (styling) and `candy-core` (utilities).

**Primary upstream:** `charmbracelet/fang` — CLI starter kit for Cobra applications, providing styled help/errors, automatic `--version`, manpage generation, shell completions, and themeable output.

---

## Component Inventory

### Theme (`Theme.php`)

Palette of `SugarCraft\Sprinkles\Style` objects keyed by status level:

```php
public function __construct(
    public readonly Style $success,   // ✓ bright green
    public readonly Style $error,     // ✗ bright red
    public readonly Style $warn,      // ⚠ bright yellow
    public readonly Style $info,       // ℹ bright blue
    public readonly Style $prompt,    // ? pink
    public readonly Style $accent,    // bold magenta
    public readonly Style $muted,     // faint/dim
) {}
```

**6 factory methods:**
- `Theme::ansi()` — sensible defaults using ANSI 16 colors (bright variants)
- `Theme::plain()` — no-op palette for snapshot tests (all styles are `Style::new()` passthrough)
- `Theme::charm()` — pink + cyan Charm-brand palette
- `Theme::dracula()` — Dracula colorscheme
- `Theme::nord()` — Nord cool blues and frost tones
- `Theme::catppuccin()` — Catppuccin Mocha pastel set

**Design:** Immutable value object (no `with*()` needed — constructor sets all fields). Theme instances are passed as nullable parameters to each rendering method, defaulting to `Theme::ansi()` via null coalescing.

### StatusLine (`StatusLine.php`)

Renders a single styled status line: glyph + space + message.

```php
final class StatusLine
{
    public const GLYPH_SUCCESS = '✓';
    public const GLYPH_ERROR   = '✗';
    public const GLYPH_WARN    = '⚠';
    public const GLYPH_INFO    = 'ℹ';
    public const GLYPH_PROMPT  = '?';

    public static function success(string $message, ?Theme $theme = null): string;
    public static function error(string $message, ?Theme $theme = null): string;
    public static function warn(string $message, ?Theme $theme = null): string;
    public static function info(string $message, ?Theme $theme = null): string;
    public static function prompt(string $message, ?Theme $theme = null): string;

    private static function format(string $glyph, string $message, Style $style): string {
        return $style->render($glyph) . ' ' . $message;
    }
}
```

**Key design decision:** The glyph + space receives the style; the message text is always plain. This keeps the output scannable while maintaining a consistent visual anchor (the styled glyph). Unicode glyphs are single-character (`mb_strlen($glyph) === 1`) for proper width measurement.

### Banner (`Banner.php`)

Renders a bordered title block with optional subtitle.

```php
final class Banner
{
    public static function title(
        string $title,
        string $subtitle = '',
        ?Theme $theme = null,
        ?Border $border = null
    ): string {
        $theme  ??= Theme::ansi();
        $border ??= Border::rounded();

        $body  = $theme->accent->render($title);
        if ($subtitle !== '') {
            $body .= "\n" . $theme->muted->render($subtitle);
        }

        return Style::new()
            ->border($border)
            ->padding(0, 2)
            ->render($body);
    }
}
```

Uses `SugarCraft\Sprinkles\Border::rounded()` (╭──╮ │ │ ╰──╯) by default. The horizontal padding of 2 cells (`padding(0, 2)`) ensures the title is centered within the border with proper breathing room.

### Section (`Section.php`)

Renders a section header — a label sandwiched between horizontal rules.

```php
final class Section
{
    public static function header(
        string $label,
        ?Theme $theme = null,
        int $leftPad = 2,
        ?int $width = 80,
        string $rune = '─',
    ): string;

    public static function rule(
        ?Theme $theme = null,
        ?int $width = 80,
        string $rune = '─',
    ): string;
}
```

Output format: `── LABEL ──────────────` (total width = 80 cells by default).

The implementation uses `SugarCraft\Core\Util\Width::string()` to compute ANSI-aware cell width for accurate padding when the terminal uses TrueColor or ANSI256:

```php
$remaining = max(0, $width - Width::string($head));
return $head . str_repeat($rune, $remaining);
```

**Design decision:** Setting `width: null` produces `── LABEL ─` (stop after the trailing rune, no fill to terminal width) — useful for nested or compact layouts.

### Stage (`Stage.php`)

Renders numbered step indicators for multi-step processes.

```php
final class Stage
{
    public const GLYPH_ARROW  = '▸';
    public const GLYPH_BULLET = '•';
    public const GLYPH_HASH   = '#';

    public static function step(
        int $current,
        int $total,
        string $message,
        ?Theme $theme = null,
        string $glyph = self::GLYPH_ARROW,
    ): string;

    public static function subStep(
        string $message,
        ?Theme $theme = null,
        bool $isLast = false,
        int $indent = 2,
    ): string;
}
```

Output for `Stage::step(2, 5, 'building')`:
```
▸ 2/5 building
```

`subStep` renders tree-style nested steps using `├─` / `└─` glyphs:
```
  ├─ checking PATH
  └─ checking composer cache
```

The `isLast` parameter switches the glyph from tee (`├─`) to corner (`└─`) to visually close the tree.

### HelpText (`HelpText.php`)

Renders a git-style `--help` page from structured input.

```php
final class HelpText
{
    public static function render(
        string $usage,
        array $sections,
        string $description = '',
        ?Theme $theme = null,
    ): string;

    public static function renderRows(array $rows, ?Theme $theme = null): string;
}
```

Output for a typical help page:
```
USAGE
  myapp [flags] <command>

A CLI tool.

FLAGS
    -v, --verbose     enable verbose logging
    --theme <name>    pick a colour theme

COMMANDS
    build             compile the project
    serve             start the dev server
```

The `renderRows` method calculates the maximum key width across all rows and left-pads shorter keys so all descriptions align at the same column. Uses `mb_strlen()` with `'UTF-8'` encoding for proper Unicode support.

### Logo (`Logo.php`)

Renders ASCII-art logos with optional color theming.

```php
final class Logo
{
    public static function fromAscii(string $ascii): self;
    public static function sugarcraft(): self;  // Built-in box-drawing logo
    public function withColor(string|Color $color): self;
    public function render(): string;
}
```

The `sugarcraft()` preset renders a box-drawing character logo:
```
╔═══════════════════════════════════════════════════════════╗
║   ____       _          _   _                 _ _         ║
║  | __ ) _   _| |_ ___   | | | |__   __ _ _ __ (_) |_ _   _║
...
╚═══════════════════════════════════════════════════════════╝
```

`withColor()` wraps the ASCII art in a `Style::new()->foreground($color)->render($ascii)` call, applying the color to the entire art. The Logo is immutable — `withColor()` returns a new instance with the styled content.

---

## Architecture

### Dependency Graph

```
candy-kit/
├── depends on: candy-core (Width utility, Color utilities)
├── depends on: candy-sprinkles (Style, Border)
└── provides: CLI presentation helpers
```

No circular dependencies. candy-kit is a leaf in the dependency tree (it depends on candy-core and candy-sprinkles but nothing depends on it within the monorepo).

### Immutability Pattern

All classes are `final` with static factory methods. No instance methods that mutate state. `Logo::withColor()` and `Theme` construction return new instances. This makes the components safe to use in concurrent contexts and easy to test.

### ANSI Safety

Width calculations use `SugarCraft\Core\Util\Width::string()` which strips ANSI sequences before measuring visual cell width. This ensures that styled text (e.g., bold glyphs, colored titles) doesn't throw off the padding/alignment calculations.

---

## CLI Presentation Patterns

### Status Display Pattern

The `StatusLine` class follows the common CLI pattern of single-line status messages with a visual indicator (glyph) + message. This pattern is widespread across CLI tools:

```
✓ All packages installed
⚠ 1 minor version available
✗ Connection refused
ℹ Cache size: 12 MB
```

The design decision to style only the glyph (not the message) keeps output scannable and prevents the message from inheriting potentially unexpected styling from the theme.

### Progress/Staging Pattern

`Stage::step()` follows the build-script pattern seen in npm, cargo, and other modern CLI tools:
```
▸ 1/5 Reading config
▸ 2/5 Validating env
  ├─ checking PATH
  └─ checking composer cache
▸ 3/5 Installing deps
▸ 4/5 Done
```

This is distinguishable from a progress bar (which would use `sugar-bits` progress components) and from a log (which would use `candy-log`). Stage is specifically for visual sequencing of multi-step operations where the user needs to see which step they're on.

### Help Page Pattern

`HelpText::render()` produces git-style help output following the de facto CLI help convention:
- `USAGE` section with one-line synopsis
- Optional description paragraph
- Sectioned flag/command tables with two-column alignment

This is distinct from `candy-shine` (markdown rendering) and `candy-log` (time-stamped log entries). HelpText is specifically for the `--help` output of a CLI command.

---

## Theme/Adaptation System

### Light/Dark Adaptation

Unlike the upstream `charmbracelet/fang` which uses `lipgloss.LightDarkFunc` for automatic light/dark detection, candy-kit does not implement automatic terminal background detection. The design chooses to make the theme explicit rather than auto-detected.

**Rationale:** PHP CLI scripts often run in contexts where terminal detection is unreliable (cron, CI/CD, Docker). Making the theme explicit at call site is more predictable. Users who want auto-detection can implement it themselves using `candy-core`'s `ColorProfile::detect()` and pass the appropriate `Theme` to each component.

### Theme Factory Pattern

Each named theme (`charm()`, `dracula()`, etc.) is a `public static function` returning a fully-populated `Theme` instance. This mirrors the pattern used in `candy-sprinkles`'s `Theme` class (10 named factories) and allows enumeration:

```php
// Users can iterate or select themes programmatically
$themes = ['ansi', 'plain', 'charm', 'dracula', 'nord', 'catppuccin'];
```

### Color Strategy

The 7-level palette (success/error/warn/info/prompt/accent/muted) covers the full range of CLI status communication. Each style uses:
- Bold modifier for glyph prominence
- Foreground color only (no background) to maintain readability on any terminal background
- ANSI 16 colors in the default theme for maximum compatibility

---

## Platform Handling

### Windows VT Processing

Unlike the upstream `charmbracelet/fang` which includes `fang_windows.go` with explicit Windows VT processing enablement:

```go
// fang_windows.go
func enableVirtualTerminalProcessing(w io.Writer) {
    // Windows-specific: enable VT processing for ANSI escape support
}
```

CandyKit does **not** include platform-specific Windows VT processing because:
1. It depends on `candy-core` for terminal utilities, which handles this at a lower level
2. The PHP port of the TTY raw mode handling is in `candy-core` and `candy-pty`
3. As a pure presentation library (output only, no input handling), candy-kit doesn't directly interact with the terminal device

**Implication:** Applications using candy-kit should ensure Windows VT processing is enabled at the application level (typically via `candy-core`'s terminal initialization) before producing styled output.

### ANSI Safety Across Platforms

The use of `Width::string()` (which calls `mb_strwidth()` after stripping ANSI) ensures consistent width calculation on all platforms. This is critical for `Section::header()` alignment, where incorrect width calculation would produce misaligned rulers.

---

## Help Formatting

### Two-Column Alignment

`HelpText::renderRows()` calculates the maximum key width:

```php
$maxKey = 0;
foreach (array_keys($rows) as $k) {
    if (mb_strlen($k, 'UTF-8') > $maxKey) {
        $maxKey = mb_strlen($k, 'UTF-8');
    }
}
```

Then pads each key with spaces so descriptions align:

```php
$padded = $key . str_repeat(' ', max(0, $maxKey - mb_strlen($key, 'UTF-8')));
$lines[] = '  ' . $theme->prompt->render($padded) . '  ' . $desc;
```

**Limitation:** Uses `mb_strlen()` for Unicode-aware length calculation but does not account for double-width characters (CJK, emoji) in the alignment. This is a known gap — `Width::string()` handles visual width but is not used in `renderRows()`.

### Section Ordering

Sections are rendered in the order supplied in the `$sections` associative array. PHP 8.0+ preserves associative array order, so callers can control the section ordering precisely.

---

## Feature Inventory

| Feature | Status | Implementation |
|---------|--------|---------------|
| StatusLine with 5 glyph types | ✅ | `StatusLine.php` |
| Theme with 6 named palettes | ✅ | `Theme.php` |
| Banner with border + subtitle | ✅ | `Banner.php` |
| Section header with fill-to-width | ✅ | `Section.php` |
| Stage step + subStep | ✅ | `Stage.php` |
| HelpText with 2-column alignment | ✅ | `HelpText.php` |
| Logo with color theming | ✅ | `Logo.php` |
| ANSI-aware width calculation | ✅ | Uses `Width::string()` in Section |
| Unicode-aware length calculation | ✅ | Uses `mb_strlen()` in HelpText |
| Plain theme for snapshot tests | ✅ | `Theme::plain()` passthrough |
| Immutable components | ✅ | All classes `final`, static factories |
| Default to Theme::ansi() | ✅ | Null coalescing `?? Theme::ansi()` |
| Per-side border colors | ❌ | Uses `Border::rounded()` only |
| Light/dark auto-detection | ❌ | Not implemented (explicit theme only) |
| Shell completion generation | ❌ | Not implemented (requires CLI framework) |
| Manpage generation | ❌ | Not implemented |
| Automatic --version wiring | ❌ | Not implemented |
| Windows VT processing | ❌ | Delegated to candy-core |

---

## Strengths

1. **Minimal, focused API** — 7 classes, each with a single responsibility. No learning curve beyond understanding what each component does.

2. **Drop-in library** — No framework requirement. Any PHP CLI script can `composer require sugarcraft/candy-kit` and use the components immediately.

3. **Immutable + final classes** — Safe for concurrent use, no hidden state, easy to test.

4. **ANSI-safe width calculation** — `Width::string()` ensures accurate alignment even with styled text.

5. **6 named theme factories** — Covers the most common branded palettes (Charm, Dracula, Nord, Catppuccin) without requiring users to define their own.

6. **Good test coverage** — Each component has dedicated tests covering: glyph rendering, plain theme passthrough, ANSI theme emission, width/alignment calculations, and edge cases (empty sections, null width, custom glyphs/runes).

7. **VHS demos** — Each component (cli-page, logo, section, stage) has a `.tape` file and rendered `.gif` in `.vhs/`, enabling visual regression testing and documentation.

---

## Weaknesses

1. **No auto theme detection** — Unlike fang which uses `lipgloss.HasDarkBackground()` for automatic light/dark adaptation, candy-kit requires explicit theme passing. Users on both dark and light terminals get the same (dark-optimized) colors by default.

2. **No shell completion generation** — Fang includes a `completion` command that generates bash/zsh/fish completions. This requires a CLI framework integration point and is not applicable to a pure presentation library, but users may expect feature parity.

3. **No manpage generation** — Fang integrates with `mango-cobra` for roff generation. Not implemented.

4. **HelpText alignment doesn't account for wide characters** — `renderRows()` uses `mb_strlen()` which counts code points, not visual cell width. Double-width characters (emoji, CJK) would cause misaligned descriptions.

5. **No built-in version flag wiring** — Fang automatically wires `--version` to build info. Candy-kit has no mechanism for this since it has no CLI framework integration.

6. **Logo is not a proper rendering primitive** — The `Logo` class stores styled content in its private field after `withColor()` applies the style. This means the Logo's internal state is a rendered string, not a renderable structure. This is a simplification that works for static logos but wouldn't support re-theming without re-rendering.

7. **No Banner title anchor positioning** — Uses fixed `Border::rounded()` + `padding(0, 2)`. No support for title positioning (top-left, top-center, top-right, etc.) which lipgloss/borders support.

---

## Comparison Against Mapped Repositories

### charmbracelet/fang (primary upstream)

| Feature | fang | candy-kit | Gap |
|---------|------|-----------|-----|
| StatusLine/success/error/warn/info | ✅ | ✅ | — |
| HelpText with alignment | ✅ | ✅ | — |
| Theme with light/dark auto-detection | ✅ | ❌ | Manual theme selection only |
| Shell completions generation | ✅ | ❌ | No CLI framework integration |
| Automatic --version wiring | ✅ | ❌ | No CLI framework integration |
| Manpage generation | ✅ | ❌ | Not applicable to library |
| Windows VT processing | ✅ | ❌ | Delegated to candy-core |
| Command grouping | ✅ | ❌ | No Cobra integration |
| Error handler with styled header | ✅ | ❌ | Only StatusLine::error() for output |
| `fang.Execute()` entry point | ✅ | ❌ | Pure library, no opinion on CLI framework |
| ColorSchemeFunc for theme factory | ✅ | ❌ | Static theme factories only |
| Option pattern (functional options) | ✅ | ❌ | Static methods only |

**Verdict:** ~50% feature parity on the presentation layer. Fang's CLI integration features (completions, version, manpages, Cobra command wiring) are not applicable to candy-kit since it has no CLI framework opinion. The presentation components (StatusLine, Banner, Section, HelpText) are well-ported.

### charmbracelet/gum (style commands)

| Feature | gum | candy-kit | Gap |
|---------|-----|----------|-----|
| `gum style` (colors/borders/padding) | ✅ | ❌ (use candy-sprinkles) | Different abstraction level |
| `gum log` (structured logging) | ✅ | ❌ (use candy-log) | Different abstraction level |
| `gum format` (markdown/code) | ✅ | ❌ (use candy-shine) | Different abstraction level |
| `gum join` (layout composition) | ✅ | ❌ (use candy-sprinkles Layout) | Different abstraction level |
| `gum spin` (spinner + command) | ✅ | ❌ | Different abstraction level |
| `gum confirm` / `gum choose` | ✅ | ❌ | Interactive TUI, not presentation |

**Verdict:** No direct feature overlap. Gum's `style` command wraps lipgloss, which is mapped to `candy-sprinkles`. Gum's `log` command wraps `charmbracelet/log`, which is mapped to `candy-log`. Candy-kit fills the gap for the static presentation helpers (StatusLine, Banner, etc.) that Gum doesn't have as standalone commands.

### charmbracelet/log (colored output)

| Feature | log | candy-kit | Gap |
|---------|-----|----------|-----|
| Leveled logging (debug/info/warn/error) | ✅ | ❌ | candy-log, not candy-kit |
| Structured key/value pairs | ✅ | ❌ | candy-log |
| Text/JSON/Logfmt formatters | ✅ | ❌ | candy-log |
| Sub-logger with context | ✅ | ❌ | candy-log |
| slog handler | ✅ | ❌ | candy-log |
| Timestamp formatting | ✅ | ❌ | candy-log |
| Styled level prefixes | ✅ | ✅ (via Theme) | Comparable |
| Global logger | ✅ | ❌ | candy-kit is stateless helpers |

**Verdict:** No direct feature overlap. `charmbracelet/log` is a full logging library with levels, formatters, and context propagation. `candy-kit` provides static presentation helpers with no logging semantics. The styled output of `StatusLine` is visually similar to what `log.Info()` etc. produce, but the mechanisms are different.

---

## Missing Features

1. **Light/dark auto-detection** — `Theme` factories don't accept a `isDark` parameter or closure; they produce fixed-palette themes. Users who want terminal-aware themes must implement their own theme selection.

2. **HelpText wide-character alignment** — `renderRows()` uses `mb_strlen()` for key width calculation, not visual cell width. Double-width characters cause misalignment.

3. **Banner title anchor positioning** — No support for placing the title at different border positions (top-left, top-center, top-right, etc.) like `BorderTitle` supports in candy-sprinkles.

4. **Logo re-theming** — Once `withColor()` is applied, the Logo stores a rendered string. There is no way to change the color of an already-colored Logo without re-creating it from the original ASCII art.

5. **Section right-side label** — The `header()` method only supports left-padded labels. No equivalent to a right-side label (e.g., `── LEFT LABEL ──── RIGHT ──`).

6. **Theme mutation helpers** — No `withSuccess()` / `withError()` etc. methods to derive a new theme from an existing one with a single color changed.

---

## Cross-Repo Innovation Opportunities

### From fang: Light/dark ColorSchemeFunc

Fang's `ColorSchemeFunc` signature:
```go
type ColorSchemeFunc = func(lipgloss.LightDarkFunc) ColorScheme
```

This enables automatic adaptation based on terminal background without requiring callers to manage dark/light state. A PHP equivalent:

```php
public static function adaptive(?Closure $isDark = null): Theme {
    $fn = $isDark ?? fn() => ColorProfile::detect()->hasDarkBackground();
    return $fn() ? Theme::dracula() : Theme::nord();
}
```

### From fang: Option pattern for HelpText

Fang's help rendering accepts `Styles` which can be customized via `WithColorSchemeFunc()`. Candy-kit's `HelpText::render()` only accepts a `Theme`. Adding an option bag for fine-grained control (e.g., custom section ordering, custom key/value separators) would increase flexibility.

### From gum: Shell completion integration

Gum generates shell completions via a hidden `completion` command. While candy-kit has no CLI framework integration point, the completion generation could be extracted as a standalone utility that works with any CLI framework's flag/command structure.

### From log: Context propagation

`charmbracelet/log` supports sub-loggers with `logger.With("key", "value")`. While candy-kit is stateless helpers, adding a `Theme::withContext(string $key, mixed $value): Theme` pattern could enable contextual theming (e.g., different colors per module).

---

## Architectural Recommendations

### Short-term (within current architecture)

1. **Add `Theme::adaptive(?Closure)`** — Theme factory that accepts a closure returning whether to use a dark or light palette. Default closure uses `ColorProfile::detect()`.

2. **Fix HelpText wide-character alignment** — Replace `mb_strlen()` with `Width::string()` for key width calculation in `renderRows()`.

3. **Add `Logo::withColorOverride()`** — Allow re-theming an already-colored Logo without storing raw ASCII art separately.

### Medium-term (new capabilities)

4. **Add `Banner::withTitleAnchor()`** — Support title positioning on the border using `BorderTitle` with anchor positions.

5. **Add `Section::headerWithRightLabel()`** — Support right-side labels for cases like `── SETUP ──────────────────── 3/5 ──`.

6. **Add `Theme::with*()` derivation methods** — `withSuccess()`, `withError()` etc. for deriving themed variants without redefining all 7 levels.

### Long-term (new subsystems)

7. **Add shell completion generator** — Extract completion generation from any CLI framework as a standalone utility in a new `candy-complete` or `sugar-complete` library.

8. **Add `HelpText::renderMarkdown()`** — Integrate `candy-shine` for rendering markdown-formatted descriptions in help output (currently only plain text description is supported).

---

## Quick Wins

1. **`Theme::adaptive()` factory** — ~10 lines in `Theme.php`:
   ```php
   public static function adaptive(?Closure $isDark = null): self {
       $fn = $isDark ?? fn() => ColorProfile::fromEnvironment()->hasDarkBackground();
       return $fn() ? self::dracula() : self::nord();
   }
   ```

2. **HelpText wide-char fix** — Replace `mb_strlen($key, 'UTF-8')` with `Width::string($key)` in `renderRows()`.

3. **`Theme::withSuccess(Color)` derivation** — Add to `Theme`:
   ```php
   public function withSuccess(Style $success): self {
       return new self($success, $this->error, $this->warn, $this->info, $this->prompt, $this->accent, $this->muted);
   }
   ```

4. **`Banner::withBorder(Border)` support** — Already implemented but not documented in the README.

---

## Notable Implementation Details

### Width-aware section fill

`Section::header()` uses `Width::string()` from `candy-core` to compute the visual width of the styled label before filling with runes:

```php
// src/Section.php:39
$remaining = max(0, $width - Width::string($head));
return $head . str_repeat($rune, $remaining);
```

This ensures that even if the label contains ANSI-styled characters (bold, colored), the ruler fills to the correct visual width, not the raw byte length.

### Theme as configuration object

Unlike fang which uses a `Styles` struct built from `ColorScheme` at render time, candy-kit's `Theme` is passed as a parameter to each render call. This:
- Avoids building a intermediate `Styles` struct
- Keeps each render call self-contained
- Allows the theme to be changed per-call without modifying global state
- Makes testing easier (pass `Theme::plain()` for deterministic output)

### Logo immutable styled-content pattern

`Logo::withColor()` applies the style and stores the result:

```php
// src/Logo.php:68
$styled = Style::new()->foreground($c)->render($this->ascii);
return new self($styled);
```

The internal representation after `withColor()` is a styled string, not a structure. This is a pragmatic simplification — the Logo is intended for static content that doesn't need re-theming. For dynamic theming, users should recreate the Logo from the original ASCII art.

---

## Test Coverage Analysis

| Component | Test File | Coverage |
|-----------|-----------|----------|
| Theme | `ThemeTest.php` | All 6 factories, ANSI SGR emission, muted faint modifier, plain passthrough |
| StatusLine | `StatusLineTest.php` | All 5 glyph types, ANSI styling on glyph, plain message passthrough, single-char glyphs |
| Banner | `BannerTest.php` | 3-row rounded box, subtitle rendering, custom border (ASCII), ANSI SGR on title, horizontal padding |
| Section | `SectionTest.php` | Fill to width, null width (trailing rune only), custom rune, rule generation |
| Stage | `StageTest.php` | Glyph + count + message, no-total variant, custom glyph, subStep tee/corner, theme accent on glyph |
| HelpText | `HelpTextTest.php` | Usage + sections rendering, empty sections, key alignment across rows |
| Logo | `LogoTest.php` | Raw ASCII passthrough, sugarcraft preset content, withColor SGR, hex string, immutability, empty ASCII |

**Assessment:** Test coverage is comprehensive for the Happy path and basic edge cases. Missing coverage for:
- Multi-byte Unicode in `Section::header()` width calculation (the `Width::string()` call)
- `HelpText::render()` with very long key names (overflow handling)
- `Banner` with very long titles (border wrapping behavior)
- `Stage` with negative or very large step numbers

---

## Conclusion

CandyKit is a well-scoped, focused library providing the presentation layer of the SugarCraft monorepo. It ports the core visual components of `charmbracelet/fang` (StatusLine, Banner, Section, Stage, HelpText) with solid fidelity to the upstream design while adapting to PHP idioms (static factories, nullable theme parameters, immutable final classes).

The main gaps relative to the upstream are the CLI integration features (shell completions, manpage generation, automatic version wiring) which require a CLI framework opinion that candy-kit correctly declines to have. The presentation components themselves are comprehensive and well-tested.

The library fills a genuine gap in the PHP CLI ecosystem — there is no comparable pure-presentation library that provides styled status lines, section headers, banners, and help text without requiring Symfony Console or another framework. This makes it a valuable building block for any PHP CLI tool that wants polished terminal output.

**Recommendation:** Ready for production use. The main improvement opportunity is adding `Theme::adaptive()` for automatic light/dark detection, which would bring feature parity with fang's theme system for the presentation layer.
