# SugarCraft/sugar-dash: Innovation & Comparison Update Report

## Overview

**Package**: `sugarcraft/sugar-dash`
**Description**: Dashboard TUI library providing column grid layouts, framed panels, status bars, tabs, and 200+ components across 13 namespaces. Ports patterns from bubble-grid, bubbletea-tilelayout, go-tealeaves, bubbleboxer, lattice, Homedash, and termui.
**Status**: 🟢 v1 ready
**Primary Upstream**: charmbracelet/bubble-grid (shahar3/bubble-grid fork)
**Ecosystem Position**: sugar-dash is the flagship dashboard/TUI composition library of SugarCraft, sitting at the application layer above candy-core (runtime), candy-sprinkles (styling), and candy-vt (terminal emulation).

### Biggest Opportunity Areas
1. **Constraint-based layout** — Ratatui-style `Constraint::Min()/Max()/Length()/Percentage()/Ratio()/Fill()` enum system for declarative, composable sizing
2. **StatefulWidget separation** — External state objects (`TableState`, `ListState`, etc.) for testability and decoupling
3. **Reactive properties** — Declarative reactive state with automatic watcher injection (Textual pattern)
4. **Buffer diffing** — Only redraw changed cells (Ratatui/php-tui pattern) instead of full re-render
5. **Plugin/events UX** — Better event system for component interaction

### Biggest Missing Capabilities
1. No constraint enum system — current `Constraint` class handles Fixed/Flex/Fit only
2. No reactive/stateful widget pattern — components are pure render-only
3. No buffer diffing — full re-render on every frame
4. No mount/unmount lifecycle hooks
5. No streaming/chunked data update support

---

## Internal Capability Summary

### Current Architecture

**Grid System** (StackedGrid):
- Multi-column stacked grid with items in columns, vertical stacking within columns
- `expandVertical` flag for filling remaining space
- Responsive breakpoint collapse at 90 cells (narrow terminal)
- `fitScreen` option for equal-width columns vs natural size
- Height allocation: floor division with remainder distributed to last expanding item

**Layout Containers**: 8 distinct layout strategies
- `StackedGrid` — column-based dashboard layout
- `HStack`/`VStack`/`ZStack` — stack layouts with alignment/spacing
- `FlexLayout` — CSS Flexbox-inspired with direction/wrap/justify/align
- `GridLayout` — CSS Grid with rows/columns/gaps/spanning
- `Layout` — advanced flex-weight algorithm with grow/shrink
- `TileLayout` — constraint-based sizing (Fixed/Flex/Fit) with 5-phase resolver
- `Split` — two-pane split view

**Component Library**: 200+ components across 13 namespaces
- Foundation (Item, Sizer, Drawable, Rect, Buffer, Cell, Style, Theme)
- Layout (Frame, Panel, BoxDrawing, Spacer, Shadow, etc.)
- Components (Modal, Select, Toast, Tabs, StatusBar, Form, Feedback, Nav, Card, Calendar, Tree, Table)
- Plot (Chart, Sparkline, Gauge, Donut, Heatmap, RadarChart, etc.)
- Events (Event, KeyEvent, MouseEvent, FocusEvent, etc.)
- Keys (Key, KeyAction, KeyMap, KeyRegistry)
- Module (Elm-arch Module interface with init/update/view/name/minSize)
- Plugin (JSON-based plugin protocol with Request/Response/PluginSdk/ExternalModule)

### Current Rendering Systems
- **Item/Sizer pattern**: Components implement `Item::render(): string` or `Sizer extends Item` with `setSize()`
- **Screen management**: Enter/leave alternate screen, cursor control, mouse tracking, screen clear
- **Immutable patterns**: All components use `with*()` withers returning new instances
- **Theme fan-down**: `Drawable::withTheme()` recursively applies theme to children

