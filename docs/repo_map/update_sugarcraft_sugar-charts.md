# Overview

SugarCharts (`sugar-charts`) is a comprehensive PHP terminal charting library providing ASCII/Unicode chart rendering with ANSI SGR color support for the SugarCraft TUI ecosystem. It ports the NimbleMarkets/ntcharts API to PHP with meaningful extensions including BrailleCanvas integration, Theme support, and multi-dataset charts.

**Ecosystem Positioning**: sugar-charts is a mid-layer component that sits above `candy-core` (Style/Color/ANSI primitives) and `candy-sprinkles` (Theme/input) but depends on `sugar-dash` for the BrailleCanvas bridge. It is a consumer of canvas primitives and a producer of chart output for dashboards.

**Biggest Opportunity Areas**:
1. **MarkLine integration** — The `MarkLine` annotation class exists but is not wired into any chart type
2. **Snapshot testing** — No byte-level SGR output tests for any chart type except Sixel
3. **Missing chart types** — Gauge, radar/spider, bubble/scatter with sized points, horizontal stacked bars
4. **Animation wiring** — `animationProgress` parameter exists but duration-driven animation via ReactPHP is not implemented
5. **Color science** — RGB linear interpolation instead of LAB for perceptually uniform gradients

**Biggest Missing Capabilities**:
1. Logarithmic axis scale support
2. Grid overlay option for charts
3. Per-data-point coloring (gradients within a series)
4. Built-in animation orchestration (duration-based frame sequencing)
5. Interactive chart modes (hover tooltips, click-to-select)

---

# Internal Capability Summary

## Current Architecture

**Layered Rendering Pipeline**:
```
User-facing Chart Classes (BarChart, LineChart, Heatmap, OHLC, Sparkline, etc.)
         ↓
   Canvas Primitives (Canvas, Graph, BrailleGrid, Cell)
         ↓
  candy-sprinkles Style / candy-core Color / ColorProfile
```

**Canvas Primitives** (`SugarCraft\Charts\Canvas`):
- `Cell` — immutable value object (single rune + optional Style)
- `Canvas` — mutable in-place 2D grid (intentional for animation performance)
- `Graph` — drawing primitives (line, axis, labels, candlestick, columns)
- `BrailleGrid` — 2x4 dot matrix per cell for sub-cell rendering

**Chart Types Implemented**:
| Chart | File | Status |
|-------|------|--------|
| Sparkline | `Sparkline/Sparkline.php` | 🟢 v1 |
| BarChart | `BarChart/BarChart.php` | 🟢 v1 (multi-bar grouped pending) |
| LineChart | `LineChart/LineChart.php` | 🟢 v1 (zoom/pan + dataset styles pending) |
| TimeSeries | `LineChart/TimeSeries.php` | 🟢 v1 (multi-dataset + update-handler pending) |
| Streamline | `LineChart/Streamline.php` | 🟢 v1 |
| Waveline | `LineChart/Waveline.php` | 🟢 v1 |
| Scatter | `Scatter/Scatter.php` | 🟡 single-dataset only |
| Heatmap | `Heatmap/Heatmap.php` | 🟢 v1 |
| OHLC | `OHLC/OHLCChart.php` | 🟡 candlestick only (volume pane + multi-series pending) |
| Picture | `Picture/Picture.php` | 🟡 Sixel only (Kitty/iTerm2 pending) |
| MarkLine | `MarkLine.php` | ⚠️ exists but not integrated |

**Aggregation Layer** (`SugarCraft\Charts\Aggregation`):
- `BucketByTime` — groups timestamped values into time buckets
- `MovingAverage` — SMA and EMA computation
- `Resample` — upsampling (linear/nearest) and downsampling (last/mean)

**Key APIs**:
- Immutable fluent `with*()` pattern throughout
- `Chart::view()` → `renderChart()` → `mergeLegend()` → `addTitle()` → `addYLabel()` → `addXLabel()`
- Short-form aliases on setters: `data`, `size`, `min`, `max`, `palette`, `bars`, etc.

## Strengths

