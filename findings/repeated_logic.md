# Repeated Logic Patterns Audit - SugarCraft Monorepo

**Date:** 2026-06-29
**Auditor:** Automated Research Agent
**Scope:** All candy-*, sugar-*, honey-* subdirectories

---

## Executive Summary

This audit identified **10 major categories** of repeated logic patterns across the SugarCraft PHP monorepo. Several patterns are well-centralized in `candy-core`, while others show significant duplication that could benefit from extraction into shared utilities.

---

## 1. Immutable Builder Pattern (`with*()` + `mutate()`)

### Description
The Elm-architecture-inspired immutable builder pattern where setter methods return new instances via a private `mutate()` method. This is the **most prevalent pattern** in the codebase.

### Statistics
- **Direct use of `Mutable` trait from candy-core:** 13 classes
- **Classes with local `private function mutate()` implementations:** 35 classes
- **`with*()` method calls:** 10,246 occurrences

### Canonical Implementation
**Source:** `candy-core/src/Concerns/Mutable.php:L27-L39`

```php
trait Mutable
{
    protected function mutate(array $changes): static
    {
        return new static(...array_merge(get_object_vars($this), $changes));
    }
}
```

### Classes Using the Trait
- `candy-flip/src/Player.php`
- `sugar-wishlist/src/Endpoint.php`
- `candy-query/src/App.php`
- `sugar-post/src/Email.php`
- `candy-zone/src/ClickCounter.php`
- `candy-shine/src/Style/StyleSheet.php`
- `candy-query/src/Admin/PerfSchema/*` (6 setup classes)
- `candy-forms/src/TextArea/TextArea.php`

### Classes With Local `mutate()` Implementations (Examples)

**`sugar-veil/src/Veil.php:L628`**
```php
private function mutate(array $changes): self
{
    return $this->mutateCore($changes);
}
```

**`candy-buffer/src/Style.php:L77`**
```php
private function mutate(array $changes): static
{
    return new static(...array_merge(get_object_vars($this), $changes));
}
```

**`candy-forms/src/Field/Input.php:L550`** (complex sentinel pattern)
```php
private function mutate(?TextInput $input = null, ?string $title = null, ?string $description = null, ?string $error = null, bool $errorSet = false): self
{
    $next = clone $this;
    if ($input !== null) { $next->input = $input; }
    if ($title !== null) { $next->title = $title; }
    // ... etc
    return $next;
}
```

### Key Insight
The `Mutable` trait exists in `candy-core` but **35 projects re-implement their own `mutate()` locally** rather than using the trait. This suggests either:
1. The trait doesn't handle edge cases (sentinel bools for nullable fields)
2. The trait isn't widely known/discoverable
3. The trait was added after many libraries were already written

---

## 2. Elm Architecture Model Interface

### Description
The `Model` interface enforces the TEA (The Elm Architecture) pattern: `init()`, `update(Msg): [Model, ?Cmd]`, `view(): string|View`, and `subscriptions(): ?Subscriptions`.

### Statistics
- **Classes implementing Model:** 92 (79 `final class`, 13 in tests/examples)
- **Interface definition:** `candy-core/src/Model.php`

### Interface Definition
**Source:** `candy-core/src/Model.php:L26-L62`

```php
interface Model
{
    public function init(): ?\Closure;
    public function update(Msg $msg): array;
    public function view(): string|View;
    public function subscriptions(): ?Subscriptions;
}
```

### Implementation Distribution

**candy-core (9):**
- `Composite`, `RootModelWithScreenStack`, `Panes`

**candy-* libs (20+):**
- `candy-files/src/Manager.php`
- `candy-forms/src/Form.php`, `Viewport.php`, `TextInput.php`, `ItemList.php`, `TextArea.php`, `FilePicker.php`, `Cursor.php`, `Spinner.php`
- `candy-flip/src/Player.php`
- `candy-mines/src/Game.php`
- `candy-tetris/src/Game.php`, `VsGame.php`
- `candy-mosaic/src/AnimationDriver.php`
- `candy-query/src/App.php`

