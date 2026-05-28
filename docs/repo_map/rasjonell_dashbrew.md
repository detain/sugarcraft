# rasjonell/dashbrew

## Metadata
- **URL:** https://github.com/rasjonell/dashbrew
- **Language:** Go (1.23.6)
- **Stars:** ~1.4k (star-history.com chart reference only; exact count not accessible via GitHub unauthenticated API)
- **License:** MIT
- **Description:** A terminal dashboard builder that visualizes data from scripts and APIs via a JSON configuration file, built on the Charmbracelet TUI ecosystem.

## Feature List
- **JSON-driven dashboard configuration** — Declarative `dashboard.json` defines layout tree (container rows/columns + leaf components) and per-component styling
- **Layout system** — Recursive row/column flexbox-style containers with configurable `flex` weights and `gap` spacing; aspect-ratio reservation for even/odd terminal dimensions
- **6 component types:**
  - `text` — Scrollable viewport of raw text output from script or API
  - `list` — Filterable list of items from script output (newline-separated) or JSON array
  - `todo` — Persistent todo list backed by a plain-text `+`/`-` file (add/toggle/delete via keyboard)
  - `chart` — ASCII line/area chart from newline-separated floats or JSON number array, using `guptarohit/asciigraph`; supports `append` refresh mode for streaming data
  - `histogram` — ASCII bar histogram from `key: count` lines or JSON object/array, with 4 bar color modes (`single`/`alternate`/`gradient`/`map`)
  - `table` — Paginated table from JSON array of objects or string arrays, with configurable column flex widths
- **Data sources** — `script` (shell command via `exec.Command`), `api` (HTTP GET with optional JSONPath extraction via `oliveagle/jsonpath`), and file-based for todos
- **Component bindings** — Arbitrary key chords mapped to shell actions; two action types: `fire_and_forget` (background, no output) and `replace_pane` (captures stdout, sticky display); `output_as: inherit` re-parses stdout through the component's native parser
- **Action palette** — `Ctrl+K` global fuzzy-filtered list of every binding across all components
- **Keyboard navigation** — Arrow keys + vim-style `H/J/K/L` move focus between components; bounding-box + overlap algorithm computes neighbor relations dynamically on resize
- **Click-to-focus** — Mouse click anywhere in a component's bounding box focuses it
- **Refresh scheduling** — Per-component `refresh_interval` (seconds) via `time.Sleep` + `tea.Cmd` timer chain; sticky actions pause refresh until Reset
- **7 embedded themes** — `default`, `dracula`, `nord`, `gruvbox-dark`, `tokyo-night`, `catppuccin-mocha`, `solarized-light`; custom themes loaded from `$DASHBREW_THEMES_DIR` or `$XDG_CONFIG_HOME/dashbrew/themes`; `theme: auto` detects terminal background via OSC 11 / `muesli/termenv`
- **Per-component style overrides** — Palette and border type can be overridden at the component level, layered on top of the global theme
- **Focus mode** — `focusMode: highlight` dims non-focused panes via lipgloss `Faint(true)`
- **Title alignment** — Component titles can be `left`/`center`/`right` anchored, with border sides matching alignment
- **Error modal** — Shows command, exit code, stderr when an action times out or exits non-zero
- **Async init** — Initial data fetch + refresh scheduling runs in parallel via `tea.Batch` on startup

## Key Classes and Methods

### `cmd/dashbrew/main.go`
- `main()` — CLI entry: parses `-c` (config path), `-t/--theme` (theme override), `--list-themes`; constructs `tea.NewProgram(tui.New(cfg), tea.WithAltScreen(), tea.WithMouseCellMotion())`

### `internal/config/config.go`
- `LoadConfig(path string)` — Reads JSON, unmarshals, fills defaults (global/border), calls `resolvePalette()`, returns `*DashboardConfig`
- `DashboardConfig` struct — Top-level: `Layout *LayoutNode`, `Style *StyleConfig`
- `LayoutNode` struct — `Type` (`container`|`component`), `Flex`, `Direction` (`row`|`column`), `Children []*LayoutNode`, `Component *Component`
- `Component` struct — `Type`, `Title`, `TitleAlign`, `Icon`, `Data *DataConfig`, `ID`, `Bindings []Binding`, `Style *ComponentStyleConfig`
- `EffectiveStyles(global, comp)` — Clones global `StyleConfig`, merges per-component palette/border overrides; used by every component constructor

