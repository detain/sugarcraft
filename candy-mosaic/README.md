# CandyMosaic

Image-to-cell renderer for the terminal — PNG/JPEG/static GIF decoded
via ext-gd and rendered via the best available protocol:

- **Sixel** — xterm, foot, mlterm, wezterm, contour
- **Kitty graphics protocol** — kitty, ghostty, wezterm
- **iTerm2 inline images** (OSC 1337) — iTerm2, wezterm, mintty
- **Half-block Unicode** (▀ + 24-bit fg/bg) — universal fallback
- **Quarter-block Unicode** (░▒▓█ 2×2) — higher fidelity than half-block

```sh
composer require sugarcraft/candy-mosaic
```

## Quickstart

```php
use SugarCraft\Mosaic\Mosaic;
use SugarCraft\Mosaic\ImageSource;

$mosaic = Mosaic::halfBlock();
$image  = ImageSource::fromFile('cat.png');
$ansi   = $mosaic->render($image, width: 40, height: 20);
echo $ansi;
```

## API

```php
use SugarCraft\Mosaic\KittyOptions;
use SugarCraft\Mosaic\Renderer\QuarterBlockRenderer;

// Probe terminal once, pick best protocol
$mosaic = Mosaic::probe();

// Force a specific backend
$mosaic = Mosaic::sixel();
$mosaic = Mosaic::kitty();
$mosaic = Mosaic::iterm2();
$mosaic = Mosaic::halfBlock();

// Render — returns ANSI bytes
$ansi = $mosaic->render($image, width: 40, height: 20);

// Builder for fine-grained control
$mosaic = Mosaic::builder()
    ->withRenderer(new QuarterBlockRenderer())
    ->withResize(width: 40, height: 20)
    ->build();

// Kitty: virtual-image placement (transmit once, place at multiple offsets)
// Step 1 — transmit with a specific id and store as virtual (a=p)
$renderer = (Mosaic::kitty())->renderer();
$opts = KittyOptions::transmit(1)->withUseVirtual(true);
$transmitted = $renderer->renderWithOptions($image, 40, null, $opts);
// Step 2 — place the same image at a different cell offset (a=p, same id)
$opts = KittyOptions::place(1, x: 5, y: 10)->withZIndex(5);
$placed = $renderer->renderWithOptions($image, 40, null, $opts);

// Kitty: zlib compression (f=1) for large images
$opts = KittyOptions::transmit()->withCompression(1);
$compressed = $renderer->renderWithOptions($image, 40, null, $opts);
```

## Supported image formats

- PNG, JPEG, static GIF — via ext-gd (`imagecreatefrompng`,
  `imagecreatefromjpeg`, `imagecreatefromgif`)
- Palette PNGs are automatically converted to truecolor before
  processing.

## KittyOptions — virtual-image placement and compression

The Kitty renderer supports two advanced options via `KittyOptions`:

- **Virtual-image placement** (`a=p`): Transmit an image once with
  `withUseVirtual(true)`, then place it at multiple on-screen locations
  using `withUseVirtual(true)` with the same image ID. The first render
  stores the image data in the terminal; subsequent renders reference it
  by ID and offset, reducing bandwidth.

- **Zlib compression** (`f=1`): Pass `withCompression(1)` to compress
  the PNG payload with zlib before base64-encoding. Useful for large
  images on slow links; adds modest CPU overhead.

```php
use SugarCraft\Mosaic\KittyOptions;

// Transmit once (a=T, the default)
$opts = KittyOptions::transmit(imageId: 1);
$first = $renderer->renderWithOptions($image, 40, null, $opts);

// Place at a different cell offset using the same transmitted image (a=p)
$opts = KittyOptions::place(imageId: 1, x: 5, y: 10)
    ->withZIndex(5);           // z-index for stacking order
$placed = $renderer->renderWithOptions($image, 40, null, $opts);

// Zlib-compressed transmit
$opts = KittyOptions::transmit()->withCompression(1);
$compressed = $renderer->renderWithOptions($image, 40, null, $opts);
```

## Protocol detection

Every renderer implements `Renderer::delete(string $imageId): string`
which emits the protocol-specific sequence to remove a previously
rendered image. The `$imageId` is the numeric image identifier passed
during rendering (Kitty) or a placeholder for interface compatibility
(iTerm2 Pop ignores it).

| Renderer | Sequence | Notes |
|---------|----------|-------|
| Kitty | APC `a=d` | Deletes specific image by id |
| iTerm2 | OSC 1337 Pop | Removes topmost image from stack; `$imageId` ignored |
| Sixel | _(none)_ | DECSIXEL has no delete command; returns `''` |
| HalfBlock | _(none)_ | Plain text SGR; no stored image identity; returns `''` |
| QuarterBlock | _(none)_ | Plain text SGR; no stored image identity; returns `''` |
| Chafa | _(none)_ | External command; no persistent image identity; returns `''` |

## Animation

Drive a sequence of frames through `AnimationDriver` — a `Model`-implementing class that uses `Cmd::tick()` for per-frame timing and `Renderer::delete()` / `Renderer::renderFrame()` for clean per-frame redraws on Kitty/iTerm2 terminals.

```php
use SugarCraft\Mosaic\Animation;
use SugarCraft\Mosaic\AnimationDriver;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Mosaic;

$frames = [
    ImageSource::fromFile('frame1.png'),
    ImageSource::fromFile('frame2.png'),
    ImageSource::fromFile('frame3.png'),
];

$animation = Animation::fixed($frames, delayMs: 100);
$driver    = new AnimationDriver(
    animation:  $animation,
    renderer:   (Mosaic::kitty())->renderer(),
    cellWidth:  40,
    cellHeight: 20,
    imageId:    1,
);

// Use in a Program:
// new Program($driver, ...);
echo $driver->view(); // renders first frame immediately
```

`Animation` is an immutable value object (`list<ImageSource>` + `list<int> $delaysMs`). Use `Animation::fixed($frames, $delayMs)` for uniform delays, or `new Animation($frames, $delaysMs)` for per-frame control. `withFrame($index, $frame, $delayMs)` returns a new instance with one replaced frame.

`AnimationDriver` composes `Animation` + current frame index + paused flag. Call `withIndex()` / `withPaused()` / `withImageId()` for fluent state changes. On Kitty-capable terminals, `KittyRenderer::renderFrame($image, $width, $height, $imageId)` renders a single frame with a stable id that `delete($imageId)` can later target.

## Architecture

```
SugarCraft\Mosaic
├── Animation              # Immutable frame sequence value object
├── AnimationDriver        # Model; drives Animation onto a Renderer via tick()
├── FrameTickMsg          # Internal Msg for frame-advance ticks
├── ImageSource            # Image bytes + metadata (bytes, format, aspect ratio)
├── KittyOptions           # Kitty protocol options (transmit / place / compress)
├── Lang                   # i18n facade
├── Mosaic                 # Facade: probe / builder / render
├── PixelGrid              # 2-D cell grid (foreground, background, alpha, char)
└── Renderer
    ├── ChafaRenderer      # External command renderer
    ├── HalfBlockRenderer  # Unicode ▀ with 24-bit fg/bg
    ├── Iterm2Renderer     # iTerm2 OSC 1337
    ├── KittyRenderer      # Kitty APC graphics (chunked PNG)
    ├── QuarterBlockRenderer # Unicode ░▒▓█ 2×2 sub-pixel
    └── SixelRenderer      # DEC sixel with median-cut quantizer
```
