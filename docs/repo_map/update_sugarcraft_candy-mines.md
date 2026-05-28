# Overview

`candy-mines` is a Minesweeper clone ported from Go (`maxpaulus43/go-sweep`) to PHP, running on the SugarCraft TUI stack (`candy-core` + `candy-sprinkles`). It faithfully reproduces the upstream experience while adding PHP-native improvements: immutable value objects, injectable deterministic RNG for testing, O(1) win detection, mid-game serialization, and i18n support across 16 locales.

**Biggest opportunity areas:**
- Undo/redo system leveraging the immutable architecture
- AI solver/hint system for educational value
- Achievement and progression tracking beyond basic stats
- Enhanced mouse interactions (drag-to-reveal, gesture support)
- Replay system using serialization
- Command palette for navigation
- Web-based rendering for accessibility

**Biggest missing capabilities:**
- No undo/redo (though architecture supports it)
- No AI solver or hint system
- No replay system leveraging mid-game serialization
- No achievements or progression beyond per-difficulty stats
- No accessibility features (screen reader support, high contrast mode)
- No demo/tutorial mode

---

# Internal Capability Summary

## Current Architecture

```
Game (implements Model)
├── board: Board
├── cursorX: int
├── cursorY: int
├── rand: Closure(int):int
├── startedAt: ?float
├── elapsedSeconds: ?int
└── stats: Stats

Board (immutable value object)
├── width, height, mineCount
├── rows: list<list<Cell>>
├── minesPlaced: bool
├── exploded: bool
├── revealedCount: int
    ├── blank() / reveal() / toggleFlag() / chord()
    ├── isWon(): bool (O(1) via revealedCount)
    ├── serialize(): string / unserialize(): Board
    └── with*() fluent setters

Cell (immutable value object)
├── mine, revealed, flagged, adjacent: int
    ├── withMine() / withAdjacent() / reveal() / toggleFlag()

Difficulty (PHP enum)
├── EASY (9x9, 10 mines)
├── MEDIUM (16x16, 40 mines)
├── EXPERT (30x16, 99 mines)
└── fromDimensions()

Renderer (pure view function)
└── Renders ANSI string via candy-sprinkles

DifficultyStats (atomic JSON persistence)
└── tmp+rename pattern for crash-safe writes
```

## Current Features

| Feature | Status |
|---------|--------|
| First-click safety | ✅ Implemented (upstream has TODO) |
| Recursive flood-fill (iterative) | ✅ Stack-based to avoid stack overflow |
| Flag toggle | ✅ No-op on revealed cells |
| Chord click | ✅ Reveals unflagged neighbors when flag count matches |
| O(1) win detection | ✅ Via revealedCount counter |
| Deterministic RNG | ✅ Injectable Closure for testing |
| Mid-game serialization | ✅ Versioned JSON |
| Atomic stats persistence | ✅ Homestead pattern (tmp+rename) |
| Sub-second timer | ✅ microtime(true) |
| i18n (16 locales) | ✅ Via Lang::t() facade |
| Vim-style movement | ✅ hjkl keys |
| Mouse support | ✅ Middle-click chord, click-to-reveal |

## Strengths

1. **First-click safety fully implemented** — upstream has this as a TODO
2. **O(1) win detection** — upstream scans entire grid after every move
3. **Deterministic injectable RNG** — enables fully reproducible tests
4. **Mid-game serialization** — save/restore without external storage
5. **Immutable architecture** — no hidden shared state, trivial testability
6. **i18n** — 16 locales, zero runtime overhead
7. **Atomic persistence** — stats survive crashes
8. **Iterative flood-fill** — no stack overflow on large boards

## Weaknesses

1. **No undo/redo** — architecture supports it but not implemented
2. **No AI solver** — purely a game, no hint system
3. **Dense 2D array access** — `rows[y][x]` easy to confuse
4. **Board state in Game::$board** — could be decoupled for replay systems
5. **No achievements** — stats only, no progression

---

# Relevant External Repositories

| Repo | Relevance | Major applicable concepts | Priority |
|------|-----------|---------------------------|----------|
| `charmbracelet/bubbletea` | High | Elm architecture, command pattern, mouse handling, testing gaps | Critical |
| `textualize/textual` | High | Reactive state, CSS layout, widget composition, web rendering | Critical |
| `charmbracelet/vhs` | Medium | Tape DSL for demos, frame capture, video encoding | Medium |
| `erikgeiser/promptkit` | Medium | Input handling, validation patterns, keybinding systems | Medium |
| `maxpaulus43/go-sweep` | Direct | Original upstream, game logic reference | Primary |

