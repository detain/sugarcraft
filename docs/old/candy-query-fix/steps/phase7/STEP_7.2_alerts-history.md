# STEP 7.2 — Alert dedup/formatting + history timestamp precision

> Read `plans/candy-query-fix/COMMON.md` first. **Agent:** `oac:coder-agent`.
> Audit refs: PART 1 §E.

## Why
- `DashboardPage::checkAlerts` rebuilds a stateless `AlertManager` every 1s tick and re-fires a
  toast for every still-breached threshold — alert spam while a condition persists.
- `Alert::toToastMessage()` formats every metric as `value*100 %` — nonsense for seconds
  (`slow_query`) and counts (`connection_errors`).
- History stores microtime float `ts` but queries via integer `getTimestamp()`/`(int)` casts,
  truncating sub-second boundaries (can drop the most recent record).

## Goal
- Alerts re-fire only on state change (or after a cooldown), not every tick.
- Per-metric alert formatting (ratio vs seconds vs count, with units).
- Consistent float-epoch timestamps end-to-end in history.

## Files
- `src/Admin/Alerts/{AlertManager,Alert,AlertNotifier,AlertThresholds,Severity}.php`.
- `src/Admin/Dashboard/DashboardPage.php` (`checkAlerts`).
- `src/Admin/History/{HistoryRecorder,HistoryQuery,SqliteHistoryStore}.php`.
- Tests under `tests/Admin/Alerts/`, `tests/Admin/History/`.

## Do
1. Track last-fired state per alert key (timestamp or breached/cleared flag) so a notification
   fires on transition into breach (and optionally a "recovered" notice), not on every tick.
   Keep the dedup-by-key in the pending store.
2. Add a unit/metric-kind to `Alert` (ratio/seconds/count) and format accordingly in
   `toToastMessage()` — no blanket `*100 %`.
3. History: bind float epoch (`(float)$dt->format('U.u')`) consistently in store + query; stop
   truncating to integer seconds.

## Acceptance criteria
- [ ] A persistently-breached threshold fires once on entry, not every poll (assert across
      multiple ticks).
- [ ] `slow_query`/`connection_errors` alerts render sane units, not percentages.
- [ ] History round-trips sub-second timestamps without dropping boundary records.
- [ ] Full suite green.

## Out of scope / defer
- Dead-code cleanup → STEP 7.3.
