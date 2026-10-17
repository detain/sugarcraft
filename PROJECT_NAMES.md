# Project naming — rules + decision history

> The canonical package roster — every library and app with its
> role — lives in [MATCHUPS.md](./docs/MATCHUPS.md). This file is the **rulebook** for picking
> a name plus the open-ended sketchpad — early ideas, rejected names,
> and rationale. When you're adding a new library, pick a name here,
> add it to MATCHUPS.md, then follow the contributor playbook in
> [AGENTS.md](./AGENTS.md).

---

## The naming rule (2026-10 law)

Every package is **one prefix + one descriptive suffix**, kebab-cased in the
slug and CamelCased in the namespace:

```
[ prefix ]  -  [ functional word ]
──────────     ───────────────────
 candy- or       tells you what the
 sugar-           package actually does
 (by role)
```

The prefix is **decided by what the package is**, not by taste:

| Prefix | Role | Shape of the deliverable |
|---|---|---|
| **`candy-`** | runnable apps, CLI tools, games, full programs | you `bin/` it — `candy-code`, `candy-top`, `candy-git` |
| **`sugar-`** | libraries — components, data engines, primitives | you `require` it — `sugar-forms`, `sugar-charts`, `sugar-core` |

`honey-` was the former math/physics family; it was retired with the 2026-10
rename — see the history line in the cheat sheet below.

The functional word does the real work: a developer reading
`composer require sugarcraft/<name>` should have a fair guess what the package
provides. The two-word brand wordplay the early ecosystem leaned on (sweet
pun + function) now lives in the suffix and namespace only — the prefix
carries role information instead.

### Suffix rule

Use a **plain descriptive noun for what the package does** (`datepicker`,
`treelist`, `markdown`, `ssh-server`, `constraints`). Keep an inherited
upstream name **only when it is the well-known term for the thing itself**
(`top`, `tetris`). Avoid generic filler (`kit`, `set`, `tool`, `stuff`)
unless the package genuinely is a grab-bag of helpers.

