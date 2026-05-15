# Honey-Bounce Library Research: Physics & Animation Patterns

**Research Date:** 2026-05-13
**Library:** sugarcraft/honey-bounce (PHP 8.3+)
**Upstream:** charmbracelet/harmonica (Go)
**Goal:** Identify improvements from cross-language analysis

---

## Executive Summary

Honey-bounce is a solid PHP port of harmonica with clean spring physics implementation. Cross-language analysis reveals **5 high-value improvements** and **3 medium-value extensions** that would elevate the library's ergonomics and feature set to match modern animation libraries while maintaining its mathematical purity.

---

## 1. Current Implementation Analysis

### 1.1 Strengths (Keep)

| Aspect | Assessment |
|--------|------------|
| **Spring Physics** | ✅ Ryan Juckett algorithm - industry-proven, handles all damping regimes |
| **Immutability** | ✅ `with*()` pattern, new instances per update |
| **3D Support** | ✅ Vector/Point with z-axis defaulting to 0.0 |
| **Coordinate System** | ✅ Y-up default with Y-down aliases |
| **No Dependencies** | ✅ Pure math, no terminal deps |

### 1.2 Gap Analysis

| Feature | Current State | Modern Libraries |
|---------|---------------|------------------|
| Spring presets | ❌ None | ✅ react-spring: `gentle`, `wobbly`, `stiff`, `slow`, `molasses` |
| Tension/Friction API | ❌ Angular frequency + damping ratio | ✅ react-spring: `tension`, `friction`, `mass` (more intuitive) |
| Cubic-bezier easing | ❌ Hardcoded formulas | ✅ CSS-standard, parameterized |
| Animation chaining | ❌ None | ✅ react-spring: `useChain`, dioxus-motion: `AnimationSequence` |
| Gesture-driven springs | ❌ None | ✅ framer-motion: `drag` + `dragConstraints`, react-spring: `useSprings` with gesture |
| Reduced motion support | ❌ None | ✅ oscillation.js: `prefers-reduced-motion` |

---

## 2. Library-by-Library Analysis

### 2.1 Go Ecosystem

#### charmbracelet/harmonica (Upstream) ⭐
**Source:** https://github.com/charmbracelet/harmonica, https://pkg.go.dev/github.com/charmbracelet/harmonica

```go
// Core API - identical structure to honey-bounce
spring := harmonica.NewSpring(harmonica.FPS(60), 6.0, 0.5)
pos, vel := spring.Update(pos, vel, target)

// Projectile - identical structure
projectile := harmonica.NewProjectile(
    FPS(60),
    Point{6.0, 100.0, 0.0},
    Vector{2.0, 0.0, 0.0},
    Vector{2.0, -9.81, 0.0},
)
pos := projectile.Update()
```

**Key insight:** harmonica's API is the reference - honey-bounce is a faithful port. No changes needed for Go parity.

#### setanarut/tween (Go) ⭐⭐
**Source:** https://github.com/setanarut/tween

Tweening library with comprehensive easing functions and minimal GC pressure.

```go
// Tween with easing - different paradigm from spring physics
t := tween.New(begin, end, duration, ease.Linear)

// Easing families
ease.Quad.In, ease.Quad.Out, ease.Quad.InOut
ease.Cubic, ease.Quart, ease.Quint, ease.Expo, ease.Sine, ease.Circ
ease.Back  // "overshoots" before settling
ease.Bounce // bouncing effect
ease.Elastic // elastic gum effect
```

**Value for honey-bounce:** Adding a separate `Tween` class with duration-based easing would complement the spring physics. Not a replacement - additive value.

#### tanema/gween (Go)
**Source:** https://github.com/tanema/gween

Similar to tween, ~45 built-in easing functions. Uses `(t, b, c, d)` signature compatible with Penner easings.

#### vron/gease (Go)
**Source:** https://github.com/vron/gease

Spring-based convenience methods for gioui. Designed to minimize GC pressure (zero allocation per frame).

**Insight:** The "zero allocation per frame" pattern is relevant for high-performance PHP scenarios.

---

