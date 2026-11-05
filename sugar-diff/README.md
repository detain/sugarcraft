# SugarDiff

Unified-diff engine — LCS line diffing, context hunks, a GNU `diff -u` writer, and a line-number scanner. First-party (there is no 1:1 Go counterpart): the engine and the scanner were extracted from `candy-code`, whose `Tools\Concerns\BuildsUnifiedDiff` trait now delegates here. `candy-code`'s `Tui\DiffGutter` is a port of this scanner's region model and still keeps its own copy today — delegating it here is an open seam.

## Installation

Releases: pre-1.0 — no tagged versions exist yet, so consume `dev-master`.

```bash
composer require sugarcraft/sugar-diff:@dev
```

## Role

- **`Diff::compute()`** — prefix/suffix-trimmed LCS over lines (same algorithmic guarantees as the candy-code trait: `>=` tie-break deletes first, `maxLcsCells` budget falls back to a whole-middle replace block, reported by the `degraded` flag — with the 250 000-cell default, edits far apart in a large file really can exceed the budget, so check the flag), producing numbered `Line` rows grouped into `Hunk`s.
- **`Writer\UnifiedFormat`** — renders `diff -u` shaped text: `--- / +++` headers, `@@ -a[,b] +c[,d] @@` hunk headers (GNU count elision included), optional `\ No newline at end of file` markers and `/dev/null` empty-side labels. Path labels are control-byte-safe (quoted like git's `core.quotePath`).
- **`Writer\SideBySideFormat`** — pairs one diff into side-by-side `[left?, right?]` rows, line by line, for split viewers: `rows(Diff)` from the hunk model, `fromUnified(string|list<string>)` from already-rendered patch text via `Reader\UnifiedScan` (both return the same rows for the same change). Leftover removals/additions stand alone; width and colour stay with the caller.
- **`Reader\UnifiedScan`** — walks any rendered unified diff and tells a viewer which old/new line each row points at, honouring each hunk's declared counts and classifying every row with a `Reader\ScanRowKind` (the gutter model the candy-code TUI was built from), without owning any view code.
- **`Writer\SideBySideRow` / `Reader\ScannedLine`** — the immutable value rows of the two render/scan halves.

## Quickstart

```php
use SugarCraft\Diff\Diff;
use SugarCraft\Diff\DiffOptions;
use SugarCraft\Diff\Reader\UnifiedScan;

$before = "Hello World\n";
$after  = "Hello PHP\n";
$diff = Diff::compute($before, $after);

$diff->isEmpty();        // false
$diff->addedLines();     // 1
$diff->removedLines();   // 1

$patchText = $diff->unified('file.txt');
echo $patchText;
// --- a/file.txt
// +++ b/file.txt
// @@ -1 +1 @@
// -Hello World
// +Hello PHP

// Options are immutable + fluent; defaults reproduce the candy-code trait's
// hunk bodies and grouping (headers are GNU-faithful — see Fidelity notes).
$wide = Diff::compute($before, $after, DiffOptions::new()
    ->withContextLines(10)
    ->withIgnoreWhitespace()
    ->withNoNewlineMarker()
    ->withDevNullOnEmptySide());

// Row model for renderers:
foreach ($diff->lines as $line) {
    // $line->kind (LineKind enum), $line->text, $line->oldNumber, $line->newNumber
    // $line->marker(), $line->toUnifiedRow()
}

// Number the rows of an existing patch (e.g. for gutter display):
foreach (UnifiedScan::lines($patchText) as $row) {
    // $row->old, $row->new, $row->fileHeader, $row->kind (ScanRowKind enum)
}
```

## API surface

| Class | Purpose |
|-------|---------|
| `Diff` | `compute()` entry point; `lines`, `hunks`, `options`, `beforeCount`/`afterCount`, `beforeHasFinalNewline`/`afterHasFinalNewline`, `degraded`; `isEmpty()`, `addedLines()`, `removedLines()`, `unified($oldPath, ?$newPath = null)` (second argument names the new side only — the before side uses the first), `hunkText()` |
| `DiffOptions` | `new()`; `withContextLines()` (`DEFAULT_CONTEXT_LINES` = 3), `withMaxLcsCells()` (`DEFAULT_MAX_LCS_CELLS` = 250 000), `withIgnoreWhitespace()`, `withIgnoreCase()`, `withNoNewlineMarker()`, `withDevNullOnEmptySide()` |
| `Hunk` | `header()`, `lines()`, `removedCount()`/`addedCount()` (`-`/`+` rows only), the `oldStart`/`oldLines`/`newStart`/`newLines` anchors |
| `Line` / `LineKind` | One numbered row (verbatim text, side numbers, `marker()`, `toUnifiedRow()`); `eq`/`del`/`ins` vocabulary |
| `Writer\UnifiedFormat` | `write()` / `hunksOnly()` statics behind `Diff::unified()` / `Diff::hunkText()`; `NO_NEWLINE_ROW` |
| `Writer\SideBySideFormat` / `Writer\SideBySideRow` | `rows()` / `fromUnified()` pairing into `[left?, right?]` value rows for split layouts; `SideBySideRow::isPaired()` |
| `Reader\UnifiedScan` | `lines()` / `fileHeaders()` / `rowsOf()` row walks with declared-count budgets and fail-closed boundaries; `MAX_LINE_NUMBER_DIGITS` |
| `Reader\ScannedLine` / `Reader\ScanRowKind` | Per-row `(old, new, fileHeader, kind)` region model; row classification enum |

Non-visual engine: no ANSI, no rendering, no demos — view layers (gutters, coloring) consume the model and stay downstream. Sides accept a raw `string` or a `list<string>` (one element = one line; an element containing `"\n"` is refused — `file()` callers pass `FILE_IGNORE_NEW_LINES`). The model is eagerly materialised: `compute()` allocates the full `Line`/`Hunk` row set, and `addedLines()`/`removedLines()` re-walk it per call.

## Rendering safely

Hunk row text ships exactly what the sides held — the engine never strips control bytes from *content*, because content is the caller's payload. A surface painting diffs of untrusted files should sanitize or escape those bytes for its own output channel (the TUI gutter clips to width; a web view HTML-escapes). The one thing the writer will not do is let a *path label* forge protocol rows: labels containing control bytes, `"` or `\` are C-quoted git-`core.quotePath`-style, so every emitted header stays one physical line.

## Fidelity notes

- Default options reproduce `candy-code`'s `BuildsUnifiedDiff` hunk **bodies** and hunk grouping byte-for-byte; the header lines deliberately diverge (GNU-faithful — next bullet), and the `\ No newline` markers and `/dev/null` labels are opt-in GNU affordances the trait never emitted.
- Header printing is GNU-faithful (oracle-verified vs `/usr/bin/diff -U0`): a zero-length side anchors at the line the hunk sits after (`@@ -1,0 +2 @@` mid-file, `@@ -0,0 +1 @@` at the head) and keeps its `,0`, and a single-line range drops its count (`@@ -4 +4 @@`, not `-4,1 +4,1`) — like GNU `diff -u` and git.
- git appends an optional function-context heading after the closing `@@`; this engine has no enclosing-function notion, emits none, and the scanner ignores anything there.
- File headers are git-style `--- a/<path>` / `+++ b/<path>` with no timestamps; GNU's own shape is bare paths tab-separated from a date column.
- A line's newline termination is invisible to the line diff: `"a\n"` vs `"a"` compare equal and produce **no hunks at all**, and a terminator-only change stays silent whether or not the marker is enabled — with no hunk triggered there is no row for a `\ No newline at end of file` annotation to ride on. The `beforeHasFinalNewline`/`afterHasFinalNewline` facts are exposed so a caller can surface such a change itself.
- `withNoNewlineMarker()` governs *triggered* hunks instead: with it ON, a hunk that ends on an unterminated final row carries the same `\ No newline at end of file` line GNU `diff -u` and git emit, and that annotated shape is what `patch`-style consumers expect to read; with it OFF (the default) the row prints without the annotation. The annotation is a `string`-side affair only — a `list<string>` side is terminated by construction, so the marker is inert there.
- CRLF vs LF differ in **every** line under default exact comparison (`\r` is content); `withIgnoreWhitespace()` folds `\r` away — deliberately stronger than git's `--strip-trailing-cr`.
- Zero-length sides anchor at the line the hunk sits AFTER, so at the start of a file the header reads `@@ -0,0 +1 @@` — a caret-style anchor, not a row.
- Binary files and rename/mode/CIM headers are out of scope: `git diff --binary` payloads and extended headers (`similarity index`, `old mode`, …) are not modelled — extended header lines scan as file headers, and binary content would diff as text by design. Colour is likewise left to the consuming surface (see candy-code's `Tui\DiffGutter` and web `DiffView`).
- This library produces and reads patch text; it does not apply patches. `patch`-style hunk fuzz, reversal detection and the `--batch`/already-applied behaviours are outside its scope — feed the output to a real applier if you need them.

Terminology, one place: old side = removed side = left; new side = added side = right.

## License

MIT
