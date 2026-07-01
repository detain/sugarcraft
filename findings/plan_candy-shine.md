---
status: not-started
phase: 1
updated: 2026-06-30
---

# Implementation Plan: candy-shine

## Goal

Address all 16 findings from the candy-shine audit (15 actionable + 1 skipped) covering bugs, performance, memory, security, complexity, and missing features.

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Lazy initialization for BlockStack/StyleSheet | `copy()` creates new Renderer but doesn't reinitialize state; lazy init in `render()` with null check fixes shared state bug | `src/Renderer.php:276-298` |
| Use `array_map` + `implode` for line-number prefixing | Avoids reference-loop foot-gun and O(n²) string concat from `&$line` pattern | `src/SyntaxHighlighter.php:108-119` |
| Remove `@` error suppression in Theme::fromJson | Suppressed warnings hide real failures; enrich exception via `error_get_last()` | `src/Theme.php:560` |
| Use array accumulation + `implode` in renderChildren | PHP string concat in loop is O(n²); array join is O(n) | `src/Renderer.php:601-608` |
| Cache compiled regex per language in SyntaxHighlighter | Regex compilation is expensive; cache key is language keywords string | `src/SyntaxHighlighter.php:130-136` |
| Skip O(depth) optimization for StyleSheet::for() | Documents rarely exceed 20 levels deep; linear scan is bounded and acceptable | `src/Style/StyleSheet.php:76-80` |
| Add `Theme::fromJsonString()` for safer API | Path-traversal risk only matters if API accepts user input; string variant documents trust boundary | `src/Theme.php:555-565` |
| Static regex pattern for emoji shortcodes | Regex compilation on every call is unnecessary overhead; use class constant | `src/Renderer.php:369-373` |

---

## Phase 1: Critical Bug Fix [PENDING]

- [ ] **1.1 HIGH: BlockStack/StyleSheet state not reset in copy()** ← CURRENT

### 1.1 BlockStack/StyleSheet State Not Reset in copy()

**What is expected:**
Initialize `$this->blockStack` and `$this->styleSheet` to `null` in the constructor, then lazy-initialize them at the start of `render()` with a null check:
```php
// Constructor (line 84-114): add these properties as nullable
private ?BlockStack $blockStack = null;
private ?StyleSheet $styleSheet = null;

// render() (line 300-348): change lines 307-308 from:
$this->blockStack = new BlockStack();
$this->styleSheet = StyleSheet::base();
// To:
if ($this->blockStack === null) {
    $this->blockStack = new BlockStack();
    $this->styleSheet = StyleSheet::base();
}
```

**Why the change should be done:**
The `copy()` method (lines 276-298) creates a new `Renderer` instance via `new self(...)` but does NOT reset `$this->blockStack` or `$this->styleSheet`. These are instance properties set only in `render()` (lines 307-308). If a `Renderer` instance is reused or derived via `with*()`, the old state can leak.

**Severity:** HIGH — correctness bug causing incorrect rendering for reused renderers.

**Conditions for success:**
- New `Renderer` instance from `with*()` has fresh `blockStack` and `styleSheet`
- Calling `render()` twice on the same `Renderer` instance produces correct output both times
- Unit test verifies immutability of `blockStack`/`styleSheet` across multiple `render()` calls

**Related code locations:**
- `src/Renderer.php:76-82` — `blockStack` and `styleSheet` properties declared
- `src/Renderer.php:84-114` — constructor (does NOT initialize blockStack/styleSheet)
- `src/Renderer.php:276-298` — `copy()` method (no initialization of state properties)
- `src/Renderer.php:300-348` — `render()` (initialization at lines 307-308)

**Investigation notes:**
```
Constructor (line 84-114) does NOT initialize blockStack or styleSheet.
These are set only in render() at lines 307-308:
    $this->blockStack = new BlockStack();
    $this->styleSheet = StyleSheet::base();

The copy() method creates new self() but the constructor does not set
blockStack/styleSheet. The fix is to make them nullable (?BlockStack, ?StyleSheet)
initialized to null in constructor, with lazy initialization in render() that
checks for null first.
```

