# Overview

**candy-shine** is a Markdown-to-ANSI renderer that ports charmbracelet/glamour to PHP, built on `league/commonmark` and the SugarCraft styling ecosystem (`candy-sprinkles`, `candy-core`). It occupies a critical position in the ecosystem as the primary markdown rendering engine for CLI output (used by `sugar-glow`).

**Biggest opportunity areas:**
1. Chroma-equivalent syntax highlighting (regex tokeniser is a critical limitation)
2. Cascading style inheritance (BlockStack pattern from glamour)
3. Definition list implementation (declared in Theme but unimplemented)
4. HTML sanitisation (security gap for untrusted input)
5. Table footer links (glamour's distinctive feature)

**Biggest missing capabilities:**
1. No block-level indent/margin Style abstraction (vs glamour's `StyleBlock`)
2. No MarginWriter/PaddingWriter composable whitespace abstraction
3. No FNV link ID hashing for OSC 8 consolidation
4. No proper language grammar-based lexing

---

# Internal Capability Summary

## Architecture

**Parser:** `league/commonmark` v2.4 (not goldmark as in upstream glamour)
- `CommonMarkCoreExtension` — headings, paragraphs, code blocks, lists, blockquotes, links, images, emphasis, strong, thematic breaks, HTML blocks/inline
- `TableExtension` — GFM tables
- `TaskListExtension` — GFM task list items (`[x]` / `[ ]`)
- `StrikethroughExtension` — `~~strikethrough~~`
- `AutolinkExtension` — auto-detect URLs

**Rendering:** Single-pass visitor via `renderChildren()` recursion. Block-level nodes return text ending in `\n\n`; inline nodes return inline fragments.

**Key limitation vs glamour:** No explicit `BlockStack`. Glamour maintains `BlockStack` to track nested indent/margin for dynamic width computation. Candy-shine handles nesting directly in `renderList()` and `renderBlockQuote()` but cannot propagate accumulated indent to deeper nested structures.

## Features

| Feature | Status |
|---|---|
| GFM parsing (headings H1-H6, paragraphs, emphasis, strong) | ✅ Complete |
| Fenced code blocks | ✅ |
| Indented code blocks | ✅ |
| Bullet/ordered/nested lists | ✅ |
| Blockquotes (▎-prefixed) | ✅ |
| GFM tables (via SprinklesTable) | ✅ |
| Task lists (☑/☐ glyphs) | ✅ |
| OSC 8 hyperlinks | ✅ |
| Link suffix `(url)` | ✅ |
| Images with alt+url | ✅ |
| Strikethrough | ✅ |
| HTML blocks/inline pass-through | ✅ |
| Autolinks | ✅ |
| Emoji shortcodes (20 entries) | ✅ |
| Preserved newlines | ✅ |
| 9 stock themes | ✅ |
| JSON theme loading | ✅ |
| GLAMOUR_STYLE env var | ✅ |
| Base URL resolution | ✅ |
| Table cell wrapping | ✅ |
| Inline table links toggle | ✅ |
| Heading case transform | ✅ |
| Custom heading prefix | ✅ |
| Document margin/indent | ✅ |
| List level indent config | ✅ |
| Custom task glyphs | ✅ |
| Custom HR glyph/length | ✅ |
| Definition lists | ⚠️ Declared in Theme, not implemented |
| Table footer links | ❌ Not implemented |
| Block-level indent/margin style | ❌ No StyleBlock distinction |
| Cascading style merge | ❌ Null-slot fallthrough only |
| HTML sanitisation | ❌ Raw HTML passes through |
| FNV link ID hashing | ❌ Null ID for all links |
| Chroma/high-quality syntax highlighting | ❌ Regex only |
| Per-column table width constraints | ❌ |
| MarginWriter/PaddingWriter abstraction | ❌ Simple string prefix/suffix |

## Source Files

| File | Role |
|---|---|
| `src/Renderer.php` (684 lines) | Main renderer — league/commonmark AST visitor dispatching on Node type |
| `src/Theme.php` (657 lines) | Immutable stylesheet — 45+ slots + 9 stock themes |
| `src/SyntaxHighlighter.php` (175 lines) | Regex-based tokeniser for 7 languages |
| `src/Lang.php` (22 lines) | i18n facade |
| `lang/en.php` + 14 locales | Translations |

## Dependencies

- `league/commonmark: ^2.4`
- `sugarcraft/candy-core` (Width, Ansi utilities)
- `sugarcraft/candy-sprinkles` (Style, Border, Table)

## Consumer

- `sugar-glow/src/RenderCommand.php` — CLI consuming candy-shine
- `sugar-glow/src/GlowModel.php` — Bubble Tea model

## Test Suite

~1,572 assertions across:
- `tests/RendererTest.php` (458 lines)
- `tests/SyntaxHighlighterTest.php` (770 lines)
- `tests/ThemeTest.php` (161 lines)
- `tests/RendererRound2Test.php` (125 lines)
- `tests/ShortAliasesTest.php` (58 lines)
- `tests/ThemeExtensionsTest.php` (161 lines)

## Strengths

1. **Clean separation** — markdown parsing (league/commonmark), styling (Sprinkles Style), layout (Width utilities)
2. **Comprehensive theming** — 9 stock themes (more than glamour's 6)
3. **Full GFM support** — tables, task lists, strikethrough, autolinks
4. **OSC 8 hyperlinks** — with graceful fallback
5. **Good test coverage** — deterministic snapshot-style assertions
6. **Immutable/fluent design** — all `with*()` builders, `Theme` as value object

## Weaknesses

1. **No block stack** — ad-hoc indent handling breaks with deep nesting
2. **No cascading style merge** — null slots become no-op, not inherited
3. **Fragile regex syntax highlighting** — can't handle grammar properly (keyword inside string)
4. **No definition list support** — Theme slots exist but Renderer has no case
5. **No HTML sanitisation** — security gap for untrusted input
6. **No table footer links** — glamour's distinctive feature missing
7. **Limited language support** — only PHP, JS, TS, Python, Go, Bash, SQL, JSON

---

# Relevant External Repositories

| Repo | Relevance | Major applicable concepts | Priority |
|---|---|---|---|
| `charmbracelet/glamour` | **Primary upstream** | BlockStack, cascading StyleBlock, MarginWriter, Chroma, table footer links, definition lists, HTML sanitisation | Critical |
| `charmbracelet/lipgloss` | **Theming system** | CSS-like shorthand properties, Style inheritance via `Inherit()`, color adaptive/darken/lighten, border gradient blends, Layer/Compositor system | High |
| `charmbracelet/huh` | **Form theming** | Theme interface with `isDark` selector, Style structs per field type | Medium |
| `charmbracelet/soft-serve` | **Chroma usage** | Uses Chroma lexers to detect markdown and highlight code in TUI | High |
| `charmbracelet/glow` | **Consumer example** | glamourRender() for markdown, frontmatter stripping, line numbers, auto theme detection | Medium |
| `charmbracelet/mods` | **Consumer example** | Streaming LLM output rendered via glamour, markdown formatting | Medium |
| `charmbracelet/pop` | **Markdown→HTML** | goldmark markdown→HTML pipeline (reference for future HTML output) | Low |
| `textualize/textual` | **TUI framework** | Markdown widget, CSS-based styling, Elm architecture | Medium |
| `ratatui/ratatui` | **TUI framework** | Stylize trait, buffer diffing, constraint layout, Widget trait patterns | Low |

---

# Feature Gap Analysis

## Critical

### 1. Proper Syntax Highlighting (Chroma-equivalent)
**Description:** The current regex-based tokeniser cannot handle language grammar properly. Keywords inside string literals get highlighted as keywords. Python keywords are case-sensitive (uppercase `True`/`False`/`None` won't match). SQL keywords are lowercase — `SELECT` won't be highlighted.

**Why it matters:** Code block rendering is a primary use case. Broken highlighting produces visually incorrect output that undermines the library's credibility.

**Source:** `charmbracelet/glamour.md` — Chroma integration with 200+ lexers

**Implementation ideas:**
- Port Chroma to PHP (significant effort)
- Use `和精神`/`PHP-Tokens`/`PHP-Parser` based highlighting
- Integrate Tree-sitter via FFI
- Add `--no-inline` option to disable highlighting for fragile languages

**Expected impact:** High — fixes a core use case

### 2. Cascading Style Inheritance
**Description:** Glamour's `cascadeStyle()` recursively merges parent and child `StyleBlock` structs. When a child element doesn't specify a property, it inherits from the parent in the block stack. Candy-shine uses null-slot fallthrough: `($this->theme->property ?? Style::new())`.

**Why it matters:** Without inheritance, nested elements (blockquote inside blockquote, list item inside list) cannot inherit from parent block styles. This limits thematic expressiveness.

**Source:** `docs/repo_map/charmbracelet_glamour.md` — `cascadeStyle()` recursive merge pattern

**Implementation ideas:**
- Implement `cascadeStyle()` equivalent in `Theme.php`
- Add a `StyleBlock` type with `Indent`, `Margin`, `IndentToken` fields (like glamour)
- Modify `renderNode()` to pass parent style context

**Expected impact:** High — enables proper nested block styling

### 3. Definition Lists Implementation
**Description:** Theme has `$definitionTerm`, `$definitionDescription`, `$definitionList` slots, but `renderNode()` has no case for these node types. They fall through to `default => renderChildren($node)`.

**Why it matters:** GFM definition lists (`dt`/`dd`) are a documented feature that doesn't work. Users who rely on this feature will be disappointed.

**Source:** `docs/repo_map/charmbracelet_glamour.md` — `DefinitionList`, `DefinitionTerm`, `DefinitionDescription` elements

**Implementation ideas:**
- Check if league/commonmark has a definition list extension
- If not, add a custom extension
- Add rendering cases to `Renderer::renderNode()`

**Expected impact:** Medium — completes GFM feature parity

## High Value

### 4. Table Footer Links
**Description:** Glamour collects links referenced in table cells and renders them as a numeric footer. Candy-shine does not implement this.

**Why it matters:** Tables with links don't show where those links point without inline `(url)` suffixes. Footer links are more readable for complex tables.

**Source:** `docs/repo_map/charmbracelet_glamour.md` — `tableLink` collection and `printTableLinks()`

**Implementation ideas:**
- Walk table AST before rendering to collect links
- Emit numbered footer links after table body
- Use Theme slots for footer styling

**Expected impact:** Medium — improves table readability

### 5. Block Stack for Dynamic Width
**Description:** Glamour's `BlockStack` computes available width dynamically as blocks nest. Candy-shine handles nesting directly in `renderList()` and `renderBlockQuote()` but lacks a general abstraction. Deep nesting (blockquote inside list item inside blockquote) is not architecturally supported.

**Source:** `docs/repo_map/charmbracelet_glamour.md` — BlockStack with `Indent()` / `Margin()` / `Width()` computed values

**Implementation ideas:**
- Create a `BlockStack` class similar to glamour's
- Push on block entry, pop on exit
- Compute available width as `WordWrap - Indent - Margin*2`
- Use in `renderParagraph()`, `renderBlockQuote()`, `renderList()`

**Expected impact:** High — fixes nested block rendering correctness

### 6. HTML Sanitisation
**Description:** Glamour uses bluemonday to sanitise HTML blocks and inline HTML. Candy-shine passes HTML through verbatim with only the `htmlBlock`/`htmlSpan` style applied.

**Why it matters:** For untrusted input (e.g., rendering markdown from user comments), raw HTML is a security gap.

**Source:** `docs/repo_map/charmbracelet_glamour.md` — `SanitizeHTML()` via bluemonday

**Implementation ideas:**
- Integrate ` HTML Purifier` or `enshkn/html-sanitizer`
- Add `withSanitizeHTML(bool)` option
- Apply sanitisation before style application

**Expected impact:** Medium — security requirement for untrusted input

## Medium

### 7. FNV Link ID Hashing
**Description:** Multiple links to the same URL cannot share an OSC 8 link ID because no hashing is done. Glamour uses FNV-32a to hash URLs for link IDs. Links always get `id=null`.

**Source:** `docs/repo_map/charmbracelet_glamour.md` — FNV-32a hash of URL as unique identifier

**Implementation ideas:**
- Implement FNV-32a hash in `SugarCraft\Core\Util\Ansi`
- Use hash as OSC 8 `id` parameter
- Note: minor cosmetic issue, most terminals work without ID

**Expected impact:** Low — minor deviation from glamour

### 8. MarginWriter/PaddingWriter Abstraction
**Description:** Glamour's `MarginWriter`/`PaddingWriter`/`IndentWriter` are composable io.Writer wrappers. Candy-shine achieves similar effects with string concatenation but loses composability.

**Source:** `docs/repo_map/charmbracelet_glamour.md` — custom `io.Writer` implementations

**Implementation ideas:**
- Create `MarginWriter` class wrapping a callable
- Compose indentation logic in a reusable way
- Could be used for nested block rendering

**Expected impact:** Medium — code cleanliness, not user-facing

### 9. Per-column Table Width Constraints
**Description:** Glamour's table rendering supports per-column width specifications. SprinklesTable handles table layout but candy-shine doesn't expose column width configuration.

**Source:** `charmbracelet_lipgloss.md` — `table` sub-package with `Width(constraint)`

**Implementation ideas:**
- Add `withColumnWidths(array $widths)` to Renderer
- Pass widths to SprinklesTable builder
- Apply proportional sizing if sum < available width

**Expected impact:** Medium — enables precise table formatting

## Low Priority

### 10. Additional Language Support
**Description:** Regex highlighter only supports PHP, JS, TS, Python, Go, Bash, SQL, JSON. Missing: HTML/XML, CSS, Regex, CRON, INI, YAML, Rust, Ruby, etc.

**Implementation ideas:**
- Add keyword lists for more languages
- Eventually replace with proper lexer

**Expected impact:** Low — existing 7 cover most common cases

---

# Algorithm / Performance Opportunities

## Current Approach vs External

### 1. Syntax Highlighting
**Current:** Regex tokeniser with hardcoded keyword lists, processed via `preg_match_all` with `PREG_OFFSET_CAPTURE | PREG_SET_ORDER`.

**External (Glamour):** Chroma lexer library with 200+ language grammars, proper AST-based tokenisation.

**Why external is better:** Regex cannot handle language grammar. For example, a keyword inside a string literal would incorrectly be styled as a keyword. Chroma uses actual lexer implementations.

**Tradeoffs:** Chroma is a Go library. PHP would need either:
- A PHP-native lexer (significant porting effort)
- FFI bindings to a native library
- A simpler but correct regex approach (accept some false positives)

**Applicability:** Critical for production use. Regex approach is a MVP that works for simple cases.

### 2. Style Inheritance
**Current:** Null-slot fallthrough — `($this->theme->property ?? Style::new())`. Each element queries its theme slot directly.

**External (Glamour):** `cascadeStyle()` recursively merges parent and child `StyleBlock` structs, preferring child values when non-nil.

**Why external is better:** Inheritance enables hierarchical theming. A `blockquote` inside a `blockquote` can inherit from the parent blockquote style rather than falling back to the global default.

**Tradeoffs:** The current approach is simpler and more predictable. Inheritance adds complexity and potential for subtle bugs. However, it enables more sophisticated theming.

**Applicability:** Medium — important for advanced theming, not blocking basic use.

### 3. Block Nesting Width Calculation
**Current:** `renderBlockQuote()` hard-codes a 2-cell subtraction for the `▎ ` prefix. `renderList()` calculates indent from bullet width.

**External (Glamour):** BlockStack maintains accumulated `Indent` and `Margin` values, computing available width as `WordWrap - Indent - Margin*2`.

**Why external is better:** BlockStack handles arbitrary nesting depths correctly. Deep nesting (blockquote > list > blockquote > paragraph) is handled by tracking accumulated context.

**Tradeoffs:** Glamour's approach requires more state management. The current approach is simpler but breaks at depth.

**Applicability:** High — affects correctness of nested rendering.

---

# Architecture Improvements

## 1. BlockStack Pattern

Add a `BlockStack` class to track nested blocks:

```php
final class BlockContext
{
    public function __construct(
        public readonly int $indent = 0,
        public readonly int $margin = 0,
        public readonly ?string $styleKey = null,
    ) {}
}

final class BlockStack
{
    /** @var BlockContext[] */
    private array $stack = [];

    public function push(BlockContext $context): void;
    public function pop(): void;
    public function indent(): int;
    public function margin(): int;
    public function availableWidth(int $wordWrap): int;
}
```

Modify `Renderer` to accept a `BlockStack` and pass it through `renderChildren()`.

**Reference:** `docs/repo_map/charmbracelet_glamour.md` — `BlockStack` with `Indent()`, `Margin()`, `Width()` (lines 44-50)

## 2. Cascading Style Merge

Add a `cascadeStyle()` function to `Theme.php`:

```php
public static function cascadeStyle(?Style $parent, ?Style $child): ?Style
{
    if ($child !== null) {
        return $child;
    }
    return $parent;
}
```

Modify render methods to accept parent style and fall through to it when child style is null.

**Reference:** `docs/repo_map/charmbracelet_glamour.md` — `cascadeStyle()` recursive merge (line 57)

## 3. StyleBlock Type

Add a `StyleBlock` class with block-level properties:

```php
final class StyleBlock
{
    public function __construct(
        public readonly ?Style $style = null,
        public readonly int $indent = 0,
        public readonly int $margin = 0,
        public readonly ?string $indentToken = null,
    ) {}
}
```

**Reference:** `docs/repo_map/charmbracelet_glamour.md` — `StyleBlock` struct (line 54)

---

# API / Developer Experience Improvements

## 1. Static Convenience Methods

Add missing factory methods to `Renderer`:

```php
public static function dark(): self;
public static function light(): self;
public static function dracula(): self;
public static function tokyoNight(): self;
public static function pink(): self;
```

Currently only `ansi()`, `plain()`, `ascii()` exist as static factories. Users must use `new Renderer(Theme::dark())`.

**Reference:** `charmbracelet_glamour.md` — `RenderWithEnvironmentConfig`, `NewTermRenderer` static convenience

## 2. One-shot Render with Options

Add static method for one-shot render with options:

```php
public static function renderMarkdown(
    string $markdown,
    ?Theme $theme = null,
    ?int $wordWrap = null,
    bool $hyperlinks = true,
    ?string $baseUrl = null,
): string;
```

Currently `renderMarkdown()` only accepts theme. Other options require building a Renderer instance.

## 3. Theme Inheritance

Add `Theme::merge()` or `Theme::inherit()` for theme inheritance:

```php
public function inherit(Theme $parent): Theme;
```

**Reference:** `charmbracelet_lipgloss.md` — `Inherit(Style)` method (line 85)

---

# Documentation / Cookbook Opportunities

## 1. Custom Theme Examples

Create a cookbook of custom themes:
- Minimalist (monochrome)
- Nord-inspired
- Dracula-inspired  
- Tokyo Night-inspired
- High-contrast accessibility theme

## 2. Advanced Usage Patterns

Document:
- Custom renderers extending `Renderer`
- Pre-processing markdown (frontmatter stripping, code block language normalization)
- Post-processing output (adding line numbers, prefixing every line)

## 3. Glamour Compatibility Guide

Document differences from glamour to help users migrating from Go:
- Parser differences (league/commonmark vs goldmark)
- Style inheritance differences
- Missing features

---

# UX / TUI Improvements

## 1. Better Hyperlink ID Sharing

Implement FNV-32a hashing for OSC 8 link IDs so multiple links to the same URL can be consolidated by terminals that cache by ID.

**Reference:** `docs/repo_map/charmbracelet_glamour.md` — FNV-32a hash for URL as unique identifier (line 100)

## 2. Terminal Background Detection

Add `Theme::withAutoBackground()` or `Theme::detectBackground()` that queries the terminal for its background color (via OSC 11) and picks an appropriate theme variant.

**Reference:** `charmbracelet_lipgloss.md` — `HasDarkBackground(os.Stdin, os.Stdout)` queries terminal via OSC 11 (lines 44, 223)

## 3. Color Downsampling

Add automatic color degradation when output is not a TTY (strip styles when piped to file/pager).

**Reference:** `charmbracelet_lipgloss.md` — automatic downsampling based on terminal profile (line 41)

---

# Testing / Reliability Improvements

## 1. Round-trip Tests

Add round-trip tests: render markdown → parse ANSI → extract styles → verify round-trip preserves styling intent.

## 2. Fuzz Testing

Add fuzz tests for:
- Malformed markdown input
- Extremely nested structures
- Very long lines (>1000 chars)
- Edge case Unicode (emoji, CJK, combining characters)

## 3. Visual Regression

Add screenshot-style tests comparing rendered output against stored "golden" ANSI snapshots for known inputs.

---

# Ecosystem / Integration Opportunities

## 1. sugar-glow Enhancement

`sugar-glow` (markdown reader TUI) could implement:
- Frontmatter stripping (`RemoveFrontmatter()` from glow)
- Live file watching (fsnotify)
- GitHub/GitLab README fetching
- Fuzzy file filtering

**Reference:** `docs/repo_map/charmbracelet_glow.md` — `RemoveFrontmatter()`, file watching, GitHub/GitLab fetching

## 2. Integration with candy-palette

Use `candy-palette`'s color manipulation (darken, lighten, complementary, blend) in theme generation.

**Reference:** `charmbracelet_lipgloss.md` — `Darken()`, `Lighten()`, `Complementary()`, `Blend1D()`, `Blend2D()` using CIELAB color space

## 3. Integration with candy-sprinkles Border System

Enhanced border styles (gradient borders, per-side colors) from lipgloss.

**Reference:** `charmbracelet_lipgloss.md` — `BorderForegroundBlend(...)`, per-side border colors

---

# Notable PRs / Issues / Discussions

## charmbracelet/glamour

### BlockStack Architecture (lines 44-50 of glamour.md)
The BlockStack pattern is glamour's architectural heart. It computes available width dynamically as blocks nest. **Lesson for candy-shine:** A simple BlockStack implementation would fix nested block rendering without major complexity.

### Cascading Style (line 57 of glamour.md)
`cascadeStyle()` recursively merges parent and child styles. **Lesson:** Implementing this in Theme.php would enable proper style inheritance.

### Table Footer Links (lines 88-93 of glamour.md)
The `collectLinksAndImages()` and `printTableLinks()` pattern shows how to implement glamour's distinctive table footer feature. **Lesson:** Candy-shine needs a pre-pass to collect links before rendering the table body.

## charmbracelet/lipgloss

### Inherit Style (line 85 of lipgloss.md)
The `Inherit(Style)` method overlays unset properties from another style. **Lesson:** Could be added to `Style` or `Theme` for composition.

### Color Adaptive (lines 41, 149, 226 of lipgloss.md)
`LightDark(bool)` and `AdaptiveColor` for terminal background-aware color selection. **Lesson:** Add to `candy-palette` and use in `Theme` construction.

## charmbracelet/soft-serve

### Chroma Lexer Usage (line 33 of soft-serve.md)
Soft-serve uses Chroma lexers to detect markdown and highlight code blocks in README rendering. **Lesson:** This is the reference implementation for Chroma integration. Soft-serve's `IsFileMarkdown()` (line 166) uses Chroma to detect markdown content type.

## textualize/textual

### Markdown Widget (line 72 of textual.md)
Textual's `Markdown` widget renders markdown in TUI. Not directly comparable (Python, different architecture), but shows what a full markdown rendering widget API looks like. **Lesson:** Could inspire a sugar-bits component wrapping candy-shine.

---

# Recommended Roadmap

## Immediate Wins

1. **Definition list implementation** — Theme slots exist, add renderer case
2. **Static theme factories** — `Renderer::dark()`, `Renderer::light()`, etc.
3. **FNV link ID hashing** — Improve OSC 8 hyperlink quality
4. **Table footer links** — Collect links during table pre-pass, render footer

## Medium-term Improvements

5. **Cascading style inheritance** — Implement `cascadeStyle()` equivalent
6. **BlockStack for nested rendering** — Track accumulated indent/margin
7. **HTML sanitisation** — Add `HTML Purifier` integration
8. **Enhanced theme factories** — `Theme::fromJsonFile()`, `Theme::merge()`

## Major Architectural Upgrades

9. **Proper syntax highlighting** — Integrate PHP lexer or Chroma FFI
10. **StyleBlock abstraction** — Add block-level properties (Indent, Margin, IndentToken)
11. **MarginWriter abstraction** — Composable whitespace handling
12. **Table column width constraints** — Per-column width configuration

## Experimental Ideas

13. **Terminal background auto-detection** — OSC 11 query + theme switching
14. **Color downsampling for non-TTY** — Automatic style stripping
15. **Markdown → HTML output mode** — For email rendering (like pop)
16. **Interactive preview mode** — Live markdown editing with candy-shine rendering

---

# Priority Matrix

| Opportunity | Impact | Complexity | Risk | Recommended Priority |
|---|---|---|---|---|
| Definition list implementation | Medium | Low | Low | **Immediate** |
| Static theme factories | Low | Low | Low | **Immediate** |
| FNV link ID hashing | Low | Low | Low | **Immediate** |
| Table footer links | Medium | Medium | Low | **Immediate** |
| Cascading style inheritance | High | Medium | Medium | **Medium-term** |
| BlockStack for nested rendering | High | Medium | Medium | **Medium-term** |
| HTML sanitisation | Medium | Low | Low | **Medium-term** |
| Enhanced theme factories | Low | Low | Low | **Medium-term** |
| Proper syntax highlighting | High | High | High | **Major (long-term)** |
| StyleBlock abstraction | High | High | Medium | **Major (long-term)** |
| MarginWriter abstraction | Medium | Medium | Low | **Major (long-term)** |
| Table column width constraints | Medium | Medium | Low | **Major (long-term)** |

---

# Final Strategic Assessment

**candy-shine** is a well-structured, feature-rich PHP port of glamour covering ~80% of upstream functionality with significantly simpler architecture. Its primary strengths are clean separation of concerns (league/commonmark for parsing, Sprinkles for styling, Width for layout), comprehensive theming (9 stock themes vs glamour's 6), full GFM support, and OSC 8 hyperlinks.

The most significant architectural gap is the **absence of an explicit block stack** for managing nested block indent/margin dynamically. In glamour, the BlockStack computes available width as `WordWrap - Indent - Margin*2` on each block entry/exit. In candy-shine, deep nesting (blockquote inside list item inside blockquote) would require cascading context through recursive `renderChildren()` calls that don't exist architecturally.

The second most significant gap is **regex-based syntax highlighting**. The custom tokeniser works for simple cases but cannot handle language grammar properly. A keyword inside a string literal gets styled as a keyword. This is a fundamental limitation of regex-based approaches. Addressing this requires either a proper lexer (Chroma FFI, Tree-sitter FFI, or PHP-Parser based highlighting) or accepting the limitation for production use.

The **cascading style inheritance** gap is architectural but lower urgency. Without it, nested elements cannot inherit from parent block styles. The current null-slot fallthrough is predictable but limits theming expressiveness.

For a 🟡 in-progress port, candy-shine is production-quality for basic markdown rendering. The critical missing pieces for full glamour parity are: proper syntax highlighting, cascading style inheritance, BlockStack, definition lists, table footer links, and HTML sanitisation. These should be addressed in order of urgency based on user needs.

**Key reference repos for future work:**
- `docs/repo_map/charmbracelet_glamour.md` — Primary upstream, full BlockStack and cascadeStyle patterns
- `docs/repo_map/charmbracelet_lipgloss.md` — Theming patterns including `Inherit()`, adaptive colors
- `docs/repo_map/charmbracelet_soft-serve.md` — Chroma lexer usage for syntax highlighting reference
