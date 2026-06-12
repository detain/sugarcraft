# STEP 1.3 — ConnectionsPage::update() (selection, tabs, filters, refresh)

> Read `plans/candy-query-fix/COMMON.md` first. **Agent:** `oac:coder-agent`.
> Audit refs: PART 1 §D (Client Connections).

## Why
`ConnectionsPage` has no `update()` method — it inherits the `PageBase` no-op. With keys now
routing (STEP 1.1), the page still ignores everything: no row selection, no detail-tab switch,
no filter toggles, no refresh. All the built-but-unreachable machinery (`withSelectedIndex`,
`withFilters`, `ConnectionDetailTabs`, `ConnectionCounters`) stays dark.

## Goal
A working `ConnectionsPage::update(Msg): [PageBase, ?Cmd]` that handles, immutably:
- `j`/`k` / Up/Down → move selected processlist row (`withSelectedIndex`).
- Detail-tab switch (e.g. Tab or `1/2/3`) across Details / Attributes / MDL
  (`ConnectionDetailTabs`).
- Filter toggles: hide-sleeping, hide-background, don't-load-full-info (`ConnectionFilters`).
- `r` → refresh (re-fetch via the async cache path, not a sync query).

## Files
- `src/Admin/Connections/ConnectionsPage.php` (add `update()`; it already has
  `withSelectedIndex`/`withFilters`/`getTable`).
- `src/Admin/Connections/{ConnectionFilters,ConnectionDetailTabs,ConnectionCounters,
  ProcesslistResult}.php` (read to wire selection→detail).
- `tests/Admin/Connections/*` for coverage hooks.

## Do
1. Implement `update()` returning a NEW page via `mutate()`; map the keys above. Keep selection
   index clamped to the filtered row count.
2. Render the detail panel for the selected row from the active detail tab. Detail-tab DATA
   that needs a DB query must go through the async cache / a `Cmd::promise`, never a sync call
   in `update()`/`view()` — if the detail query isn't cached yet, show a loading state and let
   the next tick fill it. (The actual action queries — kill/instrument — are STEP 1.4.)
3. Memoize the processlist within a render so `getTable`/`withSelectedIndex`/`filteredProcesslist`
   don't each re-fetch (audit §D notes 2-3× fetch per build).

## Acceptance criteria
- [ ] `update()` exists; j/k moves selection; tab switches detail view; filter toggles flip state
      (assert via fake processlist provider).
- [ ] No synchronous DB query introduced on the keystroke/render path.
- [ ] Full suite green; immutable.

## Out of scope / defer
- KILL / KILL QUERY / instrumentation toggle / MDL column fix → STEP 1.4. Postgres connections
  adapter → note `DEFERRED:` (decide in a later phase). EXPLAIN-tab safety → STEP 1.4.
