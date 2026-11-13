# SugarMouse

> Formerly published as `sugarcraft/candy-mouse` (renamed 2026-10); the former
> `candy-zone` package was absorbed into this one (2026-10). <!-- @rename-keep -->

[![codecov](https://codecov.io/gh/sugarcraft/sugar-mouse/branch/master/graph/badge.svg?flag=sugar-mouse)](https://codecov.io/gh/sugarcraft/sugar-mouse)

Mouse zones for terminal apps — wrap rendered chunks with sentinel-tagged
zone markers, scan the frame to discover each zone's bounding box, then ask which
zone a mouse event landed in. One package, two layers:

- **Primitive layer (`SugarCraft\Mouse`)** — self-contained Mark/Scan/Get
  hit-testing, `Scanner`, `ZoneClickTracker` press/release dedup, and the
  `Selection` / `SelectionRange` text-selection machine. The three verbs
  name three collaborators: `Mark` emits the sentinel tags, `Scan` decodes
  them, and `get()` answers "where is this id"; `hit()` answers "which zone
  is under this cell". Scanning is normally driven through `Scanner`.
  No Manager wiring needed; each consumer owns its own `Scanner` instance.
- **Zones `Manager` layer (`SugarCraft\Mouse\Zones`)** — the app-facing
  bubblezone surface: a `Zones\Manager` that owns a zone registry, the
  package-level `Zones` facade, and the hover / drag / multi-click trackers.
  The `Manager` layer delegates marker encoding and geometry to the
  primitive layer **in this same package** — the wire format lives in
  exactly one place.

```
composer require sugarcraft/sugar-mouse
```

Requires PHP `^8.3`; the only package dependency is `sugarcraft/sugar-core`
(escape-sequence constants, `Sanitize`, `Width`, and the core `MouseMsg` /
`MouseWheelMsg` wire types the Zones layer consumes).

## Quickstart

```php
<?php

require 'vendor/autoload.php';

use SugarCraft\Mouse\Mark;
use SugarCraft\Mouse\MouseEvent;
use SugarCraft\Mouse\Scanner;
use SugarCraft\Mouse\ZoneClickTracker;

// 1. Wrap interactive content with sentinel-tagged zone markers.
$rendered = Mark::zone('btn-ok', '  OK  ')
              . Mark::zone('btn-cancel', 'Cancel');

// 2. Scan after rendering to populate the zone registry.
$scanner = Scanner::new()->scan($rendered);

// 3. Reverse-lookup on mouse events ('  OK  ' spans cols 1-6 on row 1).
$zone = $scanner->hit(4, 1); // Zone 'btn-ok'

// 4. A click fires only when the release's fresh re-hit agrees with the
//    press zone on id AND box — resolve each event against the frame it
//    arrived in; never reuse the press-side zone for the release.
$tracker = ZoneClickTracker::new();
$tracker->track(MouseEvent::press(4, 1), $scanner->hit(4, 1));
$click = $tracker->track(MouseEvent::release(4, 1), $scanner->hit(4, 1));
if ($click !== null) {
    echo "Clicked zone: " . $click->zone->id . "\n"; // btn-ok
}
```

## Key classes

Every class that takes constructor arguments exposes the canonical
`::new()` factory (same signature; the constructor stays public for BC —
the house convention from AGENTS.md, landed in SM-S31).

| Class | Role |
|---|---|
| `Mark` | Emits the private-use sentinel tags around content; helpers sweep untrusted labels and render hostile ids safely (member details in the census below) |
| `Scan` | Stateless, reentrant decoder behind `Scanner` — the static `Scan::parse($rendered, $width)` returns `id => Zone`; `Scan::strip($rendered)` is the public static byte-wise marker remover |
| `Scanner` | Parse sentinels; `get(id)` and `hit(col, row)` lookups |
| `Zone` | Readonly bounding box (start/end col/row) with `width()` / `height()` and `inBounds(MouseEvent)` |
| `ZoneClickTracker` | Press+Release dedup per button |
| `MouseEvent` | Immutable event (x, y, button, action enum) |
| `MouseAction` | `Press` / `Release` / `Drag` / `Scroll` enum; also carries the X10 button consts `BUTTON_LEFT/MIDDLE/RIGHT` and the wheel pair `BUTTON_WHEEL_UP/BUTTON_WHEEL_DOWN` (legacy of the primitive's numeric-button vocabulary) |
| `Selection` | Press → drag → release text-selection state machine over a selectable region |
| `SelectionRange` | Normalized, inclusive stream range a selection covers; `extract()` copies its text out of a frame |
| `Sentinel` | The two framing codepoints (`U+E000` open / `U+E001` close), anchored to sugar-core |
| `ClickResult` | The agreed press+release pair `ZoneClickTracker::track()` returns on a confirmed click |
| `Lang` | Refusal-message lookup for this package's catalogue (`lang/`, five bare keys under Lang namespace `mouse`; the `sugar-mouse:` label rides inside each message) |
| `Zones\MsgZoneInBounds` | Zone-bound Msg emitted by `Manager::anyInBoundsAndUpdate()` into a Model's `update()` — the only Zones-layer message that lives outside the `Msg\` namespace |
### Primitive API (member census)

| Class | Member | When to use it |
|---|---|---|
| all classes | `::new(...)` | House factory with the constructor's exact signature (`Zone`, `MouseEvent`, `ClickResult`, `ZoneClickTracker`, `Zones\*` trackers, `Zones\Zone`, `MsgZoneInBounds`, every `Msg\*`) — the constructor remains public for BC |
| `Mark` | `new()` / `disabled()` | Marking-enabled / marking-off instance |
| `Mark` | `zone(id, content)` | Static one-shot wrap through an always-enabled marker |
| `Mark` | `wrap(id, content)` | Encode one zone on an instance (honours its enabled flag) |
| `Mark` | `withEnabled(bool)` | Fork with the emit flag flipped (bytes still validated) |
| `Mark` | `isValidId(id)` | The id grammar predicate shared by encoder and decoder |
| `Mark` | `untrusted(text)` | Sweep foreign sentinel framing bytes off labels you did not render |
| `Mark` | `escapeForMessage(id)` | Render a hostile id safely inside an error message |
| `Scan` | `parse(rendered, ?width)` | Static decode: `id => Zone` bounding boxes (width clamps, zero-paint rects drop) |
| `Scan` | `strip(rendered)` | Byte-wise tag removal — same rule `parse()` decodes, so bounds and bytes stay in lock-step |
| `Scanner` | `new()` | House factory for the registry-backed reverse-lookup |
| `Scanner` | `scan(frame, ?width)` | Record the frame's zones (replaces previously recorded ids) |
| `Scanner` | `get(id)` | One zone by id |
| `Scanner` | `hit(col, row)` | Smallest zone under a cell (col-first!) |
| `Scanner` | `all()` | Every recorded zone (ids this frame and earlier registered) |
| `Scanner` | `idsStartingWith(prefix)` | The reading side of the prefix vocabulary (`newPrefix()` stamps, this selects) |
| `Scanner` | `prefixed(prefix)` | @deprecated alias of `idsStartingWith()`; kept for live external callers |
| `Scanner` | `clear()` | Empty the registry |
| `Zone` | `inBounds(event)` | Cell containment for a `MouseEvent` |
| `Zone` | `pos(event)` | `[col, row]` offset from the zone's top-left, 0-based, negative outside |
| `Zone` | `width()` / `height()` | Cell extents |
| `Zone` | `isZero()` | Degenerate zone (no cell painted) — scans drop these |
| `MouseEvent` | `press/release/drag/scroll(col, row, button)` | Statics for the four actions |
| `ZoneClickTracker` | `track(event, ?zone)` | Press arms, release confirms — `?ClickResult` |
| `ZoneClickTracker` | `observeRelease(event, ?zone)` | Non-consuming agreement predicate (see Two-call click confirmation) |
| `ZoneClickTracker` | `setPressZone(zone)` | @deprecated order-coupled vestige of the two-call setter flow; prefer `track()`'s zone argument |
| `Selection` | `new(rowFrom, ..., colTo)` | Private ctor, static factory (row-first!) |
| `Selection` | `begin(row, col)` / `dragTo(row, col)` | Press anchors; move extends (row-first!) |
| `Selection` | `beginEvent(event, select)` / `dragToEvent(event, select)` | MouseMsg-shaped doors onto the same two steps (transpose col-first internally) |
| `Selection` | `clear()` | End the gesture (anchor and head released) |
| `Selection` | `range()` | The covered `SelectionRange` while active, else null |
| `Selection` | `active()` | A gesture holds an anchor (press until `clear()`) |
| `Selection` | `dragging()` | Head left the anchor — GEOMETRIC truth; a there-and-back sweep un-drags. Apps wanting a latched flag past a click tolerance latch their own (candy-code's adapter does) |
| `SelectionRange` | `new(rowA, colA, rowB, colB, ?edgeFrom, ?edgeTo)` | Normalize the pair; edges may widen, never cross (see Edge cells); 0/negative cells throw `selection.off_terminal` naming the parameter |
| `SelectionRange` | `spanOnRow(row)` | `[from, to]` cells cut on this row's own start/end, or null outside the range |
| `SelectionRange` | `cellContains(row, col)` | Is this cell selected |
| `SelectionRange` | `cells()` | Generator over every selected `[row, col]` in reading order |
| `SelectionRange` | `extract(lines)` | The copied text out of a painted frame (row R is `lines[R - 1]`) |

### Choosing your tracker idiom

Two state idioms share this package, split by layer:

| Layer | Classes | Contract |
|---|---|---|
| Primitive | `Selection`, `ZoneClickTracker`, `Scanner` | Mutates in place; `begin()` / `dragTo()` / `track()` update the receiver and return a plain value (`bool` / `?ClickResult` / chainable `$this`) |
| Zones trackers | `DragTracker`, `ZoneHoverTracker`, `MultiClickTracker` | Fork-returning; `update()` yields a `[tracker, ?Msg]` pair and the caller rebinds the returned tracker after every event |

The split is deliberate — a live gesture is sequential state, and the
primitive machines are ports of in-place codebases — but the ecosystem
cost is real: candy-code hand-wrote an immutable adapter over `Selection`
(its `Tui\TextSelection` works on a clone per step) and declined the
Zones `DragTracker` in favour of its own `PaneDragController`. Pick one
idiom per UI and bridge at the boundary; converging the primitives onto
fork-returning is a family-wide BC break and an open owner decision.

`Selection` / `SelectionRange` were ported from candy-code's
`Tui\TextSelection`, and candy-code now runs on them: `Tui\TextSelection` is
an immutable adapter over a cloned `Selection` machine, reads its geometry and
copied text through `SelectionRange`, and speaks the same 1-based cells end to
end. What it keeps locally is what this lib leaves to the app — a
drift-latched dragging flag, the release/copy record, and the highlight
repaint.

The `Zones` layer (`Manager`, `Zones`, `Zone` adapter, `ZoneHoverTracker`,
`DragTracker`, `MultiClickTracker`) is documented in its own section below.

## Sentinel design and trust boundary

Zone markers are two three-byte Unicode **private-use** codepoints:
U+E000 (open) and U+E001 (close), anchored to sugar-core's
`Sanitize::ZONE_SENTINEL_OPEN` / `::ZONE_SENTINEL_CLOSE` so a sanitizer sweep
and the markup it defends against can never drift apart.

**What they defend against.** Ordinary ASCII UI text cannot carry these
bytes, and well-behaved renderers never emit them inside escape payloads, so
normal rendering cannot fabricate a zone boundary. That is a policy of honest
output, not a property of the wire grammar. Escape payloads are arbitrary
bytes: the decoder walks OSC/CSI runs as opaque pass-through, so a forged tag
buried inside one is inert. But an unterminated CSI/OSC run swallows the rest
of its frame — including *legitimate* markers — by design, so hostile input
can still disturb recorded geometry. Sweeping untrusted bytes before
composition is therefore the consumer-side duty spelled out under **The
consumer's duty** below (`Mark::untrusted()` /
`Sanitize::untrustedForMarkedFrames()`). But the PUA is not empty space: pasted foreign frames,
image-overlay payloads and Nerd Font / powerline glyph runs all live in the
private-use area. Content that already contains `U+E000 <id> U+E001` is
structurally indistinguishable from a marker this lib emitted — the sentinels
*delimit* our markup, they authenticate nothing.

**The defenses in this lib:**

- **Id charset.** `Mark::isValidId()` accepts only
  `\A[A-Za-z0-9._:-]+\z`, matched byte-wise (no `/u`) and anchored with
  `\A…\z`, not `^…$` (a trailing newline slips past `$`). An id can never
  smuggle a sentinel, control, escape or non-ASCII byte into the stream.
- **The lone-sentinel rule.** A `U+E000` that does not begin a valid tag
  costs its own three bytes when scanning (an unterminated-open run at
  end-of-string loses the same three and keeps any id-shaped text after
  it); everything else is measured as visible text. The rule protects zone
  **identity** — foreign bytes cannot swallow a span and re-rectangle the
  frame — but it is NOT width-neutral: other private-use codepoints
  (`U+E002+` image-overlay markers, Nerd Font glyphs) count as one visible
  cell each, so a host that masks them for its own width math must measure
  against what actually reaches the terminal, or every such glyph drifts the
  recorded geometry one cell. That masking is a consumer-side duty like the
  sweep below (candy-code's `maskImageMarkers` is the precedent).
- **Bounded work.** `Mark::MAX_ID_BYTES` (256) is checked before any
  substring is materialized, so an attacker-sized span between a stray
  `U+E000` and a distant `U+E001` is rejected in O(1);
  `Mark::MAX_CONTENT_BYTES` (1 MiB) caps what `Mark` will emit around a
  chunk. Both caps bound the EMIT side. Parsing has no cap of its own: feed
  `Scan::parse()` only frames you expect to be terminal-sized. A hostile
  multi-megabyte blob is untrusted input like anything else you pass in. The
  parse-side cost profile — and what each malformed shape degrades to — is
  tabulated in the `Scan` class docblock rather than repeated here.

**The consumer's duty.** Anything pasted or otherwise untrusted must be
swept *before* it is composed into a marked frame. This package re-exports
the surgical sweep as `Mark::untrusted()`, which **is** sugar-core's
`Sanitize::stripZoneSentinels()` — a `str_replace` of the two sentinel
codepoints and nothing else. `Sanitize::untrustedForMarkedFrames()` is a
larger, composed operation instead: it runs the ANSI/C0 sweep of
`Sanitize::untrusted()` first, then the sentinel sweep
(`stripZoneSentinels(untrusted($s))`). That composed policy is what
candy-code's renderer runs on its input path. Mind the
near-miss names: `Mark::untrusted()` strips *sentinels only*, while
`Sanitize::untrusted()` strips *ANSI* — different sweeps, one word. Any other host of marked frames owns the same responsibility —
the fleet-wide write-up is `docs/TRUST-BOUNDARY.md` in the sugarcraft
monorepo. The reservation is fleet-wide: by construction, image-overlay
markers were moved to U+E002+, so they can never collide **as tags** with
this arena. They remain ordinary visible cells to the scanner; see the
lone-sentinel rule above.

Scanning *reads* the sentinels from the rendered string to compute zone
bounding boxes; it never modifies the string. Stripping the sentinels from
the bytes actually written to the terminal is the consumer's job (e.g.
candy-code's renderer scans, then strips; `Manager::scan()` returns the
stripped, display-ready frame).

## Coordinate order

Nearly everything is **(col, row)** — `Scanner::hit($col, $row)`,
`Manager::zoneAt($col, $row)`, `Zone::pos()` returning `[col, row]`, and
`MouseMsg`'s own `$x`/`$y`. Two islands are row-first by design, because a
text selection is addressed like a file:

| API | Order |
|---|---|
| `Selection::new($rowFrom, $rowTo, $colFrom, $colTo)`, `begin($row, $col)`, `dragTo($row, $col)` | row, col |
| `SelectionRange::new($rowA, $colA, $rowB, $colB)`, `extract()` indexing `$lines[$row - 1]` | row, col |

`SelectionRange`'s promoted fields are named (`startRow`, `startCol`, …),
so construction from a `range()` result is unambiguous. And every cell is
1-based: a 0 (or negative) row/col is refused loudly at construction with
`selection.off_terminal`, whose message names the offending parameter
("got 0 for parameter colA — clamp or rebase the pointer before building
a range").

## Accessibility

Zone tags are private-use-area codepoints: screen readers announce them as
Dotted-Circle garbage or "unknown character" noise, and the mouse
trackers (hover, drag, multi-click) are pointer-only affordances. Ship a
keyboard path for every zone-driven action, print ONLY the stripped frame —
at the `Zones\Manager` layer `scan()` returns it display-ready, and at the
primitive layer the standalone tag remover is `Scan::strip()` (`Scanner`
never modifies the string; stripping what you print is the consumer's job)
— never `echo` marked bytes. Follow the mode-lifecycle rules under
[Mouse-tracking modes](#mouse-tracking-modes) so assistive terminals are
not flooded by leaked `?1003` motion reporting.

Concrete parity rules:

- Tab / Shift+Tab (or the arrow keys) move focus across the actionable
  zones in reading order, and Enter fires the focused one — every zone
  action needs this route, not just the click.
- A hover affordance (tooltip, highlight, data-fetch-on-hover) needs a
  focus-revealed twin: show on keyboard focus what `ZoneEnterMsg` shows on
  pointer entry, hide on blur.
- Drag needs a no-motion fallback — a select-then-choose-destination
  keyboard flow — since terminals without `1002`/`1003` report no
  cross-boundary moves at all.
- Never gate a command on tracker state alone (hover zone, `MultiClickTracker`
  streak): the keyboard path must reach the same command without pointer
  history.
- A multi-click affordance (`MultiClickTracker` streaks) is a timed pointer
  gesture — build a keyboard or single-action twin for the same command,
  and consider surfacing `MultiClickTracker`'s `clickIntervalMs` as an
  app-exposed setting: the 500 ms default is a pointer-timing tolerance,
  and users with tremor or reduced dexterity may need it wider (cf. WCAG
  guidance on user-controlled timing).

## Scan rules

The instance method `parse()` (call it on a `new Scan()`, or go through
`Scanner::new()->scan()`) decodes exactly the grammar `Mark` encodes: a tag
is `U+E000 [/] <id> U+E001` with an id `Mark::isValidId()` accepts, and every
byte of a tag is zero-width. Anything else is measured as text, so a stray
sentinel cannot re-rectangle the zones after it (see the identity-vs-width
caveat above). The static `Scan::strip()` removes exactly the well-formed
tags — the public half of the parse/strip pair:

| Input to `parse()` | Result |
|---|---|
| Duplicate id in one render | throws `InvalidArgumentException` |
| Orphan close / unclosed open | tag skipped, no zone (a frame clipped to the viewport legitimately cuts one end off a zone) |
| Zone whose content paints no cell (`''`, `"\n"`, SGR only) | no zone — there is no cell to hit, so it cannot steal a neighbour's clicks |
| Zone whose clamped rect paints no cell (opens past `?width`, or width < 1) | dropped too — never collapsed to an edge sliver claiming the viewport's last column |
| Empty-id tag (`U+E000 U+E001`) | zero-width, inert |
| Lone sentinel, or an id with spaces/escapes/non-ASCII/over `Mark::MAX_ID_BYTES` | only the 3 sentinel bytes are skipped; the following bytes count as visible text |

Line endings: `\n` and `\r\n` both start a new row. A lone `\r` returns to
column 1 on the same row, as a terminal does; a zone that paints again after
it widens its bounding box to start at column 1, and a zone that opened
mid-row but painted nothing before the `\r` (a progress-bar redraw) covers only
the cells it painted after it. The same holds for a leading `\n`: a zone
that painted nothing before the newline starts on the first row it paints,
at column 1, whatever column it opened on — `'123456789' . zone("\nabc")`
and a bare `zone("\nabc")` opened at column 1 are both cols 1-3 of row 2.
This departs from bubblezone's behavior, which keeps the open position: opened
past the content's last column it reports an inverted rectangle no click
ever matches; opened short of it (`'12' . zone("\nabcdef")`) a box that
claims unpainted cols 3-6 of row 1 while missing the painted cols 1-2 of
row 2; and opened at column 1 a box that also spans the unpainted row 1.

**Grapheme clustering is host-dependent.** Cell columns advance one
grapheme cluster at a time, so how a cluster *ends* decides where zones
land. With `ext-intl` the split follows the bundled ICU's UAX #29 rules;
without it (or when `grapheme_extract()` returns `''` mid-sequence) the
lead-byte fallback consumes one scalar at a time. ZWJ emoji families,
regional-indicator flags and base+combining runs therefore cluster — and
advance — differently between hosts, shifting the bounding boxes around
them. Layouts that mark wide glyphs and ship on machines without a pinned
ICU should install `ext-intl`; the column math is otherwise correct per
cluster, just not identical per cluster *set*.

## Multi-row zones

A zone spanning multiple rows (e.g. `"line1\nline2"`) is stored as the
**smallest axis-aligned bounding box** that contains all cells it painted.
Consequences worth knowing before you mark reflowed or indented text:

- The box is a union rectangle: an interior cell that was never part of the
  content (the gap beside a short line) still reports as inside the zone.
  The hit-test for multi-line content is approximate *by design*.
- Only rows the zone actually painted extend it. Interior blank rows between
  painted rows stay covered (both ends are the zone's own cells); trailing
  blank lines inside the tags — content ending `"\n\n"` before the close
  tag — add nothing, so the box ends on the last row that holds a painted
  cell of the zone.

## Mouse-tracking modes

This lib is a text-processing component: it never writes to the TTY itself.
The terminal must be told to report mouse events before any tracker can see
one, and the trackers need different reporting:

| Mode | Reports | Needed by |
|---|---|---|
| `1000` | press / release / wheel | `ZoneClickTracker`, `MultiClickTracker` (clean press+release pairs, clicks) |
| `1002` | 1000 + drag (button held, crossing a cell) | `DragTracker` boundary crossings while pressed |
| `1003` | all motion, button held or not | `ZoneHoverTracker` (enter/exit with no press at all) |
| `1006` | SGR-style extended coordinates: reports arrive as `CSI < b ; x ; y M|m` — `M` press, `m` release, `b` the button code plus modifier bits | any of the above beyond row/col 223, where legacy X10 single-byte coordinates saturate |

The decimal extended-coordinate siblings of `1006` — mode `1015` (urxvt
style, `CSI b ; x ; y M`) and mode `1016` (xterm, the SGR report shape but
carrying pixel coordinates) — are emitted and parsed by neither this lib
nor sugar-core; decoding them is sugar-input's opt-in path. Swapping
`1006` for either of those modes is a host-layer decision that changes
what your input layer must speak, not just what the coordinates mean.

`Manager::motionTrackingAnsi(bool)` returns the all-motion byte string:
`\x1b[?1003h\x1b[?1006h` on enable and the LIFO `\x1b[?1006l\x1b[?1003l` on
disable — motion tracking plus the SGR coordinate companion, byte-identical
to sugar-core's `Ansi::mouseAllMotionOn()/Off()`. Core ships the same pairing
for the plain-click case (`mouseAllOn/Off` = 1000+1006) and the held-drag
case (`mouseCellMotionOn/Off` = 1002+1006). The other modes are the host
app's enable sequence; its input layer (e.g. sugar-input) turns the reports
into `MouseMsg` / `MouseEvent` values, which means the `b` field is decoded
before this lib ever sees a message: strip the modifier bits (4 shift, 8
alt, 16 ctrl; 32 marks a held-drag motion) before comparing button numbers,
and fold xterm's wheel pair (`MouseAction::BUTTON_WHEEL_UP` /
`BUTTON_WHEEL_DOWN`) into one wheel event.
Consequence: the hover tracker reacts to press and motion reports —
enter/exit fire on a plain `1000` press — but wheel reports are inert for
all three `Zones` trackers — hover, drag and `MultiClickTracker` alike (a scroll
is not a press; see `MouseWheelMsg` gates). Press-free hover needs motion
reporting (`1003`); the drag tracker's cross-boundary moves need at least
`1002`; click trackers work on plain `1000` reporting alone.

One caveat on that last sentence: "`1000` alone" describes the reports the
terminal sends — press, release and wheel — not what `?1000h` by itself
enables. Here is why that matters: the reading side on the default stack is
sugar-core's `InputReader`, which decodes the SGR form `CSI < b ; x ; y M|m`
and nothing else; there is no X10 decoder in this dependency set (legacy
mode-9/1000 reports arrive as `CSI M b x y`). So turning on `?1000h` without
its SGR companion produces **zero** mouse messages this lib can see. The two
modes therefore go together, exactly as core's `Ansi::mouseAllOn()` emits
them (`?1000h ?1006h`); `mouseCellMotionOn()` / `mouseAllMotionOn()` pair
`?1002h` / `?1003h` with the same `?1006h` companion. Readers that do speak
X10 live outside this dependency set: sugar-mouse requires
`sugarcraft/sugar-core` only, and sugar-input's X10 path is an opt-in host
arrangement, not something this lib relies on.

**Mode lifecycle.** This lib never touches the TTY, so it cannot undo what
it never wrote: whoever enables `1000`/`1002`/`1003`/`1006` owns the
disable on every exit path — including crashes (a `register_shutdown` hook
or the frame runner's `finally`). A leaked `?1003` streams every mouse move
into the session for its remaining lifetime, a continuous byte flood that
hits assistive tech hardest (Orca and brltty users cannot wave it away).
Interop floor: `1002`/`1003` are the xterm family — Konsole ships no 1003
and tmux re-encodes reports — so design for `1000`-only degradation when
motion reports never arrive.

## Zones `Manager` layer (`SugarCraft\Mouse\Zones`)

Wrap rendered chunks with named markers, let the `Manager` discover their
bounding boxes, then ask zones whether a
{@see \SugarCraft\Core\Msg\MouseMsg} fell inside them. The `Zones` layer is
the app-facing surface; the marker scheme and its parser live once in the
primitive `Mark` / `Scan` classes of this same package and are reused by
every SugarCraft zone consumer. `mark()` encodes through
`SugarCraft\Mouse\Mark::wrap()` on an instance carrying the manager's
enabled flag (not the `Mark::zone()` static convenience, which is
always-enabled — inlining that while `setEnabled(false)` emits markers the
manager's `scan()` suppresses), and `scan()`
takes its bounding boxes from the instance `parse()` method of a constructed
`SugarCraft\Mouse\Scan`, then strips
the markers so the returned frame is display-ready.

```php
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\MouseAction;   // the WIRE enum (Press / Release / Motion)
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Mouse\Zones\Manager;

$z = Manager::newUnprefixed();

// Build a frame
$btnOk     = $z->mark('btn:ok',     '  OK  ');
$btnCancel = $z->mark('btn:cancel', 'Cancel');
$frame     = $btnOk . '   ' . $btnCancel;

// Scan once before printing — Manager records marker positions and strips them.
$displayable = $z->scan($frame);
echo $displayable;

// Later, when a MouseMsg arrives (1-based col, row — the same grid `get()`
// measures; here col 5 lands inside the six-cell "  OK  " button):
$click = new MouseMsg(5, 1, MouseButton::Left, MouseAction::Press);
if ($z->get('btn:ok')?->inBounds($click)) {
    // ...
}
```

Mind the two `MouseAction` enums: `SugarCraft\Core\MouseAction` is the
wire-level event kind carried by `MouseMsg` (above), while
`SugarCraft\Mouse\MouseAction` is this library's primitive marker-action
enum (with the X10 button constants). Same class name, different namespaces
— if a file needs both, alias one.

Never mark text you did not author without sweeping it first: a label that
already contains `U+E000 <id> U+E001` is structurally a tag, so
`$z->mark('item', Mark::untrusted($userLabel))` strips the framing bytes
before wrapping — neither sentinel is in the id charset, so what survives
cannot re-form a tag.

Markers are **private-use-area sentinel tags** — zero-width *to the
scanner* and not ANSI, so they never affect the recorded layout; hosts may
still render unknown PUA codepoints, see
[Sentinel design and trust boundary](#sentinel-design-and-trust-boundary)
for the full threat model:

```
open:  U+E000 <id> U+E001
close: U+E000 /<id> U+E001
```

`Manager::scan()` computes each zone's bounding box in 1-based terminal
cells, accounting for ANSI styling and Unicode (CJK) width, and takes the
optional `?int $width` clamp the primitive exposes: ends clamp into
`[1, width]`, and a zone left painting no cell after clamping — one that
opens fully past the right edge, or any viewport narrower than one column
— is dropped rather than collapsed onto the edge column (the same
paints-no-cell/claims-nothing rule as the malformed-markup table above).

**Two engines, two rules — and a persistent registry.** The primitive
`Scanner::scan()` REPLACES its whole zone set from the frame it parses, and
its `hit()` resolves ties by earliest registration. `Manager::scan()`
MERGES into its registry: ids re-recorded in a new frame are replaced, and
ids not present are kept. The consequence: a zone whose widget stopped
rendering STAYS clickable until something replaces it under the same id,
`clear($id)` removes it, or `close()` wipes the registry. So re-scan every
frame you want authoritative, and clear ids you retire —
`idsAbsentFromFrame($seen)` turns a frame's painted-id list into exactly the
ids to drop. Manager hit-testing resolves
the SMALLEST covering area first (nested components: innermost wins), ties
between equal areas go to the first-recorded zone — the one point where it
agrees with the primitive's registration-order rule. Use `allInBounds($msg)`
for the full
stack (smallest first) and `zoneAt($col, $row)` to hit-test cells without a
mouse message (scroll wheel landed here, tooltip anchor, etc.).

**Overlay contract (no stacking primitive).** Neither layer has a z-order:
no priority stamp, no layer field, no modal state. Because the `Manager`
resolves by smallest covering area, a full-screen modal scanned into the
same registry loses every hit to any smaller zone painted underneath it —
clicks land on invisible controls. Do not overlay layers inside one
registry: retire the base ids (`clear($id)` / `clear()`) before a modal
opens, or give each layer its own `Manager` (`newUnprefixed()` / `newPrefix()`)
and route events to the top layer first. A stacking primitive (zone
priority / paint-order stamp, area demoted to tiebreak) is an open owner
decision; until it ships, the retire-or-split rule above is the contract.

**Id format:** the marked id — including any manager prefix, i.e.
`prefix() . $id` — must match `\A[A-Za-z0-9._:-]+\z` (ASCII letters, digits,
and `._:-`, byte-wise). The primitive validates this in `Mark::wrap()` and
`mark()` throws `\InvalidArgumentException` on a bad id, since an id carrying a
sentinel / control / whitespace byte would desync zone scanning. The real
consumer ids (`tab-N`, `cell:N`, `item:N`) and the auto-generated prefixes
(`1-`, `2-`, …) already comply.

### Manager API

Beyond `mark()` / `scan()` / `get()`:

- `setEnabled(bool)` / `isEnabled()` — flip marker emission off in
  non-interactive contexts (CI logs, file dumps). When off, `mark()`
  returns content verbatim and `scan()` is identity.
- `Manager::newPrefix(string $prefix = '')` — namespace every id with a
  prefix so two SugarMouse-aware components don't collide on `'item-0'`.
  Called without an argument, it auto-generates a monotonic prefix.
- `prefix()` — read-only accessor for the prefix string.
- `get($id)` / `all()` / `clear(?$id)` — single-zone lookup, every
  zone, and targeted-or-wipe-all clear.
- `close()` — drop every zone and flip the manager into pass-through
  mode. Idempotent. Recovery after closing is a deliberate sequence:
  1. Re-arm the shared default with `Zones::setDefaultManager(null)`;
     the next `defaultManager()` call lazily builds a fresh enabled manager.
  2. `scan()` a frame before hit-testing anything. `Zones::setEnabled(true)`
     alone only flips marking back on — the zone registry is already gone,
     so no hit resolves until a scan records zones again.
  (The silently-dead symptom is also spelled out in `close()`'s docblock.)

### Package-level facade

`SugarCraft\Mouse\Zones\Zones` follows the package-level surface of
bubblezone, Go names camelCased into PHP statics over a single shared
`Manager`: `defaultManager()` / `setDefaultManager()` / `mark()` /
`scan()` / `get()` / `clear()` / `close()` / `setEnabled()` / `isEnabled()`
(the Go surface's `Enabled` is `isEnabled()` here) / `newPrefix()` /
`anyInBounds()` / `anyInBoundsAndUpdate()`:

```php
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Mouse\Zones\Zones;

$header = 'Dashboard';            // the content to make clickable
$marked = Zones::mark('header', $header);
$cleaned = Zones::scan($marked);  // registers 'header', returns the echo frame
$mouse = new MouseMsg(4, 1, MouseButton::None, MouseAction::Motion);
if (Zones::get('header')?->inBounds($mouse)) { /* … */ }
```

`Zones::setDefaultManager(?Manager)` swaps in a custom manager —
useful in tests (`Zones::setDefaultManager(null)` flushes state) or
when you want every package-level call routed through a prefixed
manager.

### Hover tracking

`ZoneHoverTracker` wraps a `Manager` and tracks which zone the cursor
is in across `MouseMsg` events. It emits `ZoneEnterMsg` when the cursor
crosses into a zone and `ZoneExitMsg` when it leaves — ideal for
tooltips, highlights, or data-fetch-on-hover. Hover is a pointer-only
affordance: pair every hover reveal with a keyboard-focus equivalent, see
[Accessibility](#accessibility). `update()` treats wheel reports
(`MouseWheelMsg` — filtered by type, not by action) as inert and hit-tests
everything else: press, release AND motion all count. A plain mode-`1000`
press inside a zone fires `ZoneEnterMsg`, scrolling does not; and because
entry is triggered by any in-zone event arriving with no hovered zone
current, a release inside a zone that was never entered fires
`ZoneEnterMsg` too. Press-free hover tracking needs the terminal to report
motion (mode `1003`, see
[Mouse-tracking modes](#mouse-tracking-modes)):

```php
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Mouse\Zones\Manager;
use SugarCraft\Mouse\Zones\ZoneHoverTracker;
use SugarCraft\Mouse\Zones\Msg\ZoneEnterMsg;
use SugarCraft\Mouse\Zones\Msg\ZoneExitMsg;

$manager = new Manager();
$manager->scan($manager->mark('btn', 'OK'));  // registry knows 'btn' (row 1, cols 1-2)
$mouseMsg = new MouseMsg(1, 1, MouseButton::None, MouseAction::Motion);

$tracker = ZoneHoverTracker::new($manager);

[$tracker, $msg] = $tracker->update($mouseMsg);
if ($msg instanceof ZoneEnterMsg) {
    // cursor entered $msg->zone
} elseif ($msg instanceof ZoneExitMsg) {
    // cursor left $msg->zone
}
```

**Boundary crossing:** moving directly from zone A to zone B produces
an exit for A first; call `update()` again to receive the enter for B.
This two-step pattern lets the application animate the exit before
routing the enter. If a re-feed is awkward (the pointer already sits on B and no
further motion will arrive), read `pendingEnter()` — it reports the owed
zone without consuming it — or re-invoke `update()` with a synthetic
motion at the same coordinates; the emitted `ZoneEnterMsg` then matches.
**Vanished zones clear silently:** if the hovered zone is removed from
the registry before its exit can be built, no message is emitted (an
Exit must name a live `Zone`) but the tracker state clears, so the next
in-zone event correctly re-enters. `DragTracker` is deliberately the opposite: one boundary
crossing emits its `ZoneDragMoveMsg` in the same call — do not re-feed
the event there or you will spin.

**State accessors:**
- `currentZoneId()` — id of the hovered zone, or null
- `currentZone()` — `Zone` object, or null
- `pendingEnter()` — the `Zone` whose Enter is owed after a lone Exit(A),
  reported without consuming it (null when nothing is pending)
- `withManager(Manager)` — rebind to a different manager (e.g. a
  prefixed manager in a sub-component)
- `withCurrentZoneId(?string)` — restore from a serialized state; null
  clears the hovered state (and drops any owed enter)

### Drag tracking

`DragTracker` wraps a `Manager` and tracks press → move → release
drag sequences within and across zones. It emits a `ZoneDragStartMsg` on
button-down inside a zone, `ZoneDragMoveMsg` when the cursor crosses a
zone boundary while dragging, and `ZoneDragEndMsg` on button release. Drag
is pointer-only: anything it triggers needs a keyboard alternative, see
[Accessibility](#accessibility). Cross-boundary moves only arrive when the terminal reports drag or all
motion (mode `1002` / `1003`, see
[Mouse-tracking modes](#mouse-tracking-modes)):

```php
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Mouse\Zones\Manager;
use SugarCraft\Mouse\Zones\DragTracker;
use SugarCraft\Mouse\Zones\Msg\ZoneDragStartMsg;
use SugarCraft\Mouse\Zones\Msg\ZoneDragMoveMsg;
use SugarCraft\Mouse\Zones\Msg\ZoneDragEndMsg;

$manager = new Manager();
$manager->scan($manager->mark('btn', 'OK'));  // registry knows 'btn' (row 1, cols 1-2)
$mouseMsg = new MouseMsg(1, 1, MouseButton::Left, MouseAction::Press);

$tracker = DragTracker::new($manager);

[$tracker, $msg] = $tracker->update($mouseMsg);
if ($msg instanceof ZoneDragStartMsg) {
    // drag started in $msg->originZone
} elseif ($msg instanceof ZoneDragMoveMsg) {
    // cursor crossed from $msg->originZone into $msg->currentZone
} elseif ($msg instanceof ZoneDragEndMsg) {
    // drag ended; started at $msg->originZone, released over
    // $msg->currentZone (null when the release was outside every zone)
}
```

**Origin vs. current zone:** the origin zone is fixed for the entire
drag and never changes. The current zone updates whenever the cursor
crosses a zone boundary during the drag. The end message's current zone
is resolved from the release event itself — the zone under the cursor at
release, or null outside all zones — not the last crossed one.

**Boundary crossing:** moving directly from zone A to zone B while
dragging emits ONE `ZoneDragMoveMsg(originZone: A, currentZone: B)` in
that same `update()` call.

**Button identity:** the Press records which button started the drag; a
Release of a different button is inert. Releasing the drag button always
ends the drag — even when the origin zone has been removed from the manager.
That end is silent: no message, but the tracker clears so it cannot wedge.
A second
Press while a drag is open — the right-press steal — closes the first
gesture before anything else can claim it: `ZoneDragEndMsg(origin, null)`
(null target: abandoned, not released over a zone) and the tracker idles;
the stealing Press starts nothing, and the next Press opens the new drag.

**Click vs. zero-distance drag:** a press and release in the same spot of
the same zone satisfies both state machines — `MultiClickTracker` counts it and
`DragTracker` emits `ZoneDragStartMsg` followed by `ZoneDragEndMsg` with
`currentZone === originZone`. The Start firing on the Press itself means a
plain click inside a zone flashes the drag UI for one event pair unless the
app gates it: paint drag effects only from the first emitted
`ZoneDragMoveMsg` (candy-code's selection controller gates exactly this
way). Neither tracker ships a travel threshold (bubblezone has none
either); if a widget wants EITHER a click OR a drag per gesture, gate drag
effects on a start/end box difference or on at least one emitted
`ZoneDragMoveMsg`. An opt-in movement threshold is a lib ruling that has
not been taken.

**State accessors:**
- `originZoneId()` / `originZone()` — zone the drag started from, or null
- `currentZoneId()` / `currentZone()` — zone the cursor is in, or null
- `dragButton()` — button that started the drag, or null
- `withManager(Manager)` — rebind to a different manager (state carried)
- `withZoneIds(?string $origin, ?string $current)` — restore from
  a serialized id snapshot (button identity is not carried by ids)

### Click tracking

`MultiClickTracker` wraps a `Manager` and tracks double/triple click
streaks inside zones (renamed from `ClickCounter` — the old name collided
with upstream's `NewZoneClickTracker`, a different machine than this lib's
own `ZoneClickTracker`; `ClickCounter` remains loadable as a deprecated
alias). It emits `DoubleClickMsg` on the second press
and `TripleClickMsg` on the third press — all within a configurable
click interval (default 500 ms — a pointer-timing tolerance, not a law:
`MultiClickTracker::new($manager, clickIntervalMs: N)` widens the window, and
apps should consider exposing it as a setting for users with tremor or
reduced dexterity, per the Accessibility section). The streak resets when the interval
expires or when a PRESS lands in a different zone (or outside every zone);
hover movement never touches the streak, and wheel reports are inert. Presses arrive
with plain mode-`1000` reporting (see
[Mouse-tracking modes](#mouse-tracking-modes)):

```php
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Mouse\Zones\Manager;
use SugarCraft\Mouse\Zones\MultiClickTracker;
use SugarCraft\Mouse\Zones\Msg\DoubleClickMsg;
use SugarCraft\Mouse\Zones\Msg\TripleClickMsg;

$manager = new Manager();
$manager->scan($manager->mark('btn', 'OK'));  // registry knows 'btn' (row 1, cols 1-2)
$mouseMsg = new MouseMsg(1, 1, MouseButton::Left, MouseAction::Press);

$counter = MultiClickTracker::new($manager);

[$counter, $msg] = $counter->update($mouseMsg);
if ($msg instanceof DoubleClickMsg) {
    // second press in same zone within interval
} elseif ($msg instanceof TripleClickMsg) {
    // third press in same zone within interval
}
```

**Thinking twice (the revoke question):** `DoubleClickMsg` fires the
moment the second press lands — a later third press emits
`TripleClickMsg` but cannot take the double back. Word-vs-line actions
(double = word, triple = line) therefore need app-layer deferral: on
receiving `DoubleClickMsg`, schedule the word action only after the
remaining interval elapses with no third press (or hold word selection
until the window closes). The counter itself stays immediate-delivery;
an opt-in `deferDoubleClick` was declined in favour of this recipe
(gate G10).

**State accessors:**
- `clickCount()` — current streak count (0 when no streak is active)
- `withManager(Manager)` — rebind to a different manager (the streak is
  carried)
- `$counter->manager` / `$counter->clickIntervalMs` — public **readonly**
  properties; rebind via `withManager()` or tune by constructing a new
  counter. The third constructor parameter, `?callable $now`, is a test
  clock seam (defaults to the monotonic `hrtime()` clock, so an NTP step
  can neither swallow nor coalesce a streak); there are no setters.

### Motion tracking escape sequences

`Manager::motionTrackingAnsi(bool $on)` returns the terminal escape
sequence that enables or disables all-motion tracking (mode 1003) together
with SGR extended coordinates (mode 1006) — the exact bytes and their LIFO
disable order are spelled out under
[Mouse-tracking modes](#mouse-tracking-modes) above. The name is literal: it
returns bytes and emits nothing (renamed from `setMotionTracking()`,
whose setter shape invited discarding the return — a deprecated alias
still delegates). Write the returned string to
the TTY to activate motion reporting before processing mouse move
events. This manager does not directly emit — it is a
text-processing component that produces the raw CSI sequence.

### Event vocabularies

Three action vocabularies meet here, and mixing them up is the classic
integration bug:

- **Core wire** (`SugarCraft\Core\MouseAction`): `Press` / `Release` /
  `Motion`, carried on `MouseMsg`; wheel is a distinct MESSAGE TYPE
  (`MouseWheelMsg`) whose action field core's reader fills with `Press`.
- **Primitive** (`SugarCraft\Mouse\MouseAction`): `Press` / `Release` /
  `Drag` / `Scroll`, carried on `MouseEvent` with X10 button ints — what
  `ZoneClickTracker` and `Selection` consume.
- **Wire bytes** (SGR mode 1006): one integer `b` encoding button plus
  modifier bits — decoded by your input layer before either enum exists.

`Zones\Zone::toMouseEvent()` is the one public bridge (Core → primitive,
wheel type matched first so it becomes `Scroll`, not `Press`).

A `ZoneClickTracker::track()` null on Release is silent **by design** —
forgotten re-hit zone, a zone that moved between frames, and a
press-with-nothing all collapse to the same null (a lost click beats
firing the wrong control). `observeRelease($event, $hit)` answers "would
this release complete the click?" without consuming the pending press, so
you can tell the first cause from the other two. The two-call
`setPressZone()` is a deprecated order-coupled vestige: calling it before
`track(Press)` is a documented no-op — pass the press zone in `track()`.

### Tips

- Each id should be unique within a `Manager`. Use
  `Manager::newPrefix()` per UI sub-tree so two child models don't
  shadow each other's ids.
- Run `scan()` once on the **full root frame**, not per sub-tree —
  nested zone bounds depend on the outer layout.
- `Manager::scan()` strips the markers from its return value, so
  measuring what you print (sugar-core's `Width::string()`) sees
  only visible cells.
- `Zone::isZero()` reports whether both corners are still 0 — the shape
  bubblezone's `Get()` returned for an unknown id. It is a compatibility
  check, NOT a content discriminator: `Scan` never records a zone that
  painted no cell, and every stored zone has corners ≥ 1, so `get()`
  returning null is how "never rendered" surfaces here.
- Organic shapes (ASCII art) report a rectangular bounding box —
  the marker pair only carries 4 corners' worth of information.
- Exception and refusal messages resolve through `SugarCraft\Mouse\Lang`
  against `lang/en.php` — five keys with bare names under the Lang
  namespace `mouse`, and the `sugar-mouse:` label rides inside each
  message value, not in the keys; the package ships `en` only.
- `scan()` is synchronous (no background worker), so
  `close()` is purely a state reset / disable rather than a thread
  join.
- `scan()` strips exactly the tags the primitive `Scan`'s instance `parse()`
  decodes:
  `U+E000 [/] <id> U+E001` where `<id>` passes `Mark::isValidId()` (or is
  empty) is removed whole. Any other sentinel — an open `U+E000` with no
  closing `U+E001`, one whose would-be id holds a space/newline/CR/escape/
  non-ASCII byte or exceeds `Mark::MAX_ID_BYTES`, or a bare `U+E001` — loses
  only its own 3 bytes, and the text after it is kept, because the scanner
  measures that text as visible cells. Orphan closes / unclosed opens with a
  valid id are removed and record no zone, but every open/close tag pair
  should still be balanced for a zone's bounds to be discovered.

### API summary

Grouped by layer — the primitive rows answer "what decoded geometry do I have",
the Zones rows answer "what does the app-facing manager and its trackers do".

**Primitive layer (`SugarCraft\Mouse`)**

| Class | Method | Description |
|---|---|---|
| `Scan` | `strip(rendered)` | Public byte-wise removal of well-formed tags |
| `Scanner` | `idsStartingWith(prefix)` | Zones whose ids begin with `$prefix` — the reading side of the prefix vocabulary (`newPrefix()` stamps, this selects); formerly spelled `prefixed()`, which remains as a deprecated alias |
| `Mark` | `untrusted(text)` | Sweep foreign sentinel framing bytes off untrusted labels |
| `Zone` | `inBounds(mouseMsg)` | Test if mouse is inside zone |

**Zones layer (`SugarCraft\Mouse\Zones`)**

| Class | Method | Description |
|---|---|---|
| `Manager` | `newUnprefixed()` | Build a fresh un-prefixed manager independent of every other instance — NOT the shared default (that is `Zones::defaultManager()`); formerly spelled `newGlobal()`, which remains as a deprecated alias |
| `Manager` | `newPrefix(prefix)` | Create prefixed manager for isolation |
| `Manager` | `mark(name, rendered)` | Wrap output with zone marker |
| `Manager` | `scan(output, ?width = null)` | Merge-record zones into the registry (re-recorded ids replace, absent ids persist; a `newPrefix()` manager skips ids outside its prefix, a `newUnprefixed()` manager records every id), strip markers, clamp columns to `?width` |
| `Manager` | `anyInBounds(mouseMsg)` | Return the innermost zone under the mouse |
| `Manager` | `get(name)` | Get zone by name |
| `Manager` | `motionTrackingAnsi(bool)` | Return paired CSI 1003+1006 enable / LIFO disable sequence (bytes only, emits nothing; formerly the `setMotionTracking` setter) |
| `Manager` | `allInBounds(mouseMsg)` | Every covering zone, smallest area first |
| `Manager` | `zoneAt(col, row)` | Cell hit-test without a mouse message |
| `Manager` | `idsAbsentFromFrame(ids)` | Registered ids missing from the ids a frame painted — the prune seam |
| `Manager` | `anyInBoundsAndUpdate(model, msg)` | Dispatch `MsgZoneInBounds` into a Model |
| `Manager` | `clear(?id)` / `close()` | Drop one zone by plain name or prefixed wire id (what `mark()` stamps, what `get()` reads) / wipe the registry and flip to pass-through — recovery steps under Manager API |
| `Zone` | `toMouseEvent(mouseMsg)` | Core→primitive action/button bridge |
| `Zones` (static) | `mark` / `scan` / `get` / `clear` / `close` / `setEnabled` / `isEnabled` / `newPrefix` / `anyInBounds*` | Package-level facade over the default manager |
| `Zones` (static) | `defaultManager()` | Read the shared default `Manager` the package-level facade routes through (`Manager::newUnprefixed()` builds a different, independent instance) |
| `ZoneHoverTracker` | `__construct(manager)` | Track hover state over a manager |
| `ZoneHoverTracker` | `update(mouseMsg)` | Process mouse event, return enter/exit msg |
| `ZoneHoverTracker` | `currentZone()` | Get the hovered Zone or null |
| `ZoneEnterMsg` | `zone` | Zone the cursor just entered |
| `ZoneExitMsg` | `zone` | Zone the cursor just left |
| `DragTracker` | `__construct(manager)` | Track drag sequences over a manager |
| `DragTracker` | `update(mouseMsg)` | Process mouse event, return drag msg |
| `DragTracker` | `originZone()` | Get the origin Zone or null |
| `DragTracker` | `currentZone()` | Get the current Zone or null |
| `ZoneDragStartMsg` | `originZone / currentZone` | Zone where drag started; zone at current cursor |
| `ZoneDragMoveMsg` | `originZone / currentZone` | Fixed origin zone; zone cursor just crossed into |
| `ZoneDragEndMsg` | `originZone / currentZone` | Zone drag started from; zone at release |
| `MultiClickTracker` | `__construct(manager, clickIntervalMs)` | Track double/triple click streaks |
| `MultiClickTracker` | `update(mouseMsg)` | Process press event, return double/triple msg |
| `MultiClickTracker` | `clickCount()` | Current streak count (0 = no streak) |
| `MultiClickTracker` | `withManager(manager)` | Rebind hit-testing, carry the streak across |
| `ZoneHoverTracker` | `withManager(manager)` | Rebind hit-testing, carry the hovered zone and owed enter |
| `ZoneHoverTracker` | `withCurrentZoneId(?id)` | Restore (or clear) hover from a serialized id — drops any owed enter |
| `ZoneHoverTracker` | `pendingEnter()` | The enter owed after a boundary crossing, reported without consuming |
| `DragTracker` | `withManager(manager)` | Rebind hit-testing, carry the open gesture |
| `DragTracker` | `withZoneIds(?origin, ?current)` | Restore an open drag from serialized ids (zones re-resolved from the registry) |
| `DragTracker` | `dragButton()` | The `MouseButton` the open drag was pressed with (null when idle) |
| `DragTracker` | `originZoneId()` / `currentZoneId()` | Id spellings of the two zone accessors (survive zone re-resolution) |
| `Zones\Zone` | `fromMouseZone(zone)` | Adapt a primitive `Zone` into the hit-testable adapter (bridge, not copy) |
| `Zones\Zone` | `contains(other)` | Does this zone's rect fully cover another's |
| `Zones\Zone` | `width()` / `height()` / `isZero()` | Geometry straight off the wrapped rect |
| `Zones\Zone` | `pos(mouseMsg)` | `[col, row]` from the zone's top-left, 0-based |
| `MsgZoneInBounds` | `zone` / `mouse` | The hit zone and the event that hit it (payload properties) |
| `DoubleClickMsg` | `zone` | Zone of the second press |
| `TripleClickMsg` | `zone` | Zone of the third press |


## Examples

Runnable, self-explanatory files under `examples/` (after
`composer install`, run e.g. `php examples/zones-buttons.php`):

- `zones-buttons.php` — a button row through `Manager::newUnprefixed()`,
  marking, scanning and resolving clicks to zone ids.
- `zones-list-default.php` — a selectable list: one manager, per-row
  marked items, click-to-focus resolution, plus a scripted keyboard twin
  driving the same cursor with Down/Enter.
- `zones-nested-components.php` — two `Manager::newPrefix()` managers
  (left/right panes) keeping identically-named ids from shadowing each
  other.

## Where this fits

The whole stack consumes this package through one of its two layers:
sugar-widgets and sugar-gallery drive `Zones\Manager` instances; candy-code's TUI
runs the primitive `Selection` / `SelectionRange` machine for its
mouse text-selection, and its renderer owns the sentinel sweep described
above.

## Credits & inspiration

Originally inspired by [lrstanley/bubblezone](https://github.com/lrstanley/bubblezone) and the wider Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project. The Mark/Scan/Get pattern follows bubblezone's API; `ZoneClickTracker` addresses [bubblezone issue #10](https://github.com/lrstanley/bubblezone/issues/10).
