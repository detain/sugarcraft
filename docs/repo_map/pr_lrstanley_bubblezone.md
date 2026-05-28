# Second-Stage Ecosystem Intelligence Report: lrstanley/bubblezone

## 1. Repository Overview

- **URL:** https://github.com/lrstanley/bubblezone
- **Language:** Go
- **Stars:** 880+
- **License:** MIT
- **Current Version:** v2 (via `github.com/lrstanley/bubblezone/v2`)
- **Maintenance Status:** Active maintenance, v2 in progress; author plans eventual successor library
- **Ecosystem Position:** Core mouse-event infrastructure for the Charmbracelet/BubbleTea TUI ecosystem

**Summary Intelligence:**
The repository has low issue volume (4 open, 48 closed) indicating a mature, stable library. The overwhelming majority of closed "issues" are dependency update PRs from Renovate bot. The substantive issues cluster around: (1) v2 compatibility, (2) non-alt-screen coordinate handling, (3) mouse event deduplication during drag, and (4) debug tooling.

---

## 2. Existing SugarCraft Mapping

From `repo_map/lrstanley_bubblezone.md`:

| bubblezone concept | SugarCraft lib | Status |
|---|---|---|
| `zone.Mark(id, v)` | `sugar-bits` (focus/mouse zone tracking) | Partial |
| `zone.Get(id).InBounds(msg)` collision | `candy-core` (BubbleTea model port) | Partial |
| `zone.Scan()` ANSI marker stripping | `sugar-prompt` / lipgloss port | Not started |
| `zone.NewPrefix()` unique ID generation | `candy-shell` | Partial |
| Mouse tracking constants | `sugar-bits` + `candy-core` | Partial |
| `ZoneInfo.Pos()` relative coordinates | `sugar-bits` | Partial |

**Suggested new leaf:** `sugar-zone` — standalone zone scanning/marking/bounds-checking component.

---

## 3. Previously Identified Gaps

From `repo_map/lrstanley_bubblezone.md`:
- AABB collision only (no non-rectangular hit testing)
- Tight coupling to BubbleTea + lipgloss v1
- No native overlapping zone support
- Time-based iteration invalidation (nanosecond collision theoretically possible)
- Deprecated/in maintenance-mode risk

**New gaps identified from issue mining:**

1. **Non-alt-screen coordinate failure** — bubblezone calculates zone positions as if the terminal starts at row 0. Without `WithAltScreen`, applications may start rendering at arbitrary screen row offsets, causing zone hit detection to be completely wrong.

2. **Mouse event deduplication gap** — During drag operations, `MouseDown` events fire repeatedly as the mouse crosses cell boundaries. The library has no built-in mechanism to distinguish intentional clicks from drag artifacts.

3. **No development-time debugging** — No way to validate zone placement, detect incorrect usage of markers, or visualize zone boundaries during development.

4. **lipgloss v2 canvas incompatibility** — The v2 branch explicitly warns it may not work with lipgloss v2's canvas/compositor feature. This signals the zero-width ANSI marker approach becomes fragile when upstream changes rendering architecture.

---

## 4. High-Signal Open Issues

### Issue #11: "bug: Handle zones in non WithAltScreen" (1 comment, open)
**Problem:** When not using alt-screen mode, zone Y-positions are calculated as if content starts at top of terminal. Real applications may render starting at arbitrary vertical offsets.

**Root Cause:** bubblezone's scanner assumes the first character of scanned output corresponds to row 0. Without alt-screen, the terminal has existing content above the application output.

**Maintainer Response:**
> "From my initial testing, it looks as though mouse coordinates still return as if it was a full screen, however non-alt-screen programs scan start at any height from the top of the screen. Looking at the events that bubbletea produces, I don't see a way of being able to calculate the difference to be able to tell how far offset from the top of the screen it may be (it may not be something bubbletea can even determine). May reach out to folks on the bubbletea team..."

