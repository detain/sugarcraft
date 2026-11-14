# SugarDiff

Unified-diff engine — LCS line diffing, context hunks, a GNU `diff -u` writer, and a line-number scanner. First-party (there is no 1:1 Go counterpart): the engine and the scanner were extracted from `candy-code`, whose `Tools\Concerns\BuildsUnifiedDiff` trait now delegates here. `candy-code`'s `Tui\DiffGutter` delegates its scan half here (D1a fold landed): the gutter's numbering, file-header verdicts and hunk recognition ride `UnifiedScan::lines()`, `UnifiedScan::fileHeaders()` and `UnifiedScan::isHunkHeader()`; only the fixed-width gutter formatting stays app-side.

Two GitHub locations, one codebase: development happens in [`detain/sugarcraft`](https://github.com/detain/sugarcraft), the monorepo where this library's CI and coverage run; CI syncs the tree to the standalone [`sugarcraft/sugar-diff`](https://github.com/sugarcraft/sugar-diff) repository, which is the Packagist install source and where you file issues and read the split tests (the same URLs ride the `homepage`/`support` fields in `composer.json`). The browsable form of this document lives on [sugarcraft.github.io](https://sugarcraft.github.io/) — the [`sugar-diff` library page](https://sugarcraft.github.io/lib/sugar-diff.html) carries the full per-method signature table that the API surface below summarizes.

## Installation

Releases: pre-1.0 — no tagged versions exist yet, so consume `dev-master`. The manifest requires PHP `^8.3` and `ext-mbstring`: `withIgnoreCase()` folds case through `mb_strtolower()` (gated on `mb_check_encoding()`, with a byte-wise ASCII failover for malformed input).

```bash
composer require sugarcraft/sugar-diff:@dev
```

## Role

- **`Diff::compute()`** — prefix/suffix-trimmed LCS over lines (same algorithmic guarantees as the candy-code trait: `>=` tie-break deletes first, `maxLcsCells` budget falls back to a whole-middle replace block, reported by the `degraded` flag — with the 250 000-cell default, edits far apart in a large file really can exceed the budget, so check the flag), producing numbered `Line` rows grouped into `Hunk`s.
- **`Writer\UnifiedFormat`** — renders `diff -u` shaped text: `--- / +++` headers, `@@ -a[,b] +c[,d] @@` hunk headers (GNU count elision included), optional `\ No newline at end of file` markers and `/dev/null` empty-side labels. Path labels are control-byte-safe (C-quoted core.quotePath-style — see Fidelity notes for the one deliberate deviation from git).
- **`Writer\SideBySideFormat`** — pairs one diff into side-by-side `[left?, right?]` rows, line by line, for split viewers: `rows(Diff)` from the hunk model, `fromUnified(string|list<string>)` from already-rendered patch text via `Reader\UnifiedScan` (both return the same rows for the same change). Leftover removals/additions stand alone; width and colour stay with the caller.
- **`Reader\UnifiedScan`** — walks any rendered unified diff and tells a viewer which old/new line each row points at, honouring each hunk's declared counts and classifying every row with a `Reader\ScanRowKind` (the gutter model the candy-code TUI was built from), without owning any view code. `isHunkHeader(string): bool` publishes the walk's own recognition predicate, so a consumer can gate boundaries without a second regex to drift.
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
    ->withNoNewlineAnnotation()
    ->withDevNullOnEmptySide());

// None of those four options bite this one-line change: the sides differ
// beyond whitespace, neither is empty, both end in a newline, and the single
// hunk fits any context window — so the wide-options patch is byte-identical:
echo $wide->unified('file.txt') === $patchText ? "same patch\n" : "wider patch\n";
// same patch

