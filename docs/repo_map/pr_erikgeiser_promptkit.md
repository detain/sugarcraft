# Second-Stage Ecosystem Intelligence Report: erikgeiser/promptkit

## 1. Repository Overview

| Metric | Value |
|--------|-------|
| **Stars** | 297 |
| **Forks** | 21 |
| **Language** | Go |
| **License** | MIT |
| **Created** | 2020-11-22 |
| **Last Push** | 2026-04-19 |
| **Open Issues** | 0 |
| **Releases** | v0.2.0 through v0.11.0 (pre-1.0, API unstable) |

**Maintainer Profile:** Solo project. Erik Geiser (erikgeiser) has 133 of 136 total commits. Only 4 contributors ever, with 3 external contributors contributing exactly 1 commit each.

**Activity Pattern:** Burst activity - recent major push March-April 2026 added multi-selection, textarea, and autocomplete improvements. Long periods of low activity between feature bursts.

**Ecosystem Position:** Mid-tier TUI prompt library, positioned between simple `survey`-style libraries and full application frameworks like bubbletea. Builds on Charmbracelet ecosystem (bubbletea, bubbles, lipgloss, termenv).

---

## 2. Existing SugarCraft Mapping

From `repo_map/erikgeiser_promptkit.md`:

| PromptKit Feature | SugarCraft Library | Status |
|-----------------|---------------------|--------|
| Selection prompt (`Selection[T]`) | `sugar-prompt` | Core component |
| Multi-selection prompt (`MultiSelection[T]`) | `sugar-prompt` | Recently added |
| TextInput prompt (`TextInput`) | `sugar-prompt` | Core component |
| TextArea prompt (`TextArea`) | `sugar-prompt` | Recently added |
| Confirmation prompt (`Confirmation`) | `sugar-prompt` | Core component |
| Template-based rendering | `sugar-prompt` | View system |
| KeyMap/keybindings | `sugar-prompt` | Key binding config |
| Lipgloss styling | `candy-shine` | Style system |
| BubbleTea Model pattern | `candy-core` | MVC pattern |
| WrapMode (WordWrap/HardWrap/Truncate) | `sugar-bits` | Text utilities |

---

## 3. Previously Identified Gaps

From first-stage analysis:

- **No async input** - synchronous terminal I/O only
- **No mouse support** - keyboard-only interaction
- **No built-in help** - users must remember keybindings
- **Integer type constraint** - `int` for dimensions (32-bit concern)
- **Single-threaded** - all prompts run synchronously in single tea.Program
- **Limited terminal support** - relies on termenv for ANSI; some terminals problematic
- **No input history** - TextInput lacks history/recall

---

## 4. High-Signal Open Issues

**Status: 0 open issues** — All historical issues are closed. Current issue queue is empty, indicating either complete user satisfaction or low community engagement.

**Notable Historical High-Signal Issues (by engagement):**

| Issue | Comments | Reactions | Signal Type |
|-------|----------|-----------|-------------|
| #2 Multiple Selections | 9 | 0 | Feature demand (eventually implemented) |
| #24 `selection.New(selection.Choice[T])` bug | 7 | 0 | Bug/design flaw |
| #25 confirmation as bubbletea model | 6 | 0 | Usability/API design |
| #22 bubbles break promptkit | 5 | 1 | Dependency fragility |
| #3 Integrate with bubbletea | 5 | 0 | Architecture/integration |
| #31 bubbles v0.21.0 compatibility | 4 | 0 | Upstream bug propagation |

---

## 5. Important Closed Issues

### 5.1 Dependency Fragility (Critical Pattern)

**Issue #22: "Latest bubbles break promptkit"** (5 comments)
```
filterInput.BackgroundStyle undefined (type textinput.Model has no field or method BackgroundStyle)
```
- **Root Cause:** Upstream bubbletea/bubbles PR #341 removed a field that promptkit relied on
- **Resolution:** Required maintainer to adapt promptkit code to upstream API change
- **Pattern:** Direct dependency on internal bubbletea implementation details
- **Direct Risk to SugarCraft: HIGH** — If sugar-prompt depends on bubbletea-equivalent internals, upstream changes can break our public API

