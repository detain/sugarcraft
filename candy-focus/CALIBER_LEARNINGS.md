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