---

## Phase 2: Performance Fixes [PENDING]

- [ ] 2.1 SyntaxHighlighter line-numbers string rebuild
- [ ] 2.2 Renderer::renderChildren string concatenation
- [ ] 2.3 SyntaxHighlighter regex compilation caching
- [ ] 2.4 renderList string concatenation in loop

### 2.1 SyntaxHighlighter Line-Number String Rebuild

**What is expected:**
Replace `explode()` + `implode()` with `array_map()` to avoid the `&$line` reference loop and O(n²) string concat:
```php
// src/SyntaxHighlighter.php:108-119 — change from:
$lines = explode("\n", $highlighted);
$paddedWidth = strlen((string) count($lines));
foreach ($lines as $index => &$line) {
    $lineNum = $index + 1;
    $padded  = str_pad((string) $lineNum, $paddedWidth, ' ', STR_PAD_LEFT);
    $styled  = $commentStyle?->render($padded) ?? $padded;
    $line    = $styled . "\t" . $line;
}
return implode("\n", $lines);

// To:
$lines = explode("\n", $highlighted);
$paddedWidth = strlen((string) count($lines));
return implode("\n", array_map(
    fn($line, $i) => ($commentStyle?->render(str_pad((string)($i+1), $paddedWidth, ' ', STR_PAD_LEFT)) ?? str_pad((string)($i+1), $paddedWidth, ' ', STR_PAD_LEFT)) . "\t" . $line,
    $lines,
    array_keys($lines)
));
```

**Why the change should be done:**
1. `explode()` + `implode()` creates full string copy
2. `&$line` reference doesn't unset after loop — dangling reference bug
3. String concat inside loop is O(n²)

**Severity:** MEDIUM

**Conditions for success:**
- `SyntaxHighlighter::highlight($code, $lang, $theme, true)` produces correctly numbered output
- No reference variables used
- Performance is O(n) not O(n²)

**Related code locations:**
- `src/SyntaxHighlighter.php:108-119` — highlight() method, line-number section

**Investigation notes:**
The `&$line` reference at line 112 is not unset after the loop at line 117. This is a PHP bug waiting to happen if code is refactored. The array_map solution eliminates the reference entirely.

### 2.2 Renderer::renderChildren String Concatenation

**What is expected:**
Replace `$out .=` with array accumulation and `implode()`:
```php
// src/Renderer.php:601-608 — change from:
private function renderChildren(Node $parent): string
{
    $out = '';
    foreach ($parent->children() as $child) {
        $out .= $this->renderNode($child);
    }
    return $out;
}

// To:
private function renderChildren(Node $parent): string
{
    $parts = [];
    foreach ($parent->children() as $child) {
        $parts[] = $this->renderNode($child);
    }
    return implode('', $parts);
}
```

**Why the change should be done:**
PHP string concatenation with `.=` in a loop is O(n²) because strings are immutable. For documents with many nodes, this causes measurable slowdown.

**Severity:** MEDIUM

**Conditions for success:**
- Rendered output is byte-identical to current
- Performance is O(n) not O(n²)

**Related code locations:**
- `src/Renderer.php:601-608` — renderChildren() method

### 2.3 SyntaxHighlighter Regex Compilation Caching

**What is expected:**
Add static pattern cache to avoid rebuilding regex on every `tokenise()` call:
```php
// src/SyntaxHighlighter.php — add class property and modify tokenise():
private static array $patternCache = [];

private static function getPattern(array $keywords): string
{
    $key = implode('|', $keywords);
    return self::$patternCache[$key] ??= '/'
        . '(?P<comment>\/\/[^\n]*|\#[^\n]*|\/\*[^*]*(?:\*(?!\/)[^*]*)*\*\/|<!--(?:[^-]|-(?!->))*-->)'
        . '|(?P<string>"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|`(?:\\\\.|[^`\\\\])*`)'
        . '|(?P<keyword>\b(?:' . $kw . ')\b)'
        . '|(?P<number>\b\d+(?:\.\d+)?\b)'
        . '/su';
}

