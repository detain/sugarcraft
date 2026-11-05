# sugar-focus

> Formerly published as `sugarcraft/candy-focus` (renamed 2026-10). <!-- @rename-keep -->

A tiny, dependency-free **focus ring** for full-window terminal UIs: an ordered
set of focusable regions with a single focused member and wrap-around
Tab/Shift-Tab traversal.

It is the "which panel has focus" state for a TUI layout. Register each
focusable region (sidebar, content grid, filter bar…), map **Tab** to `next()`
and **Shift-Tab** to `previous()`, and give the region returned by `current()`
an accent border when you render. The ring carries no rendering or key decoding
of its own, so it composes with any sugar-core model without pulling in
dependencies.

## Install

```sh
composer require sugarcraft/sugar-focus
```

## Quick start

```php
use SugarCraft\Focus\FocusRing;

$ring = FocusRing::of('sidebar', 'grid', 'filter'); // 'sidebar' is focused

$ring = $ring->next();        // → 'grid'
$ring = $ring->next();        // → 'filter'
$ring = $ring->next();        // → 'sidebar' (wraps)
$ring = $ring->previous();    // → 'filter' (wraps the other way)
$ring = $ring->focus('grid'); // jump straight to a region

$ring->current();             // 'grid'
$ring->isFocused('grid');     // true
```

Wire it into a sugar-core model's `update()`:

```php
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;

if ($msg instanceof KeyMsg && $msg->type === KeyType::Tab
    && !$msg->ctrl && !$msg->alt) {
    $ring = $msg->shift ? $this->ring->previous() : $this->ring->next();
    return [$this->withRing($ring), null];
}
```

On the wire, **Shift+Tab** arrives as `Tab` with `shift: true` — sugar-core's
`InputReader` decodes `ESC [ Z` (CSI-Z, the literal "Backtab" sequence
terminals send for Shift+Tab) into that shape. The ring leg is gated on
`!$msg->ctrl && !$msg->alt`, so **Ctrl+Tab** and **Ctrl+Shift+Tab** stay free
for other bindings — but that holds only on terminals that *report* modifiers
(kitty keyboard negotiation, or an xterm `modifyOtherKeys`-style decoder).
On legacy/basic terminals Ctrl+Tab is byte-identical to bare Tab: nothing can
separate the two there, and those presses move the ring like a plain Tab
would. (On sugar-core builds predating the `ESC [ Z` decoder arm the shift
leg never fires at all.)

…and let each region style itself in `view()`:

```php
$style = $ring->isFocused('sidebar') && $ring->isEnabled('sidebar')
    ? $accentBorder : $plainBorder;
```

The `isEnabled()` conjunct matters: focus can legally park on a disabled
region, so `isFocused()` alone can paint an accent border on a dimmed panel.

Pair the paint with a non-color cue. An accent border is invisible to users
who cannot distinguish the hue, so `examples/focus-ring.php` prints a text
status row per panel as well — `> FOCUS`, `> parked`, `idle`, `off` — and a
footer naming the focused region (WCAG 1.4.1 *Use of Color*; border
strength itself under 1.4.11 *Non-text Contrast*).

## Behaviour

- A **non-empty ring always has exactly one focused region**; an empty ring
  focuses nothing (`current()` is `null`, `index()` is `-1`).
- `register()` appends to the traversal order and focuses the region only when
  the ring was empty; re-registering an existing id is a no-op.
- `unregister()` keeps focus on the same region where possible; removing the
  focused region lands focus on the nearest **enabled** survivor at or after
  the vacated slot — the slot is first clamped to the new end, so removing the
  focused *tail* lands on the new tail rather than wrapping to the head, and
  the wrap reaches the earliest enabled survivor only when every survivor
  ahead of the slot is disabled. With nothing enabled left, focus parks on the
  clamped slot itself; emptying the ring clears focus.
- `next()` / `previous()` wrap around and are no-ops with fewer than two
  **enabled** regions — with one exception: focus parked on a disabled region
  beats the no-op and moves to the nearest enabled survivor.
- `reorder()` replaces the traversal order in one step: ids are deduped
  (first wins), focus stays on the current region if it survives (otherwise
  focus lands on the first **enabled** survivor — index 0 only when every
  survivor is disabled), new ids are added and missing ids dropped.
