# Overview

**sugar-crumbs** is a PHP port of KevM/bubbleo providing NavStack (navigation stack) and Breadcrumb components for terminal UIs. It is a focused, framework-agnostic library (~370 lines of core source across 8 files) that produces pure string output, making it usable with any TUI framework. The library is 🟢 v1 ready with comprehensive test coverage (678+ lines across 6 test files).

**Biggest opportunity areas:** Integration with the broader SugarCraft ecosystem (candy-core for message-driven patterns, candy-sprinkles for styling, sugar-prompt for selection dialogs), fuzzy filtering matching the quality of `sahilm/fuzzy`, and modal/overlay compositing for stacked navigation.

**Biggest missing capabilities:** No message-driven navigation equivalent to bubbleo's `PushNavigation`/`PopNavigation` tea.Msg pattern; no animation/transition support; no built-in back-button/keybinding infrastructure; no fuzzy filtering with ranking/scoring.

---

# Internal Capability Summary

## Architecture

sugar-crumbs provides three primitives with clear separation of concerns:

| Class | Role | Lines | Public API Surface |
|---|---|---|---|
| `NavStack` | LIFO stack with push/pop/peek/filter | 197 | `push()`, `pop()`, `current()`, `parent()`, `depth()`, `isEmpty()`, `items()`, `updateTop()`, `clear()`, `setItems()`, `view()`, `viewHtml()`, `filter()` |
| `Breadcrumb` | Renders NavStack as string trail | 211 | `setSeparator()`, `setTruncator()`, `setMaxWidth()`, `setItemRenderer()`, `withZoneManager()`, `render()`, `renderTitles()` |
| `Shell` | Combines NavStack + Breadcrumb | 66 | `new()`, `withPush()`, `withPop()`, `renderBreadcrumb()`, `pushDirectory()` |

Supporting classes: `NavigationItem` (title + data payload), `Closable` (onEnter/onLeave interface), `Escape` (separator escaping), `Url` (path derive/parse), `Lang` (i18n facade).

## Current Features

- **Immutable Shell**: `withPush()`/`withPop()` return new Shell instances
- **Type-ahead filtering**: `NavStack::filter()` with case-insensitive substring matching on title AND data
- **Path parsing**: `Shell::pushDirectory()` maps filesystem paths to navigation items
- **Truncation with ellipsis**: `Breadcrumb::setMaxWidth()` keeps breadcrumbs readable in constrained terminals
- **HTML rendering**: `NavStack::viewHtml()` produces semantic HTML with `aria-current="page"`
- **Zone-based mouse tracking**: Optional `Manager` integration via `Breadcrumb::withZoneManager()`
- **Separator escaping**: Prevents corruption when titles contain ` > `
- **URL derive/parse**: Round-trip safe `Url` class
- **i18n**: `Lang` class with `crumbs` namespace

## Strengths

1. **Framework-agnostic**: breadcrumb output is just strings — works with any TUI framework
2. **Immutable Shell**: `withPush()`/`withPop()` return new instances, predictable state
3. **Type-ahead filtering**: `filter()` enables quick navigation through deep stacks
4. **Path parsing**: `pushDirectory()` maps filesystem paths with cumulative data
5. **Truncation with ellipsis**: `setMaxWidth()` for constrained terminals
6. **HTML/ARIA accessibility**: `viewHtml()` for screen reader support
7. **Zone mouse tracking**: Optional `Manager` integration via composition
8. **Comprehensive tests**: 678+ lines covering all public APIs

## Weaknesses

1. **No message-driven navigation**: Direct method calls only (no `PushNavigation`/`PopNavigation` equivalent)
2. **No animation/transition support**: Direct `View()` output, no animated transitions
3. **No keybinding infrastructure**: Applications handle ESC/backspace to pop manually
4. **Closable lifecycle asymmetry**: `onEnter()`/`onLeave()` are no-ops by default with no automatic invocation
5. **NavStack::pop() mutates in-place**: Contrasts with Shell's immutability pattern
6. **No fuzzy filtering ranking**: Simple substring match, no character index reporting
7. **No menu/selection component**: bubbleo's menu is not ported

---

# Relevant External Repositories

