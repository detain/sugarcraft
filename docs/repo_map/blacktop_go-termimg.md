# blacktop/go-termimg

## Metadata
- **URL:** https://github.com/blacktop/go-termimg
- **Language:** Go
- **Stars:** 57
- **License:** MIT (Copyright 2024-2025 blacktop)
- **Description:** Modern terminal image library for Go - renders images in terminal emulators supporting various image protocols including Kitty, Sixel, iTerm2, and Unicode halfblocks fallback.

## Feature List

### Universal Protocol Support
- **Kitty Protocol** - Fast graphics with virtual images, z-index, compression, Unicode placeholders, animation support
- **Sixel Protocol** - High-quality with palette optimization and dithering
- **iTerm2 Protocol** - Native inline images with ECH (Erase Character) clearing
- **Halfblocks** - Unicode fallback using mosaic rendering (works everywhere)

### Image Processing
- Smart scaling modes: Auto, Fit, Fill, Stretch, None
- Advanced dithering: Floyd-Steinberg, Stucki (via dither/v2 library)
- Quality vs speed control
- Image resizing with LRU cache for performance
- Center cropping

### Terminal Integration
- Automatic protocol detection with environment-based fallbacks
- Terminal font size detection via CSI queries (CSI 14t, 16t, 18t)
- Tmux passthrough support (wraps escape sequences properly)
- Screen/Tmux environment detection

### TUI Framework Integration
- ImageWidget for Bubbletea (Charmbracelet TUI framework)
- StatefulImageWidget with async background rendering
- ImageGallery for grid layouts
- TUIHelper for protocol selection

### Performance Optimizations
- Font size caching to avoid repeated terminal queries
- Resize caching with LRU eviction
- Parallel Base64 encoding with worker pool
- Buffer reuse for encoding operations

### Command Line Tools
- `imgcat` - Terminal image viewer with protocol/size options
- `gallery` - Interactive TUI demo using Bubbletea

## Key Classes and Methods

### Core Image Types

**Image** (`termimg.go:18-44`)
- Main struct for terminal image rendering with fluent API
- `Width()`, `Height()`, `Size()` - Dimension setters (character cells)
- `WidthPixels()`, `HeightPixels()`, `SizePixels()` - Dimension setters (pixels)
- `Protocol()`, `Scale()`, `ZIndex()`, `Virtual()`, `Dither()`, `DitherMode()` - Rendering options
- `Compression()`, `PNG()`, `TempFile()` - Transfer optimizations
- `UseUnicode()` - Enable Unicode placeholders for Kitty (scrolling support)
- `Render()` - Generate escape sequence string
- `Print()` - Output to stdout
- `Clear()` - Remove image from terminal

**Renderer Interface** (`termimg.go:72-87`)
- Interface all protocol renderers must implement
- `Render(img image.Image, opts RenderOptions) (string, error)` - Generate escape sequence
- `Print(img image.Image, opts RenderOptions) error` - Output directly
- `Clear(opts ClearOptions) error` - Remove image
- `Protocol() Protocol` - Return protocol type

**Protocol** (`protocol.go:7-16`)
- Enum: `Unsupported`, `Auto`, `ITerm2`, `Kitty`, `Sixel`, `Halfblocks`
- `DetectProtocol()` - Auto-detect best available protocol
- `DetermineProtocols()` - Get ordered list of supported protocols

### Renderers

**KittyRenderer** (`kitty.go:92-98`)
- `Render()` - Generates Kitty graphics protocol escape sequences
- `Print()` - Outputs to stdout with post-render positioning/animation
- `Clear()` - Sends delete action to clear images
- `AnimateImages()` - Creates animation sequence from image IDs
- `PlaceImage()`, `PlaceImageWithSize()` - Positions virtual images at coordinates
- `SendFile()` - Optimized file path transfer
- `ClearVirtualImage()` - Deletes virtual image by ID
- **Unicode Placeholder Support**: Uses combining diacritical marks (297 characters) for encoding row/column positions

**SixelRenderer** (`sixel.go:30-34`)
- Uses `github.com/mattn/go-sixel` for encoding
- `Render()` - Encodes image to Sixel format with palette control
- `Clear()` - Precise area clearing or full screen clear

**ITerm2Renderer** (`iterm2.go:27-28`)
- `Render()` - Encodes to JPEG, uses ECH for background clearing
- `Clear()` - Uses terminal reset sequences
- Supports chunked multipart file transfer for large images (256KB chunks)

**HalfblocksRenderer** (`halfblocks.go:13-17`)
- Uses `github.com/charmbracelet/x/mosaic` for Unicode block rendering
- Falls back gracefully when graphics protocols unavailable

### Terminal Detection

**TerminalFeatures** (`detect.go:95-113`)
- Cached terminal capability detection
- Font width/height detection via CSI queries
- Protocol support flags: `KittyGraphics`, `SixelGraphics`, `ITerm2Graphics`
- True color support detection

