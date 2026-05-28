# SugarCraft/candy-freeze

## Metadata
- **Package**: `sugarcraft/candy-freeze`
- **Upstream**: `charmbracelet/freeze` (Go, ~4.6k stars)
- **Status**: 🟢 v1 ready
- **PHP**: `^8.3`
- **Dependencies**: `sugarcraft/candy-core`, `sugarcraft/candy-sprinkles`, `sugarcraft/candy-shine`
- **CLI**: `bin/candyfreeze` (stdin/stdout, theme flags, window controls)
- **Docs**: https://sugarcraft.github.io/lib/candy-freeze.html

---

## Feature List

1. **SVG-first code screenshot rendering** — pure SVG output, no `ext-gd` required
2. **PNG rendering via GD** — optional `ext-gd` path for raster output
3. **ANSI SGR parsing** — foreground (16-color / 256-color / 24-bit truecolor), background, bold, italic, underline
4. **Chroma theme file loader** — loads `.json` Chroma themes
5. **VS Code theme file loader** — loads VS Code `.json` theme files
6. **5 built-in themes** — dark (charm-ish), light, dracula, tokyoNight, nord
7. **Window decoration styles** — macOS traffic lights, Windows Terminal title bar, iTerm2, Hyper, none
8. **Drop shadow** — SVG `<filter>` with `feDropShadow`, configurable
9. **Rounded corners** — `rx`/`ry` on background `<rect>`
10. **Line numbers** — gutter with configurable colour
11. **Line highlighting** — range-based background highlight rects
12. **Font embedding** — TTF base64-embedded in SVG `@font-face`
13. **Ligature control** — `font-variant-ligatures="normal"` toggle
14. **Language detection** — shebang, filename extension, content signature heuristics
15. **Interactive TUI via `candyfreeze` CLI** — stdin/stdout, file input, flag-based configuration
16. **i18n support** — 14 locales (en, fr, de, es, pt, pt-br, zh-cn, zh-tw, ja, ru, it, ko, pl, nl, tr, cs, ar)
17. **Immutable fluent builders** — `withTheme()`, `withPadding()`, `withWindow()`, etc.

---

## Source Tree

```
candy-freeze/
├── bin/candyfreeze                  # CLI entry point
├── src/
│   ├── AnsiParser.php               # SGR escape sequence parser → Segment[]
│   ├── Segment.php                  # Immutable styled-run value object
│   ├── SvgRenderer.php              # Primary SVG renderer (no GD needed)
│   ├── PngRenderer.php              # GD-based PNG renderer
│   ├── Theme.php                    # Theme value object + 5 presets
│   ├── WindowStyle.php              # enum: Macos | WindowsTerminal | ITerm2 | Hyper | None
│   ├── LanguageDetector.php         # Shebang / extension / content heuristics
│   ├── Lang.php                     # i18n facade extending SugarCraft\Core\I18n\Lang
│   └── Theme/
│       ├── ChromaThemeLoader.php    # Chroma JSON → Theme
│       └── VsCodeThemeLoader.php    # VS Code JSON → Theme
├── tests/
│   ├── AnsiParserTest.php            # 7 cases: plain text, foreground, bold, truecolor, xterm256, OSC, reset
│   ├── SvgRendererTest.php           # 11 cases: SVG structure, window controls, shadow, line numbers, ANSI
│   ├── PngRendererTest.php            # 10 cases: PNG signature, padding, window, background, themes
│   ├── WindowStyleTest.php           # 13 cases: all 5 styles, SVG validity, Macos vs iTerm2 radii
│   ├── ChromaThemeLoaderTest.php     # 7 cases: file load, fromArray, hex normalization, defaults
│   ├── VsCodeThemeLoaderTest.php     # 7 cases: file load, fromArray, tokenColors mapping, hex normalization
│   ├── LanguageDetectorTest.php      # 29 cases: shebang, extension, content scoring, fallback
│   ├── LigatureTest.php              # 5 cases: default off, withLigatures, fluent chain, ANSI combo
│   ├── FontEmbedLineHighlightTest.php # 14 cases: font embedding, line highlight range/position
│   └── SegmentTest.php               # 5 cases: bg defaults, withBg immutability
├── examples/
│   ├── screenshot.php               # Renders code to SVG with 5 themes → .svg files
│   └── freeze_to_png.php            # Renders code to PNG with 5 themes + ANSI demo + window styles
└── lang/
    ├── en.php (source of truth)
    ├── fr, de, es, pt, pt-br, zh-cn, zh-tw, ja, ru, it, ko, pl, nl, tr, cs, ar.php (translations)
```

