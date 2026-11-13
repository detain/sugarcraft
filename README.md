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
📖 **New here?** [Getting started](docs/GETTING-STARTED.md) — a finished binary, a first app, or a chart in five minutes.
📚 **Vocabulary:** [Glossary](docs/GLOSSARY.md) — every term this README uses, defined with its source file.

```sh
composer require sugarcraft/sugarcraft        # umbrella metapackage — every library
composer require sugarcraft/sugar-style   # …or just the package you need
```

> **Before you install** — `sugarcraft/*` packages publish from `dev-master`
> until their first tags are cut, so a Composer project left on the default
> `stable` minimum-stability cannot resolve them. Add
> `"minimum-stability": "dev", "prefer-stable": true` to your root
> `composer.json` (or suffix your require with `@dev`).

## What's in the box

Every package installs independently with Composer. The libraries are grouped by layer:

| | Library | Role |
|---|---|---|
| <img src="media/icons/sugar-core.png" width="48" alt=""> | **[SugarCraft](sugar-core/)** | MVC-style Model–Update–View TUI runtime — `Model` / `Msg` / `Cmd` / `Program`, plus a frame-diff renderer with an opt-in cursed cell-diff mode. |
| <img src="media/icons/sugar-ansi-parser.png" width="48" alt=""> | **[SugarAnsiParser](sugar-ansi-parser/)** | ECMA-48 VT500 state machine — ANSI byte-stream parser with abstract Handler interface. Extraction from sugar-vterm; formerly published as `sugarcraft/candy-ansi` (renamed 2026-10). |
| <img src="media/icons/sugar-cellbuffer.png" width="48" alt=""> | **[SugarCellBuffer](sugar-cellbuffer/)** | Cell-grid value objects — immutable Buffer (2-D cell grid) and Cell (`rune`/style/link/width). Shared foundation for all rendering; `Buffer::diff()` included. |
| <img src="media/icons/sugar-constraints.png" width="48" alt=""> | **[SugarConstraints](sugar-constraints/)** | Constraint-based layout solver — `LayoutSolver` interface with a deterministic `GreedySolver` engine (`CassowarySolver` kept only as a deprecated shim). |
| <img src="media/icons/sugar-async.png" width="48" alt=""> | **[SugarAsync](sugar-async/)** | Shared async vocabulary for ReactPHP — CancellationToken, Subscriptions, AsyncOps helpers (withTimeout, withDeadline, cancellable, retry, debounce, throttle, all/race, onPending, singleFlight) + TTL async cache. |
| <img src="media/icons/sugar-input.png" width="48" alt=""> | **[SugarInput](sugar-input/)** | Terminal input decoder — raw TTY bytes to typed events: keyboard (Kitty progressive protocol, xterm modifyOtherKeys, legacy encodings) and mouse (SGR, urxvt, X10) via `InputDriver` / `EscapeDecoder`. |
| <img src="media/icons/sugar-mouse.png" width="48" alt=""> | **[SugarMouse](sugar-mouse/)** | Mouse hit-testing — mark rendered regions, scan the frame, hit-test the event; `ZoneClickTracker` for press/release dedupe, plus the absorbed bubblezone-style `Zones` facade and the `Zones\Manager` layer (hover / drag / multi-click) under `SugarCraft\Mouse\Zones`. Shared default via `Zones::defaultManager()`; `newGlobal()` mints a fresh independent one. Pointer-driven; keyboard paths are consumer-owned — see sugar-mouse README Accessibility. |
| <img src="media/icons/sugar-style.png" width="48" alt=""> | **[SugarStyle](sugar-style/)** | Declarative styling + layout — `Style`, `Border`, `Table\Table`, `Listing\ItemList`, `Tree\Tree`, `Layout::joinHorizontal`/`joinVertical`, `Layout::place`, multi-layer `Canvas`, `Theme`. |
| <img src="media/icons/sugar-testing.png" width="48" alt=""> | **[SugarTesting](sugar-testing/)** | Test harness for SugarCraft programs — ProgramSimulator, scripted input, golden/snapshot assertions, VHS tape building, graphics-stream decoding. |
| <img src="media/icons/sugar-spring.png" width="48" alt=""> | **[SugarSpring](sugar-spring/)** | Damped spring physics + Newtonian projectile simulation. |
| <img src="media/icons/sugar-fuzzy.png" width="48" alt=""> | **[SugarFuzzy](sugar-fuzzy/)** | Fuzzy string matching with scored match indices — powers filter-as-you-type highlighting across the ecosystem. |
| <img src="media/icons/sugar-widgets.png" width="48" alt=""> | **[SugarWidgets](sugar-widgets/)** | Components: Tabs, Tree, Table, Progress, Help, Key, Paginator, Stopwatch, Timer, TextEdit, OptionRow; plus 8 deprecated sugar-forms re-export alias families (Cursor, FilePicker, ItemList, Scrollbar, Spinner, TextArea, TextInput, Viewport) — prefer sugar-forms. |
| <img src="media/icons/sugar-vterm.png" width="48" alt=""> | **[SugarVterm](sugar-vterm/)** | Virtual terminal emulator — ANSI byte stream → cell grid + cursor + mode state. |
| <img src="media/icons/sugar-pty.png" width="48" alt=""> | **[SugarPty](sugar-pty/)** | Pseudo-terminal primitives — `PtySystemFactory` → `Contract\PtySystem` master/slave: POSIX via FFI to libc (Linux/macOS, opt-in controlling terminal) plus a Windows ConPTY backend (`CreatePseudoConsole`; beware its empty-`$env` full-parent inversion). Legacy `Pty` facade @deprecated through v1.x. |
| <img src="media/icons/sugar-forms.png" width="48" alt=""> | **[SugarForms](sugar-forms/)** | Form library (port of charmbracelet/huh) and shared form engine behind SugarWidgets — Note, Input, Confirm, Select, MultiSelect, Text, FilePicker, Date, Slider, Color, NumberField, RadioPills; multi-page Groups; stock themes; validators; fuzzy / async suggestions; ActionSpinner. |
| <img src="media/icons/sugar-focus.png" width="48" alt=""> | **[SugarFocus](sugar-focus/)** | Tiny dependency-free focus ring — ordered focusable regions, single focused member, wrap-around Tab/Shift-Tab. |
| <img src="media/icons/sugar-gallery.png" width="48" alt="" onerror="this.style.display='none'"> | **[SugarGallery](sugar-gallery/)** | Poster grids & rails for media TUIs — virtualized PosterGrid, Rail carousel, PosterCard. |
| <img src="media/icons/sugar-charts.png" width="48" alt=""> | **[SugarCharts](sugar-charts/)** | Canvas + Sparkline, Bar, Line, Heatmap, Scatter, TimeSeries, Streamline, Waveline, OHLC, Picture (Sixel/Kitty/iTerm2) + Aggregation toolbox. |
| <img src="media/icons/sugar-image.png" width="48" alt=""> | **[SugarImage](sugar-image/)** | Image-to-cell renderer for the terminal — Sixel, Kitty, iTerm2, the chafa CLI, and Unicode text tiers (half/quarter block, ASCII ramp, chafa-style symbol coverage); ext-gd decode. |
| <img src="media/icons/sugar-markdown.png" width="48" alt=""> | **[SugarMarkdown](sugar-markdown/)** | Markdown → ANSI renderer with word-wrap, OSC 8 hyperlinks, 9 built-in themes (notty aliases plain). |
| <img src="media/icons/sugar-cli.png" width="48" alt=""> | **[SugarCli](sugar-cli/)** | CLI presentation helpers — StatusLine, Banner, Section, Stage, HelpText, Frame, Logo, and themes. |
| <img src="media/icons/sugar-ssh-server.png" width="48" alt=""> | **[SugarSshServer](sugar-ssh-server/)** | SSH-server middleware framework (leans on host `sshd`); pluggable Transport: InProcessTransport (default — sugar-pty supervisor + Spawn middleware) or HostSshdTransport (legacy, opt-in — inline middleware, including running SugarCraft apps in SSH sessions). |
| <img src="media/icons/sugar-metrics.png" width="48" alt=""> | **[SugarMetrics](sugar-metrics/)** | Telemetry primitives — counters, gauges, histograms, UpDownCounters, AsyncCounters, AsyncGauges across five backends (InMemory, JsonStream, StatsD, Prometheus textfile, Multi fan-out), plus drop-in SSH session middleware for SugarSshServer. |
| <img src="media/icons/sugar-log.png" width="48" alt=""> | **[SugarLog](sugar-log/)** | Colorful leveled logger — Debug / Info / Warn / Error / Fatal with structured context, Text / JSON / Logfmt formatters, sub-loggers, and StandardLogAdapter. |
| <img src="media/icons/sugar-colorprofile.png" width="48" alt=""> | **[SugarColorprofile](sugar-colorprofile/)** | Detects the terminal's color profile and degrades colors, strings and whole streams one-way down the ladder (reads TERM/COLORTERM/NO_COLOR…, may exec infocmp). StandardColors, ProfileWriter and an opt-in perceptual matcher. |
| <img src="media/icons/sugar-treelist.png" width="48" alt=""> | **[SugarTreelist](sugar-treelist/)** | List view with box-drawing prefixes, cursor navigation, word-wrap, and live filtering (host-driven). |
| <img src="media/icons/sugar-panels.png" width="48" alt=""> | **[SugarPanels](sugar-panels/)** | H/V panels with flex/natural sizing, borders, nesting, and stateful delta rendering. |
| <img src="media/icons/sugar-overlay.png" width="48" alt=""> | **[SugarOverlay](sugar-overlay/)** | Terminal overlay compositor — composites Veil overlays with backdrop dimming, z-stack ordering, positioning, and click-outside hit-testing; opt-in `Veil::withUntrustedContent()` at the veil boundary. |
| <img src="media/icons/sugar-navstack.png" width="48" alt=""> | **[SugarNavstack](sugar-navstack/)** | Navigation breadcrumbs — mutable NavStack with explicit push/pop, width-aware breadcrumbs, and substring filter. |
| <img src="media/icons/sugar-dashboard.png" width="48" alt="" onerror="this.style.display='none'"> | **[SugarDashboard](sugar-dashboard/)** | Dashboard TUI library — column grid, constraint layout, ratio grid, data grid, tree, modal, status bar, tabs, plot/canvas, and a module/plugin system. |
| <img src="media/icons/sugar-quickpick.png" width="48" alt=""> | **[SugarQuickpick](sugar-quickpick/)** | Fuzzy finder / quick-pick overlay for terminal UIs — embeds into your app via the package-local Model contract: your loop decodes keys and forwards them (type()/cursorUp()/Down(); Enter reads selected()). |
| <img src="media/icons/sugar-flexbox.png" width="48" alt=""> | **[SugarFlexbox](sugar-flexbox/)** | FlexBox layout engine + delta-rendering Table (sort/filter/cursor) + sticky Viewport + Scrollbar. Ratio-based sizing, gap, align, per-column styling. |
| <img src="media/icons/sugar-toast.png" width="48" alt=""> | **[SugarToast](sugar-toast/)** | Floating notification overlays — Error / Warning / Info / Success types, configurable position and host-driven auto-dismiss. |
| <img src="media/icons/sugar-datepicker.png" width="48" alt=""> | **[SugarDatepicker](sugar-datepicker/)** | Interactive month-grid date picker — keyboard navigation, min/max date constraints, locale-ready day names (English ships today), ANSI rendering; weeks start Monday (firstDayOfWeek is not configurable). |
| <img src="media/icons/sugar-readline.png" width="48" alt=""> | **[SugarReadline](sugar-readline/)** | Interactive prompts — Text, Confirm, Selection, MultiSelect, Textarea. State-machine model, no external readline dependency. |
| <img src="media/icons/sugar-table.png" width="48" alt=""> | **[SugarTable](sugar-table/)** | Customizable data table — column definitions, StyledCell ANSI formatting, pagination, frozen columns. Keyboard-driven; wire sugar-input/sugar-mouse events yourself. |
| <img src="media/icons/sugar-diff.png" width="48" alt=""> | **[SugarDiff](sugar-diff/)** | Unified-diff engine — LCS line diff, `diff -u` hunks, writer + line-number scanner. Extracted from candy-code. |
| <img src="media/icons/sugar-mcp-client.png" width="48" alt=""> | **[Sugar MCP Client](sugar-mcp-client/)** | Model Context Protocol (MCP) client core — JSON-RPC 2.0 codec, stdio transport with bounded child lifecycle, initialize/tools handshake, deny-before-allow SERVER narrowing (tool rosters follow servers). Extracted from candy-code. |
| <img src="media/icons/sugar-releases.png" width="48" alt=""> | **[SugarReleases](sugar-releases/)** | Phar self-update engine + launch-time update notice — GitHub release selection (stable + rolling dev channel), sha256-sidecar and pinned-key Ed25519 verification before the swap, one-shot `.old` rollback, Windows relaunch-dance swap. Extracted from candy-code. |
| <img src="media/icons/sugar-ignore.png" width="48" alt=""> | **[SugarIgnore](sugar-ignore/)** | gitignore-style ignore matching over injected file contents (no IO) — negation, dir-only, anchoring, `**`, escapes, nested ignore files, `.git/info/exclude` precedence, and a backtrack-free matcher that fails closed on hostile patterns. Extracted from candy-code. |
| <img src="media/icons/sugar-animation.png" width="48" alt=""> | **[SugarAnimation](sugar-animation/)** | ANSI/GIF animation toolkit (formerly published as `sugarcraft/candy-flip`, renamed 2026-10) — ext-gd decode, cell-grid downsample, solid / density (luminance-ramp) presets, `.ansi` art playback; the viewer CLI lives in candy-gifview. |
| <img src="media/icons/sugar-video.png" width="48" alt=""> | **[SugarVideo](sugar-video/)** | Terminal video player — pipes mp4/webm/avi (and more) through `ffmpeg`/`ffprobe` on the fly and renders to ASCII / ANSI / truecolor half- and quarter-block / sixel / kitty / iTerm2 (GIF and animated WebP via ext-gd, no ffmpeg). |