// In tokenise() (line 125-136): replace the inline pattern build with:
$pattern = self::getPattern($keywords);
```

**Why the change should be done:**
Regex compilation is expensive relative to the simple token replacement. For repeated rendering of the same language (common in real usage), caching provides significant speedup.

**Severity:** MEDIUM

**Conditions for success:**
- Output is byte-identical to uncached version
- Same language rendered twice uses cached regex (verified via static analysis)

**Related code locations:**
- `src/SyntaxHighlighter.php:125-136` — tokenise() method, pattern building at lines 130-136

### 2.4 renderList String Concatenation in Loop

**What is expected:**
Replace multiple `$out .=` calls with array accumulation in `renderList()`:
```php
// src/Renderer.php:769-797 — in renderList(), change from:
$out = '';
$i   = $start;
foreach ($list->children() as $item) {
    // ... setup ...
    $out .= $marker->render($bullet) . ' ' . rtrim($first) . "\n";
    foreach ($lines as $line) {
        $line = rtrim($line);
        $out .= ($line === '' ? '' : $indent . $line) . "\n";
    }
    $i++;
}
return $out . "\n";

// To:
$lines = [];
$i = $start;
foreach ($list->children() as $item) {
    // ... setup ...
    $lines[] = $marker->render($bullet) . ' ' . rtrim($first);
    foreach ($lines as $line) {
        $line = rtrim($line);
        $lines[] = ($line === '' ? '' : $indent . $line);
    }
    $i++;
}
return implode("\n", $lines) . "\n";
```

**Why the change should be done:**
Same O(n²) issue as renderChildren — PHP string concat in loop is quadratic.

**Severity:** MEDIUM

**Conditions for success:**
- Rendered output identical
- Performance improved for large lists

**Related code locations:**
- `src/Renderer.php:769-797` — renderList() method, specifically lines 769-797

---

## Phase 3: Bug Fixes [PENDING]

- [ ] 3.1 Theme::fromJson error suppression hides real failures
- [ ] 3.2 JSON decode error not distinguishable from valid `null`
- [ ] 3.3 Redundant is_file() check before file_get_contents()

### 3.1 Theme::fromJson Error Suppression

**What is expected:**
Remove `@` error suppression and use `error_get_last()` to enrich the exception:
```php
// src/Theme.php:560 — change from:
$raw = @file_get_contents($path);
if ($raw === false) {
    throw new \RuntimeException(Lang::t('theme.read_failed', ['path' => $path]));
}

// To:
$raw = file_get_contents($path);
if ($raw === false) {
    $error = error_get_last();
    $msg = $error['message'] ?? 'unknown error';
    throw new \RuntimeException(Lang::t('theme.read_failed', ['path' => $path]) . ": {$msg}");
}
```

**Why the change should be done:**
The `@` operator suppresses PHP warnings. If `file_get_contents()` fails due to permission denied, symlink loop, or other filesystem errors, the user gets a generic "read failed" message instead of the actual error. This makes debugging very difficult.

**Severity:** MEDIUM

**Conditions for success:**
- Permission denied produces informative exception with actual PHP error message
- Symlink loop produces informative exception
- All existing ThemeTest tests still pass

**Related code locations:**
- `src/Theme.php:555-565` — fromJson() method, specifically line 560

### 3.2 JSON Decode Error Indistinguishable from Null

**What is expected:**
Check `json_last_error()` before checking if result is an array:
```php
// src/Theme.php:570-572 — change from:
$data = json_decode($json, associative: true);
if (!is_array($data)) {
    throw new \InvalidArgumentException(Lang::t('theme.json_object'));
}

