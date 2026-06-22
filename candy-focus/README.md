# candy-focus

A tiny, dependency-free **focus ring** for full-window terminal UIs: an ordered
set of focusable regions with a single focused member and wrap-around
Tab/Shift-Tab traversal.

It is the "which panel has focus" state for a TUI layout. Register each
focusable region (sidebar, content grid, filter bar…), map **Tab** to `next()`
and **Shift-Tab** to `previous()`, and give the region returned by `current()`
an accent border when you render. The ring carries no rendering or key decoding
of its own, so it composes with any candy-core model without pulling in
dependencies.

## Install

```sh
composer require sugarcraft/candy-focus
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

Wire it into a candy-core model's `update()`:

```php
if ($msg instanceof KeyMsg && $msg->type === KeyType::Tab) {
    $ring = $msg->shift ? $this->ring->previous() : $this->ring->next();
    return [$this->withRing($ring), null];
}
```

…and let each region style itself in `view()`:

```php
$style = $ring->isFocused('sidebar') ? $accentBorder : $plainBorder;
```

## Behaviour

- A **non-empty ring always has exactly one focused region**; an empty ring
  focuses nothing (`current()` is `null`, `index()` is `-1`).
- `register()` appends to the traversal order and focuses the region only when
  the ring was empty; re-registering an existing id is a no-op.
- `unregister()` keeps focus on the same region where possible; removing the
  focused region shifts focus to whatever takes its slot (clamped to the end),
  and emptying the ring clears focus.
- `next()` / `previous()` wrap around and are no-ops with fewer than two
  regions.
- **Immutable** — every mutator returns a new `FocusRing` and leaves the
  receiver untouched, so it slots into the immutable-model (TEA) pattern.

## API

| Method | Description |
|---|---|
| `FocusRing::new()` | An empty ring. |
| `FocusRing::of(string ...$ids)` | A ring of regions (duplicates dropped), focusing the first. |
| `register(string $id): self` | Add a region to the end of the order. |
| `unregister(string $id): self` | Remove a region, preserving focus where possible. |
| `focus(string $id): self` | Focus a specific registered region. |
| `next(): self` / `previous(): self` | Tab / Shift-Tab traversal (wrapping). |
| `current(): ?string` | The focused region id, or `null`. |
| `isFocused(string $id): bool` | Whether `$id` is the focused region. |
| `has(string $id): bool` | Whether `$id` is registered. |
| `index(): int` | Focused position, or `-1` when empty. |
| `ids(): list<string>` | Registered region ids in traversal order. |
| `count(): int` / `isEmpty(): bool` | Size helpers. |

## License

MIT © Joe Huss
