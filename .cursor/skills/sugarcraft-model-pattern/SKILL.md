---
name: sugarcraft-model-pattern
description: Scaffolds a new SugarCraft TUI class following the canonical immutable + fluent pattern: final class, declare(strict_types=1), readonly props, private mutate() helper, with*() setters, and (for TUI roots) the Model::init/update/view contract from candy-core. Use when user says 'add a new Model', 'new TUI widget', 'port from charmbracelet/<x>', 'scaffold a SugarCraft class', or creates new files under <slug>/src/. Do NOT use for editing existing Models, for non-SugarCraft PHP libs, for tests-only changes (use phpunit-skill), for adding new libraries from scratch (use scaffold-library), or for cross-lib refactors.
---
# SugarCraft Model Pattern

Scaffold a new immutable + fluent class inside an existing SugarCraft lib. Every class must look identical in shape to `candy-sprinkles/src/Style.php` (data) or `candy-core/src/Model.php` (TUI root).

## Critical

- **Every file starts with `declare(strict_types=1);`** — no exceptions, no leading blank line.
- **`final class`** unless extension is part of the public contract.
- **Public state is `readonly`**. Never write `private $x` for state. Constructor uses `public readonly` promotion.
- **Mutation happens through ONE private helper, `mutate(array $changes): self`.** Every `with*()` calls it. Do not clone-and-assign inline in each setter.
- **Accessors have NO `get` prefix.** `theme()`, not `getTheme()`.
- **Factory methods mirror upstream** Charmbracelet names: `Theme::ansi()`, `Spinner::line()`, `Spring::fps(60)`. Add doc-comment `Mirrors charmbracelet/<repo>.<Method>`.
- **Namespace quirk**: lib `candy-core` → `SugarCraft\Core\`. All other libs follow `Candy<X>` / `Sugar<X>` / `Honey<X>` → `SugarCraft\<X>\` (e.g. `candy-shine` → `SugarCraft\Shine\`).
- **Don't add comments restating code.** Only doc *why* (upstream link, invariant, constraint).
- **TUI Model contract** is in `candy-core/src/Model.php`: `init(): ?Cmd`, `update(Msg $msg): array{0: Model, 1: ?Cmd}`, `view(): string`. Implement the interface verbatim — do not invent a `render()` method.
- **After scaffolding, run `cd <lib> && composer dump-autoload && vendor/bin/phpunit`.** If autoload misses the class, the namespace or filename is wrong — fix before continuing.

## Instructions

1. **Identify the target lib and class shape.** From the user request, pick:
   - Target lib slug (`<slug>`, e.g. `sugar-bits`, `candy-shine`).
   - Class name in PascalCase (e.g. `Style`, `Spinner`, `Theme`).
   - Shape: **Data** (value object — `Style`, `Theme`, `KeyMap`) or **TUI Root** (implements `SugarCraft\Core\Model`).
   - Upstream reference if porting (`charmbracelet/<repo>.<Type>`).

   **Verify**: `<slug>/composer.json` exists and `<slug>/src/` is present. If the lib does not exist, STOP and tell the user to use `scaffold-library` first.

2. **Resolve the namespace.** Derive from `<slug>/composer.json` `autoload.psr-4`:
   ```sh
   grep -A2 'psr-4' <slug>/composer.json
   ```
   Expected: `"SugarCraft\\<Sub>\\": "src/"`. Use that exact `<Sub>` — do not guess from the slug (`candy-core` resolves to `SugarCraft\Core\`, not `SugarCraft\CandyCore\`).

   **Verify**: `<Sub>` matches one segment, capitalised, no hyphen.

3. **Read the canonical templates before writing.** Always read both, even if you remember them:
   - Data class template: `candy-sprinkles/src/Style.php`
   - TUI Model interface: `candy-core/src/Model.php`
   - Msg/Cmd types (if TUI): `candy-core/src/Msg.php`, `candy-core/src/Cmd.php`

   **Verify**: confirm `mutate()` is `private` and returns `self`, and that `with*()` methods call it with an associative array.

4. **Create the file** at `<slug>/src/<Class>.php` (or `<slug>/src/<SubDir>/<Class>.php` mirroring upstream package layout). Use this exact skeleton — keep order: `declare` · `namespace` · `use` · doc-comment · `final class` · `readonly` props · private constructor · static factories · `with*()` · accessors (rare — props are public) · `mutate()`.

   **Data class skeleton:**
   ```php
   <?php

   declare(strict_types=1);

   namespace SugarCraft\<Sub>;

   /**
    * Mirrors charmbracelet/<repo>.<Type>.
    */
   final class <Class>
   {
       private function __construct(
           public readonly <type1> $<prop1> = <default1>,
           public readonly <type2> $<prop2> = <default2>,
       ) {
       }

       public static function new(): self
       {
           return new self();
       }

       public function with<Prop1>(<type1> $<prop1>): self
       {
           return $this->mutate(['<prop1>' => $<prop1>]);
       }

       private function mutate(array $changes): self
       {
           return new self(
               <prop1>: $changes['<prop1>'] ?? $this-><prop1>,
               <prop2>: $changes['<prop2>'] ?? $this-><prop2>,
           );
       }
   }
   ```

   **TUI Model skeleton (only if class implements the runtime contract):**
   ```php
   <?php

   declare(strict_types=1);

   namespace SugarCraft\<Sub>;

   use SugarCraft\Core\Cmd;
   use SugarCraft\Core\Model;
   use SugarCraft\Core\Msg;

   /**
    * Mirrors charmbracelet/<repo>.Model.
    */
   final class <Class> implements Model
   {
       private function __construct(
           public readonly <state-type> $<state> = <default>,
       ) {
       }

       public static function new(): self
       {
           return new self();
       }

       public function init(): ?Cmd
       {
           return null;
       }

       public function update(Msg $msg): array
       {
           return [$this, null];
       }

       public function view(): string
       {
           return '';
       }

       public function with<State>(<state-type> $<state>): self
       {
           return $this->mutate(['<state>' => $<state>]);
       }

       private function mutate(array $changes): self
       {
           return new self(
               <state>: $changes['<state>'] ?? $this-><state>,
           );
       }
   }
   ```

   **Verify**: the file has `declare(strict_types=1);` on line 3 (after `<?php` + blank), `final class`, and exactly one `mutate()` method.

5. **If the class depends on another SugarCraft lib**, ensure both the `require` and the path-repo are wired in `<slug>/composer.json`. Copy the closure from `sugar-charts/composer.json`:
   - Add `"sugarcraft/<dep>": "@dev"` to `require`.
   - Add `{"type": "path", "url": "../<dep>", "options": {"symlink": true}}` to `repositories`.
   - Repeat for the **full transitive closure** — every `@dev` dep needs its own path-repo entry, not just the direct one.

   **Verify**: `cd <slug> && composer validate` (drop `--strict` — `"@dev"` constraints are flagged but expected pre-1.0).

6. **Write at least one PHPUnit test** at `<slug>/tests/<Class>Test.php` under namespace `SugarCraft\<Sub>\Tests`. Mirror the patterns in `candy-core/tests/` and `sugar-bits/tests/`:
   - **Immutability**: assert `$a->withX(v) !== $a` and `$a->x !== $a->withX(v)->x`.
   - **Snapshot** (if it renders): assert raw `\x1b[...m` bytes from `view()`. Don't abstract.
   - **Coercion** (if it accepts user input): feed negative/oversized/empty/null, assert clamp/no-op.

   **Verify**: `cd <slug> && composer install --quiet && vendor/bin/phpunit` exits 0.

7. **Refresh autoload and run the full lib suite.** From the repo root:
   ```sh
   cd /home/sites/sugarcraft/<slug> && composer dump-autoload -q && vendor/bin/phpunit
   ```
   **Verify**: green. If red, do not move on — fix before claiming completion. Per the `oac:verification-before-completion` workflow, evidence (test output) precedes any "done" claim.

8. **Update `<slug>/CALIBER_LEARNINGS.md`** only if you discovered a non-obvious pattern while scaffolding (e.g. a Msg shape that doesn't fit the canonical `update()` tuple). Otherwise skip — don't pad the file.

## Examples

**User**: "Add a `Pulse` widget to candy-shine that mirrors charmbracelet/lipgloss.Blink"

**Actions**:
1. Confirm `candy-shine/src/` exists; namespace from `candy-shine/composer.json` is `SugarCraft\Shine\`.
2. Read `candy-sprinkles/src/Style.php` (data shape — `Pulse` doesn't implement Model, it's a render decorator).
3. Create `candy-shine/src/Pulse.php`:
   ```php
   <?php

   declare(strict_types=1);

   namespace SugarCraft\Shine;

   /**
    * Mirrors charmbracelet/lipgloss.Blink.
    */
   final class Pulse
   {
       private function __construct(
           public readonly int $intervalMs = 500,
           public readonly bool $enabled = true,
       ) {
       }

       public static function new(): self
       {
           return new self();
       }

       public static function fps(int $fps): self
       {
           return new self(intervalMs: (int) (1000 / max(1, $fps)));
       }

       public function withInterval(int $intervalMs): self
       {
           return $this->mutate(['intervalMs' => max(1, $intervalMs)]);
       }

       public function withEnabled(bool $enabled): self
       {
           return $this->mutate(['enabled' => $enabled]);
       }

       private function mutate(array $changes): self
       {
           return new self(
               intervalMs: $changes['intervalMs'] ?? $this->intervalMs,
               enabled: $changes['enabled'] ?? $this->enabled,
           );
       }
   }
   ```
4. Create `candy-shine/tests/PulseTest.php` asserting immutability + clamp on `withInterval(-5)`.
5. Run `cd candy-shine && composer dump-autoload -q && vendor/bin/phpunit`. Verify green.

**Result**: `Pulse` slots into the existing `candy-shine` API surface and is indistinguishable in shape from `Spinner` / `Theme`.

## Common Issues

- **`Class "SugarCraft\<Sub>\<Class>" not found` during PHPUnit**:
  1. Confirm filename matches class name exactly (case-sensitive on CI Linux runners).
  2. Confirm namespace declaration matches `<slug>/composer.json` PSR-4 prefix verbatim.
  3. Run `cd <slug> && composer dump-autoload` to rebuild the classmap.

- **`Cannot modify readonly property`**: a setter is writing `$this->x = ...` instead of returning `new self(...)`. Re-route through `mutate()`.

- **`mutate()` ignores the new value for a nullable field**: `$changes['x'] ?? $this->x` collapses `null` to the old value. For nullable props use `array_key_exists('x', $changes) ? $changes['x'] : $this->x`.

- **`composer validate` warns `sugarcraft/<dep> : @dev`**: expected pre-1.0 for path-repos. Drop `--strict`. See root CLAUDE.md gotchas.

- **`Argument #1 ($msg) must be of type Msg, X given` in `update()`**: the runtime dispatches concrete `Msg` subclasses (`KeyMsg`, `MouseMsg`, `WindowSizeMsg`). Type-hint the parameter as the `Msg` interface from `candy-core/src/Msg.php` and branch with `instanceof` inside the method.

- **Tests fail with `Failed asserting that two strings are equal` on `view()` output**: don't abstract the expected string — paste the raw `\x1b[...m` bytes inline. Mirror `candy-core/tests/RendererTest.php`.

- **Stream deltas wrong in renderer tests**: don't `ftruncate; rewind;` between writes. Slice with `ftell`/`fseek`/`stream_get_contents` (canonical in `candy-core/tests/RendererTest.php`).

- **VHS workflow doesn't render the new demo**: `.github/workflows/vhs.yml` matrix is hand-maintained. Add `<slug>` to the `all=(...)` array. `ci.yml` is auto-discovered via `scripts/affected-libs.php` and needs no edit.