# lrstanley/bubblezone

## Metadata

- **URL:** https://github.com/lrstanley/bubblezone
- **Language:** Go
- **Stars:** 880+
- **License:** MIT License
- **Description:** A helper utility for BubbleTea that enables easy mouse event tracking. It wraps TUI components in zero-printable-width ANSI escape sequence identifiers, allowing precise hit-detection for mouse clicks without impacting lipgloss width calculations.

## Feature List

- **Zone-based mouse event tracking** — Wrap any rendered component in a named "zone" and query whether mouse events fall within its bounds
- **Zero-width ANSI markers** — Injects invisible ANSI escape sequences around zone content; these are stripped from output and ignored by `lipgloss.Width()` calculations
- **Global or per-instance manager** — Use a package-level global manager (`NewGlobal()`) for simple apps, or create explicit `Manager` instances for library code
- **Prefix-based ID disambiguation** — `NewPrefix()` generates guaranteed-unique prefixes to prevent zone ID collisions across nested components
- **Relative mouse coordinates** — `ZoneInfo.Pos()` returns mouse position relative to the zone's top-left corner (useful for cursor placement in input fields)
- **Batch event delivery** — `AnyInBounds()` / `AnyInBoundsAndUpdate()` send `MsgZoneInBounds` messages to a model for every zone under the mouse, in alphabetical order
- **Concurrent-safe zone storage** — Background goroutine worker with channel-based communication; atomic operations for counters
- **Performance-optimized scanning** — Custom state-machine scanner processes the full view string in a single pass per render cycle
- **Toggle on/off** — `SetEnabled(false)` strips zone markers and drops zone data without removing the wiring

## Key Classes and Methods

### `Manager` (manager.go)

- `New()` — Creates a new non-global zone manager with its own goroutine worker
- `Mark(id, v string) string` — Wraps `v` with start/end ANSI markers; returns `v` unchanged if manager is disabled or if either argument is empty. Caches generated marker IDs to reuse them across renders.
- `Scan(v string) string` — Entry point at the root model's `View()`. Runs the state-machine scanner over `v`, strips all ANSI markers, sends zone coordinates to the background worker, and returns the clean string.
- `Get(id string) *ZoneInfo` — Returns stored zone bounds for a given user-facing ID (or `nil` if not yet registered)
- `Clear(id string)` — Removes a zone from storage
- `SetEnabled(bool)` — Enables/disables zone tracking; when disabled, `Mark()` is a no-op and `Scan()` strips markers without storing coordinates
- `NewPrefix() string` — Returns `"zone_N__"` where N is an auto-incremented atomic counter, guaranteeing uniqueness across sibling components

### `Manager` globals (manager_global.go)

- `NewGlobal()` — Initializes `DefaultManager` (idempotent, safe to call multiple times)
- `Close()` — Stops the background worker
- `Mark()`, `Scan()`, `Get()`, `Clear()`, `NewPrefix()`, `SetEnabled()`, `Enabled()` — Mirror the instance methods on the global manager
- `AnyInBounds(model tea.Model, mouse tea.MouseMsg)` — Fires `MsgZoneInBounds` for every zone under the mouse (results of `Update()` discarded)
- `AnyInBoundsAndUpdate(model tea.Model, mouse tea.MouseMsg) (tea.Model, tea.Cmd)` — Same as above but propagates `Update()` results through `tea.Batch()`

### `ZoneInfo` (zoneinfo.go)

- `StartX, StartY` — Coordinates of the top-left cell of the zone (0-based)
- `EndX, EndY` — Coordinates of the bottom-right cell of the zone
- `InBounds(msg tea.MouseMsg) bool` — Box-based collision test; returns false for invalid (zero) zones, inverted bounds, or out-of-bounds mouse position
- `Pos(msg tea.MouseMsg) (x, y int)` — Returns mouse coordinates relative to zone origin; returns `(-1, -1)` if zone is unknown or mouse is out of bounds
- `IsZero() bool` — Returns true if the zone has no ID set (i.e., not yet registered or explicitly empty)

### `scanner` (scanner.go)

