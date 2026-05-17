---
name: write-phpunit-test
description: Writes a PHPUnit 10 test in <slug>/tests/<Class>Test.php for SugarCraft monorepo libs. Use when the user says 'add test', 'write a phpunit test', 'cover <Class>', 'test this method', or modifies files under <slug>/src/. Produces snapshot tests for renderers (asserting raw \x1b[…m SGR bytes), behaviour tests for state machines (driving update() with scripted KeyMsg/MouseMsg/Tick), coercion tests for fluent setters (clamped/no-op on bad input), and immutability checks for with*() builders. Uses namespace SugarCraft\<Sub>\Tests, final class extending PHPUnit\Framework\TestCase, runs via vendor/bin/phpunit from the lib root. Do NOT use for integration tests, CI workflow files, JS/frontend tests, or non-PHPUnit testing frameworks.
paths:
  - '**/tests/*Test.php'
  - '**/src/**/*.php'
---
# write-phpunit-test

## Critical

- Test file lives **always** alongside the lib's other tests (e.g. `sugar-bits/tests/SpinnerTest.php`, `candy-core/tests/RendererTest.php`) — never under a top-level test root, never colocated with `src/`.
- First three lines are exactly: `<?php`, blank, `declare(strict_types=1);`. Top of every file. No exceptions.
- Namespace is `SugarCraft\<Sub>\Tests` where `<Sub>` matches the lib's source namespace (look at the lib's `composer.json` `autoload.psr-4` — drop the trailing `\`). Never invent a sub-namespace.
- Test class is `final` and extends `PHPUnit\Framework\TestCase` — not any project base class.
- Snapshot tests assert against **raw escape-byte strings** like `"\x1b[1mhello\x1b[0m"`. Do NOT abstract SGR codes behind helpers, constants, or regexes. The literal bytes are the contract.
- Every public method on the class under test needs ≥1 test. Don't merge with red tests.
- Run the per-lib PHPUnit binary from the lib root to verify before reporting done. `failOnWarning="true"` is set — warnings fail the suite.

## Instructions

1. **Locate the lib root and namespace.** From the class to test (e.g. `SugarCraft\Sprinkles\Style`), the lib root is `candy-sprinkles`. Confirm by reading `candy-sprinkles/composer.json` — the `autoload.psr-4` key (`"SugarCraft\\Sprinkles\\": "src/"`) gives you the source namespace; the test namespace is the same with `\Tests` appended (`autoload-dev.psr-4` confirms: `"SugarCraft\\Sprinkles\\Tests\\": "tests/"`). Verify both keys exist before writing the file.

2. **Decide the test category.** Pick whichever applies — many classes need two:
   - **Snapshot** — class produces ANSI/string output via `render()`/`view()`/`toString()`. Assert exact bytes.
   - **Behaviour** — class implements `Model` or has `update(Msg)`. Drive with scripted `KeyMsg`/`MouseMsg`/custom `Msg`s, destructure `[$next, $cmd] = $m->update(...)`, assert resulting state and Cmd type.
   - **Coercion** — fluent setters (`with*()`, `foreground()`, `padding()`). Feed negative/oversized/empty/null and assert the silent clamp/no-op upstream uses.
   - **Immutability** — for every `with*()` family, one test that asserts `assertNotSame($a, $b)` and that `$a`'s output is unchanged after `$b = $a->with*()`.

3. **Create the test file** alongside existing tests in the lib's tests dir, using this exact skeleton (replace `Sprinkles`/`Style`):

   ```php
   <?php

   declare(strict_types=1);

   namespace SugarCraft\Sprinkles\Tests;

   use SugarCraft\Sprinkles\Style;
   use PHPUnit\Framework\TestCase;

   final class StyleTest extends TestCase
   {
       public function testPlainStyleRendersUnchanged(): void
       {
           $this->assertSame('hello', Style::new()->render('hello'));
       }
   }
   ```

   Verify: file starts with `<?php\n\ndeclare(strict_types=1);` (no other content before declare), namespace ends with `\Tests`, class is `final`, every method is `public function testCamelCaseSentence(): void`.

4. **Write snapshot assertions** with raw bytes. Each test asserts the full output string in one `assertSame`:

   ```php
   $this->assertSame("\x1b[1mhello\x1b[0m", Style::new()->bold()->render('hello'));
   $this->assertSame("\x1b[38;2;255;128;0mhi\x1b[0m",
       Style::new()->foreground(Color::hex('#ff8000'))->render('hi'));
   ```

   Use double-quoted strings so `\x1b` escapes. Don't extract `"\x1b["` into a constant — the inline bytes are the documentation.

5. **Write behaviour assertions** by destructuring `update()`'s `[Model, ?Cmd]` tuple. Drive with concrete `Msg` subclasses from `SugarCraft\Core\Msg\*`:

   ```php
   use SugarCraft\Core\KeyType;
   use SugarCraft\Core\Msg\KeyMsg;

   public function testLeftKeyMovesPieceLeft(): void
   {
       $g = Game::start(new Bag(static fn(int $max): int => 0));
       $startX = $g->piece->x;
       [$next] = $g->update(new KeyMsg(KeyType::Left, ''));
       $this->assertSame($startX - 1, $next->piece->x);
   }

   public function testQuitKeyDispatchesQuitCmd(): void
   {
       [, $cmd] = $g->update(new KeyMsg(KeyType::Char, 'q'));
       $this->assertInstanceOf(\Closure::class, $cmd, 'q must dispatch a quit Cmd');
   }
   ```

   Cmds are `\Closure`s — assert with `assertInstanceOf(\Closure::class, $cmd)`. To inspect what a Cmd produces, invoke it: `$msg = $cmd();` then `assertInstanceOf(QuitMsg::class, $msg)`.

6. **Write coercion assertions** for fluent setters. Feed edge cases and assert the silent clamp matches upstream:

   ```php
   public function testNegativePaddingClampsToZero(): void
   {
       $s = Style::new()->paddingLeft(-5);
       $this->assertSame('hi', $s->render('hi')); // no padding rendered
   }

   public function testAsciiProfileEmitsNoSgr(): void
   {
       $out = Style::new()
           ->colorProfile(ColorProfile::Ascii)
           ->foreground(Color::hex('#ff0000'))
           ->render('hi');
       $this->assertSame('hi', $out);
   }
   ```

   Don't expect exceptions for bad fluent input — the project uses silent no-op to mirror upstream Go behaviour. Throwing is reserved for value APIs explicitly documented to throw.

7. **Add an immutability test for every `with*()` family** the class exposes. Pattern from `candy-sprinkles/tests/StyleTest.php:67`:

   ```php
   public function testImmutability(): void
   {
       $a = Style::new();
       $b = $a->bold();
       $this->assertNotSame($a, $b);
       $this->assertSame('hello', $a->render('hello'));
       $this->assertSame("\x1b[1mhello\x1b[0m", $b->render('hello'));
   }
   ```

8. **For test fixtures (recording models, deterministic RNGs, scripted readers)** define a separate `final class` in the same file *above* the test class, mirroring `candy-core/tests/ProgramTest.php:34`'s `RecordingModel`. Don't put fixtures under `src/`. Don't create a `tests/Support/` directory unless one already exists in that lib.

9. **Run the suite** from the lib root. Verify green before reporting done:

   ```sh
   cd candy-sprinkles && composer install --quiet && vendor/bin/phpunit
   ```

   To run only your new class:

   ```sh
   cd candy-sprinkles && vendor/bin/phpunit --filter StyleTest
   ```

   PR test-plan should cite the count, e.g. `candy-sprinkles full suite green (42/42)`.

## Examples

**User**: "add a test for `candy-palette/src/Color.php`'s `Color::hex()` factory"

**Actions**:
1. Read `candy-palette/composer.json` → namespace `SugarCraft\Palette`, test namespace `SugarCraft\Palette\Tests`.
2. Create `candy-palette/tests/ColorTest.php` (or add to it if it exists).
3. Write tests covering: valid 6-digit hex, 3-digit hex shorthand, leading `#` optional, lowercase/uppercase, malformed hex coercion to fallback, immutability of returned `Color`.
4. Run:

   ```sh
   cd candy-palette && vendor/bin/phpunit --filter ColorTest
   ```

**Result**: `candy-palette/tests/ColorTest.php` with ~6 `public function testX(): void` methods, suite green.

---

**User**: "cover the new `Spinner::tick()` behaviour I just added"

**Actions**:
1. Read `sugar-prompt/src/Spinner.php` to confirm `tick()` is a behaviour method (returns `[Spinner, ?Cmd]`).
2. Open `sugar-prompt/tests/SpinnerTest.php`, add behaviour-style tests destructuring the tuple.
3. Drive `tick()` with the appropriate `Msg`, assert frame index advances and `Cmd` reschedules (`assertInstanceOf(\Closure::class, $cmd)`).
4. Run:

   ```sh
   cd sugar-prompt && vendor/bin/phpunit --filter SpinnerTest
   ```

**Result**: New `testTickAdvancesFrameIndex` + `testTickReschedulesItself` methods in the existing class.

## Common Issues

- **`Class "SugarCraft\<Sub>\<Class>" not found`** — autoload not regenerated. Run `composer dump-autoload` in the lib root. If still missing, the namespace in your `use` doesn't match the lib's `composer.json` `autoload.psr-4` key — re-read the composer file.

- **`Cannot declare class …Test, because the name is already in use`** — duplicate `final class XTest` because the file was created twice. PHPUnit autoloads everything under the lib's tests dir. Delete the duplicate or rename one.

- **`Risky test that printed output`** — your code-under-test wrote to STDOUT during a test. Either capture with `ob_start()` / `ob_get_clean()`, or pass a `php://memory` stream into the constructor like `candy-core/tests/ProgramTest.php:71` does (`fopen('php://memory', 'w+')`).

- **`failed asserting that 'hi' is identical to '\x1b[1mhi\x1b[0m'`** — you're passing a single-quoted string. Switch to double quotes so `\x1b` is interpreted: `"\x1b[1mhi\x1b[0m"`, not `'\x1b[1mhi\x1b[0m'`.

- **`PHPUnit\Framework\Error\Warning`** — `failOnWarning="true"` is set. A deprecation or `E_USER_WARNING` from the code-under-test will fail the test. Fix the underlying warning rather than suppressing it.

- **`No tests found in class`** — method names must start with `test` (lowercase) and be `public`. `public function checkX()` is silently skipped.

- **Cmd assertions returning `null`** — Cmd is `?\Closure` (nullable). `assertInstanceOf(\Closure::class, $cmd)` fails when no Cmd is dispatched. If you expect no-Cmd, assert `$this->assertNull($cmd)` instead.

- **`composer install` fails with sibling `@dev` warnings** — expected. Drop `--strict` from `composer validate`; every sibling lib is a `path` repo on `@dev`.