### `internal/config/themes.go`
- `resolvePalette()` — 4-level merge: (1) `default` theme, (2) named `style.theme`, (3) explicit `style.palette`, (4) legacy `style.border.color/focusedColor`; writes back into `cfg.Style.Palette`
- `LookupTheme(name)`, `AvailableThemes()`, `OverrideTheme(cfg, name)`, `loadCustomThemesOnce()` — Theme registry with disk-scan for custom themes

### `internal/config/validate.go`
- `Validate()` — Walks layout tree, validates each component; checks reserved key conflicts, internal key conflicts per component type, action type validity, `output_as` constraints, timeout non-negativity
- `NormalizeKey(s string)` — Canonicalizes key chords (ctrl/alt/shift ordering, `space` alias expansion)

### `internal/components/components.go`
- `Component` interface — `ID()`, `Type()`, `IsFocusable()`, `SupportsAdd()`, `GetAddInput()`, `SupportsRefresh()`, `Config()`, `Bindings()`, `ActionActive()`, `ActionLoading()`, `BeginAction()`, `ApplyActionResult()`, `ResetAction()`, `Init()`, `View(w, h, focused)`, `Update(msg)`, `SetContent(result)`, `HandleAddMode(msg)`
- `NewComponent(cfg, styles)` — Factory: switches on `cfg.Type` to construct `*textComponent`, `*listComponent`, `*todoComponent`, `*chartComponent`, `*tableComponent`, `*histogramComponent`
- `baseComponent` struct — Embeds `id`, `config`, `styles`, `action actionState`; default implementations for all interface methods
- `actionState` struct — `active`, `loading`, `output`, `label`, `outputAs`, `pendingID` (atomic per-action token for late-result discard), `spinner`, `viewport`
- `renderActionOverlay(b, w, h, focused)` — Renders loading spinner or sticky scrollable text overlay with scroll `%` footer; pointer receiver intentionally mutates viewport for re-wrap on resize
- `tickActionSpinner(b, msg)`, `updateActionViewport(b, msg)` — Fan-out helpers that components call from their `Update` to handle spinner ticks and action viewport scrolling without deduplication

### `internal/components/text.go`
- `TextComponent` struct — Embeds `baseComponent` + `viewport bubbles/viewport.Model` + `content string`
- `View()` — Renders header + scrollable viewport with content wrapped to pane width + optional footer showing scroll `%` + caption
- `SetContent(result)` — Stores content string; resets viewport to top

### `internal/components/chart.go`
- `ChartComponent` struct — Embeds `baseComponent` + `plotData []float64`
- `View()` — Delegates to `asciigraph.Plot()` with ANSI-256 colors converted from theme palette via `HexToAnsi256()`; reserves 2 rows below header for chart height
- `SetContent(result)` — Parses raw output as JSON number array OR newline-separated floats; supports `refresh_mode: append` for streaming data
- `parseDataToChartPoints(rawData)` — Tries `json.Unmarshal` first, then falls back to per-line `strconv.ParseFloat`

### `internal/components/histogram.go`
- `HistogramComponent` struct — Embeds `baseComponent` + `maxValue int`, `content string`, `labels []string`, `bins map[string]int`, `viewport`
- `View()` — Conditionally renders caption inline vs in footer based on whether content overflows available height (avoids first-frame miscalculation from stale viewport dimensions)
- `SetContent(result)` — Parses via `parseDataToHistogram`; sorts labels alphabetically
- `parseDataToHistogram(rawData)` — Tries JSON object (`{key: number}`) or JSON array first; then parses `key: value` newlines or bare lines (increments count)
- `renderHistogram(w, withFooter)` — Renders `█`-bar lines with label, count, and color-coded bars; bar color via `barColor(key, count, idx)` with 4 modes
- `barColor()` — `single` (accent), `alternate` (accent/dim alternation), `gradient` (LAB interpolation dim→accent based on value/maxValue), `map` (per-bin hex override)

### `internal/components/todo.go`
- `TodoComponent` struct — Embeds `baseComponent` + `addInput string`, `list bubbles/list.Model`, `items []*data.TodoOutput`
- `TodoListItem` struct — Implements `list.Item` with `Title()` returning `✓ + strikethrough` if done, raw text for filtering
- `View()` — Delegates to `c.list.View()` with width/height set from pane dimensions
- `HandleAddMode(msg)` — Processes `Esc` (cancel), `Backspace` (delete char), `Enter` (submit new todo), `KeyRunes`/`KeySpace` (append input)
- `writeTodos()` — Serializes items back to plain-text file (`+`/`-` prefix) via `data.WriteTodoFile`

### `internal/components/list.go`
- `ListComponent` struct — Embeds `baseComponent` + `list bubbles/list.Model`
- `View()`, `Update()`, `SetContent()` — Standard component lifecycle; `parseDataToListItems` routes `script` (newline split) vs `api` (JSON array) sources differently

