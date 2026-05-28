# Overview

candy-tetris is a production-ready, well-architected Tetris implementation that has surpassed its Go upstream (Broderick-Westrope/tetrigo) in feature completeness. It implements the full Super Rotation System (SRS), 7-bag randomizer, ghost piece, NES-style scoring with T-Spin/B2B/Combo/PerfectClear bonuses, level-driven gravity, lock delay, hold piece, DAS/ARR input, and a VS Computer mode with garbage row passing and AI opponent.

**Biggest opportunities:** Full SRS kick integration (vertical kicks not wired), soft/hard drop scoring, honey-bounce physics animation integration, and comprehensive demo/gameplay recording infrastructure.

**Biggest missing capabilities:** Soft-drop scoring, hard-drop scoring (2pts per cell), true SRS vertical kicks, honey-bounce physics integration for visual polish, and Next Piece Preview queue.

---

# Internal Capability Summary

## Current Architecture

Nine pure-state classes + one rotation table, each individually testable without booting the runtime:

```
src/
├── Tetromino.php          Enum — 7 piece types with SRS rotation states + colors
├── Piece.php              VO — Tetromino + rotation + (x,y), immutable transforms
├── Board.php              VO — 10×24 grid (4 hidden spawn rows), fits/place/clearLines/dropPiece
├── Bag.php                7-bag RNG with peek(); injectable \Closure RNG for tests
├── Score.php             Points/lines/level + NES gravity frames-per-row
├── Game.php              Model — TEA orchestrator, key handling, gravity tick loop
├── GravityMsg.php        Marker Msg — triggers one gravity step
├── Renderer.php           Pure view function — board + sidebar + ghost + hold preview
├── Computer.php           AI opponent — weighted heuristic (height/holes/gaps/lines)
├── VsGame.php            Model — two Games + garbage passing + win/lose detection
├── VsRenderer.php        Split-screen view for VS mode
├── Input/
│   └── Das.php           DAS (167ms) + ARR (50ms) delayed-auto-shift input timing
├── Rotation/
│   └── SrsKickTable.php  Official SRS wall-kick offset tables (J/L/S/T/Z + I)
└── Scoring/
    └── TSpin.php        T-Spin detector (3-corner rule) + T-Spin Mini
```

**File reference:** `docs/repo_map/sugarcraft_candy-tetris.md:20-39`

## Strengths

1. **Full Guideline Scoring** — T-Spin, B2B (1.5×), Combo (+10×combo), Perfect Clear (+5000×level) — supersedes upstream tetrigo
2. **DAS/ARR Input** — Professional-grade keyboard timing via `Input/Das.php`
3. **VS Computer Mode** — Garbage passing + weighted heuristic AI opponent
4. **Pure State Architecture** — Every class immutable; `Game::update()` returns `[nextGame, ?Cmd]`
5. **Injectable RNG** — `Bag(Closure $rand)` enables deterministic testing
6. **Clean Separation** — `SrsKickTable`, `TSpin` as independent, separately testable units
7. **82+ tests, 1669 assertions** — ~300ms execution
8. **Frame-rate-agnostic gravity** — `Score::framesPerRow()` returns NES frame counts; consumers convert via `×16_667µs`

**File reference:** `docs/repo_map/sugarcraft_candy-tetris.md:550-558`

## Weaknesses

1. **SRS kicks not fully wired** — `SrsKickTable` exists with correct data, but `Game::tryRotate()` uses simplified horizontal-only nudge `[0, -1, 1, -2, 2]` instead of vertical kick offsets
2. **Soft drop awards no points** — Piece descends faster but no score bonus
3. **Hard drop awards no points** — 2 points per cell dropped is guideline standard
4. **No next-piece preview queue** — Only current piece + held piece visible; no queue of upcoming pieces
5. **No wall-kick display** — The research document exists but the feature isn't implemented
6. **Ghost piece is binary** — Instant appearance at landing; no spring-easing animation

**File reference:** `docs/repo_map/sugarcraft_candy-tetris.md:111-116, 560-568`

---

