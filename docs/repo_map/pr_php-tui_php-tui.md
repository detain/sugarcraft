# Second-Stage Ecosystem Intelligence: php-tui/php-tui

## Repository Overview

- **URL**: https://github.com/php-tui/php-tui
- **Stars**: ~597 | **Forks**: 19 | **Language**: PHP (99.8%)
- **License**: MIT | **Created**: 2023-10-09 | **Last Push**: 2024-09-08
- **Contributors**: 2 core (dantleech, KennedyTedesco)
- **Releases**: 4 (all prerelease/0.x), Latest: 0.2.1 (2024-09-08)
- **Status**: Active but small team; maintainer noted inactivity in Sept 2025
- **Dependencies**: `php-tui/cassowary` (constraint solver), `php-tui/term` (terminal control)
- **Known Limitation**: No Windows support (maintainer lacks Windows access)

---

## Existing SugarCraft Mapping

From `repo_map/php-tui_php-tui.md`:

| SugarCraft Lib | php-tui Component |
|--------------|------------------|
| `candy-core` | Display, Backend, Buffer, Cell, Area, Widget/WidgetRenderer interfaces, Viewport |
| `candy-sprinkles` | Style, Color, Modifier, Text/Line/Span, Borders, BlockWidget |
| `honey-bounce` | Layout, Constraint, CassowaryConstraintSolver |
| `sugar-bits` | ParagraphWidget, ParagraphRenderer |
| `sugar-charts` | ChartWidget, BarChartWidget, SparklineWidget, TableWidget |
| `sugar-prompt` | ListWidget, TabsWidget, ScrollbarWidget |
| `candy-shine` | GaugeWidget, CanvasWidget, image rendering |

---

## Previously Identified Gaps

The first-pass analysis identified these weaknesses in php-tui:

1. **External dependency on Cassowary** — layout requires `php-tui/cassowary` separate package
2. **External dependency on php-tui/term** — terminal control abstracted separately
3. **No Windows support** — explicitly stated, no ETA
4. **Synchronous event loop** — async integration not built-in (user must use Amp/ReactPHP)
5. **Large API surface** — 40+ classes/interfaces can overwhelm new users
6. **BDF font weight** — bundled 152KB MapData.php for bitmap fonts
7. **ImageMagick extension required** — not always available in PHP environments

---

## High-Signal Open Issues

### Issue #233: `stream_set_blocking(STDIN, false)` left unrestored
**Severity**: Medium-High | **Author**: AlexanderTheGr8-gr | **Date**: 2026-02-04

**Problem**: The `StreamReader` class sets `STDIN` to non-blocking mode but never restores it. When php-tui is invoked from another program (e.g., `nnn` file manager), the parent process's STDIN remains in non-blocking state, breaking subsequent commands.

**Root Cause**: No `__destruct` handler or explicit restoration.

**Maintainer Suggestion**: Reverse the unblocking in `__destruct` method.

**Direct Risk to SugarCraft**: HIGH — Any raw TTY mode changes must be explicitly restored via RAII/destructor pattern. SugarCraft must implement proper cleanup handlers.

---

### Issue #230: `IntlChar` class not found
**Severity**: Medium | **Author**: asya1098 | **Date**: 2025-06-13

**Problem**: `BrailleGrid` uses `IntlChar` which requires PHP's `ext-intl` extension. Users without the extension get fatal errors.

**Direct Risk to SugarCraft**: MEDIUM — SugarCraft should either require intl or provide fallback rendering. The dependency should be documented and optionally caught.

---

### Issue #213: Table crash when selecting < 1st row (undefined offset)
**Severity**: Medium | **Author**: dantleech | **Date**: 2023-12-02

**Problem**: Scrolling beyond table bounds causes undefined offset crash.

**Issue demonstrates**: State validation gap — no clamping on scroll index.

**Direct Risk to SugarCraft**: MEDIUM — All interactive widgets (List, Table, Scrollbar) must clamp indices to valid ranges.

---

### Issue #203: Paragraph word wrapping "line composer" not supported
**Severity**: Low-Medium | **Author**: dantleech | **Date**: 2023-11-30

**Problem**: Only character-based wrapping existed; word-based wrapping was missing. This was **later resolved** via PR #219 (merged Dec 2023).

**Lesson**: The maintainer explicitly stated "it should be possible to wrap lines on character or words" — showing the original design was incomplete.

