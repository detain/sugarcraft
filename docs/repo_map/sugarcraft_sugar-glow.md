# SugarCraft SugarGlow

## Metadata
- **URL:** https://github.com/sugarcraft/sugar-glow
- **Subdir:** `sugar-glow/`
- **Composer pkg:** `sugarcraft/sugar-glow`
- **Namespace:** `SugarCraft\Glow`
- **Status:** 🟢 v1 ready
- **Upstream:** [charmbracelet/glow](https://github.com/charmbracelet/glow)
- **PHP:** ^8.3
- **Entry point:** `bin/sugarglow`
- **i18n:** 15 locales (en, fr, de, es, pt, pt-br, zh-cn, zh-tw, ja, ru, it, ko, pl, nl, tr, cs, ar)

## Feature List

- **Markdown Rendering:** Composes `candy-shine` (Renderer/Theme) for ANSI-escaped styled output
- **Dual-Mode CLI:** Direct stdout rendering (default) or fullscreen pager via `GlowModel` + `Viewport`
- **8 Built-in Themes:** ansi, plain, dark, light, notty, dracula, tokyo-night, pink
- **3 JSON Themes:** solarized, monokai, github (loaded from `themes/` directory)
- **Custom Theme Loading:** `--theme-config` flag loads arbitrary JSON theme files via `Theme::fromJson`
- **Word Wrap:** Configurable width with `--width` / `-w`; 0 disables wrapping
- **OSC 8 Hyperlinks:** Enabled by default; `--no-hyperlinks` renders links as `text (url)`
- **Pager Mode:** `-p` / `--pager` opens a `GlowModel` backed Viewport with standard reader keys
- **Pager Keys:** ↑/↓/j/k (scroll), PgUp/PgDn/b/f (page), Ctrl+U/D (half-page), Home/g/End/G (bounds), q/Esc/Ctrl+C (exit)
- **Syntax Highlighting:** Regex-based `ChromaJsonHighlighter` for code blocks using named-group tokenization
- **File Watching:** `FileWatcher` uses mtime polling (cross-platform, no extension required)
- **GlamourTheme Utility:** Parses Glamour-style JSON (block_prefix/suffix, indent_token, margin, chroma map)
- **TTY Auto-Detection:** Stdin read skipped when TTY is detected (prevents empty reads)
- **Width Helpers:** CJK/emoji visual width via `SugarCraft\Core\Util\Width`
- **Streaming Pager:** `Pager` class yields chunks from streams for large file handling
- **i18n:** Full translation infrastructure with 15 locales via `Lang` facade

## Source Tree

```
sugar-glow/
├── bin/sugarglow                  # CLI entry point (20 lines)
├── composer.json                  # deps: candy-core, candy-shine, sugar-bits, symfony/console
├── phpunit.xml                    # colors=true, failOnWarning=true, cacheDirectory=.phpunit.cache
├── README.md                      # CLI usage, library API, VHS demo links
├── CALIBER_LEARNINGS.md           # WidthHelper mb_strwidth, FileWatcher Generator gotcha
├── src/
│   ├── Application.php             # Symfony Console app, registers RenderCommand as default
│   ├── RenderCommand.php          # Main command: loadInput, pickTheme, execute (render/pager)
│   ├── GlowModel.php               # Bubble Tea-style Model: Viewport-backed pager state machine
│   ├── Pager.php                   # Streaming chunk reader (IteratorAggregate)
│   ├── FileWatcher.php             # mtime-polling Generator watch loop
│   ├── GlamourTheme.php           # JSON theme loader (block_prefix/suffix, indent_token, margin, chroma)
│   ├── Lang.php                    # i18n facade (namespace='glow', extends Core\I18n\Lang)
│   └── Highlighter/
│       ├── Highlighter.php         # Markdown code-block highlighter wrapper
│       ├── HighlighterInterface.php # highlight(string, language): string + supports(language): bool
│       └── ChromaJsonHighlighter.php # Regex-based tokenizer (comment/string/keyword/number/function/operator)
├── themes/
│   ├── solarized.json             # Solarized Light color scheme
│   ├── monokai.json                # Monokai color scheme
│   └── github.json                 # GitHub color scheme
├── lang/
│   ├── en.php                      # Default (source of truth)
│   ├── fr.php, de.php, es.php, pt.php, pt-br.php, zh-cn.php, zh-tw.php,
│   │   ja.php, ru.php, it.php, ko.php, pl.php, nl.php, tr.php, cs.php, ar.php
│   └── (15 locales total per LOCALES.md recommended set)
├── tests/
│   ├── ApplicationTest.php         # Symfony app registration, name/version
│   ├── RenderCommandTest.php       # pickTheme (11 themes), loadInput, execute (8 scenarios)
│   ├── GlowModelTest.php            # Viewport init, q/Esc/Ctrl+C exit, Down scroll, post-exit ignore
│   ├── PagerTest.php               # Empty/single/chunked streaming, IteratorAggregate contract
│   ├── FileWatcherTest.php         # hasChangedSince (non-existent/modified/deleted), constructor
│   ├── GlamourThemeTest.php        # fromJson/fromFile (valid/invalid/missing), resolve()
│   ├── ThemeLoadTest.php            # solarized/monokai/github JSON load
│   └── Highlighter/
│       ├── HighlighterTest.php      # highlightMarkdown (PHP/JS/multi-block/empty/no-lang)
│       └── ChromaJsonHighlighterTest.php # highlight (empty/unchanged/theme colors, PHP code)
└── .vhs/
    ├── render.tape                  # VHS script: 800x480, TokyoNight, Type "php examples/render-readme.sh"
    ├── render.gif                   # CI-rendered demo (98KB)
    ├── pager.tape
    └── pager.gif                    # CI-rendered demo (66KB)
```

## Architecture

### Markdown Rendering Pipeline

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

The rendering pipeline uses `SugarCraft\Shine\Renderer` which wraps the League CommonMark parser with candy-shine's ANSI styling engine. The `Renderer` applies word-wrap and hyperlink styling based on configuration.

### GlowModel (Pager State Machine)

`GlowModel` implements the `Model` contract (init/update/view/subscriptions) and wraps a `Viewport` for scroll management. It handles:
- **Exit signals:** q (char), Esc, Ctrl+C → returns `Cmd::quit()`
- **Viewport delegation:** All scroll/mouse messages forwarded to `Viewport`
- **Immutable:** `update()` returns `[new GlowModel(...), ?Cmd]`

```php
// GlowModel.php:22-26
public static function fromContent(string $content, int $width = 80, int $height = 24): self
{
    $vp = Viewport::new($width, max(1, $height))->setContent($content);
    return new self($vp, false);
}
```

### ChromaJsonHighlighter Tokenization

`ChromaJsonHighlighter` builds a combined regex pattern with named groups for each token type:

```php
// ChromaJsonHighlighter.php:40-65
$orderedTypes = [
    'comment'     => "/(\/\*[\s\S]*?\*\/|\/\/[^\n]*|#.*$)/",
    'string'      => '/"[^"]*"|' . "'" . '[^' . "'" . ']*' . "'" . '/',
    'keyword'     => "/\b(abstract|and|array|...)\b/",  // PHP keywords
    'number'      => "/\b\d+\.?\d*\b/",
    'function'   => "/\b([a-zA-Z_]\w*)\s*\(/",
    'operator'   => '/[+\-*\/%=<>!&|^~]+/',
    'punctuation' => '/[{}()\[\];,\.]/',
];
```

The `highlight()` callback identifies which named group matched and applies the corresponding SGR color from the theme.

### FileWatcher Polling

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

Uses `filemtime()` polling — no extension required. Resolution is filesystem-dependent (typically 1 second).

### Theme Selection

`RenderCommand::pickTheme()` maps string names to Theme instances:

| Name(s) | Source |
|---------|--------|
| `''`, `ansi` | `Theme::ansi()` |
| `plain`, `no` | `Theme::plain()` |
| `dark` | `Theme::dark()` |
| `light` | `Theme::light()` |
| `notty`, `auto-no` | `Theme::notty()` |
| `dracula` | `Theme::dracula()` |
| `tokyo-night`, `tokyonight` | `Theme::tokyoNight()` |
| `pink` | `Theme::pink()` |
| `solarized` | `Theme::fromJson(__DIR__.'/../themes/solarized.json')` |
| `monokai` | `Theme::fromJson(__DIR__.'/../themes/monokai.json')` |
| `github` | `Theme::fromJson(__DIR__.'/../themes/github.json')` |

## Key Dependencies

| Package | Role |
|---------|------|
| `sugarcraft/candy-core` | `Viewport`, `Model`, `Cmd`, `Msg`, `Program`, `TtyDetect`, `Width`, `Ansi` |
| `sugarcraft/candy-shine` | `Renderer`, `Theme` (ANSI text styling engine) |
| `sugarcraft/sugar-bits` | Markdown parsing (League CommonMark integration) |
| `symfony/console` | `Application`, `Command`, `InputInterface`, `OutputInterface` |

## Comparison with Upstream (charmbracelet/glow)

| Feature | charmbracelet/glow (Go) | sugar-glow (PHP) |
|--------|----------------------|-----------------|
| **Markdown rendering** | glamour (goldmark-based) | Renderer via candy-shine (League CommonMark) |
| **TUI framework** | Bubble Tea (bubbletea) | Custom GlowModel + Program (ReactPHP-based) |
| **Pager** | bubbles/viewport | SugarCraft\Bits\Viewport |
| **File discovery** | gitcha (git-aware recursive search) | Not implemented |
| **Fuzzy filtering** | sahilm/fuzzy (real-time) | Not implemented |
| **GitHub README fetch** | GitHub API v3 | Not implemented |
| **GitLab README fetch** | GitLab API v4 | Not implemented |
| **File watching** | fsnotify (OS-native) | FileWatcher (mtime polling) |
| **Clipboard (OSC 52)** | Yes | Not implemented in RenderCommand |
| **External editor** | charmbracelet/x/editor ($EDITOR) | Not implemented |
| **Stash (file browser)** | Yes (stash model) | Not implemented |
| **Config file** | Viper YAML | Not implemented |
| **Line numbers** | Yes (optional) | Not implemented |
| **Pager selection** | $PAGER / less -r fallback | Not implemented |
| **Live reload** | fsnotify write/create events | FileWatcher Generator (manual integration) |
| **Syntax highlighting** | Chroma (full lexer) | ChromaJsonHighlighter (regex tokenizer) |
| **Style auto-detect** | Lipgloss.HasDarkBackground() | TtyDetect (theme selection manual) |
| **Word wrap** | Yes (configurable) | Yes (via Renderer::withWordWrap) |
| **OSC 8 hyperlinks** | Yes | Yes (via Renderer::withHyperlinks) |
| **Themes** | 6 glamour styles | 8 built-in + 3 JSON (solarized/monokai/github) |

### Gaps Analysis

1. **No GitHub/GitLab README fetching** — The most distinctive glow feature (fetching remote READMEs) is missing. This would require HTTP client integration and URL parsing for `github://` and `gitlab://` protocol prefixes.

2. **No file stash/browser** — glow's TUI has a full file browser with fuzzy filtering, spinner, and paginator. Sugar-glow only has single-file rendering/pager mode.

3. **No fuzzy search** — sahilm/fuzzy is not ported; file filtering is not available.

4. **No gitcha file discovery** — git-aware recursive file search is missing.

5. **No clipboard integration** — OSC 52 clipboard support is not wired into RenderCommand.

6. **No external editor** — Opening files in $EDITOR at current position is not implemented.

7. **No configuration file** — Viper-based YAML config with env var overrides is not implemented.

8. **Regex highlighter vs Chroma** — The `ChromaJsonHighlighter` uses a proof-of-concept regex tokenizer; upstream Chroma uses a full lexer with proper tokenization.

9. **mtime polling vs fsnotify** — FileWatcher uses `filemtime()` polling which has 1-second granularity and cannot detect rapid changes. Upstream uses OS-native fsnotify.

10. **No $PAGER integration** — Glow respects $PAGER environment variable; sugar-glow only has built-in pager mode.

## Comparison with Related Packages

### glamour (charmbracelet/glamour)

Glamour is the markdown rendering engine used by glow. Key differences:

| Aspect | glamour | sugar-glow |
|--------|---------|------------|
| **Parser** | goldmark (Go) | League\CommonMark (PHP) |
| **Style system** | JSON stylesheets with cascading | JSON via Theme + GlamourTheme |
| **Block stack** | Yes (nested block margin/indent tracking) | Delegated to candy-shine |
| **Chroma integration** | Full lexer for syntax highlighting | Regex tokenizer |
| **Custom writers** | MarginWriter, PaddingWriter, IndentWriter | Via candy-shine Renderer |
| **GFM support** | Full (tables, task lists, strikethrough, emoji) | Via League CommonMark |
| **OSC 8 hyperlinks** | Yes (FNV-32a hash for URL ID) | Via Renderer::withHyperlinks |

### gum (charmbracelet/gum)

Gum provides shell helpers including a pager subcommand. gum's pager (`gum pager --file`) is a simpler scroll viewer without markdown rendering. Sugar-glow's pager integrates with the full markdown rendering pipeline.

### Bubble Tea (charmbracelet/bubbletea)

The TUI framework underlying glow. Sugar-glow implements a simplified MVC pattern (Model/update/view/subscriptions) that mirrors Bubble Tea's tea.Model interface but using ReactPHP for async event handling rather than Go channels.

## Notable Patterns

### Immutable Model with Fluent Viewport Updates

`GlowModel` maintains immutable state while delegating scroll state to the `Viewport`:

```php
// GlowModel.php:53-54
[$next, $cmd] = $this->viewport->update($msg);
return [new self($next, false), $cmd];
```

The `Viewport` itself is mutable during its `update()` call, then wrapped in a new `GlowModel` instance.

### Generator-based File Watching

`FileWatcher::watch()` is a lazy `Generator` that blocks in `usleep()` between polls. It must be consumed inside a coroutine dispatcher or loop with cancellation:

```php
// CALIBER_LEARNINGS.md:pattern:glow
// FileWatcher::watch() is a Generator that runs indefinitely. Always consume
// it inside a loop with a termination condition or stream context cancellation.
```

### Theme Inheritance via JSON

`Theme::fromJson()` loads a flat JSON object where keys like `heading1`, `paragraph`, `code` map to style definitions with foreground, background, bold, italic, underline, strike attributes.

### Width-Aware Truncation

`SugarCraft\Core\Util\Width` provides CJK/emoji-aware measurement using `mb_strwidth()`:

```php
Width::string('你好');   // 4 (full-width CJK = 2 columns each)
Width::string('📦');    // 2 (emoji = 2 columns)
Width::truncate('hello world', 8);  // "hello wo"
```

This is critical for correct visual rendering since `strlen()` counts code units, not visual columns.

## Test Coverage

| Test File | Coverage |
|----------|----------|
| ApplicationTest | Symfony app registration, name/version, default command |
| RenderCommandTest | pickTheme (11 themes + case/underscore variants), loadInput, execute (8 scenarios) |
| GlowModelTest | Viewport init, q/Esc/Ctrl+C exit, Down scroll, post-exit ignore |
| PagerTest | Empty/single/chunked streaming, IteratorAggregate contract |
| FileWatcherTest | hasChangedSince (non-existent/modified/deleted), constructor path storage |
| GlamourThemeTest | fromJson/fromFile (valid/invalid/missing), resolve() (known/unknown/empty) |
| ThemeLoadTest | solarized/monokai/github JSON load |
| HighlighterTest | highlightMarkdown (PHP/JS/multi-block/empty/no-lang), withHighlighter() |
| ChromaJsonHighlighterTest | highlight (empty/unchanged/theme colors), fromJsonFile, supports() |

## Strengths

1. **Clean separation of concerns** — Rendering (candy-shine), TUI (GlowModel+Viewport), CLI (Symfony) are cleanly layered
2. **Immutable model pattern** — GlowModel follows the functional update pattern correctly
3. **Cross-platform file watching** — mtime polling works everywhere without extensions
4. **Theme flexibility** — 11 built-in themes + custom JSON loading + GlamourTheme compatibility
5. **i18n infrastructure** — Full 15-locale support with Lang facade
6. **Width-aware rendering** — CJK/emoji handled correctly via Width utility
7. **OSC 8 hyperlink support** — Modern terminal feature supported via Renderer option
8. **VHS demo infrastructure** — render.gif and pager.gif demonstrate both modes
9. **PHPUnit 10 testing** — Modern test structure with failOnWarning enabled
10. **Streaming pager** — Pager class handles large files without full memory load

## Weaknesses

1. **No GitHub/GitLab integration** — Cannot fetch remote READMEs (glow's killer feature)
2. **No file browser/stash** — Can only view one file at a time
3. **No fuzzy filtering** — Cannot search/filter within a directory of markdown files
4. **Regex highlighter is proof-of-concept** — Not a proper lexer; malformed code breaks tokenization
5. **mtime polling has 1-second resolution** — Cannot detect changes faster than filesystem resolution
6. **No clipboard support** — OSC 52 not integrated
7. **No $PAGER fallback** — Only built-in pager mode
8. **No line numbers** — Not available in pager or stdout mode
9. **No config file** — Cannot persist user preferences
10. **No external editor integration** — Cannot open files in $EDITOR
11. **No git-aware file discovery** — Recursive search ignores .gitignore

## SugarCraft Library Role

`sugar-glow` is a **leaf library** that consumes the core TUI infrastructure:

```
candy-core (Viewport, Model, Cmd, Program, Tty, Width, Ansi)
    ↓
sugar-bits (MarkdownRenderer via League\CommonMark)
    ↓
candy-shine (Renderer, Theme)
    ↓
sugar-glow (RenderCommand, GlowModel, FileWatcher, GlamourTheme)
```

Dependencies:
- `sugarcraft/candy-core` — Viewport, Model, Program, TtyDetect, Width, Ansi
- `sugarcraft/candy-shine` — Renderer, Theme
- `sugarcraft/sugar-bits` — MarkdownRenderer
- `symfony/console` — Application, Command

Path repos in `composer.json`:
- candy-core, candy-shine, sugar-bits, candy-pty, candy-sprinkles, candy-forms, candy-zone, honey-bounce, candy-palette

## Potential Enhancements

1. **GitHub API integration** — Add `fetchGitHubREADME(string $repo): ?string` using HTTP client
2. **GitLab API v4 integration** — Add `fetchGitLabREADME(string $project): ?string`
3. **URL protocol support** — Parse `github://` and `gitlab://` prefixes in file arguments
4. **File stash browser** — Implement stash model with file discovery and fuzzy filtering
5. **gitcha port** — Port git-aware file discovery respecting .gitignore
6. **Full Chroma lexer** — Replace regex tokenizer with proper lexer for robust syntax highlighting
7. **fsnotify extension** — Use `inotify` (Linux) / `FSEvents` (macOS) / ReadDirectoryChangesW (Windows) for native file watching
8. **OSC 52 clipboard** — Integrate clipboard copy on 'c' key in pager mode
9. **$PAGER fallback** — Respect $PAGER environment variable and shell out when not in TTY
10. **Line numbers** — Add optional line number display in Viewport
11. **Config file** — YAML or JSON config in XDG_CONFIG_HOME with env var overrides
12. **External editor** — Open file at current scroll position in $EDITOR
