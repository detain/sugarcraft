# Implementation Plan: sugar-crumbs Code Audit Fixes

**Library:** `sugarcraft/sugar-crumbs`
**Plan Date:** 2026-06-30
**Audit Source:** `/home/sites/sugarcraft/findings/sugar-crumbs.md`

---

## Goal

Address all HIGH and MEDIUM severity findings from the sugar-crumbs code audit, implement key LOW severity improvements, and add missing standard PHP interfaces to bring the library to production-ready quality.

---

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Return copy in `NavStack::items()` instead of removing method | API compatibility — callers rely on `items()` to inspect stack contents; returning a copy prevents mutation without breaking existing code | `sugar-crumbs.md:1.1` |
| Implement Closable lifecycle in NavStack rather than removing interface | Closable provides a useful extension point; callers can subclass NavigationItem to run side-effects on navigation events | `sugar-crumbs.md:1.2` |
| Wire Escape::title() in Breadcrumb::render() | Escape exists to handle titles containing the separator string; this is a legitimate bug that corrupts rendering | `sugar-crumbs.md:1.3` |
| Wire Lang::t() into Breadcrumb defaults | lang/en.php infrastructure is in place; wiring it properly fulfills the i18n contract rather than leaving dead code | `sugar-crumbs.md:1.4` |
| Implement Countable and IteratorAggregate on NavStack | PHP standard interfaces make the class more ergonomic and interoperable with PHP's built-in functions | `sugar-crumbs.md:6.1,6.3` |
| Add title validation to NavStack::push() | Empty/whitespace titles produce confusing output like "Home ›  › Settings" | `sugar-crumbs.md:1.5` |
| Fix setSeparator() to reject whitespace-only strings | Whitespace-only separator produces invisible breadcrumbs | `sugar-crumbs.md:1.6` |
| Document popTo() clamping behavior as intentional | Silent clamping is documented in docblock but not explicitly tested; adding a test makes intent clear | `sugar-crumbs.md:1.7` |

---

## Phase 1: Critical Bug Fixes [PENDING]

### 1.1 NavStack::items() Returns Internal Array By Reference — Mutability Violation

**Severity:** HIGH

**What:** Change `NavStack::items()` to return a copy of the internal array instead of a direct reference.

**Why:** Callers can mutate the returned array and corrupt the NavStack's internal state, leading to unpredictable behavior.

**Related Code:**
- `src/NavStack.php:139-142` — `items()` method returning `$this->items` directly
- `tests/NavStackTest.php:69-78` — existing test for items() that would need updating

**Investigation Notes:**
- The `items()` method at line 139-142 directly returns `$this->items`
- Existing test `testItemsReturnsAllItems()` at NavStackTest.php:69-78 does NOT test immutability
- `Shell.php:32,45,57,77` uses `$this->stack->items()` as input to `setItems()` — these all create copies via `setItems()` anyway
- The fix is to return `[...$this->items]` or `array_values($this->items)`

**Implementation:**
```php
// src/NavStack.php line 139-142 — change:
public function items(): array
{
    return [...$this->items];  // returns a copy, not a reference
}
```

**Conditions for Success:**
- [ ] `NavStackTest::testItemsReturnsAllItems` passes
- [ ] New test: mutate returned array, verify NavStack is unaffected
- [ ] `vendor/bin/phpunit sugar-crumbs` passes

---

### 1.2 Closable Lifecycle Events Are Never Invoked

**Severity:** HIGH

**What:** Implement `onEnter()` and `onLeave()` calls in `NavStack` when items become current/leave current state. Call `$top->onLeave()` before `pop()` and `$newTop->onEnter()` after `push()`.

**Why:** The Closable interface and NavigationItem's implementations exist but are dead code; lifecycle events never fire, making the interface useless.

**Related Code:**
- `src/NavStack.php:52-56` — `push()` method
- `src/NavStack.php:64-70` — `pop()` method
- `src/NavigationItem.php:27-39` — `onEnter()`/`onLeave()` implementations
- `src/Closable.php:13-22` — interface definition
- `tests/ClosableTest.php` — existing tests for Closable (only test no-op behavior)

