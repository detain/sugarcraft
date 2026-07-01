# Implementation Plan: sugar-glow

**Library:** `sugarcraft/sugar-glow`  
**Date:** 2026-06-30  
**Source:** Findings file `/home/sites/sugarcraft/findings/sugar-glow.md`

---

## Goal

Fix all 10 issues identified in the sugar-glow code review including HIGH-priority regex optimization, MEDIUM-priority API improvements, LOW-priority test coverage and error handling, and documentation improvements.

---

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Break keyword regex into categories | A 70+ word alternation creates NFA backtracking risk; splitting into smaller alternations reduces backtracking overhead | `sugar-glow.md:38-46` |
| Use array_filter for named group lookup | O(n) per match iteration where n=7 token types; array_filter with array_search is more efficient | `sugar-glow.md:50-66` |
| Add `$throws` parameter to GlamourTheme | Silent failures mask configuration errors in production; opt-in exceptions improve debuggability | `sugar-glow.md:124-137` |
| Add explanatory comment to static callback | Future maintainers may incorrectly refactor to non-static, breaking the highlighter's thread-safety | `sugar-glow.md:161-172` |
| Document blocking nature of FileWatcher | CALIBER_LEARNINGS already captures this pattern (pattern:glow); code itself should document it | `sugar-glow.md:88-105`, `CALIBER_LEARNINGS.md:7` |
| Add comprehensive Viewport key tests | Missing test coverage for PageUp/PageDown/Home/End/Ctrl+U/Ctrl+D bindings in GlowModelTest | `sugar-glow.md:175-185` |
| Implement supports() properly or simplify interface | The current implementation returns true for ANY non-empty/non-text language, making the contract misleading | `sugar-glow.md:69-86` |
| Consider Pager empty stream handling | Cannot distinguish "empty file" from "file that divides evenly into chunks" | `sugar-glow.md:108-121` |
| Improve loadInput() return value clarity | All failure modes (TTY, empty, not-found) return null; impossible to distinguish them | `sugar-glow.md:140-158` |

---

## Phase 1: High & Medium Priority Fixes [PENDING]

### 1.1 [HIGH] Refactor ChromaJsonHighlighter keyword pattern into categories

**File:** `sugar-glow/src/Highlighter/ChromaJsonHighlighter.php:48`

**What is expected:**
Replace the single 70+ word alternation with multiple smaller alternations grouped by category:
- PHP 8.x reserved words (primary keywords)
- Built-in types and constants (`async|await|void|null|true|false|mixed`)

**Why:**
Pathological input could hit `pcre.backtrack_limit` or `pcre.recursion_limit`. The existing test at `ChromaJsonHighlighterTest.php:185-197` ("testBacktrackLimitDegradesToRaw") explicitly acknowledges this risk and tests graceful degradation, but the root cause (excessive alternation) should be addressed.

**Severity:** HIGH

**Conditions for success:**
- All existing tests pass
- No regression in keyword highlighting coverage for PHP code
- Performance test with pathological input completes without backtrack errors
- The combined pattern still compiles and matches correctly

**Implementation notes:**
The current pattern at line 48:
```php
'keyword' => '\b(abstract|and|array|as|break|callable|case|catch|class|clone|const|continue|declare|default|die|do|echo|else|elseif|empty|enddeclare|endfor|endforeach|endif|endswitch|endwhile|eval|exit|extends|final|finally|fn|for|foreach|function|global|goto|if|implements|include|include_once|instanceof|insteadof|interface|isset|list|match|namespace|new|or|print|private|protected|public|require|require_once|return|static|switch|throw|trait|try|unset|use|var|while|xor|yield|yield from|async|await|void|null|true|false|mixed)\b',
```

Should be split into:
```php
'keyword'   => '\b(abstract|and|array|as|break|callable|case|catch|class|clone|const|continue|declare|default|die|do|echo|else|elseif|empty|enddeclare|endfor|endforeach|endif|endswitch|endwhile|eval|exit|extends|final|finally|fn|for|foreach|function|global|goto|if|implements|include|include_once|instanceof|insteadof|interface|isset|list|match|namespace|new|or|print|private|protected|public|require|require_once|return|static|switch|throw|trait|try|unset|use|var|while|xor|yield|yield from)\b',
'constant' => '\b(async|await|void|null|true|false|mixed)\b',
```

