# Overview
candy-vcr is a mature v1-ready VHS recorder/player and .tape-to-GIF renderer. It records TUI sessions as JSONL cassettes with byte/cell-grid assertions, replays them deterministically, and renders VHS-format `.tape` files to animated GIFs via a full PHP pipeline (Lexer → Parser → Compiler → Player → Terminal → Renderer → FrameStream → FrameDedup → Rasterizer → GifEncoder). The biggest opportunities are in editor integration (tree-sitter grammar), richer visual output (border radius, window chrome), and additional output formats. The biggest missing capability is any interactive editing/debugging workflow for cassettes.

# Internal Capability Summary

## Architecture
- **Cassette formats**: JsonlFormat (default), CompressedJsonlFormat (gzip), RelativeFormat (delta timestamps, asciinema-style), YamlFormat, AsciinemaFormat (import only)
- **Recording**: PTY-based via candy-pty, streaming JSONL with crash-safety, hook system (beforeSave/afterCapture), idle trimming, dual timestamps (t + tRaw)
- **Replay**: SPEED_INSTANT (5ms yield between events) and SPEED_REALTIME modes, deterministic byte/cell-grid assertions
- **Rendering pipeline**: .tape → Lexer → Parser → Compiler → Cassette → Player → Terminal → Renderer → FrameStream → FrameDedup → Rasterizer (Gd/Imagick) → GifEncoder (FFmpeg/Pure-PHP)
- **Assertions**: ByteAssertion (strict byte equality), ScreenAssertion (cell-grid via candy-vt), ContainsAssertion, RegexAssertion
- **Matchers**: PassthroughMatcher, ContentMatcher, TimingTolerantMatcher (±50ms default)
- **Msg serialization**: Registry with 19 built-in types + Jsonable catch-all

## Strengths
- Streaming JSONL with crash-safety (only current event lost on crash)
- Dual timestamps (t + tRaw) for idle trim with original timing preserved
- Full hook system for sanitization and metadata injection
- Glyph tile cache with 99.9% hit rate, ~7% rasterization speedup
- Host TTY safety net with SIGTERM/SIGHUP/SIGINT handling and rescue marker file
- 5ms yield in SPEED_INSTANT prevents ReactPHP StreamSelectLoop starvation
- VHS replacement soak running in CI (Phase 7)
- Round-trip tape compiler (Lexer → Parser → Compiler → Decompiler)

## Weaknesses
- Cell equality comparison is O(cols × rows) per frame (1920 comparisons at 30fps = 57,600/sec)
- No tree-sitter grammar for .tape syntax highlighting
- No SSH server for remote tape execution (unlike upstream VHS)
- No WebM/MP4 output (GIF only)
- No visual styling beyond the terminal content itself (no border radius, window bars, margins)
- No Wait command for screen-pattern-based synchronization
- No built-in publishing for GIFs
- No interactive cassette editor/debugger
- No incremental/streaming replay for large cassettes

# Relevant External Repositories

| Repo | Relevance | Major applicable concepts | Priority |
|------|-----------|--------------------------|----------|
| `charmbracelet/vhs` | Direct upstream | .tape DSL, SSH server, WebM/MP4 output, visual styling (borders, window bars), fuzzy theme matching, Wait command, frame offset looping | Critical |
| `charmbracelet/tree-sitter-vhs` | High | Tree-sitter grammar for .tape files, syntax highlighting queries, multi-language bindings | High |
| `charmbracelet/bubbletea` | Medium | teatest library for TUI testing patterns, command system | Medium |
| `charmbracelet/x` | Medium | x/vcr HTTP recording patterns, golden file testing | Medium |
| `textualize/textual` | Medium | CSS-based theming, command palette, Pilot testing class | Medium |
| `ratatui/ratatui` | Low | Widget system patterns, buffer diffing (sugar-bits is equivalent) | Low |
| `php-tui/php-tui` | Low | Cassowary layout (already in honey-bounce) | Low |
| `charmbracelet/freeze` | Low | ANSI parsing for image generation (interesting but not directly applicable) | Low |

# Feature Gap Analysis

## Critical
1. **Tree-sitter grammar for .tape files**
   - Description: No PHP tree-sitter grammar exists for `.tape` syntax highlighting in editors (Neovim, Helix, etc.)
   - Why it matters: Editor integration enables developers to author `.tape` files with full syntax highlighting, error reporting, and auto-formatting
   - Source: `docs/repo_map/charmbracelet_tree-sitter-vhs.md`
   - Implementation: Create `tree-sitter-vhs` grammar.js port to PHP-based tree-sitter bindings or generate a PHP parser using tree-sitter-cli
   - Complexity: Medium (grammar definition is the heavy lift; PHP codegen is automated)
   - Expected impact: High developer experience improvement

