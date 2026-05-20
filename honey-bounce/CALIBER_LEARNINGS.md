# Caliber Learnings — honey-bounce

Accumulated patterns and gotchas from building this library.

---

[pattern:spring-preset-enum] — `SpringPreset` as backed enum case with `resolve(): SpringConfig` — Each preset case encodes tension/friction/mass triples that downstream consumers access via `resolve()` without needing to know the raw values. This keeps call sites at `Spring::fromPreset(SpringPreset::Wobbly)` rather than scattering magic numbers.

[pattern:springconfig-physics-translation] — `SpringConfig` translates tension/friction/mass to angularFrequency/dampingRatio — The constructor computes `ω = sqrt(tension/mass)` and `ζ = friction/(2*sqrt(tension*mass))` so that `Spring` receives the physically meaningful coefficients directly. This separates the user-facing "spring feel" parameters from the integration math.

[pattern:cubic-bezier-newton-raphson-css] — `CubicBezier` uses Newton-Raphson with binary-search fallback per W3C spec — `solveCubicX()` runs up to 8 Newton iterations then falls back to binary subdivision when the derivative is too small. This ensures monotonic behaviour even for control-point configurations that would otherwise cause non-monotonic x(t). See https://www.w3.org/TR/css-easing-3/#cubic-bezier-algo
