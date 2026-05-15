---
name: sugarcraft-model-pattern
description: Scaffolds a new SugarCraft TUI class following the immutable + fluent pattern: `final` class, `declare(strict_types=1)`, `readonly` properties, private `mutate()` helper, `with*()` setters, bare accessors, and (for TUI roots) the `Model::init/update/view` contract. Mirrors upstream via `Mirrors charmbracelet/<repo>.<Method>` doc-comments and pairs every public method with a PHPUnit 10 snapshot/behaviour/coercion test. Use when user says 'add a new Model', 'new TUI widget', 'port from charmbracelet/<x>', 'scaffold a SugarCraft class', or creates files under `<slug>/src/`. Do NOT use for editing existing Models (use direct Edit), for non-PHP files, for the lib skeleton itself (use scaffold-library), or for non-SugarCraft repos.
paths:
  - **/src/**/*.php
  - **/tests/**/*.php
---
# SugarCraft Model / Widget Pattern

Scaffold a new immutable, fluent SugarCraft class that matches the conventions used across every lib in this monorepo. Output must look identical in shape to `candy-sprinkles/src/Style.php`, `candy-core/src/Spinner.php`, and `sugar-bits/src/*`.

## Critical

- **NEVER omit `declare(strict_types=1);`** — it is the first line after `<?php` in every file in the repo. CI does not enforce it but reviewers reject without it.
- **NEVER use a `get` prefix** on accessors. The codebase uses bare-named accessors: `$style->foreground()`, not `$style->getForeground()`. Same applies to factory methods: `Theme::ansi()`, `Spinner::line()`, `Spring::fps(60)`.
- **NEVER make a class non-`final`** unless the upstream Go type is genuinely intended as an extension point (rare — `final` is the default in this repo).
- **NEVER mutate `$this`**. Every state change goes through a private `mutate()` helper that returns a `clone` with the field replaced. Public `with*()` methods return the new instance.
- **NEVER return `null` for invalid input.** Throw `\InvalidArgumentException` or `\RuntimeException` per `CONTRIBUTING.md` style guide. Coercion (clamp, no-op) is fine when it mirrors upstream behaviour.
- **NEVER comment what the code does.** Only document *why* — non-obvious constraints, hidden invariants, upstream issue links. Doc-comments on public methods MUST cite upstream: `Mirrors charmbracelet/<repo>.<Method>`.
- **NEVER skip writing tests.** PHPUnit 10. Every public method needs ≥1 test before the PR is opened.
- For TUI roots only (classes that participate in the runtime loop), implement the `init()` / `update(Msg): array{0: Model, 1: ?Cmd}` / `view(): string` triple defined in `candy-core/src/Model.php`. Plain value objects (Style, Theme, Spring) DO NOT implement Model — they are dependencies of Models.

## Instructions

### Step 1 — Locate the target lib and confirm namespace

The class lives under `<slug>/src/<Class>.php`. Resolve the slug → namespace mapping using the rule from `CLAUDE.md`:

- `candy-shine/` → `SugarCraft\Shine\` (most libs follow this — drop the `candy-`/`sugar-`/`honey-` prefix, PascalCase the rest).
- `candy-core/` → `SugarCraft\Core\` (the one quirk — umbrella name).
- `sugar-bits/` → `SugarCraft\Bits\`.

If user said "port from charmbracelet/bubbles.spinner" → target lib is `candy-core` (spinner already lives there) or a new sibling if the user named one. Ask the user which lib if ambiguous before writing files.

**Verify:** `Grep` for an existing file under `<slug>/src/` and read its `namespace` line. Match it exactly. Proceed only after confirming the namespace string.

### Step 2 — Decide value object vs. Model

- **Value object** (Style, Theme, Spring, KeyMap, Spinner factory): immutable state + `with*()` builders. No `init/update/view`.
- **Model** (Field, List, Picker, Form, anything that takes input): implements the `init/update/view` triple. Look at `sugar-bits/src/TextInput.php` or `sugar-prompt/src/Confirm.php` for the canonical shape.

Ask the user only if the upstream type is ambiguous. Otherwise mirror upstream: Go types with `Update(msg) (Model, Cmd)` are Models; everything else is a value object.

**Verify:** State the decision in one sentence before writing code ("This is a value object — no Model contract") so the user can correct course before files are written.

### Step 3 — Write the value-object skeleton

Uses the decision from Step 2. The canonical reference is `candy-sprinkles/src/Style.php` — read it before writing. Skeleton:

```php
<?php

