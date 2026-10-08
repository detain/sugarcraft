# candy-top implementation plan — btop-style system monitor port

Authoritative plan for porting **btop** (aristocratos/btop @ d3389d7, clone `/home/sites/btop`) to PHP as
`candy-top`. Evidence base: `/tmp/opencode/candy-top/{env,sugarcraft-charting,btop-draw,tea-collector}-REPORT.md`
(cited below as `env §n`, `charting §n`, `draw §n`, `tea §n`). Landed state verified against the working tree
2026-10-08; discrepancies with the research brief are resolved in §8 (decision log).

## 0. Verdict summary

candy-top is a full-screen, 6-panel (cpu/proc/mem/net/disk/battery+clock+title-buttons) system-monitor TUI in
SugarCraft house TEA style, visually faithful to btop's drawing language.

**Coverage today: ~60-65% primitive-wise** (draw §0: "primitives are ~90% present … NOTHING renders btop's exact
visual language as-is"; headline register): per-point braille color + 101-stop ramp math (`Color::blend1d`,
charting §4) + Threshold value→color stops + RingBuffer + gradients + borders + mouse zones + DEC 2026 +
kitty/SGR input all exist. **Zero coverage:** the dual-sample 5×5 glyph quantizer's block tables, position-colored
meters, graph_bg underlays, border-embedded titles + junction seams, distance-fade rows, per-row mini-sparkline
composite, underline-cursor inline editor, theme registry/loader, and ALL OS collectors beyond aggregate
CPU/mem/uptime/loadavg (charting §7 "Live metric collection ❌ does not exist as a lib").

**Thesis:** (a) close the library gaps FIRST as small review-gated additions to sugar-charts, sugar-dash,
sugar-bits, candy-sprinkles (Phase 2, lanes L1-L8); (b) then assemble the candy-top app on top of the completed
primitives (Phases 3-4). **No new rendering lib is needed** — draw §10's "net" paragraph floats a candidate lib
name; this plan rejects it (see §8): everything authored lands either in an existing sibling (reusable,
drift-guarded) or in `candy-top/src/` (app-local collectors, panels, theme loader).

Scaffold already landed at master `31e4dd8ff`, pushed, split-synced to `github.com/sugarcraft/candy-top`
(commit `01bab2d8`), packagist pending by owner: `candy-top/composer.json` (requires @dev: candy-core,
candy-input, candy-sprinkles, sugar-charts, sugar-dash), phpunit.xml, `src/Top.php` placeholder citing this plan,
1 green test, README, CALIBER_LEARNINGS.md. Plan around it; do not re-plan it.

## 1. Scope & non-goals

**v1 scope**
- **Linux-first collectors**: btop's `src/linux/btop_collect.cpp` surface (3,643 LOC incl. vendored
  intel_gpu_top C, env §4) — `/proc/stat`, `/proc/meminfo`, `/proc/net/dev`, `/proc/diskstats`,
  `/proc/[pid]/{stat,status,cmdline}`, `/proc/mounts`, `/proc/uptime`, `/proc/loadavg`,
  `/sys/class/thermal`+`/sys/class/hwmon`, `/sys/class/power_supply`, `/sys/devices/.../cpufreq`.
  FreeBSD/NetBSD collectors (1,370/1,427 LOC) deferred.
- **Visual parity for the 6-panel layout** per draw §3: cpu (upper/lower graphs, per-core grid, load/freq/watts
  footer, battery + clock border embeds, title buttons), mem (per-class graphs used/available/cached/free + swap),
  net (dual graphs + autoscale hysteresis + stats sub-box + iface buttons), disk (io_mode mirror graphs or
  Used/Free meters), proc (sorter, filter, distance-fade, dual-gradient colors, vi keys, scrollbar click/drag),
  plus clock/uptime/buttons chrome. Menu set: main menu, options, help, signal popup, msgboxes (draw §5).