**Related code locations:**
- `sugar-glow/src/Highlighter/ChromaJsonHighlighter.php:48` (keyword pattern)
- `sugar-glow/src/Highlighter/ChromaJsonHighlighter.php:40-62` (buildCombinedPattern method)
- `sugar-glow/tests/Highlighter/ChromaJsonHighlighterTest.php:50-58` (existing keyword test)
- `sugar-glow/tests/Highlighter/ChromaJsonHighlighterTest.php:185-197` (backtrack limit test)

**Investigation notes:**
- Examined the full `ChromaJsonHighlighter.php` source (131 lines)
- The `buildCombinedPattern()` method at lines 40-62 builds the combined regex
- `orderedTypes` array defines all token types with their regex patterns
- The keyword pattern is the longest at 70+ words
- Viewport key bindings confirmed in `candy-forms/src/Viewport/Viewport.php:98-128` - supports Up/k, Down/j, Left/h, Right/l, PageUp/b, PageDown/space/f, Ctrl+U, Ctrl+D, Home/g, End/G

---

### 1.2 [MEDIUM] Optimize named group detection in ChromaJsonHighlighter

**File:** `sugar-glow/src/Highlighter/ChromaJsonHighlighter.php:92-111`

**What is expected:**
Replace the foreach loop that iterates through all matches with `array_filter` + `array_search` to find the named group that captured the full match. This eliminates ~7 iterations per match.

**Why:**
After `preg_replace_callback` with named groups, `$matches` contains BOTH numeric indices (0, 1, 2...) AND named string indices. For every single match, the current loop iterates through ALL groups to find which one captured the full match. With 7 token types, this means ~7 iterations per match. Performance degrades linearly with number of matches.

**Severity:** MEDIUM

**Conditions for success:**
- All existing tests pass
- The optimization produces identical output to the current implementation
- No performance regression in synthetic benchmarks

**Implementation approach:**
```php
// Current (inefficient):
$fullMatch = $matches[0];
foreach ($matches as $type => $value) {
    if (!is_string($type) || $value === '' || $value !== $fullMatch) {
        continue;
    }
    // ... process matched type
}

// Optimized:
$fullMatch = $matches[0];
$namedGroups = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
$matchedType = array_search($fullMatch, $namedGroups, true);
if ($matchedType !== false) {
    $value = str_replace("\x1b", '', $fullMatch);
    $color = $theme[$matchedType] ?? null;
    if ($color !== null) {
        return Ansi::CSI . $color . 'm' . $value . Ansi::reset();
    }
    return $value;
}
return $fullMatch;
```

**Related code locations:**
- `sugar-glow/src/Highlighter/ChromaJsonHighlighter.php:90-114` (highlight method)
- `sugar-glow/tests/Highlighter/ChromaJsonHighlighterTest.php:157-172` (test for correct group selection)

---

### 1.3 [MEDIUM] Fix or clarify HighlighterInterface::supports() contract

**Files:**
- `sugar-glow/src/Highlighter/HighlighterInterface.php:26`
- `sugar-glow/src/Highlighter/ChromaJsonHighlighter.php:125-130`

**What is expected:**
Either:
1. Implement actual language support detection in ChromaJsonHighlighter based on its actual capabilities (regex-based PHP highlighting), OR
2. Remove the parameter entirely from the interface if no implementation can do meaningful language detection

**Why:**
The current implementation returns `true` for ANY non-empty, non-"text" language. This makes the `HighlighterInterface` contract misleading. A consumer cannot ask "does this highlighter support PHP?" — it always says yes. The method signature implies it does language-specific detection, but the implementation is a no-op.

**Severity:** MEDIUM

**Conditions for success:**
- ChromaJsonHighlighter returns `true` only for languages it actually supports (e.g., `php`, possibly `javascript`, `html`, `css`)
- Interface contract is honest about what it supports
- All existing tests pass

**Implementation notes:**
Given that ChromaJsonHighlighter uses PHP-focused regex patterns, the recommended fix is to implement actual language filtering. ChromaJsonHighlighter supports: PHP (primary), and optionally other C-style languages whose syntax matches the regex patterns.

```php
private const SUPPORTED_LANGUAGES = ['php', 'javascript', 'js', 'typescript', 'ts', 'html', 'css', 'c', 'cpp', 'java', 'go', 'rust', 'ruby', 'python'];

public function supports(string $language): bool
{
    if ($language === '' || $language === 'text') {
        return false;
    }
    return in_array(strtolower($language), self::SUPPORTED_LANGUAGES, true);
}
```