# Relevant External Repositories

| Repo | Relevance | Major applicable concepts | Priority |
|------|-----------|--------------------------|----------|
| `charmbracelet/bubbletea` | High | TEA pattern (Model/Update/View), Elm architecture in PHP via candy-core | P0 |
| `charmbracelet/lipgloss` → candy-sprinkles | High | Declarative styling, color system, border rendering | P0 |
| `charmbracelet/harmonica` → honey-bounce | High | Spring physics for ghost-piece animation, line-clear particles, camera shake | P1 |
| `ratatui/ratatui` | Medium | Widget trait pattern, immediate-mode rendering, buffer diffing | P1 |
| `charmbracelet/bubbles` → sugar-bits | Medium | Component patterns (Timer, Progress, Help) for game UI chrome | P1 |
| `charmbracelet/vhs` | Medium | Demo recording infrastructure (.tape format) | P2 |
| `sugarcraft/honey-bounce` | High | Spring physics, easing curves, WCAG REDUCE_MOTION | P0 |
| `sugarcraft/candy-sprinkles` | High | Styling foundation for board rendering, borders, colors | P0 |
| `sugarcraft/candy-core` | High | TEA runtime, View rendering, input handling | P0 |

**Source:** `docs/repo_map/charmbracelet_bubbletea.md`, `docs/repo_map/sugarcraft_honey-bounce.md`, `docs/repo_map/sugarcraft_candy-sprinkles.md`, `docs/repo_map/charmbracelet_lipgloss.md`, `docs/repo_map/charmbracelet_vhs.md`

---

# Feature Gap Analysis

## Critical

### 1. Full SRS Kick Table Integration
- **Title:** Wire `SrsKickTable` into `Game::tryRotate()` for true SRS with vertical kicks
- **Description:** The `SrsKickTable` class exists with correct official data but `Game::tryRotate()` currently uses simplified horizontal-only nudge `[0, -1, 1, -2, 2]`. True SRS tests up to 5 candidates per rotation transition, including vertical offsets.
- **Why it matters:** Without full SRS, certain piece/rotation combinations that are valid in modern Tetris games will fail in candy-tetris. Players relying on wall kicks for competitive play will encounter unexpected failures.
- **Source:** `docs/repo_map/sugarcraft_candy-tetris.md:111-116`
- **Implementation ideas:**
  1. Add `Piece::rotationsWithKicks(int $delta = 1): array` (already exists at `Piece.php:58-70`)
  2. Modify `Game::tryRotate()` to iterate `$piece->rotationsWithKicks($direction)` and use first valid candidate
  3. Update `Game.php:227-250` which currently uses the simplified horizontal nudge
- **Complexity:** Medium — existing `Piece::rotationsWithKicks()` already implemented; just needs wiring
- **Impact:** High — completes the Guideline rotation system; eliminates a critical gap

### 2. Hard Drop Scoring
- **Title:** Award 2 points per cell for hard drops
- **Description:** Guideline standard awards 2 points per row the piece falls during a hard drop. Currently hard drop moves the piece but awards no points.
- **Why it matters:** Hard drop is a core scoring mechanic; absence breaks player expectation and removes a skill differentiator.
- **Source:** `docs/repo_map/sugarcraft_candy-tetris.md:563`
- **Implementation ideas:**
  1. Calculate drop distance in `Game::tryHardDrop()` before placing
  2. Add `$points = $dropDistance * 2` to score update
  3. Track in `Score::withHardDrop(int $cells): self`
- **Complexity:** Low — single calculation in `Game::tryHardDrop()`
- **Impact:** Medium — minor scoring gap but expected by players

## High

### 3. Soft Drop Scoring
- **Title:** Award 1 point per cell for soft drops
- **Description:** Guideline standard awards 1 point per row when soft dropping (holding ↓). Currently soft drop accelerates descent without score bonus.
- **Why it matters:** Soft drop is a risk/reward choice — commit to faster fall for 1 point/cell vs. waiting for better placement. Without scoring, there's no reason to not always soft drop.
- **Source:** `docs/repo_map/sugarcraft_candy-tetris.md:562`
- **Implementation ideas:**
  1. In `Game::trySoftDrop()`, add `1 point × cells dropped` to score
  2. Could be tracked via `Score::withSoftDrop(int $cells): self`
