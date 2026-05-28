# SugarCraft Sugar-Glow — Update Report

## Overview

`sugar-glow` is a PHP port of `charmbracelet/glow`, a Markdown CLI viewer that composes candy-shine (ANSI rendering) and sugar-bits Viewport (scrolling). It provides dual-mode output: direct stdout rendering and a fullscreen pager via `GlowModel` + `Viewport`. The package is 🟢 v1 ready with 15 locales, 11 themes, streaming pager, and file watching via mtime polling.

**Biggest opportunity areas:** GitHub/GitLab README fetching (glow's signature feature), file stash browser with fuzzy filtering, proper Chroma-style lexer for syntax highlighting, native file watching (fsnotify-equivalent), and clipboard integration.

**Biggest missing capabilities:** Upstream glow's killer features — remote README fetching, file browser with fuzzy search, native OS file watching, clipboard support, $PAGER fallback, line numbers, and external editor integration — are entirely unimplemented. The regex-based highlighter is a proof-of-concept, not a production lexer.

---

## Internal Capability Summary

### Current Architecture

```
RenderCommand::loadInput()
    ↓ (file path or stdin)
Renderer::render($markdown)
    ├── League\CommonMark\MarkdownRenderer (from sugar-bits)
    └── Renderer (from candy-shine)
        ├── ->withWordWrap($width)
        └── ->withHyperlinks($enabled)
    ↓ (ANSI-escaped string)
[stdout | GlowModel::fromContent()]
```

### Current Features

| Feature | Implementation |
|---|---|
| Markdown parsing | League\CommonMark via `sugar-bits` |
| ANSI styling | `SugarCraft\Shine\Renderer` with 11 built-in themes |
| Pager mode | `GlowModel` + `Viewport` (ReactPHP-based) |
| Word wrap | `Renderer::withWordWrap()` |
| OSC 8 hyperlinks | `Renderer::withHyperlinks()` |
| Syntax highlighting | `ChromaJsonHighlighter` (regex tokenizer) |
| File watching | `FileWatcher` (mtime polling, 1-second resolution) |
| i18n | 15 locales via `Lang` facade |

### APIs

- **`GlamourTheme::fromJson()` / `fromFile()`** — Load Glamour-style JSON theme files
- **`FileWatcher::watch()`** — Generator-based file watching loop
- **`FileWatcher::hasChangedSince()`** — Check if file modified since given mtime
- **`Width::string()` / `truncate()` / `padRight()`** — CJK/emoji-aware width measurement

### Rendering Systems

- **League\CommonMark** parses markdown to AST
- **candy-shine Renderer** applies ANSI styles and word-wrap
- **GlowModel** provides Bubble Tea-style state machine for pager mode

### Strengths

1. Clean separation of concerns (rendering, TUI, CLI layers)
2. Immutable model pattern correctly implemented
3. Cross-platform file watching without extensions
4. 11 built-in themes + custom JSON loading
5. Full 15-locale i18n infrastructure
6. CJK/emoji width handling via `Width` utility
7. OSC 8 hyperlink support
8. Streaming pager for large files
9. Comprehensive PHPUnit 10 test coverage

### Weaknesses

1. No GitHub/GitLab integration (glow's signature feature)
2. No file stash/browser (single-file only)
3. No fuzzy filtering (no `sahilm/fuzzy` equivalent)
4. Regex highlighter is proof-of-concept, not production lexer
5. mtime polling has 1-second resolution, can't detect rapid changes
6. No clipboard support (OSC 52)
7. No $PAGER fallback
8. No line numbers
9. No config file support
10. No external editor integration
11. No git-aware file discovery

---

## Relevant External Repositories

| Repo | Relevance | Major Applicable Concepts | Priority |
|---|---|---|---|
| `charmbracelet/glow` | 🟢 Direct upstream | GitHub/GitLab API, file stash, fuzzy filter, fsnotify, OSC 52, $PAGER, line numbers, config file | Critical |
| `charmbracelet/glamour` | 🟢 Direct rendering upstream | Block stack pattern, cascading style inheritance, two-phase rendering, word wrap fixes, footer links for tables | Critical |
| `charmbracelet/bubbletea` | 🟡 Framework reference | Elm architecture, Cmd/Msg pattern, buffered rendering, synchronized output mode | High |
| `charmbracelet/lipgloss` | 🟡 Styling reference | Style system, color blending, borders, layout helpers, adaptive colors | High |
| `charmbracelet/gum` | 🟡 CLI patterns | Config file support, horizontal scrolling, ANSI/grapheme handling | Medium |
| `charmbracelet/bubbles` | 🟡 Component reference | Viewport horizontal scroll, textarea memoization, pagination string building | Medium |
| `textualize/textual` | 🟡 Advanced patterns | Weakref DOM, GC freeze for content widgets, command palette, virtualization | Low |
| `ratatui/ratatui` | 🟡 Layout/rendering | Buffer diffing, constraint-based layout, Widget trait pattern | Low |

---

## Feature Gap Analysis

### Critical Priority

**1. GitHub/GitLab README Fetching**
- **Description:** Fetch remote README files via `github://owner/repo` or `gitlab://owner/repo` URL protocols, or direct API calls
- **Why it matters:** This is glow's defining feature — the primary reason users choose glow over simple markdown renderers
- **Source:** `charmbracelet/glow.md` — `sourceFromArg()` parses URLs, `findGitHubREADME()` / `findGitLabREADME()` fetch via API
- **Implementation ideas:**
  - Add HTTP client dependency (use ReactPHP's promises or PSR-18)
  - Parse `github://` and `gitlab://` protocol prefixes in `RenderCommand::loadInput()`
  - Implement `fetchGitHubREADME(string $repo): ?string` using GitHub REST API v3
  - Implement `fetchGitLabREADME(string $project): ?string` using GitLab API v4
- **Estimated complexity:** Medium — requires HTTP client integration and API error handling
- **Expected impact:** High — fills the single biggest gap vs. upstream

**2. File Stash Browser with Fuzzy Filtering**
- **Description:** TUI file browser that lists discovered markdown files with fuzzy search filtering
- **Why it matters:** Upstream glow's TUI mode provides stash browsing — users expect this for multi-file workflows
- **Source:** `charmbracelet/glow.md` — `stash.go` with `fuzzy.Find()` filtering, `stashModel` with spinner + filter input
- **Implementation ideas:**
  - Port `sahilm/fuzzy` algorithm for ranked filename matching with character indices
  - Create `StashModel` (Bubble Tea-style) with `SpinnerModel` + `TextInputModel` + `ItemListModel`
  - Implement `localFileToMarkdown()` converting `gitcha.SearchResult` to internal markdown representation
  - Add `/` keybinding to activate filter mode (mirrors fzf behavior from `pr_charmbracelet_gum.md`)
- **Estimated complexity:** High — requires fuzzy library port + new TUI model
- **Expected impact:** High — enables multi-file workflows

**3. Proper Syntax Highlighting Lexer**
- **Description:** Replace regex tokenizer (`ChromaJsonHighlighter`) with proper lexer-based highlighting
- **Why it matters:** The current regex approach breaks on malformed code and doesn't handle all token types correctly
- **Source:** `charmbracelet_glamour.md` — glamour uses full Chroma lexer for code block highlighting
- **Implementation ideas:**
  - Port or wrap the Chroma lexer (Go library, could be called via FFI or reimplemented)
  - Use tree-sitter for PHP syntax highlighting (referenced in `pr_textualize_textual.md` issue #5976)
  - Implement proper tokenization with comment/string/keyword/number/function/operator/punctuation token kinds
- **Estimated complexity:** High — requires either FFI integration or major reimplementation
- **Expected impact:** Medium — improves code block rendering quality significantly

**4. Native File Watching (inotify/FSEvents)**
- **Description:** OS-native file system notifications instead of mtime polling
- **Why it matters:** mtime polling has 1-second resolution and can't detect rapid changes; wastes CPU
- **Source:** `charmbracelet_glow.md` — uses `fsnotify` for `watchFile()` / `unwatchFile()`
- **Implementation ideas:**
  - Use PHP's `inotify` extension on Linux
  - Use `kqueue` via `libvent` or equivalent on macOS
  - Fall back to mtime polling when extensions unavailable
  - Create `NativeFileWatcher` class with same API as `FileWatcher`
- **Estimated complexity:** Medium — requires extension detection and platform-specific code paths
- **Expected impact:** Medium — enables responsive live reload

### High Value

**5. Clipboard Integration (OSC 52)**
- **Description:** Copy selected content to terminal clipboard
- **Why it matters:** Standard feature in modern CLI tools
- **Source:** `charmbracelet_glow.md` — `clipboard copy` in pager keys, OSC 52 integration
- **Implementation ideas:**
  - Implement OSC 52 escape sequence for clipboard write
  - Add 'c' keybinding in pager mode to copy selection
  - Handle both terminal-native (OSC 52) and native clipboard APIs where available
- **Estimated complexity:** Low — single escape sequence sequence
- **Expected impact:** Medium — improves UX for content consumption

**6. Line Numbers Display**
- **Description:** Optional line number gutter in pager mode
- **Why it matters:** Common expectation for reading code-heavy markdown
- **Source:** `charmbracelet_glow.md` — `ShowLineNumbers` config option in `ui/config.go`
- **Implementation ideas:**
  - Add `--line-numbers` flag to `RenderCommand`
  - Track line boundaries during rendering
  - Render gutter with line numbers in `GlowModel::view()`
- **Estimated complexity:** Low — render-time addition
- **Expected impact:** Medium — common user expectation

**7. $PAGER / less -r Fallback**
- **Description:** Respect $PAGER environment variable, shell out when not in TTY
- **Why it matters:** Idiomatic Unix behavior; users expect pager integration
- **Source:** `charmbracelet_glow.md` — `Pager support` using `less -r` fallback
- **Implementation ideas:**
  - Detect non-TTY and shell out to `$PAGER` or `less -r`
  - Pass rendered ANSI content through the external pager
- **Estimated complexity:** Low — environment variable check + proc_open
- **Expected impact:** Medium — better Unix integration

**8. Config File Support**
- **Description:** YAML or JSON config in XDG_CONFIG_HOME with env var overrides
- **Why it matters:** Users want persistent preferences (default theme, width, etc.)
- **Source:** `charmbracelet_glow.md` — `tryLoadConfigFromDefaultPlaces()` with Viper/YAML
- **Implementation ideas:**
  - Use `symfony/yaml` for parsing
  - Support XDG paths: `~/.config/sugar-glow/config.json`
  - Precedence: CLI flags > env vars > config file > defaults
- **Estimated complexity:** Low — configuration loading only
- **Expected impact:** Medium — improves usability

**9. External Editor Integration**
- **Description:** Open file at current position in $EDITOR
- **Why it matters:** Allows quick edits while reading documentation
- **Source:** `charmbracelet_glow.md` — `ui/editor.go` with `openEditor(path, lineno)`
- **Implementation ideas:**
  - Use `candy-pty` to spawn `$EDITOR` with filename and line number
  - Add 'e' keybinding in pager mode to open editor
- **Estimated complexity:** Low — process spawning with args
- **Expected impact:** Low — niche but valued feature

**10. Git-Aware File Discovery (gitcha)**
- **Description:** Recursive markdown file search respecting .gitignore rules
- **Why it matters:** Enables discovering all docs in a repository without spurious matches
- **Source:** `charmbracelet_glow.md` — `findLocalFiles()` using `gitcha.FindFilesExcept()`
- **Implementation ideas:**
  - Port `gitcha` algorithm or use existing PHP git-aware file discovery
  - Walk directories respecting .gitignore patterns
  - Stream results via channel/generator for incremental UI updates
- **Estimated complexity:** High — non-trivial algorithm
- **Expected impact:** Medium — enables better stash browsing

### Medium Priority

**11. Per-Element Word Wrap Widths**
- **Description:** Allow different word wrap widths for different markdown elements (headings, code blocks, tables)
- **Why it matters:** Requested feature in glamour (issue #445); useful for complex documents
- **Source:** `pr_charmbracelet_glamour.md` — issue #445, multiple renderers workaround
- **Implementation ideas:**
  - Extend GlamourTheme JSON schema with per-element `wordWrap` constraints
  - Modify block stack to apply element-specific width limits
- **Estimated complexity:** Medium — style system extension
- **Expected impact:** Low — nice-to-have

**12. GitHub-Style Blockquote Alerts**
- **Description:** Support `> [!note]`, `> [!warning]` styled blockquotes
- **Why it matters:** Popular GitHub feature for documentation
- **Source:** `pr_charmbracelet_glamour.md` — issue #300
- **Implementation ideas:**
  - Add `blockquoteAlert` style keys in GlamourTheme
  - Pre-process markdown to detect and style alert blockquotes before rendering
- **Estimated complexity:** Low — markdown preprocessing
- **Expected impact:** Low — documentation-focused feature

**13. LinkStyler Interface**
- **Description:** Customizable link rendering (text-only, full URL, footer references)
- **Why it matters:** Frequently requested in glamour (issues #361, #85, #114)
- **Source:** `pr_charmbracelet_glamour.md` — LinkStyler design discussion
- **Implementation ideas:**
  - Define `LinkStyler` interface with `styleLink(string $text, ?string $url): string`
  - Implement built-in styles: `FullLinkStyler`, `TextOnlyLinkStyler`, `FooterLinkStyler`
  - Allow users to provide custom LinkStyler implementations
- **Estimated complexity:** Medium — new interface + implementations
- **Expected impact:** Medium — improves link rendering flexibility

**14. Dynamic Height TextArea**
- **Description:** Auto-growing textarea for multi-line input
- **Why it matters:** Highly requested in bubbles (PR #910, v2.1.0)
- **Source:** `pr_charmbracelet_bubbles.md` — dynamic height feature
- **Implementation ideas:**
  - Add `dynamicHeight` option to textarea-style inputs
  - Clamp between `minHeight` and `maxContentHeight`
  - Recalculate on content change
- **Estimated complexity:** Medium — sizing logic
- **Expected impact:** Low — primarily for input forms

**15. Accessible Color Modes**
- **Description:** Support terminal16 (16-color) formatter for accessibility
- **Why it matters:** GitHub CLI needed this (issue #395)
- **Source:** `pr_charmbracelet_glamour.md` — `WithChromaFormatter("terminal16")`
- **Implementation ideas:**
  - Add `syntaxFormatter` option to `ChromaJsonHighlighter`
  - Map colors to 16-color ANSI palette when requested
- **Estimated complexity:** Low — color mapping
- **Expected impact:** Low — accessibility feature

### Low Priority

**16. Template Format for All Elements**
- **Description:** Allow `{left}` / `{mid}` / `{right}` template functions in all element styles
- **Why it matters:** Requested in glamour issue #480
- **Implementation:** Not straightforward — requires style preprocessing

**17. Image Rendering (iTerm2 Inline)**
- **Description:** Render actual images using iTerm2 inline image protocol
- **Why it matters:** Requested in glamour issue #501
- **Implementation:** Would require iTerm2 protocol support

**18. Horizontal Scrolling in Viewport**
- **Description:** Left/right arrow scrolling for long lines
- **Why it matters:** Long-standing gum issue #12; upstream PR #791 blocked on bubbletea v2
- **Source:** `pr_charmbracelet_bubbles.md` — horizontal scrolling implemented in v2

---

## Algorithm / Performance Opportunities

### Current Approach: mtime Polling

`sugar-glow`'s `FileWatcher` uses `filemtime()` polling in a tight loop:

```php
// FileWatcher.php:42-63
public static function watch(string $path, int $intervalMs = 500): \Generator
{
    $lastMtime = @filemtime($path);
    while (true) {
        usleep($intervalMs * 1000);
        clearstatcache();
        $currentMtime = @filemtime($path);
        if ($currentMtime !== false && $currentMtime !== $lastMtime) {
            $lastMtime = $currentMtime;
            yield true;
        }
    }
}
```

### Upstream Approach: fsnotify / inotify

`charmbracelet/glow` uses OS-native `fsnotify` which provides:
- Immediate notification on file change
- No CPU overhead for polling
- Correct handling of rapid successive changes

### Why External is Better

OS-native watchers register with the kernel and receive push notifications. mtime polling:
- Consumes CPU every `intervalMs` regardless of file activity
- Has filesystem resolution (typically 1 second minimum)
- May miss changes that occur and are reverted between polls

### Tradeoffs

| Approach | Pros | Cons |
|---|---|---|
| mtime polling | No extensions, cross-platform, simple | 1s resolution, CPU waste, misses rapid changes |
| inotify (Linux) | Instant, zero CPU when idle | Linux-only, requires ext-inotify |
| kqueue (macOS/BSD) | Instant, zero CPU when idle | macOS/BSD only, requires ext-kqueue |
| FSEvents (macOS) | Native macOS API | macOS only, requires ext-fsevents |
| ReadDirectoryChangesW (Windows) | Native Windows API | Windows only |

### Applicability to SugarCraft

- SugarCraft's `candy-pty` could provide native watching wrappers
- Should maintain `FileWatcher` API compatibility for fallback
- Consider a `WatchingStrategy` interface for pluggable backends

---

## Architecture Improvements

### 1. Block Stack Pattern for Nested Rendering

`charmbracelet/glamour` uses a block stack during AST traversal to compute dynamic indentation and margin without global state. Each block pushes on enter and pops on exit. This is referenced in `docs/repo_map/charmbracelet_glamour.md` and `pr_charmbracelet_glamour.md`.

Sugar-glow currently delegates to `candy-shine` for rendering but lacks the element-level control glamour provides.

### 2. Two-Phase Rendering (Entering/Exiting)

Glamour's rendering uses Entering (set up state, push onto block stack) and Exiting (tear down, pop from block stack) phases. This enables correct nested element rendering.

Sugar-glow's GlowModel has a simple `update()` / `view()` pattern but doesn't have element-level rendering phases.

### 3. Cascading Style Inheritance

Glamour's `cascadeStyle()` recursively merges parent and child `StyleBlock` structs, with child values overriding parent. This is a custom merge, not CSS delegation.

Sugar-glow's `GlamourTheme` provides `resolve()` but doesn't implement cascading inheritance.

### 4. Render Result Caching

Per `pr_charmbracelet_glamour.md` issue #2223, Charm Crush had severe performance issues from recreating renderers on every call. Sugar-glow should cache render results keyed by (markdown content, theme, width) tuples.

---

## API / Developer Experience Improvements

### 1. LinkStyler Interface

Add a `LinkStyler` interface for customizable link rendering:

```php
interface LinkStyler {
    public function styleLink(string $text, ?string $url, LinkContext $ctx): string;
}
```

### 2. MarkdownPreprocessor Chain

Allow markdown transformation before rendering (e.g., `> [!note]` → styled blockquote, custom syntax extensions):

```php
class Renderer {
    public function withPreprocessor(MarkdownPreprocessor $preprocessor): self;
}
```

### 3. Configuration Builder

Replace array of constructor args with a configuration object:

```php
$config = (new GlowConfig())
    ->withTheme('tokyo-night')
    ->withWidth(80)
    ->withHyperlinks(true)
    ->withPager(true);

$glow = new Glow($config);
```

### 4. Theme Debugging Helper

Per `pr_charmbracelet_glamour.md`, cascading style inheritance is non-obvious. Add a debug utility:

```php
GlamourTheme::debugResolve($theme, 'heading1');
// Returns: "Style { fg: #aabbcc, bold: true, inherited_from: 'document' }"
```

---

## Documentation / Cookbook Opportunities

### 1. Word Wrap Edge Cases Guide

Per `pr_charmbracelet_glamour.md`, word wrapping is the #1 pain point. Document:
- CJK character handling
- Trailing punctuation preservation
- Hyphenated word breaks
- Hard line break handling (`  \n`)

### 2. Custom Theme Tutorial

Step-by-step guide for creating Glamour-style JSON themes:
- Style config structure
- Chroma token mapping
- Block prefix/suffix usage
- Testing with `Theme::fromJson()`

### 3. Live Reload Pattern

How to use `FileWatcher::watch()` in a ReactPHP loop:

```php
$loop = React\EventLoop\Loop::get();
$watcher = FileWatcher::watch('/path/to/file.md');

$loop->addPeriodicTimer(0.5, function () use ($watcher) {
    foreach ($watcher as $changed) {
        if ($changed) {
            // Re-render
        }
    }
});
```

### 4. Pager Integration Guide

How to use `GlowModel` as a standalone component:

```php
$content = file_get_contents('README.md');
$model = GlowModel::fromContent($content, 80, 24);
$program = new Program($model);
// Run with Program::run()
```

---

## UX / TUI Improvements

### 1. Status Bar Enhancement

Add scroll percentage and filename to pager footer (per `charmbracelet_glow.md` `statusBarView()`):

```
glow 47% README.md [?] (current)
```

### 2. Help Overlay

Add '?' keybinding to show keyboard shortcuts overlay (per `charmbracelet_glow.md` `helpView()`):

```
j/k, ↑/↓    Navigate
b/f, PgUp/PgDn  Page
g/G, Home/End   Top/Bottom
c          Copy to clipboard
e          Open in editor
r          Reload file
q          Quit
?          Show this help
```

### 3. Search Within Document

Add '/' keybinding to search within rendered markdown (per `pr_charmbracelet_bubbles.md` issue #157):

```php
// GlowModel update() handles:
// '/' -> activate search mode, capture query
// 'n' -> next match
// 'N' -> previous match
```

### 4. Mouse Support

Add mouse click and wheel support for navigation (per `charmbracelet_glow.md`):

- Click to position cursor
- Wheel to scroll
- Mouse events forwarded to Viewport

---

## Testing / Reliability Improvements

### 1. Snapshot Tests for Rendering

Add golden/snapshot tests for ANSI rendering output (per `charmbracelet_lipgloss.md` strength #12):

```php
public function testRenderMatchesSnapshot(): void
{
    $html = file_get_contents(__DIR__ . '/fixtures/readme.md');
    $output = $this->renderer->render($html);
    $this->assertStringEqualsFile(__DIR__ . '/snapshots/readme.ans', $output);
}
```

### 2. Word Wrap Edge Case Tests

Per `pr_charmbracelet_glamour.md` issue #505, add comprehensive tests for:
- Trailing punctuation at wrap boundaries
- CJK characters in wrapped text
- Emoji in wrapped text
- Hyphenated words

### 3. Grapheme-Aware String Tests

Per `pr_charmbracelet_gum.md`, implement `bytePosToVisibleCharPos()` and test:
- Emoji in filter items
- ANSI codes in text
- Combining characters

### 4. Escape Sequence Truncation Tests

Per `pr_charmbracelet_bubbles.md` issue #603, test that truncation works correctly with ANSI codes in content.

---

## Ecosystem / Integration Opportunities

### 1. GitHub Actions Integration

Auto-render markdown files in CI with sugar-glow, check documentation builds cleanly.

### 2. Laravel/PHP Integration

Provide a service provider for Laravel apps to render markdown in CLIs or terminal UIs.

### 3. Composer Script Integration

Add sugar-glow as a dev dependency to render CHANGELOG.md in terminal after install.

### 4. PHP-CS-Fixer Integration

Render code style documentation with syntax highlighting for PHP-CS-Fixer rules.

---

## Notable PRs / Issues / Discussions

### charmbracelet/glamour PR #406: Footer Links for Tables
**Relevance:** High — directly applicable to `sugar-charts` table rendering

When a table contains links, glamour now:
1. Walks table AST and collects all links/images during rendering
2. Renders cells with short reference numbers like `[1]`, `[2]`
3. At table end, renders footer with `[1]: https://full/url`

This solves the "link too long for table cell" problem without breaking the link.

**Lesson:** The pattern "collect during walk, render after" is directly portable to SugarCraft's table implementation.

### charmbracelet/glamour Issue #505: Word Wrap Edge Case
**Relevance:** Critical — word wrap is the #1 pain point

Trailing punctuation (`.` or `,`) at wrap boundaries gets incorrectly dropped. Root cause: `lipgloss.Wrap` uses hardcoded breakpoints `" ,.;-+|"` that don't preserve trailing punctuation.

**Lesson:** Do NOT hardcode break characters — make them configurable. Test extensively with edge cases.

### charmbracelet/glamour Issue #331/#486: Width Calculation Bugs
**Relevance:** High — width arithmetic bugs

Multiple glamour bugs stem from using multiplication instead of addition for width calculation:
```go
// Buggy: - Indent()*Margin()
return uint(ctx.options.WordWrap) - s.Indent()*s.Margin()
// Should be: - Indent() - Margin()
return uint(ctx.options.WordWrap) - s.Indent() - s.Margin()
```

**Lesson:** Always verify width calculations with edge cases: very small widths (1-5), zero indent/margin, negative effective widths (clamp to 0).

### charmbracelet/gum PR #799: Grapheme Cluster Fix
**Relevance:** High — ANSI + fuzzy matching

Fixed ANSI handling for grapheme clusters by adding `rivo/uniseg` dependency and implementing `bytePosToVisibleCharPos()`.

**Lesson:** Any time we strip ANSI, match, then re-apply, we need grapheme-aware position mapping.

### charmbracelet/bubbles PR #427: Textarea Memoization
**Relevance:** High — performance critical

Memoization reduced textarea wrap computation from 42s to 3s (93% improvement). Text wrapping is extremely expensive and must be cached aggressively.

**Lesson:** Text wrapping and similar expensive computations should be memoized keyed by (content, width) pairs.

### charmbracelet/bubbles Issue #1652: Infinite Loop
**Relevance:** High — defensive coding

Unconditional loop in `wordLeft()` caused infinite loop when textarea empty. Fix: add boundary check at top of loop.

**Lesson:** Always check boundaries before entering navigation loops. Never assume input is non-empty.

### textualize/textual Issue #6381: GC-Induced Stuttering
**Relevance:** Medium — performance at scale

MarkdownViewer with 200+ blocks created 600+ reference cycles, causing Python gen2 GC pauses (50-200ms) every ~2 seconds.

**Lesson:** For content-heavy widgets, consider virtualization or weak references to prevent GC pressure.

### charmbracelet/glamour Issue #405: Terminal Probe Race
**Relevance:** Medium — concurrency

Auto-style detection caused Lip Gloss and Glamour to fight over stdout, causing lock-ups and leakage when both probed at render time.

**Lesson:** Never probe terminal capabilities at render time. Probe once at startup, store result, pass explicitly to renderers.

---

## Recommended Roadmap

### Immediate Wins (0-2 sprints)

1. **GitHub README Fetching** — Implement `github://` protocol parsing and GitHub API v3 fetching
2. **GitLab README Fetching** — Implement `gitlab://` protocol parsing and GitLab API v4 fetching
3. **Configuration File** — YAML/JSON config with XDG paths and env var override
4. **$PAGER Fallback** — Shell out to `$PAGER` or `less -r` when not in TTY

### Medium-term Improvements (2-4 sprints)

5. **File Stash Browser** — TUI file listing with fuzzy filtering (requires fuzzy library port)
6. **Line Numbers** — Optional gutter in pager mode
7. **Clipboard Integration** — OSC 52 copy functionality
8. **External Editor** — Open file at line in $EDITOR

### Major Architectural Upgrades (4-8 sprints)

9. **Proper Syntax Lexer** — Replace regex tokenizer with proper lexer (Chroma or tree-sitter)
10. **Native File Watching** — inotify/kqueue/FSEvents backends with FileWatcher API compatibility
11. **Per-Element Word Wrap** — Element-specific width constraints via GlamourTheme
12. **Search Within Document** — '/' keybinding to search rendered content

### Experimental Ideas

13. **LinkStyler Interface** — Customizable link rendering abstraction
14. **GitHub-Style Blockquote Alerts** — Pre-process `> [!note]` syntax
15. **iTerm2 Inline Images** — Image rendering protocol support
16. **Web Assembly Port** — Run sugar-glow in browser via WASM

---

## Priority Matrix

| Opportunity | Impact | Complexity | Risk | Recommended Priority |
|---|---|---|---|---|
| GitHub README fetching | Critical | Medium | Low | **P0 — Immediate** |
| GitLab README fetching | Critical | Medium | Low | **P0 — Immediate** |
| File stash + fuzzy filter | High | High | Medium | **P1 — Medium-term** |
| Config file support | High | Low | Low | **P1 — Immediate** |
| $PAGER fallback | High | Low | Low | **P1 — Immediate** |
| Line numbers | Medium | Low | Low | **P1 — Medium-term** |
| Clipboard (OSC 52) | Medium | Low | Low | **P1 — Medium-term** |
| External editor | Medium | Low | Low | **P2 — Medium-term** |
| Native file watching | Medium | Medium | Medium | **P2 — Medium-term** |
| Proper syntax lexer | High | High | High | **P2 — Medium-term** |
| Search within document | Medium | Medium | Low | **P2 — Medium-term** |
| Per-element word wrap | Medium | Medium | Low | **P3 — Long-term** |
| LinkStyler interface | Medium | Medium | Low | **P3 — Long-term** |
| GitHub-style alerts | Low | Low | Low | **P3 — Long-term** |
| Accessible color modes | Low | Low | Low | **P3 — Long-term** |

---

## Final Strategic Assessment

Sugar-glow provides a solid foundation for Markdown rendering in PHP TUIs, with clean separation of concerns and comprehensive test coverage. However, it is currently a **partial port** of its upstream `charmbracelet/glow` — the most valuable features (GitHub/GitLab README fetching, file browsing, fuzzy filtering) are entirely absent.

The single highest-priority gap is **remote README fetching**. This is glow's defining feature and the primary reason users choose it over simple markdown renderers. Implementing this is straightforward HTTP integration and would immediately differentiate sugar-glow from other PHP markdown renderers.

The **regex-based highlighter** is a proof-of-concept that needs replacement. For production use, either the Chroma lexer should be wrapped via FFI, or a proper lexer implementation should be created. This is a medium-term investment.

The **file watching approach** (mtime polling) works cross-platform but has fundamental limitations. A native file watching abstraction with platform-specific backends (inotify on Linux, kqueue on BSD/macOS) would provide better performance and responsiveness.

Architecture-wise, the rendering pipeline correctly separates markdown parsing (League\CommonMark) from styling (candy-shine), mirroring the upstream `goldmark` + `glamour` + `lipgloss` composition. The Elm-style `GlowModel` with `update()` / `view()` and immutable state is correctly implemented.

The **Block Stack pattern** and **Two-Phase rendering** from glamour (`docs/repo_map/charmbracelet_glamour.md`) are not yet implemented. These are essential for proper nested element rendering and will become critical when implementing table rendering with footer links.

For the PHP ecosystem, sugar-glow fills an important niche as the only production-quality markdown TUI renderer. The immediate focus should be on:
1. Completing the GitHub/GitLab integration (enabling "sugar-glow github://owner/repo" workflows)
2. Adding the missing TUI pager features (line numbers, clipboard, help overlay)
3. Implementing configuration persistence

The package's position in the SugarCraft ecosystem is as a **leaf library** consuming core infrastructure (candy-core, candy-shine, sugar-bits). This means enhancements to core libraries automatically benefit sugar-glow, but sugar-glow-specific features (like GitHub fetching) require independent implementation.

**Overall verdict:** Sugar-glow is production-ready for its current feature set (single-file markdown rendering/paging) but requires significant investment to close the gap with upstream glow. The highest-ROI next step is adding GitHub/GitLab integration.