**Related code locations:**
- `sugar-glow/src/Highlighter/HighlighterInterface.php:21-26` (interface definition)
- `sugar-glow/src/Highlighter/ChromaJsonHighlighter.php:125-130` (current implementation)
- `sugar-glow/src/Highlighter/Highlighter.php:19-28` (Highlighter::new() hardcodes ChromaJsonHighlighter)

---

### 1.4 [MEDIUM] Document blocking nature of FileWatcher::watch()

**File:** `sugar-glow/src/FileWatcher.php:45-69`

**What is expected:**
Add prominent docblock warning about the blocking nature of `watch()`, noting that it must be consumed in a worker process or with proper event loop integration. Also add `@blocking` annotation.

**Why:**
The CALIBER_LEARNINGS.md already captures this pattern (line 7: "FileWatcher::watch() is a Generator that runs indefinitely. Always consume it inside a loop with a termination condition or stream context cancellation"). The code itself should document this to prevent misuse. In a ReactPHP async context, this will block the event loop entirely because of the synchronous `usleep()` call in the `while(true)` loop.

**Severity:** MEDIUM

**Conditions for success:**
- PHPDoc updated with `@blocking` annotation or clear warning comment
- CALIBER_LEARNINGS entry already exists (pattern:glow, no change needed there)
- All tests pass

**Implementation notes:**
Add to the docblock of `watch()`:
```php
/**
 * @blocking This generator runs an infinite blocking loop with usleep().
 *           Must be consumed in a worker process or with async event loop integration.
 *           Never foreach directly outside a coroutine dispatcher or the event loop will block.
 * @see \SugarCraft\Glow\FileWatcher::watch() — CALIBER_LEARNINGS.md pattern:glow
 */
```

**Related code locations:**
- `sugar-glow/src/FileWatcher.php:45-69` (watch method)
- `sugar-glow/CALIBER_LEARNINGS.md:7` (existing pattern:glow entry)
- `sugar-glow/tests/FileWatcherTest.php` (existing tests)

---

## Phase 2: Low Priority Fixes [PENDING]

### 2.1 [LOW] Add tests for missing GlowModel Viewport key bindings

**File:** `sugar-glow/tests/GlowModelTest.php`

**What is expected:**
Add test coverage for all Viewport key bindings not currently tested:
- PageUp (b key)
- PageDown (space, f key)
- Ctrl+U (half page up)
- Ctrl+D (half page down)
- Home (g key)
- End (G key)
- Content fits entirely within viewport (no scrolling needed)
- Left/Right arrow keys (horizontal scroll)
- Mouse wheel scrolling

**Why:**
`GlowModelTest.php` only tests q, Esc, Ctrl+C, and Down. The Viewport class (`candy-forms/src/Viewport/Viewport.php:98-128`) implements many more key bindings that are never exercised in tests.

**Severity:** LOW

**Conditions for success:**
- New tests pass
- All existing tests continue to pass
- Coverage report shows the new tests hitting the relevant Viewport code paths

**Implementation approach:**
```php
public function testPageUpScrollsViewport(): void
{
    $m = GlowModel::fromContent($this->content(20), 80, 3);
    // First scroll down to middle
    [$m, ] = $m->update(new KeyMsg(KeyType::Down));
    [$m, ] = $m->update(new KeyMsg(KeyType::Down));
    // PageUp should go back one viewport height
    [$m, ] = $m->update(new KeyMsg(KeyType::PageUp));
    $this->assertLessThan(2, $m->viewport->yOffset);
}

public function testPageDownScrollsViewport(): void
{
    $m = GlowModel::fromContent($this->content(20), 80, 3);
    [$m, ] = $m->update(new KeyMsg(KeyType::PageDown));
    $this->assertGreaterThan(0, $m->viewport->yOffset);
}

public function testCtrlUHalfPageUp(): void
{
    $m = GlowModel::fromContent($this->content(20), 80, 3);
    [$m, ] = $m->update(new KeyMsg(KeyType::Down));
    [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'u', ctrl: true));
    $this->assertLessThan(1, $m->viewport->yOffset);
}

public function testHomeGoesToTop(): void
{
    $m = GlowModel::fromContent($this->content(20), 80, 3);
    [$m, ] = $m->update(new KeyMsg(KeyType::Down));
    [$m, ] = $m->update(new KeyMsg(KeyType::Home));
    $this->assertSame(0, $m->viewport->yOffset);
}

public function testEndGoesToBottom(): void
{
    $m = GlowModel::fromContent($this->content(20), 80, 3);
    [$m, ] = $m->update(new KeyMsg(KeyType::End));
    $this->assertSame($m->viewport->maxOffset(), $m->viewport->yOffset);
}

public function testContentFitsViewportNoScrollNeeded(): void
{
    $m = GlowModel::fromContent($this->content(2), 80, 5);
    $this->assertSame(0, $m->viewport->yOffset);
    // Press Down - should clamp to max offset (0)
    [$m, ] = $m->update(new KeyMsg(KeyType::Down));
    $this->assertSame(0, $m->viewport->yOffset);
}

public function testAtBottomWhenContentFits(): void
{
    $m = GlowModel::fromContent($this->content(2), 80, 5);
    $this->assertTrue($m->viewport->atBottom());
}
```

