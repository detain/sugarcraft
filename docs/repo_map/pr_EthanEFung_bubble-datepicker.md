# Second-Stage Ecosystem Intelligence: EthanEFung/bubble-datepicker

## 1. Repository Overview

| Attribute | Value |
|---|---|
| **URL** | https://github.com/EthanEFung/bubble-datepicker |
| **Language** | Go |
| **Stars** | 44 |
| **Forks** | 6 |
| **License** | MIT |
| **Status** | Active development (~Feb–Apr 2026) |
| **Ecosystem** | Charmbracelet/Bubbletea TUI framework |
| **Description** | jQuery Datepicker-inspired interactive datepicker bubble component for Bubbletea applications |

**Summary**: A small but active community-driven Go library providing datepicker functionality for the Bubbletea TUI framework. Despite low star count (~44), the repository shows meaningful community engagement with 4 open PRs, 3 open issues, and active contributor participation. The project is in a transitional phase — addressing upstream Bubbletea v2 compatibility while adding requested features.

---

## 2. Existing SugarCraft Mapping

From `repo_map/EthanEFung_bubble-datepicker.md`:

| bubble-datepicker | SugarCraft Lib | Notes |
|---|---|---|
| Datepicker component | `sugar-bits` (foundation components) | TUI "bubble" component like textinput, list, viewport |
| Calendar grid rendering | `sugar-bits` `View()` rendering | Snapshot testing pattern applies |
| Focus state machine (HeaderMonth/Year/Calendar) | `sugar-bits` focus handling | Elm-style Model/Update/View |
| `KeyMap` + key bindings | `candy-core` key handling | Same pattern as Charmbracelet's `bubbles/key` |
| `Styles` with lipgloss | `sugar-bits` styling | Lipgloss used in both ecosystems |
| Two-way data binding example | `sugar-bits` component integration | Compositing bubbles |

**Classification**: Not a direct port target — this is a user-contributed Bubbletea component, not upstream Charmbracelet. SugarCraft ports from Charmbracelet directly. This repo is a **reference implementation** for TUI component patterns.

---

## 3. Previously Identified Gaps

From `repo_map/EthanEFung_bubble-datepicker.md`:

- No programmatic date limit (min/max date constraints)
- No mouse interaction support (keyboard-only)
- No built-in localization/i18n for month/day names
- `FocusNone` doesn't prevent time mutations via navigation keys
- No date range support (select start/end dates)
- Single-file implementation (~360 lines) — no internal package organization
- Generated `focus_string.go` file committed (should be gitignored)
- Limited view customization (no custom day formatter, fixed week headers)
- Uses `time.Time` for state which is mutable

---

## 4. High-Signal Open Issues

### Issue #8: Support V2 of BubbleTea (Feb 24, 2026)
- **Author**: EthanEFung (owner)
- **Type**: Infrastructure/Compatibility
- **Signal**: Owner-initiated — indicates active maintenance commitment
- **Content**: Request to review and update project to support Bubbletea v2.0.0
- **Context**: Bubbletea v2 introduced breaking changes; this is a blocking concern for the project's continued viability
- **Related PR**: #10 (community PR addressing this exact issue)
- **Strategic Insight for SugarCraft**: The v2 migration pain point demonstrates that framework-dependent components must track upstream breaking changes. SugarCraft's `sugar-calendar` (if it exists) should have explicit version-locking strategy.

### Issue #7: Configurable First Day of Week (Feb 17, 2026)
- **Author**: EthanEFung (owner)
- **Type**: Feature Request / i18n
- **Signal**: Owner-authored, indicating this is considered valid and likely to be implemented
- **Content**: Inspired by jQuery UI Datepicker's `firstDay` option. The calendar grid is hardcoded to start on Sunday; proposal adds `FirstDay time.Weekday` field to Model
- **Acceptance criteria**: Non-breaking change (zero value = Sunday preserves default), rotates header row, realigns grid layout
- **Strategic Insight**: This is a pure i18n concern. Many locales require Monday-start weeks. SugarCraft should implement this as a first-class configurable option, not an afterthought.
- **Direct Risk to SugarCraft**: HIGH — `sugar-calendar` will face the same i18n pressure

