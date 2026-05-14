# honey-flap Research: Flappy Bird Clone Implementations

**Date:** 2026-05-13  
**Upstream:** kbrgl/flapioca (Go + Bubble Tea)  
**Stack:** SugarCraft monorepo, PHP 8.3+, HoneyBounce physics

---

## 1. Current Implementation Analysis

### Source Files
| File | Lines | Purpose |
|------|-------|---------|
| `src/Bird.php` | 71 | HoneyBounce Projectile physics (gravity + flap impulse) |
| `src/Game.php` | 263 | Game state, collision, scoring, high scores |
| `src/Pipe.php` | 45 | Pipe pair with gap collision detection |
| `src/Renderer.php` | 83 | ASCII framebuffer rendering |
| `src/TickMsg.php` | 17 | 30fps tick message |

### Physics Model
**Source:** `honey-flap/src/Bird.php:L22-L65`

```php
final class Bird
{
    public const FLAP_KICK     = -22.0; // cells/sec upward
    public const GRAVITY       = 70.0;  // cells/sec²
    public const TICKS_PER_SEC = 30;

    public static function spawn(int $x, float $y): self
    {
        return new self(
            x: $x,
            body: Projectile::new(
                deltaTime:    Spring::fps(self::TICKS_PER_SEC),  // ~0.033s
                position:     new Point($x, $y),
                velocity:     Vector::zero(),
                acceleration: new Vector(0.0, self::GRAVITY),    // Y-down gravity
            ),
        );
    }
}
```

**Key observation:** Uses HoneyBounce `Projectile` with Y-down gravity (70.0 cells/sec²), flap resets velocity to -22.0 cells/sec upward.

---

## 2. Upstream: kbrgl/flapioca (Go)

