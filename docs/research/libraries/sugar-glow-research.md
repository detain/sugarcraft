# sugar-glow Library Research: Markdown/Terminal Rendering

**Date:** 2026-05-13
**Upstream:** charmbracelet/glow
**Focus:** Compare approaches across languages for ANSI rendering, syntax highlighting, themes, paging, and link handling

---

## Executive Summary

Sugar-glow's rendering stack (candy-shine + league/commonmark) is functional but has significant gaps compared to modern implementations:
- **Syntax highlighting** is regex-based and limited to 7 languages
- **Streaming rendering** is not supported
- **Theme system** uses PHP constructor properties vs. JSON configuration
- **CJK/emoji width** handling is not explicitly addressed

This document details findings from Go, Rust, Python, and JavaScript ecosystems to identify patterns for improvement.

---

## 1. Current Implementation (sugar-glow)

**Source:** `/home/sites/sugarcraft/sugar-glow/src/` + `/home/sites/sugarcraft/candy-shine/src/`

### Architecture
```
Input Markdown
    ↓
league/commonmark (parser)
    ↓
Renderer.php (ANSI walker)
    ↓
Theme.php (Style objects per element)
    ↓
SyntaxHighlighter.php (regex-based, 7 languages)
    ↓
ANSI output
```

### Current Capabilities
- ✅ 8 stock themes (ansi, plain, dark, light, dracula, tokyo-night, pink, notty)
- ✅ Word wrap with Width::wrapAnsi()
- ✅ OSC 8 hyperlinks
- ✅ GFM tables, task lists, strikethrough
- ✅ JSON theme loading via Theme::fromJson()
- ✅ Paging via Viewport (candy-core)

### Current Limitations
- ❌ Syntax highlighting limited to: PHP, JS, TS, Python, Go, Bash, SQL (no JSON, Ruby, Rust, etc.)
- ❌ No streaming/incremental rendering
- ❌ No Chroma/syntect-style rich theme definition for code blocks
- ❌ No file watching in pager mode
- ❌ CJK/emoji width handling not explicit

---

## 2. Go Ecosystem (Upstream: glow + glamour)

