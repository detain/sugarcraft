# ratatui/ratatui-image

## Metadata
- URL: https://github.com/ratatui/ratatui-image
- Language: Rust
- Stars: ~500-700 (estimated based on active community usage)
- License: MIT
- Description: An image widget for ratatui, supporting sixels, kitty, iterm2, and unicode-halfblocks

## Feature List
- **Multi-protocol terminal image rendering**: Unifies Sixel, Kitty, iTerm2, and Unicode halfblock rendering
- **Terminal capability detection**: Queries terminal for supported graphics protocols via escape sequences
- **Font-size detection**: Automatically detects terminal font size in pixels to map image pixels to character cells
- **Stateless Image widget**: Fixed-size, non-blocking image rendering for immediate-mode TUIs
- **StatefulImage widget**: Adaptive resizing at render-time (requires thread offloading)
- **ThreadProtocol**: Offloads resize/encode to background threads to keep UI responsive
- **SlicedImage widget**: Partial image rendering for vertical scrolling scenarios
- **Resize modes**: Fit, Crop, and Scale with aspect-ratio preservation
- **Chafa integration**: Optional high-quality ASCII-art rendering via libchafa
- **Cross-platform**: Supports crossterm, termion, and termwiz backends
- **tmux passthrough**: Proper handling of graphics protocols through tmux

## Key Classes and Methods

### Core Widgets
- `Image<'a>`: Stateless fixed-size image widget with `new()`, `allow_clipping()`
- `StatefulImage<T>`: Stateful adaptive image widget with `new()`, `resize()`
- `SlicedImage<'a>`: Partially visible image for scrolling with `new()`
- `ThreadProtocol`: Non-blocking image updates via channels with `new()`, `replace_protocol()`, `update_resized_protocol()`

### Picker (Capability Detection)
- `Picker`: Terminal capability detection helper
  - `from_query_stdio()`: Query terminal for protocols and font-size
  - `from_query_stdio_with_options()`: Query with custom options
  - `halfblocks()`: Create fallback picker for halfblock rendering
  - `font_size()`: Get detected font size
  - `new_protocol()`: Create a Protocol for given image/size/resize
  - `new_resize_protocol()`: Create a StatefulProtocol for dynamic resizing

### Protocol Types
- `Protocol`: Enum of `Halfblocks(Halfblocks)`, `Sixel(Sixel)`, `Kitty(Kitty)`, `ITerm2(Iterm2)`
- `StatefulProtocol`: Stateful protocol with resize capability
- `StatefulProtocolType`: Enum variant wrapper for stateful protocols
- `ProtocolType`: Enum of `Halfblocks`, `Sixel`, `Kitty`, `Iterm2`

### Resize System
- `Resize`: Enum with `Fit(Option<FilterType>)`, `Crop(Option<CropOptions>)`, `Scale(Option<FilterType>)`
- `CropOptions`: Specifies clip_top/clip_left for cropping
- `FontSize`: Terminal font dimensions `new(width, height)`

### Protocol Implementations
- `Sixel`: Sixel graphics protocol encoder/renderer
  - `new(image, size, is_tmux)`: Encode image as sixels
- `Kitty`: Kitty graphics protocol with unicode-placeholders
  - `new(image, size, id, is_tmux)`: Transmit with unique ID
  - `render_with_skip()`: For SlicedImage support
- `Iterm2`: iTerm2 inline PNG protocol
  - `new(image, size, is_tmux)`: Encode as base64 PNG
- `Halfblocks`: Unicode halfblock ASCII rendering
  - `new(image, size)`: Render with optional chafa enhancement

### Threading
- `ResizeRequest`: Request sent to worker thread
- `ResizeResponse`: Completed resize returned from worker
- `ResizeEncodeRender` trait: `resize_encode()`, `render()`, `needs_resize()`

### Supporting
- `ImageSource`: Original image storage with hash for change detection
- `SlicedProtocol`: Enum of sliced variants per protocol
- `SignedPosition`: Signed (x, y) for partial image positioning

## Notable Algorithms / Named Patterns

### Terminal Capability Detection (cap_parser)
The `Picker` module implements terminal probing via escape sequence orchestration:
1. Write multiple queries in parallel: `_Gi=...` (Kitty), `[c` (DA1+Sixel), `[16t` (cell-size), `[1337n` (iTerm2), `[5n` (DSR)
2. Parse responses via `cap_parser::Parser`
3. Timeout-based reading with 2-second deadline
4. Interpret responses to determine protocol support and font-size

**Source:** `src/picker.rs:L94-165` and `src/picker/cap_parser.rs`

### fit_area_proportionally
Aspect-ratio-preserving resize calculation:
```rust
fn fit_area_proportionally(width, height, nwidth, nheight) -> (u32, u32) {
    let wratio = nwidth as f64 / width as f64;
    let hratio = nheight as f64 / height as f64;
    let ratio = f64::min(wratio, hratio);
    let nw = max((width as f64 * ratio).round() as u64, 1);
    let nh = max((height as f64 * ratio).round() as u64, 1);
    // ...overflow protection for u16::MAX
}
```
**Source:** `src/lib.rs:L552-570`

### Font-Size to Cell Mapping
Converts pixel dimensions to terminal cell dimensions:
```rust
fn round_pixel_size_to_cells(img_width, img_height, font_size) -> Size {
    let width = (img_width as f32 / font_size.width as f32).ceil() as u16;
    let height = (img_height as f32 / font_size.height as f32).ceil() as u16;
    Size::new(width, height)
}
```
**Source:** `src/lib.rs:L537-541`