- State-machine parser with two states: `scanMain` (scanning for printable text / newlines / ANSI start) and `scanID` (reading a numeric marker between `[` and `z`)
- `emit()` — Called when a matching end marker is found; computes `EndX`/`EndY` via `printableRuneWidth()` accounting for ANSI escape sequences, sends `ZoneInfo` to the manager's `setChan`; or if only a start marker was seen, stores a pending `ZoneInfo` for later
- `printableRuneWidth(s string) int` — Counts visible cell width by skipping ANSI escape sequences (using the 0x40-0x5A / 0x61-0x7A terminator rule) and summing `go-runewidth.RuneWidth()` for all other runes

### `MsgZoneInBounds` (messages.go)

- `Zone *ZoneInfo` + `Event tea.MouseMsg` — Message type sent to models via `AnyInBounds` when a zone is under the mouse

## Notable Algorithms / Named Patterns

- **State-machine scanner** — The scanner uses a `stateFn` function-pointer pattern (classic Ragel-style FSM) to parse the view string in a single left-to-right pass, handling nested markers by tracking start positions and newline counts
- **Zero-width ANSI markers** — BubbleZone injects `\x1B[<number>z` sequences around zone content. These are "private use" ANSI CSI sequences (parameters ≤`?` and terminator `p`-`~` are vendor-private) that terminals ignore for display purposes, and lipgloss's width functions skip because they only count printable characters
- **Iteration-based zone invalidation** — Each `Scan()` call uses `time.Now().Nanosecond()` as an iteration marker. The background worker clears all zones whose stored iteration differs from the incoming one, allowing stale zones from previous renders to be evicted automatically
- **Atomic counter prefix generation** — `prefixCounter` and `markerCounter` are `int64` values incremented with `atomic.AddInt64`, ensuring thread-safe unique ID generation without locking
- **Go channel worker pattern** — `setChan chan *ZoneInfo` connects the scanner (producer) to the background `zoneWorker()` goroutine (consumer), which applies all zone updates under a single mutex
- **Box collision detection** — `InBounds()` uses the standard axis-aligned bounding box (AABB) test: mouse.X ∈ [StartX, EndX] ∧ mouse.Y ∈ [StartY, EndY]

## Strengths

- **Minimal, focused API** — Only 6 public functions on the manager; everything a user needs to know is in the 3-method `Mark()`/`Scan()`/`Get()` pattern
- ** lipgloss.Width() compatible** — The core design constraint (zero-width markers) is explicitly guaranteed and documented; users can mix zone markers with styled text without breaking layout
- **Performance-conscious** — Single-pass state-machine scan; atomic counters avoid mutex contention for ID generation; buffered channel decouples scanning from zone storage
- **Flexible scoping** — Global singleton for simple apps, explicit manager for libraries and tests
- **Good test coverage** — Table-driven tests with lipgloss-styled inputs, benchmarks, and a fuzz test; 217-line test file covers disabled mode, clearing, worker lifecycle
- **Clear separation of concerns** — Scanner knows nothing about BubbleTea; `ZoneInfo` knows nothing about the scanner; `Manager` orchestrates. Easy to reason about and test in isolation

## Weaknesses

- **Only axis-aligned bounding box (AABB) collision** — Organic/non-rectangular shapes require manual padding; clicks on empty corners of the bounding box register as hits. Not suitable for precise non-rectangular hit testing without workaround
- **Tightly coupled to BubbleTea and lipgloss v2** — v2 warning in README explicitly states incompatibility with lipgloss v2's canvas/compositor; upstream BubbleTea/lipgloss v2 changes can break the library
- **Requires alt-screen + mouse mode** — Users must remember to set `view.AltScreen = true` and `view.MouseMode = tea.MouseModeCellMotion`; no runtime error if these are forgotten, just silent failure
- **Deprecation risk** — Author notes intent to release a successor library with "improved mouse event tracking" that supersedes bubblezone, suggesting this is in maintenance mode
- **Time-based iteration for invalidation** — Using `time.Now().Nanosecond()` as an iteration token could theoretically collide across very rapid re-renders (though practically extremely unlikely on modern hardware)
- **No native support for overlapping interactive elements** — If two zones visually overlap (e.g., a button inside a panel), `AnyInBounds` will fire for both, requiring manual deduplication logic

