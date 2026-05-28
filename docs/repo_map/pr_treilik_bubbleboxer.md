# Second-Stage Ecosystem Intelligence Report: treilik/bubbleboxer

## Repository Overview

| Attribute | Value |
|-----------|-------|
| **URL** | https://github.com/treilik/bubbleboxer |
| **Language** | Go |
| **Stars** | 84 |
| **Forks** | 7 |
| **Watchers** | 4 |
| **License** | MIT |
| **Total Commits** | 26 |
| **Open Issues** | 2 |
| **Closed Issues** | 0 |
| **Open PRs** | 0 |
| **Closed PRs** | 0 |
| **Discussions** | None (404 - not enabled) |
| **Releases** | 2 tags |
| **Dependencies** | charmbracelet/bubbles v0.14.0, charmbracelet/bubbletea v0.21.0, muesli/ansi |
| **Go Version** | 1.17+ |

**Ecosystem Note**: This is a **very niche repository** with minimal community activity. Both open issues remain unresolved after 2-3 years. No PRs have ever been submitted. The maintainer appears to be the sole contributor (26 commits all from treilik). This low-activity profile limits signal strength but also reveals a library that has **not evolved to address real user pain**.

---

## Existing SugarCraft Mapping

From `repo_map/treilik_bubbleboxer.md`, the following mappings were identified:

| bubbleboxer | SugarCraft Equivalent | Notes |
|-------------|----------------------|-------|
| `Boxer` struct + `LayoutTree` | `candy-core` viewport/layout | Tree-based vs termbox-cell |
| `Node.VerticalStacked` | `candy-shine` table layout | Row/column orientation |
| `CreateLeaf()` + `ModelMap` | `SugarCraft\Core\I18n\T` | Address/key lookup indirection |
| `SizeFunc` | `honey-bounce` layout weights | Flex-weight/grid-template sizing |
| `EditLeaf()` safe edit | Immutable `with*()` builders | Functional edit vs copy-on-write |
| `View()` rendering | `sugar-bits` view pipeline | String output for terminal |
| `renderHorizontal/Vertical` | `candy-shine` table rendering | Separator-based cell rendering |
| `UpdateSize()` propagation | `candy-core` resize handling | Size constraint hierarchy |
| `tea.Model` interface | SugarCraft `Model` contract | Charmbracelet TEA pattern |

---

## Previously Identified Gaps

From `repo_map/treilik_bubbleboxer.md`:
- No recursive `noBorder` (descendants still render borders)
- Fixed border characters (package-level vars, global effect)
- No built-in scrolling/overflow handling (fails rather than clips)
- Manual tree construction (no builder pattern)
- No automatic resize handling (user must call `UpdateSize()` manually)
- No flex/gap concept (borders consume actual character cells)
- Single-threaded rendering
- Limited layout algorithms (only even-split and custom `SizeFunc`)

---

## High-Signal Open Issues

### Issue #7: "Resizing Examples" (Aug 17, 2022, greenm01)

**Signal Strength**: Medium — represents a documentation gap, not a bug

**Full Issue**:
> Hello,
>
> First, thanks for creating this cool little library for bubbletea!
>
> I'd like to create a system of menu "tabs," each with it's own Boxer as part of my CLUI. I'm trying to understand how to specify the width and hight of child nodes at creation, and also during runtime.
>
> Would you please provide some additional examples on how to accomplish sizing and resizing boxes?
>
> Thanks!

**Analysis**:
- **Problem**: User wants to create a "tabbed" interface where each tab has its own Boxer
- **Pain Point**: No documentation on runtime resizing — the only mechanism is `SizeFunc` but it's not clear how to update it dynamically
- **Root Cause**: bubbleboxer has no API for mutating node sizes after tree construction. The `SizeFunc` is set at tree-creation time and is static.
- **No Response**: Issue has zero comments from maintainer, unresolved for 3+ years

**Implication for SugarCraft**:
- SugarCraft must provide **clear, discoverable APIs for runtime resize** — not just initial sizing
- A tabbed interface pattern suggests demand for **dynamic layout reconfiguration** (swapping subtrees, not just resizing)

