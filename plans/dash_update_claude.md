---
status: not-started
phase: 0
updated: 2026-05-13
goal: Reorganize sugar-dash from flat src/Grid/ namespace into proper subpackages, then extract missing patterns from 7 reference Go libraries.
---

# SugarDash — Update Plan (claude)

## Reference material — local source dumps from the 7 reference repos

Cloned for this plan to `/tmp/dash-research/src/` on 2026-05-13. Use as the citation target for every `Mirrors <repo>/<file>:<line>` docblock in the eventual PHP port.

```
/tmp/dash-research/src/
├── bubble-grid/         shahar3/bubble-grid (main)        — grid.go, frame.go, README.md
├── tilelayout/          mko88/bubbletea-tilelayout (main) — layout.go, layout_tile.go, README.md
├── boxer/               treilik/bubbleboxer (main)        — boxer.go, README.md  (84★)
├── lattice/             floatpane/lattice (master)        — pkg/{module,registry,plugin,config,styles}, internal/{layout,plugin,modules/*}, README.md
├── homedash/            kts982/Homedash (master)          — internal/ui/{notifications,messages,keys,app}, components/{sparkline,gauge,panel}, panels/{system,containers,header}, state, collector, README.md
├── tealeaves/           mikeschinkel/go-tealeaves (main)  — 8 sub-pkgs flattened: teagrid_*, tealayout_*, teamodal_*, teatree_*, teastatus_*, teafields_*, teanotify_*
└── termui/              sashakoshka/termui (GPLv3 — clean-room rewrite only!) — canvas.go, grid.go, style.go, style_parser.go, theme.go, drawille_drawille.go, widgets_{plot,sparkline,gauge,barchart,piechart}.go, block.go, buffer.go
```

`/tmp/` is volatile — gets wiped on reboot. Two options if the dumps disappear:

1. **Quick recover** — re-run the fetch commands. They're idempotent. The bash that produced the dump:
   ```bash
   unset GITHUB_TOKEN && mkdir -p /tmp/dash-research/src && cd /tmp/dash-research/src
   # Per-repo: gh api repos/<owner>/<name>/git/trees/<branch>?recursive=1 → pick files → curl raw
   # See conversation transcript for exact file lists per repo.
   ```
2. **Persist** — `cp -r /tmp/dash-research <somewhere-stable>` before reboot. Or stash a tarball in `plans/dash-research.tar.gz` (gitignored).

**Note on `termui` (sashakoshka/termui at git.tebibyte.media):** GPLv3 — sugar-dash is MIT. The math (braille bit-table, BRAILLE_OFFSET, cell coords, Bresenham line) is not copyrightable. The state-machine ParseStyles parser needs a **clean-room rewrite** from the algorithmic description in this plan, not from copying the Go source. Don't paste termui code into PHP files.

## Summary of current state vs. plan

