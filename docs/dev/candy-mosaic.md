# CandyMosaic — Developer Guide

Image-to-cell renderer for the terminal. This guide covers the
extension points available for customising and embedding candy-mosaic.

## Extension points

### Animation (value object)

`Animation` is an immutable readonly value object representing an ordered
sequence of frames with per-frame delays:

```php
use SugarCraft\Mosaic\Animation;
use SugarCraft\Mosaic\ImageSource;

$frames   = [ImageSource::fromFile('f1.png'), ImageSource::fromFile('f2.png')];
$delays  = [100, 200];
$anim     = new Animation($frames, $delays);

// Or uniform delay for all frames:
$anim = Animation::fixed($frames, delayMs: 150);

// Replace one frame (returns new instance):
$anim = $anim->withFrame(index: 1, frame: ImageSource::fromFile('f2-new.png'), delayMs: 300);
```

**Do not subclass `Animation`** — extend via composition instead.
`Animation` carries no state beyond `list<ImageSource> $frames` and
`list<int> $delaysMs`. All mutations return new instances via a private
`mutate()` helper.

### AnimationDriver (Model)

`AnimationDriver` implements `Model` (init/update/view/subscriptions) and
drives an `Animation` onto a `Renderer` using `Cmd::tick()` for
per-frame timing:

```php
use SugarCraft\Mosaic\AnimationDriver;
use SugarCraft\Mosaic\Mosaic;

$driver = new AnimationDriver(
    animation:   $anim,
    renderer:    (Mosaic::kitty())->renderer(),
    cellWidth:   40,
    cellHeight:  20,
    imageId:     1,
    index:       0,    // start at frame 0
    paused:      false,
);

// In a Program:
$program = new \SugarCraft\Core\Program($driver);
```

State changes are fluent (each `with*()` returns a new instance):

```php
$pausedDriver = $driver->withPaused(true);
$frame3       = $driver->withIndex(3);
$newId        = $driver->withImageId(42);
```

The `view()` method emits `delete($imageId) . render($frame)` — for
Kitty/iTerm2 this produces a targeted delete-and-redraw; for other
renderers `delete()` returns `''` and the cell grid is overwritten on
the next render.

### Renderer interface

All renderers implement:

```php
interface Renderer
{
    public function render(ImageSource $image, int $width, ?int $height): string;
    public function name(): string;
    public function supportsAlpha(): bool;
    public function delete(string $imageId): string;
}
```

Add a new renderer by implementing `Renderer`. The `delete()` method
must return `''` if the protocol has no delete mechanism (Sixel,
HalfBlock, QuarterBlock, Chafa all do this).

Kitty-capable renderers should also implement a `renderFrame(ImageSource,
int $width, ?int $height, int $imageId): string` method (like
`KittyRenderer::renderFrame()`) for stable-id animation redraws.

## Backend conventions

Renderer selection follows the same pattern as `Mosaic::probe()` /
`Mosaic::kitty()` etc. — callers do not instantiate renderers directly.
Use `Mosaic::builder()->withRenderer($renderer)->build()` for custom
combinations.

## Testing your extension

- **Unit tests**: Use `ImageSource::fromGd($gdResource)` or
  `ImageSource::fromFile('path/to/small.png')` to supply synthetic frames.
  Prefer small PNG fixtures (8×8 px) to keep test runs fast.
- **Behaviour tests**: Drive `AnimationDriver::update()` with a
  `FrameTickMsg` to assert `[Model, ?Cmd]` tuple shape and index
  advancement.
- **Snapshot tests**: Capture `view()` output as raw ANSI bytes and
  assert against a stored snapshot.

## Versioning policy

- **@api** — `Animation`, `AnimationDriver`, `Renderer`, `ImageSource`,
  `KittyOptions` are stable. All public `with*()` methods and factory
  constructors are part of the contract.
- **@internal** — `KittyRenderer::renderWithOptions()`,
  `KittyRenderer::ensurePng()`, `Animation::mutate()`,
  `AnimationDriver::mutate()`, and all renderer internal helpers are
  subject to change without notice.

## Namespace tree

```
SugarCraft\Mosaic
├── Animation              # Immutable frame sequence
├── AnimationDriver        # Model; drives Animation via tick()
├── FrameTickMsg          # Internal Msg for frame-advance ticks
├── ImageSource            # Image bytes + metadata
├── KittyOptions           # Kitty protocol options
├── Lang                   # i18n facade
├── Mosaic                 # Facade: probe / builder / render
├── PixelGrid              # 2-D cell grid
└── Renderer
    ├── ChafaRenderer
    ├── HalfBlockRenderer
    ├── Iterm2Renderer
    ├── KittyRenderer
    ├── QuarterBlockRenderer
    └── SixelRenderer
```