## SugarCraft Mapping

> **Note:** SugarCraft is a PHP monorepo porting the Charmbracelet ecosystem (BubbleTea, lipgloss, etc.) to PHP. bubblezone maps to the event/mouse-handling layer of this ecosystem.

| bubblezone concept | SugarCraft lib | Notes |
|---|---|---|
| `zone.Mark(id, v)` wrapping zone content | `sugar-bits` (focus/mouse zone tracking) | sugar-bits handles viewport/layout + focus management; mouse zones would be a sub-concern |
| `zone.Get(id).InBounds(msg)` collision | `candy-core` (BubbleTea model port) | The `Model::update()` + `KeyMsg`/`MouseMsg` dispatch loop in `candy-core` provides the TEA message bus; zones would integrate there |
| `zone.Scan()` stripping ANSI markers | `candy-forms` / lipgloss port (TBD) | If/when a lipgloss-style styling library is ported, zone scanning for marker stripping would live there |
| `zone.NewPrefix()` unique ID generation | `candy-shell` (BubbleShell port) | Shell-level component ID generation for nested components would use prefix pattern |
| Mouse tracking (`tea.MouseModeCellMotion`) | `sugar-bits` + `candy-core` | `candy-core` would carry the mouse event constants (`MouseButtonLeft`, `MouseMotionMsg`, etc.) |
| `ZoneInfo.Pos()` relative coordinates | `sugar-bits` (cursor/selection tracking) | Relative mouse position within a focused region |

**Many-to-many mapping:**

- `candy-core` — Ports the BubbleTea `Model`/`Msg`/`Cmd` architecture; would be the natural host for mouse event handling and zone dispatch
- `sugar-bits` — Ports bubbletea components (list, spinner, etc.); would need zone integration for interactive child components
- `sugar-charts` — If it has interactive/clickable chart elements, would use zone-based hit testing
- A potential new `sugar-zone` leaf library could encapsulate the zone scanning/marking/bounds-checking as a standalone component, usable by any TUI component

## Analysis

**bubblezone** solves a real and non-obvious problem in the BubbleTea ecosystem: when building nested TUI applications with multiple interactive regions, determining which component received a mouse event requires manual coordinate math that quickly becomes unmanageable as component trees deepen. The library's core insight is elegant — wrap every interactive region in invisible ANSI escape sequence markers, scan the fully-rendered view at the root to record each region's screen coordinates, then simply ask "is this mouse event inside this named zone?" at event-handling time. This shifts the coordinate calculation burden from every component to a single scan pass and eliminates the need to thread a manager through the entire component tree (via the global singleton).

The implementation quality is high. The state-machine scanner is efficient (single pass, no allocations beyond the zone map), the atomic counters ensure lock-free ID generation, and the iteration-based invalidation scheme cleanly handles stale zones from previous renders without requiring explicit cleanup calls. The zero-width ANSI marker trick is the critical design decision — it means bubblezone works seamlessly with lipgloss's width calculations and doesn't alter the visual output, which would break terminal layout.

The library's main limitation is its collision model: simple AABB box testing. For buttons, list items, and dialog boxes this is perfectly adequate, but for organically-shaped UI elements (circles, triangles, irregular borders), users must manually pad zones to the bounding box, causing false-positive hits on corner regions. The library acknowledges this but doesn't propose a solution. Additionally, the v2 release notes' candid statement that bubblezone may not work with lipgloss v2's canvas/compositor features, combined with the author's stated plan to release a replacement library, suggests this package is in maintenance mode rather than active development — a consideration for projects choosing it as a dependency.

From a SugarCraft porting perspective, bubblezone maps most naturally to the event-dispatch layer that would live in `candy-core` (the BubbleTea port). The `Mark()`/`Scan()`/`Get()` API is straightforward to port to PHP, and the state-machine scanner pattern translates well. The main challenge would be PHP's lack of goroutines — the channel-based worker pattern for asynchronous zone storage would need reimplementing as ReactPHP-based async or a synchronous inline approach. The lipgloss v2 incompatibility warning is also instructive: SugarCraft should avoid tight coupling between the styling library and mouse-tracking zone logic, keeping them as separate, loosely-coupled components.
