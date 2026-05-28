# charmbracelet/glamour

## Metadata
- URL: https://github.com/charmbracelet/glamour
- Language: Go
- Stars: ~3.4k
- License: MIT
- Module: `charm.land/glamour/v2`
- Description: Stylesheet-based markdown rendering for CLI apps. Renders markdown documents and templates on ANSI-compatible terminals with customizable stylesheets.

## Feature List
- **Markdown Parsing**: Full GFM (GitHub Flavored Markdown) support via goldmark parser
- **ANSI Escape Rendering**: Converts markdown to ANSI escape sequences for terminal color/style output
- **Stylesheet System**: JSON-based style configuration with cascading style inheritance
- **Default Styles**: dark, light, dracula, tokyo-night, pink, ascii
- **Word Wrapping**: Configurable width (default 80) with UTF-8 aware line breaking
- **Hyperlink Support**: OSC 8 ANSI hyperlinks (clickable links in supporting terminals)
- **Syntax Highlighting**: Code block highlighting via Chroma with "charm" theme
- **Table Rendering**: Alignment, borders, footer links, using charm.land/lipgloss/v2
- **Definition Lists**: dl/dt/dd markdown extension
- **Task Lists**: Checkbox rendering with ticked/unticked states
- **Emoji Rendering**: Emoji shortcode support via goldmark-emoji
- **Strikethrough**: ~~strikethrough~~ text rendering
- **HTML Sanitization**: Strict policy via bluemonday for HTML blocks/spans
- **Block Quotes**: Styled blockquote rendering with margin handling
- **Emphasis**: Bold, italic, strong emphasis rendering with level tracking
- **Environment Config**: GLAMOUR_STYLE env var for default style selection
- **Color Downsampling**: Integration point with Lip Gloss for terminal capability adaptation
- **Custom Writers**: MarginWriter, PaddingWriter, IndentWriter for layout control

## Key Classes and Methods

### Core Renderer (`ansi/renderer.go`)
- `ANSIRenderer`: Main struct implementing goldmark's `NodeRenderer` interface
- `NewRenderer(options Options) *ANSIRenderer`: Constructor
- `RegisterFuncs(reg NodeRendererFuncRegisterer)`: Registers handlers for all AST node kinds
- `renderNode(w, source, node, entering)`: Dispatches rendering based on node type and enter/exit phase

### Rendering Context (`ansi/context.go`)
- `RenderContext`: Holds state during rendering (options, blockStack, table, stripper)
- `NewRenderContext(options Options) RenderContext`: Constructor
- `SanitizeHTML(s string, trimSpaces bool) string`: HTML sanitization via bluemonday

### Block Stack (`ansi/blockstack.go`)
- `BlockStack []BlockElement`: Stack tracking nested block elements for indentation/margin
- `Push(e BlockElement)`, `Pop()`, `Len() int`: Stack operations
- `Indent() uint`, `Margin() uint`: Computed aggregate values
- `Width(ctx RenderContext) uint`: Available rendering width after indent/margin
- `Current() BlockElement`, `Parent() BlockElement`: Stack inspection
- `With(child StylePrimitive) StylePrimitive`: Style inheritance

### Style Configuration (`ansi/style.go`)
- `StyleConfig`: Complete style tree (Document, H1-H6, Paragraph, List, Table, CodeBlock, etc.)
- `StyleBlock`: Block-level style (StylePrimitive + Indent, Margin, IndentToken)
- `StylePrimitive`: Individual style properties (Color, Bold, Italic, Underline, Prefix, Suffix, etc.)
- `Chroma`: Code syntax highlighting token types (Keyword, Comment, String, etc.)
- `cascadeStyle(parent, child, toBlock)`: Inheritance merging

### Element Renderers (`ansi/elements.go`)
- `NewElement(node ast.Node, source []byte) Element`: Factory dispatching correct element for AST node kind
- Returns `Element{Entering, Exiting, Renderer, Finisher}` tuple

