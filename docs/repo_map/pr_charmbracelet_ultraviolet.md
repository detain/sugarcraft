# Second-Stage Ecosystem Intelligence: charmbracelet/ultraviolet

## Metadata
- **URL**: https://github.com/charmbracelet/ultraviolet
- **Language**: Go
- **Stars**: ~330
- **License**: MIT
- **Last Push**: 2026-04-20
- **Contributors**: 20 (top: aymanbagabas, metafates, meowgorithm, taigrr, Chronostasys)
- **Open Issues**: 15
- **API Status**: Unstable (pre-1.0)

---

## 1. Repository Overview

Ultraviolet is the foundational TUI primitive layer for the Charmbracelet ecosystem, powering Bubble Tea v2 and Lip Gloss v2. It provides cell-based rendering, cross-platform input handling, and a diffing terminal renderer — all without terminfo/termcap dependencies. The library is in active development with a rapidly evolving API; the README removed its "API stability" disclaimer in April 2026, signaling confidence but also volatility.

The codebase is organized around:
- `Terminal` / `TerminalScreen` — lifecycle, I/O, screen management
- `Buffer` / `RenderBuffer` / `Line` / `Cell` — cell grid and diffing
- `Window` — parent/child view hierarchy
- `layout` — Cassowary constraint solver
- `screen` package — drawing helpers
- `event.go` / `decoder.go` (54k) / `key_table.go` (23k) — input handling

**Key differentiating characteristic**: Everything centers on the `Screen` interface (`Bounds`, `CellAt`, `SetCell`, `WidthMethod`). This interface is the keystone — all drawing code depends on it, not on concrete types, enabling testability and composability.

---

## 2. Existing SugarCraft Mapping

From the first-pass analysis, Ultraviolet maps across multiple SugarCraft libs:

| Ultraviolet Component | SugarCraft Lib | Risk Level |
|---|---|---|
| Cell + Style + Link (ANSI SGR) | `sugar-bits` | **HIGH** |
| Buffer/RenderBuffer/Line diffing | `candy-core` | **CRITICAL** |
| Screen interface + TerminalScreen | `candy-core` | **CRITICAL** |
| Terminal lifecycle | `candy-core` | **HIGH** |
| KeyPress/Mouse/Paste/Focus events | `sugar-bits` | **HIGH** |
| Window parent/child hierarchy | `candy-sprinkles` | MEDIUM |
| Cassowary layout solver | `honey-bounce` | MEDIUM |
| Border functions | `candy-shell` | LOW |
| StyledString + screen.Context | `sugar-bits` | **HIGH** |
| ProgressBar + Cursor encoding | `sugar-bits` | MEDIUM |
| MouseMode / Kitty keyboard | `sugar-bits` | **HIGH** |
| WcWidth / GraphemeWidth | `sugar-bits` | **HIGH** |
| Color profile detection | `sugar-bits` | MEDIUM |

The highest-risk components for SugarCraft are the renderer internals (terminal_renderer.go at 1589 lines), the decoder/input system (decoder.go, key_table.go), and the Style/Color machinery.

---

## 3. Previously Identified Gaps

The first-pass analysis identified these gaps in SugarCraft's coverage:

1. **No equivalent to `transformLine` optimization paths** — The ECH/REP/ICH/DCH optimizations that make Ultraviolet bandwidth-efficient have no SugarCraft counterpart
2. **No Cassowary solver** — The layout package is entirely unported
3. **No `StyledString`** — The chars.go PR (#93) introducing Characters()/Lines()/Wrapper is open and would add text wrapping
4. **No Kitty text-sizing (OSC 66)** — PR #108 added this; SugarCraft has no equivalent
5. **No synchronized updates (mode 2026)** — Atomic batched rendering for flicker-free output
6. **Wide character handling** — Ultra-sensitive area (see Section 6)

---

## 4. High-Signal Open Issues

### Issue #95: "drawing styled strings onto a buffer loses escape sequences"
- **Author**: gwenya (Mar 2, 2026)
- **Signal**: Bug report, moderate community interest
- **Content**: When drawing styled strings onto a Buffer, escape sequences are lost. This is a fundamental API design issue about how styled text interacts with the cell buffer model.
- **Relevance to SugarCraft**: Directly applicable to `sugar-bits` if it implements `StyledString.Draw(buf, area)`. Must ensure escape sequences survive cell buffer round-trips.

### Issue #61: "Renderer does not correctly transform newlines into CRLF in some cases"
- **Author**: kralicky (Nov 18, 2025)
- **Signal**: Bug with active discussion
- **Content**: The renderer fails to properly handle `\n` → CRLF transformation in certain edge cases. The `SetMapNewline` flag exists but may not be fully implemented.
- **Relevance to SugarCraft**: Cross-platform line-ending handling is a common pitfall. SugarCraft's renderer must correctly handle both Unix (`\n`) and Windows (`\r\n`) line endings.

### Issue #54: "Testing that's sort of like playwright"
- **Author**: joeblew999 (Oct 17, 2025)
- **Signal**: Feature request, moderate interest
- **Content**: Request for a higher-level testing abstraction over the renderer output tests. Not about renderer bugs, but about developer ergonomics for testing TUI output.
- **Relevance to SugarCraft**: SugarCraft should consider a snapshot testing pattern similar to what UV uses (terminal_renderer_output_test.go). This is already in the sugar-bits test conventions but could be formalized.

---

## 5. Important Closed Issues

### Issue #97: printString infinite recursion / stack overflow (1 fps rendering)
- **Root Cause**: Infinite loop in renderer when parsing certain styled strings — same class of bug as the `transformLine` IsZero loop (#109). This and the wide-char infinite loop (#109) represent a **recurring pattern** where buffer-traversal loops fail to re-read buffer state inside the loop.
- **Fix**: Various commits addressed this; maintainer noted it as related to #109
- **Signal**: This is a **high-value detection** — infinite loops in renderer hot paths are catastrophic (CPU spin, 1 fps, process hang). SugarCraft must add timeout-based tests for any renderer loop.

### Issue #1167 (in bubbletea, closed): Windows: first character input lost on successive programs
- **Root Cause**: `CancelIo` doesn't cancel I/O across threads on Windows. Need `CancelIoEx`. Also: Windows Console input cancellation is fundamentally broken per microsoft/terminal#12143.
- **Signal**: Windows input handling is the most fragile part of the Charm stack. Ultraviolet's `terminal_reader_windows.go` is where this lives. SugarCraft's Windows input handling will face identical issues.
- **Fix**: PRs in x/input and bubbletea addressed this, but the fundamental Windows limitation remains.

### Issue #845/#847/#851 (in crush, closed): Windows non-win32 terminal bugs
- **Root Cause**: Ultraviolet's Windows input code assumed win32 console API was available. Terminals like Alacritty and Rio on Windows don't use the Windows Console — they emulate xterm. The code path that forces win32 input-mode broke these.
- **Signal**: **Critical cross-platform assumption violation**. SugarCraft must detect whether it's running in a native Windows console vs. a terminal emulator on Windows and behave differently.

### Issue #1613 (in bubbletea, closed): cursor misplaced in inline mode with Println
- **Root Cause**: `scrollHeight` tracking hack in the renderer was incorrect when ESC L (insert line) sequence was used. The hack maintained scroll position state across renders but it was never properly updated.
- **Fix**: Removed the scroll height tracking hack entirely; simplified cursor movement logic.
- **Signal**: **Inline mode is underspecified** — the renderer was built primarily for fullscreen (alternate screen) mode. Inline mode (preserving terminal scrollback) has different invariants and was being retrofitted.

---

## 6. Recurring Pain Points

### 6.1 Wide Character Rendering (CJ K/Emoji)
This is the **highest-frequency recurring bug** in the UV issue tracker:

1. **#109** (merged Apr 2026): Infinite loop in `transformLine` — forward-scanning loop reads `next` once before loop, never re-reads inside loop body. Classic off-by-one pointer bug.
2. **#103** (closed, not merged): `transformLine` column-optimizations (ICH/DCH/ECH/REP) assume 1-column-per-cell; wide chars are 2 columns. Also `relativeCursorMove` overwrite optimization writes a space for `Width==0` trailing cells, destroying wide chars.
3. **#97**: printString infinite recursion — same buffer-traversal loop pattern.
4. **#93**: The `chars.go` PR explicitly handles wide chars in `Characters()` and `Lines()` functions using grapheme cluster awareness.

**Pattern**: Wide characters expose the assumption that every cell is exactly 1 column wide. Any optimization that iterates cells column-by-column can break wide characters. The fix pattern is either:
- Detect wide chars and bypass ALL optimizations (safe but slow)
- Make each optimization wide-char-aware (complex, error-prone)

Maintainer position: "These optimizations don't fire on wide cells" — but the infinite loop (#109) contradicts this claim. The issue was the loop itself, not the optimizations firing.

**Direct Risk to SugarCraft**: CRITICAL. The `candy-core` renderer will implement cell-grid diffing. Any loop over cells that doesn't re-read buffer state on each iteration risks an infinite loop. Wide characters (CJK, emoji) must be treated as first-class concerns from day one.

### 6.2 Inline Mode Complexity
The inline mode (non-fullscreen) renderer has required multiple corrective commits:
- `fix(renderer): remove scroll height tracking hack` (Apr 2026)
- `fix(renderer): move to the last line in inline mode when the width or height changes` (Apr 2026)
- Inline mode test outputs were wrong in multiple test files, requiring corrections

**Signal**: Inline mode requires different cursor movement and scroll handling than fullscreen mode. The renderer had accumulated heuristics (scrollHeight) that didn't compose correctly. SugarCraft should decide early whether to support inline mode and design for it explicitly.

### 6.3 Windows Input Complexity
Windows input handling is the most platform-specific and bug-prone subsystem:
- Win32 Console API vs. xterm emulation on Windows terminals
- VT input mode (ENABLE_VIRTUAL_TERMINAL_INPUT) not supported on Windows 7
- UTF-16 surrogate pair handling
- NUM_LOCK state affecting key detection
- CancelIo vs. CancelIoEx threading issues

**Pattern**: Windows support requires significantly more code and testing than Unix. The Charm ecosystem has a recurring theme of Windows-specific bugs surfacing late.

**Direct Risk to SugarCraft**: HIGH. SugarCraft's `candy-core` will need Windows support. The Windows input path should be minimal and well-tested, or potentially deferred.

### 6.4 StreamEvents Goroutine Race
**#94** (merged Mar 2026): `StreamEvents()` returns without waiting for its internal goroutine when context is cancelled. Race condition between `cancelreader.Close()` and `cancelreader.wait()`.

**Root Cause**: Classic goroutine lifecycle management bug — spawn goroutine, return immediately on cancellation, don't join.

**Fix**: Added `sync.WaitGroup` to wait for goroutine completion before returning.

**Signal**: SugarCraft's async event handling (using ReactPHP) must carefully manage process forking/threading lifecycle. Any goroutine (or process) that outlives its caller's return must be explicitly joined.

---

## 7. Frequently Requested Features

### 7.1 Expanding the Screen Interface
**Discussion** (jaypipes, Feb 2026, Ideas category): Request to extend the `Screen` interface beyond the minimal 4 methods (`Bounds`, `CellAt`, `SetCell`, `WidthMethod`).

**Maintainer response**: Open to discussion but API must remain minimal. The Screen interface is intentionally small — any addition must be truly fundamental.

**Implication for SugarCraft**: The Screen interface is the right abstraction level. SugarCraft should mirror this minimalism. Adding methods to Screen should require strong justification.

### 7.2 Character Cells and Line Wrapping (PR #93, open)
The `chars.go` PR (1020 lines added) introduces:
- `Characters()` — construct []Cell from string with grapheme cluster awareness
- `Lines()` — construct []Line from string
- `Wrapper` type — word-boundary wrapping with configurable breakpoints

This is the most substantial API addition in recent history. It directly addresses the lack of text wrapping at the cell level.

**Relevance to SugarCraft**: If SugarCraft adds similar functionality to `sugar-bits`, the API should be compared. The UV approach uses factory methods and a `Wrapper` struct. SugarCraft might prefer a different API style (fluent setters, etc.).

### 7.3 Kitty Text-Sizing Protocol (PR #108, closed)
Added OSC 66 support to `StyledString` for multi-cell scaled glyphs in Kitty terminals.

**Engineering complexity**: This involved:
- Recognizing OSC 66 in the ANSI parser
- Building Cells whose Content is the full escape verbatim
- Handling multi-row glyphs (2x2 blocks) with CUF placeholder cells
- Fixing UTF-8 continuation byte `0x9C` collision with C1 string terminator
- Preventing SGR from being emitted adjacent to multicell extensions

**Relevance to SugarCraft**: Kitty graphics protocols (text-sizing, images) are emerging as important terminal capabilities. SugarCraft's `sugar-bits` should consider whether to support these, but the implementation complexity is significant.

### 7.4 Cassowary Constraint Solver
**Third-party** (metafates, Feb 2026, Show and tell): External Cassowary implementation for UV.

The UV `layout` package uses a Cassowary solver derived from Ratatui. A third party has built an alternative. This validates that the constraint-based layout approach is sound but shows there may be alternative implementations to consider.

**Relevance to SugarCraft**: `honey-bounce` is the intended home for layout. SugarCraft could either port UV's solver or adopt the third-party one.

---

## 8. Important PRs

| PR | State | Signal | Theme |
|---|---|---|---|
| #109 | merged | High | Fix infinite loop in transformLine (wide char trailing cell) |
| #103 | closed | Medium | Wide char rendering corruption (conservative fix: bypass optimizations for wide-char lines) |
| #108 | closed | Medium | Kitty text-sizing (OSC 66) support in StyledString |
| #94 | merged | High | StreamEvents goroutine race condition (sync.WaitGroup fix) |
| #93 | open | High | Character cells and line wrapping (1020 lines, major API addition) |
| #96 | closed | Low | Reflow tracking feature (closed without merge) |
| #856 (in crush) | merged | High | Windows downgrade UV due to non-win32 terminal input bugs |

---

## 9. Architectural Changes

### 9.1 Renderer Architecture Simplification (Apr 2026)
Multiple commits in April 2026 simplified the renderer:
- **Removed scroll height tracking hack** — The `scrollHeight` field and associated logic in `moveCursor` and `relativeCursorMove` was removed. Instead, always use newlines for vertical movement in inline mode. This fixes cursor position errors in Bubble Tea Println with insert-above logic.
- **Removed `scrollHeight` return from `moveCursor`** — Changed signature from `(string, int)` to just `(string)`; eliminated scrollHeight tracking throughout.
- **Inline mode dimension-change handling** — Changed condition from unconditional `s.move(newbuf, 0, newHeight-1)` to only when dimensions actually change.

**Architectural lesson**: Accumulated state (scrollHeight) that requires manual maintenance across code paths is a liability. The simpler fix (no accumulated state) is usually correct.

### 9.2 Terminal Tabs Reset (Apr 2026)
`NewTerminalScreen` now:
- Detects if tabs are supported via `optimizeMovements(state)`
- If not using tabs, explicitly calls `SetTabStops(-1)` to clear them
- Stores `resetTabs` flag to restore tabs on `Restore()`
- Windows: ignores errors from `term.GetState` since Windows supports tabs by default

This was a subtle bug where leftover tab stops from a previous program could affect the current program's rendering.

### 9.3 Mouse Encoding Reduction (Apr 2026)
The `MouseEncoding` enum was reduced from 4 variants to 2:
- Kept: `MouseEncodingLegacy` (X10), `MouseEncodingSGR` (DEC 1006)
- Disabled: `MouseEncodingUrxvt`, `MouseEncodingSGRPixel` (with TODO comments)
- `EncodeMouseEncoding()` removed encoding for disabled variants

**Signal**: Not all terminal capabilities are ready for prime time. UV is cutting back to only the most widely-supported encodings. SugarCraft should similarly disable by default any terminal feature that isn't universally supported.

### 9.4 Refactored Examples to "Advanced" Directory (Apr 2026)
Examples were reorganized from `examples/` to `examples/advanced/`. This signals a distinction between beginner-friendly demos and complex usage patterns.

---

## 10. Performance Discussions

### 10.1 Bandwidth Optimization via Cell-Based Diffing
UV's core performance insight: only send changed cells using optimized escape sequences (ECH, REP, ICH/DCH, scroll sequences). This is critical for SSH where bandwidth is the bottleneck.

**SSH latency issue**: A third-party project (lablup/all-smi) found that `stdout.flush()` is a blocking syscall that stalls the async event loop on slow SSH connections. Their fix: separate diff computation from I/O, offload write+flush to `spawn_blocking`.

**Relevance to SugarCraft**: The ReactPHP event loop will face identical issues. The flush/write operation is inherently blocking and must be offloaded on slow connections. This is an architectural decision SugarCraft must make early.

### 10.2 String Allocation Reduction (Apr 2026)
Changed `fmt.Sprintf` + `strings.Builder.WriteString` pattern to direct `fmt.Fprintf(&buf, ...)` to avoid intermediate string allocations in Windows key event logging.

**Signal**: At the renderer level, avoiding allocations matters. SugarCraft should use similar patterns (fmt.Fprintf directly to Writer) for hot paths.

### 10.3 Inline Mode Redraw Optimization
The `Render()` method re-renders the entire buffer on each call. For inline mode (where only part of the screen is used), this means re-emitting content that hasn't changed. The April 2026 commit moved the cursor to the last line only on dimension changes, reducing unnecessary cursor movement.

---

## 11. Extensibility Discussions

### 11.1 Screen Interface Extensibility
The discussion about "Expanding the Screen interface" (jaypipes, Feb 2026) reveals tension between minimalism and utility. The Screen interface has exactly 4 methods, which is deliberately minimal. Any addition must be truly fundamental.

**SugarCraft lesson**: The Screen interface should remain minimal. If additional capabilities are needed, they should come from composition (e.g., a `StyledScreen` decorator) rather than extending the interface.

### 11.2 Window vs. Buffer Distinction
`Window` supports both shared-buffer views (`NewView`) and independent buffers (`NewWindow`). The window hierarchy enables nested TUIs. The examples refactor (commit f72cc87) switched from `NewScreen` to `NewWindow` for creating the root, indicating that `Window` is the preferred abstraction even for root-level rendering.

**SugarCraft lesson**: `candy-sprinkles` (windowing) should follow the same pattern: `NewWindow` for everything, not `NewScreen`.

---

## 12. API/UX Complaints

### 12.1 API Instability
UV is pre-1.0 and makes no stability guarantees. The removal of the "API stability" disclaimer from the README (Apr 2026) is notable — it signals confidence but also means consumers must pin versions. The Bubble Tea v2 renderer had a breaking change (`Buffer` vs `RenderBuffer` type mismatch after a UV update) that required manual UV version pinning.

**SugarCraft lesson**: Pre-1.0 instability is acceptable, but SugarCraft should provide migration guides for each breaking change. The `go get github.com/charmbracelet/ultraviolet@b927aa605560` workaround for Bubble Tea users should serve as a cautionary tale.

### 12.2 Renderer Complexity
`terminal_renderer.go` is 1589 lines of complex escape sequence optimization logic. The comment at line 1 says "This is the cursed render" — the codebase acknowledges the renderer is difficult. The scroll height tracking hack, inline mode special cases, and capability-based optimization branches make this one of the most complex pieces of code in the Charm ecosystem.

**SugarCraft lesson**: Document the renderer clearly. The complexity is unavoidable (terminal rendering inherently involves many special cases), but it must be explained. Consider extracting the optimization decision logic into a separate strategy pattern for testability.

### 12.3 Missing Documentation
The first-pass analysis noted "Limited documentation — aside from README, doc.go, and TUTORIAL.md, inline godoc is the primary reference." This remains true. The `chars.go` PR added significant new API without extensive documentation beyond doc comments.

---

## 13. Migration Problems

### 13.1 V1 to V2 Bubble Tea Breaking Change
When a Bubble Tea v2 user ran `go get -u ./...`, it upgraded UV automatically. This caused a type mismatch: `s.cellbuf.Buffer` (type `*Buffer`) could not be used as `*RenderBuffer`. The fix required downgrading UV to a specific commit.

**Signal**: Go's automatic semver resolution and the lack of a stable API make it dangerous for consumers to blindly upgrade dependencies. SugarCraft should pin UV to a specific commit hash in its `composer.json` (or equivalent) until UV reaches 1.0.

### 13.2 Docker Scratch + SSH Rendering Issue (bubble tea #912)
After upgrading from Bubble Tea V1 to V2, UI renders garbled characters when the application is deployed inside a Docker scratch image and accessed via SSH. This worked in V1.

**Signal**: Docker scratch images lack libc and terminal database. SSH + scratch + UV2 had a regression. SugarCraft must test in minimal container environments, not just local development environments.

### 13.3 Windows Console Input Cancellation
The fundamental issue (CancelIo doesn't work across threads, CancelIoEx returns "Element not found") means Windows console input cancellation is unreliable. Programs that try to cleanly shut down on Windows may hang waiting for the input goroutine to exit.

---

## 14. Clever Fixes & Workarounds

### 14.1 Zero-Width Cell Sentinel Pattern
The pattern of using `Width=0, IsZero=true` cells as wide-character trailing sentinels is both clever and dangerous:
- It allows wide characters to occupy exactly 2 columns in the cell grid
- It enables normal cell iteration to "skip" the trailing cell
- But it means ANY optimization that iterates cells by column index must check Width and handle `Width<=0` specially

**SugarCraft approach**: Consider using a dedicated wide-character representation instead of sentinel cells. For example, store wide characters as a single cell with extra metadata rather than splitting them. This avoids the sentinel cell problem entirely.

### 14.2 Kitty Text-Sizing Cell Encoding
For OSC 66, the fix was to build a `Cell` whose `Content` is the full escape sequence verbatim and whose `Width` comes from the protocol metadata (`s=` / `w=`). This mirrors how OSC 8 (hyperlinks) are handled — consistent pattern for embedding protocol-specific data in cells.

**SugarCraft lesson**: When supporting new escape sequence protocols, the Cell model is flexible enough to encode them. Use the same pattern: verbatim Content + appropriate Width.

### 14.3 UTF-8 0x9C Rescue
The ANSI decoder terminates string-state sequences on bare `0x9C`. But `0x9C` is also a valid UTF-8 continuation byte. When a string sequence (OSC 66) ends on `0x9C` and more input is available, the decoder rescans for an unambiguous 7-bit terminator (BEL or ESC+\\) and extends the returned slice.

**Clever**: The fix applies to ALL string-state prefixes (OSC, DCS, APC, SOS, PM) via `hasStringPrefix`, making it future-proof.

### 14.4 Inline Mode Cursor Dangling Prevention
In `Flush()`, if the cursor is hidden and at the end of a line in inline mode, it's moved to the beginning of the next line to prevent "unwanted line wraps in some terminals." This is a terminal quirk workaround that must be applied on every flush.

---

## 15. Community Workarounds

### 15.1 Windows Non-Win32 Terminal Workaround (charmbracelet/crush)
When Crush encountered double-typing and input corruption on Alacritty/Rio on Windows, the workaround was to downgrade UV temporarily. No permanent fix has landed in UV; the issue was closed as a known limitation.

### 15.2 Windows First-Character-Lost Workaround (multiple repos)
For successive tea program runs on Windows losing the first keypress, workarounds included:
- Using `CancelIoEx` instead of `CancelIo` (platform-specific)
- Restructuring application to avoid successive `p.Run()` calls
- Using custom input readers that bypass the problematic Windows console path

### 15.3 Wide Character Handling Workaround (PR #103)
Before the proper fixes landed, wide character corruption was worked around by:
- Routing wide-char lines to `transformLineWide` (bypasses optimizations)
- Checking for wide characters before cursor overwrite operations
- The maintainer noted `Width <= 0` no longer does overwrites (commit 9b00276)

---

## 16. Maintainer Guidance Patterns

### 16.1 Conservative Optimization Policy
Maintainer (aymanbagabas) consistently prefers conservative fixes over aggressive ones:
- When wide-char lines were reported as corrupted, the response was "These optimizations don't fire on wide cells" — but the bug report showed they did via the infinite loop
- When multiple PRs addressed the same issue (#103 vs #109), maintainer merged the simpler one (#109) and closed the comprehensive one
- Mouse encodings were cut from 4 to 2 with TODO comments for future support

**Pattern**: Maintainer favors minimal, verifiable fixes over comprehensive changes. SugarCraft should follow the same pattern: small, testable fixes first.

### 16.2 API Minimalism
The Screen interface stays at 4 methods. The layout package stays focused on Cassowary constraints. New features (Kitty text-sizing, characters/lines) are additive, not interface-changing.

### 16.3 Platform Capability Detection
Platform-specific handling is gated on capability detection, not assumptions:
- `xtermCaps()` returns terminal capabilities as a bitmask
- `optimizeMovements()` detects tab/backspace support from terminal state
- Color profile is auto-detected and converted

**Pattern**: Always detect capabilities at runtime rather than hardcoding terminal assumptions. SugarCraft must implement similar detection.

---

## 17. Rejected Ideas Worth Revisiting

### 17.1 Reflow Tracking (PR #96, closed)
A contributor attempted to add reflow tracking but the PR was closed without merge. The concept (tracking how content reflows on resize) is valid but the implementation didn't meet maintainer standards.

**Relevance**: Word-wrap-aware resize (reflow on width change) is listed as planned work in the UV README. SugarCraft should watch for this feature to be properly implemented upstream.

### 17.2 Additional Mouse Encodings
`MouseEncodingUrxvt` and `MouseEncodingSGRPixel` were disabled (not deleted) with TODO comments. These remain on the roadmap but are not currently supported.

---

## 18. Problems Likely Relevant To SugarCraft

### CRITICAL (must address in candy-core/sugar-bits)

1. **Infinite loops in buffer traversal** — Any loop that iterates over cells and checks `IsZero()` or `Width` must re-read from the buffer on each iteration. Classic pattern: `for n++; next = newLine.At(n+1); next != nil && next.IsZero(); n++` — the `next = newLine.At(n+1)` must be inside the loop body.

2. **Wide character column assumptions** — Every cell optimization (erase, repeat, insert, delete, move) must be verified safe for `Width=2` cells and `Width=0` sentinel cells. The conservative approach: any line with `Width != 1` cells must bypass all single-column optimizations.

3. **Windows Console API limitations** — `CancelIo` does not work across threads. Input cancellation on Windows requires either `CancelIoEx` (which fails in some cases) or a completely different approach. The Windows Console API path must handle:
   - VT input mode not supported on Windows 7
   - UTF-16 surrogate pairs
   - NUM_LOCK state
   - Non-win32 terminals (Alacritty, Rio) requiring xterm emulation

4. **Inline mode vs. fullscreen mode differences** — Cursor movement, scroll handling, and output flushing behave differently. These must be designed as two first-class modes, not retrofitted.

5. **Flush as blocking I/O** — The `Flush()` call writes to the terminal and can block, especially over SSH. The event loop must not wait for flush to complete before processing input. Offload flush to a background thread/process.

### HIGH (should address)

6. **Goroutine/Process lifecycle management** — Any spawned async entity must be joined before the parent returns. Use WaitGroup or equivalent.

7. **Terminal state restoration** — On exit, the terminal must be restored to its original state (cursor, screen buffer, tab stops, title). `Restore()` method must be comprehensive.

8. **Color profile conversion** — TrueColor must be downsampled to the terminal's actual capability (ANSI256 or ANSI). Do not assume truecolor support.

9. **String escape sequence parsing** — OSC sequences (hyperlinks, text-sizing) must be recognized and handled specially in the cell buffer. They cannot be treated as ordinary character data.

10. **Style diff ordering** — SGR sequences must be emitted in a specific order to avoid terminal quirks (attributes before colors, underline color after underline style).

### MEDIUM (plan for)

11. **Kitty graphics protocols** — OSC 66 (text-sizing) and OSC 1337 (images) are emerging capabilities. SugarCraft should track whether to support them.

12. **Synchronized updates (mode 2026)** — Atomic batched rendering to prevent flicker. Should be available as an option.

13. **Word-wrap-aware resize** — Reflow content when terminal width changes. Currently unimplemented in UV (PR #96 rejected), but planned.

14. **Tab stop handling** — Detect existing tab stops on startup, clear or preserve them appropriately. Reset on exit.

---

## 19. Features SugarCraft Should Consider

### High Priority

1. **Cell-Based Diffing Renderer** — The core competitive advantage of UV. Implement in `candy-core`:
   - Touched-line tracking
   - Minimal SGR diffs
   - ECH/REP/ICH/DCH optimization when capabilities detected
   - Wide-char-safe path that bypasses optimizations

2. **Characters()/Lines() API** — From PR #93:
   - `Characters(string): []Cell` — grapheme-aware cell construction
   - `Lines(string): []Line` — line decomposition
   - `Wrapper` type — word-boundary wrapping with breakpoints

3. **Kitty Text-Sizing (OSC 66)** — Modeled after PR #108:
   - Recognize OSC 66 in styled string parser
   - Store full escape in Cell.Content, width from protocol metadata
   - Handle multi-row glyphs with CUF placeholders
   - Fix 0x9C UTF-8 collision

4. **Window/View Hierarchy** — From UV's `Window`:
   - `NewWindow` — independent buffer
   - `NewView` — shared-buffer child view
   - Parent/child bounds tracking

### Medium Priority

5. **Cassowary Constraint Solver** — Either port UV's or adopt metafates' third-party implementation

6. **Synchronized Updates (mode 2026)** — Wrap rendered output in synchronized-update control sequences

7. **Kitty Graphics Protocol (OSC 1337)** — Inline images. Complex; consider as phase 2.

---

## 20. Architectural Lessons

### Lesson 1: Screen Interface as Keystone
The `Screen` interface with 4 minimal methods (`Bounds`, `CellAt`, `SetCell`, `WidthMethod`) is the right abstraction. All rendering and drawing code depends on it. SugarCraft should mirror this exactly.

### Lesson 2: Wide Characters Break Single-Column Assumptions
Every column-based optimization (ECH, REP, ICH, DCH, cursor movement overwrite) assumes each cell = 1 column. Wide characters are 2 columns + 0 sentinel. This assumption is violated constantly and causes subtle corruption. The fix is to detect multi-column content and bypass all optimizations — simpler and safer than making each optimization wide-char-aware.

### Lesson 3: Inline Mode is Not Fullscreen Mode
The original UV renderer was designed for fullscreen (alternate screen). Inline mode was added later and required significant retrofitting. The scrollHeight hack was an accumulating state that never composed correctly. The simpler fix (no accumulated state, always use newlines in inline mode) is correct. SugarCraft should design for both modes from the start.

### Lesson 4: Windows is a First-Class Platform
Windows support requires significantly more code and testing. The Windows input path has had more bugs than any other component. The non-win32 terminal problem (Alacritty on Windows using xterm emulation, not Win32 Console API) means Windows can be treated as Unix in some cases and requires special handling in others. Windows cannot be an afterthought.

### Lesson 5: Accumulated State is Technical Debt
The `scrollHeight` field is a perfect example: it accumulated state across renders and required manual maintenance in multiple code paths. Removing it simplified the code and fixed bugs. Any time SugarCraft considers adding accumulated state to a renderer, it should be a red flag — prefer stateless computation.

### Lesson 6: Protocol Extension via Cell Encoding
The pattern for adding new escape sequence protocols (OSC 8 hyperlinks, OSC 66 text-sizing) is:
1. Recognize the protocol in the parser
2. Build a Cell whose Content is the full escape verbatim
3. Set Width appropriately (from protocol metadata or a convention)
4. Emit the cell normally; downstream doesn't need to know

This is an extensible pattern that SugarCraft should adopt.

### Lesson 7: The Renderer is Inherently Complex
`terminal_renderer.go` at 1589 lines is not a sign of bad design — it's a sign of the problem domain. Terminal escape sequences, capability detection, optimization selection, cursor movement, wide character handling, style diffing, and scroll optimization all interact. SugarCraft's renderer will be similarly complex. Extract the capability detection and optimization decision logic for testability, but don't expect to simplify the renderer itself.

---

## 21. Defensive Design Lessons

1. **Test renderer loops with timeout** — Any loop that traverses the buffer should have a test variant that times out. The `TestTransformLine_IsZeroInfiniteLoop` test in #109 is the model: create a Line with trailing zero cells, run the loop in a goroutine with `time.After(2s)`, assert it completes.

2. **Guard buffer access in loops** — Any loop accessing `newLine.At(n)` or similar must re-read on each iteration, not read once before the loop. This is the single most common bug pattern in the UV issue tracker.

3. **Width <= 0 means no optimizations** — Any cursor overwrite or cell optimization must check `Width <= 0` and skip the operation. Don't try to "handle" zero-width cells — just skip the optimization.

4. **Flush must not block event loop** — On any platform, `Flush()` can block. Always offload to a background thread. The `spawn_blocking` pattern from the lablup/all-smi fix is the correct approach.

5. **Detect capabilities at runtime** — Never assume a terminal capability. Use the `xtermCaps()` pattern to detect what's actually supported and degrade gracefully.

6. **Handle all C1 control byte collisions** — The `0x9C` UTF-8 collision is a specific instance of a general pattern: C0/C1 control bytes can appear as UTF-8 continuation bytes. Any escape sequence parser must handle this.

7. **Restore terminal state on panic** — The `defer t.Shutdown(ctx)` pattern must be pervasive. Any early return without cleanup is a bug. Use `panic recover` in top-level Run to ensure restore is called.

8. **Test with CJK and emoji** — The wide character rendering bugs only surfaced because of CJK/emoji content. SugarCraft's test suite must include wide character cases for every renderer operation.

---

## 22. Ecosystem Trends

1. **Kitty Protocols Maturing** — OSC 66 (text-sizing) and OSC 1337 (graphics) are becoming expected features in terminals. UV is adding support for these. SugarCraft should track this trend.

2. **Inline TUIs Gaining Popularity** — The Charm ecosystem values inline mode ("maintaining user context and flow"). This is a deliberate positioning against fullscreen-only TUIs. SugarCraft should support both modes.

3. **Bandwidth Optimization is Critical** — The explicit emphasis on "minimal bandwidth, critical for SSH" in the README reflects real demand for TUI applications over network connections. SugarCraft must prioritize bandwidth-efficient rendering.

4. **Pre-1.0 API Instability is Acceptable** — UV's rapid API evolution is tolerated because it's v0.x. SugarCraft should establish its API early and be willing to break it before v1.0, but provide clear migration paths.

5. **Third-Party Cassowary Implementations** — The constraint solver space is being explored by multiple parties. SugarCraft doesn't need to build this from scratch.

---

## 23. Strategic Opportunities

### 24-Hour Wins

1. **Adopt UV's Screen interface literally** — 4 methods, exactly named. This ensures maximum compatibility with any UV ecosystem tooling.

2. **Copy the WaitGroup fix for async lifecycle** — The `StreamEvents` race fix (`sync.WaitGroup` to join goroutines) should be directly ported to SugarCraft's async event handling.

3. **Implement wide-char bypass in renderer** — When a line contains any non-`Width=1` cell, bypass all column-based optimizations. This single decision prevents the entire class of wide-char corruption bugs.

4. **Add timeout-based renderer loop tests** — Every loop over buffer cells should have a concurrent variant with `time.After` timeout, modeled on `TestTransformLine_IsZeroInfiniteLoop`.

### Medium-Term Investments

5. **Port Characters()/Lines()/Wrapper from PR #93** — This API is well-designed and addresses a real gap. Porting it to `sugar-bits` gives SugarCraft first-class text-to-cells conversion with word wrapping.

6. **Build non-blocking flush architecture** — Separate diff computation from I/O. Compute diffs in the event loop; offload write+flush to a worker thread/process. This enables responsive SSH TUI applications.

7. **Implement synchronized updates (mode 2026)** — A single boolean option that wraps output in synchronized-update control sequences for flicker-free rendering.

8. **Kitty text-sizing support** — Follow the PR #108 pattern for OSC 66. This enables scaled glyphs in Kitty terminals, a differentiating feature.

### Long-Term Bets

9. **Reflow-on-resize** — When UV properly implements word-wrap-aware resize, port it to SugarCraft. This is a highly requested feature in the ecosystem.

10. **Kitty graphics protocol (OSC 1337)** — Inline images in Kitty. Significant complexity, but would differentiate SugarCraft as the most feature-complete PHP TUI solution.

11. **Cassowary solver** — Either port UV's implementation or adopt metafates' third-party version. This enables sophisticated constraint-based layouts (ratatui-style).

---

## 24. Cross-Ecosystem Pattern Matches

### Ratatui (Rust)
Ultraviolet's Cassowary layout solver was derived from Ratatui. The Cell/Buffer/Line/Screen model is similar. Ratatui's documentation and testing patterns should be studied for SugarCraft implementation guidance.

### bubble tea (Go)
UV is the foundational layer for Bubble Tea v2. Any breaking change in UV causes immediate breakage in Bubble Tea v2. SugarCraft's relationship to its consumer frameworks (whatever builds on candy-core/sugar-bits) will be similar. Pin UV versions until 1.0.

### ncurses
UV is consciously "ncurses without terminfo/termcap." The cell-based diffing approach and the "cursed render" moniker acknowledge the ncurses heritage. Understanding ncurses quirks (tab handling, cursor movement, scroll regions) helps explain UV design decisions.

### iTerm2 / Kitty
Both terminals implement extended protocols (SGR mouse, OSC 66 text-sizing, OSC 1337 graphics). UV supports the subset that is widely implemented. SugarCraft should match this conservative approach — support what's universal, not what's newest.

---

## 25. High ROI Recommendations

### Immediate (Before Porting candy-core Renderer)

1. **Study the `transformLine` function completely** — It's the most complex and most-bug-prone function. Understand every optimization branch before designing SugarCraft's equivalent.

2. **Establish wide-char handling policy** — Will SugarCraft use sentinel zero-width cells (UV pattern) or store wide characters as single cells with metadata? Choose now; changing later is expensive.

3. **Design async flush strategy** — ReactPHP's event loop + terminal I/O requires a threading model. Decide whether to use-process-fork (ReactPHP child) or php pthread extension or simply non-blocking stream writes. This is architectural.

4. **Write the infinite loop test pattern** — Create a test utility that runs any buffer-traversal operation with a 2-second timeout, asserts it completes. Use this for all buffer iteration code.

5. **Audit all loops over cells** — Use static analysis or manual review to find every `for`/`while` loop that accesses `Line.At()` or `Buffer.CellAt()`. Verify each one re-reads from the buffer inside the loop body.

### Short-Term (Renderer MVP)

6. **Implement minimal Screen interface** — 4 methods exactly as UV defines them. This is the contract everything else builds on.

7. **Build RenderBuffer with touched-line tracking** — The foundation of UV's bandwidth optimization. Start with simple dirty-line tracking; optimize later.

8. **Implement Style diffing** — The `Style.Diff(from)` logic is complex (handles all attributes, colors, underline color, correct SGR ordering). Port or rewrite carefully.

9. **Add color profile detection and conversion** — Detect TrueColor/ANSI256/ANSI at startup. Use UV's `colorprofile` package or equivalent.

10. **Handle inline mode from day one** — Don't design for fullscreen only. The scrollHeight hack removal proved inline mode was retrofitted. Design both modes in.

### Medium-Term (Feature Parity)

11. **Add Characters()/Lines()** — Port from PR #93. This gives SugarCraft users a ergonomic API for converting strings to cell arrays.

12. **Implement cursor movement with overwrite optimization** — Port the 4-method cursor movement optimization (direct write vs. escape sequences) with wide-char guards.

13. **Add Window hierarchy** — Port `NewWindow`/`NewView` from UV. This enables nested views and is the basis for the sugar-sprinkles windowing layer.

14. **Implement Kitty text-sizing (OSC 66)** — Port PR #108. This is the most recent major feature addition and represents the pattern for extending SugarCraft with new escape sequence protocols.

15. **Add `screen.Context` drawing helpers** — Port from UV's `screen` package. Provides `DrawString`, `Print`, `SetStyle`, `SetForeground`, etc. as a ergonomic layer over the Screen interface.

---

*Report compiled: May 2026*
*Data sources: GitHub issues, PRs, discussions, commit history for charmbracelet/ultraviolet*
*Coverage period: Oct 2025 – May 2026 (most recent activity)*