**Current state:** 222 PHP files all under `SugarCraft\Dash\Grid\` namespace. The lib has accumulated way more than the original 18-package plan envisioned — a lot of components are done, just unsorted. The `Grid/` name is misleading (it holds widgets, layout primitives, events, themes, drawing, etc.).

**Original plan scope (18 packages):** Grid, Boxer, Layout, RatioGrid, GridTable, Tree, StatusBar, Modal, Select, Toast, Tabs, Plot, Drawable, Module, Registry, Plugin, Modules, Keys/Theme/Position/Output.

**Gap analysis:**

| Plan area | Status in current src/Grid/ | Action needed |
|-----------|------------------------------|---------------|
| Item/Sizer interfaces (bubble-grid) | ✅ `Item.php`, `Sizer.php`, `ItemOptions.php`, `ItemWithOptions.php`, `Options.php`, `StackedGrid.php` | Move to `Layout/Grid/` |
| Frame (border+padding) | ✅ `Frame.php`, `Boxer.php` (misnamed — see below) | Move to `Layout/` |
| Boxer (address-tree, **bubbleboxer**) | ❌ NOT what we have — current `Boxer.php` is a padding/border wrapper, not the LayoutTree+ModelMap concept from bubbleboxer | Rename current → `Pad.php` + write a real `Layout\Boxer\Boxer.php` with `Node`/`ModelMap`/`CreateLeaf`/`EditLeaf` |
| TileLayout (constraint, **tilelayout**) | 🟡 `Layout.php` has flex + gap + min/max-ish, but no iterative leftover distribution | Replace internals with 5-phase solver from tealayout (better than tilelayout's `int(normalizedWeight)` bug) |
| RatioGrid (XRatio/WidthRatio, **termui**) | ❌ Missing | Add `Layout/RatioGrid/` |
| Drawable interface (**termui**) | ❌ Missing — no universal `GetRect/SetRect/Draw` contract; widgets all return strings | Add `Drawable/` foundation namespace |
| Buffer + Cell (**termui**) | 🟡 No `Buffer` cell array; `Canvas` exists but is pixel-mapped to cells 1:1 (no braille) | Add `Drawable/Buffer.php` + `Drawable/Cell.php` |
| ParseStyles (`[text](fg:red,bg:blue)`, **termui**) | ❌ Missing | Add `Drawable/StyleParser.php` |
| Braille Canvas (2x4 dots/cell, **termui**) | ❌ Current `Canvas.php` is 1:1 pixel-per-cell, no braille | Add `Plot/Braille/` with `⠀` offset math |
| Plot (line/scatter with braille, **termui**) | 🟡 Have `Chart`, `Sparkline`, `Bar`, `Area`, `Heatmap`, `RadarChart`, `CandlestickChart`, `FunnelChart`, `GaugeChart`, `Donut`, `Pictogram`, `OHLC`, `SparklineArea/Bar`, `Treemap`, `Sankey`, `Sunburst`, `Dendrogram` | Add `Plot/Plot.php` (line+scatter braille), keep existing chart family in `Plot/Chart/` |
| Sparkline + RingBuffer (**Homedash**) | 🟡 Have `Sparkline.php`, `SparklineArea.php`, `SparklineBar.php`, `SparkArea.php`; no RingBuffer | Move + add `Plot/RingBuffer.php` |
| Gauge + GaugeWithDetail (**Homedash**) | 🟡 Have `Gauge`, `GaugeChart`, `GaugeCircle`, `ProgressBar`, `ProgressRing`, `ProgressList`, `Meter`, `Bar`, `NProgress`, `Progress` | Move to `Plot/Gauge/` family; add `gaugeWithDetail()` overlay variant |
| GridTable (sort/filter/page/scroll, **teagrid**) | 🟡 Have `TableChart`, `TableBordered`, `TableZebra` (visual tables); not the full **data grid** with sort/filter/page/scroll/freeze | Add `Components/GridTable/` (Column/Row/Cell/Sort/Filter/Pagination/Scrolling/Border) |
| Tree (provider pattern, **teatree**) | 🟡 Have `Tree`, `TreeNode`, `TreeViz`, `TreemapLeaf`, `Network*`, `MindMap`, `OrgChart`, `ClassDiagram`, `Flowchart*`, `Dendrogram*`, `Sankey`, `Sunburst`, `Partition*`, `Timeline*`, `Gantt`, `PERT` | Move tree/graph family into `Components/Tree/` and `Plot/Graph/`; add provider+drilldown variant |
| StatusBar (two-zone, **teastatus**) | ✅ `StatusBar.php`, `StatusIndicator.php` | Move to `Components/StatusBar/` |
| Modal (wither pattern, **teamodal**) | ✅ `Modal.php`, `Notification.php`, `Popover.php`, `Drawer.php`, `Wizard.php`, `WizardStep.php`, `Alert.php`, `Toast.php` | Move to `Components/Modal/`; add `OK/YesNo/List/Multiselect/Progress` variants |
| Select (auto-position, **teafields**) | ✅ `Select.php`, `ComboBox.php`, `Dropdown.php`, `Radio.php`, `DatePicker.php`, `ColorPicker.php` | Move to `Components/Select/`; add overlay+auto-position |
| Toast (position presets, **teanotify**) | ✅ `Toast.php`, `Notification.php`, `Hint.php`, `Tooltip.php` | Move to `Components/Toast/` |
| Tabs (focus left/right, **termui**) | ✅ `Tabs.php`, `TabsVertical.php` | Move to `Components/Tabs/` |
| Module/Registry/Plugin (**lattice**) | ❌ Missing entirely | Add `Module/`, `Registry/`, `Plugin/` namespaces |
| Built-in Modules (Clock/System/Weather, **lattice**+**Homedash**) | 🟡 Have `Clock.php`, `Stopwatch.php`, `Timer.php`, `Stats.php`, `Stat.php`, `Metric.php`, `MetricsGrid.php`, `Audio.php`, `Network.php` (graph), `Console*.php`, `Terminal.php`, `Log.php`, `LogViewer.php` | Add `Modules/Clock`, `Modules/System`, `Modules/Weather` shaped to `Module` interface |
| TickEpoch (focus-regain epoch, **Homedash**) | ❌ Missing | Add `Module/TickEpoch.php` |
| NotificationQueue (3-level, dual-buffer, **Homedash**) | 🟡 Have `Notification.php`, `Alert.php`, `Toast.php` | Add `Components/Notify/Queue.php` |
| FocusManager (depth-first, name-based, **tealayout**) | 🟡 Have `Focus.php`, `FocusEvent.php` (low-level events) | Add `Layout/FocusManager.php` |
| StackLayoutModel (drilldown+breadcrumb, **tealayout**) | 🟡 Have `Breadcrumb.php`, `Wizard.php`, `Stepper.php` | Add `Layout/StackLayout.php` with cache + OnEnter/OnExit |
| Keys registry (**teautils**) | 🟡 Have `Key.php`, `KeyMap.php`, `KeyAction.php`, `KeyEvent.php` (input plumbing) | Add `Keys/Registry.php` (status-bar + help-modal categories) |
| Theme (Palette presets, **Homedash**) | ✅ `Theme.php`, `State.php`, `EdgeStyle.php` | Keep, expose `Palette::dark()/light()/adaptive()` factories |
| Position helpers (**teafields**) | 🟡 Have `Center.php`, `HAlign.php`, `VAlign.php`, `AlignItems.php`, `JustifyContent.php`, `FlexDirection.php`, `FlexWrap.php` | Add `Position/Center.php` (ANSI-aware width measure) |
| Output helpers (`renderBar`, `truncate`) | 🟡 Truncate logic duplicated in multiple files (StackedGrid, Boxer, Layout) | Extract to `Output/` namespace |

**Above-and-beyond components currently in the lib** (not in original plan; keep, reorganize):

`Accordion, ActivityFeed, AreaChart, AreaPoint, ASCIIBanner, Audio, AvatarGroup, Avatar, BadgeGroup, Badge, Barcode, BorderText, BoxDrawing, Breadcrumb, Bubble, BubblePoint, Bullet, Calendar, Candlestick(+Chart), Card, Chart, Checkbox, ChipGroup, Chip, ClassDiagram, Clock, Code, ColorPicker, Comment, Console(+Entry+Stream), Cover, CTA, Cursor, DatePicker, Dendrogram(+Node), Diff, Divider, Donut, DotMatrix, Drawer, Dropdown, Editor, Emoji, EmptyState, EventDispatcher/Handler, Features, FigletText, Flowchart(+Node+Type), Footer, Funnel(+Chart), Gantt, Graph, Header, HeatmapCalendar, HeatMapChart, Heatmap, HexDump, Highlight, Hint, Icon, Image, Input, Jumbotron, Kbd, Label, Ladder, Leaderboard, ListComponent, LoadingText, Markdown, Marquee, MindMap, Meter, Navbar, NetworkNode/Shape, OHLC(+Point), OrgChart, Pagination(+Simple), Panel, Paragraph, Partition(+Segment), PERT, Pictogram, Picture, Popover, Pricing, Profile, QRCode, RadarChart, Rating, Sankey, Scrollbar, Sequence, Shadow, Sidebar, Skeleton, Slider, Spacer, Spinner, Split(+Direction), Stepper, Sunburst, SwitchComponent, Tag, Terminal, Testimonial, Textarea, Text, Timeline(+Node+Viz), Toggle, Tooltip, Transformer, TreeNode/Viz, Video, Viewport, Waterfall(+Item+BarType), Wizard(+Step), WordCloud, ZStack`

These split into clear families: **Layout primitives** (Stack/VStack/HStack/ZStack, FlexLayout, GridLayout, Split, Panel, Sidebar, Window, Spacer, Frame), **Charts** (everything ending in Chart + Plot family), **Graphs** (Network, MindMap, OrgChart, Flowchart, ClassDiagram, Dendrogram, Sankey, Sunburst, Partition, Treemap, Gantt, PERT, Timeline), **Forms** (Input, Textarea, Select, Radio, Checkbox, Toggle, Slider, ComboBox, DatePicker, ColorPicker, Switch, Editor, Form-like CommandPalette), **Feedback** (Modal, Toast, Notification, Alert, Hint, Tooltip, Skeleton, LoadingText, EmptyState, Popover, Drawer), **Media** (Image, Picture, Audio, Video, QRCode, Barcode, FigletText, ASCIIBanner, Marquee, BorderText, BoxDrawing, Emoji, Icon, Pictogram, Shadow), **Structure/Cards** (Card, Accordion, Header, Footer, Profile, Testimonial, CTA, Jumbotron, Tag, Badge, BadgeGroup, Chip, ChipGroup, Cover, Hero, Pricing, Leaderboard, Stat, Stats, Metric, MetricsGrid, EmptyState, ActivityFeed, Comment, Bullet, Label, Kbd, Code, Highlight, Diff, Markdown), **Nav** (Tabs, TabsVertical, Menu, Navbar, Breadcrumb, Pagination, PaginationSimple, Sidebar, Stepper, Ladder, Sequence, Wizard, WizardStep, Scrollbar, CommandPalette), **System/Console** (Console, ConsoleEntry, ConsoleStream, Terminal, Log, LogViewer, HexDump, Cursor, Spinner, Clock, Timer, Stopwatch, NProgress, ProgressBar/Ring/List), **Tables/Lists** (TableChart, TableBordered, TableZebra, ListComponent, Calendar, HeatmapCalendar), **Decoration** (Divider, Spacer, Cover, Shadow), **Events/Input** (Event, EventDispatcher, EventHandler, FocusEvent, KeyEvent, MouseEvent, PasteEvent, ResizeEvent, Key, KeyMap, KeyAction, Focus).

## Ingenious math/logic worth extracting (verbatim from sources)

These are the non-obvious algorithms that should land in sugar-dash regardless of whether we ship the full lib:

### 1. Braille canvas math (termui/drawille)
**Source:** `/tmp/dash-research/src/termui/drawille_drawille.go:7-39`

```go
const BRAILLE_OFFSET = '⠀'
var BRAILLE = [4][2]rune{
    {'', ''},
    {'', ''},
    {'', ' '},
    {'@', ''},
}

