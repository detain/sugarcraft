---
paths:
  - '*/tests/**/*.php'
  - '*/phpunit.xml'
---

# PHPUnit 10 conventions

- Tests at `<slug>/tests/<Class>Test.php`, namespace `<NS>\<Sub>\Tests\…`.
- `bootstrap="vendor/autoload.php"`, `failOnWarning="true"`, `cacheDirectory=".phpunit.cache"` (see `candy-core/phpunit.xml`).
- Every public method needs ≥1 test.

**Patterns** (prior art in `sugar-bits/tests/`, `candy-core/tests/`, `candy-sprinkles/tests/`):
- **Snapshot tests** for renderers — two flavors:
  - *Byte snapshot* (default for renderer-internal): call `view()`, assert raw `\x1b[1m`-style SGR escape strings. Don't abstract.
  - *Cell-grid snapshot* (preferred for integration): drive bytes through `SugarCraft\Vt\Terminal\Terminal` and assert on `$term->screen()->cell($r, $c)->grapheme / sgr / foreground()` (and `$term->cursor()`, `$term->mode()`). See `candy-vt/tests/`.
- **Behaviour tests** — drive `update()` with scripted `KeyMsg` / `MouseMsg` instances, assert resulting state tuple `[Model, ?Cmd]`.
- **Coercion tests** — feed edge cases (negative index, oversized index, empty input, null), assert clamp/no-op matching upstream.

**Stream-write gotcha**: do NOT `ftruncate($out, 0); rewind($out);` between writes — produces empty reads. Slice deltas: `$end = ftell($out); $r->render(...); fseek($out, $end); $delta = stream_get_contents($out);` (canonical in `candy-core/tests/RendererTest.php`).

**FFI-dependent tests** (`candy-pty/tests/`): split structural vs syscall — structural tests (cdef shape, exception hierarchy) run unconditionally; round-trip tests call a `requirePtySyscalls()` helper as the FIRST line and skip cleanly on FFI-less CI.

**Run**: `cd <slug> && composer install && vendor/bin/phpunit`.
