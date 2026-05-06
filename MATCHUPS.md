# Upstream → SugarCraft matchup

The canonical map from each upstream Go (or other-language) project to its
SugarCraft port. Source-of-truth for **library identity**: when adding a
new port, link the upstream repo here first, then thread the same row
into [PROJECT_NAMES.md](./PROJECT_NAMES.md) and the website
([`docs/index.html`](./docs/index.html)).

> When this file changes, the corresponding row in
> [`docs/index.html`](./docs/index.html) (homepage lib / app grids) and
> the per-lib detail page under [`docs/lib/`](./docs/lib/) must update
> too. The contributor playbook in [`AGENTS.md`](./AGENTS.md) walks
> through the full add-a-lib flow.

Status legend:

- 🟢 v1 ready (public API + tests + docs + demo)
- 🟡 in progress (some surface shipped, gaps tracked in the audit)
- 🔴 planning (entry exists but no code yet)
- 🚀 split into its own repo (lives under `github.com/sugarcraft/<name>`)

---

## Charmbracelet libraries

| Upstream | SugarCraft port | Subdir | Composer pkg | Namespace | Status | Role |
|---|---|---|---|---|:---:|---|
| [charmbracelet/bubbletea](https://github.com/charmbracelet/bubbletea) | **CandyCore** | `candy-core/` | `candycore/candy-core` | `CandyCore\Core` | 🟢 | Elm-architecture TUI runtime |
| [charmbracelet/lipgloss](https://github.com/charmbracelet/lipgloss) | **CandySprinkles** | `candy-sprinkles/` | `candycore/candy-sprinkles` | `CandyCore\Sprinkles` | 🟢 | Declarative styling + layout |
| [charmbracelet/harmonica](https://github.com/charmbracelet/harmonica) | **HoneyBounce** | `honey-bounce/` | `candycore/honey-bounce` | `CandyCore\Bounce` | 🟢 | Spring physics + Newtonian projectile sim |
| [lrstanley/bubblezone](https://github.com/lrstanley/bubblezone) | **CandyZone** | `candy-zone/` | `candycore/candy-zone` | `CandyCore\Zone` | 🟢 | Mouse-zone tracker |
| [charmbracelet/bubbles](https://github.com/charmbracelet/bubbles) | **SugarBits** | `sugar-bits/` | `candycore/sugar-bits` | `CandyCore\Bits` | 🟡 | 14 prebuilt components (TextInput, ItemList, Table, …) |
| [NimbleMarkets/ntcharts](https://github.com/NimbleMarkets/ntcharts) | **SugarCharts** | `sugar-charts/` | `candycore/sugar-charts` | `CandyCore\Charts` | 🟡 | Sparkline / Bar / Line / Heatmap / Scatter / TimeSeries / OHLC / picture |
| [charmbracelet/huh](https://github.com/charmbracelet/huh) | **SugarPrompt** | `sugar-prompt/` | `candycore/sugar-prompt` | `CandyCore\Prompt` | 🟢 | Form library — Note / Input / Confirm / Select / MultiSelect / Text / FilePicker |
| [charmbracelet/gum](https://github.com/charmbracelet/gum) | **CandyShell** | `candy-shell/` | `candycore/candy-shell` | `CandyCore\Shell` | 🟡 | Composer-installable CLI of 13 subcommands |
| [charmbracelet/glamour](https://github.com/charmbracelet/glamour) | **CandyShine** | `candy-shine/` | `candycore/candy-shine` | `CandyCore\Shine` | 🟡 | Markdown → ANSI renderer (themes, syntax, OSC 8 hyperlinks) |
| [charmbracelet/glow](https://github.com/charmbracelet/glow) | **SugarGlow** | `sugar-glow/` | `candycore/sugar-glow` | `CandyCore\Glow` | 🟢 | Markdown CLI viewer / pager (consumes CandyShine) |
| [charmbracelet/freeze](https://github.com/charmbracelet/freeze) | **CandyFreeze** | `candy-freeze/` | `candycore/candy-freeze` | `CandyCore\Freeze` | 🟢 | Code → SVG screenshot (no GD / Imagick required) |
| [charmbracelet/sequin](https://github.com/charmbracelet/sequin) | **SugarSpark** | `sugar-spark/` | `candycore/sugar-spark` | `CandyCore\Spark` | 🟢 | ANSI escape-sequence inspector |
| [charmbracelet/fang](https://github.com/charmbracelet/fang) | **CandyKit** | `candy-kit/` | `candycore/candy-kit` | `CandyCore\Kit` | 🟢 | CLI presentation helpers (StatusLine / Banner / Section / Stage / HelpText) |
| [charmbracelet/wish](https://github.com/charmbracelet/wish) | **CandyWish** | `candy-wish/` | `candycore/candy-wish` | `CandyCore\Wish` | 🟢 | SSH-server middleware framework (leans on host `sshd`) |
| [charmbracelet/wishlist](https://github.com/charmbracelet/wishlist) | **SugarWishlist** | `sugar-wishlist/` | `candycore/sugar-wishlist` | `CandyCore\Wishlist` | 🟢 | SSH endpoint launcher (YAML / JSON shortcuts directory) |
| [charmbracelet/promwish](https://github.com/charmbracelet/promwish) | **CandyMetrics** | `candy-metrics/` | `candycore/candy-metrics` | `CandyCore\Metrics` | 🟢 | Telemetry primitives + CandyWish session middleware |
| [charmbracelet/log](https://github.com/charmbracelet/log) | **CandyLog** | `candy-log/` | `candycore/candy-log` | `CandyCore\Log` | 🟢 | Minimal, colorful logging library |
| [charmbracelet/colorprofile](https://github.com/charmbracelet/colorprofile) | **CandyPalette** | `candy-palette/` | `candycore/candy-palette` | `CandyCore\Palette` | 🔴 | Terminal color detection + ICC profile handling |
| [charmbracelet/soft-serve](https://github.com/charmbracelet/soft-serve) | **CandyServe** | `candy-serve/` | `candycore/candy-serve` | `CandyCore\Serve` | 🔴 | Self-hostable Git server over SSH |
| [charmbracelet/skate](https://github.com/charmbracelet/skate) | **SugarSkate** | `sugar-skate/` | `candycore/sugar-skate` | `CandyCore\Skate` | 🔴 | Personal key/value store |
| [charmbracelet/pop](https://github.com/charmbracelet/pop) | **SugarPost** | `sugar-post/` | `candycore/sugar-post` | `CandyCore\Post` | 🔴 | Terminal email client (SMTP/IMAP) |
| [treilik/bubblelister](https://github.com/treilik/bubblelister) | **CandyLister** | `candy-lister/` | `candycore/candy-lister` | `CandyCore\Lister` | 🔴 | List view component with boxing support |
| [treilik/bubbleboxer](https://github.com/treilik/bubbleboxer) | **SugarBoxer** | `sugar-boxer/` | `candycore/sugar-boxer` | `CandyCore\Boxer` | 🔴 | Boxing/padding/whitespace component |
| [rmhubbert/bubbletea-overlay](https://github.com/rmhubbert/bubbletea-overlay) | **SugarVeil** | `sugar-veil/` | `candycore/sugar-veil` | `CandyCore\Veil` | 🔴 | Modal / overlay window component |
| [KevM/bubbleo](https://github.com/KevM/bubbleo) | **SugarCrumbs** | `sugar-crumbs/` | `candycore/sugar-crumbs` | `CandyCore\Crumbs` | 🔴 | NavStack / Breadcrumbs / Menu navigation components |
| [Genekkion/theHermit](https://github.com/Genekkion/theHermit) | **CandyHermit** | `candy-hermit/` | `candycore/candy-hermit` | `CandyCore\Hermit` | 🔴 | Model for the Bubble Tea lifecycle |
| [Evertras/bubble-table](https://github.com/Evertras/bubble-table) | **SugarTable** | `sugar-table/` | `candycore/sugar-table` | `CandyCore\Table` | 🔴 | Customizable interactive table component |
| [erikgeiser/promptkit](https://github.com/erikgeiser/promptkit) | **SugarReadline** | `sugar-readline/` | `candycore/sugar-readline` | `CandyCore\Readline` | 🔴 | Line-editing prompt library |
| [EthanEFung/bubble-datepicker](https://github.com/EthanEFung/bubble-datepicker) | **SugarCalendar** | `sugar-calendar/` | `candycore/sugar-calendar` | `CandyCore\Calendar` | 🔴 | Date picker component |
| [DaltonSW/bubbleup](https://github.com/daltonsw/bubbleup) | **SugarToast** | `sugar-toast/` | `candycore/sugar-toast` | `CandyCore\Toast` | 🔴 | Floating alert notification component |
| [76creates/stickers](https://github.com/76creates/stickers) | **SugarStickers** | `sugar-stickers/` | `candycore/sugar-stickers` | `CandyCore\Stickers` | 🔴 | Lipgloss utility components / building blocks |

## Reference apps

| Upstream | SugarCraft port | Subdir | Composer pkg | Namespace | Status | Role |
|---|---|---|---|---|:---:|---|
| _starter scaffold_ | **CandyMold** | `candy-mold/` | `candycore/candy-mold` | `App\` | 🟢 | `composer create-project` skeleton — counter Model + bin + tests |
| [charmbracelet/crush](https://github.com/charmbracelet/crush) | **SugarCrush** | `sugar-crush/` | `candycore/sugar-crush` | `CandyCore\Crush` | 🟢 | Chat-shell TUI for AI coding assistants |
| [Broderick-Westrope/tetrigo](https://github.com/Broderick-Westrope/tetrigo) | **CandyTetris** | `candy-tetris/` | `candycore/candy-tetris` | `CandyCore\Tetris` | 🟢 | Tetris clone — SRS / 7-bag / NES scoring |
| [yorukot/superfile](https://github.com/yorukot/superfile) | **SuperCandy** | `super-candy/` | `candycore/super-candy` | `CandyCore\SuperCandy` | 🟢 | Dual-pane file manager |
| [jesseduffield/lazygit](https://github.com/jesseduffield/lazygit) | **SugarStash** | `sugar-stash/` | `candycore/sugar-stash` | `CandyCore\Stash` | 🟢 | Three-pane git TUI — shells out to `git` |
| [jorgerojas26/lazysql](https://github.com/jorgerojas26/lazysql) | **CandyQuery** | `candy-query/` | `candycore/candy-query` | `CandyCore\Query` | 🟢 | SQLite browser TUI |
| [Rtarun3606k/TakaTime](https://github.com/Rtarun3606k/TakaTime) | **SugarTick** | `sugar-tick/` | `candycore/sugar-tick` | `CandyCore\Tick` | 🟢 | Privacy-first coding-time tracker — JSONL on disk |
| [maxpaulus43/go-sweep](https://github.com/maxpaulus43/go-sweep) | **CandyMines** | `candy-mines/` | `candycore/candy-mines` | `CandyCore\Mines` | 🟢 | Minesweeper — first-click safety / flood-fill |
| [namzug16/gifterm](https://github.com/namzug16/gifterm) | **CandyFlip** | `candy-flip/` | `candycore/candy-flip` | `CandyCore\Flip` | 🟢 | ASCII GIF viewer (ext-gd) |
| [kbrgl/flapioca](https://github.com/kbrgl/flapioca) | **HoneyFlap** | `honey-flap/` | `candycore/honey-flap` | `CandyCore\Flap` | 🟢 | Flappy Bird clone — bird is a HoneyBounce projectile |

---

## Naming conventions (cheat sheet)

The SugarCraft brand has three prefixes — pick one when you add a new
port. Suffixes are short, technical, and describe the role.

| Prefix | Meaning | Example uses |
|---|---|---|
| **Candy-** | foundation / system / framework | runtime (CandyCore), shell (CandyShell), markdown (CandyShine) |
| **Sugar-** | components / data / forms / apps | components (SugarBits), forms (SugarPrompt), charts (SugarCharts) |
| **Honey-** | math / physics / motion | spring physics (HoneyBounce), Flappy clone (HoneyFlap) |

`Super-` is reserved for the file manager port (SuperCandy) and stays
opt-in. Don't mint new prefixes without a discussion in
[`PROJECT_NAMES.md`](./PROJECT_NAMES.md).

---

## How to add a new row

1. Pick the upstream repo and the SugarCraft prefix + suffix following
   the cheat sheet above.
2. Add a row to the matching table here (libraries vs reference apps).
3. Add the same name + prefix discussion to
   [`PROJECT_NAMES.md`](./PROJECT_NAMES.md) — this is the canonical
   place for naming-decision history.
4. Follow the contributor playbook in [`AGENTS.md`](./AGENTS.md) for
   the rest of the integration (composer.json, examples, tests, docs,
   website tile, VHS demo).