**Issue #31: Compatibility with charmbracelet/bubbles v0.21.0** (4 comments)
- **Problem:** Placeholder text with multi-byte characters (emojis like `💚💚`, Chinese `你好`) only displayed first grapheme cluster
- **Root Cause:** Bug in upstream bubbles v0.21.0
- **Resolution:** Upgrading to bubbles v1.0.0 + bubbletea v1.3.10 fixed it
- **Direct Risk to SugarCraft: HIGH** — Unicode/grapheme cluster handling in input fields is a common failure point

### 5.2 Empty State Handling

**Issue #27: "selection widget fails silently on empty choice list"** (1 comment)
```go
// When choices is empty, Init() returns tea.Quit
// Race condition: Update/View may never be called
```
- **Problems Identified:**
  1. `Init()` returns `tea.Quit` when choices array is empty — but this wasn't documented
  2. Race condition in composed models where Init() terminating doesn't give parent model a chance to handle error
  3. Program hangs forever if `selection.Model[string]` used instead of `*selection.Model[string]`
- **Requested Documentation:** Explicit contract for empty-state behavior
- **Direct Risk to SugarCraft: MEDIUM** — Similar empty-state handling in composable TUI models could cause hard-to-debug issues

### 5.3 Generics Type Checking Bug

**Issue #24: `selection.New(selection.Choice[T])` doesn't work** (7 comments)
```go
// This was checking for wrong types:
case Choice[T]:  // Would match when T itself is Choice[V]
case *Choice[T]: // So we're checking for Choice[Choice[V]]
```
- **Root Cause:** Go generics variance issue — when user passes `[]Choice[V]`, T becomes `Choice[V]`, so the type check matches `Choice[Choice[V]]` instead of `Choice[V]`
- **Suggested Fixes:**
  1. Change Choice to implement Stringer and eliminate special cases (breaking)
  2. Add separate constructor for Choice slices
  3. Export `asChoices` helper
  4. Use reflection (rejected)
- **Direct Risk to SugarCraft: MEDIUM** — PHP generics equivalent patterns could suffer similar type confusion

### 5.4 Usability/API Design Issues

**Issue #25: "can't use confirmation prompt as a bubbletea model"** (6 comments)
- **User Problem:** Wanted to use `Confirmation` as a bubbletea widget but `validateKeyMap` was not exported
- **Workaround Found:** Use `confirmation.Model` directly (not `Confirmation`), access `Model.View()` method
- **Pattern:** Confusion between the config struct (`Confirmation`) and the model struct (`confirmation.Model`)
- **Solution:** Community example PR #26 demonstrated correct pattern
- **Direct Risk to SugarCraft: MEDIUM** — Similar config-vs-model confusion could occur with our Prompt classes

### 5.5 Windows/TTY Issues

**Issue #23: "error: running prompt: unable to set console to raw mode"** (3 comments)
- **Trigger:** Windows 10 2012 (older builds)
- **Root Cause:** TTY raw mode initialization failure on older Windows versions
- **Issue #29 follow-up:** README claimed Windows unsupported due to bubbletea bugs, but those were eventually fixed

**Direct Risk to SugarCraft: MEDIUM** — Cross-platform TTY handling is notoriously difficult

### 5.6 Text Validation UX

**Issue #7: "textinput: Validation with error values"** (3 comments)
- **Requested Feature:** Show contextual error messages when validation fails, not just pass/fail
- **Example:** Different message for "too short" vs "contains invalid characters"
- **Implementation:** Required adding error value/description to validation
- **Direct Risk to SugarCraft: HIGH** — We should implement contextual validation messages

---

## 6. Recurring Pain Points

### 6.1 Upstream Dependency Fragility (HIGHEST FREQUENCY)

