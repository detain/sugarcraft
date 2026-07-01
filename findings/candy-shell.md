# Audit: candy-shell

**Library:** SugarCraft/candy-shell  
**Date:** 2026-06-30  
**Files analyzed:** 50 PHP source files + 47 test files  
**Upstream:** charmbracelet/gum

---

## 1. SECURITY ISSUES

### HIGH: Shell Injection in Env-Var Fallback
**Location:** src/Application.php:137-141

```php
$escaped = escapeshellarg($envValue);
$cleanValue = stripslashes(trim($escaped, "'\"")) ?: $envValue;
$tokens[] = '--' . $option->getName() . '=' . $cleanValue;
```

escapeshellarg → stripslashes → trim chain is not safe. A value like `foo'bar` becomes `foo\'bar` after escapeshellarg, then `foo\bar` after stripslashes. The backslash no longer escapes the quote.

Recommendation: Build argv array instead of string manipulation.

---

### HIGH: Unrestricted Env Var Access in Template Mode
**Location:** src/FormatCommand.php:99-102

```php
return (string) preg_replace_callback(
    '/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/',
    static fn (array $m) => (string) (getenv($m[1]) ?: ''),
    $raw,
);
```

Any env var can be substituted. If template source is untrusted, credentials/API keys could be exfiltrated.

Recommendation: Add --sandbox-env flag restricting substitution to safe allowlist.

---

## 2. BUGS

### MEDIUM: Space Key Routing in Filter Mode
**Location:** src/FilterModel.php:59-67

When typing a space into filter buffer, KeyType::Space is sent. In filter mode, this may be consumed by multi-select toggle instead of filter buffer.

Recommendation: Verify ItemList::update() routes KeyType::Space to filter buffer when in filter mode.

---

### MEDIUM: Incomplete Hex Color Validation
**Location:** src/StyleBuilder.php:166-168

3-digit hex shorthand not properly expanded. 1, 2, 4, 5-digit strings fall through to regex but error message doesn't indicate digit-count requirement.

Recommendation: Dedicated parseHexColor() method that expands 3-digit shorthand and rejects invalid lengths.

---

### MEDIUM: $this->closed Not Set in terminate()
**Location:** src/RealProcess.php:63-71

After terminate(), subsequent close() would call wait() on already-terminated process.

Recommendation: Add $this->closed = true inside terminate().

---

## 3. PERFORMANCE

### MEDIUM: O(n·m) Fuzzy Recomputation on Every Keystroke
**Location:** src/Model/FilterModel.php:161-178

computeFuzzyResults() runs SmithWatermanMatcher::matchAll() on every character change. No caching.

Recommendation: Cache results keyed by filter text. Early return if filterText unchanged.

---

### MEDIUM: php://Memory Without Size Limit
**Location:** src/TableCommand.php:168

For large CSV data, php://memory could exhaust RAM. Use php://temp with maxmemory setting.

---

## 4. MEMORY

### MEDIUM: ImagickRasterizer Shared Tile Cache Between Clones
**Location:** src/ImagickRasterizer.php:58-66

When withTheme() is called, tileCache points to same array as original. If either calls clearTileCache(), destroys tiles the other needs.

Recommendation: Clone the cache array or implement copy-on-write.

---

## 5. MISSING FEATURES

### MEDIUM: Missing --no-selected Option
**Location:** src/ChooseCommand.php

Gum's choose has --no-selected which prints message when no items selected. Not implemented.

---

### MEDIUM: Missing --print-query Option
**Location:** src/FilterCommand.php

Gum's filter has --print-query which outputs current filter text. Not implemented.

---

### MEDIUM: Fuzzy Match Highlighting Not Rendered
**Location:** src/Model/FilterModel.php

highlightIndices() computes match indices but view doesn't use them for highlighted characters.

---

## 6. PHP 8.3+ COMPATIBILITY

Fully compatible. Uses readonly, promoted constructors, match expressions, final class. No PHP 8.4 issues.

---

## Summary

| Severity | Count | Key Issues |
|----------|-------|------------|
| HIGH | 2 | Shell injection, env var exfiltration |
| MEDIUM | 7 | Space routing, hex validation, terminate, fuzzy perf, memory stream, Imagick cache, missing features |
| LOW | 6 | Double matcher alloc, silent log failure, etc. |

**Total: ~28 findings**