- **Immutable** — every mutator returns a new `FocusRing` and leaves the
  receiver untouched, so it slots into the immutable-model architecture.
  No-op calls (registering an existing id, focusing an unknown, disabled or
  already-focused id, traversal with nowhere to go, reordering an empty ring
  to an empty set…) return the *same* instance, so `$new === $old` cheaply
  detects "nothing changed".

### Disabled regions

- `disable()` keeps a region registered but makes `next()` / `previous()` skip
  it; `enable()` puts it back. Both are no-ops for unknown ids or ids already
  in that state.
- Disabling the **focused** region does not move focus — `current()` still
  names it. The following `next()` or `previous()` call moves focus to the
  nearest enabled region — even a sole enabled survivor.
- `focus()` refuses a disabled id: it is a no-op, just like focusing an
  unknown or already-focused region. Restore order relies on this — focus
  first, then re-apply the disabled flags (see the persistence recipe below).
- Once focus sits on the only enabled region, `next()` / `previous()` are
  no-ops; with every region disabled they are no-ops too.
- **Paint the accent with `isFocused($id) && isEnabled($id)`.** Focus can
  legally park on a disabled region — `disable()` never moves it, and
  `unregister()` lands on the vacated slot when every survivor is disabled —
  so `isFocused()` alone can highlight a dimmed panel.
- `register()` always adds an enabled region (a re-registered id was
  unregistered first, so it comes back enabled by construction).
  `unregister()` clears the removed id's flag, and `reorder()` keeps flags
  only for ids that survive — so a region dropped while disabled and later
  re-added comes back enabled.

### Iteration, counting and persistence

- `FocusRing` implements `IteratorAggregate`, `Countable` and
  `JsonSerializable`: `foreach ($ring as $id)` walks every registered id
  (disabled ones included) in traversal order, `count($ring)` is the number of
  registered regions, and `json_encode($ring)` produces
  `{"ids":[…],"focused":…,"index":n,"disabled":[…]}`.
- The snapshot's `disabled` list is in traversal order and always holds
  strings, numeric ids such as `"1"` included. `focused` names the id directly
  (drift-proof against reordering) while `index` rides along for readers of
  older snapshots. Restore with the inverse of `jsonSerialize()`:

```php
$decoded = json_decode($json, true);
$ring = is_array($decoded)
    ? FocusRing::restore($decoded)
    : FocusRing::of('sidebar', 'grid', 'filter');  // unreadable file: rebuild defaults
```

  For an **array** payload, `restore()` prefers the `focused` id over the
  positional leg when both are present, and an *unknown* `focused` id lands on
  the first region — the `index` leg is consulted only when the focus leg is
  absent or not a string, and then clamped into the surviving ring rather than
  trusted (a
  legacy session file from before a layout change cannot crash startup). It
  drops `disabled` entries for regions that no longer exist and still loads
  legacy positional-only snapshots. Malformed `ids` throw
  `InvalidArgumentException`; everything else *in the array payload* degrades
  fail-soft. Entry comparison is string-on-string: a hand-edited
  numeric `1` in `disabled` never matches the id `"1"`, so that region comes
  back **enabled** — ids in the list must be JSON strings. Note the parameter
  is typed `array`: a corrupt file
  whose `json_decode()` yields `null` or a string is a caller-side `is_array`
  guard away — restore() itself never sees it.

## Modals and focus traps

The ring has no built-in layer concept: a modal dialog is just more regions,
and the trap is the disabled set. The recipe:

```php
$saved = $ring->current();                       // what the dialog interrupts (null on an empty ring)
$ring = $ring->register('dialog-field')          // the dialog's focusables
    ->focus('dialog-field');
foreach (['sidebar', 'grid', 'filter'] as $id) {
    $ring = $ring->disable($id);                 // background unreachable
}

// On close — re-enable the background first, then drop the dialog:
foreach (['sidebar', 'grid', 'filter'] as $id) {
    $ring = $ring->enable($id);
}
$ring = $ring->unregister('dialog-field');
$ring = $saved === null ? $ring->focusFirst() : $ring->focus($saved);
```

`current()` is `null` when nothing was focused — a dialog opened over an
empty ring must restore via `focusFirst()` (or accept where `unregister()`
landed), never by handing `null` to the `string` parameter of `focus()`.

Mind the edges: `unregister()` of the *focused* region lands on the nearest
enabled survivor, so bring the background back before removing the dialog
when you want the saved region exactly; and `reorder()` drops the disabled
flags of any id it removes — rebuilding background order through `reorder()`
deliberately clears stale state, re-disable afterwards if the panels were
meant to stay out of traversal. A first-class `suspend()` / `ofLayer()`
primitive is a deliberate omission for now; this disable/enable dance is the
supported path.

