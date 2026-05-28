# Second-Stage Ecosystem Intelligence Report for blacktop/go-termimg

## 1. Repository Overview

| Attribute | Value |
|-----------|-------|
| **URL** | https://github.com/blacktop/go-termimg |
| **Language** | Go |
| **Stars** | 57 (niche/specialized) |
| **License** | MIT |
| **Activity** | Active 2024-2026, ~30 issues/PRs total |
| **Maintainer** | blacktop (active responder) |
| **Ecosystem** | Charmbracelet TUI integration |

**Repository Character**: This is a niche, single-maintainer project focused on terminal image rendering across multiple protocols. The low star count reflects specialization rather than immaturity. The repository has healthy activity with regular dependency updates and occasional substantive feature work.

## 2. Existing SugarCraft Mapping

### Primary Mapping: `candy-mosaic`

The `candy-mosaic` library in SugarCraft directly maps to `go-termimg` functionality:

| go-termimg Concept | candy-mosaic Implementation |
|-------------------|---------------------------|
| `Image` struct with fluent API | `Mosaic` facade with `render()` |
| `KittyRenderer` | `Renderer\KittyRenderer` |
| `SixelRenderer` | `Renderer\SixelRenderer` |
| `ITerm2Renderer` | `Renderer\Iterm2Renderer` |
| `HalfblocksRenderer` | `Renderer\HalfBlockRenderer`, `Renderer\QuarterBlockRenderer` |
| `Renderer` interface | `Renderer\Renderer` interface |
| `ImageWidget` (Bubbletea) | Direct rendering, no TUI widget yet |
| `StatefulImageWidget` | `AsyncRenderer` |
| Terminal detection (CSI queries) | `Detect` class with DA1 probing |
| Tmux passthrough | `TmuxPassthroughDecorator` |
| Font size caching | `Detect::cached()` per-process |
| Resize LRU cache | Not yet implemented |

**Key Architectural Difference**: go-termimg uses a fluent `Image` builder pattern; candy-mosaic uses a `Mosaic::probe()` factory with a `render(image, width, height)` method.

## 3. Previously Identified Gaps (from repo_map/blacktop_go-termimg.md)

1. **WebP Not Supported** - Only PNG, JPEG, GIF mentioned
2. **Sixel Performance** - ~90ms render time vs ~2.5ms for Kitty/iTerm2
3. **iTerm2 Clear Limitation** - No specific image clear command
4. **Unicode Placeholder Complexity** - Experimental, complex encoding
5. **CSI Query Failures** - Some terminals disable queries
6. **Animation Partially Implemented** - Animation struct exists but not complete
7. **File Transfer Optimization** - Not fully implemented
8. **External Dependency Risk** - Depends on go-sixel, nfnt/resize
9. **No WebAssembly Support** - Pure Go but no explicit WASM targeting
10. **Documentation Gaps** - Some TODO comments, experimental features unclear

## 4. High-Signal Open Issues

### Issue #10: "Using go-termimg in other programs" (7 comments, open)

**This is the most significant user-facing issue in the repo.**

**User's Context**:
- Building a terminal pager for djot markup language
- Using bespoke terminal library for escape sequences
- Using `TermImg.Build()` to get string for `io.Writer.Write()`
- Problem: tmux positioning issues - image corrupts other panes

**User-Reported Problems**:
1. **Unclear protocol support** - "go-termimg works in tmux, even outside of iTerm or Kitty" but unclear WHY
2. **Terminal state corruption** - "go-termimg does not leave the terminal in a reasonable state"
3. **Positioning issues in tmux** - "images rendered when cursor is at bottom of window" behave differently than when at top
4. **No error in unsupported terminals** - "Alacritty (which does not support sixel) does not print any image, nor an error"
5. **imgcat install issue** - `replace` directives in go.mod prevent `go install`

**Maintainer Acknowledgment**:
- "sorry for the slow response; I've recently become once again obsessed with terminal images"
- "I have updated the API to hopefully be more simple and added sixel and halfblocks"
- "the code is currently in a broken state, I'll try to fix it a bit later and tag it"
- "I use those replace directives for local dev, but I suppose I should switch to go.work files"