**sugar-* libs (20+):**
- `sugar-bits/src/Table.php`, `Tabs.php`, `Tree.php`, `Timer.php`, `Stopwatch.php`, `Paginator.php`, `Progress/AnimatedProgress.php`
- `sugar-crush/src/Chat.php`
- `sugar-reel/src/Player.php`
- `sugar-stash/src/App.php`
- `sugar-tick/src/Dashboard.php`
- `sugar-glow/src/GlowModel.php`
- `sugar-dash/src/Modules/*` (multiple)

**honey-* libs (2):**
- `honey-flap/src/Game.php`

### Key Insight
The Model interface is **highly standardized and well-adopted**. All 92 implementations follow the same pattern of returning `[nextModel, optionalCmd]` from `update()`.

---

## 3. Internationalization (i18n) Pattern

### Description
Each library has a `Lang` facade class that extends `SugarCraft\Core\I18n\Lang` and delegates to the central `T` registry.

### Statistics
- **Lang facade classes:** 41 libraries
- **`Lang::t()` calls:** 503 occurrences
- **Lang files (en.php):** 41 libraries

### Central i18n Infrastructure

**`candy-core/src/I18n/T.php`** - The translation registry (lines 1-324)
```php
final class T
{
    private static array $namespaces = [];
    private static array $cache = [];
    private static string $locale = 'en';

    public static function register(string $namespace, string $dir): void;
    public static function translate(string $key, array $params = [], ?string $locale = null): string;
    public static function t(string $key, array $params = [], ?string $locale = null): string;
    public static function setLocale(string $locale): void;
    public static function detect(): string;
}
```

**`candy-core/src/I18n/Lang.php`** - Base class for per-library facades (lines 1-38)
```php
abstract class Lang
{
    protected const NAMESPACE = '';
    protected const DIR = '';

    public static function t(string $key, array $params = []): string
    {
        $ns = static::NAMESPACE;
        T::register($ns, static::DIR);
        return T::translate($ns . '.' . $key, $params);
    }
}
```

### Per-Library Lang Facade Pattern

**`sugar-bits/src/Lang.php:L18-L22`**
```php
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'bits';
    protected const DIR = __DIR__ . '/../lang';
}
```

**`candy-sprinkles/src/Lang.php:L18-L22`**
```php
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'sprinkles';
    protected const DIR = __DIR__ . '/../lang';
}
```

### Key Translation Pattern
**`sugar-bits/lang/en.php`** structure:
```php
<?php
return [
    'timer.duration_nonneg' => 'Duration must be non-negative',
    'timer.interval_positive' => 'Interval must be positive',
    // ...
];
```

### Key Insight
The i18n pattern is **highly standardized** with 41 identical Lang facade patterns and 503 call sites. The fallback chain (locale → base-language → en → raw key) is consistent.

---

## 4. Error Handling Pattern

### Description
Standardized validation errors using `throw new \InvalidArgumentException(Lang::t('...'))`.

### Statistics
- **InvalidArgumentException with Lang::t():** 131 occurrences
- **Pattern:** `throw new \InvalidArgumentException(Lang::t('error.key', ['param' => $value]));`

### Common Error Patterns

**Dimension validation (repeated 50+ times):**
```php
// candy-forms/src/Viewport/Viewport.php:L57
throw new \InvalidArgumentException(Lang::t('viewport.dim_nonneg'));

// sugar-charts/src/Heatmap/Heatmap.php:L55
throw new \InvalidArgumentException(Lang::t('heatmap.dim_nonneg'));

// sugar-bits/src/Table/Table.php:L66
throw new \InvalidArgumentException(Lang::t('table.dim_nonneg'));

// candy-mines/src/Board.php:L36
throw new \InvalidArgumentException(Lang::t('board.too_small'));
```