**Direct Risk to SugarCraft**: LOW — Word wrap should be a first-class feature, not an afterthought.

---

### Issue #187: Add PhpAT or similar to enforce architectural rules
**Severity**: Low | **Author**: dantleech | **Date**: 2023-11-29

**Problem**: Request for architecture enforcement tool (like PHP Architecture Testing). Examples:
- All widget classes should have `default()` method
- All widgets should have private constructors
- All mutators should return `self`

**Direct Risk to SugarCraft**: MEDIUM — SugarCraft should establish and enforce widget conventions from day one. The lack of such enforcement leads to API inconsistency.

---

### Issue #184: `equals()` method on Color interface
**Severity**: Low | **Author**: KennedyTedesco | **Date**: 2023-11-28

**Problem**: The library uses weak comparisons (`!=`) for Color objects. PHPStan 2.0 will flag these. However, adding `equals()` causes ~6% performance regression in buffer diffing (hot path).

**Trade-off**: Correctness vs. performance in a hot path.

**Direct Risk to SugarCraft**: HIGH — Implement value equality properly from the start, accept minor perf cost. Don't use object identity for value types.

---

### Issue #139: Additional gradient color support
**Severity**: Low | **Author**: dantleech | **Date**: 2023-11-19

**Problem**: Gradient colors were only supported in Gauge, BarChart, and Sprite — not in Text, Shapes, Paragraph, Table, List, etc.

**Direct Risk to SugarCraft**: LOW — Gradient support is a nice-to-have but not critical.

---

### Issue #124: Aggregate widget/shape renderer silent failure
**Severity**: Low | **Author**: dantleech | **Date**: 2023-11-15

**Problem**: When no renderer handles a widget, the system silently continues rather than throwing. The maintainer debated:
- Option A: Add `supports(Widget): bool` method to renderer interface
- Option B: Return `bool` from `render()` to indicate acceptance
- Option C: Keep current design (iterate all, early return pattern)

**Decision deferred** — "not really an issue right now."

**Direct Risk to SugarCraft**: MEDIUM — Silent failure makes debugging hard. SugarCraft should throw or log when no renderer is found.

---

### Issue #217: Colors documentation not rendered
**Severity**: Low | **Author**: dantleech | **Date**: 2023-12-12

**Documentation bug** — the colors reference page doesn't render properly.

**Direct Risk to SugarCraft**: LOW — Documentation accuracy is important but not a technical risk.

---

## Important Closed Issues