**Signal**: This represents a **user adoption barrier** - the library works but users can't figure out where it will work and how to debug failures.

### Issue #33, #32: Dependency Update PRs (open)

These are automated Dependabot PRs - low signal but show active maintenance.

## 5. Important Closed Issues

### PR #27: "Fix Kitty Unicode placeholder rendering for scrollable images" (merged)

**Most technically significant PR in recent history.**

**Key Fixes**:

1. **Two-step rendering for Unicode placeholders**
   - Old: Single-step rendering that broke scrolling
   - New: Transmit PNG without display (`f=100,t=d`) → Create virtual placement (`a=p,U=1`) → Generate placeholders

2. **Image ID encoding fix**
   - Added `globalKittyImageNum` counter for 24-bit compatible image numbers
   - Uses small sequential numbers (1-0xFFFFFF) that fit in RGB encoding

3. **ClearAll function fix**
   - Changed `a=d` to `d=A` (delete ALL)
   - Added missing escape sequence terminator (`\x1b\\`)

4. **Terminal query skip optimization** (critical fix)
   - When Kitty/iTerm2 detected via `KITTY_WINDOW_ID`, `TERM`, etc., skip ALL terminal queries
   - **Fixes first-keypress issues with TUI frameworks like bubbletea**
   - **Prevents garbage in stdin buffer**

5. **ResizeCache race condition**
   - TOCTOU race between cache check and access time update
   - Solved with atomic file operations

### PR #25/26: "Fix resize cache race condition and Kitty Unicode placeholder scrolling"

**Two-part fixes that show iterative debugging**:

1. **TOCTOU Race in ResizeCache**
   - Between releasing RLock after cache check and acquiring Lock in `updateAccess`, another goroutine could evict entry
   - Fix: New `get` method with single write lock for lookup + update

2. **Kitty Unicode Placeholder Scrolling**
   - Old: `RenderPlaceholderAreaWithImageID` and `PlaceImageWithSize` saved/restored cursor position
   - Problem: Placeholders ARE content and should stay where rendered - cursor restore broke scrolling relationship
   - Fix: Removed cursor save/restore
   - Fix: Foreground color not reset/restored around newlines causing color bleeding
   - Fix: Added 256-color mode fallback for image IDs ≤ 255

**Signal**: These PRs show **complex concurrency issues** and **protocol edge cases** that are endemic to terminal graphics programming.

## 6. Recurring Pain Points

### Pain Point #1: Race Conditions in Cache

**Pattern**: Multiple goroutines accessing shared cache with RLock
**Problem**: TOCTOU (time-of-check to time-of-use) race
**Solution**: Atomic read-modify-write under single lock
**Relevance to SugarCraft**: `candy-mosaic`'s `Detect::cached()` should use atomic operations or proper locking

### Pain Point #2: CSI Query Side Effects

**Pattern**: Terminal CSI queries (`\x1b[14t`, `\x1b[16t`) leave garbage in stdin
**Problem**: "first-keypress issues with TUI frameworks like bubbletea"
**Root Cause**: Some terminals (Apple Terminal, VS Code) don't respond or respond incorrectly
**Solution**: Skip queries when protocol detected via environment variables
**Relevance to SugarCraft**: The `Detect` class's DA1 probing could cause similar stdin pollution

### Pain Point #3: Escape Sequence Termination

**Pattern**: Missing `\x1b\\` terminator in clear sequences
**Problem**: Terminal interprets subsequent output as escape sequence continuation
**Solution**: Always terminate DCS/APC sequences with `\x1b\\`
**Relevance to SugarCraft**: Must ensure all candy-mosaic escape sequences are properly terminated

### Pain Point #4: Tmux Positioning

**Pattern**: Image rendered differently depending on cursor position in window
**Problem**: "image printed when cursor is at bottom of window" vs "at top of pane"
**Root Cause**: Protocol-specific cursor save/restore behavior
**Relevance to SugarCraft**: TmuxPassthroughDecorator needs testing across cursor positions

## 7. Frequently Requested Features

