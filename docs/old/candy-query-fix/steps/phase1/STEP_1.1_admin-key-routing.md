# STEP 1.1 — Admin key routing (forward keys to pages; preserve page state)

> Read `plans/candy-query-fix/COMMON.md` first. **Agent:** `oac:coder-agent`.
> Audit refs: `candy_query_audit.md` PART 1 §A, §B (the single highest-leverage fix).

## Why
`App::handleAdminKey()` returns `[$this, null]` for every key it doesn't itself handle
(`src/App.php:293`); it only forwards to `page->update()` on `r`. So Tab/Space/Enter/`c`/
navigation keys never reach `DashboardPage`/`VariablesPage`/`ReportsPage`/`PerfSchemaPage`.
Separately, `adminPage` is reset to `null` on every poll tick (`App.php:383` & the fetch
path ~`:667`), so any in-page state (active tab, cursor, pending edits) is destroyed each tick.

## Goal
- Unhandled admin keys are delegated to the active page's `update()` and the returned page +
  Cmd are propagated.
- The active `adminPage` instance survives a data-refresh tick; it is only rebuilt when the
  pane actually changes.

## Files
- `src/App.php` (`handleAdminKey` ~230-293; `adminPage()`/`withAdminPage()` ~399-440; the
  admin-fetch/`withAdminPane` reset paths ~383, ~640-670; `update()` routing ~160-175).

## Do
1. At the end of `handleAdminKey`, before the final return, delegate: `$page =
   $this->adminPage(); [$newPage, $cmd] = $page->update($msg); return
   [$this->withAdminPage($newPage), $cmd];` — but keep the existing app-level keys
   (digits, q, j/k, p, r) taking precedence. Ensure keys the page consumes don't also trigger
   sidebar nav (decide precedence deliberately; document it in a WHY comment).
2. Stop discarding page state on refresh: when new admin data arrives, **update** the existing
   page (e.g. a `withData(...)`/reload on the same instance) instead of setting `adminPage =>
   null`. Only null/rebuild it when `adminPane` changes. Keep the lazy build in `adminPage()`.
3. Preserve immutability: all changes via `mutate()`; `update()` returns a NEW App.
4. Keep admin data flowing through the async cache — do NOT introduce a sync DB call here.

## Acceptance criteria
- [ ] A non-app key (e.g. Tab) routed while in the Admin pane reaches the current page's
      `update()` and its returned page is retained (assert via AppTest with a fake page or a
      real page whose `update()` changes observable state).
- [ ] After simulating a poll-tick data refresh, the page's prior in-memory state (tab/cursor)
      is unchanged.
- [ ] Full suite green; immutability preserved (no `$this->x =`).

## Out of scope / defer
- Don't add new per-page key handlers here (that's 1.2/1.3). Just make routing + state-survival
  work. If a page lacks `update()` (e.g. ConnectionsPage), note `DEFERRED: ConnectionsPage
  update() — STEP 1.3` rather than adding it now.
