# Plan: mining `ratatui` for individual widget + layout ideas

## Context

[`ratatui/ratatui`](https://github.com/ratatui/ratatui) is the dominant
Rust TUI framework — 20K stars, immediate-mode rendering,
constraint-based layout, large widget palette. Architecturally it's
**not** a port candidate (immediate-mode vs SugarCraft's Elm
architecture; rationale tracked in
[`evaluated-skipped.md`](./evaluated-skipped.md) when that lands).

What ratatui *does* have is a polished set of individual widget +
layout designs that translate cleanly into our existing libs.
This doc breaks each one out as its own small implementation plan.

## What ratatui has and where it lands in our tree

Coverage map between ratatui's widget palette and SugarCraft today:

| ratatui widget | SugarCraft equivalent | Status |
|---|---|---|
| `Block` | candy-sprinkles `Border` + `Style` | ✅ comparable |
| `Paragraph` | candy-sprinkles `Style::render(text)` + `Width::wrap` | ✅ |
| `List` | sugar-bits `ItemList` | ✅ |
| `Table` | sugar-bits `Table` + candy-sprinkles `Table` | ✅ |
| `Chart` (line/scatter/bar) | sugar-charts (Linechart, BarChart, Scatter) | ✅ |
| `Sparkline` | sugar-charts `Sparkline` | ✅ |
| `BarChart` | sugar-charts `BarChart` | ✅ |
| `Gauge` (progress bar) | sugar-bits `Progress` | ✅ |
| `LineGauge` (thin line gauge) | sugar-bits `Progress` w/ thin chars | 🟡 partial |
| `Tabs` | — | 🔴 **gap** |
| `Calendar` | sugar-calendar | ✅ |
| `Canvas` (low-level draw shapes) | candy-sprinkles `Canvas` | ✅ |
| Constraint-based `Layout` | candy-sprinkles `joinHorizontal/Vertical` | 🔴 **partial — no solver** |
| `Block` title positioning (multiple titles, alignment) | candy-sprinkles `Border::title()` | 🟡 partial |
| `Scrollbar` widget | sugar-bits `Viewport` has internal scrollbar | 🟡 partial |
| `Clear` widget (erase region under overlay) | sugar-veil overlay handles this | ✅ |
| `color_eyre` panic prettifier (`ratatui::error`) | candy-log can format errors but no rich panic handler | 🔴 **gap** |
| `Throbber` widget | candy-core `Cursor` shapes only; no spinner widget standalone | sugar-bits Spinner ✅ |
| `Logo` widget (ratatui v0.27+) | — | 🔴 candy-kit could grow one |

The gaps (🔴) are this doc's targets.

## Feature 1 — Constraint-based Layout in candy-sprinkles

### Why

candy-sprinkles today has `joinHorizontal($alignment, ...$blocks)` and
`joinVertical($alignment, ...$blocks)`. Powerful for static
arrangements but doesn't solve "one row of three columns: first 20
chars, second 50%, third fills remaining space, all min 10 wide" —
which is ratatui's bread-and-butter.

ratatui's `Layout` takes a `Direction` + an array of `Constraint` and
returns an array of `Rect`. Constraints are: `Length(n)`,
`Percentage(n)`, `Min(n)`, `Max(n)`, `Ratio(num, denom)`, `Fill(weight)`.
The solver is a simple pass that assigns fixed lengths first, then
distributes remaining space proportionally.

### Where it lives

`candy-sprinkles/src/Layout/`:

- `Constraint.php` — sealed class hierarchy: `Length`, `Percentage`, `Min`, `Max`, `Ratio`, `Fill`
- `Direction.php` — enum: `Horizontal | Vertical`
- `Layout.php` — facade: `Layout::horizontal($constraints)` / `Layout::vertical($constraints)`
- `Rect.php` — readonly: `x, y, width, height`
- `Solver.php` — internal: takes `Rect $area + Constraint[]` → `Rect[]`

### API

```php
use SugarCraft\Sprinkles\Layout\{Layout, Constraint, Rect};

$area = new Rect(0, 0, 100, 30);

$rows = Layout::vertical([
    Constraint::length(3),       # header
    Constraint::min(10),         # body — at least 10 rows, takes remaining
    Constraint::length(1),       # status line
])->split($area);
# $rows[0] = Rect(0,  0, 100,  3)
# $rows[1] = Rect(0,  3, 100, 26)
# $rows[2] = Rect(0, 29, 100,  1)

$cols = Layout::horizontal([
    Constraint::length(20),
    Constraint::percentage(50),
    Constraint::fill(1),
])->split($rows[1]);
# $cols[0] = Rect( 0, 3, 20, 26)
# $cols[1] = Rect(20, 3, 50, 26)  (50% of 100)
# $cols[2] = Rect(70, 3, 30, 26)  (remaining)
```

### Solver algorithm

1. Sum fixed `Length(n)` and `Min(n)` baseline → reserved space
2. Compute `Percentage` and `Ratio` against total area → flexible space
3. Distribute remaining slack across `Fill(weight)` proportionally
4. Apply `Max(n)` cap as a clamp pass
5. If reserved > area, truncate proportionally and warn (ratatui silently truncates; we should log)

ratatui uses cassowary (a constraint-solver crate) for the full
implementation; our needs are simpler — a 50-line pass handles all
cases above. Drop cassowary unless we hit a case it solves and ours
doesn't.

### Slices

- **PR1** — `Constraint`, `Direction`, `Rect`, `Layout` skeleton + `Length` + `Min` + `Fill` constraints (~1 day)
- **PR2** — `Percentage`, `Ratio`, `Max` constraints + clamp pass (~half day)
- **PR3** — examples + tests (snapshot a 3-pane dashboard layout) + docs (~half day)
- **PR4** — sugar-bits adoption: rewrite ItemList + Viewport + Table layout to consume `Layout` instead of inline math (~1 day, optional)

### Effort

**2-3 days** (PR1-PR3); +1 day if we adopt internally (PR4).

### Caveats

1. ratatui's `Constraint::Min(n)` semantically means "at least n,
   prefer more if available". Our naming should match upstream so users
   moving from Rust find their footing — even if it differs from
   our other naming.
2. Order matters in some constraint solvers; document clearly.
3. Don't over-engineer with cassowary; ratatui itself moved away from
   it in v0.26 toward a simpler solver.

---

## Feature 2 — `Tabs` component in sugar-bits

### Why

ratatui ships a `Tabs` widget; sugar-bits doesn't. Common pattern in
TUIs (settings panels, multi-doc editors). bubbles upstream has a
related issue (#157 — feature request for tabbed view) referenced in
`UPSTREAM_OPPORTUNITIES.md`. Building it now closes the gap.

### Where it lives

`sugar-bits/src/Tabs.php` (new), follows existing component patterns:

- final + immutable + fluent
- public readonly state
- `update(Msg): [Model, ?Cmd]`
- `view(): string`

### API

```php
use SugarCraft\Bits\Tabs;
use SugarCraft\Sprinkles\Style;

$tabs = Tabs::new(['Home', 'Profile', 'Settings'])
    ->withActive(0)
    ->withActiveStyle(Style::new()->withBold()->withForeground('cyan'))
    ->withInactiveStyle(Style::new()->withForeground('gray'))
    ->withDivider(' │ ')
    ->withKeyMap(TabsKeyMap::default());  # Tab/Shift-Tab to switch

# Inside Model::update():
[$tabs, $cmd] = $tabs->update($msg);

# Inside Model::view():
return $tabs->view();
# Output: " Home  │  Profile  │  Settings " with active item highlighted
```

Keymap defaults: `Tab` → next, `Shift+Tab` → prev, `1-9` → jump-to-N,
`h/l` (vim) optional.

### Behaviour

- Wrap-around vs. clamp at ends (configurable, default wrap)
- Scrollable when total label width exceeds available width — show ellipses on overflow side
- Mouse support via candy-zone integration: `Click` on a label sets active

### Slices

- **PR1** — Tabs class + update + view + keymap (~1 day)
- **PR2** — mouse via candy-zone + scroll-on-overflow (~half day)
- **PR3** — example + .vhs tape + readme entry (~half day)

### Effort

**1.5-2 days** total.

### Tracking

- sugar-bits/README.md — add Tabs row to component table
- `UPSTREAM_OPPORTUNITIES.md` — close bubbles #157 reference once shipped
- sugar-bits CALIBER_LEARNINGS.md — note any keymap gotchas

---

## Feature 3 — Multi-position titles in candy-sprinkles `Border`

### Why

ratatui's `Block` lets you place titles in any of six positions
(top-left / top-center / top-right / bottom-left / bottom-center /
bottom-right) and stack multiple titles per side. candy-sprinkles
`Border::title()` currently supports a single top-anchored title.

### Where it lives

`candy-sprinkles/src/Border.php` — extend existing class.

### API

```php
$border = Border::rounded()
    ->withTitle('Files', anchor: TitleAnchor::TopLeft)
    ->withTitle(' 24 items ', anchor: TitleAnchor::TopRight)
    ->withTitle('press q to quit', anchor: TitleAnchor::BottomCenter);
```

`TitleAnchor`: `enum { TopLeft, TopCenter, TopRight, BottomLeft, BottomCenter, BottomRight }`.

`withTitle()` is additive — call multiple times to stack. `withTitles($map)` for bulk replacement.

### Layout rules

- Titles on the same anchor concat with a separator (default ` `)
- Title bytes take precedence over border characters; the border line is "split" and the title inserted
- Title overflow: truncate with ellipsis, falling back to clipping
- Width-aware: uses `Util\Width::string()` for grapheme widths so emoji + CJK render correctly

### Slices

- **PR1** — `TitleAnchor` enum + `Border::withTitle($text, $anchor)` accumulator + render (~half day)
- **PR2** — overflow handling + style-per-title (~2 hours)
- **PR3** — tests + docs update (~1 hour)

### Effort

**~1 day**.

### Caveats

- Today's `Border::withTitle($string)` must keep working as a shorthand for `withTitle($string, TitleAnchor::TopLeft)`. Backwards compatible.
- Order matters: titles stack in insertion order; document.

---

## Feature 4 — Standalone `Scrollbar` widget in sugar-bits

### Why

sugar-bits Viewport has a built-in scrollbar but it's not
extractable. ratatui's `Scrollbar` is a standalone widget that
accepts a `ScrollbarState` and renders next to (or inside) any
component. Lets users add scrollbars to lists, tables, custom views.

### Where it lives

`sugar-bits/src/Scrollbar.php` (new) + extracted state object.

### API

```php
$state = ScrollbarState::new(total: 100, position: 25, viewport: 20);
$bar = Scrollbar::vertical()
    ->withTrackChar(' ')
    ->withThumbChar('█')
    ->withArrows(true);
$rendered = $bar->view($state, height: 20);    # column of thumb chars
```

Pair with Viewport so `Viewport::view()` accepts an optional
`Scrollbar` and renders it as the rightmost column.

### Slices

- **PR1** — Scrollbar + ScrollbarState + vertical render (~half day)
- **PR2** — horizontal variant (~2 hours)
- **PR3** — Viewport integration + extract its inline scrollbar to use the new component (~half day)

### Effort

**1 day**.

---

## Feature 5 — `Logo` widget in candy-kit

### Why

ratatui added a `Logo` widget in v0.27 — renders the project's logo as
ASCII art at the top of TUIs. candy-kit (our `fang` port) is the
right home: it's the "CLI presentation helpers" lib (StatusLine,
Banner, Section, Stage, HelpText). A `Logo` fits naturally next to
`Banner`.

### Where it lives

`candy-kit/src/Logo.php`.

### API

```php
$logo = Logo::sugarcraft()        # built-in: SugarCraft logo as char art
    ->withColor('candy-pink');
echo $logo->render();

# or custom
$logo = Logo::fromAscii(<<<'TXT'
    ╭──────────╮
    │ MY APP   │
    ╰──────────╯
TXT);
```

Built-in presets: `sugarcraft()`, `candy()` (just the candy emoji
banner), and one or two more. Document an `ImageMagick → asciiart`
recipe in the readme for users wanting custom.

### Slices

- **PR1** — Logo class + `fromAscii()` + `sugarcraft()` preset (~half day)
- **PR2** — color theming + size variants (small/large) (~2 hours)

### Effort

**~half day**.

---

## Feature 6 — Pretty panic / error formatter (`candy-eyre`?)

### Why

ratatui ecosystem leans on `color_eyre` to format panics nicely — show
backtrace with file paths highlighted, group repeated frames, redact
locals. candy-log formats log records but doesn't catch and pretty-print
uncaught exceptions / fatal errors. PHP's default error output is
ugly; first impressions matter for TUIs.

### Where it lives — three options

1. **Feature in candy-log** — add `Log::panicHandler()` registering a
   `set_exception_handler` + `register_shutdown_function` pair. Pros:
   no new lib. Cons: candy-log is a logger, not a panic handler;
   coupling is awkward.
2. **New tiny lib `candy-eyre`** — dedicated panic handler. Pros: clean
   separation, mirrors upstream naming. Cons: another lib in the tree.
3. **Feature in candy-mold** — the starter scaffold installs the handler
   automatically. Pros: every new SugarCraft project gets it free.
   Cons: existing projects must opt in manually.

**Recommendation: option 1** (candy-log feature). It's already a logger;
extending to "log uncaught exceptions prettily" is a small, natural
step. Tag a follow-up PR for option 3 (candy-mold ships an opt-in
`Log::installPanicHandler()` call in its bootstrap).

### API

```php
use SugarCraft\Log\Log;

Log::installPanicHandler();   # default: pretty console + stderr
# or with options:
Log::installPanicHandler(
    formatter: PanicFormatter::pretty(),
    redactPaths: ['/etc/secrets'],
    showLocals: false,                # off by default; TUIs may set on
);
```

On uncaught exception:
- Restore the terminal (exit altscreen, show cursor, restore stty)
- Print a banner with the exception class + message
- Print backtrace with file paths colorized + line numbers
- For repeated frames, collapse with `... 3 more`
- Append the `caliber` learnings hint: "consider `caliber refresh` if this is config-related"

### Slices

- **PR1** — handler install + restore-terminal helper + pretty formatter (~half day)
- **PR2** — collapse-repeats + path redaction + locals (off by default) (~half day)
- **PR3** — candy-mold bootstrap opt-in + readme updates (~2 hours)

### Effort

**~1.5 days**.

### Caveats

- Restore-terminal requires candy-core's `Tty::restore` to be callable
  outside a `Program` instance — check if `Tty::restoreLast()` static
  exists; if not, add it
- PHP shutdown function fires *after* normal output flushes; we need to
  use `set_exception_handler` for the visible part and shutdown function
  for the cleanup-from-fatal case (`E_ERROR`, `E_PARSE` — not catchable
  via normal handler)
- Don't redact stack traces in dev mode but do redact in prod; gate
  with a constructor flag

---

## Feature 7 — `LineGauge` (thin progress) in sugar-bits

### Why

sugar-bits `Progress` is a block-style bar. ratatui's `LineGauge` is a
single-line variant useful for compact UIs (status lines, side panels).
Quick win.

### Where it lives

Extend `sugar-bits/src/Progress.php` with a render mode rather than a
new class.

### API

```php
$progress = Progress::new()
    ->withRenderMode(ProgressRenderMode::Line)   # or Block (default), Slim
    ->withPercent(0.42);
echo $progress->view(width: 30);
```

### Slices

- **PR1** — `ProgressRenderMode` enum + line render path (~3 hours)
- **PR2** — examples + tests (~1 hour)

### Effort

**~half day**.

---

## Recommended sequencing

If running these as a feature wave:

1. **Wave A — Layout** (highest leverage): Feature 1 (Constraint Layout). 2-3 days.
2. **Wave B — gap fillers**: Feature 2 (Tabs), Feature 7 (LineGauge), Feature 3 (multi-position titles). ~3 days bundled across 3-4 PRs.
3. **Wave C — polish**: Feature 4 (Scrollbar), Feature 5 (Logo), Feature 6 (panic prettifier). ~3 days.

Total wave: **~9 days** of feature work, sliced into ~10 PRs.

## Cross-cutting touch-ups when each lands

- `UPSTREAM_OPPORTUNITIES.md` — add a "ratatui inspirations" section noting these as features (not ports)
- `MATCHUPS.md` — no rows added (none of these is a new lib)
- candy-sprinkles, sugar-bits, candy-kit, candy-log READMEs — feature-table updates per affected lib
- candy-sprinkles + sugar-bits CALIBER_LEARNINGS.md — capture solver / keymap gotchas

## Why not port ratatui itself

Recorded here so it stays settled:

| Reason | Detail |
|---|---|
| Architectural mismatch | SugarCraft is Elm-architecture (`Model → Update → View`) per upstream bubbletea. ratatui is immediate-mode (`frame.render_widget()` per tick). The mental models don't compose. |
| Audience overlap is ~zero | A PHP dev picking up TUI for the first time learns one architecture. Supporting two splits docs / examples / community. |
| Most widgets exist | The coverage table at the top shows we already have ratatui's core widget set via candy-sprinkles + sugar-bits + sugar-charts. The framework was the value, not the widgets. |
| Naming would imply a competitor | A `Sugar-Rata` lib would advertise "different worldview". We don't want that. |

The bet here is that we win more by absorbing ratatui's *individual*
designs into our existing libs than by porting the framework whole.
