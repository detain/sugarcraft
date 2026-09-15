# CALIBER_LEARNINGS — candy-palette

## Patterns

- `[probe-ssot]` — `Probe::colorProfile()` + `ColorProfile` enum is the SSOT for terminal-color env detection. Other libs (candy-log, candy-mosaic, candy-freeze, candy-vt) consume it directly; do not re-implement detection logic in consumers.

- `[infocmp-phase2]` — `Probe::infocmpUpgrade()` silently upgrades `Ansi → TrueColor` when infocmp reports `Tc` or `RGB` capability. This is a best-effort heuristic — infocmp availability is not guaranteed in all environments (checked against `/usr/bin/infocmp` and `/bin/infocmp`).

 - **[pattern:async-gap]** The library is entirely synchronous despite being in the ReactPHP ecosystem. `Probe::colorProfile()` uses blocking `shell_exec('infocmp ...')` calls. Consumers needing async terminal detection should use the future `AsyncProbe` (see `plan_candy-palette.md` Item 3.1). Do not assume any public method is non-blocking.

 - Lang class now extends `SugarCraft\Core\I18n\Lang` — `t()` method inherited from base; NAMESPACE and DIR are the only per-lib constants.

 - `[perceptual-distance]` — `ColorMath` (sRGB→linear→XYZ D65→Lab) + `DeltaE` (76/94/2000) + `ColorDistance` strategy enum + `NearestColor` matcher. Default strategy stays EUCLIDEAN so existing degradation is byte-for-byte unchanged; perceptual metrics are strictly opt-in. Palette Lab tables are memoised statically in `NearestColor` — never recompute `ColorMath::toLab()` per candidate inside a search loop (≈40 % hot-loop overhead).

 - `[gotcha:fromAnsi256-lossy]` — `Color::fromAnsi256Index()` decodes the 6×6×6 cube with plain float division where `intdiv()` belongs on every channel, so indexes 16–231 come back wrong (e.g. 17 → `#010933` instead of `#000033`). `NearestColor::palette256()` therefore builds the cube table directly (x6-level × 51 + floor) per the ANSI 256 specification instead of routing through that accessor. Fixing the accessor itself is a separate, behaviour-changing follow-up.