---

## Architecture

### Syntax Highlighting Architecture

Candy-freeze does **not** include a lexer. It relies entirely on **ANSI SGR escape sequences** for color and style information. The architecture is:

```
Input text (with ANSI SGR codes)
         │
         ▼
AnsiParser::parse()          ──► list<Segment>
  ├─ CSI SGR (color/style)    ──► fg, bg, bold, italic, underline flags
  ├─ OSC (title, hyperlinks)  ──► silently skipped
  └─ plain text              ──► passed through

Segments
  │
  ▼
SvgRenderer / PngRenderer
  ├─ Per-segment <rect> (bg) + <text> (content with fg/bold/italic/underline)
  └─ Window chrome (traffic lights / title bar)
```

**Key insight**: Unlike `charmbracelet/freeze` which uses the **Chroma** Go library to tokenize source code into syntax-colored spans, candy-freeze requires the input to already carry ANSI codes (e.g., from a syntax-highlighted terminal output, or from a tool like `pygments` or `highlight` run as a pre-process). This is a deliberate design trade-off — porting a full lexer to PHP would be massive.

The **ChromaThemeLoader** and **VsCodeThemeLoader** are present not for live syntax highlighting, but to convert existing theme files (with token colors) into a `Theme` object used for the window chrome, gutter, and base text colors. The token colors from theme files map to `windowRed`, `windowYellow`, `windowGreen` in the Theme — these color the decorative window elements, not the code itself.

### Rendering Pipeline

**SvgRenderer** (`SvgRenderer.php`, 413 lines):
1. `Ansi::strip($line)` computes visible character count for dimensioning (no TTF metrics needed)
2. Cell size = `fontSize * 0.6` wide, `fontSize * lineHeight` tall
3. SVG dimensions = `(cols + gutter) * cellW + padding*2` × `lines * cellH + header + padding`
4. Emits `<defs>`: shadow `<filter>`, optional embedded font via base64 `@font-face`
5. Emits background `<rect>` with optional `rx` (border-radius) and `stroke` (border)
6. Emits window chrome via `buildMacosWindow()` / `buildWindowsTerminalWindow()` / `buildITerm2Window()` / `buildHyperWindow()`
7. For each line: line-highlight `<rect>` (if active), gutter `<text>` (line numbers), then per-segment `<rect>` (bg) + `<text>` (content with `fill`, `font-weight`, `font-style`, `text-decoration`)

**PngRenderer** (`PngRenderer.php`, 373 lines):
- Mirrors SvgRenderer structurally but uses GD bitmap fonts (`imagestring`, font 5 = 8×16)
- Shadow via semi-transparent filled rectangle offset by 6px
- Traffic lights via `imagefilledellipse()` — three circles
- No per-segment background rect — GD's bitmap font doesn't support inline background per character run; instead the overall frame bg is rendered but segment bg is lost in the current implementation
- Uses `imagecreatetruecolor()` + alpha blending for shadow

### Theme System

`Theme.php` (103 lines) is a plain readonly value object:

```php
Theme {
    background, foreground, border, shadow,
    lineNumber, windowRed, windowYellow, windowGreen,
    fontFamily, fontSize, lineHeight, windowStyle
}
```