- **Complexity:** Low — same pattern as hard drop scoring
- **Impact:** Medium — removes a scoring incentive for careful play

### 4. Next Piece Preview Queue
- **Title:** Display 1-2 upcoming pieces in the sidebar
- **Description:** Most modern Tetris games show 1-3 next pieces. Currently candy-tetris only shows the current piece and held piece.
- **Why it matters:** Players plan strategy based on upcoming pieces; not seeing them is a significant information gap.
- **Source:** `docs/repo_map/sugarcraft_candy-tetris.md:568` (listed as opportunity)
- **Implementation ideas:**
  1. Add `nextPieces` field to `Game` (queue of 1-2 `Tetromino` values)
  2. Modify `Bag::next()` to pre-fill the queue for peek purposes
  3. Update `Renderer::render()` to show next pieces in sidebar
- **Complexity:** Medium — requires Bag modification + rendering update + Game state change
- **Impact:** Medium — standard player expectation

### 5. honey-bounce Integration for Visual Polish
- **Title:** Spring-animated ghost piece, line-clear particles, camera shake on Tetris
- **Description:** The existing `honey-bounce` library provides damped spring physics that could animate the ghost piece settling, particles on line clears, and camera shake on 4-line clears.
- **Why it matters:** juice" — visual feedback that makes the game feel more responsive and satisfying. The upstream (tetrigo) has none of this.
- **Source:** `docs/repo_map/sugarcraft_candy-tetris.md:564`, `docs/repo_map/sugarcraft_honey-bounce.md:303-364`
- **Implementation ideas:**
  1. `SpringCollection` for ghost piece X-axis spring settling
  2. `Projectile` instances for line-clear particle emission
  3. Screen shake via `Canvas::addLayer()` with rapid position offsets
  4. `REDUCE_MOTION` detection in `Spring::update()` already built in
- **Complexity:** High — requires animation subsystem integration into Game loop
- **Impact:** Medium — polish feature, not core gameplay

## Medium

### 6. Initial Rotation System (IRS)
- **Title:** Allow rotation during ARE before piece appears
- **Description:** IRS lets players pre-rotate the next piece while current piece is locking. Guideline standard.
- **Source:** `docs/repo_map/sugarcraft_candy-tetris.md:565`
- **Complexity:** Medium — requires tracking next piece rotation state during ARE

### 7. Initial Hold System (IHS)
- **Title:** Allow hold during ARE
- **Description:** IHS lets players execute hold during the entry delay. Guideline standard.
- **Source:** `docs/repo_map/sugarcraft_candy-tetris.md:566`
- **Complexity:** Medium — similar to IRS implementation

### 8. Diminishing Lock Delay
- **Title:** Lock delay speeds up with each reset
- **Description:** Guideline option where each successful movement/rotation during lock delay reduces remaining lock time by a percentage.
- **Source:** `docs/repo_map/sugarcraft_candy-tetris.md:567`
- **Complexity:** Medium — requires lock delay state modification

### 9. Ghost Piece Toggle
- **Title:** Allow hiding ghost per player preference
- **Description:** Some players prefer playing without ghost piece for added difficulty.
- **Source:** `docs/repo_map/sugarcraft_candy-tetris.md:568`
- **Complexity:** Low — boolean flag in Game + keybinding + renderer conditional

## Low

### 10. T-Spin Mini Recognition (Wall-Based)
- **Title:** Improve T-Spin Mini detection for wall-kick situations
- **Description:** Current 3-corner rule handles basic T-Spin detection but doesn't account for some wall-kick scenarios where T-Spin Mini should be recognized differently.
- **Source:** `docs/repo_map/sugarcraft_candy-tetris.md:223-234`
- **Complexity:** High — requires analyzing all SRS kick scenarios for T-piece specifically