- **Theme engine**: btop's 43 semantic keys + 9 gradient families ×(start,mid,end) → 101-stop caches + derived
  `proc`/`proc_color` + TTY 16-color variant (draw §6). The upstream `themes/*.theme` files — **41**, disk-verified
  2026-10-08 (`ls /home/sites/btop/themes/*.theme | wc -l`; draw §6 correct, env §4's "43" false — §8) — are
  **data to port** into `candy-top/docs/_data`-style fixtures or a themes/ dir; no
  SugarCraft lib ships them today (see §8 L-note — the brief's "sugar-dash's 43 ported btop themes" is false on
  disk; porting them is new work, folded into lane L7/Phase 3 Theme/).
- **Input**: kitty progressive keyboard + SGR mouse (candy-input, charting §6), vim_keys (draw §4).
- **Config**: `~/.config/candy-top/config.conf`, `key=value`, btop-compatible-ish subset (draw §9).

**Non-goals (v1)**
- GPU parity: no NVML/ROCm SMI dlopen (env §4 optional-GPU mechanics). Ship `nvidia-smi` shell-out best-effort
  exactly as sugar-dash SystemModule already does — memoized-absent sentinel row (tea §2, charting §2.3).
- Mouse-drag config editing (btop has none; do not invent).
- Presets editor UI: ship preset *loading* (`box:placement:graph_symbol` triples, draw §4) and a basic
  config/options panel only; no preset save/edit screen.
- Image protocols (sixel/kitty/iterm2 export, candy-mosaic) — out of scope (charting §5 exists, unused here).
- Windows collectors; FreeBSD/NetBSD/OpenBSD/macOS collectors; `intel_gpu_top` vendoring.

## 2. Library gap remediation (the heart)

Gap→lane map (derived from draw §0 hard-gaps 1-8 + §10 status column; P0 = blocking visual parity, P1 = faithful
feel):

| Gap (draw ref) | Pri | Target lib | Lane |
|---|---|---|---|
| LineChart per-POINT (x,value) color closure — residual after Audit-F11 wired per-series legend colors into the braille path (charting §8's "defect" framing superseded, §8-8) | P0 | sugar-charts | **L1** ✅ `0153b65e4` |
| BrailleCanvas 101-stop gradient memo — kill clone-at-101-sites ramp pattern | P0 | sugar-dash | **L2** ✅ `073913a44` |
| Dual-sample 5×5 quantizer: block tables + tty shades (braille packing reuses existing) (draw §2.7) | P0 | sugar-dash | **L3** ✅ `37f5c6744` |
| graph_bg underlay glyph support `⣀`/`▄`/`░` in inactive_fg (draw §2.7 idiom, tty baseline disk-verified `░`) | P0 | sugar-dash | **L3** ✅ `37f5c6744` |
| PositionGradient + `■` position-colored Meter (draw §2.6) | P0 | sugar-dash (+bits Progress doc) | **L4** ✅ `1bbbca37f` |
| Border::withTitle embedded-title + `┬├┴` junction placement (draw §2.4/§2.5/§3.3) | P0 | candy-sprinkles | **L5** ✅ `57cd08b9f` |
| sugar-charts BarChart withGradient (per-bar value→color) (single-color bars — charting §1.1 reads "single series only"; the per-bar color gap verified directly on `BarChart.php`, which ships no bar-color API) | P1 | sugar-charts | **L1** ✅ `0153b65e4` |
| bits Progress position-gradient convenience + width-passing fix note (draw §10 row 6) | P1 | sugar-bits | **L4** ✅ `1bbbca37f` |
| rounded/line box presets into border presets (disk §8) | P1 | candy-sprinkles | **L5** ✅ `57cd08b9f` |
| Distance-fade helper (proc rows, draw §3.5) | P1 | sugar-dash | **L6** ✅ `0859cfa4f` |
| Per-row mini-sparkline composite (NET-NEW — technique source btop's proc-list row graphs, draw §3.5) | P1 | sugar-dash | **L6** ✅ `0859cfa4f` |
| Underline-cursor inline TextEdit (draw §2.3) | P1 | sugar-bits | **L8** ✅ `39c65280c` |
| Option-highlight convention (draw §5 options rows) | P1 | sugar-bits | **L8** ✅ `39c65280c` |
| Net autoscale hysteresis algorithm (draw §3.4) | P1 | sugar-dash (Foundation) | **L7** ✅ `07a1e5e6f` |

The lane target libs are siblings (charts/dash/bits/sprinkles), but the lanes are NOT mutually independent:
within dash there is an ordering chain **L2 → L3 → L6** — L3's `setGradient` rides L2's `withGradient`, and
L6's composer consumes L3's `DualSampleGraph` + its graph_bg underlay — so dispatching L3 or L6 against a
pre-L2/L3 dash tree stalls. L4 and L5 stand alone within dash/sprinkles. sugar-charts *depends on* sugar-dash
(charting §1), so **L2 (at least) must merge before L1's canvas-consumer tests are meaningful**, but L1's API
change is independent. L7 depends on L1+L2 (gradient ramp lookup). L8 is independent.
candy-top app assembly (Phase 3+) depends on ALL lanes landed.

### ✅ L1 — sugar-charts: per-point color callback + bar gradient — `0153b65e4`

**Closes:** charting §8's per-series color defect — **superseded on disk**: Audit-F11 (commit `1ad48ebba`, an
ancestor of scaffold master `31e4dd8ff`) already routes per-series colors into the braille path —
`renderBrailleSeries` (`sugar-charts/src/LineChart/LineChart.php:664-737`, dispatched at `:559-567` when
`$brailleCanvas !== null`) resolves each series' color from `legendItems()` (`:450-452`) and paints
`$canvas->setCell(..., $style)`. The "no injection point" wording traces to candy-query's `MultiSeriesCell`
docblock, written pre-F11 and stale. **The residual gap L1 actually fills:** btop colors every graph *point* by
its value through the gradient (draw §2.7 `Theme::g(...).at(clamp)`); LineChart offers no per-(x,value) closure —
one color per series is the finest resolution today. Verified still on disk: `:37`
`private const DATASET_COLORS = ['red','green','yellow','blue','magenta','cyan']` is the default series cycle;
the ctor's canvas param exists at `:83` (`?BrailleCanvas $brailleCanvas = null` — not trailing, `?Theme $theme`
follows it) and IS consumed by the F11 render path.

**API to add** (`sugar-charts/src/LineChart/LineChart.php`, house immutable+fluent via private `mutate()`):

```php
/** Per-point color resolver layered on the existing per-series path:
 *  fn(string $dataset, int $x, float $value): ?Color — non-null overrides the series color
 *  for that dot/segment, null falls through. Mirrors btop's per-value theme gradient
 *  (btop_draw.cpp:422, draw §2.7). */
public function withSeriesColorFn(?\Closure $fn): self;
```

No `withSeriesColors` map is added — that path already exists via `legendItems()` (Audit-F11). Routing rule:
stroke/point paint resolves `seriesColor(dataset, x, value)` → closure → existing per-series
legend/DATASET_COLORS resolution (byte-identical default when the closure is unset — regression guarantee for
existing consumers sugar-query, sugar-tick). **Tests:** snapshot byte — closure returns red for value>50 / blue
below, assert raw SGR runs differ across the high/low segments of one series (per-point granularity, not
per-series); coercion — non-callable throws at the door (`Finite`-style fail-fast per charting §1.2);
immutability pin on `with*`.

**Also L1:** `sugar-charts/src/BarChart/BarChart.php` —
`public function withBarColor(?\Closure $fn): self; // fn(Bar $bar, int $i): ?Color`
closes the single-color-bar gap (charting §1.1 reads "single series only"; the bar-color absence verified
directly on `BarChart.php`); default unchanged. Snapshot + coercion tests as above.

Files touched: `LineChart.php`, `BarChart.php`, tests only. Est. ~120 LOC src + ~200 LOC tests.
**Regression risk:** sugar-query (uses charts LineChart + the `MultiSeriesCell` workaround — do NOT remove the
workaround in this lane; its docblock rationale is half-superseded by F11, retirement is a follow-up decision,
§8-4/§8-8), sugar-tick, candy-query dashboard. Run at lane
end: full `sugar-charts` suite, then `candy-query` suite (its 1483T/4203A era figure, tea-era record) to prove
zero behavior drift on default path.

### ✅ L2 — sugar-dash: BrailleCanvas::withGradient (101-stop ramp) — `073913a44`

**Closes:** draw §2.7 coloring law (`Theme::g(gradient).at(clamp)`) — today every consumer hand-rolls the ramp;
the audit headline (draw report era notes) is "kill clone-at-101-sites": with the canvas immutable clone-on-write
(VERIFIED: `setPoint(x,y,?Color):self` deep-copies both grids per point — sugar-dash/src/Plot/Braille/BrailleCanvas.php),
naively threading a ramp through callers is O(points×grid) copies. First-class memo avoids it.

**API to add** (`sugar-dash/src/Plot/Braille/BrailleCanvas.php`):

```php
/** Attach a value→color ramp; setPoint(x,y) with $color===null then colors the dot by
 *  its own value via the ramp. Mirrors btop per-value gradient (draw §2.7 coloring). */
public function withGradient(array $stops, ?\Closure $scale = null): self; // list<Color> 0→1 ordered, >=2 stops
public function withValue(int|float $value): self;                          // current sample value for ramp lookup
```

Implementation law: ramp expanded **once** at with-time into a 101-entry memo via
`SugarCraft\Core\Util\Color::blend1d` (candy-core Color.php:439, charting §4) — mirroring btop's
`std::array<string,101>` theme cache (draw §6 gradient engine); `setPoint(x, y)` (null color) resolves
`memo[(int)round(clamp($scale(value),0,1)*100)]` without cloning the ramp per point. `$scale` default
identity; btop's `max_value`/`offset` percent-scaling (draw §2.7: `clamp((v+offset)*100/max_value,0,100)`)
lives in the caller-supplied closure, keeping the canvas value-agnostic.

**Tests:** snapshot byte — 2-stop cyan→magenta, points at value 0/50/100 emit the exact SGR trio (assert raw
`\x1b[38;2;…m` runs, AGENTS.md snapshot mode); coercion — <2 stops throws, null-scale passthrough, immutability
of memo across `with*` (mutate() carry, `XSet` sentinel law); cell-grid mode via `SugarCraft\Vt\Terminal` asserting
dot placement unchanged vs no-gradient (gradient must not alter geometry).

Files: `BrailleCanvas.php` + tests. Est. ~90 LOC src / ~180 LOC tests. **Regressions:** sugar-charts (depends on
dash, charting §1) + its consumers from L1's list; sugar-dash own suite (5,945T era figure — tea/dash record) at
lane end; candy-query dashboard filter.

### ✅ L3 — sugar-dash: dual-sample quantizer tables + graph_bg underlay — `37f5c6744`

**Closes:** draw §0 gap 1 ("no class … implements this sampling law") + draw §10 rows 1, 4, 5. BrailleGrid/
BrailleCanvas are storage canvases; the **quantizer** mapping two adjacent samples to one cell-glyph via the
5×5 table is unbuilt, as are the block and tty symbol families and the idle underlay.

**Exact upstream law to port** (draw §2.7, btop_draw.cpp:422-541 `_create`): per row band,
`cur_high = round(100.0*(height-horizon)/height)`, `cur_low = round(100.0*(height-horizon-1)/height)`; each of
the two samples `{last, cur}` maps to a band `0..4`: `4` if `value >= cur_high`; `clamp_min` if
`value <= cur_low` (`clamp_min = 1` on the bottom row when `no_zero`); else
`clamp(round((value-cur_low)*4/(cur_high-cur_low) + mod), clamp_min, 4)` with `mod = (height==1) ? 0.3 : 0.1`.
Glyph index = `table[result[0]*5 + result[1]]` (row = prev sample band, col = current). Braille cells pack
prev-sample dots on left column (dots 0-3), current on right (dots 4-6) — btop's 25-entry `braille_up` table is
precomputed from that packing; `braille_down` mirrors for `invert`. Block mode collapses mid-bands (5→4 distinct
heights) over quadrant glyphs; tty mode is one sample/column over shades.

**New API** (`sugar-dash/src/Plot/Braille/DualSampleGraph.php`, new final class, SizedItem like siblings):

```php
/** Symbol family: btop's graph_symbols map (btop_draw.cpp:89, env §4 "5x5=25 glyph tables"). */
public const SYMBOLS_BRAILLE_UP = [/* 25 entries: index 0 is U+0020 space, 1-24 U+2800-block,
    ported verbatim from btop symbols table (btop_draw.cpp:89-95) */];
public const SYMBOLS_BRAILLE_DOWN = [/* mirrored 25 */];
public const SYMBOLS_BLOCK_UP   = ['▗','▖','▄','▐','▟','▌','▙','█' /* …25-entry table:
    quadrant packing lower-half; brief form ▄▟▙█ / upper ▀▜▛█ */];
public const SYMBOLS_BLOCK_DOWN = [/* upper-half mirrors: ▘▀▜▛ family */];
public const SYMBOLS_TTY        = [ /* 25-entry table, disk-verified btop_draw.cpp:118-132: */
    ' ','░','░','▒','▒', '░','░','▒','▒','█', '░','▒','▒','▒','█', '▒','▒','▒','█','█', '▒','█','█','█','█'];
    // 1 sample/col over shades ' ','░','▒','█' ONLY — no '▓' (▓ is chrome-only, draw §7) (draw §2.7 tty)

public static function new(int $width, int $height, string $symbols = self::SYMBOLS_BRAILLE_UP,
    bool $invert = false, bool $noZero = false, int $offset = 0, int $maxValue = 0): self;

/** The _create port: deque of raw samples -> list<string> render rows, band-quantized. */
public function push(int|float ...$values): self;
public function setGradient(?array $stops): self;   // rides L2's withGradient onto the backing canvas
public function render(): string;                    // graph_bg underlay first, then live cells
public function underlayGlyph(): string;             // '⣀' braille / '▄' block / '░' tty per family (draw §2.7 graph_bg idiom, baseline disk-verified)
```

graph_bg support: `render()` (and a static `underlay(string $family, int $cells, Color $inactiveFg): string`)
emit `inactive_fg`-colored underlay glyph × n followed by the live graph overdraw — btop prints
`Theme::c("inactive_fg") + graph_symbols.at(name+"_up").at(6) * n + Mv::l(n)` (draw §2.7: index 6 of each up-table
is the neutral baseline — braille `⣀`, block `▄`, tty `░`, all disk-verified at btop_draw.cpp:89-133; the draw
report's "▒-ish" hedge for tty is wrong; SugarCraft emits the overdraw via canvas composition, not cursor moves —
draw §10 row 13 "COVERED-BY-SUPERSESSION").

Coloring law (draw §2.7): `height==1` → per-cell color from `max(last,cur)` through the gradient, emitted only
when the glyph is non-blank; `height>1` → one color per row from `at(100-((i-1)*100/height))` (vertical gradient
through the graph; inverted family when `invert`). Port both into `render()`; the L2 memo computes stops.

**Tests:** snapshot byte — the `_create` golden battery: fixed 2×n sample pairs across all band transitions
assert exact glyph strings for all 4 families (table-index law is the bug-magnet); coercion — offset/max_value
percent-scaling edges (negative, >max, `no_zero` bottom-row clamp_min=1 vs 0 elsewhere); cell-grid mode via
candy-testing `assertCellGrid`; underlay polarity (non-blank-only color emission). Est. ~260 LOC src / ~350 LOC
tests. **Regressions:** none to existing classes (new file + constants); dash suite + charts (which will consume
it after L1). `tools/check-child-lifetimes.php` / path-repos rc=0 unaffected (no proc_open).

### ✅ L4 — Position-colored meter (dash Meter/PositionGradient + bits Progress note) — `1bbbca37f`

**Closes:** draw §0 gap 2 + §2.6 (btop `Draw::Meter`, btop_draw.cpp:397-419): every `■` (U+25A0) cell is colored
at **its own position** — `y = round(i*100/width)`; filled cells (`value >= y`) take
`Theme::g(gradient).at(invert ? 100-y : y)`, the unfilled tail takes `meter_bg` en bloc; whole result memoized
per value 0-100. This is NOT the bar's value color (what Threshold/Gauge do today, charting §2.1/§2.3).

**API (dash)** `sugar-dash/src/Plot/Chart/Meter.php` upgrade (class exists, Meter.php:22 per tea §2):

```php
public function withGradient(array $stops, bool $positionWise = false): self;
// positionWise=true -> btop Meter law: cell i colored at round(i*100/width) through a 101-stop memo.
public function withGlyph(string $glyph = '■'): self;
public static function cacheKey(array $stops, int $width, bool $invert): string; // 101-entry string memo (draw §2.6 cache)
```

`positionWise=false` keeps today's flat/Threshold behavior byte-identical for existing consumers (donut/gauge
family, candy-query MeterCell, charting §8). **API (bits)**: VERIFIED on disk `sugar-bits/src/Progress/Progress.php`
already exposes `withColorFunc(?\Closure):207` invoked as `fn($i, $cells, $percent)` — **$cells is the FILLED count,
not total width** (VERIFIED ~:428-440), so btop position coloring is only expressible via a closure capturing the
width. Add the missing third arg cleanly:

```php
/** BC note: existing closures receive $cells as before; new param appended. */
public function withColorFunc(?\Closure $fn): self; // now called fn(int $i, int $cells, float $percent, int $width): ?Color
```

and document (CALIBER_LEARNINGS) that `Progress` + `withGradient` covers btop meters once width is known; do NOT
duplicate a second position-gradient bar in bits.

**Tests:** snapshot byte — width 10, stops cyan→red, value 50 → the 5 filled cells carry stops at 10,20,…,50 and
tail is meter_bg, exact SGR runs; `invert` polarity (battery-discharge use, draw §3.1 battery Meter(10,"used",true));
memo hit-count pin (render 101 values, assert ramp expansion ran once — coercion mode); bits: width-param closure
receives true width (behavior mode) + existing consumer closures still receive old args (positional BC pin).
Est. ~80 LOC dash + ~10 LOC bits / ~150 LOC tests. **Regressions:** bits Table/Progress consumers (sugar-prompt,
sugar-glow, sugar-stickers, candy-query's bits surface — all verified `require` sugarcraft/sugar-bits in
composer.json; candy-shell does **not** and is dropped from this gate), dash consumers (candy-query,
sugar-charts). Run bits suite era figures + sugar-prompt 158T + glow + stickers + query filter.

### ✅ L5 — candy-sprinkles: Border::withTitle + junction seams — `57cd08b9f`