**Analysis for SugarCraft:** This is a fundamental architectural constraint. The zero-width ANSI marker approach relies on scanning the full view output and computing absolute coordinates. Any offset in the rendering surface breaks the coordinate system. **SugarCraft must design a zone system that is offset-agnostic** — either by requiring alt-screen mode exclusively or by providing an explicit offset parameter that gets added to all zone coordinates.

---

### Issue #7: "feature: debug mode" (0 comments, open)
**Requested Features:**
- Bounds checking validation
- Incorrect usage detection for markers
- Zone boundary visualization

**Analysis for SugarCraft:** A debug mode that validates zone integrity and can visualize zone regions would be high-value. In Go this is relatively easy to add because you can inject debug output into the view stream. In PHP, SugarCraft could implement this as a separate rendering mode that wraps zones in visible box-drawing characters or emits validation errors.

---

## 5. Important Closed Issues

### Issue #51: "breaking: bubbletea/lipgloss/bubbles v2" (6 comments, closed)
**What happened:** A major v2 branch was created to support BubbleTea v2 and lipgloss v2. The branch is at `v2.0.0-alpha.3`.

**Key observations from discussion:**
- Maintainer acknowledges v2 may break lipgloss v2's canvas/compositor features
- `Soft Serve v2` is already using this branch and patching it further
- One user reported `zone.Get` always returns `nil` when using the new canvas and layers — turned out to be a missed `zone.Scan` call
- Author states intent to eventually release a **new successor library** with "improved mouse event tracking"

**Strategic Signal:** The author is effectively deprecating bubblezone in favor of a future replacement library. The v2 work is maintenance, not feature development.

---

### Issue #10: "feature: workflow for de-duplicating mouse events when within a given zone" (1 comment, closed)
**Problem:** When holding a mouse button and dragging, BubbleTea emits multiple events for each cell the mouse crosses. Simple bounds checking leads to duplicate actions (e.g., firing a button action for each column/row crossed during drag).

**Screenshot evidence:** Shows a drag operation producing multiple `MouseDown` events across column/row boundaries.

**User-proposed solutions:**
1. Capture `mouseup` event instead of `mousedown` (mouseup only fires once)
2. Cache zone+action pairs and clear on `mouseup`

**Maintainer response:**
> "As part of bubbletea v2, this should be a non-issue, as users can simply watch for mouse up or mouse down events, which would only trigger once."

**Analysis for SugarCraft:** This is critical. In PHP/ReactPHP, the event model is different — there's no goroutine-based concurrent event stream. SugarCraft should provide explicit mouse event deduplication helpers, either by:
- Providing `MouseUpMsg` handling helpers that track which zones have pending down-events
- Offering a `ZoneManager::trackClick()` method that only fires once per zone until mouseup