### Strengths
1. Comprehensive layout system with 8 distinct layout strategies
2. Immutable patterns with readonly properties and wither methods
3. Constraint-based layout via Tile/Constraint system from tealeaves
4. Theme system with consistent Drawable interface
5. Built-in responsive breakpoint system
6. Elm-pattern Module architecture with clear contracts
7. 200+ components across 13 namespaces
8. JSON-based plugin system for external binary integration
9. 40+ VHS demo GIFs
10. Dual-SSOT awareness documented (distinct primitives from candy-sprinkles/candy-core/candy-vt)

### Weaknesses
1. Components are pure render-only — no built-in state management beyond withers
2. No constraint enum system (Ratatui-style Min/Max/Length/Percentage/Ratio/Fill)
3. No buffer diffing — full re-render every frame
4. No reactive state with automatic watcher injection
5. No mount/unmount lifecycle hooks
6. No streaming/chunked data update support
7. Bubble sort in Table sorting (O(n²)) vs PHP's built-in sorting
8. Gap distribution (space-around/space-evenly) is simplified/stubbed
9. Event handling delegated externally to candy-core runtime
10. No `compose()` generator interface for declarative child declaration

---

## Relevant External Repositories

| Repo | Relevance | Major applicable concepts | Priority |
|------|-----------|---------------------------|----------|
| `ratatui/ratatui` | **High** | Constraint enum system, StatefulWidget pattern, Block wrapper, buffer diffing, immediate-mode rendering, Cassowary layout | P0 |
| `charmbracelet/bubbletea` | **High** | Elm architecture, Cmd pattern for async, comprehensive input handling (Kitty keyboard, mouse SGR), synchronized output (ANSI 2026) | P0 |
| `textualize/textual` | **High** | Reactive state with watchers, CSS styling (TCSS), message pump with bubbling, spatial map hit-testing, compositor double-buffering | P0 |
| `charmbracelet/lipgloss` | **High** | Style system with bitmask properties, per-side borders, gradient borders, CIELAB color blending, layer compositing, Canvas | P1 |
| `76creates/stickers` | **Medium** | Ratio-based flexbox, ContentGenerator for adaptive content, minimum dimension constraints, copy accessors for read-only inspection | P1 |
| `Evertras/bubble-table` | **Medium** | Full-featured table with sorting/filtering/pagination/selection, user event system, style precedence layering, fuzzy filtering | P1 |
| `php-tui/php-tui` | **High** | PHP-native Ratatui port, Cassowary constraint solver, Widget/WidgetRenderer visitor pattern, extension architecture | P0 |
| `treilik/bubblelister` | **Medium** | List with cursor navigation, viewport-relative offset, pluggable prefixer/suffixer, concurrent search | P2 |
| `rasjonell/dashbrew` | **Low** | Dashboard-specific patterns (not currently in sugar-dash map) | P2 |

---

## Feature Gap Analysis

### Critical Priority

#### 1. Constraint Enum System (Ratatui-style)
**Title**: Constraint-based layout with declarative sizing enums

**Description**: sugar-dash's current `Tile/Constraint` system handles `Fixed`, `Flex`, and `Fit` only. Ratatui provides a richer `Constraint` enum with `Length()`, `Percentage()`, `Ratio()`, `Fill()`, `Min()`, `Max()` for more expressive, composable layout constraints.

**Why it matters**: Enables developers to write `Layout::vertical([Constraint::Percentage(20), Constraint::Fill(), Constraint::Min(5)])` instead of manual size calculations. Makes layouts more readable and maintainable.

**Source**: `docs/repo_map/ratatui_ratatui.md` — Constraint types resolve via Cassowary:
```rust
Constraint::Length(n)  // Fixed pixel value
Constraint::Percentage(p)  // Relative to container
Constraint::Ratio(n, d)  // Aspect ratio
Constraint::Fill(n)  // Remaining space
Constraint::Min(n) / Constraint::Max(n)  // Bounds
```

**Source**: `docs/repo_map/php-tui_php-tui.md` — PHP port of same pattern:
```php
Constraint::length(int)   // Fixed length
Constraint::percentage(int)  // Percentage of available space
Constraint::min(int) / Constraint::max(int)  // Bounded
```