**Closes:** draw §0 gap 3 + §10 rows 8, 9. Verified on disk: `Border.php:42-122` has normal/rounded/thick/double/
block/ascii/hidden presets (charting §6 — so "rounded/line presets into Stereotype" from the brief is moot:
presets exist, no `Stereotype` class exists anywhere in the repo, §8); what's missing is btop's
border-**embedded** text: `─┐clock┌──`, `──┘vram└──`, `┐freq┌`, and the `├ ┤ ┬ ┴` junction chars where seams meet
a frame (btop Symbols, draw §2.1: `div_left ├ div_right ┤ div_up ┬ div_down ┴`; title embeds
`title_left ┐ / title_right ┌` top, `┘/└` bottom).

**API** (candy-sprinkles, Style-level + a frame helper; house immutable):

```php
/** Embed $text into a border run, btop createBox/update_clock law (draw §2.4/§2.5).
 *  $side: 'top'|'bottom'; returns [newStyle, emittedCells] — caller positions after Width::of math. */
public function withTitle(string $title, ?string $junctionLeft = null, ?string $junctionRight = null): self;
// render paints: h_line*pad + '┐' + bold(title) + '┌' + h_line*pad  (top); '┘'/'└' pair (bottom)
public static function seamBorder(string $horizontal, string $vertical, bool $left, bool $right, bool $up, bool $down): string;
// '─'→'├'/'┤'/'┬'/'┴' substitution at seam endpoints — the §3.3 mem|disk '│' seam + ├/┤ law
```

