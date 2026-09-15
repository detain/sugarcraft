---
paths:
  - '*/tests/**/*.php'
  - '*/phpunit.xml'
---

# PHPUnit 10 conventions

- Tests at `<slug>/tests/<Class>Test.php`, namespace `<NS>\<Sub>\Tests\…`.
- `bootstrap="vendor/autoload.php"`, `failOnWarning="true"`, `cacheDirectory=".phpunit.cache"` — see `candy-core/phpunit.xml`. Every public method needs ≥1 test.
- **Loop-pinning bootstrap**: a suite that bounds waits with timers on the shared `Loop::get()` sets `bootstrap="tests/bootstrap.php"` and calls `\SugarCraft\Testing\LoopPin::pinStableClock()` there (`candy-async`, `candy-mosaic`, `candy-pty`, `candy-testing`, `sugar-crush`). Under ext-uv, timer deadlines are computed against a clock refreshed once per loop iteration, so a timer armed after synchronous idle is already overdue and fires on the first tick; `StreamSelectLoop` refreshes at arm time. Mechanism in `candy-testing/src/LoopPin.php`, measurements on `candy-core/src/Program.php`.

**Patterns** (`sugar-bits/tests/`, `candy-core/tests/`, `candy-vt/tests/`):
- **Snapshot byte** — call `view()`, assert raw `\x1b[1m`-style SGR strings. Don't abstract.
- **Golden render** — `<slug>/tests/GoldenRenderTest.php` asserts byte-exact `view()`/`render()` output against committed `<slug>/tests/fixtures/*.golden` fixtures via `SugarCraft\Testing\Snapshot\Assertions::assertGoldenAnsi($path, $output)`. Needs `sugarcraft/candy-testing` in `require-dev` only — CI injects the path repos (canonical `sugar-charts/tests/GoldenRenderTest.php`).
- **Snapshot cell-grid** — drive bytes through `SugarCraft\Vt\Terminal\Terminal`, assert `$term->screen()->cell($r,$c)` (note `$screen->cols`/`$screen->rows` are readonly PROPERTIES, not methods).
- **Behaviour** — drive `update()` with scripted `KeyMsg`/`MouseMsg`, assert `[Model, ?Cmd]` tuple.
- **Coercion** — feed negative/oversized index, empty, null; assert clamp/no-op matching upstream.
- **Cross-lib parity** — `candy-vcr/tests/VtParityTest.php` diffs the `candy-vcr` renderer against the `candy-vt` engine and must stay green against BOTH the linked monorepo `candy-vt` and the stale Packagist `dev-master` the CI matrix resolves. Gate a version-dependent assertion on a feature marker (`Scrollback::clear()`) probed through the `vtCarriesMarker()` helper — a bare `\method_exists()` on a literal class name constant-folds under PHPStan and the arm reports as always-true/always-false. Delete the legacy arm once published `dev-master` carries the fix.
- **Documentation drift** — `sugar-crush/tests/Config/ReadmeRosterDriftTest.php`, `EnvRosterDriftTest.php`, `TrustKeyDocumentationDriftTest.php`, `sugar-crush/tests/Commands/KeyBindingDriftTest.php` re-derive the rosters printed in `sugar-crush/README.md` + `sugar-crush/docs/*.md` from their generators in `sugar-crush/src/`. Adding a tool, slash command, env var, or key binding without the doc edit goes red.

**Stream-write gotcha**: don't `ftruncate; rewind;` between writes — slice deltas with `ftell`/`fseek`/`stream_get_contents` (canonical `candy-core/tests/RendererTest.php`).

**FFI tests** (`candy-pty/tests/`): structural tests run unconditionally; syscall round-trips call `requirePtySyscalls()` as the FIRST line and skip on FFI-less CI.

**Hang watchdog** (`candy-pty/tests/`): `tests/bootstrap.php` calls `HangWatchdog::install()` AFTER `LoopPin::pinStableClock()`. The out-of-process watchdog (`candy-pty/tests/Support/hang-watchdog.php`) bounds each test and SIGKILLs the runner with a forensic dump, because `PosixPump::pump()`/`MultiPump::run()` style loops can only hang, never fail. `candy-pty/tests/Support/SharedLoopResidue.php` throws on timers/streams left armed on the shared `Loop::get()`.

Run: `cd <slug> && composer install && vendor/bin/phpunit`. A bare `composer update` swaps symlinked siblings for Packagist copies — check with `php scripts/refresh-deps.php --status`.