## High Value
2. **Wait command (screen-pattern-based synchronization)**
   - Description: VHS supports `Wait` directive that waits until a regex pattern appears on screen before proceeding
   - Why it matters: Enables more robust tape recordings that don't depend on exact timing
   - Source: `docs/repo_map/charmbracelet_vhs.md`
   - Implementation: In Player, add Wait handling that polls Terminal screen content against regex
   - Complexity: Medium
   - Expected impact: More reliable cross-terminal .tape replays

3. **Loop offset / playback offset**
   - Description: Start GIF loops at arbitrary frames for better preview frames
   - Why it matters: Demo GIFs often start mid-animation; loop offset lets first frame be interesting
   - Source: `docs/repo_map/charmbracelet_vhs.md`
   - Implementation: FrameStream respects a `loopOffset` field, renaming/shifting frame indices
   - Complexity: Low
   - Expected impact: Better demo output

4. **Visual styling (border radius, window bars, margins)**
   - Description: VHS supports border radius, window bars (Colorful/Rings variants), margins, padding for polished GIF output
   - Why it matters: Raw terminal output is less visually appealing than decorated output
   - Source: `docs/repo_map/charmbracelet_vhs.md`
   - Implementation: Post-rasterization compositing layer adds SVG/PNG decorations
   - Complexity: High (requires significant new rendering layer)
   - Expected impact: Much more visually polished output

5. **WebM/MP4 output**
   - Description: VHS supports WebM and MP4 video formats beyond GIF
   - Why it matters: Smaller file sizes, true video quality, wider compatibility
   - Source: `docs/repo_map/charmbracelet_vhs.md`
   - Implementation: FfmpegGifEncoder → FfmpegVideoEncoder with VP9/AV1 codecs
   - Complexity: Medium
   - Expected impact: Smaller outputs, wider format support

## Medium
6. **Interactive cassette debugging/replay UI**
   - Description: No TUI for stepping through a recorded session event-by-event with diff inspection
   - Why it matters: When a replay assertion fails, developers need visual tools to debug
   - Source: Internal gap
   - Implementation: New `debug` command that opens a TUI showing expected vs actual frames
   - Complexity: Medium
   - Expected impact: Faster bug diagnosis in recorded sessions

7. **Fuzzy theme matching**
   - Description: VHS uses Levenshtein distance for theme name matching (handles typos)
   - Why it matters: Better DX when theme name is slightly wrong
   - Source: `docs/repo_map/charmbracelet_vhs.md`
   - Implementation: Add `levenshtein()` based theme resolver in CassetteHeader or Compiler
   - Complexity: Low
   - Expected impact: Better error messages for misspellings

8. **SSH server mode (vhs serve)**
   - Description: VHS has built-in SSH server for remote tape execution
   - Why it matters: Enables running tapes on remote machines without local PHP installation
   - Source: `docs/repo_map/charmbracelet_vhs.md`
   - Implementation: Requires PHP SSH2 server implementation; significant complexity
   - Complexity: Very High
   - Expected impact: Niche but powerful for CI/CD pipelines

9. **Incremental/replay streaming for large cassettes**
   - Description: Player loads entire cassette into memory; large cassettes could be memory-prohibitive
   - Why it matters: Long recordings (hours) should be replayable without loading entire session
   - Source: Internal gap
   - Implementation: Generator-based event streaming from Format; Player accepts Iterator
   - Complexity: Medium
   - Expected impact: Enables very long recordings

## Low Priority
10. **Publishing to vhs.charm.sh-style service**
    - Description: VHS has built-in GIF publishing to shareable URLs
    - Why it matters: Easy sharing of demo GIFs
    - Source: `docs/repo_map/charmbracelet_vhs.md`
    - Implementation: Requires backend service; not applicable to library-only port
    - Complexity: High (service, not library)

11. **MsgPack binary cassette format**
    - Description: Binary format for compact large recordings
    - Why it matters: Disk space for very long recordings
    - Source: `docs/repo_map/sugarcraft_candy-vcr.md`
    - Implementation: MsgPack encoder alongside existing JsonlFormat
    - Complexity: Low
    - Expected impact: Minor space savings