Issues #22, #31, and #34 all involve upstream breaking changes:
- **bubbletea/bubbles v1 to v2 migration** (#34, #33) — Required significant changes
- **bubbles v0.21.0 grapheme cluster bug** (#31) — Upstream bug affected promptkit users
- **BackgroundStyle removal** (#22) — Internal API change broke promptkit

**Pattern:** Promptkit depends on bubbletea internals, not just the stable `tea.Model` interface. When bubbletea makes internal changes, promptkit breaks.

### 6.2 Empty State / Initial Render Issues

Issues #27, #18, #19, #20 all relate to initialization and empty states:
- Empty choices causing silent failures
- Initial page order being wrong
- Window resize causing partial redraws

**Pattern:** Initialization timing issues with the bubbletea program lifecycle.

### 6.3 Generics Edge Cases

Issues #24 showed that the `Choice[T]` type detection priority chain had edge cases when T itself was a `Choice` type.

---

## 7. Frequently Requested Features

### 7.1 Implemented Features (from PR history)

| Feature | PR | Status | Signal |
|---------|-----|--------|--------|
| Multi-selection | #35 | Merged 2026-03-27 | 9 comments |
| TextArea (multi-line input) | #36 | Merged 2026-03-28 | 2 comments |
| Autocomplete improvements | #37 | Merged 2026-03-28 | User request #30 |
| PreSelected for multi-selection | #38 | Merged 2026-04-19 | User request |
| Cursor looping | #10 | Merged (date unknown) | User request |
| Bubbletea v2 migration | #34 | Merged 2026-03-23 | Maintenance |

### 7.2 Feature Requests Never Filed as Issues

Based on README and examples, these features appear user-driven:
- **Custom filtering functions** (case-sensitive/insensitive)
- **Custom key mappings**
- **Result templates for output formatting**
- **Page size configuration with auto-pagination**
- **Character limit support**
- **Input width limiting (horizontal scrolling viewport)**

---

## 8. Important PRs

### PR #35: Multi Selection (March 2026)
- **What:** Added `MultiSelection[T]` and `MultiModel[T]` for selecting multiple items
- **Constraints:** `MinSelections`, `MaxSelections` bounds
- **Notable:** Used `PreSelected` function for pre-selecting items
- **Community Signal:** Issue #2 (Multiple Selections) had 9 comments indicating strong demand

### PR #36: Textarea (March 2026)
- **What:** Added `TextArea` multi-line input with auto-resize
- **Features:** `Height` (fixed or auto-expand), `ShowLineNumbers`
- **Windows Fix:** Multiple commits to fix textarea submission on Windows
- **Requested by:** Issue #28 (user request)

### PR #37: Autocomplete Improvements (March 2026)
- **What:** Improved autocomplete UX based on issue #30
- **Requested by:** Issue #30 (Allow to browse suggestions)
- **Result:** User can browse suggestions with arrow keys

### PR #38: PreSelected for Multi-Selection (April 2026)
- **What:** Added `PreSelected` function to pre-select items in multi-selection
- **Example:** `MultiSelection.WithPreSelected func(index int) bool`

### PR #34: Migrate from bubbletea/bubbles v1 to v2 (March 2026)
- **What:** Major dependency upgrade
- **Impact:** All prompt types required updates
- **Note:** This was a significant maintenance burden for solo maintainer

---

## 9. Architectural Changes

### 9.1 Major Architectural Decisions

**1. Config/Model Separation**
- `Selection` (config) → `Model` (bubbletea model)
- This mirrors MVC pattern and allows embedding as widgets
- However caused usability confusion (Issue #25)

**2. Generic Type Safety**
- `Selection[T]`, `MultiSelection[T]` for compile-time type safety
- Choice display priority: explicit `String` field → `string` → `fmt.Stringer` → `fmt.Sprintf`
- Edge case: fails when T is itself a `Choice` type (Issue #24)

**3. Template-Based Rendering**
- Uses Go `text/template` with composed FuncMaps
- Functions from: termenv, promptkit helpers, custom (IsScrollDownHintPosition, Selected, Unselected)
- Enables complete UI customization

**4. Init() as Early Termination Point**
- `Init()` returns `tea.Quit` for empty choices
- This is an anti-pattern for composed models (Issue #27)
- With bubbletea v2, View() now always runs before Update()

### 9.2 Model Lifecycle Changes (bubbletea v2)

From Issue #27 comment:
> "With `bubbletea/v2` it now seems to always run `View()` and then `Update()`"

This changed the lifecycle behavior and affected how empty states are handled.

---

## 10. Performance Discussions

**No explicit performance issues found in issues/PRs.**

However, notable algorithms:
- **Auto-pagination:** Brute-force binary search for page size (`forceUpdatePageSizeForHeight`)
- **Common prefix autocomplete:** Uses sorted array endpoints (O(n log n) sort, O(n) compare)
- **Filtered+paged choices:** Linear scan with early termination

---

## 11. Extensibility Discussions

### 11.1 Non-Export当时 Issues

**Issue #25:** `validateKeyMap` was not exported, preventing users from creating Confirmation models externally.

**Workaround:** Users must use the Model directly, not the factory.

**Direct Risk to SugarCraft: HIGH** — We should export all necessary internal helpers for composing widgets.

### 11.2 Custom Filtering

README documents custom filter support:
```go
// Case-sensitive
filter := func(input string, choice Choice[string]) bool { ... }
sel := selection.New("Prompt:", choices).WithFilter(filter)
```

**Assessment:** This is well-designed extensibility.

### 11.3 Template Customization

Users can override templates completely via `WithTemplate()`.

---

## 12. API/UX Complaints

### 12.1 Documentation Gaps

**Issue #27 (empty choices):**
> "It would be helpful if the following behaviors were described in the documentation: Init() will return tea.Quit if choices is empty."

**Pattern:** Missing explicit contracts for edge cases.

### 12.2 Confusion Between Config and Model

**Issue #25:** Users confused about when to use `Confirmation` vs `confirmation.Model`.

### 12.3 Generics Confusion

**Issue #24:** Type checking logic confused when T is itself a parameterized type.

---

## 13. Migration Problems

### 13.1 Bubbletea v1 → v2 Migration (PR #34)

- Required updating all prompt types
- Changed lifecycle behavior (View always runs before Update)
- Required by upstream changes, not by user demand

### 13.2 Bubbles v0.21.0 Bug Migration (Issue #31)

- Users stuck on v0.21.0 experienced grapheme cluster bugs
- Only resolution was upgrading to v1.0.0
- Version pinning was ineffective because users pull latest minor versions

---

## 14. Clever Fixes & Workarounds

### 14.1 Window Resize Workaround (Issue #20)

```go
// On WindowSizeMsg, force full redraw
case tea.WindowSizeMsg:
    return m, tea.ClearScrollArea
```

**Pattern:** Sending `ClearScrollArea` to force full screen refresh.

### 14.2 Empty Choices Guard (Implied by Issue #27)

Users must ensure non-empty choices before creating selection model, or handle `tea.Quit` in parent Init.

### 14.3 Confirmation as Widget Pattern

From community example (PR #26):
```go
type confirmationPrompt struct {
    confirmation *confirmation.Model
    // ...
}

func (m confirmationPrompt) Init() tea.Cmd {
    return m.confirmation.Init()
}
```

---

## 15. Community Workarounds

### 15.1 Empty State Handling

Users discovered they must:
1. Never pass empty choice arrays, OR
2. Wrap selection in parent model that checks for `tea.Quit`

### 15.2 Custom Validation Messages

**Issue #7:** User implemented custom validation error display:
```go
// Instead of just returning error, show contextual message
// User had to fork/modify library initially
```

### 15.3 Multi-Byte Character Workaround

**Issue #31:** Users downgraded bubbles version to avoid grapheme cluster bug, or just accepted the display issue.

---

## 16. Maintainer Guidance Patterns

### 16.1 Response Style

- **Reactive, not proactive** — Responds to issues but rarely initiates breaking changes
- **Documentation as fix** — Often responds with "documented" after adding docs
- **Version bumping as solution** — Frequently resolves upstream issues by bumping dependencies

### 16.2 Merged vs Rejected Pattern

**Accepted:**
- Multi-selection (long-requested, eventually implemented)
- TextArea (user requested, implemented)
- Cursor looping (simple enhancement, merged)
- PreSelected for multi-selection (reasonable extension)

**Never Rejected Explicitly:**
No explicit rejections found. All feature requests either implemented, or silently closed without resolution.

### 16.3 Pre-1.0 Stability Notice

README warns: "API may change significantly before v1.0.0"

This gives maintainer latitude to make breaking changes, which occurred in v2 migration.

---

## 17. Rejected Ideas Worth Revisiting

**None explicitly rejected.**

However, strategic observations:
- **Plugin system:** Never discussed — library stays focused on core prompts only
- **Async input:** Never discussed — remains synchronous
- **Mouse support:** Never requested — keyboard-only
- **Input history:** Never requested — no shell-like history

---

## 18. Problems Likely Relevant To SugarCraft

### 18.1 HIGH RISK

| Problem | Evidence | SugarCraft Impact |
|---------|----------|-------------------|
| **Upstream dependency fragility** | Issues #22, #31, #34 | If we depend on bubbletea-equivalent, upstream changes can break our API. Need abstraction layers. |
| **Unicode/grapheme handling** | Issue #31 (bubbles v0.21.0 bug) | Input handling with emojis, CJK characters must be carefully tested |
| **Empty state handling** | Issue #27 (tea.Quit race) | TUI models with empty states need explicit contracts and error handling |
| **Validation UX** | Issue #7 (contextual errors) | SugarCraft should support detailed validation error messages, not just pass/fail |

### 18.2 MEDIUM RISK

| Problem | Evidence | SugarCraft Impact |
|---------|----------|-------------------|
| **Config vs Model confusion** | Issue #25 | SugarCraft prompt classes should have clear separation and naming |
| **Cross-platform TTY** | Issues #23, #29 | Windows raw mode issues require careful abstraction |
| **Generics type edge cases** | Issue #24 | PHP equivalent (union types) could have similar issues |
| **Non-exported internal helpers** | Issue #25 | Export necessary helpers for composing widgets |

### 18.3 LOW RISK

| Problem | Evidence | SugarCraft Impact |
|---------|----------|-------------------|
| **Initial render ordering** | Issues #18, #19 | Monitor for similar pagination bugs |
| **Window resize handling** | Issue #20 | May need ClearScrollArea equivalent |
| **Preview GIF maintenance** | Commit "Fix preview gifs" | Demo assets require ongoing maintenance |

---

## 19. Features SugarCraft Should Consider

### 19.1 HIGH PRIORITY (Strong User Demand)

| Feature | PromptKit Evidence | Implementation Notes |
|---------|-------------------|---------------------|
| **Multi-selection with constraints** | PR #35 (Min/Max) | SugarCraft needs `withMinSelections()`, `withMaxSelections()` |
| **Pre-selection** | PR #38 | `withPreselected(callable)` pattern |
| **TextArea with auto-resize** | PR #36 | PHP equivalent should handle `Height=0` for auto-expand |
| **Contextual validation errors** | Issue #7 | Not just `ValidateNotEmpty`, but `ValidateWithMessage(func) error` |
| **Autocomplete with browsing** | PR #37 (based on #30) | Arrow key browsing of suggestions, not just tab-completion |

### 19.2 MEDIUM PRIORITY (Good UX)

| Feature | PromptKit Evidence | Implementation Notes |
|---------|-------------------|---------------------|
| **Cursor looping** | Issue #10 (merged) | `withCursorLooping(bool)` |
| **Custom filter functions** | Documented | `withFilter(callable)` accepting filter function |
| **Result templates** | Documented | Final output formatting via template |
| **Custom key mappings** | Documented | Full KeyMap customization |

### 19.3 STRATEGIC (Not in PromptKit)

| Feature | Rationale |
|---------|-----------|
| **Input history (up/down arrows)** | Never requested in PromptKit but common shell expectation |
| **Mouse support** | Never requested but increasingly expected |
| **Async/background processing** | Out of scope for prompt library |
| **Plugin/extension system** | Never discussed, but could differentiate SugarCraft |

---

## 20. Architectural Lessons

### 20.1 Dependency Abstraction

**Lesson:** PromptKit directly depended on bubbletea internals (e.g., `BackgroundStyle` field), not just the stable `tea.Model` interface.

**SugarCraft Design Principle:** Depend on stable, public interfaces. Wrap upstream TTY frameworks in abstraction layers to isolate from internal API changes.

### 20.2 Init() as Early Termination is Problematic

**Lesson:** Using `Init()` to return `tea.Quit` for empty states creates race conditions in composed models.

**SugarCraft Design Principle:** Model initialization should never terminate the program. Use explicit state flags and let parent models decide how to handle empty/error states.

### 20.3 Generics Require Edge Case Testing

**Lesson:** Type checking priority chain had edge case when T was itself a `Choice` type.

**SugarCraft Design Principle:** When implementing PHP union types or generics patterns, explicitly test edge cases where the type parameter is the container type itself.

### 20.4 Template FuncMap Composition

**Lesson:** PromptKit composes FuncMaps from multiple sources (termenv + promptkit + custom).

**SugarCraft Design Principle:** Good pattern for extensibility — allow custom template functions to be added via composition.

---

## 21. Defensive Design Lessons

### 21.1 Document Edge Case Contracts

**Anti-pattern from Issue #27:** Empty choices behavior was undocumented, causing users hours of debugging.

**SugarCraft Defense:** Every public method documents its behavior for edge cases (empty input, null values, boundary conditions).

### 21.2 Export Necessary Internals

**Anti-pattern from Issue #25:** Non-exported `validateKeyMap` blocked legitimate use case of creating Confirmation models externally.

**SugarCraft Defense:** When designing composable widgets, export all types needed for composition. Private fields should be truly private, not just "not yet exported."

### 21.3 Pin Dependencies Carefully

**Anti-pattern from Issue #31:** Users pulling `v0.21.0` got a buggy version. Version constraints didn't protect them.

**SugarCraft Defense:** If depending on TTY frameworks:
1. Use strict version pinning for major versions
2. Have integration tests against multiple versions
3. Consider vendoring or copying relevant code to avoid upstream breakage

### 21.4 Test Unicode/Grapheme Handling

**Anti-pattern from Issue #31:** Upstream bug with multi-byte character rendering wasn't caught by existing tests.

**SugarCraft Defense:** Test suites must include:
- Emoji input (`💚💚`, `🎉🔥`)
- CJK characters (`你好`, `سلام`)
- Combining characters (`e\u0301` = é)
- Zero-width characters

---

## 22. Ecosystem Trends

### 22.1 Charmbracelet Ecosystem Dominance

PromptKit is one of many libraries building on Charmbracelet's TUI ecosystem:
- **bubbletea** — Application framework
- **bubbles** — Individual TUI components (textinput, textarea, filepicker, etc.)
- **lipgloss** — Styling
- **termenv** — Terminal environment detection

**Trend:** Ecosystem is actively maintained but makes breaking changes (v1→v2 migration).

### 22.2 Generics Adoption

Go 1.18+ generics enabling type-safe prompt libraries:
- PromptKit uses `Selection[T]` for compile-time type safety
- Avoids `interface{}` boxing

**Trend:** PHP 8+ union types and JIT improvements may enable similar patterns.

### 22.3 Widget Integration Pattern

PromptKit demonstrates "embeddable widget" pattern:
- Can run standalone via `RunPrompt()`
- Can embed in larger bubbletea program as `tea.Model`

**Trend:** Modularity and composability increasingly valued.

### 22.4 Template Customization

Growing expectation that TUI components are templateable:
- PromptKit uses Go `text/template`
- Users can completely redefine appearance

**Trend:** "Headless" or "templateable" UI components.

---

## 23. Strategic Opportunities

### 23.1 For SugarCraft

| Opportunity | Rationale |
|-------------|-----------|
| **Abstraction layers over bubbletea-equivalent** | PromptKit suffered from upstream breakage. SugarCraft should wrap TTY frameworks in stable abstractions. |
| **Better empty state handling** | PromptKit's tea.Quit pattern is flawed. SugarCraft can provide clearer patterns. |
| **Async-compatible design** | PromptKit is synchronous. Consider async from the start for background operations. |
| **Mouse support** | PromptKit never got mouse requests, but this is increasingly expected in 2026. |
| **Input history** | Shell-like up/down history navigation missing from PromptKit. |
| **Plugin ecosystem** | PromptKit stayed focused. SugarCraft could differentiate with extension points. |

### 23.2 Differentiation Opportunities

| Area | PromptKit Gap | SugarCraft Opportunity |
|------|---------------|------------------------|
| **API Stability** | Pre-1.0, breaking changes | Promise semver stability |
| **Documentation** | Missing edge case docs | Comprehensive docs with examples |
| **Test Coverage** | Not publicly visible | 100% snapshot + behavior coverage |
| **Windows Support** | Historically problematic | First-class Windows support |
| **Mouse/Async** | Not implemented | Modern features |
| **Extension Points** | None | Plugin system for custom prompts |

---

## 24. Cross-Ecosystem Pattern Matches

### 24.1 TUI Library Patterns

| Library | Key Insight |
|---------|-------------|
| **enquirer (Node)** | Async prompts, plugin system, built-in themes |
| **Inquirer (Python)** | Simple, no generics, type-unsafe |
| **prompts (Node)** | Combines TUI + non-TUI, beautiful defaults |
| **survey (Go)** | Simpler than PromptKit, no generics, older |
| **promptui (Go)** | No longer maintained |

**Observation:** PromptKit is most similar to `survey` but with generics. `enquirer` and `prompts` show where ecosystem is heading (async, plugins).

### 24.2 PHP TUI Landscape

| Library | Status |
|---------|--------|
| **php-terminal** | Abandoned |
| **Buzz来得** | Basic |
| **PsySH** | REPL only |

**Strategic Gap:** No modern, actively-maintained PHP TUI prompt library exists. SugarCraft could fill this void.

---

## 25. High ROI Recommendations

### 25.1 Immediate (High Impact, Low Effort)

1. **Add contextual validation error messages**
   - From Issue #7 user request
   - Adds `ValidateWithMessage(func(string) (bool, string))` pattern
   - High user satisfaction impact

2. **Add cursor looping option**
   - Simple `bool` field, straightforward to implement
   - Common shell behavior users expect

3. **Document empty-state contracts explicitly**
   - Prevent Issue #27-style confusion
   - Every model should document what happens with empty input

4. **Add comprehensive Unicode tests**
   - Catch grapheme cluster issues before users do
   - Test with emoji, CJK, combining characters

### 25.2 Short-term (High Impact, Medium Effort)

5. **Implement multi-selection with PreSelected**
   - PR #35, #38 show exactly what users want
   - `MinSelections`, `MaxSelections`, `PreSelected`
   - High-demand feature

6. **Build TextArea with auto-resize**
   - PR #36 implementation reference
   - Auto-expand height is key differentiator

7. **Add autocomplete with arrow-key browsing**
   - PR #37 improved on #30
   - Not just tab-completion, but full suggestion browsing

8. **Create abstraction layer over TTY framework**
   - Prevent upstream breakage (Issue #22 pattern)
   - Define stable internal interface

### 25.3 Medium-term (Strategic, Higher Effort)

9. **Design for async compatibility from start**
   - PromptKit is synchronous only
   - Future-proof for background operations

10. **Consider mouse support**
    - Never requested in PromptKit but increasingly expected
    - Selection highlights, clickable areas

11. **Build extension/plugin system**
    - PromptKit stayed monolithic
    - Could differentiate SugarCraft

12. **Promise semver stability (when ready)**
    - PromptKit pre-1.0 uncertainty
    - Clear stability contract builds trust

---

## Appendix: Data Sources

- GitHub API issues (37 closed, 0 open)
- GitHub API PRs (38 total, all closed)
- GitHub API commits (136 total)
- GitHub API contributors (4 total)
- GitHub API releases (10 versions v0.2.0 - v0.11.0)
- GitHub repo metadata (297 stars, 21 forks)

---

*Report generated: 2026-05-27*
*First-stage analysis: repo_map/erikgeiser_promptkit.md*