### 2.2 JavaScript/React Ecosystem

#### pmndrs/react-spring ⭐⭐⭐
**Source:** https://github.com/pmndrs/react-spring, https://context7.com/pmndrs/react-spring

**The most influential spring animation library.** Provides the reference API design:

```jsx
// Spring config with tension/friction/mass (more intuitive than ω/ζ)
const springs = useSpring({
  y: 0,
  config: {
    mass: 5,        // Higher = slower, more momentum
    tension: 120,   // Higher = faster, snappier
    friction: 120,  // Higher = more damping, less bounce
    clamp: false,   // Stop at goal without overshooting
    precision: 0.01 // Animation threshold
  }
})

// Pre-built presets
config.gentle  // { tension: 40, friction: 20 }
config.wobbly  // { tension: 180, friction: 12 }
config.stiff   // { tension: 210, friction: 20 }
config.slow    // { tension: 280, friction: 90 }
config.molasses // { tension: 70, friction: 120 }

// Per-key configuration
config: key => key === 'y' ? { mass: 5 } : { duration: 1000 }

// Chaining animations
const springRef = useSpringRef()
const transRef = useSpringRef()
useChain([springRef, transRef], [0, 0.4]) // 400ms delay
```

**Conversion formula for honey-bounce:**
```
tension = angularFrequency² × mass
friction = 2 × dampingRatio × angularFrequency × mass
```

Or inverse:
```
angularFrequency = sqrt(tension / mass)
dampingRatio = friction / (2 × sqrt(tension × mass))
```

**Value for honey-bounce:** HIGH - Add `SpringPreset` enum + `SpringConfig` value object with tension/friction/mass + conversion utilities.

#### framer-motion ⭐⭐
**Source:** https://context7.com/grx7/framer-motion

Production-grade React animation with gesture support:

```jsx
// Spring physics configuration
<motion.div
  animate={{ x: 100 }}
  transition={{
    type: "spring",
    stiffness: 100,    // Spring stiffness
    damping: 10,       // Resistance
    mass: 1,           // Virtual mass
    velocity: 50,      // Initial velocity
    restDelta: 0.01,   // Stop threshold
    restSpeed: 0.01
  }}
/>

// Gesture-driven animations
<motion.div
  drag="x"
  dragConstraints={{ top: -100, bottom: 100 }}
  dragElastic={0.2}
  dragMomentum={true}
  whileDrag={{ scale: 1.1 }}
  onDragEnd={(e, info) => console.log(info.velocity)}
/>

// whileHover, whileTap for micro-interactions
<motion.div whileHover={{ scale: 1.05 }} whileTap={{ scale: 0.95 }} />
```

**Value for honey-bounce:** MEDIUM - Gesture support is TUI-irrelevant, but `whileHover`/`whileTap` pattern and `restDelta`/`restSpeed` are useful concepts.

#### motion (formerly Framer Motion standalone) ⭐⭐
**Source:** https://motion.dev/

```javascript
// Spring physics
animate(element, { rotate: 180 }, {
  type: "spring",
  stiffness: 200,
  damping: 20
})
```

**Value for honey-bounce:** MEDIUM - Cross-platform variant reinforces spring API standardization.

#### popmotion / animate ⭐
**Source:** https://popmotion.io/

```javascript
// Core animation function - powers framer-motion
animate({
  from: 0,
  to: 100,
  type: "spring", // or "keyframes", "decay"
  // Spring options auto-detected from stiffness/damping/mass
})
```

**Value for honey-bounce:** LOW - architectural reference, not API inspiration.

#### d3-ease ⭐⭐⭐
**Source:** https://github.com/d3/d3-ease

```javascript
// Easing functions - standard across web animation
d3.easeCubic(t)           // Smooth acceleration
d3.easeQuad(t)
d3.easeSin(t)
d3.easeBounce(t)          // Bouncing effect
d3.easeElastic(t)         // Rubber band effect
d3.easeBack(t)            // Overshoot then settle

// Elastic with configurable amplitude/period
d3.easeElastic.amplitude(1)
d3.easeElastic.period(0.3)
```