---

### Issue #6: "How to access Node to get size information?" (May 28, 2022, Robert-M-Muench)

**Signal Strength**: High — reveals fundamental API design flaw

**Full Issue**:
> I need to access the size information of a node/leaf.
>
> `ModelMap` returns `tea.Model` but how to get the matching node for my model? I think that's missing.

**Analysis**:
- **Problem**: User has a `tea.Model` from `ModelMap` and wants to know its allocated size (width/height)
- **Pain Point**: The `Node` struct is decoupled from `tea.Model` — they are related only by address string
- **No Inverse Lookup**: `ModelMap["address"]` → `tea.Model`, but no way to go backwards: `tea.Model` → address → `Node`
- **Root Cause**: The design deliberately separates layout (Node tree) from content (ModelMap). This is a clean separation but makes some use cases impossible without exposing a reverse mapping.
- **No Response**: Issue has zero comments, unresolved for 3+ years

**Implication for SugarCraft**:
- SugarCraft should consider **providing bidirectional lookups** between layout constraints and contained components
- A component should be able to **query its allocated bounds** — this is essential for self-aware rendering
- The separation pattern (layout tree vs. component store) is good, but needs **query APIs** to be complete

---

## Important Closed Issues

**None** — all issues are open. No closed issues to analyze.

---

## Recurring Pain Points

### Pain Point 1: Static Layout After Construction

**Evidence**: Issue #7 explicitly requests runtime sizing control that doesn't exist

**Manifestation**:
```go
// SizeFunc is set at tree-creation and is static
m.tui.LayoutTree = boxer.Node{
    SizeFunc: func(_ boxer.Node, widthOrHeight int) []int {
        return []int{1, widthOrHeight - 2, 1}  // Fixed weights
    },
    Children: []boxer.Node{...},
}
```

**Impact**: Users cannot implement:
- Proportional resizing (e.g., sidebar shrinks to minimum, main content takes rest)
- Dynamic layouts based on content (e.g., accordion panels)
- User-resizable panes

**SugarCraft Risk**: HIGH — If SugarCraft's layout primitives don't support runtime size mutation, users will hit the same wall.

---

### Pain Point 2: Node↔Model Disconnection

**Evidence**: Issue #6 and the architecture itself

**Manifestation**:
- `ModelMap`: `string` (address) → `tea.Model`
- `Node`: Contains `address` string, `width`, `height`
- No reverse lookup from `tea.Model` to `Node`

**User Workflow Problem**:
1. Component (e.g., viewport) needs to know its allocated size
2. Component receives `tea.WindowSizeMsg` during `Update()`
3. But the component has no reference to the `Node` that allocated its size
4. The `Node.width` and `Node.height` are available but unreachable

**SugarCraft Risk**: MEDIUM — SugarCraft's termbox-cell approach doesn't have this problem (cells know their bounds). But if SugarCraft develops component-wrapper patterns, this could become relevant.

---

### Pain Point 3: Border Space is Consumed from Children

**Evidence**: `updateSize()` in source code (lines ~217-227)

**Code**:
```go
// reduce size for children if border is set
if !n.noBorder {
    length := len(n.Children)
    if n.VerticalStacked {
        size.Height -= length - 1  // Border chars subtract from child space
    } else {
        size.Width -= length - 1
    }
}
```

**Impact**:
- Borders are not CSS-style "outside" decorations — they consume child space
- This means shrinking a border makes children larger, and vice versa
- The `CreateNoBorderNode()` flag is explicitly documented as "not recursive"

**SugarCraft Risk**: LOW — SugarCraft's cell-based rendering handles this differently (styles are overlaid, not space-consuming).

---

## Frequently Requested Features

Based on open issues and commit history analysis:

### Feature 1: Runtime Resize Examples/Documentation

**Requested**: Issue #7 explicitly asks for examples on sizing at creation and **runtime**

**Unmet Need**: Documentation showing:
- How `SizeFunc` can be replaced dynamically
- How to implement draggable dividers
- How to implement collapsible panels

**SugarCraft Opportunity**: Provide comprehensive resize examples as part of core documentation.

---

