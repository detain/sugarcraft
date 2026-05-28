# charmbracelet/harmonica

## Metadata
- URL: https://github.com/charmbracelet/harmonica
- Language: Go
- Stars: 1530
- License: MIT
- Description: A simple, physics-based animation library for smooth, natural spring motion and projectile simulation

## Feature List

- **Damped Spring Physics** — Implements Ryan Juckett's damped simple harmonic oscillator algorithm for realistic spring animations (under-damped, critically-damped, and over-damped regimes)
- **Newtonian Projectile Simulation** — 3D point/vector-based projectile motion with configurable gravity, velocity, and acceleration
- **Framework-Agnostic** — Pure math library with no terminal or UI dependencies; works in 2D and 3D contexts
- **Efficient Coefficient Caching** — `NewSpring` precomputes all motion coefficients so `Update` calls are fast constant-time operations
- **Configurable Damping** — Three distinct damping regimes controlled by a single `dampingRatio` parameter
- **FPS Utility** — `FPS(int)` helper converts frames-per-second to delta-time for easy integration with animation loops

## Key Classes and Methods

- **`Spring`** (struct) — Cached motion parameters for efficient per-frame spring simulation
  - `NewSpring(deltaTime, angularFrequency, dampingRatio) Spring` — Constructor; precomputes pos/vel coefficients for the given time step and physics params
  - `Update(pos, vel, equilibriumPos) (newPos, newVel)` — Advances spring one time step; operates in equilibrium-relative space then re-baselines

- **`Projectile`** (struct) — Represents a body with 3D position, velocity, and acceleration
  - `NewProjectile(deltaTime, initialPosition Point, initialVelocity, initialAcceleration Vector) *Projectile` — Constructor
  - `Update() Point` — Advances position/velocity one time step using Euler integration (mutates receiver)
  - `Position() Point`, `Velocity() Vector`, `Acceleration() Vector` — Accessors

- **`Point`** (struct) — 3D coordinate `{X, Y, Z float64}`

- **`Vector`** (struct) — 3D magnitude+direction represented as origin-to-point `{X, Y, Z float64}`

- **`FPS(int) float64`** (package-level func) — Converts fps integer to seconds (e.g., `FPS(60)` → `0.0166...`)

- **`Gravity`, `TerminalGravity`** (package-level vars) — Pre-defined `Vector` constants for standard Earth gravity in different coordinate conventions

## Notable Algorithms / Named Patterns

- **Ryan Juckett's Damped Simple Harmonic Motion** — The spring implementation is a direct Go port of Ryan Juckett's 2008–2012 C++ algorithm. The algorithm analytically solves the damped harmonic oscillator ODE for a fixed time step, producing three distinct coefficient sets:
  - **Over-damped** (`ζ > 1`): Uses hyperbolic functions (`Exp`, `Sqrt`) to compute coefficients that never oscillate
  - **Under-damped** (`ζ < 1`): Uses trigonometric functions (`Cos`, `Sin`, `Exp`) for oscillatory decay
  - **Critically-damped** (`ζ = 1`): Special case that converges fastest without overshoot
- **Euler Integration** — The projectile simulator uses first-order Euler integration: `pos += vel * dt`, `vel += acc * dt`
- **Equilibrium-Relative Update** — Spring `Update` shifts to equilibrium-relative coordinates before applying coefficients, then re-baselines: `newPos = (oldPos - target) * posPosCoef + oldVel * posVelCoef + target`
- **Machine Epsilon Calculation** — Uses `math.Nextafter(1, 2) - 1` to dynamically compute floating-point epsilon rather than a hardcoded constant

## Strengths

- **Analytically exact** — The spring algorithm solves the ODE analytically per time step, so it's stable at any frame rate (unlike naive Verlet or RK4)
- **Constant-time updates** — After `NewSpring` precomputes coefficients, each `Update` call is just 4 multiplications and 2 additions
- **No dependencies** — Single `import "math"` and `import "time"`, no external packages
- **Clean, readable implementation** — Each damping regime is clearly separated with explanatory comments and the original C++ license preserved
- **Well-documented** — README explains damping ratio physics with diagrams; links to Ryan Juckett's excellent write-up
- **Battle-tested in Charm ecosystem** — Powers animations in `bubbletea` TUI apps (e.g., the TUI demo in examples/)
- **Framework-agnostic** — Works in CLI TUIs, OpenGL contexts, game engines, or anywhere with a per-frame update loop
- **Graceful edge-case handling** — Zero angular frequency returns identity coefficients; negative params are clamped to zero

