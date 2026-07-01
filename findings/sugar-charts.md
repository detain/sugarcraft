# Code Review: sugar-charts

**Library:** sugar-charts (SugarCraft monorepo port of NimbleMarkets/ntcharts)  
**Review scope:** `src/` (30 PHP files) + `tests/` (30+ test files)  
**PHP version:** ^8.3 | **Test framework:** PHPUnit 10.5

---

## 1. Architecture & Design

### 1.1 Abstract Base `Chart` Not Fully Utilized

`Chart` (src/Chart/Chart.php) is a well-designed abstract base class providing legend, title, X/Y label composition, animation state, and a fluent `with*` API. `LineChart` correctly extends it and routes all configuration through `lineChartCopy()`.

However, `BarChart`, `Scatter`, and `OHLCChart` are **standalone classes** that re-implement the *exact same* `buildChartWithExtras`, `mergeLegend`, `mergeLegendLeftRight`, `addTitle`, `addYLabel` patterns verbatim from Chart. This is the most significant code duplication issue — roughly 200 lines of identical logic copied 3 times.

```
BarChart.php:261-385   ← copy of Chart's extras logic
Scatter.php:164-321    ← copy of Chart's extras logic
OHLCChart.php:195-370  ← copy of Chart's extras logic
```

These three classes should extend `Chart` or share a `ChartExtras` trait.

### 1.2 `candy-async` in composer.json But Never Used

`sugar-charts/composer.json:80` declares `"sugarcraft/candy-async": "dev-master"` in the `repositories` path section. However, **zero source files in `src/` import anything from `candy-async`**. This dependency is dead weight — it adds to composer install time and the path-repo closure without providing any value.

### 1.3 `MarkLine` Not Integrated Into Rendering

`MarkLine` (src/MarkLine.php) is documented as a standalone value object with the comment "Rendering integration with chart classes... is not yet wired." It computes min/max/average lines but the output can only be retrieved programmatically — it cannot overlay a horizontal reference line on any chart. This is an incomplete feature that gives the appearance of more functionality than exists.

---

## 2. Code Duplication

### 2.1 Massive Duplication of `copy()` Methods

Each of BarChart, Scatter, OHLCChart, and LineChart implements an immutable copy-with-overrides method using the **flag-parameter pattern** (`$minSet = false`, `$maxSet = false`, etc.):

- `BarChart::copy()` — 43 lines, 19 parameters (src/BarChart/BarChart.php:570)
- `BarChart::barWidthCopy()` — 22-line near-duplicate (src/BarChart/BarChart.php:618)
- `Scatter::copy()` — 37 lines, 16 parameters (src/Scatter/Scatter.php:334)
- `OHLCChart::copy()` — 43 lines, 18 parameters (src/OHLC/OHLCChart.php:380)
- `LineChart::lineChartCopy()` — 73 lines, 30 parameters (src/LineChart/LineChart.php:556)

These are ~200 lines of nearly identical boilerplate. A shared `Mutable` concern/trait or a code-generated base would eliminate this.

### 2.2 `BufferHelper::firstCodepoint` Duplicates `mb_ord`

`BufferHelper::firstCodepoint()` (src/Buffer/BufferHelper.php:110) manually extracts Unicode codepoints from a grapheme cluster. The method checks `function_exists('mb_ord')` and then uses it as fallback — but the primary path is a manual byte-by-byte extraction. `mb_ord($g, 'UTF-8')` is available in PHP 8.3+ and handles all cases correctly. The manual extraction is unnecessary complexity.

### 2.3 `Graph::drawLine` and `Waveline::drawConnector` Are Identical

`Graph::drawLine()` (src/Canvas/Graph.php:160) and `Waveline::drawConnector()` (src/LineChart/Waveline.php:167) implement the **exact same** Bresenham line algorithm with the same slope-to-rune mapping. `Waveline` should delegate to `Graph::drawLine()` or use `Graph::drawLinePoints()`.

### 2.4 `Canvas::view()` Uses String Concatenation in a Loop

`Canvas::view()` (src/Canvas/Canvas.php:265) builds each row by string concatenation in a loop (`$row .= ...`). For large canvases this creates many intermediate strings. Using an array `$rows[]` and `implode` would be more efficient.

### 2.5 `Legend::coloredIndicator` Rebuilds `$colorMap` on Every Call

`Legend::coloredIndicator()` (src/Legend/Legend.php:158) declares `static $colorMap = [...]` inside the method body. PHP re-initializes this static on every call. It should be a **class constant** instead.

---

## 3. Logic Issues & Edge Cases

### 3.1 `Sparkline::withNoAutoMaxValue` — Double-Negative API

`withNoAutoMaxValue(bool $disable = true)` — the method name says "no auto max value" but takes a `$disable` parameter defaulting to `true`. The double-negative is confusing. A clearer name would be `withAutoMaxValue(bool $enable = true)` mirroring the standard convention.

