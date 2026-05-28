# Second-Stage Ecosystem Intelligence Report: charmbracelet/bubbles

## Repository Overview

- **URL**: https://github.com/charmbracelet/bubbles
- **Language**: Go
- **Stars**: ~8,000+ (MIT licensed)
- **Current Version**: v2.1.0 (released March 2026)
- **Description**: A collection of 14 pre-built, composable TUI components for Bubble Tea applications. Used in production by Charm tools like Crush, Glow, and 25,000+ open-source applications.
- **Ecosystem**: Part of the Charm ecosystem (Bubble Tea, Lip Gloss, Bubbles). Bubbles v2 requires Bubble Tea v2 and Lip Gloss v2 as companion upgrades.

---

## Existing SugarCraft Mapping

Per `repo_map/charmbracelet_bubbles.md`:

| Bubbles Component | SugarCraft Component | Status |
|---|---|---|
| spinner | `SugarCraft\Forms\Spinner\Spinner` | 🟢 aliased from candy-forms |
| textinput | `SugarCraft\Forms\TextInput\TextInput` | 🟢 aliased from candy-forms |
| textarea | `SugarCraft\Forms\TextArea\TextArea` | 🟡 in progress |
| table | `SugarCraft\Table\Table` | 🟢 separate sugar-table repo |
| progress | `SugarCraft\Forms\Progress\Progress` | 🟢 |
| paginator | `SugarCraft\Forms\Paginator\Paginator` | 🟢 |
| viewport | `SugarCraft\Forms\Viewport\Viewport` | 🟢 |
| list | `SugarCraft\Forms\ItemList\ItemList` | 🟡 (fuzzy filtering gaps) |
| filepicker | `SugarCraft\Forms\FilePicker\FilePicker` | 🟢 |
| timer | `SugarCraft\Forms\Timer\Timer` | 🟢 |
| stopwatch | `SugarCraft\Forms\Stopwatch\Stopwatch` | 🟢 |
| help | `SugarCraft\Forms\Help\Help` | 🟢 |
| key | `SugarCraft\Forms\Key\Key` | 🟢 |
| cursor | `SugarCraft\Forms\Cursor\Cursor` | 🟢 |

**Note**: `charmbracelet/huh` (form framework built on bubbles) maps to `sugar-prompt` (MATCHUPS.md row 34).

---

## Previously Identified Gaps

From `repo_map/charmbracelet_bubbles.md`:

1. **Fuzzy filtering** - List/ItemList doesn't yet match `sahilm/fuzzy` quality (ranked matches with character indices)
2. **TextArea soft-wrap line tracking** - Complex `LineInfo` structs with Width, Height, CharWidth, ColumnOffset, RowOffset, CharOffset need equivalent accounting for double-width Unicode runes
3. **Virtual cursor system** - TextInput/TextArea virtual vs real cursor handling needs careful porting
4. **TextArea case transforms** (`alt+u/l/c`) and **character transpose** (`ctrl+t`) not yet in PHP
5. **Progress bar half-block color blending** - Uses Unicode `▌` with separate foreground/background for 2x color resolution
6. **Help component's KeyMap interface introspection** - Clean polymorphic pattern for PHP
7. **Go-specific concurrency** - Atomic IDs, context-based timers

---

## High-Signal Open Issues

