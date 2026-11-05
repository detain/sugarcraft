<img src=".assets/icon.png" alt="candy-mdview" width="160" align="right">

# CandyMdview

> Formerly published as `sugarcraft/sugar-glow` (renamed 2026-10). <!-- @rename-keep -->
> Namespace changed: `SugarCraft\Glow\` → `SugarCraft\MdView\`. <!-- @rename-keep -->

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-mdview)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-mdview)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/candy-mdview?label=packagist)](https://packagist.org/packages/sugarcraft/candy-mdview)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->


![demo](.vhs/render.gif)

candy-mdview — a Markdown CLI viewer for PHP 8.3+ that composes **SugarMarkdown** (rendering) and **SugarForms Viewport** (scrolling). It is the native-PHP counterpart of the Go viewer [glow](https://github.com/charmbracelet/glow): rendering, paging, themes, and live reload are ported; glow's search, config UI, and editing features are not part of this tool.

## Install

```sh
composer require sugarcraft/candy-mdview   # binary lands in vendor/bin/candy-mdview
```

Prefer a single file? The rolling dev phar is rebuilt on every push to master:

```sh
wget https://github.com/detain/sugarcraft/releases/download/candy-mdview/dev/candy-mdview.phar
php candy-mdview.phar README.md
```

## CLI

```sh
candy-mdview README.md                            # render to stdout (default)
candy-mdview -p README.md                         # open in a fullscreen pager
git log -1 --pretty=%B | candy-mdview -p          # pipe stdin (pager keys still work — see Pager)
candy-mdview --theme dracula README.md
candy-mdview --width 80 --no-hyperlinks README.md
candy-mdview --theme-config ./my-theme.json README.md
```

Flags:
- `--theme {ansi|ascii|plain|notty|dark|light|dracula|tokyo-night|pink|solarized|monokai|github}`
  — picks a SugarMarkdown preset; aliases `no`, `auto-no`, `tokyonight` are
  accepted. `solarized`, `monokai`, and `github` load JSON theme files from
  `themes/`. With `--theme` omitted: **ansi** on a color-capable terminal,
  **ascii** when color is off (bold/italic survive, color does not), **plain**
  when stdout is not a terminal.
- `--style` / `-s` — alias for `--theme` (glamour-compat).
- `--theme-config <path>` — load a custom JSON theme (up to 1 MB). Two shapes
  are detected automatically: a **glamour** config (top-level `"document"`
  block with nested element/chroma maps) is mapped by `GlamourTheme` onto a
  SugarMarkdown `Theme`; the historic flat colour map goes through
  `Theme::fromJsonString`. (`Theme::fromJson` takes a *path* and is used for
  the three bundled JSON themes.) Overrides `--theme`.
- `--width` / `-w <N>` — word-wrap paragraphs / blockquotes / list bodies at
  N columns (1–9999). `0` disables wrapping; unwrapped lines are truncated by
  the pager, so scroll horizontally with `h`/`l`. Anything non-numeric is a
  usage error.
- `--no-hyperlinks` — OSC 8 envelopes are only emitted on terminals to begin
  with; this flag also suppresses them there. The visible `text (url)` tail is
  always rendered either way.
- `--pager` / `-p` — open in a fullscreen pager.
- `--ansi` / `--no-ansi` — force / forbid decorated output.

Color is decided in this order: `--no-ansi`, `--ansi`, then the environment
(`CLICOLOR_FORCE` / `FORCE_COLOR` force color on; a set `NO_COLOR` — including
`NO_COLOR=0` per the spec — or `CLICOLOR=0` force it off), then whether
stdout is a terminal, then a terminfo capability probe (`TERM=dumb` counts as
colorless). An unparseable environment never throws: the probe decides.

## Pager

The pager reads the keyboard from `/dev/tty`, so `some-command | candy-mdview -p`
stays scrollable; the document itself can still come from stdin. It reflows
when you resize the terminal, and the header row shows
`file · scroll%` (plus `↻N` after N auto-reloads).

| Key | Action |
|---|---|
| `↑` / `k` | line up |
| `↓` / `j` / mouse wheel down | line down |
| wheel up | line up (3 lines per notch) |
| `PgUp` / `b` | page up |
| `PgDn` / `space` / `f` | page down |
| `Ctrl+U` / `Ctrl+D` | half page |
| `Home` / `g` | top |
| `End` / `G` | bottom |
| `←` / `h`, `→` / `l` | scroll horizontally (no-wrap mode) |
| `q` / `Esc` / `Ctrl+C` | exit (`Alt+q` and `Ctrl+q` do not) |

When the pager is opened on a file (not stdin) it **auto-reloads**: the model
polls the source with `FileWatcher::pollTuple()` on a 0.5 s idle tick and
re-renders in place whenever the (mtime, size) tuple changes, so edits to the
Markdown appear without restarting. A delete-and-recreate keeps the last good
frame until the file returns.

## Exit codes & errors

| Code | Meaning |
|---|---|
| 0 | rendered successfully (an empty *file* renders blank) |
| 1 | runtime failure |
| 2 | usage error — bad theme, unreadable/oversized theme config, missing or unreadable file, a directory argument, non-text (binary) input, invalid `--width`, or no input (no file argument with a terminal on stdin, or empty stdin) |

Usage errors are named and go to **stderr** (`open notes.md: no such file`,
`open docs/: is a directory`, …); file paths and option values coming from
argv are sanitized before display, so a crafted name cannot inject escape
sequences or restyle the message. The rendered document is written **raw**:
Symfony's output formatter never interprets `<tags>` inside your Markdown.

## Trust boundary

Documents are untrusted input. Escape sequences, control bytes, and OSC
payloads are stripped on output, per rendered element — a hostile `.md`
cannot move the cursor or change terminal colors. OSC 8 hyperlink URLs pass
a refused-scheme gate in SugarMarkdown: `javascript:`, `data:` and
`vbscript:` never open a link target (their text and `(url)` suffix still
render, visible and inert). Every other scheme — `file:` and `mailto:`
included — reaches the terminal's opener unfiltered, so treat a hostile
document's non-`http(s)`/`mailto` links the way you would treat raw terminal
input. See the fleet [trust boundary](../docs/TRUST-BOUNDARY.md).

## Output languages

The CLI localizes its messages via `LANG` / `LC_ALL` / `LC_MESSAGES` across 16
locales; `--help` and the document itself stay as-is.

## Library API

Beyond the CLI, candy-mdview exposes two utility helpers for integrating its
behaviour into other PHP projects. Visual width measuring (CJK/emoji) is
delegated to `SugarCraft\Core\Util\Width` in **sugar-core** — use that
directly; this package ships no local width helpers.

### FileWatcher

File watching via (mtime, size) tuple polling — works cross-platform with no
extensions.

```php
use SugarCraft\MdView\FileWatcher;

