# Caliber Learnings — honey-bounce

Accumulated patterns and gotchas from building this library.

---

[pattern:spring-preset-enum] — `SpringPreset` as backed enum case with `resolve(): SpringConfig` — Each preset case encodes tension/friction/mass triples that downstream consumers access via `resolve()` without needing to know the raw values. This keeps call sites at `Spring::fromPreset(SpringPreset::Wobbly)` rather than scattering magic numbers.

[pattern:springconfig-physics-translation] — `SpringConfig` translates tension/friction/mass to angularFrequency/dampingRatio — The constructor computes `ω = sqrt(tension/mass)` and `ζ = friction/(2*sqrt(tension*mass))` so that `Spring` receives the physically meaningful coefficients directly. This separates the user-facing "spring feel" parameters from the integration math.

[pattern:cubic-bezier-newton-raphson-css] — `CubicBezier` uses Newton-Raphson with binary-search fallback per W3C spec — `solveCubicX()` runs up to 8 Newton iterations then falls back to binary subdivision when the derivative is too small. This ensures monotonic behaviour even for control-point configurations that would otherwise cause non-monotonic x(t). See https://www.w3.org/TR/css-easing-3/#cubic-bezier-algo

[pattern:springchain-sequential] — `SpringChain` sequences springs so each stage activates only when the previous settles (within 0.001 position and 0.001 velocity). One `tick()` = one step of the active stage; settled stages are "locked" and no longer updated. `isComplete()` returns true when `activeIndex >= count(stages)`.

[pattern:reduced-motion-instant-snap] — `Spring::update()` checks `Probe::reducedMotion()` at call time and returns `[$target, 0.0]` instantly when reduced motion is signalled. This is a pure conditional in `update()` — no separate factory or configuration step needed. Callers that already pass `Spring::update($pos, $vel, $target)` get reduced-motion support automatically when the env var is set.

- Lang class now extends `SugarCraft\Core\I18n\Lang` — `t()` method inherited from base; NAMESPACE and DIR are the only per-lib constants.

[pattern:snapshot-testing-assert-golden] — Use `candy-testing`'s `assertGolden*` for any renderable/serializable output — ANSI cell renders via `assertGoldenAnsi`, numeric trajectories via `assertGolden` file equality. This pins canonical output so refactors are intentional rather than accidental regressions.