1. **Comprehensive chart coverage** — 11 chart types, all major chart families covered
2. **Immutable API** — consistent `with*()` pattern, no internal mutation during rendering
3. **Universal data coercion** — constructors accept arrays, tuples, assoc arrays, or value objects transparently
4. **Sub-cell rendering via BrailleGrid** — 2x4 dot matrix gives higher resolution than character cells
5. **Multi-stop palette interpolation** — not limited to 2 colors, arbitrary N-stop palettes supported
6. **Protocol auto-detection** — `Picture::detect()` reads `$TERM`/`$TERM_PROGRAM` for image encoding selection
7. **Pure-PHP Sixel encoder** — no external dependencies for Sixel output
8. **Theme integration** — `withTheme(Theme)` wires into candy-sprinkles Theme system

## Weaknesses

1. **Canvas is mutable** — contradicts immutable fluent pattern used elsewhere (CALIBER_LEARNINGS acknowledges this as intentional)
2. **Many `copy()` variants** — `Chart::copy()`, `BarChart::copy()`, `lineChartCopy()` with flag parameters indicate immutability pattern is strained
3. **Sixel palette quantization** — simple RGB cube sampling, no dithering or error diffusion
4. **RGB color blending** — not perceptually uniform (vs LAB interpolation in dashbrew)
5. **No snapshot tests** — only Sixel has byte-level assertions
6. **`Graph::niceNumbers()` edge cases** — scientific notation not handled

---

# Relevant External Repositories

| Repo | Relevance | Major applicable concepts | Priority |
|------|-----------|-------------------------|----------|
| `ratatui/ratatui` | High | Chart widget (X/Y axes, legend), Gauge widget, Canvas (Bresenham drawing), buffer diffing | P0 |
| `charmbracelet/bubbletea` | High | ntkernelcharts upstream, Elm architecture pattern | P0 |
| `NimbleMarkets/ntcharts` | Critical | Primary upstream — all chart types, API shapes | P0 |
| `php-tui/php-tui` | High | ChartWidget, BarChartWidget, SparklineWidget, GaugeWidget, canvas rendering | P0 |
| `ratatui/ratatui-image` | Medium | Terminal image protocols (Sixel/Kitty/iTerm2), sliced rendering, graceful fallback | P1 |
| `textualize/textual` | Medium | Sparkline widget, reactive state for chart updates, animation system | P1 |
| `rasjonell/dashbrew` | Medium | Chart integration patterns, asciigraph usage, LAB color interpolation | P2 |
| `76creates/stickers` | Low | Flexbox-based table/chart composition | P2 |
| `kojiflowers/php-tui-chart` | None | Browser-based (wrapper for TUI Chart JS) — not comparable | — |

---

# Feature Gap Analysis

## Critical

### 1. MarkLine Not Integrated into Charts
**Description**: `MarkLine` class exists as a standalone annotation concept (`min()`, `max()`, `average()`, `at()` static factories) but is not used by any chart class.
**Why it matters**: Threshold reference lines are a fundamental chart annotation feature expected by users.
**Source**: `docs/repo_map/sugarcraft_sugar-charts.md` (upstream comparison section)
**Implementation ideas**: Add `withMarkLines(MarkLine[])` to LineChart/BarChart; render as horizontal dashed lines with labels.
**Expected complexity**: Low — MarkLine already exists; needs integration wiring only.
**Expected impact**: High — fills a common annotation gap.

### 2. Snapshot Tests Absent
**Description**: No chart class has snapshot tests asserting exact SGR byte output. Only Sixel has byte-level assertions.
**Why it matters**: Without snapshot tests, SGR output changes silently and breaking changes go undetected.
**Source**: `docs/repo_map/sugarcraft_sugar-charts.md` (testing section)
**Implementation ideas**: Add `tests/` snapshots for each chart type using golden-file pattern; assert exact `\x1b[...m` bytes.
**Expected complexity**: Low — straightforward golden file comparison.
**Expected impact**: High — prevents regressions in visual output.

## High Value

### 3. Gauge/Arc Chart Type
**Description**: Ratatui has a `Gauge` widget; sugar-charts does not.
**Why it matters**: Gauge charts are common for progress/kpi dashboards.
**Source**: `docs/repo_map/ratatui_ratatui.md` (widget list)
**Implementation ideas**: Implement `Gauge` class with Arc/ semicircle Unicode rendering; integrate with sugar-dash Frame/Panel.
**Expected complexity**: Medium — new chart type with arc calculation.
**Expected impact**: High — fills common dashboard need.

