# SugarCraft CandyMosaic — Update Report

## Metadata
- **Library:** `candy-mosaic` (`sugarcraft/candy-mosaic`)
- **Namespace:** `SugarCraft\Mosaic`
- **Upstream:** `charmbracelet/x/mosaic` (Go)
- **Status:** 🟢 v1 ready (public API + tests + docs + demo)
- **PHP:** ^8.3 | **ext-gd:** Required
- **Key Dependencies:** `sugarcraft/candy-core`, `sugarcraft/candy-sprinkles`, `react/promise`

---

# Overview

CandyMosaic is the most complete PHP terminal image rendering library, rendering PNG/JPEG/static GIF images into terminal emulators via six graphics protocols (Kitty, iTerm2, Sixel, HalfBlock, QuarterBlock, Chafa) with automatic terminal capability detection.

**Biggest opportunity areas:**
- Parallel base64 encoding for large images (Go-termimg achieves ~33% speedup via sync.Pool)
- Sliced/partial rendering for scrollable image support (shipped in ratatui-image v11)
- Background color detection for Sixel transparency handling
- JPEG encoding option for iTerm2 (currently always PNG)
- Dynamic content generation via callback pattern
- Larger LRU cache (4 entries vs go-termimg's 100)

**Biggest missing capabilities:**
- No WebP support (ext-gd limitation shared with all implementations)
- No Unicode placeholder mode for Kitty scrolling (experimental in go-termimg)
- No request coalescing in async pipeline
- No built-in diagnostic/debug mode for terminal capability reporting

---

# Internal Capability Summary

## Current Architecture

### Source Tree
```
candy-mosaic/src/
├── AdaptiveImage.php              # LRU memoizing wrapper (key: "WxH")
├── Animation.php                   # Immutable frame sequence value object
├── AnimationDriver.php             # Model implementing candy-core tick() API
├── AsyncRenderer.php               # Interface for async rendering
├── Capability.php                 # Immutable terminal capability snapshot
├── CellSize.php                   # Font-size value object (cellW × cellH)
├── Detect.php                      # Terminal probing (env + DA1 + XTWINOPS)
├── Dither.php                      # Error-diffusion enum (None/FS/Stucki/Atkinson)
├── FrameTickMsg.php                # Internal Msg for frame-advance ticks
├── ImageSource.php                 # Image bytes + format + dimensions
├── KittyOptions.php                # Kitty protocol options (transmit/place/compress)
├── Lang.php                        # i18n facade extending SugarCraft\Core\I18n\Lang
├── Mosaic.php                      # Facade (Picker pattern) + MosaicBuilder
├── PixelGrid.php                   # 2-D grid of RGBA cells at cell resolution
├── PrecomputedImage.php            # Frozen render result with dimensions
├── Scale.php                       # Enum: Fit/Fill/Stretch/None/Crop
├── SyncAsyncRenderer.php           # ReactPHP drop-in (futureTick defer)
├── TmuxPassthroughDecorator.php   # Renderer decorator for tmux DCS envelope
└── Renderer/
    ├── ChafaRenderer.php           # External command (libchafa) fallback
    ├── HalfBlockRenderer.php        # Unicode ▀ with 24-bit fg/bg SGR
    ├── Iterm2Renderer.php          # iTerm2 OSC 1337 inline images
    ├── KittyRenderer.php           # Kitty APC graphics (chunked PNG)
    ├── QuarterBlockRenderer.php    # Unicode ░▒▓█ 2×2 sub-pixel
    ├── Renderer.php               # Interface contract
    └── SixelRenderer.php         # DEC Sixel with median-cut quantizer
```

### Current Features
- **6 protocol renderers:** Kitty, iTerm2, Sixel, HalfBlock, QuarterBlock, Chafa
- **Terminal detection:** Environment → DA1 → XTWINOPS with per-process caching
- **tmux passthrough:** Full DCS/APC/OSC envelope wrapping
- **Animation support:** `Animation` value object + `AnimationDriver` as candy-core Model
- **LRU resize cache:** 4-entry LRU keyed on cell dimensions
- **Scale modes:** Fit, Fill, Stretch, None, Crop
- **Dithering:** Floyd-Steinberg, Stucki, Atkinson for Sixel
- **Kitty compression:** gzcompress-based zlib compression
- **Kitty virtual placement:** Transmit-once, place-multiple pattern

### Strengths
- Most complete PHP terminal image library (6 protocols vs 2 in upstream)
- Clean Renderer interface with consistent contract
- Immutable/fluent API throughout
- Superior TUI integration via AnimationDriver (no upstream equivalent)
- Proper null-alpha handling in HalfBlock (many implementations miss this)
- Full tmux passthrough support
- Comprehensive i18n via Lang facade

### Weaknesses
- Smaller LRU (4 vs 100 entries in go-termimg)
- No parallel base64 encoding
- No sliced/partial rendering for scrolling
- No background color detection for Sixel
- No JPEG option for iTerm2
- No diagnostic/debug mode
- No request coalescing in async pipeline

---

# Relevant External Repositories

| Repo | Relevance | Major applicable concepts | Priority |
|------|-----------|---------------------------|----------|
| `blacktop/go-termimg` | **High** | Parallel base64 encoding, larger LRU, request coalescing, animation patterns | P0 |
| `ratatui/ratatui-image` | **High** | SlicedImage for scrolling, background color query, Size vs Rect refactor, graceful fallback patterns | P0 |
| `charmbracelet/x/mosaic` | **High** | Primary upstream reference, Kitty/Sixel encoding | P1 |
| `charmbracelet/bubbletea` | **Medium** | Elm architecture integration patterns, component model | P2 |
| `textualize/textual` | **Medium** | Reactive state, CSS layout, widget compositing | P2 |
| `charmbracelet/lipgloss` | **Low** | Style system, layer compositing (adjacent, not image-specific) | P3 |

---

# Feature Gap Analysis

## Critical Priority

### 1. Parallel Base64 Encoding
**Title:** Add parallel chunk encoding for large images
**Description:** Go-termimg achieves ~33% speedup via `sync.Pool` buffer reuse + goroutine worker pool for base64 encoding. PHP's single-threaded nature makes this challenging but achievable via ReactPHP async.
**Source:** `blacktop/go-termimg` (internal parallel.go)
**Implementation ideas:**
- Split base64 string into N chunks
- Process chunks in parallel via `React\Promise\PromiseInterface` with `React\Promise\Deferred` in worker pool
- Use `clue/reactphp-promise-stream` or `pcntl_fork()` for parallelization
- Reassemble results in order
**Estimated complexity:** High (requires async worker pool architecture)
**Expected impact:** 20-30% reduction in render time for large images on Kitty/iTerm2

### 2. Graceful Fallback Picker
**Title:** Terminal probing should never panic — implement `Mosaic::auto()`
**Description:** Ratatui-image issues #69, #68, #72, #64 all stem from terminal probing failures producing unhelpful errors (`Timeout`, `NoFontSize`). CandyMosaic should implement a fallback pattern that tries capability query and falls back to sensible defaults.
**Source:** `ratatui/ratatui-image.md` — Issues #69, #68
**Implementation ideas:**
```php
public static function auto(?CellSize $default = null): self {
    $default ??= new CellSize(8, 12);
    try {
        return self::probe();
    } catch (\Throwable $e) {
        return self::halfBlock(); // Safe universal fallback
    }
}
```
**Estimated complexity:** Low
**Expected impact:** Eliminates user-facing failures on Windows, SSH, old terminals

### 3. SlicedImage / Partial Rendering
**Title:** Add band-sliced rendering for scrollable large images
**Description:** Ratatui-image v11 shipped `SlicedImage` support for vertical scrolling via protocol-specific slicing. Each protocol handles it differently: Kitty (natural), Sixel (6-pixel bands), iTerm2 (base64 PNG slices).
**Source:** `ratatui/ratatui-image.md` — PR #171, Feature #1
**Implementation ideas:**
- Add `SlicedRenderer` interface with `renderSlice($image, $cellW, $cellH, $skipLines, $dropLines)`
- Each renderer implements protocol-specific slicing math
- Band indexing from `ratatui-image/src/sliced.rs:L310-331`
**Estimated complexity:** Medium
**Expected impact:** Enables smooth scrolling for large images in terminals

---

## High Value

### 4. Terminal Diagnostic Mode
**Title:** Add `Mosaic::diagnose()` for debug reporting
**Description:** Users currently struggle to understand why images don't appear. Built-in diagnostic mode would report detected terminal type, supported protocols, font size, background color, and any probing failures.
**Source:** `ratatui/ratatui-image.md` — Section 19, Feature 1
**Implementation ideas:**
```php
public function diagnose(): DiagnosticReport {
    return new DiagnosticReport([
        'terminal' => $this->detectTerminal(),
        'protocol' => $this->selectedProtocol->name,
        'fontSize' => $this->cellSize,
        'backgroundColor' => $this->queryBackgroundColor(),
        'failures' => $this->probingFailures,
    ]);
}
```
**Estimated complexity:** Medium
**Expected impact:** Dramatically reduces user confusion and support burden

### 5. LRU Cache Size Increase
**Title:** Increase AdaptiveImage cache from 4 to 64+ entries
**Description:** Go-termimg uses 100-entry LRU keyed on `widthxheight_path_srcWidthxsrcHeight`. CandyMosaic uses only 4 entries with simpler key. Larger cache reduces repeated resize operations.
**Source:** `blacktop/go-termimg` (cache.go)
**Implementation ideas:**
- Change `$maxCache` from `4` to `64`
- Optionally include source dimensions in cache key for more precise caching
- Add cache statistics method for debugging
**Estimated complexity:** Low
**Expected impact:** 10-15% reduction in resize overhead for typical TUI usage

### 6. Background Color Detection for Sixel
**Title:** Query terminal background color for Sixel transparency handling
**Description:** Ratatui-image PR #174 added OSC query for terminal background color to enable proper Sixel transparency. Without background color, transparent Sixel pixels use terminal default which may not match.
**Source:** `ratatui/ratatui-image.md` — PR #174, Feature 6
**Implementation ideas:**
- Emit OSC 11 query (`\x1b]11;?\x07`)
- Parse response with state machine (similar to DA1 parsing)
- Cache result per-process
**Estimated complexity:** Medium
**Expected impact:** Proper Sixel transparency on terminals with non-default backgrounds

### 7. JPEG Encoding Option for iTerm2
**Title:** Add JPEG encoding option to Iterm2Renderer
**Description:** Currently `Iterm2Renderer` always uses PNG encoding. For photographic images, JPEG is significantly smaller, improving performance.
**Source:** `blacktop/go-termimg` (iterm2.go)
**Implementation ideas:**
```php
public function renderAsJpeg(ImageSource $image, int $width, ?int $height = null, int $quality = 85): string
```
- Add `withFormat('jpeg')` option to renderer
- Use `imagejpeg($img, null, $quality)` instead of `imagepng()`
**Estimated complexity:** Low
**Expected impact:** 30-50% size reduction for photographic images

---

## Medium Priority

### 8. Custom Scale Modes via Callback
**Title:** Add `Scale::custom(callable $fn)` for flexible scaling
**Description:** Users want arbitrary height/width scaling factors, not just Fit/Crop/Scale. Discussion #96 in ratatui-image shows demand for `Resize::custom(fn)` pattern.
**Source:** `ratatui/ratatui-image.md` — Discussion #96
**Estimated complexity:** Low
**Expected impact:** Enables custom scaling logic without fork

### 9. Request Coalescing in Async Pipeline
**Title:** Coalesce rapid async render requests
**Description:** Go-termimg implements request coalescing where rapid resize requests for same image collapse into single render. Prevents redundant work during fast window resizes.
**Source:** `blacktop/go-termimg` (async.go)
**Estimated complexity:** Medium
**Expected impact:** Reduced CPU during rapid viewport changes

### 10. Unicode Placeholder Mode (Kitty)
**Title:** Implement Kitty Unicode placeholder for scrolling
**Description:** Go-termimg implements experimental Unicode placeholder mode using U+10EEEE + combining diacritics for scrolling support. Not yet stable but shows future direction.
**Source:** `blacktop/go-termimg` (kitty.go:unicodePlaceholders)
**Estimated complexity:** Medium
**Expected impact:** Enables smooth scrolling in Kitty terminals

### 11. Protocol-Specific Clipping Consistency
**Title:** Default to no clipping, add opt-in `allowClipping()`
**Description:** Ratatui-image v11 changed default clipping behavior for consistency: none clip by default, `allow_clipping(true)` for explicit control. CandyMosaic's current behavior varies by protocol.
**Source:** `ratatui/ratatui-image.md` — PR #168
**Estimated complexity:** Low
**Expected impact:** Consistent cross-protocol behavior

---

## Low Priority

### 12. WebP Support via PHP 8.4+ FFI
**Title:** Add WebP support using FFI to libwebp
**Description:** Both candy-mosaic and go-termimg lack WebP support due to ext-gd limitations. PHP 8.4+ with FFI could enable direct libwebp binding.
**Source:** `blacktop/go-termimg` (webp.go — planned)
**Estimated complexity:** High
**Expected impact:** Support for next-generation format

### 13. Callback-Based Protocol Generation
**Title:** Add `Protocol::fromGenerator($callback)` for dynamic content
**Description:** Ratatui-image Issue #71 requested callback-based protocol generation for dynamic content (waveforms, real-time visualizations). Closed as "too large" but valid use case.
**Source:** `ratatui/ratatui-image.md` — Issue #71
**Estimated complexity:** Medium
**Expected impact:** Enables dynamic content generation

### 14. ImageSource Privacy Cleanup
**Title:** Consider making ImageSource internal to protocol implementations
**Description:** Ratatui-image v11 made `ImageSource` private because it confused users as public API. Consider whether candy-mosaic's `ImageSource` should be public.
**Source:** `ratatui/ratatui-image.md` — PR #170
**Estimated complexity:** Low
**Expected impact:** Cleaner API surface

---

# Algorithm / Performance Opportunities

## Base64 Encoding
**Current approach:** Single-threaded `base64_encode()` on full PNG bytes
**External approach:** `sync.Pool` buffer reuse + parallel chunk encoding via worker pool
**Why external is better:** ~33% faster for large images; avoids allocation overhead
**Tradeoffs:** PHP's single-threaded nature requires async worker pool complexity
**Applicability:** High — would benefit all PNG-based protocols (Kitty, iTerm2)

## LRU Cache
**Current approach:** 4-entry LRU keyed on cell dimensions only
**External approach:** 100-entry LRU keyed on `widthxheight_path_srcWidthxsrcHeight`
**Why external is better:** Larger cache + more precise key = fewer redundant resizes
**Tradeoffs:** Memory vs speed tradeoff; current 4-entry is minimal but may thrash
**Applicability:** Medium — current cache is adequate for typical usage

## Sixel Band Slicing
**Current approach:** Full image render only
**External approach:** Band-sliced rendering for scrollable viewport
**Why external is better:** Enables smooth scrolling for large images
**Tradeoffs:** Complexity; only needed for large image use cases
**Applicability:** Medium — scrolling is common in file viewers, image galleries

## Async Request Coalescing
**Current approach:** Each async render request processes independently
**External approach:** Channel-based request coalescing for same image/resize
**Why external is better:** Avoids redundant work during rapid viewport changes
**Tradeoffs:** Complexity of tracking in-flight requests
**Applicability:** Low — only matters for rapid resize scenarios

---

# Architecture Improvements

1. **Size vs Area separation:** Ratatui-image v11 refactored from `Rect` to `Size` for non-positioned parameters. Consider renaming `Mosaic::render($image, $cellWidth, $cellHeight)` parameter types to distinguish dimensions from positioned areas.

2. **Graceful fallback architecture:** Implement `Mosaic::auto()` that never fails — always returns a usable renderer with sensible defaults.

3. **Diagnostic subsystem:** Add `Mosaic::diagnose()` that produces structured report of all detected capabilities and any probing failures.

4. **Async pipeline redesign:** Redesign `SyncAsyncRenderer` to support request coalescing similar to go-termimg's `AsyncRenderWorker`.

5. **Protocol encapsulation:** Ensure protocol-specific details (Sixel bands, Kitty placeholders) remain encapsulated — don't expose in public API.

---

# API / Developer Experience Improvements

1. **Comprehensive error messages:** Error messages should enumerate ALL possible failure causes (e.g., "Timeout — possible causes: terminal doesn't support probing, stdout is locked, platform is Windows").

2. **Unit documentation:** Every geometry-related API must specify units (pixels vs cells) in docblock.

3. **Resize semantics clarity:** Document `Scale::Fit` as "preserve aspect ratio while fitting within bounds" — similar to ratatui-image's confusion between Fit/Crop/Scale.

4. **Initialization order documentation:** Explicitly document that capability probing must happen before stdout locking.

5. **Minimum version matrix:** Document minimum terminal versions for each protocol (Kitty 0.26+ for graphics, etc.).

---

# Documentation / Cookbook Opportunities

1. **Protocol comparison guide:** When to use each protocol (quality vs compatibility vs speed)
2. **Troubleshooting guide:** Common issues (images don't appear, wrong colors, poor quality)
3. **Performance tuning:** LRU cache sizing, when to use compression, async patterns
4. **Animation cookbook:** Real-world examples of `AnimationDriver` usage in TUI apps
5. **Platform-specific notes:** Windows Terminal, WSL, SSH, tmux differences
6. **Migration guide from go-termimg:** For users coming from Go ecosystem

---

# UX / TUI Improvements

1. **Graceful degradation:** When capability probing fails, fall back to HalfBlock instead of throwing
2. **Diagnostic output:** `Mosaic::diagnose()` should output human-readable terminal capability report
3. **Progress indication:** For large image rendering, consider progress callback
4. **Consistent clipping behavior:** Default to no clipping across all protocols
5. **Verbose mode:** `-v` flag or `Mosaic::verbose()` that prints selected protocol + parameters

---

# Testing / Reliability Improvements

1. **Protocol interoperability tests:** Test same image across all protocols and verify no crashes
2. **Terminal edge case tests:** Test image rendering at terminal boundaries (top, bottom, corners)
3. **Even/odd dimension tests:** Centering behavior differs for even-dimension terminals (documented in bubbletea-overlay)
4. **Position + offset combinatorial tests:** Table-driven tests for all scale + position combinations
5. **Async race condition tests:** Verify no race conditions in `SyncAsyncRenderer`
6. **Fixture expansion:** Add more test fixtures (RGBA, palette, large images, animated GIF frames)
7. **Snapshot tests:** Expand golden/snapshot tests for all renderers (currently only HalfBlock has comprehensive snapshots)

---

# Ecosystem / Integration Opportunities

1. **candy-flip integration:** Bridge for decoding animated GIF frames into `ImageSource[]` for use with `AnimationDriver`
2. **candy-vcr integration:** Record/replay of image rendering for testing
3. **sugar-charts integration:** Use `Mosaic` to render chart output (heatmaps, etc.)
4. **sugar-prompt integration:** Render images inline in prompt sessions
5. **VHS demo tape support:** Add `candy-mosaic` to `.vhs/` examples for documentation

---

# Notable PRs / Issues / Discussions

## From ratatui/ratatui-image:

### Issue #69: Windows Platform Failures (7 comments)
- Terminal probing fails on Windows Terminal and CMD.EXE
- Workaround: `from_fontsize()` fallback
- **Lesson:** Provide graceful fallback, don't panic on platform differences

### Issue #68: Stdout Lock Causes Picker Failure (5 comments)
- `lock()` on stdout causes instant timeout during probing
- **Lesson:** Document initialization order, provide fallback path

### PR #171: v11.0.0 Major Release
- Added `SlicedImage` + `SlicedProtocol` for scrolling support
- `FontSize` refactor: tuple → struct with named fields
- `Rect` → `Size` refactor for non-positioned dimensions
- **Lesson:** Willingness to make breaking changes for long-term clarity

### PR #174: Add Option to Query Background Color with OSC
- Adds terminal background color detection for Sixel transparency
- **Lesson:** Small features can have significant impact for edge cases

### PR #168: Image::allow_clipping(bool) and Docs
- Kitty/HalfBlocks now consistent with Sixel/ITerm2 behavior
- **Lesson:** Consistency across protocols matters; provide opt-in for special cases

### Discussion #96: Custom Image Scale (OPEN)
- Users want arbitrary scaling factors, not just Fit/Crop/Scale
- **Lesson:** Consider adding `Resize::custom(fn)` callback pattern

## From blacktop/go-termimg:

- Parallel base64 encoding via sync.Pool (~33% speedup)
- Request coalescing in async pipeline
- 100-entry LRU cache with source dimensions in key
- Unicode placeholder mode for Kitty scrolling (experimental)

## From rmhubbert/bubbletea-overlay:

### PR #19: Viewable interface
- Relaxed from `tea.Model` requirement to `interface { View() string }`
- **Lesson:** Framework-agnostic interfaces enable broader use

### v0.5.1: Offset/position bugs
- Center + offset produces surprising results
- **Lesson:** Position/offset interaction must be documented clearly

---

# Recommended Roadmap

## Immediate Wins (0-2 sprints)
1. Implement `Mosaic::auto()` with graceful fallback — **Low effort, high impact**
2. Increase LRU cache from 4 to 64 entries — **Low effort, medium impact**
3. Add `Mosaic::diagnose()` for debug reporting — **Low effort, high impact**
4. Add JPEG encoding option to Iterm2Renderer — **Low effort, medium impact**
5. Expand snapshot tests to cover all renderers — **Low effort, high impact**

## Medium-Term Improvements (2-4 sprints)
1. Add background color detection for Sixel — **Medium effort, medium impact**
2. Implement protocol-specific `SlicedRenderer` for scrolling — **Medium effort, high impact**
3. Add `Scale::custom(callable)` for flexible scaling — **Low effort, medium impact**
4. Implement parallel base64 encoding via ReactPHP worker pool — **High effort, medium-high impact**
5. Add request coalescing to async pipeline — **Medium effort, medium impact**

## Major Architectural Upgrades (4-8 sprints)
1. Full SlicedImage support with per-protocol slicing math
2. WebP support via PHP 8.4+ FFI to libwebp
3. Unicode placeholder mode for Kitty scrolling
4. Dynamic content generation via callback pattern

## Experimental Ideas (backlog)
1. Canvas-based rendering with layer compositing
2. Mouse-interactive image regions
3. Video frame extraction for animation
4. Remote image rendering via URL

---

# Priority Matrix

| Opportunity | Impact | Complexity | Risk | Recommended Priority |
|------------|--------|------------|------|---------------------|
| Mosaic::auto() graceful fallback | High | Low | Low | P0 — Immediate |
| Mosaic::diagnose() debug mode | High | Low | Low | P0 — Immediate |
| Increase LRU to 64 entries | Medium | Low | Low | P1 — Sprint 1 |
| JPEG encoding for iTerm2 | Medium | Low | Low | P1 — Sprint 1 |
| Expand snapshot tests | High | Low | Low | P1 — Sprint 1 |
| SlicedImage for scrolling | High | Medium | Medium | P1 — Sprint 2 |
| Background color detection | Medium | Medium | Low | P2 — Sprint 2 |
| Scale::custom callback | Medium | Low | Low | P2 — Sprint 2 |
| Parallel base64 encoding | High | High | Medium | P2 — Sprint 3 |
| Request coalescing | Medium | Medium | Medium | P3 — Sprint 3 |
| WebP via FFI | Medium | High | High | P3 — Backlog |
| Unicode placeholder (Kitty) | Medium | Medium | High | P3 — Backlog |
| Callback-based generation | Medium | Medium | Low | P3 — Backlog |

---

# Final Strategic Assessment

CandyMosaic is a **mature, well-architected** PHP library that represents the most complete terminal image rendering capability in the PHP ecosystem. Its six-protocol approach (Kitty, iTerm2, Sixel, HalfBlock, QuarterBlock, Chafa) with automatic capability detection provides excellent terminal coverage.

**Key differentiators vs upstream (charmbracelet/x/mosaic):**
- Superior protocol coverage (6 vs 2)
- Full terminal detection with caching
- Animation driver integration with candy-core
- LRU memoization
- tmux passthrough
- i18n support

**Key gaps vs go-termimg:**
- No parallel base64 encoding (~33% potential speedup)
- Smaller LRU cache (4 vs 100)
- No request coalescing
- No experimental Unicode placeholder mode

**Key architectural insights from ratatui-image v11:**
- Graceful fallback over panics — implement `auto()` that never fails
- Error messages should enumerate ALL failure causes
- Size vs Rect distinction — use Size for non-positioned parameters
- SlicedImage for scrolling support is proven user need
- Willingness to make breaking changes pre-1.0 for long-term clarity

**Strategic recommendations:**
1. **Prioritize robustness:** `auto()` fallback and `diagnose()` mode will eliminate most user pain points
2. **Match go-termimg performance:** Parallel encoding and larger LRU are achievable with ReactPHP
3. **Lead on TUI integration:** AnimationDriver is unique to SugarCraft — expand this advantage
4. **Document terminal versions:** Minimum version matrix prevents user confusion
5. **Don't fear breaking changes:** Pre-1.0 is the time to clean up API surface

The library is production-ready for v1 with clear upgrade paths for post-v1 features like SlicedImage and WebP support.

---

*Report generated: 2026-05-27*
*Source files: `docs/repo_map/sugarcraft_candy-mosaic.md`, `candy-mosaic/README.md`, `candy-mosaic/CALIBER_LEARNINGS.md`*
*Comparison repos: `blacktop/go-termimg`, `ratatui/ratatui-image`, `charmbracelet/x/mosaic`, `charmbracelet/bubbletea`, `textualize/textual`, `charmbracelet/lipgloss`, `rmhubbert/bubbletea-overlay`*