### Issue #204: Dialogs/floating windows support
**Status**: Closed (completed via PR #209) | **Author**: dantleech | **Date**: 2023-11-30

**Problem**: No widget existed for floating windows/dialogs.

**Solution**: PR #209 added window/dragging capability through `BufferContext`. The maintainer noted: "Dialogs are technically possible via CompositeWidget... Floating windows are possible but not in a way that anybody would consider reasonable."

**Key Insight**: The maintainer created a simple `WindowWidget` POC that works with position-relative rendering.

---

### Issue #225: Widget area access for click handling
**Status**: Closed (completed via PR #209) | **Author**: mtk3d | **Date**: 2024-07-15

**Problem**: User wanted to check if mouse position is inside a widget's rendered area.

**Solution**: BufferContext now provides area access for widget rendering.

**Direct Risk to SugarCraft**: HIGH — Event handling + widget geometry access is fundamental. SugarCraft needs a clean mechanism for this.

---

### Issue #229: Documentation examples have incorrect imports
**Status**: Closed | **Author**: ellisgl | **Date**: 2025-03-28

**Problem**: Example code shows `PhpTui\Tui\Text\Title` but should be `PhpTui\Tui\Model\Text\Title`. Namespace reorg broke examples.

**Lesson**: Multiple namespace reorganizations (documented in changelog v0.2.0) caused breaking changes.

**Direct Risk to SugarCraft**: MEDIUM — Stable namespaces matter. SugarCraft should never reorganize namespaces post-1.0.

---

### Issue #224: Symfony command wrapper question
**Status**: Closed | **Author**: ellisgl | **Date**: 2024-05-12

**Problem**: User asked about wrapping Symfony Console output with php-tui.

**Signal**: Interest in integrating php-tui with existing PHP frameworks.

---

### Issue #199: Events blocking infinitely on Mac
**Status**: Closed (pending reproduction) | **Author**: AuroraYolo | **Date**: 2023-11-30

**Problem**: Event reading blocks indefinitely on macOS, with screenshot showing hang.

**Root cause**: Unknown — issue labeled "pending reproduction" and closed without resolution.

**Direct Risk to SugarCraft**: HIGH — Cross-platform event handling is fragile. Need robust non-blocking I/O with timeouts.

---

### Issue #202: Paragraph wrap not used
**Status**: Closed (fixed via PR #219) | **Author**: dantleech | **Date**: 2023-11-30

**Problem**: Word wrap option existed but wasn't actually applied during rendering.

---

### Issue #201: Remove corners from borders when superfluous
**Status**: Closed (fixed) | **Author**: dantleech | **Date**: 2023-12-01

**Bug**: Adjacent borders rendered double corners unnecessarily.

---

### Issue #211: Moving cursor truncates text
**Status**: Closed (fixed via PR #216) | **Author**: dantleech | **Date**: 2023-12-04

**Bug**: Line truncation when moving cursor in certain positions.

---

## Recurring Pain Points

### 1. Multi-width Unicode Handling
**Issues**: #215, #214, #192, #180, #178

Repeated issues with Unicode grapheme width calculation, truncation, and multi-byte input. This suggests the underlying `mb_strwidth()` approach is fragile.

**Signal**: Unicode is a constant source of bugs in terminal rendering.

---

### 2. Namespace/Class Organization
**Issues**: #206 (massive reorg), #229 (stale docs)

The library went through major namespace reorganization, breaking user code and documentation.

**Signal**: This is a normal part of early library evolution, but painful for users.

---

### 3. Inline Viewport Complexity
**Issues**: #218 (POC inline resize), #150, #154, #158

The inline viewport had multiple bugs related to clearing and resizing. The current solution (`setInlineHeight` method) is considered a "code smell."

**Signal**: Inline viewport state management is complex; the design may need revisiting.

---

## Frequently Requested Features

### 1. Word Wrap for Paragraph
**Status**: Implemented via PR #219 (Dec 2023) | **Author**: KennedyTedesco

Supported variants: `Word`, `WordTrimmed`, `Character` (default).

**Lesson**: Basic text wrapping was missing at launch — should be foundational.

---

### 2. Self-rendering Widgets
**Status**: Implemented via PR #226 (Sept 2024)

Widgets can now render themselves if implementing `WidgetRenderer`.

**Architectural pattern worth copying**: Allow widgets to be self-rendering while maintaining the external renderer interface.

---

### 3. Window/Draggable Dialogs
**Status**: Implemented via PR #209 (Jul 2024)

Simple window widget with draggable positioning.

**SugarCraft Opportunity**: This is a clear gap — no built-in dialog/window layer.

---

### 4. Text Editor / TextArea Widget
**Status**: Draft PR #170 (Nov 2023, stalled)

Full-featured text editor with cursor, undo/redo, multi-byte support. The maintainer noted (Sept 2025) he's not currently working on it.

**Opportunity**: SugarCraft could prioritize this as a premium feature.

---

### 5. Gradient Color Expansion
**Status**: Requested (Issue #139)

Users want gradients in more widgets: Text, Circle, Line, Map, Points, Rectangle, Chart, List, Paragraph, Table.

---

### 6. Inline Height Resize
**Status**: Implemented via PR #218 (Dec 2023)

Allows resizing the inline viewport dynamically.

---

## Important PRs

### PR #235: Require INTL extension
**Merged**: Apr 2026 | **Author**: dantleech

Makes `ext-intl` a hard requirement for certain canvas features.

**Implication**: Dependency requirements are evolving — users may need to install additional extensions.

---

### PR #226: Allow self-rendering widgets
**Merged**: Sep 2024 | **Author**: dantleech

Allows widgets to implement their own renderer without external renderer class.

---

### PR #219: Paragraph word wrap support
**Merged**: Dec 2023 | **Author**: KennedyTedesco

Added proper word-wrapping. Heavily based on Ratatui's implementation.

---

### PR #210: Perf — improves `Position::toIndex()`
**Merged**: Dec 2023 | **Author**: KennedyTedesco

Shows active performance optimization work on hot paths.

---

### PR #208: Minor change in Buffer diff
**Merged**: Dec 2023 | **Author**: KennedyTedesco

Buffer diffing is a performance-critical path that receives ongoing attention.

---

### PR #207: Reintroduce Area argument to WidgetRenderer
**Merged**: Dec 2023 | **Author**: dantleech

Shows the API design was still evolving — the `Area` argument was removed and then reintroduced.

**Lesson**: The renderer interface wasn't stable even at v0.1.0.

---

### PR #206: Massive Class Reorganisation
**Merged**: Dec 2023 | **Author**: dantleech

Major namespace cleanup — removed `Model` namespace entirely.

---

## Architectural Changes

### v0.2.0 (Major Refactoring)
- Removed `Model` namespace entirely
- Renamed `RawWidget` → `BufferWidget`
- Reintroduced `area` argument to `WidgetRenderer`
- Made `Line::fromSpans()` variadic

### v0.1.0
- Made all classes `final`
- Split `term` into separate package
- Added int range type hints

### Design Pattern: Visitor-style Rendering
```php
// WidgetRenderer::render($renderer, $widget, $buffer, $area)
// The renderer passes itself as the "outer" renderer for nested rendering
```

**Issue #124 Discussion**: The aggregate renderer pattern uses iteration-with-early-return. The maintainer considered but deferred a `supports()` method.

### Design Pattern: Extension Architecture
`DisplayExtension` interface allows adding widget renderers and shape painters. Built-in `CoreExtension` provides defaults.

---

## Performance Discussions

### Hot Path: Buffer Diffing
- Issue #184 notes that Color equality checks in buffer diff cause ~6% regression
- Ongoing optimization work (PRs #210, #208)

**Signal**: Buffer diffing is the primary performance bottleneck.

### Position Calculation
PR #210 improved `Position::toIndex()` performance — shows that coordinate conversion is a hotspot.

### Widget Rendering
Each `render()` call traverses the widget tree. No evidence of virtualization or viewport culling.

---

## Extensibility Discussions

### Issue #124: Silent Failure in Aggregate Renderer
Main maintainer deferred because "not really an issue right now." Community member (KennedyTedesco) suggested:
```php
public function supports(Widget $widget): bool;
```

**Current design**: Iterate all renderers, each returns early if not interested.

**Problem**: Silent failure when no renderer matches.

---

### DisplayExtension Pattern
Allows adding custom:
- Widget renderers
- Shape painters
- Extensions (BdfExtension, ImageMagickExtension)

**Limitation**: No standard mechanism to extend existing widgets or override behavior.

---

## API/UX Complaints

### Issue #229: Stale Example Code
Documentation examples had incorrect imports after namespace reorganization.

**Lesson**: Namespace stability is critical for user experience.

### Issue #224: Symfony Integration
User asked about wrapping Symfony Console output — shows php-tui doesn't integrate with common PHP frameworks.

### Issue #225: Widget Area Access
No clean way to get widget's rendered area for hit-testing.

**Resolved via PR #209** — BufferContext now provides area access.

---

## Migration Problems

### Namespace Changes (v0.2.0)
Breaking change: `PhpTui\Tui\Text\Title` → `PhpTui\Tui\Model\Text\Title`

Users on v0.0.x had to update all imports after upgrading.

### Prerelease Status
Library is still at 0.x — users should expect breaking changes.

**SugarCraft Implication**: SugarCraft should aim for API stability earlier than php-tui did.

---

## Clever Fixes & Workarounds

### Destructor Fix for STDIN Restoration (Issue #233)
Maintainer suggested using `__destruct` to restore `stream_set_blocking(STDIN, true)`.

This is a **RAII pattern** adaptation for PHP resource management.

### Inline Viewport Workarounds
Issue #154: "Inline viewport sets terminal to raw but does not set it back" — fixed with proper state restoration.

Issue #158: "Inline 'clear' is too greedy" — shows the clear behavior was eating content unexpectedly.

### Cross-platform Event Handling
Issue #199 was closed as "pending reproduction" — event blocking on Mac suggests platform-specific event handling differences.

---

## Community Workarounds

### Symfony Command Integration (Issue #224)
User asked about using php-tui inside Symfony Commands. No official solution provided — user was seeking guidance.

**Opportunity for SugarCraft**: Provide official integration patterns for common frameworks.

### IntlChar Workaround (Issue #230)
User didn't know they needed `ext-intl`. Solution: install PHP's intl extension.

**SugarCraft Opportunity**: Better error messages that guide users to install missing dependencies.

---

## Maintainer Guidance Patterns

### Defer Non-Critical Issues
Issue #124 (silent renderer failure) was deferred with "let's leave it for now." This suggests:
- Small team prioritizes features over polish
- Technical debt accumulates intentionally

### Acknowledge Design Imperfections
On Issue #218 (inline resize): "I don't really like [the API], but we do need to be able to resize the inline viewport. There should be a better way."

This honest acknowledgment shows a pragmatic approach — ship working code, note the debt.

### Active Bug Fixes
Most PRs fix specific bugs reported by users (unicode truncation, adjacent borders, line truncation, etc.).

---

## Rejected Ideas Worth Revisiting

### No Architectural Enforcement Tool
Issue #187 requested PhpAT or similar. Maintainer didn't act on it.

**For SugarCraft**: Consider adding architecture tests from day one.

### No `supports()` Method on Renderer Interface
Despite discussion, the `supports()` method was never added. The aggregate renderer still uses iteration-with-early-return.

**For SugarCraft**: Consider whether explicit `supports()` improves debuggability enough to justify the API change.

---

## Problems Likely Relevant To SugarCraft

1. **STDIN state restoration** — Raw TTY mode changes must be RAII-wrapped
2. **Unicode multi-width bugs** — Use a well-tested Unicode library, don't roll your own
3. **Index clamping in scrollable widgets** — Always clamp selection indices
4. **Extension missing error messages** — Guide users to install missing deps (intl, imagick)
5. **Widget area access for events** — Need clean API for hit-testing rendered widgets
6. **Buffer diffing performance** — Hot path; optimize carefully but correctly
7. **Namespace stability** — Lock namespaces early, never reorganize post-1.0
8. **Async event handling** — Non-blocking I/O with timeouts for cross-platform safety

---

## Features SugarCraft Should Consider

### High Priority

1. **RAII TTY State Management**
   - Automatic cleanup of raw mode changes via destructor/fiber
   - Prevents stdin corruption in parent processes

2. **Widget Hit-Testing API**
   - Clean mechanism to get widget's rendered `Area` for mouse event handling
   - BufferContext pattern is a good reference

3. **Index Clamping**
   - All scrollable widgets must clamp selection to valid range
   - Add assertion or defense against out-of-bounds

4. **Word Wrap Foundation**
   - Word, word-trimmed, and character wrapping modes
   - Built into Text/Paragraph rendering

5. **Async Event Loop Integration**
   - Support for ReactPHP/Amp event loops
   - Non-blocking reads with configurable timeouts

### Medium Priority

6. **Window/Dialog Layer**
   - Floating windows with position/Draggable
   - Modal dialog support

7. **Gradient Color Expansion**
   - Support in Text, Shapes, Paragraph, etc.

8. **Self-Rendering Widgets**
   - Allow widgets to implement their own renderer

### Lower Priority

9. **Text Editor Widget**
   - Full-featured text area with cursor, undo/redo

10. **Framework Integration Patterns**
    - Symfony Console, Laravel, etc.

---

## Architectural Lessons

### 1. The Widget/Renderer Separation Was Inverted
In Ratatui, the widget holds render logic. In php-tui, the renderer was split out — but the interface evolved: `WidgetRenderer::render($renderer, $widget, $buffer, $area)`.

This inversion makes sense in PHP but required ongoing API refinement.

### 2. Extension Points Should Be Explicit
The `DisplayExtension` pattern works but is underdocumented. Users don't know how to add custom widgets.

**For SugarCraft**: Provide clear extension patterns with examples.

### 3. Buffer Is King
Buffer diffing is the central performance concern. All optimizations center on reducing buffer comparisons.

**For SugarCraft**: Design buffer/cell representation for cache efficiency.

### 4. Viewport Modes Are Orthogonal
Fullscreen, inline, and fixed viewports should be composable, not mutually exclusive.

---

## Defensive Design Lessons

### Lesson 1: RAII for Terminal State
```php
// Never leave raw mode set
public function __destruct() {
    stream_set_blocking(STDIN, true);
}
```

### Lesson 2: Fail Fast on Missing Renderers
Instead of silent iteration, throw when no renderer handles a widget.

### Lesson 3: Clamp All Indices
```php
$this->selected = max(0, min($this->selected, $maxIndex));
```

### Lesson 4: Value Objects Need Equality
Color, Position, Area should use value equality, not object identity.

### Lesson 5: Document Extension Points
Users shouldn't need to read source to understand how to add custom widgets.

---

## Ecosystem Trends

1. **PHP TUI is nascent** — php-tui is the only major PHP TUI library (586 stars), indicating a small ecosystem
2. **Port fidelity vs. PHP idiom** — php-tui closely follows Ratatui but makes PHP-specific adaptations (e.g., Display instead of Terminal)
3. **Early stage = instability** — Namespace reorganizations, API changes show pre-1.0 flux
4. **Community-driven bug fixes** — KennedyTedesco contributed many performance fixes and unicode handling improvements
5. **Single-maintainer bottleneck** — dantleech is primary maintainer; KennedyTedesco contributes but project has limited bandwidth

---

## Strategic Opportunities for SugarCraft

### 1. Superior API Stability
Avoid php-tui's namespace churn. Establish stable APIs early with BC guarantees.

### 2. Better Extension Story
Provide documented, first-class extension points for custom widgets and renderers.

### 3. Async-First Design
Build async event handling into the core rather than as an afterthought.

### 4. Framework Integration
Unlike php-tui, SugarCraft could provide official integrations with Laravel, Symfony, etc.

### 5. Comprehensive Unicode Handling
Use a battle-tested approach from the start — don't roll custom Unicode width logic.

### 6. Strict Type Safety
PHPStan's 2.0 strictness will break weak comparisons. Use value equality for value objects from day one.

### 7. Community-Centric Development
php-tui has limited contributor diversity. SugarCraft could foster broader community contribution.

---

## Cross-Ecosystem Pattern Matches

### From Ratatui (Rust)
php-tui ports Ratatui faithfully but struggles with:
- Async event handling (Rust has async/.await)
- Trait object safety (PHP doesn't have dyn Trait)
- Lifetime management (PHP has GC, not ownership)

**SugarCraft should**: Learn from php-tui's adaptation challenges when porting from Charmbracelet.

### From Terminal Community
Key patterns:
- Crossterm-style event model (already adopted)
- Terminal state management best practices
- Cross-platform ANSI handling

---

## High ROI Recommendations

### Immediate (High Impact, Low Effort)

1. **Add `__destruct` cleanup for raw TTY mode** in candy-core
2. **Clamp scroll indices** in all scrollable widgets (List, Table, Scrollbar)
3. **Throw on missing widget renderer** instead of silent iteration
4. **Document extension points** with worked examples
5. **Add value equality** to Color, Position, Area classes

### Short-term (High Impact, Moderate Effort)

6. **Build async event loop integration** using ReactPHP
7. **Implement word wrap foundation** in sugar-bits (Paragraph)
8. **Add widget area access API** for mouse event hit-testing
9. **Create framework integration examples** (Laravel, Symfony)
10. **Use tested Unicode library** for width/grapheme handling

### Medium-term (High Impact, High Effort)

11. **Window/Dialog widget layer** for floating UI
12. **Text editor widget** with undo/redo
13. **Comprehensive gradient support** across widgets
14. **Cross-platform testing** matrix (Linux, macOS, Windows)
15. **Performance benchmarking** suite for buffer diffing hot path

---

## Conclusion

php-tui/php-tui is a faithful and impressive port of Rust's Ratatui to PHP, but it carries the hallmarks of early-stage development: API instability, limited Windows support, async gaps, and small-team capacity constraints. For SugarCraft, the repository offers a valuable preview of real-world TUI library challenges.

**Most critical intelligence for SugarCraft**:

1. **Terminal state management is dangerous** — STDIN corruption is a real bug
2. **Unicode handling is the #1 bug source** — don't DIY
3. **Index clamping is essential** — all scrollable widgets need it
4. **Extension points must be documented** — or users can't extend the library
5. **Value equality matters for hot paths** — accept minor perf cost for correctness
6. **API stability is a competitive advantage** — php-tui's namespace churn is a cautionary tale

The ecosystem is small but real. SugarCraft has an opportunity to learn from php-tui's growing pains and build a more stable, better-documented, and more extensible TUI framework for PHP.

---

*Report generated: Second-Stage Ecosystem Intelligence Analysis*
*Source: php-tui/php-tui GitHub Issues, PRs, and Changelog*
