# SugarCraft/honey-flap

## Metadata
- **Package**: `sugarcraft/honey-flap`
- **Upstream**: [kbrgl/flapioca](https://github.com/kbrgl/flapioca) (72 stars, Go)
- **Language**: PHP 8.3+
- **Namespace**: `SugarCraft\Flap`
- **Status**: 🟢 v1 ready (public API + tests + docs + demo)
- **Role**: Flappy Bird-style arcade game demonstrating HoneyBounce projectile physics in a TUI

## Overview

honey-flap is a Flappy Bird-inspired terminal game that ports the Go [kbrgl/flapioca](https://github.com/kbrgl/flapioca) ecosystem to PHP using the SugarCraft stack. The bird's vertical motion is powered by **HoneyBounce projectile physics** (gravity + upward velocity kick on flap), pipes scroll left at a fixed cell rate, and collision is per-cell. Difficulty scales with score via shrinking pipe gaps.

The library demonstrates the clean separation between physics (honey-bounce) and TUI rendering (candy-core) — a Flappy Bird clone is effectively a physics consumer with ANSI output.

## Architecture

```
honey-flap/src/
├── Game.php          # TEA Model: world state + init/update/view + game loop
├── Bird.php          # Wraps HoneyBounce Projectile — physics-based vertical motion
├── Pipe.php          # Single-column pipe pair with centred gap
├── PipeGenerator.php # Generates pipes with score-dependent gap height
├── Renderer.php      # Pure view: ANSI-styled playfield + border + HUD
└── TickMsg.php       # Frame-tick message (30 fps via Cmd::tick)

honey-flap/tests/
├── GameTest.php         # 9 tests: init, flap, tick, quit, restart, crash, PRNG
├── BirdTest.php         # 4 tests: spawn, gravity, flap, row rounding
├── PipeTest.php         # 4 tests: tick, collision, off-screen
├── PipeGeneratorTest.php # 6 tests: gap scaling, floor, determinism
└── RendererTest.php     # 5 tests: output structure, crash state

honey-flap/examples/
└── play.php            # Run: php examples/play.php

honey-flap/bin/
└── honey-flap          # CLI entry point (composer bin)
```

### Design Principles

- **Physics-first**: Bird motion is a HoneyBounce `Projectile` with gravity and velocity kick — not integer increment/decrement
- **Immutable state**: Every `update()` / `tick()` / `flap()` returns a new instance
- **PRNG injection**: Deterministic pipe layouts in tests via `\Closure(int): int`
- **Separation of concerns**: `Game` is pure state (no rendering), `Renderer` is pure view (no logic), `Bird` is pure physics (no game knowledge)
- **TEA pattern**: Full `Model::init()/update()/view()` contract via candy-core

## Feature Inventory

| Class | Description | Key Methods |
|-------|-------------|-------------|
| `Game` | TEA Model: bird + pipes + score + crashed + tick counter | `start()`, `init()`, `update()`, `view()`, `tickN()` |
| `Bird` | HoneyBounce Projectile wrapper | `spawn()`, `tick()`, `flap()`, `row()` |
| `Pipe` | Single-column obstacle pair | `tick()`, `collides()`, `isOffScreen()` |
| `PipeGenerator` | Pipe factory with difficulty scaling | `makePipe()`, `gapHeightForScore()` |
| `Renderer` | Pure ANSI view | `render()` |
| `TickMsg` | Frame-tick message (30 fps) | — |

### Game Constants

| Constant | Value | Meaning |
|----------|-------|---------|
| `WIDTH` | 60 | Playfield columns |
| `HEIGHT` | 18 | Playfield rows |
| `BIRD_COL` | 8 | Fixed bird column (world scrolls past) |
| `PIPE_EVERY` | 18 | Ticks between pipe spawns |
| `TICKS_PER_SEC` | 30 | Game loop frequency |

### Physics Constants (Bird.php)

| Constant | Value | Meaning |
|----------|-------|---------|
| `FLAP_KICK` | -22.0 cells/sec | Upward velocity applied on flap |
| `GRAVITY` | 70.0 cells/sec² | Downward acceleration (tuned for game feel, not real-world) |

### Difficulty Scaling

| Score Range | Gap Height | Source |
|-------------|------------|--------|
| 0–4 | 6 cells | `PipeGenerator::GAP_DEFAULT` |
| 5–9 | 5 cells | Shrinks 1 cell per 5 points |
| 10–14 | 4 cells | — |
| 15+ | 3 cells | `PipeGenerator::GAP_MIN` floor |

## HoneyBounce Physics Integration

### Source Files
- **Bird.php**: `honey-flap/src/Bird.php:22-71`
- **Projectile.php**: `honey-bounce/src/Projectile.php:36-120`
- **Spring.php**: `honey-bounce/src/Spring.php:20-144`

### Projectile Configuration

The bird uses a `Projectile` with these parameters:

```php
// honey-flap/src/Bird.php:33-44
public static function spawn(int $x, float $y): self
{
    return new self(
        x: $x,
        body: Projectile::new(
            deltaTime:    Spring::fps(self::TICKS_PER_SEC),  // 1/30 sec
            position:     new Point($x, $y),                // column, row
            velocity:     Vector::zero(),                    // starts still
            acceleration: new Vector(0.0, self::GRAVITY),    // 70 cells/sec² downward
        ),
    );
}
```

### Physics Update (Bird.php:46-48)

```php
public function tick(): self
{
    return new self($this->x, $this->body->update());
}
```

Each tick calls `Projectile::update()` (`honey-bounce/src/Projectile.php:65-70`):

```php
public function update(): self
{
    $newPos = $this->position->add($this->velocity->scale($this->deltaTime));
    $newVel = $this->velocity->add($this->acceleration->scale($this->deltaTime));
    return new self($this->deltaTime, $newPos, $newVel, $this->acceleration);
}
```

This is **Euler integration**:
- `pos += vel * dt` → position advances by velocity over deltaTime
- `vel += acc * dt` → velocity changes by acceleration over deltaTime

### Flap Mechanics (Bird.php:51-65)

```php
public function flap(): self
{
    // Carry the current position, reset vertical velocity to the
    // upward kick. (HoneyBounce projectile has no setter; rebuild
    // a fresh one off the current position.)
    return new self(
        $this->x,
        Projectile::new(
            deltaTime:    Spring::fps(self::TICKS_PER_SEC),
            position:     $this->body->position,  // same position
            velocity:     new Vector(0.0, self::FLAP_KICK),  // -22 upward
            acceleration: new Vector(0.0, self::GRAVITY),
        ),
    );
}
```

**Key insight**: `flap()` doesn't add to velocity — it **replaces** it with a fixed upward kick. The upstream flapioca uses the same trick with an ad-hoc accumulator. honey-flap expresses it cleanly as projectile state.

### Gravity Tuning

`GRAVITY = 70.0 cells/sec²` is deliberately tuned for game feel:

- Real-world gravity: 9.81 m/s²
- honey-flap gravity: 70 cells/sec² ≈ 7.1× real-world (tuned for 30 fps gameplay)
- At 30 fps: `vel += 70 * (1/30) ≈ 2.33` cells/sec per frame
- After 1 second of falling: velocity ≈ 70 cells/sec downward
- After ~9 frames (0.3 sec): bird falls ~3 rows — fast enough to require quick reactions

## Game Loop Architecture

### Source File: `honey-flap/src/Game.php:143-183`

### Initialization (Game.php:143-146)

```php
public function init(): ?\Closure
{
    return Cmd::tick(0.033, static fn() => new TickMsg());
}
```

`Cmd::tick(0.033, ...)` schedules a `TickMsg` to fire in ~33ms (≈30 fps). The closure creates a fresh `TickMsg` each time.

### Update Cycle (Game.php:148-183)

```
KeyMsg (space/w/up/q/r/esc/ctrl-c)
    ↓
isFlap? → Bird::flap() ──────────────────────────────────────→ [Model with new bird, no cmd]
    ↓
TickMsg → advance() ──────────────────────────────────────────→ [Model with tick+1, Cmd::tick(...)]
    ↓
advance() = bird.tick() + pipe.tick() + spawn check + score + collision
    ↓
Cmd::tick(0.033, ...) schedules the next frame
```

### The `advance()` Method (Game.php:190-237)

```php
private function advance(): self
{
    $bird = $this->bird->tick();  // Physics step

    // Slide every pipe left, drop off-screen ones
    $pipes = [];
    foreach ($this->pipes as $p) {
        $next = $p->tick();
        if (!$next->isOffScreen()) {
            $pipes[] = $next;
        }
    }
    // Spawn new pipe every PIPE_EVERY ticks
    $tick = $this->tickIndex + 1;
    if ($tick % self::PIPE_EVERY === 0) {
        $pipes[] = PipeGenerator::makePipe($this->score, $this->rand);
    }

    // Score: count pipes that just passed the bird
    $score = $this->score;
    foreach ($pipes as $p) {
        if ($p->x === self::BIRD_COL - 1) {  // pipe just arrived at bird column
            $score++;
        }
    }

    // Collision detection
    $crashed = $bird->row() < 0 || $bird->row() >= self::HEIGHT;  // floor/ceiling
    if (!$crashed) {
        foreach ($pipes as $p) {
            if ($p->collides($bird->x, $bird->row())) {
                $crashed = true;
                break;
            }
        }
    }

    return new self(bird: $bird, pipes: $pipes, score: $score, crashed: $crashed, tickIndex: $tick, rand: $this->rand);
}
```

### Tick Scheduling (Game.php:179-180)

```php
return [$next, Cmd::tick(0.033, static fn() => new TickMsg())];
```

The game runs **forever** via recursive `Cmd::tick` scheduling. Each `TickMsg` triggers `advance()` and schedules the next tick. This is the canonical ReactPHP/candy-core pattern for game loops.

### Note on Tick Rate vs Upstream

- **flapioca**: `tea.Tick(time.Second/5, ...)` = 5 Hz tick rate (200ms per frame)
- **honey-flap**: `Cmd::tick(0.033, ...)` ≈ 30 Hz tick rate (33ms per frame)

honey-flap is 6× more granular, giving smoother physics and more responsive controls.

## Collision Detection

### Source File: `honey-flap/src/Pipe.php:31-39`

```php
public function collides(int $col, int $row): bool
{
    if ($col !== $this->x) {
        return false;
    }
    $top    = $this->gapY - intdiv($this->gapHeight, 2);
    $bottom = $this->gapY + intdiv($this->gapHeight, 2);
    return $row < $top || $row > $bottom;
}
```

**Algorithm**:
1. Check if bird is in the same column as the pipe
2. Compute gap boundaries: gap is centred at `gapY` with half-height `intdiv($gapHeight, 2)`
3. Bird collides if row is **above the top** OR **below the bottom** of the gap
4. Rows inside `[top, bottom]` (inclusive) are the open air — no collision

**Example** (gapY=8, gapHeight=6):
- `top = 8 - 3 = 5`
- `bottom = 8 + 3 = 11`
- `collides(10, 0)` → `0 < 5` → true (above gap)
- `collides(10, 7)` → `7 >= 5 && 7 <= 11` → false (inside gap)
- `collides(10, 12)` → `12 > 11` → true (below gap)

### Collision Sources in Game.php

```php
// Game.php:218-227
$crashed = $bird->row() < 0 || $bird->row() >= self::HEIGHT;  // boundary crash
if (!$crashed) {
    foreach ($pipes as $p) {
        if ($p->collides($bird->x, $bird->row())) {
            $crashed = true;
            break;
        }
    }
}
```

Boundary crash: bird row < 0 (off top) or >= HEIGHT (off bottom).
Pipe collision: per-pipe check with bird at column `BIRD_COL` (8) and row `bird.row()`.

## Rendering

### Source File: `honey-flap/src/Renderer.php:18-82`

Renderer is a **pure view function** — no state, no side effects:

```php
public static function render(Game $g): string
```

### Output Structure

```
┌──────────────────────────────────────────────────────────────┐
│  (60 chars wide, 18 rows tall, rounded border, amber)       │
│                                                              │
│  Background dots (parallax: every 12 cols, every 5 rows)   │
│  Bird glyph: '>' (amber, bold) at (BIRD_COL, birdRow)        │
│  Pipe glyph: '▓' (green) where collides() is true          │
│                                                              │
└──────────────────────────────────────────────────────────────┘
 score: 0      space / ↑ / w  flap   ·   q  quit
```

### Cell Glyph Logic (Renderer.php:62-82)

```php
private static function cellGlyph(Game $g, int $x, int $y, int $birdX, int $birdRow): string
{
    // Bird glyph wins over everything.
    if ($x === $birdX && $y === $birdRow) {
        return Style::new()->foreground(Color::hex('#fde68a'))->bold()->render('>');
    }
    // Pipe walls.
    foreach ($g->pipes as $p) {
        if ($p->x !== $x) continue;
        if ($p->collides($x, $y)) {
            return Style::new()->foreground(Color::hex('#6ee7b7'))->render('▓');
        }
        // Inside the gap: just air.
        return ' ';
    }
    // Background — leading column gets a subtle dot every 4 rows for parallax.
    if (($x + $g->tickIndex) % 12 === 0 && ($y % 5) === 2) {
        return Style::new()->foreground(Color::hex('#3a2c5a'))->render('·');
    }
    return ' ';
}
```

**Rendering order** (first match wins):
1. Bird cell — always renders `>` at bird position
2. Pipe cell — renders `▓` if `collides()` is true, else ` ` (air in gap)
3. Background — parallax dot every 12 columns offset by tickIndex
4. Empty cell

### Score Display (Renderer.php:40-59)

```php
$score = Style::new()->bold()->foreground(Color::hex('#fde68a'))
    ->render("score: {$g->score}");
```

When crashed:
- If new high score: green highlight + trophy emoji
- If not new but has high score: muted "best: N"  
- Always shows crash hint: "💥 splat — press r to flap again, q to quit"

## Comparison with Upstream (kbrgl/flapioca)

| Aspect | flapioca (Go) | honey-flap (PHP) | Advantage |
|--------|--------------|-----------------|------------|
| Framework | Bubble Tea | candy-core (ReactPHP) | Both use TEA pattern |
| Physics | Integer increment | HoneyBounce Projectile | honey-flap |
| Bird motion | `Cursor.Y++` each tick | Projectile with gravity + flap kick | honey-flap |
| Gravity | None (integer stepping) | 70 cells/sec² | honey-flap |
| Tick rate | 5 Hz | 30 Hz | honey-flap |
| Difficulty scaling | None | Gap shrinks 6→3 every 5 points | honey-flap |
| High score persistence | None | JSON to ~/.config | honey-flap |
| Test determinism | N/A | PRNG injection | honey-flap |
| Pipe gap | Fixed aperture (5) | Score-dependent (3–6) | honey-flap |
| State model | Mutable struct | Immutable with() pattern | honey-flap |

### Key Upstream File: `internal/model.go`

```go
// flapioca:model.go:79-82
if !m.Pressed {
    m.Cursor.Y++  // No physics — just integer increment
}
// ...
m.Cursor.Y--  // On flap, just decrement Y
```

flapioca uses integer stepping for bird position. The "Pressed" lock prevents multiple decrements per tick, but there's no concept of gravity, velocity, or momentum.

### Collision Algorithm Comparison

**flapioca** (`internal/obstacle.go:16-20`):
```go
func (o *Obstacle) Collides(l Location) bool {
    sameColumn := o.X == l.X
    radius := o.Aperture / 2
    inAperture := abs(o.Y-l.Y) <= radius
    return sameColumn && !inAperture
}
```

**honey-flap** (`honey-flap/src/Pipe.php:31-39`):
```php
public function collides(int $col, int $row): bool
{
    if ($col !== $this->x) {
        return false;
    }
    $top    = $this->gapY - intdiv($this->gapHeight, 2);
    $bottom = $this->gapY + intdiv($this->gapHeight, 2);
    return $row < $top || $row > $bottom;
}
```

Both use the same algorithm (column check + gap boundary check). honey-flap uses `intdiv()` for integer gap halves; flapioca uses integer division implicitly. Minor implementation difference only.

## Innovation Analysis

### 1. Physics Integration Pattern

honey-flap demonstrates a **physics-as-a-service** pattern:
- `honey-bounce/Projectile` is a pure kinematics engine (no rendering, no collision)
- `Game` is a physics consumer that wraps physics state in a TEA Model
- `Renderer` consumes physics state to produce ANSI output

This separation means honey-bounce physics can power other games without game-specific knowledge. The pattern is:

```php
// 1. Physics layer (framework-agnostic)
$bird = Bird::spawn(col: 8, row: 9.0);
$bird = $bird->tick();  // Projectile::update() with gravity

// 2. Game logic layer (physics-aware)
$crashed = $bird->row() < 0 || $bird->row() >= self::HEIGHT;
foreach ($pipes as $p) {
    if ($p->collides($bird->x, $bird->row())) { $crashed = true; }
}

// 3. View layer (game-aware)
return Renderer::render($this);  // ANSI output
```

### 2. PRNG Injection for Deterministic Tests

**Source**: `honey-flap/src/Game.php:35-36, 52-55, 75-78`

```php
private \Closure $rand;

public function __construct(
    ...
    ?\Closure $rand = null,
    ...
) {
    $this->rand = $rand ?? static fn(int $max): int => random_int(0, $max);
    ...
}
```

Tests inject a deterministic PRNG to pin pipe layouts:

```php
// GameTest.php:83-91
public function testDeterministicWithSeededRand(): void
{
    $rand = static fn(int $max): int => intdiv($max, 2);
    $a = Game::start($rand)->tickN(60);
    $b = Game::start($rand)->tickN(60);
    // Both runs produce identical pipe layouts
}
```

### 3. Difficulty Curve via Gap Shrinkage

**Source**: `honey-flap/src/PipeGenerator.php:33-38`

```php
public static function gapHeightForScore(int $score): int
{
    $shrinkCount = intdiv($score, self::GAP_SHRINK_INTERVAL);  // 5 points per shrink
    $gap = self::GAP_DEFAULT - ($shrinkCount * self::GAP_SHRINK_STEP);  // -1 per shrink
    return max($gap, self::GAP_MIN);  // Floor at 3
}
```

The gap shrinks by 1 cell every 5 points, starting at 6, floor at 3. This creates a smooth difficulty curve that keeps the game challenging without making it impossible.

### 4. Immurable Game State

**Source**: `honey-flap/src/Game.php:229-248`

Every state transition returns a new `Game` instance. The previous state is preserved for:
- Debugging / replay
- Undo functionality (future)
- Testing via `tickN()` helper

## Test Coverage

| Test File | Tests | Coverage |
|----------|-------|----------|
| `GameTest.php` | 9 | PRNG, flap, restart, high score, crash, deterministic layout |
| `BirdTest.php` | 4 | Gravity pull, flap kick, row rounding |
| `PipeTest.php` | 4 | Tick shift, collision at column/gap boundary |
| `PipeGeneratorTest.php` | 6 | Gap scaling floor, score intervals, determinism |
| `RendererTest.php` | 5 | Bird glyph, score display, crash hint, dimensions |
| **Total** | **28** | All public methods covered |

### Key Test Patterns

**Behavior test** (GameTest.php:34-46):
```php
public function testFlapResetsVelocity(): void
{
    $g = Game::start(static fn(int $max): int => 0);
    $beforeY = $g->bird->row();
    $dropped = $g->tickN(6);  // Without flap — bird falls
    $this->assertGreaterThan($beforeY, $dropped->bird->row());
    $msg = new KeyMsg(KeyType::Space, '');
    [$g, ] = $g->update($msg);  // Flap
    $afterFlap = $g->tickN(6);
    $this->assertLessThan($dropped->bird->row(), $afterFlap->bird->row());  // Higher than no-flap
}
```

**Coercion test** (PipeTest.php:26-37):
```php
public function testCellsAboveAndBelowGapCollide(): void
{
    $p = new Pipe(x: 10, gapY: 8, gapHeight: 6);
    $this->assertTrue($p->collides(10, 0));    // way above
    $this->assertTrue($p->collides(10, 4));    // just above gap (y=4 < top=5)
    $this->assertFalse($p->collides(10, 5));  // top of gap
    $this->assertFalse($p->collides(10, 11)); // bottom of gap
    $this->assertTrue($p->collides(10, 12));  // just below gap (y=12 > bottom=11)
}
```

## Files Reference

| File | Purpose |
|------|---------|
| `honey-flap/src/Game.php` | TEA Model: 265 lines, all game state + logic |
| `honey-flap/src/Bird.php` | HoneyBounce Projectile wrapper: 71 lines |
| `honey-flap/src/Pipe.php` | Pipe obstacle: 45 lines |
| `honey-flap/src/PipeGenerator.php` | Pipe factory + difficulty scaling: 56 lines |
| `honey-flap/src/Renderer.php` | Pure ANSI view: 83 lines |
| `honey-flap/src/TickMsg.php` | Frame-tick message: 17 lines |
| `honey-bounce/src/Projectile.php` | Euler-integrated physics: 120 lines |
| `honey-bounce/src/Spring.php` | Damped spring oscillator: 144 lines |
| `honey-bounce/src/Point.php` | 3D immutable point: 41 lines |
| `honey-bounce/src/Vector.php` | 3D immutable vector: 76 lines |

## Strengths

1. **Clean physics integration**: Bird motion correctly uses HoneyBounce Projectile — gravity, velocity kick, 30fps timestep
2. **Proper TEA pattern**: Full `Model::init()/update()/view()` contract, testable without rendering
3. **Deterministic tests**: PRNG injection enables reproducible pipe layouts
4. **Difficulty scaling**: Gap shrinkage keeps game challenging without becoming impossible
5. **Immutable state**: All mutations return new instances — no spooky action
6. **High score persistence**: JSON-based score tracking across sessions
7. **Real arcade feel**: 30fps physics vs upstream's 5Hz — much more responsive
8. **Separation of concerns**: Physics layer (honey-bounce) is framework-agnostic

## Weaknesses

1. **No sound**: Classic Flappy Bird had audio feedback (flap, score, crash)
2. **No animation**: Bird is always `>` — no wing-flap animation during fall/rise
3. **Pipe gap is integer cells**: Real physics would use float positions and sub-cell collision
4. **No pause**: Game runs continuously until quit or crash
5. **Single player**: No multi-player or leaderboard comparison
6. **Terminal-dependent rendering**: Assumes ANSI SGR + Unicode support
7. **No high score sync**: JSON file only, no cloud backup
8. **Euler integration drift**: First-order Euler accumulates error over many frames (minor at 30fps)

## Comparison with Other Game Clones

### kbrgl/flapioca (Go, 72 stars)
- **Physics**: None (integer stepping)
- **Tick rate**: 5 Hz
- **Difficulty**: Fixed gap aperture (5)
- **High score**: None
- **Verdict**: honey-flap is a strict superset with physics, 6× faster tick, difficulty scaling

### Other TUI Games in Ecosystem

The SugarCraft monorepo contains other game-related ports:
- **candy-tetris**: Tetris clone (no physics library use)
- **sugar-crush**: Match-3 game (no physics)

honey-flap is unique in its use of honey-bounce physics for game motion — all other games use integer-grid movement only.

## Potential Enhancements

### Short-term (v1.x)
1. **Wing animation**: Alternate bird glyph `>` / `<` based on velocity direction (rising vs falling)
2. **Sound effects**: Play ANSI bell + speak text on flap/score/crash via `exec('printf \\a')`
3. **Particle trail**: Render trailing dots when bird is moving fast

### Medium-term (v2.0)
1. **Mutable Bird physics**: Add `ProjectileMutable` variant for zero-allocation per-tick (game use case)
2. **Animated death**: Show bird rotation + fall when crashing
3. **Pause/resume**: Space bar toggles pause (stops tick scheduling)
4. **High score API**: Optional HTTP POST to submit scores to a leaderboard endpoint
5. **Pipe variety**: Different pipe colors/styles, moving obstacles, wind gusts

### Long-term
1. **Verlet integration**: Upgrade from Euler to Verlet for better numerical stability
2. **Physics-based level elements**: Moving platforms, bouncy pads, wind zones
3. **Particle system**: Explosion on crash, confetti on new high score
4. **Multiplayer**: Local two-player via split-screen or networked via WebSocket