### Block Elements
- `BlockElement`: Buffer for block children, applies margin/wrap via MarginWriter
- `HeadingElement`: Level-aware heading (h1-h6) with style cascade
- `ParagraphElement`: Text wrapping with soft/hard line break handling
- `TableElement`: Lip Gloss table builder with alignment and border configuration
- `TableCellElement`, `TableRowElement`, `TableHeadElement`: Table sub-components
- `ListElement` (inline in elements.go): Level-indent aware ordered/unordered lists
- `ItemElement`: List item with enumeration support
- `TaskElement`: Checkbox (ticked/unticked) rendering
- `DefinitionList`, `DefinitionTerm`, `DefinitionDescription`: Definition list elements

### Inline Elements
- `BaseElement`: Primitive text token with style, prefix/suffix, and template formatting
- `EmphasisElement`: Level-based (italic/strong) with child rendering
- `LinkElement`: OSC 8 hyperlink with text/href parts and URL resolution
- `ImageElement`: Image rendering with text fallback and relative URL resolution
- `CodeSpanElement`: Inline code with style
- `CodeBlockElement`: Fenced code block with Chroma syntax highlighting
- `StrikethroughElement`: Crossed-out text

### Custom Writers (`ansi/margin.go`)
- `MarginWriter`: io.Writer applying indentation and padding around content
- `PaddingWriter`: Adds trailing spaces for alignment (UTF-8 aware)
- `IndentWriter`: Adds indentation at line starts, skips on continuation lines

### Utility (`ansi/table_links.go`)
- `tableLink`: Link/image storage for table footer links
- `collectLinksAndImages(ctx)`: Walks table AST, collects reference-style links
- `printTableLinks(ctx)`: Renders collected links in table footer style
- `linkWithSuffix(tl tableLink, list)`: Formats link with numeric reference

### Template Helpers (`ansi/templatehelper.go`)
- `TemplateFuncMap`: Left, Mid, Right, Last, Matches + all strings.* functions for style format templates

## Notable Algorithms / Named Patterns
- **Block Stack Pattern**: Maintains a stack of block elements during AST traversal to compute dynamic indentation/margin without global state. Each block pushes itself on entry, pops on exit.
- **Cascading Style Inheritance**: Styles cascade from parent to child blocks (not CSS - custom recursive merge). Parent properties are inherited unless explicitly overridden by child.
- **OSC 8 Hyperlinks**: ANSI hyperlinks via `ESC ] 8 ; id=hash ; URL ST`. Uses FNV-32a hash of URL as unique identifier to allow multiple links with same URL.
- **Visitor Pattern (Goldmark AST)**: `NewElement()` dispatches on `ast.Kind` to return appropriate Element, which holds `Renderer` (enter) and `Finisher` (exit) interfaces.
- **UTF-8 Aware Word Wrap**: `PaddingWriter` and `IndentWriter` decode UTF-8 runes properly for CJK character width handling.
- **Pure Renderer Contract**: Renderer always produces identical output for identical input. Color downsampling is NOT done by glamour—caller uses Lip Gloss separately.
- **Child Element Short-Circuit**: `isChild()` checks if a node's parent renders it automatically (CodeSpan, Link, Image, Emphasis, etc.) to avoid double-rendering.

## Strengths
- **Comprehensive Markdown Coverage**: GFM + definition lists + emoji + strikethrough + task lists + tables
- **Stylesheet Flexibility**: Full JSON-based theming with per-element control (colors, bold, italic, indent, margin, prefixes/suffixes)
- **Cascading Style System**: Parent styles inherit to children without circular dependency
- **Pure Output**: Same input always produces same output—predictable, testable, deterministic rendering
- **Rich Integration Ecosystem**: Used by GitHub CLI, GitLab CLI, Gitea CLI, Glow, Meteor
- **Modern Terminal Support**: OSC 8 hyperlinks, TrueColor, wide character (CJK, emoji) handling
- **Chroma Integration**: High-quality syntax highlighting with customizable token styles
- **Clean Separation**: Markdown parsing (goldmark) vs rendering (glamour) vs color adaptation (Lip Gloss)
- **Environment Config**: GLAMOUR_STYLE for zero-config default style selection
- **Active Maintenance**: Charm ecosystem with regular releases (v2.0.0 March 2026)