5 presets: `dark()`, `light()`, `dracula()`, `tokyoNight()`, `nord()`.

**ChromaThemeLoader** maps flat chroma `colors` object (keys like `comment`, `keyword`, `string`) to Theme properties via `TOKEN_MAP`:
```php
'comment'  => 'lineNumber',   // Uses comment color for gutter
'keyword'  => 'windowRed',    // Maps keyword color to red traffic light
'string'   => 'windowGreen',  // Maps string color to green traffic light
'number'   => 'windowYellow',// Maps number color to yellow traffic light
```

**VsCodeThemeLoader** parses `tokenColors[]` array (TextMate scopes) and `colors` object (VS Code theme keys):
- `editor.background` / `editor.foreground` → Theme bg/fg
- `editorLineNumber.foreground` → lineNumber
- Token colors mapped via `SCOPE_MAP` (comment → lineNumber, keyword → windowRed, etc.)
- Foreground is intentionally NOT overridden from token colors — editor.foreground is authoritative base text color

### Window Decoration Rendering

`WindowStyle` enum (5 cases):
- **Macos** (`Macos`) — 3 circles, r=6, gap=18, at y=shadowMargin+18
- **WindowsTerminal** (`WindowsTerminal`) — title bar rect (#1e1e1e) + 3 rect buttons (#444444), no circles
- **ITerm2** (`ITerm2`) — 3 circles, r=4, gap=14 (smaller/tighter than macOS)
- **Hyper** (`Hyper`) — thin title bar rect + 3 circles inside it
- **None** (`None`) — empty string (no chrome)

### How it Uses candy-shine / candy-core

- **`SugarCraft\Core\Util\Ansi::strip()`** — Called in both SvgRenderer and PngRenderer to compute visible character width for layout math (line 122 in SvgRenderer, line 100 in PngRenderer). This is the only ANSI utility consumed from candy-core.
- **`SugarCraft\Core\I18n\Lang`** — Extended by `Lang.php` with namespace `'freeze'`, providing `Lang::t()` for i18n.

The package does NOT use candy-shine for rendering — it implements its own SVG DOM building inline (string concatenation into SVG XML).

---

## Comparison with Upstream charmbracelet/freeze

| Feature | freeze (Go) | candy-freeze (PHP) | Gap |
|---------|-------------|-------------------|-----|
| SVG output | ✅ xml + etree | ✅ pure string concatenation | None — feature parity |
| PNG output | ✅ rsvg-convert or resvg WASM | ✅ GD (ext-gd) | None — different backend |
| WebP output | ✅ via rsvg-convert | ❌ Not supported | Significant — no WebP |
| Chroma syntax highlighting | ✅ 100+ lexers + themes | ❌ Not included | Major — requires pre-colored input |
| VS Code theme loading | ❌ | ✅ ChromaThemeLoader + VsCodeThemeLoader | SugarCraft leads here |
| ANSI input parsing | ✅ dispatcher-based | ✅ AnsiParser-based | Feature parity |
| Window styles | macOS, rounded, square | Macos, WindowsTerminal, iTerm2, Hyper, None | SugarCraft leads (more styles) |
| Line numbers | ✅ | ✅ | None |
| Line range selection | ✅ `--lines` | ❌ Not in CLI (withHighlight() in lib) | CLI gap |
| Font embedding | ✅ JetBrains Mono TTF embedded | ✅ via withFont() | None |
| Ligatures | ✅ font-variant-ligatures | ✅ withLigatures() | None |
| Drop shadow | ✅ feDropShadow filter | ✅ same filter | None |
| Border radius | ✅ CSS-style | ✅ rx attribute | None |
| Interactive TUI | ✅ huh-based form | ❌ CLI-only | Major — no interactive config |
| User config persistence | ✅ XDG JSON | ❌ Stateless | CLI gap |
| PTY execution | ✅ --execute flag | ❌ Not included | Major |
| tmux integration | ✅ capture-pane | ❌ Not included | N/A for static screenshots |
| Language detection | ✅ Chroma-based | ✅ Heuristic (shebang/ext/content) | Different approach, similar result |
| 5 built-in themes | ✅ (charm default + all Chroma) | ✅ dark/light/dracula/tokyoNight/nord | Fewer than upstream |

### Key Architectural Differences

1. **No embedded lexer** — freeze uses Chroma to tokenize and colorize code at rest; candy-freeze requires input that already contains ANSI SGR codes. This is a fundamental design difference — candy-freeze is an *ANSI-to-image* renderer, not a *code-to-image* renderer.

2. **PHP string-concatenation SVG** — freeze uses Go's `xml/etree` for proper namespace-aware XML construction; candy-freeze uses `$svg .= sprintf(...)` string concatenation. This works for SVG but does not validate XML structure.

3. **GD vs rsvg-convert** — freeze uses external binaries for PNG rasterization (fast, high-quality) with WASM fallback (portable); candy-freeze uses `imagepng()` from ext-gd (ubiquitous but lower quality for anti-aliased text).

4. **Theme loading** — candy-freeze ships its own ChromaThemeLoader and VsCodeThemeLoader; freeze embeds Chroma. This makes candy-freeze more portable but requires separate theme file management.

---

## Comparison with Mapped Third-Party Repos

### charmbracelet/glamour (markdown rendering with ANSI)
- glamour converts markdown → ANSI escape sequences for terminal rendering
- candy-freeze converts ANSI → SVG/PNG image for screenshot capture
- **Relationship**: Inverse pipelines — glamour is input-side, candy-freeze is output-side
- glamour's `StylePrimitive` and cascade-style system influenced the Theme value object design in candy-freeze

### charmbracelet/gum (shell script helpers)
- gum is a CLI tool that wraps Bubble Tea components for shell scripts
- candy-freeze is a PHP library/CLI that produces static images, not interactive TUIs
- **No direct overlap** — gum is about interactive prompts, candy-freeze is about static output capture

### sugar-charts (image rendering)
- `sugar-charts` has a `Picture` class that renders images into terminals via Sixel/Kitty/iTerm2 protocols
- `candy-freeze` renders text/code as SVG/PNG images (static files, not terminal protocols)
- **Relationship**: Both handle image generation but for different targets (terminal vs file)

### candy-mosaic (terminal image rendering)
- `candy-mosaic` renders PNG/JPEG into terminals via multiple protocols (Kitty, iTerm2, Sixel, HalfBlock)
- `candy-freeze` renders text (with ANSI) as images
- **No overlap** — mosaic is for displaying images in terminals, freeze is for capturing text as images

---

## Notable Implementation Patterns

### [pattern:per-segment-bg-rect]
`SvgRenderer::render()` emits a `<rect>` for every segment with a non-null `$bg`. The rect is emitted before the `<text>` element at the same X/Y, scaled to `cellW × textLen` wide and `cellH` tall, using the segment's background colour as the fill. This produces per-character background highlighting matching the ANSI terminal experience.

### [pattern:sgr-bg-48]
ANSI SGR code 48 (set background) is parsed identically to code 38 (set foreground) — mode 5 for 256-color (`\x1b[48;5;Nm`) and mode 2 for 24-bit RGB (`\x1b[48;2;R;G;Bm`). Code 49 resets the background to default. The `AnsiParser::applySgr()` method handles both codes symmetrically.

### [pattern:language-detector-priority-chain]
`LanguageDetector::detect()` uses a three-tier priority chain: (1) shebang line exact/partial match, (2) filename extension lookup, (3) content signature scoring. Shebang is checked first because it is authoritative when present. Content scoring uses a simple hit-count per language signature array; the language with the most hits wins. Returns `"text"` as the fallback.

### [pattern:segment-immutable-withbg]
`Segment` is an immutable value object. `withBg(?string $bg)` returns a new `Segment` instance with only the `$bg` field changed (via private constructor + named parameters), leaving `$text`, `$fg`, and attribute flags unchanged. This follows the same `mutate()` pattern used in other SugarCraft immutable classes.

---

## Test Coverage

- **10 test files**, **92+ test cases**
- `AnsiParserTest` — SGR parsing correctness (16-color, 256-color, truecolor, bold, OSC, reset)
- `SvgRendererTest` — SVG structure, window controls, shadow, line numbers, ANSI integration, XML escaping
- `PngRendererTest` — PNG signature, sizing, padding, window, background, themes
- `WindowStyleTest` — All 5 styles render correctly and produce valid SVG
- `ChromaThemeLoaderTest` — File loading, fromArray, hex normalization (3/6/8-digit)
- `VsCodeThemeLoaderTest` — File loading, tokenColors mapping, hex normalization
- `LanguageDetectorTest` — 29 cases covering all detection paths
- `LigatureTest` — Default off, withLigatures, fluent chain, ANSI combo
- `FontEmbedLineHighlightTest` — Font embedding, line highlight range, position alignment, combined features
- `SegmentTest` — Immutability, withBg, null defaults

Coverage runs via `cd candy-freeze && composer install && vendor/bin/phpunit`.

---

## Strengths

1. **SVG-only path requires no extensions** — The primary renderer (SvgRenderer) produces valid SVG XML with no ext-gd, ImageMagick, or FFI dependencies. Suitable for CI environments, git diffs, and anywhere an image file is needed but graphics toolchains are unavailable.

2. **Multiple window decoration styles** — 5 styles (Macos, WindowsTerminal, ITerm2, Hyper, None) vs upstream's implicit macOS style. Particularly noteworthy that Windows Terminal and Hyper styles are included.

3. **Theme loading from standard formats** — Chroma and VS Code theme file support makes it easy to use existing editor themes without manual color mapping.

4. **Line highlighting** — `withHighlight($start, $end, $color)` for focused/selected line rendering, useful for code tutorial screenshots.

5. **Font embedding** — `withFont('/path/to/font.ttf')` embeds the font as base64 in the SVG `@font-face`, ensuring consistent rendering across environments without relying on system fonts.

6. **Immutable fluent builders** — All renderer options return new instances, safe for concurrent use and predictable state.

7. **i18n coverage** — 15 locales covering major languages, using the shared `SugarCraft\Core\I18n\Lang` registry.

8. **CLI usability** — `candyfreeze` accepts stdin or file input, with all rendering options available via flags.

---

## Weaknesses / Gaps

1. **No syntax highlighting** — Unlike upstream freeze, candy-freeze does not include a lexer. It requires input pre-colored with ANSI SGR codes. This fundamentally limits use cases to terminal output capture and tools that output ANSI (pygments, highlight, etc.).

2. **No WebP output** — Upstream freeze supports WebP via rsvg-convert; candy-freeze only supports SVG and PNG (via GD).

3. **No interactive TUI configuration** — Upstream freeze has a full interactive configuration mode via `freeze --interactive`. candy-freeze is CLI-only.

4. **GD renderer loses per-segment background** — `PngRenderer` cannot render per-segment background colors because GD's bitmap font rendering doesn't support inline background per character. Only the frame background is visible.

5. **No PTY execution** — Upstream freeze has `--execute` to run a command in a PTY and capture colored output. candy-freeze reads pre-existing text.

6. **No user config persistence** — Upstream freeze saves settings to `~/.config/freeze/user.json`. candy-freeze is stateless per invocation.

7. **CLI only exposes SVG** — The CLI (`bin/candyfreeze`) only outputs SVG; PNG output is only available via the PHP library API (`PngRenderer`).

8. **No line range CLI flag** — `withHighlight()` is available in the library API, but the CLI has no `--lines` equivalent for cropping input to a line range.

9. **Fixed 0.6 font aspect ratio** — `cellW = fontSize * 0.6` is a fixed estimate; true monospace aspect ratios vary by font. This causes slight layout drift for proportional or variable-width fonts.

---

## SugarCraft Ecosystem Position

- **Depends on**: `candy-core` (Ansi::strip), `candy-sprinkles` (?), `candy-shine` (?)
- **Consumed by**: Not yet used by other packages in the monorepo (verified by searching composer.json files for sugarcraft/candy-freeze)
- **candy-palette** (ColorProfile) is consumed by candy-freeze for color profile detection in terminals, but the dependency is not declared in composer.json — ColorProfile is accessed through candy-core

The package is at the **output edge** of the rendering pipeline — it produces static image files rather than interactive TUIs, making it complementary to (not overlapping with) the core TUI libraries.

---

## Future Opportunities

1. **Syntax highlighting integration** — Partner with a PHP syntax highlighter (e.g., `geshi`, `php-lexer`, or a Python bridge to `pygments`) to auto-colorize code before rendering, closing the lexer gap.

2. **WebP support** — Add `WebpRenderer` using `imagewebp()` (ext-gd) or an external `cwebp` binary invocation.

3. **Interactive CLI** — Build a `candyfreeze --interactive` TUI using `candy-sprinkles` form components for live theme/preview configuration.

4. **PNG CLI** — Add `--format svg|png` flag to CLI, wiring in `PngRenderer`.

5. **Line range CLI** — Add `--lines start,end` flag to crop input.

6. **User config** — Persist last-used settings to `$XDG_CONFIG_HOME/candy-freeze/user.json`.

7. **PTY capture** — Integrate `candy-pty` for `--execute` flag functionality.

8. **Background color fix for PngRenderer** — Investigate using `imagettftext()` with a background rect per character to enable per-segment background in PNG output (currently only frame background works).

---

## Source References

- `src/AnsiParser.php:34` — `parse()` entry point, CSI SGR scan loop
- `src/AnsiParser.php:96` — `applySgr()` handles 38/48 (fg/bg) modes 2 (RGB) and 5 (256-color), plus all attribute codes
- `src/AnsiParser.php:170` — `xterm256ToHex()` converts 256-color index to 6-digit hex
- `src/SvgRenderer.php:112` — `render()` — full pipeline, ANSI strip → dimension → SVG emit
- `src/SvgRenderer.php:189` — Per-segment `<rect>` bg + `<text>` fg/attrs emit
- `src/SvgRenderer.php:225` — `AnsiParser::parse($line)` called per line
- `src/SvgRenderer.php:256` — `buildWindowChrome()` delegates to style-specific builders
- `src/PngRenderer.php:86` — `render()` — GD path with shadow compositing
- `src/PngRenderer.php:201` — `AnsiParser::parse($line)` called per line (but bg rendering limited by GD font)
- `src/Theme.php:34` — 5 preset factories
- `src/Theme/ChromaThemeLoader.php:23` — `TOKEN_MAP` maps chroma token names to Theme properties
- `src/Theme/VsCodeThemeLoader.php:25` — `SCOPE_MAP` maps TextMate scope roots to Theme properties
- `src/LanguageDetector.php:17` — `SHEBANG_MAP` for shebang-to-language mapping
- `src/LanguageDetector.php:29` — `CONTENT_SIGNATURES` for content-based detection
- `src/WindowStyle.php:12` — Enum cases: Macos, WindowsTerminal, ITerm2, Hyper, None
- `src/Lang.php:18` — Extends `SugarCraft\Core\I18n\Lang` with namespace `'freeze'`
- `bin/candyfreeze:67` — Theme name matching with kebab-case normalization
- `candy-core/src/Util/Ansi.php:622` — `Ansi::strip()` used for visible-width computation