### `internal/components/table.go`
- `TableComponent` struct — Embeds `baseComponent` + `table bubbles/table.Model`, `cols []table.Column`
- `parseDataToTableRows()` — Handles `[][]string` arrays and `[]map[string]any` (object arrays with field names mapped to column configs)
- `getTableColumns()` — Distributes available width across columns proportional to `flex` weights
- `getTableStyles()` — Builds `table.Styles` from theme accent + border settings

### `internal/components/utils.go`
- `ComponentId(comp)` — Returns `comp.ID` if non-empty, else `fmt.Sprintf("%p", comp)` (pointer address as implicit ID)
- `CalcWidthHeight(w, h)` — Subtracts `2*borderSize` from each dimension, clamping to minimum 1
- `GetFlex(flex)` — Returns 1 if `flex <= 0`, else `flex`

### `internal/components/styles.go`
- `GetBorderStyle(borderStyles)` — Maps `borderStyles.Type` string to `lipgloss.Border` constants (`rounded`, `thicc`, `double`, `hidden`, `normal`, `md`, `ascii`, `block`); returns `(normal, focused, border)`
- `HexToAnsi256(hex, fallback)` — Maps hex → ANSI-256 6×6×6 cube + 16 base (16+36*r+6*g+b), for `asciigraph` which不接受 truecolor
- `blendHexColors(a, b, t, fallback)` — LAB-space linear interpolation between two hex colors; used by histogram `gradient` mode
- `PaletteColor(styles, role, fallback)` / `PaletteHex(styles, role, fallback)` — Look up role-named palette hex strings (`background`, `foreground`, `dim`, `accent`, `success`, `warning`, `error`, `border`, `borderFocused`)
- `BrightenColor(color, percent)` — Brightens ANSI or hex colors by a percentage factor; handles 3-digit hex (`#RGB`), 6-digit, and named basic colors

### `internal/data/data.go`
- `FetchOutput` interface — `Error()`, `Output()`; implemented by `*fetchOutput`
- `RunScript(command)` — `exec.Command("sh", "-c", command)` → combined output
- `RunAPI(url, jsonPath)` — HTTP GET with 5s timeout; optionally extracts via `jsonpath.JsonPathLookup`; marshals result back to indented JSON
- `ReadTodoFile(path)` — Parses plain text: lines starting with `+` are done, `-` are undone; returns `[]*TodoOutput`
- `WriteTodoFile(path, items)` — Inverse of ReadTodoFile; serializes `[]*TodoOutput` back to `+`/`-` prefixed lines

### `internal/tui/tui.go`
- `model` struct — `cfg`, `width/height`, `ready`, `isAdding`, `initialized`, `focusedComponentId`, `components map[string]Component`, `componentBoxes`, `navMap`, `errorModal`, `palette`
- `Init()` — Calls `buildComponentMap`, finds first component, runs initial `fetchAllData()`, schedules refreshes, sends `tea.ClearScreen`
- `Update(msg)` — Large switch on `tea.Msg` type: handles `fetchResultMsg` (avoids clobbering if action active), `refreshMsg` (reschedules + refetches unless action active), `actionResultMsg` (routes to `SetContent` for `output_as=inherit`, else `ApplyActionResult`), `actionFailedMsg` (resets action + shows error modal), `spinner.TickMsg` (fan-out to all loading components), `MouseMsg` (click-to-focus), `KeyMsg` (navigation, palette, add mode, binding dispatch, component-internal)
- `View()` — Returns `renderErrorModal()` or `renderPalette()` or `renderNode(layout, width, height, focusedComponentId)` + help line

### `internal/tui/renderer.go`
- `renderNode(node, width, height, focusedComponentId)` — Recursive layout renderer; for `container` computes flex proportions and gap interleaving; for `component` calls `comp.View()` with `isFocused` flag; applies `Faint(true)` when not focused and `focusMode == "highlight"`
- `calculateBoundingBoxes()` — Recursive flex layout algorithm matching `renderNode`; stores `(x, y, w, h, id)` for each component; used for click-to-focus and arrow-key navigation
- `calculateNavigationMap(boxes)` — For each component, finds closest neighbor in 4 cardinal directions using axis-aligned bounding box overlap + minimum Euclidean distance

### `internal/tui/navigation.go`
- `boundingBox` struct — `X, Y, W, H int`, `ID string`
- `navigationMap` struct — `Up, Down, Left, Right` component IDs
- `handleResize(msgWidth, msgHeight)` — Reserves 2 rows for help line; calls `calculateBoundingBoxes` + `calculateNavigationMap`; resets `ready` flag until complete