**Implementation ideas**:
- Create new `Constraint` enum with cases: `Length(int)`, `Percentage(int)`, `Ratio(int, int)`, `Fill(?int)`, `Min(int)`, `Max(int)`
- Update `Layout` to use new Constraint enum
- Potentially integrate Cassowary solver from `php-tui/cassowary` for complex multi-constraint layouts

**Estimated complexity**: High — requires new enum type, layout algorithm updates, potential solver integration

**Expected impact**: High — makes layout code significantly more declarative and maintainable

---

#### 2. StatefulWidget Separation
**Title**: External state objects for widget state management

**Description**: Components like Tabs, List, Table mix state and rendering. External state objects (`TabsState`, `TableState`, `ListState`) would improve testability and allow multiple views of the same data.

**Why it matters**: Makes components more testable (state can be manipulated independently), enables multiple widgets viewing same data, and follows proven patterns from Ratatui and Textual.

**Source**: `docs/repo_map/ratatui_ratatui.md`:
```rust
// State types separate from widget
ListState: select(), selected(), offset()
TableState: select(), selected(), offset(), scroll_to()
ScrollbarState: position(), scroll_amount()
```

**Source**: `docs/repo_map/textualize_textual.md`:
```python
# Reactive state descriptor
numbers = var("0")  # Reactive state
def watch_numbers(self, value: str) -> None:  # Watcher
    self.query_one("#numbers", Digits).update(value)
```

**Implementation ideas**:
- Create `TabsState` class with `selectedIndex`, `withSelectedIndex()` 
- Create `TableState` class with `scrollOffset`, `selectedRow`, `sortColumns`
- Update Tabs/Table constructors to accept state objects
- Add `StatefulWidget` marker interface

**Estimated complexity**: Medium — requires new state classes and constructor updates

**Expected impact**: High — significantly improves testability and enables advanced patterns

---

#### 3. Buffer Diffing / Incremental Rendering
**Title**: Only redraw changed cells instead of full re-render

**Description**: sugar-dash re-renders everything on every frame. Ratatui uses buffer diffing to compute minimal cell changes and only write those to the terminal.

**Why it matters**: Significant performance improvement for complex dashboards, especially with frequent updates (live data, animations).

**Source**: `docs/repo_map/ratatui_ratatui.md`:
```rust
// Rendering pipeline per Terminal::draw():
// 4. Diff current vs previous buffer, write changes
```

**Source**: `docs/repo_map/php-tui_php-tui.md`:
```php
// Buffer maintains two buffers and computes diff on flush()
Buffer::diff(Buffer)  // Compute minimal updates between two buffers
BufferUpdates: Collection of BufferUpdate (position + cell)
```

**Implementation ideas**:
- Add `Buffer::diff(Buffer): array<array{pos: [int,int], cell: Cell}>` method
- Track previous buffer in `Screen` or `Program`
- Only write changed cells on render

**Estimated complexity**: High — requires buffer tracking infrastructure and diff algorithm

**Expected impact**: High — performance improvement for live dashboards

---

### High Value

#### 4. Reactive Properties with Watchers
**Title**: Declarative reactive state with automatic UI updates

**Description**: Textual-style reactive properties that automatically trigger re-renders when values change, with watcher hooks for side effects.

**Why it matters**: Eliminates manual state propagation code; makes components self-updating when state changes.

**Source**: `docs/repo_map/textualize_textual.md`:
```python
class CalculatorApp(App):
    numbers = var("0")  # Reactive state
    
    def watch_numbers(self, value: str) -> None:  # Watcher
        self.query_one("#numbers", Digits).update(value)
```

**Implementation ideas**:
- Create `Reactive<T>` descriptor class
- Add `watch_` method convention for reactive property watchers
- Integrate with `BaseModule::withState()` pattern

**Estimated complexity**: Medium

**Expected impact**: High — reduces boilerplate and improves reactivity

---

#### 5. Block Wrapper Component
**Title**: DRY up border/padding/title rendering across Frame, Panel, Card

**Description**: Ratatui-style `Block` component that Frame, Panel, Card all reinvent separately. A unified Block wrapper with title, border, padding, and inner getter would eliminate duplication.

