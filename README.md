# SugarCraft

<p align="center">
  <img src="media/social-preview.png" alt="SugarCraft — sweet to build, fun to use" width="720">
</p>

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg)](https://app.codecov.io/gh/detain/sugarcraft)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.1-8892bf.svg)](https://www.php.net/)
[![PRs Welcome](https://img.shields.io/badge/PRs-welcome-ff5f87.svg)](CONTRIBUTING.md)
<!-- BADGES:END -->

PHP ports of the [Charmbracelet](https://charm.sh) TUI ecosystem (plus
[bubblezone](https://github.com/lrstanley/bubblezone),
[ntcharts](https://github.com/NimbleMarkets/ntcharts), and a small
SugarCraft-flavoured sweetshop of original libraries) — composer-installable,
PHP 8.1+, async on ReactPHP.

🌐 **Website:** [sugarcraft.github.io](https://sugarcraft.github.io/) — library matrix, quickstart, comparison page.

```sh
composer require candycore/candycore
```

## What's in the box

Sixteen libraries grouped by layer:

| | Library | Role |
|---|---|---|
| <img src="media/icons/candy-core.png" width="48" alt=""> | **[CandyCore](candy-core/)** | Elm-architecture TUI runtime — `Model` / `Msg` / `Cmd` / `Program` (incl. cursed cell-diff renderer). Port of [bubbletea](https://github.com/charmbracelet/bubbletea). |
| <img src="media/icons/candy-sprinkles.png" width="48" alt=""> | **[CandySprinkles](candy-sprinkles/)** | Declarative styling + layout — `Style`, `Border`, `Table`, `List`, `Tree`, `Layout::join`, `Place`, `Canvas` (multi-layer compositor). Port of [lipgloss](https://github.com/charmbracelet/lipgloss). |
| <img src="media/icons/honey-bounce.png" width="48" alt=""> | **[HoneyBounce](honey-bounce/)** | Damped spring physics + Newtonian projectile sim. Port of [harmonica](https://github.com/charmbracelet/harmonica). |
| <img src="media/icons/candy-zone.png" width="48" alt=""> | **[CandyZone](candy-zone/)** | Mouse-zone tracker — wrap rendered chunks, get back bounding boxes. Port of [bubblezone](https://github.com/lrstanley/bubblezone). |
| <img src="media/icons/sugar-bits.png" width="48" alt=""> | **[SugarBits](sugar-bits/)** | 14 components: TextInput, TextArea, ItemList, Table, Viewport, FilePicker, Progress, Spinner, Cursor, Help, Key, Paginator, Stopwatch, Timer. Port of [bubbles](https://github.com/charmbracelet/bubbles). |
| <img src="media/icons/sugar-charts.png" width="48" alt=""> | **[SugarCharts](sugar-charts/)** | Canvas + Sparkline, Bar, Line, Heatmap, Scatter, TimeSeries, Streamline, Waveline, OHLC, Picture (Sixel/Kitty/iTerm2). Port of [ntcharts](https://github.com/NimbleMarkets/ntcharts). |
| <img src="media/icons/sugar-prompt.png" width="48" alt=""> | **[SugarPrompt](sugar-prompt/)** | Form library — Note, Input, Confirm, Select, MultiSelect, Text, FilePicker; multi-page Groups; 6 themes. Port of [huh](https://github.com/charmbracelet/huh). |
| <img src="media/icons/candy-shell.png" width="48" alt=""> | **[CandyShell](candy-shell/)** | Composer-installable CLI of all 13 subcommands (choose, confirm, file, filter, format, input, join, log, pager, spin, style, table, write). Port of [gum](https://github.com/charmbracelet/gum). |
| <img src="media/icons/candy-shine.png" width="48" alt=""> | **[CandyShine](candy-shine/)** | Markdown → ANSI renderer with word-wrap, OSC 8 hyperlinks, 8 themes. Port of [glamour](https://github.com/charmbracelet/glamour). |
| <img src="media/icons/candy-kit.png" width="48" alt=""> | **[CandyKit](candy-kit/)** | CLI presentation helpers — StatusLine, Banner, Section, Stage, HelpText. Port of [fang](https://github.com/charmbracelet/fang). |
| <img src="media/icons/candy-freeze.png" width="48" alt=""> | **[CandyFreeze](candy-freeze/)** | Code → SVG screenshot generator (no `ext-gd` required). Port of [freeze](https://github.com/charmbracelet/freeze). |
| <img src="media/icons/sugar-glow.png" width="48" alt=""> | **[SugarGlow](sugar-glow/)** | Markdown CLI viewer / pager. Port of [glow](https://github.com/charmbracelet/glow). |
| <img src="media/icons/sugar-spark.png" width="48" alt=""> | **[SugarSpark](sugar-spark/)** | ANSI escape-sequence inspector. Port of [sequin](https://github.com/charmbracelet/sequin). |
| <img src="media/icons/candy-wish.png" width="48" alt=""> | **[CandyWish](candy-wish/)** | SSH server middleware — Logger, Auth, RateLimit, BubbleTea (mount a CandyCore Program over `ForceCommand`). Port of [wish](https://github.com/charmbracelet/wish). |
| <img src="media/icons/sugar-wishlist.png" width="48" alt=""> | **[SugarWishlist](sugar-wishlist/)** | TUI directory of SSH endpoints — YAML/JSON config + `pcntl_exec` into the chosen `ssh`. Port of [wishlist](https://github.com/charmbracelet/wishlist). |
| <img src="media/icons/candy-metrics.png" width="48" alt=""> | **[CandyMetrics](candy-metrics/)** | Telemetry primitives — counters, gauges, histograms with InMemory / JSON / StatsD / Prometheus textfile / Multi backends, plus a CandyWish session middleware. Port of [promwish](https://github.com/charmbracelet/promwish). |

## Apps built on the stack

| | App | Role |
|---|---|---|
| <img src="media/icons/candy-mold.png" width="48" alt=""> | **[CandyMold](candy-mold/)** | `composer create-project candycore/candy-mold my-app` — bootstrap skeleton with a working counter Model. Port of [bubbletea-app-template](https://github.com/charmbracelet/bubbletea-app-template). |
| <img src="media/icons/candy-tetris.png" width="48" alt=""> | **[CandyTetris](candy-tetris/)** | Tetris clone — SRS rules, 7-bag, ghost piece, NES scoring, level-driven gravity. Port of [tetrigo](https://github.com/Broderick-Westrope/tetrigo). |
| <img src="media/icons/super-candy.png" width="48" alt=""> | **[SuperCandy](super-candy/)** | Dual-pane file manager — Midnight Commander style, multi-select, sort, delete-with-confirm. Port of [superfile](https://github.com/yorukot/superfile). |
| <img src="media/icons/sugar-crush.png" width="48" alt=""> | **[SugarCrush](sugar-crush/)** | AI coding-assistant chat shell — pluggable backend (EchoBackend offline; CommandBackend for Anthropic / OpenAI / Ollama via a wrapper script). Port of [crush](https://github.com/charmbracelet/crush). |
| <img src="media/icons/sugar-stash.png" width="48" alt=""> | **[SugarStash](sugar-stash/)** | Three-pane git TUI — status / branches / log, single-key stage / unstage; shells out to `git` for every mutation. Port of [lazygit](https://github.com/jesseduffield/lazygit). |
| <img src="media/icons/candy-query.png" width="48" alt=""> | **[CandyQuery](candy-query/)** | Terminal SQLite browser — list tables, browse rows, run ad-hoc queries (PDO + `:memory:` test fixtures). Port of [lazysql](https://github.com/jorgerojas26/lazysql). |
| <img src="media/icons/sugar-tick.png" width="48" alt=""> | **[SugarTick](sugar-tick/)** | Privacy-first coding-time tracker — JSONL on disk, SugarCharts-driven dashboard, no cloud / no MongoDB. Port of [TakaTime](https://github.com/Rtarun3606k/TakaTime). |
| <img src="media/icons/candy-mines.png" width="48" alt=""> | **[CandyMines](candy-mines/)** | Minesweeper — first-click safety, recursive flood-fill, flag toggle, win/lose detection, deterministic-RNG injectable. Port of [go-sweep](https://github.com/maxpaulus43/go-sweep). |
| <img src="media/icons/candy-flip.png" width="48" alt=""> | **[CandyFlip](candy-flip/)** | ASCII GIF viewer — ext-gd decode, downsample to a cell grid, render as ANSI 24-bit blocks or a luminance-ramp. Port of [gifterm](https://github.com/namzug16/gifterm). |
| <img src="media/icons/honey-flap.png" width="48" alt=""> | **[HoneyFlap](honey-flap/)** | Flappy-Bird-style game — bird motion is a HoneyBounce projectile, pipes scroll left at a fixed cell rate. Port of [flapioca](https://github.com/kbrgl/flapioca). |

Each library has its own `README.md` with usage examples and a deep dive into
its public API.

## Quickstart — a counter app

```php
use CandyCore\Core\{Cmd, KeyType, Model, Msg, Program};
use CandyCore\Core\Msg\KeyMsg;

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
}

(new Program(new Counter()))->run();
```

## Architecture

- **PHP 8.1+** — fibers, readonly props, enums, `match`, intersection types.
- **Runtime**: ReactPHP event loop. Mirrors goroutine semantics for input,
  signals, render tick, command execution.
- **Style**: PSR-12 + readonly DTOs. Every `Style`, `Model`, etc. is
  immutable — `with*()` returns a new instance.
- **Testing**: PHPUnit 10. Snapshot ANSI tests for renderers; scripted-input
  event tests for the runtime.
- **Layout**: monorepo during the porting phase. Each library will split
  into its own repo at v1.0.

## Status

Every library in the table above is **at v1**. The full surface of every Go
counterpart that PHP can reasonably express (modulo the niche items called
out in `CONVERSION.md` § Phase audit) has been ported. See
[CONVERSION.md](./CONVERSION.md) for the full roadmap, per-library status,
and the v2-parity sweep against Bubble Tea v2 / Lipgloss v2 / Bubbles v2.

## Adding a new library or app

The matchup of upstream → SugarCraft port is the source-of-truth in
[MATCHUPS.md](./MATCHUPS.md), and the contributor / agent playbook in
[AGENTS.md](./AGENTS.md) walks through the full integration: naming
conventions, package skeleton, tests, examples, VHS demos, website
tiles, and the central docs to update. AI assistants should read
AGENTS.md first.

## Running the test suites

The umbrella package is a metapackage; each library has its own
`composer.json` + `vendor/`. To test everything:

```sh
for d in candy-core candy-sprinkles honey-bounce candy-zone sugar-bits \
         sugar-charts sugar-prompt candy-shell candy-shine candy-kit \
         candy-freeze sugar-glow sugar-spark \
         candy-wish sugar-wishlist candy-metrics \
         candy-mold candy-tetris super-candy sugar-crush \
         sugar-stash candy-query sugar-tick candy-mines candy-flip honey-flap; do
    (cd "$d" && composer install --quiet && vendor/bin/phpunit) || exit 1
done
```

## Contributing

See [CONTRIBUTING.md](./CONTRIBUTING.md). Bugs, feature requests, and
ports of additional Charmbracelet (or compatible) libraries welcome.
For security issues, see [SECURITY.md](./SECURITY.md).

## License

[MIT](./LICENSE).

---

<p align="center">
  <a href="https://sugarcraft.github.io/"><img src="media/profile.png" alt="SugarCraft" width="120"></a><br>
  <em>made with sugar · sweet to build · fun to use</em>
</p>