### Feature 2: Node-to-Model Reverse Lookup

**Requested**: Issue #6 asks to access `Node` given a `tea.Model`

**Unmet Need**: Bidirectional mapping between layout tree nodes and component models

**SugarCraft Opportunity**: If SugarCraft adopts a similar separation pattern, provide explicit reverse-lookup APIs.

---

### Feature 3: Bidirectional Node↔Model Mapping

**Not explicitly requested but implied by Issue #6**

**SugarCraft Opportunity**: The ModelMap pattern is sound, but needs complementing APIs:
- `GetNodeForModel(model tea.Model) (Node, error)` 
- `GetSizeForModel(model tea.Model) (width, height int, error)`

---

## Important PRs

**None** — Zero PRs have been opened or closed. This is a single-maintainer project with no external contributions.

---

## Architectural Changes (From Commit History)

### Change 1: Panics → Errors (Dec 17, 2022)

**Commit**: `feat: replace panics through errors` (610a2bba)

**Before**: Library panicked on invalid states (e.g., insufficient size)
**After**: Returns `error` types (`SizeError`, `NotFoundError`)

**Rationale**: For a library embedded in a larger application, panics are fatal. Error returns allow graceful degradation.

**SugarCraft Lesson**: Libraries should **never panic** — always return errors. SugarCraft follows this pattern correctly.

---

### Change 2: UpdateSize Returns Error (Jan 31, 2022)

**Commit**: `change UpdateSize to return error` (8ab2baf)

**Before**: `UpdateSize(size tea.WindowSizeMsg)` — void return
**After**: `UpdateSize(size tea.WindowSizeMsg) error`

**Rationale**: Size updates can fail (e.g., window too small). Caller needs to handle this.

**SugarCraft Implication**: SugarCraft's resize handling should similarly propagate errors and allow the application to display "waiting for size" state gracefully.

---

### Change 3: EditLeaf Safe Editing (Dec 16, 2022)

**Commit**: `add EditLeaf method to allow saver way for changing leafs` (085b559)

**Pattern**:
```go
func (b *Boxer) EditLeaf(address string, editFunc func(tea.Model) (tea.Model, error)) error
```

**Rationale**: Previously, modifying a leaf required manual lookup, mutation, and re-store. `EditLeaf` provides atomic modification with rollback on error.

**SugarCraft Alignment**: SugarCraft's `with*()` immutable builders serve a similar purpose. The key difference: bubbleboxer mutates in place (with undo), SugarCraft copies on write.

---

### Change 4: Fail Early on Size Violation (Dec 20, 2022)

**Commit**: `feat: fail early if model-view-string breaks the size boundary` (a999627)

**Behavior**: During rendering, if a leaf's `View()` returns more lines than `Node.height` or a line wider than `Node.width`, rendering fails immediately with descriptive error.

**Rationale**: Better to fail visibly than produce garbled output.

**SugarCraft Implication**: SugarCraft's viewport/layer system should have similar **overflow clipping** — either clip silently or fail visibly, not produce broken output.

---

## Performance Discussions

**None found** — No issues or commits discuss performance. The library is small enough (~455 lines) that performance hasn't been a concern.

---

## Extensibility Discussions

**None found** — No community discussion about extending the library.

---

## API/UX Complaints

### Complaint 1: Opaque Error Messages from SizeFunc Mismatch

**Location**: `updateSize()` lines ~278-282

**Code**:
```go
if len(sizeList) != len(n.Children) {
    return fmt.Errorf("SizeFunc returned %d WindowSizeMsg's but want one for each child and thus: %d", len(sizeList), len(n.Children))
}
```

**UX Problem**: Error says what was returned vs. expected but doesn't say **which node** caused the problem. This makes debugging complex layouts difficult.

**SugarCraft Lesson**: Error messages should include **context path** (e.g., "at node '/root/left-panel/main-viewport': ...").

---

### Complaint 2: CreateLeaf is the Only Valid Leaf Creation

**Evidence**: `CreateLeaf` docstring and `UpdateSize` validation

**Code**:
```go
// From CreateLeaf docstring:
// "Change it as you like as long as every node without children was
// created with CreateLeaf (to make sure that every leave has a corresponding ModelMap entry)"
```