**Source:** [github.com/kbrgl/flapioca](https://github.com/kbrgl/flapioca)

### Architecture
- **Framework:** Bubble Tea (Charmbracelet TUI framework)
- **Physics:** Manual increment per tick (no physics library)
- **Tick rate:** 5 ticks/second (`time.Second/5`)
- **World:** 60×9 cells, bird at column 2

### Physics Implementation
**Source:** [flapioca/internal/model.go](https://raw.githubusercontent.com/kbrgl/flapioca/master/internal/model.go)

```go
func (m Model) Frame() (tea.Model, tea.Cmd) {
    if !m.Pressed {
        m.Cursor.Y++  // Simple gravity: 1 cell/tick down
    }
    m.Pressed = false

    for _, obst := range m.Obstacles {
        obst.Location.X--  // Pipes move left
        // ... collision & scoring ...
    }
    // Spawn new obstacles
    if gap > 5 || (rand.Intn(100) > 90 && gap > 2) {
        // Add new obstacle
    }
}
```

### Key Differences from honey-flap
| Aspect | flapioca | honey-flap |
|--------|----------|------------|
| Gravity | 1 cell/tick (discrete) | 70.0 cells/sec² (continuous) |
| Tick rate | 5 Hz | 30 Hz |
| Physics lib | None (manual) | HoneyBounce Projectile |
| Collision | `abs(y-rightmost.Y) <= radius` | `row < top || row > bottom` |
| Bird glyph | `*` | `>` |

---

## 3. Rust/Bevy Implementations

### 3.1 rust-adventure/floppy-corgi (Bevy 0.18)

**Source:** [rust-adventure/floppy-corgi](https://github.com/rust-adventure/floppy-corgi)

**Physics (ECS systems):**
```rust
#[derive(Component)]
#[require(Gravity(1000.), Velocity)]
struct Player;

#[derive(Component)]
struct Gravity(f32);

#[derive(Component, Default)]
struct Velocity(f32);

fn gravity(
    mut transforms: Query<(&mut Transform, &mut Velocity, &Gravity)>,
    time: Res<Time>,
) {
    for (mut transform, mut velocity, gravity) in &mut transforms {
        velocity.0 -= gravity.0 * time.delta_secs();  // a = -g
        transform.translation.y += velocity.0 * time.delta_secs();  // v = at
    }
}

fn controls(
    mut velocity: Single<&mut Velocity, With<Player>>,
    buttons: Res<ButtonInput<MouseButton>>,
) {
    if buttons.any_just_pressed([MouseButton::Left, MouseButton::Right]) {
        velocity.0 = 400.;  // JUMP_VELOCITY
    }
}
```

**Bird rotation based on velocity:**
```rust
fn enforce_bird_direction(
    mut player: Single<(&mut Transform, &Velocity), With<Player>>,
) {
    let calculated_velocity = Vec2::new(PIPE_SPEED, player.1.0);
    player.0.rotation = Quat::from_rotation_z(
        calculated_velocity.to_angle(),  // Rotate bird with velocity vector
    );
}
```

### 3.2 Rustfinity Tutorial (Bevy 0.14)

**Source:** [rustfinity.com/tutorials/flappy-rust](https://rustfinity.com/tutorials/flappy-rust/physics)

**Physics with rotation:**
```rust
pub fn gravity(time: Res<Time>, mut query: Query<(&mut Bird, &mut Transform)>) {
    for (mut bird, mut transform) in query.iter_mut() {
        let delta = time.delta().as_secs_f32();
        let gravity = 9.8;
        let delta_v = gravity * 150. * delta;
        
        bird.velocity -= delta_v;
        transform.translation.y += bird.velocity * delta;
        
        // Rotate bird based on velocity
        let rotation = bird.velocity / 600.0;
        let max_rotation = 0.5;
        transform.rotation = Quat::from_rotation_z(
            rotation.clamp(-max_rotation, max_rotation)
        );
    }
}

pub fn jump(mut query: Query<&mut Bird>, keyboard_input: Res<ButtonInput<KeyCode>>) {
    if !keyboard_input.just_pressed(KeyCode::Space) { return; }
    for mut bird in query.iter_mut() {
        bird.velocity = 400.0;  // Upward impulse
    }
}
```

### 3.3 dangreen07/Flappy-Bird-Rust

**Physics constants:**
| Constant | Value |
|----------|-------|
| Pipe speed | 50.0 px/sec |
| Pipe gap | 150 px |
| Gravity | -20.0 |
| Jump force | 5000.0 |

**Collision:** Axis-aligned bounding box with `Rect::intersect`

---

## 4. Comparison Matrix

| Feature | flapioca (Go) | honey-flap (PHP) | Bevy (Rust) |
|---------|---------------|------------------|-------------|
| **Physics** | Discrete gravity | HoneyBounce Projectile | ECS gravity system |
| **Gravity value** | 1 cell/tick | 70.0 cells/sec² | 9.8 * 150 scale factor |
| **Flap impulse** | Y-- (discrete) | -22.0 cells/sec | 400.0 (Bevy), 5000.0 (Rust) |
| **Collision** | Cell-based | Cell-based | AABB/Circle |
| **Rotation** | None | None | Yes (velocity-based) |
| **Animation** | None | None | Sprite sheets |
| **Sound** | None | None | Wing sound on flap |
| **High scores** | No | JSON file | No |
| **Tests** | None mentioned | 4 test files | Unknown |

---

## 5. Specific Improvements for honey-flap

### 5.1 Bird Rotation (High Priority)
**Reference:** rust-adventure/floppy-corgi + Rustfinity

Add visual rotation based on velocity to communicate bird pitch.

**Implementation:**
```php
// src/Bird.php - Add after row()
public function rotation(): float
{
    // Map velocity to rotation angle (radians)
    // Y-down: positive velocity = falling = negative rotation (nose down)
    // Clamp between -0.5 and 0.5 radians (~±30°)
    $maxRotation = 0.5;
    $scale = $this->body->velocity->y / 400.0;  // Normalize by flap kick
    return max(-$maxRotation, min($maxRotation, $scale * $maxRotation));
}
```

**Effort:** 2-3 hours  
**Impact:** High (game feel improvement)

### 5.2 Variable Pipe Gap Height (Medium Priority)
**Reference:** flapioca uses dynamic obstacle generation

Currently fixed gap of 6 cells. Vary difficulty based on score.

**Implementation:**
```php
// In Game::advance()
$gapHeight = self::PIPE_GAP;
if ($this->score > 10) {
    $gapHeight = max(4, self::PIPE_GAP - intdiv($this->score - 10, 5));
}
```

**Effort:** 1-2 hours  
**Impact:** Medium (replayability)

### 5.3 Progressive Difficulty (Medium Priority)
**Reference:** flapioca spawn logic

Modify pipe spawn rate and speed based on score.

**Implementation in Game::advance():**
```php
$speedMultiplier = 1.0 + ($this->score * 0.02);  // 2% faster per point
$pipeSpacing = max(12, self::PIPE_EVERY - intdiv($this->score, 5));
```

**Effort:** 2 hours  
**Impact:** Medium

### 5.4 Screen Shake on Collision (Low Priority)
**Reference:** Game feel common pattern

Add brief screen shake when bird crashes.

**Implementation:**
```php
// In Game, add $shakeFrames field
// In Renderer::render, offset output based on shake
```

**Effort:** 3-4 hours  
**Impact:** Low-Medium (juice)

### 5.5 Sound Effects via candy-shell (Low Priority)
**Reference:** Rustfinity plays wing.ogg on flap

Use sugar-bits or candy-shell audio integration for:
- Wing flap sound
- Point scored sound  
- Crash sound

**Effort:** 4-6 hours  
**Impact:** Medium (immersion)

### 5.6 Smooth Pipe Scrolling (Medium Priority)
**Reference:** Continuous movement vs discrete ticks

Currently pipes move 1 cell per tick (discrete). Could use sub-cell positioning.

**Implementation:**
```php
// Pipe.php - Add sub-cell position
public function __construct(
    public readonly float $x,  // float instead of int
    public readonly int $gapY,
    public readonly int $gapHeight,
) {}

// Game::advance() - Move by fractional amounts
$scrollSpeed = 1.5;  // cells per tick
foreach ($pipes as $p) {
    $p = $p->withX($p->x - $scrollSpeed);
}
```

**Effort:** 4-5 hours  
**Impact:** Medium (polish)

### 5.7 Test Coverage for Collision Edge Cases (High Priority)
**Reference:** Current tests are basic

**Missing tests:**
- Bird at exact gap boundary
- Bird passing through gap at high speed
- Multiple simultaneous collisions
- Pipe despawn during collision check

**Effort:** 3-4 hours  
**Impact:** High (reliability)

### 5.8 Velocity-Based Terminal Velocity (Low Priority)
**Reference:** HoneyBounce Projectile::TERMINAL_GRAVITY

Currently gravity is constant. Add terminal velocity cap.

**Implementation:**
```php
// In Bird::flap() or tick(), cap downward velocity
// Add to update: if ($this->body->velocity->y > self::TERMINAL_VELOCITY) { ... }
public const TERMINAL_VELOCITY = 100.0;
```

**Effort:** 2 hours  
**Impact:** Low (subtle physics improvement)

---

## 6. Prioritized Recommendations

### Tier 1: Quick Wins
| # | Improvement | Effort | Impact | Description |
|---|-------------|--------|--------|-------------|
| 1 | **Bird rotation** | 2-3h | High | Velocity-based tilt for game feel |
| 2 | **Collision edge case tests** | 3-4h | High | Reliability for edge cases |
| 3 | **Variable gap height** | 1-2h | Medium | Difficulty scaling |

### Tier 2: Medium Effort
| # | Improvement | Effort | Impact | Description |
|---|-------------|--------|--------|-------------|
| 4 | **Progressive difficulty** | 2h | Medium | Speed/spacing increases with score |
| 5 | **Smooth pipe scrolling** | 4-5h | Medium | Sub-cell pipe movement |

### Tier 3: Nice to Have
| # | Improvement | Effort | Impact | Description |
|---|-------------|--------|--------|-------------|
| 6 | **Screen shake** | 3-4h | Med-Low | Juice on collision |
| 7 | **Sound effects** | 4-6h | Medium | Audio feedback via candy-shell |
| 8 | **Terminal velocity** | 2h | Low | Physics realism |

---

## 7. Key Insights

1. **Physics approach difference:** honey-flap uses continuous physics (HoneyBounce Projectile) vs Rust discrete timestep. The 30fps tick rate with 70.0 gravity gives ~2.3 cells/tick² acceleration.

2. **No rotation in current impl:** Both Go upstream and PHP honey-flap lack bird rotation. Bevy implementations all add velocity-based rotation for game feel.

3. **Collision is simple cell-based:** Works for ASCII but limits visual polish. Rust implementations use AABB/Circle for pixel-perfect detection.

4. **High score persistence is unique to honey-flap:** Neither flapioca nor Rust implementations persist scores.

5. **Test coverage is decent:** 4 test files covering Bird, Game, Pipe, Renderer. Missing edge cases around collision timing.

---

## 8. References

- **Upstream:** [kbrgl/flapioca](https://github.com/kbrgl/flapioca) - Go + Bubble Tea
- **Physics reference:** [rust-adventure/floppy-corgi](https://github.com/rust-adventure/floppy-corgi) - Bevy 0.18
- **Tutorial:** [rustfinity.com Flappy Bird](https://rustfinity.com/tutorials/flappy-rust) - Bevy 0.14
- **Rust impl:** [dangreen07/Flappy-Bird-Rust](https://github.com/dangreen07/Flappy-Bird-Rust)
- **HoneyBounce:** `/home/sites/sugarcraft/honey-bounce/src/Projectile.php`
- **Current tests:** `/home/sites/sugarcraft/honey-flap/tests/`