## Apps built on the stack

| | App | Role |
|---|---|---|
| <img src="media/icons/candy-starter.png" width="48" alt=""> | **[CandyStarter](candy-starter/)** | `composer create-project sugarcraft/candy-starter my-app --stability=dev` — bootstrap skeleton with a working counter Model (Packagist carries only dev-master until a `candy-starter/*` tag exists). |
| <img src="media/icons/candy-scriptui.png" width="48" alt=""> | **[CandyScriptui](candy-scriptui/)** | Composer-installable CLI of 13 presentation subcommands (choose, confirm, file, filter, format, input, join, log, pager, spin, style, table, write) — scriptable terminal UI in one-liners. |
| <img src="media/icons/candy-codeshot.png" width="48" alt=""> | **[CandyCodeshot](candy-codeshot/)** | Code → SVG (or PNG via `ext-gd`) screenshot generator. |
| <img src="media/icons/candy-mdview.png" width="48" alt=""> | **[CandyMdview](candy-mdview/)** | Markdown CLI viewer / pager. |
| <img src="media/icons/candy-ansi-inspect.png" width="48" alt=""> | **[CandyAnsiInspect](candy-ansi-inspect/)** | ANSI escape-sequence inspector. |
| <img src="media/icons/candy-ssh-launcher.png" width="48" alt=""> | **[CandySshLauncher](candy-ssh-launcher/)** | TUI directory of SSH endpoints — YAML/JSON config + `pcntl_exec` into the chosen `ssh`. |
| <img src="media/icons/candy-kv.png" width="48" alt=""> | **[CandyKv](candy-kv/)** | Personal key/value store — one SQLite file per database, glob + fuzzy listing, TTL, binary-safe values, JSON/YAML import/export. |
| <img src="media/icons/candy-mail.png" width="48" alt=""> | **[CandyMail](candy-mail/)** | Email library + CLI — SMTP + Resend API transports, attachments, HTML + plain-text multipart, fluent interface. |
| <img src="media/icons/candy-git-server.png" width="48" alt=""> | **[CandyGitServer](candy-git-server/)** | Self-hostable Git server — smart-HTTP clone/fetch/push via `serve --http`; SSH delegates real git under host-sshd `ForceCommand`; git:// daemon advertisement-only. Users, repos, access control, optional LFS. |
| <img src="media/icons/candy-tetris.png" width="48" alt=""> | **[CandyTetris](candy-tetris/)** | Tetris clone — SRS rules, 7-bag, ghost piece, NES-style base scoring with Guideline bonuses, level-driven gravity. |
| <img src="media/icons/candy-files.png" width="48" alt=""> | **[CandyFiles](candy-files/)** | Dual-pane file manager — Midnight Commander style, multi-select, sort, delete-with-confirm. |
| <img src="media/icons/candy-code.png" width="48" alt=""> | **[CandyCode](candy-code/)** | Terminal AI coding agent (TUI) — eleven provider type keys over nine engines (OpenAI / Anthropic / Claude Code / Codex / SGLang / Bedrock / Vertex / Gemini / custom), thirty-eight built-in tools on every launch behind permission modes, sub-agents, teams and workflows, MCP client and server, hooks, skills and image/video generation — plus an offline echo backend for demos. |
| <img src="media/icons/candy-code-web.png" width="48" alt=""> | **[CandyCodeWeb](candy-code-web/)** | Browser UI for CandyCode's server mode — Vite + Vue 3 bundle committed as `dist/`, located by a one-class PHP shim so `candy-code serve` needs no Node. Multi-session live: up to twelve tabs, and a tiled grid of up to nine sessions, with tool cards, diffs and approvals. |
| <img src="media/icons/candy-vcr.png" width="48" alt=""> | **[CandyVcr](candy-vcr/)** | Record + replay sugar-core sessions — JSONL/YAML cassettes, Recorder hook (`setRecorder`/`withRecorder`), `Player` with byte + cell-grid assertions, `.tape`→GIF renderer, CLI. |
| <img src="media/icons/candy-git.png" width="48" alt=""> | **[CandyGit](candy-git/)** | Terminal Git client — a TUI for diff/hunk staging, branching, log, stash, worktrees and rebase; shells out to `git` for every mutation. |
| <img src="media/icons/candy-sql.png" width="48" alt=""> | **[CandySql](candy-sql/)** | Terminal SQL client — SQLite, MySQL and PostgreSQL: schema browsing, row browsing, ad-hoc queries, EXPLAIN plans (PDO + `:memory:` test fixtures). |
| <img src="media/icons/candy-codetime.png" width="48" alt=""> | **[CandyCodetime](candy-codetime/)** | Privacy-first coding-time tracker — heartbeats in JSONL day-files by default, optional SQLite backend (`--backend`), SugarCharts-driven dashboard, no cloud. |
| <img src="media/icons/candy-minesweeper.png" width="48" alt=""> | **[CandyMinesweeper](candy-minesweeper/)** | Minesweeper — first-click safety, iterative flood-fill, flag toggle, win/lose detection, deterministic-RNG injectable. |
| <img src="media/icons/candy-gifview.png" width="48" alt=""> | **[CandyGifView](candy-gifview/)** | Terminal GIF viewer — the sugar-animation CLI: sizes to the viewport, plays in the alternate screen (solid 24-bit blocks or an ASCII luminance ramp). |
| <img src="media/icons/candy-image.png" width="48" alt=""> | **[CandyImage](candy-image/)** | Terminal image viewer — the chafa-independent CLI over sugar-image: protocol tiers (sixel/kitty/iTerm2), half/quarter block and text tiers with colored-character modes, cell sizing with `--scale`, `--canvas`/`--work` dials, GIF animation, `--dump-detect`. |
| <img src="media/icons/candy-top.png" width="48" alt=""> | **[CandyTop](candy-top/)** | Terminal system monitor — cpu / mem+disks / net / process boxes with braille, block and sextant graphs, gradient meters; up to six AMD/Intel GPU and Intel/AMD NPU boxes, an ipmi BMC band and a VM dashboard; battery readouts, menus, presets, 43 themes (42 ported from btop plus candy-top's own pastel), and btop-compatible config with save/reload, over Linux `/proc` + `/sys` and FreeBSD collectors. |
| <img src="media/icons/candy-flappy.png" width="48" alt=""> | **[CandyFlappy](candy-flappy/)** | Flappy-Bird-style game — bird motion is a SugarSpring projectile, pipes scroll left at a fixed cell rate. |

**Renamed packages (2026-10):** every package moved to the two-prefix
scheme above — `candy-` for runnable apps and CLI tools, `sugar-` for
libraries (the `honey-` family was retired). The full old → new map, with
redirect notes, lives in
[`PROJECT_NAMES.md`](./PROJECT_NAMES.md#renamed-in-2026-10).

Each library has its own `README.md` with usage examples and a deep dive into
its public API.

## Quickstart — a counter app

Save as `counter.php` in a project with `composer require sugarcraft/sugar-core`,
then run `php counter.php`:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

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
for d in candy-* sugar-*; do
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
The fleet trust model — what counts as untrusted input and where the
sanitize gates must sit — is [docs/TRUST-BOUNDARY.md](./docs/TRUST-BOUNDARY.md).

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