| Repo | Relevance | Major applicable concepts | Priority |
|---|---|---|---|
| `KevM/bubbleo` | 🔴 Primary upstream | NavStack, Breadcrumb, Shell, Closable, menu, message-driven navigation, tea.Sequence | **Critical** |
| `charmbracelet/bubbletea` | 🟡 Framework | Elm architecture, tea.Model, Cmd/Msg patterns, Program, subscriptions | High |
| `charmbracelet/bubbles` | 🟡 Component reference | List with fuzzy filtering, ItemList, pagination, delegates | High |
| `treilik/bubblelister` | 🟡 List alternative | Cursor navigation, sorting by LessFunc, viewport offset, concurrent search | Medium |
| `erikgeiser/promptkit` | 🟡 Selection/filtering | Generic `Selection[T]`, fuzzy filtering, auto-completion, pagination | Medium |
| `lrstanley/bubblezone` | 🟢 Already integrated | Zone-based mouse tracking, state-machine scanner, zero-width ANSI markers | 🟢 Done |
| `charmbracelet/lipgloss` | 🟡 Styling reference | Fluent Style, CSS-like properties, borders, color, layout | Medium |
| `textualize/textual` | 🟢 Reference only | CSS layout, reactive state, message pump, widget system | Low |
| `rmhubbert/bubbletea-overlay` | 🟢 Compositing reference | Viewable interface, compositing algorithm, positioning system | Low |

---

# Feature Gap Analysis

## Critical

### 1. Message-driven navigation pattern
**Title:** Add message-driven navigation (PushNavigation/PopNavigation)
**Description:** bubbleo uses `tea.Msg` for navigation — `PushNavigation{Item}` and `PopNavigation{}` messages that integrate with the Elm update cycle. sugar-crumbs has no equivalent.
**Why it matters:** Enables reactive, decoupled navigation where any component can request navigation without direct coupling to the NavStack. Essential for large applications with many navigation points.
**Source repo:** `KevM/bubbleo` (`navstack/model.go:40-44`)
**Source PR/issue:** `KevM/bubbleo` natively implements this
**Implementation ideas:**
- Create `PushNavigationMsg` and `PopNavigationMsg` classes in `SugarCraft\Crumbs\Msg`
- Add `update(Msg $msg): array{0: self, 1: ?Closure}` method to NavStack/Shell
- Route messages in the consuming application's main update loop
**Estimated complexity:** Medium (requires integration pattern, not complex algorithm)
**Expected impact:** High — enables reactive navigation architecture

### 2. Fuzzy filtering with ranking/scoring
**Title:** Upgrade filter() to fuzzy matching with score + indices
**Description:** sugar-crumbs uses simple case-insensitive substring matching. bubbleo doesn't have filtering, but bubbles List uses `sahilm/fuzzy` for ranked fuzzy matching with character index reporting. promptkit also has filtering. sugar-crumbs needs quality fuzzy matching.
**Why it matters:** Type-ahead filtering with ranking enables intelligent UX — best matches surface first, matched characters can be highlighted in the UI.
**Source repo:** `charmbracelet/bubbles` (`list/filter.go`), `erikgeiser/promptkit` (`selection/model.go`)
**Source PR/issue:** `sahilm/fuzzy` library (pure Go, MIT licensed)
**Implementation ideas:**
- Port `sahilm/fuzzy` to PHP as `SugarCraft\Fuzzy` or integrate via FFI
- Add `filterFuzzy(string $term): FilterResult` returning `{matches: bool, score: int, indices: int[]}`
- Extend `Breadcrumb::setItemRenderer()` to support highlighting matched ranges
**Estimated complexity:** High (fuzzy algorithm port or FFI binding)
**Expected impact:** High — significantly improves type-ahead UX

## High Value

### 3. Animation/transition support
**Title:** Add animated transitions between navigation states
**Description:** bubbleo's View() renders immediately. textualize and charmbracelet/harmonica (honey-bounce) provide animated transitions.
**Why it matters:** Smooth transitions between screens improve perceived quality and help users understand spatial relationships in navigation.
**Source repo:** `textualize/textual` (animator system), `charmbracelet/lipgloss` v2 (layer transitions)
**Implementation ideas:**
- Add `withTransition(Transition $t)` to Shell
- Implement fade/slide transitions in Breadcrumb renderer
- Coordinate with `honey-bounce` for spring animations
**Estimated complexity:** Medium
**Expected impact:** Medium — polish feature