### Issue #950: textinput v2: 0 Width breaks placeholders
**Severity**: Bug | **Reactions**: Multiple PRs filed
- When creating a textinput model with Width = 0 & a placeholder, only the first character of the placeholder is shown
- "if there is no width, the placeholder can be any length" - maintainer expectation
- Multiple PRs (#951, #956) filed to fix this

**Direct Risk to SugarCraft**: YES - TextInput placeholder rendering with 0 width is likely affected in PHP port. The truncation logic when width is 0 needs careful handling.

### Issue #810: list paginator rendering performance
**Severity**: Performance | **Reactions**: ❤️ 1 + community workaround
- Slow rendering on list with several thousand items (tested on HP laptop, Intel Celeron)
- Noticeable scroll delay with ~8000 results
- **Community fix**: Replaced string concatenation with `strings.Builder` in `dotsView()` - PR #882 merged
- Root cause: O(n) string concatenation in paginator

**Direct Risk to SugarCraft**: YES - ItemList paginator with large datasets likely has similar issue. String concatenation in loops is the anti-pattern.

### Issue #737: List: Unnecessary ellipsis on full width title
**Severity**: Bug | **Reactions**: Active discussion
- When list title occupies full width, unnecessary ellipsis is appended
- Root cause: Unconditional appending of two spaces for status message
- Proposed fix: Only append space when statusMessage is non-empty

**Direct Risk to SugarCraft**: YES - List title truncation logic with status messages likely has same issue.

### Issue #461: help rendering issues when text doesn't fit width
**Severity**: Bug | **Reactions**: Active
- Help bubble shows strange behavior when text doesn't fit available width
- Short help: word wrap before ellipsis displayed
- Full help: invisible tail gets wrapped, columns have inconsistent heights

**Direct Risk to SugarCraft**: YES - Help component width-constrained rendering likely affected.

### Issue #461: help rendering issues when text doesn't fit width
**Severity**: Bug | **Reactions**: Active
- Help bubble shows strange behavior when text doesn't fit available width
- Short help: word wrap before ellipsis displayed
- Full help: invisible tail gets wrapped, columns have inconsistent heights

**Direct Risk to SugarCraft**: YES - Help component width-constrained rendering likely affected.

### Issue #571: Lack of Style Options for Spaces Between Key and Description in Short Help View
**Severity**: Enhancement | **Reactions**: Bug label
- Space between key and description does not have dedicated style options
- When custom background color applied, spaces remain unstyled
- Suggested: Add `WhitespaceStyle` or use existing `SeparatorStyle`

**Direct Risk to SugarCraft**: YES - Help styling of whitespace between keys/descriptions needs configurable styling.

### Issue #887: Cursor gets stuck when navigating up/down in textarea
**Severity**: Bug | **Reactions**: Active
- Cursor can get stuck and stop responding to up/down navigation
- Root cause: `offset >= nli.CharWidth-1` exits cursor positioning loop one iteration too early
- Secondary issue: When line content equals textarea width, `wrap()` incorrectly creates extra empty line

**Direct Risk to SugarCraft**: YES - TextArea cursor navigation across wrapped lines has same boundary condition issues.

### Issue #925: Proposal: Terminal charts library (charm-charts)
**Severity**: Feature Request | **Reactions**: Community interest
- Building dashboards/data visualizations in Bubble Tea requires external libraries
- Proposed: `charm-charts` with Sparkline, Bar Chart, Progress Bar components
- Working POC exists at https://github.com/junhinhow/charm-charts

**Strategic Opportunity for SugarCraft**: SugarCraft could implement chart components (sparklines, bar charts) ahead of upstream, creating differentiation.

### Issue #1652 (bubbletea): textarea infinite loop when pressing alt+left on empty input
**Severity**: Critical Bug | **Reactions**: Well documented
- `wordLeft()` has unconditional `for {}` loop with only exit being finding non-space rune
- When textarea empty, loop spins forever, freezing UI
- Proposed fix: Add boundary check at top of `wordLeft()` mirroring `doWordRight()`

**Direct Risk to SugarCraft**: YES - TextArea word navigation on empty input likely has same infinite loop risk. Boundary check before iteration is essential.

---

## Important Closed Issues

### Issue #566: textarea sometimes freezes when calling SetValue() then View()
**Status**: Closed | **Key Finding**: Resolved by modifying styles
- Replacing `lipgloss.AdaptiveColor` with explicit color fixed the issue
- Shows that adaptive color detection can cause hangs in certain contexts

**Lesson**: SugarCraft should handle theme detection carefully, avoid blocking operations.

### Issue #193: Textarea bubble hanging on initial View() call
**Status**: Closed (completed) | **Key Finding**: Fixed in v2
- Textarea hanging on initial View() call
- Related to lipgloss/bubbletea interaction
- Fixed in v2 with pointer receiver changes

### Issue #263: textarea/textinput: control characters in input cause misbehavior
**Status**: Closed | **Key Finding**: Bracketed paste support needed
- Control characters in clipboard input cause corruption
- Fixed via PR #214: bracketed paste in textinput + textarea

**Lesson for SugarCraft**: Clipboard input must be sanitized before processing. Control characters can corrupt internal state.

### Issue #276: Table efficiency - O(n) rendering on moveup/movedown
**Status**: Closed | **Key Finding**: Major performance issue
- With 4.5k rows, MoveDown() takes 0.5 seconds (vs 0.002s for <1k rows)
- Root cause: `UpdateViewPort()` iterates and copies entire table
- Solution: Only render visible rows, lazy-load content

**Lesson for SugarCraft**: Table rendering must be virtualized - only render visible rows. Full table rendering on each navigation is O(n).

### Issue #301: Performance of textarea
**Status**: Closed (completed) | **Key Finding**: Major performance issues fixed
- Textarea taking lots of CPU resources, causing lag
- Solution: Rope implementation to reduce memory usage and speed up
- PR #427: Implemented memoization to cache wrapping computation results
- Benchmark improvement: 42s → 3s for pasting 1000 characters (14 lines at 72 chars wide)

**Lesson for SugarCraft**: TextArea wrapping computation is expensive and must be memoized/cached. Every keystroke should not trigger full rewrap.

### Issue #395: Partial block characters for progress bar
**Status**: Open (enhancement) | **Key Finding**: Partial blocks requested
- Progress bar uses whole blocks instead of partial blocks (▏, ▎, ▍, ▌, ▋, etc.)
- Allows smoother progress for short bars (width 10)
- Suggested: `progress.UsePartialBlocks()` opt-in option

**Lesson for SugarCraft**: Consider partial block support for progress bar smoothness at small widths.

---

## Recurring Pain Points

### 1. TextInput/TextArea Width/Cursor Interactions
**Pattern**: Multiple issues (#779, #816, #845, #950) about placeholder and cursor rendering with zero/constrained width
**Root Cause**: Width assumptions in rendering logic don't account for edge cases
**Defensive Lesson**: Test components with width=0, width=1, width=exact-content-width edge cases

### 2. Style Application Timing (#566, #633)
**Pattern**: Lipgloss style interactions causing freezes or unexpected behavior
**Root Cause**: `AdaptiveColor` detection or style computation during View() causes issues
**Defensive Lesson**: Never compute styles during View() - styles should be pre-computed and cached

### 3. Textarea Paste Handling (#468, #468)
**Pattern**: CharLimit bypassed when pasting large strings
**Root Cause**: Incorrect slice calculation when trimming pasted content
**Defensive Lesson**: Verify boundary conditions in paste handling - test with content exactly at limit, content 1 byte over limit, empty content

### 4. Escape Sequence Handling in Table (#502, #603)
**Pattern**: Table cells with ANSI codes or emojis get truncated incorrectly
**Root Cause**: Truncation functions don't account for invisible escape sequences
**Defensive Lesson**: All text truncation must strip escape sequences first, then restore them after truncation

### 5. List Filtering State Corruption (#632, #638, #810)
**Pattern**: RemoveItem when filtering removes wrong item, ghost items after SetItems
**Root Cause**: Index confusion between filtered and unfiltered item arrays
**Defensive Lesson**: Filtered/unfiltered index mapping must be explicit and tested. Never assume filtered index == unfiltered index

### 6. Viewport Soft-Wrap Performance (#823)
**Pattern**: Viewport with softwrap had 35% less CPU, 38% less memory, 38% less allocations after refactor
**Root Cause**: Inefficient allocations, re-invocations of heavy methods in multiple places, re-processing data multiple times
**Defensive Lesson**: Soft-wrap computation is expensive - cache aggressively, invalidate only when content changes

---

## Frequently Requested Features

### 1. Search/Filter Within Viewport (#157)
**Request**: Search a document for keywords, similar to vim `/` or list filtering
**Status**: v2 feature (PR #697 for highlighting)
**Trend**: Users want inline search, not just filtering lists but searching content

### 2. Dynamic Height for TextArea (#910)
**Request**: Textarea automatically grows/shrinks to fit content, clamped between MinHeight and MaxHeight
**Status**: Implemented in v2.1.0 (March 2026)
**Lesson for SugarCraft**: Dynamic height is a highly desired feature - implement with proper clamping

### 3. Password Input Terminal Notification (#865)
**Request**: Tell terminal when password is being requested (for focus stealing prevention, lock icon)
**Status**: Open enhancement
**Trend**: Security-conscious UI indicators becoming expected

### 4. History for TextInput (#631)
**Request**: Store and recover input history, up/down to navigate
**Status**: Declined as out-of-scope, but interface suggested for external implementation
**Community Alternative**: Use suggestions feature as history
**Lesson for SugarCraft**: Consider whether history should be internal or interface-based for external storage (SQLite, network)

### 5. Real Cursor Support (#718, #794)
**Request**: Use actual terminal cursor instead of virtual cursor
**Status**: Implemented in v2 (opt-in via `SetVirtualCursor(false)`)
**Lesson for SugarCraft**: Real cursor support is important for accessibility - implement as opt-in

### 6. Table Resizing (#894)
**Request**: Table that adjusts to terminal width/height like lipgloss table
**Status**: Open enhancement
**Trend**: Responsive tables that adapt to window size

### 7. Horizontal Scrolling in Viewport (#761)
**Request**: Horizontal mouse wheel scrolling, left/right arrow keys
**Status**: Implemented in v2
**Lesson for SugarCraft**: Bidirectional scrolling is expected for Viewport

### 8. Progress Bar Multiple Color Stops (#838)
**Request**: Support multiple gradient stops for progress bar
**Status**: Implemented in v2
**Lesson for SugarCraft**: Multi-stop gradients add visual richness

### 9. Whitespace Styling in Help (#571, #572)
**Request**: Style the spaces between key and description in help view
**Status**: Open enhancement
**Trend**: Fine-grained styling control over whitespace

---

## Important PRs

### PR #823: Viewport refactor - softwrap, performance improvement
**Impact**: 35% less CPU, 38% less memory, 38% less allocations
**Changes**:
- Inefficient allocations removed
- Heavy methods not re-invoked in multiple places
- Data not re-processed multiple times
- Soft-wrap handling improved
**Lesson**: Performance optimization in hot paths (Viewport.View()) has outsized impact

### PR #882: Improve list paginator rendering performance
**Impact**: Fixes 8000+ item list lag
**Changes**: String concatenation → strings.Builder
**Lesson**: String concatenation in loops is always the first thing to fix

### PR #214: Bracketed paste in textinput + textarea
**Impact**: Fixes clipboard corruption issues
**Changes**: Proper handling of paste sequences, sanitization of control characters
**Lesson**: Clipboard input is untrusted - always sanitize

### PR #638: Fix list RemoveItem correctly when filtering
**Impact**: Fixes wrong item removal when list is filtered
**Changes**: Use filteredItems index to look up correct unfiltered index before removal
**Lesson**: Filter state requires index translation - never use filtered index directly on unfiltered array

### PR #427: Implement memoization for textarea rendering
**Impact**: 42s → 3s for pasting 1000 chars
**Changes**: Cache wrapping computation results
**Lesson**: Text wrapping is expensive - memoize aggressively

### PR #657: textarea(v2) highlight, formatter, suggestions
**Impact**: Major textarea API expansion
**Features**: Real cursor support, formatter callbacks, suggestion system
**Lesson**: Textarea API expanded significantly in v2 - consider which features to port

### PR #794: (v2) fix(textinput,textarea) various improvements
**Impact**: Cursor handling fixes
**Changes**:
- Styles use getters/setters
- Real-virtual cursor management via getters/setters
- Virtual cursors enabled by default in New()

### PR #910: feat(textarea) dynamic height
**Impact**: Textarea auto-grows to content
**Features**:
- `DynamicHeight bool`
- `MinHeight int`
- `MaxContentHeight int` (visual lines limit)

---

## Architectural Changes in v2

### Breaking Changes Summary
1. **Import path**: `github.com/charmbracelet/bubbles` → `charm.land/bubbles/v2`
2. **tea.KeyMsg** → **tea.KeyPressMsg**
3. **Exported Width/Height fields** → **Getter/Setter methods** (SetWidth/Width, SetHeight/Height)
4. **DefaultKeyMap variables** → **DefaultKeyMap() functions**
5. **AdaptiveColor removed** → **LightDark with isDark bool** parameter
6. **HighPerformanceRendering removed** (Bubble Tea v2 handles this)
7. **runeutil and memoization packages** → internal (not importable)

### Per-Component v2 Changes

**TextInput**:
- `Model.VirtualCursor` bool for real cursor support
- `Model.Cursor` is now `func() *tea.Cursor` for real cursor
- Styles reorganized: `FocusedStyle/BlurredStyle` → `Styles.Focused/Styles.Blurred`
- `Model.SetCursor` renamed to `Model.SetCursorColumn`

**TextArea**:
- Real cursor support (opt-in)
- `Model.Cursor` returns `*tea.Cursor` function
- `Model.VirtualCursor` bool
- `CursorStyle` field added
- `SetCursorColumn` method (renamed from `SetCursor`)
- New: `Column()`, `ScrollYOffset()`, `ScrollPosition()`, `MoveToBeginning()`, `MoveToEnd()`
- `PageUp`/`PageDown` key bindings

**Viewport**:
- `New(...Option)` instead of `New(width, height int)`
- Horizontal scrolling via mouse wheel and arrow keys
- Soft-wrap improved
- `HighPerformanceRendering` removed

**List**:
- `Styles.FilterPrompt` and `Styles.FilterCursor` consolidated into `Styles.Filter`
- `GlobalIndex` helper added
- `DefaultKeyMap` variable → function

**Progress**:
- `Model.EmptyColor`/`Model.FullColor` changed from `string` to `image/color.Color`
- `WithGradient`/`WithScaledGradient` → `WithColors(...color.Color)`
- Multiple color stops support

**Table**:
- Uses `ansi.Truncate` instead of `runewidth.Truncate`
- Fixed critical out-of-bounds cursor bug

**Paginator**:
- `DefaultKeyMap` variable → function
- Removed `UsePgUpPgDownKeys`, `UseLeftRightKeys` (customize KeyMap directly)

---

## Performance Discussions

### Viewport Soft-wrap Performance (#823)
**Finding**: 35% less CPU, 38% less memory, 38% less allocations
**Root causes fixed**:
- Inefficient allocations
- Re-invocations of heavy methods in multiple places
- Re-processing and re-stitching data multiple times
**Lesson**: Profile before optimizing - the actual hot spots were different than expected

### Textarea Memoization (#427)
**Finding**: Pasting 1000 chars at 72 char width: 42s → 3s (93% improvement)
**Approach**: Cache wrapping computation results
**Lesson**: Text wrapping is extremely expensive - cache at all costs

### Table O(n) Rendering (#276)
**Finding**: 4.5k rows causes 0.5s delay per move
**Root cause**: `UpdateViewPort()` copies entire table for rendering
**Solution**: Only render visible rows (virtualization)
**Lesson**: Tables must be virtualized - never render off-screen content

### List Paginator String Concatenation (#810, #882)
**Finding**: 8000 items causes lag
**Root cause**: String concatenation in loop for pagination dots
**Solution**: `strings.Builder`
**Lesson**: Any string building in a loop must use builder/sprintf avoided

### Viewport Lazy Loading (#694)
**Finding**: 10k lines of string doesn't lag, but combined with data retrieval does
**Lesson**: Rendering is rarely the bottleneck - data fetching is

---

## Extensibility Discussions

### History Interface Suggestion (#631)
Maintainer suggested interface for external history implementation:
```go
type HistoryManager interface {
    Save(string) error
    Get() (string, error)
    Next()
    Previous()
}
```
**Rationale**: Allow large history storage via SQLite, network fetch, etc.
**SugarCraft Opportunity**: Implement similar interface for SugarCraft\Forms\TextInput history

### Suggestions as History Alternative
Maintainer noted: "text input already have the suggestions feature, which can be used as a history"
**Trade-off**: Simplicity vs flexibility
**SugarCraft Decision**: Implement history as separate feature, not conflated with suggestions

### Viewport Content via SetContentLines
Allows setting "virtual lines" containing `\n` for fine control
**Lesson**: Extensibility through alternative constructors/methods

---

## API/UX Complaints

### Issue #779/#816/#845: Placeholder only shows first character
**Complaint**: Since v0.21.0, placeholder shows only first letter
**Workaround**: Set width explicitly (e.g., `SetWidth(20)`)
**Root cause**: Truncation logic at width=0

### Issue #566: SetValue then View freezes
**Complaint**: Textarea hangs when calling SetValue() then View()
**Root cause**: `AdaptiveColor` style detection
**Workaround**: Replace AdaptiveColor with explicit color

### Issue #633: Styled text in textarea breaks
**Complaint**: Setting styled text (with lipgloss.Render) in textarea shows escaped sequences
**Response**: "textarea doesn't support stylized text - use viewport to display styled text, textarea for plain text"
**Lesson**: SugarCraft should not attempt styled text in TextArea - viewport for display, textarea for input

### Issue #483: Prompt wrapping not supported
**Complaint**: Long prompts get truncated instead of wrapped
**Workaround**: Fork textinput
**Status**: Not addressed in v2

---

## Migration Problems

### V1 to V2 Breaking Changes
**Impact**: Significant migration effort required
**Key Pain Points**:
1. Import path changes everywhere
2. Field access → method calls (Width/Height)
3. AdaptiveColor removal breaks auto-dark/light detection
4. KeyMsg → KeyPressMsg
5. Deprecated symbols removed (no backward compatibility)

### Upgrade Guide
Maintainers provided: https://github.com/charmbracelet/bubbles/blob/main/UPGRADE_GUIDE_V2.md
**Quality**: Very comprehensive, includes LLM-assisted migration guidance
**Lesson**: Major version bumps need extensive upgrade documentation

---

## Clever Fixes & Workarounds

### Partial Block Progress (Issue #395)
User created Gist showing partial block implementation:
```go
// Calculate remainder after filling width with whole blocks
// Then get remainder percent from partial blocks
```
**SugarCraft Opportunity**: Implement partial blocks as option

### Empty Textarea Word Navigation Fix (#1652)
```go
func (m *Model) wordLeft() {
    if m.row == 0 && m.col == 0 {
        return  // Boundary check prevents infinite loop
    }
    // ... rest unchanged
}
```
**Lesson**: Always check boundaries before entering navigation loops

### SetItems Ghost Items Fix
**Problem**: After SetItems, ghost items from previous state visible
**Solution**: Call `ResetFilter()` after `SetItems()`
**Lesson**: When replacing all items, reset filter state

### Table Truncation with Escape Sequences (#603)
```go
// Extract escape sequences, truncate plain text, reapply sequences
func truncate(s string, width int) string {
    sequences := extractEscapeSequences(s)
    plain := stripEscapeSequences(s)
    truncated := runewidth.Truncate(plain, width, "...")
    return applyEscapeSequences(truncated, sequences)
}
```
**Lesson**: Always handle escape sequences separately from content

---

## Community Workarounds

### Multi-line TextInput Hack (#63)
User encoded Enter as `¬` character, then decoded on render:
```go
case tea.KeyEnter:
    o := m.textInput.Value()
    m.textInput.SetValue(o + encodedEnter)
// Then decode:
decoded := strings.Replace(m.textInput.View(), encodedEnter, "\n", -1)
```
**Lesson**: Users will hack around missing features - multi-line textinput was highly requested

### Status Message Spacing Fix (#737)
```go
// Before:
view += "  " + m.statusMessage
view = ansi.Truncate(view, m.width-spinnerWidth, ellipsis)

// After:
if m.statusMessage != "" {
    view += " " + m.statusMessage
}
view = ansi.Truncate(view, m.width-spinnerWidth, ellipsis)
```
**Lesson**: Unconditional whitespace addition causes truncation issues

### Performance via View Caching
User cached entire view, only called Textarea.View() when focused:
```go
// Use lipgloss.PlaceOverlay to composite
```
**Lesson**: Partial UI refresh is a valid optimization pattern

### Brute Force Textarea Performance
User set CharLimit to prevent performance issues:
**Lesson**: Constraints can help performance by limiting state space

---

## Maintainer Guidance Patterns

### "Out of Scope" Pattern
History feature (#631): "this feature seems out of scope to be handled by the bubbles library"
**Action**: Suggest interface for external implementation
**Rationale**: Keep library small, allow extension

### "Exists Elsewhere" Pattern
Truncate with escape sequences (#603): "functionality already exists in x/ansi"
**Action**: Direct users to companion libraries
**Lesson**: Bubbles doesn't try to be everything - references x/ansi, reflow, etc.

### "UseViewportForDisplay" Pattern
Styled text in textarea (#633): "textarea doesn't support stylized text... use a viewport to display the text, then use textarea to edit the plain text"
**Lesson**: Separate display from input - viewport for styled, textarea for plain

### "Version Bump Fixes" Pattern
Windows diacritics (#460): "This is now fixed in Bubble Tea on master"
**Lesson**: Sometimes fix is in dependency, not in bubbles itself

### "Merged In Future Version" Pattern
Search functionality (#157): "this feature will be introduced when v2 is officially released"
**Lesson**: Feature flags via milestones, not blocking

---

## Rejected Ideas Worth Revisiting

### 1. Styled Text in TextArea
**Rejected**: "textarea doesn't support stylized text"
**Revisit**: Could SugarCraft support inline styling via ANSI codes stored in value?
**Risk**: High - escape sequence handling adds complexity

### 2. Internal History
**Rejected**: "this feature seems out of scope"
**Revisit**: SugarCraft could implement as interface-based system
**Benefit**: Allows SQLite/network history backend

### 3. vim-mode for Textarea
**Mentioned**: PR #225 for overwrite mode
**Status**: Closed, not merged, suggested as "opt-in or part of separate more fully-featured textarea"
**Revisit**: Could SugarCraft implement vim-mode as extension?

### 4. Multi-line Prompt for TextInput
**Rejected**: Consider removing prompt from textinput in v2, leave as exercise for user
**Status**: Not implemented
**Revisit**: SugarCraft could implement wrapped prompts as feature

---

## Problems Likely Relevant To SugarCraft

### 1. TextInput/TextArea Width Edge Cases
**Why**: PHP port likely has same truncation logic issues
**Action**: Test with width=0, width=1, width=exact-content

### 2. TextArea Word Navigation Infinite Loop
**Why**: Unconditional loop exists in PHP port
**Action**: Add boundary checks before all navigation loops

### 3. List Filter Index Confusion
**Why**: filtered/unfiltered index mapping is complex in any language
**Action**: Ensure RemoveItem, Select, GlobalIndex all translate indices correctly

### 4. Escape Sequence Truncation
**Why**: PHP lacks native runewidth - must handle ANSI codes manually
**Action**: Implement proper strip/extract/restore sequence pattern

### 5. Textarea Paste CharLimit Bypass
**Why**: PHP charlimit implementation likely has same slice calculation bug
**Action**: Test paste at exactly limit, 1 over, empty

### 6. Viewport Soft-Wrap Performance
**Why**: PHP string operations are expensive
**Action**: Cache wrapped lines, only recompute on content/width change

### 7. Progress Bar Partial Blocks
**Why**: PHP can use same Unicode characters
**Action**: Consider implementing as option

### 8. Help Whitespace Styling
**Why**: PHP Help component likely has same styling gap
**Action**: Add configurable whitespace style

---

## Features SugarCraft Should Consider

### High Priority (Based on Community Demand)
1. **TextArea Dynamic Height** - Auto-grow to content (implemented in Go v2.1.0)
2. **Real Cursor Support** - Opt-in terminal cursor (v2 feature)
3. **TextArea PageUp/PageDown** - Already in Go, needed in PHP
4. **Viewport Horizontal Scrolling** - Already in Go, needed in PHP
5. **Progress Bar Multiple Color Stops** - Already in Go, needed in PHP

### Medium Priority
6. **History Interface for TextInput** - Allow external storage backends
7. **Partial Block Progress** - Smoother small bars
8. **Help Whitespace Styling** - Configurable whitespace between key/description
9. **TextArea Word Under Cursor** - `alt+u/l/c` transforms (already mentioned in gap analysis)
10. **Filtered List SetItems** - Ensure ghost items don't persist

### Lower Priority (Nice to Have)
11. **Sparkline/Chart Components** - Community request, SugarCraft differentiation opportunity
12. **Password Input Terminal Notification** - Security feature
13. **Table Auto-resize** - Responsive to terminal size
14. **Multi-line Prompt for TextInput** - Wrapping prompts

---

## Architectural Lessons

### 1. Getter/Setter Over Public Fields
**Why**: Internal bookkeeping when dimensions change
**Example**: Width/Height changes require internal recalculation (viewport offset, wrap cache invalidation)
**SugarCraft Pattern**: Use `__set` magic method or explicit setters that trigger recalculation

### 2. Memoization for Expensive Computation
**Why**: Text wrapping, fuzzy filtering, pagination rendering are expensive
**Example**: Textarea wrap caching reduced 42s → 3s operation
**SugarCraft Pattern**: Cache wrap results keyed by (content, width) pairs

### 3. Virtualization for Large Datasets
**Why**: Tables with 1000+ rows can't render all rows on each update
**Example**: Only visible rows rendered, lazy loading of content
**SugarCraft Pattern**: Table/LIst only renders items that would be visible

### 4. Option Functions Over Constructor Arguments
**Why**: Flexibility, readability, named parameters
**Example**: `viewport.New(WithWidth(80), WithHeight(20))` vs `viewport.New(80, 20)`
**SugarCraft Pattern**: Implement Option pattern for component configuration

### 5. Interface-Based Extension
**Why**: Allow users to customize without forking
**Example**: HistoryManager interface, FilterFunc interface
**SugarCraft Pattern**: Define interfaces for: History, Filter, Formatter, Sanitizer

### 6. Pointer Receiver for Model Methods
**Why**: State mutation during Update() must persist
**Example**: v2 textarea uses pointer receivers for all Model methods
**SugarCraft Pattern**: All component methods that modify state use `&$this` or return new instance with mutation

### 7. Message-Based Async Operations
**Why**: Keep UI responsive during expensive operations
**Example**: Fetching list items via `tea.Cmd` that returns message with results
**SugarCraft Pattern**: Use ReactPHP async for data fetching, dispatch result messages

---

## Defensive Design Lessons

### 1. Validate Boundary Conditions Before Loops
**Problem**: #1652 infinite loop, #887 cursor stuck, #468 charlimit bypass
**Fix**: Check bounds before entering loops, after loops verify valid state
**PHP Pattern**:
```php
if ($this->row === 0 && $this->col === 0) {
    return; // Nothing to navigate
}
// Then proceed with loop
```

### 2. Always Sanitize Clipboard Input
**Problem**: #263 control characters corrupt state, #214 bracketed paste
**Fix**: Strip/handle control characters before processing
**PHP Pattern**: Use `preg_replace` to remove control chars, validate UTF-8

### 3. Separate Content from Presentation
**Problem**: #633 styled text in textarea
**Fix**: Textarea stores plain text, viewport/styled components display styled
**PHP Pattern**: TextArea value is always plain text, use Viewport for styled display

### 4. Test Edge Cases Explicitly
**Problem**: Many bugs only appear at edge values (width=0, empty input, exactly-full)
**Fix**: Property-based testing or explicit edge case tests
**PHP Pattern**: Add tests for: empty, 1-char, exactly-width, width+1, max-char-limit

### 5. Cache Invalidation on State Change
**Problem**: Stale cache causes rendering bugs after SetValue/SetWidth
**Fix**: Any state change that affects cached computation must invalidate cache
**PHP Pattern**: Setter methods call `invalidateCache()` or use `__unset` magic

### 6. Escape Sequences Are Invisible
**Problem**: #502 table truncation breaks with ANSI codes
**Fix**: Always strip escape sequences before measuring/truncating, restore after
**PHP Pattern**:
```php
$plain = preg_replace('/\x1b\[[^m]*m/', '', $text);
$width = mb_strwidth($plain);
$truncated = mb_strimwidth($plain, 0, $maxWidth, '...');
```

### 7. Don't Compute in View()
**Problem**: #566 hangs due to style computation
**Fix**: All expensive computation happens in Update(), View() only renders
**PHP Pattern**: View() should be pure render, no calculations

---

## Ecosystem Trends

### 1. Terminal Capabilities Expanding
- Rich keyboard support, inline images, synchronized rendering
- Clipboard transfer over SSH
- Real cursor support becoming standard
- SugarCraft should prepare for these capabilities

### 2. AI Agents in Terminal
- v2 motivated by AI coding agents (Crush) requirements
- Performance is critical - every ms matters over SSH
- SugarCraft should prioritize performance optimization

### 3. Form Framework Layer (huh→sugar-prompt)
- Users want high-level forms, not just primitives
- sugar-prompt (huh port) addresses this
- Consider higher-level abstractions on top of candy-forms

### 4. Real Cursor + Virtual Cursor
- Real cursor for accessibility, virtual for flexibility
- v2 implements both, user chooses via SetVirtualCursor(false)
- SugarCraft should support both modes

### 5. Dynamic Content Sizing
- Auto-growing text areas, responsive tables
- Users expect terminals to adapt to content
- SugarCraft should implement DynamicHeight, responsive tables

### 6. Performance as Feature
- 25,000+ open source apps using this ecosystem
- Performance is a feature - affects user experience directly
- SugarCraft should benchmark, profile, optimize hot paths

---

## Strategic Opportunities

### 1. Charts Library (sugar-spark/sugar-charts)
- Community requested charm-charts
- No Go implementation yet merged
- SugarCraft could implement sparklines, bar charts first
- Differentiates from upstream

### 2. History System
- Go upstream declined internal history
- SugarCraft could implement as interface with SQLite/file/redis backends
- High user demand for input history

### 3. Dynamic Height TextArea
- Just released in Go v2.1.0 (March 2026)
- SugarCraft can implement same feature now
- Aligns PHP port with latest upstream

### 4. Performance Optimization Focus
- Viewport refactor (35% CPU, 38% mem reduction) shows big wins possible
- SugarCraft PHP could target similar improvements
- Memoization, virtualization, string building patterns

### 5. Multi-Platform Input Handling
- Windows diacritics issue shows platform differences
- SugarCraft can test more thoroughly on Windows
- Implement better platform-specific input handling

### 6. Async/Await Pattern for PHP
- Go uses goroutines for async data fetching
- ReactPHP could provide similar pattern
- Implement proper async item loading for List/Table

---

## Cross-Ecosystem Pattern Matches

### TextInput States (React/HTML Forms)
- Default, Focused, Error, Disabled, Readonly
- Echo modes (normal, password, none) mirror HTML input types
- SugarCraft should ensure all states are styled distinctly

### TextArea Behavior (Code Editors)
- Word wrap with visual line tracking
- Line numbers gutter
- Cursor position tracking across wrapped lines
- Vim-style navigation (word, paragraph)
- SugarCraft could learn from CodeMirror, Monaco

### List Behavior (File Browsers)
- Filtering with fuzzy match highlighting
- Pagination with "X of Y" display
- Sorting (community third-party does this)
- SugarCraft ItemList could add sorting

### Table Behavior (Data Grids)
- Column resizing
- Sorting by column
- Filtering by column
- Frozen columns
- Cell editors
- Third-party (evertras/bubble-table) does this

### Progress Bar (Game UIs)
- Gradient fills
- Partial block smoothness
- Animation
- Multiple stops
- Percentage display optional

---

## High ROI Recommendations

### Immediate (High Impact, Low Effort)
1. **Add boundary checks to TextArea navigation loops** - Prevents infinite loops (issue #1652 pattern)
2. **Fix TextInput placeholder with width=0** - Multiple users affected, clear fix
3. **Use strings.Builder for pagination rendering** - Simple fix, big performance win
4. **Implement cache invalidation on SetValue/SetWidth** - Prevents stale rendering bugs
5. **Strip escape sequences before truncation** - Fixes table ANSI issues

### Short-term (High Impact, Medium Effort)
6. **Implement TextArea memoization** - Critical for performance (42s→3s improvement)
7. **Add Real/Virtual cursor mode** - Accessibility feature, v2 API
8. **Implement DynamicHeight for TextArea** - Highly requested, aligned with v2.1.0
9. **Add PageUp/PageDown to TextArea** - Already in Go, straightforward to port
10. **Fix List RemoveItem filtering index** - Correctness bug, clear fix

### Medium-term (High Impact, High Effort)
11. **Virtualize Table rendering** - Critical for large datasets
12. **Implement History interface** - Differentiates from upstream
13. **Add fuzzy filter with match highlighting** - Quality gap in ItemList
14. **Implement soft-wrap caching in Viewport** - Performance critical
15. **Add Viewport horizontal scrolling** - v2 feature, improves usability

### Long-term (Strategic Value)
16. **Chart components (sparkline, bar)** - Community request, differentiation
17. **Vim-mode for TextArea** - Niche but valued by power users
18. **Table auto-resize** - Responsive terminal support
19. **Password terminal notification** - Security feature
20. **Multi-line prompt wrapping** - UX polish for TextInput

---

## Appendix: Key Issue/PR References

| ID | Type | Component | Summary |
|---|---|---|---|
| #950 | Issue | TextInput | 0 width placeholder bug |
| #810 | Issue | List | Performance with 8000 items |
| #737 | Issue | List | Ellipsis on full width title |
| #461 | Issue | Help | Rendering with constrained width |
| #571 | Issue | Help | Whitespace styling gap |
| #887 | Issue | TextArea | Cursor stuck on up/down |
| #925 | Issue | (new) | Charts library proposal |
| #1652 | Issue | TextArea | Infinite loop on word nav |
| #566 | Issue | TextArea | Freeze with SetValue+View |
| #276 | Issue | Table | O(n) rendering performance |
| #301 | Issue | TextArea | Performance optimization |
| #395 | Issue | Progress | Partial blocks request |
| #823 | PR | Viewport | 35% CPU / 38% mem improvement |
| #882 | PR | Paginator | String builder performance |
| #214 | PR | TextInput/TextArea | Bracketed paste |
| #638 | PR | List | RemoveItem with filtering |
| #427 | PR | TextArea | Memoization |
| #657 | PR | TextArea | Highlight/formatter/suggestions |
| #794 | PR | TextInput/TextArea | Cursor improvements |
| #910 | PR | TextArea | Dynamic height |
| #631 | PR | TextInput | History (declined) |
| #603 | PR | Table | Escape sequence truncation |

---

*Report generated for SugarCraft ecosystem intelligence*
*Source: GitHub Issues, PRs, Discussions for charmbracelet/bubbles*
*Coverage: Open and closed issues, v1 and v2 development, through March 2026*
