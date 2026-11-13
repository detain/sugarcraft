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

candy-mdview — a Markdown CLI viewer for PHP 8.3+ that composes **SugarMarkdown** (rendering) and **SugarForms Viewport** (scrolling). It is the native-PHP counterpart of the Go viewer [glow](https://github.com/charmbracelet/glow): rendering, paging, themes, and live reload are ported; glow's directory browser, search, config UI, and presentation modes are not part of this tool.

## Install

```sh
composer require sugarcraft/candy-mdview   # binary lands in vendor/bin/candy-mdview
```

Prefer a single file? The rolling dev phar is rebuilt on every push to master that affects this lib:

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
  when stdout is not a terminal or `--no-ansi` vetoes the stream. The mirror
  edge: forcing decoration (`--ansi` / `CLICOLOR_FORCE` / `FORCE_COLOR`) yields
  a decorated tier even on a pipe.
- `--style` / `-s` — alias for `--theme` (glamour-compat). When both are given,
  `--style` silently wins; there is no warning.
- `--version` — handled by the Symfony Console application (no row in the
  command's own flags). Inside a phar the build stamp wins; outside an archive
  it reports the composer/dev fallback — the only documented way to tell
  `vendor/bin/candy-mdview`, a wget'd `candy-mdview.phar`, and a bare checkout
  apart.
- `--theme-config <path>` — load a custom JSON theme (up to 1 MB). Two shapes
  are detected automatically: a **glamour** config (top-level `"document"`
  block with nested element/chroma maps) is mapped by `GlamourTheme` onto a
  SugarMarkdown `Theme`; the historic flat colour map goes through
  `Theme::fromJsonString`. (`Theme::fromJson` takes a *path* and is used for
  the three bundled JSON themes.) Overrides `--theme`. The path must be a
  regular file — a directory, FIFO or device refuses under the same heading
  instead of warning, reading unbounded, or hanging — and the payload must
  satisfy one of the two shapes: scalars, JSON lists and objects that match
  neither schema (a typo'd top-level container) refuse with exit 2 rather
  than silently rendering the stock theme.
- `--width` / `-w <N>` — word-wrap paragraphs / blockquotes / list bodies at
  N columns (1–9999). `0` disables wrapping; unwrapped lines are truncated by
  the pager, so scroll horizontally with `h`/`l`. Anything non-numeric is a
  usage error.
- `--no-hyperlinks` — suppresses the OSC 8 envelopes. They are emitted for
  terminal output by default, and also into redirected output when decoration
  is forced (`--ansi` / `CLICOLOR_FORCE` / `FORCE_COLOR`) — forcing decoration
  means full decoration. Labelled links always show their visible `text (url)`
  tail; plain autolinks and `mailto:` targets render the URL itself as the
  text, so the address stays visible either way.
- `--pager` / `-p` — open in a fullscreen pager. Requires a real terminal:
  when neither stdout is a terminal nor `/dev/tty` can be opened, the run
  refuses with exit code 2 naming stdout rendering as the replacement
  (drop `-p`) — the pager arms the alt screen and mouse tracking the
  moment it starts, so a non-terminal run would paint escape sequences
  into your redirect target instead of a screen.
- `--ansi` / `--no-ansi` — force / forbid decorated output.

Two independent chains decide color, and it matters which one you are in:

- **Decoration gate** (does the stream emit ANSI and OSC envelopes at all):
  `--no-ansi`, then `--ansi`, then the environment — where only affirmative
  ON verdicts act: `CLICOLOR_FORCE` / `FORCE_COLOR` force color on when set
  to any value other than `0`. A non-empty `NO_COLOR` — including
  `NO_COLOR=0` per the spec — or `CLICOLOR=0` forces nothing off *here*: it
  decides the theme probe only, so on a terminal the gate still opens. An
  empty `NO_COLOR=` takes no position in either chain. The terminfo probe is
  *not* consulted at the gate.
- **Theme probe** (implicit theme tier only): a terminfo capability probe
  (`TERM=dumb` counts as colorless) picks between the `ansi` and `ascii`
  themes.

Two consequences worth knowing: `NO_COLOR` on a terminal selects the ascii
theme but does not silence OSC 8 link envelopes — the decoration gate is a
tty check and never reads `NO_COLOR`; and `--no-ansi` on a color-capable
terminal yields the **plain** theme, not `ascii`. A value `CLICOLOR` or
`NO_COLOR` cannot act on never throws: it takes no position and the
terminal/probe chain decides (`FORCE_COLOR=banana` is the different shape —
any non-`0` value forces color on at the gate).

## Pager

The pager reads the keyboard from `/dev/tty`, so `some-command | candy-mdview -p`
stays scrollable; the document itself can still come from stdin. It reflows
when you resize the terminal, and — when opened on a file — the header row
shows `file · scroll%` (plus `↻N` after N auto-reloads); a stdin pager has no
header row.

| Key | Action |
|---|---|
| `↑` / `k` | line up |
| `↓` / `j` | line down |
| mouse wheel | 3 lines per notch, either direction |
| `PgUp` / `b` | page up |
| `PgDn` / `space` / `f` | page down |
| `Ctrl+U` / `Ctrl+D` | half page |
| `Home` / `g` | top |
| `End` / `G` | bottom |
| `←` / `h`, `→` / `l` | scroll horizontally (no-wrap mode) |
| `r` | force a reload of the watched file (unmodified only) |
| `q` / `Esc` / `Ctrl+C` | exit (`Shift+q`, `Alt+q` and `Ctrl+q` do not) |

Only an unmodified `q` quits. The scrolling letter keys are the exception to
that caution: they fire even with modifiers held — `Ctrl+B` pages up and
`Alt+j` scrolls — while `Ctrl+U` / `Ctrl+D` are the deliberate half-page
chords.

When the pager is opened on a file (not stdin) it **auto-reloads**: a fixed
0.5 s tick runs one non-blocking (mtime, size) probe through
`FileWatcher::pollTuple()`; a tick that finds a differing tuple consumes the
baseline and queues the read and re-render for the FOLLOWING tick — save
bursts coalesce into one render of the newest bytes and the pager never sits
on a parse while your keystrokes queue. A delete-and-recreate keeps the last
good frame until the recreated (mtime, size) differs from the baseline. A
rewrite preserving both second-granularity mtime and byte size stays
invisible to the tuple; press `r` to force a reload in that case. Refused
replacements (oversize, device swap, binary) and throwing re-renders never
blank the screen: the last good frame stays and the header marks `↻!` until
the next accepted reload.

## Exit codes & errors

| Code | Meaning |
|---|---|
| 0 | rendered successfully (an empty *file* renders blank) |
| 1 | runtime failure |
| 2 | usage error — bad theme, unreadable/oversized theme config, missing or unreadable file, a directory argument, non-text (binary) input, a document over 64 MiB, invalid `--width`, or no input (no file argument with a terminal on stdin, or empty stdin) |

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

`--theme-config` files are untrusted input too, and the glamour adapter treats
them that way at its parse door: the document/paragraph decoration slots
(`block_prefix` / `block_suffix`) accept only inert whitespace, so a style
file cannot inject title-setting escapes, arbitrary SGR, or forged hyperlink
envelopes into every frame; `document.indent` and `document.margin` refuse
values beyond `Theme::MAX_DOCUMENT_DECORATION` (256) instead of multiplying
into a memory blowup. Both refusals name themselves and exit 2. Colour and
spacing values that pass are painted verbatim, so style files should still
come from sources you trust.

## Output languages

The CLI localizes its messages via `LANG` / `LC_ALL` / `LC_MESSAGES` across 16
locales; `--help` and the document itself stay as-is.

## Library API

Beyond the CLI, candy-mdview exposes three public pieces for integrating its
behaviour into other PHP projects: the pager's TEA model (`MdViewModel`) and
two utility helpers (`FileWatcher`, `GlamourTheme`). The deprecated
`RenderCommand::set*Callback()` test seams and the legacy public
`RenderCommand::loadInput()` are deliberately off this surface — kept for
tests and integrators, not advertised API. Visual width measuring
(CJK/emoji) is delegated to `SugarCraft\Core\Util\Width` in **sugar-core** —
use that directly; this package ships no local width helpers.

### MdViewModel

The pager's Model (init/update/view over sugar-core's Program): `fromContent()`
builds it from already-rendered text at a given size, `withDocument()` prepends
the status header row (name · scroll% · reload marker), `withWatch()` arms
auto-reload from disk via the `FileWatcher` tuple API, and `isWatching()`
reports whether a watch is armed.

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

The pager's reload path uses the tuple API directly on the model's fixed
tick: FileWatcher loops, sleeps and backs off nothing — the old generator
`watch()` and its unwired idle-backoff ladder were deleted with it, leaving
one watcher per session (the `ReloadTickMsg` chain in `MdViewModel`).

### GlamourTheme

`GlamourTheme` parses charmbracelet/glamour style JSON (a `"document"`
block, per-element `StylePrimitive` maps, and a `chroma` token map) and
projects it onto SugarMarkdown's `Theme` via `toMarkdownTheme()`: document block affixes, indent and
margin, heading/paragraph/inline element styles, and the Keyword /
LiteralString / LiteralNumber / Comment chroma families. `isGlamourSchema()`
detects the shape; `fromFile()` / `fromJsonString()` parse it, and
`fromDecoded()` is the entry the CLI's `--theme-config` path actually takes.
Parsing is hygiene-gated: decoration slots are whitespace-only, decoration
ints are capped at `Theme::MAX_DOCUMENT_DECORATION`. What is DELIBERATELY
DROPPED, in full: line-control `block_prefix` / `block_suffix` on
non-document, non-paragraph elements (vertical spacing SugarMarkdown manages
itself), the `image` / `table_header` / `table_row` / `table_cell` /
`table_separator` styles (no mapping is decided for them — mapping would
silently change what existing style files render, so they stay disclosed and
addressable instead), per-heading-level prefixes, and chroma families beyond
the four slots. Unmappable fields are exposed verbatim through `element()` /
`chroma()` /
`indentToken()` for integrations that need them — no silent loss. This —
plus the three JSON preset files — is the theme extension point; the built-in
theme set itself is fixed.

## Shared foundations

candy-mdview declares its direct dependencies: **sugar-markdown** (renderer +
themes), **sugar-forms** (Viewport), **sugar-core** (TEA runtime, width,
sanitize), **sugar-colorprofile** (terminal capability probing),
**sugar-style**, and **symfony/console** (`^6.4 || ^7.0`) — the app is a
Symfony Console program (`Application` extends
`Symfony\Component\Console\Application`; the whole flag and exit-code surface
rides on it). The command's private static `terminalSupportsColor()` consults
the environment verdict first, then
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
