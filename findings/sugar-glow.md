# Code Review: sugar-glow

**Library**: `sugarcraft/sugar-glow`  
**Reviewer**: Claude Code (automated review)  
**Date**: 2026-06-29

---

## Summary

Sugar-glow is a PHP port of charmbracelet/glow — a Markdown CLI viewer/pager that composes CandyShine (rendering) and SugarBits Viewport (scrolling). The library is well-structured with good test coverage, immutable patterns, and clean separation of concerns. However, several issues ranging from minor inefficiencies to architectural limitations were identified.

---

## Files Reviewed

### Source Files (`src/`)
- `Application.php` — CLI entry point (22 lines)
- `GlowModel.php` — Pager model implementing `Model` contract (68 lines)
- `GlamourTheme.php` — Glamour-style JSON theme loader (93 lines)
- `FileWatcher.php` — File watching via mtime polling (70 lines)
- `Pager.php` — Streaming pager via `IteratorAggregate` (44 lines)
- `RenderCommand.php` — Main CLI command (175 lines)
- `Lang.php` — i18n wrapper (22 lines)
- `Highlighter/Highlighter.php` — Markdown code block highlighter wrapper (60 lines)
- `Highlighter/HighlighterInterface.php` — Highlighter interface (27 lines)
- `Highlighter/ChromaJsonHighlighter.php` — Regex-based syntax highlighter (131 lines)

### Test Files (`tests/`)
- 10 test files covering all major components

---

## Issues Found

### 1. HIGH: ChromaJsonHighlighter Keyword Pattern is Dangerously Large (Line 48)

```php
'keyword' => '\b(abstract|and|array|as|break|callable|case|catch|class|clone|const|continue|declare|default|die|do|echo|else|elseif|empty|enddeclare|endfor|endforeach|endif|endswitch|endwhile|eval|exit|extends|final|finally|fn|for|foreach|function|global|goto|if|implements|include|include_once|instanceof|insteadof|interface|isset|list|match|namespace|new|or|print|private|protected|public|require|require_once|return|static|switch|throw|trait|try|unset|use|var|while|xor|yield|yield from|async|await|void|null|true|false|mixed)\b',
```

**Problem**: This is a 70+ word alternation as a single regex pattern. PCRE compiles this into an NFA with significant backtracking overhead. Pathological input could hit `pcre.backtrack_limit` or `pcre.recursion_limit`.

**Evidence**: Test at `ChromaJsonHighlighterTest.php:185-197` ("testBacktrackLimitDegradesToRaw") explicitly tests this failure mode, acknowledging the risk.

**Recommendation**: Break the keyword pattern into categories (PHP 8.x reserved words, built-in functions, etc.) or use multiple smaller alternations with possessive quantifiers where possible.

---

### 2. MEDIUM: Inefficient Named Group Detection in ChromaJsonHighlighter (Lines 92-111)

```php
foreach ($matches as $type => $value) {
    if (!is_string($type) || $value === '' || $value !== $fullMatch) {
        continue;
    }
    // ...
}
```

**Problem**: After `preg_replace_callback` with named groups, `$matches` contains BOTH numeric indices (0, 1, 2...) AND named string indices. For every single match, this loop iterates through ALL groups to find which one captured the full match. With 7 token types, this means ~7 iterations per match.

**Impact**: Performance degrades linearly with number of matches. For large code blocks, this is significant.

**Recommendation**: Use `array_filter` to find named groups that match `$fullMatch`, or pre-compute an index mapping from group name to the string value in `$matches`.

---

### 3. MEDIUM: ChromaJsonHighlighter::supports() Ignores Language Argument (Lines 125-130)

```php
public function supports(string $language): bool
{
    // Supports any language except empty-string (no language specified)
    // and the generic 'text' sentinel (plain text, not to be highlighted).
    return $language !== '' && $language !== 'text';
}
```

**Problem**: Despite accepting a `$language` parameter, the implementation returns `true` for ANY non-empty, non-"text" language. This makes the `HighlighterInterface` contract misleading. A consumer cannot ask "does this highlighter support PHP?" — it always says yes.

**Recommendation**: Either:
- Implement actual language support detection based on the ChromaJsonHighlighter's capabilities
- Remove the parameter and update the interface contract to remove the parameter

---

### 4. MEDIUM: FileWatcher::watch() Is an Infinite Blocking Generator (Line 45-69)

```php
public static function watch(string $path, int $intervalMs = 500): \Generator
{
    // ...
    while (true) {
        usleep($intervalMs * 1000);
        // ...
        yield true;
    }
}
```

**Problem**: This is a synchronous infinite loop with `usleep()`. In a ReactPHP async context, this will block the event loop entirely. The CALIBER_LEARNINGS.md correctly notes this: "FileWatcher::watch() is a Generator that runs indefinitely. Always consume it inside a loop with a termination condition or stream context cancellation."

**Recommendation**: Consider providing an async-compatible version using `candy-async`'s event loop integration. Alternatively, document the blocking nature prominently and suggest use in worker processes or separate threads.