This is a **direct feature opportunity** for SugarCraft to solve better than Go-based bubblezone (since PHP's single-threaded model makes this easier to reason about).

---

### Issue #50: "feature: Support V2 BETA" (1 comment, closed)
**Contributor:** alexanderbh offered a PR to a v2-exp branch. The only required changes were the new `MouseMsg` structure in BubbleTea v2.

**Analysis:** The v2 API changes were minimal — primarily the mouse message structure changed.

---

## 6. Recurring Pain Points

1. **AABB collision inadequacy for organic shapes** — User must manually pad zones to bounding box; corners outside the visual shape still register as hits. No shape mask support.

2. **Alt-screen dependency** — The library fundamentally requires alt-screen mode to work correctly. Non-alt-screen usage produces silently incorrect coordinates.

3. **lipgloss.Width() compatibility gotcha** — The library correctly handles `lipgloss.Width()` but NOT `len()`. Users who use `len()` for width calculations get incorrect behavior. This is documented but users still hit it.

4. **Nested/multi-manager complexity** — When using explicit (non-global) managers, the zone data is isolated to that manager instance. Cross-component communication becomes tricky.

5. **Zone prefix uniqueness** — Using `NewPrefix()` helps avoid collisions but users must still be disciplined about prefix assignment in deeply nested component trees.

---

## 7. Frequently Requested Features

1. **Debug/development mode** — Visualize zone boundaries, detect incorrect marker usage (issue #7)

2. **Mouse event deduplication** — Prevent duplicate actions during drag operations (issue #10)

3. **Non-alt-screen support** — Provide coordinate offset mechanism (issue #11)

4. **Shape masking** — Allow non-rectangular hit regions (only addressed via documentation/padding workarounds)

5. **V2 support** — Completed via v2 branch

6. **Overlapping zone handling** — `AnyInBounds` fires for all overlapping zones; no built-in prioritization/deduplication

---

## 8. Important PRs

**Open PRs (both are Renovate bot dependency updates):**
- #57: Update charm.land/bubbles/v2 (v2.0.0 → v2.1.0)
- #56: Update non-major dependencies

**Merged PRs (representative sample):**
- #55/54: Dependency bumps (most recent)
- #53: Go version update (1.24.2 → 1.26.0)
- #52: bubbletea v1.3.4 → v1.3.10

**Notable:** The library uses Renovate bot extensively for automated dependency updates. This indicates a mature CI/CD setup but also that the author focuses only on substantive changes.

---

## 9. Architectural Changes

### v2 Breaking Changes:
```
- charm.land/bubbletea/v2 (was github.com/charmbracelet/bubbletea)
- charm.land/lipgloss/v2 (was github.com/charmbracelet/lipgloss)
- charm.land/bubbles/v2
- Replaced github.com/muesli/ansi with github.com/mattn/go-runewidth
```

### The Great Relocation:
The v2 module path moved from `github.com/lrstanley/bubblezone` to `github.com/lrstanley/bubblezone/v2`. This is a Go module version suffix pattern for major version handling.

### Canvas/Compositor Warning:
> "bubblezone v2 may not work when using the lipgloss v2 canvas/compositor. lipgloss/bubbletea v2 have some more native features for mouse event tracking. That said, I do plan to release another library that covers advanced layouts/layering/etc with improved mouse event tracking (that's even better than bubblezone)."

**Analysis:** The author explicitly acknowledges bubblezone has reached its architectural ceiling. The successor library hints at a more layered, composable approach to mouse tracking — possibly one that doesn't rely on string-scanning ANSI markers.

---

## 10. Performance Discussions

The README explicitly claims "fast" as a feature, noting:
- State-machine single-pass scanning
- Atomic counters for lock-free ID generation
- Buffered channel decouples scanning from zone storage
- No allocations beyond zone map updates per render

**No substantive performance complaints in issues.** The maintainer's performance sensitivity is evident in the design choices. There are no issue reports of performance degradation at scale.

**SugarCraft implication:** The single-pass scanner pattern translates to PHP but the goroutine-based async worker pattern will need reimplementation (ReactPHP event loop or synchronous dispatch).

---

## 11. Extensibility Discussions

**No formal plugin system.** The library is deliberately minimal — 6 public manager methods.

**Extensibility mechanism:** The prefix system (`NewPrefix()`) allows users to build their own namespacing conventions on top of bubblezone.

**Overlapping zones limitation:** When zones visually overlap, `AnyInBounds` fires a message for each zone. There is no built-in z-ordering or conflict resolution. Users must implement their own deduplication.

---

## 12. API/UX Complaints

**No major complaints.** The library is praised for its simplicity.

**Documented gotcha (causes confusion):**
> "It doesn't impact width calculations when using lipgloss.Width() (if you're using len() it will)."

**v2 migration friction:** The module path change from `github.com/lrstanley/bubblezone` to `github.com/lrstanley/bubblezone/v2` is a breaking change requiring import path updates.

---

## 13. Migration Problems

**v2 migration pain points observed:**
1. Import path changes everywhere (Go module v2 convention)
2. `MouseMsg` structure changed in BubbleTea v2 (minimal change needed)
3. lipgloss v2 canvas/compositor incompatibility — some applications cannot upgrade
4. Third-party patches required (e.g., Soft Serve maintained their own v2-exp branch)

**For SugarCraft:** This demonstrates the cost of tight coupling to upstream. SugarCraft should keep zone logic strictly isolated from styling/rendering logic to avoid cascading breaking changes when upstream evolves.

---

## 14. Clever Fixes & Workarounds

### Workaround: Non-rectangular zone padding
Users surround organic shapes with padding to approximate the bounding box, accepting false-positive hits in corners.

### Workaround: Drag event deduplication
Users implement their own deduplication by tracking which zones they've already fired an action for, clearing on `mouseup`.

### Workaround: lipgloss.Width() instead of len()
The documentation explicitly directs users to use `lipgloss.Width()` rather than `len()` for width calculations when using bubblezone.

### Internal fix: Iteration-based invalidation
```go
// Uses time.Now().Nanosecond() as iteration marker
// Worker clears zones whose iteration differs from current scan's iteration
```
This is clever but has theoretical nanosecond collision risk in rapid re-renders.

---

## 15. Community Workarounds

1. **Self-managed zone prefixing** — Users create hierarchical ID schemes like `"parent/child/button"` to avoid collisions
2. **Explicit manager per subtree** — Creating separate Manager instances for different component subtrees to isolate zone data
3. **Manual z-order deduplication** — When overlapping zones fire, users sort by area or creation order and only process the top
4. **Delayed event processing** — In the Update loop, accumulate mouse events and only process on `MouseRelease`

---

## 16. Maintainer Guidance Patterns

**Pattern 1: Defer to upstream for fundamental issues**
On issue #11 (non-alt-screen): "May reach out to folks on the bubbletea team to see if there is some hidden [mechanism]."

**Pattern 2: Close feature requests with "fixed in v2"**
On issue #10 (deduplication): "As part of bubbletea v2, this should be a non-issue, as users can simply watch for mouse up or mouse down events."

**Pattern 3: Acknowledge architectural ceiling**
In README v2 changes section: "I do plan to release another library that covers advanced layouts/layering/etc with improved mouse event tracking."

**Pattern 4: Automated dependency management**
Heavy use of Renovate bot — maintainer focuses on substantive changes, not dependency bookkeeping.

---

## 17. Rejected Ideas Worth Revisiting

1. **Non-alt-screen support** — Currently no solution; upstream may need to provide offset information for this to work
2. **Shape masks for non-rectangular zones** — Only workarounds exist; no native support
3. **Built-in drag deduplication** — Maintainer deferred to upstream v2 mouse event changes
4. **Debug mode** — Still open, not yet implemented
5. **Overlapping zone conflict resolution** — No built-in mechanism, users must implement their own

---

## 18. Problems Likely Relevant To SugarCraft

### Problem A: Alt-screen dependency
**Severity:** High
**Description:** bubblezone fundamentally requires alt-screen mode. SugarCraft's TUI support should either:
- Require alt-screen mode for zone-based mouse tracking
- Provide an explicit offset parameter for non-alt-screen scenarios
- Document that mouse zones require alt-screen

### Problem B: AABB collision for organic shapes
**Severity:** Medium
**Description:** SugarCraft should consider whether to implement any shape-aware hit testing (mask-based) or just document the padding workaround clearly.

### Problem C: Mouse event deduplication
**Severity:** High
**Description:** SugarCraft MUST implement proper click/drag deduplication. The Go library deferred this to upstream v2 changes (mouseup/mousedown event separation). PHP's BubbleTea port should provide explicit helpers for this.

### Problem D: Tight coupling to styling library
**Severity:** High
**Description:** bubblezone's ANSI marker approach is coupled to lipgloss's width calculation behavior. If SugarCraft's styling library changes rendering approach (like lipgloss v2 canvas), zone scanning could break. **SugarCraft should strictly isolate zone logic from styling logic.**

### Problem E: Global singleton convenience vs explicit manager complexity
**Severity:** Medium
**Description:** The global singleton is convenient but creates testing difficulties. SugarCraft should provide both patterns but make the explicit manager the default for library code.

---

## 19. Features SugarCraft Should Consider

1. **Mouse event deduplication helpers** — `ZoneManager::trackClick()` or similar that only fires once per zone until mouseup

2. **Debug rendering mode** — A mode that wraps zones in visible box-drawing characters for development visualization

3. **Shape-agnostic hit testing (future)** — Support for mask-based hit regions beyond AABB

4. **Non-alt-screen offset parameter** — Allow users to specify vertical offset for zone coordinate calculations

5. **Overlapping zone conflict resolution** — Built-in z-ordering when zones overlap, rather than requiring user logic

6. **Zone lifecycle hooks** — Callbacks for when zones are entered/exited (hover detection)

7. **Mouse position relative to zone** — Already exists as `ZoneInfo.Pos()` — must preserve this in SugarCraft

8. **Zone ID uniqueness validation (debug mode)** — Detect duplicate IDs at scan time

---

## 20. Architectural Lessons

### Lesson 1: String-scanning markers are fragile
The zero-width ANSI marker approach works until upstream changes rendering architecture (as happened with lipgloss v2 canvas). **SugarCraft should consider whether markers are the right abstraction** — perhaps a declarative zone API that doesn't rely on string transformation would be more robust.

### Lesson 2: Single-pass scanning is the right default
bubblezone's state-machine scanner processes the entire view in one pass with no allocations beyond the zone map. This is the correct performance model. PHP port should replicate this.

### Lesson 3: Async worker pattern needs PHP equivalent
The Go implementation uses a goroutine + channel to decouple scanning from zone storage. In PHP/ReactPHP, this can be replicated as:
- Synchronous dispatch (simplest, no concurrency)
- ReactPHP event loop integration (proper async)
- A queue-based approach for batch updates

### Lesson 4: Global convenience vs testing clarity
The global singleton pattern is both praised (simple apps) and criticized (hard to test, hard to reason about in library code). SugarCraft should make the explicit manager the documented pattern and global the opt-in convenience.

### Lesson 5: Iteration invalidation is clever but risky
Using nanosecond time as an iteration token is elegant but theoretically collides. SugarCraft should use a monotonic counter (e.g., increment on each Scan call) instead.

---

## 21. Defensive Design Lessons

1. **Don't depend on upstream for fundamental behavior** — bubblezone had to wait for BubbleTea v2 to get mouseup/mousedown event separation. SugarCraft should implement deduplication helpers regardless of whether upstream provides it.

2. **Document explicit dependencies and requirements** — Alt-screen requirement is documented but users still hit issue #11. SugarCraft should make requirements (alt-screen, specific mouse mode) enforced at initialization time, not silently ignored.

3. **Provide migration paths** — The v2 module path change caused friction. SugarCraft should never change module paths; use compatibility layers instead.

4. **Design for testability** — The global singleton makes testing harder. SugarCraft should prioritize injectability and explicit manager patterns.

5. **Avoid silent failures** — When alt-screen is not enabled, bubblezone produces wrong coordinates silently. SugarCraft should emit warnings or errors when required setup is missing.

---

## 22. Ecosystem Trends

1. **Mouse event granularity increasing** — BubbleTea v2 separates mouseup/mousedown events, enabling better click/drag discrimination. Future versions will likely have even richer mouse event models.

2. **Canvas/compositor rendering emerging** — lipgloss v2 introduces a canvas/compositor model that changes how styling is applied. This breaks marker-based approaches and signals future rendering architectures will be more layered.

3. **Debug tooling as first-class feature** — Issue #7 (debug mode) has 0 comments but is a valid request. The ecosystem is moving toward better developer experience for TUI apps.

4. **Dependency automation maturity** — Heavy Renovate bot usage indicates the maintainer values automated dependency updates. This is a model SugarCraft should adopt.

5. **Successor library signaling** — Author's intent to replace bubblezone with a better mouse tracking library suggests the current ANSI-marker approach is considered a dead end.

---

## 23. Strategic Opportunities

### Opportunity 1: PHP-native deduplication helpers
The Go library couldn't solve mouse event deduplication without upstream changes. **SugarCraft can implement this entirely in userland** with a `ZoneTracker` class that wraps a zone manager and tracks in-progress clicks. This is a **competitive differentiation** — Go users still need to implement this themselves.

### Opportunity 2: Declarative zone API
Instead of string-transforming `Mark()` calls embedded in view strings, SugarCraft could use a declarative approach where zones are registered separately and the view is scanned automatically. This would be more robust and easier to test.

### Opportunity 3: Development-time visualization
A `RENDER_DEBUG=1` mode that renders zone boundaries with visible box-drawing characters. This is a straightforward feature that would significantly improve developer experience.

### Opportunity 4: Strict mode enforcement
A `STRICT=1` mode that validates:
- All zones have unique IDs
- Zone IDs use proper prefixing
- Mouse mode is correctly configured
- Alt-screen is enabled (when required)

This prevents silent failures and teaches users correct usage.

### Opportunity 5: ReactPHP async integration
Go's goroutine-based async is elegant. SugarCraft can replicate this with ReactPHP's event loop, providing a proper async zone update mechanism that doesn't block the main loop.

---

## 24. Cross-Ecosystem Pattern Matches

**vs. Charmbracelet/bubbletea issues:**
- Mouse event deduplication was a recurring theme in bubbletea itself
- The v2 transition affected multiple Charmbracelet libraries simultaneously (bubbletea, lipgloss, bubbles)

**vs. ratatui (Rust TUI):**
- ratatui's widget hit testing uses exactly the same AABB approach — same limitation for organic shapes
- ratatui has a `on_click` handler pattern that SugarCraft could replicate

**vs. textualize/textual:**
- textual has a more sophisticated widget hierarchy and event bubbling model
- textual's "mode" concept for mouse handling (click, drag, hover modes) is more advanced than bubblezone

**vs. chrisk rug-laying patterns:**
- Several TUI libraries use private-use ANSI sequences for markers
- The fragility of this approach is known; SugarCraft should consider alternatives

---

## 25. High ROI Recommendations

### For SugarCraft `candy-zone` (or `sugar-zone`) development:

1. **Implement `ZoneManager::trackClick()`** (HIGH ROI) — Provides built-in click deduplication without depending on upstream mouse event structure changes. Go bubblezone users still implement this manually.

2. **Use monotonic counter for iteration invalidation** (MEDIUM ROI) — Replace `time.Now().Nanosecond()` with atomic increment. Eliminates theoretical collision edge case.

3. **Provide `STRICT` mode** (MEDIUM ROI) — Validate zone ID uniqueness, mouse configuration, alt-screen requirement. Prevents silent failures.

4. **Design debug visualization layer** (MEDIUM ROI) — Zone boundary rendering as a separate pass. Can be implemented as a view decorator.

5. **Avoid string-marker approach if possible** (HIGH EFFORT, HIGH VALUE) — Consider a declarative zone registration API instead of ANSI marker injection. More robust against upstream rendering changes.

6. **Document alt-screen requirement prominently** (LOW COST) — Make enforcement automatic or error on missing config, not silently producing wrong coordinates.

7. **Adopt Renovate bot for dependency management** (LOW COST) — The maintainer's heavy use of automation enables focusing on substantive work.

---

## Appendix: Issue/PR Quick Reference

| # | Title | Type | Signal | Key Insight |
|---|---|---|---|---|
| 51 | breaking: bubbletea/lipgloss/bubbles v2 | PR | 6 comments | v2 transition; author plans successor library |
| 11 | bug: Handle zones in non WithAltScreen | Bug | 1 comment | Alt-screen dependency is fundamental |
| 10 | feature: de-duplicating mouse events | Feature | 1 comment | Drag causes duplicate events; fixed in v2 |
| 7 | feature: debug mode | Feature | 0 comments | Still open; no debug tooling exists |
| 50 | feature: Support V2 BETA | Feature | 1 comment | Minimal changes needed for v2 |
| 57 | update charm.land/bubbles/v2 | Deps | 2 comments | Automated dependency update |
| 56 | update non-major dependencies | Deps | 3 comments | Automated dependency update |

---

*Report generated from mining GitHub Issues, Pull Requests, and README documentation.*
*First-passe analysis: `repo_map/lrstanley_bubblezone.md`*
