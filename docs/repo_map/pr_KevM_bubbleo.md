# PR: Second-Stage Ecosystem Intelligence Report for KevM/bubbleo

## 1. Repository Overview

| Attribute | Value |
|---|---|
| **URL** | https://github.com/KevM/bubbleo |
| **Language** | Go |
| **Stars** | 69 |
| **Forks** | 3 |
| **License** | MIT |
| **Open Issues** | 1 |
| **Open PRs** | 3 |
| **Closed PRs** | 2 (1 merged, 1 dependabot) |
| **Releases** | v0.1.0 through v0.1.5 (Feb–Apr 2025) |
| **Activity Profile** | Low-volume niche TUI navigation library. Active but sparse community engagement. Maintainer-driven development with occasional external contributions. |

**Note on limited data**: This is a niche repository (~69 stars) with very limited community activity. Only 1 open issue, 3 open PRs, and a small number of total contributors. Patterns observed are statistically thin but strategically relevant. Some sections below are marked `LOW_SIGNAL` where data is insufficient for confident inference.

---

## 2. Existing SugarCraft Mapping

From `MATCHUPS.md`:

| Upstream | SugarCraft Port | Subdir | Status |
|---|---|---|---|
| KevM/bubbleo | **SugarCrumbs** | `sugar-crumbs/` | 🟢 |

The mapping is specifically to `sugar-crumbs` for breadcrumb rendering. However, the upstream `bubbleo` library encompasses multiple components:

| bubbleo Component | SugarCraft Mapping | Notes |
|---|---|---|
| `navstack.Model` | `sugar-prompt` (navigation), `candy-shell` (composition) | Stack-based navigation is distinct from SugarPrompt's form model |
| `menu.Model` | `sugar-bits` (list widget) | Wraps bubbles/list for menu selection |
| `breadcrumb.Model` | `sugar-crumbs` | Full mapping exists |
| `shell.Model` | `candy-shell` | Composite nav + breadcrumb |
| `window.Model` | `candy-core` | Offset-based dimension management |
| `Closable` interface | Not directly mapped | Resource cleanup pattern |
| `utils.Cmdize` | `SugarCraft\Core\Utils` | Message→Command wrapper |

**Gap**: The `navstack` (stack-based hierarchical navigation) is only partially mapped. SugarCraft lacks a first-class stack navigation primitive that mirrors bubbleo's NavStack.

---

## 3. Previously Identified Gaps

From `repo_map/KevM_bubbleo.md`:

- **No parallel navigation paths** — only linear push/pop
- **No back button management** — ESC handling is implementation-dependent
- **Closable errors not surfaced** — `Close()` errors collected but not shown to user
- **No state persistence** — Navigation state lost on quit
- **Tight bubbletea coupling** — Hard dependency on `tea.Model` interface
- **No animation/transition support** — Direct `View()` output, no animated transitions
- **Window size propagation** — Relies on `tea.WindowSizeMsg` being properly forwarded

---

## 4. High-Signal Open Issues

### Issue #5: "some advise" — Disposal + Async Data Loading Pattern

**Author**: seth-shi (opened Jul 7, 2025)
**Status**: Open, no responses

**Request Summary**:
```
1. Change Closable.Close() to return tea.Cmd instead of error
2. Add async data initialization hooks (onDataInit, onDataInited)
3. Support loading state in View
```

**Proposed API**:
```go
func (m *tea.Model) onDataInit() tea.Cmd {
    return func() tea.Msg {
        fetch http data  // async HTTP fetch
    }
}

func (m *tea.Model) onDataInited() {
    m.data = fetch http data
    m.loading = false
}

func (m *tea.Model) Dispose() tea.Msg {
    return dosomething  // return tea.Cmd instead of error
}

func (m *tea.Model) View() {
    if m.loading {
        return "loading"
    }
    return m.data
}
```