### 4. Logarithmic Axis Scale
**Description**: All axes are linear only; no logarithmic scale support.
**Why it matters**: Log scales are essential for scientific/financial data spanning multiple orders of magnitude.
**Source**: `docs/repo_map/sugarcraft_sugar-charts.md` (incomplete APIs)
**Implementation ideas**: Add `withXLogScale()`, `withYLogScale()` to Chart base class; modify axis tick generation.
**Expected complexity**: Medium — requires tick generation algorithm change.
**Expected impact**: Medium — niche but important for specific domains.

### 5. Grid Overlay Option
**Description**: Charts don't support optional grid lines for reference.
**Why it matters**: Grid lines aid visual estimation of values in charts.
**Source**: `docs/repo_map/sugarcraft_sugar-charts.md` (incomplete APIs)
**Implementation ideas**: Add `withGridLines(bool)` or `withGridStyle(Style)` to Chart base; render via `Graph::drawHLine` at tick positions.
**Expected complexity**: Low — uses existing Graph primitives.
**Expected impact**: Medium — common expectation.

### 6. Per-Data-Point Color (Gradient Within Series)
**Description**: LineChart applies single color per series, not per-point gradients.
**Why it matters**: Gradient coloring within a series can show intensity/spatial distribution.
**Source**: `docs/repo_map/sugarcraft_sugar-charts.md` (incomplete APIs)
**Implementation ideas**: Add `withPointColors(Color[])` or `withGradientFill(bool)` to LineChart; apply per-point color in `renderChart()`.
**Expected complexity**: Medium — requires per-point style tracking.
**Expected impact**: Medium — enhances visual expressiveness.

### 7. Animation Orchestration (ReactPHP)
**Description**: `animationProgress` exists but duration-based animation (driven by ReactPHP timers) is not wired.
**Why it matters**: Smooth chart animations require frame sequencing over time.
**Source**: `docs/repo_map/sugarcraft_sugar-charts.md` (rendering gaps)
**Implementation ideas**: Add `Animation` helper class with `Loop::addTimer()` callbacks; expose `withDuration(int $ms)` on charts.
**Expected complexity**: Medium — requires ReactPHP event loop integration.
**Expected impact**: Medium — enables live data dashboards.

## Medium

### 8. Radar/Spider Chart
**Description**: Not present in sugar-charts.
**Why it matters**: Radar charts are useful for multi-variable comparison.
**Source**: Not present in upstream (ntcharts) — SugarCraft extension opportunity.
**Implementation ideas**: Implement `RadarChart` with polar coordinate projection; multiple axes arranged radially.
**Expected complexity**: High — requires polar coordinate math.
**Expected impact**: Low-Medium — specialized use case.

### 9. Bubble/Scatter with Sized Points
**Description**: `Scatter` uses fixed-size rune; bubble would need size encoding.
**Why it matters**: Bubble charts show 3 dimensions (x, y, size).
**Source**: `docs/repo_map/sugarcraft_sugar-charts.md` (incomplete)
**Implementation ideas**: Add `withBubbleMode(bool)` and `withPointSizes(float[])` to Scatter; render varying radii via BrailleGrid dots or half-block characters.
**Expected complexity**: Medium — size encoding requires visual vocabulary.
**Expected impact**: Low-Medium — specialized.

### 10. Horizontal Stacked Bar Charts
**Description**: `BarChart::withHorizontal()` renders horizontal bars but stacked behavior requires custom Bar extensions.
**Why it matters**: Stacked horizontal bars are common in comparative dashboards.
**Source**: `docs/repo_map/sugarcraft_sugar-charts.md` (incomplete)
**Implementation ideas**: Extend `Bar` value object to support `stackOffset`; modify horizontal rendering to accumulate stacks.
**Expected complexity**: Medium — requires Bar object change.
**Expected impact**: Medium — common dashboard pattern.