**Key insight:** D3 easing is the web standard. The key insight is **symmetric easing** (`easeIn`, `easeOut`, `easeInOut`) and **configurable parameters** (amplitude, period).

**Value for honey-bounce:** HIGH - Add `CubicBezier` easing class that accepts CSS-standard control points.

#### dynamics.js ⭐
**Source:** https://github.com/michaelvillar/dynamics.js

```javascript
// Physics-based animation
dynamics.animate(el, {
  translateX: 350,
  scale: 2
}, {
  type: dynamics.spring,
  frequency: 200,
  friction: 200
})
```

**Insight:** `frequency` parameter is different from angular frequency - more like "bounciness".

#### oscillation ⭐⭐
**Source:** https://github.com/UnknownPrinciple/oscillation

```javascript
// Minimal spring physics with reduced motion support
motion(spring(0, 100), (value) => {
  console.log(value)
}, { ignoreReducedMotion: true })
```

**Value for honey-bounce:** MEDIUM - The `prefers-reduced-motion` handling is a11y important.

---

### 2.3 Rust/Dioxus Ecosystem

#### dioxus-spring ⭐⭐
**Source:** https://github.com/dioxus-community/dioxus-spring

```rust
// Spring signal with reactive integration
let spring = use_spring(
    if is_big() { rect.width() as f32 } else { 0f32 },
    Duration::from_millis(500),
);

// Animated value application
use_animated(animated_ref, spring, |width| {
    format!("width: {width}px;")
})
```

**Value for honey-bounce:** LOW - Dioxus-specific reactive hooks don't translate to PHP.

#### dioxus-motion ⭐⭐
**Source:** https://docs.rs/dioxus-motion

**Most comprehensive Rust animation library:**

```rust
// Animation sequence - highly relevant
let sequence = AnimationSequence::new()
    .then(1.2, AnimationConfig::new(AnimationMode::Spring(Spring {
        stiffness: 400.0, damping: 10.0, mass: 1.0, velocity: 5.0
    })))
    .then(0.8, AnimationConfig::new(AnimationMode::Spring(Spring {
        stiffness: 300.0, damping: 15.0, mass: 1.0, velocity: -2.0
    })))
    .then(1.0, AnimationConfig::new(AnimationMode::Spring::default()));

// Loop mode
.with_loop(LoopMode::Infinite)

// Spring config
Spring {
    stiffness: 100.0,
    damping: 5.0,
    mass: 0.5,
    velocity: 1.0
}
```

**Value for honey-bounce:** HIGH - `AnimationSequence` pattern and `stiffness`/`damping`/`mass`/`velocity` config are directly implementable in PHP.

---

### 2.4 PHP Ecosystem

#### haberco/easing ⭐
**Source:** https://packagist.org/packages/haberco/easing

Robert Penner easings ported to PHP. Current honey-bounce implementation is similar quality.

#### smnandre/easing-functions ⭐⭐
**Source:** https://github.com/smnandre/easing-functions

Modern PHP 8 easing library with comprehensive test coverage and cubic-bezier support:

```php
Easing\Functions::easeOutCubic(0.5); // 0.875

// Also available: Quadratic, Quartic, Quintic, Sine, Exponential, Circular, Back, Bounce, Elastic
// Each with In, Out, InOut variants
```

**CubicBezier support is notably absent** - this is an opportunity for honey-bounce to add the CSS-standard API.

---

## 3. Comparative API Analysis

### 3.1 Spring Physics Parameters

| Library | Parameter Set | Notes |
|---------|--------------|-------|
| **harmonica (Go)** | `deltaTime`, `angularFrequency`, `dampingRatio` | Mathematically precise, less intuitive |
| **react-spring** | `mass`, `tension`, `friction` | Intuitive, translates to ω/ζ internally |
| **framer-motion** | `stiffness`, `damping`, `mass`, `velocity` | Same as react-spring, different names |
| **dioxus-motion** | `stiffness`, `damping`, `mass`, `velocity` | Same paradigm, also adds precision/epsilon |
| **honey-bounce** | `deltaTime`, `angularFrequency`, `dampingRatio` | Matches harmonica exactly |

