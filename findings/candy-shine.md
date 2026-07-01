# Audit: candy-shine

**Library:** SugarCraft/candy-shine  
**Date:** 2026-06-30  

---

## Overview

`candy-shine` is a Markdown → ANSI renderer port of `charmbracelet/glamour`, built on `league/commonmark`. It parses Markdown and renders styled terminal output with word-wrap, OSC 8 hyperlinks, syntax highlighting, and 8 stock themes. Generally well-structured with good test coverage, but several issues warrant attention.

---

## 1. BUGS & EDGE CASES

### HIGH: BlockStack/StyleSheet state not reset in copy()
**Location:** `src/Renderer.php:276-298` (the `copy()` method)

The `copy()` method does not reset `$this->blockStack` or `$this->styleSheet`. These are instance properties initialized fresh only inside `render()`. If a `Renderer` instance is reused and `with*()` is called to get a "new" instance, the new renderer shares the **same** `blockStack` and `styleSheet` object references.

**Recommendation:** Initialize `$this->blockStack = new BlockStack()` and `$this->styleSheet = StyleSheet::base()` inside `copy()`, OR mark them as `readonly` and force re-initialization on first `render()`.

---

### MEDIUM: SyntaxHighlighter line-numbers rebuilds string inefficiently
**Location:** `src/SyntaxHighlighter.php:108-119`

```php
$lines = explode("\n", $highlighted);
foreach ($lines as $index => &$line) {
    $line = $styled . "\t" . $line;
}
return implode("\n", $lines);
```

**Issues:**
1. `explode()` + `implode()` creates a full copy of the string
2. The `&$line` reference iteration is error-prone — `$line` reference doesn't unset after loop
3. String concatenation inside loop is O(n²)

**Recommendation:**
```php
return implode("\n", array_map(
    fn($line, $i) => $commentStyle->render(str_pad((string)($i+1), $paddedWidth, ' ', STR_PAD_LEFT)) . "\t" . $line,
    $lines,
    array_keys($lines)
));
```

---

### MEDIUM: Theme::fromJson error suppression hides real failures
**Location:** `src/Theme.php:560`

```php
$raw = @file_get_contents($path);
```

The `@` suppresses warnings. If `file_get_contents()` fails for permission denied or symlink loop, user gets generic "read failed" instead of actual PHP error.

**Recommendation:** Remove `@` error suppression, or use `error_get_last()` to enrich the exception.

---

### MEDIUM: Renderer::renderChildren string concatenation in loop
**Location:** `src/Renderer.php:601-608`

```php
private function renderChildren(Node $parent): string
{
    $out = '';
    foreach ($parent->children() as $child) {
        $out .= $this->renderNode($child);  // O(n²) for deep trees
    }
    return $out;
}
```

For documents with many nodes, repeated `.=` concatenation is O(n²).

**Recommendation:**
```php
$parts = [];
foreach ($parent->children() as $child) {
    $parts[] = $this->renderNode($child);
}
return implode('', $parts);
```

---

### LOW: JSON decode error not distinguishable from valid `null`
**Location:** `src/Theme.php:570-572`

`json_decode()` returns `null` both for JSON `null` and for decoding errors. `!is_array($data)` conflates the two.

**Recommendation:**
```php
if (json_last_error() !== JSON_ERROR_NONE) {
    throw new \InvalidArgumentException(Lang::t('theme.json_invalid'));
}
```

---

### LOW: Emoji shortcode regex compiled every call
**Location:** `src/Renderer.php:369-373`

```php
return (string) preg_replace_callback(
    '/:([a-z0-9_+-]+):/i',
    static fn (array $m): string => $map[strtolower($m[1])] ?? $m[0],
    $markdown,
);
```

Regex pattern recompiled on every call. Could use pre-compiled pattern.

---

## 2. PERFORMANCE PROBLEMS

### MEDIUM: Repeated string concatenation throughout Renderer
**Location:** `src/Renderer.php` — multiple methods

The renderer uses `$out .=` pattern extensively in:
- `renderChildren()` at line 605
- `renderList()` at lines 769-797 (many `$out .=` calls in a loop)
- `renderBlockQuote()` at lines 704-709

**Recommendation:** Collect parts in array, `implode()` once at the end.

---

### MEDIUM: SyntaxHighlighter regex compilation on every tokenise() call
**Location:** `src/SyntaxHighlighter.php:130-136`

```php
$pattern = '/'
    . '(?P<comment>...)'
    . '|(?P<string>...)'
    . '...'
    . '/su';
```

Full combined regex rebuilt on every `tokenise()` call. Could be cached per language.

**Recommendation:**
```php
private static array $patternCache = [];
private static function getPattern(array $keywords): string
{
    $key = implode('|', $keywords);
    return self::$patternCache[$key] ??= '/...(pattern using $key).../su';
}
```

---