**Pattern validation:**
```php
// candy-forms/src/Validator/Pattern.php:L29
return $this->message ?? Lang::t('validator.pattern');

// candy-forms/src/Validator/Email.php:L20
return Lang::t('validator.email');

// candy-forms/src/Validator/Required.php:L17
return Lang::t('validator.required');
```

**Email/attachment validation:**
```php
// sugar-post/src/Email.php:L102
throw new \InvalidArgumentException(Lang::t('email.crlf_in_address'));

// sugar-post/src/Email.php:L114
throw new \InvalidArgumentException(Lang::t('email.invalid_address', ['addr' => $bareAddr]));
```

### Key Insight
The error handling pattern is **highly consistent** - 131 identical patterns using `Lang::t()` for user-facing error messages. The main improvement opportunity is potentially extracting common validation methods (e.g., `validateNonNeg()`, `validatePositive()`) into a shared validator utility.

---

## 5. Async/Promise Pattern (ReactPHP)

### Description
ReactPHP-based async operations using `Deferred`, `PromiseInterface`, and helper classes.

### Statistics
- **`React\Promise` usage:** 120 occurrences
- **`Deferred` usage:** 28 occurrences
- **`Cmd::promise()` / `Cmd::tick()`:** 78 occurrences

### AsyncOps in candy-async

**`candy-async/src/AsyncOps.php:L22-L205`**

```php
final class AsyncOps
{
    public static function withTimeout(
        LoopInterface $loop,
        PromiseInterface $promise,
        float $seconds,
    ): PromiseInterface { /* ... */ }

    public static function retry(
        callable $operation,
        int $attempts = 3,
        float $baseBackoffSeconds = 0.1,
        ?CancellationToken $token = null,
    ): PromiseInterface { /* ... */ }

    public static function debounce(callable $fn, float $seconds, ?LoopInterface $loop = null): callable { /* ... */ }

    public static function throttle(callable $fn, float $seconds, ?LoopInterface $loop = null): callable { /* ... */ }
}
```

### AsyncOps in candy-files

**`candy-files/src/AsyncOps.php:L18-L206`** (duplicated async pattern)

```php
final class AsyncOps
{
    public function copyAsync(string $src, string $dst): PromiseInterface
    {
        $deferred = new Deferred();
        \React\EventLoop\Loop::futureTick(static function () use ($src, $dst, $deferred): void {
            try {
                $result = self::doCopy($src, $dst);
                $deferred->resolve($result);
            } catch (\Throwable $e) {
                $deferred->resolve(false);
            }
        });
        return $deferred->promise();
    }

    public function copyManyAsync(array $map): PromiseInterface
    {
        return \React\Promise\all($promises)->then(/* ... */);
    }
}
```

### Cmd Factory Pattern

**`candy-core/src/Cmd.php:L18-L136`**

```php
final class Cmd
{
    public static function tick(float $seconds, \Closure $produce): \Closure
    {
        return static fn (): Msg => new TickRequest($seconds, $produce);
    }

    public static function promise(\Closure $factory): \Closure
    {
        return static fn (): AsyncCmd => new AsyncCmd($factory());
    }

    public static function batch(?\Closure ...$cmds): \Closure { /* ... */ }
    public static function sequence(?\Closure ...$cmds): \Closure { /* ... */ }
    public static function every(float $seconds, \Closure $produce): \Closure { /* ... */ }
}
```

### Key Insight
There are **two separate `AsyncOps` classes** (`candy-async/src/AsyncOps.php` and `candy-files/src/AsyncOps.php`) implementing similar patterns. The file operations in `candy-files` could potentially reuse `candy-async`'s utilities. Both use the same `Deferred` + `futureTick` pattern.

---

## 6. Factory Method Pattern (`::new()`)

### Description
Standardized factory method `::new()` for creating new instances.

### Statistics
- **`::new()` calls:** 3,042 occurrences across the codebase

### Pattern
```php
public static function new(): self
{
    return new self();
}
```