### 4. Built-in back-button key handling
**Title:** Add ESC/backspace key handler for automatic stack popping
**Description:** Applications must implement ESC/backspace manually to pop the stack. There's no built-in handling or keybinding infrastructure.
**Why it matters:** Back navigation is fundamental — users expect ESC or backspace to go back. Missing this is a UX gap.
**Source repo:** `charmbracelet/bubbles` (`help/key.go` for keybinding patterns)
**Implementation ideas:**
- Add `KeyMap` class with configurable back keys (ESC, backspace, left arrow)
- Add `withKeyMap(KeyMap $km)` to Shell
- Return `?PopNavigationMsg` from Shell's update when back key pressed
**Estimated complexity:** Low
**Expected impact:** High — fundamental UX improvement

### 5. Closable lifecycle integration
**Title:** Auto-invoke onEnter()/onLeave() during push/pop
**Description:** bubbleo's `Closable.Close()` is automatically called on pop. sugar-crumbs has `onEnter()`/`onLeave()` but they are no-ops by default with no invocation in base NavStack.
**Why it matters:** Resource cleanup (closing files, canceling subscriptions) is a core pattern from bubbleo that's not yet implemented.
**Source repo:** `KevM/bubbleo` (`navstack/model.go:26-28`)
**Implementation ideas:**
- Modify `NavStack::pop()` to invoke `onLeave()` on popped item
- Modify `NavStack::push()` to invoke `onEnter()` on new top item
- Collect errors and surface via `?ClosableError` from pop/push return
**Estimated complexity:** Low
**Expected impact:** Medium — enables proper resource management

### 6. Menu/selection component
**Title:** Port bubbleo's Menu component for selection-driven navigation
**Description:** bubbleo includes a Menu component wrapping `bubbles/list` that pushes selected choices onto the NavStack. sugar-crumbs doesn't port this.
**Why it matters:** Selection-driven navigation (choose from a list → push choice onto stack) is a common pattern for drill-down UIs.
**Source repo:** `KevM/bubbleo` (`menu/model.go`)
**Implementation ideas:**
- Create `SugarCraft\Crumbs\Menu` class
- Use `SugarCraft\Bits\ItemList` as the underlying list widget
- On selection, call `Shell::withPush()` with the chosen item
**Estimated complexity:** Medium
**Expected impact:** Medium — common use case

## Medium Priority

### 7. Shell change listener/callback
**Title:** Add onChange callback for shell state transitions
**Description:** Shell has no listener mechanism. Applications must poll or observe externally. bubbleo has no equivalent, but it's a natural extension.
**Why it matters:** Enables reactive UI updates when navigation changes — important for MVU architecture.
**Source repo:** N/A (sugar-crumbs extension point)
**Implementation ideas:**
- Add `?Closure $onChange` to Shell constructor/withPush/withPop
- Fire callback after stack mutation with old/new item info
**Estimated complexity:** Low
**Expected impact:** Medium

### 8. Separator customizability per-item
**Title:** Allow different separators between specific crumb items
**Description:** Currently a single separator is used for all items. bubbleo doesn't have this, but it could be useful for semantic separation (e.g., "Home / Settings > Display").
**Source repo:** N/A
**Implementation ideas:**
- Add `NavigationItem::separator` field
- Modify `Breadcrumb::render()` to use per-item separators
**Estimated complexity:** Low
**Expected impact:** Low — edge case

### 9. Multiple navigation stack support (tabs)
**Title:** Support parallel navigation stacks for tabbed navigation
**Description:** Linear single-stack navigation only. No support for tabs or parallel navigation paths.
**Source repo:** `textualize/textual` (Tabs widget)
**Implementation ideas:**
- Create `TabGroup` class managing multiple `Shell` instances
- `withActiveTab(int $idx)` to switch between shells
**Estimated complexity:** High
**Expected impact:** Low — advanced feature

### 10. Persist/restore navigation state
**Title:** Add serialization for navigation state persistence
**Description:** Navigation state is lost on quit. No save/restore mechanism.
**Source repo:** `KevM/bubbleo` (not present, but natural extension)
**Implementation ideas:**
- Add `jsonSerialize()` to NavStack/Shell
- Add `NavStack::restore(array $data)` and `Shell::restore()`
**Estimated complexity:** Low
**Expected impact:** Medium — enables session persistence