**Investigation Notes:**
- `NavigationItem` implements `Closable` and provides no-op defaults for `onEnter()`/`onLeave()`
- No code in `NavStack` ever calls these methods
- `popTo()` should also call `onLeave()` on all popped items

**Implementation:**
```php
// src/NavStack.php push() — call onEnter on new top:
public function push(string $title, mixed $data = null): self
{
    $newItem = new NavigationItem($title, $data);
    $newItem->onEnter();  // Lifecycle event
    $this->items[] = $newItem;
    return $this;
}

// src/NavStack.php pop() — call onLeave on popped item:
public function pop(): ?NavigationItem
{
    if ($this->items === []) {
        return null;
    }
    $popped = \array_pop($this->items);
    $popped->onLeave();  // Lifecycle event
    return $popped;
}
```

**Conditions for Success:**
- [ ] New test: verify onEnter is called when item becomes current
- [ ] New test: verify onLeave is called when item leaves current state
- [ ] `vendor/bin/phpunit sugar-crumbs` passes

---

### 1.3 Escape::title() Never Called in Breadcrumb::render()

**Severity:** HIGH

**What:** Call `Escape::title()` on each title in `Breadcrumb::render()` before using it in `doRender()`, so titles containing the separator string are properly escaped.

**Why:** A NavStack with items `['A › B', 'C']` and separator `" › "` would render incorrectly as two separate crumbs rather than one escaped crumb.

**Related Code:**
- `src/Breadcrumb.php:156-181` — `render()` method
- `src/Breadcrumb.php:173-175` — where title is assigned but not escaped
- `src/Escape.php:18-21` — `Escape::title()` implementation
- `tests/BreadcrumbTest.php:293-304` — test `testBreadcrumbTitleContainingSeparatorRendersCorrectly`
- `tests/BreadcrumbTest.php:268-291` — test `testTruncationWithScannerZoneCountMatchesVisibleCrumbs`

**Investigation Notes:**
- `Escape::title()` escapes the hardcoded separator `' > '` (from `Escape::SEPARATOR`)
- BUT `Breadcrumb` uses separator `' › '` by default (defined at Breadcrumb.php:44)
- **CRITICAL FINDING:** There's a SEPARATOR MISMATCH — `Escape::title()` escapes `' > '` but `Breadcrumb::render()` uses `' › '` as default separator
- The comment in `BreadcrumbTest.php:273-274` acknowledges this: `"Escape::title() uses hardcoded separator ' > ', different from Breadcrumb's default ' › '"` — so the bug isn't currently triggered by the default separator, but would be if someone uses `' > '` as a custom separator
- The real fix is to escape using the ACTUAL separator being used in rendering

**Implementation:**
```php
// src/Breadcrumb.php render() method — apply Escape::title():
// In the render loop at line 173-175:
// Change:
$title = $item->title;
// To:
$title = Escape::title($item->title);  // Escape using ACTUAL separator
```

**Conditions for Success:**
- [ ] `tests/BreadcrumbTest::testBreadcrumbTitleContainingSeparatorRendersCorrectly` passes
- [ ] `tests/BreadcrumbTest::testTruncationWithScannerZoneCountMatchesVisibleCrumbs` passes
- [ ] New test: title containing Breadcrumb's actual separator renders as ONE crumb
- [ ] `vendor/bin/phpunit sugar-crumbs` passes

---

### 1.4 Lang Translations Are Dead Code

**Severity:** HIGH

**What:** Wire `Lang::t('separator')` and `Lang::t('truncator')` into Breadcrumb defaults, or remove the unused infrastructure. Recommended: wire it in to fulfill the i18n contract.

**Why:** `lang/en.php` defines `separator` and `truncator` keys, but `Breadcrumb` hardcodes `' › '` and `'… '` directly, making the i18n infrastructure dead code.

**Related Code:**
- `src/Breadcrumb.php:44-45` — hardcoded defaults:
  ```php
  private string $separator  = ' › ';
  private string $truncator  = '… ';
  ```
- `lang/en.php` — defines `'separator' => ' › '` and `'truncator' => '… '`
- `src/Lang.php` — extends `SugarCraft\Core\I18n\Lang` with namespace `'crumbs'`
- `tests/LangCoverageTest.php` — tests that lang/en.php exists and has keys

