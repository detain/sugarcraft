<img src=".assets/icon.png" alt="sugar-glow" width="160" align="right">

# SugarGlow

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-glow)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-glow)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/sugar-glow?label=packagist)](https://packagist.org/packages/sugarcraft/sugar-glow)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.1-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->


![demo](.vhs/render.gif)

PHP port of [charmbracelet/glow](https://github.com/charmbracelet/glow) —
a Markdown CLI viewer that composes **CandyShine** (rendering) and
**SugarBits Viewport** (scrolling).

```sh
composer require sugarcraft/sugar-glow
```

## CLI

```sh
sugarglow README.md                            # render to stdout (default)
sugarglow -p README.md                         # open in a fullscreen pager
git log -1 --pretty=%B | sugarglow -p          # pipe stdin
sugarglow --theme dracula README.md
sugarglow --width 80 --no-hyperlinks README.md
sugarglow --theme-config ./my-theme.json README.md
```

Flags:
- `--theme {ansi|plain|dark|light|notty|dracula|tokyo-night|pink|solarized|monokai|github}`
  — picks a CandyShine preset. `solarized`, `monokai`, and `github` load
  JSON theme files from `themes/`.
- `--style` / `-s` — alias for `--theme` (glamour-compat).
- `--theme-config <path>` — load a custom JSON theme via
  `Theme::fromJson`. Overrides `--theme`.
- `--width` / `-w <N>` — word-wrap paragraphs / blockquotes / list bodies.
  0 = no wrap.
- `--no-hyperlinks` — disable OSC 8 link envelopes; render links as
  `text (url)` instead.
- `--pager` / `-p` — open in a fullscreen pager.

## Pager keys

Standard reader keys come from `Viewport`:

| Key | Action |
|---|---|
| `↑` / `k` | line up |
| `↓` / `j` | line down |
| `PgUp` / `b` | page up |
| `PgDn` / `space` / `f` | page down |
| `Ctrl+U` / `Ctrl+D` | half page |
| `Home` / `g` | top |
| `End` / `G` | bottom |
| `q` / `Esc` / `Ctrl+C` | exit |

## Demos

### Render to stdout

![render](.vhs/render.gif)

### Fullscreen pager

![pager](.vhs/pager.gif)

## Library API

Beyond the CLI, sugar-glow exposes three utility classes for integrating its
behaviour into other PHP projects.

### GlamourTheme

Loads and parses Glamour-style theme JSON files (block_prefix/suffix,
indent_token, margin, chroma token mapping).

```php
use SugarCraft\Glow\GlamourTheme;

// From a JSON string
$theme = GlamourTheme::fromJson(file_get_contents('./my-theme.json'));

// From a file path
$theme = GlamourTheme::fromFile('./my-theme.json');

// Resolve a chroma token to an SGR color code (e.g., "31" for red)
$sgr = $theme->resolve('emphasis'); // => "31" or null
```

### FileWatcher

File watching via mtime polling — works cross-platform with no extensions.

```php
use SugarCraft\Glow\FileWatcher;

$watcher = new FileWatcher('/path/to/file.md');

// Check if modified since a given mtime
if ($watcher->hasChangedSince($lastMtime)) {
    // re-render
}

// Generator-based watch loop (e.g., in a ReactPHP stream)
foreach (FileWatcher::watch('/path/to/file.md', 500) as $changed) {
    // $changed === true each time the file is modified
}
```

### Width helpers

CJK and emoji width handling lives in `SugarCraft\Core\Util\Width`. Use it
directly for visual truncation, padding, and ANSI-aware measurement:

```php
use SugarCraft\Core\Util\Width;

Width::string('hello');           // 5
Width::string('你好');            // 4 (full-width)
Width::string('📦');              // 2 (emoji)

Width::padRight('hi', 8);         // "hi      "
Width::truncate('hello world', 8); // "hello wo"
```

## Snapshot tests

Render output is covered by golden-file snapshot tests. Fixture files live
in `tests/fixtures/` with a `.golden` extension and are compared against
actual ANSI byte output via `SugarCraft\Testing\Snapshot\Assertions::assertGoldenAnsi()`.
To re-record fixtures after intentional output changes:

```sh
UPDATE_GOLDENS=1 vendor/bin/phpunit
```

## Test

```sh
cd sugar-glow && composer install && vendor/bin/phpunit
```
