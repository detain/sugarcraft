# Project naming — rules + decision history

> Canonical **upstream → SugarCraft port** mapping lives in
> [MATCHUPS.md](./MATCHUPS.md). This file is the **rulebook** for picking
> a name plus the open-ended sketchpad — early ideas, rejected names,
> and rationale. When you're adding a new library, pick a name here,
> add it to MATCHUPS.md, then follow the contributor playbook in
> [AGENTS.md](./AGENTS.md).

---

## The naming rule

Every lib + app uses **two words**, joined CamelCase:

```
[ Sweet word ]  +  [ Functional word ]
─────────────       ───────────────────
 evokes the         tells you what
 SugarCraft         the package
 brand              actually does
```

Both words have to do real work — the sweet word grounds it in the brand;
the functional word means a developer can read the name in a `composer
require` line and have a fair guess what it ports / provides.

### Word 1 — sweet vocabulary

The original three prefixes (`Candy-` / `Sugar-` / `Honey-`) are still in
play and remain the safest defaults, but the palette is **open** — pick
any sweet, dessert, or candy-shop word that pairs naturally with the
function. Some good candidates:

> Honey · Sugar · Candy · Sprinkles · Frosting · Glaze · Icing · Caramel ·
> Toffee · Fudge · Truffle · Praline · Mochi · Marshmallow · Macaron ·
> Cookie · Biscuit · Cupcake · Cake · Brownie · Donut · Eclair · Pancake ·
> Waffle · Scone · Tart · Pie · Pudding · Custard · Sorbet · Sherbet ·
> Gelato · Sundae · Parfait · Popsicle · Lollipop · Gumdrop · Bonbon ·
> Bento · Pretzel · Strawberry · Berry · Cherry · Peach · Apricot · Plum

**Avoid** sweet words that already have strong unrelated meanings in
software (`Cookie` reads as HTTP cookies; `Crumb` is fine because we
already use it for breadcrumbs).

### Word 2 — functional vocabulary

The functional half should be a **noun or verb that maps to what the
lib does**. Avoid generic filler (`Kit`, `Set`, `Tool`, `Stuff`) unless
the lib genuinely is a grab-bag of helpers. Examples that read well:

| Domain          | Good functional words |
|-----------------|------------------------|
| Components / widgets | Bits, Parts, Chips, Widgets |
| Layout         | Layout, Grid, Boxer, Stickers |
| Styling        | Style, Paint, Color, Sprinkles |
| Charts         | Charts, Plots, Graph, Pie |
| Forms / prompts | Prompt, Form, Ask, Input |
| Markdown / text | Markdown, Glow, Render |
| Mouse / input  | Zone, Click, Tap |
| Animation / physics | Bounce, Spring, Wobble |
| Telemetry      | Metrics, Tally, Track |
| Logging        | Log, Trail, Trace |
| Color profile  | Palette, Color, Profile |
| Storage / KV   | Store, Stash, Keep |
| Email          | Post, Mail |
| Server / SSH   | Serve, Tunnel, Pipe, Wish |
| Files / browse | File, Browse, Pantry |
| Time tracking  | Tick, Clock, Timer |
| Game           | (the game name itself, e.g. Tetris, Mines, Flap) |

### The "good combination" test

Read the candidate aloud. Ask:

1. Does the sweet word **pair** naturally with the functional word? (`PancakeFlip`
   ✓ — pancakes flip; `MarshmallowQuery` ✗ — no connection.)
2. Could a developer skimming a `composer require sugarcraft/<name>` line
   make a fair guess at what they're getting? If the answer is "no idea
   without docs", reach for a clearer functional word.
3. Is it under ~16 characters? Long names get truncated in lockfiles
   and read poorly in `--help` output.

If two words don't earn their place, the name is doing branding only.
That's fine for the umbrella (`SugarCraft`) but a poor choice for a
single-purpose lib.

---

## ✅ Strong (keep / build around)