// To:
$data = json_decode($json, associative: true);
if (json_last_error() !== JSON_ERROR_NONE) {
    throw new \InvalidArgumentException(Lang::t('theme.json_invalid'));
}
if (!is_array($data)) {
    throw new \InvalidArgumentException(Lang::t('theme.json_object'));
}
```

**Why the change should be done:**
`json_decode()` returns `null` both for JSON `null` literal and for decode errors. Currently `!is_array($data)` conflates the two, meaning valid JSON containing `null` would throw "json_object" error. The fix distinguishes actual errors from valid `null`.

**Severity:** LOW

**Conditions for success:**
- Theme JSON with explicit `null` value is properly handled (throws appropriate error since null is not a valid Style)
- Actual JSON decode errors (malformed JSON) throw informative exception
- All existing ThemeTest tests still pass

**Related code locations:**
- `src/Theme.php:568-573` — fromJsonString() method

### 3.3 Redundant is_file() Check

**What is expected:**
Remove `is_file()` check at line 557 since `file_get_contents()` already returns `false` for non-existent files:
```php
// src/Theme.php:555-565 — change from:
public static function fromJson(string $path): self
{
    if (!is_file($path)) {
        throw new \RuntimeException(Lang::t('theme.read_failed', ['path' => $path]));
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        throw new \RuntimeException(Lang::t('theme.read_failed', ['path' => $path]));
    }
    return self::fromJsonString($raw);
}

// To:
public static function fromJson(string $path): self
{
    $raw = @file_get_contents($path);
    if ($raw === false) {
        throw new \RuntimeException(Lang::t('theme.read_failed', ['path' => $path]));
    }
    return self::fromJsonString($raw);
}
```

Note: The `@` on `file_get_contents()` should also be removed as part of 3.1 fix.

**Why the change should be done:**
`is_file()` is redundant because `file_get_contents()` already returns `false` if the file doesn't exist or is unreadable. The redundant check just adds code complexity.

**Severity:** LOW

**Conditions for success:**
- Same behavior after removing is_file() check
- All existing ThemeTest tests still pass

**Related code locations:**
- `src/Theme.php:555-565` — fromJson() method, specifically line 557

---

## Phase 4: Memory Issues [PENDING]

- [ ] 4.1 SyntaxHighlighter line-number reference loop (addressed in 2.1)

### 4.1 SyntaxHighlighter Line-Number Reference Loop

**What is expected:**
This is fixed by the array_map solution in 2.1, which eliminates the `foreach (... &$line)` pattern entirely.

**Why the change should be done:**
After `foreach` with `&$line`, the variable `$line` still holds a reference to the last array element. This can cause subtle bugs if code is later modified to reuse the variable.

**Severity:** LOW

**Conditions for success:**
- After array_map refactor (2.1), no reference variables are used in line-number code path

**Related code locations:**
- `src/SyntaxHighlighter.php:108-119` — addressed by 2.1 fix

---

## Phase 5: Security [PENDING]

- [ ] 5.1 Theme::fromJson path traversal documentation

### 5.1 Theme::fromJson Path Traversal

**What is expected:**
Document clearly that `$path` must be a trusted file path. The `Theme::fromJsonString()` method (line 568) already exists for cases where you have untrusted input and want to parse without file access.

Add docblock note:
```php
/**
 * Load a theme from a JSON file.
 * 
 * @param string $path Trusted file path — must NOT be user-controlled.
 *   If you have untrusted JSON string input, use {@see fromJsonString()} instead.
 */
public static function fromJson(string $path): self
```

**Why the change should be done:**
API design issue: if `$path` is user-controlled, a path traversal attack could read arbitrary files. The method should document this trust requirement.

**Severity:** LOW

**Conditions for success:**
- Docblock clearly documents trust requirement
- `Theme::fromJsonString()` is available as the safe alternative

**Related code locations:**
- `src/Theme.php:555-565` — fromJson() method

---

## Phase 6: Complexity Issues [PENDING]

- [ ] 6.1 StyleSheet uses custom mutate() workaround

### 6.1 StyleSheet Custom mutate() Workaround

**What is expected:**
Add a clarifying docblock to the `mutate()` override explaining why it exists:
```php
/**
 * Override mutate() because StyleSheet uses a private no-arg constructor.
 * The standard Mutable trait pattern relies on constructor parameters,
 * which doesn't work when the constructor takes no arguments.
 * 
 * This override creates a new instance via `new static()`, copies the
 * styles array, and merges in any changes — bypassing the trait's
 * constructor-based approach.
 */