**Why it matters**: Reduces code duplication, ensures consistent behavior, makes it easy to add borders to any component.

**Source**: `docs/repo_map/ratatui_ratatui.md`:
```rust
Block::new(), bordered(), title(), title_top(), title_bottom(), 
padding(), border_type(), border_style(), inner(), style(), shadow()
```

**Implementation ideas**:
- Create `Block` class with title, border style, padding, and inner area calculation
- Update Frame, Panel, Card to use Block internally
- Expose `inner()` method for content area dimensions

**Estimated complexity**: Medium

**Expected impact**: Medium — reduces duplication, improves consistency

---

#### 6. Component Lifecycle Hooks
**Title**: Mount/unmount hooks for initialization/cleanup

**Description**: No current lifecycle for components. Add `mount()` and `unmount()` hooks called when components are added/removed from the tree.

**Why it matters**: Enables proper resource acquisition/release (e.g., closing file handles, stopping timers).

**Implementation ideas**:
- Add `Component` interface with `mount(): void`, `unmount(): void`
- Call hooks in parent component's render/layout cycle

**Estimated complexity**: Low

**Expected impact**: Medium — enables proper resource management

---

### Medium Priority

#### 7. Bubble Sort Replacement in Table
**Title**: Replace bubble sort with efficient PHP sorting

**Description**: Table sorting uses bubble sort (O(n²)) — should use PHP's built-in `usort` or `array_multisort`.

**Source**: `docs/repo_map/76creates_stickers.md`:
> bubble sort implementation... replace with quicksort/mergesort in PHP port for large datasets

**Implementation ideas**:
- Replace bubble sort with `usort($rows, fn($a, $b) => $a['col'] <=> $b['col'])`
- Add stable sort support for multi-column sorting

**Estimated complexity**: Low

**Expected impact**: Medium for large tables

---

#### 8. Gap Distribution (space-around/space-evenly)
**Title**: Complete FlexLayout gap distribution algorithms

**Description**: FlexLayout's `SpaceAround` and `SpaceEvenly` implementations are noted as "simplified/stubbed" in the mapping.

**Source**: `docs/repo_map/sugarcraft_sugar-dash.md` — architectural gaps

**Implementation ideas**:
- `SpaceEvenly`: `(totalGapWidth - totalContentWidth) / (itemCount + 1)` per gap
- `SpaceAround`: `(totalGapWidth - totalContentWidth) / itemCount / 2` per side

**Estimated complexity**: Medium

**Expected impact**: Medium — improves CSS flexbox parity

---

#### 9. ContentGenerator Pattern (stickers)
**Title**: Per-cell adaptive content via closures

**Description**: stickers' `Cell.SetContentGenerator(func(maxX, maxY int) string)` allows cell content to adapt to available dimensions. sugar-dash doesn't have this.

**Source**: `docs/repo_map/76creates_stickers.md`:
> Cell content can adapt to available dimensions (`maxX`, `maxY`), enabling text truncation/wrapping without pre-measuring

**Implementation ideas**:
- Add `Cell::withContentGenerator(callable(int $maxX, int $maxY): string)` method
- Call generator during render with allocated dimensions

**Estimated complexity**: Low

**Expected impact**: Medium — enables adaptive content

---

### Low Priority

#### 10. Streaming Data Support
**Title**: Append-mode updates for streaming data

**Description**: bubble-grid supports append mode for streaming data; sugar-dash does not.

**Source**: `docs/repo_map/sugarcraft_sugar-dash.md` — architectural gaps

**Estimated complexity**: Medium

**Expected impact**: Low — niche use case

---

#### 11. Composition Generator Interface
**Title**: `compose()` generator for declarative child declaration

**Description**: Textual uses `compose()` method yielding widgets. sugar-dash lacks this pattern.

**Source**: `docs/repo_map/textualize_textual.md`:
```python
class CalculatorApp(App):
    def compose(self):
        yield Button("1")
        yield Button("2")
```

**Estimated complexity**: Medium

**Expected impact**: Low — nice-to-have pattern

---