**Related code locations:**
- `sugar-glow/tests/GlowModelTest.php` (existing tests, 66 lines)
- `sugar-glow/src/GlowModel.php` (GlowModel implementation)
- `candy-forms/src/Viewport/Viewport.php:98-128` (Viewport key bindings)
- `sugar-bits/src/Viewport/Viewport.php:8` (alias to candy-forms)

---

### 2.2 [LOW] Add `$throws` parameter to GlamourTheme::fromJson() and ::fromFile()

**File:** `sugar-glow/src/GlamourTheme.php:34-62`

**What is expected:**
Add an optional `bool $throws = false` parameter to both methods. When `$throws = true`, throw `\JsonException` on parse failure instead of returning a default empty theme.

**Why:**
Silent failures mask configuration errors in production. The current behavior (returns defaults on failure) is tested in `GlamourThemeTest.php:49-58` and documented in CALIBER_LEARNINGS.md, but an opt-in exception mode would improve debuggability.

**Severity:** LOW

**Conditions for success:**
- Existing behavior preserved when `$throws = false` (default)
- When `$throws = true`, throws `\JsonException` for invalid JSON
- All existing tests pass
- New tests cover the exception path

**Implementation approach:**
```php
public static function fromJson(string $json, bool $throws = false): self
{
    $data = json_decode($json, true);

    if (!is_array($data)) {
        if ($throws) {
            throw new \JsonException('Invalid JSON: ' . json_last_error_msg());
        }
        return new self();
    }
    // ... rest of implementation
}

public static function fromFile(string $path, bool $throws = false): self
{
    if (!is_readable($path)) {
        if ($throws) {
            throw new \InvalidArgumentException("Theme file not readable: {$path}");
        }
        return new self();
    }
    // ... rest of implementation
}
```

**Related code locations:**
- `sugar-glow/src/GlamourTheme.php:34-49` (fromJson method)
- `sugar-glow/src/GlamourTheme.php:54-62` (fromFile method)
- `sugar-glow/tests/GlamourThemeTest.php:49-58` (existing test for invalid JSON)

---

### 2.3 [LOW] Improve RenderCommand::loadInput() return value clarity

**File:** `sugar-glow/src/RenderCommand.php:153-166`

**What is expected:**
Either:
1. Document the current behavior clearly in the PHPDoc, noting that null return is ambiguous, OR
2. Change return type to use a result object or union type to distinguish failure modes

**Why:**
The current logic returns `null` when:
1. STDIN is a TTY (interactive terminal)
2. The stream is empty
3. The stream read returns empty string

All three cases return the same `null` value, making it impossible for callers to distinguish "user declined to provide input" from "file not found" from "empty stdin pipe."

**Severity:** LOW

**Conditions for success:**
- If changed: All callers updated to handle new return type
- Backward compatibility maintained
- All tests pass

**Implementation notes:**
Given this is a CLI command with a single internal caller, the minimal fix is to document the behavior clearly:

```php
/**
 * @return string|null Returns null when:
 *   - file path provided but file does not exist or is unreadable
 *   - no file path and STDIN is a TTY (interactive terminal)
 *   - no file path and STDIN is empty
 * Note: It is not possible to distinguish these failure modes from the return value.
 */
public static function loadInput(string $file, $stream = null): ?string
```

---

### 2.4 [LOW] Add explanatory comment to static callback in ChromaJsonHighlighter