### 11. Sixel Dithering
**Description**: Simple RGB cube quantization produces visible banding; no error diffusion/dithering.
**Why it matters**: Photographic or gradient content renders poorly.
**Source**: `docs/repo_map/sugarcraft_sugar-charts.md` (technical debt)
**Implementation ideas**: Implement Floyd-Steinberg or ordered dithering in `Sixel::encode()`.
**Expected complexity**: Medium — dithering algorithm.
**Expected impact**: Medium — visual quality for certain image types.

### 12. Kitty/iTerm2 PNG Encoding
**Description**: `Picture::fromGrid()` works for Sixel but Kitty/iTerm2 require GD/Imagick for PNG encoding.
**Why it matters**: Kitty/iTerm2 provide better image quality than Sixel.
**Source**: `docs/repo_map/sugarcraft_sugar-charts.md` (platform integration)
**Implementation ideas**: Use `Intervention\Image` or `imagick` extension for PNG encoding; detect availability at runtime.
**Expected complexity**: Low (if using library) — integration only.
**Expected impact**: Medium — better image quality on supporting terminals.

## Low Priority

### 13. LAB Color Interpolation
**Description**: `Color::blend()` uses RGB linear interpolation, not perceptually uniform.
**Why it matters**: RGB gradients can have non-uniform perceived brightness across the gradient.
**Source**: `docs/repo_map/sugarcraft_sugar-charts.md` (technical debt)
**Implementation ideas**: Add `Color::blendLab(Color $other, float $t)` using LAB colorspace; use for Heatmap palettes.
**Expected complexity**: Medium — requires RGB→LAB→RGB conversion.
**Expected impact**: Low — subtle visual improvement.

### 14. PHP 8.4 Constructor Property Hooks
**Description**: Many `copy()` variants with flag parameters indicate strained immutability pattern.
**Why it matters**: PHP 8.4 constructor property hooks could simplify immutable setter pattern.
**Source**: `docs/repo_map/sugarcraft_sugar-charts.md` (technical debt)
**Implementation ideas**: Refactor chart classes to use `public function __construct(..., readonly ?Type $field = null) {}` with hooks.
**Expected complexity**: High (refactor) — large surface area.
**Expected impact**: Low — code cleanliness, not user-facing.

### 15. Scientific Notation in `niceNumbers()`
**Description**: `Graph::niceNumbers()` doesn't handle very large/small values with scientific notation.
**Why it matters**: Scientific data with extreme values produces bad tick labels.
**Source**: `docs/repo_map/sugarcraft_sugar-charts.md` (technical debt)
**Implementation ideas**: Detect when range exceeds threshold and switch to scientific notation formatting.
**Expected complexity**: Low — formatting change.
**Expected impact**: Low — edge case.

---

# Algorithm / Performance Opportunities

## Immediate-Mode Rendering vs Buffer Diffing

**Current approach**: sugar-charts produces complete frame strings each render (retained mode).

**External approach (ratatui)**: Immediate-mode rendering with buffer diffing — only changed cells written to terminal.

**Why external is better**: For large dashboards with multiple charts, diffing minimizes terminal I/O. Bubble Tea's ultraviolet renderer uses the same pattern.

**Tradeoffs**: 
- PHP's single-threaded nature makes diffing less critical than in Go/Rust
- sugar-dash already has `Buffer` with diffing capability
- Implementing diffing at chart level would require buffer abstraction matching sugar-dash

**Applicability**: Sugar-dash's `Buffer` class already implements diff. sugar-charts could adopt a similar buffer model if integrated more deeply with sugar-dash for dashboard rendering.

## Sixel Encoding Performance

**Current approach**: Pure PHP with O(n*m) palette quantization where n=pixels, m=palette colors.

**Source**: `docs/repo_map/sugarcraft_sugar-charts.md` — 320x200 image at ~100ms estimated.

**ratatui-image approach**: Sliced rendering for vertical scrolling; protocol-specific optimizations.

**Tradeoffs**: Pure PHP is portable but slower than native code. Most terminals are fast enough that 100ms encoding is acceptable for typical sizes.

**Applicability**: Sixel encoding is already pure PHP. Main optimization would be parallel stripe encoding if PHP's pthreads extension were available (not recommended).

## Animation Frame Computation