## Weaknesses

- **Mutable `Projectile`** — `Update()` mutates the receiver in place (returns `Point` but modifies `*Projectile`). This makes it unsuitable for pure/functional contexts and prevents safe concurrent use
- **No spring presets or configuration helpers** — Caller must manually tune `angularFrequency` and `dampingRatio`. No UIKit/Spring-like preset mappings
- **No multi-spring management** — No equivalent of `SpringCollection` for running many springs with shared config efficiently
- **No easing functions** — No cubic-bezier, elastic, or bounce easings — just raw physics
- **Limited coordinate system support** — Only one Y-direction convention (origin bottom-left); no built-in Y-flip for terminal vs. screen coordinates
- **No reduced-motion support** — No `prefers-reduced-motion` or `REDUCE_MOTION` env-var handling for accessibility
- **Go-only** — No WASM, no other language ports; PHP port (`honey-bounce`) exists separately in SugarCraft
- **3D vectors lack operations** — `Vector` is just 3 floats; no `add`, `scale`, `dot`, `cross` methods

## SugarCraft Mapping

| harmonica feature | SugarCraft lib | SugarCraft class(es) | Notes |
|---|---|---|---|
| `Spring` (damped harmonic oscillator) | `honey-bounce` | `Spring`, `SpringConfig`, `SpringPreset`, `SpringChain`, `SpringCollection` | PHP port adds presets (UIKit tensions), `SpringChain` (sequenced animations), `SpringCollection` (multi-spring manager), immutable `update()` returns new instance, `REDUCE_MOTION` support |
| `Projectile` (Newtonian physics) | `honey-bounce` | `Projectile`, `Point`, `Vector`, `Gravity` | PHP port adds immutable `update()` (returns new `Projectile`), Y-down gravity variants, richer `Point`/`Vector` with methods (`add`, `scale`, `dot`, `cross`, `distance`) |
| `FPS(int)` utility | `honey-bounce` | `Spring::fps(int)` | Static factory method on `Spring` class |
| `Gravity` / `TerminalGravity` constants | `honey-bounce` | `Gravity` class + `Projectile::gravity()` / `Projectile::terminalGravity()` | SugarCraft adds `standardYDown()` / `terminalYDown()` for flipped Y-axis |
| (not in harmonica) | `honey-bounce` | `Easing`, `CubicBezier` | Not in upstream — SugarCraft extension with CSS-named easings and Newton-Raphson cubic-bezier evaluator |

**Source:** MATCHUPS.md:L30 (`honey-bounce` row), honey-bounce/README.md

## Analysis

Harmonica is a focused, high-quality physics animation library that does exactly two things—spring physics and projectile motion—and does them well. Its core strength is the Ryan Juckett algorithm, which solves the damped harmonic oscillator analytically for a fixed timestep rather than numerically integrating each frame. This means the spring update is just 4 multiplies and 2 adds after a one-time coefficient setup, making it both fast and numerically stable. The library's tight scope (3 source files, ~350 lines of Go) makes it trivially auditable and portable.

The most significant design limitation is the mutable `Projectile` struct—its `Update()` method returns the new `Point` but mutates `*Projectile` in place, which is a footgun in concurrent or pure-functional contexts. SugarCraft's `honey-bounce` ports this correctly with immutable `update()` semantics. The spring side is handled better in both libraries (no mutation in `Update`), but harmonica lacks the ergonomic extensions SugarCraft adds: spring presets (UIKit-style tension/friction), `SpringChain` for sequenced animations, `SpringCollection` for managing multiple independent springs, and built-in `REDUCE_MOTION` accessibility support. The absence of easing functions (elastic, bounce, cubic-bezier) is notable given how often animation libraries pair physics springs with named easing curves.

The library's 1530 GitHub stars reflect strong community trust in the Charmbracelet ecosystem, and its use powering TUI animations in `bubbletea` provides production validation. It maps cleanly to SugarCraft's `honey-bounce` package, which is a faithful PHP port that also extends the upstream with meaningful ergonomic improvements (immutable patterns, presets, easing curves, accessibility support) without distorting the core algorithm.