---

### 5. LOW: Pager Yields Nothing for Empty Streams (Lines 34-36)

```php
if ($buffer !== []) {
    yield $buffer;
}
```

**Problem**: If a stream is empty or has no trailing content to flush the final chunk, `Pager` yields nothing. A consumer calling `iterator_to_array($pager)` would receive an empty array with no indication of whether the stream was empty vs. empty-vs-seekable-vs-closed.

**Impact**: `PagerTest.php:15-23` ("testEmptyStreamYieldsNothing") shows this is intentional behavior — but it makes it impossible to distinguish "empty file" from "file with lines that happen to divide evenly into chunks."

**Recommendation**: Consider yielding a final empty chunk or having a method to report stream status.

---

### 6. LOW: GlamourTheme Silently Returns Empty Theme on Invalid JSON (Lines 38-40, 56-61)

```php
if (!is_array($data)) {
    return new self();
}
```

**Problem**: When `fromJson()` receives invalid JSON, it returns a default empty theme silently. Callers have no way to know if parsing failed unless they explicitly check `$theme->chroma !== []`.

**Evidence**: `GlamourThemeTest.php:49-58` explicitly tests that invalid JSON returns defaults — so this is documented behavior. But it could mask configuration errors in production.

**Recommendation**: Consider adding a `bool $throws = false` parameter to `fromJson()` and `fromFile()` to allow callers to opt-in to validation failure exceptions.

---

### 7. LOW: RenderCommand::loadInput() Returns Null for Empty TTY Stdin (Lines 160-165)

```php
if (!defined('STDIN') || !is_resource($stream) || TtyDetect::isAtty($stream)) {
    return null;
}
$raw = stream_get_contents($stream);
return is_string($raw) && $raw !== '' ? $raw : null;
```

**Problem**: The logic returns `null` when:
1. STDIN is a TTY (interactive terminal)
2. The stream is empty
3. The stream read returns empty string

All three cases return the same `null` value, making it impossible for callers to distinguish "user declined to provide input" from "file not found" from "empty stdin pipe."

**Recommendation**: Consider a more specific return type or exception for different failure modes.

---

### 8. LOW: Static Callback in ChromaJsonHighlighter Cannot Access $this (Line 90-92)

```php
$result = preg_replace_callback(
    $pattern,
    static function (array $matches) use ($theme): string {
```

**Problem**: The callback is `static`, which means it cannot access `$this` or use `$this->theme`. This is correct in this case (uses `$theme` from `use`), but it could be confusing if someone extends this class and tries to access instance state.

**Recommendation**: Add a comment clarifying why `static` is used and that any refactoring must preserve this.

---

### 9. LOW: Missing Tests for GlowModel Viewport Integration

**Problem**: `GlowModelTest.php` tests individual key handling (q, Esc, Ctrl+C, Down) but doesn't test:
- Scrolling to bottom (End/G key)
- Page up behavior
- Half-page scroll (Ctrl+U/D)
- Home key
- What happens when content fits entirely within viewport (no scrolling needed)

**Recommendation**: Add integration tests for all Viewport key bindings.

---

### 10. INFO: ChromaJsonHighlighter Acknowledges Regex Limitation (Lines 16-18)

```php
/**
 * Chroma-inspired JSON theme highlighter.
 *
 * Uses a JSON theme file (array of token-type => SGR color mappings) and
 * regex-based tokenization to apply syntax highlighting. This is a simplified
 * proof-of-concept; real tokenization requires a proper lexer (Pygments/Scrivener).
 */
```

**Observation**: The library itself acknowledges this is a simplified implementation. For production use with complex code, a proper lexer (via FFI to Pygments or native PHP lexer) would be needed.

---

## Performance Considerations

### Optimizations Already Present

1. **Pager uses Generator streaming** — Good for memory efficiency with large files
2. **FileWatcher uses (mtime, size) tuple** — Catches same-second edits
3. **clearstatcache() called appropriately** — In both `hasChangedSince()` and `watch()`
4. **Regex pattern built once in constructor** — `combinedPattern` is cached

### Potential Bottlenecks