| Name | Why it works |
|---|---|
| **SugarCraft** | umbrella brand — vague is correct here, it's the meta |
| **HoneyBounce** | honey is sticky / springy; bounce = spring physics. ✓✓ |
| **SugarPrompt** | sugar + prompt = forms. clear. |
| **SugarCharts** | sugar + charts = charts. literal. |
| **SugarTable** | sugar + table = data table. literal. |
| **SugarCalendar** | sugar + calendar = date picker. literal. |
| **SugarToast** | toast = notification metaphor IS the function. delightful. |
| **SugarCrumbs** | crumbs = breadcrumbs nav IS the function. delightful. |
| **CandyAnsi** | candy + ansi = ECMA-48 state machine. mirrors charmbracelet/x/ansi parser exactly; "ansi" is the established upstream term. foundation layer extracted from candy-vt. |
| **CandyShell** | candy + shell = CLI. `gum`-style one-shots. |
| **CandyServe** | candy + serve = `soft-serve` Git server. perfect pun. |
| **CandyFreeze** | candy + freeze = code → SVG screenshots. literal. |
| **SugarStash** | stash = the git verb. clever lazygit play. |
| **HoneyFlap** | honey (bee) + flap = Flappy clone. ✓✓ |
| **CandyMold** | candy + mold = the project skeleton you pour into. ✓✓ |
| **CandyMosaic** | candy + mosaic = image-to-cell renderer. mosaic is the technical term; candy is the brand. clear and literal. |
| **CandyVt**     | candy + vt = virtual terminal emulator. mirrors charmbracelet/x/vt exactly; vt is the established upstream name. |
| **CandyVcr**    | candy + vcr = record + replay terminal sessions. mirrors charmbracelet/x/vcr; "vcr" carries the cassette / playback metaphor for free. |
| **CandyPty**    | candy + pty = pseudo-terminal primitive. mirrors charmbracelet/x/xpty (we drop the `x` prefix — it's a Charm package-namespace artefact, not part of the role name). foundation lib for spawning child processes wired to a controlled PTY. |
| **CandyForms** | candy + forms = form primitives foundation. extraction target for TextInput, TextArea, ItemList, Viewport, FilePicker, Field interface, Confirm, Form from sugar-bits and sugar-prompt. |
| **CandyBuffer** | candy + buffer = cell-grid value objects. mirrors charmbracelet/vte's Buffer/Cell as the shared foundation for terminal rendering across the ecosystem. |
| **CandyLayout** | candy + layout = constraint-based layout solver. new investment — no direct upstream; inspired by ratatui's Cassowary constraint system and Badros & Borning 2001. Provides LayoutSolver interface with CassowarySolver (simplex) + GreedySolver (5-phase fallback from candy-sprinkles). |
| **CandyTesting** | candy + testing = test harness for TEA programs. pioneering — no upstream parallel. ProgramSimulator drives Programs with scripted input; Assertions provides assertGoldenAnsi, assertCellGrid, assertAnsiEquals; TapeRecorder emits VHS .tape files. |
| **CandyInput** | candy + input = terminal escape sequence decoder. pioneering — no upstream parallel. EscapeDecoder consumes raw TTY bytes and emits typed Events (KeyEvent, MouseEvent, FocusEvent, PasteEvent, ResizeEvent). Unblocks sugar-readline migration to real-TTY input (step-21). |
| **CandyMouse** | candy + mouse = self-contained Mark/Scan/Get mouse hit-testing (bubblezone pattern) + ZoneClickTracker. Mirrors bubblezone semantics but no external Manager wiring — scanner is owned by each consumer. |
| **CandyMines** | candy + mines = Minesweeper. clear. |
| **CandyQuery** | candy + query = SQLite browser. clear. |

## ⚠️ Functional half is weak / vague

| Current | Issue | Sketches |
|---|---|---|
| (the runtime, `candy-core`) | "SugarCraft" is also the umbrella; the sub-package needs a less-collision-prone name | `CakeStage`, `BatterCore`, `WhiskLoop`, `MochiCore` |
| **SugarBits** | "Bits" is generic; the lib is 15 named widgets | `BiscuitWidgets`, `SprinkleParts`, `ChipParts` |
| **CandyKit** | "Kit" is filler; the lib is CLI presentation primitives | `CupcakeKit`, `BentoCli`, `BiscuitCli` |
| **CandyShine** | "Shine" doesn't say markdown | `GlazeMarkdown`, `IcingMarkdown` |
| **CandyHermit** | "Hermit" comes from upstream; doesn't say fuzzy-find | `TruffleFinder`, `MacaronFilter` |
| **SugarSkate** | "Skate" comes from upstream; doesn't say KV store | `CaramelStore`, `HoneyStore` |
| **CandyWish** | "Wish" comes from upstream; doesn't say SSH | `HoneyTunnel`, `MochiTunnel` |
| **SugarWishlist** | piggybacks on Wish; doesn't say SSH launcher | `BentoLauncher`, `PicnicPicker` |
| **SuperCandy** | "Super" is hype; doesn't say file manager | `PantryFile`, `BentoFile`, `CookieFile` |

> The names in the right column are sketches, not commitments — see the
> proposals section in PRs to pick what gets adopted.

---

## ❌ Rejected (early ideas worth remembering)

* **CandyDrops** → too close to "drops" as a synonym for releases; ambiguous
* **SweetShop** → sounds like a marketplace, not a library
* **CookiePress** → reads as WordPress / browser cookies, not TUI
* **CutieMarks** → niche reference, doesn't age
* **HoneyComb** → considered for layout/grid but `bubbleboxer` already mapped to **SugarBoxer**

---

## 💡 Sweet × functional combinations to mine for new ports

These haven't been used yet — they're queued for whatever the next
matching upstream port turns out to be.

| Combo | Likely fit |
|---|---|
| **PancakeFlip** | image / video / GIF flipping (CandyFlip alternative) |
| **PieChart** | another charts library |
| **CookieClick** | mouse-zone tracker (CandyZone alternative) |
| **BentoBox** | layout (SugarBoxer alternative) |
| **BentoLauncher** | launcher / picker (SugarWishlist alternative) |
| **MarshmallowFilter** | fuzzy-finder (CandyHermit alternative) |
| **HoneyTunnel** | SSH / tunnel (CandyWish alternative) |
| **CaramelStore** | KV / cache (SugarSkate alternative) |
| **TruffleFinder** | fuzzy / search overlay |
| **GlazeMarkdown** | markdown renderer (CandyShine alternative) |
| **IcingTheme** | theme system / palette extension |
| **FrostingPaint** | colors / styling overlay |
| **SherbetSnap** | screenshot / capture (CandyFreeze alternative) |
| **BiscuitWidgets** | components grab-bag (SugarBits alternative) |
| **SundaeServe** | SSH-served app aggregator |
| **MochiTunnel** | SSH multiplexer |
| **GumdropTimer** | stopwatch / countdown |
| **PuddingMetrics** | telemetry (CandyMetrics alternative) |

---

## Naming conventions (cheat sheet — kept for back-compat)

The original three prefixes still work and most of the existing
ecosystem uses them. New ports can use any of the wider sweet
vocabulary above — these three remain reserved as the safe defaults.

| Prefix | Meaning | Example uses |
|---|---|---|
| **Candy-** | foundation / system / framework | runtime (SugarCraft), shell (CandyShell), markdown (CandyShine) |
| **Sugar-** | components / data / forms / apps | components (SugarBits), forms (SugarPrompt), charts (SugarCharts) |
| **Honey-** | math / physics / motion | spring physics (HoneyBounce), Flappy clone (HoneyFlap) |

`Super-` was a one-shot for the file manager (SuperCandy) — the file
manager is now flagged for rename. Don't mint new prefixes without a
discussion in this file.
