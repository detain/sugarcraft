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
- `--theme {ansi|plain|dark|light|notty|dracula|tokyo-night|pink}`
  — picks a CandyShine preset.
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

## Test

```sh
cd sugar-glow && composer install && vendor/bin/phpunit
```
