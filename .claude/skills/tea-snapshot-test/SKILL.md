---
name: tea-snapshot-test
description: Writes PHPUnit 10 tests for SugarCraft TEA libs in the four project modes — snapshot byte (view()→raw SGR), cell-grid (SugarCraft\Vt\Terminal / Buffer), behaviour (update()→[Model,?Cmd]), and coercion (clamp/throw edges) — plus golden-file and program-driving helpers from candy-testing (Assertions::assertGoldenAnsi/assertCellGrid/assertViewIdempotent, ProgramSimulator, ScriptedInput). Use when the user says 'write a test', 'add snapshot test', 'add golden test', 'test this Model', 'test update()', or 'test view() output' for any candy-*/sugar-*/honey-* lib. Do NOT use to duplicate a canonical lib's suite into a class_alias façade lib (ship a single AliasesTest asserting each alias resolves to its canonical FQN instead), and do NOT use for non-TUI plain-PHP unit tests that have no view()/update()/Model.
paths:
  - **/tests/**/*Test.php
  - **/*Test.php
  - candy-testing/src/**
---
# TEA Snapshot Test

Write PHPUnit 10 tests for SugarCraft TUI libs using the four canonical modes and the `candy-testing` harness. Match the exact structure in `sugar-bits/tests/GoldenRenderTest.php`, `candy-zone/tests/ZoneHoverTrackerTest.php`, and `sugar-charts/tests/FiniteGuardTest.php`.

## Critical

- **Never duplicate a façade suite.** If the class under test is re-exported via `class_alias` (e.g. `sugar-bits`/`sugar-prompt` → `SugarCraft\Forms\*`, `candy-lister`'s `ScoringProfile` → `SugarCraft\Fuzzy\*`), do NOT copy the canonical lib's tests. Ship ONE `AliasesTest` asserting each alias symbol resolves to its canonical FQN (`self::assertSame('SugarCraft\\Forms\\Input', (new \ReflectionClass(\SugarCraft\Bits\Input::class))->getName())`), plus tests only for genuinely lib-local behaviour. (Refs #1275/#1312/#1314 deleted ~4300 LOC of this.)
- **Side effects live in `Cmd`, never `view()`.** A behaviour test asserting on a side effect must assert on the returned `Cmd`, not on `view()`.
- **`view()` output is byte-exact.** Snapshot tests assert the raw SGR string, including every `\x1b[...m`. Do not normalize or trim.
- **First line of every test file: `declare(strict_types=1);`** then `namespace SugarCraft\<Sub>\Tests;` (quirk: `candy-core` → `SugarCraft\Core\Tests`). Class is `final`, extends `PHPUnit\Framework\TestCase`. Every public method under test gets ≥1 test.
- **Stale vendor lies.** Before trusting a local red, run `cd <slug> && composer update --quiet` — per-lib `composer.lock`/`vendor/` go stale (gitignored; CI is unaffected).

## Instructions

### 1. Locate the lib and confirm it is not a façade
Map slug→namespace: `candy-shine/` → `SugarCraft\Shine\`. Grep the target `src/` for `class_alias(`. If present for the class you were asked to test → STOP, write an `AliasesTest` per Critical, done. Otherwise continue. **Verify: you know the FQN of the class under test and it is NOT a pure alias before proceeding.**

### 2. Pick the mode(s) — cover every relevant one
- **Snapshot byte** — the class has a `view()` (or `render()`) returning SGR bytes → Step 3.
- **Cell-grid** — output is easier to assert as a 2-D grid, or you render into a `SugarCraft\Buffer\Buffer` / drive a `SugarCraft\Vt\Terminal` → Step 4.
- **Behaviour** — the class implements `update(Msg): [Model, ?Cmd]` → Step 5.
- **Coercion** — the class clamps or rejects out-of-range input → Step 6.
- **Program-level** — you need to drive `init()`→`update()`→`view()` with scripted input → Step 7.
A typical Model needs snapshot + behaviour + coercion. **Verify: list which modes apply before writing.**

### 3. Snapshot byte test (golden file)
Build the immutable object with `::new()` + `with*()`, call `view()`, assert with `Assertions::assertGoldenAnsi`. Golden files live under the lib's `tests/fixtures/` directory (e.g. `sugar-bits/tests/fixtures/progress-fill-color.golden`).
```php
use SugarCraft\Testing\Snapshot\Assertions;
// ...
public function testProgressBarWithFillColorRendersAnsi(): void
{
    $p = Progress::new()
        ->withWidth(10)
        ->withShowPercent(false)
        ->withFillColor(Color::hex('#ff0000'))
        ->withColorProfile(ColorProfile::TrueColor)
        ->withPercent(1.0);

    $output = $p->view();

    $this->assertNotEmpty($output);
    Assertions::assertGoldenAnsi(__DIR__ . '/fixtures/progress-fill-color.golden', $output);
}
```
Create the baseline once: `cd <slug> && UPDATE_GOLDENS=1 vendor/bin/phpunit --filter testProgressBar...`. This writes the `.golden` file only when missing. **Inspect the generated `.golden` by eye — a wrong baseline locks in a wrong result.** Optionally add `Assertions::assertViewIdempotent($p);` to guard render purity. **Verify: the fixture file exists and re-running without `UPDATE_GOLDENS` passes.**

### 4. Cell-grid test
For grid assertions use `Assertions::assertCellGrid($expected, $buffer)` where `$expected[row][col] = ['rune' => 'a', 'style' => $style]` and `$buffer` is a `SugarCraft\Buffer\Buffer`. For full-terminal parsing (SGR → cells), feed bytes to `SugarCraft\Vt\Terminal` and read cells back (see `candy-pty/tests/Integration/VimSmokeTest.php`). Dimensions are checked first, so size the expected grid to match `$buffer->width()`/`$buffer->height()`. **Verify: expected grid dimensions equal the buffer's.**

### 5. Behaviour test (`update()`)
Destructure the tuple; the second element is the optional `Cmd`. Pattern from `candy-zone/tests/ZoneHoverTrackerTest.php`:
```php
[$next, $cmd] = $tracker->update($this->move(2, 1));
$this->assertNotSame($tracker, $next);   // immutability: new instance
$this->assertInstanceOf(HoverMsg::class, $cmd);
// discard the cmd when only the model matters:
[$tracker] = $tracker->update($this->move(2, 1));
```
Assert on the *returned* model/cmd, never by mutating the original (state is `readonly`). When a `Cmd` is expected, assert its type or run it; when none is expected assert `$cmd === null`. **Verify: the test proves `update()` returns a new instance and the correct `?Cmd`.**

### 6. Coercion test
Two shapes. Clamp (silent): assert the coerced value. Reject (loud): `\InvalidArgumentException`/`\RuntimeException` — the project throws rather than returning null (`sugar-charts/tests/FiniteGuardTest.php`):
```php
public function testRejectsNonFiniteValue(): void
{
    $this->expectException(\InvalidArgumentException::class);
    Chart::new()->withData([INF]);
}
```
Cover both edges (e.g. `withPercent(-0.5)`→`0.0`, `withPercent(2.0)`→`1.0`). **Verify: each clamp boundary and each throw path has its own test method.**

### 7. Program-level test (ScriptedInput + ProgramSimulator)
Drive a whole TEA program without real stdin/stdout:
```php
use SugarCraft\Testing\{ProgramSimulator, TestResult};
use SugarCraft\Testing\Input\ScriptedInput;

$script = ScriptedInput::new()->key('a')->enter()->resize(80, 24)->quit()->build();
$sim = ProgramSimulator::for($model);          // Model|Program
foreach ($script as $msg) { $sim->send($msg); }
$result = $sim->run();                          // TestResult

Assertions::assertGoldenAnsi(__DIR__ . '/fixtures/flow.golden', $result->output);
$result->assertCmdCount(1);                      // or ->assertNoCmds() / ->assertCmdContains(fn($c) => ...)
$this->assertInstanceOf(MyModel::class, $result->model);
```
`TestResult` exposes `->model`, `->view` (last frame), `->output` (concatenated frames), `->cmds`. To block real side effects use `ProgramSimulator::for($m)->withRealCmdRunner(false)` (captures cmds without executing) or `->withFakeCmdRunner($closure)`. **Verify: the run terminates (script ends with `->quit()`) and assertions read from `$result`.**

### 8. Run and green
```sh
cd <slug> && composer install --quiet && vendor/bin/phpunit
```
Filter one test with `--filter testName`. If a red looks like a stale-dep artifact, `composer update --quiet` then re-run. **Verify: the touched lib's full suite is green before you consider the task done.**

## Examples

**User says:** "Write a snapshot test for the gradient progress bar in sugar-bits."

**Actions taken:**
1. Slug `sugar-bits` → `SugarCraft\Bits`; grep `sugar-bits/src/Progress/Progress.php` — no `class_alias`, it's canonical.
2. Mode = snapshot byte (`Progress::view()` returns SGR).
3. Add `testProgressBarWithGradientRendersAnsi()` to `sugar-bits/tests/GoldenRenderTest.php` building `Progress::new()->withWidth(5)->withGradient(Color::hex('#ff0000'), Color::hex('#00ff00'))->withPercent(1.0)`, assert via `Assertions::assertGoldenAnsi(__DIR__.'/fixtures/progress-gradient.golden', $p->view())`.
4. `cd sugar-bits && UPDATE_GOLDENS=1 vendor/bin/phpunit --filter testProgressBarWithGradient` to seed the fixture `sugar-bits/tests/fixtures/progress-gradient.golden`; eyeball the bytes.
5. Re-run without the env var → green.

**Result:** New test method + `sugar-bits/tests/fixtures/progress-gradient.golden`, byte-exact regression guard, suite green.

---

**User says:** "Test the hover tracker's update in candy-zone."

**Actions taken:** Mode = behaviour. In `candy-zone/tests/ZoneHoverTrackerTest.php`: `[$next, $cmd] = $tracker->update($this->move(2, 1)); $this->assertNotSame($tracker, $next); $this->assertInstanceOf(HoverMsg::class, $cmd);` and a moved-off case asserting `$cmd === null`.

**Result:** Behaviour test proving new-instance immutability + correct `?Cmd`, no `view()` side-effect assertions.

## Common Issues

- **`No golden file found at '.../x.golden'. Set UPDATE_GOLDENS=1 to create it.`** → The fixture is missing. Run `UPDATE_GOLDENS=1 vendor/bin/phpunit --filter <test>` once, then **read the generated file** to confirm the baseline is correct before committing.
- **`Golden file mismatch at '...'`** with a `\x1b[...m` diff → Either the render genuinely changed (fix the code) or the baseline is stale (delete the `.golden` under `tests/fixtures/`, re-seed with `UPDATE_GOLDENS=1`). Never edit `.golden` files by hand.
- **`Dimension mismatch: expected AxB, got CxD`** from `assertCellGrid` → Your `$expected` 2-D array size doesn't match `$buffer->width()`×`height()`. Size the grid to the buffer, not the visible content.
- **`Cannot use object of type ... as array` on `[$m, $cmd] = $x->update(...)`** → `update()` returned a bare Model, not `[Model, ?Cmd]`. Fix the Model to return the tuple (`return [$next, null];`).
- **Test mutates original then asserts it changed** → State is `readonly`; `with*()`/`update()` return new instances. Assign the result (`$m = $m->withX()`) and assert on the new instance.
- **Program test hangs** → `ScriptedInput` script never emits `->quit()`; `ProgramSimulator::run()` drains until a `QuitMsg`. Append `->quit()`. For PTY/FFI hangs, `timeout` won't help — use a backgrounded `pkill` watchdog.
- **Local red, CI green** → Stale per-lib `vendor/`. `cd <slug> && composer update --quiet && vendor/bin/phpunit`.
- **`Class "SugarCraft\Testing\..." not found`** → The lib lacks `sugarcraft/candy-testing` in `require-dev` + a path-repo. Add both, then `php tools/check-path-repos.php --fix`.
