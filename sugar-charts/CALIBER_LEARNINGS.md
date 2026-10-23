# Caliber Learnings

Accumulated patterns and anti-patterns from development sessions.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.

- **[pattern:sugar-charts]** `withCanvas(BrailleCanvas)` bridges sugar-charts to sugar-dashboard's `Plot/Braille` module. `Chart` and `LineChart` accept a `BrailleCanvas` instance via `withCanvas()` for 2×4 sub-cell dot-matrix rendering instead of character-cell rendering. The `BrailleCanvas` comes from `SugarCraft\Dashboard\Plot\Braille\BrailleCanvas` — sugar-charts takes a soft dep on sugar-dashboard for this integration point.

- **[pattern:sugar-charts]** `withTheme(Theme $theme)` wires sugar-style `Theme` into rendering on the `Chart` base, consumed by `LineChart` (the only `Chart` subclass): the theme's `primary`/`accent`/`info`/`success`/`warning`/`secondary`/`error` colors become the series palette in render order and the legend indicators are recolored to match. All `Theme::` factory methods are supported. The theme flows through `copy()`/`lineChartCopy()` as a `readonly ?Theme`; unthemed renders stay byte-identical. `BarChart`/`Scatter`/`OHLCChart` compose `ChartExtras` and do not read a theme (per-point/per-bar color closures are their styling surface).

- **[pattern:sugar-charts]** Aggregation classes (`BucketByTime`, `MovingAverage`, `Resample`) live in `SugarCraft\Charts\Aggregation` and are immutable + fluent — `add()`/`addMany()` return `$this` clones, `compute()`/`computeSimple()`/`ema()` are static factories. All three classes follow the same pattern: private constructor, public static factories, private state via `readonly` constructor params, clone-mutate on builders. No shared base class — each is `final` and self-contained.

- **[pattern:sugar-charts]** `MovingAverage::ema()` docblock was accidentally duplicated during authoring (two stacked `/** Compute exponential… */` blocks). Only the second (full) docblock with `@param float|null $alpha` is relevant — the first was dead code and has been removed.

- **[pattern:sugar-charts]** `Ansi::fg16()` only accepts codes 30–37 and 90–97 (standard + bright foreground). Code 39 (default foreground) and 38 are rejected by validation even though they are valid ANSI SGR codes. Use `Ansi::sgr(39)` directly for default foreground color to preserve all other attributes.

- **[pattern:sugar-charts]** `Sparkline`/`BufferHelper` compose rows through sugar-cellbuffer's `Buffer`; the grid renderers (`LineChart`, `BarChart`, `Heatmap`, `Scatter`, `OHLCChart`) build on the lib-local `Charts\Canvas\Canvas` instead. Don't re-implement string padding — reuse `Buffer` or `Canvas` per output shape, and take display widths from `SugarCraft\Core\Util\Width` (cell-correct for CJK/emoji).

- **[pattern:assert-golden-ansi]** Use `assertGoldenAnsi` for any new `render()` test. Fixture files live in `tests/fixtures/` with a `.golden` extension. Re-record goldens with `UPDATE_GOLDENS=1 vendor/bin/phpunit` after intentional output changes. Goldens live in `sugar-charts/tests/fixtures/`.
- Lang class now extends `SugarCraft\Core\I18n\Lang` — `t()` method inherited from base; NAMESPACE and DIR are the only per-lib constants.