**Strategic Interpretation**:
- **Direct Risk to SugarCraft**: HIGH. If `sugar-crumbs` or related libs implement `Closable`-like cleanup, the same concern applies: cleanup commands cannot be properly sequenced in the current design.
- **Feature Opportunity**: SugarCraft should implement disposal-as-command pattern (`Dispose() tea.Cmd`) rather than `Close() error`. This allows proper cleanup sequencing in the TEA model lifecycle.
- **The async init pattern** (`onDataInit`/`onDataInited`) mirrors React's `componentDidMount` and is a common request for data-loading in TUI apps. SugarPrompt handles this differently (via its form fields), but a general-purpose async init lifecycle hook could be valuable across all SugarCraft models.

---

## 5. Important Closed Issues

**LOW_SIGNAL**: No closed issues found. All issues in the repo's history (1 total) are currently open.

---

## 6. Recurring Pain Points

**LOW_SIGNAL** due to repository size. However, the following can be inferred from PR patterns and releases:

| Pain Point | Evidence | Relevance to SugarCraft |
|---|---|---|
| **Resource cleanup timing** | Issue #5 requests `Dispose() tea.Cmd`; v0.1.5 added symmetry (close on push AND pop) | HIGH — SugarCraft's disposal patterns should handle both push and pop |
| **Menu help visibility control** | PR #4 requests control over initial help display state | MEDIUM — sugar-bits may have similar toggle needs |
| **Delimiter override in Shell** | PR #6 bugfix: Shell.View() was overriding configured delimiter | MEDIUM — candy-shell composition must preserve child configurations |
| **Quit-on-empty-stack behavior** | PR #2: Option to not quit when stack empties | MEDIUM — navigation models need configurable empty-state behavior |
| **Functional options API** | PR #3: Builder pattern via functional options for Menu config | MEDIUM — SugarCraft APIs should use functional options for optional params |

---

## 7. Frequently Requested Features

### PR #2: "Navstack: option to not quit on empty stack" (Open, by KevM)

**What it does**: Adds an option to prevent `tea.Quit` when the navigation stack becomes empty. Allows apps to continue running with an empty navstack rather than exiting.

**Why it matters**: Many apps want to show a "home" screen or dashboard when the stack empties, not immediately exit. Currently the only option is to never let the stack fully empty.

**SugarCraft Opportunity**: SugarCraft's navigation primitives should offer a configurable empty-stack behavior:
- `QuitOnEmpty` option (default: true for backwards compatibility)
- `ShowHomeOnEmpty` option — automatically navigate to a designated home screen
- `DoNothingOnEmpty` option — keep running in a suspended state

### PR #3: "feat(option): add support for functional option arguments" (Open, by ardnew)

**What it does**: Adds functional options pattern to `menu.Model` for configuration:
- `WithShowPagination(bool)`
- `WithShowTitle(bool)`
- `WithFilteringEnabled(bool)`
- `WithShowFilter(bool)`
- `WithShowStatusBar(bool)`
- `WithShowHelp(bool)`
- `WithAdditionalFullHelpKeys(func)`
- `WithAdditionalShortHelpKeys(func)`

**Why it matters**: This is the **canonical Go pattern for extensible APIs without breaking changes**. The PR author specifically chose it because "it makes it much easier to add optional configurations without breaking any existing code or external interfaces."

**SugarCraft Defensive Lesson**: All SugarCraft APIs that take optional configuration should use functional options. Do NOT use boolean parameters or builder methods that break when new options are added.

### PR #4: "Allow controlling displaying help" (Open, by clinta)

**What it does**: Allows apps to start with help text already displayed, and to prevent automatic hiding on keypress.

**Why it matters**: Some apps want help visible by default (e.g., CLI wizards, onboarding flows). Currently help is hidden on first keypress automatically.

**SugarCraft Opportunity**: sugar-bits should support configurable help visibility state at initialization time.

### PR #6: "Don't override configured delimiter in shell View" (Merged, by LaiArturs)