## Algorithm / Performance Opportunities

### Current vs External Approaches

| Area | Current Approach | External Approach | Why External is Better | Tradeoffs |
|------|-----------------|------------------|---------------------|-----------|
| **Layout constraints** | Fixed/Flex/Fit only | Ratatui: Min/Max/Length/Percentage/Ratio/Fill | More declarative, composable | New enum API to learn |
| **Table sorting** | Bubble sort O(n²) | PHP `usort` O(n log n) | Standard library optimized | None — direct replacement |
| **Buffer rendering** | Full re-render every frame | Buffer diffing — only changed cells | Significant perf gain | Requires buffer tracking |
| **Style storage** | Individual readonly properties | Lipgloss bitmask `int64 props` | Compact, fast `has()`/`set()` | More complex internal impl |
| **Reactive state** | Manual withers | Textual `var()` + watchers | Automatic propagation | Learning curve |
| **Gap distribution** | Simplified stub | CSS flexbox spec | Correct behavior | None — implement properly |
| **Layout algorithm** | Custom flex-weight | Cassowary constraint solver | Handles complex constraints | External dependency |

### Applicability to sugar-dash
- **Buffer diffing**: Highly applicable — would improve performance for live dashboards
- **Constraint enum**: Highly applicable — improves layout API expressiveness
- **ContentGenerator**: Applicabale — enables adaptive cell content
- **Bitmask style storage**: Low priority — current approach is more readable

---

## Architecture Improvements

### 1. Widget/StatefulWidget Trait Pattern
Ratatui's `Widget` and `StatefulWidget` traits provide clean contracts for custom widget development. sugar-dash should adopt a similar marker interface pattern.

**Current**: Components just implement `Item` or `Sizer`
**Proposed**: Add `Widget` marker interface, `StatefulWidget` for components with state

### 2. Extension/Plugin Architecture
php-tui's `DisplayExtension` interface allows adding widget renderers without modifying core. sugar-dash's plugin system is JSON-based binary communication; a PHP-level extension system would be more ergonomic.

### 3. Visitor Pattern for Widget Rendering
php-tui uses visitor pattern: `WidgetRenderer::render(WidgetRenderer, Widget, Buffer, Area)`. Allows nested widget rendering (List containing Paragraphs).

### 4. Compositor Double-Buffering
Textual's compositor renders widgets to strips, composites with control sequences, and only updates dirty regions. sugar-dash could adopt incremental rendering.

---

## API / Developer Experience Improvements

### 1. Constraint Enum Declarative API
```php
// Current verbose approach
$layout = new Layout([
    new FixedConstraint(20),
    new FlexConstraint(1.0),
]);

// Proposed Ratatui-style
$layout = Layout::vertical([
    Constraint::Length(20),
    Constraint::Fill(),
    Constraint::Min(5),
    Constraint::Percentage(20),
    Constraint::Ratio(16, 9),
]);
```

### 2. Builder Pattern for Components
Many components already use fluent withers; standardize across all 200+ components with consistent naming.

### 3. Consistent Factory Methods
- `::new()` default everywhere
- `::ansistr` for ANSI string parsing
- `Theme::tokyoNight()`, `Theme::dracula()` presets

### 4. Type-Safe Events
Current events are loosely typed. Ratatui-style strongly typed event payloads improve IDE support.

---

## Documentation / Cookbook Opportunities

### 1. Layout Algorithm Deep Dive
Document the 5-phase constraint resolution algorithm in TileLayout with visual examples.

### 2. Component Composition Patterns
Show how to compose StackedGrid + Frame + VStack + components into dashboards.

### 3. Performance Cookbook
- When to use each layout type
- Buffer diffing for live data
- Lazy rendering strategies

### 4. Migration Guide from bubble-grid
Document differences from Go bubble-grid for developers coming from that ecosystem.

---

## UX / TUI Improvements

### 1. Synchronized Output (ANSI 2026)
bubble-tea supports synchronized output mode for flicker-free updates on supported terminals (Windows Terminal, ghostty, wezterm, alacritty, kitty, rio).