### 3.2 `Sparkline::glyph` Discontinuity at Minimum Value

`Sparkline::glyph()` (src/Sparkline/Sparkline.php:214) has a special case:
```php
if ($idx < 1 && $v > $min) { $idx = 1; }
```
This means a value *just barely* above the minimum gets glyph 1 (lowest non-empty bar), but if all values are identical (`range = 0`), it returns glyph 4 (mid-bar). This creates a visual discontinuity — a tiny change near the minimum produces a large visual jump.

### 3.3 `Waveline` No Out-of-Bounds Clipping

`Waveline::view()` (src/LineChart/Waveline.php:122) calls `$canvas->setCell()` without bounds checking on projected coordinates. Points outside the canvas are silently dropped without any indication to the caller.

### 3.4 `Scatter::view` Returns Canvas With Trailing Newlines

`Scatter::view()` (src/Scatter/Scatter.php:164) returns `(new Canvas($w, $h))->view()` for empty input. For a `3×2` canvas this produces `"\n\n"` — two trailing newlines. `BarChart::view()` returns `''` for empty input. This inconsistency means the same canvas geometry produces different string lengths across chart types.

### 3.5 `BufferHelper::colorToInt` Trusts Property Names Without Validation

`BufferHelper::colorToInt()` (src/Buffer/BufferHelper.php:105) accesses `$color->r`, `$color->g`, `$color->b` via magic property access. If the Color object uses a different property naming scheme, this silently produces incorrect results. No type hint and no validation.

### 3.6 `LineChart` Animation Progress Ignores Datasets

`LineChart::renderChart()` (src/LineChart/LineChart.php:386) limits animation to the primary data series only. Named datasets (added via `withDataset()`) are drawn fully regardless of animation progress. This is inconsistent.

### 3.7 `BarChart::copy` Can Lose Animation State

`BarChart::copy()` (src/BarChart/BarChart.php:570) does not include `animationProgress` or `animationDuration` parameters. `BarChart` has `withAnimationProgress()` and `withAnimationDuration()` inherited from `Chart`, but they return `Chart::copy()` — not `BarChart::copy()`. Since BarChart's `copy()` is a different method that doesn't include animation parameters, animation state is **silently dropped** when calling BarChart's copy methods.

### 3.8 `Graph::niceNumbers` Edge Case: Zero Range

`Graph::niceNumbers()` (src/Canvas/Graph.php:533) correctly returns `[$min]` when `$min === $max`, but the returned value is the raw `$min`, not a "nice" rounded number. Document this as a known edge case.

### 3.9 `Resample::upsampleNearest` Slight Bias

`Resample::upsampleNearest()` (src/Aggregation/Resample.php:262) always picks the later point when equidistant between two inputs. This creates a slight bias and potential oscillation in the output.

---

## 4. Performance Issues

### 4.1 `Sixel::nearest` O(n) Palette Lookup

`Sixel::nearest()` (src/Picture/Sixel.php:165) performs a linear scan of all palette entries per pixel. For a 256-color palette over a 320×200 image, that's 256 × 64,000 = 16.4 million distance calculations. A spatial index would significantly improve encoding speed.

### 4.2 `Heatmap::view` Style Object Allocation Per Cell

`Heatmap::view()` (src/Heatmap/Heatmap.php:202) creates a new `Style` object **for every cell**. For a 100×100 heatmap, that's 10,000 Style object allocations per render. These could be cached by color value.

### 4.3 `Graph::getFullCirclePoints` Uses String Keys for Deduplication

`Graph::getFullCirclePoints()` (src/Canvas/Graph.php:291) builds `$key = $x . ',' . $y` as a string. For large radii this creates many string allocations. A packed integer key would be more efficient.

### 4.4 `BufferHelper::isZeroWidth` Uses Sequential `in_array`

`BufferHelper::isZeroWidth()` (src/Buffer/BufferHelper.php:134) checks membership in a 100+ element array via `in_array()`. This is O(n). A hash-set lookup would be O(1).

---

## 5. Memory & Object Model

### 5.1 `Sparkline` Immutable Push Creates Copies on Every Push

`Sparkline::push()` (src/Sparkline/Sparkline.php:113) creates a new `Sparkline` instance with a copy of the entire data array on every call. For streaming use cases with thousands of pushes, this allocates O(n²) total memory over the lifetime of a stream. `Streamline::push()` has a sliding window cap which mitigates this.

### 5.2 `Resample::add` / `addMany` Clone-Then-Mutate Pattern

`Resample::add()` (src/Aggregation/Resample.php:89) and `BucketByTime::add()` do `$clone = clone $this` then immediately mutate `$clone->points[] = ...`. While correct, the intermediate `clone` is unnecessary overhead for a simple append.

---

## 6. API Design Issues

### 6.1 Flag Parameters in `copy()` Methods