**What it does**: Fixed a bug where `Shell.View()` was hardcoding a newline delimiter between breadcrumb and navstack output, ignoring any user-configured delimiter.

**Before (buggy)**:
```go
func (m Model) View() string {
    bc := m.Breadcrumb.View()
    nav := m.Navstack.View()
    return lipgloss.NewStyle().Render(bc, nav)  // Hardcoded lipgloss.Join
}
```

**After (fixed)**:
```go
func (m Model) View() string {
    bc := m.Breadcrumb.View()
    nav := m.Navstack.View()
    // Uses configured delimiter or default
    return lipgloss.JoinHorizontal(lipgloss.Left, bc, nav)
}
```

**SugarCraft Lesson**: Always preserve user-configured lipgloss join delimiters. Don't assume a fixed layout behavior.

---

## 8. Important PRs

| PR | Status | Author | Significance |
|---|---|---|---|
| #6 | **Merged** | LaiArturs | Bugfix — shell delimiter override, important for composition correctness |
| #3 | Open | ardnew | API design — functional options pattern, extensive configuration surface |
| #2 | Open | KevM | Navigation behavior — empty-stack quit option |
| #4 | Open | clinta | UX control — help visibility toggle |
| #1 | Closed | dependabot | Dependency update only, not significant |

---

## 9. Architectural Changes

### v0.1.5: Push Symmetry + Init/WindowSizeMsg Ordering

**Change**: When pushing a new item onto the stack, the old top item is now also closed (symmetric with Pop behavior).

**Before**: Only Pop() called `Closable.Close()`. Push() did not close the displaced item.

**After**: Both Push() and Pop() call `Closable.Close()` on the displaced/top item.

**Additional Change**: `Init()` is now always called before `Update(WindowSizeMsg)` when pushing/popping, with resulting commands executed sequentially via `tea.Sequence`.

**Why this matters for SugarCraft**: This is an important lifecycle ordering decision. The pattern ensures:
1. Resources from old item are cleaned up
2. New item is initialized
3. New item receives window size
4. All in proper sequential order

SugarCraft should follow the same pattern: `Init` → `WindowSizeMsg` via `tea.Sequence`.

### v0.1.4: Menu Help State Machine Bug

**Bug**: Help text didn't disappear when help key was pressed a second time.

**Fix**: Proper state management for help visibility toggle.

**SugarCraft Lesson**: Help state is a common source of bugs in list/table/menu components. SugarCraft should test help toggle behavior explicitly.

### v0.1.3: Style API Exposure

**Change**: `SetShowTitle(bool)` added to menu, breadcrumb styling exposed for customization.

**SugarCraft Lesson**: Style APIs should be fully exposed, not hidden behind opinionated defaults. Allow users to control every visual aspect.

---

## 10. Performance Discussions

**LOW_SIGNAL**: No performance-related issues or discussions found.

**Inferred concern**: The `window.Model` offsets approach for layout avoids recomputation. SugarCraft's dimension tracking should follow similar principles — compute once, propagate deltas, don't recompute on every View().

---

## 11. Extensibility Discussions

### PR #3 Functional Options Pattern

The functional options pattern in PR #3 is the most significant extensibility discussion. It provides:

```go
// Before: rigid constructor
func New(title string, choices []Choice, selected *Choice) Model

// After: flexible with options
func New(title string, choices []Choice, selected *Choice, opts ...Option) Model
```

**SugarCraft Standard**: All SugarCraft constructor signatures should accept functional options for optional parameters. This is the Go idiomatic pattern that bubbleo itself is adopting.

### Closable Interface Extensibility

The `Closable` interface pattern:
```go
type Closable interface {
    Close() error
}
```

**SugarCraft Issue**: The current bubbleo `Closable` returns `error` but is fire-and-forget (errors are collected but not surfaced). Issue #5 proposes changing this to return `tea.Cmd` for proper command-based cleanup sequencing.