### Issue #4: Adding Specific Date/Time Ranges (Jan 27, 2026)
- **Author**: jacobdanielrose (community)
- **Type**: Feature Request / UX Enhancement
- **Signal**: +1 reaction, explicit real-world use case (age verification for service eligibility)
- **Content**: Request for min/max date constraints to prevent selection of dates outside acceptable range (e.g., blocking dates before `time.Now()` or requiring 18+ years in the past)
- **Workaround in place**: User notes they have "a small workaround in my project" but want it built-in
- **Suggested visual treatment**: Strikethrough or disabled styling for out-of-range dates
- **Related PRs**: #5 (date range support), #6 (key binding help + date range combined)
- **Direct Risk to SugarCraft**: HIGH — date range constraints are fundamental to calendar UX

---

## 5. Important Closed Issues

### Issue #1: Add a GIF Example to README (Closed May 14, 2024)
- **Author**: airtonix
- **Type**: Documentation/UX
- **Outcome**: Closed (completed)
- **Signal**: Community-requested documentation improvement
- **Context**: Request to add animated GIF demonstrating the component in action to the README
- **Insight**: Visual demos are essential for TUI component adoption — the first thing users want to see is what it looks like

---

## 6. Recurring Pain Points

### 6.1 i18n / Localization Gap
The hardcoded "Su Mo Tu We Th Fr Sa" week header appears in multiple contexts as a pain point:
- Issue #7 explicitly cites jQuery UI Datepicker as the reference for `firstDay` configuration
- This is a fundamental locale mismatch for ~60% of the world's users (Europe, Latin America, etc.)
- **Pattern**: Single-file implementation with no locale abstraction

### 6.2 Date Range / Boundary Constraints
Multiple users independently arrive at the same requirement:
- Issue #4: Need to block dates before `time.Now()` for age-restricted services
- PR #5: Full date range support implementation with navigation limits
- PR #6: Also includes date range support + key binding descriptions
- **Pattern**: Consumers keep hitting the same wall — no way to constrain the selectable date range

### 6.3 Framework Version Compatibility
- Issue #8: Bubbletea v2 breaking changes require code migration
- PR #10: Community contribution for v2 support
- **Pattern**: Small ecosystem components are fragile to upstream breaking changes

### 6.4 Focus State Machine Inconsistency
- `FocusNone` doesn't actually prevent time mutations — only blocks focus zone switching
- Reported in initial analysis, not formally filed as issue, but visible in the code behavior
- **Pattern**: Incomplete abstraction — the focus/blur contract is not fully honored

---

## 7. Frequently Requested Features

### 7.1 Date Range Constraints (HIGH demand)
- **PRs**: #5, #6 (both open, both implement this)
- **Description**: Constrain selectable dates to a range with navigation limits and visual disabled styling
- **Implementation approach**: `NewWithRange(time.Time, time.Time, time.Time)` factory, `StartDate`/`EndDate` fields
- **UX**: Navigation at boundaries is a no-op; out-of-range dates render with `DisabledText` style

### 7.2 Configurable First Day of Week (HIGH demand)
- **Issue**: #7 (owner-authored)
- **Description**: Add `FirstDay time.Weekday` field to rotate week header and realign grid
- **Reference**: jQuery UI Datepicker `firstDay` option
- **No breaking change**: Zero value of `time.Weekday` is `time.Sunday`, preserving existing behavior

### 7.3 Highlighted/Disabled Dates (MODERATE demand)
- **PR**: #9 (open, community)
- **Description**: Add `Highlight` struct to manage date-specific styles, render custom styles for highlighted dates
- **Purpose**: Mark holidays, deadlines, special events visually
- **Implementation**: `NewWithHighlight`, nil-safe map lookup

### 7.4 Key Binding Help Descriptions (LOW-MODERATE demand)
- **PR**: #6 (merged with #5)
- **Description**: `key.WithHelp("↑/k", "previous week/year")` style descriptions exported via `DefaultKeyMap()`
- **Purpose**: Make keyboard shortcuts discoverable to users
- **Owner feedback**: "I would probably categorize this as necessary part of minDate/maxDate"

### 7.5 Bubbletea v2 Compatibility (INFRASTRUCTURE)
- **Issue**: #8
- **PR**: #10
- **Description**: Update dependencies and refactor for Bubbletea v2 breaking changes
- **Signal**: Critical for project survival

---

## 8. Important PRs