### Non-visual access

SGR borders are invisible *and* inaudible: an Orca-style reader never sees the
accent paint, so the ring's state must reach the user as text. Announce
`current()` by name whenever it changes — the demo's footer (`● focused: …`)
is that contract in miniature. For a "region k of n" position, prefer
`enabledIndex() + 1` over `enabledCount()` (`FocusRing.php:753, :607`) to the
raw `index()`/`count()` pair: it reflects what Tab can actually reach.
`enabledIndex()` is `null` when focus is parked on a disabled region or the
ring is empty — announce the region name alone in that case, never position
`0`. Seen this way the trap recipe above is not a dialog hack but the
keyboard-accessibility pattern itself: the disabled set *is* the focus order
(WCAG 2.4.3 *Focus Order*), and a ring-driven text marker is how focus stays
perceivable (2.4.7 *Focus Visible*).

## API

| Method | Description |
|---|---|
| `FocusRing::new()` | An empty ring. |
| `FocusRing::of(string ...$ids)` | A ring of regions (duplicates dropped), focusing the first. |
| `FocusRing::ofStrict(string ...$ids)` | Like `of()`, but throws `InvalidArgumentException` on a duplicate id. |
| `FocusRing::restore(array $snapshot): self` | Inverse of `jsonSerialize()` — clamps stale indices on legacy positional-only snapshots, unknown `focused` ids land on the first region, drops unknown disabled entries. Takes a decoded **array**: guard `json_decode()` with `is_array()` before calling. |
| `register(string $id): self` | Add an enabled region to the end of the order. |
| `unregister(string $id): self` | Remove a region, preserving focus where possible. |
| `reorder(string ...$ids): self` | Replace the traversal order, keeping focus on the current id when it survives. |
| `focus(string $id): self` | Focus a specific registered, **enabled** region (no-op otherwise). |
| `next(): self` / `previous(): self` | Tab / Shift-Tab traversal (wrapping, skipping disabled regions). |
| `nextOf(string $id): ?string` / `previousOf(string $id): ?string` | Pure query: the id traversal would land on if focus sat on `$id` (focus not moved; `null` for unregistered ids). |
| `focusFirst(): self` / `focusLast(): self` | Jump to the first / last **enabled** region (no-op when none is enabled). |
| `disable(string $id): self` / `enable(string $id): self` | Exclude a region from / restore it to traversal. |
| `isEnabled(string $id): bool` | Whether `$id` is registered and not disabled (`false` conflates unregistered with disabled — use `has()` to tell them apart). |
| `enabledIds(): list<string>` / `disabledIds(): list<string>` | Enabled / disabled ids in traversal order. |
| `enabledCount(): int` / `disabledCount(): int` | Enabled / disabled region counts. |
| `current(): ?string` | The focused region id, or `null`. |
| `isFocused(string $id): bool` | Whether `$id` is the focused region (paint with `&& isEnabled($id)`). |
| `has(string $id): bool` | Whether `$id` is registered. |
| `index(): int` | Focused position into **all registered ids**, or `-1` when empty (`enabledIndex()` for the "k of n" enabled ordinal). |
| `enabledIndex(): ?int` | Focused region's 0-based ordinal into `enabledIds()`, or `null` when the ring is empty or focus is parked on a disabled region. |
| `ids(): list<string>` | Registered region ids in traversal order. |
| `count(): int` / `isEmpty(): bool` | Size helpers; `count($ring)` works too (`Countable`). |
| `getIterator(): Traversable<int, string>` | `foreach` support — every registered id in traversal order. |
| `jsonSerialize(): array` | `['ids' => …, 'focused' => …, 'index' => …, 'disabled' => …]` snapshot for `json_encode()`; `restore()` reads it back. |

## License

MIT © Joe Huss

## Credits & inspiration

The focus ring is an **original SugarCraft design — no 1:1 Go counterpart**.
It plays the role charmbracelet/bubbles' focus handling and sugar-dashboard's
`FocusManager` play in their stacks, but as a standalone, dependency-free
ring, so the attribution above is by analogy only.

Runnable example: [`examples/focus-ring.php`](https://github.com/sugarcraft/sugar-focus/tree/master/examples)

<img loading="lazy" src="https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-focus/.vhs/focus-ring.gif" alt="sugar-focus demo">