// SetPoint(p) where p is in dot-coordinates (2x4 per cell):
//   cell = (p.X/2, p.Y/4)
//   dotBit = BRAILLE[p.Y%4][p.X%2]
//   rune |= dotBit
// Final rune emitted = rune + BRAILLE_OFFSET (U+2800 = base Braille pattern)
```

**Why this matters:** Gives 2× horizontal and 4× vertical resolution vs. the 1:1 pixel canvas sugar-dash currently ships. A 80×24 terminal becomes a 160×96 dot canvas for line charts, scatter plots, and arbitrary geometry. Plot widget gets dramatically smoother curves.

### 2. Five-phase constraint solver (go-tealeaves/tealayout)
**Source:** `/tmp/dash-research/src/tealeaves/tealayout_resolve.go:35-201`

Five-phase resolver beats tilelayout's iterative leftover distribution:

1. **minSizeFit** — query optional SizeHinter for effective min before solving
2. **Phase 1** — assign Fixed and Fit sizes, subtract from remaining
3. **Phase 2+3** — distribute remaining to Flex via **cumulative rounding**:
   ```go
   cumulative += (weight/totalWeight) * flexAvailable
   pos := int(math.Round(cumulative))
   rawSize := pos - prevPos
   prevPos = pos
   ```
   Then clamp; if any child hit min/max, freeze it and redistribute the rest. Loops max-N times.
4. **Phase 4** — optional children below MinSize are removed and retry from Phase 1
5. Stable.

**vs. bubbletea-tilelayout** (`/tmp/dash-research/src/tilelayout/layout.go:258-313`): tilelayout uses `toAdd := max(1, leftoverWidth*int(normalizedWeight))` — `int(normalizedWeight)` **truncates 0.5 → 0**, so all flex tiles get 1 pixel per iteration. Up to 100 iterations to converge. Cumulative rounding converges in O(N) with no error accumulation.

**Why this matters:** Current `Layout::calculateHorizontalSizes()` (`src/Grid/Layout.php:241-311`) does fixed-then-flex but no clamping loop and no cumulative rounding — flex children silently lose pixels at the rounding boundary. Adopt cumulative rounding now even if we don't ship the full 5-phase machinery.

### 3. Bubbleboxer address-tree separation (84★)
**Source:** `/tmp/dash-research/src/boxer/boxer.go:24-86`

Layout tree (`Node`) is structurally distinct from model instances (`ModelMap[address]string → tea.Model`). Mutating a model never requires traversing the tree. `CreateLeaf(address, model)` is the **only** way to spawn a valid leaf — the `address` field is private. `EditLeaf(address, fn)` runs a closure on the model and auto-saves only on success.

**Why this matters:** Sugar-dash currently rebuilds entire layouts on each `setSize()`. With a Node/ModelMap split, panels can update independently without re-allocating the tree. Critical for dashboards where one panel updates per second and the others are quiescent.

### 4. ParseStyles inline syntax (termui)
**Source:** `/tmp/dash-research/src/termui/style_parser.go:77-156`

Syntax: `[text](fg:red,bg:blue,mod:bold)` with state-machine parser. Nested `[...]` allowed via `squareCount` tracking. Falls back to default style on malformed input via `rollback()`.

**Why this matters:** Markdown/Highlight/Code/Text widgets all currently take an external `Style` argument per chunk. Inline syntax compresses theming into the string itself — much nicer ergonomics for log output, status messages, hover hints. Becomes the canonical "rich text" syntax for sugar-dash.

### 5. RatioGrid recursive composition (termui/grid.go)
**Source:** `/tmp/dash-research/src/termui/grid.go:82-133`

```go
// NewCol(0.5, widget) | NewCol(0.5, NewRow(0.3, w1), NewRow(0.7, w2))
// setHelper multiplies parent's WidthRatio/HeightRatio recursively
//   child.WidthRatio = parentWidthRatio * (col ? item.ratio : 1.0)
//   child.HeightRatio = parentHeightRatio * (row ? item.ratio : 1.0)
//   leaf: x = width*XRatio, y = height*YRatio, w = width*WidthRatio
```

**Why this matters:** Reads like declarative UI. Sugar-dash's current `Layout` is imperative (`withChild()`/`addItem()`). RatioGrid composition is cleaner for dashboard layouts that don't need flex semantics — pure float ratios that compose recursively.

### 6. Cumulative-rounding sparkline scale (Homedash)
**Source:** `/tmp/dash-research/src/homedash/internal_ui_components_sparkline.go:31-46`

```php
$idx = (int)($v / 100 * (count($blocks) - 1));  // ▁▂▃▄▅▆▇█ → 0..7
if ($v < 5) { use dim-style $blocks[0]; }       // visual floor
```

Plus **left-pad with dim ░** when buffer has fewer samples than width — keeps right-edge anchored on most recent data even as history fills.

**Why this matters:** Sugar-dash's existing Sparkline probably scales differently; standardize on 0–100 → idx mapping with dim-edge padding.

### 7. GaugeWithDetail overlay (Homedash)
**Source:** `/tmp/dash-research/src/homedash/internal_ui_components_gauge.go:36-89`

Compute bar width = total − label − padding − pct. Center detail text on bar. Compute filled/empty split, then split detail-text position too: **left of detail** gets filled-color blocks up to `min(pad, filled)`, then dim blocks for the remainder; **right of detail** gets filled if still in the filled range, else dim. Detail text is bold, in default fg color, drawn between.

```
DATA   ███████ 105G/250G ░░░░░░░░  42%
```

**Why this matters:** Disk gauges in Homedash overlay `used/total` strings directly on the bar. Much higher information density than two-line "DISK ███░░ 42%\n  105G/250G".

### 8. Notification queue with dual ring (Homedash)
**Source:** `/tmp/dash-research/src/homedash/internal_ui_notifications.go:22-102`

Two parallel slices: `items` (active, max 20, dismissable) + `history` (max 50, append-only). `current()` returns head of `items`. `recent(n)` returns last `n` from `history` newest-first. Both buffers drop oldest when full via slice reslicing.

**Why this matters:** "Show current notification + show last 5 in alert panel" is the standard dashboard pattern. Current sugar-dash `Notification.php` is single-shot.

### 9. Atomic state persistence (Homedash)
**Source:** `/tmp/dash-research/src/homedash/internal_state_state.go:46-75`

```go
tmp := path + ".tmp"
os.WriteFile(tmp, data, 0644)
os.Rename(tmp, path)  // atomic on POSIX
```

**Why this matters:** Dashboards persist user prefs (collapsed panels, current tab). Tmp+rename prevents corruption on crash. PHP's `file_put_contents` + `rename()` gives same guarantee.

### 10. Responsive panel breakpoint (Homedash)
**Source:** `/tmp/dash-research/src/homedash/internal_ui_panels_system.go:13-122`

`if width < 90: render single-column; else: dual-column with left=48%, right=52%, gap=2`. Disk gauges fall through to single-column body in narrow mode.

**Why this matters:** TUI dashboards are routinely run in narrow tmux panes. Hard width thresholds are the simplest responsive pattern. Should be a documented convention across sugar-dash panels.

### 11. Lattice JSON plugin protocol (floatpane/lattice)
**Source:** `/tmp/dash-research/src/lattice/pkg_plugin_sdk.go` + `internal_plugin_runner.go`

Line-delimited JSON over stdin/stdout. Three request types: `init` (returns Name/MinSize/Interval), `update` (returns Content), `view` (with Width/Height → Content). Plugin can be any executable in any language. `Interval > 0` schedules periodic update via `tea.Tick`.

**Why this matters:** Lets users write dashboard modules in Bash, Python, Node, or PHP without recompiling sugar-dash. A `system-fan-speed.sh` plugin is 20 lines of bash.

### 12. teagrid GCD for column ratios (`/tmp/dash-research/src/tealeaves/teagrid_calc.go:1-15`)
Recursive GCD via `goto end`-style. Used when reducing column-width ratios to smallest equivalent integers (e.g. `[2,4,6] → [1,2,3]`). Useful when column widths are user-specified as integers but need to be proportional.

---

## New plan: 0-7 phases, each shipped as a separate PR

Each phase is a self-contained PR per the project's ship-as-you-go cadence (see `AGENTS.md`). Branches `ai/sugar-dash-phase-NN-<slug>`.

### Phase 0 — Inventory + Reorganize (no behavior changes)

Goal: move all 222 files out of `src/Grid/` and into proper subnamespaces. Update PSR-4 autoload, update all internal `use` references, update tests. No new features.

**New namespace structure:**

```
src/
├── Foundation/           # Pure interfaces + low-level primitives (no widgets)
│   ├── Item.php          # was Grid/Item.php
│   ├── Sizer.php         # was Grid/Sizer.php
│   ├── Drawable.php      # NEW — interface (GetRect/SetRect/Draw/Lock)
│   ├── Buffer.php        # NEW — Cell[][] grid
│   ├── Cell.php          # NEW — rune + style
│   ├── Style.php         # extracted from Theme
│   ├── StyleParser.php   # NEW — ParseStyles inline syntax
│   ├── Color.php         # alias of Core's Color (if no extra logic)
│   ├── Rect.php          # NEW — image.Rectangle equivalent
│   └── Theme.php         # was Grid/Theme.php
├── Layout/
│   ├── Grid/             # bubble-grid pattern
│   │   ├── StackedGrid.php
│   │   ├── Options.php
│   │   ├── ItemOptions.php
│   │   └── ItemWithOptions.php
│   ├── Tile/             # tilelayout pattern but with 5-phase solver
│   │   ├── TileLayout.php
│   │   ├── Tile.php
│   │   ├── BaseTile.php
│   │   ├── Size.php           # Weight/Min/Max/Fixed/optional/minSizeFit
│   │   ├── Constraint.php
│   │   ├── Direction.php
│   │   └── Resolver.php       # 5-phase: minSizeFit → Fixed/Fit → Flex+clamp → optional-remove
│   ├── Boxer/            # NEW — bubbleboxer pattern (NOT current Boxer.php)
│   │   ├── Boxer.php          # LayoutTree + ModelMap
│   │   ├── Node.php
│   │   ├── SizeFunc.php
│   │   └── Address.php        # type-safe wrapper around string
│   ├── RatioGrid/        # NEW — termui pattern
│   │   ├── RatioGrid.php
│   │   ├── GridItem.php       # XRatio/YRatio/WidthRatio/HeightRatio
│   │   └── NewColRow.php      # factories
│   ├── FocusManager.php  # NEW — depth-first walk, name-based, hidden-skip
│   ├── StackLayout.php   # NEW — drilldown + breadcrumb + view cache
│   ├── Frame.php         # was Grid/Frame.php
│   ├── Pad.php           # RENAMED from Boxer.php (padding-only wrapper)
│   ├── Layout.php        # was Grid/Layout.php — keep but use Tile/Resolver internally
│   ├── LayoutItem.php
│   ├── LayoutDirection.php
│   ├── FlexLayout.php
│   ├── FlexDirection.php
│   ├── FlexWrap.php
│   ├── GridLayout.php
│   ├── GridItem.php
│   ├── Stack.php · VStack · HStack · ZStack · Split · SplitDirection
│   ├── Panel.php · Sidebar · Window · Spacer · Screen · Viewport
│   └── Center · HAlign · VAlign · AlignItems · JustifyContent
├── Components/
│   ├── GridTable/        # NEW — teagrid full data grid
│   │   ├── GridTable.php       # sort/filter/page/scroll/freeze
│   │   ├── Column.php
│   │   ├── Row.php
│   │   ├── Cell.php
│   │   ├── BorderConfig.php    # Outer/Header/Inner/Footer regions
│   │   ├── BorderChars.php     # Default/Rounded/Borderless/Minimal presets
│   │   ├── Sort.php
│   │   ├── Filter.php
│   │   ├── Pagination.php
│   │   ├── Scrolling.php
│   │   └── Header.php · Footer.php · Overflow.php · Calc.php (gcd)
│   ├── Table/            # existing visual tables
│   │   ├── TableChart.php
│   │   ├── TableBordered.php
│   │   └── TableZebra.php
│   ├── Tree/
│   │   ├── Tree.php             # generic
│   │   ├── TreeNode.php
│   │   ├── TreeViz.php
│   │   ├── BranchStyle.php      # 5 presets
│   │   ├── Provider.php         # NEW — teatree-style lazy loader interface
│   │   └── DrilldownTree.php    # NEW — teatree drilldown variant
│   ├── StatusBar/
│   │   ├── StatusBar.php
│   │   ├── StatusIndicator.php
│   │   ├── MenuItem.php         # NEW
│   │   └── SeparatorKind.php    # NEW
│   ├── Modal/
│   │   ├── Modal.php             # base
│   │   ├── ConfirmModal.php      # NEW — OK/YesNo with withers
│   │   ├── ListModal.php         # NEW — selectable list with edit/delete
│   │   ├── ProgressModal.php     # NEW
│   │   ├── MultiselectModal.php  # NEW
│   │   ├── Popover.php
│   │   ├── Drawer.php
│   │   ├── Wizard.php · WizardStep
│   │   └── messages: ClosedMsg, AnsweredYesMsg, AnsweredNoMsg
│   ├── Select/
│   │   ├── Select.php
│   │   ├── ComboBox.php
│   │   ├── Dropdown.php
│   │   ├── Radio.php
│   │   ├── DatePicker.php
│   │   ├── ColorPicker.php
│   │   ├── Option.php
│   │   └── OverlayPosition.php   # NEW — auto above/below anchor
│   ├── Toast/
│   │   ├── Toast.php
│   │   ├── Notification.php
│   │   ├── NotificationQueue.php # NEW — dual-ring (items+history)
│   │   ├── Level.php             # info/warning/error
│   │   ├── NoticePosition.php    # NEW — top/bottom/center/anchor
│   │   ├── Hint.php
│   │   └── Tooltip.php
│   ├── Tabs/
│   │   ├── Tabs.php
│   │   ├── TabsVertical.php
│   │   └── TabPane.php           # NEW — single pane wrapper
│   ├── Form/
│   │   ├── Input.php · Textarea · Editor
│   │   ├── Checkbox · Toggle · SwitchComponent
│   │   ├── Slider · Rating · CommandPalette
│   │   └── Cursor
│   ├── Feedback/         # non-modal feedback
│   │   ├── Alert · Skeleton · LoadingText · EmptyState
│   │   └── Spinner
│   ├── Nav/
│   │   ├── Menu · Navbar · Breadcrumb
│   │   ├── Pagination · PaginationSimple
│   │   ├── Stepper · Ladder · Sequence
│   │   └── Scrollbar
│   ├── Card/
│   │   ├── Card · Header · Footer · Profile
│   │   ├── Testimonial · CTA · Jumbotron · Pricing · Cover
│   │   ├── Tag · Badge · BadgeGroup · Chip · ChipGroup
│   │   ├── Stat · Stats · Metric · MetricsGrid
│   │   ├── ActivityFeed · Comment · Leaderboard
│   │   ├── Bullet · Label · Kbd · Code · Highlight · Diff · Markdown
│   │   └── Paragraph · Text · BorderText · BoxDrawing · Divider
│   ├── Media/
│   │   ├── Image · Picture · Audio · Video
│   │   ├── QRCode · Barcode · FigletText · ASCIIBanner
│   │   ├── Marquee · Emoji · Icon · Pictogram · Shadow
│   │   └── AvatarGroup · Avatar
│   ├── System/
│   │   ├── Console · ConsoleEntry · ConsoleStream
│   │   ├── Terminal · Log · LogViewer · HexDump
│   │   └── Clock · Timer · Stopwatch
│   ├── Calendar/
│   │   ├── Calendar
│   │   ├── HeatmapCalendar
│   │   └── ListComponent
│   └── (legacy `Grid` namespace becomes empty — leave only a deprecated alias if needed)
├── Plot/
│   ├── Plot.php          # NEW — line+scatter with braille (Plot from termui)
│   ├── Braille/
│   │   ├── BrailleCanvas.php    # NEW — 2x4 dots/cell, U+2800 offset
│   │   ├── BrailleMatrix.php    # NEW — 4x2 bit table
│   │   └── Bresenham.php        # NEW — integer line plotter
│   ├── Canvas/
│   │   ├── Canvas.php           # existing pixel canvas — keep
│   │   ├── CanvasPoint.php
│   │   └── DrawingOps.php       # extract drawLine/drawCircle/fillRect for re-use
│   ├── Chart/
│   │   ├── Chart · AreaChart · Area · AreaPoint · SparkArea
│   │   ├── Bar · BubbleChart · Bubble · BubblePoint
│   │   ├── CandlestickChart · Candlestick
│   │   ├── Donut · FunnelChart · Funnel
│   │   ├── GaugeChart · GaugeCircle · Gauge · ProgressRing
│   │   ├── HeatmapCalendar · HeatMapChart · Heatmap
│   │   ├── OHLC · OHLCPoint · PartitionSegment · Partition
│   │   ├── RadarChart · Sparkline · SparklineArea · SparklineBar
│   │   ├── Waterfall · WaterfallItem · WaterfallBarType
│   │   ├── DotMatrix · MetricsGrid · WordCloud
│   │   └── helpers: RingBuffer (NEW), GaugeWithDetail
│   ├── Graph/            # node/edge visualizations
│   │   ├── Network · NetworkNode · NetworkShape
│   │   ├── MindMap · OrgChart · ClassDiagram
│   │   ├── Flowchart · FlowchartNode · FlowchartNodeType
│   │   ├── Dendrogram · DendrogramNode
│   │   ├── Sankey · Sunburst · Treemap · TreemapLeaf
│   │   ├── Gantt · PERT · Timeline · TimelineNode · TimelineViz
│   │   └── Graph
│   ├── Meter.php
│   ├── Progress.php · ProgressBar · ProgressList · NProgress
│   └── Bullet · Stepper (if visualisation-only)
├── Module/               # NEW — lattice pattern
│   ├── Module.php             # interface: name/init/update/view/minSize
│   ├── ImagePlacer.php        # optional interface
│   ├── ImagePlacement.php
│   ├── ModuleConfig.php
│   ├── BaseModule.php         # abstract helper with default behavior
│   └── TickEpoch.php          # NEW — Homedash focus-regain epoch
├── Registry/             # NEW
│   ├── Registry.php           # static register/get/list/reset
│   └── Constructor.php        # type alias / closure shape
├── Plugin/               # NEW — lattice JSON protocol
│   ├── Request.php · Response.php
│   ├── PluginSdk.php          # `Run(handler)` loop for plugin authors
│   ├── ExternalModule.php     # wraps a binary into Module interface
│   └── Discovery.php          # scan ~/.config/<app>/plugins/
├── Modules/              # built-in module implementations
│   ├── Clock/ClockModule.php
│   ├── System/SystemModule.php       # CPU/mem/disk via /proc
│   ├── Weather/WeatherModule.php     # HTTP fetcher
│   ├── Uptime/UptimeModule.php
│   ├── Greeting/GreetingModule.php
│   └── Generic/GenericModule.php
├── Events/               # input plumbing
│   ├── Event · EventDispatcher · EventHandler
│   ├── FocusEvent · KeyEvent · MouseEvent
│   ├── PasteEvent · ResizeEvent
│   ├── Key · KeyAction · KeyMap
│   └── Focus
├── Keys/                 # NEW — teautils KeyRegistry
│   ├── KeyRegistry.php        # central source of bindings
│   ├── KeyIdentifier.php
│   ├── KeyMeta.php            # Binding/StatusBar/HelpModal/Category
│   └── Category.php
├── Position/             # NEW — ANSI-aware geometry
│   ├── Center.php             # CalculateCenter, MeasureRenderedView
│   └── Overlay.php            # auto above/below anchor
├── Output/               # NEW — extracted helpers
│   ├── Truncate.php           # extracted from Grid/Layout, StackedGrid, Boxer
│   ├── RenderBar.php
│   └── WrapCells.php
└── State/
    └── (move Grid/State.php here; add atomic persist Save/Load)