12. **SVG output (term-transcript-style)**
    - Description: Render frames as SVG rather than GIF
    - Why it matters: Vector output for crisp rendering at any size
    - Source: `docs/repo_map/sugarcraft_candy-vcr.md`
    - Implementation: New Rasterizer → SVG encoder
    - Complexity: Medium
    - Expected impact: Niche

13. **Cassette append (resume recording)**
    - Description: Resume recording on existing cassette
    - Why it matters: Long-running sessions can be checkpointed
    - Source: `docs/repo_map/sugarcraft_candy-vcr.md`
    - Implementation: Append mode to Recorder; needs header merge logic
    - Complexity: Medium
    - Expected impact: Niche but useful for very long recordings

14. **Custom matcher scoring**
    - Description: Partial match scoring for fuzzy assertion
    - Why it matters: More nuanced test results when bytes differ slightly
    - Source: `docs/repo_map/sugarcraft_candy-vcr.md`
    - Implementation: New `ScoringMatcher` with match percentage
    - Complexity: Low
    - Expected impact: Better test output readability

# Algorithm / Performance Opportunities

## Current approach vs external approach

**Cell equality comparison** (Current: O(cols × rows) per frame):
- candy-vcr iterates all cells comparing `Cell::equals()` for frame dedup
- At 80×24 × 30fps = 57,600 cell comparisons/sec
- `docs/repo_map/sugarcraft_candy-vcr.md` documents this as a known bottleneck

**External approach (hash-based dedup)**:
- Compute SHA-256 of serialized grid+cursor state per frame
- Hash comparison is O(1) vs O(n) cell iteration
- Only on hash collision do you need to compare cells
- Applicable when: Frame dedup is the bottleneck (not rasterization)
- Tradeoff: Memory for hash storage; collision handling adds complexity

**Textual's spatial map**:
- Uses R-tree-like spatial indexing for O(log n) hit detection
- Not directly applicable to frame comparison but useful for interactive debugging UI

**Hash-based frame dedup implementation idea**:
```php
// Per-frame: compute hash of (grid serialized + cursor state)
$frameHash = hash('sha256', serialize($snapshot->grid) . serialize($snapshot->cursor));
// Store hash in sliding window; if seen, skip with hold tracking
// Only deserialize on hash collision to verify true equality
```

## Ffmpeg VFR encoding
- Current approach in `FfmpegGifEncoder` uses concat demuxer with process substitution — works well
- No significant external improvements to borrow; the VFR technique is already state-of-art

## Glyph cache already well-optimized
- 99.9% hit rate with fingerprint-based invalidation — no further optimization needed
- Documented in `docs/repo_map/sugarcraft_candy-vcr.md` CALIBER_LEARNINGS.md section

# Architecture Improvements

1. **Player accepts Iterator for incremental cassette loading**
   - Change `Player::play(cassette: Cassette, ...)` → `play(events: Iterator, ...)`
   - Format implementations return Generator<Event> instead of loading all into array
   - Enables streaming replay of multi-GB compressed cassettes

2. **Separate Rendering from Encoding in TapeToGif pipeline**
   - Currently Renderer + FrameStream + FrameDedup + Rasterizer + Encoder are tightly coupled in TapeToGif::render()
   - Extract `Pipeline` interface allowing individual stage replacement
   - Enables: Renderer → FrameStream → FrameDedup → [custom Rasterizer] → [custom Encoder]

3. **Command architecture cleanup**
   - `Application` uses a custom `Command` interface mixed with Symfony `Command`
   - Bridge via `runSymfonyCommand()` is a workaround; consider unifying all commands under Symfony Console
   - Source: CALIBER_LEARNINGS.md Phase 6 notes

# API / Developer Experience Improvements

1. **Fluent API for Player replay**
   - Already has `withIdleTrim()` but other options (speed, matcher, assertion) are not fluent
   - Add `withSpeed()`, `withMatcher()`, `withAssertion()` for more ergonomic chaining

2. **Named constructors for assertions**
   - Currently: `new ByteAssertion()`, `new ScreenAssertion(cols: 80, rows: 24)`
   - Add `ByteAssertion::exact()`, `ScreenAssertion::tolerant()` factory methods with sensible defaults

3. **Better error messages on cassette format errors**
   - When cassette loading fails, provide line number and character position of parse error
   - Currently throws `\InvalidArgumentException` with minimal context

4. **Progress callbacks for render-tape**
   - Long renders (>30s) have no progress indication
   - Add `TapeToGif::render()` callback option: `onProgress(float $percent, string $phase)`