### Examples
```php
// sugar-bits/src/Stopwatch/Stopwatch.php:L33
public static function new(float $interval = 1.0): self
{
    if ($interval <= 0.0) {
        throw new \InvalidArgumentException(Lang::t('stopwatch.interval_positive'));
    }
    return new self(0.0, $interval, false);
}

// candy-sprinkles/src/Style.php:L103
public static function new(): self
{
    return new self();
}
```

### Key Insight
The `::new()` factory is **ubiquitous** (3,042 uses). Some factories include validation (like `Stopwatch::new()`), others are simple wrappers. This is a strong convention that should be documented as required for all new classes.

---

## 7. Terminal Width Measurement Utility

### Description
`SugarCraft\Core\Util\Width` class for measuring terminal display width accounting for ANSI codes, emoji, and CJK characters.

### Location
**`candy-core/src/Util/Width.php`** (736 lines)

### Capabilities
```php
final class Width
{
    public static function string(string $s): int;       // Cell width of string
    public static function of(string $s): int;            // Alias of string()
    public static function truncate(string $s, int $max): string;
    public static function truncateMiddle(string $s, int $max, string $ellipsis = '…'): string;
    public static function padRight(string $s, int $width, string $pad = ' '): string;
    public static function padLeft(string $s, int $width, string $pad = ' '): string;
    public static function padCenter(string $s, int $width, string $pad = ' '): string;
    public static function wrap(string $s, int $max): string;
    public static function wrapAnsi(string $s, int $max): string;
    public static function truncateAnsi(string $s, int $max): string;
    public static function dropAnsi(string $s, int $skip): string;
}
```

### Key Insight
This utility is **well-centralized in candy-core** and handles complex Unicode/grapheme width calculation. It is used extensively by styled text rendering libraries.

---

## 8. Validator Interface Pattern

### Description
`candy-forms` provides a `Validator` interface and concrete implementations for input validation.

### Location
**`candy-forms/src/Validator/`**

### Interface
**`candy-forms/src/Validator/Validator.php:L12-L17`**

```php
interface Validator
{
    /**
     * @return true|string True if $input is valid, error message string otherwise
     */
    public function validate(string $input): true|string;
}
```

### Implementations
- `Required.php` - validates non-empty input
- `Email.php` - validates email format
- `Pattern.php` - validates against regex
- `MinLength.php` - validates minimum length
- `MaxLength.php` - validates maximum length

### Example
**`candy-forms/src/Validator/Required.php:L12-L21`**

```php
final class Required implements Validator
{
    public function validate(string $input): true|string
    {
        if ($input === '') {
            return Lang::t('validator.required');
        }
        return true;
    }
}
```

### Key Insight
The validator interface is **defined in candy-forms and re-exported from sugar-prompt** for backward compatibility. This is a clean pattern but only used within forms/prompt contexts, not across the broader monorepo.

---

## 9. Clamping/Range Limiting Pattern

### Description
Various forms of value clamping to ensure values stay within valid ranges.

### Statistics
- **`clamp()` method definitions:** 4 occurrences
- **`clamp()` method calls:** 28 occurrences

### Examples

**`candy-forms/src/Viewport/Viewport.php:L462`** - Private method
```php
private function clamp(): self
{
    return $this->copy(
        yOffset: max(0, min($this->yOffset, $this->maxOffset())),
        xOffset: max(0, min($this->xOffset, $this->maxXOffset())),
    )->copy(
        width: max(1, $this->width),
        height: max(1, $this->height),
    );
}
```

**`sugar-stash/src/App.php:L515`** - Standalone helper
```php
private function clamp(int $i, int $size): int
{
    return max(0, min($i, $size - 1));
}
```

**`candy-palette/src/Color.php:L67`** - Static private helper
```php
private static function clamp(int $v): int
{
    return max(0, min(255, $v));
}
```

**`sugar-bits/src/Tree/Tree.php:L317`** - Private method
```php
private function clamp(): self
{
    return $this->copy(cursor: max(0, min($this->cursor, $this->visibleCount() - 1)));
}
```

