# Caliber Learnings

Accumulated patterns and anti-patterns from development sessions.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.

- **[pattern:sugar-charts]** `withCanvas(BrailleCanvas)` bridges sugar-charts to sugar-dash's `Plot/Braille` module. `Chart` and `LineChart` accept a `BrailleCanvas` instance via `withCanvas()` for 2×4 sub-cell dot-matrix rendering instead of character-cell rendering. The `BrailleCanvas` comes from `SugarCraft\Dash\Plot\Braille\BrailleCanvas` — sugar-charts takes a soft dep on sugar-dash for this integration point.

- **[pattern:sugar-charts]** `withTheme(Theme $theme)` wires candy-sprinkles `Theme` into chart rendering. All `Theme::` factory methods (`dracula()`, `oneDark()`, `tokyoNight()`, etc.) are supported on both `Chart` and `LineChart`. The theme is stored as a `readonly ?Theme` property and flows through `copy()`/`lineChartCopy()` helpers. Mirrors step 02.01 candy-sprinkles Theme work.

- **[pattern:sugar-charts]** Aggregation classes (`BucketByTime`, `MovingAverage`, `Resample`) live in `SugarCraft\Charts\Aggregation` and are immutable + fluent — `add()`/`addMany()` return `$this` clones, `compute()`/`computeSimple()`/`ema()` are static factories. All three classes follow the same pattern: private constructor, public static factories, private state via `readonly` constructor params, clone-mutate on builders. No shared base class — each is `final` and self-contained.

- **[pattern:sugar-charts]** `MovingAverage::ema()` docblock was accidentally duplicated during authoring (two stacked `/** Compute exponential… */` blocks). Only the second (full) docblock with `@param float|null $alpha` is relevant — the first was dead code and has been removed.

- **[pattern:sugar-charts]** `Ansi::fg16()` only accepts codes 30–37 and 90–97 (standard + bright foreground). Code 39 (default foreground) and 38 are rejected by validation even though they are valid ANSI SGR codes. Use `Ansi::sgr(39)` directly for default foreground color to preserve all other attributes.

- Lang class now extends `SugarCraft\Core\I18n\Lang` — `t()` method inherited from base; NAMESPACE and DIR are the only per-lib constants.