### `internal/tui/fetch.go`
- `fetchComponentAsyncCmd(id, comp)` — Returns a `tea.Cmd` closure that dispatches to `RunScript`, `RunAPI`, or `ReadTodoFile` (special-cased for todo); emits `fetchResultMsg`

### `internal/tui/scheduler.go`
- `refreshMsg` struct — `ID string`; timer chain message
- `scheduleRefreshes()` — Returns `tea.Cmd` per component with `refresh_interval > 0`
- `scheduleSingleRefresh(id, comp)` — `time.Sleep(interval) * time.Second` then returns `refreshMsg{ID: id}`

### `internal/tui/actions.go`
- `actionResultMsg` / `actionFailedMsg` — Result messages from shell action execution
- `nextPendingID()` — Atomic counter for per-action ID used to discard stale results
- `matchBinding(bindings, chord)` — Normalizes pressed key and searches component bindings
- `dispatchAction(componentID, label, action)` — Transitions component to loading, returns `tea.Batch(beginCmd, runActionCmd)`
- `runActionCmd(componentID, pendingID, label, action)` — Dispatches to `runFireAndForget` or `runReplacePane` based on `action.Type`
- `runFireAndForget()` — Starts process in goroutine with `context.WithTimeout`; process survives after closure returns
- `runReplacePane()` — Synchronous `exec.CommandContext`; captures stdout/stderr; emits `actionResultMsg` or `actionFailedMsg`

## Notable Algorithms / Named Patterns
- **Flexbox-style layout** — Recursive flex proportion algorithm with `gap` interleaving and "last child gets remainder" treatment (avoids rounding drift)
- **Bounding-box navigation map** — AABB overlap detection + closest-neighbor search by direction; computed from live layout on every resize
- **Pending-ID action deduplication** — Atomic counter mints a unique `act-N` token per action dispatch; late-arriving results after `Esc`/`R` reset are silently dropped by `applyActionResult` checks
- **LAB color blending** — Histogram `gradient` mode uses `lucasb-eyer/go-colorful` LAB interpolation for perceptually uniform color ramps
- **Hex → ANSI-256 conversion** — `HexToAnsi256` maps truecolor hex to 6×6×6 ANSI cube index + 16 base colors for `asciigraph` compatibility
- **Sticky action state** — `actionState.active` flag preserves action output across render cycles; normal data fetch is suppressed; `R`/Esc reset restores original data
- **Effective styles merge** — 4-level palette resolution (default → named theme → explicit override → legacy border fields) with pointer cloning to avoid mutation

## Strengths
- **Declarative, data-driven architecture** — Entire dashboard is defined in JSON; no code required to create a dashboard
- **Rich component set** — 6 visually distinct component types (text, list, todo, chart, histogram, table) with data-source abstraction
- **Sophisticated layout** — Flexbox-style recursive containers with dynamic neighbor navigation (no hardcoded positions)
- **Action system** — Two-mode action semantics (`fire_and_forget` vs `replace_pane`) with timeout, sticky display, and `output_as: inherit` passthrough to component parsers
- **Theming** — 7 built-in themes, disk-loaded custom themes, `auto` terminal-background detection, per-component palette overrides
- **Mouse support** — Click-to-focus and mouse-wheel scrolling in text/histogram components
- **Well-structured TEA pattern** — Clean `model`/`Update`/`View` separation using `charmbracelet/bubbletea`; component-level mutation via value receiver cloning to satisfy TEA's immutability expectation
- **Boundary-box resize robustness** — Even/odd dimension correction prevents off-by-one layout drift on terminal resize

## Weaknesses
- **Go-only** — Not a library; deploys as a standalone CLI; no embeddable components for other Go programs
- **No streaming/chunked data** — Chart `append` mode only appends new data points; no streaming updates for live data; `tail -f`-style commands not supported in actions
- **Todo persistence is file-based** — Plain-text format limits expressiveness; no metadata, tags, due dates, or prioritization
- **Histogram color modes limited** — `map` mode requires explicit per-bin color in config; no automatic color scale
- **No grid layout** — Only row and column containers; no true 2D grid for placing components at arbitrary (x, y) positions
- **Key binding conflicts** — Must validate against per-component internal keys and reserved globals; adds complexity for plugin-like binding extension
- **HTTP client is basic** — No retry, no auth support, no custom headers, 5s hard timeout on API fetches
- **Refresh is poll-based only** — No webhooks, no SSE, no inotify; every component refetches even if data hasn't changed
- **Limited widget extensibility** — New component types require modifying `NewComponent()` factory in `components.go` and recompiling

