# Step 03.02 — sugar-dash Grid reorg part 2: chart family

**Source:** `leftover_updates_later.md` Dash-01 (part 2 of 3)
**Branch:** `ai/dash-reorg-charts`

## Deliverable

Move the chart-family files out of `src/Grid/`. Many already have
duplicates under `src/Plot/Chart/` from the stalled migration — **delete
the Grid version, keep the Plot version**.

## Files

**Delete (Grid duplicates, Plot version is canonical):**
`src/Grid/{Bar.php, Donut.php, OHLC.php, RadarChart.php,
Waterfall.php, Bubble.php, Heatmap.php, HeatMapChart.php, Sparkline.php,
SparklineArea.php, SparklineBar.php, SparkArea.php, Pictogram.php,
CandlestickChart.php, FunnelChart.php, GaugeChart.php, Meter.php,
WordCloud.php, Treemap.php, DotMatrix.php, Chart.php, AreaChart.php,
Area.php, AreaPoint.php, GaugeCircle.php}.php`.

For each deletion: confirm `src/Plot/Chart/<Name>.php` exists; verify
the two are byte-equivalent (or the Plot version is newer); update any
external `use` references; delete the Grid version.

**Move (no Plot duplicate exists):**
- `src/Grid/Graph.php` → `src/Plot/Graph/Graph.php`
- `src/Grid/Image.php`, `Picture.php`, `Video.php`, `Audio.php` →
  `src/Components/Media/`
- `src/Grid/Marquee.php`, `Shadow.php`, `QRCode.php`, `Barcode.php`,
  `Emoji.php`, `Icon.php`, `FigletText.php`, `ASCIIBanner.php` →
  `src/Components/Media/`
- `src/Grid/Features.php`, `Transformer.php` → `src/Components/Card/`
  (or appropriate family — check what they actually do first).

**Update:** `use` imports across `src/`, `tests/`, `examples/`.

## Acceptance

- `find sugar-dash/src/Grid -maxdepth 1 -name '*Chart.php' -o -name '*Sparkline*.php'` returns nothing.
- `cd sugar-dash && vendor/bin/phpunit` green.
- `examples/dashboard-charts.php` still runs and renders.

## Notes

- When two parallel files exist, the Plot version generally has more
  recent fixes — verify with `git log`; prefer newer.
- This is part 2 of 3. Do not try to bundle with part 3 (events/state).

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