### glow (charmbracelet/glow)
**Source:** [github.com/charmbracelet/glow](https://github.com/charmbracelet/glow) (25K stars)

#### Paging Architecture
```go
// From ui/pager.go - uses Bubble Tea viewport
type pagerModel struct {
    common   *commonModel
    viewport viewport.Model  // Bubble Tea viewport
    state    pagerState
    watcher  *fsnotify.Watcher  // File watching for live reload
}
```

Key features:
- **File watching**: `fsnotify.Watcher` monitors markdown file and re-renders on change
- **High-performance rendering**: `viewport.HighPerformanceRendering` flag
- **Less as pager**: Falls back to `less -r` for CLI mode
- **Help overlay**: `?` key shows hotkey help

#### glamour Rendering (charmbracelet/glamour)
**Source:** [github.com/charmbracelet/glamour](https://github.com/charmbracelet/glamour) (3.4K stars)

Uses **goldmark** parser + custom ANSI renderer. Theme system is JSON-based:

```json
// From glamour/styles/dark.json
{
  "document": {
    "block_prefix": "\n",
    "block_suffix": "\n",
    "color": "252",
    "margin": 2
  },
  "heading": {
    "block_suffix": "\n",
    "color": "39",
    "bold": true
  },
  "code_block": {
    "color": "244",
    "margin": 2,
    "chroma": {          // Chroma syntax highlighting theme embedded
      "text": { "color": "#C4C4C4" },
      "keyword": { "color": "#00AAFF" },
      "string": { "color": "#C69669" }
    }
  }
}
```

**Key glamour patterns:**
1. `block_prefix`/`block_suffix` for document-level wrappers
2. `indent_token` for list continuation markers (e.g., `│ ` for blockquotes)
3. `chroma` section for syntax highlighting theme
4. `format` string for custom rendering (e.g., `Image: {{.text}} →`)

### v2.0 Changes (2026-03)
- Now uses Lip Gloss v2 for color downsampling
- Auto-style detection removed (default is "dark")
- Overline styles removed (poor terminal support)

---

## 3. Rust Ecosystem

### mdansi (2026-03, actively maintained)
**Source:** [crates.io/crates/mdansi](https://crates.io/crates/mdansi)

Modern, fast Markdown-to-ANSI renderer with streaming mode:

```rust
use mdansi::{Renderer, RenderOptions, Theme, TerminalCaps};

// Streaming mode for LLM output
let renderer = Renderer::streaming();
for chunk in markdown_chunks {
    print!("{}", renderer.render(&chunk));
}
```

**Key features:**
1. **Streaming mode**: Incremental rendering for piped LLM/AI output with buffered multi-line constructs
2. **200+ languages** via syntect syntax highlighting
3. **TOML theme system** with 4 built-in themes + custom `.toml` files
4. **Box-drawn code blocks** with language labels and optional line numbers
5. **Adaptive terminal detection**: Auto-detects color level, width, capabilities
6. **Smart text wrapping**: Unicode-aware, CJK/emoji-correct, orphan prevention

### markdown-to-ansi
**Source:** [crates.io/crates/markdown-to-ansi](https://crates.io/crates/markdown-to-ansi)

Simpler library, uses pulldown-cmark + syntect:

```rust
use markdown_to_ansi::{render, Options};

let opts = Options {
    syntax_highlight: true,
    width: Some(80),
    code_bg: true,  // Background color on code blocks
};
let output = render("# Hello\n\nThis is **bold**.", &opts);
```

### pulldown-cmark
**Source:** [github.com/pulldown-cmark/pulldown-cmark](https://github.com/pulldown-cmark/pulldown-cmark)

The standard CommonMark parser for Rust. Key design:
- **Pull parsing**: Returns iterator of Events, not AST
- **Extensions**: Footnotes, GFM tables, task lists, strikethrough via extensions
- **Source maps**: `into_offset_iter()` maps events to source positions
- **Copy-on-write strings**: Minimal allocation

```rust
let parser = pulldown_cmark::Parser::new_ext(
    markdown_input,
    Options::all()
        | Options::ENABLE_TABLES
        | Options::ENABLE_FOOTNOTES
);
```

---

## 4. Python Ecosystem

### mistune (lepture/mistune)
**Source:** [github.com/lepture/mistune](https://github.com/lepture/mistune) (3K stars)

Fast, extensible Python Markdown parser with pluggable renderer architecture:

```python
import mistune
from pygments import highlight
from pygments.lexers import get_lexer_by_name
from pygments.formatters import html

class HighlightRenderer(mistune.HTMLRenderer):
    def block_code(self, code, lang=None):
        if lang:
            lexer = get_lexer_by_name(lang, stripall=True)
            formatter = html.HtmlFormatter()
            return highlight(code, lexer, formatter)
        return f'<pre><code>{mistune.escape(code)}</code></pre>'

markdown = mistune.create_markdown(renderer=HighlightRenderer())
```

**Key patterns:**
1. **Renderer interface**: Separate renderers for HTML, Markdown, RST output
2. **AST mode**: `renderer=None` returns token list for analysis
3. **Plugin system**: Striketrhough, table, footnote, task lists as plugins
4. **No built-in ANSI renderer**: Community `mistune-terminal` package exists

### marked-terminal (Node.js)
**Source:** [npmjs.com/package/marked-terminal](https://www.npmjs.com/package/marked-terminal)

Uses chalk for styling + cli-highlight for syntax:

```javascript
import { marked } from 'marked';
import { markedTerminal } from 'marked-terminal';

marked.use(markedTerminal({
  code: chalk.yellow,
  heading: chalk.green.bold,
  firstHeading: chalk.magenta.underline.bold,
  showSectionPrefix: true,
  reflowText: true,
  width: 80
}));

// Syntax highlighting via cli-highlight
marked.use(markedTerminal({}, { theme: 'monokai' }));
```

**Default styles (uses chalk):**
```javascript
{
  code: chalk.yellow,
  blockquote: chalk.gray.italic,
  heading: chalk.green.bold,
  firstHeading: chalk.magenta.underline.bold,
  link: chalk.blue,
  strong: chalk.bold,
  em: chalk.italic,
  codespan: chalk.yellow,
  del: chalk.dim.gray.strikethrough,
}
```

---

## 5. JavaScript Ecosystem

### marked + marked-highlight + marked-terminal
**Source:** [github.com/markedjs/marked](https://github.com/markedjs/marked)

Marked is a fast, low-level markdown compiler. Terminal rendering via extensions:

```javascript
import { Marked } from "marked";
import { markedHighlight } from "marked-highlight";
import hljs from 'highlight.js';

const marked = new Marked(
  markedHighlight({
    langPrefix: 'hljs language-',
    highlight(code, lang) {
      const language = hljs.getLanguage(lang) ? lang : 'plaintext';
      return hljs.highlight(code, { language }).value;
    }
  })
);
```

### Bun's markdown-to-ANSI (2026)
**Source:** [oven-sh/bun#28833](https://github.com/oven-sh/bun/pull/28833)

Built-in Zig implementation with sophisticated ANSI rendering:
- OSC 8 hyperlinks with fallback
- Light/dark theme detection via `COLORFGBG`, `NO_COLOR`, `FORCE_COLOR`
- Kitty Graphics Protocol for images
- Escape-aware word-breaking for wrapped table cells

```zig
// From src/md/ansi_renderer.zig
pub const AnsiRenderer = struct {
    theme: Theme,
    hyperlinks: bool,
    light: bool,
    columns: usize,
};
```

### markdown-it-terminal
**Source:** [github.com/trabus/markdown-it-terminal](https://github.com/trabus/markdown-it-terminal)

Uses `ansi-styles` for styling + `cardinal` for highlighting:

```javascript
var options = {
  styleOptions: {
    code: chalk.yellow,
    heading: chalk.green.bold
  },
  highlight: require('cardinal').highlight,
  unescape: true,
  indent: '  '
};
```

---

## 6. Comparative Analysis

### ANSI Rendering Approaches

| Library | Approach | Config Format |
|---------|----------|---------------|
| glamour (Go) | goldmark → custom walker | JSON with block_prefix/suffix |
| mdansi (Rust) | comrak → custom walker | TOML |
| marked-terminal (JS) | chalk functions | JS object |
| mistune (Python) | pluggable renderer | Python class |
| **candy-shine (PHP)** | league/commonmark → custom walker | PHP constructor + JSON |

### Syntax Highlighting

| Library | Engine | Languages |
|---------|--------|-----------|
| glamour | Chroma (based on syntect) | 100+ |
| mdansi | syntect | 200+ |
| marked-highlight | highlight.js | 190+ |
| marked-terminal | cli-highlight | 100+ |
| mistune + Pygments | Pygments | 500+ |
| **candy-shine (PHP)** | Regex (custom) | **7** |

### Theme Configuration

| Library | Features |
|---------|----------|
| glamour | `block_prefix/suffix`, `indent_token`, `margin`, `chroma` block for syntax |
| mdansi | TOML with `heading`, `strong`, `emph`, `inlineCode`, `blockCode`, `link`, etc. |
| **candy-shine (PHP)** | Style objects, limited nesting, no `chroma`-style token coloring |

### Paging Behavior

| Library | Approach |
|---------|----------|
| glow | Bubble Tea viewport + fsnotify file watching |
| glow (CLI) | External `less -r` |
| **sugar-glow** | candy-core Viewport (no file watching) |
| marked-terminal | External pager via shell |

### Link Handling

| Library | OSC 8 | Fallback |
|---------|-------|----------|
| glamour | ✅ | `text (url)` suffix |
| mdansi | ✅ | `text (url)` suffix |
| **candy-shine** | ✅ | `text (url)` suffix |
| Bun (Zig) | ✅ | Only for non-data URIs (security) |

---

## 7. Prioritized Recommendations

### P0: Critical Improvements

#### 7.1 Syntax Highlighting - Use Pygments or Scrutiny

**Problem:** Current regex-based highlighter only handles 7 languages.

**Options:**
1. **Pygments (recommended for PHP)**: `pygments/pygments` package, 500+ languages
2. **Scrutiny**: `scrutinyphp/scrutiny` - PHP-native port of Prism.js
3. **shivammathur/php-highlight**: Highlight.js wrapper for PHP

**Implementation approach:**
```php
// In SyntaxHighlighter.php - replace regex with Pygments
use Pygments\Lexer\PhpLexer;
use Pygments\Formatter\TerminalFormatter;

public static function highlight(string $code, string $language, Theme $theme): string
{
    $lexer = $this->getLexer($language);  // Maps lang aliases
    $formatter = new TerminalFormatter();
    $highlighted = \Pygments\Highlight($code, $lexer, $formatter);
    return $highlighted;
}
```

**Effort:** Medium (2-3 days)
**Impact:** High - enables full README rendering

#### 7.2 Streaming Rendering for Pager

**Problem:** Large documents load fully before display.

**Reference:** mdansi's streaming mode buffers multi-line constructs:

```rust
// mdansi/stream.rs concept
let renderer = Renderer::streaming();
for chunk in markdown_chunks {
    print!("{}", renderer.render(&chunk));
}
```

**Implementation for PHP:**
- Buffer fenced code blocks and tables until complete
- Emit paragraph content immediately
- Use ReactPHP async streams for non-blocking render

**Effort:** High (5-7 days)
**Impact:** Medium - improves UX for large files

### P1: Important Improvements

#### 7.3 Enhanced Theme System

**Add glamour-style features to Theme.php:**

1. **`block_prefix` / `block_suffix`** for document wrappers
2. **`indent_token`** for blockquote continuation markers
3. **`margin`** for document/paragraph spacing
4. **`chroma` block** for syntax highlighting token colors

**Example enhanced JSON theme:**
```json
{
  "document": {
    "block_prefix": "\n",
    "block_suffix": "\n",
    "margin": 2
  },
  "block_quote": {
    "indent": 1,
    "indent_token": "│ "
  },
  "code_block": {
    "chroma": {
      "keyword": { "color": "#FF79C6", "bold": true },
      "string": { "color": "#F1FA8C" },
      "comment": { "color": "#6272A4", "italic": true }
    }
  }
}
```

**Effort:** Medium (3-4 days)
**Impact:** High - enables custom styling parity with glamour

#### 7.4 File Watching in Pager Mode

**Reference:** glow's `fsnotify.Watcher` in pager.go

**PHP alternatives:**
- `symfony/process` + `inotify_wait` (Linux)
- `ReactPHP/async-rotation` for async file monitoring

**Implementation:**
```php
// In GlowModel.php - add file watching
private ?\React\Filesystem\Watcher $watcher = null;

public function watchFile(string $path): void
{
    $filesystem = \React\Filesystem\Filesystem::create();
    $this->watcher = $filesystem->watch($path);
    $this->watcher->on('change', fn() => $this->onFileChange());
}
```

**Effort:** Medium (2-3 days)
**Impact:** Medium - improves development workflow

#### 7.5 CJK/Emoji Width Handling

**Reference:** mdansi's "Unicode-aware, CJK/emoji-correct" wrapping

**PHP libraries:**
- `symfony/polyfill-mbstring` (already available)
- `composer/package-versions-guard` for `symfony/text-width`

**Current issue:**
```php
// Width::wrapAnsi() may miscalculate with CJK
"你好世界"  // Each CJK char should be 2 cells, not 1
```

**Fix:**
```php
use Symfony\Component\String\UnicodeString;

public static function wrapAnsi(string $text, int $width): string
{
    // Use UnicodeString for proper width calculation
    $u = UnicodeString::create($text);
    // ... wrapping logic with mb_strwidth
}
```

**Effort:** Low (1 day)
**Impact:** Medium - critical for Asian markets

### P2: Nice to Have

#### 7.6 Additional Stock Themes

Add themes from other ecosystems:
- **Solarized**: From glamour's style gallery
- **Monokai**: Popular code editor theme
- **GitHub**: Light theme with blue links

**Effort:** Low (1 day)
**Impact:** Low - cosmetic improvement

#### 7.7 Code Block Language Labels

**Reference:** mdansi's box-drawn code blocks with language labels:

```
┌─javascript─────────────────┐
│ const x = 1;              │
└────────────────────────────┘
```

**Effort:** Low (1 day)
**Impact:** Low - visual polish

---

## 8. Implementation Priority Matrix

| Improvement | Effort | Impact | Priority |
|------------|--------|--------|----------|
| Pygments syntax highlighting | 2-3 days | High | P0 |
| Streaming rendering | 5-7 days | Medium | P1 |
| Enhanced theme system (chroma, block_prefix) | 3-4 days | High | P1 |
| File watching in pager | 2-3 days | Medium | P1 |
| CJK/emoji width | 1 day | Medium | P1 |
| Additional themes | 1 day | Low | P2 |
| Code block labels | 1 day | Low | P2 |

---

## 9. Reference Implementations

### Key Files to Reference

| File | Relevance |
|------|-----------|
| [charmbracelet/glamour/styles/dark.json](https://github.com/charmbracelet/glamour/blob/master/styles/dark.json) | Theme JSON structure with chroma section |
| [charmbracelet/glamour/ui/pager.go](https://github.com/charmbracelet/glow/blob/master/ui/pager.go) | File watching + Bubble Tea viewport |
| [crates.io/crates/mdansi](https://crates.io/crates/mdansi) | Rust streaming renderer architecture |
| [crates.io/crates/markdown-to-ansi](https://crates.io/crates/markdown-to-ansi) | Simple pulldown-cmark → ANSI |
| [marked-terminal/index.d.ts](https://github.com/mikaelbr/marked-terminal) | JS chalk-based styling |

### Library Versions (as of 2026-05)

| Library | Version |
|---------|---------|
| league/commonmark | 2.x |
| pygmentize / pygments | Latest |
| symfony/console | 6.4+ / 7.0+ |
| react/react | 1.x |

---

## 10. Conclusion

Sugar-glow's core architecture (league/commonmark → custom walker) is sound and matches the upstream glow's goldmark → glamour → ANSI approach. The main gap is **syntax highlighting quality** — the regex-based approach is insufficient for a general-purpose markdown viewer.

**Recommended immediate action:** Integrate Pygments for syntax highlighting while preserving the existing theme system. This provides maximum impact with moderate effort.

**Long-term direction:** Consider:
1. Adding streaming rendering for very large documents
2. Enhancing the theme system with glamour-style `block_prefix/suffix` and `chroma` token colors
3. Adding file watching for the pager use case

---

*Research compiled from upstream documentation, GitHub repositories, and crate/package registries.*