**Current approach**: `animationProgress` float 0.0–1.0 controls how many points are rendered — reduces work proportionally.

**External approach (textualize/textual)**: Built-in `Animator` with easing functions and `Transition` objects.

**Why external is better**: Easing functions (ease-in, ease-out, bounce, etc.) produce more natural animations than linear progression.

**Tradeoffs**: SugarCraft would need to implement or port easing functions. PHP doesn't have built-in animation curves.

**Applicability**: Adding `Easing` enum with common curves (linear, easeIn, easeOut, easeInOut, bounce) would enable more polished animations.

---

# Architecture Improvements

## 1. Separate Chart State from Rendering

**Problem**: Chart classes mix data/state with rendering logic. Animation state (`animationProgress`) lives on the chart object.

**Proposed**: Introduce `ChartState` objects (mirroring ratatui's `StatefulWidget` pattern) that hold mutable animation/render state separate from the immutable chart data.

```php
// Current: animation state on chart object
$chart = LineChart::new([1,2,3], 80, 24)->withAnimationProgress(0.5);

// Proposed: separate state
$chart = LineChart::new([1,2,3], 80, 24);
$state = new LineChartState(animationProgress: 0.5);
```

**Source**: `docs/repo_map/ratatui_ratatui.md` — StatefulWidget pattern.

## 2. Canvas/Immutable Consistency

**Problem**: Canvas is mutable, contradicting the immutable fluent pattern used throughout SugarCraft.

**Proposed options**:
- (a) Keep mutable Canvas but document it clearly as an intentional performance optimization
- (b) Implement copy-on-write Canvas that clones on mutation, providing immutability with performance similar to mutable for single-frame renders
- (c) Refactor to use `ImmutableCanvas` for all chart rendering and only use mutable Canvas explicitly in animation loops

**Source**: `docs/repo_map/sugarcraft_sugar-charts.md` — Canvas mutability model section.

## 3. Protocol Abstraction for Picture

**Problem**: Picture mixes concerns — input source (grid vs PNG) with output encoding (Sixel/Kitty/iTerm2).

**Proposed**: Separate `ImageSource` interface from `ProtocolEncoder` interface.

```php
interface ImageSource {
    public function toPixelGrid(): Color[][];
}

interface ProtocolEncoder {
    public function encode(ImageSource, Rect): string;
}
```

**Source**: `docs/repo_map/ratatui_ratatui-image.md` — Protocol encapsulation lesson.

---

# API / Developer Experience Improvements

## 1. MarkLine Integration API

**Proposed**:
```php
$chart = LineChart::new($data, 80, 24)
    ->withMarkLines([
        MarkLine::average()->withStyle($dashed),
        MarkLine::at(0.75)->withLabel('Target'),
    ]);
```

**Source**: `docs/repo_map/sugarcraft_sugar-charts.md` — MarkLine not integrated.

## 2. Consistent Copy/Wither Pattern

**Problem**: `Chart::copy()`, `BarChart::copy()`, `lineChartCopy()` with flag parameters.

**Proposed**: Standardize on single `with(...$params)` pattern using PHP 8.4 constructor property hooks or a base trait.

```php
// Current: multiple copy methods
$chart = $lineChart->lineChartCopy(minSet: true, maxSet: false, newMin: 0);

// Proposed: standard with
$chart = $lineChart->with(min: 0);
```

**Source**: `docs/repo_map/sugarcraft_sugar-charts.md` — technical debt section.

## 3. Chart Type Factory

**Problem**: Each chart type has different constructor signature.

**Proposed**: Unified `Chart::make(string $type, array $config)` factory.

```php
$sparkline = Chart::make('sparkline', ['data' => [1,2,3], 'width' => 20]);
$bar = Chart::make('bar', ['data' => [['a', 1], ['b', 2]], 'width' => 20, 'height' => 5]);
```

**Source**: `docs/repo_map/php-tui_php-tui.md` — ChartWidget factory pattern.

## 4. Graceful Fallback for Terminal Capabilities

**Proposed** (mirroring ratatui-image patterns):
```php
// Try TrueColor, fall back to ANSI 256, fall back to 16-color
$profile = ColorProfile::autoDetect() ?? ColorProfile::ANSI16;
$chart = $chart->withColorProfile($profile);
```

**Source**: `docs/repo_map/ratatui_ratatui-image.md` — graceful fallback patterns.

---

# Documentation / Cookbook Opportunities

## 1. Chart Type Selection Guide

**Gap**: Users must read individual chart docs to choose.
**Proposed**: Add a decision tree / comparison table in README showing:
- Sparkline vs LineChart vs Streamline for time-series
- BarChart vs Heatmap for categorical intensity
- OHLC vs LineChart for financial data
- When to use Waveline (explicit XY) vs TimeSeries (timestamped)

## 2. Responsive Chart Recipes

**Gap**: No documentation on how charts respond to terminal resize.
**Proposed**: Document `autoAdjustRange()`, `withSize()` behavior at various terminal sizes.

## 3. Animation Cookbook

**Gap**: `animationProgress` exists but no examples of animation orchestration.
**Proposed**: Add example using ReactPHP timers for frame sequencing.

## 4. Color Scale Best Practices

**Gap**: Heatmap palettes can produce non-uniform gradients.
**Proposed**: Document palette design principles (equal perceptual spacing, avoiding banding).

## 5. Integration with sugar-dash

**Gap**: BrailleCanvas integration is mentioned but not fully documented.
**Proposed**: Cookbook section showing `LineChart::withCanvas(BrailleCanvas)` for dashboard use.

---

# UX / TUI Improvements

## 1. Hover Tooltips for LineChart/Scatter

**Problem**: No way to inspect individual data point values.
**Why it matters**: Standard expectation for interactive charts.
**Source**: Common in all major chart libraries (Chart.js, D3, etc.)

**Proposed**: Add `withTooltips(bool)` to LineChart/Scatter; render tooltip on mouse position using candy-zone mouse tracking.

## 2. Click-to-Isolate Dataset

**Problem**: Multi-dataset charts show all series simultaneously.
**Why it matters**: Clicking a series to isolate it is common interactive behavior.
**Source**: Standard in interactive chart libraries.

**Proposed**: Add `withInteractive(bool)` and emit click events via candy-core message system.

## 3. Axis Label Truncation

**Problem**: Long labels may overflow or wrap awkwardly.
**Why it matters**: Poor UX when chart labels don't fit.
**Source**: `docs/repo_map/charmbracelet_glow.md` — word wrap lessons.

**Proposed**: Add `withTruncatedLabels(bool)` and ellipsis truncation for labels exceeding available width.

## 4. Automatic Width Detection Without `tput`

**Problem**: Requires external `tput` command for terminal width.
**Why it matters**: `tput` not universally available; users expect auto-detection.
**Source**: `docs/repo_map/pr_charmbracelet_glow.md` — issue #942.

**Proposed**: Use `PHP_EOL` detection or environment variable fallback; document `--width` override.

---

# Testing / Reliability Improvements

## 1. Snapshot Tests for All Chart Types

**Priority**: Critical.
**Current**: Only Sixel has byte-level assertions.
**Proposed**: Add golden-file snapshots for each chart type asserting exact `\x1b[...m` SGR bytes.

```php
public function testSparklineRendersCorrectly(): void
{
    $sparkline = Sparkline::new([1, 3, 2, 8, 5, 4, 7, 6], 8);
    $this->assertMatchesGoldenFile($sparkline->view(), 'sparkline_basic.txt');
}
```

**Source**: `docs/repo_map/sugarcraft_sugar-charts.md` — snapshot tests needed.

## 2. Color Profile Cross-Platform Tests

**Priority**: High.
**Current**: Tests assume TrueColor output.
**Proposed**: Test with ANSI16 and ANSI256 fallbacks to ensure graceful degradation.

## 3. Sixel Encoding Correctness Tests

**Priority**: High.
**Current**: Only 6 tests for Sixel.
**Proposed**: Add tests for:
- Palette quantization edge cases
- Image dimensions at terminal boundaries
- Empty/transparent regions

**Source**: `docs/repo_map/pr_ratatui_ratatui-image.md` — Sixel trailing empty bands issue.

## 4. Aggregation Algorithm Tests

**Priority**: Medium.
**Current**: MovingAverage, Resample, BucketByTime have tests but edge cases may be missing.
**Proposed**: Add tests for:
- Empty input handling
- Single-element windows
- Very large timestamp ranges
- Timezone edge cases in BucketByTime

---

# Ecosystem / Integration Opportunities

## 1. Deeper sugar-dash Integration

**Current**: BrailleCanvas bridge is experimental.
**Proposed**: Formalize `Chart` → `Drawable` adapter so charts integrate seamlessly into sugar-dash layouts.

```php
$grid->addItem(
    new Frame(ChartDrawable::from($lineChart))->withPadding(1),
    new ItemOptions(column: 0)
);
```

**Source**: `docs/repo_map/sugarcraft_sugar-dash.md` — chart integration.

## 2. Integration with sugar-table for DataTable + Chart Combos

**Proposed**: Allow `Table` cells to contain inline sparklines for compact data display.

## 3. sugar-prompt Integration

**Proposed**: Add `selectChart()` or `pickChartType()` helper in sugar-prompt for interactive chart type selection.

## 4. Export to HTML (for reports)

**Gap**: Charts only render in terminal.
**Proposed**: Add `toHtml()` method to charts for non-terminal output (report generation).

## 5. CLI Tool for Quick Chart Generation

**Proposed**: `bin/chart` CLI tool accepting JSON/stdin for quick chart generation without PHP code.

```bash
echo '{"type": "bar", "data": [["a", 1], ["b", 2]]}' | php bin/chart --width 40 --height 10
```

---

# Notable PRs / Issues / Discussions

## ratatui/ratatui-image: v11 Major Refactor (2026)

**Summary**: Large breaking changes for architectural clarity — FontSize tuple→struct, Rect→Size separation, private ImageSource.

**Relevance**: Informs better API design principles: distinguish size-only from positioned areas, keep internals private, break API for clarity pre-1.0.

**Source**: `docs/repo_map/pr_ratatui_ratatui-image.md` — architectural lessons.

## ratatui/ratatui: Gauge Widget

**Summary**: Built-in progress/gauge widget with block characters.
**Relevance**: sugar-charts should implement similar Gauge for dashboard use cases.

**Source**: `docs/repo_map/ratatui_ratatui.md` — widget list.

## php-tui/php-tui: ChartWidget and BarChartWidget

**Summary**: Complete Ratatui port with ChartWidget supporting line/scatter charts with axes and datasets, BarChartWidget with groups and labels.

**Relevance**: sugar-charts could reference php-tui's ChartWidget implementation for patterns in axis rendering, data normalization, and widget rendering interface.

**Source**: `docs/repo_map/php-tui_php-tui.md` — widget descriptions.

## charmbracelet/glow: Initialization Race Condition

**Summary**: Multiple bugs from Init() running before WindowSizeMsg — causes incorrect rendering on TUI startup.

**Relevance**: sugar-charts should defer rendering until dimensions confirmed; store chart data and re-render on resize.

**Source**: `docs/repo_map/pr_charmbracelet_glow.md` — word wrap initialization race.

## ratatui/ratatui-image: Terminal Probing Fragility

**Summary**: Terminal capability detection via escape sequences fails in many scenarios — Windows, stdout locked, old terminal versions.

**Relevance**: sugar-charts' `ColorProfile::autoDetect()` should provide explicit fallback values and never panic.

**Source**: `docs/repo_map/pr_ratatui_ratatui-image.md` — terminal probing lessons.

## textualize/textual: Reactive Chart Updates

**Summary**: Charts use reactive `watch_*()` methods to auto-update when data changes.

**Relevance**: sugar-charts could adopt reactive patterns for live data scenarios.

**Source**: `docs/repo_map/textualize_textual.md` — reactive state management.

---

# Recommended Roadmap

## Immediate Wins (0-2 sprints)

1. **Wire MarkLine into LineChart** — existing class just needs integration
2. **Add snapshot tests for core chart types** — prevent regressions
3. **Document animation cookbook** — even without ReactPHP wiring, document `animationProgress` usage
4. **Fix Graph::niceNumbers() edge cases** — handle scientific notation

## Medium-Term Improvements (2-4 sprints)

5. **Implement Gauge chart type** — common dashboard need
6. **Add logarithmic axis scale** — important for scientific/financial data
7. **Grid overlay option** — simple but high value
8. **Per-data-point colors for LineChart** — gradient fills
9. **Sixel dithering** — improve gradient image quality
10. **Kitty/iTerm2 PNG support** — using Intervention/Image or imagick

## Major Architectural Upgrades (4-8 sprints)

11. **ChartState separation** — adopt StatefulWidget pattern
12. **ReactPHP animation orchestration** — duration-based frame sequencing
13. **Refactor to PHP 8.4 constructor hooks** — clean up copy() variants
14. **ImmutableCanvas option** — resolve mutability inconsistency
15. **Protocol abstraction for Picture** — separate ImageSource from encoding

## Experimental Ideas (future)

16. **Radar/spider chart** — polar coordinate math
17. **Hover tooltips** — requires candy-zone integration
18. **LAB color interpolation** — perceptual uniformity
19. **Export to HTML** — non-terminal rendering
20. **CLI chart generation tool** — quick data visualization

---

# Priority Matrix

| Opportunity | Impact | Complexity | Risk | Priority |
|-------------|--------|------------|------|----------|
| MarkLine integration | High | Low | Low | P0 |
| Snapshot tests | High | Low | Low | P0 |
| Gauge chart type | High | Medium | Low | P1 |
| Logarithmic axis | Medium | Medium | Low | P1 |
| Grid overlay | Medium | Low | Low | P1 |
| Per-point colors | Medium | Medium | Low | P1 |
| Animation wiring | Medium | Medium | Medium | P1 |
| Sixel dithering | Medium | Medium | Low | P2 |
| Kitty/iTerm2 PNG | Medium | Low | Medium | P2 |
| ChartState separation | Medium | High | Medium | P2 |
| Logarithmic axis | Medium | Medium | Low | P1 |
| Radar chart | Low | High | Low | P3 |
| LAB interpolation | Low | Medium | Low | P3 |
| HTML export | Low | Medium | Low | P3 |
| Hover tooltips | Medium | High | Medium | P3 |

---

# Final Strategic Assessment

sugar-charts is a **well-structured, mature charting library** that successfully ports the NimbleMarkets/ntcharts API to PHP with meaningful extensions. The architecture cleanly separates concerns: Canvas primitives for low-level drawing, Graph for chart-specific primitives, and individual chart classes for domain logic.

**Key strengths**: Comprehensive chart coverage (11 types), consistent immutable API, BrailleCanvas for high-resolution rendering, pure-PHP Sixel encoder, and Theme integration.

**Key weaknesses**: No snapshot tests, MarkLine annotation unused, missing common chart types (gauge, radar), Canvas mutability inconsistency, and no animation orchestration.

**Competitive position**: php-tui has ChartWidget/BarChartWidget but no sparkline/heatmap/OHLC. ratatui has all widget types but is Rust-only. sugar-charts has the most comprehensive PHP terminal chart coverage.

**Strategic priorities**:
1. **Complete the MarkLine integration** — low effort, high value, immediately useful
2. **Add snapshot testing** — prevents regressions, enables confident iteration
3. **Implement Gauge** — fills common dashboard need, matches ecosystem expectations
4. **Wire up ReactPHP animation** — enables live data dashboards, differentiates from competitors

**Dependency health**: The bridge to sugar-dash via BrailleCanvas is experimental but functional. The pure-PHP Sixel encoder is a strong differentiator — no GD/Imagick dependency. Dependencies on candy-core and candy-sprinkles are stable.

**Pre-1.0 opportunities**: The v11 breaking changes in ratatui-image demonstrate that pre-1.0 is the time for API cleanup. sugar-charts should consider:
- Breaking `copy()` variants in favor of standardized `with()` pattern before v1.0
- Making Canvas mutability explicit (either fully mutable for animation or immutable with explicit mutable wrapper)
- Establishing clear ChartState pattern for animation/render state separation

The library is ready for production use in dashboard scenarios and has solid fundamentals. Remaining work is largely extension (more chart types), integration (MarkLine, animation), and hardening (snapshot tests, error handling).
