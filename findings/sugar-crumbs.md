# Sugar-Crumbs Code Audit Findings

**Library:** `sugarcraft/sugar-crumbs`  
**Audit Date:** 2026-06-30  
**Files Audited:** 8 source files (`Breadcrumb.php`, `Closable.php`, `Escape.php`, `Lang.php`, `NavigationItem.php`, `NavStack.php`, `Shell.php`, `Url.php`), 7 test files  
**PHP Version Target:** 8.3+

---

## Severity Scale

- **HIGH**: Critical bugs, security issues, or broken functionality
- **MEDIUM**: Important issues affecting correctness or maintainability
- **LOW**: Minor issues, API design concerns, or missed opportunities
- **INFO**: Observations, not necessarily problems

---

## 1. ISSUES (Bugs, Edge Cases, Error Handling)

### 1.1 NavStack::items() Exposes Internal Array By Reference — Mutability Violation

- **Severity:** HIGH
- **Location:** `src/NavStack.php:139-142`
- **Finding:**
  ```php
  public function items(): array
  {
      return $this->items;  // Returns direct reference, not a copy
  }
  ```
  A caller can mutate the returned array and corrupt the NavStack's internal state:
  ```php
  $items = $navStack->items();
  $items[] = new NavigationItem('malicious');  // Corrupts NavStack internal state
  ```
- **Recommendation:** Return a copy: `return [...$this->items];` or `return array_values($this->items);`

---

### 1.2 Closable Lifecycle Events Are Never Invoked

- **Severity:** HIGH
- **Location:** `src/NavigationItem.php:27-39`, `src/Closable.php:16-22`
- **Finding:** `NavigationItem` implements `Closable` with `onEnter()` and `onLeave()` methods, but **no code in src/ ever calls these methods**. NavStack never fires `onEnter` when an item becomes current or `onLeave` when leaving an item. The interface exists but is dead code.
- **Recommendation:** Either remove the `Closable` interface and methods (if lifecycle is out-of-scope), or implement the lifecycle in `NavStack`:
  - Call `$top->onLeave()` before `pop()` 
  - Call `$newTop->onEnter()` after `push()`

---

### 1.3 Escape::title() Never Used in Breadcrumb Rendering

- **Severity:** HIGH
- **Location:** `src/Escape.php:18-21`, `src/Breadcrumb.php:174`
- **Finding:** `Escape::title()` exists to escape titles containing the separator string. However, `Breadcrumb::render()` (line 174) never calls it. A NavStack with items `['A › B', 'C']` and separator `" › "` would render incorrectly — the title `"A › B"` would appear as two separate crumbs rather than one escaped crumb.
- **Recommendation:** Call `Escape::title()` on each title in `Breadcrumb::render()` before using it, or document clearly that consumers must escape titles before pushing them.

---

### 1.4 Lang Translations Are Dead Code

- **Severity:** HIGH
- **Location:** `lang/en.php`, `src/Breadcrumb.php:44-45`, `src/Lang.php`
- **Finding:** `lang/en.php` defines `separator` and `truncator` translation keys. `Breadcrumb` hardcodes `$this->separator = ' › '` and `$this->truncator = '… '` directly. The i18n infrastructure is wired but never used — translations have zero effect on rendering.
- **Recommendation:** If i18n is desired, inject `Lang` into `Breadcrumb` and use `Lang::t('separator')` as default. Otherwise, remove `lang/en.php` and `src/Lang.php` to reduce dead code.

---

### 1.5 NavStack::push() Accepts Empty/Whitespace-Only Titles

- **Severity:** MEDIUM
- **Location:** `src/NavStack.php:52-56`
- **Finding:**
  ```php
  public function push(string $title, mixed $data = null): self
  {
      $this->items[] = new NavigationItem($title, $data);  // No validation
      return $this;
  }
  ```
  No validation on `$title`. Empty string `''` or whitespace `'   '` passes. This renders confusing output like `"Home ›  › Settings"`.
- **Recommendation:** Add validation: `if (trim($title) === '') throw new \InvalidArgumentException('Title must be non-empty');`

---

### 1.6 Breadcrumb::setSeparator() Accepts Whitespace-Only String

- **Severity:** MEDIUM
- **Location:** `src/Breadcrumb.php:59`
- **Finding:** Validation checks for empty string `''` and newlines `\r\n`, but allows whitespace-only strings like `'   '`. A separator of only spaces produces an invisible breadcrumb.
- **Recommendation:** Use `trim($s) === ''` instead of `$s === ''` to catch whitespace-only strings.

---

### 1.7 popTo() Silently Clamps Out-of-Range Indices

- **Severity:** MEDIUM
- **Location:** `src/NavStack.php:91-95`
- **Finding:** `popTo(-5)` on a 2-item stack silently produces an empty stack instead of signaling an error. The silent clamping behavior masks potential caller bugs.
- **Recommendation:** Throw `\OutOfRangeException` for negative indices, or document clearly that silent clamping is intentional.