**File:** `sugar-glow/src/Highlighter/ChromaJsonHighlighter.php:90-92`

**What is expected:**
Add a comment explaining why `static` is used (prevents accidental `$this` capture, ensures stateless operation), and that any refactoring must preserve this property for thread-safety.

**Why:**
The callback is `static`, which means it cannot access `$this` or use instance state. This is correct in this case (uses `$theme` from `use`), but it could be confusing if someone extends this class and tries to access instance state in the future.

**Severity:** LOW

**Conditions for success:**
- Comment added explaining the design decision
- All tests pass

**Implementation notes:**
```php
// NOTE: static closure is intentional.
// The $theme is captured via use(), not $this->theme, to ensure stateless
// operation and prevent accidental $this capture. If refactoring, preserve
// static + use() pattern to maintain thread-safety.
$result = preg_replace_callback(
    $pattern,
    static function (array $matches) use ($theme): string {
```

---

### 2.5 [LOW] Consider Pager empty stream handling

**File:** `sugar-glow/src/Pager.php:34-36`

**What is expected:**
Either:
1. Yield a final empty chunk for empty streams so consumers can detect completion, OR
2. Document this limitation clearly

**Why:**
If a stream is empty or has no trailing content to flush the final chunk, `Pager` yields nothing. A consumer calling `iterator_to_array($pager)` would receive an empty array with no indication of whether the stream was empty vs. seekable-vs-closed. The test at `PagerTest.php:15-23` explicitly tests that empty streams yield nothing.

**Severity:** LOW

**Conditions for success:**
- If changed: All existing tests updated
- Consumer code can determine stream status
- All tests pass

**Implementation approach (optional):**
```php
if ($buffer !== []) {
    yield $buffer;
} elseif (feof($stream)) {
    // Yield an empty chunk to signal stream end for empty streams
    yield [];
}
```

---

## Phase 3: Documentation & Info Items [PENDING]

### 3.1 [INFO] ChromaJsonHighlighter regex limitation documentation

**File:** `sugar-glow/src/Highlighter/ChromaJsonHighlighter.php:16-18`

**What is expected:**
The library already acknowledges (lines 16-18) that this is a simplified proof-of-concept requiring a proper lexer for production use with complex code. This is informational only, no code change required.

**Why:**
The docblock at lines 12-20 already states:
> "This is a simplified proof-of-concept; real tokenization requires a proper lexer (Pygments/Scrivener)."

Consider adding a `@see` reference to a potential future FFI-based lexer implementation.

**Severity:** INFO (no code change required)

---

## Verification Commands

Run all tests after each phase:

```bash
# Full test suite
cd sugar-glow && vendor/bin/phpunit

# Specific test files for each phase
cd sugar-glow && vendor/bin/phpunit tests/Highlighter/ChromaJsonHighlighterTest.php
cd sugar-glow && vendor/bin/phpunit tests/GlowModelTest.php
cd sugar-glow && vendor/bin/phpunit tests/GlamourThemeTest.php
cd sugar-glow && vendor/bin/phpunit tests/RenderCommandTest.php
cd sugar-glow && vendor/bin/phpunit tests/PagerTest.php
cd sugar-glow && vendor/bin/phpunit tests/FileWatcherTest.php
```

---

## Summary Table

| Priority | Issue | File:Line | Category |
|----------|-------|-----------|----------|
| HIGH | Keyword regex backtrack risk | `ChromaJsonHighlighter.php:48` | Performance |
| MEDIUM | Inefficient named group lookup | `ChromaJsonHighlighter.php:97-100` | Performance |
| MEDIUM | `supports()` ignores language | `HighlighterInterface.php:26` / `ChromaJsonHighlighter.php:125-130` | API Design |
| MEDIUM | Blocking FileWatcher::watch() | `FileWatcher.php:57` | Documentation |
| LOW | Pager empty stream ambiguity | `Pager.php:34-36` | API Design |
| LOW | Silent theme parse failure | `GlamourTheme.php:38-40` | Error Handling |
| LOW | loadInput() null ambiguity | `RenderCommand.php:160-165` | API Design |
| LOW | Static callback comment missing | `ChromaJsonHighlighter.php:90-92` | Documentation |
| LOW | Missing Viewport key tests | `GlowModelTest.php` | Testing |
| INFO | Regex limitation acknowledged | `ChromaJsonHighlighter.php:16-18` | Documentation |