// Row model for renderers (derived on demand — see the note below the table):
foreach ($diff->lines() as $line) {
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
| `Diff` | `compute()` entry point; `hunks` (retained row model — hunk bodies only), `options`, `beforeCount`/`afterCount`, `beforeHasFinalNewline`/`afterHasFinalNewline`, `degraded`; `lines()` (full row sequence, materialized on demand and memoized), `isEmpty()`, `addedLines()`, `removedLines()`, `unified($oldPath, ?$newPath = null)` (second argument names the new side only — the before side uses the first), `unifiedWithLabels($oldLabel, $newLabel)` (caller-supplied headers, still row-forgery-guarded; no prefixes forced), `hunkText()` |
| `DiffOptions` | `new()`; every option is also a promoted readonly property (`contextLines`, `maxLcsCells`, `ignoreWhitespace`, `ignoreCase`, `noNewlineAnnotation`, `devNullOnEmptySide`, `oldOffset`, `newOffset`) — the only way to ask which options produced a patch is `$diff->options`; `withContextLines(int)` (negative clamps to 0; `DEFAULT_CONTEXT_LINES` = 3), `withMaxLcsCells(int)` (0 = a middle with rows on both sides falls back degraded; a one-sided middle is linear work and stays exact; `DEFAULT_MAX_LCS_CELLS` = 250 000), `withIgnoreWhitespace(bool $ignore = true)`, `withIgnoreCase(bool $ignore = true)`, `withNoNewlineAnnotation(bool $on = true)`, `withDevNullOnEmptySide(bool $on = true)`, `withOldOffset(int)` / `withNewOffset(int)` (shift that side's hunk headers and row numbers for relocated presentation; negative clamps to 0; the default 0 keeps every byte of the ported engine) |
| `Hunk` | `header()`, `lines()`, `removedCount()`/`addedCount()` (`-`/`+` rows only, context excluded — O(1), backed by the constructor-stored readonly totals `removedTotal`/`addedTotal`), the `oldStart`/`oldLines`/`newStart`/`newLines` anchors |
| `Line` / `LineKind` | One numbered row (verbatim text, side numbers, `marker()`, `toUnifiedRow()`); `eq`/`del`/`ins` vocabulary |
| `Writer\UnifiedFormat` | `write()` / `writeWithLabels()` / `hunksOnly()` statics behind `Diff::unified()` / `Diff::unifiedWithLabels()` / `Diff::hunkText()`; `NO_NEWLINE_ANNOTATION` |
| `Writer\SideBySideFormat` / `Writer\SideBySideRow` | `rows()` / `fromUnified()` pairing into `[left?, right?]` value rows for split layouts; `SideBySideRow::isPaired()` |
| `Reader\UnifiedScan` | `lines()` / `fileHeaders()` / `rowsOf()` row walks with declared-count budgets and fail-closed boundaries; `isHunkHeader()` recognition predicate; `MAX_LINE_NUMBER_DIGITS` |
| `Reader\ScannedLine` / `Reader\ScanRowKind` | Per-row `(old, new, fileHeader, kind)` region model; row classification enum — seven unbacked cases, `Context`, `Insert`, `Delete`, `HunkHeader`, `FileHeader`, `NoNewlineAnnotation`, `OutsideHunk` (`$row->kind` is the enum OBJECT — label it with `->name`; it is not a string and will never coerce to one); `isContentRow()` is true exactly for `Context`/`Insert`/`Delete`, so a pairing renderer gates its runs on the kind instead of re-sniffing the marker column. `Hunk`'s row/property surface is not further documented here until the OG-3 ruling (SD-20) settles it |

Non-visual engine: no ANSI, no rendering, no demos — view layers (gutters, coloring) consume the model and stay downstream. Sides accept a raw `string` or a `list<string>` (one element = one line; an element containing `"\n"` is refused — `file()` callers pass `FILE_IGNORE_NEW_LINES`). The silent mirror of that refusal: a `list<string>` side's final element is a line even when empty, so `explode("\n", "a\nb\n")` hands the engine a 3-line side where the string channel counts 2, shifting `beforeCount` and every row number — pass `file($p, FILE_IGNORE_NEW_LINES)` or strip the trailing `""` before handing an `explode()` result in. The model is lazy at the row level: `compute()` materializes `Line` rows only for hunk bodies (changed ± context) and keeps the op sequence as one packed byte per row, so a one-line change in a huge file retains hunk-sized state — not one `Line` per input line; `lines()` derives the full row sequence on demand (memoized after the first call), and `addedLines()`/`removedLines()` count the packed bytes without materializing anything.

### Diffing two files

Sides take a raw `string` or a `list<string>`; for real files use the string
channel and cast away `file_get_contents()`'s `string|false`:

```php
$diff = Diff::compute(
    (string) file_get_contents($a),
    (string) file_get_contents($b),
    DiffOptions::new()->withNoNewlineAnnotation(),
);
echo $diff->unified($a, $b);
```

Reading with `file($path, FILE_IGNORE_NEW_LINES)` works too, but the two
channels diff differently once a final newline is missing: a `list<string>`
side is terminated by construction, so `beforeHasFinalNewline`/
`afterHasFinalNewline` report `true` there whatever the file held, and when
some other change triggers a hunk only a string side can carry the
`\ No newline at end of file` annotation, and a terminator-only change is
zero hunks while the marker is off — with it on it renders GNU's
`-c`/`+c` pair (see the Fidelity notes).

### Consuming rows side by side

`Writer\SideBySideFormat::rows()` pairs one `Diff`'s changed runs into
`[left?, right?]` rows for split viewers — `fromUnified()` yields exactly the
same rows from rendered patch text:

```php
use SugarCraft\Diff\Writer\SideBySideFormat;

foreach (SideBySideFormat::rows($diff) as $row) {
    $left  = $row->left?->text;    // old column, numbered $row->left?->oldNumber
    $right = $row->right?->text;   // new column, numbered $row->right?->newNumber
    // $row->isPaired() separates replacements and context from leftovers,
    // which stand alone with one half null.
}
```

## Rendering safely

Hunk row text ships exactly what the sides held — the engine never strips control bytes from *content*, because content is the caller's payload. A surface painting diffs of untrusted files should sanitize or escape those bytes for its own output channel (the TUI gutter clips to width; a web view HTML-escapes). The one thing the writer will not do is let a *path label* forge protocol rows: labels containing control bytes, `"` or `\` are C-quoted git-`core.quotePath`-style, so every emitted header stays one physical line. The refusal asymmetry runs one way only: `UnifiedScan::lines()` and `SideBySideFormat::fromUnified()` never newline-refuse their row input and never re-emit it — a multi-line element (`"-a\n+++ forged"`) scans and pairs verbatim as a single row, so a host feeding `file()` lines without `FILE_IGNORE_NEW_LINES` into the scan side owns its channel.

Colour never has to carry a change alone, and the model refuses to make it carry anything: every row arrives with redundant non-colour channels — a `LineKind` with its column-0 `marker()` (` `, `-`, `+`), and, for scanned patches, a `ScanRowKind` separating hunk headers, annotations and out-of-hunk prose from content. A surface whose add/remove sign is the *only* difference between two rows fails WCAG 1.4.1 (Use of Colour); the reference consumer tints over the kind, never instead of it — candy-code-web's `DiffView.vue` renders a visible `+`/`−` sign column derived from `line.kind` beside its background tint.

## Fidelity notes

- Default options reproduce `candy-code`'s `BuildsUnifiedDiff` hunk **bodies** and hunk grouping byte-for-byte; the header lines deliberately diverge (GNU-faithful — next bullet), and the `\ No newline` markers and `/dev/null` labels are opt-in GNU affordances the trait never emitted.
- Header printing is GNU-faithful (oracle-verified vs `/usr/bin/diff -U0`): a zero-length side anchors at the line the hunk sits after (`@@ -1,0 +2 @@` mid-file, `@@ -0,0 +1 @@` at the head) and keeps its `,0`, and a single-line range drops its count (`@@ -4 +4 @@`, not `-4,1 +4,1`) — like GNU `diff -u` and git.
- git appends an optional function-context heading after the closing `@@`; this engine has no enclosing-function notion, emits none, and the scanner ignores anything there.
- File headers are git-style `--- a/<path>` / `+++ b/<path>` with no timestamps; GNU's own shape is bare paths tab-separated from a date column. The `a/`/`b/` prefix is prepended by literal concatenation: an absolute path `/etc/passwd` renders `--- a//etc/passwd`, and a path already beginning `a/` doubles it (`--- a/a/lib.php`) — GNU prints paths as given and git's prefixes are repo-root-relative, so pass root-relative paths if you care how the label reads (the scanner's sibling-header logic is unbothered by the doubled slash).
- Path quoting is `core.quotePath`-*style* with exactly **one** deliberate deviation from git: bytes ≥ 0x80 pass through raw (git octal-escapes every byte above 0x7E — high bytes can carry no control code, and raw UTF-8 filenames stay readable). Everything else matches live git byte-for-byte: when a label is quoted at all, the `a/`/`b/` prefix rides *inside* the quoted string — `--- "a/w\"ird.txt"` against git 2.43.0 (probe recorded in `tests/Writer/UnifiedFormatQuotingTest.php`) — and only labels carrying control bytes, `"` or `\` are quoted, so clean paths — and their prefixes — stay byte-identical.
- Ties on repeated-content files may **place** the edit on a different occurrence than GNU/Myers does (both scripts stay minimal and both apply): the engine's LCS backtracking takes delete-first on equal scores, while GNU's anchors elsewhere — 120 of 882 fuzz shapes in the P2 probe differed in placement, and every one of them byte-applied. Do not pin hunk bodies against `/usr/bin/diff` on repeated content; `tests/GnuTieApplyOracleTest.php` holds the honest contract (equal edit counts, byte-exact application via `patch`).
- A terminator-only change is invisible to the line diff **only while the annotation option is off**: with `withNoNewlineAnnotation()` OFF (the default), `"a\n"` vs `"a"` folds to no hunks at all — the ported behaviour, kept byte-for-byte — and the `beforeHasFinalNewline`/`afterHasFinalNewline` facts are exposed so a caller can surface such a change itself.
- With the marker ON the terminator is part of the last line's equality: a side that ends without a newline against a side that ends with one renders GNU's `-c` / `+c` pair with the `\ No newline at end of file` annotation under the unterminated side (oracle-verified and apply-tested via `patch` — `tests/Writer/UnifiedFormatApplyTest.php`), and any triggered hunk that ends on an unterminated final row carries the same annotation. With it OFF the row prints without the annotation. The annotation is a `string`-side affair only — a `list<string>` side is terminated by construction, so the marker is inert there.
- CRLF vs LF differ in **every** line under default exact comparison (`\r` is content); `withIgnoreWhitespace()` folds `\r` away — deliberately stronger than git's `--strip-trailing-cr`.
- Binary files are out of scope: `git diff --binary` payloads are not modelled — binary content would diff as text by design.
- git's rename/mode/CIM extended headers (`similarity index`, `rename from`, `old mode`, …) are not modelled either, and the scanner does not prettify them: they are
  scanned as unnumbered out-of-hunk rows (`ScanRowKind::OutsideHunk`), so `fileHeaders()` never yields them — only `diff `, git's own `index <sha>..` line, and out-of-hunk `--- `/`+++ ` count as file headers.
- git **combined diffs** (`git diff --cc`, merge output from `git show`) are out of scope too: their `@@@` columns are not the single-pair `@@ -a[,b] +c[,d] @@` grammar this library writes and scans, so `isHunkHeader()` reports false for `@@@ -1,2 -1,4 +1,3 @@@` and every combined-diff row — header and two-column body alike — scans as an unnumbered `OutsideHunk` row rather than being misnumbered. To view a merge change, diff against one parent (`git diff <parent>`) or hand the texts to `Diff::compute()` directly.
- Colour is left to the consuming surface (see candy-code's `Tui\DiffGutter` and web `DiffView`).
- This library produces and reads patch text; it does not apply patches. **Use instead / not for:** applying — `/usr/bin/patch` or `git apply` (hunk fuzz, reversal detection and the `--batch`/already-applied behaviors are stateful work a pure producer declines); word- and inline-level diffs — `sebastianbergmann/diff` or a `diff`-based word renderer (this engine is line-mode only: `Diff::compute()` compares whole line rows, see `src/Diff.php`); binary payloads — `git diff --binary` (binary content would diff as text by design, previous bullet).

Terminology, one place: old side = removed side = left; new side = added side = right.

## License

MIT