---

### 1.8 No Way to Clear or Reset itemRenderer

- **Severity:** LOW
- **Location:** `src/Breadcrumb.php:88-92`
- **Finding:** `setItemRenderer(\Closure $fn)` has no corresponding reset method. Once set, the only way to restore default rendering is to instantiate a new `Breadcrumb`.
- **Recommendation:** Accept `null` to reset: `if ($fn === null) { $this->itemRenderer = null; return $this; }`

---

## 2. PERFORMANCE PROBLEMS

### 2.1 Shell::with* Methods Copy Entire Stack On Every Navigation

- **Severity:** MEDIUM
- **Location:** `src/Shell.php:30-60`
- **Finding:**
  ```php
  public function withPush(string $title, mixed $data = null): self
  {
      $newStack = (new NavStack())->setItems($this->stack->items());  // O(n) copy
      $newStack->push($title, $data);                                 // O(1) push
      return new self($newStack, $this->breadcrumb);
  }
  ```
  Each `withPush`/`withPop`/`withPopTo` creates a new `NavStack`, copies **all items**, then mutates. For a stack of depth N, every navigation operation is O(N).
- **Recommendation:** Document that shallow stacks (< 100 items) are the expected use case. For deep histories, consider a persistent data structure or a shared-nothing copy-on-write strategy.

---

### 2.2 Breadcrumb::effectiveWidth() Called Repeatedly in Truncation

- **Severity:** LOW
- **Location:** `src/Breadcrumb.php:202`, `src/Breadcrumb.php:232`
- **Finding:** In the truncate loop (line 232), `effectiveWidth($candidate)` is called on every iteration. Each call delegates to `Width::string($s)` which may allocate.
- **Recommendation:** Accept as micro-optimization for typical stack sizes (< 20 items).

---

### 2.3 Breadcrumb::truncate() Creates Multiple Temporary Arrays Per Iteration

- **Severity:** LOW
- **Location:** `src/Breadcrumb.php:231`
- **Finding:**
  ```php
  $candidate = $this->truncator . \implode($this->separator, \array_merge([$titles[$i]], \array_reverse($out)));
  ```
  Each iteration creates two temporary arrays plus an imploded string.
- **Recommendation:** Accept as micro-optimization territory.

---

## 3. MEMORY LEAKS

### 3.1 No Memory Leak Issues Found

- **Severity:** INFO
- **Finding:** No undisposed streams, unclosed resources, or lingering references detected. The `clone` pattern in `withScanner`/`withZoneManager` is correctly implemented. No circular references that would prevent GC.
- **Recommendation:** No action needed.

---

## 4. SECURITY

### 4.1 NavStack::push() and Url::parse() Accept Arbitrary Strings

- **Severity:** LOW
- **Location:** `src/NavStack.php:52-56`, `src/Url.php:31-42`
- **Finding:** These methods accept any string as a title/segment. If consumers use raw titles for security-sensitive operations (file paths, SQL), malicious input could be dangerous. However, `viewHtml()` properly escapes with `htmlspecialchars()`, and the library itself performs no security-sensitive operations.
- **Recommendation:** Document that consumers are responsible for validating/sanitizing titles if used in security-sensitive contexts.

---

### 4.2 No SQL Injection or Code Execution Vectors

- **Severity:** INFO
- **Finding:** No database operations, no `eval()`, no dynamic code generation from user input. Output is properly escaped in `viewHtml()`. No security concerns in the library itself.

---

## 5. COMPLEXITY

### 5.1 Mixed Mutable/Immutable API Is Documented But Still Confusing

- **Severity:** LOW
- **Location:** `src/NavStack.php:14-18`, `src/Breadcrumb.php:23-28`
- **Finding:** Both classes document that `set*` methods mutate in place while `with*` methods return new instances. This is a deliberate exception to the repo's immutable convention, but it creates cognitive overhead.
- **Recommendation:** Ensure the documentation is prominent. Consider runtime assertions or a readonly flag if PHP ever supports immutable arrays.

---

### 5.2 withZoneManager() Deprecated But Still Present

- **Severity:** LOW
- **Location:** `src/Breadcrumb.php:100-110`
- **Finding:** `withZoneManager()` is marked `@deprecated` but still implemented. It enables scanner-based zone tracking but becomes a no-op that ignores the Manager parameter. Adds maintenance surface.
- **Recommendation:** If Scanner is the intended replacement, consider removing `withZoneManager()` entirely in a major version.

---

## 6. MISSING FEATURES / INCOMPLETE PORTS

### 6.1 No count() Interface Support