### 11. Complicated Piece Kick Tables (CPC)
- **Title:** Implement full SRS-C drop mechanic
- **Description:** Some modern variants use complicated piece-specific kick tables for certain rotations.
- **Source:** General Tetris knowledge
- **Complexity:** High — would require research and extensive testing

---

# Algorithm / Performance Opportunities

## Current vs External Approach

### Ghost Piece Drop
**Current:** `Board::dropPiece()` uses a `while(true)` loop that moves the piece down one row at a time until collision. This is O(distance) per hard drop.

**External (ratatui/ratatui):** No game-specific algorithm; rendering is immediate-mode with buffer diffing. The game logic is consumer responsibility.

**Why external better:** The while loop is appropriate for game logic (collision detection must happen per-row for lock delay resets). However, for **display** purposes, computing ghost position via simple calculation (find minimum y where collision occurs) rather than iteration would be cleaner and potentially faster for preview animations.

**Tradeoffs:** Not a significant optimization for 20-row boards — the loop runs at most 20 iterations.

**Applicability:** Low priority — current approach is correct for game logic. Could optimize display ghost calculation separately.

### AI Move Evaluation
**Current:** `Computer.php` uses weighted sum of board features (height, holes, gaps, lines) evaluated for all x positions + rotations. O(n×rotations) per tick.

**External (typical Tetris AI):** More sophisticated AIs use:
- 7-8 feature weights (not just 4)
- Population-based search (genetic algorithms)
- Depth-limited lookahead with garbage estimation
- Machine learning-trained weights

**Source:** `docs/repo_map/sugarcraft_candy-tetris.md:455-470`

**Why external better:** The current heuristic is a "greedy best single move" approach. More sophisticated AIs can plan 3-5 moves ahead and account for upcoming pieces.

**Tradeoffs:** The current AI is already competitive. More sophisticated AI would require more CPU per move. Since candy-tetris runs on PHP (single-threaded), there's a practical ceiling on AI complexity.

**Applicability:** Medium — could improve AI quality but current AI is sufficient for casual play.

### DAS/ARR Implementation
**Current:** `Input/Das.php` implements classic DAS/ARR with accumulator-based timing.

**External (charmbracelet/bubbletea):** Uses ` tea.KeyPressMsg` with `IsRepeat` flag directly from terminal, bypassing DAS/ARR entirely.

**Why external better:** Modern terminals (Kitty keyboard protocol) report key repeat natively, eliminating the need for DAS/ARR software simulation. However, not all terminals support this.

**Source:** `docs/repo_map/charmbracelet_bubbletea.md:121-126`

**Tradeoffs:** Native repeat reporting is simpler but less portable. DAS/ARR is the correct approach for maximum compatibility.

**Applicability:** Low — current implementation is correct and portable.

---

# Architecture Improvements

## 1. Extract Scoring into Value Object

**Current:** `Score` combines points/lines/level/gravity in one class with `withLines()` mutator.

**Improvement:** Consider a more granular state — `Points`, `Level`, `Lines` as separate readonly value objects combined via `Score::fromPointsAndLines()`.

**Reference:** Pattern from `candy-core` immutable value objects.

## 2. Weak Subscriber Pattern for Game Events

**Current:** `Game::update()` is a monolith that handles all logic in one function.

**Improvement:** Add a `Game::subscribe(callable $onLinesCleared)` pattern where renderers/animation systems can hook into game events without modifying Game state.

**Reference:** `charmbracelet_bubbletea.md:169-179` command pattern for async events.

## 3. Animation Framebudget System

**Current:** Gravity ticks are handled by `GravityMsg` at fixed intervals.

**Improvement:** An `AnimationFramebudget` that separates logical updates (gravity tick, lock delay countdown) from visual interpolation (spring-eased ghost piece position).

**Reference:** `docs/repo_map/sugarcraft_honey-bounce.md:248-264` — honey-flap separates physics from rendering.

## 4. Separate Display State from Game Logic

**Current:** `Game` holds both logical state (board, score, piece) and some display preferences (ghost visibility).

