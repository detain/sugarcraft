# SugarCraft/candy-shine — Innovation & Comparison Report

## Metadata

| Field | Value |
|---|---|
| **Package** | `sugarcraft/candy-shine` |
| **Description** | Markdown-to-ANSI renderer with word-wrap, OSC 8 hyperlinks, syntax highlighting, and 8 stock themes |
| **Upstream** | [charmbracelet/glamour](https://github.com/charmbracelet/glamour) |
| **Status** | 🟡 In progress |
| **Namespace** | `SugarCraft\Shine` |
| **Subdirectory** | `candy-shine/` |
| **PHP** | ^8.3 |
| **Test suite** | 458 + 770 + 161 + 125 + 58 lines (~1,572 assertions) |

---

## 1. Deep Internal Analysis

### 1.1 Source File Inventory

| File | Lines | Role |
|---|---|---|
| `src/Renderer.php` | 684 | `league/commonmark` AST visitor — dispatches on `Node` type → styled ANSI output |
| `src/Theme.php` | 657 | Immutable stylesheet — 45+ slots (headings, inline, blocks, syntax tokens) + 8 stock themes |
| `src/SyntaxHighlighter.php` | 175 | Regex-based tokeniser — PHP/JS/TS/Python/Go/Bash/SQL + JSON; keyword/string/number/comment classes |
| `src/Lang.php` | 22 | i18n facade wrapping `SugarCraft\Core\I18n\T` with `'shine'` namespace |
| `lang/en.php` + 14 locales | — | Translations: fr, de, es, pt, pt-br, zh-cn, zh-tw, ja, ru, it, ko, pl, nl, tr, cs, ar |

### 1.2 Markdown Parsing Architecture

**Parser**: `league/commonmark` v2.4 (not goldmark as in upstream glamour)

The `Renderer` constructor builds a `CommonMarkCoreEnvironment` with these extensions:
- `CommonMarkCoreExtension` — headings, paragraphs, code blocks, lists, blockquotes, links, images, emphasis, strong, thematic breaks, HTML blocks/inline
- `TableExtension` — GFM tables
- `TaskListExtension` — GFM task list items (`[x]` / `[ ]`)
- `StrikethroughExtension` — `~~strikethrough~~`
- `AutolinkExtension` — auto-detect URLs

The `MarkdownParser` is instantiated per `Renderer` instance (cached, not rebuilt per render call).

**Rendering** is a single-pass visitor via `renderNode(Node $node)`. Unlike glamour's two-phase Enter/Exit pattern, candy-shine uses a single `renderChildren()` recursion that concatenates child output. Block-level nodes (heading, paragraph, list, blockquote, table, code) return text that already ends in `\n\n`; inline nodes return inline fragments.

Key limitation vs upstream glamour: **no explicit block stack**. Glamour maintains `BlockStack` to track nested indent/margin for nested blockquotes, lists, and definition lists, allowing dynamic width computation as you descend. Candy-shine handles nesting directly in `renderList()` (calculates `indentN` from bullet width + `listLevelIndent`) and `renderBlockQuote()` (subtracts 2 cells for `▎ ` prefix), but does not propagate accumulated indent context to deeper nested structures beyond immediate parentage.

### 1.3 Rendering Pipeline

```
$markdown
  ├─ expandEmojiShortcodes() if enabled
  └─ $parser->parse($markdown) → Document node (league/commonmark AST)
       └─ renderChildren(document) → recursively calls renderNode()
            ├─ renderHeading()    → apply headingN style + headingCase + headingPrefix/Suffix
            ├─ renderParagraph()   → renderChildren → Width::wrapAnsi() → paragraphPrefix/Suffix → paragraph style
            ├─ renderFencedCode() → SyntaxHighlighter::highlight() or codeBlock style
            ├─ renderBlockQuote() → ▎ prefix per line → blockquote style
            ├─ renderList()         → ordered/unordered distinction, nested indent, marker → listMarker style
            ├─ renderTable()       → SprinklesTable with rounded border, cell renderChildren
            ├─ renderLink()        → linkText style + Ansi::hyperlink() + (url) suffix
            ├─ renderImage()       → image/imageText style + (url) suffix
            ├─ renderStrike()      → strike style (SGR 9) or plain
            ├─ renderHtmlBlock/Span → htmlBlock/htmlSpan style
            └─ renderTaskMarker()  → taskTickedGlyph/taskUntickedGlyph via listMarker style
```

After `renderChildren(document)`:
1. `rtrim()` strips trailing newlines
2. `documentBlockPrefix` / `documentBlockSuffix` wrap the document
3. `documentIndent` applies left indent
4. `documentMargin` adds blank lines before/after
5. `reapplyBlankRuns()` reinflates `\n{3,}` if `preservedNewLines` is set

### 1.4 Theme System

**Immutable value object** with 45+ `readonly` constructor parameters. Every stock theme (ansi, dark, light, dracula, tokyoNight, pink, ascii, plain, notty) is a named factory method. Theme lookup is case-insensitive with hyphen/underscore normalisation via `Theme::byName()`.

**Cascading** is implicit — null slots fall through to the consumer (e.g., `$this->theme->strike ?? Style::new()->strikethrough()` in `renderStrike()`). There is no recursive merge/cascade like glamour's `cascadeStyle()`.

**JSON loading** (`Theme::fromJsonString()`) parses a flat object keyed by element name, supporting:
- `foreground` / `background`: hex `#rrggbb`, `ansi:N`, `ansi256:N`
- flags: `bold`, `italic`, `underline`, `strike`, `faint`, `blink`, `reverse`

**Environment variable** `GLAMOUR_STYLE` is read by `Theme::fromEnvironment()` — mirrors glamour's behaviour exactly, including the `auto` keyword (dark/notty based on `stream_isatty()`).

**Shortcomings vs glamour**:
- No `StyleBlock` distinction between block-level (with Indent/Margin/IndentToken) and inline styles
- No cascading merge — null slots become no-op Style, not inherited parent values
- No `conceal` implementation (placeholder property exists but is not acted upon)
- No margin/indent writer abstraction — paragraph prefix/suffix are applied as simple strings

### 1.5 Code Highlighting Integration

`SyntaxHighlighter::highlight()` is a **custom regex tokeniser**, not Chroma (the Go library glamour uses).

**Supported languages** (hardcoded keyword lists):
- PHP, JavaScript/TypeScript, Python, Go, Bash, SQL, JSON

**Token classes**: `comment`, `string`, `keyword`, `number`

**Algorithm** (in `tokenise()`):
1. Builds combined regex with 4 named alternatives (comment/string before keyword/number)
2. Iterates matches in offset order via `PREG_OFFSET_CAPTURE | PREG_SET_ORDER`
3. Styles each token via the matching theme slot
4. Passes unrecognised gaps through `codeBlock` style

**Line numbers**: Optional `$lineNumbers` parameter adds 1-based padded line numbers styled with `comment` theme.

**Limitations**:
- No actual lexer — regex cannot handle language grammar properly (e.g., string inside comment would be styled as string)
- No HTML/XML lexer (upstream glamour's soft-serve uses Chroma for this)
- Python keywords are case-sensitive (uppercase `True`/`False`/`None` won't match the lowercase keyword list)
- SQL keywords in the list are lowercase — `SELECT` won't be highlighted
- No regex/CRON/INI/yaml/css/etc. lexers
- No theme inheritance for syntax tokens in stock themes (keyword/string/number/comment slots exist but not set in all stock themes — e.g., `plain()` has them set to no-op Style)

### 1.6 Hyperlink Handling

Uses `SugarCraft\Core\Util\Ansi::hyperlink()` which emits OSC 8:

```php
Ansi::hyperlinkOpen(string $uri, ?string $id = null)  // → "\x1b]8;id=...;url\x1b\\"
Ansi::hyperlinkClose()                                  // → "\x1b]8;;\x1b\\"
Ansi::hyperlink(string $url, string $text, string $id = '')  // combined
```

**FNV hashing** for URL IDs is NOT implemented — unlike glamour which uses FNV-32a to hash URLs for link IDs. Links always get `id=null` (empty ID). This is a minor deviation but means multiple links to the same URL share no state.

**In tables**: `inTableCell` flag suppresses hyperlinks when `inlineTableLinks(false)` — the `renderLink()` method checks `$this->inTableCell && !$this->inlineTableLinks` to skip OSC 8 wrapping.

**Base URL resolution** (`resolveUrl()`) handles:
- Absolute URLs (any scheme) pass through unchanged
- Protocol-relative (`//host`) pass through
- Fragment-only (`#anchor`) pass through
- Relative paths are prefixed with `baseUrl + '/' + path`

### 1.7 Word Wrap

Uses `SugarCraft\Core\Util\Width::wrapAnsi()` — a custom ANSI-aware word wrapper that:
- Parses inline CSI sequences and attaches them to the word that contains them
- Breaks on word boundaries with fallback to mid-grapheme cuts for oversize words
- Handles both soft breaks (`\n` in source) and hard wraps (computed)

**Applied to**: paragraphs (full width), blockquotes (width - 2 for `▎ ` prefix), list item bodies (via indent calculation), table cells (when `tableWrap` enabled)

**Not wrapped**: code blocks, tables (except cells with `tableWrap`), headings

### 1.8 Table Rendering

Delegates to `SugarCraft\Sprinkles\Table\Table` (Sprinkles border system):
- `border(Border::rounded())` — `╭` `─` `┬` `├` `┼` `┤` `┴` `╰` `╯` characters
- Header row via `->headers(...$headers)` — rendered in bold (default) or `tableHeader` style if set
- Body rows via `->row(...$cells)` — each cell rendered via `renderChildren(cell)` (inline pipeline), then optionally wrapped, then `tableCell` style applied
- No per-column width constraints (unlike glamour's column width specification)

**Gap vs glamour**: glamour supports table footer links (collected via `collectLinksAndImages()` and rendered below the table). Candy-shine does not.

### 1.9 List Rendering

**Nested indent** via `listLevelIndent` + bullet width calculation:
```php
$indentN = max(mb_strlen($bullet, 'UTF-8') + 1, $levelIndent);
```

**Continuation lines** indent to align under first character of body:
```php
$out .= $marker->render($bullet) . ' ' . rtrim($first) . "\n";
foreach ($lines as $line) {
    $line = rtrim($line);
    $out .= ($line === '' ? '' : $indent . $line) . "\n";
}
```

**Trailing blank lines** from CommonMark softbreaks collapsed via `preg_replace('/\n{2,}/', "\n", $body)`.

**Ordered list format** configurable via `orderedListMarkerFormat` (default `'%d.'`).

**Custom glyphs**: `taskTickedGlyph` (`☑`) / `taskUntickedGlyph` (`☐`).

### 1.10 Blockquote/Strikethrough/Code Span Handling

- **Blockquote**: `▎ ` prefix per line, then `blockquote` style wraps each prefixed line
- **Strikethrough**: `Style::strikethrough()` (SGR 9) applied to inner text; falls back to no-op if theme has `$strike = null`
- **Code span**: `code` style applied to literal text — no backtick stripping
- **Indented code block**: `codeBlock` style applied to `rtrim($node->getLiteral(), "\n")`
- **Fenced code block**: routed to `SyntaxHighlighter::highlight()` if language specified, otherwise plain `codeBlock` style

---

## 2. Comparison Against Mapped Third-Party Repos

### 2.1 charmbracelet/glamour (primary upstream) — 3.4k stars

| Feature | Glamour (Go) | Candy-Shine (PHP) |
|---|---|---|
| Markdown parser | goldmark (custom AST) | league/commonmark v2.4 |
| Visitor pattern | Two-phase Enter/Exit with BlockStack | Single-pass renderChildren |
| Block stack | Full `BlockElement` stack tracking indent/margin/width | No explicit stack; ad-hoc indent in list/blockquote |
| Style inheritance | `cascadeStyle()` recursive merge | Null-slot fallthrough only |
| Code highlighting | Chroma with 200+ lexers + "charm" theme | Custom regex (7 languages) |
| Definition lists | dl/dt/dd via extension | Declared in Theme but not implemented in Renderer |
| HTML sanitisation | bluemonday strict policy | None — HTML passes through with style |
| OSC 8 link ID | FNV-32a hash of URL | Null ID (no hashing) |
| Link suffix | Numeric `[1]` footnotes in table footer | `(url)` suffix inline |
| Word wrap writer | `MarginWriter` + `PaddingWriter` + `IndentWriter` | `Width::wrapAnsi()` |
| Stylesheet format | JSON with nested blocks | JSON with flat element keys |
| Emoji expansion | goldmark-emoji extension | Static shortcode map (20 entries) |
| Stock themes | dark, light, dracula, tokyo-night, pink, ascii | +notty, plain, ansi (9 themes) |

**Key architectural differences**:
1. Glamour's BlockStack is the heart of its rendering — it computes available width dynamically as blocks nest. Candy-shine loses this by handling nesting directly in each renderer method.
2. Glamour's `Element` abstraction (Entering/Exiting/Renderer/Finisher) allows parent blocks to push state and child blocks to query it. Candy-shine lacks this abstraction.
3. Glamour's `StyleBlock` distinguishes block-level properties (Indent, Margin, IndentToken) from inline `StylePrimitive`. Candy-shine conflates them into one `Style` type.

### 2.2 charmbracelet/glow (markdown reader) — 15k stars

Glow consumes glamour for rendering. Candy-shine fills the same role:
- Glow's `glamourRender()` does: strip YAML frontmatter, optionally wrap in code fences, render via glamour, add line numbers
- SugarGlow (`sugar-glow/`) consumes candy-shine the same way

**Gap**: SugarGlow does not yet implement:
- Frontmatter stripping (`RemoveFrontmatter()`)
- Live file watching (fsnotify)
- GitHub/GitLab README fetching
- Fuzzy file filtering

### 2.3 charmbracelet/huh (theming) — 4.9k stars

Maps to candy-shine only for the **theme system** concept:
- Huh uses `lipgloss` for all styling — its `Theme` maps different element types to `Styles` structs with `Base lipgloss.Style`
- Candy-shine's `Theme` is the equivalent — a value object with per-element styles

**Different focus**: huh is about form field presentation; candy-shine is about markdown rendering. They share the theming pattern (JSON-configurable stylesheet) but serve different rendering domains.

### 2.4 pterm/pterm (styling) — 6k stars

No direct markdown rendering. However:
- `pterm.ThemeDefault` maps to `candy-shine` + `candy-core` as the SugarCraft theme defaults
- The `Style` chain pattern (`color.go:L263-L396`) is functionally similar to `SugarCraft\Sprinkles\Style` — both use fluent builders returning new instances

**Key insight**: pterm's 25+ printers cover the full spectrum from colored text to interactive TUI. Candy-shine's scope is narrower — markdown-to-ANSI only — but the styling primitives it uses (`Style`, `Color`) are shared infrastructure used by all SugarCraft libs.

---

## 3. Additional Third-Party Repo Exploration

### 3.1 Related Markdown/ANSI Rendering Repos

**charmbracelet/soft-serve** — Uses Chroma lexers to detect markdown and highlight code blocks in README rendering. Relevant for future Chroma integration into candy-shine.

**charmbracelet/pop** — Email client using goldmark for Markdown→HTML. The markdown→ANSI pipeline in candy-shine is conceptually equivalent but for terminal output rather than email HTML.

**charmbracelet/mods** — AI CLI using glamour for markdown rendering of LLM output. Shows the real-world use case candy-shine targets.

**charmbracelet/fang** — CLI helper where `candy-shine` is thematically adjacent but for markdown vs. help text rendering.

### 3.2 Textual (Python TUI framework)

**textualize/textual** has a `Markdown` widget that renders markdown in TUI. Not directly comparable — Python, not PHP, and uses different rendering approach.

---

## 4. Innovation Report

### 4.1 What's Implemented

| Feature | Status | Implementation |
|---|---|---|
| GFM parsing | ✅ Complete | league/commonmark with 5 extensions |
| Headings H1-H6 | ✅ | `renderHeading()` with per-level style + case transform + prefix/suffix |
| Paragraphs with word-wrap | ✅ | `renderParagraph()` → `Width::wrapAnsi()` |
| Bold/italic/strike | ✅ | `renderNode()` match dispatch |
| Inline code | ✅ | `code` style on `Code::getLiteral()` |
| Fenced code blocks | ✅ | `renderFencedCode()` → `SyntaxHighlighter::highlight()` |
| Indented code blocks | ✅ | `codeBlock` style |
| Bullet/ordered/nested lists | ✅ | `renderList()` with indent calculation |
| Blockquotes | ✅ | `▎ ` prefix per line |
| GFM tables | ✅ | SprinklesTable with rounded border |
| Task lists | ✅ | `☑`/`☐` glyphs via `listMarker` style |
| OSC 8 hyperlinks | ✅ | `Ansi::hyperlink()` |
| Link suffix `(url)` | ✅ | Inline or suppressed in tables |
| Images with alt+url | ✅ | `image`/`imageText` styles |
| Strikethrough | ✅ | SGR 9 style |
| HTML blocks/inline pass-through | ✅ | `htmlBlock`/`htmlSpan` styles |
| Autolinks | ✅ | AutolinkExtension |
| Emoji shortcodes | ✅ | 20-entry static map |
| Preserved newlines | ✅ | Blank-run re-inflation |
| 8 stock themes | ✅ | ansi/plain/notty/ascii/dark/light/dracula/tokyoNight/pink |
| JSON theme loading | ✅ | `Theme::fromJsonString()` |
| GLAMOUR_STYLE env var | ✅ | `Theme::fromEnvironment()` |
| Base URL resolution | ✅ | `resolveUrl()` with scheme detection |
| Table cell wrapping | ✅ | `withTableWrap()` |
| Inline table links toggle | ✅ | `withInlineTableLinks()` |
| Heading case transform | ✅ | upper/lower/title/none |
| Custom heading prefix | ✅ | `headingPrefix` slot |
| Document margin/indent | ✅ | `documentMargin`/`documentIndent` |
| List level indent config | ✅ | `listLevelIndent` |
| Custom task glyphs | ✅ | `taskTickedGlyph`/`taskUntickedGlyph` |
| Custom HR glyph/length | ✅ | `horizontalRuleGlyph`/`horizontalRuleLength` |
| Definition list styles | ⚠️ | Declared in Theme, not implemented in Renderer |
| Table footer links | ❌ | Not implemented |
| Block-level indent/margin style | ❌ | No `StyleBlock` distinction |
| Cascading style merge | ❌ | Null-slot fallthrough only |
| HTML sanitisation | ❌ | Raw HTML passes through |
| FNV link ID hashing | ❌ | Null ID for all links |
| Chroma/high-quality syntax highlighting | ❌ | Custom regex only |
| Per-column table width constraints | ❌ | SprinklesTable handles natively |
| MarginWriter/PaddingWriter abstraction | ❌ | Simple string prefix/suffix |

### 4.2 What Remains Incomplete

1. **Definition lists** (`dl`/`dt`/`dd`) — Theme has `$definitionTerm`, `$definitionDescription`, `$definitionList` slots, but `renderNode()` has no case for these node types. They fall through to `default => renderChildren($node)`.

2. **Table footer links** — Glamour collects links referenced in table cells and renders them as a numeric footer. Candy-shine does not implement this.

3. **Cascading style inheritance** — Glamour's `cascadeStyle()` recursively merges parent and child `StyleBlock` structs. When a child element doesn't specify a property, it inherits from the parent in the block stack. Candy-shine uses null-slot fallthrough: `($this->theme->property ?? Style::new())`. This means nested elements cannot inherit from parent block styles.

4. **Block-level style properties** — Glamour's `StyleBlock` has `Indent`, `Margin`, `IndentToken` fields. These allow blockquote rendering to dynamically adjust available width as nesting deepens. Candy-shine only supports the literal `blockquote` style — it can't express "indent this block by 2 cells and reduce available width by 4 cells."

5. **HTML sanitisation** — Glamour uses bluemonday to sanitise HTML blocks and inline HTML. Candy-shine passes HTML through verbatim with only the `htmlBlock`/`htmlSpan` style applied. For untrusted input this is a security gap.

6. **Custom margin/indent writers** — Glamour's `MarginWriter`/`PaddingWriter`/`IndentWriter` are composable io.Writer wrappers. The margin writer is particularly important for nested blockquotes/lists. Candy-shine achieves similar effects with string concatenation but loses the composability.

7. **High-quality syntax highlighting** — The regex tokeniser cannot properly handle language grammar. For instance, a keyword inside a string literal would incorrectly be styled as a keyword. Glamour uses Chroma which is a proper lexer library. A proper integration would require a PHP port of Chroma or an equivalent (e.g., `和精神`/`PHP-Tokens`/`PHP-Parser` based highlighting).

8. **FNV link ID hashing** — Multiple links to the same URL cannot share an OSC 8 link ID in candy-shine because no hashing is done. This is minor but means terminals that cache link targets by ID won't consolidate them.

### 4.3 Architectural Gaps vs Glamour

The most significant architectural gap is the **absence of an explicit block stack**. In glamour:

```go
// On entering a block, push onto stack:
ctx.blockStack.Push(block)

// On exiting a block, pop from stack:
ctx.blockStack.Pop()

// Available width = WordWrap - Indent(ctx.blockStack) - Margin(ctx.blockStack)*2
```

This allows nested blockquotes at any depth to correctly compute their available width. In candy-shine, `renderBlockQuote()` hard-codes a 2-cell subtraction for the `▎ ` prefix, and `renderList()` calculates indent from bullet width, but deeper nesting (blockquote inside a list item inside a blockquote) is not architecturally supported — it would require cascading the context through the recursive `renderChildren()` calls.

---

## 5. File References

### Source Files
- `/home/sites/sugarcraft/candy-shine/src/Renderer.php` — Main renderer with all node type dispatch
- `/home/sites/sugarcraft/candy-shine/src/Theme.php` — Theme value object with 45+ slots and 9 stock themes
- `/home/sites/sugarcraft/candy-shine/src/SyntaxHighlighter.php` — Regex tokeniser for 7 languages
- `/home/sites/sugarcraft/candy-shine/src/Lang.php` — i18n facade

### Tests
- `/home/sites/sugarcraft/candy-shine/tests/RendererTest.php` — 458 lines, core rendering assertions
- `/home/sites/sugarcraft/candy-shine/tests/SyntaxHighlighterTest.php` — 770 lines, comprehensive tokeniser coverage
- `/home/sites/sugarcraft/candy-shine/tests/ThemeTest.php` — 161 lines, theme loading and preset assertions
- `/home/sites/sugarcraft/candy-shine/tests/RendererRound2Test.php` — 125 lines, round-2 features
- `/home/sites/sugarcraft/candy-shine/tests/ShortAliasesTest.php` — 58 lines, alias parity
- `/home/sites/sugarcraft/candy-shine/tests/ThemeExtensionsTest.php` — 161 lines, audit #9 extensions

### Dependencies
- `/home/sites/sugarcraft/candy-shine/composer.json` — `league/commonmark:^2.4`, `sugarcraft/candy-core`, `sugarcraft/candy-sprinkles`
- `/home/sites/sugarcraft/candy-core/src/Util/Width.php` — Word wrap + ANSI-aware utilities
- `/home/sites/sugarcraft/candy-core/src/Util/Ansi.php` — OSC 8 hyperlink primitives (lines 269-303)
- `/home/sites/sugarcraft/candy-sprinkles/src/Style.php` — ANSI styling primitives
- `/home/sites/sugarcraft/candy-sprinkles/src/Border.php` + `Table/Table.php` — Table rendering

### Consumer
- `/home/sites/sugarcraft/sugar-glow/src/RenderCommand.php` — CLI that consumes candy-shine
- `/home/sites/sugarcraft/sugar-glow/src/GlowModel.php` — Bubble Tea model for pager

---

## 6. Summary

**Candy-shine** is a well-structured, feature-rich PHP port of glamour that covers ~80% of the upstream functionality with significantly simpler architecture. Its primary strengths are:

1. **Clean separation** between markdown parsing (league/commonmark), styling (Sprinkles Style), and layout (Width utilities)
2. **Comprehensive theming** with 9 stock themes and JSON loading
3. **Full GFM support** including tables, task lists, strikethrough
4. **OSC 8 hyperlinks** with graceful fallback
5. **Good test coverage** with deterministic snapshot-style assertions

Its primary limitations stem from:
1. **No block stack** for managing nested block indent/margin dynamically
2. **No cascading style merge** — null slots fall through to no-op
3. **Regex-based syntax highlighting** — insufficient for production code display
4. **No definition list support** despite Theme slots existing
5. **No HTML sanitisation** for untrusted input
6. **No table footer links** (glamour's distinctive feature)

For a 🟡 in-progress port, candy-shine is production-quality for basic markdown rendering, but lacks the nuanced block-level style handling and proper syntax highlighting that make glamour distinctive.