## Low Priority

### 11. Tea.Sequence equivalent for ordered ops
**Title:** Add sequential command execution for coordinated navigation
**Description:** bubbleo uses `tea.Sequence(pop, cmd)` to ensure pop executes before subsequent command. PHP has no equivalent.
**Why it matters:** Ensures navigation state is consistent before passing messages to new top item.
**Source repo:** `KevM/bubbleo` (`navstack/model.go:99-100`)
**Implementation ideas:**
- Document that PHP's synchronous model doesn't need Sequence
- If async needed, use ReactPHP promises
**Estimated complexity:** N/A
**Expected impact:** Low — PHP synchronous model makes this unnecessary

### 12. Window/dimension management
**Title:** Port bubbleo's Window model for layout calculations
**Description:** bubbleo has `window.Model` for dimension management with TopOffset/SideOffset. Not needed without tea.WindowSizeMsg.
**Source repo:** `KevM/bubbleo` (`window/model.go`)
**Implementation ideas:**
- Not needed until integration with candy-core's tea.WindowSizeMsg equivalent
**Estimated complexity:** N/A
**Expected impact:** Low

---

# Algorithm / Performance Opportunities

## Current Approach

**NavStack filtering** uses simple `stripos` substring matching:
```php
public function filter(string $term): self
{
    $filtered = \array_filter($this->items, static function(NavigationItem $item) use ($term): bool {
        $titleMatch = \stripos($item->title, $term) !== false;
        $dataMatch = $item->data !== null && \stripos((string) $item->data, $term) !== false;
        return $titleMatch || $dataMatch;
    });
    // Returns new NavStack with matching items
}
```

**Truncation algorithm** iterates from most-recent to oldest:
```php
// Start from most recent, prepend older until we fit
$out = [\end($titles)];
for ($i = \count($titles) - 2; $i >= 0; $i--) {
    $candidate = $this->truncator . \implode($this->separator, \array_merge([$titles[$i]], \array_reverse($out)));
    if ($this->effectiveWidth($candidate) <= $this->maxWidth) {
        $out[] = $titles[$i];
    } else {
        break;
    }
}
```

## External Approaches

### Fuzzy Filtering (bubbles List / promptkit)
bubbles uses `sahilm/fuzzy` for ranked fuzzy matching:
```go
// Returns matches with score and matched indices
matches := fuzzy.Find(filter, items)
for _, match := range matches {
    fmt.Printf("Match: %s, Score: %d, Indices: %v\n", match.Str, match.Score, match.MatchedIndexes)
}
```

promptkit's filter also supports custom filter functions with binary search pagination.

**Why external is better:** Substring matching doesn't rank results — every match is equal. Fuzzy matching surfaces the best match first, which is critical for type-ahead UX in deep hierarchies.

**Tradeoffs:** Fuzzy matching is more computationally expensive. For small stacks (< 20 items), substring matching is fine.

**Applicability:** High — filtering is a core feature of navigation UIs.

---

# Architecture Improvements

1. **Separate NavStack mutation from Shell immutability**
   - `NavStack::pop()` mutates in-place; `Shell::withPop()` returns new Shell
   - Consider making NavStack immutable too, or document the asymmetry clearly

2. **Add update(Msg) method for reactive pattern**
   - Enable message-driven navigation matching bubbleo's pattern
   - `update(mixed $msg): array{self, ?Closure}` returning new instance + optional command

3. **Layer separation for rendering vs. state**
   - Breadcrumb handles rendering only
   - NavStack/Shell handle state only
   - This separation is already clean — maintain it

4. **Zone manager decoupling**
   - Zone marking is already done via composition (not inheritance)
   - Could further decouple by using an interface `ZoneMarkable` with `mark(string $id, string $content): string`

---

# API / Developer Experience Improvements

1. **Fluent Breadcrumb configuration**
   - Already fluent — `setSeparator()` etc. return `$this`
   - Consider builder pattern: `Breadcrumb::build(): BreadcrumbRenderer`

2. **Type-safe item accessors**
   - `current(): ?NavigationItem` — already good
   - Add `nth(int $n): ?NavigationItem` for direct stack access
   - Add `slice(int $from, int $to): array<NavigationItem>` for subtree extraction