**Recommendation:** Add `SpringConfig` value object with tension/friction/mass, provide conversion utilities to angularFrequency/dampingRatio.

### 3.2 Easing Function Families

| Family | honey-bounce | react-spring | d3-ease | Notes |
|--------|-------------|--------------|---------|-------|
| Linear | ✅ | ✅ | ✅ | |
| Quadratic | ✅ | ✅ | ✅ | |
| Cubic | ✅ | ✅ | ✅ | |
| QuadraticInOut | ✅ | ✅ | ✅ | |
| Elastic | ✅ | ✅ | ✅ | |
| Bounce | ✅ | ✅ | ✅ | |
| Back | ✅ | ✅ | ✅ | |
| **CubicBezier** | ❌ | ❌ | ✅ | CSS standard |
| **Step** | ❌ | ✅ | ✅ | Discrete jumps |

**Recommendation:** Add `CubicBezier` easing class with Newton-Raphson solver for CSS-compatible curves.

### 3.3 Animation Orchestration

| Feature | honey-bounce | react-spring | dioxus-motion |
|---------|-------------|--------------|---------------|
| Chaining | ❌ | `useChain` | `AnimationSequence` |
| Staggering | ❌ | `trail` prop | Sequence delays |
| Parallel springs | `SpringCollection` | `useSprings` | Multiple `use_motion` |
| Per-spring config | ❌ | ✅ (per-key) | ✅ |

**Recommendation:** `SpringChain` class with `.then()` fluent API.

---

## 4. Prioritized Recommendations

### Priority 1: High-Value, Low-Effort

#### 4.1 Spring Presets + Tension/Friction API

**Effort:** Medium (2-3 days)
**Impact:** High - dramatically improves ergonomics

**New classes:**

```php
namespace SugarCraft\Bounce;

/**
 * Spring presets from react-spring.
 * Source: https://github.com/pmndrs/react-spring/blob/next/docs/app/routes/docs.advanced.config.mdx
 */
enum SpringPreset: string
{
    case Gentle  = 'gentle';   // { tension: 40, friction: 20 }
    case Wobbly  = 'wobbly';   // { tension: 180, friction: 12 }
    case Stiff   = 'stiff';    // { tension: 210, friction: 20 }
    case Slow    = 'slow';     // { tension: 280, friction: 90 }
    case Molasses = 'molasses'; // { tension: 70, friction: 120 }

    public function config(): SpringConfig
    {
        return match ($this) {
            self::Gentle   => new SpringConfig(tension: 40, friction: 20),
            self::Wobbly   => new SpringConfig(tension: 180, friction: 12),
            self::Stiff    => new SpringConfig(tension: 210, friction: 20),
            self::Slow     => new SpringConfig(tension: 280, friction: 90),
            self::Molasses => new SpringConfig(tension: 70, friction: 120),
        };
    }
}

/**
 * Intuitive spring configuration (tension/friction/mass).
 * Converted to angularFrequency/dampingRatio internally.
 */
final readonly class SpringConfig
{
    public function __construct(
        public float $tension = 100.0,
        public float $friction = 10.0,
        public float $mass = 1.0,
        public float $velocity = 0.0,
        public bool $clamp = false,
        public float $precision = 0.001,
    ) {}

    /**
     * Convert to harmonica's angularFrequency + dampingRatio.
     *
     * tension = ω² × mass → ω = sqrt(tension / mass)
     * friction = 2 × ζ × ω × mass → ζ = friction / (2 × ω × mass)
     */
    public function toHarmonicaParams(): array {
        $omega = sqrt($this->tension / $this->mass);
        $zeta = $this->friction / (2.0 * $omega * $this->mass);
        return ['angularFrequency' => $omega, 'dampingRatio' => $zeta];
    }
}

/**
 * Convenience factory using presets.
 */
final class SpringFactory
{
    public static function create(float $deltaTime, SpringPreset $preset): Spring { ... }

    public static function createWithConfig(float $deltaTime, SpringConfig $config): Spring { ... }
}
```

