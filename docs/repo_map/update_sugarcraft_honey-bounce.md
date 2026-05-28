# Overview

`honey-bounce` is a pure-math PHP physics/animation library providing damped spring physics (Ryan Juckett's algorithm) and Newtonian projectile simulation. It is the PHP port of `charmbracelet/harmonica` and extends upstream with significant ergonomic improvements: UIKit-inspired spring presets, `SpringChain`/`SpringCollection` for multi-spring orchestration, `REDUCE_MOTION` accessibility support, and a complete `Easing`/`CubicBezier` curve library.

**Biggest opportunity areas:**
1. Forces system (applyForce) for projectiles — explicitly called out in upstream PR #6 TODO but never built
2. Animation completion callbacks — standard pattern in Flutter/iOS/Android/Framer Motion, missing from both upstream and SugarCraft
3. Collision detection primitives for physics-based games
4. Mutable projectile variant for high-frequency game loops (reduces GC pressure)
5. Verlet integration upgrade from Euler for better numerical stability

**Biggest missing capabilities:**
1. No collision response system (only pure kinematics)
2. No multi-body physics (joints/constraints)
3. No spatial partitioning for SpringCollection (O(n) linear search)
4. No animation timeline (GSAP-like orchestration)
5. No keyframe animation with cubic interpolation

# Internal Capability Summary

## Current Architecture

```
honey-bounce/src/
├── Spring.php              # Damped harmonic oscillator (Ryan Juckett algorithm)
├── SpringConfig.php        # tension/friction/mass → ω/ζ derivation
├── SpringPreset.php       # UIKit-inspired presets (Gentle, Wobbly, Stiff, Slow, Molasses)
├── SpringChain.php        # Sequential spring sequencer
├── SpringCollection.php   # Multi-spring parallel manager
├── Projectile.php         # Euler-integrated projectile
├── Point.php              # Immutable 3D point {x, y, z}
├── Vector.php             # Immutable 3D vector with add/sub/scale/length/dot/cross
├── Gravity.php             # Static gravity vector accessors
├── Easing/
│   ├── Easing.php          # Enum with 15 easing curves
│   └── CubicBezier.php     # CSS cubic-bezier() with Newton-Raphson solver
└── Lang.php               # i18n facade
```

## Current Features

**Core Physics:**
- `Spring`: Analytically exact damped harmonic oscillator with three damping regimes (under-damped ζ<1, critically damped ζ=1, over-damped ζ>1)
- `Projectile`: Euler-integrated 3D projectile motion with configurable gravity
- `Point`/`Vector`: Immutable 3D value objects with full vector math (add, sub, scale, length, dot, cross)
- `Gravity`: Package-level static accessors for standard/terminal gravity in Y-up/Y-down conventions

**Animation Orchestration:**
- `SpringChain`: Sequential stage animator (each stage activates when previous settles within 0.001 pos/vel)
- `SpringCollection`: Parallel multi-spring manager with tick()/setTarget() API

**Easing:**
- `Easing`: 15 named curves (Linear, Quad, Cubic, Elastic, Bounce, Back — each with In/Out/InOut)
- `CubicBezier`: 24 CSS-named easings via Newton-Raphson with binary-search fallback

**Accessibility:**
- `REDUCE_MOTION` / `PREFERS_REDUCED_MOTION` env var support — springs snap to target instantly
- `Probe::reducedMotion()` check in `Spring::update()` at line 114

## API Strengths

1. **O(1) per-frame updates**: After constructor precomputes coefficients, `Spring::update()` is 4 multiplies + 2 additions
2. **Immutable patterns**: `Projectile::update()` returns new instance (upstream mutates); `Point`/`Vector` are value objects
3. **Rich presets**: UIKit tension/friction presets eliminate magic number tuning
4. **FPS helper**: `Spring::fps(int)` returns `1.0/n` for consistent simulation cadence
5. **Y-up/Y-down flexibility**: `Gravity::standard()` vs `Gravity::standardYDown()` matches any coordinate convention

## API Weaknesses

1. **No animation completion callbacks** — callers must poll `isComplete()` or manually check convergence
2. **Mutable Projectile allocates per frame** — in tight game loops (30+ FPS), this creates garbage
3. **No frame-skip handling** — baked-in `deltaTime` assumes consistent frame cadence
4. **No spring termination detection** — no `isSettled()` helper
5. **SpringCollection uses linear search** — O(n) per tick for n springs

# Relevant External Repositories

| Repo | Relevance | Major applicable concepts | Priority |
|------|-----------|---------------------------|---------|
| `charmbracelet/harmonica` | Direct upstream | Ryan Juckett algorithm, coefficient caching, spring presets (missing from upstream) | Critical |
| `charmbracelet/bubbletea` | Downstream consumer | Elm architecture, animation integration patterns | High |
| `textualize/textual` | Sibling framework | Animator class with easing, reactive state + watchers for auto animation triggering | High |
| `charmbracelet/confettysh` | Downstream consumer | Particle physics, velocity/gravity/acceleration for confetti/fireworks | Medium |
| `ratatui/ratatui` | Competing TUI | No built-in animation — animations must be manually implemented via state and timers | Low |
| `charmbracelet/glow` | Ecosystem reference | File watching + live reload patterns | Low |

# Feature Gap Analysis

## Critical Priority

### 1. Spring Completion Callback System
- **Title**: Spring termination callbacks
- **Description**: Add `Spring::setOnComplete(callable)` or `SpringChain::onComplete(callable)` that fires when a spring settles
- **Why it matters**: This is a standard pattern in every major animation system — Flutter (`AnimationController.addStatusListener`), iOS (`CAAnimation.delegate`), Framer Motion (`onComplete`), React Spring (`onRest`). Neither upstream harmonica nor SugarCraft currently implements this
- **Source repo**: `charmbracelet/harmonica` (implicit need, never requested formally)
- **Source discussion**: `pr_charmbracelet_harmonica.md` Section 19, item 5
- **Implementation ideas**: Add `?callable $onComplete` parameter to `Spring::__construct()`; store as readonly property; call with `($pos, $vel, $target)` when equilibrium is reached within epsilon
- **Estimated complexity**: Low — add callable property, call at end of `update()` when convergence detected
- **Expected impact**: High — eliminates polling boilerplate for all animation consumers

### 2. Forces System for Projectile
- **Title**: ApplyForce API for projectiles
- **Description**: Add `Projectile::applyForce(Vector $force)` to modify acceleration mid-flight (wind, thrust, explosions)
- **Why it matters**: Explicitly listed in upstream PR #6 TODO but never built. Natural extension for game physics
- **Source repo**: `charmbracelet/harmonica` PR #6
- **Source PR**: `pr_charmbracelet_harmonica.md` lines 73-78
- **Implementation ideas**: Return new `Projectile` with modified `acceleration` field; chainable API
- **Estimated complexity**: Low — single method adding force vector to acceleration
- **Expected impact**: Medium — enables wind/thrust/explosion effects in games

## High Value

### 3. Collision Detection Primitives
- **Title**: Collidable interface with sphere/AABB colliders
- **Description**: Add `Collidable` interface with `SphereCollider`, `AABBCollider` implementations for physics-based games
- **Why it matters**: Explicitly in upstream PR #6 TODO list and needed for particle games
- **Source repo**: `charmbracelet/harmonica` PR #6
- **Implementation ideas**: `interface Collidable { function collidesWith(Point $p): bool; }` + `SphereCollider(float $radius)` + `AABBCollider(Rect $bounds)`
- **Estimated complexity**: Medium — requires `Rect` or bounds type
- **Expected impact**: Medium — enables bouncing ball games, particle collisions

### 4. Mutable Projectile Variant
- **Title**: In-place projectile update for game loops
- **Description**: Add `ProjectileMutable` with in-place `update()` for particle systems where allocation overhead matters
- **Why it matters**: Current immutable `Projectile::update()` creates new instance each tick — in a 30 FPS game loop with 100s of particles, this generates significant garbage
- **Source**: `sugarcraft_honey-bounce.md` lines 371-373
- **Implementation ideas**: `class ProjectileMutable { public function update(): void { $this->position = $this->position->add(...); ... } }`
- **Estimated complexity**: Low — clone existing class, change update to void, mutate in place
- **Expected impact**: Medium — enables high-performance particle systems without GC pressure

### 5. Spring.isSettled() Helper
- **Title**: Convergence detection utility
- **Description**: Add `bool isSettled(float $pos, float $vel, float $target, float $epsilon = 0.001)` method
- **Why it matters**: Eliminates common user pattern of manually checking `abs($pos - $target) < $epsilon && abs($vel) < $epsilon`
- **Source**: `pr_charmbracelet_harmonica.md` Section 24, item 2
- **Implementation ideas**: Simple epsilon comparison; make epsilon configurable via constructor or constant
- **Estimated complexity**: Trivial — one method, ~5 lines
- **Expected impact**: Medium — reduces boilerplate for all Spring consumers

## Medium Priority

### 6. Frame-Skip Handling
- **Title**: Adaptive timestep for springs
- **Description**: Add `Spring::updateWithElapsed($pos, $vel, $target, $actualDt)` variant that recomputes coefficients if actual frame time deviates significantly from baked-in dt
- **Why it matters**: Current implementation assumes consistent frame cadence; if actual frame time differs, simulation drifts
- **Source**: `sugarcraft_honey-bounce.md` lines 377-380
- **Implementation ideas**: Detect when `$actualDt` differs from constructor dt by >10%; use precomputed coefficients for typical case, fallback to numerical integration for large deviations
- **Estimated complexity**: Medium — requires coefficient recomputation path
- **Expected impact**: Medium — improves simulation accuracy in variable-frame-rate environments

### 7. SpringCollection Spatial Indexing
- **Title**: O(log n) spring lookup in collections
- **Description**: Add spatial index to `SpringCollection` for efficient spring lookup by name
- **Why it matters**: Currently O(n) linear search per tick for n springs
- **Source**: `sugarcraft_honey-bounce.md` lines 439-441
- **Implementation ideas**: Use associative array (already O(1) by key) — the linear search concern is misplaced for hashmap; verify current implementation uses array or SplFixedArray
- **Estimated complexity**: Verification needed — may already be O(1) by key
- **Expected impact**: Low — depends on current implementation

### 8. Animation Timeline (GSAP-like)
- **Title**: Timeline orchestrator for multi-sequence animations
- **Description**: Add `Timeline` class that orchestrates multiple `SpringChain`/`SpringCollection` instances with start/delay/loop/repeat config
- **Why it matters**: Consumers must manually orchestrate multi-stage animations via SpringChain polling; GSAP-style timeline would simplify complex sequences
- **Source**: `sugarcraft_honey-bounce.md` lines 408-411
- **Implementation ideas**: `class Timeline { function add(Spring|$animation, float $startTime, float $duration, ?Easing $ease): self; function tick(float $currentTime): array; }`
- **Estimated complexity**: High — significant new API surface
- **Expected impact**: Medium — improves developer experience for complex animations

### 9. Verlet Integration Upgrade
- **Title**: Replace Euler with Verlet for projectile physics
- **Description**: Upgrade `Projectile::update()` from first-order Euler to Verlet integration for better numerical stability
- **Why it matters**: Euler integration accumulates error over many frames; Verlet is more stable for games
- **Source**: `sugarcraft_honey-bounce.md` lines 400-402
- **Implementation ideas**: Store previous position; compute velocity as `(currentPos - prevPos) / dt`; new position = `currentPos + velocity * dt + acceleration * dt * dt`
- **Estimated complexity**: Medium — changes update semantics
- **Expected impact**: Medium — better physics accuracy for games

### 10. Keyframe Animation
- **Title**: Cubic interpolation between keyframes
- **Description**: Add `KeyframeAnimation` with cubic interpolation between keyframes, supporting `Easing` curves between segments
- **Why it matters**: Complements spring physics with explicit keyframe animation for cutscene/montage-style animations
- **Source**: `sugarcraft_honey-bounce.md` lines 412-414
- **Implementation ideas**: `class KeyframeAnimation { function __construct(array $keyframes, ?Easing $ease); function evaluate(float $t): float; }` where keyframes are `[['time' => 0.0, 'value' => 0.0], ...]`
- **Estimated complexity**: Medium — new class with interpolation logic
- **Expected impact**: Low-Medium — edge case for complex animation sequences

## Low Priority

### 11. 2D Vector Shortcut
- **Title**: Vector2D or Vector::from2D() factory
- **Description**: Add `Vector::from2D()` factory or dedicated `Vector2D` class for 2D-only use cases
- **Why it matters**: Avoids unused `$z` allocation for 2D use cases
- **Source**: `sugarcraft_honey-bounce.md` lines 396-398
- **Implementation ideas**: `Vector::from2D(float $x, float $y): Vector` returning `new Vector($x, $y, 0.0)`
- **Estimated complexity**: Trivial
- **Expected impact**: Low — micro-optimization

### 12. Mass for Projectiles
- **Title**: Projectile mass parameter
- **Description**: Add mass parameter to `Projectile` for more realistic physics (F = ma calculations)
- **Why it matters**: Listed in upstream PR #6 TODO; enables force-based physics
- **Source**: `charmbracelet/harmonica` PR #6
- **Implementation ideas**: Add `float $mass` to constructor; modify acceleration calculation to `acceleration = force / mass`
- **Estimated complexity**: Medium — changes constructor signature
- **Expected impact**: Low — niche use case

### 13. Spring 1D/2D/3D Generic Variants
- **Title**: Dimension-specific spring classes
- **Description**: Template spring to specific dimensions to avoid array packing/unpacking (`[$pos, $vel]`)
- **Why it matters**: Would enable SIMD optimizations and cleaner API for multi-dimensional springs
- **Source**: `sugarcraft_honey-bounce.md` lines 404-406
- **Implementation ideas**: `class Spring1D { function update(float $pos, float $vel, float $target): float; }` etc.
- **Estimated complexity**: High — significant refactoring
- **Expected impact**: Low — premature optimization

### 14. Particle System with Spatial Hashing
- **Title**: Bundled Particle + ParticleSystem class
- **Description**: Add `Particle` struct + `ParticleSystem` manager with spatial hashing for efficient broad-phase collision detection
- **Why it matters**: Foundation for particle effects; confettysh uses this pattern
- **Source**: `charmbracelet/confettysh.md` lines 35-39
- **Implementation ideas**: `class Particle { Point $pos; Vector $vel; float $lifetime; }` + `class ParticleSystem { function __construct(SpatialHash $hash); function emit(int $count): void; function tick(): void; }`
- **Estimated complexity**: High — significant new system
- **Expected impact**: Low-Medium — enables particle effects

### 15. Spring Visualization/Debugging Tools
- **Title**: SpringDebug helper for animation inspection
- **Description**: Add `SpringDebug` helper that formats current spring state as string for debugging animations
- **Why it matters**: Upstream has no way to inspect spring state mid-animation; helpful for developers
- **Source**: `pr_charmbracelet_harmonica.md` Section 19, item 4
- **Implementation ideas**: `class SpringDebug { static function describe(Spring $s, float $pos, float $vel, float $target): string; }`
- **Estimated complexity**: Trivial
- **Expected impact**: Low — developer convenience

# Algorithm / Performance Opportunities

## Current Approach vs External Approach

### Spring Physics
- **Current**: Ryan Juckett analytically exact solution per timestep; O(1) per update after coefficient precomputation
- **External**: Various numerical integrators (Verlet, RK4) — less stable at low frame rates
- **Verdict**: Current approach is superior for fixed-timestep spring physics. No change needed.

### Projectile Physics
- **Current**: First-order Euler integration: `pos += vel * dt`, `vel += acc * dt`
- **External**: Verlet integration (position-based, no velocity accumulation error), RK4 (higher accuracy)
- **Why external is better**: Euler accumulates velocity error over many frames; Verlet is more stable for games with variable dt
- **Tradeoffs**: Verlet changes the API semantics (requires storing previous position); RK4 is 4x cost per frame
- **Applicability**: Medium — beneficial for games, not critical for UI animations

### SpringCollection Lookup
- **Current**: Assumed linear search O(n) per tick
- **External**: Hash map O(1) lookup by key
- **Why external is better**: If using PHP associative array, already O(1) by key
- **Tradeoffs**: None if already using array
- **Applicability**: Low — verify current implementation before optimizing

### Easing Functions
- **Current**: PHP enum with 15 easing curves + 24 CSS cubic-bezier via Newton-Raphson
- **External**: JavaScript `easings.net` visual reference, CSS easing spec
- **Why external is better**: Parity with web standards; Newton-Raphson implementation matches W3C spec
- **Tradeoffs**: None — already well-implemented
- **Applicability**: N/A — feature is complete

# Architecture Improvements

## Immediate

1. **Add `isSettled()` method to Spring**: Simple convergence check helper (see Feature Gap item 5)

2. **Document PHP extension requirements clearly**: The OpenGL example issue in upstream (charmbracelet/harmonica#13) happened because system dependencies weren't documented. SugarCraft should have a visible "Requirements" section.

3. **Add spring termination callback**: See Feature Gap item 1

## Medium-term

4. **Forces system for Projectile**: `Projectile::applyForce(Vector $force)` for mid-flight acceleration changes

5. **Verlet integration upgrade**: Replace Euler with Verlet for better numerical stability in games

6. **Collision detection primitives**: `Collidable` interface with `SphereCollider`, `AABBCollider`

7. **Mutable Projectile variant**: `ProjectileMutable` for high-frequency game loops without GC pressure

## Long-term

8. **Animation Timeline**: GSAP-like orchestrator for complex animation sequences

9. **Particle System**: With spatial hashing for broad-phase collision

10. **Spring 1D/2D/3D variants**: Dimension-specific classes for cleaner API and potential SIMD

# API / Developer Experience Improvements

1. **Spring completion callback**: `Spring::setOnComplete(callable)` — eliminates polling boilerplate
2. **isSettled() helper**: `Spring::isSettled($pos, $vel, $target, $epsilon = 0.001)` — standard convergence check
3. **Forces API**: `Projectile::applyForce($force)` — chainable force application
4. **Preset visualization**: Add ASCII visualization of each preset's feel to docs (e.g., "~~~" for Wobbly, "--" for Stiff)
5. **valueWhenSettled()**: Return what value spring would converge to if left running indefinitely
6. **SpringChain debug()**: Return structured array of all springs and their current states for debugging

# Documentation / Cookbook Opportunities

1. **Animation cookbook**: Common patterns — drag-and-drop with spring back, parallax scrolling, staggered list animations, bounce-in entrances
2. **Physics visualization**: ASCII art showing each damping regime's behavior over time
3. **Preset comparison table**: Visual reference for which preset to use when ( Gentle = modal dialogs, Wobbly = notifications, Stiff = sliders, etc.)
4. **Game physics tutorial**: Build a simple bouncing ball game using Projectile + collision detection
5. **Integration examples**: honey-bounce + candy-core (TUI animation), honey-bounce + honey-flap (game physics)

# UX / TUI Improvements

1. **Animator wrapper for candy-core**: `SugarCraft\Bounce\Animator` class that auto-hooks springs to reactive properties in candy-core models — similar to textual's Animator class
2. **Animation presets in sugar-bits**: Pre-built animated components (animated list item, spring button, bounce notification) using honey-bounce springs
3. **REDUCE_MOTION documentation**: Prominently document the accessibility feature — this is SugarCraft's strongest differentiation from upstream

# Testing / Reliability Improvements

1. **Property-based testing**: Use PHPStan/Erigon-style property tests for spring physics invariants (convergence, energy decay)
2. **Snapshot tests for spring curves**: Assert known position/velocity sequences for each preset at fixed time steps
3. **Easing curve validation**: Assert easing outputs are monotonically increasing for In easings, decreasing for Out easings
4. **Frame-rate independence tests**: Verify spring behavior is consistent across different fps values (30, 60, 120)
5. **Reduced motion integration test**: Explicit test that REDUCE_MOTION=1 causes instant snap

# Ecosystem / Integration Opportunities

1. **honey-bounce + candy-core**: Animation system for TUI components — animated buttons, spring-based list reordering
2. **honey-bounce + honey-flap**: Already exists — Flappy Bird clone using Projectile for vertical motion
3. **honey-bounce + sugar-charts**: Animated chart transitions with spring-based easing
4. **Publish as standalone package**: When SugarCraft reaches v1.0, publish `honey-bounce` separately to Packagist as `sugarcraft/honey-bounce`
5. **Particle effects system**: Using confettysh as reference, build particle system on top of honey-bounce physics

# Notable PRs / Issues / Discussions

## From upstream charmbracelet/harmonica:

### Issue #13 — "issue running an example" (Open)
- System dependencies for OpenGL example were never documented
- **Lesson**: SugarCraft should document PHP extension requirements clearly

### Issue #11 — "go mod tidy unable to pull Projectile" (Closed)
- Breaking API between v0.1.0 and v0.2.0 — types on master not available in latest tag
- **Lesson**: SugarCraft should ensure examples match released versions

### PR #6 — Projectile motion addition
- Added projectile physics; explicitly listed TODO items that were never built: mass, forces, collisions
- **Lesson**: SugarCraft implementing these TODOs would be genuine feature leadership

### PR #24 — Spring tests added
- Added first comprehensive spring tests for all three damping regimes
- **Lesson**: SugarCraft should have equivalent comprehensive tests

### Commit d45b9627 — Data race prevention
- Copy values in Update method to avoid data races in concurrent scenarios
- **Lesson**: Immutable pattern in SugarCraft already addresses this; verify concurrent use is safe

## From textualize/textual:

### Animator class
- Built-in animator with easing functions and transitions
- **Lesson**: A `SugarCraft\Bounce\Animator` wrapper for candy-core could provide similar ergonomics

### Reactive state + watchers
- Animations triggered automatically when reactive properties change
- **Lesson**: Spring animations could be integrated into candy-core's reactive system

## From charmbracelet/confettysh:

### Particle system pattern
- Uses velocity, gravity, acceleration for confetti/fireworks
- **Lesson**: `honey-bounce` + a future `ParticleSystem` class could replicate this

# Recommended Roadmap

## Immediate Wins (Next Release)

1. **Add `isSettled()` method** to Spring — trivial addition, high impact
2. **Add spring completion callback** — `?callable $onComplete` in constructor
3. **Add `valueWhenSettled()`** — return target value for interruption handling
4. **Document REDUCE_MOTION prominently** — this is SugarCraft's strongest accessibility differentiator

## Medium-term Improvements (Next Minor Version)

5. **Forces system for Projectile** — `applyForce(Vector $force)`
6. **Mutable Projectile variant** — for game/particle loops without GC pressure
7. **Collision detection primitives** — `Collidable` interface
8. **Document PHP extension requirements** — fix the upstream documentation gap

## Major Architectural Upgrades (v2.0)

9. **Verlet integration** for projectiles
10. **Animation Timeline** (GSAP-like)
11. **Keyframe animation** with cubic interpolation
12. **ParticleSystem** with spatial hashing

## Experimental Ideas (Long-term)

13. **Spring 1D/2D/3D generic variants**
14. **rust-physics binding** via PHP FFI for complex physics
15. **WebAssembly compilation** for browser-based TUI rendering

# Priority Matrix

| Opportunity | Impact | Complexity | Risk | Recommended Priority |
|-------------|--------|------------|------|---------------------|
| Spring completion callback | High | Low | Low | Immediate |
| isSettled() helper | Medium | Trivial | None | Immediate |
| valueWhenSettled() | Low | Trivial | None | Immediate |
| REDUCE_MOTION docs | Medium | Low | None | Immediate |
| Forces system (Projectile) | Medium | Low | Low | Medium-term |
| Mutable Projectile variant | Medium | Low | Low | Medium-term |
| Collision detection | Medium | Medium | Low | Medium-term |
| Verlet integration | Medium | Medium | Medium | Medium-term |
| Animation Timeline | Medium | High | Medium | v2.0 |
| Keyframe animation | Low | Medium | Low | v2.0 |
| ParticleSystem | Medium | High | Medium | v2.0 |
| Spring 1D/2D/3D variants | Low | High | Low | Long-term |
| rust-physics FFI binding | High | Very High | High | Long-term |

# Final Strategic Assessment

**honey-bounce** is a well-executed, faithful PHP port of `charmbracelet/harmonica` that meaningfully extends the upstream with ergonomic improvements: UIKit-inspired spring presets, immutable patterns, multi-spring orchestration (`SpringChain`/`SpringCollection`), `REDUCE_MOTION` accessibility support, and a complete easing curve library. The implementation quality is high — the Ryan Juckett spring algorithm is correctly ported with O(1) per-frame updates, and the physics-first, framework-agnostic design is the right architectural choice.

**The library's strongest differentiation from upstream is:**
1. Spring presets (UIKit tension/friction mappings) — upstream has no equivalent
2. REDUCE_MOTION support — upstream has zero accessibility consideration
3. Immutable `Projectile::update()` — upstream mutates in place
4. Easing/CubicBezier curves — upstream has no easing functions
5. SpringChain/SpringCollection — upstream has no multi-spring abstraction

**Strategic gaps to address:**
The most significant opportunity is implementing the features explicitly called out in upstream's PR #6 TODO list but never built: forces system, collision detection, and mass. Since upstream explicitly sketched but never delivered these, SugarCraft implementing them would represent genuine feature leadership, not just porting.

Second, the lack of animation completion callbacks is a notable ergonomic gap compared to every major animation system (Flutter, iOS, Android, Framer Motion, React Spring). Adding `onComplete` callbacks to springs would eliminate the polling boilerplate that all animation consumers currently endure.

Third, the mutable `Projectile` allocation pattern creates garbage in tight game loops. Adding a `ProjectileMutable` variant with in-place updates would enable high-performance particle systems without GC pressure.

**Ecosystem positioning:**
`honey-bounce` fills the physics/animation layer in SugarCraft's stack, sitting below `candy-core` (TUI runtime) and alongside `honey-flap` (game physics consumer). Its physics-first approach is correct — consumers handle rendering, enabling use in TUIs, games, or any animation context. The library's independence from terminal dependencies is a strength that should be preserved.

**Recommended strategic direction:**
1. **Consolidate v1.x** by adding the immediate wins (isSettled, onComplete, forces, collision detection)
2. **Position v2.0** around game physics capabilities (Verlet, ParticleSystem, Timeline)
3. **Document accessibility leadership** — REDUCE_MOTION support is a genuine differentiator that should be prominently marketed
4. **Consider standalone publishing** — when SugarCraft reaches v1.0, `honey-bounce` on Packagist would expose PHP physics animation to the broader ecosystem

The library is in strong shape — the main opportunities are ergonomic additions rather than fundamental architectural changes.