**Investigation Notes:**
- `Breadcrumb` has no dependency on `Lang` — the defaults are hardcoded strings
- The `Lang::t()` method in `src/Lang.php` extends `SugarCraft\Core\I18n\Lang::t()` which handles translation lookup
- Wiring Lang would require adding the dependency to Breadcrumb
- The test `LangCoverageTest` explicitly notes it does NOT assert that Lang::t() keys exist in src/ because the source is "purely computational"

**Implementation:**
```php
// src/Breadcrumb.php — in property declarations, change:
private string $separator  = ' › ';   // hardcoded default
private string $truncator  = '… ';  // hardcoded default

// To use Lang defaults:
private string $separator  = Lang::t('separator');   // uses i18n
private string $truncator  = Lang::t('truncator');  // uses i18n
```

**Conditions for Success:**
- [ ] `LangCoverageTest` still passes (lang/en.php has the keys)
- [ ] `BreadcrumbTest` tests pass (no behavior change with en.php defaults)
- [ ] `vendor/bin/phpunit sugar-crumbs` passes

---

## Phase 2: Input Validation [PENDING]

### 1.5 NavStack::push() Accepts Empty/Whitespace-Only Titles

**Severity:** MEDIUM

**What:** Add validation to `NavStack::push()` to reject empty or whitespace-only title strings.

**Why:** Empty string `''` or whitespace `'   '` passes and renders confusing output like `"Home ›  › Settings"`.

**Related Code:**
- `src/NavStack.php:52-56` — `push()` method with no validation

**Investigation Notes:**
- `NavStack::push()` directly creates a `NavigationItem` without any title validation
- A test for this does not exist in NavStackTest

**Implementation:**
```php
// src/NavStack.php:52-56 — add validation:
public function push(string $title, mixed $data = null): self
{
    if (\trim($title) === '') {
        throw new \InvalidArgumentException('Title must be non-empty');
    }
    $this->items[] = new NavigationItem($title, $data);
    return $this;
}
```

**Conditions for Success:**
- [ ] New test: `push('')` throws InvalidArgumentException
- [ ] New test: `push('   ')` (whitespace) throws InvalidArgumentException
- [ ] New test: `push('Valid Title')` works normally
- [ ] `vendor/bin/phpunit sugar-crumbs` passes

---

### 1.6 Breadcrumb::setSeparator() Accepts Whitespace-Only String

**Severity:** MEDIUM

**What:** Fix `setSeparator()` to reject whitespace-only strings using `trim($s) === ''` instead of `$s === ''`.

**Why:** Validation checks for empty string `''` and newlines, but allows whitespace-only strings like `'   '`, producing an invisible breadcrumb.

**Related Code:**
- `src/Breadcrumb.php:57-67` — `setSeparator()` method
- `tests/BreadcrumbTest.php:168-180` — existing tests for setSeparator validation

**Investigation Notes:**
- Current validation at line 59 checks `$s === ''` and rejects newlines at line 62
- But whitespace-only strings pass through since `'   ' !== ''` evaluates to true

**Implementation:**
```php
// src/Breadcrumb.php:57-67 — fix validation:
public function setSeparator(string $s): self
{
    if (\trim($s) === '') {
        throw new \InvalidArgumentException('Breadcrumb separator must be non-empty and single-line');
    }
    if (preg_match('/[\r\n]/', $s) === 1) {
        throw new \InvalidArgumentException('Breadcrumb separator must be non-empty and single-line');
    }
    $this->separator = $s;
    return $this;
}
```

**Conditions for Success:**
- [ ] New test: `setSeparator('   ')` (whitespace-only) throws InvalidArgumentException
- [ ] Existing test `testSetSeparatorRejectsEmpty` still passes
- [ ] `vendor/bin/phpunit sugar-crumbs` passes

---

### 1.7 popTo() Silently Clamps Out-of-Range Indices

**Severity:** MEDIUM

**What:** Verify the clamping behavior is documented and add an explicit test. The existing test `testPopToClampsOutOfRange` already tests this behavior.