**Source:** react-spring config presets at https://context7.com/pmndrs/react-spring/llms.txt

---

#### 4.2 CubicBezier Easing

**Effort:** Medium (1-2 days)
**Impact:** High - CSS compatibility, infinite curve expressiveness

```php
namespace SugarCraft\Bounce\Easing;

/**
 * CSS standard cubic-bezier easing.
 * Source: https://github.com/gre/bezier-easing (ported from WebKit)
 */
final class CubicBezier
{
    public function __construct(
        private readonly float $p1x,
        private readonly float $p1y,
        private readonly float $p2x,
        private readonly float $p2y,
    ) {}

    public function ease(float $t): float
    {
        // Newton-Raphson solver for x → t mapping
        // Then sampleCurveY for y output
        return $this->solve($t, $this->solveEpsilon(1.0));
    }

    // Pre-defined CSS easings
    public static function ease(): self { return new self(0.25, 0.1, 0.25, 1.0); }
    public static function easeIn(): self { return new self(0.42, 0.0, 1.0, 1.0); }
    public static function easeOut(): self { return new self(0.0, 0.0, 0.58, 1.0); }
    public static function easeInOut(): self { return new self(0.42, 0.0, 0.58, 1.0); }
}
```

**Source:** WebKit cubic-bezier implementation at https://github.com/gre/bezier-easing, CSS spec at https://developer.mozilla.org/en-US/docs/Web/CSS/easing-function/cubic-bezier

---

### Priority 2: Medium-Value, Medium-Effort

#### 4.3 SpringChain (Animation Sequencing)

**Effort:** Medium (2-3 days)
**Impact:** High - enables complex animation workflows

```php
namespace SugarCraft\Bounce;

/**
 * Fluent animation chain with spring physics.
 * Mirrors dioxus-motion's AnimationSequence.
 * Source: https://docs.rs/dioxus-motion
 */
final class SpringChain
{
    /** @var array<array{value: float, config: SpringConfig, delay: float}> */
    private array $steps = [];

    public function then(float $value, ?SpringConfig $config = null, float $delayMs = 0): self
    {
        $this->steps[] = [
            'value' => $value,
            'config' => $config ?? SpringConfig::default(),
            'delay' => $delayMs,
        ];
        return $this;
    }

    public function loop(): self { /* ... */ return $this; }

    /**
     * @return \Generator<float> Yields (value, isComplete) tuples
     */
    public function run(float $deltaTime, float $from, float $to): \Generator { ... }
}
```

**Usage:**
```php
$chain = (new SpringChain())
    ->then(1.0, SpringPreset::Wobbly->config())
    ->then(0.0, SpringPreset::Stiff->config())
    ->loop();

foreach ($chain->run(Spring::fps(60), $currentValue, 100.0) as $value) {
    // Render frame
}
```

---

#### 4.4 Rest Detection Helpers

**Effort:** Low (1 day)
**Impact:** Medium - enables clean animation completion detection

```php
namespace SugarCraft\Bounce;

/**
 * Check if spring has essentially reached target.
 * Mirrors framer-motion's restDelta/restSpeed.
 * Source: https://context7.com/grx7/framer-motion
 */
final class SpringRestDetector
{
    public function __construct(
        private readonly float $restDelta = 0.01,
        private readonly float $restSpeed = 0.01,
    ) {}

    public function isAtRest(float $pos, float $vel, float $target): bool
    {
        return abs($pos - $target) < $this->restDelta
            && abs($vel) < $this->restSpeed;
    }
}
```

---

### Priority 3: Lower-Value, Higher-Effort

#### 4.5 Tween Class (Duration-Based Animation)

**Effort:** Medium-High (3-4 days)
**Impact:** Medium - adds alternative animation paradigm

Different from spring physics - uses duration + easing instead of physics simulation.

