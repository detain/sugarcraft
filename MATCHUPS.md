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
| [charmbracelet/bubbletea](https://github.com/charmbracelet/bubbletea) | **SugarCraft** | `candy-core/` | `sugarcraft/candy-core` | `SugarCraft\Core` | 🟢 | Elm-architecture TUI runtime |
| [charmbracelet/lipgloss](https://github.com/charmbracelet/lipgloss) | **CandySprinkles** | `candy-sprinkles/` | `sugarcraft/candy-sprinkles` | `SugarCraft\Sprinkles` | 🟢 | Declarative styling + layout |
| [charmbracelet/harmonica](https://github.com/charmbracelet/harmonica) | **HoneyBounce** | `honey-bounce/` | `sugarcraft/honey-bounce` | `SugarCraft\Bounce` | 🟢 | Spring physics + Newtonian projectile sim |
| [lrstanley/bubblezone](https://github.com/lrstanley/bubblezone) | **CandyZone** | `candy-zone/` | `sugarcraft/candy-zone` | `SugarCraft\Zone` | 🟢 | Mouse-zone tracker |
| [charmbracelet/bubbles](https://github.com/charmbracelet/bubbles) | **SugarBits** | `sugar-bits/` | `sugarcraft/sugar-bits` | `SugarCraft\Bits` | 🟡 | 14 prebuilt components (TextInput, ItemList, Table, …) |
| [NimbleMarkets/ntcharts](https://github.com/NimbleMarkets/ntcharts) | **SugarCharts** | `sugar-charts/` | `sugarcraft/sugar-charts` | `SugarCraft\Charts` | 🟡 | Sparkline / Bar / Line / Heatmap / Scatter / TimeSeries / OHLC / picture |
| [charmbracelet/huh](https://github.com/charmbracelet/huh) | **SugarPrompt** | `sugar-prompt/` | `sugarcraft/sugar-prompt` | `SugarCraft\Prompt` | 🟢 | Form library — Note / Input / Confirm / Select / MultiSelect / Text / FilePicker |
| [charmbracelet/glamour](https://github.com/charmbracelet/glamour) | **CandyShine** | `candy-shine/` | `sugarcraft/candy-shine` | `SugarCraft\Shine` | 🟡 | Markdown → ANSI renderer (themes, syntax, OSC 8 hyperlinks) |
| [charmbracelet/fang](https://github.com/charmbracelet/fang) | **CandyKit** | `candy-kit/` | `sugarcraft/candy-kit` | `SugarCraft\Kit` | 🟢 | CLI presentation helpers (StatusLine / Banner / Section / Stage / HelpText) |
| [charmbracelet/wish](https://github.com/charmbracelet/wish) | **CandyWish** | `candy-wish/` | `sugarcraft/candy-wish` | `SugarCraft\Wish` | 🟢 | SSH-server middleware framework (leans on host `sshd`) |
| [charmbracelet/promwish](https://github.com/charmbracelet/promwish) | **CandyMetrics** | `candy-metrics/` | `sugarcraft/candy-metrics` | `SugarCraft\Metrics` | 🟢 | Telemetry primitives + CandyWish session middleware |
| [charmbracelet/log](https://github.com/charmbracelet/log) | **CandyLog** | `candy-log/` | `sugarcraft/candy-log` | `SugarCraft\Log` | 🟢 | Minimal, colorful logging library |
| [charmbracelet/colorprofile](https://github.com/charmbracelet/colorprofile) | **CandyPalette** | `candy-palette/` | `sugarcraft/candy-palette` | `SugarCraft\Palette` | 🟢 | Terminal color detection + ICC profile handling |
| [charmbracelet/x/mosaic](https://github.com/charmbracelet/x/tree/main/mosaic) | **CandyMosaic** | `candy-mosaic/` | `sugarcraft/candy-mosaic` | `SugarCraft\Mosaic` | 🟢 | Image-to-cell renderer (Sixel, Kitty, iTerm2, Unicode half-block); Picker facade; ext-gd |
| [charmbracelet/x/vt](https://github.com/charmbracelet/x/tree/main/vt) | **CandyVt** | `candy-vt/` | `sugarcraft/candy-vt` | `SugarCraft\Vt` | 🟡 | In-memory virtual terminal emulator; ANSI byte stream → cell grid + cursor + mode state |
| [charmbracelet/x/vcr](https://github.com/charmbracelet/x/tree/main/vcr) | **CandyVcr** | `candy-vcr/` | `sugarcraft/candy-vcr` | `SugarCraft\Vcr` | 🟡 | Record + replay terminal sessions (cassette format, frame assertion) |
| [treilik/bubblelister](https://github.com/treilik/bubblelister) | **CandyLister** | `candy-lister/` | `sugarcraft/candy-lister` | `SugarCraft\Lister` | 🟢 |
| [treilik/bubbleboxer](https://github.com/treilik/bubbleboxer) | **SugarBoxer** | `sugar-boxer/` | `sugarcraft/sugar-boxer` | `SugarCraft\Boxer` | 🟢 |
| [rmhubbert/bubbletea-overlay](https://github.com/rmhubbert/bubbletea-overlay) | **SugarVeil** | `sugar-veil/` | `sugarcraft/sugar-veil` | `SugarCraft\Veil` | 🟢 |
| [KevM/bubbleo](https://github.com/KevM/bubbleo) | **SugarCrumbs** | `sugar-crumbs/` | `sugarcraft/sugar-crumbs` | `SugarCraft\Crumbs` | 🟢 |
| [Genekkion/theHermit](https://github.com/Genekkion/theHermit) | **CandyHermit** | `candy-hermit/` | `sugarcraft/candy-hermit` | `SugarCraft\Hermit` | 🟢 |
| [Evertras/bubble-table](https://github.com/Evertras/bubble-table) | **SugarTable** | `sugar-table/` | `sugarcraft/sugar-table` | `SugarCraft\Table` | 🟢 |
| [erikgeiser/promptkit](https://github.com/erikgeiser/promptkit) | **SugarReadline** | `sugar-readline/` | `sugarcraft/sugar-readline` | `SugarCraft\Readline` | 🟢 |
| [EthanEFung/bubble-datepicker](https://github.com/EthanEFung/bubble-datepicker) | **SugarCalendar** | `sugar-calendar/` | `sugarcraft/sugar-calendar` | `SugarCraft\Calendar` | 🟢 |
| [DaltonSW/bubbleup](https://github.com/daltonsw/bubbleup) | **SugarToast** | `sugar-toast/` | `sugarcraft/sugar-toast` | `SugarCraft\Toast` | 🟢 |
| [76creates/stickers](https://github.com/76creates/stickers) | **SugarStickers** | `sugar-stickers/` | `sugarcraft/sugar-stickers` | `SugarCraft\Stickers` | 🟢 |

## Apps

| Upstream | SugarCraft port | Subdir | Composer pkg | Namespace | Status | Role |
|---|---|---|---|---|:---:|---|
| _starter scaffold_ | **CandyMold** | `candy-mold/` | `sugarcraft/candy-mold` | `App\` | 🟢 | `composer create-project` skeleton — counter Model + bin + tests |
| [charmbracelet/gum](https://github.com/charmbracelet/gum) | **CandyShell** | `candy-shell/` | `sugarcraft/candy-shell` | `SugarCraft\Shell` | 🟡 | Composer-installable CLI of 13 subcommands |
| [charmbracelet/freeze](https://github.com/charmbracelet/freeze) | **CandyFreeze** | `candy-freeze/` | `sugarcraft/candy-freeze` | `SugarCraft\Freeze` | 🟢 | Code → SVG screenshot (no GD / Imagick required) |
| [charmbracelet/glow](https://github.com/charmbracelet/glow) | **SugarGlow** | `sugar-glow/` | `sugarcraft/sugar-glow` | `SugarCraft\Glow` | 🟢 | Markdown CLI viewer / pager (consumes CandyShine) |
| [charmbracelet/sequin](https://github.com/charmbracelet/sequin) | **SugarSpark** | `sugar-spark/` | `sugarcraft/sugar-spark` | `SugarCraft\Spark` | 🟢 | ANSI escape-sequence inspector |
| [charmbracelet/wishlist](https://github.com/charmbracelet/wishlist) | **SugarWishlist** | `sugar-wishlist/` | `sugarcraft/sugar-wishlist` | `SugarCraft\Wishlist` | 🟢 | SSH endpoint launcher (YAML / JSON shortcuts directory) |
| [charmbracelet/skate](https://github.com/charmbracelet/skate) | **SugarSkate** | `sugar-skate/` | `sugarcraft/sugar-skate` | `SugarCraft\Skate` | 🟢 | Personal key/value store |
| [charmbracelet/pop](https://github.com/charmbracelet/pop) | **SugarPost** | `sugar-post/` | `sugarcraft/sugar-post` | `SugarCraft\Post` | 🟢 |
| [charmbracelet/soft-serve](https://github.com/charmbracelet/soft-serve) | **CandyServe** | `candy-serve/` | `sugarcraft/candy-serve` | `SugarCraft\Serve` | 🟢 |
| [charmbracelet/crush](https://github.com/charmbracelet/crush) | **SugarCrush** | `sugar-crush/` | `sugarcraft/sugar-crush` | `SugarCraft\Crush` | 🟢 | Chat-shell TUI for AI coding assistants |
| [Broderick-Westrope/tetrigo](https://github.com/Broderick-Westrope/tetrigo) | **CandyTetris** | `candy-tetris/` | `sugarcraft/candy-tetris` | `SugarCraft\Tetris` | 🟢 | Tetris clone — SRS / 7-bag / NES scoring |
| [yorukot/superfile](https://github.com/yorukot/superfile) | **SuperCandy** | `super-candy/` | `sugarcraft/super-candy` | `SugarCraft\SuperCandy` | 🟢 | Dual-pane file manager |
| [jesseduffield/lazygit](https://github.com/jesseduffield/lazygit) | **SugarStash** | `sugar-stash/` | `sugarcraft/sugar-stash` | `SugarCraft\Stash` | 🟢 | Three-pane git TUI — shells out to `git` |
| [jorgerojas26/lazysql](https://github.com/jorgerojas26/lazysql) | **CandyQuery** | `candy-query/` | `sugarcraft/candy-query` | `SugarCraft\Query` | 🟢 | SQLite browser TUI |
| [Rtarun3606k/TakaTime](https://github.com/Rtarun3606k/TakaTime) | **SugarTick** | `sugar-tick/` | `sugarcraft/sugar-tick` | `SugarCraft\Tick` | 🟢 | Privacy-first coding-time tracker — JSONL on disk |
| [maxpaulus43/go-sweep](https://github.com/maxpaulus43/go-sweep) | **CandyMines** | `candy-mines/` | `sugarcraft/candy-mines` | `SugarCraft\Mines` | 🟢 | Minesweeper — first-click safety / flood-fill |
| [namzug16/gifterm](https://github.com/namzug16/gifterm) | **CandyFlip** | `candy-flip/` | `sugarcraft/candy-flip` | `SugarCraft\Flip` | 🟢 | ASCII GIF viewer (ext-gd) |
| [kbrgl/flapioca](https://github.com/kbrgl/flapioca) | **HoneyFlap** | `honey-flap/` | `sugarcraft/honey-flap` | `SugarCraft\Flap` | 🟢 | Flappy Bird clone — bird is a HoneyBounce projectile |

---

## Naming conventions (cheat sheet)

The SugarCraft brand has three prefixes — pick one when you add a new
port. Suffixes are short, technical, and describe the role.

| Prefix | Meaning | Example uses |
|---|---|---|
| **Candy-** | foundation / system / framework | runtime (SugarCraft), shell (CandyShell), markdown (CandyShine) |
| **Sugar-** | components / data / forms / apps | components (SugarBits), forms (SugarPrompt), charts (SugarCharts) |
| **Honey-** | math / physics / motion | spring physics (HoneyBounce), Flappy clone (HoneyFlap) |

`Super-` is reserved for the file manager port (SuperCandy) and stays
opt-in. Don't mint new prefixes without a discussion in
[`PROJECT_NAMES.md`](./PROJECT_NAMES.md).

---

## How to add a new row

1. Pick the upstream repo and the SugarCraft prefix + suffix following
   the cheat sheet above.
2. Add a row to the matching table here (libraries vs apps).
3. Add the same name + prefix discussion to
   [`PROJECT_NAMES.md`](./PROJECT_NAMES.md) — this is the canonical
   place for naming-decision history.
4. Follow the contributor playbook in [`AGENTS.md`](./AGENTS.md) for
   the rest of the integration (composer.json, examples, tests, docs,
   website tile, VHS demo).