protected function mutate(array $changes): static
{
    $new = new static();
    $new->styles = array_merge($this->styles, $changes['styles'] ?? []);
    return $new;
}
```

**Why the change should be done:**
The custom `mutate()` is necessary given the private no-arg constructor, but it adds complexity because it bypasses the standard `Mutable` trait pattern. Documentation helps future maintainers understand why this exists.

**Severity:** MEDIUM

**Conditions for success:**
- Code is clearer after adding documentation
- All existing StyleSheetTest tests pass

**Related code locations:**
- `src/Style/StyleSheet.php:123-128` — mutate() override

**Investigation notes:**
The `Mutable` trait expects a constructor with parameters to merge via `new static(...array_merge(get_object_vars($this), $changes))`. But `StyleSheet` has a private no-arg constructor (`line 29: private function __construct() {}`), so the trait's `mutate()` would fail. The override creates `new static()` directly and manually merges the styles array.

---

## Phase 7: Missing Features [PENDING]

- [ ] 7.1 Missing glamour "Write" function parity
- [ ] 7.2 Limited emoji shortcode map
- [ ] 7.3 No streaming/rendering for very large documents

### 7.1 Missing glamour "Write" Function Parity

**What is expected:**
Add `Renderer::write()` method that writes directly to a stream:
```php
/**
 * Write rendered Markdown directly to a stream (default STDOUT).
 * Mirrors glamour's Write function.
 * 
 * @param string $markdown Input Markdown
 * @param resource $output Output stream (default STDOUT)
 * @return int Number of bytes written
 */
public function write(string $markdown, mixed $output = STDOUT): int
{
    $rendered = $this->render($markdown);
    return fwrite($output, $rendered);
}
```

**Why the change should be done:**
Feature parity with upstream glamour, which has a `Write(markdown, options)` function that writes directly to a terminal-optimized writer.

**Severity:** MEDIUM

**Conditions for success:**
- `Renderer::write($md)` writes to STDOUT
- `Renderer::write($md, $stream)` writes to provided stream
- Returns byte count written

**Related code locations:**
- `src/Renderer.php` — new method (suggest placing after `renderMarkdown()` at line 131)

### 7.2 Limited Emoji Shortcode Map

**What is expected:**
Expand the emoji shortcode map from 15 to at least 30 entries. Current map at lines 358-368:
```php
private static function expandEmojiShortcodes(string $markdown): string
{
    static $map = [
        'smile'    => '😄', 'grin'    => '😁',
        'heart'    => '❤️', 'fire'    => '🔥',
        'rocket'   => '🚀', 'star'    => '⭐',
        'thumbsup' => '👍', 'thumbsdown' => '👎',
        'check'    => '✅', 'x'       => '❌',
        'warning'  => '⚠️',  'info'    => 'ℹ️',
        'tada'     => '🎉', 'sparkles' => '✨',
        'candy'    => '🍬', 'sugar'   => '🍭',
        'honey'    => '🍯',
    ];
    // ...
}
```

Add more common shortcodes: clap, eyes, tongue, wink, sob,astonished, tired, sleeping,zzz, headphones, mail, email, phone, camera, gift, ballot, memo, pencil, hammer, wrench, gun, knife, bomb, skull, koala, tiger, rabbit, dragon, camel, snake, turtle, fish, octopus

**Why the change should be done:**
Feature parity with glamour's broader emoji support; users expect more emoji shortcuts.

**Severity:** MEDIUM

**Conditions for success:**
- At least 30 common shortcodes supported
- Existing emoji tests still pass

**Related code locations:**
- `src/Renderer.php:358-368` — expandEmojiShortcodes() method, `$map` array

### 7.3 No Streaming for Large Documents

**What is expected:**
Document this as a known limitation and potential future enhancement. No code change in this PR.

**Why the change should be done:**
The entire output string is built in memory before returning. For very large documents, this can be memory-intensive. glamour supports streaming output.

**Severity:** LOW

**Conditions for success:**
- Documentation added to README or docblock noting streaming as future enhancement

**Related code locations:**
- `src/Renderer.php:300-348` — render() method

---

## Phase 8: Additional Performance Items [PENDING]

- [ ] 8.1 Emoji shortcode regex compiled every call
- [ ] 8.2 StyleSheet::for() linear scan (SKIPPED — not warranted)

### 8.1 Emoji Shortcode Regex Compiled Every Call

**What is expected:**
Move the emoji regex to a class constant:
```php
// src/Renderer.php — add constant near top of class (after use statements)
private const EMOJI_SHORTCODE_PATTERN = '/:([a-z0-9_+-]+):/i';