**Improvement:** Extract `DisplayPreferences` as a separate injectable config object, allowing renderers to customize appearance without modifying game logic.

---

# API / Developer Experience Improvements

## 1. Game Configuration Builder

**Current:** `Game::start()` / `Game::startWithLockDelay()` are the entry points.

**Improvement:**
```php
Game::configure()
    ->withLockDelay(15)
    ->withGravity(Score::fromLevel(5))
    ->withDas(new Das(167_000, 50_000))
    ->withNextPiecePreview(3)
    ->withGhostEnabled(true)
    ->start($bag);
```

**Reference:** `charmbracelet_lipgloss.md:73-101` — fluent builder pattern for Style.

## 2. Event Stream for Game Telemetry

**Current:** No way to observe game events externally (for replay, analytics, spectator mode).

**Improvement:** `Game::events(): \Generator` that yields typed events (`PiecePlaced`, `LinesCleared`, `GameOver`, etc.) consumable by replay systems or the VS mode spectator view.

**Reference:** `charmbracelet_bubbletea.md:100-112` — Cmd pattern for async event emission.

## 3. Pluggable Scoring System

**Current:** Scoring is hard-coded in `Game::update()`. Different scoring systems (NES, Guideline, Custom) are not swappable.

**Improvement:** `ScoringSystem` interface with implementations:
- `NesScoring`
- `GuidelineScoring` (current)
- `CustomScoring`

**Reference:** Pattern from `candy-sprinkles/src/Style.php` where rendering styles are composable.

---

# Documentation / Cookbook Opportunities

## 1. Tetris Clone Tutorial
A multi-part tutorial walking through building a minimal Tetris clone using candy-tetris as the game logic engine, focusing on custom renderers.

**Reference:** `charmbracelet_vhs.md:145-154` — VHS architecture is well-documented for tool demos.

## 2. AI Bot Development Guide
How to extend `Computer.php` with custom heuristics or integrate ML-trained weight files.

## 3. Scoring System Deep Dive
`docs/repo_map/sugarcraft_candy-tetris.md:194-283` has the scoring data but needs a dedicated document with worked examples of B2B + combo + T-Spin stacking.

## 4. Physics Integration Examples
How to use honey-bounce for ghost piece animation, particles, and camera shake — with copy-paste code snippets.

**Reference:** `docs/repo_map/sugarcraft_honey-bounce.md:266-301` — honey-flap integration example.

---

# UX / TUI Improvements

## 1. Ghost Piece Animation
**Priority:** Medium | **Complexity:** High

The ghost piece appears instantly at the landing position. A spring-eased interpolation from current Y to ghost Y would provide visual feedback about where the piece will land.

**Reference:** `docs/repo_map/sugarcraft_honey-bounce.md:303-364` — Spring animation system available.

## 2. Line Clear Visual Effect
**Priority:** Medium | **Complexity:** High

Currently lines clear instantly with no animation. A flash/pulse effect (or the classic line compression animation) would improve feedback.

**Reference:** `charmbracelet_vhs.md:24-26` — visual effects in terminal recording.

## 3. Camera Shake on Tetris
**Priority:** Low | **Complexity:** Medium

Screen shake (via `Canvas` layer offset) when player achieves a Tetris (4-line clear).

## 4. Custom Color Themes
**Priority:** Medium | **Complexity:** Low

Allow `Theme` customization for board colors, piece colors, border styles. Currently uses candy-sprinkles styling but no game-specific theme system.

**Reference:** `docs/repo_map/sugarcraft_candy-sprinkles.md:208-214` — 10 named theme factories.

## 5. Sound Effect Hooks
**Priority:** Low | **Complexity:** Medium

A `Game::onSound(string $event)` callback system for playing sounds on line clear, Tetris, T-Spin, game over, etc.

---

# Testing / Reliability Improvements

## 1. Golden Snapshot Tests for Rendered Boards
**Priority:** High | **Complexity:** Low

`RendererTest.php` exists with snapshot tests. Expand to cover:
- All piece types at all rotation states on empty board
- Ghost piece rendering in various positions
- Hold piece display
- Score display formatting

