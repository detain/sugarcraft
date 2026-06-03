---
name: sugarcraft-model-pattern
description: Scaffolds a new immutable+fluent SugarCraft class — `final`, `declare(strict_types=1)`, private constructor with public `readonly` promoted props, `::new()` factory, `with*()` setters returning new instances, bare accessors, and (for TUI roots) the candy-core `Model::init/update/view/subscriptions` contract. Mirror `candy-sprinkles/src/Style.php` (value object) or `sugar-bits/src/Stopwatch/Stopwatch.php` (Model). Use when user says 'add a Model', 'new TUI widget', 'scaffold a SugarCraft class', 'port from charmbracelet/<x>', or creates files under `<slug>/src/`. Do NOT use for editing existing classes (use direct Edit), tests-only changes (use write-phpunit-test), the lib skeleton itself (use scaffold-library), or non-SugarCraft repos.
paths:
  - '*/src/**/*.php'
---
# SugarCraft Model / value-object pattern

Scaffold a new `final` immutable class under `<slug>/src/` that looks identical to existing SugarCraft code. Two shapes:
- **Value object** — styled-text / config builders. Canonical: `candy-sprinkles/src/Style.php`.
- **TUI Model** — implements `SugarCraft\Core\Model` (Elm architecture). Canonical: `sugar-bits/src/Stopwatch/Stopwatch.php`.

## Critical

- `declare(strict_types=1);` is the FIRST statement after `<?php` in every file — no exceptions.
- Class is `final` unless extension is an explicit part of the contract.
- ALL state is `private readonly` promoted constructor params. The constructor itself is `private` — instances come from `::new()` or `with*()`, never `new Class(...)` from outside.
- NEVER mutate `$this`. Every `with*()`/factory returns a NEW instance.
- Factory naming: `::new()` is the zero-arg/default root. Use bare upstream-mirroring names for variants (`Theme::ansi()`, `Spinner::line()`, `Spring::fps(60)`). NEVER introduce `::create()`, `::make()`, or `::default()`.
- Accessors are bare-named — `elapsed()`, `interval()`, `id()` — NEVER `getElapsed()`.
- Bad input throws `\InvalidArgumentException` / `\RuntimeException` with a `Lang::t(...)` message — NEVER return `null` for "wasn't valid".
- Namespace = slug-derived: `candy-shine/` → `SugarCraft\Shine\`; sub-namespaces follow the dir (`sugar-bits/src/Stopwatch/` → `SugarCraft\Bits\Stopwatch`). QUIRK: `candy-core/` → `SugarCraft\Core\`.
- When porting a Go upstream, every ported method carries a doc-comment line: `Mirrors charmbracelet/<repo>.<Method>`.

## Instructions

### Step 1 — Place the file and set the namespace

File: `<slug>/src/<Class>.php` (or `<slug>/src/<Sub>/<Class>.php` for grouped widgets). Derive the namespace from the path, not the slug guess. Verify: `head -6 <slug>/src/<Class>.php` shows `declare(strict_types=1);` then `namespace SugarCraft\<Sub>...;`. Proceed only if the namespace matches an existing file in the same dir.

Skeleton header:
```php
<?php

declare(strict_types=1);

namespace SugarCraft\<Sub>;

use SugarCraft\<Sub>\Lang;
```

### Step 2 — Decide shape: value object vs Model

- If it has `init`/`update`/`view` lifecycle (a runnable TUI component) → **Model** (Step 4).
- If it is an immutable config/style/data builder → **value object** (Step 3).

### Step 3 — Value object (mirror `candy-sprinkles/src/Style.php`)

Private promoted-readonly constructor + `::new()`:
```php
final class <Class>
{
    private function __construct(
        private readonly int $width = 0,
        private readonly bool $bold = false,
        private readonly ?Color $fg = null,
    ) {}

    public static function new(): self
    {
        return new self();
    }
```

`with*()` setters via the `mutate()` helper. For a plain class, pull in the trait `use SugarCraft\Core\Concerns\Mutable;` which gives `protected function mutate(array $changes): static { return new static(...array_merge(get_object_vars($this), $changes)); }`:
```php
    public function withWidth(int $width): self  { return $this->mutate(['width' => $width]); }
    public function bold(bool $on = true): self   { return $this->mutate(['bold' => $on]); }
```
Bare accessors:
```php
    public function width(): int { return $this->width; }
```
Verify: every `with*()` returns `self`/`static` and references no `$this->prop =` assignment. Grep for `$this->` followed by ` = ` in the file — there must be ZERO matches outside the constructor.

### Step 3b — Nullable fields need a sentinel

`array_merge(get_object_vars(...))` cannot tell "passed null" from "omitted". For a nullable prop that must be settable to `null`, add a paired `bool $XSet = false` constructor param and override `mutate()`/use a `with(...)` helper that honors the sentinel — mirror `candy-sprinkles/src/Style.php` `foreground()` (`$this->with(fg: $c, fgSet: true, propsAdded: ['fg'])`) and `sugar-bits/src/Help/Help.php` `withStyles()` + `$stylesSet`. Verify: setting the field to `null` via the setter actually clears it (covered by the immutability test in Step 6).

### Step 4 — TUI Model (mirror `sugar-bits/src/Stopwatch/Stopwatch.php`)

Implement the four `SugarCraft\Core\Model` methods exactly:
```php
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;

final class <Class> implements Model
{
    private function __construct(
        public readonly float $elapsed,
        public readonly float $interval,
        public readonly bool $running,
    ) {}

    public static function new(float $interval = 1.0): self
    {
        if ($interval <= 0.0) {
            throw new \InvalidArgumentException(Lang::t('<class>.interval_positive'));
        }
        return new self(0.0, $interval, false);
    }

