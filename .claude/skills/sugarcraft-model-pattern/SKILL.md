---
name: sugarcraft-model-pattern
description: Scaffolds a new immutable+fluent SugarCraft class — `final`, `declare(strict_types=1)`, a private constructor with public `readonly` promoted props, `::new()` factory, `with*()` setters that return a new instance, bare accessors (no `get`), and (for TUI roots) the candy-core `Model` contract `init()`/`update(Msg): [Model, ?Cmd]`/`view()`/`subscriptions()`. Mirror `candy-sprinkles/src/Style.php` (value object) or `sugar-bits/src/Stopwatch/Stopwatch.php` (Model). Use when the user says 'add a Model', 'new TUI widget', 'scaffold a SugarCraft class', 'port from charmbracelet/<x>', or creates files under `<slug>/src/`. Do NOT use for editing an existing class (use direct Edit), tests-only changes (use write-phpunit-test), scaffolding a whole new library skeleton (use scaffold-library), or non-SugarCraft repos.
paths:
  - "*/src/**/*.php"
---
# SugarCraft Model / value-object pattern

Scaffold a new immutable+fluent class that looks byte-identical to the rest of the monorepo. Two shapes: a **value object** (mirror `candy-sprinkles/src/Style.php`) and a **TUI root** implementing `SugarCraft\Core\Model` (mirror `sugar-bits/src/Stopwatch/Stopwatch.php`).

## Critical

- **First line is always `<?php`, second is blank, third is `declare(strict_types=1);`.** No exceptions.
- **Never mutate `$this`.** Every `with*()`, `update()`, and state transition returns a **NEW instance**. State is `public readonly`.
- **Constructor is `private`** with **promoted `public readonly` params**. Public entry is `::new()` — NEVER `::create()`, `::make()`, or `::default()`.
- **Accessors are bare** — `elapsed()`, not `getElapsed()`.
- **Nullable fields that a `with*()` can set need a paired `bool $XSet` sentinel** — `mutate()` cannot tell a passed `null` from an omitted arg. See `sugar-bits/src/TextInput/TextInput.php`.
- **Side effects go in a `Cmd` (a `Closure(): ?Msg`), never in `view()`.** `view()` is pure.
- **Namespace = slug mapped**: `candy-shine/` → `SugarCraft\Shine\`; quirk: `candy-core/` → `SugarCraft\Core\`. Subdir classes nest, e.g. `sugar-bits/src/Stopwatch/Stopwatch.php` → `SugarCraft\Bits\Stopwatch\`.
- **Doc-comment cites upstream**: `Mirrors charmbracelet/<repo>.<Method>` or `Mirror of {@see X}`. Comment WHY, not WHAT.
- **Validation throws** `\InvalidArgumentException` (with `Lang::t('<key>')`), never returns `null` for bad input.

## Instructions

### Step 1 — Resolve name, path, namespace
Given the target lib slug `<slug>` and class `<Class>`:
- File: `<slug>/src/<Class>.php`, or `<slug>/src/<Sub>/<Class>.php` if it groups with sibling types (Msg, enums) — e.g. `sugar-bits/src/Stopwatch/Stopwatch.php`.
- Namespace: kebab slug → PascalCase, drop the `candy-`/`sugar-`/`honey-` idea into `SugarCraft\<Sub>\`. Confirm against an existing file in that lib: `head -6 <slug>/src/*.php`.
- **Verify** the namespace matches a sibling file before writing. Do not guess.

### Step 2 — Pick the shape
- **Value object** (styling, config, DTO, geometry): no `Model` interface. → Step 3.
- **TUI root / widget** (has state that changes over time in a program): `implements SugarCraft\Core\Model`. → Step 4.

### Step 3 — Value object (mirror `candy-sprinkles/src/Style.php`)
Write:
```php
<?php

declare(strict_types=1);

namespace SugarCraft\<Sub>;

/**
 * <one-line role>. Mirrors charmbracelet/<repo>.<Type>.
 */
final class <Class>
{
    private function __construct(
        private readonly int $width = 0,
        private readonly ?Color $fg = null,
        private readonly bool $fgSet = false,   // sentinel for the nullable
    ) {}

    public static function new(): self
    {
        return new self();
    }

    /** Fluent setter — returns a NEW instance. */
    public function withWidth(int $width): self
    {
        if ($width < 0) {
            throw new \InvalidArgumentException(Lang::t('<slug>.width_nonnegative'));
        }
        return new self($width, $this->fg, $this->fgSet);
    }

    public function withFg(?Color $fg): self
    {
        return new self($this->width, $fg, true);
    }

    // Bare accessors (no get).
    public function width(): int { return $this->width; }
    public function fg(): ?Color { return $this->fg; }
}
```
For a class with **many** props, use the `Mutable` trait (`candy-core/src/Concerns/Mutable.php`) instead of hand-writing each `new self(...)`:
```php
use SugarCraft\Core\Concerns\Mutable;

final class <Class>
{
    use Mutable; // provides: protected function mutate(array $changes): static

    public function withWidth(int $width): static
    {
        return $this->mutate(['width' => $width]);
    }
}
```
`mutate()` does `new static(...array_merge(get_object_vars($this), $changes))`. This ONLY works when every constructor param name matches a property name. If you have sentinel fields, set them in the `$changes` array too: `$this->mutate(['fg' => $fg, 'fgSet' => true])`.
**Verify**: `php -l <slug>/src/<Class>.php` returns `No syntax errors`.

### Step 4 — TUI Model (mirror `sugar-bits/src/Stopwatch/Stopwatch.php`)
This step uses the class/namespace resolved in Step 1. Implement all four `Model` methods:
```php
<?php

declare(strict_types=1);

namespace SugarCraft\<Sub>;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Subscriptions;