**Reference:** `docs/repo_map/sugarcraft_candy-tetris.md:543-544` — existing test coverage.

## 2. Lock Delay Integration Tests
**Priority:** High | **Complexity:** Medium

Current tests cover lock delay behavior in isolation. Add integration tests for:
- Lock delay reset on successful rotation
- Lock delay expiration leading to piece lock
- Diminishing lock delay (future feature)

## 3. T-Spin Corner Case Tests
**Priority:** Medium | **Complexity:** Medium

Add tests for wall-kick T-Spin scenarios, OOB corner handling, and mini vs full T-Spin boundary conditions.

**Reference:** `docs/repo_map/sugarcraft_candy-tetris.md:223-235` — existing 6 T-Spin tests.

## 4. DAS/ARR Timing Tests
**Priority:** Medium | **Complexity:** Medium

Add microsecond-precision tests for DAS/ARR accumulator logic to ensure correct key repeat behavior.

**Reference:** `docs/repo_map/sugarcraft_candy-tetris.md:382-392` — Input/Das.php implementation.

## 5. Performance Regression Suite
**Priority:** Low | **Complexity:** Medium

Benchmark the game loop to ensure gravity tick + render cycle completes within frame budget (16.67ms at 60fps).

---

# Ecosystem / Integration Opportunities

## 1. sugar-charts Integration for Score History
**Priority:** Low | **Complexity:** Medium

Render a sparkline chart of score progression over time using `sugar-charts`. Could be shown in VS mode sidebar.

**Reference:** `docs/repo_map/sugarcraft_sugar-charts.md` (not read, but in ecosystem).

## 2. candy-pty for Sound Effects
**Priority:** Low | **Complexity:** Medium

Use `candy-pty` to spawn aplay/aplay for audio playback on Linux/macOS.

## 3. Replay System via Session Recording
**Priority:** Low | **Complexity:** High

Record game inputs (key presses + timestamps) and replay them for demos or analytics. Use `candy-vcr` concepts.

**Reference:** `docs/repo_map/charmbracelet_bubbletea.md:249-250` — vcr mapped to candy-vcr.

## 4. VS Mode Network Play
**Priority:** Low | **Complexity:** High

Two-player network play via WebSocket or TCP. Each player's garbage rows would be sent over the network.

## 5. Integration with sugar-bits Timer Component
**Priority:** Low | **Complexity:** Low

Use `SugarCraft\Forms\Timer\Timer` for game timer in VS mode instead of manual tick counting.

**Reference:** `docs/repo_map/charmbracelet_bubbles.md:62-63` — Timer component.

---

# Notable PRs / Issues / Discussions

## From Upstream (tetrigo)
The upstream `Broderick-Westrope/tetrigo` has not implemented SRS kicks, T-Spin, B2B, Combo, Perfect Clear, DAS/ARR, or VS mode. **candy-tetris has significantly surpassed its upstream.**

**Reference:** `docs/repo_map/sugarcraft_candy-tetris.md:501-519`

## From charmbracelet/bubbletea
The Elm Architecture is well-understood in the Go ecosystem with extensive examples. The Bubble Tea repo has 60+ example programs demonstrating patterns directly applicable to candy-tetris.

**Key lesson:** The `Cmd func() Msg` pattern maps to PHP closures. Bubble Tea's subscription system (`Tick`, `Every`) maps to `GravityMsg` scheduling in candy-tetris.

**Reference:** `docs/repo_map/charmbracelet_bubbletea.md:208-221`

## From honey-bounce
The physics library is production-validated in `honey-flap` (Flappy Bird clone). The `REDUCE_MOTION` accessibility support is already built into `Spring::update()`.

**Key lesson:** Frame-rate independence via `Spring::fps(60)` returning `1.0/60` deltaTime ensures consistent simulation across platforms.

**Reference:** `docs/repo_map/sugarcraft_honey-bounce.md:375-377`

---

# Recommended Roadmap

## Immediate Wins (Next PR)