### Sixel Band Slicing
Sixel images are sliced vertically by bands (6-pixel columns) for partial rendering:
```rust
fn bands(&self, skip_line_count, drop_line_count) -> Vec<&str> {
    let skip_bands = (skip_line_count * self.font_height as usize).div_ceil(6);
    let take_bands = (((self.size.height.saturating_sub(drop_line_count as u16)) * self.font_height) / 6) as usize;
    // ... extract relevant bands
}
```
**Source:** `src/sliced.rs:L310-331`

### Kitty Transmission Tracking
Uses `AtomicBool` to track one-time transmission:
```rust
struct KittyProtoState {
    transmitted: Arc<AtomicBool>,
    transmit_str: Option<String>,
    id: (u32, String, u16),
}
fn make_transmit(&self) -> Option<&str> {
    let transmitted = self.transmitted.swap(true, Ordering::SeqCst);
    if transmitted { None } else { self.transmit_str.as_deref() }
}
```
**Source:** `src/protocol/kitty.rs:L19-48`

## Strengths
- **Immediate-mode friendly**: Both stateless (Image) and stateful (StatefulImage) widgets work with ratatui's immediate-mode paradigm
- **Protocol abstraction**: Unified `Protocol` enum hides implementation details of different terminal graphics protocols
- **Thread-safe resizing**: `ThreadProtocol` pattern prevents UI blocking during expensive resize/encode operations
- **Comprehensive terminal support**: 12+ terminals tested and supported with compatibility matrix
- **Graceful degradation**: Falls back to halfblocks when no graphics protocol is available
- **Font-size awareness**: Proper pixel-to-cell mapping for accurate image sizing
- **Sliced rendering**: Enables smooth scrolling of large images
- **Chafa integration**: Optional high-quality ASCII-art fallback
- **tmux passthrough**: Proper handling of graphics through tmux via DCS passthrough
- **Comprehensive tests**: Snapshot tests, screenshot tests across multiple terminals

## Weaknesses
- **StatefulImage blocking risk**: Resize at render-time can block UI thread if not properly offloaded
- **termwiz backend broken**: Not working correctly (documented issue)
- **Sixel scroll issue**: Sixel images on last terminal line cause unwanted scrolling (#57)
- **Terminal-specific quirks**: Each protocol has different behaviors (e.g., WezTerm iTerm2 works, Sixel/Kitty have issues)
- **Platform-specific code**: Large conditional compilation for Windows vs Unix
- **External library dependency**: Optional libchafa for high-quality halfblocks
- **Complexity for simple use-cases**: The `ThreadProtocol` pattern adds significant boilerplate

## SugarCraft Mapping

ratatui-image is a Rust library that would map to SugarCraft's TUI image rendering capabilities:

| ratatui-image Feature | SugarCraft Lib | Notes |
|---------------------|---------------|-------|
| Sixel protocol | N/A | No sugar-something yet |
| Kitty protocol | N/A | No sugar-something yet |
| iTerm2 protocol | N/A | No sugar-something yet |
| Halfblocks (ASCII) | `sugar-bits` | Could be CharacterCell renderer |
| Image widget | `sugar-bits` | Base widget infrastructure |
| Picker/cap detection | `candy-core` | Terminal capability detection |
| StatefulImage | `sugar-charts`? | Dynamic resize behavior |
| ThreadProtocol | `candy-core` | Async/thread messaging |
| Resize algorithm | `honey-bounce`? | Image transformation math |
| FontSize detection | `candy-core` | Terminal geometry |

**Mapping Analysis:**
- **sugar-bits**: Closest match for base widget infrastructure (Image widget, Ratatui integration)
- **candy-core**: For Picker (terminal capability detection) and ThreadProtocol (async messaging)
- **honey-bounce**: For resize algorithms (fit_area_proportionally, aspect-ratio math)
- No direct SugarCraft equivalent for Sixel/Kitty/iTerm2 protocols — would be a new sugar-* or honey-* lib

## Analysis

ratatui-image solves a deeply technical problem in the terminal TUI ecosystem: unified image rendering across incompatible terminal graphics protocols. The library's design reflects careful thought about the immediate-mode paradigm — its `Image` widget is stateless and thus non-blocking, while `StatefulImage` honestly documents the blocking risk and points users to `ThreadProtocol` for proper async handling.

The architecture is built around three layers: (1) the `Picker` which detects terminal capabilities and font-size, (2) the `Protocol` enum which abstracts over four graphics backends, and (3) the widget layer which provides both stateless and stateful rendering. This separation of concerns allows the library to handle Sixel's "immediate-mode" nature differently from Kitty's stateful approach, while presenting a uniform API to ratatui users.

The code quality is high — comprehensive error handling via `thiserror`, snapshot tests for rendering, and even VM-based screenshot tests across 10+ terminals. The use of `self_cell` for the SlicedSixel owner/dependent pattern shows careful memory management. However, the library's complexity reflects its domain — terminal graphics protocols are notoriously inconsistent across terminals, and ratatui-image essentially provides a compatibility layer that abstracts away this chaos.

For SugarCraft, porting this would require significant work across multiple libs: the protocols themselves are Rust-specific (icy_sixel, direct Kitty spec implementation), but the Picker/capability detection and resize algorithms could be PHP-ified. The ThreadProtocol pattern would translate well to ReactPHP's async primitives.
