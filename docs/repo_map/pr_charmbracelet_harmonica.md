# Second-Stage Ecosystem Intelligence Report: charmbracelet/harmonica

## 1. Repository Overview

**charmbracelet/harmonica** is a Go physics-based animation library (~1530 stars, MIT) implementing Ryan Juckett's damped harmonic oscillator algorithm for spring physics and Euler integration for projectile motion. The library is framework-agnostic, has zero external dependencies (just `import "math"`), and powers animations in the broader Charmbracelet TUI ecosystem (bubbletea, lipgloss). Two releases: v0.1.0 (July 2021) and v0.2.0 (April 2022 — adds projectile/particle physics). 7 contributors, low issue/PR volume. No security advisories filed. No discussions page activity.

---

## 2. Existing SugarCraft Mapping

From `repo_map/charmbracelet_harmonica.md`:

| harmonica feature | SugarCraft lib | SugarCraft class(es) | Notes |
|---|---|---|---|
| `Spring` (damped harmonic oscillator) | `honey-bounce` | `Spring`, `SpringConfig`, `SpringPreset`, `SpringChain`, `SpringCollection` | PHP port adds presets (UIKit tensions), `SpringChain`, `SpringCollection`, immutable `update()`, `REDUCE_MOTION` support |
| `Projectile` (Newtonian physics) | `honey-bounce` | `Projectile`, `Point`, `Vector`, `Gravity` | PHP port adds immutable `update()`, Y-down gravity variants, richer `Point`/`Vector` ops |
| `FPS(int)` utility | `honey-bounce` | `Spring::fps(int)` | Static factory method |
| `Gravity`/`TerminalGravity` constants | `honey-bounce` | `Gravity` class + `Projectile::gravity()`/`::terminalGravity()` | SugarCraft adds Y-down variants |
| (not in harmonica) | `honey-bounce` | `Easing`, `CubicBezier` | SugarCraft extension |

---

## 3. Previously Identified Gaps

From `repo_map/charmbracelet_harmonica.md`:
- Mutable `Projectile` (Update() mutates receiver)
- No spring presets or UIKit tension/friction mappings
- No `SpringChain` or `SpringCollection`
- No easing functions (elastic, bounce, cubic-bezier)
- No reduced-motion accessibility support
- No multi-spring management
- `Vector` is just 3 floats with no `add`/`scale`/`dot`/`cross` methods

---

## 4. High-Signal Open Issues

### Issue #13 — "issue running an example" (Open, 4 comments)
**Signal**: Environment setup problem for OpenGL example. User could not compile OpenGL example despite having Mesa drivers installed. Maintainers pointed to missing `libgl1-mesa-dev xorg-dev` system packages. This issue remained unresolved in the conversation (user said error persisted after trying suggested fixes).

**Root Cause**: OpenGL example depends on GLFW vendored C sources that require development headers. The `go-gl/glfw/v3.3/glfw/c_glfw.go:4` includes `glfw/src/context.c` directly — this is a vendored C file that must be present on the system. The README had no mention of system dependencies.

**Direct Risk to SugarCraft**: PHP examples using FFI/gd dependencies would face similar undocumented system dependency issues. SugarCraft should document PHP extension requirements clearly.

**后续**: PR #25 (still open as of this analysis) documents the OpenGL system dependencies.

---

## 5. Important Closed Issues

### Issue #11 — "`go mod tidy` unable to pull Projectile, Point, Vector etc" (Closed, 2 comments)
**Signal**: Breaking API between v0.1.0 and v0.2.0. Example code from `master` used types (`Projectile`, `Point`, `Vector`, `NewProjectile`) that didn't exist in the latest tagged release (v0.1). Go's module proxy served v0.1, not master, so users got undefined errors.

**Impact**: Users following README examples couldn't compile. The `examples/physics/main.go` file on master referenced types that only existed after v0.2.0 was tagged.

**Lesson**: Master HEAD vs. latest tagged release mismatch creates confusing onboarding. SugarCraft should ensure examples match the latest tagged release or use versioned imports correctly.

