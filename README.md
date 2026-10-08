# SugarCraft

<p align="center">
  <img src="media/social-preview.png" alt="SugarCraft — sweet to build, fun to use" width="720">
</p>

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![PTY matrix](https://github.com/detain/sugarcraft/actions/workflows/pty-matrix.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/pty-matrix.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg)](https://app.codecov.io/gh/detain/sugarcraft)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
[![PRs Welcome](https://img.shields.io/badge/PRs-welcome-ff5f87.svg)](CONTRIBUTING.md)
<!-- BADGES:END -->

SugarCraft is a complete terminal-application stack for modern PHP: an
MVC-style Model–Update–View runtime for terminal user interfaces (TUIs);
styling, layout, and form engines; reusable components; charts; and terminal
emulators and plumbing — plus a fleet of finished apps. Every package is
composer-installable and requires PHP 8.3+; the interactive stack — runtime,
components, and apps — runs async on a ReactPHP event loop.

🌐 **Website:** [sugarcraft.github.io](https://sugarcraft.github.io/) — library matrix, quickstart, comparison page.

```sh
composer require sugarcraft/sugarcraft        # umbrella metapackage — every library
composer require sugarcraft/candy-sprinkles   # …or just the package you need
```

## What's in the box

Every package installs independently with Composer. The libraries are grouped by layer:

| | Library | Role |
|---|---|---|
| <img src="media/icons/candy-core.png" width="48" alt=""> | **[SugarCraft](candy-core/)** | MVC-style Model–Update–View TUI runtime — `Model` / `Msg` / `Cmd` / `Program`, plus a frame-diff renderer with an opt-in cursed cell-diff mode. |
| <img src="media/icons/candy-ansi.png" width="48" alt=""> | **[CandyAnsi](candy-ansi/)** | ECMA-48 VT500 state machine — ANSI byte-stream parser with abstract Handler interface. Extraction from candy-vt. |
| <img src="media/icons/candy-buffer.png" width="48" alt=""> | **[CandyBuffer](candy-buffer/)** | Cell-grid value objects — immutable Buffer (2-D cell grid) and Cell (`rune`/style/link/width). Shared foundation for all rendering; `Buffer::diff()` included. |
| <img src="media/icons/candy-layout.png" width="48" alt=""> | **[CandyLayout](candy-layout/)** | Constraint-based layout solver — `LayoutSolver` interface with a Cassowary engine (`CassowarySolver`) and a `GreedySolver` fallback. |
| <img src="media/icons/candy-async.png" width="48" alt=""> | **[CandyAsync](candy-async/)** | Shared async vocabulary for ReactPHP — CancellationToken, Subscriptions, AsyncOps helpers (withTimeout, retry, debounce, throttle). |
| <img src="media/icons/candy-input.png" width="48" alt=""> | **[CandyInput](candy-input/)** | Terminal input decoder — raw TTY bytes to typed events: keyboard (Kitty progressive protocol, xterm modifyOtherKeys, legacy encodings) and mouse (SGR, urxvt, X10) via `InputDriver` / `EscapeDecoder`. |
| <img src="media/icons/candy-mouse.png" width="48" alt=""> | **[CandyMouse](candy-mouse/)** | Mouse hit-testing — mark rendered regions, scan an event, get the zone back; `ZoneClickTracker` for press/release dedupe. Owned per consumer, no global manager — the self-contained successor to CandyZone's external-`Manager` model. |
| <img src="media/icons/candy-sprinkles.png" width="48" alt=""> | **[CandySprinkles](candy-sprinkles/)** | Declarative styling + layout — `Style`, `Border`, `Table`, `List`, `Tree`, `Layout::join`, `Place`, `Canvas` (multi-layer compositor). |
| <img src="media/icons/candy-testing.png" width="48" alt=""> | **[CandyTesting](candy-testing/)** | Test harness for SugarCraft apps — ProgramSimulator, golden-file assertions, snapshot helpers. |
| <img src="media/icons/honey-bounce.png" width="48" alt=""> | **[HoneyBounce](honey-bounce/)** | Damped spring physics + Newtonian projectile simulation. |
| <img src="media/icons/candy-zone.png" width="48" alt=""> | **[CandyZone](candy-zone/)** | Mouse-zone tracker — wrap rendered chunks, get back bounding boxes. |
| <img src="media/icons/candy-fuzzy.png" width="48" alt=""> | **[CandyFuzzy](candy-fuzzy/)** | Fuzzy string matching with scored match indices — powers filter-as-you-type highlighting across the ecosystem. |
| <img src="media/icons/sugar-bits.png" width="48" alt=""> | **[SugarBits](sugar-bits/)** | Components: TextInput, TextArea, ItemList, Table, Tabs, Tree, Scrollbar, Viewport, FilePicker, Progress, Spinner, Cursor, Help, Key, Paginator, Stopwatch, Timer. |
| <img src="media/icons/candy-vt.png" width="48" alt=""> | **[CandyVt](candy-vt/)** | Virtual terminal emulator — ANSI byte stream → cell grid + cursor + mode state. |
| <img src="media/icons/candy-vcr.png" width="48" alt=""> | **[CandyVcr](candy-vcr/)** | Record + replay candy-core sessions — JSONL/YAML cassettes, `Program::withRecorder()`, `Player` with byte + cell-grid assertions, CLI. |
| <img src="media/icons/candy-pty.png" width="48" alt=""> | **[CandyPty](candy-pty/)** | Pseudo-terminal primitive — `Pty::open()` master/slave round-trip via FFI to libc; spawn/resize/non-blocking I/O. Linux + macOS only. |
| <img src="media/icons/candy-forms.png" width="48" alt=""> | **[CandyForms](candy-forms/)** | Foundation lib for form primitives — TextInput, TextArea, ItemList, Viewport, FilePicker, Field interface, Confirm, Form (the shared form engine behind SugarBits and SugarPrompt). |
| <img src="media/icons/candy-focus.png" width="48" alt=""> | **[CandyFocus](candy-focus/)** | Focus-ring state machine — ordered focusable regions with Tab/Shift-Tab traversal. |
| <img src="media/icons/sugar-gallery.png" width="48" alt="" onerror="this.style.display='none'"> | **[SugarGallery](sugar-gallery/)** | Poster grids & rails for media TUIs — virtualized PosterGrid, Rail carousel, PosterCard. |
| <img src="media/icons/sugar-charts.png" width="48" alt=""> | **[SugarCharts](sugar-charts/)** | Canvas + Sparkline, Bar, Line, Heatmap, Scatter, TimeSeries, Streamline, Waveline, OHLC, Picture (Sixel/Kitty/iTerm2). |
| <img src="media/icons/candy-mosaic.png" width="48" alt=""> | **[CandyMosaic](candy-mosaic/)** | Image-to-cell renderer — PNG/JPEG/static GIF decoded via ext-gd, drawn with the best available protocol (Kitty, iTerm2, Sixel; an ANSI half-block fallback is always available). |
| <img src="media/icons/sugar-prompt.png" width="48" alt=""> | **[SugarPrompt](sugar-prompt/)** | Form library — Note, Input, Confirm, Select, MultiSelect, Text, FilePicker; multi-page Groups; 7 stock themes. |
| <img src="media/icons/candy-shine.png" width="48" alt=""> | **[CandyShine](candy-shine/)** | Markdown → ANSI renderer with word-wrap, OSC 8 hyperlinks, 9 built-in themes. |
| <img src="media/icons/candy-kit.png" width="48" alt=""> | **[CandyKit](candy-kit/)** | CLI presentation helpers — StatusLine, Banner, Section, Stage, HelpText. |
| <img src="media/icons/candy-wish.png" width="48" alt=""> | **[CandyWish](candy-wish/)** | SSH server middleware — Logger, Auth, RateLimit, and the `BubbleTea` middleware that mounts a SugarCraft Program over `ForceCommand` (HostSshd transport). |
| <img src="media/icons/candy-metrics.png" width="48" alt=""> | **[CandyMetrics](candy-metrics/)** | Telemetry primitives — counters, gauges, histograms with InMemory / JSON / StatsD / Prometheus textfile / Multi backends, plus a CandyWish session middleware. |
| <img src="media/icons/candy-log.png" width="48" alt=""> | **[CandyLog](candy-log/)** | Colorful leveled logger — Debug / Info / Warn / Error / Fatal with structured context, Text / JSON / Logfmt formatters, sub-loggers, and StandardLogAdapter. |
| <img src="media/icons/candy-palette.png" width="48" alt=""> | **[CandyPalette](candy-palette/)** | Terminal color profile detection + ANSI / ANSI256 / TrueColor conversion. StandardColors and ProfileWriter. |
| <img src="media/icons/candy-lister.png" width="48" alt=""> | **[CandyLister](candy-lister/)** | Tree/list view with box-drawing prefixes, cursor navigation, word-wrap, and filter-as-you-type. |
| <img src="media/icons/sugar-boxer.png" width="48" alt=""> | **[SugarBoxer](sugar-boxer/)** | Box-drawing layout engine — H/V panel composition with weighted sizing, borders, and nested grids. |
| <img src="media/icons/sugar-veil.png" width="48" alt=""> | **[SugarVeil](sugar-veil/)** | Terminal overlay compositor — push/pop overlay views with z-ordering, positioning, and per-overlay teardown. |
| <img src="media/icons/sugar-crumbs.png" width="48" alt=""> | **[SugarCrumbs](sugar-crumbs/)** | Navigation breadcrumbs — immutable NavStack with push/pop, shell-change detection, and type-ahead filter. |
| <img src="media/icons/sugar-dash.png" width="48" alt="" onerror="this.style.display='none'"> | **[SugarDash](sugar-dash/)** | Dashboard TUI library — column grid layout, framed panels, status bar, tabs, and more. |
| <img src="media/icons/candy-hermit.png" width="48" alt=""> | **[CandyHermit](candy-hermit/)** | Fuzzy finder overlay — type to filter a list, arrow keys to select, Enter to confirm; wraps any Model. |
| <img src="media/icons/sugar-stickers.png" width="48" alt=""> | **[SugarStickers](sugar-stickers/)** | FlexBox layout engine + simple sort/filter table. Ratio-based sizing, gap, justify, align, per-column styling. |
| <img src="media/icons/sugar-toast.png" width="48" alt=""> | **[SugarToast](sugar-toast/)** | Floating notification overlays — Info / Success / Warning / Error types, configurable position and auto-dismiss. |
| <img src="media/icons/sugar-calendar.png" width="48" alt=""> | **[SugarCalendar](sugar-calendar/)** | Interactive month-grid date picker — keyboard navigation, min/max date constraints, locale day names, ANSI rendering. |
| <img src="media/icons/sugar-readline.png" width="48" alt=""> | **[SugarReadline](sugar-readline/)** | Interactive prompts — Text, Confirm, Selection, MultiSelect, Textarea. State-machine model, no external readline dependency. |
| <img src="media/icons/sugar-table.png" width="48" alt=""> | **[SugarTable](sugar-table/)** | Full-featured interactive data table — column definitions, StyledCell ANSI formatting, pagination, frozen rows/cols. |
| <img src="media/icons/sugar-diff.png" width="48" alt=""> | **[SugarDiff](sugar-diff/)** | Unified-diff engine — LCS line diff, `diff -u` hunks, writer + line-number scanner. Extracted from sugar-crush. |
| <img src="media/icons/sugar-mcp.png" width="48" alt=""> | **[SugarMcp](sugar-mcp/)** | Model Context Protocol (MCP) client core — JSON-RPC 2.0 codec, stdio transport with bounded child lifecycle, initialize/tools handshake, tool narrowing. Extracted from sugar-crush. |

## Apps built on the stack

| | App | Role |
|---|---|---|
| <img src="media/icons/candy-mold.png" width="48" alt=""> | **[CandyMold](candy-mold/)** | `composer create-project sugarcraft/candy-mold my-app` — bootstrap skeleton with a working counter Model. |
| <img src="media/icons/candy-shell.png" width="48" alt=""> | **[CandyShell](candy-shell/)** | Composer-installable CLI of 13 presentation subcommands (choose, confirm, file, filter, format, input, join, log, pager, spin, style, table, write) — scriptable terminal UI in one-liners. |
| <img src="media/icons/candy-freeze.png" width="48" alt=""> | **[CandyFreeze](candy-freeze/)** | Code → SVG screenshot generator (no `ext-gd` required). |
| <img src="media/icons/sugar-glow.png" width="48" alt=""> | **[SugarGlow](sugar-glow/)** | Markdown CLI viewer / pager. |
| <img src="media/icons/sugar-spark.png" width="48" alt=""> | **[SugarSpark](sugar-spark/)** | ANSI escape-sequence inspector. |
| <img src="media/icons/sugar-wishlist.png" width="48" alt=""> | **[SugarWishlist](sugar-wishlist/)** | TUI directory of SSH endpoints — YAML/JSON config + `pcntl_exec` into the chosen `ssh`. |
| <img src="media/icons/sugar-skate.png" width="48" alt=""> | **[SugarSkate](sugar-skate/)** | Personal key/value store — one SQLite database per store, `@dbname` namespaces, glob listing, TTL, binary values, JSON/YAML import/export. |
| <img src="media/icons/sugar-post.png" width="48" alt=""> | **[SugarPost](sugar-post/)** | Email sending library — SMTP + Resend API transports, attachments, HTML + plain-text multipart, fluent interface. |
| <img src="media/icons/candy-serve.png" width="48" alt=""> | **[CandyServe](candy-serve/)** | Self-hostable Git server over SSH (authorized keys), Git daemon, and HTTP. Users, repos, access control, optional LFS. |
| <img src="media/icons/candy-tetris.png" width="48" alt=""> | **[CandyTetris](candy-tetris/)** | Tetris clone — SRS rules, 7-bag, ghost piece, NES scoring, level-driven gravity. |
| <img src="media/icons/candy-files.png" width="48" alt=""> | **[CandyFiles](candy-files/)** | Dual-pane file manager — Midnight Commander style, multi-select, sort, delete-with-confirm. |
| <img src="media/icons/sugar-crush.png" width="48" alt=""> | **[SugarCrush](sugar-crush/)** | AI coding-assistant chat shell — direct multi-provider engine (OpenAI / Anthropic-compatible / SGLang / Bedrock / Vertex), shell-out command backends, and an offline EchoBackend. |
| <img src="media/icons/sugar-crush-web.png" width="48" alt=""> | **[SugarCrushWeb](sugar-crush-web/)** | Browser UI for SugarCrush's server mode — Vite + Vue 3 bundle committed as `dist/`, located by a one-class PHP shim so `sugarcrush serve` needs no Node. MVP: one session with live tool cards, diffs and approvals. |
| <img src="media/icons/sugar-stash.png" width="48" alt=""> | **[SugarStash](sugar-stash/)** | Three-pane git TUI — status / branches / log, single-key stage / unstage; shells out to `git` for every mutation. |
| <img src="media/icons/candy-query.png" width="48" alt=""> | **[CandyQuery](candy-query/)** | Terminal SQL browser — SQLite, MySQL and PostgreSQL: schema browsing, row editing, ad-hoc queries, EXPLAIN plans (PDO + `:memory:` test fixtures). |
| <img src="media/icons/sugar-tick.png" width="48" alt=""> | **[SugarTick](sugar-tick/)** | Privacy-first coding-time tracker — JSONL on disk, SugarCharts-driven dashboard, no cloud. |
| <img src="media/icons/candy-mines.png" width="48" alt=""> | **[CandyMines](candy-mines/)** | Minesweeper — first-click safety, recursive flood-fill, flag toggle, win/lose detection, deterministic-RNG injectable. |
| <img src="media/icons/candy-flip.png" width="48" alt=""> | **[CandyFlip](candy-flip/)** | ASCII GIF viewer — ext-gd decode, downsample to a cell grid, render as ANSI 24-bit blocks or a luminance-ramp. |
| <img src="media/icons/candy-top.png" width="48" alt="" onerror="this.style.display='none'"> | **[CandyTop](candy-top/)** | Terminal system monitor — live CPU, memory, network, disk, and process views built on sugar-charts, sugar-dash, and the candy-core runtime. |
| <img src="media/icons/sugar-reel.png" width="48" alt=""> | **[SugarReel](sugar-reel/)** | Terminal video player — pipes mp4 (and more) through `ffmpeg`/`ffprobe` on the fly and renders to ASCII / ANSI / truecolor half-block / sixel / kitty (GIF via ext-gd, no ffmpeg). |
| <img src="media/icons/honey-flap.png" width="48" alt=""> | **[HoneyFlap](honey-flap/)** | Flappy-Bird-style game — bird motion is a HoneyBounce projectile, pipes scroll left at a fixed cell rate. |

Each library has its own `README.md` with usage examples and a deep dive into
its public API.

## Quickstart — a counter app

Save as `counter.php` in a project with `composer require sugarcraft/candy-core`,
then run `php counter.php`:

```php
use SugarCraft\Core\{Cmd, KeyType, Model, Msg, Program, Subscriptions};
use SugarCraft\Core\Msg\KeyMsg;

final class Counter implements Model
{
    public function __construct(public readonly int $n = 0) {}
    public function init(): ?\Closure { return null; }

    public function update(Msg $msg): array
    {
        if ($msg instanceof KeyMsg && $msg->type === KeyType::Char && $msg->rune === 'q') {
            return [$this, Cmd::quit()];
        }
        return [
            $msg instanceof KeyMsg && $msg->type === KeyType::Up
                ? new self($this->n + 1)
                : ($msg instanceof KeyMsg && $msg->type === KeyType::Down
                    ? new self($this->n - 1)
                    : $this),
            null,
        ];
    }

    public function view(): string { return "n = {$this->n}\n↑ ↓ to count, q to quit\n"; }

    public function subscriptions(): ?Subscriptions { return null; }
}

(new Program(new Counter()))->run();
```

## Architecture

- **PHP 8.3+** — fibers, readonly props, enums, `match`, intersection types.
- **Runtime**: ReactPHP event loop — input, signals, render tick, and command
  execution all run concurrently on a single loop.
- **Style**: PSR-12 + readonly DTOs. Every `Style`, `Model`, etc. is
  immutable — `with*()` returns a new instance.
- **Testing**: PHPUnit 10. Snapshot ANSI tests for renderers; scripted-input
  event tests for the runtime.
- **Layout**: monorepo; every library also publishes as a standalone package
  and will later split into its own repo.

## Status

Every library in the table above ships at **v1**, each with its own composer
package and PHPUnit suite, and CI publishes per-library coverage to Codecov;
the rendering libraries pin their output with snapshot-ANSI tests. The
[website](https://sugarcraft.github.io/) library matrix carries live
per-package status, and each library's `README.md` documents its full public
API.

## Adding a new library or app

The full package roster lives in
[MATCHUPS.md](./docs/MATCHUPS.md), and the contributor / agent playbook in
[AGENTS.md](./AGENTS.md) walks through the complete integration checklist:
naming conventions, package skeleton, tests, examples, VHS demos (terminal
sessions recorded as GIFs), website tiles, and the central docs to update.
AI assistants should read AGENTS.md first.

## Running the test suites

The umbrella package is a metapackage; each library has its own
`composer.json` + `vendor/`. To test everything:

```sh
for d in candy-* sugar-* honey-*; do
    [ -f "$d/phpunit.xml" ] || continue
    (cd "$d" && composer install --quiet && vendor/bin/phpunit) || exit 1
done
```

Code style is enforced via `php-cs-fixer` (root `.php-cs-fixer.dist.php`). Run from the repo root:

```sh
PHP_CS_FIXER_IGNORE_ENV=1 php-cs-fixer fix --diff --allow-risky=yes
```

## Contributing

See [CONTRIBUTING.md](./CONTRIBUTING.md). Bugs, feature requests,
and new libraries — original tools, components, and terminal apps that
fill gaps in the PHP stack — are all welcome.
For security issues, see [SECURITY.md](./SECURITY.md).

## License

[MIT](./LICENSE).

## Credits & inspiration

SugarCraft is a PHP-native project. Its original design inspiration came
from the Go terminal ecosystem — notably
[Bubble Tea](https://github.com/charmbracelet/bubbletea) and the wider
[Charm](https://github.com/charmbracelet) family of libraries — reimagined
here for PHP 8.3+.

---

<p align="center">
  <a href="https://sugarcraft.github.io/"><img src="media/profile.png" alt="SugarCraft" width="120"></a><br>
  <em>made with sugar · sweet to build · fun to use</em>
</p>