5. **Cassette header schema validation**
   - On load, validate version, cols, rows are within valid ranges
   - Currently accepts any values; malformed cassettes cause cryptic downstream errors

# Documentation / Cookbook Opportunities

1. **Tutorial: Recording a bug reproduction**
   - `record --output bug.cas -- mycommand --with-bugs`
   - Ship bug.cas to maintainer
   - Maintainer replays with `replay --speed=instant`

2. **Tutorial: CI regression testing with cassettes**
   - Record golden session in tests/
   - Replay in CI with ScreenAssertion
   - Document the shared factory closure pattern

3. **Tutorial: Writing .tape files from scratch**
   - Cover all directives with examples
   - Show decompiler round-trip for verification

4. **Cookbook: Custom hooks for CI metadata**
   - SanitizingHook removes keys
   - MetadataHook injects CI_RUN_ID, test_name
   - Complete working example

5. **Performance tuning guide**
   - Explain SPEED_INSTANT vs SPEED_REALTIME tradeoffs
   - idleThresholdSeconds tuning for CI vs demos
   - Glyph cache hit rate interpretation

# UX / TUI Improvements

1. **Interactive replay debugging UI**
   - TUI showing: left pane = recorded session, right pane = expected vs actual
   - Step through events with arrow keys
   - Visual diff highlighting on mismatched frames

2. **Better `inspect` command output**
   - Currently prints raw JSONL with minimal formatting
   - Add `--format=table` for human-readable event listing
   - Add `--filter=kind:input,output` for filtering

3. **Colorized render-tape progress output**
   - Use terminal colors to distinguish pipeline phases
   - Show elapsed time per phase

# Testing / Reliability Improvements

1. **Property-based testing for cassette round-trips**
   - Use PHPStan/Ec Psalm to generate random valid events
   - Verify: record → replay → assert produces identical output

2. **Fuzzing seeds via malformed cassette injection**
   - Generate invalid JSONL, malformed timestamps, invalid event kinds
   - Verify graceful error handling with clear messages

3. **CI soak period for VHS parity testing**
   - Currently running in `.github/workflows/vhs.yml`
   - Continue until candy-vcr renders all 46 libs cleanly

4. **Visual regression goldens for each theme**
   - Currently has 10 goldens (TokyoNight, Dracula, etc.)
   - Add goldens for all 5 themes with both encoders (PhpGif + FfmpegGif)

# Ecosystem / Integration Opportunities

1. **tree-sitter-vhs grammar for PHP-aware editing**
   - Port `charmbracelet/tree-sitter-vhs` grammar.js to PHP
   - Enable syntax highlighting in tree-sitter enabled editors
   - Source: `docs/repo_map/charmbracelet_tree-sitter-vhs.md`

2. **GitHub Action for cassette-based testing**
   - `charmbracelet/vhs-action` for Go; equivalent for PHP
   - Action that records a step, commits cassette, replays in CI
   - Enables PR-level regression detection

3. **VS Code extension for .tape files**
   - Syntax highlighting using tree-sitter queries
   - Hover docs for directives
   - Snippet completion for common patterns

4. **Neovim plugin integration**
   - Uses existing `tree-sitter-vhs` grammar
   - Key mapping: `<leader>vr` to render current .tape file to GIF
   - Preview rendered GIF inline

# Notable PRs / Issues / Discussions

## VHS (charmbracelet/vhs)
**Theme fuzzy matching** (`docs/repo_map/charmbracelet_vhs.md`):
- Uses Levenshtein distance for typo-tolerant theme lookup
- `agnivade/levenshtein.ComputeDistance()` handles "draclua" → "dracula"
- Lesson: defensive string matching improves DX significantly

**Wait command** (`docs/repo_map/charmbracelet_vhs.md`):
- `Wait <regex>` directive pauses until pattern appears on screen
- Critical for cross-terminal reliability; exact timing varies by terminal emulator
- Lesson: never rely solely on timing for synchronization

**Loop offset** (`docs/repo_map/charmbracelet_vhs.md`):
- Frame renaming during render shifts loop start point
- Enables first GIF frame to be at an interesting animation state
- Lesson: timing and framing are separate concerns

## tree-sitter-vhs (charmbracelet/tree-sitter-vhs)
**Multi-language bindings** (`docs/repo_map/charmbracelet_tree-sitter-vhs.md`):
- Generated parsers for Node.js, Go, Python, Rust, Swift, C
- `grammar.js` produces `src/parser.c` which is compiled for each target
- Lesson: invest in code generation infrastructure early