### LOW: StyleSheet::for() does linear scan up the depth ladder
**Location:** `src/Style/StyleSheet.php:76-80`

```php
for ($d = $depth; $d >= 0; $d--) {
    if (isset($this->styles[$name][$d])) {
        return $this->styles[$name][$d];
    }
}
```

For deeply nested documents, O(depth) per lookup.

---

## 3. MEMORY LEAKS

### LOW: No explicit cleanup of BlockStack after render
**Location:** `src/Renderer.php:300-348`

`render()` sets new `$this->blockStack` and `$this->styleSheet`. PHP will GC old ones, but if Renderer is kept alive for many renders, old references persist until overwritten.

**No action needed** — safe given PHP's memory model.

---

### LOW: SyntaxHighlighter line-number reference loop
**Location:** `src/SyntaxHighlighter.php:112`

```php
foreach ($lines as $index => &$line) {
    // ...
}
unset($line);  // NOT called
```

After `foreach` with `&$line`, the reference is not `unset()`. `$line` still holds a reference to last array element.

**Recommendation:** Add `unset($line)` after the loop.

---

## 4. SECURITY

### LOW: Input sanitization covers C0/ESC but not all injection vectors
**Location:** `src/Renderer.php:458-462` (`stripControls()`) and `src/Renderer.php:545-548` (`safeUrl()`)

`stripControls()` strips C0 controls except tab/newline, and ESC. `safeUrl()` strips C0/ESC/BEL from URLs. `withSanitize(false)` bypasses all sanitization — no runtime warning.

**No issue found** — sanitization is thorough. The `sanitize=false` opt-out is documented as intentional.

---

### LOW: Theme::fromJson path traversal possibility
**Location:** `src/Theme.php:555-565`

API design issue: method takes a file path. If path is user-controlled, could read arbitrary files.

**Recommendation:** Document clearly that `$path` must be a trusted file path. Consider adding `Theme::fromJsonString()`.

---

## 5. COMPLEXITY

### MEDIUM: StyleSheet uses custom mutate() workaround
**Location:** `src/Style/StyleSheet.php:123-128`

```php
protected function mutate(array $changes): static
{
    $new = new static();
    $new->styles = array_merge($this->styles, $changes['styles'] ?? []);
    return $new;
}
```

`Mutable` trait expects constructor with parameters, but `StyleSheet` has private no-arg constructor. Override bypasses this but adds complexity.

**Recommendation:** Consider whether `Mutable` trait is right here, or if simple `withBlockKind*()` returning new instances directly would be clearer.

---

### LOW: Redundant is_file() check before file_get_contents()
**Location:** `src/Theme.php:557, 560`

`file_get_contents()` already returns `false` if file doesn't exist. `is_file()` check is redundant.

---

## 6. MISSING FEATURES / INCOMPLETE PORTS

### MEDIUM: Missing glamour "Write" function parity
**Location:** `src/Renderer.php`

Upstream `glamour` has `Write(markdown, options)` function that writes directly to a terminal-optimized writer. This library only provides `Renderer::renderMarkdown()` returning a string.

**Recommendation:** Consider adding `Renderer::write(string $markdown, $output = STDOUT)` method.

---

### MEDIUM: Limited emoji shortcode map
**Location:** `src/Renderer.php:358-368`

Emoji map has 15 entries. glamour's emoji support is more extensive.

**Recommendation:** Expand the shortcode map to cover more common emoji shortcodes.

---

### LOW: No streaming/rendering for very large documents
**Location:** `src/Renderer.php:300` (`render()` method)

Entire output string built in memory before returning. glamour supports streaming output.

**Recommendation:** For large documents, streaming via callback/writer could be added as optional feature.

---

## 7. PHP 8.3/8.4 COMPATIBILITY

### LOW: Already using PHP 8.3 features correctly
**Location:** All source files

Uses `declare(strict_types=1)`, `readonly`, constructor property promotion, `fn()` arrow functions, `match` expressions. **No PHP 8.3/8.4 compatibility issues found.**

---

## 8. ASYNC/REACTPHP IMPROVEMENTS

### LOW: No async rendering support
**Location:** `src/Renderer.php`

Renderer is fully synchronous. For high-throughput use cases:

1. **Streaming render**: Accept writable stream and write chunks as produced
2. **Concurrent rendering**: Use `React\Promise` for parallel rendering of independent documents
3. **Generator-based rendering**: Use `Generator` to yield rendered blocks as completed

**Recommendation:** A separate `AsyncRenderer` class could be added in a future iteration if async use cases are prioritized.

---

## Summary Table

| Severity | Count | Categories |
|----------|-------|------------|
| HIGH | 1 | Bug: BlockStack state in copy() |
| MEDIUM | 6 | Performance (3), Bugs (2), Missing features (1) |
| LOW | 9 | Performance (2), Memory (2), Security (1), Complexity (2), PHP 8.x (1), Missing features (1) |

**Total: 16 findings**
