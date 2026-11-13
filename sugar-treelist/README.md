<img src=".assets/icon.png" alt="" width="160">

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-treelist)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-treelist)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/sugar-treelist?label=packagist)](https://packagist.org/packages/sugarcraft/sugar-treelist)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

# SugarTreelist

> Formerly published as `sugarcraft/candy-lister` (renamed 2026-10). <!-- @rename-keep -->
> Namespace changed: `SugarCraft\Lister\` → `SugarCraft\TreeList\`. <!-- @rename-keep -->

sugar-treelist — a tree-list view component for terminal UIs, for PHP 8.3+. Renders items with custom prefix/suffix hooks (box-drawing gutters by default), whitespace-faithful word wrap, cursor navigation, and host-driven live filtering.

## Features

- **Customizable Prefixer** — generates per-line prefix strings (0-based line numbers, box-drawing borders, tree branches)
- **Customizable Suffixer** — generates per-line suffix strings (status markers, padding)
- **Line wrapping** — items wrap to multiple lines within a fixed viewport width, whitespace-faithfully (indentation and inter-word runs survive)
- **Cursor navigation** — current item highlighted with configurable style
- **Viewport awareness** — fixed-height rendering via `withViewport()`; `withCursorOffset()` controls the context gap kept above the cursor (render-clamped so it can never exceed the viewport)
- **`Stringable` items** — any PHP object with `__toString()` or `Stringable` works as a list item
- **Plain-string intake** — every item door (`withItem()`, `withItems()`, `withItemsFromArray()`, `find()`) accepts `string|\Stringable` and normalizes internally; `null`/bool/int/float elements are refused with an `InvalidArgumentException` naming the position (never silently cast to `''`/`'1'`/`'2.5'`). `StringItem` remains for typed slots and explicit adapters.
- **`LessFunc` / `EqualsFunc`** — plug-in sorting and equality comparison
- **Fuzzy matching** — `FuzzyMatch` scores candidates by delegating to sugar-fuzzy's `SmithWatermanMatcher` (SSOT — see [Deprecation & delegation](#deprecation--delegation))
- **Filter state machine** — `withFilterFn()` / `withoutFilter()` with `FilterState` enum tracking (unfiltered / filtering; `filterState()` returns `null` until one of them is called for the first time); a filter is a live view, mutations project through it. `withFuzzyFilter()` / `withoutFuzzyFilter()` are the fuzzy search door over the same machinery
- **Delta rendering** — `view()` emits a full frame first, then repaints only the rows that changed; `resetPreviousFrame()` forces a repaint (see [Delta rendering](#delta-rendering))

## Install

```bash
composer require sugarcraft/sugar-treelist
```

## Quick Start

```php
use SugarCraft\TreeList\Model;

// Model::tree() = new() + the Default prefixer/suffixer pair: a ╭ ├ ╰
// join column, 0-based line numbers and a visible > cursor marker. Plain
// new() stays deliberately undecorated for custom-gutter consumers, and
// withTreeDefaults() picks the pair up mid-chain.
//
// Fluent setters RETURN NEW Models — rebind (or chain); instances are
// immutable as far as data goes — but view()/resetPreviousFrame() mutate
// the delta-render row memo in place (see Delta rendering).
$model = Model::tree()
    ->withWidth(80)
    ->withHeight(24)
    ->withItemsFromArray(['First item', 'Second item', 'Third item']);

echo $model->view();
// Renders the list with a ╭ ├ ╰ box-drawing gutter, 0-based line numbers, and > cursor marker
```

### Naming: `with*` everywhere

Clone-returning setters are named `with*()` (the house fluent vocabulary — a
`with*()` call that is not rebinded is as loud as a forgotten assignment can
be, and `set*()` on an immutable value would lie about what it does). The
older `set*()`/`add*()`/`cursor*()` spellings survive as **one-way deprecated
aliases** delegating to their `with*()` canonicals (`setWidth()` →
`withWidth()`, `addItem()` → `withItem()`, `cursorUp()` → `withCursorUp()`,
and so on for the full surface); they return a new Model and leave the
receiver unchanged, exactly like the canonicals. The aliases are removed in
2.0.0 — write new code against `with*()`.

## Item Types

**Note:** Item values are rendered through the library frame choke
(`Model::sweepForFrame`: sugar-core `Sanitize::untrustedForMarkedFrames` plus a
CR→LF fold): C0/C1 control bytes, DEL, any ANSI embedded in the item text, and
mouse-zone sentinels (U+E000/U+E001) are stripped at render time, and carriage
returns become line breaks (content splits, never overwrites) — item content
can forge neither a frame nor a click zone. Malformed UTF-8 is repaired at the
same choke before any grapheme-aware step: each invalid byte subsequence
becomes one `?` cell (well-formed text is untouched), so short and over-long
damaged values take the same path and a damaged word never collapses to a
silently empty row. The only styles that reach the
output are the SGR parameters you pass to
`withLineStyle()`/`withCurrentStyle()` — each is sanitize-then-reject: leading
escape/`[`/`m` decoration bytes are trimmed before the `[0-9;]` gate, so a
style like `m1;3` is repaired to `1;3` and accepted, while anything else that
survives the trim is refused (no escape bytes can ever ride through), and the
`DefaultPrefixer`/`DefaultSuffixer` glyph knobs refuse control bytes at init.
The library emits no colour of its own: all styles are caller-supplied — pass
empty style strings to emit no colour (sugar-treelist neither reads nor acts on
the `NO_COLOR` variable).

```php
// Plain strings are first-class item values — the intake doors normalize
// them to StringItem internally (see Plain-string intake above).
$model = $model->withItem('Plain string item');

// Any Stringable object
class MyItem implements \Stringable {
    public function __toString(): string { return 'Formatted item'; }
}
$model = $model->withItem(new MyItem());
```

## Custom Prefixer

`DefaultPrefixer` renders the box-drawing gutter and has public knobs (all
glyph strings are validated at init — control bytes and malformed UTF-8 are
refused): `$firstSep` (top corner, default `╭`), `$separator` (middle join,
default `├`), `$lastSep` (bottom corner, default `╰`), `$separatorWrap`
(continuation rows of a wrapped item, default `│`), `$currentMarker` (`>`),
`$emptyMarker` (a single space on non-cursor rows), `$number` (toggle the
number column — its field width is derived from the item count, so columns
stay aligned past nine items), `$numberRelative` (number as each item's
unsigned distance from the cursor: 2 1 0 1 2), and `$prefixWrap` (continuation
rows use `$separatorWrap` instead of the join glyph). The join glyph follows
the item's **position in the list**, so the gutter reads as a tree — the list
itself stays flat: tree structure is yours to encode as leading whitespace in
the item text, which survives the whitespace-faithful wrapper verbatim.
`DefaultSuffixer::$currentMarker` (default `<`) marks the cursor row.

Guide the cursor with text, not colour alone. Keep a marker glyph
(`$currentMarker`, or your suffixer's) so position survives monochrome
terminals and screen readers — relying on `withCurrentStyle()`'s colour by
itself is a WCAG 1.4.1 (use of colour) warning; if you do style-only, add a
redundant cue. Passing `''` to any glyph knob drops that decoration (control
bytes are refused loudly, empty is not). `withLineStyle('')` +
`withCurrentStyle('')` + `lines()` is the full plain-text mode: `lines()` never
styles and never deltas, so it is the right reader for assistive and
scripted-output hosts.

To implement the contract yourself:

```php
use SugarCraft\TreeList\{Prefixer, Model};
use SugarCraft\Core\Util\Width;

$model = $model->withPrefixer(new class implements Prefixer {
    public function initPrefixer(
        \Stringable $value, int $currentIndex, int $cursorIndex,
        int $lineOffset, int $width, int $height, int $totalItems
    ): int {
        return Width::string('• '); // widest emission, measured — see below
    }
    public function prefix(int $currentLine, int $totalLines): string {
        return $currentLine === 0 ? '• ' : '  ';
    }
});
```

Return the **cell width your `prefix()` can emit** (here: `Width::string('• ')`,
the widest of the two strings) — the Model subtracts the declared reservation
from the content budget verbatim and never re-measures the emitted prefix, so
a `return 0` for a two-cell gutter reproduces ragged, over-wide rows. Prefer
measuring with `Width::string()` over typing the number by hand:
East-Asian-Ambiguous glyphs (`•` `★` `✦` `◉`) are 1 cell on ordinary hosts but
2 on ambiguous-wide CJK configs, and a hand-computed reservation silently
desyncs from the renderer there (the shipped `DefaultPrefixer` chose
Wide-stable box glyphs for exactly this reason). An emitted prefix wider than
its reservation no longer overruns the viewport — every assembled row is
clamped to it — but the clamp is a safety net, not a budget: the overflow is
lost to the eye.

## Custom Suffixer

```php
use SugarCraft\TreeList\{Suffixer, Model};

$model = $model->withSuffixer(new class implements Suffixer {
    public function initSuffixer(
        \Stringable $value, int $currentIndex, int $cursorIndex,
        int $lineOffset, int $width, int $height
    ): int {
        return 0;
    }
    public function suffix(int $currentLine, int $totalLines): string {
        return '';
    }
});
```

Returning `''` from `suffix()` means *no suffix and no padding* on that line —
the reserved width is still subtracted from the content budget on every line,
but only a non-empty return is right-aligned, measured from the actual widths
of that row's prefix, content, and suffix, so the marker lands flush at the
viewport edge. Reserve the width your widest real suffix needs: a return
wider than the reservation can no longer overrun the viewport — every
assembled row is clamped to it, with the suffix giving cells first, then the
prefix, and only a spent gutter making the item content fall (a hostile
gutter degrades its own leg instead of corrupting sibling panes).

## Viewport

Set the rendering viewport dimensions before calling `view()`:

```php
$model = $model->withViewport(80, 25);     // or withWidth(80)->withHeight(25)
$model = $model->withCursorOffset(3);
$model = $model->withLineOffset(5);
$model = $model->withWrap(2);
```

| Setter | What it controls |
| --- | --- |
| `withCursorOffset(n)` | context gap kept **above** the cursor at the viewport top (render-clamped to `height - 1`) |
| `withLineOffset(n)` | context value reported to `initPrefixer()`/`initSuffixer()` for relative markers — not the viewport anchor, not a scroll budget |
| `withWrap(n)` | per-item displayed-row cap — `0` means **no per-item cap**; rows still stay bounded by the viewport height |

Geometry setters reject negative values and dimensions beyond the
`MAX_DIMENSION` cap; the `MAX_TOTAL_CELLS` area cap applies to the viewport
(width × height) only. Zero is accepted at set time for every knob; a zero
viewport dimension is refused loudly at render, while zero
`cursorOffset`/`lineOffset` are legal steady states and zero `wrap` means no
per-item cap. `withCursorOffset` is additionally **clamped at
render** to `height - 1`, so it can never ask for more context rows than the
viewport has. Scrolling uses a scroll-end clamp: near the bottom of the list
the window slides back so the frame stays full and the cursor row is always
inside it — short lists simply show everything.

## Filtering

Attach a filter function to narrow the visible items. The model tracks filter state via the `FilterState` enum; `filterState()` is `null` on a fresh model and only becomes an explicit state after the first `withFilterFn()` / `withoutFilter()` call (semantically a never-filtered list is unfiltered, it just carries no recorded state yet):

```php
use SugarCraft\TreeList\{Model, FilterState};

// Start with a list — the batch door is one clone for the whole set, and
// plain strings are first-class item values.
$model = Model::tree()
    ->withWidth(80)
    ->withHeight(24)
    ->withItemsFromArray(['apple', 'banana', 'cherry', 'apricot', 'blueberry']);

// Filter to items starting with "a"
$filtered = $model->withFilterFn(
    fn(\Stringable $item) => stripos((string) $item, 'a') === 0
);
// filterState is now FilterState::filtering

echo $filtered->length(); // 2 (apple, apricot)
echo $filtered->view();

// Remove filter and restore original items
$restored = $filtered->withoutFilter();
// filterState is now FilterState::unfiltered
echo $restored->length(); // 5
```

Filter state transitions (the `unfiltered` from-state is the state once `withoutFilter()` has been called at least once — see the null note above):

| From | To | Trigger |
|------|----|---------|
| `unfiltered` | `filtering` | `withFilterFn()` called (filter applied, items reduced) |
| `filtering` | `unfiltered` | `withoutFilter()` called (original items restored) |

A filter is a **live view**, not a one-time snapshot: while filtering,
`withItem()`/`withItems()`/`withItemsFromArray()`/`removeItem()`/`clear()`/`sort()`
all act on the authoritative list and the projection is re-derived immediately
— added items appear when (and only when) the predicate accepts them, removals
stick, and lifting the filter with `withoutFilter()` reveals exactly the
mutations that happened underneath. `removeItem()` re-anchors the cursor by
item **identity**: it stays on the same logical item it was on (clamping only
when that item was itself removed). Narrowing or lifting the filter does the
same — `withFilterFn()` and `withoutFilter()` keep the selection on the item
it was resting on whenever that item survives (stays visible, or comes back);
the cursor only falls back to position-clamping when the selected item itself
is filtered out. Appending into a live filter with one `withItem()` call per
item re-derives the whole projection every time (quadratic for a streaming
result set) — batch with `withItemsFromArray()`/`withItems()`, which re-apply
once per call.

Fuzzy search as the user types is one door — no hand-wired matcher, no
capture closure to re-wrap per keystroke:

```php
$searching = $model->withFuzzyFilter('ap');       // apple, apricot (coverage rule)
$narrower  = $searching->withFuzzyFilter('apic'); // apricot only
$widened   = $narrower->withFuzzyFilter('ap');    // apple + apricot again —
                                                  // re-projected from the pristine list
$restored  = $widened->withoutFuzzyFilter();      // all five items
```

`withFuzzyFilter()` keeps an item only when every query character appears in
it **in order** (full-query coverage — a stray shared letter is not a match),
and an empty query clears the filter. Like `withFilterFn()` it is a live view:
each call re-derives the projection from the whole original list, which is
what makes typing and backspacing behave correctly. Matching is not ranking —
survivors keep list order; use `FuzzyMatch::match()` directly when
score-descending order is the product requirement.

## Fuzzy Matching

`FuzzyMatch` ranks candidates by Smith-Waterman local-alignment score. It is a
thin delegating shim: the DP core lives in sugar-fuzzy's `SmithWatermanMatcher`
(SSOT), and this class keeps sugar-treelist's own public contract —
`\Stringable` items and input-order tiebreak on equal scores (intentionally
*not* `SmithWatermanMatcher::matchAll()`, whose haystack-ascending tiebreak
would reorder equal-score items). The tiebreak is carried structurally: each
hit keeps its input position through the sort (PHP's `usort` is unstable), and
duplicate strings collapse to one scoring pass, so equal scores always mean
"input order", with or without repeats:

```php
use SugarCraft\TreeList\FuzzyMatch;
use SugarCraft\TreeList\StringItem;

$matcher = new FuzzyMatch();

// Score a single candidate
$score = $matcher->score('april', 'apricot'); // 27 (consecutive match bonus applied)

// Filter and rank a list of items
$items = [
    new StringItem('April'),
    new StringItem('September'),
    new StringItem('June'),
    new StringItem('July'),
    new StringItem('November'),
];

$results = $matcher->match('sep', $items);
// Returns [ [StringItem('September'), 19], ... ] sorted by score descending

// Threshold and limit (pass-throughs to the SSOT's batch door):
$real = $matcher->match('sep', $items, minScore: 8, limit: 5);
// Only September survives — April/June/November aligned at 3 on a stray letter.
```

**Score guidance.** Local alignment rewards *any* in-order letters, so an
incidental hit is not zero: on the canonical profile a single stray character
scores `matchScore` (3), a two-letter adjacent hit scores 11, and a real
substring lands at 19-27. A `qqzzrr` typo still paints every item containing
one `q` or `z` at score 3 under the default threshold. For typo-tolerant
search on the canonical weights, `minScore: 8` is the measured junk line:
it clears every single-letter alignment while keeping genuine multi-letter
hits. Raise it further when short queries look noisy, or use strict mode
below, which refuses the alignment shape outright rather than reweighting it.

**Tie order.** Equal scores keep input order — nothing ranks above a real hit
because it came first, but a page of same-scored items renders in list order,
so put items in the order you want ties to surface in (favourites first).

**Strict mode (full-query coverage).** `withStrictMode()` keeps a candidate
only when *every* query character appears in it in order: `bxz` no longer
"matches" `abcdef` on its lone `b`, while `gst` still matches `git status`.

**Candidate cap (DoS guard).** sugar-fuzzy caps input lengths
(`SmithWatermanMatcher::DEFAULT_MAX_QUERY_LENGTH` /
`DEFAULT_MAX_CANDIDATE_LENGTH`). Over-cap input is aligned on its **prefix**
on the same score scale as every other item, so a very long candidate ranks
consistently with short ones. The trade-off: **text that exists only past the
candidate cap is not searched.** The candidate side of the cap is tunable
(`withCandidateCap(int $characters)`, also settable through the constructor);
both knobs survive `withProfile()`.

**Knobs and pruning.** The constructor takes `($profile, $maxCandidateLength,
$strictMode)`; `withCandidateCap()`, `withStrictMode()` and `withProfile()`
return tuned copies; `candidateCap()` / `isStrictMode()` report them; and
`matchGenerator(...)` yields the same ranked pairs as `match(...)`. Passing
`minScore` through the SSOT batch door means its ceiling prune fires: a
candidate whose best attainable score provably cannot reach the threshold is
skipped without paying for the quadratic alignment (measured: 20 001 mostly
duplicate short items, `match('ab')` default = 311 ms with 20 001 hits vs
`minScore: 17` = 68 ms with none — the prune plus the unmaterialised hit list).

**Narrow-first recipe (measured).** Full scoring is ~63 µs/item: an 8 000-item
palette costs ~430 ms per keystroke through `match()` alone, and a `withFuzzyFilter`
over the same corpus re-projects in ~85 ms (coverage refusals exit before the
DP). For very large lists, pre-screen with a cheap `str_contains` pass and
fuzzy-rank only the survivors:

```php
$needle = 'stat';
$survivors = [];
foreach ($items as $item) {
    if (stripos((string) $item, $needle) !== false) {
        $survivors[] = $item; // the contiguous core at least appears
    }
}
$ranked = $matcher->match($needle, $survivors); // 8 000 items: 430 ms -> 80 ms measured
```

The trade-off is recall: scattered in-order alignments (`gst` → `git status`)
never survive a `str_contains($needle)` pre-screen — pre-screen on a core that
real hits contain contiguously, or drop the pre-screen and pay the scan.

**Filtering a Model as the user types** is `withFuzzyFilter()` (the coverage
rule, re-projected from the pristine list per call); see [Filtering](#filtering).

## Deprecation & delegation

This lib is a façade over [sugar-fuzzy](https://github.com/sugarcraft/sugar-fuzzy)
for fuzzy scoring (reciprocally documented in sugar-fuzzy's README):

- `SugarCraft\TreeList\ScoringProfile` is a **`@deprecated` `class_alias`** to
  sugar-fuzzy's `SugarCraft\Fuzzy\ScoringProfile` — the single source of truth.
  It is aliased, not subclassed, so `new FuzzyMatch(ScoringProfile::strict())`
  hands the SSOT matcher an instance it accepts natively.
- `SugarCraft\TreeList\FuzzyMatch` is a **thin delegating shim** over
  `SmithWatermanMatcher` (keeping sugar-treelist's own `\Stringable`-item
  contract and input-order tiebreak, plus the prefix-alignment cap behaviour
  documented above). Its `?ScoringProfile` constructor parameter and
  `withProfile()` exist for migration only — because the alias is the same
  class, sugar-fuzzy's own profile passes straight through; new code depends
  on `SugarCraft\Fuzzy\` directly.

**New code should depend on `SugarCraft\Fuzzy\*` directly** and use
`sugar-fuzzy`'s `SmithWatermanMatcher` / `ScoringProfile`; the re-exports here
exist only for back-compat.

## Delta rendering

`Model::view()` keeps a memo of the rows it emitted last time
(`?list<string> $previousRows` — styled row text, one entry per emitted frame
line). The first render emits a full frame; each later render compares rows one
by one and repaints **only the changed rows**, each addressed absolutely: one
cursor-position before the row's own bytes and one erase-line after them. Frame
lines are joined and terminated with bare `\n` (no carriage return), relying on
the host terminal's `ONLCR` mapping to wrap each line — the same convention the
full frame uses. Unchanged
rows cost nothing; a changed row costs its own content plus a fixed addressing
overhead. A resize, a filter transition, `resetPreviousFrame()`, or a failed
frame clears the memo and forces the next `view()` to emit a full repaint.

Because rows are written whole, styled rows round-trip exactly — wide glyphs
are never split and SGR bytes never land in the grid as literal glyph cells.
(The earlier cell-granular design projected rows into sugar-cellbuffer and
diffed them; its style transport could not carry palette SGR spellings, so it
corrupted styled frames after the first repaint. That dependency was retired
with the row-granular rewrite.)

**SSH bandwidth win:** over a slow link only changed rows ride the wire, which
greatly reduces per-frame data and the full-screen flicker of rewrite-based
rendering.

**Lineage caveat:** the memo is per-instance render state **carried through
clones**. If you fork a model and later switch which fork's frames you paint
onto the same terminal region, call `resetPreviousFrame()` on the fork you are
about to render — otherwise it diffs against its sibling's last frame. Same
rule after any out-of-band write to the list's screen area.

**Assistive hosts:** a delta frame is a fragment — changed rows plus absolute
cursor jumps — so screen readers, braille displays and text-extraction tools
see no stable reading order in it; only a host that interprets CUP/EL renders
it whole. To serve an assistive surface (or to capture frames for later
reading), render with `lines()` — the row data with no delta bookkeeping — or
call `resetPreviousFrame()` before every paint so each frame is a full rewrite.
There is deliberately no configuration-level "always full frame" mode; those
two routes are the supported ones.

## Cursor & navigation API

Beyond the fluent setters shown above, the navigation surface is:

- `withCursor(int $index)` — clamped to `0..count-1` (an out-of-range value lands on the nearest end; the setter always returns a fresh clone).
- `withCursorUp(int $n = 1)`/`withCursorDown(int $n = 1)` and the page helpers `withCursorPageUp(int $pages = 1)`/`withCursorPageDown(int $pages = 1)` — movement **saturates** at the list ends (`withCursorDown(PHP_INT_MAX)` lands on the last item, never wraps or overflows). A page spans `height` **items**, not displayed lines — a list of multi-line items pages visually faster. `withCursorToStart()`/`withCursorToEnd()` jump to the ends.
- `cursorIndex()` — the cursor's **item** index (not a row position: with multi-line items, rows and items differ). `cursorItem()` — the cursor item's `\Stringable`; throws `RuntimeException` on an empty list. `tryCursorItem()` returns `null` instead, matching the `tryItemAt()`/`itemAt()` pair.
- `length()`, `isEmpty()`, `itemAt(int)`, `tryItemAt(int)`, `find(string|\Stringable)`, `itemIds()` — read-only item access (indices address the **visible** projection, so they follow the active filter).
- `lines()` — the laid-out frame rows without any delta bookkeeping; throws `RuntimeException` with a localized message (see below) on an empty list, a zero viewport, or gutters whose reservation consumes every viewport column (`list.no_content_width` — narrow panes refuse loudly instead of painting silent blanks). `linesStream()` is a generator-shaped wrapper over the same eager computation — it interleaves nothing.
- `view()` — full frame or row delta as described above. Together with `resetPreviousFrame()` it is one of only two members that mutate in place: `view()` updates the render memo, `resetPreviousFrame()` (void) drops it, which is why the immutability contract is scoped to item/configuration data. Unlike `lines()`, `view()` **never throws**: a `Throwable` raised mid-render drops the memo and the frame becomes the swept exception message (`Model::sweepForFrame` — the marked-frames sanitize plus the CR→LF fold — is the choke, so a failure surfaces as readable text, never as a live escape channel; line breaks the message itself carries stay breaks); pass a `LoggerInterface` as `view()`'s optional parameter to receive the same failure at `warning` level. `lines()` keeps the opposite polarity and throws.

## Localisation

The three refusal strings raised by `lines()` — `list.no_items` (which
`cursorItem()` reuses for its own empty-list refusal),
`list.zero_viewport`, and `list.no_content_width` (the prefix/suffix
reservations consumed every viewport column, sugar-treelist-008) — are live
`Lang::t()` lookups across the 16 shipped
locales under `lang/`. Locale selection is the host application's concern; the
renderer only formats through `SugarCraft\TreeList\Lang`.

## License

MIT

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project.

Design antecedent: [treilik/bubblelister](https://github.com/treilik/bubblelister).
