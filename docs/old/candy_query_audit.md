# candy-query — State-of-Implementation Audit

*Generated 2026-06-03. Scope: full `candy-query` lib (~95 src files, 21.9k LOC, 1112 tests green/1 skipped) audited against `plans/CANDY_QUERY_UPSTREAM.md`, `plans/CANDY_QUERY_UPSTREAM_PHASE2.md`, `docs/old/candy_queries.md`, `docs/mysql_workbench_dash.md`, and `query_update.md`.*

---

> **POST-AUDIT RESOLUTION (2026-06-12, archived to `docs/old/`).** Every finding
> below (§A–§E and the §F 14-issue inventory) was remediated by the `candy-query-fix`
> plan (PRs #1018–#1076) and verified green on master (1164 tests). A final
> completeness pass re-checked each finding against live code and closed the last
> stragglers:
> - §A/§B/§C/§D headline defects (admin key routing, phantom classes, KILL/SSL/
>   double-quote/instrumentation/MDL bugs, page construction) — confirmed resolved.
> - **§D KILL / KILL QUERY were unreachable** (correct SQL but never wired to a key)
>   — wired with a confirm step in [PR #1080].
> - **§F#2 stale `1-6:select` help** (8 panes now) — made dynamic in [PR #1080].
> - **§B `PostgresConnectionsAdapter` stub** (zero callers) — deleted in [PR #1080],
>   finishing STEP 7.3's dead-code cleanup.
> - **§E MysqlDatabase → Admin layering inversion** + a latent `Sampler::registerUptime`
>   fatal it masked — fixed in [PR #1077] via a Db-level interface seam.
> - **§E alert spam / mis-formatting** — fixed in STEP 7.2; the deferred dedup test was
>   activated in [PR #1078] (`AlertNotifierInterface`).
>
> Intentionally still deferred (accepted design decisions, not gaps): `ResultTable`
> vs sugar-table (distinct use), `AsyncOps::throttle` (returns a void callable —
> TEA-incompatible, documented deviation), `prepare()` on `DatabaseInterface` (kept
> for parameterized commits; raw-PDOStatement leak already fixed), `password()` on
> `ServerContextInterface` (internal async-bootstrap use, not a display path),
> per-thread instrumentation-toggle wiring (needs state the processlist row lacks).

## Executive summary

The **foundation work is genuinely solid**: the multi-driver `Db` layer, the async React-MySQL/pgasync admin fetch path, the calc engine (precompiled closures, no `eval`), the Phase-2 widget adoption (zero raw `\x1b` outside `CellValue.php`), and immutability discipline in `App.php` are all real and well-tested. 1112 tests pass.

But there is a **single architectural defect that silently disables most of the admin UI's interactivity**, and a cluster of **"looks-done-but-dead" features** — fully built, fully unit-tested classes that are never wired into the running app, so their tests pass while the feature does nothing at runtime. The 1112-green test suite masks this because the tests exercise classes in isolation, not the App's key-routing path.

The headline issues:

1. **🔴 Admin key routing is a dead end** — `App::handleAdminKey()` (`src/App.php:293`) returns `[$this, null]` for every key it doesn't itself handle. It only forwards to `page->update()` for `r` (and `p` on Dashboard). So **every per-page key — tab switches, row selection, toggles, commits, category/report navigation, kill, edit — never reaches the page.** This is the root cause of ~half the "feature X is unreachable" findings below.
2. **🔴 Admin pages are constructed without their collaborators** — `VariablesPage::new($context)` (`App.php:435`) passes no `Catalog`/`VariableEditor`; `ReportsPage::new($context)` (`:437`) passes no `$db`; pages are also rebuilt from scratch every poll tick (`adminPage => null` reset), discarding all in-page state (active tab, cursor, pending edits).
3. **🔴 Several MySQL admin actions are wrong against a real server** — `KILL ?` / `KILL QUERY ?` as **prepared statements** (MySQL rejects placeholders on `KILL`); instrumentation toggle targets `setup_actors` instead of `performance_schema.threads`; MDL tab queries `THREAD_ID` instead of `OWNER_THREAD_ID`; PerfSchema commit SQL is unparameterized and the instrument RLIKE never matches.
4. **🟡 Dead / unwired subsystems** — `StatusPoller`, `Sampler`, `ProcessQueryExecutor`, all three `Validation\*` validators, `PostgresDashboardAdapter`, `CellEditor`, `SnippetStore`, `ResultPager`, `RawValue` are built + tested but have **no production caller**.

None of these are caught by CI because no live MySQL/PG runs and the tests are class-isolated.

---

## A. The central defect — admin interactivity is unrouted

**`src/App.php:230-293` `handleAdminKey()`** handles only: digits `1-9` (pane select), `q` (back to Tables), `j/k`/arrows (sidebar nav), `p` (pause, Dashboard-aware), `r` (cache reset → forwards to page). **The final statement is `return [$this, null];`** — any other key is swallowed.

Consequence: the `update()` methods that *do* exist on `DashboardPage`, `VariablesPage`, `ReportsPage`, `PerfSchemaPage` are only ever invoked for `r`. Keys like `Tab`, `Space`, `Enter`, `c` (commit), `x` (export), category-navigation arrows, `K` (kill) are never delivered.

**Fix (single highest-leverage change):** at the end of `handleAdminKey`, delegate unhandled keys to the active page and persist the result:
```php
$page = $this->adminPage();
[$newPage, $cmd] = $page->update($msg);
return [$this->withAdminPage($newPage), $cmd];
```
…and stop resetting `adminPage => null` on every data refresh (`App.php:383,667`) so page state survives a poll tick. This one change resurrects: Reports category/report navigation, Variables tab/search/edit, PerfSchema tab/toggle/commit, Connections selection/actions (once `ConnectionsPage::update()` exists — see D).

---

## B. Dead / unwired-but-tested code (the "phantom features")

Each of these is fully implemented and has passing tests, but **zero production callers** (verified by grep across `src/`):

| Class | Intended role | Status | Action |
|---|---|---|---|
| `Admin/StatusPoller` | 3s `SHOW GLOBAL STATUS` poll w/ restart detection | No caller; live polling is `App::createAdminFetchPromise` | Delete, or make it the poll path |
| `Admin/Sampler` | delta-per-second rate math | Only `SidebarGaugeSet` accepts it, but `ServerStatusPage` builds the set **without** a sampler (`ServerStatusPage` has no `Sampler` import) → all gauges use the inaccurate cumulative-bytes fallback | Wire a sampler + call `poll()`, or delete |
| `Admin/ProcessQueryExecutor` | subprocess query runner | No caller; **writes DSN+password to a world-readable temp `.php` file** (`:43-71`); tracks only one in-flight query | **Delete** (superseded by React connections) |
| `Admin/Validation/{Connection,PsUsable,Privilege}Validator` | page gating | Only in tests; `ConnectionValidator` runs a **blocking `SELECT 1`** | Wire into `PageBase::validate()` via async cache, or delete |
| `Admin/Dashboard/PostgresDashboardAdapter` | PG dashboard | Orphan; says "Postgres support coming" but `WidgetRegistry::buildForPostgres()` already builds a real catalog | **Delete** (false message) |
| `CellEditor` | inline cell UPDATE | No `update()` key invokes it | Wire into Rows pane, or mark experimental |
| `SnippetStore` / `Snippet` | save/search SQL snippets | No caller | Wire, or remove |
| `ResultPager` | pagination | No `src/` caller (only its test); `Lang::t` is used **only** here | Delete (+ orphans `Lang`), or wire into `rowsPane` |
| `Admin/Calc/RawValue` | raw status lookup | Superseded by `StatusVar` | Delete |
| `Admin/PerfSchema/EasySetupDetector` | Easy-Setup state detection | `PerfSchemaPage` is always built with `null` detector → falls back to inferior in-page `detectSetupState()` | Inject it |
| `AdminPane::next()` / `all()` | enum helpers | Unused; `all()` is a 2nd hardcoded source of truth vs `cases()` | Delete or `return self::cases()` |

> **Why this matters:** Phase-2's "Definition of done" claims `ResultTable`/`ResultPager` are "retired or adapter-only" — `ResultPager` is neither (it's a dead orphan), and `ResultTable` still reimplements column-sizing/padding/scroll that sugar-table provides (kept only for the executed-query path).

---

## C. Correctness bugs that break against a real database

These will silently misbehave or error on a live MySQL/PG (CI has none, so untested):

**CRITICAL**
- **`KILL ? / KILL QUERY ?` as prepared statements** (`ConnectionActions.php:125-135`) — MySQL does not accept placeholders on `KILL`; prepare/execute errors. Kill is non-functional. → int-cast and interpolate: `'KILL CONNECTION ' . (int)$threadId` (int-cast is injection-safe), add explicit `CONNECTION` keyword.
- **MySQL DSN uses invalid `ssl-mode=` key** (`Db/ConnectionConfig.php:39-45`) — appended to **every** MySQL DSN; PDO mysql has no such DSN param (SSL is set via `PDO::MYSQL_ATTR_SSL_*`). Non-default `sslMode` breaks or silently no-ops. → drop from DSN, pass SSL via driver options.
- **`SqlExporter` double-quotes values** (`Db/Export/SqlExporter.php:75`) — wraps `$db->quote(...)` (already a complete quoted literal) in extra `'…'`, producing `''value''`; every INSERT is malformed. → use `$db->quote($val)` alone. (Also `SqlExporter`/`CsvExporter` are SQLite-only — `PRAGMA table_info` / `sqlite_master` — despite the "driver-agnostic" contract.)

**HIGH**
- **Instrumentation toggle hits the wrong table** (`ConnectionActions.php:76-93`) — `UPDATE setup_actors … WHERE HOST='%' AND USER='%'` instead of `UPDATE performance_schema.threads SET INSTRUMENTED=? WHERE THREAD_ID=?`; affects 0 rows yet reports success.
- **MDL tab wrong column** (`ConnectionDetailTabs.php:193-207`) — filters `metadata_locks.THREAD_ID` (should be `OWNER_THREAD_ID`); returns no/incorrect rows.
- **PROCESSLIST_ID vs THREAD_ID confusion** across `ConnectionActions`/`ConnectionDetailTabs` — PS tables key on `THREAD_ID`, `KILL` takes the connection id; the code passes one id type to both. → carry both ids on `ProcesslistResult`.
- **Postgres status vars silently dropped** (`App.php:567-597`) — the `.then()` mapper only keeps rows shaped `Variable_name`/`Value` (MySQL) or `name`/`setting` (pg_settings); the PG `pg_stat_database` status rows match neither, so `statusVars` is always `[]` → PG dashboard/status show "No data".
- **`SET GLOBAL PERSIST …` is invalid SQL** (`VariableEditor.php:142`) — valid forms are `SET PERSIST` / `SET PERSIST_ONLY` / `RESET PERSIST`. The persist path is also unreachable (page hardcodes `edit()`).
- **`handleEdit()` issues a pointless live `SET GLOBAL` of a var to its own current value** (`VariablesPage.php:300-339`) — there's no value-input dialog, so editing re-writes the current value (privileged no-op) and mutates `$this` in place (immutability violation).
- **StatusPoller first-poll cadence gate broken** (`StatusPoller.php:57-81`) — `lastPollAt` only set on non-first polls, so the 2nd poll fires immediately; also throttles on the *cached* snapshot ts, not wall-clock. (Moot until StatusPoller is actually wired — see B.)
- **InnoDB Buffer Pool Usage uses the wrong formula** (`Calc/InnoDBBufferPoolUsage.php`) — ports the *sidebar monitor* `(total−free)/total` instead of Appendix A's dashboard `(Innodb_buffer_pool_bytes_data/Innodb_page_size)/pages_total`.

---

## D. Missing features vs the Workbench spec

**Client Connections (`docs/mysql_workbench_dash.md §5.5`)** — `ConnectionsPage` has **no `update()` at all** (inherits PageBase no-op). Combined with A, the entire page is display-only: no row selection, no detail tabs (Details/Attributes/MDL all built but unreachable), no kill/kill-query, no instrumentation toggle, no filters toggle, no refresh-rate selector. `PostgresConnectionsAdapter` is never instantiated → PG connections page dead. `EXPLAIN {$query}` interpolates another session's `PROCESSLIST_INFO` raw into a new statement (`ConnectionDetailTabs.php:180`).

**Variables (`§5.6`)** — catalog + editor not wired (B); metadata catalog is **73 entries vs ~600** in `wb_admin_variable_list` (most vars get no description/`[rw]`/category); `editable=69/73` is implausibly high and conflates "editable" with "runtime-dynamic" (static vars will hit error 1238); no value-input dialog; `SET PERSIST`/`PERSIST_ONLY`/`RESET PERSIST` + Persisted category absent; copy-to-clipboard absent.

**Performance Schema Setup (`§5.3/§6.5`)** — page renders but **no interaction works** (A); no server-version gating (`setup_actors`/`setup_objects` ≥5.6, `.ENABLED` ≥5.6.3, `setup_timers` <8.0 — relies on try/catch swallowing); **no `setup_timers` model**; Threads tab doesn't select/commit `INSTRUMENTED`; commit SQL is **unparameterized** (`CommitPlanner::quote()` just doubles quotes) and the instrument **RLIKE never matches** (`SetupInstruments.php:161` wraps the name in literal backticks inside the regex); Easy-Setup detection diverges from the spec's COUNT/SUM queries (ignores `TIMED` + consumers) and the default instrument/consumer sets are wrong vs Appendix C; no top-down tri-state group cascade; tree renders flat (depth discarded).

**Server Status (`§5.4`)** — sidebar gauges always use the inaccurate cumulative-bytes fallback (no Sampler wired); "CPU" gauge is a mislabeled duplicate of the Connections ratio; GTID-mode selector absent; firewall panel is an `Aurora_lwm` stub; replica panel can't distinguish "not configured" from "permission denied" (swallows all `PDOException`→null, so the 1227-specific path is dead) and drops multi-channel rows; `hasStoredPrograms` reads non-existent status vars.

**Dashboard (`§5.1`/Appendix A)** — tuple/multi-series timelines collapsed to a single summed line (`TimeSeriesCell.php:64` `array_sum`); level-meter `%d / %d` format string is dead (`MeterCell::viewLevel` never renders value/max); missing InnoDB extras (row-lock, pages, insert-buffer — issue #4); per-frame catalog rebuild (`DashboardPage::getWidgetsForSection`); `elapsed` hardcoded to 3.0 instead of measured. Postgres `shared_buffers` mis-scaled (treated as bytes; it's 8KB blocks).

**Reports (`§5.2`/Appendix B)** — catalog is **31 reports vs ~35**; tree is unnavigable (D/A); runs **synchronous** `db->query()` inside the render/`validate()` path (blocks the event loop — `ReportsPage`, `AvailabilityChecker`); the dedicated `CsvExporter::exportReportResults` space-pads fields (invalid CSV) and has no injection guard — but `ReportsPage` bypasses it with an inline CSV builder (so the exporter is dead); curiously both `QueryStats` **and** `TableStats` panes map to the same `ReportsPage`.

---

## E. Architecture / quality issues

- **`MysqlDatabase` (Phase-0 driver) imports the Admin layer** (`Sampler`, `ReconnectManager`) — layering inversion; `SqliteDatabase`/`PostgresDatabase` don't.
- **Three hand-rolled polling cadences** (`StatusPoller`, `App::subscriptions` tick, `ServerContext` TTL) while `candy-async AsyncOps::throttle` exists and is **never used**; `candy-async` isn't even a declared `require` (path-repo only) — issue #11 confirmed unresolved.
- **Three duplicated restart-detection implementations** (`Sampler::registerUptime`, `StatusPoller::trackUptimeFromSnapshot`, `ServerContext::detectReset`) with float/int inconsistency.
- **`query()` return-contract violation** — interface documents `array|null` (null = retry signal); `Sqlite`/`Postgres` are typed `:array` and can't return null; `MysqlDatabase` returns `[]` when `$pdo===null`. Callers treat a dead connection as "0 rows".
- **`DatabaseInterface::password(): string`** exposes plaintext password as a public accessor — contradicts the "never echo password" invariant; `prepare(): mixed` leaks a raw `PDOStatement` and is meaningless for async drivers.
- **`ConnectionFactory::fromDsn()`** hand-parses with `explode('@'/':')` — breaks on `@`/`:` in passwords, rejects passwordless users, mis-parses IPv6. → use `parse_url()`.
- **`Flavor` default fallback is `Sqlite`** — an unparseable MySQL/PG version routes to SQLite providers (wrong EXPLAIN/schema SQL). Detection should seed from `driverName()` first. Capability flags (plan 0.5) don't exist.
- **MySQL/PG `EXPLAIN` providers flatten the tree** — `ExplainView::depthFromDetail` only understands SQLite glyphs; JSON-plan depth is discarded.
- **`::create()` factories** (`ConnectionConfig::create`, `SchemaBrowser::create`) violate the `::new()` convention.
- **Alert spam** — `DashboardPage::checkAlerts` rebuilds a stateless `AlertManager` every 1s tick and re-fires a toast for every still-breached threshold; `Alert::toToastMessage` formats every metric as `value*100 %` (nonsense for seconds/count metrics).
- **History timestamp precision loss** — stored as microtime float but queried via integer `getTimestamp()`/`(int)` casts.

---

## F. Cross-check vs `query_update.md` (the 14-issue inventory)

| # | Issue | Status |
|---|---|---|
| 1 | PerfSchemaPage not wired | ✅ wired (renders) — but interaction dead (A) |
| 2 | 6 panes don't match Workbench layout | ⚠️ 8 panes now; digit-key order ≠ sidebar section order; help still says "1-6" |
| 3 | Stale ConnectionsPage comment | ✅ removed |
| 4 | Missing InnoDB widgets | ❌ still missing (row-lock/pages/insert-buffer) |
| 5 | Postgres dashboard stub | ⚠️ real catalog exists but PG status mapping broken (C) + adapter orphaned |
| 6/12 | ReportsPage CSV no-op | ✅ CSV implemented — but tree unnavigable, exporter class still dead/buggy |
| 7 | Mysql PS processlist path | ✅ added |
| 8 | AlertManager not wired | ✅ wired into Dashboard — but spams + mis-formats |
| 9 | HistoryRecorder not in poll loop | ✅ wired |
| 10 | docs `forFlavor()` API | not re-checked (docs scope) |
| 11 | AsyncOps::throttle vs manual | ❌ still manual; candy-async not even required |
| 13 | SidebarGaugeSet not rendered | ✅ rendered — but no Sampler so always fallback math |
| 14 | composer candy-metrics path | n/a (history uses SQLite, not candy-metrics) |

---

## G. Prioritized remediation

**P0 — makes the admin UI actually work (small, high leverage)**
1. Route unhandled keys to `adminPage()->update()` and stop nulling `adminPage` each tick (A).
2. Construct pages with collaborators: `VariablesPage::new($context, Catalog::new(), VariableEditor::new(...))`, `ReportsPage` with `$db`/async runner.
3. Add `ConnectionsPage::update()` (select/kill/tabs/filters/refresh).
4. Fix `KILL` (int-interpolate), instrumentation-toggle table, MDL `OWNER_THREAD_ID`, PG status-var mapping.

**P1 — correctness on a real server**
5. Drop `ssl-mode` from MySQL DSN; fix `SqlExporter` double-quote; route Reports through async (un-block the loop).
6. PerfSchema: version-gate loads, parameterize commits, fix instrument RLIKE (`^name$`, no backticks), correct Easy-Setup detection + Appendix-C defaults, wire `update()`.
7. Variables: add value-input dialog, fix persist SQL, distinguish dynamic vs editable.

**P2 — cleanup / parity**
8. Delete dead code (`ProcessQueryExecutor` ⚠️creds-leak, `PostgresDashboardAdapter`, `RawValue`, `ResultPager`, unused validators, `AdminPane::all/next`) or wire it.
9. Adopt `AsyncOps::throttle`; collapse the 3 restart-detection copies into one; declare `candy-async` in `require`.
10. Fill the variable-metadata catalog (~600), reports catalog (~35), multi-series timelines, level-meter readout, sidebar Sampler.
11. Fix `query()` null-contract, remove `password()`/`prepare()` from the interface, `parse_url()` in the factory, `Flavor` seed from driver, `::new()` factories, alert dedup/formatting.

---

*Bottom line: candy-query has excellent bones and test coverage, but is in a "scaffold-complete, runtime-hollow" state for the admin layer — the plumbing between `App` key-handling and the admin pages was never connected, and ~12 classes are dead on arrival. Fixing the key-routing defect (A) plus the page-construction wiring (B) is a few hours of work that would light up most of the already-written UI; the live-server correctness bugs (C/D) are the next tier and need a scratch MySQL to validate.*

---
---

# PART 2 — REMEDIATION PLAN (supervisor-orchestrated)

This part turns the findings above into an executable, agent-orchestrated plan. It is the **master reference**. The operational instruction files live under `plans/candy-query-fix/` — the supervisor reads ONLY its own file; subagents read ONLY the files they are pointed at.

## Orchestration model

```
SUPERVISOR  (reads plans/candy-query-fix/SUPERVISOR.md ONLY)
  │  never investigates or edits code itself; just walks the handoff sequence
  │  and spawns ONE subagent at a time, telling it which instruction file to read.
  │
  ├─ REAL STEP        → oac:coder-agent      reads COMMON.md + steps/<phase>/STEP_x.md
  ├─ ↳ REVIEW         → oac:code-reviewer    reads COMMON.md + between/REVIEW.md
  ├─ ↳ FIX            → oac:coder-agent      reads COMMON.md + between/FIX.md
  ├─ ↳ TESTS & CI     → oac:test-engineer    reads COMMON.md + between/TESTS_CI.md
  ├─ ↳ DOCS           → oac:coder-agent      reads COMMON.md + between/DOCS.md
  └─ (research, on demand) → general-purpose researcher spawned BY the supervisor
```

**The between-step cadence runs after EVERY real step** (Review → Fix → Tests&CI → Docs), then the next real step begins. At a **phase's final real step**, that step's between-block is scoped to the whole phase (it doubles as the phase closeout) — so "after every real step and phase" is satisfied without duplicate passes.

**Shared scratchpad — `plans/candy-query-fix/updates.md`.** The ONLY cross-agent channel. Subagents append items they need to pass on (deferred work, gotchas, "RESEARCH NEEDED", blocking issues) and **remove items once resolved/consumed**. It is an untracked working file — never `git add` it.

**Research.** Subagents (coder/reviewer/test-engineer) cannot spawn agents. If one lacks information, it writes `RESEARCH NEEDED: <topic + why>` to `updates.md` and signals the supervisor (BLOCKING if it can't proceed). The **supervisor** spawns a `general-purpose` researcher, writes the findings back into `updates.md`, and re-spawns the blocked step.

**Ship cadence (every subagent that changes files).** Branch `ai/candy-query-<slug>` → commit (author `Joe Huss <detain@interserver.net>`) → push → `unset GITHUB_TOKEN && gh pr create` → `unset GITHUB_TOKEN && gh pr merge <n> --merge --delete-branch` → `git checkout master && git pull --ff-only`. **End every task on `master`, clean tree, ready for the next subagent.** Review steps are read-only (no PR) — findings go to `updates.md`.

> **GH TOKEN — MANDATORY:** you MUST run `unset GITHUB_TOKEN` immediately before EVERY `gh` invocation (chain it: `unset GITHUB_TOKEN && gh …`). This note is repeated in every instruction file on purpose.

**Blocking vs deferrable.** Can't finish but non-blocking → add to `updates.md`, ship what works, move on. Blocks the next step → STOP, write a `BLOCKER:` item to `updates.md`, and report to the supervisor for resolution before continuing.

**Caliber:** do NOT run `caliber refresh` on this machine; if a hook auto-stages Caliber files, unstage them before committing.

## Phase / step breakdown

Severity tags map to PART 1. Each real step is one subagent task sized for minimal context.

### Phase 1 — Wire the admin UI (P0; resurrects already-built pages)
- **1.1 admin-key-routing** — forward unhandled admin keys to `adminPage()->update()`; stop nulling `adminPage` each poll tick (preserve tab/cursor/edit state). (PART 1 §A)
- **1.2 page-collaborators** — build pages with their collaborators (`VariablesPage`+Catalog+Editor, `ReportsPage`+db/async runner); fix digit-key vs sidebar-section order; fix "1-6"→"1-N" help text. (§B, §H3 core)
- **1.3 connections-update** — add `ConnectionsPage::update()` (row select, detail-tab switch, filter toggles, refresh-rate). Actions deferred to 1.4. (§D)
- **1.4 connections-actions** — fix real-server actions: `KILL`/`KILL QUERY` int-interpolation + `CONNECTION` keyword; instrumentation toggle → `performance_schema.threads`; MDL `OWNER_THREAD_ID`; carry both PROCESSLIST_ID & THREAD_ID; guard `EXPLAIN` to single SELECT. (§C, §D)

### Phase 2 — Driver / connection correctness (P1)
- **2.1 dsn-and-factory** — drop invalid `ssl-mode` DSN key (SSL via `PDO::MYSQL_ATTR_SSL_*`); `ConnectionFactory::fromDsn` → `parse_url` (handle `@`/`:` in creds, passwordless, IPv6). (§C, §E)
- **2.2 query-contract-and-flavor** — make `query()` honour `array|null` across all drivers; remove/secure `password()` & `prepare()` on the interface; `Flavor` seed from `driverName()` first; MySQL/PG EXPLAIN carry real tree depth. (§E)
- **2.3 exporters** — make `CsvExporter`/`SqlExporter` driver-agnostic (no `PRAGMA`/`sqlite_master`); fix `SqlExporter` double-quote; RFC-4180 via `fputcsv`, no width padding; formula-injection guard (`= + - @ \t \r`); `ReportsPage` delegates to `CsvExporter`. (§C, §D)

### Phase 3 — Reports functional (P1)
- **3.1 reports-async** — route `ReportRunner`/`AvailabilityChecker` through the async cache (no sync `db->query()` in render/`validate`); catch `\Throwable`. (§D)
- **3.2 reports-navigation-catalog** — wire category/report navigation keys (`withSelectCategory/withSelectReport`); resolve both `QueryStats` & `TableStats` → `ReportsPage` (split or label distinctly); expand `data/sys_reports.json` to the ~35 of Appendix B; per-column unit toggle. (§D)

### Phase 4 — Variables functional (P1)
- **4.1 variables-edit-dialog** — add a value-input dialog; stop the self-`SET GLOBAL`; keep immutability; distinguish runtime-dynamic vs editable (fallback on error 1238). (§C, §D)
- **4.2 variables-persist** — `SET PERSIST` / `SET PERSIST_ONLY` / `RESET PERSIST` (8.0-gated), Persisted category + columns; remove invalid `SET GLOBAL PERSIST`. (§C, §D)
- **4.3 variables-metadata-catalog** — regenerate `data/variable_metadata.json` to the full ~600 vars with accurate `editable`/`dynamic`/group fields. **Likely RESEARCH NEEDED** (upstream `wb_admin_variable_list`/`mysqld.xml`). (§D)

### Phase 5 — Performance Schema Setup correct + functional (P1)
- **5.1 perfschema-gating-models** — server-version gating (`setup_actors`/`setup_objects` ≥5.6, `.ENABLED` ≥5.6.3, `setup_timers` <8.0); add `SetupTimers` model; Threads tab selects+commits `INSTRUMENTED`. (§D)
- **5.2 perfschema-commit-tree** — parameterize all commits; fix instrument RLIKE (`^name$`, regex-escaped, level-bucketed); top-down tri-state cascade + ancestor recompute; render the collapsible tri-state tree (use the discarded depth). (§C, §D)
- **5.3 perfschema-easysetup** — Easy-Setup detection per spec COUNT/SUM (include TIMED + consumers, exclude `memory/%`); Appendix-C default instrument/consumer sets (5.6/5.7); wire `EasySetupDetector`. (§D)

### Phase 6 — Dashboard + Server Status accuracy (P1/P2)
- **6.1 sampler-gauges** — wire a `Sampler` into `SidebarGaugeSet` + `ServerStatusPage` and call `poll()`; fix key-efficiency math; make the "CPU" gauge honest or remove it. (§B, §C, §D)
- **6.2 postgres-status-mapping** — fix PG `pg_stat_database` status-var pivot in `createAdminFetchPromise`; `shared_buffers` block→byte scaling; `PostgresWidgetCatalog` static/instance consistency. (§C, §D)
- **6.3 dashboard-accuracy** — multi-series tuple timelines (stop `array_sum`); level-meter value/max readout; measured `elapsed`; cache per-section widgets (no per-frame rebuild); add missing InnoDB widgets; fix buffer-pool-usage formula. (§C, §D, issue #4)
- **6.4 serverstatus-features** — replica: distinguish 1227 vs not-configured, multi-channel rows, flavor switch; GTID-mode selector; real firewall/stored-program detection. (§D)

### Phase 7 — Async unification, alerts, history, cleanup (P2)
- **7.1 async-throttle-restart** — adopt `candy-async AsyncOps::throttle` for the admin tick; add `sugarcraft/candy-async` to `require`; collapse the 3 restart-detection copies into `ServerContext`. (§E, issue #11)
- **7.2 alerts-history** — alert dedup/cooldown (re-fire only on state change); per-metric formatting (ratio vs seconds vs count); history timestamp precision (float epoch end-to-end). (§E)
- **7.3 dead-code-cleanup** — delete/retire the phantom classes (`ProcessQueryExecutor` ⚠️creds-leak, `PostgresDashboardAdapter`, `RawValue`, `ResultPager`, `AdminPane::all/next`); wire-or-remove `CellEditor`/`SnippetStore`/validators; `ResultTable`→sugar-table adapter; `::new()` factories; remove `MysqlDatabase`→Admin layering inversion. Decide wire-vs-delete per class via `updates.md` notes from earlier phases. (§B, §E)

### Phase 8 — Final integration
- **8.1 final-integration** — full-suite green across touched libs; manual smoke plan (SQLite path + a scratch MySQL reaching each admin page, kill/edit/commit/export round-trips); reconcile `updates.md` (every item resolved or explicitly deferred); confirm `query_update.md` 14-issue table all closed or tracked.

## Agent assignment summary
| Step kind | Agent type |
|---|---|
| Real step, Fix, Docs | `oac:coder-agent` |
| Review | `oac:code-reviewer` |
| Tests & CI | `oac:test-engineer` |
| Research (supervisor-spawned, on demand) | `general-purpose` |

> If a chosen agent type proves unsuitable for a step, the supervisor may substitute another and record the swap in `updates.md`.

## Instruction-file map
```
plans/candy-query-fix/
  SUPERVISOR.md                 ← the ONLY file the supervisor reads
  COMMON.md                     ← shared subagent protocol (read first by every subagent)
  updates.md                    ← shared scratchpad (untracked; never git add)
  between/REVIEW.md  FIX.md  TESTS_CI.md  DOCS.md   ← reusable between-step templates
  steps/phase1..8/STEP_*.md     ← one self-contained file per real step
```