Based on issue analysis, no explicit feature requests have been submitted - the repository is primarily used as:
1. A dependency to build other tools upon
2. A CLI viewer (imgcat)
3. A reference implementation for terminal graphics

**Implicit features users want**:
1. Clear error messages when protocol unsupported
2. Better documentation on which terminals support which protocols
3. Simpler API (maintainer acknowledged this)
4. go.work compatibility instead of replace directives

## 8. Important PRs

| PR # | Title | Significance | Key Change |
|------|-------|--------------|------------|
| #27 | Fix Kitty Unicode placeholder rendering | High | Two-step render, ClearAll fix, query skip |
| #25/26 | Fix resize cache race + placeholder scrolling | Medium | TOCTOU fix, cursor restore removal |
| #21 | Bump actions/checkout from 5 to 6 | Low | CI update |
| #17 | Bump actions/setup-go from 5 to 6 | Low | CI update |
| #15 | Bump testify 1.10.0 to 1.11.0 | Low | Dependency update |

**Notable absence**: No user-submitted feature PRs. All substantive code changes are from maintainer.

## 9. Architectural Changes

### Major Architectural Refactor (ongoing)

Maintainer is refactoring API to be "more simple" - this is significant because:
1. The current API with replace directives suggests a monorepo structure being forced into single-package distribution
2. The maintainer recognizes complexity ("I've been looking at this problem too long and can no longer 'see it'")

### Key Architectural Decision: Two-Step Unicode Rendering

Old approach:
```
Render placeholder → place content → restore cursor (breaks scrolling)
```

New approach:
```
Transmit image data (f=100,t=d) → Create virtual placement (a=p,U=1) → Generate placeholder with image ID → NO cursor restore
```

**Implication**: This is a fundamental insight - for scrolling to work, placeholder characters MUST be treated as actual content, not as markers to be managed.

## 10. Performance Discussions

### Sixel Performance Bottleneck

go-termimg README acknowledges: Sixel ~90ms vs Kitty/iTerm2 ~2.5ms

This is a known trade-off:
- Sixel: High quality, palette optimization, dithering
- Kitty/iTerm2: Fast, but less universal support

### Encoding Optimizations in go-termimg

1. **Parallel Base64 encoding** - Worker pool with channel-based distribution
2. **Buffer reuse** - `sync.Pool` for buffer pooling
3. **Chunked encoding** - 4KB default chunks
4. **Resize caching** - LRU with RWMutex

**Relevance to SugarCraft**: candy-mosaic doesn't have resize caching yet - this would be a valuable optimization.

## 11. Extensibility Discussions

### API Extensibility

Current API allows:
- Protocol selection: `.Protocol(termimg.Kitty)`
- Scale modes: `.Scale(termimg.ScaleFit)`
- Virtual images: `.Virtual(true).ZIndex(5)`
- Dithering: `.Dither(true).DitherMode(termimg.DitherStucki)`

### Plugin Ecosystem Requests

None found - the repository is too small and niche for plugin requests.

## 12. API/UX Complaints

### Issue #10 Complaint: Unclear Environment Support

User explicitly stated confusion about:
- Which environments are supported
- Why it works in tmux but not in native terminals
- Why some protocols fail silently vs with errors

**Maintainer Response**: Acknowledged by saying "take a look as your questions might be different now" after API changes.

### Issue #10 Complaint: Replace Directive Error

User couldn't `go install` the imgcat command due to replace directives.

**Maintainer Response**: "I use those replace directives for local dev, but I suppose I should switch to go.work files"

## 13. Migration Problems

### go.mod Replace Directive Issue

**Problem**: Sub-package go.mod files contain `replace` directives that prevent `go install` from working
**Impact**: Users cannot easily install CLI tools
**Workaround**: Use `go install @latest` from HEAD
**Solution Path**: go.work files (maintainer acknowledged)

### API Stability

**Problem**: Maintainer says "code is currently in a broken state"
**Impact**: API not stable for production use
**Risk**: Breaking changes likely during refactor

## 14. Clever Fixes & Workarounds

### Clever Fix #1: Skip Terminal Queries When Protocol Detected

