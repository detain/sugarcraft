---
status: r86-review (Phase-2/3/4 re-derived + built; Phase-1 4/5 landed, 1.3 premise-false)
phase: 4
updated: 2026-09-17
---

# Implementation Plan: sugar-charts Code Review Findings

## Goal

Address all 19 prioritized findings from the sugar-charts code review, organized into 5 phases from critical refactoring to missing features.

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Extract `ChartExtras` trait instead of forcing inheritance | BarChart, Scatter, OHLCChart have different constructor signatures making direct extension complex | `src/Chart/Chart.php:L1-354` vs `src/BarChart/BarChart.php:L32-641` |
| Remove dead `candy-async` path-repo | Never imported in any source file, dead weight in composer install | `sugar-charts/composer.json:L80` |
| Use empty string `''` as unified empty-output convention | BarChart already returns `''` for empty; Scatter/OHLC/Heatmap return `"\n\n"` inconsistent | `src/BarChart/BarChart.php:L263-265` |
| Use `mb_ord` as primary path in `firstCodepoint` | PHP 8.3+ always has `mb_ord` available, manual extraction unnecessary | `src/Buffer/BufferHelper.php:L110-132` |
| Make `Legend::$colorMap` a class constant | Static local variable in method re-initialized every call | `src/Legend/Legend.php:L158-169` |

---

## Phase 1: Critical Code Duplication & Architecture [PENDING]

- [ ] **1.1 Extract `ChartExtras` trait** — Create `src/Chart/ChartExtras.php` trait with `buildChartWithExtras`, `mergeLegend`, `mergeLegendLeftRight`, `addTitle`, `addYLabel` methods. Refactor BarChart, Scatter, OHLCChart to use trait instead of duplicating ~200 lines. BarChart:L261-385, Scatter:L164-321, OHLCChart:L195-370 are all copies of Chart:L80-185. Severity: **high**. Verify: All existing tests pass.

- [ ] **1.2 Remove dead `candy-async` path-repo** — Delete `sugar-charts/composer.json:L78-84` path repository entry. Zero source files import from candy-async. Severity: **high**. Verify: `composer validate` passes, tests pass.

- [ ] ❗ **1.3 premise-false at r86 verify (lane v4)** — BarChart is standalone (`src/BarChart/BarChart.php:33-34`: `final class BarChart` + `use ChartExtras`, no `extends Chart`); neither the class nor the trait exposes `withAnimation*`, so the flag-carry `copy()` at :560 drops no animation state. Animation lives only on `Chart` (:294-301) and `LineChart` (`lineChartCopy` carries `animationProgress` :769). (Historical prescription: add animation params to copy() because BarChart "inherits" the setters — the inheritance claim is what was false.) No fix needed.

- [ ] **1.4 Unify empty-output behavior** — Change Scatter::view(), OHLCChart::view(), and Heatmap::view() to return `''` for empty input instead of `"\n\n"` (canvas view). BarChart already returns `''`. Scatter:L166-167, OHLCChart:L197-198, Heatmap:L204-205. Severity: **high**. Verify: All chart types return `''` for empty data.

- [ ] **1.5 Unify Waveline::drawConnector with Graph::drawLine** — Refactor `Waveline::drawConnector()` (Waveline:L167-189) to delegate to `Graph::drawLine()` (Graph:L160-178). Both implement identical Bresenham line algorithm with same slope-to-rune mapping. Severity: **high**. Verify: Waveline tests pass.

---

## Phase 2: Performance & Memory Optimizations [PENDING]

- [x] ✅ **[r86-v4 LANDED]** **2.1 Cache Style objects in Heatmap::view()** — `$styleCache` keyed on the sampled Color's (r,g,b); profile/cellStyle are loop-invariant so equal colours share one final Style. Render-dump byte-identical; pins + mutation-discriminated in `tests/Support/Phase2PerfPinsTest.php`. Original: — Add `$styleCache` array in `Heatmap::view()` to reuse Style objects by color value. Currently creates 10,000 Style allocations per 100×100 heatmap render (Heatmap:L248). Severity: **medium**. Verify: Performance benchmark improves.