**TerminalQuerier** (`detect.go:128-133`)
- Handles batched terminal queries in raw mode
- Thread-safe with mutex protection
- Context-based timeout (100ms default)

**CSI Queries** (`pkg/csi/csi.go`)
- `QueryTextAreaSizeInPixels()` - CSI 14t
- `QueryCharacterCellSizeInPixels()` - CSI 16t
- `QueryXTSMGRAPHICS()` - XTSMGRAPHICS for Sixel geometry
- `QueryFontSize()` - Combines CSI 14t and window size
- `QuerySupported()` - Heuristic for CSI query support

### TUI Widgets

**ImageWidget** (`tui.go:9-24`)
- Widget for embedding images in TUIs
- `SetSize()`, `SetPosition()`, `SetProtocol()`, `SetVirtual()`, `SetZIndex()`
- `Render()` - Returns string representation
- `RenderVirtual()` - Kitty-specific virtual placement rendering

**StatefulImageWidget** (`stateful_widget.go:145-162`)
- Tracks viewport size, re-renders only when needed
- `SetMinimumCells()` - Configure minimum render size
- `EnableAsync()` - Background rendering worker
- `RenderInto()` - Renders into viewport of specified dimensions
- Uses `AsyncRenderWorker` for non-blocking renders

**AsyncRenderWorker** (`stateful_widget.go:39-51`)
- Background goroutine pool for render requests
- Request coalescing: newer requests replace older ones in queue
- `Schedule()` - Enqueue render request
- `TryLatest()` - Get most recent completed render

**ImageGallery** (`tui.go:216-222`)
- Grid layout for multiple images
- `AddImage()`, `AddImageFromFile()` - Add images
- `SetProtocol()`, `SetSpacing()`, `SetImageSize()` - Configuration
- `Render()` - Renders entire grid

### Image Processing

**ResizeCache** (`resize.go:17-23`)
- LRU cache for resized images (default 100 entries)
- Cache key: `widthxheight_path_srcWidthxsrcHeight`
- Thread-safe with RWMutex

**ScaleMode** (`termimg.go:46-60`)
- `ScaleAuto` - Intelligent scaling (resize down only if needed)
- `ScaleNone` - No scaling
- `ScaleFit` - Maintain aspect ratio within bounds
- `ScaleFill` - Fill bounds, crop if needed
- `ScaleStretch` - Stretch to exact dimensions

**DitherMode** (`termimg.go:62-70`)
- `DitherNone` - No dithering
- `DitherFloydSteinberg` - Floyd-Steinberg error diffusion

### Encoding

**Base64Encode** (`encoding.go:22-41`)
- Uses `sync.Pool` for buffer reuse
- ~33% faster than standard library

**ChunkedBase64Encode** (`encoding.go:43-56`)
- Processes data in fixed-size chunks (4KB default)

**ParallelBase64Encode** (`encoding.go:58-92`)
- Multi-goroutine encoding for large data (threshold: 2x chunk size)
- Worker pool with channel-based job distribution

### Tmux Support

**tmux.go**
- `inTmux()` - Check if running inside tmux
- `ForceTmux()` - Force tmux passthrough mode
- `enableTmuxPassthrough()` - Sets `allow-passthrough on`
- `wrapTmuxPassthrough()` - Wraps escape sequences: `\x1bPtmux;\x1b{seq}\x1b\\`

## Notable Algorithms / Named Patterns

### Unicode Placeholder Encoding (Kitty)
- Uses private-use character `U+10EEEE` as placeholder
- 297 combining diacritical marks encode row/column/id positions
- Image ID encoded in foreground RGB color using `\x1b[38;2;R;G;Bm`

### LRU Cache with RWMutex
```go
// Access order tracked for eviction
// Write lock only on modification, read lock on access
```

### Parallel Protocol Detection
- Environment check first (fast path)
- Falls back to terminal queries with 100ms timeout
- Queries run in parallel via goroutines with mutex-protected result collection

### Tmux Passthrough Wrapping
```
Original:  ESC [ params
Wrapped:  ESC P tmux ; ESC ESC [ params ESC \
```
All ESC characters doubled inside tmux wrapper.

### Async Render Pipeline
- Request coalescing: identical requests deduplicated
- Queue overflow: oldest request dropped when full
- Result buffer: drains to surface most recent output

### Font Size Detection Fallback Chain
1. Protocol-specific queries (Kitty CSI, iTerm2 OSC)
2. Generic CSI 16t
3. Terminal-specific TERM variable matching
4. Hardcoded defaults per terminal type

## Strengths

1. **Universal Terminal Support** - Falls back gracefully from advanced protocols to Unicode halfblocks, ensuring images display on any terminal

2. **Automatic Protocol Detection** - No configuration needed; library detects best available protocol automatically

3. **Performance Optimized** - Caching at multiple levels (font size, resize), parallel encoding, buffer pooling

