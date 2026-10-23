# Caliber Learnings — sugar-spring

Accumulated patterns and gotchas from building this library.

---

[pattern:spring-preset-enum] — `SpringPreset` as backed enum case with `resolve(): SpringConfig` — Each preset case encodes tension/friction/mass triples that downstream consumers access via `resolve()` without needing to know the raw values. This keeps call sites at `Spring::fromPreset(SpringPreset::Wobbly)` rather than scattering magic numbers.

[pattern:springconfig-physics-translation] — `SpringConfig` translates tension/friction/mass to angularFrequency/dampingRatio — The constructor computes `ω = sqrt(tension/mass)` and `ζ = friction/(2*sqrt(tension*mass))` so that `Spring` receives the physically meaningful coefficients directly. This separates the user-facing "spring feel" parameters from the integration math.

[pattern:cubic-bezier-newton-raphson-css] — `CubicBezier` uses Newton-Raphson with binary-search fallback per W3C spec — `solveCubicX()` runs up to 8 Newton iterations then falls back to binary subdivision when the derivative is too small. The solver only INVERTS x(t); monotone time mapping is guaranteed by the constructor's x1/x2 ∈ [0, 1] range check (the CSS spec's own precondition), not by the solver, and y may overshoot [0, 1] by design for back/elastic-style control points. See https://www.w3.org/TR/css-easing-3/#cubic-bezier-algo

[pattern:springchain-sequential] — `SpringChain` sequences springs so each stage activates only when the previous settles (position within 5e-4 of target AND velocity within 5e-4 of zero — `SpringChain::SETTLING_THRESHOLD`, deliberately looser than `Spring::SETTLING_THRESHOLD` of 1e-4). One `tick()` = one step of the active stage returning `[positions, complete, newChain]`; callers MUST carry the returned chain (`[$p, $c, $chain] = $chain->tick()`) or the run never advances. `isComplete()` returns true when `activeIndex >= count(stages)`.

[pattern:reduced-motion-instant-snap] — `Spring::update()` checks the reduced-motion signal at call time and returns `[$target, 0.0]` instantly when it is set. The signal is ENV-ONLY: `Probe::reducedMotion()` (sugar-colorprofile) reads exactly `REDUCE_MOTION` / `PREFERS_REDUCED_MOTION` — no terminal query, no a11y-daemon channel. A per-instance `?bool $reducedMotionOverride` constructor parameter pins the choice regardless of the environment. Scope is `Spring::update()` only; `Projectile`/`Easing`/`SpringChain` animate regardless and must be gated by callers. Callers that already pass `Spring::update($pos, $vel, $target)` get reduced-motion support automatically when the env var is set.

- Lang class now extends `SugarCraft\Core\I18n\Lang` — `t()` method inherited from base; NAMESPACE and DIR are the only per-lib constants. Both `Spring` and `Projectile` throw `Lang::t(...)` messages (projectile.* keys live in every `lang/*.php`); `LocaleParityTest` keeps all locales' key sets in lockstep with `en.php`.

[pattern:snapshot-testing-golden-file] — Numeric (JSON) trajectory output is pinned with sugar-testing's `GoldenFile::save()`/`load()` + plain `assertEquals` in `tests/GoldenRenderTest.php`; re-record with `UPDATE_GOLDENS=1 vendor/bin/phpunit` (no `--update-golden` flag exists). sugar-testing's `assertGolden*` helpers are ANSI/cell-grid specific — there is no generic `assertGolden` method. This pins canonical output so refactors are intentional rather than accidental regressions.