## SugarCraft Mapping

SugarCraft is a PHP TUI library monorepo (Charmbracelet ecosystem ports). Dashbrew maps as follows:

| Dashbrew Concept | SugarCraft Lib(s) | Notes |
|---|---|---|
| Dashboard model (layout tree, component containers, flex routing) | `candy-core` (foundation) | `candy-core` provides the core TUI model pattern with `Model::init/update/view`; would need a dashboard/layout composer |
| Terminal rendering (lipgloss borders, styles, ANSI colors) | `candy-core` + `candy-shine` | `candy-shine` is the styling/rendering lib; `candy-core` provides terminal capabilities |
| Text/scrollable viewport component | `sugar-bits` (components/data) | `sugar-bits` is the canonical leaf lib for basic TUI components |
| Line/area chart (ASCII art) | `sugar-charts` | Charts component — likely the most direct mapping |
| Histogram/bar chart | `sugar-charts` | Same lib; histogram would be a specialized chart type |
| Table (column-flex layout, paginated) | `sugar-bits` or `sugar-charts` | Table component would live alongside charts |
| Todo list (persistent, keyboard-driven) | `sugar-bits` or a new `sugar-todo` leaf | No dedicated todo lib yet in SugarCraft |
| Action system (shell dispatch, timeout, sticky state) | `candy-core` (process/spawn handling) | Would live in core as part of the update loop |
| Bounding-box navigation (focus ring, neighbor finding) | `candy-core` (focus management) | Navigation model; `candy-sprinkles` for key bindings |
| Theme system (named palettes, auto detection) | `candy-shine` (styling) | Palette/theme management is shine's domain |
| Key bindings / action palette | `candy-sprinkles` (key handling) | `candy-sprinkles` is for input/bindings |
| HTTP data fetching (API source, JSONPath) | `sugar-bits` (HTTP utilities) | Would be a data-fetch utility, not a component |
| Layout containers (row/column flex) | `candy-core` (layout system) | Flexbox layout algorithm could be a shared utility |

**Key insight:** Dashbrew's architecture bundles all of these into one app. SugarCraft's approach would split them: a `SugarCraft\Dashboard` layout composer (not yet built), separate component leaf libs for each widget type (text, chart, histogram, table, todo), and `candy-core` for the underlying TEA model infrastructure and action dispatch system.

---

## Analysis

**Dashbrew** is a well-crafted, single-purpose Go CLI that brings the Charmbracelet TUI philosophy to terminal dashboarding. Its architecture is cleanly separated into config loading (with theme resolution and validation), a component layer (6 visually distinct widget types each following the TEA `Component` interface), and a TUI layer (model, renderer, navigation, fetch, actions, scheduler). The use of `charmbracelet/bubbletea` for the main loop, `charmbracelet/lipgloss` for styling, and `charmbracelet/bubbles` (spinner, viewport, list, table) means it fully participates in the Charmbracelet ecosystem — making it a natural porting candidate for SugarCraft which aims to port that entire ecosystem to PHP.

The layout system is the most architecturally interesting part. Dashbrew implements a recursive flexbox algorithm that simultaneously computes rendering dimensions and bounding boxes for every component; those bounding boxes are then used at runtime to determine keyboard navigation neighbors (closest component in each cardinal direction with overlapping axis-aligned projection) and mouse click targets. This eliminates any need for hardcoded spatial relationships — any layout shape (nested rows/columns with arbitrary flex weights and gaps) automatically gets correct focus navigation.

The action system demonstrates sophisticated TEA patterns: an atomic pending-ID counter mints unique tokens per action dispatch, and the component tracks its `actionState` independently so late-arriving results after a user-initiated reset are silently discarded. The distinction between `fire_and_forget` (goroutine-owned process that survives the tea command closure) and `replace_pane` (synchronous with timeout via `context.WithTimeout`) is clean and well-isolated in `actions.go`. The `output_as: inherit` mode, which re-routes stdout through the component's existing `SetContent` parser, shows thoughtful compositional design.

The main limitations are its monolithic nature (not a library), lack of streaming data support (no SSE, no `tail -f`), and basic HTTP client (no auth, no retries). For SugarCraft, the most valuable port would be the flexbox layout algorithm and bounding-box navigation system, which could form the foundation of a `SugarCraft\Dashboard` layout composer. The component types (chart, histogram, table) map well to SugarCraft leaf libs, but the chart rendering would require either porting `guptarohit/asciigraph` or implementing ASCII chart generation from scratch.