```

**Phase 0 deliverables (PR #1):**
- New directory tree per above with all 222 files **moved** (no rewrites)
- `composer.json` autoload remains `SugarCraft\\Dash\\` → `src/` — no change needed; nested namespaces inferred from path
- Every internal `use` updated (mostly relative — most code uses `Grid\Foo` which becomes `Components\Foo` or `Layout\Foo`)
- `tests/` tree mirrors `src/`
- Examples in `examples/` get their `use` lines updated only — no semantic changes
- `README.md` table updated to point at new namespaces
- All `vendor/bin/phpunit` green before push

**Note:** `Boxer.php` rename to `Pad.php` is the only renaming risk — every test/example uses `Boxer::new()`. Grep first, then `replace_all` per file.

### Phase 1 — Foundation primitives (Drawable, Buffer, StyleParser)

Goal: introduce the universal `Drawable` contract and the inline `[text](fg:...)` syntax. Widgets gain a second draw mode (string `render()` and `draw(Buffer)`) — opt-in, existing code unaffected.

**New files:**
- `Foundation/Drawable.php` — interface with `getRect(): Rect; setRect(Rect): void; draw(Buffer): void`. Default impl wraps existing `render()` output for backwards compat.
- `Foundation/Buffer.php` — fixed-size `Cell[][]` grid; `getCell(x,y)`, `setCell(x,y,Cell)`, `fill(rect, Cell)`, `setString(x,y,string,Style)`.
- `Foundation/Cell.php` — `readonly { string $rune; Style $style }`.
- `Foundation/Rect.php` — `readonly { int $minX, $minY, $maxX, $maxY }` with `contains()`, `intersect()`, `dx()`, `dy()`.
- `Foundation/StyleParser.php` — `parse(string, defaultStyle): Cell[]` matching termui state machine (see source above).
- Tests: snapshot for each. Buffer test asserts cell-level mutations. StyleParser tests cover nested `[[x]]`, malformed `[(`, empty, all three tokens (fg/bg/mod), unknown color (silent ignore).

### Phase 2 — Braille canvas + new Plot widget

Goal: 2× horizontal, 4× vertical resolution for any pixel-style widget. New `Plot` widget with `MarkerBraille`/`MarkerDot` toggle.

**New files:**
- `Plot/Braille/BrailleCanvas.php` — port `drawille.go` (lines 7-83). Use `mb_chr(0x2800 + $bits)` to emit braille runes. `setPoint(Point, Color)`, `setLine(Point, Point, Color)` (Bresenham per source lines 55-83).
- `Plot/Braille/BrailleMatrix.php` — 4×2 lookup table as constant.
- `Plot/Plot.php` — port `widgets/plot.go`. `LineChart` + `ScatterPlot` modes. `MarkerBraille` default, `MarkerDot` fallback. `ShowAxes` with auto-tick labels via `verticalScale = maxVal / (innerH - 1)`. `HorizontalScale` for x-stride.
- `Plot/RingBuffer.php` — port Homedash `RingBuffer` (slice-based — fine for PHP `array_shift`/`array_push`; 60 default).
- Refactor `Plot/Chart/Sparkline.php` to use `RingBuffer` + 8-block scaling (`▁▂▃▄▅▆▇█`) per Homedash. Add `withDimEdge(bool)` for left-pad dim block behavior.
- Add `Plot/Chart/GaugeWithDetail` (overlay detail text on bar per Homedash math).
- Tests: snapshot Plot at small fixed sizes; assert exact braille runes by hex bytes (`\x{2800}` family). Catches braille math regressions.

### Phase 3 — Layout engine upgrade (Tile/Resolver + Boxer + RatioGrid + FocusManager)

Goal: replace `Layout::calculateHorizontalSizes` internals with 5-phase resolver; add proper Boxer; add RatioGrid for declarative layouts; add FocusManager.

**Sub-phases shipped as separate PRs if it's too big:**

3a. **Resolver** (`Layout/Tile/Resolver.php`):
- Port `tealayout_resolve.go` 5-phase algorithm verbatim.
- `Resolver::resolveLinear(int $available, Constraint[] $constraints, int $gap, ?SizeHinter[] $hinters, bool $horizontal): int[]`
- Make existing `Layout` use it internally — public API unchanged, just better distribution math.
- Tests: cumulative-rounding verification (sum of returned ≤ available, no zero-pixel drift), optional-removal retry, clamp/freeze.

3b. **Tile primitives** (`Layout/Tile/*`):
- `Tile` interface, `BaseTile`, `Size` struct with `weight`/`min`/`max`/`fixed`/`optional`/`minSizeFit`.
- `TileLayout` as a `Sizer` that holds child Tiles, calls `Resolver`.

3c. **Boxer** (`Layout/Boxer/*`):
- Rename existing `Boxer.php` → `Layout/Pad.php` (full sed across codebase).
- New `Layout/Boxer/Boxer.php` with `LayoutTree: Node`, `ModelMap: array<string, Item>`.
- `createLeaf(string $address, Item $model): Node` — only way to spawn a valid leaf.
- `editLeaf(string $address, callable $fn): void` — auto-save on success.
- `Node::sizeFunc: ?callable` — custom per-node distribution.
- Tests: address uniqueness, evenSplit vs sizeFunc, vertical/horizontal sizing with border accounting.

3d. **RatioGrid** (`Layout/RatioGrid/*`):
- Port `termui/grid.go`. `newCol(float $ratio, Item|GridItem[] $entry)`, `newRow(...)`, `set(...entries)`.
- Recursive `setHelper` multiplies parent ratios.
- Leaf coords: `x = width*XRatio`, `w = width*WidthRatio`.

3e. **FocusManager + StackLayout** (`Layout/FocusManager.php`, `Layout/StackLayout.php`):
- `FocusManager` builds depth-first order, `focusNext()`/`focusPrev()` skips hidden.
- `StackLayout` pushes/pops views, calls `onEnter`/`onExit` lifecycle, caches by key, renders breadcrumb (uses existing `Components/Nav/Breadcrumb.php`).

### Phase 4 — GridTable data grid

Goal: full data grid (sort/filter/page/scroll/freeze) distinct from existing visual tables.

**New files** under `Components/GridTable/`:
- `GridTable.php` — model with `withColumns`, `withRows`, `sort(Column)`, `filter(string)`, `page(int)`, `scrollTo(int)`, `freezeColumns(int)`.
- `Column.php` — `readonly { string $key, string $label, ?int $minWidth, ?int $maxWidth, bool $sortable, bool $filterable, callable $renderer }`.
- `Row.php`, `Cell.php`.
- `BorderConfig.php` — region-based (Outer/Header/Inner/Footer each toggleable + style). Per `teagrid_border.go`.
- `BorderChars.php` — `Default` (heavy ━┃┏), `Rounded` (─│╭), `Borderless`, `Minimal` presets.
- `Sort.php`, `Filter.php`, `Pagination.php`, `Scrolling.php`, `Calc.php` (gcd for col-width reduction).
- KeyMap (sort=`s`, filter=`/`, page-up/down, etc).
- Tests: snapshot 120×40 and 80×24 (per teagrid's own golden tests), sort flip, filter narrow, page boundaries, freeze column rendering.

### Phase 5 — Module + Registry + Plugin + built-in Modules

Goal: extensibility surface. Anyone can author a module (PHP class) or plugin (any executable).

**New files:**
- `Module/Module.php` — interface (name/init/update/view/minSize).
- `Module/BaseModule.php` — abstract with default `init() = null`, `minSize() = [30, 4]`.
- `Module/TickEpoch.php` — `uint64`-style counter, `bump()` on focus regain, `isStale($received)` for discarding old ticks.
- `Module/ImagePlacer.php` — optional `imagePlacements(): ImagePlacement[]`.
- `Registry/Registry.php` — static `register(name, ctor)`, `get(name)`, `list()`, `reset()` (for tests). Single panic on duplicate.
- `Plugin/Request.php` + `Plugin/Response.php` — typed DTOs matching lattice JSON shape.
- `Plugin/PluginSdk.php` — `run(callable $handler): never` — reads stdin line-by-line, dispatches to handler, writes JSON to stdout. For PHP plugin authors.
- `Plugin/ExternalModule.php` — wraps a binary into `Module`. Spawn via `proc_open`, json-encode on stdin, json-decode on stdout, schedule next `update` via `Interval`.
- `Plugin/Discovery.php` — scan `$XDG_CONFIG_HOME/sugar-dash/plugins/` for executable files.
- `Modules/Clock/ClockModule.php` — single-line clock with optional date+timezone.
- `Modules/System/SystemModule.php` — `/proc/cpuinfo`, `/proc/meminfo`, `/proc/mounts`, sparkline history. Wraps responsive-panel pattern.
- `Modules/Weather/WeatherModule.php` — HTTP fetch (wttr.in or open-meteo); fall back to cached value on error.
- `Modules/Uptime/UptimeModule.php` — `/proc/uptime`.
- `Modules/Greeting/GreetingModule.php` — time-of-day-based static text.
- `Modules/Generic/GenericModule.php` — runs an arbitrary shell command on `Interval`, displays stdout.

### Phase 6 — Components feature parity (Modal/Select/Toast/StatusBar/Tree)

Goal: bring component variants up to teamodal/teafields/teanotify/teastatus/teatree feature levels.

- **Modal**: add `ConfirmModal::ok(text)`, `::yesNo(text)`, `ListModal` (selectable list with edit/delete keymaps), `ProgressModal`, `MultiselectModal`. Messages: `Modal\Msg\ClosedMsg`, `AnsweredYesMsg`, `AnsweredNoMsg`, `AnsweredEditMsg(item)`, `AnsweredDeleteMsg(item)`. Overlay-modal pattern (render over existing content via Buffer composite).
- **Select**: add `OverlayPosition` (auto above/below anchor based on available space, per teafields `position.go`).
- **Toast**: replace single-shot `Notification.php` with `NotificationQueue` (dual-ring per Homedash); add `NoticePosition::Top/Bottom/Center/Anchor` + alert-panel renderer (top 5 from history).
- **StatusBar**: add `MenuItem` left-zone + `StatusIndicator` right-zone composition; `SeparatorKind` enum (Space/Pipe/Dot/None).
- **Tree**: add `Provider` interface (lazy `children(node): Node[]`) + `DrilldownTree` that uses `StackLayout` for child-view navigation.

### Phase 7 — Tests + VHS demos + docs

Goal: catch up on test coverage for everything new, plus VHS demos.

- **Snapshot tests** for every new widget at fixed 80×24 and 120×40 — store goldens under `tests/golden/`.
- **Behavior tests** — drive `update()` with scripted `KeyEvent`/`MouseEvent`, assert on `[model, ?cmd]` return.
- **Plot tests** — assert exact braille bytes for known geometry (line from (0,0) to (10,10) at 4×10 cell area).
- **Resolver tests** — port tealayout's `resolve_test.go` cases verbatim.
- **Plugin tests** — spawn a `tests/fixtures/echo-plugin.sh` that round-trips request → response; assert ExternalModule wires up correctly. Use stream-write pattern (`ftell`/`fseek`/`stream_get_contents`) per AGENTS.md.
- **VHS demos**:
  - `.vhs/dashboard-modules.tape` — Clock + System + Weather modules side-by-side.
  - `.vhs/plot-braille.tape` — same data rendered with `MarkerDot` then `MarkerBraille`.
  - `.vhs/gridtable.tape` — sort + filter + page cycle.
  - `.vhs/boxer.tape` — split panel with three named leaves, focus rotation.
  - Add all to `.github/workflows/vhs.yml` `all=(...)` matrix.
- **Docs**:
  - Update `sugar-dash/README.md` with the new namespace table.
  - `docs/lib/sugar-dash.md` — link to docs.
  - Update root `MATCHUPS.md`, `PROJECT_NAMES.md`, root `README.md`, root `docs/index.html` tile.

---

## Technical debt to fix during reorganization

Per `sugar-dash/CALIBER_LEARNINGS.md` (35 entries documented), the following concrete bugs/anti-patterns should be addressed during the appropriate phase rather than after. Each fix below cites the CALIBER entry it resolves so reviewers can verify against the learnings file.

| # | Bug | Phase | Notes |
|---|-----|-------|-------|
| TD-1 | `readonly` constructor-promoted properties + clone-mutate withers fail at runtime | Phase 0 | Convert affected classes to private non-readonly with public accessors; or use named-constructor `new self(...)` instead of `clone $this; $this->x = ...`. Audit every `final class` with `withFoo()` methods first. |
| TD-2 | Dual-state collections (Console/Log keep filtered + unfiltered arrays) — withers must update BOTH | Phase 0 / Components/System | Add `private function rebuildFiltered()` helper called from every wither. |
| TD-3 | Chart size defaults `?? 20` literals clip data when collection exceeds default | Phase 2 (charts move) | Replace with `$width ?? max(20, count($data))` or thread explicit width through. |
| TD-4 | Treemap cell gap `+$cellWidth + 1` overflows and drops cells | Phase 2 / Plot/Graph/Treemap | Track cumulative width via running total instead of per-cell `+1`. |
| TD-5 | `str_pad` byte-counts — ANSI-prefixed lines get under-padded | Phase 0 / Output/Truncate | Replace every `str_pad` on styled strings with `SugarCraft\Core\Util\Width::string()`-aware padder; extract to `Output/PadAnsi.php`. |
| TD-6 | Inline secondary classes break PSR-4 | Phase 0 reorg | Auto-detectable: `grep -nE '^(final )?class ' src/Grid/*.php \| awk -F: '$3 != ""'` — each file with >1 class needs splitting. |
| TD-7 | OHLC chart loop `chartHeight × pointCount` rows instead of `chartHeight` | Phase 2 / Plot/Chart/OHLC | Fix outer loop bound; add regression test for an OHLC at 50×20 that previously rendered hundreds of rows. |
| TD-8 | Y-axis label inversion (grid top-down but labels bottom-up) | Phase 2 / Plot | Flip the label loop or invert tick-index math; assert via snapshot at `5x10` cells. |

Address these inline rather than batching them — each fix lands in the same PR that touches its file. Don't create a "technical debt" PR with all 8.

## Cross-cutting algorithms & utilities (used across multiple phases)

These primitives are shared by several phases and live under `src/Util/` or `src/Output/`. Build them once, in the earliest phase that needs them, and reuse.

### Indexed RingBuffer (true ring, not slice-shift)

Homedash uses a slice-shift implementation (`$data = $data[1:]` on push) — O(n) per push. For sparklines polling at 1Hz this is fine, but for 100Hz event streams the slice-shift dominates. Use an indexed ring:

```php
final class RingBuffer {
    /** @var float[]|null[] */
    private array $data;
    private int $size;
    private int $index = 0;
    private int $count = 0;

    public function __construct(int $size = 60) {
        $this->size = $size;
        $this->data = array_fill(0, $size, null);
    }

    public function push(float $value): void {
        $this->data[$this->index] = $value;
        $this->index = ($this->index + 1) % $this->size;
        $this->count = min($this->count + 1, $this->size);
    }

    /** Returns values in chronological order (oldest first). */
    public function toArray(): array {
        if ($this->count < $this->size) {
            return array_slice($this->data, 0, $this->count);
        }
        return array_merge(
            array_slice($this->data, $this->index),
            array_slice($this->data, 0, $this->index),
        );
    }
}
```

O(1) push, O(n) to-array. Lives at `src/Plot/RingBuffer.php` (Phase 2 ships first).

### SemaphorePool (parallel-ops cap)

For modules that fan out (Docker stats across N containers, HTTP fetches across N upstream services), cap concurrency at 5 workers to avoid socket exhaustion. ReactPHP-friendly via `\React\Promise`. Lives at `src/Util/SemaphorePool.php` (Phase 5).

### LAB color interpolation for fade animations

teanotify uses `back.BlendLab(fore, step)` for toast fade-in/fade-out. PHP needs a CIELAB color-space converter (sRGB → XYZ → LAB → interpolate → back). candy-core likely has the math already — check `SugarCraft\Core\Util\Color` for `blendLab(Color $target, float $t): Color`. If missing, port from teanotify's color helpers. Used by Phase 6 Toast.

### 9-position Notice anchoring

```php
enum NoticePosition {
    case TopLeft;    case TopCenter;    case TopRight;
    case MiddleLeft; case Center;       case MiddleRight;
    case BottomLeft; case BottomCenter; case BottomRight;
}
```

Plus an `Anchor(int $x, int $y)` mode for tooltip-style overlays. Position calculator (`Position/Overlay.php`) decides above-vs-below the anchor based on remaining viewport space (per teafields `position.go`).

## Test coverage targets per phase

Codecov flag `sugar-dash` already exists in root `codecov.yml`. Add per-component component flags during Phase 0 so coverage can be tracked sub-package-by-sub-package:

| Subpackage | Target | Test types |
|------------|--------|------------|
| `Foundation/` | 90% | Unit (snapshot + behavior + coercion) |
| `Layout/` (all sub-namespaces) | 85% | Unit + integration (multi-pane scenarios) |
| `Components/GridTable/` | 80% | Snapshot at 80×24 + 120×40; sort/filter/page behavior |
| `Components/Modal,Select,Toast,Tabs,StatusBar,Tree` | 80% | Snapshot + behavior with scripted KeyEvents |
| `Plot/` (Braille especially) | 85% | Exact-byte assertion for known braille geometry |
| `Module/Registry/Plugin/` | 85% | Mock plugin fixture |
| `Theme/Keys/Position/Output/` | 90% | Unit |

Coverage measured locally with pcov per CLAUDE.md instructions.

## Risks & open questions

1. **`Boxer` rename collision.** Every test/example uses `Boxer::new()`. Plan to grep first (`rg -l 'Grid\\Boxer'`), rename across files in one PR titled `sugar-dash: rename current Boxer → Pad`. Keep a deprecated alias `class Boxer extends Pad {}` for one release if external users could exist (unlikely pre-1.0, but cheap).
2. **Drawable mode interop.** Existing widgets return strings via `render()`. New Drawable-mode widgets target `Buffer`. Need a `BufferRenderer` adapter that runs old widgets and pastes their output into a Buffer region — that's the bridge during the transition. Don't force-port everything at once.
3. **5-phase resolver vs. existing Layout flex.** Current `Layout::calculateHorizontalSizes` callers may rely on current rounding behavior (off-by-one pixel here and there). Plan: snapshot tests before swap → swap → diff snapshots → adjust thresholds. The 5-phase is strictly more correct but may shift edges by ±1px on small widths.
4. **PHP perf on large Buffers.** 200×60 cell × 5KB style per cell = 6MB. Use `SplFixedArray` for `Cell[][]` and intern Styles (Flyweight) — most cells share defaults.
5. **Sub-agents are ONE-AT-A-TIME** per CALIBER_LEARNINGS — Phase 0's file moves can't be parallelized across sub-agents because they all touch `composer.json` autoload + a handful of shared `use` blocks. Sequence them: Foundation moves → Layout moves → Components moves → Plot moves → docs.
6. **`sashakoshka/termui` upstream is GPLv3.** Sugar-dash is MIT. Re-implement from the doc comments + algorithm description, **not** by copying lines. The math (BRAILLE table, BRAILLE_OFFSET, cell = (X/2, Y/4)) is not copyrightable. The state machine for ParseStyles needs a clean-room rewrite.
7. **VHS demos** are hand-maintained per AGENTS.md — every new demo must land in `.github/workflows/vhs.yml`'s `all=(...)` array or the GIF never re-renders.
8. **Backward compatibility — DO NOT BUILD.** The other AI's plan suggested a `Grid\` alias namespace pointing at new locations during a deprecation window. Sugar-dash is pre-1.0 and per `feedback_audit_skip_credit_upgrade.md` the project skips upgrade-guide work pre-1.0. Just move files, update tests/examples in the same commit. If a downstream user has pinned `dev-master` they'll get the new namespaces atomically — that's fine for path-repo siblings.
9. **Theme preset names** — standardize on `Theme::dark()`, `Theme::light()`, `Theme::adaptive()`, `Theme::default()` factories (matches teautils + Homedash). `adaptive()` reads `$_ENV['COLORFGBG']` to choose between dark/light. Document in Phase 0 `Theme.php` move.

---

---

## Guiding principles (project priorities)

These are the unconditional north-star priorities for sugar-dash. Every phase must move all three forward. If a design choice trades one against another, document the trade-off in the PR body.

### 1. Breadth — ship as many components and features as possible

Sugar-dash already carries 222 PHP files. The plan ADDS more (GridTable, BraillePlot, ConfirmModal/ListModal/MultiselectModal/ProgressModal, DrilldownTree, ExternalModule, Module variants, FocusManager, StackLayout, etc.). The reorganization is NOT a scope-cut — it's a clean shelf so we can keep adding without drowning.

- Every phase MUST end with strictly more public surface than it started with. No "trim down to MVP" rationalizations.
- When porting a reference repo, port **every** feature it surfaces — Confirm AND List AND Multiselect AND Progress modals, not just Confirm. Wide is the goal.
- After Phase 6, scan the 7 upstream repos one more time for anything we missed (especially go-tealeaves which has 8 packages worth of patterns). Open follow-up PRs for stragglers — don't gate on completing them before shipping the core.
- The original 18-package plan is a FLOOR, not a ceiling. The current src/ has ~30 sub-families already (Layout / Charts / Graphs / Forms / Feedback / Media / Cards / Nav / System / Tables / Decoration / Events). All survive the reorg. Extra welcome.

### 2. Performance — fast renders, no avoidable allocations

TUIs feel laggy fast. PHP is not free. Bake speed in from Phase 0:

- **No O(n²) renders.** Every widget's `render()`/`draw()` must be O(width × height) or better. Audit existing widgets during the move — TD-4 (Treemap), TD-7 (OHLC) are the known offenders; there will be more.
- **Intern styles.** Most cells share the default style. Use a Flyweight: cache `Style` instances by `(fg, bg, modifier)` triple. Saves ~80% memory on large buffers.
- **`SplFixedArray` for Buffer cells.** A 200×60 cell grid as nested PHP arrays = 12+ MB hash overhead. `SplFixedArray` is ~3MB.
- **Render diffing.** Track dirty rects per widget so unchanged regions don't re-emit ANSI. Even a naive "full redraw" cache (compare current frame to previous, emit only changed cells with cursor-position escapes) is a 5-10× speedup on slow-changing dashboards.
- **Indexed RingBuffer** (above) — O(1) push beats Homedash's O(n) slice-shift.
- **5-phase Resolver** (Phase 3a) — converges in O(N) cumulative-rounding rather than tilelayout's up-to-100-iteration scheme.
- **Lazy size hinting.** Don't call `render()` to measure children — implement a `SizeHinter` interface that returns `(min, desired)` without rendering. Most widgets know their natural size from input data without producing the styled string.
- **Avoid `array_map`-clone-everything in withers.** The current `Canvas::setPixel` (`src/Grid/Canvas.php:121-137`) does `array_map(fn($row) => [...$row], $this->pixels)` — full O(width × height) copy on every pixel set. For drawing routines that call setPixel in a loop (drawLine, drawCircle, fillRect), this is O(w²h²). Switch to mutable internal state with a `seal()` boundary, or use copy-on-write with structural sharing.
- **Benchmark every PR.** Add `tests/Benchmark/<Subpkg>Bench.php` running each public surface 1000× and asserting < N microseconds per call. Fail CI on regression > 20%. (Hook via `composer bench` script.)
- **Profile before optimizing.** XHProf or Spx output checked into `.benchmarks/` for major widgets — Plot, BrailleCanvas, GridTable, Boxer. Measure twice.

### 3. Quality — works "nice"

Wide + fast is worthless if it's buggy. Quality bar:

- Every public method has a test. Every visual widget has a snapshot at 80×24 AND 120×40. Every interactive widget has scripted-input behavior tests.
- Edge cases tested: zero-width, zero-height, single-char, multi-byte unicode, embedded ANSI, RTL text, emoji, narrow + wide East Asian.
- VHS demos for everything (Phase 7) — visual regression is a real risk; the GIF in the README catches "looks right" issues that unit tests miss.
- Docblocks on everything. The reader of `sugar-dash/src/Plot/Braille/BrailleCanvas.php` should not need to read drawille.go to understand the bit-packing — explain it in `/** ... */` above the constants.

## Cross-cutting requirements (apply to EVERY phase)

These are non-negotiable. They apply to every PR in this plan, not just one phase.

### Code quality

- **Extensive docblocks** on every public class, method, property, and constant. Include:
  - One-line summary.
  - `@param` / `@return` / `@throws` with types and meaning.
  - `Mirrors <upstream-repo>/<file>:<line>` citing the source pattern when porting from a reference repo.
  - Non-obvious invariants explained inline (e.g. "address is private so it can only be set via createLeaf — see bubbleboxer rationale").
- **Inline comments** ONLY for the *why*: hidden constraints, math derivations (especially the braille bit-packing), workarounds for upstream issues, links to GitHub issues. No "increment counter" tier prose — see `CLAUDE.md` Conventions.
- Every file: `declare(strict_types=1);`, PSR-12, PSR-4, `final` unless extension is part of the contract, immutable + fluent withers, public `readonly` properties for state.

### Testing

- **Every public method gets at least one test.** PHPUnit 10, namespace `SugarCraft\Dash\Tests\<Subpkg>\`, mirror `src/` tree.
- Three styles per AGENTS.md:
  - **Snapshot** — assert exact byte string (ANSI SGR `\x1b[1m...` included). Store goldens under `tests/golden/<phase>/`.
  - **Behavior** — drive `update()` with scripted `KeyEvent`/`MouseEvent`, assert on returned `[Model, ?Cmd]` tuple.
  - **Coercion** — feed edge inputs (negative/oversized index, empty, null, multi-byte unicode) — assert clamping or no-op matching upstream.
- **Stream-write pattern** — when tests capture output via `php://memory`, use `ftell`/`fseek`/`stream_get_contents` (per AGENTS.md gotcha); never `ftruncate` + `rewind` between writes.
- **Plot / Braille tests** — assert exact braille bytes (`mb_chr(0x2800 + bits)`) for known geometry (e.g. line `(0,0)→(7,15)` on a 4×4-cell area).
- **Plugin tests** — fixture executable at `tests/fixtures/echo-plugin.sh`. Verify init/update/view request → response round-trip.
- `vendor/bin/phpunit` must be GREEN before every commit, every PR.
- Aim for ≥85% coverage per Codecov flag (`sugar-dash` flag in `codecov.yml`).

### Documentation per phase

Each phase MUST land four kinds of docs:

1. **`sugar-dash/README.md`** — extend the per-subpackage section. New widgets get a code snippet (`composer require ...` then a minimal example) AND a link to the rendered `.vhs/<demo>.gif`.
2. **`sugar-dash/examples/<feature>.php`** — runnable demo. One example per new public surface (not one per class — group related). Must work standalone with `php examples/<demo>.php`.
3. **`sugar-dash/.vhs/<demo>.tape`** — VHS recording driving the example. Standard preamble: `Set Theme "TokyoNight"`, dimensions matching the example, `Type "php examples/<demo>.php"`, `Enter`, `Sleep 2s`, optional keystrokes. Rendered GIF lands at `https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-dash/.vhs/<demo>.gif`.
4. **`.github/workflows/vhs.yml`** — add the new tape name to the hand-maintained `all=(...)` matrix or the GIF will never re-render (see AGENTS.md gotcha).
5. **`docs/lib/sugar-dash.md` + `docs/index.html` tile** — extend the lib's docs page with new sections, link to each `.vhs/<demo>.gif` and to the relevant source files. The homepage tile description gets a brief mention of the new capability.

### Caliber sync before every commit

Per `AGENTS.md`:
1. Check hook: `grep -q "caliber" .git/hooks/pre-commit 2>/dev/null && echo "hook-active" || echo "no-hook"`.
2. **hook-active**: commit normally, hook syncs automatically.
3. **no-hook**: run `caliber refresh && git add CLAUDE.md .claude/ .cursor/ .cursorrules .github/copilot-instructions.md .github/instructions/ AGENTS.md CALIBER_LEARNINGS.md .agents/ .opencode/ 2>/dev/null`, then commit.

---

## Ship-as-you-go workflow — execute literally for every phase

This is the exact sequence to run between phases. NO pause for user prompts — finish a phase, ship it, move on.

```bash
# 1. Make sure you're on master and current
git checkout master && git pull --ff-only

# 2. Branch
git checkout -b ai/sugar-dash-phase-NN-<slug>

# 3. Do the work (edits, new files, tests, docs, .vhs tapes)

# 4. Verify
cd sugar-dash && composer install --quiet && vendor/bin/phpunit
cd ..

# 5. Caliber sync (if hook missing)
grep -q "caliber" .git/hooks/pre-commit || caliber refresh
git add <touched files>  # be explicit — never `git add -A`

# 6. Commit as Joe Huss (NEVER skip hooks, NEVER use --no-verify)
git commit -m "$(cat <<'EOF'
sugar-dash: phase NN — <one-line summary>

<2-4 sentence body describing what changed and why.>

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)" --author="Joe Huss <detain@interserver.net>"

# 7. Push
git push -u origin ai/sugar-dash-phase-NN-<slug>

# 8. Open PR — GITHUB_TOKEN must be UNSET for gh to use the user's auth
unset GITHUB_TOKEN
gh pr create --title "sugar-dash: phase NN — <summary>" --body "$(cat <<'EOF'
## Summary
- <bullet 1>
- <bullet 2>

## Test plan
- [x] vendor/bin/phpunit green (NNN tests, MMM assertions)
- [x] Snapshot diffs reviewed under tests/golden/phase-NN/
- [x] examples/<demo>.php runs cleanly (manual TUI check)
- [x] .vhs/<demo>.tape added to .github/workflows/vhs.yml matrix
- [x] sugar-dash/README.md + docs/lib/sugar-dash.md updated

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"

# 9. Merge (assumes CI passed; if not, fix and force-push the branch — NEVER force-push master)
PR_NUM=$(gh pr view --json number -q .number)
gh pr merge $PR_NUM --merge --delete-branch

# 10. Back to master, pull, prune local branch ref
git checkout master
git pull --ff-only
git branch -D ai/sugar-dash-phase-NN-<slug> 2>/dev/null || true

# 11. Immediately start next phase — DO NOT WAIT for user confirmation
```

**Key rules baked into the workflow above:**
- ✅ `unset GITHUB_TOKEN` BEFORE every `gh` call — otherwise `gh` uses the project bot token and `gh pr create` 401s (see CLAUDE.md PR workflow).
- ✅ Author commits as `Joe Huss <detain@interserver.net>` via `--author=` flag — required even when AI-driven (see AGENTS.md).
- ✅ Use HEREDOCs for commit + PR bodies (formatting).
- ✅ After merging, `git checkout master && git pull --ff-only` THEN `git branch -D` to remove the local stale ref.
- ✅ Never `git add -A` or `git add .` — only stage files you touched (CLAUDE.md security rule).
- ✅ Never `--no-verify` / `--no-gpg-sign` to bypass hooks (CLAUDE.md).
- ✅ Don't push to master directly. Don't force-push master. Don't amend pushed commits.
- ✅ Move directly to the next phase after merge — no pause, no confirmation prompt.

### Phase order (no breaks between)

```
0 → 1 → 2 → 3a → 3b → 3c → 3d → 3e → 4 → 5 → 6 → 7
```

Each phase number above maps to ONE PR. Phase 3 is split into 5 sub-PRs (3a–3e) because the layout-engine swap is too big to land atomically.