**UX Problem**: Enforcing leaf creation through a factory method is correct, but the constraint is **implicit** — there's no runtime check preventing direct `Node{}` construction with an address.

**SugarCraft Lesson**: Factory constraints should be enforced by making the constructor private and providing a single factory method.

---

## Migration Problems

**None found** — No migration issues reported (no v2/v3, no breaking changes in commits).

---

## Clever Fixes & Workarounds

### Workaround 1: Manual WindowSizeMsg Forwarding

**Evidence**: Example code (`examples/main.go` lines ~90-95)

```go
case tea.WindowSizeMsg:
    m.tui.UpdateSize(msg)
```

**Problem**: The `Boxer.Update()` method does **not** automatically forward `WindowSizeMsg` to leaf models. The embedding application must do this manually in its `Update()` method.

**SugarCraft Implication**: SugarCraft's model lifecycle should handle size propagation **automatically** rather than requiring manual forwarding in user code.

---

### Workaround 2: Wrapper Types for Non-Model Types

**Evidence**: Example code

```go
type stringer string  // Wraps string as tea.Model
type spinnerHolder struct { m spinner.Model }  // Wraps spinner
type viewPortHolder struct { m viewport.Model }  // Wraps viewport
```

**Problem**: bubbleboxer only accepts `tea.Model` — you can't pass raw strings or primitive types without wrapping them in a struct that satisfies the interface.

**SugarCraft Lesson**: SugarCraft should consider **adapter patterns** or **built-in wrappers** for common types to reduce boilerplate.

---

## Community Workarounds

**None found** — No community discussions (discussions not enabled). Issues have zero comments.

---

## Maintainer Guidance Patterns

**Pattern 1: Implicit Constraints via Documentation**
- The `CreateLeaf` requirement is documented but not enforced by type system
- `noBorder` non-recursiveness is documented but not enforced