All `copy()` methods use boolean flag parameters (`$minSet`, `$maxSet`, `$barWidthSet`, etc.) to distinguish "not passed" from "explicitly null". This is a workaround for PHP's lack of native optional parameter overloading. With PHP 8.3's property hooks, this pattern becomes unnecessary.

### 6.2 Inconsistent Empty-Output Behavior

- `BarChart::view()` returns `''` for empty input
- `Scatter::view()` returns `"\n\n"` for 3×2 empty canvas
- `Heatmap::view()` returns `"\n\n"` for empty canvas
- `OHLCChart::view()` returns `"\n\n"`
- `LineChart::view()` returns `Canvas->view()` for empty

Charts should agree on one empty-output convention.

### 6.3 `Chart` Protected `renderChart` Not Enforced for All

Only `LineChart` extends `Chart`. `BarChart`, `Scatter`, `OHLCChart` are standalone with their own `view()`/`renderChart()` splits but aren't part of the hierarchy. Consuming code cannot treat all charts polymorphically via `Chart` as the base.

---

## 7. Async / ReactPHP Integration

### 7.1 No Async Streaming Infrastructure

The library declares `sugarcraft/candy-async` as a dependency but provides **zero async integration**. The streaming classes (`Sparkline::push()`, `Streamline::push()`, `Heatmap::pushPoint()`) produce new instances but no signals for rendering systems to know when to re-render. No event loop integration exists.

### 7.2 `Streamline` Is Not Truly Reactive

`Streamline::push()` returns a new `Streamline` instance. There is no mechanism to automatically trigger a re-render when data changes. Users must manually call `push()` then `view()`.

---

## 8. Missing Features

### 8.1 No Stacked Bar Chart, Pie/Donut, or Histogram
Common chart types absent from the library.

### 8.2 No Real-Time Animation Driver
`LineChart` has `withAnimationProgress()` but no ReactPHP-based animation loop that drives it frame-by-frame.

### 8.3 No Grid Lines Option
`Graph::drawXYAxis()` draws the axis frame, but no chart class exposes options for horizontal/vertical grid lines at tick marks.

### 8.4 No Export to Plain Text / CSV
Charts only render to ANSI strings. No `toCsv()` or `toRawValues()` method exists.

---

## 9. Security

No significant security issues found. `Picture::detect()` reads `TERM`/`TERM_PROGRAM` from the environment — at worst, manipulation causes fallback to text output. No risk of terminal injection because SGR codes are generated by `Style::render()`.

---

## 10. Compatibility

- `candy-async` path-repo declared but not used — should be removed or implemented
- `LineChart` extends `Chart` correctly with proper constructor chaining
- All 30 source files declare `declare(strict_types=1)`
- All classes are `final` unless extension is intended — consistent with AGENTS.md

---

## 11. Test Coverage Observations

- **Golden file tests** (`GoldenRenderTest.php`) correctly use `candy-testing`'s snapshot assertions
- **Short-alias parity tests** (`ShortAliasesTest.php`) verify byte-identical output
- **Edge case coverage** is good for negative dimensions, empty inputs, degenerate ranges, label truncation
- **Missing tests**: `Graph::niceNumbers`, `BufferHelper::graphemeWidth`, `Waveline`, `Streamline`, `OHLCChart`, `Heatmap`
- **`TestChart::testCopy()`** exposes protected `copy()` via a public test method — works but is a code smell

---

## 12. Prioritized Recommendations

### High Priority

1. **Extract `ChartExtras` trait** — eliminate ~200 lines of duplication in BarChart, Scatter, OHLCChart
2. **Remove dead `candy-async`** path-repo or implement async streaming
3. **Fix `BarChart::copy`** to include animation parameters or remove inherited animation methods
4. **Unify empty-output behavior** — all chart `view()` should return `''` for empty data
5. **Unify `Waveline::drawConnector` with `Graph::drawLine`**

### Medium Priority

6. **Cache `Style` objects in `Heatmap::view()`** — avoid 10,000 allocations per render
7. **Replace `in_array` with hash-set** in `BufferHelper::isZeroWidth`
8. **Make `Legend::$colorMap` a class constant**
9. **Add `mb_ord` as primary path** in `BufferHelper::firstCodepoint`
10. **Rename `withNoAutoMaxValue`** to `withAutoMaxValue`
11. **Fix `LineChart` animation** to apply to datasets
12. **Implement `MarkLine` rendering** or remove the class
13. **Add validation to `BufferHelper::colorToInt`**

### Low Priority

14. Implement O(log n) palette lookup in `Sixel::nearest`
15. Add ReactPHP animation driver using `candy-async`
16. Add grid-line support to axis rendering
17. Consider PHP 8.3 property hooks for `copy()` migration
18. Add missing test coverage
19. Add stacked bar, pie, histogram chart types

---

*Review completed. All findings are based on direct reading of source files. No assumptions about runtime behavior were made.*