/**
 * <one-line role>. Mirrors charmbracelet/bubbles/<type>.
 */
final class <Class> implements Model
{
    private function __construct(
        public readonly int $value,
        public readonly bool $running,
    ) {}

    public static function new(): self
    {
        return new self(0, false);
    }

    public function init(): ?\Closure
    {
        return null; // or return $this->tick();
    }

    /** @return array{0:Model, 1:?\Closure} */
    public function update(Msg $msg): array
    {
        if ($msg instanceof TickMsg && $this->running) {
            $next = new self($this->value + 1, true);
            return [$next, $next->tick()];
        }
        return [$this, null];   // unhandled Msg: return self, no Cmd
    }

    public function view(): string
    {
        return (string) $this->value; // PURE — no I/O, no side effects
    }

    public function subscriptions(): ?Subscriptions
    {
        return null;
    }

    private function tick(): \Closure
    {
        return Cmd::tick(1.0, static fn(): Msg => new TickMsg());
    }
}
```
- `update()` returns the tuple `[Model, ?Cmd]` — destructure at the call site with `[$m, $cmd] = $m->update($msg)`.
- Side-effect commands come from `SugarCraft\Core\Cmd`: `Cmd::tick()`, `Cmd::every()`, `Cmd::batch()`, `Cmd::sequence()`, `Cmd::send()`, `Cmd::quit()`, `Cmd::promise()`. Never do the effect inline.
- State-transition helpers (`start()`, `stop()`, `toggle()`) that may launch a Cmd return `array{0:self,1:?\Closure}`; pure ones (`reset()`) return `self`. Make idempotent transitions no-ops (see `Stopwatch::start()`).
**Verify**: `php -l` passes AND the class has all four `Model` methods (`init`, `update`, `view`, `subscriptions`).

### Step 5 — Companion Msg types
Each message a Model emits is its own `final` class in the same subdir (mirror `sugar-bits/src/Stopwatch/TickMsg.php`):
```php
<?php

declare(strict_types=1);

namespace SugarCraft\<Sub>;

use SugarCraft\Core\Msg;

/** <one-line>. */
final class TickMsg implements Msg
{
    public function __construct(public readonly int $id) {}
}
```
Route ticks by `id` when multiple instances of the same Model can coexist (see `Stopwatch::$nextId` + `TickMsg->id`).

### Step 6 — i18n any user-facing string
Exception messages and rendered labels go through `Lang::t('<slug>.<key>', [...])`, which wraps `SugarCraft\Core\I18n\T` (pattern in `candy-pty/src/Lang.php`). Do not hard-code English in a `throw` or `view()`.

### Step 7 — Verify before finishing
1. `php -l <slug>/src/<Class>.php` → `No syntax errors detected`.
2. `cd <slug> && composer install --quiet && vendor/bin/phpunit` (stale `vendor/` gives false failures — `composer update` first if a failure looks unrelated).
3. Tests are a separate step — hand off to the `write-phpunit-test` skill; do NOT ship a `src/` class with no test.

## Examples

**User says:** "Port charmbracelet/bubbles stopwatch into sugar-bits."

**Actions taken:**
1. Slug `sugar-bits` → namespace `SugarCraft\Bits\Stopwatch\`; files `sugar-bits/src/Stopwatch/Stopwatch.php` + `sugar-bits/src/Stopwatch/TickMsg.php`.
2. Shape = TUI Model (state changes over ticks) → Step 4.
3. Write `final class Stopwatch implements Model`: private ctor with `public readonly float $elapsed/$interval, bool $running`; `::new(float $interval = 1.0)` throwing `\InvalidArgumentException(Lang::t('stopwatch.interval_positive'))` on `<= 0`; `update()` matches `TickMsg` by `id` and returns `[$next, $next->tick()]`; `start()` idempotent; `view()` returns `Timer::format($this->elapsed)`; `subscriptions()` returns null.
4. Write `final class TickMsg implements Msg` with `public readonly int $id`.
5. `php -l` both files → clean.

**Result:** Two files that match `sugar-bits/src/Stopwatch/*` exactly; ready for `write-phpunit-test`.

## Common Issues

- **`Too few arguments to function __construct()` from `mutate()`**: a constructor param name doesn't match its property name, so `get_object_vars()` can't supply it. Rename so promoted param == property, or hand-write `new self(...)` instead of using the `Mutable` trait.
- **A `withFoo(null)` silently keeps the old value**: you're missing the sentinel. Add `bool $fooSet` to the constructor and pass `true` in that setter's `mutate(['foo' => $foo, 'fooSet' => true])`.
- **`Class SugarCraft\... not found` at test time**: namespace doesn't match the PSR-4 map for the slug. Run `head -6 <slug>/src/<AnyExisting>.php` and copy its `namespace` line's prefix. Remember `candy-core` → `SugarCraft\Core`, not `SugarCraft\CandyCore`.
- **`update()` return type error / `Cannot use [...] as array`**: `update()` must return the tuple `[$model, $cmd]`. Return `[$this, null]` for unhandled messages, never a bare `$this` or `void`.
- **Effect fires during render / duplicated tick chains**: you put a side effect in `view()`, or `start()` isn't idempotent and spawns parallel tick loops. Move the effect into a `Cmd` returned from `update()`/`init()`, and early-return `[$this, null]` when already running.
- **`vendor/bin/phpunit` fails on code you didn't touch**: stale per-lib `composer.lock`/`vendor/` (gitignored, CI unaffected). Run `cd <slug> && composer update` before trusting the failure.
- **php-cs-fixer rewrites your file**: run `PHP_CS_FIXER_IGNORE_ENV=1 php-cs-fixer fix --diff --allow-risky=yes` and accept the PSR-12 formatting rather than fighting it.