### Issue #15 — "remake a telnet starwars movie with harmonica" (Closed, 2 comments)
**Signal**: Novel use-case request — using harmonica to animate ASCII art in a telnet starwars movie remake. commenter `@taigrr` pointed to their `ssh-wars` project as an implementation. This demonstrates harmonica's framework-agnostic appeal beyond TUIs.

---

## 6. Recurring Pain Points

1. **Example dependency documentation**: The OpenGL example requires system-level OpenGL dev headers that are never documented. Multiple users hit this; it remains a friction point years later (PR #25 was still open as of this analysis).
2. **Version/tag confusion**: Types on master not available in the latest tag caused onboarding failures (issue #11). SugarCraft should pin examples to actual released versions.
3. **No spring presets**: Users must manually tune `angularFrequency` and `dampingRatio`. No UIKit-style tension/friction mappings exist, making it difficult for newcomers to get pleasing animations without reading the physics documentation.

---

## 7. Frequently Requested Features

From PR #6 (Introduce 2D + 3D projectile motion) the PR author explicitly called out **unfinished work**:
1. **Projectile mass** — Not implemented
2. **Forces system** (`projectile.AddForce(Force{...})`) — Not implemented
3. **Collisions** — Not implemented

These were noted as TODOs in the PR but never addressed. SugarCraft's `honey-bounce` also doesn't implement any of these, leaving them as future opportunities.

**No feature requests appear in the issue tracker** — the repository is very low-activity and most "requests" are implicit in PRs (e.g., exposing `Velocity`/`Acceleration` on Projectiles came from the confetty use case in PR #9).

---

## 8. Important PRs

| PR | Title | Significance |
|---|---|---|
| #6 | Introduce 2D + 3D projectile motion | Major feature expansion — added Projectile, Point, Vector, Gravity types; renamed physics examples directory; added confetty as a showcase app |
| #8 | Generalize package to include projectiles | Reframed harmonica from "spring animation library" to "physics-based animation library" |
| #9 | Expose Velocity and Acceleration on Projectiles | Added accessors needed by confetty app; shows real-world use case driving API additions |
| #12 | Organize examples per spring/particle dichotomy | Clarified that library has two distinct subsystems |
| #17 | Add projectile usage example | Added runnable TUI demo for projectile motion |
| #24 | Add spring tests and fix typos | Added first comprehensive spring tests (all three damping regimes); fixed `initalAcceleration` → `initialAcceleration` typo; fixed `"for for"` in package doc |
| #25 | Document OpenGL system dependencies | Responded to Issue #13 — documents `libgl1-mesa-dev xorg-dev` requirement |

---

## 9. Architectural Changes

### v0.1 → v0.2 (April 2022)
- **Added**: `Projectile`, `Point`, `Vector`, `Gravity`, `TerminalGravity`, `NewProjectile()`, `FPS()`
- **Renamed**: examples directory from `physics/` to `particle/`
- **Package doc**: Added projectile example alongside spring example
- **Significant**: The entire second half of the library (projectile physics) was added in this release

### Notable Code Changes
- `NewProjectile` initially returned value; PR #7 changed it to return `*Projectile` pointer (to match confetty usage pattern)
- PR #24 added `initialAcceleration` parameter name fix (was `initalAcceleration` — typo)
- Commit `d45b9627`: "Copy values in Update method to avoid data races" — data race prevention in concurrent use

---

## 10. Performance Discussions

No explicit performance discussions in issues or PRs. The algorithm is already analytically exact (constant-time per Update after one-time coefficient setup). The README explicitly notes this benefit.

**Concurrent access**: Commit `d45b9627` addressed data races in the Update path — the spring coefficients are cached and read-only after `NewSpring`, making concurrent use safe for the Spring struct itself. However, the mutable `Projectile` struct is not safe for concurrent use (no locks, no copying).

---

## 11. Extensibility Discussions

**No explicit extensibility discussions exist in the issue tracker.** The PR #6 TODO list (mass, forces, collisions) represents the maintainers' own roadmap for extensibility, none of which was ever implemented. This suggests the maintainers prefer to keep the library scope intentionally narrow.

SugarCraft's approach of adding `SpringChain`, `SpringCollection`, `SpringPreset`, and `Easing`/`CubicBezier` extensions goes significantly beyond what upstream has shown interest in, representing genuine innovation on the upstream design.

---

## 12. API/UX Complaints

From issue #13 and the OpenGL compilation errors: **Poor error messages when dependencies are missing.** Go's `pkg-config` error "Package gl was not found in the pkg-config search path" doesn't guide users to install `libgl1-mesa-dev`.

From issue #11: **API changes between master and tags cause confusing build failures.** Users following README examples get `undefined: harmonica.Projectile` because the latest tag (v0.1) predates the projectile feature.

**Typo noise**: PRs #22, #23 are open typo fixes. The code had repeated typo issues (`initalAcceleration`, `"for for"` in package doc, `"neeeds"` in doc comment).

---

## 13. Migration Problems

**Between v0.1 and v0.2**: The projectile types were entirely new. Users on v0.1 who upgraded got new types but no migration path. The `examples/physics/main.go` on master was the only documentation of the new API, but it didn't exist as a runnable reference in v0.1.

**Parameter name typo**: `initalAcceleration` (missing 'i') was a latent bug in `NewProjectile` that PR #24 fixed. This could have broken callers using named parameters in Go (though Go doesn't typically use named parameters, the typo was confusing in documentation).

---

## 14. Clever Fixes & Workarounds

1. **Commit `d45b9627` — "Copy values in Update method to avoid data races"**: When updating spring values, the fix was to copy values rather than mutate in place, preventing data races in concurrent scenarios.

2. **Sharing a single Spring across multiple animated values**: The README explicitly documents this pattern — one `Spring` instance can drive `x, xVel = s.Update(x, xVel, targetX)` and `y, yVel = s.Update(y, yVel, targetY)` simultaneously because the Spring struct is stateless (only coefficient cache). This is an intentional design that SugarCraft should preserve.

3. **Zero angular frequency as no-op**: `NewSpring(deltaTime, 0, dampingRatio)` returns identity coefficients, effectively disabling animation without changing update logic. This is an elegant edge-case handling pattern.

---

## 15. Community Workarounds

From issue #15 (telnet starwars): The `ssh-wars` project (`github.com/taigrr/ssh-wars`) uses harmonica for animating ASCII art over SSH connections, demonstrating that harmonica works outside TUI/terminal contexts. SugarCraft's immutable PHP implementation could enable similar creative uses.

From PR #9 (expose Velocity/Acceleration): The confetty app needed internal projectile state access to trigger effects (e.g., when velocity drops below threshold). They worked around the lack of accessors by forking or duplicating the projectile logic until PR #9 landed.

---

## 16. Maintainer Guidance Patterns

- **Short, factual responses**: Maintainer responses are terse and solution-oriented ("Try installing the development headers as well: `sudo apt install libgl1-mesa-dev xorg-dev`").
- **Release-based feature delivery**: Features are batched into releases rather than shipped incrementally (v0.2.0 packaged projectile motion + examples + confetty showcase).
- **Conscious scope limitation**: The TODO items in PR #6 (mass, forces, collisions) were never addressed, suggesting deliberate scope control rather than feature pressure.
- **Community-driven API**: Exposing `Velocity()` and `Acceleration()` on Projectiles was directly driven by a real use case (confetty app needs to check velocity state).

---

## 17. Rejected Ideas Worth Revisiting

**No explicit rejections found** — the issue tracker is too low-volume to have documented rejections. The implicit rejections are:
1. **Forces system** (not implemented, despite being in PR #6 TODO)
2. **Collision detection** (not implemented)
3. **Projectile mass** (not implemented)
4. **Spring presets** (never requested but commonly needed)

These represent a gap between what the maintainer roadmap sketched and what was delivered. SugarCraft has already addressed some of these (presets via `SpringPreset`).

---

## 18. Problems Likely Relevant To SugarCraft

1. **Mutable `Projectile`** — SugarCraft's `honey-bounce` already fixed this with immutable `update()` that returns a new `Projectile`. This is a meaningful improvement over upstream.

2. **Missing spring presets** — Users must manually tune `angularFrequency` and `dampingRatio`. SugarCraft's `SpringPreset` class (with UIKit tension mappings) directly addresses this. **This is SugarCraft's strongest differentiation from upstream.**

3. **REDUCE_MOTION support** — Upstream has zero accessibility consideration. SugarCraft's `REDUCE_MOTION` environment variable handling is a significant accessibility win.

4. **Easing functions** — SugarCraft's `Easing`/`CubicBezier` classes extend the physics-only model with familiar CSS-style easing curves. Upstream has never expressed interest in this.

5. **Multi-spring management** — SugarCraft's `SpringChain` and `SpringCollection` address the common pattern of sequencing or grouping animations. Upstream's examples show manual wiring of multiple springs, with no abstraction for this.

6. **Version tagging confusion** — SugarCraft should ensure that `composer.json` `require` constraints and README examples reference actual released versions, avoiding the master-vs-tag confusion that plagued Go users.

---

## 19. Features SugarCraft Should Consider

1. **Forces system for Projectile**: Upstream's PR #6 explicitly listed `AddForce()` as a future feature that was never built. SugarCraft could implement `Projectile::applyForce(Vector $force)` to modify acceleration mid-flight. This is a natural extension with clear use cases (wind, thrust, explosions).

2. **Collision detection for Projectile**: Similarly noted in PR #6 as a TODO. SugarCraft could add `Projectile::collidesWith(Bounds $bounds): bool` or similar.

3. **Projectile mass**: Also from PR #6 TODO. Could enable more realistic physics (F = ma calculations).

4. **Spring visualization/debugging tools**: Upstream has no way to inspect spring state mid-animation. SugarCraft could add a `SpringDebug` helper that formats the current position/velocity/target as a string for debugging animations.

5. **Animation completion callbacks**: Neither upstream nor SugarCraft has a way to register a callback that fires when a spring reaches equilibrium. This is a commonly requested pattern in UI animation libraries (Flutter, iOS, Android all have animation completion handlers).

6. **Spring monitoring**: A way to check if a spring has essentially converged (within epsilon of target) without the caller manually checking `abs(pos - target) < epsilon`. Could be `spring.isSettled(pos, vel, target): bool`.

---

## 20. Architectural Lessons

1. **Stateless spring vs. stateful caller**: Harmonica's design (state in caller, spring is a pure coefficient cache) is deliberate and correct. SugarCraft should preserve this — `Spring` should remain stateless, with position/velocity always in the caller's model.

2. **Factory naming convention**: `NewSpring` + `FPS()` utility is clean and explicit. SugarCraft's `Spring::fps(int)` is an appropriate translation. Factory methods should not be called `create()` or `make()` or `default()`.

3. **Equilibrium-relative update**: The pattern of shifting to equilibrium-relative space (`oldPos - target`), applying coefficients, then re-baselining (`+ target`) is the correct approach for the Juckett algorithm. This pattern is preserved correctly in SugarCraft.

4. **Coefficient precomputation**: The Juckett algorithm's power comes from computing all coefficients once in `NewSpring`/`__construct`, making each `Update` call just 4 multiplies and 2 additions. SugarCraft's PHP implementation should ensure the constructor does the same precomputation.

5. **Sharp scope boundary**: Harmonica has exactly two subsystems (spring + projectile) that share nothing. SugarCraft should maintain this — if `honey-bounce` grows beyond spring physics and projectile physics (e.g., adding easing curves), it should be clearly separated.

---

## 21. Defensive Design Lessons

1. **Input clamping**: `NewSpring` clamps `angularFrequency` and `dampingRatio` to minimum of 0. SugarCraft should do the same.

2. **Zero-frequency identity**: When `angularFrequency == 0`, `NewSpring` returns identity coefficients (pos stays at current position, velocity stays at current velocity). This is an elegant no-op that allows disabling animation by setting frequency to 0. SugarCraft should preserve this.

3. **Machine epsilon calculation**: Upstream uses `math.Nextafter(1, 2) - 1` to compute floating-point epsilon dynamically rather than hardcoding. SugarCraft's PHP implementation should consider equivalent floating-point epsilon handling.

4. **Typed property guidance**: Go's `float64` for all physics values is simple but loses semantic meaning. SugarCraft's PHP could use typed classes (`AngularFrequency`, `DampingRatio` as value objects) to prevent mixing up parameters — though this adds boilerplate.

5. **Avoid mutable internals in shared objects**: The mutable `Projectile.Update()` method mutates in place, which is a footgun. SugarCraft's immutable `update()` approach is better. However, the stateless `Spring.Update()` returning `(newPos, newVel)` is correct and SugarCraft should keep that pattern.

---

## 22. Ecosystem Trends

1. **Physics animation libraries expanding scope**: The Go ecosystem has several spring physics libraries (harmonica, easing, animate), but none have achieved dominance. The trend is toward combining physics-based motion (springs) with explicit easing curves (cubic-bezier, elastic).

2. **Terminal animation as legitimate use case**: The Charmbracelet ecosystem (bubbletea, harmonica, lipgloss) has legitimized terminal-based animations. SugarCraft can build on this precedent for PHP TUI animation.

3. **Framework-agnostic design as differentiator**: Harmonica's lack of framework dependencies means it works in OpenGL, TUIs, game engines, and web contexts. SugarCraft's `honey-bounce` should maintain this portability — no coupling to a specific PHP framework.

4. **Preset-driven usability**: The UIKit tension/friction preset system (used by Framer Motion, SwiftUI, and SugarCraft's `SpringPreset`) represents a maturing of spring animation APIs toward accessible defaults. Upstream harmonica never built this, leaving the gap for SugarCraft to fill.

5. **Accessibility as table stakes**: `prefers-reduced-motion` / `REDUCE_MOTION` handling is becoming expected in animation libraries. SugarCraft's implementation ahead of upstream addresses this trend.

---

## 23. Strategic Opportunities

1. **Preset ecosystem**: SugarCraft's `SpringPreset` class is the most significant user-facing enhancement over upstream. Expanding this (e.g., adding presets for "iOS system animations", "Material Design", "Twitter like animation", "Elastic bounce-out") would create a differentiated, discoverable feature that upstream has no equivalent for.

2. **Immutability as a selling point**: SugarCraft's immutable `Projectile::update()` and immutable spring pattern is strictly superior for concurrent and functional contexts. This should be highlighted in documentation as a design advantage over the Go original.

3. **Forces/collision as premium features**: Since upstream explicitly called these out as TODO but never implemented them, SugarCraft implementing them would represent genuine feature leadership, not just porting.

4. **Multi-spring abstractions**: `SpringChain` (sequenced animations) and `SpringCollection` (parallel independent springs) are missing from upstream entirely. These are high-value ergonomic wins for users building complex UI animations.

5. **REDUCE_MOTION leadership**: Upstream has zero accessibility support. SugarCraft's implementation should be documented prominently as an accessibility feature, potentially attracting users from the charmbracelet ecosystem who need this.

6. **Easing function pairing**: SugarCraft's `Easing`/`CubicBezier` classes pair physics springs with explicit easing curves. This combination is standard in modern animation (Flutter's `SpringSimulation` + `Curve`, iOS's `UIView.animate` + `UIViewPropertyAnimator`). Having both in the same library is a legitimate ergonomic advantage.

---

## 24. Cross-Ecosystem Pattern Matches

1. **Ryan Juckett algorithm**: Widely ported (C++, Go, JavaScript via TUI4J, PHP via SugarCraft). The algorithm itself is the stable core; all ecosystem variation is in ergonomics.

2. **UIKit tension/friction presets**: Framer Motion, SwiftUI, React Spring, and SugarCraft all converge on similar preset values. This suggests the preset values are physics-correct and not arbitrary.

3. **Spring + Easing pairing**: React Spring, Framer Motion, and Flutter all pair physics springs with explicit easing curves. SugarCraft's approach of having both is correct.

4. **Immutable update semantics**: React's `useState` + functional updates, Immer's produce pattern, and SugarCraft's `with*()` pattern all reflect the same immutability preference for state management. SugarCraft's immutable `Projectile::update()` fits this pattern.

5. **Animation completion callbacks**: Flutter's `AnimationController.addStatusListener`, iOS's `CAAnimation.delegate`, Framer Motion's `onComplete` — all standard patterns that neither upstream harmonica nor SugarCraft currently implements.

---

## 25. High ROI Recommendations

### Immediate (High Impact, Low Effort)

1. **Document PHP extension requirements clearly**: The OpenGL example issue (#13) happened because system dependencies weren't documented. SugarCraft should have a clearly visible "Requirements" section listing required PHP extensions (FFI, GD, etc.) and how to install them.

2. **Add `Spring::isSettled()` method**: A `bool isSettled(float $pos, float $vel, float $target, float $epsilon = 0.001)` method that checks if a spring has effectively converged. This is a very small addition that eliminates the common user pattern of manually checking `abs($pos - $target) < $epsilon`.

3. **Add `Spring::valueWhenSettled()`**: Return the value a spring would converge to if left running indefinitely (essentially the target). Useful for interruption handling.

4. **Document the preset philosophy**: The `SpringPreset` class is SugarCraft's strongest differentiation. Make sure it's prominently documented with visual examples showing what each preset looks like.

### Short-term (High Impact, Moderate Effort)

5. **Spring completion callback system**: Add `SpringChain::onComplete(callable $callback)` or a `Spring::setOnComplete(callable)` that fires when a spring settles. This addresses a widely-requested pattern upstream that was never implemented.

6. **Y-down coordinate system helpers**: SugarCraft has `standardYDown()` and `terminalYDown()` on Gravity. Make sure these are documented as a pair, not just listed. Explain the terminal coordinate convention difference clearly.

7. **Add mass/force API to Projectile**: Implement the forces system from PR #6's TODO list (`Projectile::applyForce(Vector $force)`). This is a modest extension that fills an obvious gap.

### Medium-term (High Impact, Higher Effort)

8. **SpringChain visual debugger**: A `SpringChain::debug()` method that returns a structured array of all springs in the chain and their current states, useful for debugging complex sequenced animations.

9. **Collision detection for Projectile**: Implement bounding-box or point-in-rect collision for Projectiles. Natural extension from the forces system.

10. **Publish `honey-bounce` as standalone package**: When SugarCraft reaches v1.0, publishing `honey-bounce` separately to Packagist as `sugarcraft/honey-bounce` will expose it to the broader PHP ecosystem beyond the SugarCraft monorepo context.

---

## Appendix: Issue/PR Quick Reference

| ID | Type | State | Key Takeaway |
|---|---|---|---|
| #3 | issue | closed | README example had incorrect usage |
| #6 | PR | closed | Projectile motion added; TODO list (mass, forces, collisions) never implemented |
| #7 | PR | closed | Changed NewProjectile to return `*Projectile` pointer for confetty compatibility |
| #8 | PR | closed | Generalized library from spring-centric to general physics |
| #9 | PR | closed | Exposed Velocity/Acceleration on Projectiles (from confetty use case) |
| #11 | issue | closed | Master types not in latest tag caused go mod tidy failures |
| #13 | issue | **open** | OpenGL example requires undocumented system dependencies |
| #14 | issue | closed | Typo fixes |
| #15 | issue | closed | Novel use case (ASCII telnet starwars) |
| #17 | PR | closed | Added projectile TUI example |
| #24 | PR | closed | Added spring tests; fixed `initalAcceleration` typo |
| #25 | PR | **open** | Documents OpenGL system dependencies (fix for #13) |