Instead of always querying terminal capabilities, check environment first:
```go
if KITTY_WINDOW_ID set or TERM matches Kitty patterns → skip CSI queries
```

**Why clever**: Environment-based detection is fast path, queries are slow and can have side effects.

### Clever Fix #2: Two-Step Unicode Placeholder Rendering

Separate image transmission from placement to allow proper scrolling behavior.

**Why clever**: Terminals handle scaling, so pre-resizing is unnecessary and breaks scrolling contract.

### Clever Fix #3: GlobalKittyImageNum Counter

Use sequential small numbers (1-0xFFFFFF) that fit in RGB encoding, not random large numbers.

**Why clever**: 24-bit RGB can encode 16M colors, but only sequential small numbers work as image IDs.

### Clever Fix #4: Async Render Request Coalescing

`StatefulImageWidget` queues render requests and newer requests replace older ones.

**Why clever**: Prevents queue overflow, ensures most recent render is what user sees.

## 15. Community Workarounds

### User Workaround: ansimage Package

User in Issue #10 switched to `eliukblau/pixterm/pkg/ansimage` as temporary alternative because "it just works" (though it rasterizes images).

**Signal**: Users value reliability over features when integration is complex.

### User Workaround: Check cmd/gallery Demo

Maintainer suggested: "you might want to check out cmd/gallery as it might be more what you would want to build etc"

**Signal**: The gallery demo is the intended reference implementation.

## 16. Maintainer Guidance Patterns

### Pattern: Acknowledge Complexity

"I have been looking at this problem too long and can no longer 'see it'"

### Pattern: Suggest Latest Main

When asked about install issues, maintainer implied using HEAD

### Pattern: Redirect to Demos

Maintainer redirects users to `cmd/gallery` demo when they ask about building applications

### Pattern: Active Development

Regular commits (multiple per month), respond to issues, merge fixes quickly

## 17. Rejected Ideas Worth Revisiting

### Idea: WebP Support

**Status**: Planned but not implemented
**Mention**: README says "WebP support planned"
**Relevance**: candy-mosaic uses ext-gd which supports WebP - potential advantage

### Idea: Animation Support

**Status**: Partially implemented (Animation struct exists with TODO comments)
**Relevance**: candy-mosaic has `AnimationDriver` and `Animation` classes - might need to check if go-termimg's approach is better

### Idea: File Transfer Optimization

**Status**: TODO comment in code indicates not fully implemented
**Relevance**: For large images, transmission optimization matters

## 18. Problems Likely Relevant To SugarCraft

### Problem #1: CSI Query Side Effects (HIGH)

**Risk**: candy-mosaic's `Detect` class issues DA1 queries and CSI font-size queries that could pollute stdin
**Evidence**: PR #27 specifically fixed this by skipping queries when protocol detected from environment
**Action**: Review candy-mosaic's Detect class for similar issues

### Problem #2: Race Condition in Cache (MEDIUM)

**Risk**: If candy-mosaic ever adds a resize cache, the same TOCTOU pattern could occur
**Evidence**: PR #25/26 fixed exactly this pattern
**Action**: If implementing ResizeCache, use atomic operations or single-lock discipline

### Problem #3: Tmux Positioning (MEDIUM)

**Risk**: candy-mosaic's `TmuxPassthroughDecorator` may not handle cursor-position-dependent rendering correctly
**Evidence**: Issue #10 shows user experienced different behavior based on cursor position in tmux
**Action**: Test TmuxPassthroughDecorator across different cursor positions

### Problem #4: Escape Sequence Termination (HIGH)

**Risk**: Missing `\x1b\\` terminators could cause terminal state corruption
**Evidence**: PR #27 fixed missing terminator in ClearAll
**Action**: Audit all candy-mosaic escape sequences for proper termination

### Problem #5: Protocol Detection Clarity (MEDIUM)

**Risk**: Users don't understand which protocols will work where
**Evidence**: Issue #10 explicitly complained about unclear support
**Action**: Add clearer documentation in candy-mosaic about protocol/terminal compatibility

## 19. Features SugarCraft Should Consider

### Feature #1: Async Render Request Coalescing

