# STEP 6.3 — Dashboard accuracy: multi-series, level readout, elapsed, InnoDB widgets

> Read `plans/candy-query-fix/COMMON.md` first. **Agent:** `oac:coder-agent`.
> Audit refs: PART 1 §C (buffer-pool formula), §D (Dashboard), issue #4; `docs/mysql_workbench_dash.md` §5.1, §8, Appendix A.

## Why
- `TimeSeriesCell::ingest()` does `array_sum($value)` for tuple calcs, collapsing the multi-series
  SQL-Statements (SELECT/writes/DDL) and PG Transactions graphs into one summed line.
- The connections level meter's `%d / %d` format is dead — `MeterCell::viewLevel()` renders only
  a bar, never the value/max readout.
- `pollAndUpdateCells()` hardcodes `elapsed = 3.0` instead of measuring wall-clock.
- `DashboardPage::getWidgetsForSection()` rebuilds Widget objects every frame (and re-reads
  version) — a second source of truth that can drift from the keyed cells.
- Missing InnoDB widgets (row-lock, pages, insert-buffer — issue #4); buffer-pool-usage uses the
  sidebar formula `(total−free)/total` instead of Appendix A's
  `(Innodb_buffer_pool_bytes_data/Innodb_page_size)/pages_total`.

## Goal
Dashboard cells render accurately per Appendix A / §8.

## Files
- `src/Admin/Dashboard/{TimeSeriesCell,MeterCell,DashboardPage,WidgetCatalog,WidgetRegistry}.php`.
- `src/Admin/Calc/InnoDBBufferPoolUsage.php` (or a new bytes-based calc).
- Tests under `tests/Admin/Dashboard/`, `tests/Admin/Calc/`.

## Do
1. Multi-series timelines: render tuple calcs as separate polylines (multi-dataset `LineChart`)
   instead of `array_sum`. If a clean multi-series widget isn't feasible, document the
   simplification with a `NOTE:` and at least stop silently summing unrelated series.
2. Level meter: render `sprintf($widget->format, $value, $max)` alongside the gauge; thread the
   absolute value + max through `MeterCell` (currently only `ratio` is kept).
3. Measure `elapsed` from the tracked `lastPollAt`/`now` (fallback 3.0 only on the first sample).
4. Cache the per-section widget lists (build once in the constructor / from `allWidgets`); stop
   per-frame catalog rebuilds.
5. Add a bytes-based buffer-pool-usage calc per Appendix A and wire it into `innodb()`; add the
   missing InnoDB widgets (row-lock waits/time, pages flushed/created/read, insert-buffer,
   read-ahead) per issue #4 / `steps/B1_INNODB_WIDGETS.md` (legacy).

## Acceptance criteria
- [ ] Tuple timelines render multiple series (or the sum is removed + documented).
- [ ] Level meter shows `value / max`.
- [ ] `elapsed` reflects real wall-clock between polls.
- [ ] Per-section widgets are not rebuilt every frame.
- [ ] Buffer-pool-usage matches Appendix A; InnoDB widget set extended.
- [ ] Full suite green.

## Out of scope / defer
- Server Status replica/GTID/firewall → 6.4.