// In expandEmojiShortcodes() (line 356-374):
private static function expandEmojiShortcodes(string $markdown): string
{
    static $map = [/* ... */];
    return (string) preg_replace_callback(
        self::EMOJI_SHORTCODE_PATTERN,  // Changed from '/:([a-z0-9_+-]+):/i'
        static fn (array $m): string => $map[strtolower($m[1])] ?? $m[0],
        $markdown,
    );
}
```

**Why the change should be done:**
The regex `/:([a-z0-9_+-]+):/i` is compiled on every call to `expandEmojiShortcodes()`. Moving it to a class constant means it's compiled once when the class is loaded.

**Severity:** LOW

**Conditions for success:**
- Output identical before and after
- Regex compiled once not per-call

**Related code locations:**
- `src/Renderer.php:356-374` — expandEmojiShortcodes() method, regex at line 369

### 8.2 StyleSheet::for() Linear Scan — SKIPPED

**What is expected:**
No action needed. The O(depth) lookup in `StyleSheet::for()` is acceptable because:
1. Documents rarely exceed 20 nesting levels
2. The linear scan is bounded by a small constant
3. The optimization would add complexity without meaningful benefit

**Decision:** Skip this optimization.

**Why the change should be done:**
N/A — skipped

**Severity:** LOW

**Conditions for success:**
N/A — skipped

**Related code locations:**
- `src/Style/StyleSheet.php:66-83` — for() method

---

## Summary Table

| Phase | Item | Severity | Status |
|-------|------|----------|--------|
| 1 | BlockStack/StyleSheet state in copy() | HIGH | PENDING |
| 2.1 | SyntaxHighlighter line-number string rebuild | MEDIUM | PENDING |
| 2.2 | renderChildren string concatenation | MEDIUM | PENDING |
| 2.3 | SyntaxHighlighter regex caching | MEDIUM | PENDING |
| 2.4 | renderList string concatenation | MEDIUM | PENDING |
| 3.1 | Theme::fromJson error suppression | MEDIUM | PENDING |
| 3.2 | JSON decode error indistinguishable from null | LOW | PENDING |
| 3.3 | Redundant is_file() check | LOW | PENDING |
| 4.1 | SyntaxHighlighter reference loop | LOW | PENDING |
| 5.1 | Theme::fromJson path traversal | LOW | PENDING |
| 6.1 | StyleSheet custom mutate() | MEDIUM | PENDING |
| 7.1 | Missing Write function | MEDIUM | PENDING |
| 7.2 | Limited emoji map | MEDIUM | PENDING |
| 7.3 | No streaming | LOW | PENDING |
| 8.1 | Emoji regex compiled every call | LOW | PENDING |
| 8.2 | StyleSheet::for() linear scan | LOW | SKIPPED |

**Total: 16 findings, 15 to address, 1 skipped**

---

## Notes

- 2026-06-30: Plan created based on audit findings in `findings/candy-shine.md`
- Phase ordering follows severity: HIGH bug first, then MEDIUM performance, then remaining items
- The BlockStack/StyleSheet bug (1.1) is the only HIGH severity item and should be addressed first
- Items 4.1 (reference loop) is automatically fixed by 2.1 (array_map refactor)
- Items 3.3 (redundant is_file) and 3.1 (error suppression) are related — both in Theme::fromJson and should be done together