**Recommendation**: SugarCraft should design its disposal interface to return a command, not an error:
```php
interface Disposable {
    public function dispose(): ?Command;
}
```

This allows disposal actions (closing files, canceling coroutines, flushing buffers) to be properly sequenced in the ReactPHP/Tea event loop.

---

## 12. API/UX Complaints

**LOW_SIGNAL**: Only 1 open issue which is a feature request, no explicit complaints.

**Inferred from Issue #5**: The current `Closable.Close() error` pattern is confusing. Users expect disposal to integrate with the command system, not be a side-effect. The `Dispose() tea.Msg` request indicates users want disposal actions to be proper TEA commands.

**Inferred from PR #6**: The Shell.View() API was surprising — users expected it to respect configured delimiters but it was hardcoded.

---

## 13. Migration Problems

**LOW_SIGNAL**: No migration problems reported. The library is still pre-1.0 (v0.1.x).

**Inferred risk**: The v0.1.5 change to close on push (not just pop) could be a breaking change for users who relied on resources staying open when pushed down the stack. SugarCraft should document push-closure behavior clearly.

---

## 14. Clever Fixes & Workarounds

### tea.Sequence for Ordered Commands

The use of `tea.Sequence(pop, cmd)` instead of `tea.Batch` in navigation operations is a key pattern:

```go
// Correct: sequential, pop executes before subsequent command
return m, tea.Sequence(pop, cmd)

// Wrong: parallel, subsequent command may arrive before pop completes
return m, tea.Batch(pop, cmd)
```

**SugarCraft should adopt**: Navigation state changes should always use `tea.Sequence`, never `tea.Batch`, to ensure consistent ordering.

### Cmdize Utility Pattern

```go
func Cmdize[T any](t T) tea.Cmd {
    return func() tea.Msg { return t }
}
```

**SugarCraft equivalent exists in `SugarCraft\Core\Utils`**: The pattern is already known and should be used for all message→command conversions.

### Closable Error Aggregation

```go
func (m *Model) Clear() error {
    var errs []error
    for _, item := range m.stack {
        if c, ok := item.Model.(Closable); ok {
            if err := c.Close(); err != nil {
                errs = append(errs, err)
            }
        }
    }
    m.stack = []NavigationItem{}
    return errors.Join(errs...)
}
```

**SugarCraft should adopt**: Error aggregation in clear/reset operations using `errors.Join`.

---

## 15. Community Workarounds

**LOW_SIGNAL**: No community workarounds documented in issues or PRs.

---

## 16. Maintainer Guidance Patterns

### Release-Driven Documentation

The maintainer improved documentation in v0.1.1 because "there was severe lack of doc comments." This is a pattern: documentation is incrementally improved when users report confusion.

**SugarCraft lesson**: Start with comprehensive doc comments. Don't rely on users filing issues to discover documentation gaps.

### Ordering Guarantees

From v0.1.5 release notes: "The resulting commands will be invoked sequentially. This maybe overkill as there should not be coupling between model setup and window sizing."

**SugarCraft lesson**: Ordering guarantees should be intentional and documented, even if they seem "overkill." Users depend on predictable lifecycle ordering.

### Merged vs. Open PRs

Maintainer (KevM) has merged PR #6 (external contributor) but left PRs #2, #3, #4 open with no reviews or responses. This suggests:
- The library is in maintenance mode
- External PRs may not get reviewed promptly
- Focus is on internal improvements

**SugarCraft implication**: External contributions may need more proactive review from SugarCraft maintainers to avoid stagnation.

---

## 17. Rejected Ideas Worth Revisiting

**LOW_SIGNAL**: No explicitly rejected ideas found. PRs #2, #3, #4 are open but unmerged — these are not rejected, just pending.

**However**: The lack of maintainer response to PRs #3 and #4 (open since Aug/Oct 2024, no reviews) may indicate indirect rejection or lack of priority.

---

## 18. Problems Likely Relevant To SugarCraft

