# Project naming — rules + decision history

> The canonical package roster — every library and app with its
> role — lives in [MATCHUPS.md](./docs/MATCHUPS.md). This file is the **rulebook** for picking
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
require` line and have a fair guess what the package provides.

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
| Layout         | Layout, Grid, Panels, Flexbox |
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

### Exception: app satellites (`<app>-<surface>`)

A package that exists only to give an existing app a second **surface**
takes the app's slug plus the surface word, even though that makes three
words. The app's name is already the brand and the function; the suffix
says which face of it you are installing, and the package sorts next to
the app it belongs to.

| Satellite | App | Surface | Decision |
|---|---|---|---|
| **SugarCrushWeb** (`sugar-crush-web/`, `sugarcraft/sugar-crush-web`, `SugarCraft\CrushWeb\`) | SugarCrush | browser UI for `sugarcrush serve` (Vite + Vue 3 bundle + one-class PHP shim) | User decision, 2026-10-01: keep the three-word name. A two-word candidate (e.g. a `Sugar-` + `Web` coinage) would hide that it is sugar-crush's UI and unusable without it. |

The exception is narrow: a satellite has no use without its app, ships no
standalone functionality, and keeps the app's prefix (no `Candy-` for an
app's web face, per the prefix law below). A package that is useful on its
own still needs a two-word name of its own.

### Exception: `candy-top` (prefix ruling on a system-monitor app)

| Name | Prefix heuristic says | Owner ruling | Decision |
|---|---|---|---|
| **CandyTop** (`candy-top/`, `sugarcraft/candy-top`, `SugarCraft\Top\`) | `Sugar-` — it is a full-screen **app** (system monitor in the btop family), and the prefix law puts apps under `Sugar-`; the add-a-lib review flagged that "a system monitor app arguably reads sugar-top". | `Candy-` — the owner explicitly chose `candy-top`. | Owner decision, recorded 2026-10-08: keep `candy-top`. |

Both sides, for the record: **for `Sugar-`**, candy-top ships no reusable
primitives — every rendering gap it needed was closed in `sugar-dashboard`,
`sugar-charts`, `sugar-widgets` and `candy-sprinkles`, and the app only
assembles them, which is exactly the `Sugar-` "components / apps" role.
**For `Candy-`**, the AGENTS.md naming line gives `Candy-` to
"foundation / **system**", and a system monitor is about as literally
"system" as a package gets (it reads `/proc` and `/sys`, not user data);
`Candy-` apps already exist (CandyFiles, CandySql, CandyGifView). The ruling
stands; it is not a precedent that apps default to `Candy-`.

---

## ✅ Strong (keep / build around)

| Name | Why it works |
|---|---|
| **SugarCraft** | umbrella brand — vague is correct here, it's the meta |
| **SugarSpring** | honey is sticky / springy; bounce = spring physics. ✓✓ |
| **SugarCharts** | sugar + charts = charts. literal. |
| **SugarTable** | sugar + table = data table. literal. |
| **SugarDatepicker** | sugar + datepicker = date picker. literal. |
| **SugarQuickpick** | sugar + quickpick = fuzzy quick-pick / filter overlay component. "quickpick" says the function plainly, matching the established terminal picker concept (was CandyHermit — the inherited upstream name retired with the 2026-10 rename). <!-- @rename-keep -->
| **SugarToast** | toast = notification metaphor IS the function. delightful. |
| **SugarNavstack** | nav stack = push/pop navigation stack + breadcrumb trail IS the function. delightful. (renamed from SugarCrumbs 2026-10). <!-- @rename-keep --> |
| **CandyAnsi** | candy + ansi = ECMA-48 state machine. "ansi" is the established term for the escape-code standard. Foundation layer extracted from candy-vt. |
| **CandyScriptui** | candy + script UI = scriptable one-shot terminal widgets callable from shell scripts (renamed from CandyShell 2026-10). <!-- @rename-keep --> |
| **CandyGitServer** | candy + Git server = self-hostable Git server app (was CandyServe). | <!-- @rename-keep -->
| **CandyKv** | candy + kv = personal key/value store app. `kv` says the domain plainly (was SugarSkate — the inherited upstream name retired with the 2026-10 rename). <!-- @rename-keep -->
| **CandyCodeshot** | candy + codeshot = code → SVG screenshots, literally. Took this name in the 2026-10 rename; the upstream-inherited `freeze` pun retired with it. | <!-- @rename-keep -->
| **CandyGit** | candy + git = the git TUI. took this name in the 2026-10 rename; the original stash = the git verb wordplay retired with the `sugar-` family placement. |
| **CandyFlappy** | Flappy-Bird clone — took this name in the 2026-10 rename; the original honey (bee) + flap wordplay retired with the `honey-` family placement. |
| **CandyStarter** | candy + starter = the create-project skeleton you start from. ✓✓ |
| **SugarImage** | candy + mosaic = image-to-cell renderer. mosaic is the technical term; candy is the brand. clear and literal. |
| **CandyVt**     | candy + vt = virtual terminal emulator. "vt" is the established term. |
| **CandyVcr**    | candy + vcr = record + replay terminal sessions. "vcr" carries the cassette / playback metaphor for free. |
| **SugarAnimation**   | candy + flip = flip-book frames — the GIF member of the visual family beside SugarImage (still images) and SugarVideo (video). "flip" carries the frame-by-frame animation metaphor for free. |
| **CandyGifView** | candy + gif + view = the terminal GIF/.ansi-art viewer app — the command formerly `bin/sugar-animation`, split into its own package (rename campaign step 1.3). "view" states the app role beside the SugarAnimation toolkit. |
| **CandyPty**    | candy + pty = pseudo-terminal primitive. "pty" is the Unix term of art; no package-namespace prefixes. Foundation lib for spawning child processes wired to a controlled PTY. |
| **SugarForms** | candy + forms = form primitives foundation. extraction target for TextInput, TextArea, ItemList, Viewport, FilePicker, Field interface, Confirm, Form from sugar-widgets. |
| **SugarFocus** | sugar + focus = the focus-management component. dependency-free ring: an ordered set of focusable regions with one focused member + wrap-around Tab/Shift-Tab traversal for full-window TUI layouts. Original design; it generalizes the one-focused-region approach sugar-dashboard first used here. || **SugarGallery** | sugar + gallery = a poster gallery / grid for media TUIs. 2-D virtualized, sparse PosterGrid for large libraries + owner-driven range paging, a horizontal Rail carousel, and a renderer-agnostic PosterCard tile. Original — the web media-shelf pattern, rebuilt for the terminal. |
| **SugarFuzzy** | candy + fuzzy = fuzzy string matching with scored matched indices. extracted from sugar-forms; adds indices output unblocking filter-highlighting UI. two algorithms: Smith-Waterman (local alignment) + Sahilm (filter-as-you-type). || **CandyBuffer** | candy + buffer = cell-grid value objects. The shared Buffer/Cell foundation for terminal rendering across the ecosystem. |
| **CandyAsync** | candy + async = shared async vocabulary for ReactPHP. Original — CancellationToken (owner/source pattern), Subscription interface, Subscriptions::compose(), AsyncOps static helpers (withTimeout, retry, debounce, throttle). Unifies ReactPHP usage scattered across candy-core, sugar-forms, candy-wish. |
| **CandyLayout** | candy + layout = constraint-based layout solver. Implements the Cassowary constraint system (Badros & Borning, 2001) behind a LayoutSolver interface: CassowarySolver (simplex) + GreedySolver (5-phase fallback from candy-sprinkles). |
| **CandyTesting** | candy + testing = test harness for SugarCraft apps. ProgramSimulator drives Programs with scripted input; Assertions provides assertGoldenAnsi, assertCellGrid, assertAnsiEquals; TapeRecorder emits VHS .tape files. |
| **SugarInput** | candy + input = terminal escape sequence decoder. EscapeDecoder consumes raw TTY bytes and emits typed Events (KeyEvent, MouseEvent, FocusEvent, PasteEvent, ResizeEvent). Unblocks sugar-readline's move to real-TTY input. |
| **SugarMouse** | plain descriptive — took this name in the 2026-10 rename; the “candy + mouse” wordplay retired with it. Self-contained Mark/Scan/Get mouse hit-testing + ZoneClickTracker. No external Manager wiring — the scanner is owned by each consumer. |
| **CandyMinesweeper** | plain descriptive — took this name in the 2026-10 rename; the “candy + mines” wordplay retired with it. |
| **SugarTreelist** | candy + lister = scrollable tree/list view component. literal. |
| **CandyTop** | candy + top = the `top`/`btop` family of system monitors. Design antecedent [aristocratos/btop](https://github.com/aristocratos/btop); `Candy-` by owner ruling (see the `candy-top` exception above — the heuristic alone would say `Sugar-`). |
| **CandySql** | candy + sql = terminal SQL client. literal pairing (was candy-query — the query-editor framing retired with the 2026-10 rename); multi-driver: SQLite, MySQL, PostgreSQL — schema browser, query editor, EXPLAIN plan viewer, server status page, alerting, and query history. | <!-- @rename-keep -->
| **SugarVideo** | sugar + reel = film reel = terminal video player. reel is the literal video metaphor; sits beside SugarAnimation (GIF) and SugarImage (images) as the video member of the family. |
| **SugarDiff** | sugar + diff = unified-diff engine. component/data per prefix law; extracted from sugar-crush (BuildsUnifiedDiff + DiffGutter numbering model); first-party PHP. |
| **Sugar MCP Client** | sugar + mcp = Model Context Protocol client core. component/data per prefix law; extracted from sugar-crush (src/MCP stdio stack + McpMessage codec); first-party PHP — MCP is an open protocol spec. |
| **SugarCrushWeb** | app satellite of SugarCrush — the browser UI for `sugarcrush serve`. Three words by the `<app>-<surface>` exception above; first-party PHP + Vue. |
| **SugarCrush** | sugar + crush = TUI AI coding assistant. Multi-provider (OpenAI, SGLang, Claude Code, etc.), multi-agent, skill-aware. Original. |

## ⚠️ Functional half is weak / vague

| Current | Issue | Sketches |
|---|---|---|
| (the runtime, `candy-core`) | "SugarCraft" is also the umbrella; the sub-package needs a less-collision-prone name | `CakeStage`, `BatterCore`, `WhiskLoop`, `MochiCore` |
| ~~**SugarBits**~~ → **SugarWidgets** | resolved by the 2026-10 rename — "Bits" was generic; the lib is a set of named widgets <!-- @rename-keep --> | `BiscuitWidgets`, `SprinkleParts`, `ChipParts` |
| **SugarCli** | "Kit" is filler; the lib is CLI presentation primitives | `CupcakeKit`, `BentoCli`, `BiscuitCli` |
| **SugarMarkdown** | "Shine" doesn't say markdown | `GlazeMarkdown`, `IcingMarkdown` |
| **CandyWish** | "Wish" is an inherited name; doesn't say SSH | `HoneyTunnel`, `MochiTunnel` |
| **CandySshLauncher** | piggybacks on Wish; doesn't say SSH launcher | `BentoLauncher`, `PicnicPicker` |
| **CandyFiles** | "Files" describes the app | `FilePane`, `DualPane`, `FileMan` |

> The names in the right column are sketches, not commitments — see the
> proposals section in PRs to pick what gets adopted.

---

## ❌ Rejected (early ideas worth remembering)

* **CandyDrops** → too close to "drops" as a synonym for releases; ambiguous
* **SweetShop** → sounds like a marketplace, not a library
* **CookiePress** → reads as WordPress / browser cookies, not TUI
* **CutieMarks** → niche reference, doesn't age
* **HoneyComb** → considered for layout/grid, but that slot is already taken by **SugarPanels**

---

## 💡 Sweet × functional combinations to mine for new packages

These haven't been used yet — they're queued for whatever the next
matching package turns out to be.

| Combo | Likely fit |
|---|---|
| **PancakeFlip** | image / video / GIF flipping (SugarAnimation alternative) |
| **PieChart** | another charts library |
| **CookieClick** | mouse-zone tracker (SugarMouse alternative) |
| **BentoBox** | layout (SugarPanels alternative) |
| **BentoLauncher** | launcher / picker (CandySshLauncher alternative) |
| **MarshmallowFilter** | fuzzy-finder (SugarQuickpick alternative) |
| **HoneyTunnel** | SSH / tunnel (CandyWish alternative) |
| **CaramelStore** | KV / cache (CandyKv alternative) |
| **TruffleFinder** | fuzzy / search overlay |
| **GlazeMarkdown** | markdown renderer (SugarMarkdown alternative) |
| **IcingTheme** | theme system / palette extension |
| **FrostingPaint** | colors / styling overlay |
| **SherbetSnap** | screenshot / capture (CandyCodeshot alternative) |
| **BiscuitWidgets** | components grab-bag (SugarWidgets alternative) |
| **SundaeServe** | SSH-served app aggregator |
| **MochiTunnel** | SSH multiplexer |
| **GumdropTimer** | stopwatch / countdown |
| **PuddingMetrics** | telemetry (CandyMetrics alternative) |

---

## Naming conventions (cheat sheet — kept for back-compat)

The original three prefixes still work and most of the existing
ecosystem uses them. New packages can use any of the wider sweet
vocabulary above — these three remain reserved as the safe defaults.

| Prefix | Meaning | Example uses |
|---|---|---|
| **Candy-** | foundation / system / framework | runtime (SugarCraft), terminal scripting (CandyScriptui), markdown (SugarMarkdown) |
| **Sugar-** | components / data / forms / apps | components (SugarWidgets), charts (SugarCharts) |
| **Honey-** | math / physics / motion | spring physics (SugarSpring); the Flappy clone left this prefix family in the 2026-10 rename (now CandyFlappy) |

`Candy-` (Files) is the naming for the file manager (formerly SuperCandy).
Don't mint new prefixes without a discussion in this file.