3. **Generic Stack implementation**
   - Could generalize `NavStack` to `Stack<T>` for reuse
   - Go's `bubbleo` uses `interface{}` / `any` similarly

4. **Better error handling**
   - `pop()` returns `null` on empty — clear
   - Add `?ClosableError` type for onLeave() failures
   - Document error propagation in push/pop

5. **IDE documentation**
   - PHPDoc already comprehensive
   - Add `@example` blocks for all public methods

---

# Documentation / Cookbook Opportunities

1. **Navigation patterns cookbook**
   - Simple push/pop navigation
   - Deep directory navigation with `pushDirectory()`
   - Type-ahead filtering with custom rendering
   - Mouse-clickable breadcrumbs

2. **Integration guide: candy-core**
   - How sugar-crumbs integrates with candy-core's message loop
   - Wiring mouse events from `candy-core` to `sugar-crumbs` via `Manager::anyInBoundsAndUpdate()`

3. **Integration guide: candy-sprinkles**
   - Styling breadcrumbs with `candy-sprinkles` Style
   - Color customization, borders

4. **Migration guide from bubbleo**
   - Go → PHP mapping for each concept
   - Key differences: message-driven vs. direct method calls
   - Closable lifecycle differences

---

# UX / TUI Improvements

1. **Fuzzy filtering with highlighted matches**
   - Show which characters matched in the filter
   - Use `Breadcrumb::setItemRenderer()` with match highlighting

2. **Animated transitions**
   - Fade out old breadcrumb, fade in new
   - Slide animation for push/pop

3. **Better truncation indicator**
   - Current truncator is "… " (ellipsis + space)
   - Could add click hint: "‹ … ›" indicating expandable

4. **Keyboard-navigable breadcrumbs**
   - Left/right arrows to move focus between crumbs
   - Enter to "click" focused crumb and navigate to it

---

# Testing / Reliability Improvements

1. **Golden/snapshot tests for breadcrumb rendering**
   - Capture rendered output as expected strings
   - Guard against unintended formatting changes

2. **Fuzz testing for filter() edge cases**
   - Empty strings, Unicode, very long titles, separator collisions

3. **Concurrent access tests**
   - Though PHP is single-threaded for most TUI apps, async patterns in ReactPHP could introduce concurrency

4. **Integration tests with candy-core**
   - Full update/render cycle with real mouse/keyboard events

---

# Ecosystem / Integration Opportunities

1. **sugar-crumbs → candy-core integration**
   - Register as a standard navigation component in candy-core
   - Document message types for PushNavigation/PopNavigation

2. **sugar-crumbs → sugar-prompt integration**
   - Menu component for selection-driven navigation
   - `Selection` prompt → push selected item onto Shell

3. **sugar-crumbs → candy-sprinkles integration**
   - Style-aware Breadcrumb renderer
   - Support custom separators with box-drawing characters

4. **sugar-crumbs → sugar-veil (overlay) integration**
   - Modal dialogs that overlay the current navigation view
   - Use overlay compositing for confirmations

---

# Notable PRs / Issues / Discussions

### bubbleo Menu component not ported
**Issue:** bubbleo's menu wrapping bubbles/list is not in sugar-crumbs
**Relevance:** High — common navigation pattern
**Lesson:** Need to explicitly plan Menu porting or document that `sugar-bits/ItemList` replaces it

### Closable lifecycle asymmetry
**Issue:** `onEnter()`/`onLeave()` in sugar-crumbs are no-ops; bubbleo's `Close()` is called on pop
**Relevance:** Medium — resource cleanup pattern missing
**Adaptation:** Implement automatic invocation in NavStack::push()/pop()

### bubblezone zero-width ANSI markers
**Source:** `docs/repo_map/lrstanley_bubblezone.md`
**Relevance:** 🟢 Already integrated — sugar-crumbs uses `candy-zone/Manager` for zone marking
**Lesson:** Zone marking pattern is sound — maintain composition over inheritance

### lipgloss v2 layer/compositor
**Source:** `docs/repo_map/charmbracelet_lipgloss.md`
**Relevance:** Medium — lipgloss v2 has built-in compositing that supersedes bubbletea-overlay
**Lesson:** Consider using `candy-sprinkles` layer system instead of building overlay compositing