| Problem | Direct Risk | Severity |
|---|---|---|
| **Disposal not integrated with command system** | HIGH | `Closable.Close() error` is fire-and-forget. If SugarCraft uses similar disposal, cleanup actions can't be sequenced properly. |
| **Empty-stack quit behavior is rigid** | MEDIUM | sugar-crumbs/nav should allow configurable empty-stack behavior |
| **Help state machine bugs** | MEDIUM | Help visibility toggle is error-prone. SugarCraft should test this explicitly. |
| **Shell composition overrides child config** | MEDIUM | candy-shell must preserve child configuration (like the delimiter bug in PR #6) |
| **No async data loading lifecycle** | MEDIUM | Issue #5's `onDataInit`/`onDataInited` pattern is a common need. sugar-prompt handles this for forms, but general models need a lifecycle hook. |
| **Functional options not used in v0.1.0 API** | LOW | Early API was designed before functional options adoption. PR #3 is retroactively fixing this. SugarCraft should use functional options from the start. |

---

## 19. Features SugarCraft Should Consider

### 1. Disposal-as-Command Pattern (Priority: HIGH)

Instead of `Closable.Close() error`, implement `Disposable.dispose(): ?Command`:

```php
interface Disposable {
    public function dispose(): ?Command;
}
```

This allows disposal to schedule async cleanup, send completion messages, or chain other commands.

**Implementation note**: The command can be run via `tea.Sequence` during pop/push operations.

### 2. Async Init Lifecycle Hook (Priority: MEDIUM)

Add support for async data loading in models:

```php
interface AsyncInitializable {
    public function onDataInit(): ?Command;
    public function onDataInited(mixed $data): void;
}
```

The `onDataInit` command runs during `Init()`. When it completes, the returned data is passed to `onDataInited()` before the first `View()`.

### 3. Functional Options API (Priority: HIGH)

All SugarCraft constructors that take optional parameters should use functional options:

```php
// Good
public function __construct(Options ...Option $options);

// Option interface
interface Option {
    public function apply(Model $model): void;
}
```

**Libraries to update**: sugar-bits (many components), sugar-prompt, sugar-charts.

### 4. Empty-Stack Behavior Configuration (Priority: MEDIUM)

Navigation models should accept behavior options:

```php
class NavigationOptions {
    public bool $quitOnEmpty = true;
    public ?Model $homeOnEmpty = null;
    public bool $suspendOnEmpty = false;
}
```

### 5. Configurable Help Visibility (Priority: LOW-MEDIUM)

Similar to PR #4, sugar-bits components should allow:
- Initial help visibility state
- Prevention of auto-hide on first keypress
- Programmatic help toggle

---

## 20. Architectural Lessons

### Lesson 1: Lifecycle Ordering is Sacred

The v0.1.5 change to ensure `Init()` → `WindowSizeMsg` ordering via `tea.Sequence` is a **correct architectural decision**. In TEA, lifecycle operations must be explicitly ordered because the runtime is message-driven and concurrent.

**SugarCraft rule**: Any multi-step lifecycle operation must use `tea.Sequence` (or equivalent) to ensure ordering. Never assume operations complete in the order written.

### Lesson 2: Push Symmetry for Resource Management

Closing the displaced item on push (v0.1.5) is architecturally correct. Resources attached to a view that is no longer visible should be released, regardless of whether it's a push or pop operation.

**SugarCraft rule**: When navigating away from a view (push or pop), always call disposal on the displaced item.

### Lesson 3: Closable Returns Command, Not Error

The `Close() error` signature is a design mistake. Error-based disposal:
- Cannot schedule async cleanup
- Cannot chain subsequent commands
- Cannot integrate with the message system

**SugarCraft rule**: Disposal methods must return commands, not errors. Errors can be communicated via result messages.

### Lesson 4: Composition Must Preserve Configuration

The PR #6 bug (Shell.View() overriding delimiter) demonstrates a common composition error: parent components modifying child output in surprising ways.

**SugarCraft rule**: Composite components must not modify the output of child components except through explicit, documented channels. Use lipgloss.Join with explicit parameters, don't assume defaults.

### Lesson 5: Functional Options are the Default for Optional Params

PR #3 explicitly chose functional options because "it makes it much easier to add optional configurations without breaking any existing code or external interfaces." This is the canonical Go (and PHP) pattern for API evolution.

**SugarCraft rule**: Use functional options for all optional parameters. Do not use boolean flags or builder methods that break callers when new options are added.

---

## 21. Defensive Design Lessons

| Anti-Pattern | bubbleo Mistake | SugarCraft Defense |
|---|---|---|
| **Dispose returns error** | `Close() error` is fire-and-forget | Return `?Command` for sequenced disposal |
| **Rigid quit behavior** | App always quits on empty stack | Configurable empty-stack behavior |
| **Help state bugs** | v0.1.4: help not disappearing | Explicit help state machine tests |
| **Composition override** | Shell hardcoded delimiter | Explicit lipgloss.Join with user config |
| **Boolean config params** | v0.1.0: `SetShowTitle(bool)` awkward | Functional options from the start |

---

## 22. Ecosystem Trends

### Trend 1: Functional Options Adoption in Go TUI Ecosystem

The adoption of functional options in PR #3 reflects a broader trend in the Go TUI ecosystem (and Go ecosystem generally) toward this pattern for API extensibility. Libraries like `charmbracelet/huh` (upstream of SugarPrompt) use functional options extensively.

**SugarCraft alignment**: This is already the intended pattern per AGENTS.md guidelines.

### Trend 2: Async Data Loading in TUI

The request in Issue #5 for `onDataInit`/`onDataInited` hooks reflects a common need: TUI applications frequently need to load data asynchronously (HTTP, database, file I/O) and display loading states.

**SugarCraft gap**: SugarPrompt handles this for form fields but there's no general-purpose async init lifecycle. This could be a SugarCraft-wide pattern.

### Trend 3: Lifecycle Command Sequencing

The careful use of `tea.Sequence` in bubbleo v0.1.5 reflects an increasing awareness in the bubbletea ecosystem that lifecycle operations need explicit ordering guarantees.

**SugarCraft alignment**: SugarCraft already follows this via its ReactPHP integration, but the pattern should be explicitly documented.

### Trend 4: Resource Cleanup Symmetry

The v0.1.5 push-symmetry change (closing on push AND pop) reflects a maturing understanding: resources attached to views should be cleaned up when views are displaced, whether by push or pop.

**SugarCraft rule**: Disposal is bidirectional — applies on both push and pop.

---

## 23. Strategic Opportunities

### 1. First-Class Stack Navigation Library

**Opportunity**: SugarCraft lacks a dedicated stack navigation primitive. `sugar-prompt` handles forms, `candy-shell` is a CLI shell, but neither provides the bubbleo-style `NavStack` with push/pop/close semantics.

**Recommendation**: Consider creating `sugar-nav` (or expanding `sugar-crumbs`) to provide:
- `NavigationStack` model with configurable empty behavior
- `NavigationItem` with `Disposable` disposal
- `Push`/`Pop`/`Clear` operations returning commands
- Breadcrumb integration

### 2. Disposal-as-Command Pattern

**Opportunity**: SugarCraft can implement disposal correctly from the start, avoiding the design mistake in bubbleo's `Closable`.

**Recommendation**: Define `Disposable` interface in `candy-core`:
```php
interface Disposable {
    public function dispose(): ?Command;
}
```

All navigation items and long-lived views should implement this.

### 3. Async Init Lifecycle

**Opportunity**: Implement a general-purpose async init pattern that works across all SugarCraft models, not just forms.

**Recommendation**: Add to `candy-core` base model:
```php
trait AsyncInitializable {
    private bool $loading = false;
    private mixed $data = null;
    
    public function onDataInit(): ?Command {
        return null;
    }
    
    public function onDataInited(mixed $data): void {
        $this->data = $data;
        $this->loading = false;
    }
}
```

### 4. Functional Options as Standard

**Opportunity**: Ensure ALL SugarCraft APIs use functional options for optional parameters.

**Recommendation**: Audit all existing SugarCraft constructors and add functional options where missing. This is especially important for `sugar-bits` components and `sugar-charts`.

---

## 24. Cross-Ecosystem Pattern Matches

| Pattern | bubbleo | SugarCraft Equivalent | Notes |
|---|---|---|---|
| **Stack navigation** | `navstack.Model` | Needs `sugar-nav` | Push/pop/close semantics |
| **Closable** | `Closable interface` | Needs `Disposable` | Return `Command`, not `error` |
| **Cmdize** | `utils.Cmdize<T>` | `SugarCraft\Core\Utils::cmdize` | Already exists |
| **tea.Sequence** | Navigation ordering | ReactPHP sequence pattern | Ensure ordering guarantees |
| **Functional options** | PR #3 (pending) | Should use in all libs | Standard Go/PHP pattern |
| **Window offsets** | `window.Model` | `candy-core` dimension | Compute-once, propagate deltas |
| **Closable on push** | v0.1.5 | Implement in navigation | Bidirectional disposal |

---

## 25. High ROI Recommendations

### Priority 1: Fix Disposal Pattern (HIGH ROI)

**Action**: Create `Disposable` interface in `candy-core` that returns `?Command`. Update `sugar-crumbs` and any navigation model to use it.

**Why**: The current `Closable.Close() error` pattern cannot integrate with the command system. This is a foundational pattern used everywhere — fixing it early prevents cascading rework.

**Effort**: Low. Just a new interface and update to navigation models.

### Priority 2: Add Navigation Stack Library (MEDIUM-HIGH ROI)

**Action**: Create `sugar-nav` library providing the stack navigation pattern from bubbleo.

**Why**: SugarCraft has no first-class stack navigation. Apps using SugarCraft for complex navigation need this primitive. The pattern is well-understood from bubbleo.

**Effort**: Medium. Requires full implementation with tests.

### Priority 3: Adopt Functional Options Everywhere (MEDIUM ROI)

**Action**: Audit all SugarCraft constructors. Add functional options where boolean flags or many constructor params exist.

**Why**: This prevents API breakage as the libraries evolve. Functional options are the Go/PHP standard for extensible APIs.

**Effort**: Medium. Needs systematic audit and refactor.

### Priority 4: Add Async Init Lifecycle (MEDIUM ROI)

**Action**: Add `AsyncInitializable` trait to `candy-core` and document the pattern.

**Why**: Issue #5 shows users need async data loading in TUI models. Having a standard pattern prevents ad-hoc solutions.

**Effort**: Low-Medium. A trait and documentation.

### Priority 5: Configure Empty-Stack Behavior (LOW-MEDIUM ROI)

**Action**: Add `quitOnEmpty`, `homeOnEmpty`, `suspendOnEmpty` options to navigation stack.

**Why**: PR #2 shows this is a real user need. Apps want to show a home screen, not quit, when navigation empties.

**Effort**: Low. Just additional options on navigation model.

---

## Appendix: Data Quality Notes

This report is based on:
- 1 open issue
- 3 open PRs (2 by external contributors, 1 by maintainer)
- 2 closed/merged PRs
- 6 releases (v0.1.0 - v0.1.5)
- Source code (navstack, menu, shell)

**Confidence**: LOW for recurring patterns (insufficient statistical base). MEDIUM-HIGH for architectural decisions and design principles (clear from code and PRs). The absence of issues/PRs should not be interpreted as absence of problems — niche repos with low activity often have unreported pain.

---

*Report generated: Second-Stage Ecosystem Intelligence Analysis*
*Repo: KevM/bubbleo (https://github.com/KevM/bubbleo)*
