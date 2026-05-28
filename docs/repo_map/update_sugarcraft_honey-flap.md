# SugarCraft/honey-flap — Ecosystem Update Report

## Overview

honey-flap is a Flappy Bird-style arcade game that demonstrates HoneyBounce projectile physics in a TUI context. It ports the Go [kbrgl/flapioca](https://github.com/kbrgl/flapioca) ecosystem to PHP, running on the SugarCraft stack. The package is production-ready (🟢 v1) with 28 passing tests, a VHS demo, and full documentation.

**Biggest opportunities:**
1. Wing animation — the bird has no visual state change during flap/fall (always `>` glyph)
2. Audio feedback — flap, score, and crash lack sound effects (classic Flappy Bird had all three)
3. Mutable physics variant — per-tick allocation via `Projectile::update()` could be avoided with a mutable builder for game-critical paths

**Biggest missing capabilities:**
1. No pause/resume functionality
2. No sub-cell collision (pipe gaps are integer cells, bird position is rounded)
3. No death animation (bird just stops and shows "💥 splat")

---

## Internal Capability Summary

### Current Architecture

```
honey-flap/src/
├── Game.php          # TEA Model: world state + init/update/view + game loop (265 lines)
├── Bird.php          # Wraps HoneyBounce Projectile — physics-based vertical motion (71 lines)
├── Pipe.php          # Single-column pipe pair with centred gap (45 lines)
├── PipeGenerator.php # Generates pipes with score-dependent gap height (56 lines)
├── Renderer.php      # Pure view: ANSI-styled playfield + border + HUD (83 lines)
└── TickMsg.php       # Frame-tick message (30 fps via Cmd::tick) (17 lines)

honey-flap/tests/     # 28 tests across 5 test files
honey-flap/examples/  # play.php — run via `php examples/play.php`
honey-flap/bin/       # honey-flap CLI entry point (composer bin)
```

### Features

| Feature | Status | Notes |
|---------|--------|-------|
| HoneyBounce physics | ✅ Implemented | Gravity 70 cells/sec², flap kick -22 cells/sec |
| 30 fps game loop | ✅ Implemented | Via `Cmd::tick(0.033, ...)` |
| Difficulty scaling | ✅ Implemented | Gap shrinks 6→3 every 5 points |
| PRNG injection | ✅ Implemented | Enables deterministic tests |
| High score persistence | ✅ Implemented | JSON to ~/.config |
| TEA pattern | ✅ Implemented | Full `Model::init()/update()/view()` contract |
| Immutable state | ✅ Implemented | Every mutation returns new instance |
| Collision detection | ✅ Implemented | Per-cell, column + gap boundary check |
| Wing animation | ❌ Missing | Bird glyph is always `>` |
| Audio feedback | ❌ Missing | No sound on flap/score/crash |
| Pause/resume | ❌ Missing | Game runs until quit/crash |
| Death animation | ❌ Missing | Bird just stops, no rotation/fall |
| Sub-cell collision | ⚠️ Limited | Integer cells only |
| Verlet integration | ⚠️ Not planned | Currently Euler (first-order) |

### Strengths

1. **Physics-first design**: Bird motion uses HoneyBounce `Projectile` with gravity and velocity kick — not integer increment/decrement like upstream flapioca
2. **Proper TEA pattern**: Full `Model::init()/update()/view()` contract, testable without rendering
3. **Deterministic tests**: PRNG injection enables reproducible pipe layouts
4. **Difficulty scaling**: Gap shrinkage keeps game challenging without becoming impossible
5. **Immutable state**: All mutations return new instances — no spooky action
6. **6× faster tick rate than upstream**: 30 Hz vs upstream's 5 Hz
7. **Clean separation**: Physics (honey-bounce) is framework-agnostic, Game is physics-aware, Renderer is game-aware

### Weaknesses

1. **No wing animation**: Bird is always `>` — no visual feedback for rising vs falling
2. **No sound**: Classic Flappy Bird had audio feedback
3. **No pause**: Game runs until quit or crash
4. **Integer cell collision**: Real physics would use float positions and sub-cell collision
5. **Euler integration drift**: First-order Euler accumulates error over many frames (minor at 30fps)
6. **Single player**: No multi-player or leaderboard comparison
7. **No high score sync**: JSON file only, no cloud backup

---

## Relevant External Repositories

| Repo | Relevance | Major applicable concepts | Priority |
|------|-----------|--------------------------|----------|
| `charmbracelet/bubbletea` | Critical | TEA pattern, Cmd/Tick subscriptions, `Program::Run()` | P0 |
| `charmbracelet/vhs` | High | `.tape` DSL format for demo recording | P1 |
| `rasjonell/dashbrew` | Medium | Layout system, flexbox algorithm, bounding-box navigation | P2 |
| `ratatui/ratatui` | Medium | Cassowary constraint solver layout, immediate-mode rendering, buffer diffing | P2 |
| `pterm/pterm` | Medium | AreaPrinter for dynamic content, progress bars, RGB gradients | P2 |
| `blacktop/go-termimg` | Low | Image rendering protocols (Kitty/Sixel/iTerm2) | P3 |
| `charmbracelet/glow` | Low | Markdown rendering, adaptive colors, file watching | P3 |

**Rationale for priority assignments:**
- **P0**: Bubble Tea is the upstream framework; understanding its Cmd/Tick/Subscription patterns is essential for comparing honey-flap's game loop architecture
- **P1**: VHS provides the demo recording infrastructure used by the SugarCraft ecosystem
- **P2**: Dashbrew, Ratatui, and pterm all demonstrate sophisticated rendering patterns potentially applicable to game development
- **P3**: Low relevance for honey-flap specifically; more relevant to general TUI component development

---

## Feature Gap Analysis

### Critical

| # | Title | Description | Why it matters | Source repo | Implementation ideas | Complexity | Impact |
|---|-------|-------------|----------------|------------|---------------------|------------|--------|
| 1 | Wing animation | Bird glyph changes based on velocity direction | Provides visual feedback for rising vs falling — core to the Flappy Bird feel | `docs/repo_map/sugarcraft_honey-flap.md:606` | Track `velocity.y` sign in Bird; render `>` (rising) vs `<` (falling) vs `-` (flap frame); alternate glyph every 2-3 ticks during flap recovery | Low | High |

### High

| # | Title | Description | Why it matters | Source repo | Implementation ideas | Complexity | Impact |
|---|-------|-------------|----------------|------------|---------------------|------------|--------|
| 2 | Audio feedback | Play sounds on flap/score/crash | Classic Flappy Bird had distinct sounds for each action; completes the arcade feel | `docs/repo_map/sugarcraft_honey-flap.md:606` | Use `exec('printf \\a')` for terminal bell on flap, distinct sequences for score (+1 point sound) and crash; platform via `candy-pty` for cross-platform | Low | High |
| 3 | Pause/resume | Space bar toggles pause when alive | Allows player to take a break mid-game without losing progress | `docs/repo_map/sugarcraft_honey-flap.md:612` | Add `paused` field to Game; when paused, `update(TickMsg)` returns `[self, null]` (no advance); render "PAUSED" overlay; 'p' key toggles | Low | Medium |

### Medium

| # | Title | Description | Why it matters | Source repo | Implementation ideas | Complexity | Impact |
|---|-------|-------------|----------------|------------|---------------------|------------|--------|
| 4 | Death animation | Show bird rotation + fall when crashing | Makes crash more satisfying/legible; common in Flappy Bird clones | `docs/repo_map/sugarcraft_honey-flap.md:612` | Add `Bird::die(): self` that starts a fall animation; track `deathTickCount`; render bird glyph rotated ( `/` then `|` then `\`) over 10-15 frames as it falls off screen | Medium | Medium |
| 5 | Mutable physics variant | Add `ProjectileMutable` for zero-allocation per-tick | Game loop creates new `Projectile` every tick (30/sec); a mutable variant could reduce GC pressure | `docs/repo_map/sugarcraft_honey-flap.md:611` | Add `Projectile::updateInPlace(self): void` that mutates position/velocity fields; honey-flap's `Bird::tick()` would call this instead of creating new instance | Medium | Medium |
| 6 | High score API | Optional HTTP POST to submit scores to a leaderboard endpoint | Enables competitive play across sessions/users | `docs/repo_map/sugarcraft_honey-flap.md:612` | Add `Game::submitScore(int $score): ?Closure` that returns a Cmd doing HTTP POST to configurable endpoint; store endpoint in config file | Medium | Medium |
| 7 | Pipe variety | Different pipe colors, moving obstacles, wind gusts | Increases visual interest and gameplay variety | `docs/repo_map/sugarcraft_honey-flap.md:615` | Add `PipeStyle` enum (green/yellow/red); randomly select style per pipe; wind gusts would apply horizontal force to bird via `Projectile::applyForce()` | High | Low |

### Low

| # | Title | Description | Why it matters | Source repo | Implementation ideas | Complexity | Impact |
|---|-------|-------------|----------------|------------|---------------------|------------|--------|
| 8 | Particle trail | Render trailing dots when bird is moving fast | Visual flourish that makes fast movement more legible | `docs/repo_map/sugarcraft_honey-flap.md:607` | Track last 3-5 bird positions; render small `·` glyphs at those positions with decreasing opacity | Low | Low |
| 9 | Verlet integration | Upgrade from Euler to Verlet for better numerical stability | Euler integration accumulates error; Verlet is more stable for game physics | `docs/repo_map/sugarcraft_honey-flap.md:618` | Add `ProjectileVerlet` class: stores current + previous position, computes velocity as `pos - prevPos`; `update()` becomes position update only | High | Medium |
| 10 | Multiplayer | Local two-player via split-screen or networked via WebSocket | Extends game beyond single-player experience | `docs/repo_map/sugarcraft_honey-flap.md:618` | Split-screen: render two birds at different columns; networked: use ReactPHP WebSocket for real-time multi-player sync | High | Low |
| 11 | Physics-based level elements | Moving platforms, bouncy pads, wind zones | Demonstrates honey-bounce versatility beyond simple projectile | `docs/repo_map/sugarcraft_honey-flap.md:619` | Add `BouncyPad` class that applies upward force on collision; `WindZone` applies horizontal acceleration; both implement `PhysicsBody` interface | Medium | Low |
| 12 | Particle system | Explosion on crash, confetti on new high score | Visual celebration for key moments | `docs/repo_map/sugarcraft_honey-flap.md:620` | On crash: spawn 8-12 particles at bird position with random velocity vectors; render as `*` glyphs; on high score: spawn confetti from top of screen | Medium | Low |

---

## Algorithm / Performance Opportunities

### Current approach vs external

| Aspect | honey-flap | External approach | Why external better | Tradeoffs | Applicability |
|--------|-----------|-------------------|-------------------|----------|---------------|
| Physics integration | Euler: `newPos = pos + vel * dt; newVel = vel + acc * dt` | Verlet: stores `pos(t-1)` and computes `vel = pos(t) - pos(t-1)` | Verlet is more numerically stable, no velocity storage needed, symmetric for collision | More complex to implement; Euler is sufficient at 30fps | Medium — would require new `ProjectileVerlet` class |
| Rendering | Immediate-mode string concatenation per frame | Ratatui: buffer diffing (only changed cells written) | Buffer diffing reduces terminal I/O by 10-100× for mostly-static frames | Would require refactoring Renderer to build buffer then diff | Low — game is already fully dynamic per frame |
| Layout algorithm | Fixed cell grid | Dashbrew: recursive flexbox with AABB bounding boxes | Flexbox adapts to any terminal size; AABB enables click-to-focus and arrow navigation | Requires significant architecture change; game is fixed-size | Low — game intentionally fixed-size for arcade feel |
| Terminal image rendering | Unicode block characters (via candy-mosaic) | go-termimg: Kitty/Sixel/iTerm2 protocols | Modern protocols support actual images; halfblocks is fallback | Requires platform-specific protocol support; not relevant for game | None — game uses ASCII, not images |

### Key insight

The most impactful algorithm improvement is **mutable physics for the game loop**. Currently `Bird::tick()` creates a new `Projectile` instance every frame via `Projectile::update()`. A mutable variant using in-place updates could eliminate 30+ allocations/second:

```php
// Current (allocation per tick):
public function tick(): self {
    return new self($this->x, $this->body->update());
}

// Mutable variant (no allocation):
public function tickInPlace(): void {
    $this->body->updateInPlace();
}
```

This would require a `ProjectileMutable` or `Projectile::updateInPlace()` method — see `docs/repo_map/sugarcraft_honey-bounce.md` for HoneyBounce internals.

---

## Architecture Improvements

1. **Extract `PhysicsBody` interface**: Define a common interface for `Bird`, `Pipe` (future: `BouncyPad`, `WindZone`) that exposes position and collision bounds. This enables a unified collision detection system and demonstrates the Strategy pattern.

2. **Add `GameState` enum**: Replace boolean `crashed` with `GameState::Playing | GameState::Paused | GameState::Dead`, enabling proper state machine transitions (e.g., `Dead` can transition to `Playing` via restart, but `Paused` cannot directly go to `Dead`).

3. **Introduce `Renderer::buffer()`**: Add a method that returns the raw 2D cell grid (before ANSI styling) so other renderers (image exporters, replay systems) can process the game state without duplicating logic.

4. **Decouple physics from game constants**: Move `FLAP_KICK`, `GRAVITY`, `TICKS_PER_SEC` to a configurable `PhysicsConfig` passed to `Bird::spawn()`. This enables different difficulty modes and makes the physics reusable for other games.

---

## API / Developer Experience Improvements

1. **Add `Game::scoreIncrement(): int` accessor** — Currently score is derived from pipe passage; exposing the increment allows custom scoring rules (e.g., bonus for narrow gaps).

2. **Add `Bird::velocityY(): float`** — Currently only `row()` (rounded integer) is public. Exposing `velocityY()` enables renderers to show a speedometer or apply velocity-based glyph selection.

3. **Add `Pipe::gapTop()` and `Pipe::gapBottom()` accessors** — These are computed in `collides()` but not publicly accessible. They could be useful for debug output or scoring.

4. **Add `Game::tickRate(): float`** — Returns the configured tick rate (currently hardcoded 0.033). Allows renderers to display FPS.

5. **Add `PipeGenerator::setGapRange(int $min, int $max)` — Currently gap range is hardcoded (6-3). Making it configurable enables custom difficulty modes and broader testing of edge cases.

---

## Documentation / Cookbook Opportunities

1. **"Building a physics-based TUI game" tutorial**: Walking through honey-flap's architecture, explaining how HoneyBounce physics integrates with a TEA model. Would cover:
   - Designing the physics layer (honey-bounce)
   - Wrapping physics state in a Model (Game)
   - Building a pure view (Renderer)
   - Writing deterministic tests with PRNG injection

2. **"Porting Go TEA apps to PHP" guide**: Using honey-flap (ported from flapioca) as a case study. Covers:
   - Mapping Go structs to PHP classes
   - Translating goroutine-based commands to ReactPHP callbacks
   - Handling mutable vs immutable state differences

3. **"Physics comparison: Euler vs Verlet" explainer**: Demonstrates the numerical difference between Euler and Verlet integration with visualizations. Could use honey-flap's Bird as the example, showing trajectory divergence over 1000+ frames.

4. **"Adding sound to TUI games" how-to**: Using honey-flap's (future) audio support as the example, covering:
   - Platform-independent sound via `exec('printf \\a')`
   - Using candy-pty for more sophisticated audio
   - Timing sounds with the game loop

---

## UX / TUI Improvements

1. **Add pause screen overlay**: When paused, render a semi-transparent overlay with "PAUSED — press p to resume" centered in the playfield. Uses `Style` with background color and centered text.

2. **Add velocity-based bird glyph**: As described in Feature Gap #1, show different glyphs:
   - `>` (amber, bold) — rising or stationary
   - `v` (amber, bold) — falling fast
   - `~` (amber) — gentle fall (low velocity magnitude)

3. **Add score pop animation**: When scoring, briefly flash the score number larger/inverted for 3-5 frames. This gives positive feedback beyond just updating the number.

4. **Add parallax background layers**: Currently there's a single dot parallax layer. Adding 2-3 layers with different scroll speeds creates depth perception — a common technique in 2D games.

5. **Add intro title screen**: Before the game starts, show "HONEYFLAP" in big ASCII art with "Press SPACE to start". This establishes the game's identity and gives players time to prepare.

---

## Testing / Reliability Improvements

1. **Add collision boundary snapshot tests**: Currently `PipeTest::testCellsAboveAndBelowGapCollide` tests specific cells. Add snapshot tests that verify the entire collision map (every cell in playfield) matches expected pattern for a given Pipe configuration.

2. **Add physics trajectory tests**: Test that `Bird` follows expected parabolic trajectory under gravity. Compare actual `row()` values at tick N against precomputed expected values.

3. **Add difficulty curve tests**: Test that `gapHeightForScore()` returns expected values for all score values 0-100. This ensures the difficulty curve is monotonic and floors correctly.

4. **Add render output snapshot tests**: Currently `RendererTest` only checks for string containment. Add tests that verify exact ANSI output bytes using `assertSame($expected, Renderer::render($game))`. This guards against accidental style changes.

5. **Add integration test with real PRNG**: Test the game with `random_int()` (non-deterministic) to verify behavior in production conditions. Use a mock time source if possible to make the test deterministic.

6. **Add performance regression test**: Verify that a 60-second game (1800 ticks) completes in under 1 second of CPU time. This guards against accidental O(n²) behavior as pipe count grows.

---

## Ecosystem / Integration Opportunities

1. **Publish `honey-flap` as a standalone Composer package**: Currently it lives in the monorepo. Publishing to packagist.org would increase visibility and allow non-monorepo PHP projects to use it as a demo/showcase for SugarCraft physics.

2. **Add `honey-flap` to SugarCraft website**: Create a demo page on sugarcraft.example.com that renders the game in a live terminal emulator (using go-termimg-style protocols or a web-based terminal). This could be powered by candy-mosaic for the fallback rendering.

3. **Create `honey-bounce` game examples package**: A new `honey-games` package (or `sugar-games`) that ports multiple physics-based games (Flappy Bird, Angry Birds-style slingshot, space invaders with gravity) demonstrating honey-bounce versatility.

4. **Integrate with `sugar-charts` for score analytics**: Track session scores over time and render charts showing score distribution, high score progression, etc. This could use the existing sugar-charts library.

5. **Add `candy-mosaic` integration for image rendering**: If the terminal supports it (Kitty/iTerm2/Sixel), render the bird as a small sprite image instead of a text glyph. This would use candy-mosaic's halfblocks fallback and could be extended to support protocol-specific rendering.

---

## Notable PRs / Issues / Discussions

### Relevant discussions from upstream (kbrgl/flapioca)

- **No physics**: flapioca uses integer stepping for bird position (just `Cursor.Y++` each tick). honey-flap's physics-first approach is a meaningful improvement. No upstream issues directly, but the approach was validated by the upstream's simplicity.

- **Discussion on tick rate**: flapioca runs at 5 Hz. honey-flap runs at 30 Hz (6× more granular). This is a deliberate design choice that trades off per-frame CPU time for smoother physics. The ReactPHP event loop handles this naturally.

### Lessons from ecosystem analysis

1. **Bubble Tea's Cmd pattern is the right abstraction**: The `Cmd::tick()` pattern used in honey-flap mirrors Bubble Tea's `tea.Every()` / `tea.Tick()`. This was validated by the Bubble Tea documentation (`docs/repo_map/charmbracelet_bubbletea.md:100-112`) which shows the same pattern.

2. **PRNG injection is a SugarCraft convention**: Both honey-flap and dashbrew (via different mechanisms) achieve deterministic tests. honey-flap uses `Closure(int): int` injection; dashbrew uses explicit seed in config. The injection approach is cleaner.

3. **Immutable state is the right default**: Every framework analyzed (Bubble Tea, Ratatui, pterm) eventually converges on immutable state for testability. honey-flap's `with*()` pattern and new-instance-per-mutation is the correct approach.

4. **Buffer diffing matters at scale**: Ratatui's immediate-mode rendering with buffer diffing (`docs/repo_map/ratatui_ratatui.md:95-109`) is highly efficient for complex UIs but less critical for games (which redraw everything each frame). honey-flap's current approach is appropriate for its use case.

---

## Recommended Roadmap

### Immediate wins (v1.x)

1. **Wing animation** — Low complexity, high impact. Track velocity direction in Bird; render different glyphs based on rising/falling state.
2. **Audio feedback** — Low complexity, completes arcade feel. Add terminal bell sounds on flap/score/crash using `exec('printf \\a')`.
3. **Pause/resume** — Low complexity, improves UX. Add `paused` state to Game; 'p' key toggles; render "PAUSED" overlay.

### Medium-term (v2.0)

1. **Death animation** — Medium complexity. Add death sequence with bird rotation during fall.
2. **Mutable physics variant** — Medium complexity. Add `Projectile::updateInPlace()` for zero-allocation game loop.
3. **High score API** — Medium complexity. Add optional HTTP POST to submit scores to a leaderboard endpoint.
4. **Pipe variety** — High complexity, low impact currently. Add colored pipes as a visual enhancement.

### Major upgrades (v3.0)

1. **Verlet integration** — High complexity. Replace Euler with Verlet for better numerical stability.
2. **Physics-based level elements** — Medium complexity. Add bouncy pads and wind zones using honey-bounce physics.
3. **Particle system** — Medium complexity. Explosion on crash, confetti on high score.

### Experimental

1. **Multiplayer via WebSocket** — High complexity, low current priority.
2. **Terminal image rendering** — High complexity, requires candy-mosaic protocol support.
3. **Mobile companion app** — High complexity, separate project.

---

## Priority Matrix

| Opportunity | Impact | Complexity | Risk | Priority |
|------------|--------|------------|------|----------|
| Wing animation | High | Low | Low | **P0** |
| Audio feedback | High | Low | Low | **P0** |
| Pause/resume | Medium | Low | Low | **P1** |
| Death animation | Medium | Medium | Low | **P1** |
| Mutable physics variant | Medium | Medium | Medium | **P2** |
| High score API | Medium | Medium | Low | **P2** |
| Velocity-based glyph | Medium | Low | Low | **P1** |
| Pipe variety | Low | High | Low | **P3** |
| Verlet integration | Medium | High | Medium | **P3** |
| Physics-based level elements | Low | Medium | Low | **P3** |
| Particle system | Low | Medium | Low | **P3** |
| Multiplayer | Low | High | High | **P4** |

---

## Final Strategic Assessment

honey-flap is a **well-architected, production-ready** package that demonstrates the SugarCraft stack's capability for physics-based TUI games. Its use of HoneyBounce for projectile physics is a genuine differentiator — no other Flappy Bird clone in the TUI ecosystem uses real physics simulation.

**Key differentiators from upstream (flapioca):**
- Real physics (gravity + velocity kick) vs integer stepping
- 6× faster tick rate (30 Hz vs 5 Hz)
- Difficulty scaling (gap shrinkage)
- High score persistence
- Comprehensive test suite with deterministic PRNG

**Areas where honey-flap could lead the ecosystem:**
1. Physics-based game development — honey-flap + honey-bounce establishes a pattern for physics TUI games in PHP
2. Sound integration — adding audio feedback to TUI games is underexplored in the SugarCraft ecosystem
3. Performance optimization — mutable physics variant would be a first for the ecosystem

**Risk factors:**
- No core maintainer identified for v2.0 features
- Limited community awareness (single upstream source)
- No benchmark suite for performance regression detection

**Bottom line:** honey-flap is a flagship demonstration package that should be promoted as the canonical example of physics-based TUI game development in PHP. Its architecture is sound, its tests are comprehensive, and its approach to physics integration is unique in the TUI game space.

---

*Report generated: 2026-05-28*
*Source files: `docs/repo_map/sugarcraft_honey-flap.md`, `honey-flap/src/*.php`, `honey-flap/tests/*.php`, `docs/repo_map/charmbracelet_bubbletea.md`, `docs/repo_map/charmbracelet_vhs.md`, `docs/repo_map/rasjonell_dashbrew.md`, `docs/repo_map/ratatui_ratatui.md`, `docs/repo_map/pterm_pterm.md`, `docs/repo_map/blacktop_go-termimg.md`, `docs/repo_map/charmbracelet_glow.md`*
