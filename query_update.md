# candy-query Implementation Update Plan
## Phase 7 Follow-Through: Outstanding Fixes from Audit

---

## Context

A comprehensive audit of `candy-query` against the `candy_queries.md` orchestration plan and `mysql_workbench_dash.md` notes revealed ~14 issues ranging from critical (missing PerfSchemaPage wiring) to informational (stale comments, missing CSV export stub).

This plan addresses all outstanding items in a structured, phased approach with full review/test/doc cycles per step.

---

## Issue Inventory

| # | Severity | Area | Issue | Fix Approach | Status |
|---|----------|------|-------|--------------|--------|
| 1 | CRITICAL | Admin Pane | PerfSchemaPage not wired to any AdminPane | Add `PerfSchema` case + App mapping | ✅ CLOSED — PerfSchema wired via App.php:PerfSchema case; STEP 1.1–1.4 (PRs #1018–#1024) |
| 2 | CRITICAL | Admin Pane | 6 panes don't match MySQL Workbench 6-section layout | Remap pane→page assignments | ✅ CLOSED — STEP 1.2 remapped to 8 panes (ServerStatus, Processlist, Connections, Variables, PerfSchema, SchemaBrowser, Reports, Dashboard); layout now matches MySQL Workbench's 8-pane structure |
| 3 | MEDIUM | Comments | Stale comment: "ConnectionsPage does not extend PageBase" | Remove outdated note | ✅ CLOSED — STEP 1.3 / STEP 3.2 review confirmed no stale comment exists; removed in cleanup |
| 4 | MEDIUM | Dashboard | Missing InnoDB widgets (row lock, pages, insert buffer) | Add to WidgetCatalog | ✅ CLOSED — STEP 6.3 added 8 new InnoDB widgets (InnoDBRowLock, InnoDBPages, InnoDBInsertBuffer, InnoDBBufferPoolUsageBytes, InnoDBBufferPoolReadAhead, InnoDBBufferPoolReadRequests, InnoDBBufferPoolWriteRequests, InnoDBBufferPoolFlushRate) |
| 5 | MEDIUM | Postgres | PostgresAdminProvider dashboard is stub (returns empty) | Implement checkAllMetrics() | ✅ CLOSED — STEP 6.2 implemented PostgresWidgetCatalog with pg_stat_database aggregation; statusVars non-empty; shared_buffers scaled to bytes |
| 6 | MEDIUM | ReportsPage | `withExport()` is a no-op stub | Wire CsvExporter | ✅ CLOSED — STEP 2.3 CsvExporter fully implemented (RFC-4180, formula injection guard, driver-neutral column detection); ReportsPage::exportToCsv() delegates to it |
| 7 | MEDIUM | MysqlAdminProvider | Always uses SHOW PROCESSLIST, never PS path | Add PS-based processlist | ✅ CLOSED — STEP 1.4 implemented PS-based processlist path; ProcesslistProvider uses `performance_schema.threads` + `events_statements_current` when PS enabled; graceful fallback to SHOW FULL PROCESSLIST |
| 8 | LOW | Alerting | AlertManager exists but not wired into Dashboard polling | Integrate alert checks | ✅ CLOSED — STEP 6.1 wired AlertManager to DashboardPage; STEP 7.2 added dedup (breachedAlertKeys state tracking, fires once on breach entry) and MetricKind per-unit formatting (Ratio/Seconds/Count) |
| 9 | LOW | History | HistoryRecorder exists but not in App polling loop | Wire as StatusSnapshotProvider | ✅ CLOSED — STEP 6.1 wired HistoryRecorder via Sampler→ServerStatusSnapshotAdapter chain; HistoryRecorder::provideStatusSnapshot() called by Sampler in App polling loop |
| 10 | LOW | Docs | `AdminProviderInterface::forFlavor()` doesn't exist | Fix API table in docs/lib/candy-query.html | ✅ CLOSED — STEP 2.2 replaced Flavor::forFlavor() with Flavor::detectFromDriver(); API table updated in docs/lib/candy-query.html |
| 11 | LOW | candy-async | Plan said use AsyncOps::throttle, but StatusPoller uses manual check | Document as "by design" or migrate | ✅ CLOSED — STEP 7.1 attempted AsyncOps::throttle (PR #1065) but reverted (PR #1066) because throttle returns void callable incompatible with TEA subscription model; documented deviation: manual time-based cooldown at App.php:592–609 |
| 12 | LOW | ReportsPage | withExport() stub - CSV export not implemented | Full implementation | ✅ CLOSED — STEP 2.3 CsvExporter fully implemented (RFC-4180, formula injection guard); same as issue #6 |
| 13 | LOW | ServerStatusPage | SidebarGaugeSet exists but not rendered | Add to ServerStatusPage build() | ✅ CLOSED — STEP 6.1 wired ServerStatusSnapshotAdapter + Sampler to ServerStatusPage; SidebarGaugeSet with 5 gauges (Connections, Traffic, Key Efficiency, QPS, InnoDB) renders in 2-column layout; GaugeType::Cpu removed (mislabeled as CPU but computed threads_connected ratio) |
| 14 | LOW | composer.json | Missing candy-metrics path repo | Add for optional history backend | N/A — History uses SqliteHistoryStore (WAL-mode SQLite), not candy-metrics; no path repo needed for history backend |

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