**Semantic highlighting via highlights.scm** (`docs/repo_map/charmbracelet_tree-sitter-vhs.md`):
- Tree-sitter query language maps nodes to highlight groups
- Editor-agnostic; works in Neovim, Emacs, VS Code (via extensions)
- Lesson: separate syntax from highlighting rules

## Bubble Tea teatest (charmbracelet/x)
**Golden file testing** (`docs/repo_map/charmbracelet_x.md`):
- `exp/golden` package with `Update()` method for auto-update
- `exp/teatest` for Bubble Tea program testing with fixture assertions
- Lesson: golden files need canonical update mechanism (not just manual)

# Recommended Roadmap

## Immediate Wins (Next PR)
1. Fuzzy theme matching (Levenshtein) — 1-2 days, high DX impact
2. Loop offset for GIF rendering — 1 day, better demo output
3. Fluent `with*()` setters on Player — 1 day, better DX
4. Progress callbacks on TapeToGif::render() — 1 day
5. Hash-based frame dedup (SHA-256 of grid+cursor) — 2 days, performance

## Medium-term Improvements (Next 3 PRs)
6. Wait command (screen-pattern-based) — 1 week
7. Interactive replay debugging UI — 1 week
8. Iterator-based incremental cassette loading — 1 week
9. tree-sitter-vhs grammar port to PHP — 2 weeks
10. WebM/MP4 output via FfmpegVideoEncoder — 1 week
11. Visual styling (border radius, margins, window bars) — 2 weeks

## Major Architectural Upgrades
12. Pipeline interface extraction (Renderer/Encoder decoupling) — 2 weeks
13. Full SSH server mode — 3+ weeks (requires PHP SSH2 server)
14. VS Code / Neovim editor extensions — 2 weeks
15. GitHub Action for cassette-based CI testing — 1 week

## Experimental Ideas
16. MsgPack binary cassette format
17. SVG rasterizer output
18. Cassette append mode
19. Custom matcher scoring

# Priority Matrix

| Opportunity | Impact | Complexity | Risk | Recommended Priority |
|-------------|--------|-------------|------|---------------------|
| Fuzzy theme matching | Medium | Low | Low | Immediate |
| Loop offset | Low | Low | Low | Immediate |
| Player fluent API | Medium | Low | Low | Immediate |
| Progress callbacks | Medium | Low | Low | Immediate |
| Hash-based frame dedup | Medium | Medium | Low | Immediate |
| Wait command | High | Medium | Medium | Medium-term |
| Interactive debug UI | High | Medium | Medium | Medium-term |
| Incremental cassette loading | Medium | Medium | Low | Medium-term |
| tree-sitter grammar | High | High | Medium | Medium-term |
| WebM/MP4 output | Medium | Medium | Low | Medium-term |
| Visual styling | High | High | Medium | Medium-term |
| Pipeline decoupling | Medium | High | Low | Major |
| SSH server mode | Medium | Very High | High | Experimental |
| Editor extensions | Medium | High | Medium | Major |
| GitHub Action | Medium | Medium | Low | Medium-term |

# Final Strategic Assessment

candy-vcr is a well-architected, mature library that successfully ports the VHS concept to PHP. Its core strengths — streaming JSONL cassettes, deterministic replay, multiple format support, and comprehensive assertion system — make it production-ready for both bug reproduction and visual regression testing.

The most significant strategic gap is **editor integration**: without a tree-sitter grammar for `.tape` files, developers lack syntax highlighting, error reporting, and auto-formatting when authoring tapes. This is a high-impact, medium-complexity gap that should be addressed to improve the day-to-day authoring experience.

The second major gap is **visual output quality**: VHS produces decorated output with border radius, window bars, and margins; candy-vcr produces raw terminal content. A post-processing compositing layer could bridge this gap without modifying the core rendering pipeline.

The third strategic opportunity is **interactive debugging**: when a replay assertion fails, developers have no visual tools to inspect the divergence. An interactive TUI for stepping through events with diff visualization would significantly improve the debugging experience.

For the VHS replacement soak (Phase 7), candy-vcr is on track — it needs continued parallel running until parity is confirmed across all 46 libs. Once soak completes, the Go-based VHS binary can be retired.

The library's architecture is sound: the hook system, format abstraction, and encoder interface are all extensible without breaking changes. Future additions (WebM output, visual styling, Wait command) can slot into the existing pipeline without restructuring.
