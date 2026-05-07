---
paths:
  - '*/tests/**/*.php'
---

# PHPUnit 10 conventions

- Tests at `<slug>/tests/<Class>Test.php`, namespace `<NS>\<Sub>\Tests\…`.
- `bootstrap="vendor/autoload.php"`, `failOnWarning="true"`, `cacheDirectory=".phpunit.cache"` (see `candy-core/phpunit.xml`).
- Every public method needs ≥1 test.

**Patterns** (prior art in `sugar-bits/tests/`, `candy-core/tests/`):
- **Snapshot tests** for renderers — call `view()`, assert raw `\x1b[1m`-style SGR escape strings. Don't abstract the bytes.
- **Behaviour tests** for state machines — drive `update()` with scripted `KeyMsg` / `MouseMsg` instances, assert resulting state tuple `[Model, ?Cmd]`.
- **Coercion tests** — feed edge cases (negative index, oversized index, empty input, null), assert clamp/no-op behaviour matching upstream.

**Stream-write gotcha**: do NOT `ftruncate($out, 0); rewind($out);` between writes — produces empty reads. Slice deltas instead: `$end = ftell($out); $r->render(...); fseek($out, $end); $delta = stream_get_contents($out);` (canonical pattern in `candy-core/tests/RendererTest.php`).

**Run**: `cd <slug> && composer install && vendor/bin/phpunit`.
