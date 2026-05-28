---
paths:
  - '*/tests/**/*.php'
  - '*/phpunit.xml'
---

# PHPUnit 10 conventions

- Tests at `<slug>/tests/<Class>Test.php`, namespace `<NS>\<Sub>\Tests\…`.
- `bootstrap="vendor/autoload.php"`, `failOnWarning="true"`, `cacheDirectory=".phpunit.cache"` — see `candy-core/phpunit.xml`. Every public method needs ≥1 test.

**Patterns** (`sugar-bits/tests/`, `candy-core/tests/`, `candy-vt/tests/`):
- **Snapshot byte** — call `view()`, assert raw `\x1b[1m`-style SGR strings. Don't abstract.
- **Snapshot cell-grid** — drive bytes through `SugarCraft\Vt\Terminal\Terminal`, assert `$term->screen()->cell($r,$c)` (note `$screen->cols`/`$screen->rows` are readonly PROPERTIES, not methods).
- **Behaviour** — drive `update()` with scripted `KeyMsg`/`MouseMsg`, assert `[Model, ?Cmd]` tuple.
- **Coercion** — feed negative/oversized index, empty, null; assert clamp/no-op matching upstream.

**Stream-write gotcha**: don't `ftruncate; rewind;` between writes — slice deltas with `ftell`/`fseek`/`stream_get_contents` (canonical `candy-core/tests/RendererTest.php`).

**FFI tests** (`candy-pty/tests/`): structural tests run unconditionally; syscall round-trips call `requirePtySyscalls()` as the FIRST line and skip on FFI-less CI.

Run: `cd <slug> && composer install && vendor/bin/phpunit`.
