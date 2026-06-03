# candy-query Implementation Update Plan
## Phase 7 Follow-Through: Outstanding Fixes from Audit

---

## Context

A comprehensive audit of `candy-query` against the `candy_queries.md` orchestration plan and `mysql_workbench_dash.md` notes revealed ~14 issues ranging from critical (missing PerfSchemaPage wiring) to informational (stale comments, missing CSV export stub).

This plan addresses all outstanding items in a structured, phased approach with full review/test/doc cycles per step.

---

## Issue Inventory

| # | Severity | Area | Issue | Fix Approach |
|---|----------|------|-------|--------------|
| 1 | CRITICAL | Admin Pane | PerfSchemaPage not wired to any AdminPane | Add `PerfSchema` case + App mapping |
| 2 | CRITICAL | Admin Pane | 6 panes don't match MySQL Workbench 6-section layout | Remap pane→page assignments |
| 3 | MEDIUM | Comments | Stale comment: "ConnectionsPage does not extend PageBase" | Remove outdated note |
| 4 | MEDIUM | Dashboard | Missing InnoDB widgets (row lock, pages, insert buffer) | Add to WidgetCatalog |
| 5 | MEDIUM | Postgres | PostgresAdminProvider dashboard is stub (returns empty) | Implement checkAllMetrics() |
| 6 | MEDIUM | ReportsPage | `withExport()` is a no-op stub | Wire CsvExporter |
| 7 | MEDIUM | MysqlAdminProvider | Always uses SHOW PROCESSLIST, never PS path | Add PS-based processlist |
| 8 | LOW | Alerting | AlertManager exists but not wired into Dashboard polling | Integrate alert checks |
| 9 | LOW | History | HistoryRecorder exists but not in App polling loop | Wire as StatusSnapshotProvider |
| 10 | LOW | Docs | `AdminProviderInterface::forFlavor()` doesn't exist | Fix API table in docs/lib/candy-query.html |
| 11 | LOW | candy-async | Plan said use AsyncOps::throttle, but StatusPoller uses manual check | Document as "by design" or migrate |
| 12 | LOW | ReportsPage | withExport() stub - CSV export not implemented | Full implementation |
| 13 | LOW | ServerStatusPage | SidebarGaugeSet exists but not rendered | Add to ServerStatusPage build() |
| 14 | LOW | composer.json | Missing candy-metrics path repo | Add for optional history backend |

---

## Phase Breakdown

### Phase A — AdminPane / PerfSchema Wiring (CRITICAL)
**Steps:** A.1, A.2, A.3

### Phase B — Dashboard Widget Completeness (MEDIUM)
**Steps:** B.1, B.2, B.3

### Phase C — Postgres Dashboard (MEDIUM)
**Steps:** C.1

### Phase D — Reports CSV Export (MEDIUM)
**Steps:** D.1

### Phase E — MysqlAdminProvider PS Path (MEDIUM)
**Steps:** E.1

### Phase F — Alerting Integration (LOW)
**Steps:** F.1

### Phase G — History Wiring (LOW)
**Steps:** G.1

### Phase H — Documentation Fixes (LOW)
**Steps:** H.1

### Phase I — ServerStatus Sidebar Gauges (LOW)
**Steps:** I.1

### Phase J — Final Polish (LOW)
**Steps:** J.1, J.2

---

## Step Instruction Files

Each step has an instruction file in `steps/`:
- `steps/A1_PERFSCHEMA_PANE.md` — Add PerfSchema case + App mapping
- `steps/A2_ADMIN_PANE_FIXES.md` — Fix pane→page mapping inconsistencies  
- `steps/A3_STALE_COMMENTS.md` — Remove/update outdated comments
- `steps/B1_INNODB_WIDGETS.md` — Add missing InnoDB metrics
- `steps/B2_WIDGET_CATALOG_CLEANUP.md` — Reorder/verify widget definitions
- `steps/B3_POSTGRES_WIDGET_CATALOG.md` — Complete PostgresWidgetCatalog
- `steps/C1_POSTGRES_DASHBOARD.md` — Implement PostgresAdminProvider metrics
- `steps/D1_CSV_EXPORT.md` — Wire CsvExporter into ReportsPage
- `steps/E1_PS_PROCLIST.md` — Add PS path to MysqlAdminProvider
- `steps/F1_ALERT_INTEGRATION.md` — Wire AlertManager into DashboardPage
- `steps/G1_HISTORY_WIRING.md` — Connect HistoryRecorder to App
- `steps/H1_DOC_FIXES.md` — Fix docs/lib/candy-query.html API table
- `steps/I1_SIDEBAR_GAUGES.md` — Add SidebarGaugeSet to ServerStatusPage
- `steps/J1_COMPOSER_REPOS.md` — Add missing path repos
- `steps/J2_FINAL_REVIEW.md` — End-to-end review

---

## Per-Step Agent Cycle (repeat for every step)

For each step N:

```
1. CODER agent    → implements step per steps/NN_*.md spec
2. REVIEWER agent → reviews diff vs spec, checks correctness/security
3. FIXER agent    → fixes reviewer findings (loop until clean)
4. TESTER agent   → adds/updates PHPUnit tests, targets 95% coverage
5. SCRIBE agent   → updates README, docs/lib/candy-query.html, CALIBER_LEARNINGS.md
6. SHIP           → commit → push → PR → merge → git checkout master && git pull
```

---

## Concurrent Steps

The following steps have NO interdependencies and MAY run concurrently IF the supervisor schedules them that way:

- **B1** (InnoDB widgets) + **D1** (CSV export) + **E1** (PS processlist)
- **A3** (stale comments) + **H1** (doc fixes) + **I1** (sidebar gauges)
- **F1** (alerting) + **G1** (history wiring) + **J1** (composer repos)

**IMPORTANT**: When running concurrently, each subagent works on its own branch (`ai/candy-query-step-{slug}`). Do NOT merge concurrently — serialize the ship phase.

---

## Prerequisites

- All steps assume `cd /home/sites/sugarcraft/candy-query && composer install` has been run
- PHP 8.3+, PHPUnit 10, ext-pdo_mysql, ext-pdo_pgsql, ext-pdo_sqlite available
- No live MySQL/PostgreSQL required — use fakes/test doubles per convention

---

## Ship Cadence

For EACH step after its test+doc phase:

```bash
cd /home/sites/sugarcraft/candy-query
git checkout -b ai/candy-query-{step-slug}
git add <step's files only>
git commit -m "candy-query: {step description}"
unset GITHUB_TOKEN && gh pr create --fill --title "candy-query: {step description}" --body "## Test plan: N tests"
unset GITHUB_TOKEN && gh pr merge <n> --merge --delete-branch
git checkout master && git pull --ff-only
```

**NOTE**: Always `unset GITHUB_TOKEN` before any `gh` command.

---

## Verification

After ALL phases complete:

```bash
cd /home/sites/sugarcraft/candy-query
composer install
vendor/bin/phpunit   # all green
# Manual smoke:
php bin/candy-query --dsn sqlite://:memory:   # browse mode
php bin/candy-query --dsn mysql://user:pass@localhost:3306/dbname  # admin pages reachable
```

---

## Blocking Issues

If any subagent reports a BLOCKING issue in `updates.md`, the supervisor must resolve it before proceeding to dependent steps.

---

*Plan created: 2026-06-03*
*Based on audit of candy-query implementation vs candy_queries.md + mysql_workbench_dash.md*