- [x] ✅ **[r86-v4 LANDED]** **2.2 Replace in_array with hash-set in BufferHelper::isZeroWidth** — `private const ZERO_WIDTH` (124 keyed members, source order verbatim) + `isset(self::ZERO_WIDTH[$cp])`. Original: — Convert `isZeroWidth()` (BufferHelper:L134-164) from `in_array()` O(n) to keyed array lookup `isset()` O(1) for the 100+ codepoint zero-width set. Severity: **medium**. Verify: All BufferHelper tests pass.

- [x] ✅ **[r86-v4 LANDED]** **2.3 Make Legend::coloredIndicator colorMap a class constant** — now `private const COLOR_MAP` with `Ansi::CSI` literals, pinned equal to the former `Ansi::fg16()`/`Ansi::sgr(39)` strings. Premise note: PHP method-statics init ONCE, so the old cost was first-call only — the const is intent-correctness, not a hot-path win. Original: — Move `static $colorMap` from method body (Legend:L160) to `private const COLOR_MAP` class constant. PHP re-initializes static on every call. Severity: **medium**. Verify: Legend tests pass.

- [x] ✅ **[PRE-R86 ALREADY LANDED, re-confirmed r86-v4]** **2.4 Swap mb_ord to primary path in BufferHelper::firstCodepoint** — `BufferHelper.php:112` leads with `mb_ord`, manual byte extraction is the fallback. Original: — Reorder logic so `mb_ord()` is the primary path (available in PHP 8.3+) and manual byte extraction is the fallback. Currently reversed (BufferHelper:L112-116). Severity: **medium**. Verify: BufferHelper tests pass.

- [x] ✅ **[r86-v4 LANDED, cheaper shape]** **2.5 Optimize Sixel::nearest with spatial index** — `nearest()` is pure in (rgb, fixed palette), so `encode()` carries an RGB-keyed memo scoped to the call (no cross-palette staleness — pinned); k-d tree unnecessary. Original: — Replace O(n) linear palette scan (Sixel:L165-180) with O(log n) k-d tree or grid-based spatial index. 256-color palette × 64,000 pixels = 16.4M calculations for 320×200 image. Severity: **low**. Verify: Encoding results identical, benchmark improves.

---

## Phase 3: Logic & Edge Case Fixes [PENDING]

- [ ] ⏭️ **[r86 RULED OUT — STOP-class]** **3.1 Rename withNoAutoMaxValue to withAutoMaxValue** — public-API break; excluded from build scope by the round-86 mission ruling, zero touch (name lives `Sparkline.php:95`). Original: — Rename `Sparkline::withNoAutoMaxValue(bool $disable = true)` (Sparkline:L89-98) to `withAutoMaxValue(bool $enable = true)`. Double-negative API is confusing. Severity: **medium**. Verify: Sparkline tests updated and passing.

- [x] ✅ **[PRE-R86 ALREADY ADDRESSED, verified r86-v4]** **3.2 Fix Sparkline::glyph discontinuity at minimum value** — the floor guard `$idx < 1 && $v > $min → glyph[1]` (`Sparkline.php:227-229`) removes the just-above-min blanking; the `range == 0` steady mid-bar is an intentional product choice. Original: — Review `Sparkline::glyph()` special case (Sparkline:L214-228). When range=0 returns glyph[4] (mid-bar), but value just above min gets glyph[1]. Visual discontinuity. Severity: **medium**. Verify: Edge case tested.

- [x] ✅ **[PRE-R86 ALREADY LANDED, verified r86-v4]** **3.3 Add out-of-bounds clipping to Waveline::view()** — `$project` clamps normalized tx/ty to [0,1] (`Waveline.php:161-162`) and `Canvas::setCell` hard-guards coordinates (`Canvas.php:41-43`). Original: — Add bounds checking before `setCell()` calls in `Waveline::view()` (Waveline:L155). Coordinates outside canvas are silently dropped. Severity: **medium**. Verify: Out-of-bounds tests.

