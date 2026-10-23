# CALIBER_LEARNINGS — sugar-colorprofile

## Patterns

- `[probe-ssot]` — `Probe::colorProfile()` + `ColorProfile` enum is the SSOT for terminal-color env detection. Live consumers (derived 2026-10-23 from `use SugarCraft\ColorProfile\` imports): sugar-log, sugar-image, sugar-spring, sugar-video, candy-mdview — candy-codeshot and sugar-vterm do NOT import this library. Do not re-implement detection logic in consumers.

- `[infocmp-phase2]` — `Probe::infocmpUpgrade()` silently upgrades `Ansi → TrueColor` when infocmp reports `Tc` or `RGB` capability. This is a best-effort heuristic — infocmp availability is not guaranteed in all environments (checked against `/usr/bin/infocmp` and `/bin/infocmp`).

 - **[pattern:async-gap]** (corrected 2026-10-23 — this entry used to claim the library was "entirely synchronous" and pointed at a *future* `AsyncProbe`; that was false at the time of correction.) The library IS async-capable: `AsyncProbe::colorProfile()` spawns the infocmp check as a ReactPHP child process with a bounded-child ladder. The sync path (`Probe::colorProfile()`) remains the default and uses a blocking `shell_exec`-style infocmp read; async consumers must declare `react/event-loop`, `react/child-process`, `react/promise` (now all in composer require).

 - Lang class now extends `SugarCraft\Core\I18n\Lang` — `t()` method inherited from base; NAMESPACE and DIR are the only per-lib constants.

 - `[perceptual-distance]` — `ColorMath` (sRGB→linear→XYZ D65→Lab) + `DeltaE` (76/94/2000) + `ColorDistance` strategy enum + `NearestColor` matcher. Default strategy stays EUCLIDEAN so existing degradation is byte-for-byte unchanged; perceptual metrics are strictly opt-in. Palette Lab tables are memoised statically in `NearestColor` — never recompute `ColorMath::toLab()` per candidate inside a search loop (≈40 % hot-loop overhead).

 - `[gotcha:fromAnsi256-lossy]` — FIXED 2026-10-23 (historical record). `Color::fromAnsi256Index()` once decoded the 6×6×6 cube with plain float division where `intdiv()` belongs, so indexes 16–231 came back wrong. The accessor is now intdiv-exact and `NearestColor::palette256()` is built FROM the accessor (the old hand-rolled table workaround is gone). Lesson kept: cube/grey-ramp decoding needs integer division per channel — pin the edge indexes (16/17/231), not just a sample.