declare(strict_types=1);

namespace SugarCraft\<Sub>;

/**
 * Mirrors charmbracelet/<repo>.<UpstreamType>.
 */
final class <Class>
{
    public function __construct(
        public readonly <type> $<field> = <default>,
        // ... one readonly property per piece of state
    ) {
    }

    /** Mirrors charmbracelet/<repo>.<UpstreamType>.<UpstreamMethod>. */
    public static function <factory>(): self
    {
        return new self(/* preset values */);
    }

    /** Mirrors charmbracelet/<repo>.<UpstreamType>.With<Field>. */
    public function with<Field>(<type> $<field>): self
    {
        return $this->mutate('<field>', $<field>);
    }

    /** Mirrors charmbracelet/<repo>.<UpstreamType>.<Accessor>. */
    public function <field>(): <type>
    {
        return $this-><field>;
    }

    private function mutate(string $property, mixed $value): self
    {
        $clone = clone $this;
        $clone->{$property} = $value;

        return $clone;
    }
}
```

Notes:
- `readonly` properties + `mutate()` requires PHP 8.3+ — confirmed by every lib's `composer.json` `"php": "^8.3"`.
- Bare accessors only when the property is not already public-readable. Many libs skip the accessor entirely and let callers read `$style->foreground` directly. Mirror what the upstream Go type exposes.
- Factory methods are `public static` and match the upstream constructor name in lowercase: `New() → ::new()`, `NewWithDefaults() → ::default()`, etc.

**Verify:** `php -l <slug>/src/<Class>.php` passes. Re-read the namespace line and confirm it matches Step 1.

### Step 4 — Write the Model skeleton (only if Step 2 said Model)

Reference: `candy-core/src/Model.php` (the interface), `sugar-bits/src/TextInput.php` (canonical implementation). Skeleton:

```php
<?php

declare(strict_types=1);

namespace SugarCraft\<Sub>;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * Mirrors charmbracelet/<repo>.<UpstreamType>.
 */
final class <Class> implements Model
{
    public function __construct(
        public readonly string $value = '',
        // ... readonly state
    ) {
    }

    public function init(): ?Cmd
    {
        return null; // or a Cmd that fires on mount
    }

    /** @return array{0: Model, 1: ?Cmd} */
    public function update(Msg $msg): array
    {
        if ($msg instanceof KeyMsg) {
            // dispatch on $msg->key — return [$this->mutate(...), null]
        }

        return [$this, null];
    }

    public function view(): string
    {
        return ''; // raw ANSI bytes — use candy-sprinkles Style to compose
    }

    public function with<Field>(<type> $<field>): self
    {
        return $this->mutate('<field>', $<field>);
    }