1. **Full SRS kick integration** — Wire `SrsKickTable` into `Game::tryRotate()`
2. **Hard drop scoring** — +2 points per cell
3. **Soft drop scoring** — +1 point per cell
4. **Ghost piece toggle** — `g` key to toggle visibility

## Medium-Term (1-2 releases)

5. **Next piece preview queue** — Show 1-2 upcoming pieces
6. **honey-bounce ghost animation** — Spring-eased ghost piece settling
7. **Line-clear visual effect** — Flash/pulse on line clear
8. **Game configuration builder** — Fluent API for game setup
9. **Initial Rotation System (IRS)** — Pre-rotate next piece during ARE
10. **Initial Hold System (IHS)** — Hold during ARE

## Major Upgrades (v2.0)

11. **Animation subsystem** — Hook honey-bounce into Game event stream for particles/camera shake
12. **Pluggable scoring system** — Interface + NES/Guideline/Custom implementations
13. **Tournament mode** — Standardized rules (Sprint, Ultra, etc.)
14. **Network VS mode** — Two-player over network

## Experimental

15. **AI depth search** — More sophisticated AI with lookahead
16. **ML-trained weights** — Train AI on human games
17. **Replay system** — Input recording/playback for analytics

---

# Priority Matrix

| Opportunity | Impact | Complexity | Risk | Priority |
|------------|--------|------------|------|----------|
| Full SRS kick integration | High | Medium | Low | P0 |
| Hard drop scoring | Medium | Low | Low | P0 |
| Soft drop scoring | Medium | Low | Low | P1 |
| Ghost piece toggle | Low | Low | Low | P1 |
| Next piece preview | Medium | Medium | Low | P1 |
| honey-bounce ghost animation | Medium | High | Medium | P2 |
| Line-clear visual effect | Medium | High | Medium | P2 |
| Game configuration builder | Medium | Low | Low | P1 |
| IRS / IHS | Low | Medium | Medium | P2 |
| Diminishing lock delay | Low | Medium | Medium | P2 |
| Animation subsystem | High | High | High | P3 |
| Pluggable scoring | Medium | Medium | Low | P2 |
| VS network mode | High | High | High | P3 |
| AI depth search | Medium | High | Medium | P3 |

---

# Final Strategic Assessment

candy-tetris is a **mature, well-architected** library that has already surpassed its upstream in feature completeness. The pure-state TEA architecture, comprehensive test suite, and clean separation of concerns make it an excellent foundation for further development.

**Immediate focus should be:** Completing the full SRS kick system (critical gap), adding drop scoring (soft/hard), and implementing next-piece preview. These are core Tetris features that players expect.

**Medium-term differentiator:** The honey-bounce integration for visual polish is where candy-tetris can exceed other Tetris implementations. Spring-animated ghost pieces, particle effects on line clears, and camera shake would make the game feel more polished and responsive than most terminal Tetris implementations.

**Long-term positioning:** If candy-tetris adds tournament-standard game modes (Sprint, Ultra, Battle), network VS play, and a replay system, it could become the definitive Tetris implementation for the terminal.

**Noted lessons from ecosystem:**
- The Elm Architecture (bubbletea → candy-core) is the correct model — immutable state + pure update functions + declarative views work equally well in PHP
- honey-bounce's physics-first approach (no rendering dependency) is the right split — rendering should remain consumer's responsibility
- DAS/ARR keyboard timing is essential for competitive play; native terminal key repeat (Kitty protocol) is a future optimization
- The 7-bag randomizer with injectable RNG is a best practice other games should adopt

**Source citations:** `docs/repo_map/sugarcraft_candy-tetris.md`, `docs/repo_map/charmbracelet_bubbletea.md`, `docs/repo_map/sugarcraft_honey-bounce.md`, `docs/repo_map/sugarcraft_candy-sprinkles.md`, `docs/repo_map/charmbracelet_lipgloss.md`, `docs/repo_map/charmbracelet_vhs.md`, `docs/repo_map/charmbracelet_bubbles.md`, `candy-tetris/README.md`, `candy-tetris/CALIBER_LEARNINGS.md`