1. **ChromaJsonHighlighter** — Regex matching with large alternations (see Issue #1)
2. **Rendering entire file before paging** — `GlowModel::fromContent()` renders all markdown before the pager receives it. For very large files, this could use significant memory.
3. **foreach named group lookup** — O(n) per match where n = number of token types (see Issue #2)

---

## Security Considerations

### Good: ESC Byte Stripping (Line 104)

```php
$value = str_replace("\x1b", '', $value);
```

This prevents terminal control-code injection in highlighted code.

### Good: No eval() or Dynamic Code Execution

The library processes markdown and code for display only.

### Good: Error Handling in Terminal Probe (Lines 135-147)

```php
try {
    // ...
    $report = TerminalProbe::run();
    return !$report->has(Capability::NoColor);
} catch (\Throwable) {
    return true; // Fall back to assuming color is available.
}
```

Graceful degradation prevents broken probes from crashing the application.

### No Obvious Security Issues

The library appears security-conscious. No user-provided data is passed to `exec()`, `system()`, or similar. File paths are not passed to shell commands.

---

## Architecture & Design Patterns

### Positive Patterns

1. **Immutable GlowModel** — Uses private constructor + factory methods, returns new instances on state changes
2. **Dependency Injection for Test Seams** — `RenderCommand::$colorProbeCallback` allows test injection
3. **Interface Segregation** — `HighlighterInterface` allows pluggable highlighters
4. **Method Chaining** — `Renderer::withWordWrap()` and `withHyperlinks()` return new instances
5. **Composition over Inheritance** — `GlowModel` composes `Viewport` rather than extending it

### Concerns

1. **Highlighter.new() Hardcodes ChromaJsonHighlighter** (Line 19-28) — If someone calls `Highlighter::new()` expecting a general-purpose highlighter, they get a PHP-specific regex highlighter with no warning.
2. **RenderCommand is a God Class** — Handles input loading, theme selection, color probing, rendering, and pager orchestration. Some of this could be extracted to separate services.

---

## Compatibility with SugarCraft Ecosystem

### Proper Integration Points

1. Uses `SugarCraft\Core\Model` contract for GlowModel
2. Uses `SugarCraft\Bits\Viewport\Viewport` for scrolling
3. Uses `SugarCraft\Shine\Renderer` and `Theme` for markdown rendering
4. Uses `SugarCraft\Core\Util\TtyDetect` for TTY detection
5. Uses `SugarCraft\Core\Util\Tty` for terminal size
6. Uses `SugarCraft\Palette\Probe\TerminalProbe` for color capability probing
7. Uses `SugarCraft\Testing\Snapshot\Assertions::assertGoldenAnsi()` for snapshot tests

### No Obvious Compatibility Issues

The library correctly uses path repositories for all SugarCraft dependencies in `composer.json`.

---

## Async Patterns (ReactPHP)

### Current State

The library is **synchronous** and blocking. The `FileWatcher::watch()` method is a generator that runs a blocking `while(true)` loop with `usleep()`.

### Opportunities for Async Improvement

1. **FileWatcher** could use `candy-async`'s event loop with async file watching (e.g., inotify/Darwin FSEvents integration, or poll via async `setInterval()`)
2. **Pager** could be made async-aware for streaming large files in non-blocking fashion
3. **RenderCommand in pager mode** uses `Program::run()` which is blocking. For true async, this would need to yield control back to an event loop

### Recommendation

Since the library is CLI-based (not a long-running server), the blocking behavior is likely acceptable for the primary use case. Document the blocking nature clearly if async use is unsupported.

---

## Missing Features

1. **Theme validation** — No way to validate a theme JSON file before loading
2. **Paged rendering** — Markdown is fully rendered before the pager sees it; streaming markdown parsing would reduce memory for large files
3. **Search within pager** — Upstream glow supports `/` for searching
4. **Hyperlink navigation in pager** — Clickable OSC 8 links in pager mode (currently only stdout rendering respects `--no-hyperlinks`)
5. **Mouse support** — Scroll via mouse wheel in pager mode
6. **Custom key bindings** — No way to remap pager keys

---

## Code Quality

### Strengths

- `declare(strict_types=1)` on all files
- PSR-12 compliant formatting likely
- `final` classes throughout
- Readonly properties for immutability
- Comprehensive docblocks with `@see` references
- Tests cover edge cases including graceful degradation

### Minor Issues

- `RenderCommand::pickTheme()` at line 109 uses `strtolower(str_replace('_', '-', $name))` — could be extracted to a helper method
- The inline generator in `Pager::__construct()` mixes stream reading with chunking logic; a named private method would improve readability

---

## Recommendations Summary

| Priority | Issue | File:Line |
|----------|-------|-----------|
| HIGH | Keyword regex backtrack risk | `ChromaJsonHighlighter.php:48` |
| MEDIUM | Inefficient named group lookup | `ChromaJsonHighlighter.php:97-100` |
| MEDIUM | `supports()` ignores language | `HighlighterInterface.php:26` / `ChromaJsonHighlighter.php:125-130` |
| MEDIUM | Blocking FileWatcher::watch() | `FileWatcher.php:57` |
| LOW | Pager empty stream ambiguity | `Pager.php:34-36` |
| LOW | Silent theme parse failure | `GlamourTheme.php:38-40` |
| LOW | loadInput() null ambiguity | `RenderCommand.php:160-165` |
| LOW | Missing Viewport key tests | `GlowModelTest.php` |

---

## Test Coverage Assessment

**Overall: Good**

- 87 tests covering all major components
- Golden file snapshots for ANSI rendering
- Edge case coverage (probe failures, empty streams, backtrack limits)
- Integration points properly mocked

**Gaps:**
- Full pager key binding coverage (End, Home, PgUp, PgDn, Ctrl+U/D)
- Integration test for pager mode with actual `Program::run()`
- Performance tests for large file handling

---

*This review was generated by automated code analysis. Manual verification of all findings is recommended before acting on any recommendation.*
