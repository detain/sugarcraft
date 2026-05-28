# Second-Stage Ecosystem Intelligence Report: 76creates/stickers

## 1. Repository Overview

- **URL**: https://github.com/76creates/stickers
- **Stars**: ~394
- **Language**: Go
- **License**: MIT
- **Description**: TUI layout library providing CSS flexbox-inspired FlexBox and responsive Table components for Charmbracelet Bubbletea/lipgloss ecosystem
- **Ecosystem Position**: Upstream for SugarCraft's `sugar-bits` port
- **Maintenance Pattern**: Single maintainer (Dusan Gligoric / 76creates); sporadic commit activity with burst merged PRs

## 2. Existing SugarCraft Mapping

From `repo_map/76creates_stickers.md`:

| stickers (Go) | SugarCraft (PHP) | Status |
|---|---|---|
| `flexbox.FlexBox` | `SugarCraft\Bits\FlexBox` | Port planned |
| `flexbox.HorizontalFlexBox` | `SugarCraft\Bits\HorizontalFlexBox` | Port planned |
| `flexbox.Row` / `flexbox.Column` | `SugarCraft\Bits\Row` / `Column` | Port planned |
| `flexbox.Cell` | `SugarCraft\Bits\Cell` | Port planned |
| `flexbox/utils.go` ratio algorithms | Internal to `SugarCraft\Bits` | Core algorithm |
| `table.Table` | `SugarCraft\Bits\Table` | Port planned |
| `table/sortIndex` bubble sort | Replace with `usort` | Performance risk |
| `table/cursor.go` bitmask | `SugarCraft\Bits\TableCursorDirection` enum | Simple mapping |
| `Cell.SetContentGenerator` | `Cell::withContentGenerator(callable)` | Closure pattern |

## 3. Previously Identified Gaps

From `repo_map/76creates_stickers.md`:
- Bubble sort O(n²) for large tables
- No built-in key event handling (pure render component)
- Single-column filtering only
- No cell-level mouse handling
- No pagination API
- Recalculation flag footgun for threading
- Integer-only ratios cause off-by-one accumulation
- Lipgloss v2 API (charm.land) vs v0.x (github.com/charmbracelet)

## 4. High-Signal Open Issues