### 2. Kitty Keyboard Protocol
Full key repeat detection, modifier support, alternate key values.

### 3. Better Focus Management
Ratatui has no built-in focus system — sugar-dash could excel here with a built-in FocusManager.

### 4. Terminal Background Detection
lipgloss's `HasDarkBackground()` queries terminal via OSC 11 for adaptive theming.

---

## Testing / Reliability Improvements

### 1. Snapshot Testing Expansion
Current snapshot testing covers rendering output. Expand to:
- Layout algorithm outputs at various terminal sizes
- Constraint resolution at edge cases
- Responsive breakpoint transitions

### 2. Property-Based Testing
Use `phpunit/phpunit` `@dataProvider` with edge case inputs (0, -1, max int, empty collections).

### 3. Golden File Tests
Establish golden file test suite for all 40+ VHS demos to catch regression.

### 4. Fuzz Testing
Like bubble-table's fuzz tests for scrolling, add fuzz tests for:
- Layout with extreme constraint values
- Chart rendering with NaN/Inf values
- Unicode edge cases

---

## Ecosystem / Integration Opportunities

### 1. MCP Server Integration
Build MCP server exposing sugar-dash components as tools for AI agents to generate TUIs.

### 2. Integration with sugar-charts
Charts and dashboards naturally compose — ensure seamless integration.

### 3. Integration with sugar-prompt
Form inputs (Textarea, Select, etc.) should integrate with dashboard layouts.

### 4. VS Code Extension
TUI preview extension that renders sugar-dash output in a terminal panel.

---

## Notable PRs / Issues / Discussions

### 1. bubble-tea ANSI 2026 Synchronized Output
**Source**: `docs/repo_map/charmbracelet_bubbletea.md`

> The default renderer (cursedRenderer) uses uv.TerminalRenderer for actual rendering with synchronized output mode (ANSI 2026) for flicker-free updates

**Lesson**: Synchronized output is a simple addition that dramatically improves visual quality on supported terminals.

### 2. ratatui Layout Constraint Solver
**Source**: `docs/repo_map/ratatui_ratatui.md`

> The layout system uses the Cassowary constraint solver algorithm (via the kasuari crate) to resolve conflicting layout constraints

**Lesson**: For complex responsive layouts, a proper constraint solver handles edge cases better than hand-rolled algorithms.

### 3. stickers Ratio-based Layout
**Source**: `docs/repo_map/76creates_stickers.md`

> The core layout algorithm distributes available space (width or height) across cells/rows/columns using integer ratios

**Lesson**: Ratio-based sizing with minimum constraints is simpler than Cassowary for common cases and sufficient for most dashboards.

### 4. bubble-table Style Precedence
**Source**: `docs/repo_map/Evertras_bubble-table.md`

> rowStyle.Inherit(column.style).Inherit(baseStyle) — cell most specific, base least

**Lesson**: Layered style precedence is intuitive and powerful for data-driven styling.

### 5. php-tui Widget Extension Architecture
**Source**: `docs/repo_map/php-tui_php-tui.md`

> The DisplayExtension interface allows adding widget renderers and shape painters without modifying core code

**Lesson**: Extension points prevent framework bloat while allowing ecosystem growth.

---

## Recommended Roadmap

### Immediate Wins (0-3 months)
1. **Replace bubble sort with `usort`** in Table — trivial, immediate performance gain
2. **Complete gap distribution** (space-around/space-evenly) — medium effort, improves flexbox parity
3. **Add ContentGenerator pattern** to cells — enables adaptive content
4. **Expand snapshot tests** for layout algorithms

### Medium-Term (3-6 months)
1. **Constraint enum system** (Ratatui-style) — significant API improvement
2. **StatefulWidget separation** — improves testability
3. **Buffer diffing** — performance improvement for live dashboards
4. **Block wrapper component** — DRY up border rendering

### Major Architectural (6-12 months)
1. **Reactive properties with watchers** — reduces boilerplate
2. **Component lifecycle hooks** — proper mount/unmount
3. **Synchronized output support** (ANSI 2026) — visual quality
4. **Cassowary constraint solver integration** — complex responsive layouts

