# CandyMosaic

Image-to-cell renderer for the terminal — PNG/JPEG/static GIF decoded
via ext-gd and rendered via the best available protocol:

- **Sixel** — xterm, foot, mlterm, wezterm, contour
- **Kitty graphics protocol** — kitty, ghostty, wezterm
- **iTerm2 inline images** (OSC 1337) — iTerm2, wezterm, mintty
- **Half-block Unicode** (▀ + 24-bit fg/bg) — universal fallback

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
    ->withRenderer(new HalfBlockRenderer())
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