    private function mutate(string $property, mixed $value): self
    {
        $clone = clone $this;
        $clone->{$property} = $value;

        return $clone;
    }
}
```

Notes:
- `update()` returns `[Model, ?Cmd]` — tuple, not an object. Tests rely on the array shape; do not change it.
- `view()` returns the rendered ANSI string. Compose with `SugarCraft\Sprinkles\Style` rather than hand-rolling escape codes when possible.
- If the upstream type accepts `tea.Msg` subtypes (`MouseMsg`, `WindowSizeMsg`), handle them with separate `instanceof` branches — see `sugar-bits/src/Viewport.php`.

**Verify:** `php -l` passes AND `composer dump-autoload` resolves the new class. Run `cd <slug> && vendor/bin/phpunit --filter NonExistentTest` — autoload errors surface immediately even with no tests.

### Step 5 — Write the test file

Tests live at `<slug>/tests/<Class>Test.php`, namespace `SugarCraft\<Sub>\Tests\`. Three patterns from `AGENTS.md`:

- **Snapshot** (Models with `view()`): call `view()`, assert the literal byte string including `\x1b[…m` SGR codes. Do not abstract — copy/paste the expected bytes. Canonical: `candy-core/tests/RendererTest.php`.
- **Behaviour** (Models with `update()`): build a `KeyMsg`/`MouseMsg`, call `update($msg)`, destructure `[$model, $cmd] = $result`, assert on the new state and the optional `Cmd`.
- **Coercion** (value objects): feed edge cases (negative width, oversized index, empty string, null where applicable), assert clamp or no-op matching upstream Go behaviour.

Skeleton:

```php
<?php

declare(strict_types=1);

namespace SugarCraft\<Sub>\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\<Sub>\<Class>;

final class <Class>Test extends TestCase
{
    public function test_with_<field>_returns_new_instance_with_value_set(): void
    {
        $a = new <Class>();
        $b = $a->with<Field>(<value>);

        self::assertNotSame($a, $b);
        self::assertSame(<value>, $b-><field>());
        self::assertSame(<default>, $a-><field>());
    }

    public function test_view_renders_expected_ansi(): void
    {
        $model = (new <Class>())->with<Field>(<value>);
        self::assertSame("\x1b[1mexpected\x1b[0m", $model->view());
    }
}
```

Stream-write gotcha (from `AGENTS.md`): if a test writes to a stream multiple times, slice deltas with `ftell` / `fseek` / `stream_get_contents`. Never `ftruncate; rewind;` between writes — canonical fix in `candy-core/tests/RendererTest.php`.

**Verify:** `cd <slug> && vendor/bin/phpunit --filter <Class>Test` — every public method on the new class must have ≥1 assertion.

### Step 6 — Confirm composer + namespace wiring

If this is the first class in a brand-new lib, the `scaffold-library` skill should have already written `composer.json` and `phpunit.xml`. For an existing lib:

- Re-run `composer dump-autoload` from inside the lib directory (`cd <slug> && composer dump-autoload`).
- Run `composer validate` (drop `--strict` — every `"sugarcraft/*": "@dev"` is flagged, expected per `AGENTS.md`).
- Run `vendor/bin/phpunit` and confirm green.

**Verify:** All three commands exit 0 before announcing completion.

### Step 7 — Update the upstream mapping

If this class corresponds to a new upstream type, add a row to `MATCHUPS.md` per the existing table format (status icon 🟡 if partial, 🟢 if complete pre-1.0). If it's a method on an already-mapped type, no `MATCHUPS.md` change is needed.

**Verify:** `grep -n "<UpstreamType>" MATCHUPS.md` returns at least one row after the edit.

## Examples

### Example 1 — Value object

User says: "add a Padding value object to candy-sprinkles mirroring charmbracelet/lipgloss.Style padding fields"

Actions taken:
1. Confirmed `candy-sprinkles` → `SugarCraft\Sprinkles\` namespace from existing `candy-sprinkles/src/Style.php`.
2. Decided value object (no `update()` upstream).
3. Wrote `candy-sprinkles/src/Padding.php`:

```php
<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles;

/** Mirrors charmbracelet/lipgloss.Style padding block. */
final class Padding
{
    public function __construct(
        public readonly int $top = 0,
        public readonly int $right = 0,
        public readonly int $bottom = 0,
        public readonly int $left = 0,
    ) {
    }

    public static function uniform(int $n): self
    {
        return new self($n, $n, $n, $n);
    }

    public function withTop(int $n): self    { return $this->mutate('top', max(0, $n)); }
    public function withRight(int $n): self  { return $this->mutate('right', max(0, $n)); }
    public function withBottom(int $n): self { return $this->mutate('bottom', max(0, $n)); }
    public function withLeft(int $n): self   { return $this->mutate('left', max(0, $n)); }

    private function mutate(string $property, mixed $value): self
    {
        $clone = clone $this;
        $clone->{$property} = $value;

        return $clone;
    }
}
```

4. Wrote `candy-sprinkles/tests/PaddingTest.php` with: identity (`uniform(2)->top === 2`), immutability (`withTop` returns new instance), coercion (`withTop(-5)->top === 0` clamped per upstream lipgloss).
5. `cd candy-sprinkles && composer dump-autoload && vendor/bin/phpunit --filter PaddingTest` → green.

Result: One new source file, one new test file, no `MATCHUPS.md` change needed (Padding is part of the already-mapped `Style` type).

### Example 2 — Model

User says: "port charmbracelet/bubbles spinner.Model into candy-core"

Actions taken:
1. Confirmed `candy-core` → `SugarCraft\Core\` (the namespace quirk).
2. Decided Model (upstream has `Update`).
3. Wrote `candy-core/src/Spinner.php` implementing `SugarCraft\Core\Model`, with `init()` returning a tick `Cmd`, `update(TickMsg)` advancing the frame index, `view()` returning the current frame string.
4. Added factory methods mirroring upstream: `Spinner::line()`, `Spinner::dot()`, `Spinner::points()`.
5. Wrote `candy-core/tests/SpinnerTest.php` with snapshot (`view()` returns `"|"` at frame 0 of `line()`), behaviour (`update(new TickMsg())` increments frame), coercion (frame wraps modulo frame count).
6. `cd candy-core && vendor/bin/phpunit` → green.
7. Added `Spinner` row to `MATCHUPS.md` with 🟢 since all upstream methods are covered.

Result: Model implements the `init/update/view` triple, factory methods mirror upstream verbatim, tests cover every public method.

## Common Issues

- **`Cannot modify readonly property` at runtime**: you wrote `$this->foo = $value;` somewhere instead of routing through `mutate()`. Fix: replace direct assignment with `return $this->mutate('foo', $value);`. The `clone` inside `mutate()` is what makes the new instance writable during the single property assignment.
- **`Class "SugarCraft\X\Y" not found` from PHPUnit**: composer autoload is stale. Fix: `cd <slug> && composer dump-autoload`. If that fails, `composer.json` has wrong PSR-4 mapping — open it and confirm `"SugarCraft\\<Sub>\\": "src/"` exists.
- **`Declaration of <Class>::update(Msg $msg) must be compatible with Model::update`**: you changed the return type from `array` to an object, or annotated the array shape differently than the interface. Fix: copy the exact signature from `candy-core/src/Model.php` — `/** @return array{0: Model, 1: ?Cmd} */ public function update(Msg $msg): array`.
- **Snapshot test fails with visually identical strings**: SGR byte mismatch. Use `var_export(bin2hex($actual))` to diff. Common cause: forgetting the trailing `\x1b[0m` reset, or `ConfigureTheme::ansi()` returning a different palette than expected. Fix: read `candy-sprinkles/src/Style.php` `render()` to see the exact byte order it produces.
- **`composer validate` reports `"sugarcraft/x": "@dev"` is unstable**: expected per `AGENTS.md`. Drop `--strict` from the command. Path-repo siblings are pre-1.0 and always flagged.
- **New transitive dep can't resolve**: every consuming lib needs the path-repo entry. Fix: copy the `repositories` array from `sugar-charts/composer.json` into the lib that just gained the new dep, then `composer update`.
- **`init()` returns `?Cmd` but tests expect `array`**: `init()` returns a single optional `Cmd`; only `update()` returns the `[Model, Cmd]` tuple. Don't conflate the two contracts.
- **`with*()` returns `self` but caller sees `static` analysis failure**: `self` is correct for `final` classes. If PHPStan/Psalm complains, the class isn't `final` — add the `final` keyword (default per repo conventions).