    public function init(): ?\Closure { return null; }

    /** @return array{0:Model, 1:?\Closure} */
    public function update(Msg $msg): array
    {
        if ($msg instanceof TickMsg && $msg->id === $this->id && $this->running) {
            $next = new self($this->elapsed + $this->interval, $this->interval, true);
            return [$next, $next->tick()];
        }
        return [$this, null];
    }

    public function view(): string { return /* render */ ''; }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions { return null; }
}
```
Rules for Models:
- `update()` ALWAYS returns the `[Model, ?Cmd]` tuple — `return [$this, null];` for the no-op branch. NEVER return a bare model.
- Side effects (timers, IO) live in a `Cmd` closure (`Cmd::tick(...)`), NEVER inside `view()`. `view()` is pure.
- `view()` return type is `string|View` — use plain `string` unless you need per-frame cursor/title control.
- Async-producing actions (`start()`, `toggle()`) return the `[self, ?\Closure]` tuple too; pure state changes (`stop()`, `reset()`) may return `self`.
- Verify: grep the file — `update(` signature returns `array`, and no `echo`/`fwrite`/IO appears inside `view()`.

### Step 5 — Upstream doc-comments

If porting, add `Mirrors charmbracelet/<repo>.<Method>` to the class and each ported method's doc-block. Comment only the WHY (constraints/invariants/upstream-issue links) — never restate what the code does. See the `start()` idempotency note in `Stopwatch.php:63-69`.

### Step 6 — Pair every public method with a test

Every public method needs ≥1 PHPUnit 10 test in `<slug>/tests/`. Hand off to the `write-phpunit-test` skill, or write directly:
- **Snapshot** — call `view()`, assert the raw `\x1b[1m`-style SGR byte string. Don't abstract the escape codes.
- **Behaviour** — drive `update()` with scripted `KeyMsg`/`MouseMsg`/`TickMsg`, assert the `[Model, ?Cmd]` tuple.
- **Coercion** — feed negative/oversized/empty/null input, assert clamp / no-op / thrown `\InvalidArgumentException`.
- **Immutability** — call a `with*()`, assert the original instance is unchanged (`assertNotSame`).
Verify: `cd <slug> && composer install --quiet && vendor/bin/phpunit` is GREEN before claiming done.

### Step 7 — i18n any user-facing string

No raw English in thrown messages or rendered chrome. Use `Lang::t('<class>.<key>', $params)` and add the key to `<slug>/lang/en.php`. Each lib has a thin `Lang::t()` wrapper around `SugarCraft\Core\I18n\T` (canonical: `sugar-wishlist/src/Lang.php`, `candy-pty/src/Lang.php`).

## Examples

**User:** "Port charmbracelet/bubbles stopwatch into sugar-bits"

Actions:
1. Create `sugar-bits/src/Stopwatch/Stopwatch.php`, namespace `SugarCraft\Bits\Stopwatch`, `declare(strict_types=1)` first.
2. Model shape: private promoted-readonly ctor (`elapsed`, `interval`, `running`), `::new(float $interval = 1.0)` throwing `\InvalidArgumentException(Lang::t('stopwatch.interval_positive'))` on `<= 0`.
3. `init()` returns `null`; `update()` matches `TickMsg`, returns `[$next, $next->tick()]` else `[$this, null]`; `view()` formats elapsed; `subscriptions()` returns `null`.
4. `start()`/`toggle()` return `[self, ?\Closure]`; `stop()`/`reset()` return `self`. Bare accessors `elapsed()`, `interval()`, `id()`. `withInterval()` rebuilds via `new self(...)`.
5. Doc-comment `Mirrors charmbracelet/bubbles.Stopwatch`; add `stopwatch.interval_positive` to `sugar-bits/lang/en.php`.
6. Write `sugar-bits/tests/StopwatchTest.php` (behaviour: tick advances elapsed; coercion: zero interval throws).

Result: `cd sugar-bits && vendor/bin/phpunit` green; file is byte-for-byte consistent with `Tabs.php`/`Timer.php` in the same lib.

## Common Issues

- **PHPUnit warning `Cannot instantiate ... constructor is private`**: a test or caller used `new <Class>(...)`. Replace with `<Class>::new()` or the relevant factory. The private constructor is intentional.
- **`with*()` setter returns the wrong/stale value when set to null**: `array_merge(get_object_vars(...))` dropped the null. Add a `bool $XSet` sentinel param + custom `mutate()`/`with()` (Step 3b), mirroring `Style::foreground()`.
- **`Error: Call to undefined method mutate()`**: the class lacks the trait. Add `use SugarCraft\Core\Concerns\Mutable;` inside the class body (not just a `use` import at top), or define a private `mutate(array $changes): static`.
- **`TypeError: update() must return array`**: a branch returned a bare `$model`. Every `update()` path must return the `[Model, ?Cmd]` tuple — use `[$this, null]` for no-ops.
- **Tick/timer never fires or fires twice**: side effect was put in `view()` (pure) or `start()` wasn't idempotent. Move it into a `Cmd::tick(...)` closure returned from `update()`/`start()`; guard `start()` with `if ($this->running) return [$this, null];` (see `Stopwatch.php:70-77`).
- **`composer install` shows missing `sugarcraft/*` path-repo**: a new sibling dep was added. Run `php tools/check-path-repos.php --fix` from the repo root to insert the path-repo entry, then re-run `composer install`.
- **Local `vendor/bin/phpunit` red but CI green**: stale per-lib `vendor`/`composer.lock`. Run `composer update` in that lib before trusting the failure.