---

# Feature Gap Analysis

## Critical

### 1. Undo/Redo System
**Title:** Implement undo/redo using immutable architecture
**Description:** Every state transition in candy-mines returns a new Board instance. This makes undo/redo trivial to implement by maintaining a history stack of Board states.
**Why it matters:** Major UX improvement for a puzzle game — players can recover from mistakes without restarting.
**Source repo:** N/A (architectural opportunity)
**Source PR/issue/discussion:** N/A
**Implementation ideas:**
- Add `history: list<Board>` to Game model
- On each move that changes board state, push previous state to history
- `undo()` pops history and returns previous board
- `redo()` maintains separate forward stack
- Limit history size to prevent memory bloat (e.g., last 100 moves)
**Estimated complexity:** Low — architecture already supports it
**Expected impact:** High — standard Minesweeper feature

### 2. Testing Infrastructure
**Title:** First-class testing utilities for TUI games
**Description:** Bubble Tea v2 has no official testing framework (issue #1654). Textual has Pilot class. candy-mines could lead with superior testing DX.
**Why it matters:** Ensures game logic correctness, enables TDD, prevents regressions
**Source repo:** `charmbracelet/bubbletea` issue #1654, `textualize/textual` Pilot class
**Source PR/issue/discussion:** `pr_charmbracelet_bubbletea.md:65-74`
**Implementation ideas:**
- `Simulator` class for deterministic input simulation
- `Program::withInput()` / `withOutput()` for I/O redirection
- Snapshot testing for ANSI output
- Message injection helpers
**Estimated complexity:** Medium
**Expected impact:** High — foundational quality tool

## High Value

### 3. AI Solver / Hint System
**Title:** Add optional AI solver demonstrating safe moves
**Description:** Analyze board state and highlight cells that are provably safe based on constraint propagation.
**Why it matters:** Educational value, accessibility for新手, demonstrates PHP capability
**Source repo:** N/A (novel for this domain)
**Source PR/issue/discussion:** N/A
**Implementation ideas:**
- Basic: Highlight cells with 0 adjacent mines (automatic reveal)
- Intermediate: Constraint-based solving for cells with unique configurations
- Advanced: Mine probability calculation for uncertain cells
**Estimated complexity:** Medium-High
**Expected impact:** Medium — nice-to-have, not core

### 4. Replay System
**Title:** Record and playback games using serialization
**Description:** Use mid-game serialization to enable game recording and playback functionality.
**Why it matters:** Share impressive games, analyze mistakes, community feature
**Source repo:** N/A
**Source PR/issue/discussion:** N/A
**Implementation ideas:**
- Record each move's timestamp, cursor position, and action
- Serialize to JSON alongside Board serialization
- ReplayPlayer class to step through recorded games
- Support variable playback speed
**Estimated complexity:** Medium
**Expected impact:** Medium — community engagement

### 5. Achievement System
**Title:** Add achievements and progression tracking
**Description:** Beyond basic stats (games/wins/best time), add achievements for milestones.
**Why it matters:** Increased engagement, replayability
**Source repo:** N/A
**Source PR/issue/discussion:** N/A
**Implementation ideas:**
- Achievements: "Win without flagging", "Win in under 60 seconds", "Win Expert first try"
- Unlock notifications via toast-style rendering
- Persistent achievement storage alongside DifficultyStats
- Local leaderboard (per-installation)
**Estimated complexity:** Low-Medium
**Expected impact:** Medium — engagement driver

### 6. Enhanced Mouse Interactions
**Title:** Drag-to-reveal and gesture support
**Description:** Implement Windows Minesweeper-style drag-to-reveal where holding mouse on revealed number and dragging shows destination.
**Why it matters:** Familiar UX for Windows users, accessibility
**Source repo:** N/A
**Source PR/issue/discussion:** N/A
**Implementation ideas:**
- On mousedown on revealed number with adjacent flags, enter "drag mode"
- Show ghost cursor following mouse
- On mouseup over neighbor, chord-reveal
- Visual feedback during drag (highlight valid targets)
**Estimated complexity:** Medium
**Expected impact:** Medium — UX polish

## Medium Priority

### 7. Command Palette
**Title:** Add command palette for navigation and actions
**Description:** Textual's command palette pattern enables fuzzy search of available actions.
**Why it matters:** Keyboard power users expect this pattern
**Source repo:** `textualize/textual` command system, `candy-kit`
**Source PR/issue/discussion:** `pr_textualize_textual.md:642-645`
**Implementation ideas:**
- `?` or `Ctrl+P` to open palette
- Actions: new game, change difficulty, undo, redo, show stats, toggle theme
- Fuzzy search filtering
- Recent actions at top
**Estimated complexity:** Low-Medium
**Expected impact:** Medium — power user feature

### 8. High Contrast / Accessibility Mode
**Title:** Add accessibility options for visually impaired
**Description:** High contrast color schemes, optional large cursor, screen reader announcements.
**Why it matters:** Accessibility compliance, broader audience reach
**Source repo:** N/A
**Source PR/issue/discussion:** N/A
**Implementation ideas:**
- High contrast theme option (black/white/yellow)
- Larger cell rendering option
- Announce cell contents on cursor move (via Lang::t)
- Optional color-blind friendly palette
**Estimated complexity:** Low
**Expected impact:** Medium — accessibility

### 9. Tutorial / Demo Mode
**Title:** Interactive tutorial for new players
**Description:** Guided walkthrough teaching mechanics.
**Why it matters:** onboarding, accessibility
**Source repo:** N/A
**Source PR/issue/discussion:** N/A
**Implementation ideas:**
- First launch shows brief tutorial overlay
- "Teach flood-fill" → "Teach chord" → "Teach flagging"
- Interactive hints that highlight possible actions
**Estimated complexity:** Medium
**Expected impact:** Low-Medium — onboarding

### 10. Board Customization Improvements
**Title:** Enhanced custom difficulty with presets
**Description:** Beyond current 2-50 constraints, add named presets and community shapes.
**Why it matters:** Flexibility for experienced players
**Source repo:** N/A
**Source PR/issue/discussion:** N/A
**Implementation ideas:**
- Named custom presets stored in stats
- "Pascal's Triangle" shaped boards (if technically feasible)
- Hexagonal grid option (major refactor)
**Estimated complexity:** Medium
**Expected impact:** Low — niche

## Low Priority

### 11. Web Rendering Path
**Title:** Browser-based rendering for candy-mines
**Description:** Textual offers textual-web. SugarCraft has no equivalent.
**Why it matters:** Access from non-terminal environments
**Source repo:** `textualize/textual` web driver
**Source PR/issue/discussion:** `pr_textualize_textual.md:798-802`
**Implementation ideas:**
- Not recommended for candy-mines — significant architecture change
- Would require separate package
**Estimated complexity:** Very High
**Expected impact:** Low — terminal app doesn't need web rendering

### 12. Sound Effects
**Title:** Audio feedback for actions
**Description:** Sound effects for reveal, flag, win, lose.
**Why it matters:** Immersion
**Source repo:** N/A
**Source PR/issue/discussion:** N/A
**Implementation ideas:**
- PHP can invoke external player via Process
- Keep sounds optional/muted by default
- Provide sample sounds
**Estimated complexity:** Low
**Expected impact:** Low — not core to gameplay

---

# Algorithm / Performance Opportunities

## Current Approach vs External

### 1. O(1) Win Detection (Already Superior)

**Current candy-mines:** Track `revealedCount` counter, increment on every reveal. Win = `revealedCount === width * height - mineCount`.

**Upstream go-sweep:** Scans entire grid on every `isWon()` call.

**Why ours is better:** O(1) vs O(n) per check, critical when isWon() called after every user input.

**Tradeoffs:** None — counter maintenance is trivial and always passes through canonical code paths.

**Applicability:** Already optimal.

### 2. Flood-Fill (Already Superior)

**Current candy-mines:** Iterative stack-based `floodReveal()`.

**Upstream go-sweep:** Recursive `sweep()` function.

**Why ours is better:** Avoids PHP stack overflow on large boards (Expert 30x16 = 480 cells).

**Tradeoffs:** Slightly more code, but negligible performance difference.

**Applicability:** Already optimal.

### 3. First-Click Safety (Already Superior)

**Current candy-mines:** Mines placed after first click, excluding 3x3 neighborhood.

**Upstream go-sweep:** Has TODO comment acknowledging unimplemented first-click safety.

**Why ours is better:** Fully working feature vs TODO.

**Tradeoffs:** None.

**Applicability:** Already optimal.

### 4. Serialization (Opportunity for Enhancement)

**Current candy-mines:** Versioned JSON with all cell data.

**External approaches:** Binary serialization for compactness, streaming for large boards.

**Why external might be better:**
- JSON is human-readable but larger
- For very large boards (>100x100), binary would use less memory

**Tradeoffs:** JSON is debuggable, binary requires additional parsing code.

**Applicability:** Low priority — current implementation is sufficient for standard board sizes.

---

# Architecture Improvements

## 1. Decouple Board State from Game Logic

**Current state:** Board is directly held by Game model. For replay systems, this coupling is limiting.

**Proposed:**
```php
interface BoardState {
    public function getBoard(): Board;
    public function withBoard(Board $board): self;
}

class ReplayGame implements BoardState {
    private Board $board;
    private list<Move> $moves;
    private int $currentMoveIndex;
}
```

**Benefit:** Separates recording/playback logic from core game.

## 2. Extract Game Logic Interface

**Current state:** All logic in Board.php methods.

**Proposed:** Extract strategy interfaces for:
- `MinePlacer`: Algorithm for placing mines (default, random, solver-based)
- `CellRevealer`: Algorithm for revealing cells (flood-fill, single, etc.)
- `WinDetector`: Algorithm for win detection (current counter approach)

**Benefit:** Enables AI solver, custom rules, testing stubs.

## 3. Event System for Observability

**Current state:** Direct method calls, no event hooks.

**Proposed:** Add optional event dispatcher:
- `onBeforeReveal(x, y)` → can modify behavior or cancel
- `onAfterReveal(x, y, cell)`
- `onGameWon(duration, board)`
- `onGameLost(explodedCell)`

**Benefit:** Enables achievements, replay recording, analytics without core changes.

---

# API / Developer Experience Improvements

## 1. Streamlined Board Creation

**Current:**
```php
Board::blank(9, 9, 10)->reveal($x, $y, $rand);
```

**Proposed (fluent):**
```php
Board::newGame(9, 9, 10, rng: $rand)->firstClick($x, $y);
// or
Game::withDifficulty(Difficulty::EASY)->start($rand);
```

**Rationale:** More semantic, clearer lifecycle.

## 2. Better Error Messages

**Current:** Generic InvalidArgumentException for malformed serialization.

**Proposed:** Domain-specific exceptions:
```php
class BoardSerializationException extends \RuntimeException;
class InvalidBoardDimensionsException extends \InvalidArgumentException;
class MinePlacementException extends \RuntimeException;
```

**Rationale:** Better debugging, enables recovery strategies.

## 3. Builder Pattern for Custom Games

**Current:**
```php
Game::new()
    ->withDifficulty(Difficulty::$EASY)
    ->withCustomBoard(50, 50, 200);
```

**Proposed (builder):**
```php
$game = Game::builder()
    ->rows(50)
    ->columns(50)
    ->mines(200)
    ->withFirstClickSafety(false) // custom rule
    ->withRNG($deterministicRand)
    ->build();
```

**Rationale:** More flexible than fixed presets.

---

# Documentation / Cookbook Opportunities

## 1. Algorithm Documentation

**Topic:** Explain O(1) win detection via revealedCount counter.

**Target audience:** Developers learning game logic optimization.

**Location:** CALIBER_LEARNINGS.md, inline docs.

## 2. Testing Cookbook

**Topic:** How to test game logic with deterministic RNG.

**Examples:**
- Testing flood-fill with pinned shuffle
- Testing win detection with known board states
- Testing chord click with partial flagging

**Location:** tests/README.md or CALIBER_LEARNINGS.md.

## 3. Porting Guide

**Topic:** How candy-mines differs from go-sweep (reference for future ports).

**Key differences documented in sugarcraft_candy-mines.md lines 363-386.

**Opportunity:** Expand into full porting documentation for other game ports.

---

# UX / TUI Improvements

## 1. Visual Feedback During Drag

**Current:** Middle-click chord works but no visual indication of what will be revealed.

**Proposed:** When middle-mouse held on number cell:
- Highlight all unflagged neighbors with subtle highlight
- Show count of what will be revealed

**Implementation:** Track drag state in Game model, modify Renderer to show highlights.

## 2. Animation for Flood-Fill

**Current:** Instant reveal of all flood-filled cells.

**Proposed:** Staggered reveal animation (50ms per cell or so) for visual satisfaction.

**Implementation:** In Renderer, add optional animation delay; in Game, track animation state.

## 3. Status Bar Improvements

**Current:** Timer and flag count shown inline.

**Proposed:**
- Add remaining safe cells count (`width * height - mineCount - revealedCount`)
- Add current game state indicator (playing, won, lost)
- Show keyboard shortcuts hint

## 4. Color Scheme Selector

**Current:** Fixed color scheme (8 distinct number colors).

**Proposed:**
- Theme selector via command palette
- Built-in themes: Classic, TokyoNight, Monokai, HighContrast
- Persist preference

---

# Testing / Reliability Improvements

## 1. Snapshot Testing for Renderer

**Current:** Manual testing of ANSI output.

**Proposed:** Add snapshot tests:
```php
public function testRendererOutput(): void
{
    $board = Board::blank(5, 5, 2)->reveal(2, 2, $this->rand);
    $view = Renderer::view($board, cursor: [0, 0]);
    $this->assertSnapshot('board-initial-reveal', $view);
}
```

**Benefit:** Catch rendering regressions automatically.

## 2. Property-Based Testing

**Current:** Deterministic test cases written manually.

**Proposed:** Use PHP property-based testing (if available) or exhaustive testing:
- Test all 3x3 board configurations with 1-2 mines
- Test all board sizes 2x2 to 5x5
- Verify flood-fill correctness for edge cases

## 3. Fuzz Testing for Serialization

**Current:** Valid JSON tested.

**Proposed:** Fuzz test unserialize with malformed data:
- Invalid JSON
- Missing fields
- Wrong types
- Out-of-range values

**Benefit:** Ensure graceful failure, not crashes.

## 4. Performance Benchmarking

**Current:** No benchmarks.

**Proposed:** Add benchmarks for:
- Flood-fill on large board
- Serialization/deserialization
- Win detection calls

**Benefit:** Detect performance regressions.

---

# Ecosystem / Integration Opportunities

## 1. VS Code Extension

**Opportunity:** Minesweeper game playable in VS Code sidebar.

**Implementation:** Package as VS Code extension using Code's TUI rendering.

**Benefit:** Reach developers who live in VS Code.

## 2. Integration with sugar-charts

**Opportunity:** Show stats as charts (win rate over time, time distribution).

**Implementation:** Use sugar-charts to render stats visualization.

**Benefit:** Visual feedback on progression.

## 3. Integration with candy-vcr

**Opportunity:** Record gameplay sessions for bug reports.

**Implementation:** Use candy-vcr to record and replay sessions.

**Benefit:** Better bug reports, demo creation.

---

# Notable PRs / Issues / Discussions

## From charmbracelet/bubbletea (via pr_charmbracelet_bubbletea.md)

### Issue #1654: Testing Framework Proposal
**Summary:** Community proposed `charm-test` package with Simulator class for deterministic TUI testing.
**Relevance:** Direct opportunity for SugarCraft to lead with superior testing infrastructure.
**Lessons learned:** 
- Input simulation (`SendKey()`, `Type()`, `Resize()`) is essential
- Snapshot testing with golden files is the expected pattern
- No official testing solution after 6+ years — gap in market

### Issue #1655: DevTools Inspector
**Summary:** Proposal for F12-toggleable inspector showing message log, state viewer, component tree.
**Relevance:** Would be valuable for debugging complex games like minesweeper.
**Lessons learned:** Implementing later is harder than designing in from start.

### Issue #1599 / #1690: Data Races
**Summary:** Shared state between render loop and input handlers caused races.
**Relevance:** candy-mines uses immutable objects but candy-core renderer could have similar issues.
**Lessons learned:** All shared state between loops must be mutex-protected.

## From textualize/textual (via pr_textualize_textual.md)

### Issue #6381: GC-Induced Stuttering
**Summary:** MarkdownViewer created 600+ reference cycles, causing 50-200ms GC pauses.
**Relevance:** If candy-sprinkles styles hold strong references to widgets, same issue possible.
**Lessons learned:** Use weakrefs for parent references, clear caches on shutdown.

### Issue #4964: Object Leak / Reference Cycles
**Summary:** Styles held strong refs to widget.node, causing leaks.
**Relevance:** Same pattern could affect SugarCraft widget system.
**Lessons learned:** Audit all parent-child references for weakref opportunities.

## From erikgeiser/promptkit (via pr_erikgeiser_promptkit.md)

### Issue #27: Empty State Handling
**Summary:** Selection widget with empty choices caused silent failure via tea.Quit.
**Relevance:** Similar pattern could affect candy-mines with edge cases.
**Lessons learned:** Document explicit contracts for edge cases; don't use early termination.

### Issue #7: Contextual Validation Errors
**Summary:** Users wanted specific error messages, not just pass/fail validation.
**Relevance:** Not directly applicable to minesweeper but good practice.
**Lessons learned:** Contextual error messages improve UX.

---

# Recommended Roadmap

## Immediate Wins (This Sprint)

1. **Implement undo/redo system**
   - Leverage immutable architecture
   - Simple history stack in Game model
   - Keyboard shortcuts: `u` (undo), `y` (redo)

2. **Add comprehensive snapshot tests**
   - Renderer output tests
   - Game state tests
   - Serialization round-trip tests

3. **Document algorithm decisions**
   - O(1) win detection rationale
   - First-click safety implementation
   - Iterative vs recursive tradeoffs

## Medium-Term Improvements (Next Quarter)

4. **AI solver / hint system**
   - Basic constraint propagation solver
   - Highlight safe cells option
   - "Show hint" command

5. **Achievement system**
   - Milestone tracking
   - Toast notifications
   - Persistent storage

6. **Replay system**
   - Move recording
   - Variable-speed playback
   - Shareable replay files

7. **Command palette**
   - Integrate with candy-kit
   - Fuzzy search actions
   - Keyboard navigation

## Major Architectural Upgrades (Future)

8. **Event system for extensibility**
   - onBeforeReveal / onAfterReveal hooks
   - Plugin architecture for custom rules
   - Achievement triggers

9. **Extract strategy interfaces**
   - MinePlacer interface
   - CellRevealer interface
   - WinDetector interface

10. **Web rendering path**
    - Only if strategic need identified
    - Significant complexity

## Experimental Ideas

- Hexagonal grid variant
- Pascal's triangle shaped board
- Multiplayer via shared state
- AI vs AI competition mode

---

# Priority Matrix

| Opportunity | Impact | Complexity | Risk | Recommended Priority |
|------------|--------|------------|------|-------------------|
| Undo/Redo System | High | Low | Low | P0 - Immediate |
| Snapshot Testing | High | Low | Low | P0 - Immediate |
| Algorithm Documentation | Medium | Low | None | P1 - This Sprint |
| Achievement System | Medium | Medium | Low | P1 - Next Quarter |
| AI Solver / Hints | Medium | Medium-High | Medium | P2 - Future |
| Replay System | Medium | Medium | Low | P2 - Next Quarter |
| Command Palette | Medium | Medium | Low | P2 - Next Quarter |
| Event System | High | High | Medium | P3 - Future |
| Strategy Interfaces | Medium | Medium | Medium | P3 - Future |
| Drag-to-Reveal | Medium | Medium | Low | P2 - Next Quarter |
| High Contrast Mode | Medium | Low | Low | P1 - This Sprint |
| Web Rendering | Low | Very High | High | P4 - Never |

---

# Final Strategic Assessment

`candy-mines` is a well-executed Minesweeper port that already exceeds its upstream in several meaningful ways: first-click safety, O(1) win detection, deterministic testing via injectable RNG, and atomic persistence. The immutable architecture is sound and enables several high-value features that haven't been implemented yet.

The most immediate opportunity is implementing **undo/redo** — the architecture already supports it trivially, and this is a standard feature users expect from puzzle games. Combined with **snapshot testing** for the renderer, these two additions would significantly improve reliability and UX.

The **achievement system** and **replay system** represent medium-complexity, high-impact features that would increase engagement without major architectural changes. Both leverage the existing serialization infrastructure.

The **AI solver/hint system** is more ambitious but would differentiate candy-mines from other Minesweeper implementations by demonstrating PHP's capability for constraint-based reasoning.

Looking at the broader TUI ecosystem, several lessons from `charmbracelet/bubbletea` and `textualize/textual` are directly applicable:

1. **Testing infrastructure is a gap** — SugarCraft could lead with first-class testing support
2. **GC management matters** — weak references for parent links, cache lifecycle management
3. **Documentation of edge cases** — explicit contracts prevent user confusion

The main risk is scope creep — minesweeper is a simple game and should remain simple. The focus should be on polish and reliability rather than feature bloat.

For a v1 release, the priority should be:
1. Undo/redo (high impact, low complexity)
2. Snapshot tests (reliability)
3. Achievement system (engagement)
4. Documentation (onboarding)

After v1, the replay system and AI solver represent the most valuable additions for long-term engagement.