### PR #10: Fix: Update dependencies to version 2 and refactor code for compatibility (Apr 22, 2026)
- **Author**: MeLlamoOmar
- **Status**: Open
- **Commits**: 1
- **Signal**: Community stepping up to solve critical compatibility issue
- **Content**: Updates project for Bubble Tea v2 compatibility
- **Relevance**: Demonstrates that single-maintainer projects rely on community for critical upgrades

### PR #9: feat(datepicker): support highlighted dates in calendar view (Apr 10, 2026)
- **Author**: andmart
- **Status**: Open
- **Signal**: Feature enhancement driven by community need
- **Content**: Introduces `Highlight` struct, `NewWithHighlight` factory, custom styles for specific dates
- **Safety note**: "ensure safe handling of nil maps in highlight lookup"
- **Relevance**: SugarCraft should implement similar extensibility for special date rendering

### PR #6: small key binding help descriptions (Feb 11, 2026)
- **Author**: jacobdanielrose
- **Status**: Open (superseded by combined PR #6 which contains both key binding help AND date range)
- **Commits**: 3
- **Key Discussion**: Maintainer prefers squash-merge but acknowledges this makes feature delineation harder
- **Notable Architecture Debate**: Maintainer argues range checking should happen in private `update` functions, not time setters, to preserve the "previous valid time" for clamping decisions
- **Community workaround**: Separate PR for keymap-only changes

### PR #5: Add date range support to datepicker with navigation limits (Feb 2, 2026)
- **Author**: jacobdanielrose
- **Status**: Open
- **Signal**: Active development, community contributor maintaining feature
- **Content**: `NewWithRange(now, now.AddDate(0, 0, -7), now.AddDate(0, 0, 7))`, `DisabledText` styling for out-of-range dates
- **VHS demo**: Contributor created `.tape` file and GIF demonstrating the feature
- **Key insight**: Empty `time.Time{}` supported as one-sided limit (e.g., only min date, no max)

---

## 9. Architectural Changes

### 9.1 Factory Pattern Evolution
**Initial pattern**: `New(time.Time) Model`
**Emerging pattern**: `NewWithRange(time.Time, time.Time, time.Time) Model` and `NewWithHighlight(...)`
**Implication**: SugarCraft should design factory methods with composable options from the start, not additive factories

### 9.2 Time Mutation Architecture
**Current issue**: Time setters (`LastWeek()`, `Yesterday()`, etc.) mutate `m.Time` directly
**Proposed improvement**: Return `(Model, Cmd)` from private update functions, use `InvalidDateNavigationMsg` for boundary feedback
**Debate**: Should range checking happen at setter level or update-function level?
- Maintainer view: At update function level to avoid mutating then clamping (avoids losing previous valid state)
- Contributor view: At setter level allows reusable clamp functions and toggle between clamp/stay behaviors

### 9.3 Style Extension Pattern
**PR #9 approach**: Add `Highlight` struct, inject via `NewWithHighlight`, render via custom style lookup
**Alternative**: Could use a callback/visitor pattern for maximum flexibility
**SugarCraft approach**: Favor composition over inheritance; `Highlight` struct with style map is pragmatic

### 9.4 KeyBinding with Help Text
**Emerging standard**: `key.NewBinding(key.WithKeys("up", "k"), key.WithHelp("↑/k", "previous week/year"))`
**Pattern source**: Charmbracelet conventions
**SugarCraft alignment**: Already follows same pattern in `candy-core`

---

## 10. Performance Discussions

**Limited explicit discussion** — the component is lightweight and performance has not been raised as an issue.

**Implicit performance considerations**:
- Calendar grid algorithm walks backward from first of month to find anchor Sunday, walks forward to first Sunday of next month
- `lipgloss.Style.Copy().Inherit()` used for style derivation — potential allocation overhead
- **For SugarCraft**: The grid algorithm should be memoized where possible; lipgloss style caching is already handled by lipgloss itself

---

## 11. Extensibility Discussions

### 11.1 Highlighted Dates (PR #9)
- `Highlight` struct injected at construction time
- Map lookup for date-specific styles
- Nil-safe handling for empty highlight map
- **Lesson**: Inject extensibility at construction, not runtime

### 11.2 Date Range Constraints
- Two-sided or one-sided (empty `time.Time{}` for unbounded)
- Navigation at boundaries is no-op, not wrap-around
- Visual distinction via `DisabledText` style
- **Lesson**: SugarCraft should provide both navigation blocking AND visual distinction

### 11.3 Extensibility Not Implemented
- No plugin/hook system
- No callback for date selection events
- No programmatic day cell rendering customization
- **Gap**: The component is only as extensible as its fields allow

---

## 12. API/UX Complaints

### 12.1 Missing i18n
- Hardcoded English weekday abbreviations "Su Mo Tu..."
- No way to customize month names
- No locale-aware formatting
- **Complaint severity**: HIGH for non-US users

### 12.2 FocusNone Semantics Confusion
- `Blur()` sets `Focused = FocusNone` but doesn't prevent time mutations
- Users expecting focus to block all interaction are surprised
- **Complaint severity**: MEDIUM — documentation issue more than code bug

### 12.3 Single-file Implementation
- ~360 lines in one file makes internal organization unclear
- No package hierarchy for styles, keys, models
- **Complaint severity**: MEDIUM — scales poorly for feature additions

---

## 13. Migration Problems

### 13.1 Bubbletea v2 Breaking Changes
- **Status**: Active (Issue #8, PR #10)
- **Nature**: Unknown specifics without PR #10 code review
- **Scope**: Likely affects `tea.Model` interface compatibility, possibly msg types
- **Workaround**: Community stepped in with PR #10

### 13.2 No Version Pinning
- `go.mod` likely allows any version of `bubbletea`
- No CI enforcing compatibility
- **Risk**: Future upstream breaking changes will silently break consumers

---

## 14. Clever Fixes & Workarounds

### 14.1 One-Sided Date Range
**Trick**: Use empty `time.Time{}` as "no limit" on one side
```go
dp := datepicker.NewWithRange(now, now.AddDate(0, 0, -7), time.Time{})
```
**Cleverness**: Zero value of `time.Time` is the zero clock (Jan 1, year 1) — naturally "before everything" for date comparisons
**SugarCraft lesson**: Use zero values as the "unset" sentinel; avoids need for `*time.Time` pointers

### 14.2 Date-Only Comparison Semantics
**Trick**: Normalize comparisons to date-only (strip time components) before range checking
**Purpose**: `time.Now().AddDate(0, 0, -7)` at 3pm should compare equal to midnight boundary
**Implementation**: Compare `t.Year()`, `t.Month()`, `t.Day()` not full `time.Time`

### 14.3 Preserving Previous State for Clamping
**Pattern from PR #6 review**:
```go
func (m *Model) updateUp() {
    curr := m.Time  // preserve before mutation
    // ... mutate ...
    if m.MinDate != nil && m.Time.Before(*m.MinDate) {
        m.Time = curr  // restore, don't clamp
    }
}
```
**Insight**: Distinguish between "clamp to boundary" vs "stay at previous valid date" — the UX difference matters

---

## 15. Community Workarounds

### 15.1 Date Range Workaround (Issue #4 author)
- User has "a small workaround in my project" to block dates before `time.Now()`
- No details on implementation, but it's a consumer-side fix
- **Signal**: Built-in feature was urgently needed enough to build a workaround

### 15.2 Two-Way data binding via tea.Model composition
- README example shows integration with `textinput` bubble
- Pattern: Wrap datepicker in parent Model, delegate `Update()` and `View()`
- **Workaround quality**: Clean, idiomatic Bubbletea — not a hack

---

## 16. Maintainer Guidance Patterns

### 16.1 Squash-Merge Preference with Caveats
- Maintainer prefers squash-merge to keep commit history clean
- But acknowledges this makes it "hard to delineate between features if multiple features are added"
- **Guidance**: Maintainer explicitly asks contributors to separate features into distinct PRs when possible
- **SugarCraft parallel**: SugarCraft's PR workflow encourages bundling 2-4 related items per PR — similar philosophy

### 16.2 Architecture Feedback Style
- Maintainer provides philosophical guidance ("I prefer this function to be further up in the callstack")
- Explains reasoning (philosophically, handling range should happen within private `update` functions)
- But acknowledges practicality argument ("an argument can be made that given the structure of the time setters...")
- **Pattern**: Collaborative rather than dictatorial; explains the "why" behind preferences

### 16.3 Feature Categorization
- Key binding help text categorized as "necessary part of minDate/maxDate" — not optional
- **Implication**: SugarCraft should consider help text / discoverability features as first-class, not afterthought

---

## 17. Rejected Ideas Worth Revisiting

**None formally rejected** — all PRs remain open (no explicit rejections visible).

**Implicit deferrals**:
- Mouse interaction support — never mentioned, likely out of scope for v1
- Date range picker (start AND end date selection) — range PRs only implement one-way constraint, not dual-selection
- Localization system — Issue #7 is owner-authored but not yet implemented, effectively deferred

---

## 18. Problems Likely Relevant to SugarCraft

### 18.1 Date Range Constraints
**Direct risk**: HIGH — any calendar component will face this pressure
**Specific scenarios**:
- Age verification (must be 18+ years in the past)
- Booking systems (future dates only, or within N days)
- Financial systems (cutoff dates, grace periods)
**SugarCraft strategy**: Implement `MinDate`/`MaxDate` at the Model level with clear visual distinction for disabled dates

### 18.2 i18n / First Day of Week
**Direct risk**: HIGH — hardcoded Sunday-start affects global usability
**Specific gaps**:
- Week header rotation
- Grid anchor day
- Calendar view layout
**SugarCraft strategy**: `FirstDayOfWeek` as a `time.Weekday` field with default `time.Sunday` preserves backward compatibility

### 18.3 Framework Version Fragility
**Direct risk**: MEDIUM — if SugarCraft depends on upstream TUI framework (ReactPHP-based TUI), similar compatibility issues arise
**Specific concern**: When upstream makes breaking changes, ecosystem components break
**SugarCraft strategy**: Version pinning with CI regression suite; avoid depending on latest minor versions

### 18.4 Focus State Machine
**Direct risk**: MEDIUM — SugarCraft's `sugar-bits` focus handling must be consistent
**Specific issue**: `FocusNone` semantics must fully block interaction, not just focus zone switching
**SugarCraft strategy**: Document focus contract explicitly; blur = fully inactive, no side effects possible

### 18.5 Single-File Implementation Scaling
**Direct risk**: LOW for SugarCraft (PSR-4 organized libs), but worth noting
**Lesson**: Single-file components become unmaintainable as features accumulate
**SugarCraft strategy**: Multi-file package organization from day one

---

## 19. Features SugarCraft Should Consider

### 19.1 Date Range Constraints (Priority: CRITICAL)
Implement as first-class feature:
- `MinDate` and `MaxDate` fields on Model
- Navigation at boundary = no-op (not wrap)
- Out-of-range dates render with `DisabledStyle` (not selectable)
- One-sided bounds via zero `time.Time{}` sentinel
- Include unit tests for boundary behavior

### 19.2 Configurable First Day of Week (Priority: HIGH)
Implement as:
- `FirstDay time.Weekday` field on Model
- Zero value = `time.Sunday` (backward compatible)
- Header row rotates based on `FirstDay`
- Grid anchor day adjusts accordingly

### 19.3 Highlighted Dates (Priority: MEDIUM)
Implement as:
- `Highlight` map from date to style
- `NewWithHighlights(time.Time, map[time.Time]Style)` factory
- Nil-safe lookup (empty map = no highlights)
- PR #9 pattern is pragmatic and should be mirrored

### 19.4 Key Binding Help (Priority: MEDIUM)
Implement as:
- `DefaultKeyMap()` with `key.WithHelp()` descriptions
- Make key bindings exportable and customizable
- Help text visible to users in the UI

### 19.5 Visual Date States (Priority: HIGH)
Implement distinct styles for:
- Default (normal)
- Selected (current selection)
- Focused (keyboard focus)
- Disabled (out of range or highlighted)
- Today (current date marker)

### 19.6 Framework Version Compatibility (Priority: CRITICAL)
- Pin to known-working upstream version
- CI matrix testing against multiple upstream versions
- Document upgrade path for breaking changes

---

## 20. Architectural Lessons

### 20.1 Factory Method Variety
Start with `New(time.Time)` but design for extension:
- `NewWithRange(start, end, selected)` — range constraints
- `NewWithHighlights(time, map)` — date-specific styling
- Avoids god-constructor with 10 parameters

### 20.2 Time Mutation Discipline
Keep time mutations centralized:
- All mutations go through `setTimeWithinRange()` helper
- Compare date-only (strip time component) for range checks
- Return previous state if mutation would violate constraints
- Never mutate then clamp — preserve rollback state

### 20.3 Focus State Machine Design
Define clear semantics:
- `FocusNone` = fully inactive, NO side effects on keys
- `Blur()` must truly blur, not just change focus state
- Document the contract explicitly in doc comments

### 20.4 Style Derivation Safety
Use lipgloss copy/inherit pattern correctly:
- `style.Copy().Inherit(original)` for derived styles
- Never mutate original style objects
- Nil-safe map lookups for optional styling

### 20.5 Elm Architecture Adherence
The `Model.Update(msg)` returning `(Model, Cmd)` pattern:
- Private update functions should mirror the public interface where possible
- Return `(Model, Cmd)` from private `update*` methods to enable composition
- `InvalidDateNavigationMsg` as a signal message for boundary violations

---

## 21. Defensive Design Lessons

### 21.1 Validate Time Mutations
Never allow invalid state:
```go
func (m *Model) setTimeWithinRange(next time.Time) {
    if m.MinDate != nil && next.Before(*m.MinDate) {
        return // no-op, preserve current
    }
    if m.MaxDate != nil && next.After(*m.MaxDate) {
        return // no-op, preserve current
    }
    m.Time = next
}
```

### 21.2 Nil-Safe Map Lookups
Always handle nil maps:
```go
if m.Highlights != nil {
    if style, ok := m.Highlights[key]; ok {
        return style
    }
}
```

### 21.3 Date-Only Comparisons
Strip time component for calendar logic:
```go
func isSameDay(a, b time.Time) bool {
    return a.Year() == b.Year() && a.Month() == b.Month() && a.Day() == b.Day()
}
```

### 21.4 One-Sided Bounds
Support unbounded on one side:
```go
func (m *Model) isDisabled(t time.Time) bool {
    if m.MinDate != nil && !t.After(*m.MinDate) { return true }
    if m.MaxDate != nil && !t.Before(*m.MaxDate) { return true }
    return false
}
```

### 21.5 Preserve Previous State for UX
For date range navigation:
```go
func (m *Model) updateUp() (tea.Model, tea.Cmd) {
    prev := m.Time
    // ... attempt mutation ...
    if prev.Equal(m.Time) {
        return m, InvalidDateNavigationMsg{}
    }
    return m, nil
}
```

---

## 22. Ecosystem Trends

### 22.1 TUI Component Ecosystem Growth
- Charmbracelet ecosystem expanding with user-contributed components
- bubble-datepicker fills a gap left by upstream (no official datepicker in bubbles)
- **Trend**: Community fills gaps that upstream doesn't address

### 22.2 jQuery UI as Reference Model
- bubble-datepicker explicitly inspired by jQuery UI Datepicker
- jQuery UI patterns are familiar to web developers transitioning to TUI
- **SugarCraft lesson**: Reference familiar UI paradigms when bridging to TUI

### 22.3 Keyboard-First Design Persistence
- No mouse support requested or implemented
- All navigation via keys (arrows, tab, vim hjkl)
- **Trend**: TUI components remain keyboard-centric by default

### 22.4 Visual Demo as Documentation
- README includes GIF of component in action
- Contributors create VHS tapes and render GIFs
- **Trend**: Animated demos are standard for TUI component documentation

### 22.5 Framework Version Drift Risk
- Small components break when upstream makes breaking changes
- No formal versioning contract with upstream
- **Trend**: Ecosystem fragmentation when frameworks make breaking changes

---

## 23. Strategic Opportunities

### 23.1 SugarCraft Date Component Gap
**Opportunity**: SugarCraft currently has `sugar-calendar` — if it doesn't implement date range constraints and i18n, it will face the same feature requests
**Action**: Audit `sugar-calendar` for:
- Min/Max date constraints
- First day of week configuration
- Highlighted dates support
- Disabled date styling

### 23.2 Comprehensive TUI Component Suite
**Opportunity**: The bubble-datepicker shows that Charmbracelet's upstream has gaps. SugarCraft could be the definitive source for well-maintained, full-featured TUI components
**Action**: Consider whether `sugar-calendar` should absorb datepicker functionality or remain separate

### 23.3 i18n-First Design
**Opportunity**: Most TUI components hardcode English strings. SugarCraft could establish i18n as a first-class concern from day one
**Action**: Every TUI component should:
- Accept locale configuration
- Use string lookup for all user-facing text
- Provide `en` locale as default

### 23.4 Framework Agnosticism
**Opportunity**: bubble-datepicker is tightly coupled to Bubbletea. SugarCraft (PHP-based) faces different constraints
**Action**: Design SugarCraft components to be framework-agnostic where possible, using interfaces/abstractions for TUI framework coupling

---

## 24. Cross-Ecosystem Pattern Matches

### 24.1 jQuery UI Datepicker Parallels
The bubble-datepicker directly mirrors jQuery UI Datepicker:
- `firstDay` → proposed `FirstDay` field
- `minDate`/`maxDate` → implemented as `StartDate`/`EndDate` range
- `beforeShowDay` callback → `Highlight` struct pattern
- **Lesson**: jQuery UI Datepicker is the canonical reference for datepicker UX; SugarCraft should study its API surface

### 24.2 React datepicker Ecosystem Parallels
Modern React datepicker libraries (react-datepicker, Mantine DatePicker) all share:
- Range selection with two calendars
- Disable dates array/map
- Week start configuration
- Locale/i18n support
- **Lesson**: These patterns are proven; SugarCraft should implement the consensus features

### 24.3 bubble-datepicker's Novel Contributions
Not found in standard datepickers:
- Inline key help descriptions via `key.WithHelp()`
- TUI-specific navigation limits (no wrap, visual disabled state)
- Integration with Bubbletea's focus state machine
- **Lesson**: TUI-specific UX patterns must be invented/adjusted for terminal context

---

## 25. High ROI Recommendations

### Recommendation 1: Implement Date Range Constraints Immediately
**ROI**: HIGH — eliminates the #1 user pain point
**Approach**:
- Add `MinDate` and `MaxDate` nullable fields
- Centralize all mutations through range-validated helper
- Render out-of-range dates with `DisabledStyle`
- Support one-sided bounds with `null`/`zero` sentinel

### Recommendation 2: Implement First Day of Week Configuration
**ROI**: HIGH — enables global adoption beyond US market
**Approach**:
- Add `FirstDay time.Weekday` field
- Default to `time.Sunday` (backward compatible)
- Rotate header row, adjust grid anchor algorithm

### Recommendation 3: Add Key Binding Help Text
**ROI**: MEDIUM — improves discoverability, low implementation cost
**Approach**:
- Export `DefaultKeyMap()` with `key.WithHelp()` descriptions
- Make customizable via exported `KeyMap` field
- Include in default view rendering

### Recommendation 4: Plan for Framework Version Changes
**ROI**: HIGH — prevents future breakages
**Approach**:
- Pin upstream dependency versions
- Add CI regression tests
- Document upgrade path for breaking changes
- Consider abstracting TUI framework behind interface

### Recommendation 5: Audit SugarCraft Calendar for Feature Parity
**ROI**: HIGH — prevents the same issues from appearing in SugarCraft
**Approach**:
- Check `sugar-calendar` for range constraints, i18n support, highlighted dates
- If missing, prioritize adding them before users request them
- Reference bubble-datepicker's implementation for patterns

### Recommendation 6: Support Multiple Factory Methods
**ROI**: MEDIUM — improves API ergonomics
**Approach**:
- `New(time.Time)` — basic
- `NewWithRange(time.Time, *time.Time, *time.Time)` — with bounds
- `NewWithHighlights(time.Time, map[time.Time]Style)` — with highlights
- Avoids constructor explosion while keeping APIs clean

### Recommendation 7: Document Focus Contract Explicitly
**ROI**: MEDIUM — prevents user confusion
**Approach**:
- `FocusNone` fully deactivates component, no key handling
- `Blur()` truly blurs, not just changes state
- Document in doc comments what each focus level allows

---

## Summary

The EthanEFung/bubble-datepicker repository provides a valuable window into real-world TUI component design pressures. Despite its small size (~44 stars), it exhibits the same feature demands that any calendar/datepicker component will face: date range constraints, i18n support, visual date states, and keyboard discoverability.

**Key signals for SugarCraft**:
1. **Date range constraints are the #1 requested feature** — implement them first
2. **i18n (first day of week) is fundamental** — not optional for global adoption
3. **Factory method flexibility** beats god constructors
4. **Focus contracts must be explicit** — `FocusNone` means truly none
5. **Framework version fragility** is a real risk — pin versions and CI test

This repository is a reference implementation, not a SugarCraft port target. Its value lies in the patterns, workarounds, and feature requests that reveal what users actually need from a TUI datepicker component.