**Why:** `popTo(-5)` silently produces an empty stack instead of signaling an error. However, the docblock already documents this behavior, and there is an existing test.

**Related Code:**
- `src/NavStack.php:91-95` — `popTo()` method with clamping
- `tests/NavStackTest.php:303-328` — existing test `testPopToClampsOutOfRange` that explicitly tests clamping

**Investigation Notes:**
- The existing test `testPopToClampsOutOfRange` (NavStackTest.php:316-328) already tests both positive clamping (`popTo(99)`) and negative clamping (`popTo(-5)`)
- The test at line 326-327 explicitly asserts `popTo(-5)` clamps to empty stack
- So the behavior is INTENTIONAL and TESTED — the finding is about documentation clarity

**Decision:** Keep the clamping behavior (it's documented, tested, and the upstream bubbleo NavStack does the same). Close the finding as "working as designed but undocumented in tests". Update docblock to clarify.

**Implementation:**
```php
// src/NavStack.php:91-95 — update docblock to clarify:
// Change:
/**
 * Truncate the stack to the item at $index (inclusive), removing everything newer.
 * The item at position $index becomes the top.
 *
 * Out-of-range indices are clamped silently: negative clamps to empty,
 * oversized clamps to current depth.
 *
 * Mirrors bubbleo NavStack truncation.
 */
// To:
/**
 * Truncate the stack to the item at $index (inclusive), removing everything newer.
 * The item at position $index becomes the top.
 *
 * Out-of-range indices are clamped silently:
 * - Negative indices clamp to empty stack (index -5 → empty)
 * - Indices beyond current depth clamp to current depth
 *
 * This behavior is intentional (mirrors bubbleo NavStack) and is tested.
 *
 * @see testPopToClampsOutOfRange() for explicit clamping tests
 */
```

**Conditions for Success:**
- [ ] Existing test `testPopToClampsOutOfRange` passes (already does)
- [ ] `vendor/bin/phpunit sugar-crumbs` passes

---

## Phase 3: Missing PHP Interfaces [PENDING]

### 6.1 No count() Interface Support

**Severity:** LOW

**What:** Implement `\Countable` on `NavStack` with `public function count(): int { return $this->depth(); }`.

**Why:** `NavStack::depth()` exists but `count($navStack)` does not work; implementing Countable makes the class more ergonomic.

**Related Code:**
- `src/NavStack.php:31` — class declaration
- `src/NavStack.php:121-124` — `depth()` method

**Implementation:**
```php
// src/NavStack.php:31 — add Countable:
final class NavStack implements \Countable
{
    // ...

    // Add count() method:
    public function count(): int
    {
        return $this->depth();
    }
}
```

**Conditions for Success:**
- [ ] New test: `count($navStack)` works after implementing Countable
- [ ] `vendor/bin/phpunit sugar-crumbs` passes

---

### 6.3 Missing IteratorAggregate for Stack Iteration

**Severity:** LOW

**What:** Implement `\IteratorAggregate` on `NavStack` with `public function getIterator(): \Traversable { return new \ArrayIterator($this->items); }`.

**Why:** Without `getIterator()`/`IteratorAggregate`, consumers must call `items()` to iterate; adding IteratorAggregate enables `foreach ($navStack as $item)` syntax.

**Related Code:**
- `src/NavStack.php:31` — class declaration

**Implementation:**
```php
// src/NavStack.php:31 — already implements Countable, add IteratorAggregate:
final class NavStack implements \Countable, \IteratorAggregate
{
    // ...

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->items);
    }
}
```

**Conditions for Success:**
- [ ] New test: `foreach ($navStack as $item)` works
- [ ] `vendor/bin/phpunit sugar-crumbs` passes

---

## Phase 4: API Improvements [PENDING]

### 1.8 No Way to Clear or Reset itemRenderer

**Severity:** LOW

**What:** Modify `setItemRenderer()` to accept `null` to reset the item renderer to default behavior.

**Why:** `setItemRenderer(\Closure $fn)` has no corresponding reset method; once set, the only way to restore default rendering is to instantiate a new `Breadcrumb`.

**Related Code:**
- `src/Breadcrumb.php:88-92` — `setItemRenderer()` method
- `tests/BreadcrumbTest.php:149-159` — existing test for custom itemRenderer

**Implementation:**
```php
// src/Breadcrumb.php:88-92 — accept null to reset:
public function setItemRenderer(?\Closure $fn): self
{
    $this->itemRenderer = $fn;
    return $this;
}
```

**Conditions for Success:**
- [ ] New test: `setItemRenderer(null)` resets to default rendering
- [ ] `vendor/bin/phpunit sugar-crumbs` passes

---

## Phase 5: Performance Observations [PENDING]

### 2.1 Shell::with* Methods Copy Entire Stack On Every Navigation

**Severity:** MEDIUM

**What:** Document the O(n) copy behavior as expected for typical use cases (shallow stacks < 100 items).

**Why:** Each `withPush`/`withPop`/`withPopTo` creates a new NavStack, copies ALL items, then mutates. For a stack of depth N, every navigation operation is O(N).

**Related Code:**
- `src/Shell.php:30-60` — `withPush()`, `withPop()`, `withPopTo()` methods

**Investigation Notes:**
- `withPush`: `new NavStack()->setItems($this->stack->items())` — O(n) copy
- `withPop`: same O(n) copy pattern
- `withPopTo`: same O(n) copy pattern
- `pushDirectory`: same O(n) copy pattern

**Decision:** Document the performance characteristics in the Shell class docblock.

**Implementation:**
```php
// src/Shell.php — add performance note to class docblock:
/**
 * Shell — combines NavStack + Breadcrumb into a single navigation component.
 *
 * The Shell holds the current view (NavStack) and can render breadcrumbs
 * on demand. This mirrors the bubbleo Shell pattern.
 *
 * Note: The `with*` methods create new NavStack instances and copy all items
 * (O(n) where n = stack depth). For typical navigation stacks (< 100 items)
 * this is negligible. For deep histories, use mutable NavStack operations
 * directly.
 */
```

**Conditions for Success:**
- [ ] Docblock on Shell class documents O(n) performance
- [ ] `vendor/bin/phpunit sugar-crumbs` passes

---

### 2.2 Breadcrumb::effectiveWidth() Called Repeatedly in Truncation

**Severity:** LOW

**Decision:** No action needed — this is micro-optimization territory for typical stack sizes (< 20 items).

---

### 2.3 Breadcrumb::truncate() Creates Multiple Temporary Arrays Per Iteration

**Severity:** LOW

**Decision:** No action needed — this is micro-optimization territory.

---

## Phase 6: Info Findings — Documentation Only [PENDING]

### 3.1 No Memory Leak Issues Found

**Finding:** No undisposed streams, unclosed resources, or lingering references detected. No action needed.

---

### 4.1 NavStack::push() and Url::parse() Accept Arbitrary Strings

**Severity:** LOW

**What:** Add docblock note on `push()` and `Url::parse()` that consumers are responsible for validating/sanitizing titles if used in security-sensitive contexts.

**Implementation:**
```php
// src/NavStack.php:52 — add to docblock:
/**
 * Push a new navigation item onto the stack.
 *
 * @param string $title  Navigation title. Consumers are responsible for
 *                       validating/sanitizing this value if used in
 *                       security-sensitive contexts.
 * @param mixed $data    Optional arbitrary data to attach to this item.
 */
public function push(string $title, mixed $data = null): self

// src/Url.php:31 — add to docblock:
/**
 * Parse a URL path back into a NavStack.
 *
 * @param string $path  URL path. Consumers are responsible for validating
 *                      this value if used in security-sensitive contexts.
 */
public static function parse(string $path): NavStack
```

**Conditions for Success:**
- [ ] Docblocks updated to note consumer responsibility
- [ ] `vendor/bin/phpunit sugar-crumbs` passes

---

### 4.2 No SQL Injection or Code Execution Vectors

**Finding:** No database operations, no `eval()`, no dynamic code generation from user input. No action needed.

---

### 5.1 Mixed Mutable/Immutable API Is Documented But Still Confusing

**Finding:** Both NavStack and Breadcrumb document that `set*` methods mutate in place while `with*` methods return new instances. This is intentional. No action needed.

---

### 5.2 withZoneManager() Deprecated But Still Present

**Severity:** LOW

**Finding:** `withZoneManager()` is marked `@deprecated` but still implemented. The method is functional back-compat that internally delegates to Scanner.

**Decision:** No action needed — keep as-is for back-compat.

---

### 6.2 No JsonSerializable Interface

**Severity:** LOW

**What:** Implement `JsonSerializable` on `NavStack` to serialize items for debugging.

**Implementation:**
```php
// src/NavStack.php — add JsonSerializable:
final class NavStack implements \Countable, \IteratorAggregate, \JsonSerializable
{
    // ...

    public function jsonSerialize(): array
    {
        return $this->items;
    }
}
```

**Conditions for Success:**
- [ ] New test: `json_encode($navStack)` works
- [ ] `vendor/bin/phpunit sugar-crumbs` passes

---

### 6.4 NavigationItem Already Uses Readonly Promotion

**Finding:** Already uses PHP 8.1 readonly promoted parameters — no action needed.

---

### 7.1 No PHP 8.4 Features Used — Fully Compatible

**Finding:** The codebase uses `declare(strict_types=1)`, readonly properties, constructor property promotion, `mixed` type, and named arguments — all available in PHP 8.3. No action needed.

---

### 8.1 No Async Support

**Finding:** All methods are synchronous and blocking. For TUI use cases, this is correct by design.

**Decision:** Document this is by design in the class docblocks.

---

## Summary Table

| # | Finding | Severity | Status | Files |
|---|---------|----------|--------|-------|
| 1.1 | `items()` returns internal array by reference | HIGH | To Fix | `src/NavStack.php:139-142` |
| 1.2 | Closable lifecycle never invoked | HIGH | To Fix | `src/NavStack.php:52-70` |
| 1.3 | `Escape::title()` never called | HIGH | To Fix | `src/Breadcrumb.php:173-175` |
| 1.4 | Lang translations unused | HIGH | To Fix | `src/Breadcrumb.php:44-45` |
| 1.5 | `push()` no title validation | MEDIUM | To Fix | `src/NavStack.php:52-56` |
| 1.6 | `setSeparator()` allows whitespace-only | MEDIUM | To Fix | `src/Breadcrumb.php:57-67` |
| 1.7 | `popTo()` silently clamps negative indices | MEDIUM | Doc Fix | `src/NavStack.php:91-95` |
| 1.8 | No reset for itemRenderer | LOW | To Fix | `src/Breadcrumb.php:88-92` |
| 2.1 | O(n) copy on Shell with* | MEDIUM | Doc Fix | `src/Shell.php:30-60` |
| 2.2 | effectiveWidth called per iteration | LOW | No Fix | `src/Breadcrumb.php:232` |
| 2.3 | Temporary arrays in truncate loop | LOW | No Fix | `src/Breadcrumb.php:231` |
| 3.1 | No memory leaks | INFO | No Fix | — |
| 4.1 | Arbitrary strings accepted | LOW | Doc Fix | `src/NavStack.php`, `src/Url.php` |
| 4.2 | No injection vectors | INFO | No Fix | — |
| 5.1 | Mixed mutable/immutable API | LOW | No Fix | `src/NavStack.php`, `src/Breadcrumb.php` |
| 5.2 | Deprecated withZoneManager() present | LOW | Keep | `src/Breadcrumb.php:100-110` |
| 6.1 | No Countable interface | LOW | To Fix | `src/NavStack.php:31` |
| 6.2 | No JsonSerializable | LOW | To Fix | `src/NavStack.php` |
| 6.3 | No IteratorAggregate | LOW | To Fix | `src/NavStack.php:31` |
| 6.4 | Readonly promotion already used | INFO | No Fix | `src/NavigationItem.php:18-21` |
| 7.1 | PHP 8.4 compatible | INFO | No Fix | — |
| 8.1 | No async support | INFO | Doc Fix | All source files |

---

## Verification Commands

After all fixes are implemented, run:

```bash
cd sugar-crumbs && composer install && vendor/bin/phpunit
```

To validate composer.json:
```bash
composer validate --strict
```

To run with coverage:
```bash
vendor/bin/phpunit --coverage-text
```