### Key Insight
Clamping logic is **duplicated across 4+ libraries** with different implementations. A shared `clamp(int $value, int $min, int $max): int` utility in `candy-core` could eliminate this duplication.

---

## 10. ANSIAware String Utilities

### Description
Shared ANSI escape sequence handling in `SugarCraft\Core\Util\Ansi`.

### Location
**`candy-core/src/Util/Ansi.php`**

### Capabilities
- Strip ANSI sequences: `Ansi::strip($s)`
- Parse ANSI sequences (CSI, OSC handlers)
- Build SGR sequences for colors/styles
- Terminal queries (cursor position, color profiles, etc.)

### Usage Pattern
```php
// candy-core/src/Util/Width.php:L21
$clean = Ansi::strip($s);
```

### Key Insight
This is **well-centralized in candy-core** and used by the Width utility and various renderers. No duplication detected.

---

## Summary of Duplication Issues

| Pattern | Centralized? | Duplication Level | Recommendation |
|---------|--------------|-------------------|----------------|
| Mutable trait + mutate() | Partially | High (35 local implementations) | Promote trait adoption or document when local impl is needed |
| Model interface | Yes | None (standardized) | Continue current approach |
| i18n Lang::t() | Yes | None (standardized) | Continue current approach |
| Error handling | Yes | Low (131 consistent patterns) | Consider extracting common validators |
| Async/Promise | Partially | Medium (2 AsyncOps classes) | Consider merging file ops into candy-async |
| ::new() factory | Yes | None (convention) | Document as required pattern |
| Width utility | Yes | None (well-centralized) | Continue current approach |
| Validator interface | Yes | Low | Already well-structured |
| Clamping | No | Medium | Extract to candy-core utility |

---

## Recommendations

### High Priority

1. **Audit the `Mutable` trait adoption gap** - 35 classes re-implement `mutate()` rather than using the trait. Understand why and either:
   - Update the trait to handle more edge cases
   - Document when local implementation is preferred
   - Create a migration script to adopt the trait

2. **Merge AsyncOps utilities** - `candy-files/src/AsyncOps.php` duplicates much of `candy-async/src/AsyncOps.php`. Consider extracting common patterns into `candy-async`.

### Medium Priority

3. **Create shared clamping utility** - Extract `clamp(int $min, int $max, int $value): int` into `candy-core/src/Util/` for use across the monorepo.

4. **Document the `::new()` factory contract** - With 3,000+ uses, this is a de facto standard. Consider adding it to CALIBER_LEARNINGS.md as a required pattern.

### Low Priority

5. **Consider Validator extraction** - The `Validator` interface in `candy-forms` could potentially serve the broader monorepo for input validation needs.

---

## Files Referenced

### candy-core (Foundation)
- `candy-core/src/Concerns/Mutable.php` - Mutable trait
- `candy-core/src/Model.php` - Model interface
- `candy-core/src/Cmd.php` - Cmd factory
- `candy-core/src/I18n/T.php` - Translation registry
- `candy-core/src/I18n/Lang.php` - Base Lang class
- `candy-core/src/Util/Width.php` - Terminal width utilities
- `candy-core/src/Util/Ansi.php` - ANSI utilities
- `candy-core/src/AsyncCmd.php` - Async command wrapper

### candy-async
- `candy-async/src/AsyncOps.php` - Async utilities (timeout, retry, debounce, throttle)

### candy-files
- `candy-files/src/AsyncOps.php` - File-specific async operations (DUPLICATED)

### candy-forms
- `candy-forms/src/Validator/Validator.php` - Validator interface
- `candy-forms/src/Validator/Required.php`, `Email.php`, `Pattern.php`, `MinLength.php`, `MaxLength.php`

### sugar-bits
- `sugar-bits/src/Lang.php` - Per-library Lang facade
- `sugar-bits/src/Stopwatch/Stopwatch.php` - Model implementation example
