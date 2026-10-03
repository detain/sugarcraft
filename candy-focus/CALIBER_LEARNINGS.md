# candy-focus — session learnings

Patterns and anti-patterns specific to this lib. Treat as project-specific rules.

- **Dependency-free on purpose.** `FocusRing` is pure focus-ring *state*: no
  rendering, no key decoding, no candy-core/candy-sprinkles imports. Keep it that
  way — styling the focused region and mapping Tab→`next()` belong to the
  consumer. Adding a dependency to draw a focus border would defeat the point.
- **One focused member invariant.** A non-empty ring always has exactly one
  focused region (`index` in `[0, count)`); empty rings use `index = -1` /
  `current() === null`. Every code path must preserve this — tests assert it
  after register/unregister/focus/next/previous.
- **`unregister()` index math is the subtle bit.** Removing a region *before*
  the focused slot decrements the focused index; removing the focused region
  keeps the slot (clamped to the new end); removing one *after* leaves the index
  alone. Cover all three plus the empty-out case.
- **Immutable like the rest of the stack.** Mutators return a new instance; the
  no-op cases (`register` existing, `focus` unknown/already-focused, `next`/
  `previous` with < 2 regions) return `$this` so callers can cheaply detect
  "nothing changed" by identity.
- **Every member-changing path must keep `$disabled` a subset of `$ids`.**
  `unregister()` and `reorder()` drop the flags of ids they remove; `register()`
  and `reorder()` add new ids enabled. A phantom flag desyncs the counts from
  `enabledIds()`/`disabledIds()` and leaks into `jsonSerialize()`. Asserts are
  off (`zend.assertions=-1`) on this runtime, so the test helper
  `assertCacheConsistent()` checks it via reflection — call it after any new
  mutator.
- **Traversal from a disabled focus beats the sole-enabled no-op.** `step()`
  handles "focus parked on a disabled region" before "only one region enabled",
  otherwise the user is stranded on a dimmed panel. Keep `next()`/`previous()`
  as thin wrappers over the one `step()` so the directions cannot drift.
- **Never `array_keys()` a set keyed by region id for output.** PHP coerces
  numeric-string keys ("1") to int; derive id lists from `$ids` values instead.