// Stateless tuple API (drives the pager's auto-reload):
$base = FileWatcher::snapshot($path) ?? [0, 0];      // [mtime, size]
$changed = FileWatcher::pollTuple($path, ...$base);  // new tuple or null

// Instance API:
$watcher = new FileWatcher('/path/to/file.md');
if ($watcher->hasChangedSince($lastMtime)) {
    // re-render
}
```

`FileWatcher::watch()` (a generator) is currently consumed only by tests; the
pager uses the tuple API directly on its idle tick.

### GlamourTheme

`GlamourTheme` parses charmbracelet/glamour style JSON (a `"document"`
block, per-element `StylePrimitive` maps, and a `chroma` token map) and
projects it onto SugarMarkdown's `Theme`: document block affixes, indent and
margin, heading/paragraph/inline element styles, and the Keyword /
LiteralString / LiteralNumber / Comment chroma families. Unmappable fields
are exposed verbatim through `element()` / `chroma()` for integrations
that need them. This — plus the three JSON preset files — is the theme
extension point; the built-in theme set itself is fixed.

## Shared foundations

candy-mdview declares its direct dependencies: **sugar-markdown** (renderer +
themes), **sugar-forms** (Viewport), **sugar-core** (TEA runtime, width,
sanitize), **sugar-colorprofile** (terminal capability probing), and
**sugar-style**. `RenderCommand::terminalSupportsColor()` consults the
environment verdict first, then
`\SugarCraft\ColorProfile\Probe\TerminalProbe::run()`, and falls back to
`true` (assume color) if the probe throws — ensuring graceful degradation
on Windows, over SSH, and in old terminals.

## Snapshot tests

Render output is covered by golden-file snapshot tests. Fixture files live
in `tests/fixtures/` with a `.golden` extension and are compared against
actual ANSI byte output via `SugarCraft\Testing\Snapshot\Assertions::assertGoldenAnsi()`.
The recorder is **create-only**: an existing golden that differs never gets
rewritten. To re-record after intentional output changes, delete the fixture
first:

```sh
rm tests/fixtures/<name>.golden
UPDATE_GOLDENS=1 vendor/bin/phpunit
```

## Test

```sh
cd candy-mdview && composer install && vendor/bin/phpunit
```

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem (notably glow); SugarCraft is developed as a native PHP project.