- [x] ✅ **[r86-v4 LANDED as boundary typing]** **3.4 Add validation to BufferHelper::colorToInt** — narrowed `object` → `Color` (parse, don't validate; call sites already feed only Sprinkles `Style::getForeground()/getBackground()`). Original: — Add type/structure validation before accessing `$color->r`, `$color->g`, `$color->b` (BufferHelper:L107). Currently trusts property names without validation. Severity: **medium**. Verify: Invalid Color input handled gracefully.

- [x] ✅ **[PRE-R86 ALREADY LANDED, verified r86-v4]** **3.5 Fix LineChart animation to apply to all datasets** — `renderChart()` animates `$allSeries = ['_primary' => data] + datasets` (`LineChart.php:521-537`). Original: — Update `LineChart::renderChart()` (LineChart:L447-482) to apply animation progress to named datasets in addition to primary series. Currently only primary series is animated. Severity: **medium**. Verify: Animation tests with datasets.

- [x] ✅ **[r86-v4 LANDED, docs-only]** **3.6 Document Graph::niceNumbers zero-range edge case** — docblock now states the `$min === $max` single-tick unrounded return. Original: — Add docblock note that `Graph::niceNumbers()` returns raw `$min` without rounding when `$min === $max` (Graph:L541-542). No code change needed. Severity: **low**. Verify: Docblock added.

---

## Phase 4: API Improvements [PENDING]

- [x] ✅ **[LANDED — verified r86-v4]** **4.1 Implement MarkLine rendering or remove class** — rendering wired by 9065a2784 (ancestor of b26b9989f): `LineChart::withMarkLines()` :255 + alias :330, paint pass `drawMarkLines()` :613 called at :572 sharing the series row mapping; `[]` renders byte-identical. Original: — `MarkLine` (MarkLine:L1-95) computes min/max/average but rendering "not yet wired" (line 12-15). Either implement integration with charts or remove the incomplete feature. Severity: **medium**. Verify: MarkLine renders on charts OR class removed.

- [ ] **4.2 Consider PHP 8.3 property hooks for copy() methods** — Research using property hooks to replace flag-parameter pattern (`$minSet = false`) in BarChart::copy(), Scatter::copy(), OHLCChart::copy(), LineChart::lineChartCopy(). Finding 6.1. Severity: **low**. Verify: Research complete, recommendation documented.

- [ ] **4.3 Add grid lines option to Graph::drawXYAxis** — Add optional grid lines at tick marks to axis rendering (Graph:L74-89). Finding 8.3: no chart exposes grid line options. Severity: **low**. Verify: New parameter/option available.

---

## Phase 5: Missing Features & Tests [PENDING]

- [ ] **5.1 Add missing test coverage** — Add tests for: `Graph::niceNumbers`, `BufferHelper::graphemeWidth`, `Waveline`, `Streamline`, `OHLCChart`, `Heatmap`. Finding 11 identifies these as lacking coverage. Severity: **low**. Verify: Coverage improved.

- [ ] **5.2 Add stacked bar, pie/donut, histogram chart types** — Implement common chart types absent from library. Finding 8.1. Severity: **low**. Verify: New chart classes implemented and tested.

- [ ] **5.3 Add ReactPHP animation driver** — Implement animation loop that drives `LineChart::withAnimationProgress()` frame-by-frame using candy-async. Finding 8.2: animation exists but no driver. Severity: **low**. Verify: Driver class works with LineChart.

- [ ] **5.4 Add export to plain text/CSV** — Add `toCsv()` or `toRawValues()` methods to chart classes. Finding 8.4: only ANSI string output exists. Severity: **low**. Verify: Export methods work.

---

## Notes

- Phase 1 tasks are blocking: fix code duplication before addressing performance
- Each task should be verified with tests before considering complete
- The plan covers all 19 prioritized items from the findings file (12 high/medium, 7 low)
- All phases start `[PENDING]` — implementation begins with Phase 1