- **Severity:** LOW
- **Location:** `src/NavStack.php`
- **Finding:** `NavStack::depth()` exists but `count($navStack)` does not work because NavStack doesn't implement `Countable`.
- **Recommendation:** Add `implements \Countable` with `public function count(): int { return $this->depth(); }`

---

### 6.2 No JsonSerializable Interface

- **Severity:** LOW
- **Location:** `src/NavStack.php`, `src/Breadcrumb.php`
- **Finding:** Neither class implements `JsonSerializable`.
- **Recommendation:** Implement `JsonSerializable` on `NavStack` to serialize items for debugging.

---

### 6.3 Missing IteratorAggregate for Stack Iteration

- **Severity:** LOW
- **Location:** `src/NavStack.php`
- **Finding:** No `getIterator()` / `IteratorAggregate` — consumers must call `items()` to iterate.
- **Recommendation:** Add `implements \IteratorAggregate` with `public function getIterator(): \Traversable { return new \ArrayIterator($this->items); }`

---

### 6.4 NavigationItem Already Uses Readonly Promotion

- **Severity:** INFO
- **Location:** `src/NavigationItem.php:18-21`
- **Finding:** Already uses PHP 8.1 readonly promoted parameters — no upgrade needed.

---

## 7. PHP 8.3/8.4 COMPATIBILITY

### 7.1 No PHP 8.4 Features Used — Fully Compatible

- **Severity:** INFO
- **Finding:** The codebase uses `declare(strict_types=1)`, readonly properties, constructor property promotion, `mixed` type, and named arguments — all available in PHP 8.3. No PHP 8.4-specific features are used, meaning it's compatible with PHP 8.3 and ready for 8.4.
- **Recommendation:** No action needed. Consider using PHP 8.4 property hooks in a future version if appropriate.

---

## 8. ASYNC/ReactPHP IMPROVEMENTS

### 8.1 No Async Support

- **Severity:** INFO
- **Location:** All source files
- **Finding:** All methods are synchronous and blocking. No ReactPHP promises, no async iterators, no streaming render.
- **Recommendation:** For TUI use cases, synchronous is likely correct. If async web rendering is needed, consider:
  - A `renderAsync(NavStack $stack): Promise<string>` method
  - A streaming renderer using `React\Stream\ThroughStream`
  - Document that current rendering is synchronous by design

---

## SUMMARY TABLE

| Severity | Category | Location | Issue |
|----------|----------|----------|-------|
| **HIGH** | Bug | `NavStack.php:139-142` | `items()` returns internal array by reference |
| **HIGH** | Missing Feature | `NavStack.php` (general) | `onEnter`/`onLeave` lifecycle never invoked |
| **HIGH** | Bug | `Breadcrumb.php:174` | `Escape::title()` never called on titles |
| **HIGH** | Dead Code | `Breadcrumb.php:44-45`, `lang/en.php` | Lang translations unused, hardcoded defaults |
| **MEDIUM** | Validation | `NavStack.php:52` | No validation on push() title parameter |
| **MEDIUM** | Validation | `Breadcrumb.php:59` | setSeparator allows whitespace-only strings |
| **MEDIUM** | Bug | `NavStack.php:91-95` | popTo silently clamps negative indices |
| **MEDIUM** | Performance | `Shell.php:30-60` | Full O(n) copy on every with* operation |
| **LOW** | API Design | `Breadcrumb.php:88-92` | No way to reset itemRenderer |
| **LOW** | Missing Interface | `NavStack.php` | No `Countable` implementation |
| **LOW** | Missing Interface | `NavStack.php` | No `IteratorAggregate` |
| **LOW** | Complexity | `Breadcrumb.php:100-110` | Deprecated withZoneManager() still present |
| **LOW** | Performance | `Breadcrumb.php:232` | effectiveWidth called per iteration |
| **INFO** | PHP 8.4 | All files | No 8.4 features — fully compatible |
| **INFO** | Async | All files | No async/ReactPHP support (by design) |
| **INFO** | Memory | All files | No memory leak issues found |
| **INFO** | Security | All files | No injection/execution vectors |

---

## RECOMMENDED PRIORITY FIXES (In Order)

1. **`NavStack::items()`** — Return a copy (`return [...$this->items]`) instead of direct reference
2. **Closable lifecycle** — Either implement `onEnter`/`onLeave` calls in NavStack or remove the dead interface
3. **Escape in Breadcrumb::render()** — Call `Escape::title()` on titles or document consumer responsibility
4. **Lang/i18n** — Wire `Lang::t()` into Breadcrumb or remove the unused infrastructure
5. **NavStack::push() title validation** — Reject empty/whitespace titles
6. **setSeparator() validation** — Use `trim($s) === ''` instead of `$s === ''`
7. **Add Countable to NavStack** — Implement `count(): int` via `depth()`
8. **Add IteratorAggregate to NavStack** — For ergonomic iteration