Erase-and-re-embed on width/second change (draw §2.5 "erases the previous span by overwriting with cpu_box-colored
h_lines") is **frame-level** and lands in candy-top's View layer — with cell-diff supersession (draw §10 row 13)
no ANSI-erasing helper is required in sprinkles; note this in the docblock so nobody re-adds it.

**Tests:** snapshot byte — `╭─┐ label ┌─╮` exact runes incl. rounded vs square family switch and odd-padding
distribution; coercion — title longer than border (clamp with `…` via Width, draw §2.9 uresize law), empty title
byte-identical to untouched style; seam matrix — all 16 seam-flag combos → expected rune. Est. ~140 LOC src /
~220 LOC tests. **Regressions:** sprinkles is the most-depended styling lib (751T/2629A era figure — campaign
record); consumers sugar-boxer (default border `Border::rounded()`, VERIFIED SugarBoxer.php:814-871), candy-top
later, crush (untouched surface — additive only). Gate: full sprinkles + boxer (187T/375A era) suites.

### ✅ L6 — sugar-dash: distance-fade + ProcRowComposer mini-sparkline composite — `0859cfa4f`

**Closes:** draw §0 gap 6, and §3.5 proc coloring + per-row 5×1 cpu graphs (§3.5 lifecycle: created on first
cpu>0, erased after 10 consecutive samples <0.1%, skipped on the selected row, sits on graph_bg). Today the
composite is "known campaign gap" (draw §10 row 26): nothing in the monorepo builds it. Technique source =
btop's proc-list row graphs (draw §3.5). This class is **NET-NEW composition** — no prior `ProcRowComposer`
exists anywhere (an earlier draft of this plan cited one in candy-query as an exemplar; phantom, struck —
§8-8). The genuinely existing candy-query `MultiSeriesCell` (`src/Admin/Dashboard/MultiSeriesCell.php`) is
cited only as proof that compact ring-windowed braille raster painting is in real demand — it is a dashboard
timeline widget (per-series colored polylines via dash `Bresenham`+`BrailleMatrix`), not a proc-row composite.

**API** (`sugar-dash/src/Plot/DistanceFade.php` + `sugar-dash/src/Plot/ProcRow/ProcRowComposer.php`, new files):

```php
/** btop proc_gradient law (draw §3.5): row colored through g("proc") = main_fg→inactive_fg by
 *  calc = |selectedRow - row| ; flat main_fg when disabled. */
public static function fadeColor(Color $fg, Color $inactive, int $calc, int $selectMax): Color; // blend1d memo, 101 stops

/** btop blended metric color (draw §3.5): val=(min(v,100)+100)-calc*100/select_max;
 *  val<100 -> g("proc_color").at(max(0,val)); else g("process").at(val-100). Threads: val/3. */
public static function metricColor(array $procColor, array $process, int|float $v, int $calc, int $selectMax): Color;

/** One process row: [prefix?][pid][graph5x1][mem][cpu%][user][cmd] with fade + metric coloring,
 *  graph lifecycle owned by caller map, 5x1 DualSampleGraph (L3) on graph_bg underlay (L3). */
final class ProcRowComposer { public static function new(array $opts): self;
    public function row(array $proc, int $rowIndex, int $selected, bool $selectedFlat, bool $gradient): string; }
```

**Tests:** snapshot byte — golden row with planted 5-sample cpu history renders the exact braille quintuple +
underlay on cold start; coercion — calc=0 selected row bypasses fade (bar covers, draw §3.5), graph destroyed
after the 10-idle-sample rule (behavior mode on the lifecycle helper); metricColor table vs hand-computed btop
formula. Est. ~200 LOC src / ~300 LOC tests. **Regressions:** dash-only new files; gate dash suite + candy-query
(`MultiSeriesCell` is the adjacent prior-art consumer, nothing moves out of it; its inline raster copies stay —
retirement is a follow-up decision, §8-4).

### ✅ L7 — sugar-dash Foundation: net autoscale hysteresis + ThemeGradientStore — `07a1e5e6f`

**Closes:** draw §0 gap 4 (theme registry/loader unbuilt — "candy-core has the interpolation primitive but no
palette registry") and §3.4 autoscale (collect.cpp:2993-3073 law). Both are reusable system-monitor foundations
that fit dash `Foundation/` (Threshold's neighbor, charting §2.3).

```php
// sugar-dash/src/Foundation/NetAutoScale.php — btop net_auto hysteresis (draw §3.4):
public function offer(float $bytesPerSec): void;   // per-direction counters max_count[up/down][fast/slow]
public function maxValue(): float;                 // rescale when any counter>=5 -> max(avg(last5)*{1.3|3.0}, 10KiB)
public function syncFrom(self $other): void;       // net_sync: copy recomputed max, skip bump when other dir faster
public function forceRescale(): void;              // iface change/loss

// sugar-dash/src/Foundation/GradientStore.php — 101-stop cache keyed by name (draw §6):
public static function ramp(Color $start, Color $mid, Color $end): array; // two blend1d legs, cached
public function at(string $name, int $percent): Color;                    // clamp 0-100, btop Theme::g(name)[i]
public function derive(string $name, Color $a, Color $b): void;           // the injected proc / proc_color pseudo-gradients
```

**Tests:** hysteresis table-driven — planted speed sequences assert rescale fires exactly at the 5-count boundary
and the 10 KiB floor (coercion mode); sync polarity; ramp cache: same stops → same array identity (memo pin),
mid-stop exactness at 50 (snapshot byte via Color::blend1d, charting §4). Est. ~150 LOC src / ~150 LOC tests.
**Regressions:** new Foundation files, nothing rewired; gate dash suite. candy-top's Theme/ (Phase 3) will consume
GradientStore + port the `.theme` ini loader in-app (loader is app-specific key vocabulary, §8).

### ✅ L8 — sugar-bits: underline-cursor TextEdit + option-highlight convention — `39c65280c`

**Closes:** draw §0 gap 5 + §2.3 (Draw::TextEdit — inline filter/option editor: underline-as-cursor, no real
terminal cursor; empty field renders `Fx::ul + " " + Fx::uul`; window carved keeping ~half columns each side of
caret when over-limit; numeric mode; UTF-8/wide-aware) and §5 options row layout (`cjust(name,29)` selected row +
`cjust(value,25)`, edit arrows `←/→` at fixed x-offsets, `↵` enter glyph — a *convention* helper so every
consumer's options screen matches btop's geometry).

**API** (`sugar-bits/src/Input/TextEdit.php`, new final class — distinct from the forms-loop TextInput):

```php
public static function new(string $text = '', ?int $caret = null): self; // null caret = end
public function withCaret(int $pos): self; public function insert(string $chars): self;
public function backspace(): self; public function delete(): self; public function clear(): self;
/** Render inline within $limit display cells, windowing around the caret (draw §2.3 operator()(limit)). */
public function view(int $limit): string;
public function withNumeric(bool $on = true): self;   // digits only, btop signal/renice fields
```

**API** (`sugar-bits/src/Menu/OptionRow.php`): `public static function render(string $name, string $value,
bool $selected, bool $editing, bool $hasArrows, int $nameCol=29, int $valueCol=25, ?Style $selBg=null,
?Style $selFg=null): string` — the btop two-line option geometry above.

**Tests:** snapshot byte — caret under a wide (CJK) char emits the exact underline SGR pair around the right
cluster; window edges (len 40, limit 9, caret at 0/middle/end — half-budget law, coercion); numeric rejection of
non-digits (insert no-op pin); OptionRow golden two-line pair selected/unselected. Est. ~200 LOC src / ~250 LOC
tests. **Regressions:** bits suite + prompt/glow/stickers consumers (additive files; gate same lanes as L4).

## 3. candy-top app architecture

Per tea §2 verdict: collectors live in `candy-top/src/Collect/` — **do not extend sugar-dash's SystemModule**
(its Module/ProcAvailability idioms are liftable, the lib stays shared). TEA skeleton per tea §1
(Model/Cmd/subscriptions, tick re-arm, resize via SIGWINCH→WindowSizeMsg Program.php:1569-1572,
throttle-in-update not subscriptions — candy-query App.php:906-919 canonical gotcha).

```
candy-top/
  bin/candy-top            # launcher per candy-query/bin/candy-query:8-53 IIFE-autoload shape, Program(
                           #   App::start(...), new ProgramOptions(useAltScreen:true, mouseMode:..., framerate: 20.0))
  src/Collect/             # ✅ b2d9207cc — ALL readers: injectable $paths + \Closure $clock (HostLoadSampler shape,
                           #   HostLoadSampler.php:27-28,66-77), UNMEASURED=-1.0 / 'n/a' sentinel law
                           #   (ProcAvailability, tea §2 — E731 COMP-2 option (b)).
    Cpu.php                # /proc/stat cpu0..N jiffies deltas (user,nice,system,idle,iowait,irq,softirq,steal);
                           #   per-core % + total; aggregate+loadavg model mirrors HostLoadSampler.php:252-283
                           #   (total/busy, clamp, aliasing guard, honest gap on read failure)
    Memory.php             # /proc/meminfo MemTotal/Available/Buffers/Cached/Swap* → btop class buckets used/
                           #   available/cached/free + swap (draw §3.3)
    Net.php                # /proc/net/dev per-iface rx/tx; auto-pick connected→highest-total (draw §3.4 policy);
                           #   hysteresis via dash NetAutoScale (L7)
    DiskIo.php             # /proc/diskstats read/write sectors→B/s; /sys/block filtering, fstab/only_physical
                           #   parity subset (draw §3.3)
    ProcList.php           # /proc/[pid]/{stat,status,cmdline}; USER via posix_getpwuid cache (pwuid map, draw §3.5
                           #   user col); kernel-thread filter, aggregate-by-name option; ENOENT mid-scan → skip
                           #   silently (Phase-7 risk R2); cpu_p history rings per pid + 10-idle destroy via L6
    Temp.php               # /sys/class/thermal/thermal_zone*/{temp,type} + /sys/class/hwmon walk (coretemp family,
                           #   draw §3.1 cpu temp / btop cpu_sensor_list semantics)
    Battery.php            # /sys/class/power_supply/*: status else online+percent heuristic; time-to-empty
                           #   energy_now/power_now → charge_now/(current_now*voltage_now µW/µA/µV fallback) (draw §3.1)
    Freq.php               # /sys/devices/system/cpu/cpu0/cpufreq scaling_cur/min/max + boost (draw §3.1 freq label)
    Mounts.php             # /proc/mounts + disk_free_space/statvfs per physical device
    Gpu.php                # nvidia-smi --query=... shell-out, memoized-absent -1.0 (SystemModule precedent, tea §2)
  src/App.php              # root TEA Model: size + theme + boxes layout state + child panel Models;
                           #   update() routes per-panel msgs by id; init() = banner → Cmd::batch(tick, colorProfile)
  src/Model/               # child models: CpuPanel/MemPanel/NetPanel/DiskPanel/ProcPanel/ClockState —
                           #   each owns its RingBuffer history + data_same memo (draw §1 'X::collect(no_update)
                           #   + draw(force_redraw, data_same)') and throttles its own tick re-arm
  src/View/                # pure renderers composing L1-L8 primitives:
    FrameBuilder.php       # 6-panel layout math = calcSizes port (draw §2.8): ratios cpu 100w/32h, mem 45/40,
                           #   net 45/28, proc 55/68; minimums cpu 60×8, mem 36×10, net 36×6, proc 44×16;
                           #   cpu_bottom/mem_below_net/proc_left flags; seam junctions via L5 seamBorder
    CpuView.php            # upper/lower DualSampleGraphs (L3) + divider row `├─ Upper▲▼Lower ─┤` + per-core grid
                           #   (graph_bg underlay + rjust % + │ separators + inactive-core dim) + Meter row (L4)
                           #   + load/freq/watts footers + clock/battery/title-button border embeds (L5)
    MemView.php NetView.php DiskView.php ProcView.php  # mirror draw §3.3/§3.4/§3.5; proc via L6 ProcRowComposer
    Overlays.php           # menus/help/signals/msgboxes (draw §5) — veil-styled dim = SGR-strip + inactive_fg
                           #   (draw §1 overlay law, sugar-veil backdrop, draw §10 row 36)
  src/Theme/               # ✅ 672c64680 (+ themes/ 41 files, btop oracle)
    ThemeConfig.php        # 43 semantic keys + 9 families (hexes: draw §6 verbatim Default table);
                           #   .theme ini parser `theme[key]="#hex"` (+ # comments, 'r g b', #GG gray forms)
                           #   → dash GradientStore (L7) ramps; pseudo-derivations proc/proc_color injected
    BtopThemes.php         # ported upstream themes/ data files (41 .theme files — disk-verified 2026-10-08;
                           #   env §4's "43 named" is false, draw §6 correct)
                           #   under candy-top/themes/*.theme — loaded by path, Default+TTY builtin-first
                           #   (draw §6 discovery order), live-cycle via options (preview-behind-overlay)
    TtyTheme.php           # 16-color stepped variant: gradient stops at 33/66 (draw §6 degradation)
  src/Config/              # rc reader/writer ~/.config/candy-top/config.conf, key=value, subset of draw §9
                           #   defaults; unknown keys ignored (forward-compat), AtomicJsonFile-style atomic write
                           #   per candy-core P2 (withPermissions(0600) not needed — public config)
  src/Lang/ + lang/en.php  # i18n wrapper Lang::t for user-facing strings (AGENTS.md i18n law)
```

**TEA wiring recipe** (inline, per tea §1 — every panel deviates nowhere):
1. `init()` returns `Cmd::batch(Cmd::tick(1/framerate… no: per-panel `Cmd::tick($secs, fn) ) …)` — btop default
   `update_ms=2000` (draw §8), min 100; `+/-` adjust ×100 steps, hold ×1000 (draw §4 repeat-acceleration).
2. Every panel `update()` on its TickMsg: **re-arm `Cmd::tick` unconditionally** (candy-core ticks are one-shot)
   and apply the throttle decision per-fire there — never branch in `subscriptions()` (id-lock gotcha).
3. `WindowSizeMsg` (SIGWINCH, Program.php:1569-1572): recompute `FrameBuilder` sizes, rebuild mouse zones, force
   full redraw (clears every `data_same` memo), re-embed clock/battery spans (L5).
4. Quit: `q`/`Ctrl-C` (`catchInterrupts`) → `Cmd::quit()`; restore sequence = show cursor + mouse-off + primary
   screen (ProgramOptions handles via candy-core teardown; DEC 2026 sync wraps each frame, charting §6/§7 covered).
5. Mouse: per-frame rebuild of zone registry (btop `mouse_mappings` law, draw §2.8/§4): title buttons, per-core,
   proc rows/scrollbar track (proportional-click math draw §3.5: `start=round(y*(numpids-selectMax-2)/(selectMax-2))`),
   menu buttons → candy-mouse `Mark`/`Get` zones → synth KeyMsg dispatch (draw §10 row 38 adapter).
6. Tests bootstrap: `candy-top/tests/bootstrap.php` calls `LoopPin::pinStableClock()` FIRST (AGENTS.md; suites
   arming timers on `Loop::get()`), then `HangWatchdog`-style guards are optional (no proc_open in lib; candy-pty
   pattern not needed since collectors are pure file reads).

## 4. Phased build order (app, after library lanes)

Each phase ends with: targeted tests green + full `candy-top` suite + independent review → fix → re-review until
0C/0M, then commit to master (campaign law). Fake collectors (fixed fixtures) before P-E so renderers are testable
from P-A.

| Phase | Deliverable | Acceptance demo | Suite growth |
|---|---|---|---|
| P-A | Frame + theme load + clock + quit + resize, fake collectors | `php bin/candy-top` draws 6 bordered panels w/ embedded clock, `q` exits clean, resize reflows seams | +~15 tests (frame snapshot, resize coercion) |
| P-B | CPU + MEM panels: real Cpu/Memory, DualSampleGraph upper/lower, per-core grid, Meter row, load/freq | live per-core % ticking, gradient ramps visible | +~20 (snapshot golden ×2 panels, collector delta math) |
| P-C | NET panels: Net collector, dual graphs, hysteresis autoscale, stats sub-box, iface buttons b/n/z/a/y | up/down graph autoscales on scp burst, `z` zeroes | +~20 (hysteresis table, iface pick policy) |
| P-D | DISK + BATTERY: Mounts/DiskIo/Temp/Battery, mem_graphs vs meters toggle, io_mode mirror graphs (L3 invert), border battery + watts | `i` flips disk graph modes | +~15 |
| P-E | PROC list: ProcList, sorter incl cpu-lazy rotation (draw §3.5: pull >30% or >max-of-top-6 hogs forward, btop_shared.cpp:132-150), filter `f`/`!` regex, distance fade + metric blend (L6), vi/arrows/page keys, scrollbar click/drag, detailed view, kill/signal popup | full scroll+filter+sort loop; `k` signal grid 5-col 16-skip | +~30 |
| P-F | Config menu + keybindings overlay: options screen via L8 OptionRow/TextEdit, presets load-only (draw §4 triples), `ctrl_r` hot-reload | edit `update_ms` live via `+/-` persisted | +~15 |
| P-G | Themes ported (all shipped `.theme` files), TTY mode fallback, VHS demo tape (`candy-top/.vhs/top.tape`: `Set Theme "TokyoNight"`, quoted values, `Type "php examples/top.php"`, deterministic seeded collectors per tea §5 — fake /proc fixtures), polish, README examples/ | CI re-renders demo GIF; `--tty` demo | +~10 |

## 5. Monorepo integration checklist (still-open add-a-lib items)

Scaffold (composer.json/phpunit.xml/src/Top.php/tests/README/CALIBER_LEARNINGS) already landed @ `31e4dd8ff`.
Remaining per AGENTS.md "Adding a lib — checklist":
- root `composer.json`: require `sugarcraft/candy-top: "@dev"` + `repositories[]` entry (root manifest keeps its
  own — lib manifests never get one, path-repo-closure law)
- `MATCHUPS.md`: row `aristocratos/btop → SugarTop/CandyTop → candy-top/ → SugarCraft\Top\ → 🔴` (no btop row
  exists yet — verified on disk in `docs/MATCHUPS.md` directly; the charting report has no MATCHUPS section)
  + note the user naming ruling (§8)
- `PROJECT_NAMES.md`: record `candy-top` under a naming-exception note (heuristic says system/app → `sugar-`;
  owner explicitly chose `candy-top`, §8)
- root `README.md` lib table row
- `docs/_data/candy-top.json` + `docs/_data/candy-top.body.html`, then `php tools/gen-docs.php` (never hand-edit
  `docs/lib/candy-top.html`)
- `media/icons/candy-top.png`
- `.github/workflows/vhs.yml`: `all=(… candy-top …)` array entry + the P-G tape
- `codecov.yml`: flag + component for candy-top
- `scripts/affected-libs.php` — verify auto-discovery already lists candy-top (it maps monorepo→split dirs
  dynamically; current state per brief: already picks it up)
- packagist: owner-side entry pending (`github.com/sugarcraft/candy-top` synced @ `01bab2d8`) — note only
- candy-top `composer.json` deps review after lanes: candy-flip/candy-vt/mosaic stay OUT (tea §6 minimal set);
  sugar-bits + candy-mouse move IN (L8/L6 consumers), sugar-dash + sugar-charts already required; candy-async not
  needed (React loop rides candy-core)

## 6. Verification & cadence

- **Every step:** targeted tests for touched classes (AGENTS.md modes: snapshot byte `view()`→raw SGR, cell-grid
  via `SugarCraft\Vt\Terminal`, behavior `update()`→[Model,?Cmd], coercion clamp edges; stream-write slice idiom
  per `candy-core/tests/RendererTest.php`).
- **Every lane end (Phase 2):** that lib's FULL suite, plus every listed consumer suite in the same session
  (L1/L2/L3/L4 → sugar-charts + sugar-dash + candy-query filter + sugar-tick + sugar-prompt; L5 → candy-sprinkles
  + sugar-boxer; L6/L7/L8 → dash/bits + consumers named).
- **Review gate:** independent reviewer per commit → fix → re-review until APPROVE 0C/0M, then commit direct to
  master (campaign cadence: small review-gated steps; author Joe Huss <[EMAIL]> via split-var env, no
  Co-Authored trailers).
- **sugar-crush:** untouched by every lane and phase (zero crush files) — **no crush suite-figure re-pin expected,
  no durations.tsv rows, no docfigure drift risk newly minted** beyond the additive doc claims (each doc claim in
  candy-top's README cites a tested class; crush ReadmeRosterDrift family does not police candy-top files).
- Suite-figure law: candy-top's own tests grow the lib-local figure only; if `scripts/affected-libs.php` CI matrix
  or `tools/check-child-lifetimes.php` rosters ever need a candy-top row (proc_open appears in a collector test —
  Gpu nvidia-smi shell-out!), the fail-closed roster gets the row in the same commit.

## 7. Risks

- **R1 — pcntl/posix for the proc table**: `posix_getpwuid` is ext-posix (present in CI); no pcntl needed for
  reads. Gate uid lookups behind `function_exists` with uid-number fallback string — never fatal on a slim host.
  FFI stays out of scope (AGENTS.md FFI tests gate rule not applicable — pure file reads).
- **R2 — /proc races**: pids vanish mid-scan (ENOENT), files truncate mid-read. Law: per-entry try/catch →
  skip silently, next tick re-snapshots; never fail a frame for one vanished process (btop tolerates the same).
- **R3 — Unicode widths**: braille U+2800 block, `■ █ ▄ ▟` quadrants are all width-1 in sprinkles `Width`
  (charting §6 wide-rune aware Canvas; draw §7 full inventory already width-checked) — no new width law needed,
  but L3 snapshot tests pin cell counts through `Width::of` to prove no drift.
- **R4 — Performance budget**: btop itself targets ~update_ms=2000 frames with full string rebuilds (draw §1);
  SugarCraft TEA paints at `ProgramOptions::$framerate` with cell-diff (draw §10 row 13 supersedes btop's
  ANSI-length scroll-diff). **Decision: target 10-20 fps paint, default data tick 2 s** — collector syscalls
  (proc table especially, ~N×3 file reads) bound the real cadence well below 60; 60 fps buys nothing for a
  monitor and burns CPU on every user's machine. Per-panel `data_same` short-circuits (draw §2.7 diff law) keep
  dirty-frame cost low; benchmark ProcList over 500 fake pids <50 ms in P-E test, not CI-blocking.
- **R5 — Windows/BSD**: explicitly out of scope (§1); UNMEASURED/'n/a' sentinel law (tea §2) is what keeps a
  non-Linux run honest (empty panels, no crash) rather than pretending support.
- **R6 — Lanes L1-L8 drift risk to siblings**: mitigated by byte-identical-default guarantees (each API above
  ships an unchanged default path) + consumer suites in-lane (Phase 2 per lane).

## 8. Decision log

1. **Naming — `candy-top` kept.** PROJECT_NAMES heuristic reads system/app → `Sugar-` (tea §6 flagged exactly
   this: "a system monitor app arguably reads sugar-top"). Owner explicitly chose **candy-top** anyway; ruling
   stands, recorded in PROJECT_NAMES.md as a named exception (AGENTS.md naming line cites `Candy-`
   foundation/system — a system monitor arguably IS "system", which is the defensible line; document both sides).
2. **Collectors in-app (`src/Collect/`), not sugar-dash.** tea §2 verdict: build fresh, injectable-path readers
   "modeled on HostLoadSampler's shape" (tea §3's inventory lists it as the monorepo's only jiffies-delta
   collector, injectable `$paths`/`$clock` at :27-28,66-77); dash's shared
   Module/BaseModule idioms stay untouched; a future extraction to its own lib is allowed but unplanned.
3. **No new rendering lib.** draw §10 "net" floats authoring the visual-language core "as a new small lib
   (candidate: sugar-top or extensions to sugar-dash Plot)". Rejected in favor of: quantizer+meters+fade in
   **sugar-dash** (it already owns the braille/buffer/Threshold/gradient vocabulary L1-L8 build on), per-point
   color callback in **sugar-charts** (per-series routing already landed — §8-4), border embedding in
   **candy-sprinkles**, editor in **sugar-bits** — i.e. the report's
   own alternative ("extensions to sugar-dash Plot") wins; one app lib (candy-top) assembles.
4. **LineChart per-point callback; original "defect" claim superseded.** charting §8's "braille ignores
   per-series colors" defect — repeated by this plan's first draft as "no injection point" — was overtaken by
   Audit-F11 (commit `1ad48ebba`, an ancestor of `31e4dd8ff`): `renderBrailleSeries` already paints per-series
   legend colors resolved from `legendItems()`. The stale wording's origin chain: candy-query `MultiSeriesCell`
   docblock (pre-F11) → charting §8 → this plan. L1's true delta is the per-(x,value) `withSeriesColorFn`
   closure; the `withSeriesColors` map was dropped as a duplicate of the legendItems path. candy-query keeps its
   `MultiSeriesCell` inline raster — half its docblock rationale (no per-series color) is already retired by
   F11, the rest (`setPoint` clone cost) stands; retirement = separate follow-up decision after L1+L3 land, so
   this plan never couples a lane to a consumer rewrite.
5. **Brief discrepancies resolved against disk** (verified 2026-10-08):
   - *"sugar-dash's 43 ported btop themes" — FALSE on disk.* No themes dir, zero `btop` hits in
     `sugar-dash/src/`. Resolution: theme **porting is new work** — the `.theme` files + registry/loader land in
      candy-top `src/Theme/` consuming L7's dash GradientStore; the file-count dispute is resolved on disk:
      **41** `.theme` files (`ls /home/sites/btop/themes/*.theme | wc -l`, 2026-10-08) — draw §6 correct,
      env §4's "43" false. Port all 41.
   - *"sugar-bits BarChart withGradient+withLabels" — misnomer.* bits has no BarChart; its bar is
     `src/Progress/Progress.php` (withGradient:138, withColors:177, withColorFunc:207 all EXIST). Resolution:
     the gradient/label work targets **sugar-charts BarChart** (L1) and the position-color gap targets
     **dash Meter + a width-aware withColorFunc note** (L4); bits needs no new gradient API.
   - *"rounded/line box presets into Stereotype" — no `Stereotype` class exists repo-wide.* Resolution: presets
     already exist as `Border::normal/rounded/…` (charting §6, Border.php:42-122); the real gap is title-embedding
     + seam junctions → **L5**.
   - *btop-draw report shape*: cited sections here are the on-disk 230-line report's actual structure
     (§0 gap-headline, §2 techniques, §3 panels, §6 themes, §8 cadence, §10 mapping table, §11 evidence map); the
     brief's "§11 gap register / §12 build-order" numbering maps to §0+§10+§11 here.
   - *Tick default*: scaffold `DEFAULT_TICK_MS=1000` vs btop `update_ms=2000` (draw §8). Plan adopts **2000 ms**
     as the shipped default to match btop; `Top.php` placeholder constant gets flipped in P-A (app code,
     not a lane).
6. **Theme loader location.** Upstream format is `.ini`-ish per-theme `theme[key]` lines (draw §6,
   btop_theme.cpp:473 LOC); the registry/cache vocabulary is generic → L7 GradientStore in dash; the
   key-name table (43 semantic keys, box borders, pseudo-derivation rules) is btop-specific → stays in
   candy-top `ThemeConfig` (app) so dash doesn't accrete a foreign schema.
7. **Cadence ruling**: 10-20 fps paint, 2 s data tick, clock 1 s (draw §8 `update_clock` per-second runner).
   Synchronized-output DEC 2026 per frame already available (draw §10 row 14 PARTIAL → candy-core escape writer;
   P-A verifies the constant exists, adds a 1-line escape otherwise).
8. **Grounding corrections from the 2026-10-08 independent plan review** (all four re-verified against disk
   before landing here):
   - *L6 phantom exemplar:* an earlier draft cited "candy-query's `ProcRowComposer` example pattern (tea §3)" —
     no class of that name exists anywhere in the repo, and tea §3 is the collector inventory
     (SystemModule/UptimeModule/HostLoadSampler), not a sparkline example. The lane stands as **NET-NEW
     composition** grounded in btop's proc-list row graphs (draw §3.5, review-verified); `MultiSeriesCell`
     (which does exist) is cited only as genuine adjacent prior art for compact braille raster painting.
     The proposed dash class keeps the name.
   - *L1 premise:* superseded-by-Audit-F11 restatement per item 4 above.
   - *tty glyph tables:* btop_draw.cpp:118-132 uses only `' '`,`░`,`▒`,`█` — no `▓` (`▓` is chrome-only,
     draw §7) — and the graph_bg baseline (up-table index 6) is `░`; corrected in L3.
   - *bits consumers:* candy-shell does **not** require sugar-bits; the real requiring consumers are
     candy-query, sugar-glow, sugar-prompt, sugar-stickers — L4/L8 regression gates corrected.