## Weaknesses
- **External Root Package Missing**: The public `charm.land/glamour/v2` root package (`NewTermRenderer`, `Render`, `WithWordWrap`, etc.) is not present in this repo—only the `ansi` subpackage. The root package must be published separately to the charm.land module registry.
- **No Color Downsampling Built-in**: Purposely "pure" but requires separate Lip Gloss call for real terminals, adding boilerplate
- **No Custom Renderer Extension API**: Advanced customization requires copying internal types (MarginWriter, etc.) rather than interfaces
- **Limited Documentation**: Inline code comments are sparse; UPGRADE_GUIDE_V2.md exists but no formal spec
- **Shallow Clone Missing History**: `git clone --depth 1` only gets latest commit; no historical context
- **Styles Not in Repo**: The `styles/` directory (gallery of JSON styles) is referenced in tests but not present in shallow clone

## SugarCraft Mapping

| SugarCraft Lib | Glamour Feature | Mapping Type |
|---|---|---|
| `sugar-bits` (TUI base) | ANSI escape sequences, word wrap | **Direct port target** — glamour's `ansi` package is essentially a renderer for a TUI. The block stack, margin writer, and element rendering pattern map directly to `sugar-bits`'s view/render architecture. |
| `sugar-charts` | Table rendering | **Reference** — glamour's `TableElement` using Lip Gloss's table.Builder with alignments and borders is a production-quality table rendering example. The `TableCellElement` with children rendering mirrors chart data binding. |
| `candy-shine` | Styles/theme system | **Reference** — glamour's `StyleConfig` + `cascadeStyle()` is a complete stylesheet-in-JSON pattern. The `StylePrimitive` (Color, Bold, Italic, Underline, etc.) maps to `candy-shine`'s style primitives. |
| `candy-core` (rendering) | BaseElement, Element, Renderer pattern | **Direct port** — The `BaseElement.doRender()` with prefix/suffix/blockPrefix/blockSuffix and the Entering/Exiting/Renderer/Finisher tuple pattern is directly portable to `candy-core`'s render architecture. |
| `sugar-prompt` | Word wrapping, ANSI output | **Direct port** — glamour's `MarginWriter`/`lipgloss.Wrap` wrapping logic and UTF-8 aware `PaddingWriter` are directly reusable in `sugar-prompt` for multi-line prompt rendering. |
| `honey-bounce` | Animation timing | **Not applicable** — glamour has no animation concepts. |
| `candy-shell` | Link rendering (hyperlinks) | **Reference** — glamour's `LinkElement` with OSC 8 hyperlink generation (FNV hash for URL ID, `ansi.SetHyperlink`) provides a production implementation of terminal hyperlink rendering. |

## Analysis

Glamour is a well-architected markdown-to-ANSI renderer that cleanly separates concerns: goldmark handles parsing, glamour handles rendering, and Lip Gloss handles color adaptation. The architecture uses a visitor-like pattern where `ANSIRenderer.renderNode()` dispatches on AST node type, returning an `Element` with `Renderer` (enter) and `Finisher` (exit) callbacks. This two-phase approach (entering/exiting) allows proper nesting—parent blocks push themselves onto the `BlockStack` when entering and pop when exiting, so child elements can query `blockStack.Current()` and `blockStack.Parent()` for indentation and style inheritance.

The stylesheet system uses cascading inheritance: `cascadeStyle()` recursively merges parent and child `StyleBlock` structs, preferring child values when non-nil. This enables hierarchical theming (e.g., heading styles inherit from base heading style, which inherits from document style) without circular dependencies. The `StylePrimitive` struct holds individual attributes (Color pointer, Bold bool pointer, etc.) using pointer semantics for "not set" detection.

The block stack is the heart of the rendering state machine. It tracks not just nesting depth but also accumulated `Indent` and `Margin` values, computing available `Width` as `WordWrap - Indent - Margin*2`. This allows nested blockquotes and lists to dynamically reduce available width without passing state through the AST visitor. Custom `io.Writer` implementations (`MarginWriter`, `PaddingWriter`, `IndentWriter`) apply the actual whitespace handling, with UTF-8 aware rune decoding for proper CJK character width calculation.

The main limitation for porting to SugarCraft is that glamour is tightly coupled to goldmark's AST (`ast.Node`, `ast.Kind`, `astext.KindTable`). A PHP port would need to either reimplement an AST-based walker or build an intermediate representation. The element factory pattern (`NewElement()` dispatching on node kind) and the Entering/Exiting/Renderer/Finisher tuple are highly portable concepts. The cascading style system and block stack for margin/indent tracking are also directly implementable in PHP.
