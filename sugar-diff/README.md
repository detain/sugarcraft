# SugarDiff

Unified-diff engine — LCS line diffing, context hunks, a GNU `diff -u` writer, and a line-number scanner. Extracted from `sugar-crush` (`Tools\Concerns\BuildsUnifiedDiff` + the `Tui\DiffGutter` region model) so every SugarCraft surface that shows or produces patches shares one faithful implementation. First-party: there is no 1:1 upstream Go library; this is the ecosystem's own engine.

## Installation

```bash
composer require sugarcraft/sugar-diff
```

## Role

- **`Diff::compute()`** — prefix/suffix-trimmed LCS over lines (same algorithmic guarantees as the sugar-crush trait: `>=` tie-break deletes first, `maxLcsCells` budget falls back to a whole-middle replace block), producing numbered `Line` rows grouped into `Hunk`s.
- **`Writer\UnifiedFormat`** — renders `diff -u` shaped text: `--- / +++` headers, `@@ -a,b +c,d @@` hunk headers, optional `\ No newline at end of file` markers and `/dev/null` empty-side labels.
- **`Reader\UnifiedScan`** — walks any rendered unified diff and tells a viewer which old/new line each row points at (the gutter model the sugar-crush TUI consumes), without owning any view code.

## Quickstart

```php
use SugarCraft\Diff\Diff;
use SugarCraft\Diff\DiffOptions;

$diff = Diff::compute("Hello World\n", "Hello PHP\n");

$diff->isEmpty();        // false
$diff->addedLines();     // 1
$diff->removedLines();   // 1

echo $diff->unified('file.txt');
// --- a/file.txt
// +++ b/file.txt
// @@ -1,1 +1,1 @@
// -Hello World
// +Hello PHP

// Options are immutable + fluent; defaults reproduce the sugar-crush output byte-for-byte.
$wide = Diff::compute($before, $after, DiffOptions::new()
    ->withContextLines(10)
    ->withIgnoreWhitespace()
    ->withNoNewlineMarker()
    ->withDevNullOnEmptySide());

// Row model for renderers:
foreach ($diff->lines as $line) {
    // $line->kind (LineKind enum), $line->text, $line->oldNumber, $line->newNumber
}

// Number the rows of an existing patch (e.g. for gutter display):
use SugarCraft\Diff\Reader\UnifiedScan;

foreach (UnifiedScan::lines($patchText) as $row) {
    // $row->old, $row->new, $row->fileHeader
}
```

## API surface

| Class | Purpose |
|-------|---------|
| `Diff` | `compute()` entry point; `lines`, `hunks`, `options`, `beforeCount`/`afterCount`, `beforeHasFinalNewline`/`afterHasFinalNewline`; `isEmpty()`, `addedLines()`, `removedLines()`, `unified()`, `hunkText()` |
| `DiffOptions` | `withContextLines()` (default 3), `withMaxLcsCells()` (default 250 000), `withIgnoreWhitespace()`, `withIgnoreCase()`, `withNoNewlineMarker()`, `withDevNullOnEmptySide()` |
| `Hunk` / `Line` / `LineKind` | The immutable region model — one `@@` block, one numbered row, `eq`/`del`/`ins` |
| `Writer\UnifiedFormat` | `write()` / `hunksOnly()` statics behind `Diff::unified()` / `Diff::hunkText()` |
| `Reader\UnifiedScan` | `lines()` / `fileHeaders()` row-number walk of any unified diff |

Non-visual engine: no ANSI, no rendering, no demos — view layers (gutters, coloring) consume the model and stay downstream.

## Fidelity notes

- Default options reproduce `sugar-crush`'s `BuildsUnifiedDiff` output byte-for-byte; the `\ No newline` markers and `/dev/null` labels are opt-in GNU affordances the trait never emitted.
- Empty hunk sides anchor at `0` (git's `@@ -1,1 +0,0 @@` shape), inherited from the source's rule rather than GNU's mid-file zero-length convention.
- Like git, a line's newline termination is invisible to the diff itself: `"a\n"` vs `"a"` compare equal; `"a\n"` vs `"a\n\n"` shows one added empty row.

## License

MIT

[codecov]: https://app.codecov.io/gh/sugarcraft/sugar-diff "codecov status"