Slug → kebab dir → composer pkg → namespace, with the namespace taking the
descriptive suffix: `sugar-markdown` → `sugarcraft/sugar-markdown` →
`SugarCraft\Markdown\`; `sugar-core` → `SugarCraft\Core\` follows the same
rule — the namespace is the suffix, whatever the prefix.

### Word flavor — the sweet palette (prose only)

The dessert vocabulary below is still the brand's color: it names the umbrella
(`SugarCraft`), flavors namespaces and examples, and is fine material for an
app's *display* name. It is **not** a source of new prefixes — new packages
take `candy-` or `sugar-` by role.

> Honey · Sugar · Candy · Sprinkles · Frosting · Glaze · Icing · Caramel ·
> Toffee · Fudge · Truffle · Praline · Mochi · Marshmallow · Macaron ·
> Cookie · Biscuit · Cupcake · Cake · Brownie · Donut · Eclair · Pancake ·
> Waffle · Scone · Tart · Pie · Pudding · Custard · Sorbet · Sherbet ·
> Gelato · Sundae · Parfait · Popsicle · Lollipop · Gumdrop · Bonbon ·
> Bento · Pretzel · Strawberry · Berry · Cherry · Peach · Apricot · Plum

**Avoid** sweet words that already have strong unrelated meanings in
software (`Cookie` reads as HTTP cookies; `Crumb` is fine because we
already use it for breadcrumbs).

### Domain cheat sheet — functional words

| Domain          | Good functional words |
|-----------------|------------------------|
| Components / widgets | Widgets, Parts, Chips, Panels |
| Layout         | Constraints, Flexbox, Grid, Style |
| Charts         | Charts, Plots, Graph |
| Forms / prompts | Forms, Input, Ask, Readline |
| Markdown / text | Markdown, Renderer |
| Mouse / input  | Mouse, Input, Click |
| Animation / physics | Animation, Spring |
| Telemetry      | Metrics, Tally, Track |
| Logging        | Log, Trail, Trace |
| Color profile  | Colorprofile, Profile |
| Storage / KV   | Store, Keep |
| Email          | Mail |
| Server / SSH   | GitServer, SshServer, Tunnel |
| Files / browse | Files, Gallery |
| Time tracking  | Codetime, Clock, Timer |
| Game           | (the game name itself, e.g. Tetris, Minesweeper, Flappy) |

### The "good name" test

Read the candidate aloud. Ask:

1. Does the **prefix match the role**? App / CLI → `candy-`; library → `sugar-`.
   If you cannot tell which you have, the package's shape is unclear — settle
   that before naming it.
2. Could a developer skimming a `composer require sugarcraft/<name>` line
   make a fair guess at what they're getting? If the answer is "no idea
   without docs", reach for a clearer functional word.
3. Is it under ~16 characters? Long names get truncated in lockfiles
   and read poorly in `--help` output.

If the functional word doesn't earn its place, the name is doing branding
only. That's fine for the umbrella (`SugarCraft`) but a poor choice for a
single-purpose package.

### Exception: app satellites (`<app>-<surface>`)

A package that exists only to give an existing app a second **surface**
takes the app's slug plus the surface word, even though that makes three
words. The app's name is already the brand and the function; the suffix says
which face of it you are installing, and the package sorts next to the app it
belongs to.

| Satellite | App | Surface | Decision |
|---|---|---|---|
| **CandyCodeWeb** (`candy-code-web/`, `sugarcraft/candy-code-web`, `SugarCraft\CodeWeb\`) | CandyCode | browser UI for `candy-code serve` (Vite + Vue 3 bundle + one-class PHP shim) | User decision, 2026-10-01: keep the three-word name. A two-word candidate (e.g. a `Sugar-` + `Web` coinage) would hide that it is candy-code's UI and unusable without it. |

The exception is narrow: a satellite has no use without its app, ships no
standalone functionality, and keeps the **app's** prefix (`candy-`) even
though its kind is a library — it is a face of the app, not a primitive you
compose with. A package that is useful on its own still needs a name of its
own under the role rule above.

### Historical ruling: `candy-top` (recorded 2026-10-08, superseded by the 2026-10 law)

Before the two-prefix law, `candy-top` was a *named exception*: the old cheat
sheet put apps under `Sugar-`, so a system-monitor app "would read
`sugar-top` by the apps heuristic," and the owner explicitly ruled
`Candy-` ("a system monitor is about as literally 'system' as a package
gets"). The 2026-10 rename made that ruling the **rule**: apps and CLI tools
— candy-top included — are `candy-` by definition. `candy-top` needs no
exception any more; it is the law's plainest example. The `top` suffix
survives under the well-known-term clause.

---

## Renamed in 2026-10

The full old → new map, generated from `tools/renames.json` (single source of
truth — regenerate with the `php -r` walk in `plan_rename.md` Phase 9 #1,
never hand-edit rows):

| Old package | New package | Batch | What happened |
|---|---|:---:|---|
| `honey-flap` | `candy-flappy` | A | renamed <!-- @rename-keep --> |
| `candy-mines` | `candy-minesweeper` | A | renamed <!-- @rename-keep --> |
| `sugar-calendar` | `sugar-datepicker` | A | renamed <!-- @rename-keep --> |
| `sugar-stash` | `candy-git` | B | renamed <!-- @rename-keep --> |
| `candy-query` | `candy-sql` | B | renamed <!-- @rename-keep --> |
| `candy-serve` | `candy-git-server` | B | renamed <!-- @rename-keep --> |
| `candy-shell` | `candy-scriptui` | B | renamed <!-- @rename-keep --> |
| `sugar-glow` | `candy-mdview` | B | renamed <!-- @rename-keep --> |
| `sugar-spark` | `candy-ansi-inspect` | B | renamed <!-- @rename-keep --> |
| `sugar-skate` | `candy-kv` | B | renamed <!-- @rename-keep --> |
| `sugar-post` | `candy-mail` | B | renamed <!-- @rename-keep --> |
| `sugar-wishlist` | `candy-ssh-launcher` | B | renamed <!-- @rename-keep --> |
| `sugar-tick` | `candy-codetime` | B | renamed <!-- @rename-keep --> |
| `candy-freeze` | `candy-codeshot` | B | renamed <!-- @rename-keep --> |
| `candy-mold` | `candy-starter` | B | renamed <!-- @rename-keep --> |
| `sugar-boxer` | `sugar-panels` | C | renamed <!-- @rename-keep --> |
| `sugar-stickers` | `sugar-flexbox` | C | renamed <!-- @rename-keep --> |
| `sugar-crumbs` | `sugar-navstack` | C | renamed <!-- @rename-keep --> |
| `sugar-veil` | `sugar-overlay` | C | renamed <!-- @rename-keep --> |
| `candy-hermit` | `sugar-quickpick` | C | renamed <!-- @rename-keep --> |
| `candy-lister` | `sugar-treelist` | C | renamed <!-- @rename-keep --> |
| `sugar-dash` | `sugar-dashboard` | C | renamed <!-- @rename-keep --> |
| `sugar-mcp` | `sugar-mcp-client` | C | renamed <!-- @rename-keep --> |
| `sugar-bits` | `sugar-widgets` | C | renamed <!-- @rename-keep --> |
| `candy-forms` | `sugar-forms` | C | renamed <!-- @rename-keep --> |
| `candy-mouse` | `sugar-mouse` | C | renamed <!-- @rename-keep --> |
| `candy-focus` | `sugar-focus` | C | renamed <!-- @rename-keep --> |
| `candy-fuzzy` | `sugar-fuzzy` | C | renamed <!-- @rename-keep --> |
| `candy-shine` | `sugar-markdown` | D | renamed <!-- @rename-keep --> |
| `candy-mosaic` | `sugar-image` | D | renamed <!-- @rename-keep --> |
| `sugar-reel` | `sugar-video` | D | renamed <!-- @rename-keep --> |
| `candy-flip` | `sugar-animation` | D | renamed <!-- @rename-keep --> |
| `candy-kit` | `sugar-cli` | D | renamed <!-- @rename-keep --> |
| `honey-bounce` | `sugar-spring` | D | renamed <!-- @rename-keep --> |
| `candy-palette` | `sugar-colorprofile` | D | renamed <!-- @rename-keep --> |
| `candy-sprinkles` | `sugar-style` | E | renamed <!-- @rename-keep --> |
| `candy-layout` | `sugar-constraints` | E | renamed <!-- @rename-keep --> |
| `candy-buffer` | `sugar-cellbuffer` | E | renamed <!-- @rename-keep --> |
| `candy-ansi` | `sugar-ansi-parser` | E | renamed <!-- @rename-keep --> |
| `candy-vt` | `sugar-vterm` | E | renamed <!-- @rename-keep --> |
| `candy-input` | `sugar-input` | E | renamed <!-- @rename-keep --> |
| `candy-async` | `sugar-async` | E | renamed <!-- @rename-keep --> |
| `candy-testing` | `sugar-testing` | E | renamed <!-- @rename-keep --> |
| `candy-log` | `sugar-log` | E | renamed <!-- @rename-keep --> |
| `candy-metrics` | `sugar-metrics` | E | renamed <!-- @rename-keep --> |
| `candy-wish` | `sugar-ssh-server` | E | renamed <!-- @rename-keep --> |
| `candy-pty` | `sugar-pty` | E | renamed <!-- @rename-keep --> |
| `candy-core` | `sugar-core` | E | renamed <!-- @rename-keep --> |
| `sugar-crush` | `candy-code` | F | renamed <!-- @rename-keep --> |
| `sugar-crush-web` | `candy-code-web` | F | renamed <!-- @rename-keep --> |
| `candy-zone` | `sugar-mouse` | 1 | absorbed into the target package <!-- @rename-keep --> |
| `sugar-prompt` | `sugar-forms` | 1 | folded into the target package <!-- @rename-keep --> |
| — | `candy-gifview` | 1.3 | new package split out of the renamed lib |

Old slugs keep working: every Packagist name above was redirected, every
GitHub repo moved or archived, and `docs/lib/<old>.html` serves a redirect
stub (plan_rename.md step 2.3). The pre-rename project/home runtime dirs (`~/.sugar-crush`, `.sugar-crush/`) are migrated on first launch by the `sugar-core` legacy-path handling. <!-- @rename-keep -->

---

## ✅ Strong (keep / build around)

| Name | Why it works |
|---|---|
| **SugarCraft** | umbrella brand — vague is correct here, it's the meta |
| **SugarSpring** | spring = spring physics, literally. (It shed its original math-family prefix in the 2026-10 rename; `sugar-` now marks it as the library it is.) |
| **SugarCharts** | sugar + charts = charts. literal. |
| **SugarTable** | sugar + table = data table. literal. |
| **SugarDatepicker** | sugar + datepicker = date picker. literal. |
| **SugarQuickpick** | sugar + quickpick = fuzzy quick-pick / filter overlay component. "quickpick" says the function plainly, matching the established terminal picker concept (was CandyHermit — the inherited upstream name retired with the 2026-10 rename). <!-- @rename-keep -->
| **SugarToast** | toast = notification metaphor IS the function. delightful. |
| **SugarNavstack** | nav stack = push/pop navigation stack + breadcrumb trail IS the function. delightful. (renamed from SugarCrumbs 2026-10). <!-- @rename-keep --> |
| **SugarAnsiParser** | ansi + parser = ECMA-48 state machine. "ansi" is the established term for the escape-code standard. Foundation layer extracted from sugar-vterm. |
| **CandyScriptui** | candy + script UI = scriptable one-shot terminal widgets callable from shell scripts (renamed from CandyShell 2026-10). <!-- @rename-keep --> |
| **CandyGitServer** | candy + Git server = self-hostable Git server app (was CandyServe). | <!-- @rename-keep -->
| **CandyKv** | candy + kv = personal key/value store app. `kv` says the domain plainly (was SugarSkate — the inherited upstream name retired with the 2026-10 rename). <!-- @rename-keep --> |
| **CandyCodeshot** | candy + codeshot = code → SVG screenshots, literally. Took this name in the 2026-10 rename; the upstream-inherited `freeze` pun retired with it. | <!-- @rename-keep -->
| **CandyGit** | candy + git = the git TUI. took this name in the 2026-10 rename; the original stash = the git verb wordplay retired with the old placement. |
| **CandyFlappy** | Flappy-Bird clone — took this name in the 2026-10 rename; the original bee-and-flap wordplay retired with the old math-family prefix. |
| **CandyStarter** | candy + starter = the create-project skeleton you start from. ✓✓ |
| **SugarImage** | image = image-to-cell renderer. "mosaic" was the technical term behind the old name; `image` is the plainer one. clear and literal. |
| **SugarVterm**     | vterm = virtual terminal emulator. "vt" is the established term inside the namespace (`SugarCraft\Vterm`). |
| **CandyVcr**    | candy + vcr = record + replay terminal sessions — shipped as a CLI (cassettes, player). "vcr" carries the cassette / playback metaphor for free. |
| **SugarAnimation**   | animation = flip-book frames — the GIF member of the visual family beside SugarImage (still images) and SugarVideo (video). "flip" carried the frame-by-frame metaphor in the old name. |
| **CandyGifView** | candy + gif + view = the terminal GIF/.ansi-art viewer app — the command formerly `bin/sugar-animation`, split into its own package (rename campaign step 1.3). "view" states the app role beside the SugarAnimation toolkit. |
| **SugarPty**    | pty = pseudo-terminal primitive. "pty" is the Unix term of art; no package-namespace prefixes. Foundation lib for spawning child processes wired to a controlled PTY. |
| **SugarForms** | sugar + forms = form primitives foundation. extraction target for TextInput, TextArea, ItemList, Viewport, FilePicker, Field interface, Confirm, Form from sugar-widgets. |
| **SugarFocus** | sugar + focus = the focus-management component. dependency-free ring: an ordered set of focusable regions with one focused member + wrap-around Tab/Shift-Tab traversal for full-window TUI layouts. Original design; it generalizes the one-focused-region approach sugar-dashboard first used here. || **SugarGallery** | sugar + gallery = a poster gallery / grid for media TUIs. 2-D virtualized, sparse PosterGrid for large libraries + owner-driven range paging, a horizontal Rail carousel, and a renderer-agnostic PosterCard tile. Original — the web media-shelf pattern, rebuilt for the terminal. |
| **SugarFuzzy** | sugar + fuzzy = fuzzy string matching with scored matched indices. extracted from sugar-forms; adds indices output unblocking filter-highlighting UI. two algorithms: Smith-Waterman (local alignment) + Sahilm (filter-as-you-type). || **SugarCellBuffer** | cell buffer = cell-grid value objects. The shared Buffer/Cell foundation for terminal rendering across the ecosystem. |
| **SugarAsync** | sugar + async = shared async vocabulary for ReactPHP. Original — CancellationToken (owner/source pattern), Subscription interface, Subscriptions::compose(), AsyncOps static helpers (withTimeout, retry, debounce, throttle). Unifies ReactPHP usage scattered across sugar-core, sugar-forms, sugar-ssh-server. |
| **SugarConstraints** | constraints = constraint-based layout solver. Implements the Cassowary constraint system (Badros & Borning, 2001) behind a LayoutSolver interface: CassowarySolver (simplex) + GreedySolver (5-phase fallback from sugar-style). |
| **SugarTesting** | sugar + testing = test harness for SugarCraft apps. ProgramSimulator drives Programs with scripted input; Assertions provides assertGoldenAnsi, assertCellGrid, assertAnsiEquals; TapeRecorder emits VHS .tape files. |
| **SugarInput** | input = terminal escape sequence decoder. EscapeDecoder consumes raw TTY bytes and emits typed Events (KeyEvent, MouseEvent, FocusEvent, PasteEvent, ResizeEvent). Unblocks sugar-readline's move to real-TTY input. |
| **SugarMouse** | plain descriptive — took this name in the 2026-10 rename; the “candy + mouse” wordplay retired with it. Self-contained Mark/Scan/Get mouse hit-testing + ZoneClickTracker. No external Manager wiring — the scanner is owned by each consumer. |
| **CandyMinesweeper** | plain descriptive — took this name in the 2026-10 rename; the “candy + mines” wordplay retired with it. |
| **SugarTreelist** | tree + list = scrollable tree/list view component. literal. |
| **CandyTop** | candy + top = the `top`/`btop` family of system monitors. Design antecedent [aristocratos/btop](https://github.com/aristocratos/btop); `top` kept as the well-known term, `candy-` by the 2026-10 apps law (it was the owner's prefix ruling before the law existed — see the historical note above). |
| **CandySql** | candy + sql = terminal SQL client. literal pairing (was candy-query — the query-editor framing retired with the 2026-10 rename); multi-driver: SQLite, MySQL, PostgreSQL — schema browser, query editor, EXPLAIN plan viewer, server status page, alerting, and query history. | <!-- @rename-keep -->
| **SugarVideo** | video = terminal video player. sits beside SugarAnimation (GIF) and SugarImage (images) as the video member of the family. |
| **SugarDiff** | sugar + diff = unified-diff engine. library per the role law; extracted from candy-code (BuildsUnifiedDiff + DiffGutter numbering model); first-party PHP. |
| **Sugar MCP Client** | sugar + mcp = Model Context Protocol client core. library per the role law; extracted from candy-code (src/MCP stdio stack + McpMessage codec); first-party PHP — MCP is an open protocol spec. |
| **CandyCodeWeb** | app satellite of CandyCode — the browser UI for `candy-code serve`. Three words by the `<app>-<surface>` exception above; first-party PHP + Vue. |
| **CandyCode** | candy + code = TUI AI coding assistant, shipped as a CLI binary. Multi-provider (OpenAI, SGLang, Claude Code, etc.), multi-agent, skill-aware. |

## ⚠️ Functional half is weak / vague

| Current | Issue | Sketches |
|---|---|---|
| (the runtime, `sugar-core`) | "SugarCraft" is also the umbrella; the sub-package needs a less-collision-prone name | `CakeStage`, `BatterCore`, `WhiskLoop`, `MochiCore` |
| ~~**SugarBits**~~ → **SugarWidgets** | resolved by the 2026-10 rename — "Bits" was generic; the lib is a set of named widgets <!-- @rename-keep --> | `BiscuitWidgets`, `SprinkleParts`, `ChipParts` |
| **SugarCli** | "Kit" was filler; the lib is CLI presentation primitives | `CupcakeKit`, `BentoCli`, `BiscuitCli` |
| **SugarMarkdown** | the old inherited name didn't say markdown | `GlazeMarkdown`, `IcingMarkdown` |
| **SugarSshServer** | the old inherited name didn't say SSH | `MochiTunnel`, `SorbetTunnel` |
| **CandySshLauncher** | piggybacked on the old server name; doesn't say SSH launcher | `BentoLauncher`, `PicnicPicker` |
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
matching package turns out to be. (New packages still take `candy-`/`sugar-`
by role; these combos sketch the flavor for namespaces, examples, and display
names.)

| Combo | Likely fit |
|---|---|
| **PancakeFlip** | image / video / GIF flipping (SugarAnimation alternative) |
| **PieChart** | another charts library |
| **CookieClick** | mouse-zone tracker (SugarMouse alternative) |
| **BentoBox** | layout (SugarPanels alternative) |
| **BentoLauncher** | launcher / picker (CandySshLauncher alternative) |
| **MarshmallowFilter** | fuzzy-finder (SugarQuickpick alternative) |
| **MochiTunnel** | SSH multiplexer |
| **CaramelStore** | KV / cache (CandyKv alternative) |
| **TruffleFinder** | fuzzy / search overlay |
| **GlazeMarkdown** | markdown renderer (SugarMarkdown alternative) |
| **IcingTheme** | theme system / palette extension |
| **FrostingPaint** | colors / styling overlay |
| **SherbetSnap** | screenshot / capture (CandyCodeshot alternative) |
| **BiscuitWidgets** | components grab-bag (SugarWidgets alternative) |
| **SundaeServe** | SSH-served app aggregator |
| **GumdropTimer** | stopwatch / countdown |
| **PuddingMetrics** | telemetry (SugarMetrics alternative) |

---

## Naming conventions (cheat sheet — two prefixes, since 2026-10)

| Prefix | Meaning | Example uses |
|---|---|---|
| **Candy-** | runnable apps, CLI tools, games | coding agent (CandyCode), git TUI (CandyGit), SQL client (CandySql), file manager (CandyFiles) |
| **Sugar-** | libraries — components, data, engines, primitives | forms (SugarForms), charts (SugarCharts), runtime (SugarCraft), markdown (SugarMarkdown) |

History: the `Honey-` family (math / physics / motion) was retired with the
2026-10 rename — the two honey packages joined the surviving families
(the spring-physics lib took `sugar-`, the Flappy app took `candy-`).
<!-- @rename-keep -->

Don't mint new prefixes without a discussion in this file.