**Pattern 2: TODO Comments in README**
- README contains: `// TODO write about the need for embedding boxer into another model and about nobordernodes being non recursive`
- This indicates **incomplete documentation** that users have noticed (Issue #7 requests more examples)

**Pattern 3: Silent Unresolved Issues**
- Both open issues have zero maintainer responses
- No labels, no milestones, no development branches

**SugarCraft Lesson**: 
- Don't leave TODO comments in user-facing documentation
- Respond to all issues, even to say "won't fix" or "consider this workaround"
- Use labels and milestones to signal priority

---

## Rejected Ideas Worth Revisiting

### Idea 1: Runtime Size Function Updates

**Implicitly Rejected**: Issue #7 asks for runtime resizing but no response or code change

**Why It Might Be Rejected**: Implementing dynamic `SizeFunc` updates would require:
- Thread-safe mutation of the tree during rendering
- Re-rendering affected subtrees
- Potential for layout thrashing

**SugarCraft Opportunity**: Implement **immutable layout descriptions** where a new tree is constructed for new sizes (via `with*()` pattern), avoiding mutation during render.

---

### Idea 2: Reverse Model→Node Lookup

**Implicitly Rejected**: Issue #6 requests reverse lookup but no response or code change

**Why It Might Be Rejected**: Adding a reverse map would:
- Require synchronizing two maps on every leaf operation
- Increase memory footprint
- Introduce potential for inconsistency

**SugarCraft Opportunity**: Rather than a reverse map, provide **query methods** that traverse the tree and return size information:
```php
// SugarCraft equivalent concept
$node = $layout->findNodeByComponent($component);
$bounds = $node->getBounds();
```

---

## Problems Likely Relevant To SugarCraft

### Problem 1: Layout Tree Mutation During Render

**Direct Risk**: HIGH

The `updateSize()` and `render()` methods mutate `Node.width` and `Node.height` directly. This means:
- The tree is effectively **write-once, read-many** for sizes
- Concurrent renders (if ever supported) would race
- It's impossible to implement "preview" layouts before committing

**SugarCraft Implication**: SugarCraft's layout system should use **immutable nodes** — creating new nodes with updated sizes rather than mutating in-place.

---

### Problem 2: No Overflow/Clipping Strategy

**Direct Risk**: MEDIUM

When a leaf's `View()` exceeds its allocated bounds, bubbleboxer **fails with an error**. There's no:
- Automatic scrolling
- Truncation with ellipsis
- "Overflow" indicator

**SugarCraft Implication**: SugarCraft's viewport components should have explicit overflow strategies (scroll, clip, error).

---

### Problem 3: Single-Model Leaf Constraint

**Direct Risk**: LOW (for SugarCraft's cell-based approach)

Each leaf holds exactly **one** `tea.Model`. You cannot have a leaf that contains multiple models with internal layout.

**SugarCraft Implication**: If SugarCraft wraps external components, consider supporting **composite leaves** that contain sub-layouts.

---

## Features SugarCraft Should Consider

### Feature 1: Layout Query API

Provide a way to ask "what bounds would this component get in the current layout?"

```php
// SugarCraft concept
$bounds = $viewport->queryBounds();
$layout->getBoundsFor($componentId);
```

**Rationale**: Issue #6 shows users need to know allocated size, not just receive `WindowSizeMsg`.

---

### Feature 2: Immutable Layout Descriptions

Allow layouts to be described as immutable objects that can be efficiently "recomputed" for new sizes.

```php
// SugarCraft concept - create new layout from existing with modified sizes
$newLayout = $layout->withSize(80, 24);
$newLayout = $layout->withFlexWeight('sidebar', 1.0);
```

**Rationale**: Addresses Issue #7's request for runtime resize without mutation during render.

---

### Feature 3: Builder Pattern for Tree Construction

```php
// SugarCraft concept
$layout = Layout::vertical()
    ->add(Layout::horizontal()
        ->add($sidebar)
        ->add($mainContent)
        ->flexWeight(1.0, 2.0))
    ->add($statusBar);
```

**Rationale**: bubbleboxer's manual `Node{}` construction is verbose and error-prone. Builder pattern would improve DX.

---

### Feature 4: Comprehensive Resize Examples

Document the resize pattern thoroughly:
- Initial sizing via constructor
- Runtime resize via `withSize()` or similar
- Proportional (flex-weight) sizing
- Minimum-size constraints
- Drag-to-resize divider pattern

**Rationale**: Issue #7 explicitly requests this.

---

## Architectural Lessons

### Lesson 1: Separate Layout from Content — But Provide Queries

The ModelMap/Node separation is architecturally sound (models don't know about layout). But the lack of **query APIs** (Issue #6) makes the separation feel incomplete.

**Action**: When separating concerns, always provide **read access** to the relationship.

---

### Lesson 2: Error Messages Need Context Paths

The `wrapError()` helper exists but isn't used consistently. Users get errors like "model has too much lines" without knowing **which model**.

**Action**: Every error should include the path from root to the failing node.

---

### Lesson 3: Documentation Debt Compounds

The TODO in the README about "nobordernodes being non recursive" is a **known documentation gap** that users have encountered (Issue #7 mentions confusion about sizing).

**Action**: Don't leave TODO comments in user-facing docs. Resolve documentation gaps before they become issues.

---

## Defensive Design Lessons

### Lesson 1: Never Mutate During Render

The `updateSize()` mutates `Node.width/height` which are then read by `render()`. This creates a temporal coupling — you must call `UpdateSize()` before `View()`.

**SugarCraft Pattern**: Separate layout calculation (immutable, produces a "computed layout") from rendering (reads computed layout).

---

### Lesson 2: Provide Atomic Operations for Shared State

`EditLeaf` was added to provide atomic read-modify-write on leaf models. Without it, users would do:
```go
model := b.ModelMap[addr]
model, err := edit(model)
if err != nil { return err }
b.ModelMap[addr] = model  // Gap where another goroutine could see inconsistent state
```

**SugarCraft Pattern**: All mutable shared state should have atomic operations or proper locking.

---

### Lesson 3: Fail Fast on Impossible Layouts

The `fail early if model-view-string breaks the size boundary` commit ensures garbage output isn't produced.

**SugarCraft Pattern**: Enable a "strict mode" where overflow is an error, not silent clipping.

---

## Ecosystem Trends

### Trend 1: Charmbracelet Ecosystem Growth

bubbleboxer depends on charmbracelet/bubbletea v0.21.0 (now v0.25+ in 2024). The ecosystem has grown significantly.

**SugarCraft Insight**: SugarCraft ports of charmbracelet components should track upstream versions and ensure compatibility.

---

### Trend 2: Layout Libraries for Bubbletea

Several layout libraries exist for bubbletea (lipgloss, bubbleboxer, huh, etc.). This shows **demand for composition tools** beyond basic components.

**SugarCraft Insight**: SugarCraft should position itself as the **PHP equivalent** of these layout tools.

---

## Strategic Opportunities

### Opportunity 1: PHP Layout Builder

bubbleboxer has no builder pattern. SugarCraft could implement:
```php
$layout = Layout::vertical()
    ->gap(1)
    ->border('─')
    ->add($component);
```

**Advantage over bubbleboxer**: Better DX, discoverable API.

---

### Opportunity 2: Query APIs for Layout Bounds

SugarCraft could expose:
```php
$bounds = $layout->getBounds('sidebar');
$path = $layout->getPath($componentId);
```

**Advantage over bubbleboxer**: Addresses Issue #6's core pain.

---

### Opportunity 3: Immutable Layout Descriptions

SugarCraft could implement:
```php
$layout = $rootLayout->withChildren(
    $sidebar->withSize(20, null),  // 20 wide, auto height
    $main->withFlexWeight(2.0)
);
```

**Advantage over bubbleboxer**: No mutation during render.

---

## Cross-Ecosystem Pattern Matches

### Pattern: Model-Layout Separation

**Ecosystem**: bubbletea (bubbleboxer), React (props/state), Flutter (widget tree)

**Insight**: This is a **universal pattern** for component-based UIs. The key is providing good **query/inspection APIs** alongside the separation.

---

### Pattern: Size Propagation Down Tree

**Ecosystem**: Flutter (LayoutBuilder, MediaQuery), Android (MeasureSpec), CSS (containing block)

**Insight**: bubbleboxer's `updateSize()` is a simplified version of the cascade. SugarCraft should ensure size constraints propagate correctly through nested containers.

---

## High ROI Recommendations

### Recommendation 1: Document Resize Patterns (High ROI)

**Effort**: Low — documentation only
**Impact**: High — reduces issue #7 class of problems
**Action**: Add comprehensive resize examples to `candy-core` or `sugar-bits` documentation

---

### Recommendation 2: Implement Immutable Layouts (High ROI)

**Effort**: Medium — architectural change
**Impact**: High — enables concurrent renders, preview layouts
**Action**: Add `withSize()`, `withFlexWeight()` to layout node classes

---

### Recommendation 3: Add Bidirectional Lookup APIs (Medium ROI)

**Effort**: Low — adds query methods
**Impact**: Medium — addresses issue #6 class of problems
**Action**: Add `findNodeByComponent()`, `getBoundsFor()` to layout manager

---

### Recommendation 4: Implement Layout Builder Pattern (Medium ROI)

**Effort**: Medium — new API surface
**Impact**: High — significantly improves DX
**Action**: Add fluent builder for tree construction in `candy-shine`

---

### Recommendation 5: Error Context Enhancement (Low ROI)

**Effort**: Low — improve error messages
**Impact**: Medium — reduces debugging time
**Action**: Add node path to all layout errors

---

## Conclusion

treilik/bubbleboxer is a **small, niche, low-activity** library that solves a real problem (TUI layout composition) but has **stalled** on user issues. The two open issues represent genuine pain points that remain unaddressed for 3+ years:

1. **Issue #7** (resize examples) reveals documentation debt
2. **Issue #6** (node access) reveals missing query APIs

The library's architecture is sound — separation of layout and content is correct — but lacks **completion APIs** (queries, builders, error context). SugarCraft can learn from both what's done well and what's missing.

**Key Takeaway for SugarCraft**: Focus on **query APIs** and **immutable descriptions** alongside the core layout engine. These are the gaps that bubbleboxer users have hit.
