# candy-freeze Research: Code Screenshot Tools Comparison

**Date:** 2026-05-13
**Status:** Research Complete
**Library:** candy-freeze (PHP 8.3+)
**Upstream:** charmbracelet/freeze

---

## Executive Summary

candy-freeze currently renders ANSI-colored text to SVG/PNG but lacks true syntax highlighting. This research compares approaches across Go, Rust, Python, and TypeScript to identify improvements for language-aware syntax highlighting, theme support, font rendering, and export formats.

**Current Gap:** candy-freeze parses ANSI SGR codes but has no language detection or grammar-based highlighting. It relies entirely on external tools (e.g., `cat -A` or `ls -la`) to produce pre-highlighted ANSI output.

---

## 1. Tool Landscape

### 1.1 Go Ecosystem

#### charmbracelet/freeze (Upstream) ⭐
**Stars:** ~4K | **License:** MIT | **Language:** Go

| Aspect | Details |
|--------|---------|
| **Syntax Engine** | [chroma](https://github.com/alecthomas/chroma) — TextMate/Sublime-compatible lexers |
| **Language Support** | 100+ languages with automatic detection |
| **Theme Support** | Any chroma theme (50+ built-in: dracula, monokai, nord, etc.) |
| **Font Rendering** | Embedded TTF via [fontlib](https://github.com/charmbracelet/fontlib), ligature support |
| **Export Formats** | SVG (primary, zero deps), PNG (via librsvg or resvg), WebP |
| **Window Chrome** | macOS traffic lights, Windows Terminal, iTerm2, Hyper, none |
| **CLI Features** | Interactive TUI, config files, `--execute` for command output |

**Key Source:** `github.com/charmbracelet/freeze/main.go`

```go
// Freeze uses chroma for syntax highlighting
highlighter := chroma.NewChromalist()
tokens := highlighter.Tokenize(string(code), lang, theme)

// Then renders to SVG with lipgloss for styling
```

**Why it matters:** freeze is the reference implementation. candy-freeze should track its feature set.

---

#### coalaura/codeview (Go)
**License:** MIT | **Purpose:** GitHub preview images

| Aspect | Details |
|--------|---------|
| **Syntax Engine** | Custom/simple approach |
| **Language Support** | Limited to common languages |
| **Export** | PNG only |
| **Features** | Logo embedding, project name in title |

---

### 1.2 Rust Ecosystem

#### silicon ⭐
**Stars:** ~2.6K | **License:** MIT | **Language:** Rust

| Aspect | Details |
|--------|---------|
| **Syntax Engine** | [syntect](https://github.com/trishume/syntect) — Sublime Text 3 grammars |
| **Language Support** | 150+ languages |
| **Theme Support** | Any Sublime Text theme (Dracula, One Dark, Solarized built-in) |
| **Font Rendering** | System fonts via [cosmic-text](https://crates.io/crates/cosmic-text) |
| **Export Formats** | PNG only (no SVG) |
| **Window Chrome** | macOS traffic lights, toggleable |
| **Notable Features** | Line highlighting (`--highlight-lines "3-7;12"`), clipboard output |

**syntect Architecture:**

```rust
use syntect::easy::HighlightLines;
use syntect::highlighting::{ThemeSet, Style};
use syntect::parsing::SyntaxSet;

let ps = SyntaxSet::load_defaults_newlines();
let ts = ThemeSet::load_defaults();
let syntax = ps.find_syntax_by_extension("rs").unwrap();
let mut h = HighlightLines::new(syntax, &ts.themes["base16-ocean.dark"]);

// Output to colored HTML <span> tags or 24-bit ANSI escape sequences
```

**Key insight:** syntect is the gold standard for offline syntax highlighting in Rust. It parses Sublime Text grammar files and can output ANSI (for terminal), HTML (for web), or custom formats.

---

#### codesnap ⭐
**Stars:** ~554 | **License:** MIT | **Language:** Rust

| Aspect | Details |
|--------|---------|
| **Syntax Engine** | `syntect` + `ansi_term` fallback |
| **Language Support** | 100+ |
| **Export Formats** | PNG, SVG, **HTML** |
| **Font Rendering** | `cosmic-text` |
| **Notable Features** | Pure Rust, no browser needed, fast |

**SVG Export Approach (from codesnap):**

```rust
// syntect provides html module for styled spans
use syntect::html::{styled_line_to_highlighted_html, IncludeBackground};

let html = styled_line_to_highlighted_html(line, style, IncludeBackground::IfDifferent)?;
```

---

#### rucr
**Status:** Not found in mainstream search. Likely a niche/archived tool.

---

### 1.3 Python Ecosystem

#### code2image
**License:** MIT | **Approach:** Pygments + Pillow

| Aspect | Details |
|--------|---------|
| **Syntax Engine** | [pygments](https://pygments.org/) — 300+ languages |
| **Theme Support** | Pygments styles (all standard styles) |
| **Font Rendering** | PIL/Pillow bitmap fonts |
| **Export Formats** | PNG |
| **Window Chrome** | None (simple code block) |

```python
from code2image.cls import Code2Image
c2i = Code2Image()
img = c2i.highlight(code)
img.save('output.png')
```

---

#### code_nitro
**License:** MIT | **Purpose:** CLI tool, online + offline

| Aspect | Details |
|--------|---------|
| **Syntax Engine** | Pygments |
| **Features** | Auto-detect language, line selection, themes |
| **Export** | PNG |

---

#### pycodesnap ⭐
**License:** MIT | **Purpose:** Modern code screenshots

| Aspect | Details |
|--------|---------|
| **Syntax Engine** | Pygments |
| **Language Support** | 300+ |
| **Export Formats** | PNG, JPEG, WebP |
| **Features** | VS Code-style window controls, rounded corners, shadows, line numbers |

```python
from pycodesnap import CodeSnap
snap = CodeSnap("python", code)
snap.create(width=800, font_size=16, line_numbers=True, style="github-dark")
snap.save("output.png")
```

---

#### carbon-api / py-carbon
**Approach:** Web scraper wrappers for carbon.now.sh

| Aspect | Details |
|--------|---------|
| **Syntax Engine** | Carbon.now.sh (server-side) |
| **Limitations** | Requires internet, rate limits, privacy concerns |
| **Export** | PNG via browser screenshot |

**Verdict:** Not suitable for offline/CI use.

---

### 1.4 TypeScript/JavaScript Ecosystem

#### shiki ⭐⭐⭐
**Stars:** ~13K | **License:** MIT | **Language:** TypeScript

| Aspect | Details |
|--------|---------|
| **Syntax Engine** | TextMate grammars (VS Code's engine) |
| **Language Support** | 100+ (all VS Code languages) |
| **Theme Support** | All VS Code themes (100+) |
| **Export Formats** | HTML, SVG (via shiki-renderer-svg) |

**shiki Architecture:**

```typescript
import { codeToTokens } from 'shiki'

const tokens = await codeToTokens(code, {
  lang: 'javascript',
  theme: 'github-dark',
})

// Then render with shiki-renderer-svg
const { renderToSVG } = await getSVGRenderer()
const svg = await renderToSVG(tokens)
```

**SVG Renderer (shiki-renderer-svg):**

```typescript
const svgRenderer = await getSVGRenderer({
  fontFamily: 'IBM Plex Mono',
  fontSize: 14,
  backgroundColor: '#2e3440'
})
```

**Key Insight:** shiki uses the exact same grammar format as VS Code, ensuring perfect syntax highlighting accuracy. The `shiki-image` package wraps this for PNG output via `takumi`.

---

#### shiki-image
**Purpose:** Convert code snippets to images

```javascript
import { codeToImage } from 'shiki-image'

const buffer = await codeToImage('console.log("hello")', {
  lang: 'js',
  theme: 'github-dark',
  format: 'webp'
})
```

---

## 2. Architecture Comparison

### 2.1 Syntax Highlighting Engines

| Engine | Languages | Format | Accuracy | Speed |
|--------|-----------|--------|----------|-------|
| **chroma** (Go) | 100+ | TextMate | High | Fast |
| **syntect** (Rust) | 150+ | Sublime Text 3 | Very High | Fastest |
| **pygments** (Python) | 300+ | Custom RegExp | Medium-High | Medium |
| **shiki** (TS) | 100+ | TextMate (VS Code) | Highest | Fast |
| **highlight.js** (JS) | 190+ | Custom RegExp | Medium | Medium |

**Accuracy Ranking:** shiki > syntect > chroma ≈ pygments > highlight.js

### 2.2 Theme Support

| Tool | Theme Format | Built-in Themes | Custom Themes |
|------|--------------|-----------------|---------------|
| freeze (Go) | chroma | 50+ | Any chroma theme |
| silicon (Rust) | Sublime Text | 10+ | Any ST theme |
| codesnap (Rust) | Sublime Text + custom | 5 | Yes |
| code2image (Python) | pygments | All pygments styles | Yes |
| shiki (TS) | VS Code | 100+ | Any VS Code theme |

### 2.3 Font Rendering

| Tool | Approach | Ligatures | TTF Embedding |
|------|----------|-----------|---------------|
| freeze (Go) | Embedded fontlib | Yes (`--font-ligatures`) | Yes (in SVG) |
| silicon (Rust) | cosmic-text | System dependent | No |
| codesnap (Rust) | cosmic-text | System dependent | No |
| code2image (Python) | Pillow bitmap | No | No |
| shiki (TS) | Web fonts | Browser dependent | Via CSS |

### 2.4 Export Formats

| Tool | SVG | PNG | WebP | HTML | Notes |
|------|-----|-----|------|------|-------|
| freeze (Go) | ✅ Primary | ✅ librsvg/resvg | ✅ (buggy) | ❌ | SVG is native |
| silicon (Rust) | ❌ | ✅ | ❌ | ❌ | PNG only |
| codesnap (Rust) | ✅ | ✅ | ❌ | ✅ | SVG via syntect html |
| code2image (Python) | ❌ | ✅ | ❌ | ❌ | PIL-based |
| pycodesnap (Python) | ❌ | ✅ | ✅ | ❌ | PIL-based |
| shiki (TS) | ✅ | Via takumi | ✅ | ✅ | SVG renderer package |

---

## 3. candy-freeze Current Implementation Analysis

### 3.1 Current Strengths ✅

1. **Pure SVG output** — No GD/Imagick required for SVG
2. **ANSI SGR parsing** — Handles 16-color, 256-color, 24-bit RGB
3. **Window chrome** — macOS traffic lights, Windows Terminal, iTerm2, Hyper
4. **Fluent builder API** — `with*()` pattern for immutability
5. **Multiple themes** — dark, light, dracula, tokyo-night, nord
6. **i18n support** — 16 locales already translated

### 3.2 Current Gaps ❌

1. **No language-aware syntax highlighting** — Relies on external pre-highlighting
2. **No language detection** — Must specify manually or pipe pre-colored ANSI
3. **No font embedding** — Uses CSS `font-family` stack, not embedded TTF
4. **No ligature support** — Fira Code ligatures won't work
5. **Limited theme format** — Custom Theme class, not VS Code/chroma/syntect themes
6. **Hardcoded cell dimensions** — `fontSize * 0.6` for width is approximate
7. **No background color handling** — Background colors from ANSI ignored (see AnsiParser.php L13-14)

### 3.3 Core Code Locations

| File | Purpose | Key Limitation |
|------|---------|----------------|
| `src/SvgRenderer.php` | Main SVG rendering | No font embedding, approximate sizing |
| `src/PngRenderer.php` | GD-based PNG | ext-gd required, bitmap fonts only |
| `src/AnsiParser.php` | ANSI SGR parsing | No background colors, no language awareness |
| `src/Theme.php` | Theme definition | Custom format, not VS Code compatible |
| `src/WindowStyle.php` | Window chrome enum | Fixed set of styles |

---

## 4. Recommended Improvements

### 4.1 High Priority

#### 4.1.1 Language Detection
**Effort:** Medium | **Impact:** High

Add automatic language detection similar to freeze's approach:

```php
// New: LanguageDetector class
final class LanguageDetector
{
    private const EXTENSION_MAP = [
        'php' => 'php',
        'py'  => 'python',
        'rs'  => 'rust',
        'js'  => 'javascript',
        'ts'  => 'typescript',
        'go'  => 'go',
        'rb'  => 'ruby',
        // ... 100+ mappings
    ];

    public static function detect(string $code, ?string $filename = null): ?string
    {
        if ($filename !== null) {
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (isset(self::EXTENSION_MAP[$ext])) {
                return self::EXTENSION_MAP[$ext];
            }
        }

        // Heuristic: analyze code patterns
        return self::analyzeCode($code);
    }
}
```

#### 4.1.2 Background Color Support
**Effort:** Low | **Impact:** Medium

Update `AnsiParser.php` to track background colors:

```php
// In Segment.php, add:
final class Segment
{
    public function __construct(
        public readonly string $text,
        public readonly ?string $fg,
        public readonly ?string $bg,  // NEW
        public readonly bool $bold,
        public readonly bool $italic,
        public readonly bool $underline,
    ) {}
}
```

Then in `SvgRenderer.php`, emit `<tspan fill="...">` with background:

```php
// For segments with background color:
$svg .= sprintf(
    '<tspan fill="%s" stroke="%s">%s</tspan>',
    self::xmlEscape($seg->fg ?? $this->theme->foreground),
    self::xmlEscape($seg->bg),  // For background
    self::xmlEscape($seg->text),
);
```

#### 4.1.3 Theme Format Compatibility
**Effort:** High | **Impact:** High

Add support for VS Code / chroma theme JSON format:

```php
// New: VsCodeTheme class
final class VsCodeTheme
{
    /**
     * Load from VS Code JSON theme file.
     */
    public static function fromJson(string $path): self { /* ... */ }

    /**
     * Convert to candy-freeze Theme format.
     */
    public function toTheme(): Theme { /* ... */ }
}
```

This enables using any of shiki's 100+ themes or syntect's Sublime themes.

---

### 4.2 Medium Priority

#### 4.2.1 Font Embedding in SVG
**Effort:** Medium | **Impact:** Medium

Similar to how freeze embeds TTF fonts:

```php
// In SvgRenderer.php
public function renderWithEmbeddedFont(string $text, string $fontPath): string
{
    $fontData = file_get_contents($fontPath);
    $fontBase64 = base64_encode($fontData);
    $fontFace = sprintf(
        '<font-face font-family="EmbeddedFont" />' .
        '<glyph d="..." />',  // Simplified
    };

    // Inject into SVG <defs>
    $svg .= '<defs><style>@font-face { ... }</style></defs>';
}
```

**Reference:** freeze uses `github.com/charmbracelet/fontlib` to embed fonts in SVG.

#### 4.2.2 Ligature Support
**Effort:** Low | **Impact:** Low-Medium

Add `--font-ligatures` flag (like freeze):

```php
// In Theme.php or SvgRenderer.php
public function __construct(
    // ... existing params
    public readonly bool $ligatures = false,
) {}

// In SvgRenderer.php render():
$textAttrs = 'font-family="' . self::xmlEscape($this->theme->fontFamily) . '"';
if ($this->theme->ligatures) {
    $textAttrs .= ' font-variant-ligatures="common-ligatures"';
}
```

#### 4.2.3 Additional Window Styles
**Effort:** Low | **Impact:** Low

Add more window chrome styles:
- Ubuntu style
- VS Code style
- Custom title bar with text

---

### 4.3 Lower Priority

#### 4.3.1 PNG Optimization
**Effort:** Medium | **Impact:** Medium

The current `PngRenderer` uses GD's built-in bitmap fonts. Consider:
- Adding TTF support via `imagettftext()`
- Adding `imagepng()` quality/compression options
- Adding WebP export support

#### 4.3.2 Line Highlighting
**Effort:** Low | **Impact:** Low

Like silicon's `--highlight-lines "3-7;12"`:

```php
public function withHighlightedLines(array $lines): self
{
    return $this->copy(highlightedLines: $lines);
}

// In render(): apply different background to highlighted lines
$isHighlighted = in_array($i + 1, $this->highlightedLines);
$lineBg = $isHighlighted ? '#3a3a3a' : $this->theme->background;
```

#### 4.3.3 Interactive CLI Mode
**Effort:** High | **Impact:** Medium

freeze has an interactive TUI for customization:

```bash
freeze --interactive
```

This could be built using `sugar-prompt` (the SugarCraft port of bubbletea).

---

## 5. Implementation Roadmap

### Phase 1: Quick Wins (1-2 days)

| Task | Effort | Files | Impact |
|------|--------|-------|--------|
| Add background color to Segment | 2h | `Segment.php`, `AnsiParser.php`, `SvgRenderer.php` | Medium |
| Add ligature flag | 1h | `Theme.php`, `SvgRenderer.php` | Low-Medium |
| Expand window styles | 3h | `WindowStyle.php`, `SvgRenderer.php` | Low |
| Language detector with extension map | 4h | `LanguageDetector.php` | High |

### Phase 2: Theme Compatibility (3-5 days)

| Task | Effort | Files | Impact |
|------|--------|-------|--------|
| VS Code theme JSON loader | 1d | `VsCodeTheme.php`, `ThemeConverter.php` | High |
| Chroma theme converter | 1d | `ChromaThemeConverter.php` | High |
| Additional built-in themes (catppuccin, one-dark, etc.) | 1d | `Theme.php` | Medium |

### Phase 3: Font & Export (3-4 days)

| Task | Effort | Files | Impact |
|------|--------|-------|--------|
| TTF font embedding in SVG | 2d | `SvgRenderer.php`, `FontEmbedder.php` | Medium |
| WebP export support | 1d | `PngRenderer.php` or new `WebpRenderer.php` | Low |
| TTF text rendering in PNG | 2d | `PngRenderer.php` | Medium |

### Phase 4: Advanced Features (1+ week)

| Task | Effort | Files | Impact |
|------|--------|-------|--------|
| Interactive CLI TUI | 1w | `bin/candyfreeze`, uses `sugar-prompt` | Medium |
| Syntax-aware highlighting (integration layer) | 2w+ | External lib or FFI | High |

---

## 6. Integration Options for True Syntax Highlighting

Since PHP lacks native syntax highlighting libraries of comparable quality, consider these approaches:

### 6.1 External Process / FFI

```php
// Option A: Spawn external highlighter (like highlight.js via node)
final class ExternalHighlighter
{
    public function highlight(string $code, string $lang, string $theme): string
    {
        $proc = proc_open(
            ['node', 'highlight.mjs', '--lang', $lang, '--theme', $theme],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );

        fwrite($pipes[0], $code);
        fclose($pipes[0]);

        $output = stream_get_contents($pipes[1]);
        proc_close($proc);

        return $output; // Returns ANSI-colored code
    }
}
```

### 6.2 Pre-built Grammar Files

Similar to how syntect bundles Sublime Text grammars:

```php
// Bundle JSON grammar files and write a pure-PHP tokenizer
// for a subset of common languages (PHP, JS, Python, etc.)
final class PhpTokenizer
{
    private array $grammars;

    public function __construct()
    {
        $this->grammars = json_decode(
            file_get_contents(__DIR__ . '/../grammars/javascript.json'),
            true
        );
    }

    public function tokenize(string $code): array { /* ... */ }
}
```

**Verdict:** Not recommended — maintaining grammar files is high maintenance.

### 6.3 PHP Extension (FFI to Rust)

```php
// If ext-syntect were available via FFI
$syntax = $syntect->findSyntaxByExtension('php');
$tokens = $syntect->highlight($code, $syntax, $theme);
```

**Verdict:** Requires users to compile/install Rust extension.

### 6.4 Recommended Path

**Hybrid approach for v2.0:**

1. **Keep ANSI parsing for backward compatibility** — tools like `ls -la`, `cat -A` produce ANSI
2. **Add optional syntax-aware highlighting** via external process (node with highlight.js or deno with shiki)
3. **Bundle shiki-compatible theme JSONs** for easy theming

```php
final class SvgRenderer
{
    public function renderWithHighlighting(string $code, string $lang): string
    {
        // Option 1: Use external shiki/highlight.js process
        $ansiCode = $this->highlighter->highlight($code, $lang, $this->theme->toAnsiFormat());

        // Option 2: Fall back to raw code if no highlighter available
        if ($ansiCode === null) {
            return $this->render($code);
        }

        return $this->render($ansiCode);
    }
}
```

---

## 7. References

### Upstream
- **charmbracelet/freeze:** https://github.com/charmbracelet/freeze
- **chroma:** https://github.com/alecthomas/chroma

### Rust Tools
- **silicon:** https://github.com/Aloxaf/silicon
- **syntect:** https://github.com/trishume/syntect
- **codesnap:** https://github.com/mistricky/codesnap

### Python Tools
- **code2image:** https://github.com/axju/code2image
- **pycodesnap:** https://pypi.org/project/pycodesnap/

### TypeScript Tools
- **shiki:** https://github.com/shikijs/shiki
- **shiki-renderer-svg:** https://www.npmjs.com/package/shiki-renderer-svg
- **shiki-image:** https://github.com/syntax-syndicate/shiki-image

### Theme Resources
- **VS Code Themes:** https://github.com/themegrill/vscode-theme-collection
- **Catppuccin:** https://github.com/catppuccin/catppuccin
- **Chroma Themes:** https://github.com/chroma-graphql/chroma-themes

---

## Appendix A: Language Detection Map (Recommended)

```php
private const EXTENSION_MAP = [
    'php'  => 'php',
    'py'   => 'python',
    'pyw'  => 'python',
    'rs'   => 'rust',
    'go'   => 'go',
    'js'   => 'javascript',
    'jsx'  => 'javascript',
    'ts'   => 'typescript',
    'tsx'  => 'typescript',
    'rb'   => 'ruby',
    'gem'  => 'ruby',
    'java' => 'java',
    'c'    => 'c',
    'cpp'  => 'cpp',
    'cc'   => 'cpp',
    'h'    => 'c',
    'hpp'  => 'cpp',
    'cs'   => 'csharp',
    'swift' => 'swift',
    'kt'   => 'kotlin',
    'kts'  => 'kotlin',
    'scala' => 'scala',
    'r'    => 'r',
    'R'    => 'r',
    'lua'  => 'lua',
    'pl'   => 'perl',
    'pm'   => 'perl',
    'sh'   => 'bash',
    'bash' => 'bash',
    'zsh'  => 'bash',
    'fish' => 'fish',
    'ps1'  => 'powershell',
    'psm1' => 'powershell',
    'ex'   => 'elixir',
    'exs'  => 'elixir',
    'erl'  => 'erlang',
    'hrl'  => 'erlang',
    'hs'   => 'haskell',
    'ml'   => 'ocaml',
    'mli'  => 'ocaml',
    'fs'   => 'fsharp',
    'fsx'  => 'fsharp',
    'ex'   => 'elixir',
    'exs'  => 'elixir',
    'db'   => 'sql',
    'sql'  => 'sql',
    'md'   => 'markdown',
    'markdown' => 'markdown',
    'json' => 'json',
    'yaml' => 'yaml',
    'yml'  => 'yaml',
    'xml'  => 'xml',
    'html' => 'html',
    'htm'  => 'html',
    'css'  => 'css',
    'scss' => 'scss',
    'sass' => 'sass',
    'less' => 'less',
    'vue'  => 'vue',
    'svelte' => 'svelte',
    'dockerfile' => 'dockerfile',
    'tf'   => 'hcl',
    'tfvars' => 'hcl',
    'proto' => 'protobuf',
    ' graphql' => 'graphql',
    'toml' => 'toml',
    'ini'  => 'ini',
    'cfg'  => 'ini',
    'conf' => 'nginx',
    'nix'  => 'nix',
    'zig'  => 'zig',
    'nim'  => 'nim',
    'nimble' => 'nim',
    'v'    => 'v',
    'mod'  => 'v',
    'solidity' => 'solidity',
    'vy'   => 'vyper',
];
```

---

## Appendix B: Theme Color Conversion Example

VS Code / chroma theme to candy-freeze Theme conversion:

```php
final class ThemeConverter
{
    /**
     * Convert a VS Code JSON theme to candy-freeze Theme.
     *
     * @param array $vsCodeTheme Parsed JSON from VS Code theme file
     */
    public static function fromVsCode(array $vsCodeTheme): Theme
    {
        $colors = $vsCodeTheme['colors'] ?? [];

        // Map VS Code colors to candy-freeze colors
        $background = $colors['editor.background'] ?? '#1e1e1e';
        $foreground = $colors['editor.foreground'] ?? '#d4d4d4';
        $lineNumber = $colors['editorLineNumber.foreground'] ?? '#858585';

        // Derive border/shadow from background
        $border = self::darken($background, 0.1);
        $shadow = 'rgba(0, 0, 0, 0.5)';

        return new Theme(
            background:   $background,
            foreground:   $foreground,
            border:       $border,
            shadow:       $shadow,
            lineNumber:   $lineNumber,
            windowRed:    '#ff5f56',
            windowYellow: '#ffbd2e',
            windowGreen:  '#27c93f',
        );
    }

    private static function darken(string $hex, float $amount): string
    {
        $r = max(0, intval(substr($hex, 1, 2), 16) - ($amount * 255));
        $g = max(0, intval(substr($hex, 3, 2), 16) - ($amount * 255));
        $b = max(0, intval(substr($hex, 5, 2), 16) - ($amount * 255));
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }
}
```

---

*Research compiled from upstream repositories, official documentation, and code analysis.*
*Last updated: 2026-05-13*