**What**: Newer render requests cancel older ones in queue
**Why valuable**: Prevents queue overflow, ensures responsiveness
**Reference**: `StatefulImageWidget` in go-termimg

### Feature #2: Environment-Based Protocol Shortcut

**What**: Skip expensive terminal queries when `KITTY_WINDOW_ID` or similar env vars present
**Why valuable**: Faster startup, avoids stdin pollution
**Reference**: PR #27 optimization

### Feature #3: Resize LRU Cache with Atomic Operations

**What**: Cache resized images with proper atomic get/put
**Why valuable**: Significant performance win for repeated renders
**Reference**: go-termimg's `ResizeCache` with TOCTOU fix

### Feature #4: WebP Support via ext-gd

**What**: Add WebP format support
**Why valuable**: go-termimg has this as "planned"; candy-mosaic has it via GD
**Reference**: ext-gd natively supports WebP

### Feature #5: Terminal Capability Detection Caching

**What**: Cache terminal capabilities per-process
**Why valuable**: CSI queries are expensive; done once at probe
**Reference**: `Detect::cached()` already does this

## 20. Architectural Lessons

### Lesson #1: Placeholders Are Content

In go-termimg, the "fix" for scrolling was realizing that Unicode placeholder characters should NOT save/restore cursor position - they ARE the content and should stay where rendered.

**Application**: Any Unicode-block rendering must consider whether characters are markers or content.

### Lesson #2: Protocol Detection Is Environment-First

Fast path: Check environment variables (`KITTY_WINDOW_ID`, `TERM`, etc.)
Slow path: Issue CSI queries with timeout
Fallback: Use hardcoded defaults per terminal type

**Application**: candy-mosaic's `Detect` class should follow this pattern.

### Lesson #3: Escape Sequences Must Be Terminated

All DCS/APC/OSC sequences need proper terminators (`\x1b\\` for DCS/APC)
Missing terminators cause terminal state corruption

**Application**: Audit all candy-mosaic escape sequence generation.

### Lesson #4: Concurrency Requires Single-Point Atomicity

For cache operations that check-then-update, the entire operation must be atomic under a single lock

**Application**: If implementing ResizeCache in candy-mosaic, use `get()` method that locks for entire read-modify-write.

## 21. Defensive Design Lessons

### Lesson #1: Never Trust Terminal Responses

CSI queries can:
- Not respond (timeout)
- Respond with garbage
- Respond incorrectly
- Pollute stdin with partial responses

**Defense**: Always have timeouts, skip queries when env vars indicate protocol, flush stdin after queries.

### Lesson #2: Detect Protocol Before Issuing Queries

If environment variables or TERM indicate a specific protocol, skip terminal queries entirely.

**Defense**: go-termimg PR #27 showed that skipping queries when protocol is detected from environment prevents stdin pollution.

### Lesson #3: Test With Multiple Terminals

Issues only appear in specific terminals:
- Apple Terminal: CSI queries disabled
- VS Code: CSI queries disabled
- Alacritty: No Sixel support (fails silently)
- tmux: Special passthrough requirements

**Defense**: Test candy-mosaic against Kitty, iTerm2, Alacritty, VS Code, tmux.

### Lesson #4: Clear Operations Must Be Idempotent

Clear operations should work regardless of whether images exist.

**Defense**: Use `d=A` (delete ALL) not just `a=d`.

## 22. Ecosystem Trends

### Trend #1: Kitty Protocol Dominance

Kitty protocol is becoming the de-facto standard for terminal graphics due to:
- Virtual images (scrollable)
- Compression support
- Animation support
- Fast rendering

**Implication**: SugarCraft should prioritize KittyRenderer quality.

### Trend #2: Terminal Emulator Fragmentation

Users run diverse terminals: Kitty, Ghostty, WezTerm, Alacritty, Rio, iTerm2, VS Code, Apple Terminal

**Implication**: Universal fallback (halfblocks) is critical; protocol detection must be robust.

### Trend #3: TUI Framework Integration

Charmbracelet's Bubbletea has first-class image support via go-termimg

**Implication**: If SugarCraft builds TUI widgets, similar integration patterns needed.