```php
namespace SugarCraft\Bounce\Tween;

/**
 * Duration-based tween with easing.
 * Mirrors setanarut/tween and gween Go libraries.
 * Source: https://github.com/setanarut/tween
 */
final class Tween
{
    public function __construct(
        private readonly float $from,
        private readonly float $to,
        private readonly float $durationMs,
        private readonly Easing\Easing $easing,
    ) {}

    /**
     * @return \Generator<float> Yields interpolated values
     */
    public function run(float $deltaTime): \Generator
    {
        $elapsed = 0.0;
        $total = $this->durationMs / 1000.0;

        while ($elapsed < $total) {
            $t = $elapsed / $total;
            $easedT = $this->easing->ease($t);
            yield $this->from + ($this->to - $this->from) * $easedT;
            $elapsed += $deltaTime;
        }

        yield $this->to;
    }
}
```

---

#### 4.6 Accessibility: Reduced Motion Support

**Effort:** Low (0.5 day)
**Impact:** Medium - accessibility compliance

```php
namespace SugarCraft\Bounce;

/**
 * Accessibility helper for reduced motion preference.
 * Source: oscillation.js https://github.com/UnknownPrinciple/oscillation
 */
final class ReducedMotion
{
    /**
     * Check if user prefers reduced motion.
     * In TUI context, would check $NO_COLOR or terminal capability.
     */
    public static function prefersReducedMotion(): bool
    {
        return getenv('NO_COLOR') !== false
            || getenv('TERM_PROGRAM') === 'Apple_Terminal';
    }

    /**
     * If reduced motion is preferred, return target directly.
     * Otherwise return null to animate normally.
     */
    public static function skipAnimation(?float $target, ?float $current): ?float
    {
        return self::prefersReducedMotion() ? $target : null;
    }
}
```

---

## 5. Implementation Roadmap

### Phase 1: Core Improvements (Week 1)

| Task | Effort | Priority | Files |
|------|--------|----------|-------|
| Add `SpringConfig` value object | 1 day | P1 | `src/SpringConfig.php` |
| Add `SpringPreset` enum | 0.5 day | P1 | `src/SpringPreset.php` |
| Add `SpringFactory` | 0.5 day | P1 | `src/SpringFactory.php` |
| Add `CubicBezier` easing | 1.5 days | P1 | `src/Easing/CubicBezier.php` |
| Update `Spring` to accept `SpringConfig` | 0.5 day | P1 | `src/Spring.php` |
| Add unit tests for new classes | 1 day | P1 | `tests/` |

**Files modified:** `src/Spring.php`, new `src/SpringConfig.php`, `src/SpringPreset.php`, `src/SpringFactory.php`, `src/Easing/CubicBezier.php`

### Phase 2: Animation Orchestration (Week 2)

| Task | Effort | Priority |
|------|--------|----------|
| Add `SpringRestDetector` | 1 day | P2 |
| Add `SpringChain` | 2 days | P2 |
| Add `SpringChainTest` | 1 day | P2 |
| Update `SpringCollection` to support configs | 0.5 day | P2 |

### Phase 3: Polish & Extensions (Week 3)

| Task | Effort | Priority |
|------|--------|----------|
| Add `Tween` class | 3 days | P3 |
| Add `ReducedMotion` helper | 0.5 day | P3 |
| Update `README.md` with new APIs | 0.5 day | P3 |
| Add VHS demos for new features | 1 day | P3 |

---

## 6. Specific Code Improvements

### 6.1 Spring.php - Add convenience constructor

**Current:**
```php
public function __construct(
    float $deltaTime,
    float $angularFrequency,
    float $dampingRatio,
) { ... }
```

**Proposed addition:**
```php
/**
 * Create from intuitive tension/friction/mass config.
 * Source: react-spring config conversion
 */
public static function fromConfig(float $deltaTime, SpringConfig $config): self
{
    $omega = sqrt($config->tension / $config->mass);
    $zeta = $config->friction / (2.0 * $omega * $config->mass);
    return new self($deltaTime, $omega, $zeta);
}
```

### 6.2 Easing.php - Add CubicBezier support

**Current enum approach limits parameterization.** Recommendation: Add separate class.

