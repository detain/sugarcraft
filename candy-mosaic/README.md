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
```

## Supported image formats

- PNG, JPEG, static GIF — via ext-gd (`imagecreatefrompng`,
  `imagecreatefromjpeg`, `imagecreatefromgif`)
- Palette PNGs are automatically converted to truecolor before
  processing.

## Protocol detection

`Mosaic::probe()` checks environment variables and (when interactive)
sends a DA1 capability query to determine the best protocol. Results
are cached per-process.

## Delete

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