### Issue #11 — GetContentWidth ignores padding (OPEN)
- **Author**: jon4hz (Jan 2023)
- **Reactions**: 🚀 1
- **Signal**: Suggests API design problem — `GetContentWidth` does not account for cell padding via `GetHorizontalFrameSize()`. Maintainer questioned whether the function name is misleading rather than fixing it.
- **Status**: PR #16 (open since Jun 2024) proposes fix using `GetHorizontalFrameSize()` / `GetVerticalFrameSize()`
- **Maintainer position**: Unsure; invited reproducible example
- **DRY observation** (from PR #16 commenter): Identical `getContentHeight()`, `getContentWidth()`, `getExtraHeight()`, `getExtraWidth()` methods exist in `row.go`, `column.go`, `flexbox.go`, `horizontal_flexbox.go` — code duplication suggests need for base type refactor

**SugarCraft Risk**: **HIGH** — If PHP port replicates this padding-ignoring behavior, cell sizing will be wrong when styles have padding. The `getExtraWidth()` equivalent must account for frame size.

## 5. Important Closed Issues

### Issue #14 — SetMinHeight is ignored (CLOSED Nov 2023)
- **Author**: antonmedv
- **Resolution**: Closed — "now working as expected"
- **Signal**: Minimum dimension constraints had historical bugs; may have been fixed

### Issue #13 — A new version would be nice (CLOSED Aug 2023)
- **Author**: fabio42
- **Reactions**: None visible
- **Signal**: **Critical ecosystem complaint**: Latest tag v1.3.0 was old and did not contain the `table` package. Users couldn't import new features. Forces forking or using `@latest` which may be unstable.
- **Impact**: This is a recurring problem — users needed features from master but couldn't get them via semver. Maintainer acknowledged and eventually released v1.4+.

### Issue #17 — Feature: X axis scrollable table (CLOSED Jul 2025, OWN-IMPLEMENTING)
- **Author**: 76creates (maintainer self-filed)
- **Signal**: Maintainer explicitly acknowledged wide tables with many/hidden columns is a common use case. Proposed solution: max content length column sizing, `maxWidth`, cursor-based horizontal scrolling, column focus switching.
- **Roadmap indicator**: This is actively being planned — SugarCraft should implement X-axis scrolling in parallel or beforehand.

### Issue #19 — Randomly Column Order Changes (CLOSED Dec 2024)
- **Author**: csg33k
- **Problem**: In `HorizontalFlexBox`, repeated tab navigation causes rendered column order to shift randomly
- **Root cause**: Likely related to `GetColumn(ndx).GetCell(0).SetContent(content)` called in a loop while modifying the active column — potential race condition or iteration order issue
- **User workaround**: Recreating the entire FlexBox rather than mutating cells in-place
- **SugarCraft Risk**: **HIGH** — PHP equivalent code using `withContent()` or mutation patterns could exhibit same behavior

### Issue #4 — Multiline text in row (CLOSED Jul 2025)
- **Author**: Vinschni (Jul 2022)
- **Question**: How to make cell content span multiple lines?
- **Resolution**: Closed (years later) — no solution implemented
- **Signal**: This is a known limitation — FlexBox and Table assume single-line cell content. `ContentGenerator` can produce multiline output but the layout system doesn't account for it.

### Issue #8 — Flexbox does not render borders correctly (CLOSED Jan 2023)
- **Author**: Cro0ksy
- **Problem**: Right border not rendering; FlexBox takes 100% width regardless of content
- **Root cause**: Lipgloss border rendering requires precise width allocation — FlexBox's integer ratio calculations didn't leave space for borders
- **SugarCraft Risk**: **MEDIUM** — border rendering is a common pain point; SugarCraft should ensure ANSI SGR codes for borders (e.g., `\x1b[1m`) work with the ratio layout system

### Issue #22 — Examples panic with nil pointer dereference (CLOSED Oct 2025)
- **Author**: ancientcatz
- **Problem**: Examples crash if `SetContent` not called before `Render`
- **Signal**: API requires mandatory initialization step; panic vs. graceful no-op

### Issue #20 — Can not run example using Go 1.23 (CLOSED Jul 2025)
- **Author**: yunfan
- **Signal**: Compatibility issues with newer Go versions — possible reliance on internal behavior

### Issue #9 — Use variadic function to add cells (CLOSED Apr 2023)
- **Author**: jon4hz
- **Resolution**: Implemented in PR #10 — `AddCells(...*Cell)` replaced `AddCells([]*Cell)`
- **Signal**: API ergonomics improvement; variadic is preferred

## 6. Recurring Pain Points

1. **Missing versioning/releases**: Users stranded on old semver tags; force `@latest` workarounds
2. **Code duplication**: FlexBox and HorizontalFlexBox repeat identical methods; DRY violation flagged by community
3. **Padding ignored in measurements**: Issue #11 persists; the `getExtraWidth/Height` methods don't use lipgloss frame size methods
4. **Typo in public API**: `SetMinHeigth` (not `SetMinHeight`) — typo in exported identifier; fixed in PR #18 with deprecation wrapper
5. **Minimum dimension constraints**: Historical bugs (#14); recursive `calculateRatioWithMinimum` algorithm is fragile

## 7. Frequently Requested Features

1. **X-axis scrollable table** — Issue #17 (maintainer-owned, high priority)
2. **Live table updating** — PR #12 (merged) — `ClearRows()`, `GetOrder()`, `OrderByAsc/Desc` needed for streaming data
3. **Multi-column filtering** — Single-column only; multi-column is TODO
4. **HorizontalFlexBox** — Added in PR #10
5. **Multiline cell support** — Issue #4 (never implemented)
6. **Variadic cell addition** — PR #9 → PR #10
7. **Mouse/click handling in Table** — No mouse event handling exists

## 8. Important PRs

### PR #12 — Add methods to enable live updating of Tables (MERGED Oct 2024)
- **Contributors**: drmille2, spogatetz (asked for merge after 1.5yr)
- **Changes**:
  - `ClearRows()` — empty table for fresh data
  - `OrderByAsc(n)`, `OrderByDesc(n)` — directional sorting
  - `GetOrder()` — retrieve current sort state
- **Context**: Original submission Mar 2023; sat idle for 18 months before merge
- **Key lesson**: Maintainer's "I'll check it ASAP" followed by 18 months silence — suggests single-maintainer bottleneck

### PR #10 — Horizontally stacked flexbox and more (MERGED Apr 2023)
- **Contributors**: jon4hz
- **Changes**: Breaking refactor — package reorganization (`table/` separate from `flexbox/`), renaming (`FlexBoxRow` → `Row`), `HorizontalFlexBox` addition, variadic cells
- **Maintainer concern**: "Feels like `HorizontalFlexBox` is basically mirror of the `FlexBox`, this is not really ideal... it might be better to create base struct that `FlexBox` and `HorizontalFlexBox` would inherit"
- **Decision**: Merged as-is; refactor deferred to future PR

### PR #18 — Linter fixes (MERGED Nov 2024)
- **Contributors**: tvanriel, ccoVeille (reviewer)
- **Changes**: Typo fixes (`insipred` → `inspired`), `SetMinHeigth` deprecated wrapper for backwards compat
- **Pattern**: Contributor found typo in public comment `// FlexBox responsive box grid inspipred by CSS flexbox`

### PR #23 — Update lipgloss to charm.land/lipgloss/v2 (MERGED Apr 2026)
- **Contributor**: duckpie3
- **Changes**: Module path update only; no behavioral change
- **Signal**: lipgloss v2 moved to `charm.land` domain; library was still on v0.x import path

### PR #16 — Fix for issue #11 (OPEN)
- **Contributor**: Omnikron13
- **Changes**: Use `GetHorizontalFrameSize()` and `GetVerticalFrameSize()` for padding
- **Status**: Open since Jun 2024; maintainer unclear on desired behavior

## 9. Architectural Changes

### Package/Module Reorganization (v1.4 / Apr 2023)
- `flexbox/` and `table/` split into separate packages
- Users importing `github.com/76creates/stickers/table` needed new module path
- This caused Issue #13 — users on v1.3.0 couldn't access table package

### Base Type Refactoring (Deferred)
- Maintainer explicitly noted in PR #10 review: "Feels like `HorizontalFlexBox` is basically mirror of the `FlexBox`... it might be better to create base struct"
- **Status**: Never implemented; code duplication persists
- **SugarCraft opportunity**: Create shared base trait/class for FlexBox/HorizontalFlexBox to avoid duplication

### Style Inheritance Chain
- `FlexBox → Row → Cell` optional `StylePassing` with `style.Inherit(parent)`
- Duplicated across both FlexBox variants

## 10. Performance Discussions

1. **Bubble sort in Table ordering** — Original issue noted O(n²) worst case; no alternative implemented
2. **Render performance** — PR #5 (closed) addressed "improve performance for rendering" but details not surfaced
3. **Lazy recalculation flag** — Performance optimization; triggers only on `Render()` when dirty flag set
4. **ContentGenerator for dynamic sizing** — Per-cell callback avoids full re-render when only one cell changes

## 11. Extensibility Discussions

1. **No plugin/extension system** — Library is closed; consumers subclass or wrap
2. **Style overrides** — PR #7 added per-cell/row/box style override capability
3. **Custom content generators** — The `func(maxX, maxY int) string` pattern is user-extensible
4. **No interfaces for injection** — No abstractions for styling, measurement, or rendering backends

## 12. API/UX Complaints

1. **Misleading function names** — `GetContentWidth` ignores padding (Issue #11); maintainer acknowledged potential naming problem
2. **Mandatory initialization order** — Must call `SetContent` before `Render` or panic
3. **Inconsistent naming** — `SetMinHeigth` typo in public API
4. **No graceful degradation** — Nil content causes panic, not empty state

## 13. Migration Problems

1. **v1.3 → v1.4 break**: Package reorganization broke imports
2. **lipgloss v0.x → v2 migration**: PR #23 needed; library was on old import path
3. **Go version compatibility**: Issue #20 (Go 1.23) may have broken examples
4. **HorizontalFlexBox asymmetry**: API not unified with FlexBox; different cell orientation semantics

## 14. Clever Fixes & Workarounds

1. **Bubble sort returning index permutation**: `sortIndex` returns sorted indices, not sorted slice — useful for parallel reordering
2. **Bitmask cursor direction**: 2-bit `uint8` for `cursorDirection` — avoids full struct for simple state
3. **Copy accessors** (`GetRowCopy`, `GetCellCopy`): Read without triggering dirty flag
4. **ContentGenerator closure**: Per-cell adaptive content without full reinitialize

## 15. Community Workarounds

1. **Live table update pattern** (from PR #12):
   ```go
   m.table.ClearRows()
   var rows [][]string
   for _, m := range msg {
       rows = append(rows, toRow(&m))
   }
   m.table.AddRows(rows)
   // Re-apply sort order
   if m.orderPhase == 0 {
       m.table.OrderByAsc(m.orderColumn)
   } else {
       m.table.OrderByDesc(m.orderColumn)
   }
   ```

2. **Column order randomizing workaround** (Issue #19): Recreate entire FlexBox rather than mutate cells in place

3. **Deprecated wrapper pattern** (PR #18):
   ```go
   // Deprecated: use SetMinHeight
   func (r *Cell) SetMinHeigth(value int) *Cell {
       return r.SetMinHeight(value)
   }
   ```

## 16. Maintainer Guidance Patterns

1. **"Check it ASAP" followed by long silence** — Pattern in PR #12, Issue #13; single-maintainer bottleneck
2. **Defer refactoring to "future PR"** — DRY violation deferred repeatedly
3. **Accept PRs with acknowledged imperfections** — PR #10 merged despite maintainer noting code duplication
4. **Breaking changes with changelog** — v1.4.2 noted "BREAKING CHANGES" for typo fix
5. **Invite reproducible examples** — Issue #11 maintainer asked for working example before committing

## 17. Rejected Ideas Worth Revisiting

1. **Base struct for FlexBox/HorizontalFlexBox** — Maintainer suggested it but deferred; code duplication continues
2. **Multi-column filtering** — Explicitly marked TODO; single-column only
3. **Mouse handling in Table** — Never implemented; `MouseMsg` not handled
4. **Multiline cell support** — Issue #4 closed without solution

## 18. Problems Likely Relevant To SugarCraft

1. **Padding in dimension calculations** — PHP `Cell::getContentWidth()` must include padding frame
2. **Recalculation flag threading** — PHP immutable `with*()` pattern avoids this but must be implemented correctly
3. **Bubble sort O(n²)** — Replace with PHP `usort()` using proper comparison callback
4. **Minimum constraint algorithm** — The recursive `calculateRatioWithMinimum` is complex; PHP port needs careful testing
5. **ContentGenerator closure capture** — PHP closures must capture `$maxX`, `$maxY` by value for correct rendering
6. **Integer-only ratios** — PHP can use floats; need to decide if SugarCraft should use int-only or float

## 19. Features SugarCraft Should Consider

1. **X-axis horizontal scrolling** — Issue #17 roadmap; SugarCraft should implement proactively
2. **Live table updating API** — `ClearRows()`, sort state preservation (`GetOrder()`)
3. **Multi-column filtering** — `SetFilter(colIdx, value)` is single column only; need composable filters
4. **Mouse click handling in Table** — `MouseMsg` support for cell click to place cursor
5. **Graceful nil/no-content handling** — Render empty cell rather than panic
6. **Base class/trait for FlexBox/HorizontalFlexBox** — Avoid the DRY violation
7. **Deprecation wrapper pattern** — When fixing `SetMinHeight` typos, provide deprecated alias

## 20. Architectural Lessons

1. **Lazy recalculation is a footgun in concurrent code** — The `recalculateFlag` boolean is not thread-safe; PHP's immutable `with*()` pattern is architecturally superior
2. **Duplicated code between FlexBox and HorizontalFlexBox** — Maintainer acknowledged; never fixed; creates maintenance burden
3. **Separate packages for optional components** — Table in its own package is good; but semver tagging must follow
4. **Lipgloss v2 module path migration** — `charm.land/lipgloss/v2` vs `github.com/charmbracelet/lipgloss` — SugarCraft must decide on lipgloss v2 or v0.x

## 21. Defensive Design Lessons

1. **Never export typos** — `SetMinHeigth` persisted until external contributor found it; SugarCraft should lint for typos in public identifiers
2. **Deprecation before removal** — PR #18 pattern of `@deprecated` docblock + forwarding function
3. **Semver discipline** — Don't let latest tag get out of sync with master; users will depend on `@latest`
4. **Panic vs graceful degradation** — nil content causing panic (Issue #22) is a bad API contract; SugarCraft should render empty state
5. **Test for zero/empty inputs** — The `can not run the example using Go 1.23` issue suggests implicit assumptions about initialization order

## 22. Ecosystem Trends

1. **Bubbletea ecosystem moving to lipgloss v2** — PR #23 reflects module path change; v2 is the future
2. **Growing demand for horizontal scroll** — Issue #17 shows modern TUI apps need wide table support
3. **Live data streaming patterns** — PR #12 pattern shows tables used for real-time data display
4. **Single-maintainer bottleneck** — Long gaps between maintainer responses; ecosystem needs more distributed ownership
5. **Documentation-driven discovery** — Examples with nil content panicking (Issue #22) suggests docs/examples need to cover edge cases

## 23. Strategic Opportunities

1. **Superior semver discipline** — SugarCraft should maintain tags in sync with code; never leave users stranded on old versions
2. **Thread-safe immutable architecture** — PHP's value objects prevent the recalculation-flag threading issue entirely
3. **Proper sorting algorithm** — PHP's `usort()` with Timsort is O(n log n) vs bubble sort O(n²); SugarCraft wins on performance
4. **Comprehensive edge case handling** — nil content, empty tables, zero dimensions should all degrade gracefully
5. **Horizontal scroll as first-class feature** — Build X-axis scrolling into Table from the start rather than as an afterthought
6. **Multi-column filter composition** — Design filter chain as a composable pipeline

## 24. Cross-Ecosystem Pattern Matches

1. **CSS flexbox mental model** — Both Go stickers and SugarCraft sugar-bits target developers familiar with web flexbox; API familiarity is a selling point
2. **ContentGenerator closure pattern** — Mirrors React render props; powerful for dynamic content adaptation
3. **Copy accessors for read-only inspection** — Equivalent to PHP's `with*()` returning new instance; the read-only variant avoids dirty flag trigger

## 25. High ROI Recommendations

1. **Fix padding calculations immediately** — `Cell::getContentWidth()` must use `GetHorizontalFrameSize()`; this will cause visible layout bugs if wrong
2. **Implement immutable `with*()` API** — Avoid the recalculation flag entirely; every mutation returns a new instance
3. **Replace bubble sort with `usort()`** — Performance gain is immediate for large tables; O(n²) → O(n log n)
4. **Design horizontal scroll first-class** — Issue #17 roadmap indicates this is the next major feature; SugarCraft should implement before upstream if possible
5. **Provide graceful nil/empty handling** — No panics on uninitialized content; render empty cells instead
6. **Add deprecation wrappers for typos** — If `SetMinHeight` has a typo variant, provide deprecated wrapper like PR #18
7. **Document initialization contract** — Ensure examples show `SetContent` or equivalent before `Render` is called
8. **Run linting on public identifiers** — Prevent `SetMinHeigth` style typos from reaching public API

---

*Report compiled: Second-stage ecosystem intelligence from GitHub issues, PRs, and discussions for 76creates/stickers (2022-2026)*