4. **TUI Framework Integration** - First-class Bubbletea support with ImageWidget and async rendering

5. **Tmux/Screen Support** - Escape sequences properly wrapped for terminal multiplexers

6. **Fluent API** - Chainable method calls for intuitive configuration:
   ```go
   termimg.Open("image.png").Width(80).Height(40).Scale(termimg.ScaleFit).Print()
   ```

7. **Comprehensive Image Processing** - Multiple scale modes, dithering algorithms, pixel vs cell dimensions

8. **Kitty Virtual Placement** - Unicode placeholders enable images that scroll with text content

9. **Well-Tested** - Multiple test files covering detection, rendering, protocol specifics

10. **Active Development** - Recent commits, MIT license, responsive to issues

## Weaknesses

1. **WebP Not Supported** - Only PNG, JPEG, GIF mentioned; WebP planned but not implemented

2. **Sixel Performance** - ~90ms render time vs ~2.5ms for Kitty/iTerm2 - high quality comes at cost

3. **iTerm2 Clear Limitation** - No specific image clear command like Kitty; falls back to screen clears

4. **Unicode Placeholder Complexity** - Kitty Unicode mode is experimental, complex encoding using diacritics

5. **CSI Query Failures** - Some terminals (Apple Terminal, VS Code) disable CSI queries; relies on fallback detection

6. **Animation Partially Implemented** - Animation struct exists but TODO comments indicate full animation support not complete

7. **File Transfer Optimization** - TODO comment indicates file transfer optimization not fully implemented

8. **External Dependency Risk** - Depends on `github.com/mattn/go-sixel` for Sixel encoding, `nfnt/resize` for resizing

9. **No WebAssembly Support** - Pure Go but no explicit WASM targeting for browser terminals

10. **Documentation Gaps** - Some TODO comments in code; experimental features not clearly documented

## SugarCraft Mapping

### Primary Mapping: `candy-mosaic`
The halfblocks rendering functionality directly maps to SugarCraft's `candy-mosaic` library which provides Unicode block character rendering for terminal graphics. Both:
- Use the Charmbracelet `x/cellbuf` and `x/mosaic` packages
- Render images as Unicode block characters
- Provide fallback when graphics protocols unavailable
- Support dithering for quality

### Secondary Mapping: Image Protocol Rendering
If SugarCraft were to port the full protocol implementations:
- `candy-kitty` - Kitty graphics protocol port
- `candy-sixel` - Sixel protocol port
- `candy-iterm2` - iTerm2 inline images port

### Relevant SugarCraft Patterns
The `Image` struct's fluent API pattern with `with*()` methods mirrors SugarCraft's immutable builder pattern:
```go
// Go-termimg
img.Width(80).Height(40).Scale(termimg.ScaleFit).Print()

// SugarCraft (implied PHP pattern)
$img->withWidth(80)->withHeight(40)->withScale(ScaleFit::AUTO)->print();
```

## Analysis

Go-termimg is a sophisticated terminal image rendering library that abstracts the complexity of multiple competing image protocols into a unified API. At its core, the library solves the problem of displaying images in terminals that have varying levels of graphics protocol support - from modern terminals like Kitty and iTerm2 with dedicated graphics protocols, down to plain Unicode terminals that can only render images as colored block characters.

The architecture follows a clean renderer interface pattern where each protocol (Kitty, Sixel, iTerm2, Halfblocks) implements the `Renderer` interface with `Render()`, `Print()`, `Clear()`, and `Protocol()` methods. The `Image` struct acts as a builder/facade, accumulating configuration through its fluent API and delegating to the appropriate renderer based on protocol selection or auto-detection.

The most impressive technical achievement is the comprehensive terminal detection system. It combines environment variable checks (fast path), CSI/OSC queries to terminals (thorough but slower), and hardcoded fallback defaults for specific terminal types. The caching of font dimensions is particularly important because accurately converting pixel dimensions to character cells requires knowing the terminal's font size, and querying this on every render would be prohibitively expensive.

The Kitty protocol implementation deserves special mention for its Unicode placeholder feature. By encoding image data using a private Unicode character combined with 297 different combining diacritical marks for row/column positioning and RGB foreground colors for image IDs, images can be rendered that scroll naturally with terminal content - something not possible with the traditional "overlay" approach of other protocols.

The library is production-quality for Kitty and Halfblocks protocols, which together cover the vast majority of use cases. Sixel provides high-quality output but at significant performance cost. iTerm2 support is solid for display but lacks the image management features (specific clearing) of Kitty. The TUI integration via Bubbletea is well thought-out, with both synchronous and asynchronous rendering options and proper cursor management to prevent image output from corrupting text layouts.

For SugarCraft, the most directly mappable component is the halfblocks/mosaic rendering, which provides a fallback for terminals without graphics protocol support. The protocol abstraction and auto-detection patterns would also inform designing a cross-terminal compatible image rendering system in PHP.