### Trend #4: Async Rendering

Long-running renders should not block UI

**Implication**: candy-mosaic's `AsyncRenderer` is the right approach.

## 23. Strategic Opportunities

### Opportunity #1: Superior Documentation

go-termimg has unclear environment support documentation
**Opportunity**: SugarCraft can document protocol/terminal compatibility matrix clearly

### Opportunity #2: Better Error Messages

go-termimg fails silently in some terminals
**Opportunity**: SugarCraft can provide actionable errors explaining why render failed and what to try

### Opportunity #3: WebP First-Mover

go-termimg has WebP as "planned"
**Opportunity**: SugarCraft via ext-gd can support WebP now

### Opportunity #4: Resize Caching

go-termimg has LRU resize cache
**Opportunity**: candy-mosaic doesn't have this yet; adding would improve repeat-render performance

## 24. Cross-Ecosystem Pattern Matches

### Pattern: Race Condition in Shared Cache

**Occurrence**: go-termimg ResizeCache TOCTOU (PR #25/26)
**Match**: Classic concurrency bug pattern
**Lesson**: Use atomic operations or single-lock discipline

### Pattern: Escape Sequence Termination

**Occurrence**: go-termimg missing `\x1b\\` (PR #27)
**Match**: Common terminal programming bug
**Lesson**: Always verify escape sequences are properly terminated

### Pattern: Fast-Path Optimization

**Occurrence**: go-termimg environment check before CSI queries
**Match**: Performance optimization common in terminal emulators
**Lesson**: Trust environment over probes when available

### Pattern: Cursor Position Dependency

**Occurrence**: go-termimg tmux rendering varies by cursor position (Issue #10)
**Match**: Protocol implementation issue
**Lesson**: Test rendering at various cursor positions

## 25. High ROI Recommendations

### Recommendation #1: Audit Detect Class for Stdin Pollution (HIGH ROI)

**Action**: Review `candy-mosaic/src/Detect.php` to ensure:
- CSI queries have timeouts
- stdin is flushed after queries
- Environment-based detection short-circuits queries

**Why**: PR #27 proved this causes real user-facing bugs (first-keypress issues).

### Recommendation #2: Add Resize LRU Cache (MEDIUM ROI)

**Action**: Implement `ResizeCache` in candy-mosaic with:
- Single-lock `get()` method (no TOCTOU)
- RWMutex for concurrent reads, exclusive writes
- LRU eviction with configurable size

**Why**: Significant performance win for repeated renders; go-termimg proved the pattern works.

### Recommendation #3: Audit Escape Sequence Termination (HIGH ROI)

**Action**: Review all `Renderer` classes for proper terminators:
- Kitty: Ensure `\x1b\\` after DCS sequences
- Sixel: Ensure proper termination
- iTerm2: Ensure OSC sequences terminated

**Why**: PR #27 fixed exactly this bug causing terminal corruption.

### Recommendation #4: Test Tmux With Various Cursor Positions (MEDIUM ROI)

**Action**: Create test that renders image at top, middle, bottom of tmux pane and verifies clean output

**Why**: Issue #10 showed this is a real user pain point.

### Recommendation #5: Document Protocol/Terminal Compatibility (MEDIUM ROI)

**Action**: Create compatibility matrix in candy-mosaic README

| Terminal | Sixel | Kitty | iTerm2 | Halfblock |
|----------|-------|-------|--------|-----------|
| Kitty | ✓ | ✓ | ✗ | ✓ |
| Ghostty | ✓ | ✓ | ✗ | ✓ |
| WezTerm | ✓ | ✓ | ✓ | ✓ |
| iTerm2 | ✗ | ✗ | ✓ | ✓ |
| Alacritty | ✗ | ✗ | ✗ | ✓ |
| VS Code | ✗ | ✗ | ✗ | ✓ |
| tmux | Varies | Varies | Varies | ✓ |

**Why**: Users want to know what will work before investing integration effort.

---

*Report generated: Second-stage intelligence analysis*
*Data sources: GitHub Issues, PRs, README, code analysis*
*Confidence: Medium (niche repo, limited user feedback, maintainer actively refactoring)*