### Experimental
1. **MCP server** exposing TUI components as AI tools
2. **TCSS (Textual CSS)** styling — familiar to web developers
3. **Web-based TUI rendering** — serve dashboards in browser
4. **Streaming data** append mode for live charts

---

## Priority Matrix

| Opportunity | Impact | Complexity | Risk | Priority |
|-------------|--------|------------|------|----------|
| Replace bubble sort with usort | Medium | Low | Low | **P0 — Immediate** |
| Complete gap distribution | Medium | Medium | Low | **P0 — Immediate** |
| Constraint enum system | High | High | Medium | **P0 — High value** |
| StatefulWidget separation | High | Medium | Medium | **P0 — High value** |
| Buffer diffing | High | High | High | **P1 — Medium term** |
| Block wrapper component | Medium | Medium | Low | **P1 — Medium term** |
| ContentGenerator pattern | Medium | Low | Low | **P1 — Medium term** |
| Reactive properties | High | Medium | Medium | **P2 — Major arch** |
| Component lifecycle hooks | Medium | Low | Low | **P2 — Major arch** |
| Synchronized output (ANSI 2026) | Medium | Medium | Medium | **P2 — Major arch** |
| Cassowary solver integration | High | High | High | **P3 — Experimental** |
| MCP server integration | High | High | High | **P3 — Experimental** |

---

## Final Strategic Assessment

sugar-dash is a **mature, comprehensive TUI dashboard library** that successfully ports multiple Go TUI patterns to PHP. Its primary strength is **breadth** — over 200 components across 13 namespaces — while maintaining consistent patterns for sizing, theming, and event handling. The StackedGrid system derived from bubble-grid provides intuitive column-based dashboard layout, while various stack implementations (H/V/Z) offer flexible composition. The Tile/Constraint system from tealeaves enables sophisticated constraint-based sizing.

**Key differentiators from external repos**:
- More component types than any single external library
- Immutable patterns throughout (readonly + wither)
- Dual-SSOT awareness prevents confusion with same-named types in other SugarCraft libs
- Comprehensive VHS demo library (40+ demos)

**Critical gaps vs Ratatui/Textual**:
1. No constraint enum system for declarative layout
2. No buffer diffing for incremental rendering
3. No reactive state with automatic watcher injection
4. No StatefulWidget separation for testability

**Implementation priority**: Focus on the immediate wins (bubble sort replacement, gap distribution completion) while planning the constraint enum system and buffer diffing as medium-term investments. The constraint enum system is the highest-impact architectural improvement — it fundamentally changes how developers write layouts, making code more declarative and maintainable.

**Ecosystem position**: sugar-dash is the application-layer library that consumes candy-core (runtime), candy-sprinkles (styling), and candy-vt (terminal emulation). It should lead the ecosystem in providing high-level dashboard composition patterns while relying on foundation libs for primitives.

The most significant long-term opportunity is **MCP server integration** — exposing sugar-dash components as AI-accessible tools would position SugarCraft uniquely in the AI agent tooling space, allowing agents to generate and modify TUIs programmatically. This is speculative but high-potential given the current AI agent tooling boom.

---

## Source References

- `docs/repo_map/sugarcraft_sugar-dash.md` — Primary mapping document
- `docs/repo_map/ratatui_ratatui.md` — Constraint system, buffer diffing, StatefulWidget
- `docs/repo_map/charmbracelet_bubbletea.md` — Elm architecture, async command pattern
- `docs/repo_map/textualize_textual.md` — Reactive state, CSS styling, message pump
- `docs/repo_map/charmbracelet_lipgloss.md` — Style system, borders, layer compositing
- `docs/repo_map/76creates_stickers.md` — Ratio-based flexbox, ContentGenerator
- `docs/repo_map/Evertras_bubble-table.md` — Table features, events, style precedence
- `docs/repo_map/php-tui_php-tui.md` — PHP-native TUI, Cassowary solver, extension architecture
- `docs/repo_map/treilik_bubblelister.md` — List component patterns