---

# Recommended Roadmap

## Immediate Wins (0-1 sprints)

1. **Auto-invoke onEnter()/onLeave() in NavStack::push()/pop()**
   - Low complexity, enables resource cleanup pattern from bubbleo

2. **Back-button key handling**
   - Add `KeyMap` class with ESC/backspace/left for pop
   - Low complexity, high UX impact

3. **Breadcrumb styling integration with candy-sprinkles**
   - Style-aware rendering
   - Low complexity, improves visual polish

## Medium-term Improvements (2-4 sprints)

4. **Fuzzy filtering with ranking**
   - Port `sahilm/fuzzy` to PHP or create FFI binding
   - Add `FilterResult` with score and indices
   - Medium-high complexity

5. **Message-driven navigation**
   - Create `PushNavigationMsg`/`PopNavigationMsg` classes
   - Add `update(mixed $msg)` method to Shell
   - Medium complexity

6. **Menu/selection component**
   - Port bubbleo's Menu using `sugar-bits/ItemList`
   - Medium complexity

7. **Shell change listener/callback**
   - Add `?Closure $onChange` for reactive UI updates
   - Low complexity

## Major Architectural Upgrades (future)

8. **Animation/transition system**
   - Coordinate with `honey-bounce` for spring animations
   - High complexity

9. **Tab group for parallel stacks**
   - Multiple Shell instances with active tab switching
   - High complexity

10. **Navigation state persistence**
    - Serialize/deserialize Shell state
    - Low complexity

---

# Priority Matrix

| Opportunity | Impact | Complexity | Risk | Priority |
|---|---|---|---|---|
| Auto-invoke onEnter()/onLeave() | Medium | Low | Low | **Immediate** |
| Back-button key handling | High | Low | Low | **Immediate** |
| Breadcrumb styling (candy-sprinkles) | Medium | Low | Low | **Immediate** |
| Shell change listener/callback | Medium | Low | Low | **Immediate** |
| Fuzzy filtering with ranking | High | High | Medium | **Medium-term** |
| Message-driven navigation | High | Medium | Medium | **Medium-term** |
| Menu/selection component | Medium | Medium | Low | **Medium-term** |
| Animation/transition support | Medium | High | Medium | **Future** |
| Tab group (parallel stacks) | Low | High | Medium | **Future** |
| Navigation state persistence | Medium | Low | Low | **Future** |

---

# Final Strategic Assessment

sugar-crumbs is a well-designed, focused library that successfully ports bubbleo's navigation primitives to PHP while deliberately decoupling from the bubbletea framework. The decision to produce pure string output (rather than `tea.Model`) makes it framework-agnostic and reusable across any PHP TUI stack — this was the right choice.

The library's **core strengths** are its clean separation of concerns (NavStack for state, Breadcrumb for rendering, Shell for composition), its comprehensive test coverage, and its thoughtful extension points (custom itemRenderer, zoneManager integration). The `Closable` interface provides a hook for resource cleanup, and the `filter()` method enables useful type-ahead navigation.

**Key gaps** relative to the broader TUI ecosystem are:
1. No message-driven navigation — applications must call methods directly rather than dispatching `PushNavigation`/`PopNavigation` messages
2. No fuzzy filtering with ranking — substring matching is adequate for small stacks but insufficient for deep hierarchies
3. No animation/transition support — direct View() output with no smooth transitions
4. No built-in back-button handling — applications handle ESC/backspace manually

**Strategic position:** sugar-crumbs is the navigation primitive for SugarCraft TUI applications. Its framework-agnostic design makes it a general-purpose library for any PHP application needing hierarchical navigation. The library is production-ready but has room for enhancement in filtering quality, reactive patterns, and visual polish.

**Recommendations:**
- Prioritize the "immediate wins" (onEnter/onLeave auto-invocation, back-button handling, change listener) — they have high impact per complexity
- Invest in fuzzy filtering — it's the single highest-impact enhancement for deep navigation hierarchies
- Consider integration with `candy-core` message loop for optional reactive navigation
- Maintain framework-agnosticism — the pure string output is a key differentiator

The library should remain focused (don't add unrelated features), continue emphasizing composition over inheritance (zoneManager pattern), and document integration patterns with other SugarCraft libraries.
