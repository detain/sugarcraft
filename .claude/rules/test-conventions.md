---
paths:
  - '*/tests/**/*.php'
---

# PHPUnit 10 conventions

- Tests at `<slug>/tests/<Class>Test.php`, namespace `<NS>\<Sub>\Tests\…`.
- `bootstrap="vendor/autoload.php"`, `failOnWarning="true"`, `cacheDirectory=".phpunit.cache"` (see `candy-core/phpunit.xml`).
- Every public method needs ≥1 test.

**Patterns** (prior art in `sugar-bits/tests/`, `candy-core/tests/`):
- **Snapshot tests** for renderers — two flavors:
  - *Byte snapshot* (default for renderer-internal tests): call `view()`, assert raw `\x1b[1m`-style SGR escape strings. Don't abstract the bytes. Right when you're verifying the renderer's ANSI output itself.
  - *Cell-grid snapshot* (preferred for downstream / integration tests): drive bytes through `SugarCraft\Vt\Terminal\Terminal` and assert on `$term->screen()->cell($r, $c)->grapheme / sgr / foreground()` (and `$term->cursor()`, `$term->mode()`). Survives ANSI-byte-level reordering — e.g. redundant SGR re-emission or cursor-position equivalents — that doesn't change what the user actually sees. See `candy-vt/tests/SnapshotTest.php` for fixture-driven examples.
- **Behaviour tests** for state machines — drive `update()` with scripted `KeyMsg` / `MouseMsg` instances, assert resulting state tuple `[Model, ?Cmd]`.
- **Coercion tests** — feed edge cases (negative index, oversized index, empty input, null), assert clamp/no-op behaviour matching upstream.

**Stream-write gotcha**: do NOT `ftruncate($out, 0); rewind($out);` between writes — produces empty reads. Slice deltas instead: `$end = ftell($out); $r->render(...); fseek($out, $end); $delta = stream_get_contents($out);` (canonical pattern in `candy-core/tests/RendererTest.php`).

**Run**: `cd <slug> && composer install && vendor/bin/phpunit`.
