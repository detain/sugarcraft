<img src=".assets/icon.png" alt="candy-codeshot" width="160" align="right">

# CandyCodeshot

> Formerly published as `sugarcraft/candy-freeze` (renamed 2026-10). <!-- @rename-keep -->
> Namespace changed: `SugarCraft\Freeze\` → `SugarCraft\CodeShot\`. <!-- @rename-keep -->

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-codeshot)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-codeshot)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/candy-codeshot?label=packagist)](https://packagist.org/packages/sugarcraft/candy-codeshot)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-8.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

candy-codeshot — turn code or terminal output into an SVG (or PNG) screenshot,
for PHP 8.3+. SVG output needs **no `ext-gd` / Imagick**; the SVG is plain text
suitable for git diffs and CI artifacts. PNG output is also available and
requires `ext-gd`.

```sh
composer require sugarcraft/candy-codeshot:dev-master
```

> **Stability:** this package requires its `sugarcraft/` siblings
> (`sugarcraft/sugar-ansi-parser` and `sugarcraft/sugar-core`) at `dev-master`.
> A Composer project left on the default `stable` minimum-stability cannot
> resolve them — add `"minimum-stability": "dev", "prefer-stable": true` to the
> consuming root (or pin the released `sugarcraft/*` tags once cut). The
> monorepo publishes all libs together, so sibling versions always line up.

A rolling standalone binary is also published on GitHub Releases under
[`candy-codeshot/dev`](https://github.com/detain/sugarcraft/releases/tag/candy-codeshot/dev)
as `candy-codeshot.phar`; stable `candy-codeshot/v*` tags are reserved for
maintainers. A GitHub release download lands as a non-executable regular
file, so run the phar through PHP: `php candy-codeshot.phar`. The phar
needs PHP 8.3+ with `mbstring`; `--format png` additionally needs `ext-gd`.

### Verify the phar

The release carries the sidecars `candy-codeshot.phar.sha256`,
`candy-codeshot.phar.sha256.sig`, `candy-codeshot.phar.minisig`,
`candy-codeshot.phar.commit`, and `candy-codeshot.phar.pins.json`.
Check a download with:

```bash
curl -LO https://github.com/detain/sugarcraft/releases/download/candy-codeshot/dev/candy-codeshot.phar
curl -LO https://github.com/detain/sugarcraft/releases/download/candy-codeshot/dev/candy-codeshot.phar.sha256
sha256sum -c candy-codeshot.phar.sha256   # integrity
grep -m1 . candy-codeshot.phar.commit     # which build you actually hold
```

The `.sig` / `.minisig` sidecars carry a minisign signature over the
`.sha256` body made with the sugarcraft-wide fleet keypair (one keypair
signs every app's phar, owner ruling 2026-10-21); signing is optional per
workflow, so a build can ship unsigned, and verification should follow the
fleet's published signature procedure rather than trusting the sidecars
blind. Until an anchored public key ships for this app, treat `.sha256` +
`.phar.commit` as a disclosure of what you hold, not a cryptographic
attestation of who built it.

## CLI

### Run it

Inside a checkout, run `./bin/candy-codeshot` directly. When installed via
Composer, the executable lands at `vendor/bin/candy-codeshot` — put
`vendor/bin` on your `PATH` or call the full path. The examples below assume
it is on `PATH`.

```sh
echo "function hello() { return 'world'; }" \
    | candy-codeshot --theme dracula --line-numbers > out.svg

candy-codeshot input.php \
    --theme tokyo-night --no-window --output screenshot.svg

candy-codeshot notes.md --format png -o notes.png
```

(That last one renders **without** syntax colour: detection names
`notes.md` as Markdown, the lexer has no Markdown grammar, and a name
with no grammar renders plain — see [Language Detection](#language-detection).)

### Flags

Every flag the parser accepts. Flag values are **separate arguments** —
`--theme dracula`, not `--theme=dracula` (the `=` form is rejected as an
unrecognised flag).

- `--theme <name|file>` — colour palette: `dark` (default), `light`, `dracula`, `tokyo-night`, `nord` — or a path to a JSON theme file (VS Code or flat-palette; see [Themes](#themes)). Names match case-insensitively with `_` read as `-`, so `tokyo_night`, `Tokyo-Night` and `DARK` are all accepted. A value that looks like a path (contains a separator, or ends in `.json`) goes through the file route — confined like every other path argument — and refuses with exit 2 if it cannot be read or parsed.
- `--padding <n>` — content padding inside the frame (default 24).
- `--no-window` — drop the window chrome entirely (traffic lights, title bar).
- `--no-shadow` — drop the drop-shadow.
- `--no-border` — drop the frame outline.
- `--line-numbers` — render a left-gutter line counter.
- `--border-radius <n>` — corner radius of the frame (default 8).
- `--window-style <style>` — chrome style: `macos` (default), `windows-terminal`, `iterm`, `hyper`, `none`. `none` collapses the title-bar band (no empty 36 px strip).
- `--ligatures` — enable ligatures (`font-variant-ligatures: normal`). **SVG only.**
- `--font <path>` — embed a TTF/OTF/WOFF/WOFF2 font in the SVG. **SVG only.** The CLI validates the file's signature; the library-level `SvgRenderer::withFont()` only checks existence and falls back to `font/ttf` for unknown MIME at render time.
- `--unsafe-paths` — allow input / `--font` / `--output` paths outside the CWD (see Path confinement).
- `--highlight <start:end[:color]>` — highlight lines `start:end` with an optional colour (default: the theme’s own highlight colour). Ranges are validated at parse time, whichever output format you chose: `start >= 1`, `end >= start`, else a friendly refusal, exit 2. The highlight itself is **SVG only.**
- `--format <svg|png>` — output format (default `svg`; `png` requires `ext-gd`).
- `-t`, `--type <lang>` — force the syntax-highlighting language (default: filename, then shebang, then content detection). The value is not validated: any string is accepted, and a name with no grammar renders as plain text rather than erroring.
- `-o`, `--output <path>` — write the screenshot to a file instead of stdout.
- `-h`, `--help` — show the help message.
- `-v`, `--version` — print the version and exit.

### Exit codes

- `0` — success.
- `1` — I/O failure: unreadable input, unwritable output, or a missing composer `autoload.php` (installation problem; the binary names itself and says to run `composer install`).
- `2` — usage or input error: unknown or malformed flag, missing flag value,
  non-numeric `--padding` / `--border-radius`, unknown theme, `--format` or
  `--window-style` name, path refused by confinement (input, `--font` or
  `--output`), refused font (not a file, or not a TTF/OTF/WOFF/WOFF2
  signature), directory as input, missing `ext-gd` for PNG, PNG output refused
  to a terminal, refused `--highlight` range, or a render the engine refuses
  mid-flight (PNG pixel ceiling, font read failure).

### Path confinement

For security, the input path, `--font` path and `--output` path must resolve
inside the current working directory; anything outside (including `..`
traversal) is refused with exit 2. A path that does not exist yet (a new
`--output` file) is confined through its nearest existing ancestor, so a
traversal-laden destination is refused before anything is written. This keeps
an untrusted command line — or a scripted caller feeding paths from outside
data — from reading arbitrary files (embedding a font or reading input) or
writing outside the project. Pass `--unsafe-paths` to lift the restriction
deliberately. The terminal counts as a protected destination too: `--format
png` with no `--output` file on an interactive terminal is refused with exit 2
rather than spewing raw PNG bytes into the shell.

### PNG output

`--format png` renders the same screenshot through `PngRenderer` using the GD
bitmap fonts:

```sh
candy-codeshot script.php --theme dracula --format png -o shot.png
```

PNG mode is more limited than SVG (see [Known limitations](#known-limitations)):
theme `fontSize`/`lineHeight` are not honoured (GD's built-in bitmap font is
fixed-size) and fonts cannot be embedded. The SVG-only options `--ligatures`,
`--font`, `--highlight` and `--border-radius` are dropped; when you pass any of
them together with `--format png`, the CLI **announces each dropped option on
stderr** instead of failing silently. If `ext-gd` is missing, PNG requests exit
2 with a clear message; likewise `--format png` without an `--output` file is
refused (exit 2) when stdout is a terminal, because raw high-bit PNG bytes
belong in a file and not on your shell. Font checks are not part of that
drop-list, though: the `--font` confinement, existence and signature checks run
before the format branch decides to discard the option, so
`--format png --font <bad-path>` exits 2 on the refused font even though a
valid font would merely have been dropped. Writing `--format svg` (or omitting it)
to a `*.png` filename — or `--format png` to a `*.svg` filename — still writes
the bytes of the requested format but warns on stderr that the extension and
format disagree.

### Languages (CLI messages)

The CLI speaks 16 languages for its messages: the catalogues live under
`lang/` — the English baseline `en.php` plus 15 translations (Arabic,
Czech, German, Spanish, French, Italian, Japanese, Korean, Dutch,
Polish, Portuguese, Brazilian Portuguese — currently a copy of `pt`
until a genuine revision lands — Russian, Turkish, Simplified Chinese). Which catalogue is chosen
is decided once at startup by `T::detect()` (sugar-core): it probes
`LC_ALL`, then `LC_MESSAGES`, then `LANG`, in that order, skipping values
that are empty or `C` / `POSIX`; the winning value has its encoding
suffix stripped (`de_DE.UTF-8` → `de`), and `en` is the fallback when no
variable names a shipped locale. The traditional GNU `LANGUAGE` variable
is **not** consulted — this is the sugar-core house chain, not
gettext-full.

```sh
LANG=de_DE.UTF-8 candy-codeshot --help             # usage in German
LC_ALL=fr candy-codeshot --padding abc script.php  # refusal in French
```

Only messages localise. Flag names (`--padding`), format and theme
identifiers, file paths and line content always appear exactly as typed,
inside the translated sentences around them.

## Library

```php
use SugarCraft\CodeShot\SvgRenderer;

$code = <<<'PHP'
<?php
echo "hello, world\n";
PHP;

$svg = SvgRenderer::dracula()
    ->withLineNumbers(true)
    ->withWindow(true)
    ->withPadding(24)
    ->withLigatures(true)
    ->render($code);

file_put_contents('out.svg', $svg);
```

ANSI input is honoured — SGR foreground **and background** colours (16 / 256 /
24-bit truecolor) plus **bold / italic / underline** become per-segment `<text>`
elements (carrying `fill`, `font-weight`, `font-style` and `text-decoration`
attributes) plus per-segment `<rect>` background fills. SVG honours these
attributes; PNG paints only the foreground colour and background bands — bold /
italic / underline are silently dropped there (see [Known
limitations](#known-limitations)). SGR state carries across lines: an
unterminated colour sequence keeps painting the following lines, as in a
terminal. Foreground colours are read in both spellings xterm allows — the flat
`38;2;R;G;B` and the grouped `38:2::R:G:B` (ECMA-48 sub-parameters, where the
slot after the mode is a colour-space id and not the red component) — and both
resolve to the same `#rrggbb`. SGR codes the renderers deliberately do not
map: `reverse` (7), `hidden` (8), `strikethrough` (9), `dim` (2), `blink` (5),
`rapid blink` (6), `fringe` (21), `overlined` (53) — among others — they are
parsed and ignored. The same doctrine covers an out-of-range palette index:
`38;5;N` / `48;5;N` with N outside 0..255 changes no colour, matching xterm —
and the public table helper `AnsiParser::xterm256ToHex()` refuses such an
index with `InvalidArgumentException` rather than clamping or guessing.

```php
$svg = SvgRenderer::dark()->render("\x1b[31merror:\x1b[0m something broke");

// With background colour
$svg = SvgRenderer::dark()->render("\x1b[44m\x1b[37malert:\x1b[0m background highlight");
```

`PngRenderer` is the GD-backed sibling with the same fluent surface — except the SVG-only `withLigatures()`, `withFont()`, `withHighlight()` and `withTitle()` setters, plus `withBorderRadius()`, which is accepted but not honoured: PNG corners stay square.

```php
use SugarCraft\CodeShot\PngRenderer;

$png = PngRenderer::dracula()
    ->withLineNumbers(true)
    ->render($code); // requires ext-gd
```

### API map

| Class | Role |
|---|---|
| `SvgRenderer` | Immutable fluent renderer producing SVG; factories `dark()/light()/dracula()/tokyoNight()/nord()`, fluent `with*()`. |
| `PngRenderer` | GD-backed renderer producing PNG bytes; same theme, fixed bitmap fonts. |
| `Theme` | Immutable palette + font config shared by both renderers (window-chrome style lives on the renderers). |
| `WindowStyle` | Enum of chrome styles: `Macos`, `WindowsTerminal`, `ITerm2`, `Hyper`, `None`. |
| `WindowChromeGeometry` | Per-style traffic-light / title-bar geometry (used by both renderers). |
| `CodeHighlighter` | Keyword/string/comment highlighting for a detected language under a theme. |
| `LanguageDetector` | Heuristic language detection from filename, shebang and content. |
| `LayoutCalculator` | Canvas maths: content box, header band, shadow margin, totals. |
| `Segment` | One styled run of text (text + fg/bg/style flags) produced by the parser/highlighter. |
| `Theme\VsCodeThemeLoader` | Import a VS Code `themes/*.json` colour scheme into a `Theme`. |
| `Theme\FlatPaletteThemeLoader` | Import this library's flat JSON palette file into a `Theme`. |
| `OutputWriter` (+ `StringOutputWriter`, `FileOutputWriter`) | Buffer/forward helpers for caller-chunked pieces — they do not stream screenshots themselves; the renderers return complete bytes and do not call them. |
| `Lang` | i18n lookup wrapper (16 locales shipped under `lang/`). |

## Ligatures

Code editors render ligatures (→, >=, !==, etc.) when `font-variant-ligatures: normal` is set. Enable it explicitly (**SVG only**):

```php
$svg = SvgRenderer::dracula()
    ->withLigatures(true)
    ->render($code);
```

## Language Detection

`LanguageDetector` provides heuristic detection from filename, shebang or content:

```php
use SugarCraft\CodeShot\LanguageDetector;

// From filename extension (first choice for file input)
$lang = LanguageDetector::detectFromFilename('script.py');  // "python"
$lang = LanguageDetector::detectFromFilename('foo.php');    // "php"

// From content (shebang, then content signatures)
$lang = LanguageDetector::detect($code);        // "php", "bash", "python", ...
```

The CLI applies these sources in this order for a file argument: **filename
extension first** (so a Python file whose body has no distinctive markers still
highlights as Python), then shebang, then content signatures for stdin or when
the extension is unknown. Force a language explicitly with `-t/--type`. Input that already carries ANSI colour wins: the highlighter is skipped, and `-t/--type` cannot override embedded colour.

What the lexer actually knows is a narrower set than what detection can
name: **PHP, JavaScript, TypeScript, JSON, Python, Go, Bash, SQL, Rust,
Java, C, C++, Ruby, YAML, HTML and CSS** — sixteen grammars, each a
keyword list plus per-language comment/string rules, lexed by one linear
combined regex (sugar-core's shared `RegexHighlighter`). Every other
detectable name — Markdown, XML, PowerShell, Perl, Lua, Swift, Kotlin,
Scala, R, Tcl, Elixir, Erlang, Haskell among them — tokenises to a single
Plain span and renders exactly as if no language had been named: colour
safe, highlight free. The research notes weighed bundling real TextMate
grammars, and shelling out to a shiki/highlight.js process, and set both
aside — grammar maintenance weight, and a mandatory external process for
a screenshot tool; the keyword lexer is the accepted tradeoff, recorded
in `docs/research/libraries/candy-codeshot-research.md` §6.2 and §6.4.

Source details:
- **Filename extension** — the richest source (`detectFromFilename`): `.php`, `.py`/`.pyw`, `.js`/`.mjs`/`.cjs`, `.ts`/`.tsx`, `.rb`, `.sh`, `.go`, `.rs`, `.c`, `.cpp`, `.java`, `.cs`, `.swift`, `.kt`, `.scala`, `.sql`, `.html`, `.css`/`.scss`/`.sass`, `.json`, `.yaml`/`.yml`, `.md`, `.xml`, and more.
- **Shebang** — `#!/bin/bash`, `#!/usr/bin/env node`, `#!/usr/bin/env php`, etc.
- **Content signatures** — a deliberately small list matched by `detect()`: php, javascript, python, ruby, bash, sql, html, css, json, yaml, markdown (TypeScript/Go/Rust are recognised **by extension only**, not by content).

Returns `"text"` when no match is found.

## Themes

```php
SvgRenderer::dark();        // charm-ish #0d1117
SvgRenderer::light();       // #f6f8fa
SvgRenderer::dracula();     // #282a36
SvgRenderer::tokyoNight();  // #1a1b26
SvgRenderer::nord();        // #2e3440
```

Build a custom theme via the `Theme` constructor — set background / foreground
/ border / shadow / line-number colour / window-control colours / font family
/ size / line height / highlight band colour (`Theme::$highlight`, a nullable
string and the default `--highlight` colour; all five bundled presets set
their own). VS Code theme JSON and the library's flat-palette JSON can be
imported straight from the CLI with `--theme <path.json>`: the path rides
the same CWD confinement as every other file argument, VS Code files are
sniffed by their `colors` / `tokenColors` keys and anything else is read
as a flat palette. An imported VS Code theme repaints the *code* too:
its `tokenColors` fill the theme's keyword / string / number / comment
slots, which `CodeHighlighter::forTheme()` reads, and slots the file
leaves empty fall back to accents chosen for the theme's brightness (the
Dracula set on dark backgrounds, a GitHub-Light-ish set on pale ones).
The five names remain the whole bundled roster — file import is the only
extension door, and window-chrome tinting still follows the preset, not
the imported palette (`windows-terminal` is hard-coded by design; see
Known limitations). Library-side doors:
`Theme\VsCodeThemeLoader::load()` and `Theme\FlatPaletteThemeLoader::load()`.

## Differences from upstream freeze

This library started life as a port of `charmbracelet/freeze` and has
since diverged deliberately. Where freeze and candy-codeshot differ
today:

- **Highlighting engine** — freeze drives chroma's 100+ TextMate-adjacent
  lexers; codeshot lexes sixteen keyword grammars in pure PHP (see
  Language Detection above). No external process, no grammar bundles.
- **No config file** — freeze reads a persisted config; codeshot takes
  every option from the command line or the library call. A `--config`
  route has been considered and deliberately not shipped: a static
  renderer's behaviour should be reproducible from the invocation alone.
- **No `--execute`** — freeze can run a command and screenshot its
  output; codeshot renders text it is handed (file, stdin, string).
  Spawning a caller-named process is a surface this tool chooses not to
  carry.
- **No TUI** — freeze's companion interactive mode (and the glamour
  markdown rendering around it) has no counterpart here; codeshot is a
  one-shot renderer by design.
- **Export formats** — freeze offers SVG, PNG (via resvg/librsvg) and
  WebP; codeshot emits SVG natively and PNG through `ext-gd`'s bitmap
  fonts. WebP is not on the roadmap.
- **Fonts** — freeze embeds via fontlib and rasterises with real glyph
  metrics everywhere; codeshot embeds the font file into the SVG (CSS
  `@font-face`, the viewer does the shaping) and PNG falls back to GD's
  built-in bitmap font.

Matching the upstream surface: the five window styles (`macos`,
`windows-terminal`, `iterm`, `hyper`, `none`) mirror freeze's chrome set.

## Known limitations

- **PNG / GD bitmap fonts** — `PngRenderer` uses GD's built-in bitmap font: no
  Unicode beyond Latin-1, and the theme's `fontSize` / `lineHeight` are not
  honoured. Font embedding, ligatures, `--highlight` and `--border-radius` (PNG corners
  stay square) are SVG-only (the CLI announces what PNG drops). SGR background
  bands are honoured in both formats; bold / italic / underline attributes are
  dropped in PNG — they ride inside the input text, so no drop-announcement
  can name them.
- **Shadow technique differs by format** — SVG uses an `feDropShadow` filter;
  PNG draws a flat offset rectangle. Expect slightly different shadows.
- **`windows-terminal` chrome is deliberately dark** — its title bar uses the
  hard-coded `#1e1e1e` / `#303030` / `#444444` of the real Windows Terminal
  default theme and is not re-tinted by `--theme` (planned future work:
  theme-derived chrome colours). Its title-bar rects are clipped to the rounded frame, so a
  non-zero `--border-radius` shows rounded top corners — the `hyper` chrome
  receives the same rounded-frame clip.
- **Accessible SVG output** — every render emits `<title>`, `<desc>` and
  `role="img"`; the CLI names the document after the input basename (or
  `stdin`), the library via `withTitle()`. The accessible name comes from the
  child `<title>` alone — renders carry no `aria-labelledby` anchors, so any
  number of them can be embedded inline in one document without name
  collisions. PNG bitmaps carry no text metadata.
- **Terminal-accurate cell math** — layout width uses `SugarCraft\Core\Util\Width`
  (East-Asian widths) and tabs expand to 8-cell stops, matching a terminal.
  The advance is terminal-cell arithmetic, never a measured glyph width:
  SVG cells are estimated at `fontSize × 0.6`, so a viewer whose font
  substitution carries different metrics drifts the columns.
  PNG still *draws* with GD's Latin-1 bitmap font, so non-Latin-1 text can
  overrun its computed advance in PNG output.
- **No cursor model** — input is read as text, never replayed as a
  terminal: only TAB (expanded to 8-cell stops) and LF (line breaks) act
  on the layout. Carriage return, backspace and the other C0 control
  bytes are dropped as the SGR stream is parsed — `progress\rdone`
  renders as the single line `progressdone`, and `a\b` as `ab`: nothing
  overwrites, nothing erases, and progress-bar spam collapses to one
  line. DEL and the C1 controls never reach an artifact either — the
  escape sweep drops them because XML 1.0 cannot encode them.
- **Theme JSON truncation** — theme loaders read at most 1 MB; for larger
  files the truncated JSON almost certainly surfaces as a `JsonException` at
  parse time.
- **Numeric ceilings** — the CLI refuses `--padding` above 512 and
  `--border-radius` above 64; engine constructors clamp to 0..4096 /
  0..256, and PNG output above 40,000,000 pixels is refused, not allocated.
  Language detection reads at most 1 MB of content (`src/LanguageDetector.php:87`):
  input beyond that cap is treated as plain text, so a multi-megabyte paste is
  rendered unhighlighted rather than refused.
- **Font embedding caps at 32 MiB** — unreadable or oversized font files
  raise a loud `RuntimeException` (CLI exit 2); SVG never silently drops
  the `@font-face`. The CLI verifies the font's signature before reading it;
  a font deleted between that check and the read is caught by the same loud
  refusal — the check→read window is accepted and fail-closed, not chased
  with locks.
- **Classic ANSI palette** — `AnsiParser::ANSI16` follows classic terminal
  values — regular blue is xterm's `#0000ee`, bright black is the Linux-console
  `#7f7f7f` (xterm classic spells it `#808080`) — so contrast on dark themes
  is low by design.

## Security

- **Invisible formatting characters pass through by design** — the
  renderers escape and sanitize *markup* but never rewrite *text*, so
  bidi overrides (RIGHT-TO-LEFT OVERRIDE U+202E and friends), bidi
  isolates (U+2066..U+2069) and zero-width characters (U+200B..U+200D,
  U+FEFF) survive into SVG/PNG artifacts unmarked. A screenshot tool
  that altered source bytes would misrepresent the code it shows, so
  fidelity wins; the cost is the "Trojan Source" window: a rendered line
  can *appear* to read differently from what its bytes contain. When
  showing untrusted input, pre-sweep it library-side with
  `SugarCraft\Core\Util\Sanitize::markInvisibleFormatting()` (renders
  those codepoints as visible `<U+XXXX>` markers) or use
  `Sanitize::untrustedForDisplay()`, which marks them as part of its
  display policy. The CLI itself never alters subject text; the
  pass-through is pinned by
  `tests/SvgInjectionTest::testInvisibleBidiOverridesSurviveUnmarkedAsDocumented`.

## Demos

### Code screenshot

![screenshot](.vhs/screenshot.gif)

### ANSI input

![ansi-input](.vhs/ansi-input.gif)

## Testing

```sh
cd candy-codeshot && composer install && vendor/bin/phpunit
```

The options roster guard (`tests/OptionsRosterTest.php`) fails when the parser,
`cli.usage`, or the README flag list drift apart.

## Accessor deprecation (2026-10-24)

Per the house bare-accessor convention (`AGENTS.md`: *"Bare accessors (no* `get`*"), ruling 3b)*, `StringOutputWriter::getResult()` was renamed to its bare twin `result()` — unclaimed on the class, and deliberately not lifted onto the `OutputWriter` interface, because `FileOutputWriter` has no accumulated string to hand back; `result()` is this implementation's surface. The legacy spelling stays a byte-identical one-line BC forwarder marked `@deprecated` with `REMOVED-IN: 2.0.0`; it will be removed in candy-codeshot v2.0.0.

| Namespace | Class | Deprecated | Use instead |
|-----------|-------|------------|-------------|
| `CodeShot` | `StringOutputWriter` | `getResult()` | `result()` |

`tests/AccessorDeprecationTest.php` pins BC identity on a live writer (empty buffer, after writes, after flush), the forwarder shape (single return line + markers), a discovery-first `src/` census with an inverse leg that reddens any unmarked public `get*()`, and the README table agreement. The bundled renderers never call the writer, so there were no internal call sites to switch; the existing `StringOutputWriterTest` needles stay green through the forwarder.

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project.
