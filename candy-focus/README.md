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
- `reorder()` replaces the traversal order in one step: ids are deduped
  (first wins), focus stays on the current region if it survives (otherwise
  the first region is focused), new ids are added and missing ids dropped.
- **Immutable** — every mutator returns a new `FocusRing` and leaves the
  receiver untouched, so it slots into the immutable-model (TEA) pattern.
  No-op calls (registering an existing id, focusing an unknown or already
  focused id, traversal with nowhere to go…) return the *same* instance, so
  `$new === $old` cheaply detects "nothing changed".

### Disabled regions

- `disable()` keeps a region registered but makes `next()` / `previous()` skip
  it; `enable()` puts it back. Both are no-ops for unknown ids or ids already
  in that state.
- Disabling the **focused** region does not move focus — `current()` still
  names it. The next `next()` / `previous()` carries focus off onto the nearest
  enabled region in that direction, even when that is the only enabled region
  left.
- Once focus sits on the only enabled region, `next()` / `previous()` are
  no-ops; with every region disabled they are no-ops too.
- `register()` always adds an enabled region. `unregister()` clears the
  removed id's flag, and `reorder()` keeps flags only for ids that survive —
  so a region dropped while disabled and later re-added comes back enabled.

### Iteration, counting and persistence

- `FocusRing` implements `IteratorAggregate`, `Countable` and
  `JsonSerializable`: `foreach ($ring as $id)` walks every registered id
  (disabled ones included) in traversal order, `count($ring)` is the number of
  registered regions, and `json_encode($ring)` produces
  `{"ids":[…],"index":n,"disabled":[…]}`.
- The snapshot's `disabled` list is in traversal order and always holds
  strings, numeric ids such as `"1"` included, so it can be fed straight back
  in to restore a session:

```php
$s = json_decode($json, true); // a non-empty snapshot
$ring = FocusRing::of(...$s['ids'])->focus($s['ids'][$s['index']]);
foreach ($s['disabled'] as $id) {
    $ring = $ring->disable($id);
}
```

## API

| Method | Description |
|---|---|
| `FocusRing::new()` | An empty ring. |
| `FocusRing::of(string ...$ids)` | A ring of regions (duplicates dropped), focusing the first. |
| `FocusRing::ofStrict(string ...$ids)` | Like `of()`, but throws `InvalidArgumentException` on a duplicate id. |
| `register(string $id): self` | Add an enabled region to the end of the order. |
| `unregister(string $id): self` | Remove a region, preserving focus where possible. |
| `reorder(string ...$ids): self` | Replace the traversal order, keeping focus on the current id when it survives. |
| `focus(string $id): self` | Focus a specific registered region. |
| `next(): self` / `previous(): self` | Tab / Shift-Tab traversal (wrapping, skipping disabled regions). |
| `disable(string $id): self` / `enable(string $id): self` | Exclude a region from / restore it to traversal. |
| `isEnabled(string $id): bool` | Whether `$id` is registered and not disabled. |
| `enabledIds(): list<string>` / `disabledIds(): list<string>` | Enabled / disabled ids in traversal order. |
| `enabledCount(): int` / `disabledCount(): int` | Enabled / disabled region counts. |
| `current(): ?string` | The focused region id, or `null`. |
| `isFocused(string $id): bool` | Whether `$id` is the focused region. |
| `has(string $id): bool` | Whether `$id` is registered. |
| `index(): int` | Focused position, or `-1` when empty. |
| `ids(): list<string>` | Registered region ids in traversal order. |
| `count(): int` / `isEmpty(): bool` | Size helpers; `count($ring)` works too (`Countable`). |
| `getIterator(): Traversable<int, string>` | `foreach` support — every registered id in traversal order. |
| `jsonSerialize(): array` | `['ids' => …, 'index' => …, 'disabled' => …]` snapshot for `json_encode()`. |

## License

MIT © Joe Huss