```php
namespace SugarCraft\Bounce\Easing;

/**
 * CSS cubic-bezier easing with Newton-Raphson solver.
 * Source: https://github.com/gre/bezier-easing
 */
final class CubicBezier
{
    private const NEWTON_ITERATIONS = 8;
    private const NEWTON_MIN_SLOPE = 0.001;
    private const SUBDIVISION_PRECISION = 0.0000001;
    private const SUBDIVISION_MAX_ITERATIONS = 10;

    private readonly float $ax;
    private readonly float $bx;
    private readonly float $cx;
    private readonly float $ay;
    private readonly float $by;
    private readonly float $cy;

    public function __construct(float $p1x, float $p1y, float $p2x, float $p2y)
    {
        $this->cx = 3.0 * $p1x;
        $this->bx = 3.0 * ($p2x - $p1x) - $this->cx;
        $this->ax = 1.0 - $this->cx - $this->bx;

        $this->cy = 3.0 * $p1y;
        $this->by = 3.0 * ($p2y - $p1y) - $this->cy;
        $this->ay = 1.0 - $this->cy - $this->by;
    }

    public function ease(float $t): float
    {
        return $this->solveY($this->solveX($t));
    }

    private function sampleCurveX(float $t): float
    {
        return (($this->ax * $t + $this->bx) * $t + $this->cx) * $t;
    }

    private function sampleCurveY(float $t): float
    {
        return (($this->ay * $t + $this->by) * $t + $this->cy) * $t;
    }

    private function sampleCurveDerivativeX(float $t): float
    {
        return (3.0 * $this->ax * $t + 2.0 * $this->bx) * $t + $this->cx;
    }

    private function solveCurveX(float $x): float
    {
        // Newton-Raphson iteration
        $t = $x;
        for ($i = 0; $i < self::NEWTON_ITERATIONS; $i++) {
            $x2 = $this->sampleCurveX($t) - $x;
            if (abs($x2) < self::SUBDIVISION_PRECISION) {
                return $t;
            }
            $d2 = $this->sampleCurveDerivativeX($t);
            if (abs($d2) < 1e-6) break;
            $t -= $x2 / $d2;
        }
        // Binary search fallback
        $t0 = 0.0; $t1 = 1.0; $t2 = $x;
        if ($t2 < $t0) return $t0;
        if ($t2 > $t1) return $t1;
        while ($t0 < $t1) {
            $x2 = $this->sampleCurveX($t2);
            if (abs($x2 - $x) < self::SUBDIVISION_PRECISION) return $t2;
            if ($x > $x2) $t0 = $t2;
            else $t1 = $t2;
            $t2 = ($t1 - $t0) * 0.5 + $t0;
        }
        return $t2;
    }

    private function solveY(float $x): float
    {
        return (($this->ay * $x + $this->by) * $x + $this->cy) * $x;
    }
}
```

---

## 7. References

| Library | Language | Relevance | URL |
|---------|----------|-----------|-----|
| harmonica | Go | Primary upstream | https://github.com/charmbracelet/harmonica |
| react-spring | JS/React | Spring API reference | https://github.com/pmndrs/react-spring |
| framer-motion | JS/React | Gesture patterns | https://github.com/motiondivision/motion |
| d3-ease | JS | Easing standard | https://github.com/d3/d3-ease |
| dioxus-motion | Rust | AnimationSequence | https://docs.rs/dioxus-motion |
| bezier-easing | JS | CubicBezier reference | https://github.com/gre/bezier-easing |
| tween | Go | Tweening patterns | https://github.com/setanarut/tween |
| oscillation | JS | Reduced motion | https://github.com/UnknownPrinciple/oscillation |
| easing-functions | PHP | PHP easing comparison | https://github.com/smnandre/easing-functions |

---

## 8. Out of Scope

These features are intentionally excluded from recommendations:

- **React hooks / reactive integration** - PHP doesn't have reactive primitives
- **DOM manipulation / CSS transitions** - TUI library, not browser
- **3D physics engines** - beyond harmonica's scope
- **Bezier path animation** - SVG territory, not terminal
- **Audio synchronization** - out of scope for pure physics

---

*Research compiled from Context7 API documentation, GitHub source analysis, and web search findings.*
