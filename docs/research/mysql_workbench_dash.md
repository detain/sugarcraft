# MySQL Workbench — Administration & Performance Subsystem
## Exhaustive Reverse-Engineering Report for a Portable PHP TUI Reimplementation

> Source analyzed: MySQL Workbench 8.0.47 (this repository, branch `8.0`).
> Target: a portable, cross-platform PHP **terminal UI** application using `mysqli`/`PDO` + optional SSH, replicating Workbench's Administration → Management and Performance tooling.
> Scope priority (as requested): **Performance Dashboard**, **Performance Reports**, **Performance Schema Setup**, then Server Status, Client Connections, Status/System Variables.

---

## Table of Contents

1. Executive Summary
2. Repository Structure Map
3. Relevant Modules Breakdown
4. Architecture Overview
5. Feature-by-Feature Deep Dive
6. SQL Query Catalog
7. Performance Schema Usage Catalog
8. Graphing / Visualization Architecture
9. Polling / Refresh Architecture
10. UI State Architecture
11. Porting Strategy to PHP TUI
12. Recommended PHP Architecture
13. Recommended Class Design
14. Suggested Terminal UI Layouts
15. Suggested Libraries
16. Suggested Query Abstraction Layers
17. Suggested Caching Strategy
18. Suggested Sampling Engine
19. Suggested Timeseries Engine
20. Risks / Complexities
21. Features Worth Simplifying
22. Features Worth Expanding
23. MariaDB / Percona Compatibility Notes
24. Security / Privilege Considerations
25. Incremental Implementation Roadmap
- Appendix A: Dashboard widget table (exact expressions)
- Appendix B: Performance Reports catalog (exact views/columns)
- Appendix C: Default PS instrument/consumer sets

---

## 1. Executive Summary

MySQL Workbench's Administration ("WBA") features are **not** implemented in the C++ core. They are implemented as **Python plugins** living under `plugins/wb.admin/`, rendered through a thin cross-platform UI toolkit called **mforms** (a C++ widget abstraction exposed to Python via SWIG-like bindings, with native backends for GTK/Cocoa/Win32). All the *interesting* logic — what to query, how often, how to compute rates, how to draw graphs, how to model Performance Schema instrumentation — lives in plain, readable Python. This is extremely good news for a reimplementation: **the entire data and computation layer is portable and language-agnostic**, and the only thing that must be replaced is the rendering layer (mforms → a PHP TUI toolkit).

Key architectural findings:

- **One shared backend object (`WbAdminControl`, aka `ctrl_be`)** owns the SQL connection(s), runs a **background polling thread** that executes `SHOW GLOBAL STATUS` every **3 seconds**, and caches the latest snapshot in `ctrl_be.status_variables` (+ a timestamp). It also caches `SHOW VARIABLES` (system variables), `SHOW PLUGINS` (active plugins), and the detected server version. Every admin page reads from these caches rather than querying independently.
- **The Performance Dashboard is a pure status-variable visualizer.** It does **not** use Performance Schema at all. Every gauge/graph is driven by a small Python "calc" expression over the cached `SHOW GLOBAL STATUS` dictionary, computing per-second deltas or raw values. The dashboard is 100% reimplementable with only `SHOW GLOBAL STATUS` + `SHOW VARIABLES` and a low-privilege account.
- **Performance Reports are entirely declarative.** A JSON file (`sys_reports.js`) maps report captions to `sys` schema views (e.g. `sys.x$statement_analysis`). The Python code just runs `SELECT * FROM sys.<view> [LIMIT n]`, then formats latency/byte columns with unit scalers. There is **no dynamic SQL generation** — all heavy lifting is delegated to the `sys` schema views, which depend on `performance_schema`.
- **Performance Schema Setup is a CRUD UI over the `performance_schema.setup_*` tables.** It loads `setup_instruments` (as a `/`-delimited tree), `setup_consumers`, `setup_actors`, `setup_objects`, `threads`, `setup_timers` (pre-8.0), models tri-state group checkboxes, computes a **minimal change set**, and commits with `UPDATE ... WHERE NAME RLIKE '...'` / `IN (...)`.
- **Graphing is bespoke and simple.** Time-series graphs keep an in-memory list of `(value, timestamp)` tuples trimmed to a fixed pixel width (~160 samples), auto-scale the vertical axis to a "nice" rounded maximum, and draw a right-to-left polyline via Cairo. The sidebar mini-graphs on Server Status use *native* mforms bar/line widgets with threshold-based color severity.
- **No historical persistence.** Workbench keeps only an in-memory ring of recent samples for the duration the page is open. Nothing is written to disk. A PHP reimplementation can trivially match this and optionally *exceed* it (persist to SQLite/RRD).
- **SSH is used only for OS-level metrics and server start/stop**, never for SQL. CPU/load on the Server Status sidebar comes from running `/usr/bin/uptime` (Unix) or WMI/VBScript (Windows) over the management channel. This is the only piece that genuinely needs SSH/remote exec; it is optional and degrades gracefully.

Bottom line: a PHP TUI can replicate **all six sections** with `mysqli`/`PDO` alone. SSH is needed only for the host CPU gauge (and config-file editing / start-stop, which are out of scope here). Performance Schema / `sys` are required only for Reports, PS Setup, the PS-based connection list extras, and a couple of dashboard-independent niceties — everything else works on a plain low-privilege account.

---

## 2. Repository Structure Map

```
mysql-workbench/
├── plugins/wb.admin/              ← THE ADMIN SUBSYSTEM (Python)
│   ├── frontend/                  ← UI pages (mforms)
│   │   ├── wb_admin_main.py                     AdministratorTab container + module scan
│   │   ├── wb_admin_grt.py                      Admin context, register_page(), sidebar sections
│   │   ├── wb_admin_utils.py                    WbAdminTabBase, validation framework, weakcb
│   │   ├── wb_admin_server_status.py            Server Status page
│   │   ├── wb_admin_monitor.py                  Server Status sidebar gauges (native widgets)
│   │   ├── wb_admin_connections.py              Client Connections page
│   │   ├── wb_admin_variables.py                Status & System Variables page
│   │   ├── wb_admin_performance_dashboard.py    ★ Performance Dashboard
│   │   ├── wb_admin_perfschema.py               PS base tab + sys-schema install/validation
│   │   ├── wb_admin_perfschema_reports.py       ★ Performance Reports (JSON-driven)
│   │   ├── wb_admin_perfschema_instrumentation.py ★ Performance Schema Setup UI
│   │   ├── wb_admin_logs.py / _config_file_ui.py / _security.py / _export.py … (out of scope)
│   ├── backend/                   ← data + server-management logic (no UI)
│   │   ├── wb_admin_control.py                  ★ ctrl_be: SQL exec + status polling thread
│   │   ├── wba_monitor_be.py                    ★ monitor data sources (SQL + shell/WMI CPU)
│   │   ├── wb_admin_perfschema_instrumentation_be.py ★ PS setup table models + commit SQL
│   │   ├── wb_admin_variable_list.py            generated metadata: (name, desc, editable, groups)
│   │   ├── wb_server_management.py / wb_server_control.py  SSH/WMI/local exec, start/stop
│   │   ├── wb_common.py                         enums (Users, OS), exceptions
│   │   └── config/gen-opt/                      generator that builds variable_list from mysqld.xml
│   └── unit-tests/
├── library/python/workbench/
│   ├── graphics/
│   │   ├── charting.py            ★ DBTimeLineGraph, DBSimpleCounter, DBRoundMeter, DBLevelMeter…
│   │   ├── canvas.py              Figure base, hit-testing, repaint plumbing
│   │   └── cairo_utils.py         Cairo Context wrapper
│   ├── db_utils.py                MySQLConnection, QueryError, escape_sql_string, strip_password
│   ├── utils.py                   Version, format_duration
│   └── notifications.py           NotificationCenter (nc) pub/sub
├── res/scripts/sys/
│   ├── sys_reports.js             ★ Performance Reports definitions (JSON)
│   ├── sys_56.sql / sys_57.sql / sys_80.sql   sys schema install scripts (bundled)
│   └── before_setup.sql           carries sys_version string
└── images/ , res/                 dashboard PNG assets (headers, arrows, separators)
```

The `★` files are the core of this report.

---

## 3. Relevant Modules Breakdown

| Module | Role | Key exported symbols |
|---|---|---|
| `wb_admin_control.py` | The single backend hub (`ctrl_be`). Owns SQL connections, runs the status-poll thread, caches variables, detects version/plugins, dispatches lifecycle events. | `WbAdminControl`, `SQLQueryExecutor`, `EventManager` |
| `wba_monitor_be.py` | Pulls per-widget values for the Server Status sidebar. SQL source reads `ctrl_be.status_variables`; shell/WMI source reads host CPU. Own poll thread (3 s). | `WBAdminMonitorBE`, `DBStatusDataSource`, `DBWidgetHandler`, `ShellDataSource`, `WMIStats`, `WinRemoteStats` |
| `charting.py` | Pure-Python chart figures drawn on a Cairo canvas. | `DBTimeLineGraph`, `DBSimpleCounter`, `DBRoundMeter`, `DBLevelMeter`, `DBHBarMeter`, `DBImage`, `DBText`, `scale_value()` |
| `wb_admin_performance_dashboard.py` | The Dashboard page. Declarative widget tables + calc classes. | `WbAdminDashboard`, `GLOBAL_DASHBOARD_WIDGETS_*`, `CSingleDifferencePerSecond`, `CTupleDifferencePerSecond`, `CRawValue`, `CMakeTuple`, `RenderBox` |
| `wb_admin_perfschema_reports.py` | Performance Reports page. Loads `sys_reports.js`, runs `SELECT * FROM sys.<view>`, formats. | `WbAdminPerformanceSchema`, `PSHelperViewTab`, `JSSourceHelperViewTab`, `unit_formatters` |
| `wb_admin_perfschema.py` | Shared base for PS pages: validation (PS enabled? sys installed? privileges?), sys-schema installer. | `WbAdminPSBaseTab`, `WbAdminValidationPSUsable`, `WbAdminValidationNeedsInstallation`, `HelperInstallPanel` |
| `wb_admin_perfschema_instrumentation_be.py` | Models of the `setup_*` tables with change tracking + minimal-diff commit-SQL generation. | `PSConfiguration`, `PSInstruments`, `PSConsumers`, `PSActors`, `PSObjects`, `PSThreads`, `PSTimers`, `PSVariables` |
| `wb_admin_perfschema_instrumentation.py` | PS Setup UI: Easy Setup switch, Instruments tri-state tree, Consumers, Actors&Objects, Threads, Options. | `WbAdminPerformanceSchemaInstrumentation`, `EasySetupPage`, `SetupInstruments`, `SetupDataCollection`, `SetupFiltering`, `SetupThreads` |
| `wb_admin_server_status.py` | Server Status page: connection info card + feature/directory/replica/SSL sections. | `WbAdminServerStatus`, `ConnectionInfo`, `StateIcon` |
| `wb_admin_monitor.py` | Server Status sidebar (CPU bar, connection/traffic/key-eff/QPS/InnoDB line graphs). | `WbAdminMonitor` |
| `wb_admin_connections.py` | Client Connections: process list, info counters, MDL locks, attributes, thread stack, kill. | `WbAdminConnections`, `ConnectionDetailsPanel`, `WBThreadStack` |
| `wb_admin_variables.py` | Status & System Variables: dual tabs, category tree, search, edit, 8.0 persistence. | `WbAdminVariables`, `VariablesViewer`, `VariablesGroupContainer` |
| `wb_admin_variable_list.py` | Generated catalog `[(name, description, editable_bool, [groups])]` for system + status vars. | `system_variable_list`, `status_variable_list`, `ro_persistable` |
| `wb_admin_utils.py` | Base tab lifecycle (validate → error screen / create_ui / update_ui), validation classes. | `WbAdminTabBase`, `WbAdminValidation*` |
| `wb_admin_main.py` / `wb_admin_grt.py` | Container tab, module scan, page registration into sidebar sections "Management" / "Performance". | `AdministratorTab`, `register_page` |

---

## 4. Architecture Overview

### 4.1 The big picture

```
                ┌──────────────────────────────────────────────────────────┐
                │                  AdministratorTab (mforms)                 │
                │   sidebar sections: "Management", "Performance"            │
                │   tab pages (lazy-instantiated on first open):             │
                │   Server Status · Client Connections · Variables ·         │
                │   Dashboard · Performance Reports · PS Setup               │
                └───────────────┬────────────────────────────────────────────┘
                                │ each page holds a ref to ctrl_be
                                ▼
        ┌───────────────────────────────────────────────────────────────────┐
        │                 WbAdminControl  (ctrl_be)  — the hub               │
        │                                                                     │
        │  self.sql           : SQLQueryExecutor  (mutex-guarded, foreground) │
        │  self.poll_connection: 2nd MySQLConnection (background poll thread) │
        │                                                                     │
        │  CACHES (read by all pages):                                        │
        │    server_variables       ← SHOW VARIABLES                          │
        │    status_variables       ← SHOW GLOBAL STATUS  (refreshed 3s)      │
        │    status_variables_time  ← time.time() of last poll                │
        │    server_active_plugins  ← SHOW PLUGINS                            │
        │    target_version         ← Version.fromstr(version)               │
        │                                                                     │
        │  server_polling_thread():  loop { SHOW GLOBAL STATUS; sleep(3) }    │
        └───────────────┬───────────────────────────────┬─────────────────────┘
                        │ foreground exec_query()        │ background snapshot
                        ▼                                ▼
                 ┌────────────┐                   ┌──────────────────────┐
                 │ MySQL/     │                   │ status_variables dict │
                 │ MariaDB    │                   │ {Variable_name:Value} │
                 │ server     │                   └──────────────────────┘
                 └────────────┘
                        ▲
                        │ (SSH/WMI only, optional, non-SQL)
                 ┌──────┴───────────────────────────────┐
                 │ ServerManagementHelper                │  uptime/WMI for CPU,
                 │ (SSH via paramiko / local / WMI)      │  start/stop, file read
                 └───────────────────────────────────────┘
```

### 4.2 Two connections, one cache

`ctrl_be` maintains **two** physical MySQL connections:

1. **Foreground connection** (`self.sql`, a `SQLQueryExecutor` wrapping `MySQLConnection`) — used synchronously by UI actions: report queries, processlist refresh, variable edits, PS-setup commits. Guarded by a `threading.Lock` so the UI thread and any helper threads don't collide (`wb_admin_control.py:107-160`).
2. **Background poll connection** (`self.poll_connection`) — created in `server_polling_thread()` and used **only** for the periodic `SHOW GLOBAL STATUS` (`wb_admin_control.py:556-587`). This is deliberately a separate connection so a long-running foreground query never stalls the live metrics.

The poll loop:

```python
# wb_admin_control.py:571-580
while self.running:
    variables = {}
    result = self.poll_connection.executeQuery("SHOW GLOBAL STATUS")
    while result and result.nextRow():
        variables[result.stringByName("Variable_name")] = result.stringByName("Value")
    self.status_variables, self.status_variables_time = variables, time.time()
    time.sleep(self.status_variable_poll_interval)   # = 3
```

`status_variable_poll_interval = 3` (`wb_admin_control.py:215`). This is the *master cadence* for all live metrics.

### 4.3 Event + UI-task model

- A small `EventManager` (`wb_admin_control.py:56-104`) implements pub/sub for `server_started` / `server_stopped` / `server_offline` / `shutdown` lifecycle events. Pages register themselves (`add_me_for_event`) and implement `<event>_event` methods. Events can be *deferred* during init.
- Because mforms is not thread-safe, background threads never touch widgets directly. They enqueue closures via `ctrl_be.uitask(fn, *args)` into a `queue.Queue`; the `AdministratorTab` drains this queue every **0.5 s** on the UI thread (`wb_admin_main.py:140,274-279` → `process_ui_task_queue`).

### 4.4 Page lifecycle (`WbAdminTabBase`)

Every page subclasses `WbAdminTabBase` (`wb_admin_utils.py:165`). On `page_activated()`:

```
validate() → first failing WbAdminValidation?  ── yes ─▶ show error screen
                         │ no
                         ▼
            body already built? ── no ─▶ create_ui()  (build widgets once)
                         │ yes
                         ▼
                     update_ui()  (refresh data)
```

Validations are ordered lists of objects with a `.validate()` bool and an `.errorScreen()` widget. Standard ones: `WbAdminValidationConnection` (is SQL connected?), `WbAdminValidationPSUsable` (`SELECT @@performance_schema == 1`), `WbAdminValidationNeedsInstallation` (sys present + correct version + privileges). This is the gate that drives the "Install Helper"/"insufficient privileges" screens.

---

## 5. Feature-by-Feature Deep Dive

Each feature below documents: **Purpose · UX · Data sources · SQL · Polling · Internal abstractions · Rendering model · State · Perf considerations · PHP reimplementation**.

---

### 5.1 ★ Performance Dashboard (`wb_admin_performance_dashboard.py`)

**Purpose.** A single fixed-layout 1024×700 "cockpit" with three columns — **Network**, **MySQL** (statement activity), **InnoDB** — showing live throughput graphs, per-second counters, round efficiency meters, and a connection level meter. It is the flagship live view.

**UX behavior.**
- Static, hand-placed widget layout (absolute pixel coordinates) drawn on a scrollable canvas, centered horizontally; on macOS the canvas resizes to the panel.
- Hovering any widget pops a tooltip rendered from a per-widget template string (with `%(VarName)s` substitution and `${python expr}` mini-eval).
- Time-line graphs show a vertical "detail line" under the cursor with the value N seconds ago.
- Dark-mode aware: re-themes text/fill colors on `GNColorsChanged` notification.

**Data source.** **Only** `ctrl_be.status_variables` (the cached `SHOW GLOBAL STATUS`) plus a few `ctrl_be.server_variables` (e.g. `max_connections`, `Innodb_page_size`) for meter denominators. **No Performance Schema, no INFORMATION_SCHEMA, no sys.** Minimum server version gate: `(5,6,6)` and a separate post-8.0 widget set.

**SQL.** None issued by the page itself; it consumes the 3-second `SHOW GLOBAL STATUS` snapshot.

**Polling / update.**
- `create_ui()` calls `mforms.Utilities.add_timeout(self.ctrl_be.status_variable_poll_interval, self.refresh)` → **3 s** UI timer (`:728`).
- `refresh()` (`:765`) checks `status_variables_time` against the last seen timestamp; if newer, it feeds the new snapshot to every widget's `process(status_variables, timestamp)` and triggers a repaint. So data freshness is bounded by the 3 s backend poll; the UI just samples it.

**Internal abstractions — the calc engine (`:209-293`).** This is the heart and is fully portable:

| Class | Behavior | Formula |
|---|---|---|
| `CRawValue(expr)` | Evaluate expr against the var dict, return raw. | `eval(expr % values)` |
| `CSingleDifferencePerSecond(expr)` | Rate of one counter. | `(value − old_value) / (t − old_t)` |
| `CTupleDifferencePerSecond(expr)` | Element-wise rate of a tuple of counters. | per-i `(v[i]−old[i])/(t−old_t)` |
| `CMakeTuple(*calcs)` | Compose several calcs into a tuple. | `(c1.handle(), c2.handle(), …)` |

`expr` is a Python `%`-format string referencing status vars, e.g. `"%(Bytes_received)s"` or a big additive expression of `Com_*`. The widget definition pairs a **calc class** with an **expression**; on first sample the rate calc returns `None` (no previous point), thereafter the per-second delta. `reset()` is called on `server_started` to avoid a huge spurious spike across a restart.

**Widget declaration model (`GLOBAL_DASHBOARD_WIDGETS_*`).** Each widget is a tuple:

```
(caption, WidgetClass, args, init, (CalcClass, calc_expr), color, (x, y), hover_template)
```

`create_ui()` (`:679-733`) iterates the concatenated list for the detected version
(`NETWORK + MYSQL_PRE_80 + INNODB`, or `…MYSQL_POST_80…` for ≥8.0), instantiates `WidgetClass(CalcClass(expr), *args)`, positions it, sets color, optional `init()` (e.g. level-meter max from `max_connections`), and stores it. The post-8.0 set differs only by adding `Com_*_role` / `Com_alter_user_default_role` to the statement-count expressions (8.0 added roles).

See **Appendix A** for the exact expression of every widget.

**Rendering model (`charting.py`).** Widgets are Cairo `Figure`s on a `Canvas`; `RenderBox` (a `mforms.PyDrawBox`) forwards `repaint`/mouse events. Graph types:
- `DBTimeLineGraph` — the throughput/connection graphs (detailed in §8).
- `DBSimpleCounter` — a rounded rectangle showing a formatted number with K/M/G scaling ("SELECT 1.2 K/s").
- `DBRoundMeter` — an arc 0–100% efficiency/usage ring.
- `DBLevelMeter` — a vertical bar with "limit" and "max seen" markers (connections vs `max_connections`).
- `DBImage`, `DBText` — static labels/headers.

**State.** Each `DBTimeLineGraph` keeps an in-memory `_points` ring (per dataset) of `(value, timestamp)` trimmed to the pixel width. Counters/meters keep only the last value. Nothing persisted.

**Performance considerations.** Trivial server load: one `SHOW GLOBAL STATUS` per 3 s. All computation is client-side. The only cost is repaint.

**PHP reimplementation strategy.**
- Port the calc engine verbatim: a `RatePerSecond`, `RawValue`, `TupleRatePerSecond`, `MakeTuple` set of classes operating on an associative array of status vars + a timestamp.
- Replace `%(Var)s`-format expressions with either (a) a tiny safe expression evaluator over the var array, or (b) precompiled PHP closures `fn($v) => $v['Bytes_received']`. **Do not** use `eval()` on server data; precompile closures at definition time (the expressions are static constants, not user input).
- Keep the same widget-definition table as a PHP array of structs.
- Render with Unicode braille/block sparklines (see §8, §14). The fixed 1024×700 absolute layout becomes a responsive 3-panel TUI grid.
- Drive it from a single `SHOW GLOBAL STATUS` poll on a 1–3 s interval (configurable).

---

### 5.2 ★ Performance Reports (`wb_admin_perfschema_reports.py` + `res/scripts/sys/sys_reports.js`)

**Purpose.** A library of ~35 canned diagnostic reports (top queries, full-table-scans, I/O hot spots, wait analysis, schema/table/index stats, memory by user/host/thread, user resource use) presented as sortable, exportable tables.

**UX behavior.**
- Left tree groups reports by category; right side is a tabless tab-view, one grid per report.
- Each grid: multi-select, sortable columns, per-column **display-unit switching** (latency columns toggle us/ms/s/h:m:s; byte columns toggle Bytes/KB/MB/GB via a header context menu), column widths persisted in `grt.root.wb.state`.
- Buttons: **Refresh**, **Export… (CSV)**, **Copy Selected**, **Copy Query**.
- Query runs on a worker thread with an indeterminate progress bar; a 1 s poll checks completion.

**Data source.** The `sys` schema views. Categories and report→view mappings come from the bundled JSON `sys_reports.js`. Min version `(5,6,6)`; requires `performance_schema=ON` and an installed/up-to-date `sys` schema (validated by the PS base tab).

**SQL.**
- Discover available views: `show full tables from sys where Table_type='VIEW'` (`:486`). Reports whose `view` isn't present are skipped (graceful degradation across versions).
- Column metadata when not provided in JSON: `DESCRIBE \`sys\`.<view>` (`:334`), with heuristic typing (names ending in "atency"/"ead"/"ritten" → number-with-unit; char/varchar → string with width = length×10 capped; bigint → long; decimal → integer).
- Run a report: from `JSSourceHelperViewTab`, `SELECT * FROM sys.\`<view>\` [LIMIT n]` (`:438,450`). Reports use the **`x$` raw variants** (e.g. `x$statement_analysis`) so values come back as raw picoseconds/bytes and the client formats them; non-`x$` views (already human-formatted) are used where no reformatting is desired.

**Polling / update.** None. Reports refresh only on demand (Refresh button or report selection). They are point-in-time aggregates, not live.

**Internal abstractions.**
- `unit_formatters` (`:38-48`): latency picoseconds → us/ms/s/h:m:s; bytes → KB/MB/GB. (Note: `sys.x$` latency is in **picoseconds**; the formatters divide by 1e6/1e9/1e12.)
- `known_column_types` maps JSON type strings ("Time","Bytes","LongInteger","StringLT",…) to mforms column types + default unit.
- Report definitions are **data, not code** — see Appendix B for the full catalog.

**Rendering model.** Plain tree/grid (no graphs). Latency/byte cells are pre-formatted strings; numeric cells use long/float columns for correct sorting.

**State.** Per-column unit + width persisted to `grt.root.wb.state["wb.admin.psreport:unit:<view>:<i>"]`. Result sets are not cached between refreshes.

**Performance considerations.** Some views are heavy (e.g. `schema_object_overview` is explicitly labeled "High Overhead"; `x$schema_table_statistics_with_buffer` scans `INNODB_BUFFER_PAGE`). Workbench runs them off the UI thread and warns in descriptions.

**PHP reimplementation strategy.**
- Ship the **same `sys_reports.js`** (or a PHP/JSON port). Iterate, skip views not present (`SHOW FULL TABLES FROM sys WHERE Table_type='VIEW'`).
- Implement the unit formatters exactly (picoseconds → time, bytes → IEC/SI). Provide a TUI header action to cycle units per column.
- Run `SELECT * FROM sys.\`<view>\` [LIMIT n]`, render a scrollable, sortable table widget. Export to CSV identically.
- Gate behind a "PS available + sys installed" check; if missing, show the same install/permissions guidance.
- **This whole feature is essentially free to port** — it's declarative.

---

### 5.3 ★ Performance Schema Setup (`wb_admin_perfschema_instrumentation*.py`)

**Purpose.** Configure instrumentation: enable/disable/timed instruments, enable consumers, restrict by actors (user/host) and objects (schema/table), per-thread instrumentation, and pick the timer source. Plus an "Easy Setup" big on/off/default switch.

**UX behavior (tabs).**
1. **Easy Setup** — a big graphical switch with four detected states: `fully` / `default` / `custom` / `disabled`. Flipping it bulk-updates everything (with a perf warning when enabling everything).
2. **Introduction** — static help.
3. **Instruments** — a hierarchical tree (split on `/`) with **tri-state** "Enabled" and "Timed" checkboxes (`TriCheckColumnType`): a parent shows checked/unchecked/mixed based on children.
4. **Consumers** — flat checkbox list.
5. **Actors & Objects** (≥5.6) — two grids; add/remove rows.
6. **Threads** (≥5.6) — per-thread "Instrumented" checkbox grid with processlist columns.
7. **Options** — timer selection + PS-related variables.

**Data source / SQL (load) — `wb_admin_perfschema_instrumentation_be.py`:**

| Model | Load SQL | Notes |
|---|---|---|
| `PSInstruments` | `SELECT * FROM performance_schema.setup_instruments` | NAME split on `/` into a `PSInstrumentGroup` tree; ENABLED/TIMED → 1/0 leaves; group states computed bottom-up (tri-state −1/0/1). |
| `PSConsumers` | `SELECT * FROM performance_schema.setup_consumers` | name→enabled bool. |
| `PSTimers` | `SELECT name, timer_name FROM performance_schema.setup_timers` | **Pre-8.0 only** (table removed in 8.0). |
| `PSActors` | `SELECT user, host FROM performance_schema.setup_actors` | |
| `PSObjects` | `SELECT * FROM performance_schema.setup_objects` | `ENABLED` column exists ≥5.6.3; before that all matching rows were implicitly enabled. |
| `PSThreads` | `SELECT THREAD_ID,NAME,TYPE,PROCESSLIST_*,SUBSTRING(PROCESSLIST_INFO,1,80) AS INFO,PARENT_THREAD_ID,INSTRUMENTED FROM performance_schema.threads` | |
| `PSVariables` | `SHOW variables LIKE 'performance_schema%'` | |
| timer types | `SELECT * FROM performance_schema.performance_timers WHERE timer_frequency IS NOT NULL AND timer_resolution IS NOT NULL AND timer_overhead IS NOT NULL ORDER BY timer_name` | NULL columns ⇒ timer unavailable. |

Version gating (`PSConfiguration.__init__`, `_be:712-718`): `setup_actors`/`setup_objects`/`threads` only added ≥5.6; `setup_objects.ENABLED` only ≥5.6.3.

**SQL (commit) — minimal change-set generation.** Each model implements `get_commit_statements()` from a `ChangeTracker`:
- **Instruments** — collapses changes to the highest tree level that fully shares a value, builds a regex, and emits:
  `UPDATE performance_schema.setup_instruments SET ENABLED='YES' WHERE NAME RLIKE '<^a|^b*|…>'` (one statement per (column,value) bucket; trailing `*` group → prefix regex, leaf → anchored `^name$`). (`_be:203-233`)
- **Consumers** — `UPDATE … setup_consumers SET enabled='YES' WHERE NAME IN ('a','b')` (+ the 'NO' counterpart). (`_be:333-350`)
- **Actors** — `INSERT INTO setup_actors VALUES ('user','host','%')` / `DELETE … WHERE user=… AND host=…`. (`_be:456-467`)
- **Objects** — `UPDATE setup_objects SET col='YES' WHERE object_type=… AND object_schema=… AND object_name=…`, plus INSERT/DELETE. (`_be:525-549`)
- **Threads** — `UPDATE performance_schema.threads SET INSTRUMENTED='YES' WHERE THREAD_ID IN (…)`. (`_be:678-698`)
- **Timers** — `UPDATE setup_timers SET timer_name='…' WHERE name='…'`. (`_be:597-605`)

All commits run through `ctrl_be.exec_sql()`; `PSConfiguration.commit_changes()` only emits statements for sections with `change_count > 0`, then resets change tracking (`_be:765-782`).

**Easy Setup state detection (`wb_admin_perfschema_instrumentation.py:149-207`).** Pure SQL counting:
- **fully**: `COUNT(*) FROM setup_consumers WHERE enabled='NO'` == 0 **and** `COUNT(*) FROM setup_instruments WHERE NAME NOT LIKE 'memory/%' AND (enabled='NO' OR timed='NO')` == 0. (memory/* can't be disabled, so excluded.)
- **disabled**: same with `'YES'`.
- **default**: compares current consumers/instruments against `DEFAULT_CONSUMERS_5x` / `DEFAULT_INSTRUMENTS_5x` via `SUM(IF(...))` expressions returning 0 when everything matches the default profile.
- **custom**: otherwise.

Easy Setup toggle SQL (`:232-261`):
- fully → `UPDATE setup_consumers SET enabled='YES'` + `UPDATE setup_instruments SET enabled='YES', timed='YES'`
- disabled → both to `'NO'`
- default → `UPDATE setup_instruments SET ENABLED=IF(<default regex/in>,'YES','NO'), TIMED=ENABLED` + `UPDATE setup_consumers SET ENABLED=IF(NAME IN(<defaults>),'YES','NO')`

See **Appendix C** for the default sets.

**Internal abstractions.** A `ChangeTracker` / `ChangeCounter` / `ChangeNotifierDict`/`List` family (in `workbench.change_tracker`) underpins dirty-tracking, revert, and counting so the UI can show pending changes and the commit can be minimal. Tri-state propagation: setting a group cascades to children (`set_children_state`) and recomputes ancestors (`set_state_from_children`: all-on→1, all-off→0, mixed→−1).

**Validation / dependency handling.** Avoids invalid states by: excluding `memory/%` instruments (which cannot be disabled) from "all-disabled" logic; only exposing tables that exist for the server version; reverting uncommitted UI edits via `refresh()` walking the change set; warning before enabling everything.

**PHP reimplementation strategy.**
- Recreate the six models as plain classes loading from the same `setup_*` queries. Represent instruments as a tree keyed by `/` tokens; store `enabled`/`timed` ∈ {0,1} on leaves and {−1,0,1} on groups.
- Implement dirty-tracking (original vs current) and the **same minimal-diff commit** (RLIKE for instruments, IN() for consumers/threads, row-keyed UPDATE/INSERT/DELETE for objects/actors).
- Reproduce Easy Setup's four COUNT/SUM detection queries verbatim and the toggle statements.
- TUI: a collapsible tree with tri-state `[x]/[ ]/[~]` glyphs for Instruments; flat checkbox lists for Consumers/Threads; simple add/remove grids for Actors/Objects; a selector for the timer.
- **Privilege note:** writing `setup_*` requires `UPDATE` on `performance_schema.setup_*` (historically tied to having access; effectively a privileged/admin operation). Reading needs `SELECT` on `performance_schema`.

---

### 5.4 Server Status (`wb_admin_server_status.py` + `wb_admin_monitor.py`)

**Purpose.** At-a-glance health: a connection-info card (host/socket/port/version/compiled-for/config file/uptime), a status light, live sidebar gauges, and expandable info sections (available features, directories, replica, authentication, SSL, firewall).

**UX behavior.** Left = info card + scrollable feature/dir/replica/SSL/firewall sections; right = a vertical stack of live gauges. A 0.5 s UI timer (`_update_timeout`) refreshes labels; the status light reflects running/offline/stopped.

**Data sources.**
- `ctrl_be.server_variables` (SHOW VARIABLES) for host/socket/port/version/dirs/SSL paths/feature flags.
- `ctrl_be.status_variables` (SHOW GLOBAL STATUS) for `Uptime`, firewall counters.
- `ctrl_be.server_active_plugins` (SHOW PLUGINS) for memcached/PAM/Windows auth/semisync presence.
- `SHOW SLAVE STATUS` for replica IO state / master host (error 1227 → "insufficient privileges").
- Disk space via `server_helper.get_available_space(datadir)` (SSH/local; optional).
- Sidebar CPU via shell/WMI (see monitor below).

**SQL.** `SHOW VARIABLES`, `SHOW GLOBAL STATUS`, `SHOW PLUGINS` (all via ctrl_be caches), `SHOW SLAVE STATUS`, and `SET @@GLOBAL.GTID_MODE=…` when the GTID selector changes (≥5.7.6).

**Polling.** `add_timeout(0.5, self.update)` — but `update()` mostly reads caches, so effective metric freshness is still 3 s. Sidebar gauges have their own 3 s backend (below).

**Feature detection logic.** A `tristate(value, true_value)` helper maps OFF/NO→False, set→True, missing→None(n/a). Features probed: `performance_schema`, `thread_handling=loaded-dynamically` (thread pool), `daemon_memcached` plugin, semisync master/slave vars, `have_openssl`/`have_ssl`, PAM/Windows auth plugin, `validate_password_policy`, `audit_log_policy`, `mysql_firewall_mode`.

**Sidebar gauges (`wb_admin_monitor.py`).** Uses **native mforms widgets** (`newBarGraphWidget`, `newLineDiagramWidget`) with `set_thresholds([critical],[warning…])` and `enable_auto_scale`. Data comes from `WBAdminMonitorBE` (a *second* poll thread at `UPDATE_INTERVAL=3 s`) whose `DBStatusDataSource` reads `ctrl_be.status_variables`, plus a `ShellDataSource`/`WMIStats`/`WinRemoteStats` for host CPU. Gauges & their math (delta ÷ 3 s interval):

| Gauge | Vars | Calc |
|---|---|---|
| CPU/Load | host `uptime` load avg (or WMI `PercentProcessorTime`) | raw load number / percentage |
| Connections | `Threads_connected` | raw |
| Traffic | `Bytes_sent` | `(now−last)/interval` B/s |
| Key Efficiency | `Key_reads`,`Key_read_requests` | `100 − (Key_reads/Key_read_requests·100)/interval` |
| Selects/sec | `Com_select` | `(now−last)/interval` |
| InnoDB Buffer Usage | `Innodb_buffer_pool_pages_free`,`…_total` | `100·(total−free)/total` |
| InnoDB Reads/sec | `Innodb_data_reads` | `(now−last)/interval` |
| InnoDB Writes/sec | `Innodb_data_writes` | `(now−last)/interval` |

(Query Cache gauges are commented out — removed in 8.0.)

**PHP reimplementation strategy.**
- Build the info card from `SHOW VARIABLES` + `Uptime` (running-since = `now − Uptime`, formatted as duration).
- Reproduce `tristate` and the same feature/dir/SSL/replica probes. `SHOW REPLICA STATUS` / `SHOW SLAVE STATUS` (version-dependent) for the replica panel.
- Sidebar gauges: same delta-per-interval math over the status snapshot; render as small sparklines or threshold-colored bars.
- CPU/load gauge: optional — via SSH `uptime`/`/proc/stat` (Linux), or skip when no remote channel.

---

### 5.5 Client Connections (`wb_admin_connections.py`)

**Purpose.** A live process list with kill, query EXPLAIN, per-connection details, connection attributes, metadata-lock (MDL) inspector, and a Performance-Schema thread-stack viewer.

**UX behavior.** Sortable multi-select grid; configurable auto-refresh rate; checkboxes "Hide sleeping connections", "Hide background threads", "Don't load full thread info"; a counters strip (Threads connected/running/created/cached, rejected, total, limit, aborted, errors); a collapsible Details sidebar with Details/Locks/Attributes tabs; context menu (Copy, Show in Editor, Explain, View Thread Stack, Enable/Disable Instrumentation, Kill Query/Connection).

**Data source & SQL.**
- **PS path (≥5.6 with PS on)** — chosen by `SELECT @@performance_schema`:
  `SELECT <cols> FROM performance_schema.threads t LEFT OUTER JOIN performance_schema.session_connect_attrs a ON t.processlist_id=a.processlist_id AND (a.attr_name IS NULL OR a.attr_name='program_name') WHERE t.TYPE <> 'BACKGROUND'` (or `WHERE 1=1` when not hiding bg). `PROCESSLIST_INFO` truncated to 255 with `SUBSTR(...)` when "don't load full info" is on; event_scheduler user fixed up via `IF(NAME='thread/sql/event_scheduler',…)`.
- **Fallback (<5.6 or PS off)** — `SHOW FULL PROCESSLIST`.
- Counters strip — `SHOW GLOBAL STATUS` filtered to `Threads_*`, `Connections`, `Aborted_*`, `Connection_errors_*`; `max_connections` from server_variables.
- Attributes tab — `SELECT * FROM performance_schema.session_connect_attrs WHERE processlist_id=<id> ORDER BY ORDINAL_POSITION`.
- MDL tab (≥5.7.3) — `SELECT * FROM performance_schema.metadata_locks WHERE owner_thread_id=<tid>`; cross-references GRANTED/PENDING owners. Enabling MDL: `UPDATE setup_instruments SET enabled='YES' WHERE name='wait/lock/metadata/sql/mdl'`.
- Thread stack — `SELECT sys.ps_thread_stack(<tid>, <bool>)` → JSON parsed into a tree.
- Kill — `KILL CONNECTION <id>` / `KILL QUERY <id>` (background threads refused).
- Toggle instrumentation — `UPDATE performance_schema.threads SET instrumented='YES'|'No' WHERE thread_id=<id> LIMIT 1`.
- Explain — delegates to `grt.modules.SQLIDEQueryAnalysis.visualExplainForConnection` (visual EXPLAIN; ≥5.7).

**Polling.** Refresh selector values `[0.5,1,2,3,4,5,10,15,30]` seconds + "Don't Refresh" (default = Don't Refresh, index 9). A `serial` counter cancels stale timers; refresh re-selects the previously selected row by id.

**PHP reimplementation strategy.**
- Same two-path approach: prefer `performance_schema.threads` (+attrs join) and fall back to `SHOW FULL PROCESSLIST`. Provide the same filters/counters.
- Kill / kill-query / toggle-instrumentation map directly. MDL inspector and attributes are straight PS queries. Thread-stack via `sys.ps_thread_stack` (needs statement+stage instrumentation & history consumers).
- Visual EXPLAIN is Workbench-specific; in a TUI, fall back to `EXPLAIN FORMAT=JSON`/`EXPLAIN ANALYZE` text for the selected statement.

---

### 5.6 Status & System Variables (`wb_admin_variables.py` + `wb_admin_variable_list.py`)

**Purpose.** Browse/search/group/edit server status counters and system variables, with descriptions and (8.0) persisted-variable management.

**UX behavior.** Two sub-tabs: **Status Variables** (`SHOW GLOBAL STATUS`) and **System Variables** (`SHOW GLOBAL VARIABLES`). Left category tree (`All`, `Filtered`, generated groups, `Other`, `Persisted`, custom user groups); search box (filters the `Filtered` node); right grid Name/Value/Description (+ Persist checkbox & Persist Value columns when ≥8.0). Editable variables show a `[rw]` prefix and are inline-editable. Copy-all / copy-selected to clipboard. Custom groups saved as JSON in the user data folder.

**Data sources.**
- Live values: `SHOW GLOBAL STATUS` / `SHOW GLOBAL VARIABLES`.
- Metadata (description, editable bool, category groups): the **generated** `wb_admin_variable_list.py` — flat lists of tuples `(name, 'description', editable, ['Group/Subgroup', …])`. Built offline from `backend/config/gen-opt/mysqld.xml` by the `gen-opt` scripts. Names are normalized (`-`→`_`).
- Persistence (≥8.0.1): `SELECT * FROM performance_schema.persisted_variables`; `ro_persistable` list flags read-only-but-persistable vars.

**SQL (writes).**
- Edit: `SET GLOBAL <name>=<int>` or `SET GLOBAL <name>='<escaped>'`, then re-read with `SHOW … LIKE '<name>'`.
- Persist (8.0): `SET PERSIST <name>=…` / `SET PERSIST_ONLY <name>=…`; `RESET PERSIST [<name>]`.

**PHP reimplementation strategy.**
- Ship the generated `variable_list` as PHP arrays/JSON (port the tuples). Provide search + group filtering + the `[rw]` indicator and inline edit (`SET GLOBAL`).
- 8.0 persistence is a clean add-on (`performance_schema.persisted_variables`, `SET PERSIST`).
- Descriptions are static reference data; ship them with the app (no live source).

---

## 6. SQL Query Catalog

Grouped by privilege/feature need. (`?`/`<…>` = parameter.)

### 6.1 Core (low-privilege, no PS) — used everywhere
```sql
SHOW GLOBAL STATUS;                 -- 3s poll; dashboard, monitor, connections counters, vars
SHOW VARIABLES;                     -- server_variables cache
SHOW GLOBAL VARIABLES;              -- System Variables tab, copy-all
SHOW PLUGINS;                       -- active plugin detection (>=5.1.9; 'SHOW PLUGIN' for 5.1.5-5.1.8)
SELECT 1;                           -- ping / liveness
SHOW <name> LIKE '<var>';           -- re-read a single var after edit
SET GLOBAL <name>=<value>;          -- edit a variable
SET PERSIST <name>=<value>;         -- 8.0 persistence
SET PERSIST_ONLY <name>=<value>;    -- 8.0 persistence (read-only-at-runtime vars)
RESET PERSIST [<name>];             -- 8.0 clear persisted
SET @@GLOBAL.GTID_MODE=<mode>;      -- Server Status GTID selector (>=5.7.6)
```

### 6.2 Process list / connections
```sql
SHOW FULL PROCESSLIST;              -- fallback (no PS)
SELECT @@performance_schema;        -- choose PS vs legacy path
-- PS path:
SELECT <cols> FROM performance_schema.threads t
  LEFT OUTER JOIN performance_schema.session_connect_attrs a
    ON t.processlist_id=a.processlist_id
   AND (a.attr_name IS NULL OR a.attr_name='program_name')
  WHERE t.TYPE <> 'BACKGROUND';     -- or WHERE 1=1
SELECT * FROM performance_schema.session_connect_attrs
  WHERE processlist_id=? ORDER BY ORDINAL_POSITION;
SELECT * FROM performance_schema.metadata_locks WHERE owner_thread_id=?;          -- MDL (>=5.7.3)
SELECT sys.ps_thread_stack(?, <TRUE|FALSE>);                                      -- thread stack
UPDATE performance_schema.threads SET instrumented=? WHERE thread_id=? LIMIT 1;
KILL CONNECTION ?;   KILL QUERY ?;
```

### 6.3 Replication / status
```sql
SHOW SLAVE STATUS;                  -- (8.0: SHOW REPLICA STATUS) Server Status replica panel
```

### 6.4 Performance Reports (require PS + sys)
```sql
SHOW FULL TABLES FROM sys WHERE Table_type='VIEW';   -- enumerate available report views
DESCRIBE `sys`.<view>;                                -- infer columns when not in JSON
SELECT * FROM sys.`<view>` [LIMIT n];                 -- the report itself
SELECT sys_version FROM sys.version;                  -- sys schema version check
```
See Appendix B for the exact `<view>` list.

### 6.5 Performance Schema Setup (require PS; writes are privileged)
```sql
SELECT * FROM performance_schema.setup_instruments;
SELECT * FROM performance_schema.setup_consumers;
SELECT name, timer_name FROM performance_schema.setup_timers;     -- < 8.0 only
SELECT user, host FROM performance_schema.setup_actors;           -- >= 5.6
SELECT * FROM performance_schema.setup_objects;                   -- >= 5.6
SELECT THREAD_ID,NAME,TYPE,PROCESSLIST_ID,PROCESSLIST_USER,PROCESSLIST_HOST,
       PROCESSLIST_DB,PROCESSLIST_COMMAND,PROCESSLIST_TIME,PROCESSLIST_STATE,
       SUBSTRING(PROCESSLIST_INFO,1,80) AS INFO,PARENT_THREAD_ID,INSTRUMENTED
  FROM performance_schema.threads;
SELECT * FROM performance_schema.performance_timers
  WHERE timer_frequency IS NOT NULL AND timer_resolution IS NOT NULL
    AND timer_overhead IS NOT NULL ORDER BY timer_name ASC;
SHOW variables LIKE 'performance_schema%';
SELECT * FROM performance_schema.persisted_variables;             -- 8.0 (vars tab)

-- Easy-setup detection (counts):
SELECT COUNT(*) FROM performance_schema.setup_consumers WHERE enabled='NO';
SELECT COUNT(*) FROM performance_schema.setup_instruments
  WHERE NAME NOT LIKE 'memory/%' AND (enabled='NO' OR timed='NO');
-- (+ the 'YES' mirror and the SUM(IF(...)) default-profile comparisons)

-- Commits (minimal diff):
UPDATE performance_schema.setup_instruments SET ENABLED='YES' WHERE NAME RLIKE '<regex>';
UPDATE performance_schema.setup_instruments SET TIMED='NO'   WHERE NAME RLIKE '<regex>';
UPDATE performance_schema.setup_consumers   SET enabled='YES' WHERE NAME IN ('a','b');
INSERT INTO performance_schema.setup_actors VALUES ('user','host','%');
DELETE FROM performance_schema.setup_actors WHERE user='u' AND host='h';
UPDATE performance_schema.setup_objects SET <cols> WHERE object_type=? AND object_schema=? AND object_name=?;
INSERT INTO performance_schema.setup_objects VALUES (?,?,?,?,?);
DELETE FROM performance_schema.setup_objects WHERE object_type=? AND object_schema=? AND object_name=?;
UPDATE performance_schema.threads SET INSTRUMENTED='YES' WHERE THREAD_ID IN (...);
UPDATE performance_schema.setup_timers SET timer_name=? WHERE name=?;             -- < 8.0
UPDATE performance_schema.setup_instruments SET enabled='YES' WHERE name='wait/lock/metadata/sql/mdl';
```

### 6.6 sys schema install / version
```sql
SELECT @@performance_schema;          -- PS usable validation
SHOW GRANTS;                          -- privilege gate for install
-- install: run bundled sys_<maj><min>.sql (or server's share/mysql_sys_schema.sql), with DELIMITER handling
```

---

## 7. Performance Schema Usage Catalog

### 7.1 `performance_schema.*` tables touched directly
- `setup_instruments`, `setup_consumers`, `setup_actors`, `setup_objects`, `setup_timers` (≤5.7), `performance_timers`, `threads`, `session_connect_attrs`, `metadata_locks`, `persisted_variables` (8.0).

### 7.2 `sys.*` views consumed (Performance Reports + helpers)
Both human-formatted views and their `x$` raw-number twins. (Reports pick `x$` where the client reformats units.) Full list in **Appendix B**. Notable:
- Statements: `x$statement_analysis`, `x$statements_with_runtimes_in_95th_percentile`, `statements_with_temp_tables`, `statements_with_sorting`, `statements_with_full_table_scans`, `statements_with_errors_or_warnings`.
- I/O: `x$io_global_by_file_by_bytes`, `x$io_global_by_file_by_latency`, `x$io_global_by_wait_by_bytes`, `x$io_global_by_wait_by_latency`, `x$io_by_thread_by_latency`.
- Waits: `x$waits_global_by_latency`, `x$waits_by_user_by_latency`, `x$wait_classes_global_by_latency`, `x$wait_classes_global_by_avg_latency`.
- Schema/index/table: `schema_object_overview`, `x$schema_index_statistics`, `x$schema_table_statistics[_with_buffer]`, `schema_tables_with_full_table_scans`, `schema_unused_indexes`.
- InnoDB buffer: `x$innodb_buffer_stats_by_schema`, `x$innodb_buffer_stats_by_table` (summarize `INFORMATION_SCHEMA.INNODB_BUFFER_PAGE`).
- Memory: `x$memory_global_total`, `x$memory_global_by_current_bytes`, `x$memory_by_{user,host,thread}_by_current_bytes`.
- User resource: `x$user_summary`, `x$user_summary_by_file_io_type`, `x$user_summary_by_statement_type`.
- Functions: `sys.ps_thread_stack(thread_id, debug)`.

### 7.3 INFORMATION_SCHEMA usage
Indirect — via `sys` views that read `INFORMATION_SCHEMA.INNODB_BUFFER_PAGE` (the InnoDB buffer reports). The admin Python itself does not query `INFORMATION_SCHEMA` directly in these pages.

### 7.4 What needs PS vs what does not

| Feature | Needs `performance_schema` | Needs `sys` | Works on plain low-priv account |
|---|---|---|---|
| Performance Dashboard | ❌ | ❌ | ✅ (SHOW GLOBAL STATUS/VARIABLES) |
| Server Status | ❌ | ❌ | ✅ (some panels need SELECT on a few I_S/SHOW; replica needs REPLICATION CLIENT) |
| Status/System Variables | ❌ | ❌ | ✅ (edit needs SYSTEM_VARIABLES_ADMIN/SUPER) |
| Client Connections (basic) | ❌ (uses SHOW PROCESSLIST) | ❌ | ✅ (full list needs PROCESS priv) |
| Client Connections (PS extras: attrs, MDL, stack, per-thread instr.) | ✅ | partial (`sys.ps_thread_stack`) | ❌ |
| Performance Reports | ✅ | ✅ | ❌ |
| Performance Schema Setup | ✅ | ❌ (sys not strictly required) | read ✅ / write ❌ (privileged) |

---

## 8. Graphing / Visualization Architecture

Two distinct rendering systems coexist.

### 8.1 Dashboard charts — bespoke Cairo figures (`charting.py`)

**`DBTimeLineGraph`** (the throughput/connection graphs):
- State: `self._points = [[(value,timestamp), …] per dataset]`, `_width=160`, `_height=120`, `_seconds_per_hpixel=1`, `_vertical_dividers=4`, `_auto_scale_vaxis=True`, `_scale=100`.
- **Ingest (`process`)**: `values = calc.handle(data, timestamp)`; append `(value, ts)`; **trim** while `(t_last − t_first)/seconds_per_hpixel > width` — i.e. keep ~160 s of history (because 1 s = 1 px and width=160). For multi-dataset graphs (the SQL-statements graph has 3 series), each series is trimmed independently.
- **Auto-scale**: take the current max sample, then round **up** to a "nice" ceiling — take the max as a decimal string, increment the first digit, zero the rest (e.g. 4 500 → first digit 4→5, rest "000" → 5 000), floor at 100. This keeps the y-axis stable and readable.
- **Render**: x-axis line; 4 horizontal grid lines with formatted axis labels (`format_vaxis_value`, which scales via `scale_value` and swaps `%f`→`%i` when unit is empty); polyline drawn **right→left** (`x=width; for p in reversed(points): x -= round(Δt/seconds_per_hpixel)`), newest sample anchored at the right edge. A hover "detail line" prints each series' value "N seconds ago".
- **`scale_value(v)`**: **binary (1024-based)** K/M/G/T scaling. (Note: the Server Status traffic label uses 1024 too; the Reports unit formatters use SI 1000-based — be deliberate about which you copy.)

**`DBSimpleCounter`** — rounded rect; `set(value)` → optional `scale_value` → `format % (value, unit)`.
**`DBRoundMeter`** — arc from 0 to `value·2π`; label `"<int(value·100)>%"`; rotated caption.
**`DBLevelMeter`** — vertical fill of `value/max·height`; draws "limit <max>" and "max <max_seen>" markers; tracks `_max_seen_value`.
**`DBHBarMeter`** — split green/red horizontal bar for ratio pairs.

### 8.2 Server Status sidebar — native mforms widgets
`newBarGraphWidget` (CPU) and `newLineDiagramWidget` (connections/traffic/key-eff/QPS/InnoDB). Configured with:
- `enable_auto_scale(True)`
- `set_thresholds([critical_levels], [warning_levels])` — e.g. traffic `set_thresholds([0.0], [100000,1000000,10000000,100000000])`. These thresholds drive **severity coloring** (the higher band the current value falls into, the more "warning" the color), and provide auto-scale step hints.

### 8.3 Severity / threshold / warning logic
- Dashboard graphs themselves have **no** color-severity; color is a fixed per-widget brand color (read=teal `(60,178,191)`, write=orange `(253,138,39)`, green for connections, etc.).
- Severity coloring lives only in the native sidebar widgets via `set_thresholds`. There is no numeric "score/rating" of overall health beyond the running/offline/stopped status light.
- Round meters implicitly communicate health by fill fraction (e.g. Table Open Cache efficiency, InnoDB buffer usage).

### 8.4 Reimplementing graphs in a terminal

| Workbench widget | TUI technique |
|---|---|
| `DBTimeLineGraph` | **Braille line graph** (`⣀⣤⣶⣿` via U+2800 block, 2×4 dots/cell → 2× horizontal, 4× vertical resolution) or block sparkline `▁▂▃▄▅▆▇█`. Keep the same `(value,ts)` ring trimmed to panel width·resolution; auto-scale identically. |
| `DBSimpleCounter` | A label cell: `SELECT  1.2 K/s` with the same `scale_value`. |
| `DBRoundMeter` | A percentage + a horizontal gauge `[█████░░░░] 56%` (terminals have no easy arc; a bar reads fine). |
| `DBLevelMeter` | Vertical bar using stacked block chars, or a horizontal `current/limit` bar with a "max" marker. |
| Threshold colors | ANSI 256/truecolor: green→yellow→red by which threshold band the value sits in. |

A braille canvas of width W cells gives 2W horizontal points — so an 80-col panel yields ~160 points, conveniently matching Workbench's 160-sample ring.

---

## 9. Polling / Refresh Architecture

| Timer | Interval | Where | Drives |
|---|---|---|---|
| Status poll thread | **3 s** (`status_variable_poll_interval`) | `wb_admin_control.py:556` | `SHOW GLOBAL STATUS` → `status_variables` cache (the master clock) |
| Dashboard UI refresh | **3 s** (uses poll interval) | `performance_dashboard.py:728` | feeds widgets from cache, repaints |
| Monitor backend poll thread | **3 s** (`UPDATE_INTERVAL`) | `wb_admin_monitor.py:28`, `wba_monitor_be.py:468` | sidebar gauges + host CPU |
| Server Status UI update | **0.5 s** | `server_status.py:255` | label refresh from caches + `SHOW SLAVE STATUS` |
| UI task queue drain | **0.5 s** | `wb_admin_main.py:140` | marshals background results to UI thread |
| Client Connections refresh | selectable **0.5/1/2/3/4/5/10/15/30 s** or off (default off) | `connections.py:442,903` | reload process list |
| Performance Reports | on-demand only | `perfschema_reports.py` | run report query (worker thread + 1 s completion poll) |
| PS Setup | on-demand | — | load/commit |
| Variables | on-demand (+ on tab activate) | `variables.py` | re-query SHOW GLOBAL STATUS/VARIABLES |

**Design principle:** one slow backend poll (3 s) populates a shared cache; fast UI timers (0.5 s) only *sample* the cache and marshal cross-thread updates. A PHP TUI's event loop should mirror this: a single periodic `SHOW GLOBAL STATUS` tick feeding all live views, and on-demand fetches for reports/setup.

---

## 10. UI State Architecture

- **Live metric state**: in-memory only. `status_variables` (dict) + `status_variables_time`. Per-graph `(value,ts)` rings. Counters/meters keep last value. Rate calcs keep `(old_value, old_timestamp)`; `reset()` on server start.
- **Version/capability state**: `target_version` (parsed), `server_active_plugins` set, feature gates computed from `server_variables`.
- **PS Setup state**: full original snapshot of `setup_*` tables modeled as objects + a change-tracking overlay (dirty diff). Commit = minimal SQL; then reset tracking.
- **Persisted UI prefs** (`grt.root.wb.state` / options / JSON files):
  - report column widths & display units (`wb.admin.psreport:width|unit:<view>:<i>`),
  - connection-list column widths (`wb.admin:ConnectionListColumnWidths[PS]`),
  - connection refresh-rate index (`Administrator:refresh_connections_rate_index`),
  - custom variable groups (`<userdata>/custom_<status|system>_group.json`).
- **No time-series persistence** anywhere.

A PHP equivalent: an in-memory `StatusSnapshot` + per-metric ring buffers; a small JSON/INI settings file for UI prefs; optional SQLite for opt-in history.

---

## 11. Porting Strategy to PHP TUI

**Guiding principle:** the Python data/computation layer is portable 1:1; only mforms rendering and the SSH/WMI host-metrics path need replacement.

What ports directly (copy the logic):
- The calc engine (rate/raw/tuple), dashboard widget definition table, `scale_value`.
- The reports JSON + unit formatters + `SELECT * FROM sys.<view>` runner.
- The PS-setup table models, tri-state propagation, minimal-diff commit, Easy-Setup detection SQL.
- All feature/version/plugin detection (`tristate`, `is_supported_mysql_version_at_least`).
- The two-connection model (one foreground, one background poller) — in PHP, use a non-blocking tick rather than threads (PHP TUIs are single-process event loops).

What must be replaced:
- mforms widgets → a PHP TUI toolkit (PHP-TUI or a custom ANSI renderer).
- Cairo charts → braille/block sparklines.
- SSH via paramiko → `phpseclib3` (or shelling out to `ssh`).
- Background threads → cooperative scheduling: a tick loop with per-task "next run" timestamps, or `ext-pcntl`/async if available. For the 3 s poll, just run it on the loop tick; queries are fast.

What to simplify (see §21):
- Drop visual EXPLAIN (use text EXPLAIN), drop WMI/Windows-VBScript CPU (use SSH/`/proc`), drop config-file editing & start/stop (out of scope), drop the absolute-pixel layout (use responsive panels).

What requires PS / privileges (see §7.4, §24): Reports, PS Setup writes, PS connection extras.

---

## 12. Recommended PHP Architecture

```
app/
├── Db/
│   ├── Connection.php          PDO/mysqli wrapper: query(), exec(), ping(), serverVersion()
│   ├── ConnectionFactory.php   builds from DSN, optional SSH tunnel
│   └── SshTunnel.php           phpseclib3 local port-forward (optional)
├── Core/
│   ├── ServerContext.php       ≈ ctrl_be: caches statusVars, serverVars, plugins, version
│   ├── StatusPoller.php        runs SHOW GLOBAL STATUS on a schedule -> snapshot + ts
│   ├── EventBus.php            server_started/stopped/offline pub-sub
│   ├── Scheduler.php           cooperative timer loop (3s poll, 0.5s ui, per-page rates)
│   └── Version.php             parse + isAtLeast(maj,min,rel); flavor detection
├── Metrics/
│   ├── Calc/RawValue.php  RatePerSecond.php  TupleRatePerSecond.php  MakeTuple.php
│   ├── ScaleValue.php           1024-based K/M/G/T (and an SI variant for reports)
│   └── RingBuffer.php           fixed-width (value,ts) series + auto-scale ceiling
├── Reports/
│   ├── ReportCatalog.php        loads sys_reports.json
│   ├── ReportRunner.php         SELECT * FROM sys.<view> [LIMIT]
│   └── UnitFormatter.php        ps->us/ms/s/h:m:s ; bytes->KB/MB/GB
├── PerfSchema/
│   ├── SetupInstruments.php  SetupConsumers.php  SetupActors.php
│   ├── SetupObjects.php  SetupThreads.php  SetupTimers.php
│   ├── InstrumentTree.php       /-split tree, tri-state, minimal-diff commit
│   └── EasySetup.php            fully/default/custom/disabled detection + toggles
├── Pages/                       one per section, each = build() + refresh()
│   ├── DashboardPage.php  ServerStatusPage.php  ConnectionsPage.php
│   ├── VariablesPage.php  ReportsPage.php  PerfSchemaSetupPage.php
│   └── PageBase.php              validate()->errorScreen()/build()/refresh() lifecycle
├── Tui/
│   ├── App.php  Router/Sidebar  Theme(ANSI)  Table  Tree  Sparkline(braille)
│   └── Gauge  LevelMeter  Counter  StatusLight
└── data/
    ├── sys_reports.json          ported from res/scripts/sys/sys_reports.js
    └── variable_metadata.json    ported from wb_admin_variable_list.py
```

Event-loop sketch (single process):

```php
$loop->everyMs(3000, fn() => $poller->poll());        // SHOW GLOBAL STATUS -> snapshot
$loop->everyMs(500,  fn() => $ui->drainAndRedraw());  // sample cache, repaint active page
$loop->everyMs($connPage->rateMs(), fn() => $connPage->refresh());
$loop->onKey(...);                                    // navigation, actions
```

---

## 13. Recommended Class Design (key contracts)

```php
final class StatusSnapshot {
    /** @param array<string,string> $vars */
    public function __construct(public array $vars, public float $ts) {}
    public function num(string $k): float { return (float)($this->vars[$k] ?? 0); }
}

interface Calc { /** @return float|array|null */ public function handle(StatusSnapshot $s); }

final class RatePerSecond implements Calc {
    private ?float $old = null; private ?float $oldTs = null;
    /** @param callable(array):float $expr */ public function __construct(private $expr) {}
    public function reset(): void { $this->old = $this->oldTs = null; }
    public function handle(StatusSnapshot $s): ?float {
        $v = ($this->expr)($s->vars); $r = null;
        if ($this->old !== null && $s->ts > $this->oldTs)
            $r = ($v - $this->old) / ($s->ts - $this->oldTs);
        $this->old = $v; $this->oldTs = $s->ts; return $r;
    }
}

final class Widget {                 // a dashboard cell
    public function __construct(
        public string $kind,         // 'timeline'|'counter'|'round'|'level'
        public Calc $calc,
        public string $format,       // e.g. "%s/s"
        public array  $color,
        public string $hoverTpl = ''
    ) {}
}

final class RingBuffer {
    private array $points = [];      // [ [value, ts], ... ]
    public function __construct(private int $maxSeconds = 160) {}
    public function push(float $v, float $ts): void {
        $this->points[] = [$v, $ts];
        while ($this->points && ($this->points[array_key_last($this->points)][1] - $this->points[0][1]) > $this->maxSeconds)
            array_shift($this->points);
    }
    public function autoScaleCeiling(): float { /* first-digit+1, floor 100 */ }
    public function points(): array { return $this->points; }
}

interface SetupSection {            // PS setup model
    public function load(Connection $db): void;
    /** @return string[] */ public function commitStatements(): array;
    public function revert(): void;
    public function isDirty(): bool;
}
```

The dashboard widget list becomes a static array of `Widget`s whose `Calc->expr` closures reference snapshot keys — no `eval`, fully precompiled.

---

## 14. Suggested Terminal UI Layouts

**Dashboard** (responsive 3-column; each cell a small chart):
```
┌ Performance Dashboard ───────────────────────────── conn: prod · MySQL 8.0.36 ┐
│ NETWORK                  │ MYSQL                     │ INNODB                    │
│ ▼ in   1.2 MB/s          │ Table Open Cache  �$98%$   │ buf usage  ▓▓▓▓▓▓▓░ 73%   │
│ in  ⡀⡠⠤⠒⠉⠉⠒⠤  (160s)     │ SQL/s ⣀⣠⣴⣶⣿ sel/ins/oth  │ disk r ⡠⠊⠉ 4.0 MB/s       │
│ ▲ out  340 KB/s          │ SELECT  1.2k/s            │ disk w ⠒⠤⡀ 2.1 MB/s       │
│ out ⠉⠒⠤⢄⡀⡠⠤⠒  (160s)     │ INSERT  210/s  UPDATE 90  │ redo w 880 KB/s           │
│ conns  ▁▃▅▂▁ 42 / 151    │ DELETE  12/s   CREATE 0   │ dblwr  3/s                │
└──────────────────────────────────────────────────────────────────────────────┘
[Tab] switch panel  [p] pause  [r] reset  [1-6] sections  [q] quit
```

**Performance Reports**:
```
┌ Reports ──────────────┬ Statement Analysis ──────────────────────────────────┐
│ ▸ High Cost SQL       │ Query                         Exec   AvgTime  Rows…    │
│ ▾ Hot Spots for I/O   │ SELECT * FROM orders WHERE…    9.1k   12.4 ms  1.2M    │
│   • Top File I/O      │ UPDATE inventory SET …          412   88.0 ms   …      │
│ ▸ Wait Event Times    │ …                                                      │
│ ▸ Memory Usage        │ [Refresh] [Export CSV] [Copy] unit: [ms]               │
└───────────────────────┴────────────────────────────────────────────────────────┘
```

**PS Setup — Instruments** (tri-state tree):
```
Instrument                                   Enabled  Timed
▾ wait                                          [~]     [~]
  ▾ io                                          [x]     [x]
    ▸ file                                      [x]     [x]
    ▸ table                                     [x]     [ ]
  ▸ lock                                        [ ]     [ ]
▸ statement                                     [x]     [x]
[space] toggle  [a] all on  [n] all off  [c] commit (3 pending)  [z] revert
```

Use a left **sidebar** for the six sections (Management / Performance), matching Workbench's `register_page` sections.

---

## 15. Suggested Libraries

- **TUI framework**: `php-tui/php-tui` (Rust-ratatui-inspired; widgets, canvas, events) — best fit; or roll a minimal ANSI renderer over `ext-ncurses` (often unavailable) — prefer pure-PHP.
- **DB**: PDO_mysql or mysqli (PDO recommended for portability; enable `MYSQLI_*`/`PDO::ATTR_TIMEOUT`). Use buffered queries for small result sets, unbuffered/streaming for processlist.
- **SSH (optional)**: `phpseclib/phpseclib` v3 (pure PHP, no ext-ssh2 needed) for tunneling and `uptime`/`/proc/stat` exec.
- **Sparklines**: implement braille/block rendering yourself (tiny); php-tui's Canvas can also draw braille.
- **Config**: `symfony/yaml` or plain JSON for settings + ported `sys_reports.json` / `variable_metadata.json`.
- **CLI/loop**: `symfony/console` for entrypoint; a hand-rolled tick loop (`stream_select` on STDIN + timers) for the event loop. Optionally `revolt/event-loop` for clean timers.

---

## 16. Suggested Query Abstraction Layers

Mirror `SQLQueryExecutor`/`exec_query` with safe parameterization:

```php
final class Connection {
    public function __construct(private \PDO $pdo) {}
    /** @return array<int,array<string,mixed>> */
    public function rows(string $sql, array $p = []): array { /* prepare+execute+fetchAll */ }
    public function pairs(string $sql, string $k='Variable_name', string $v='Value'): array { /* assoc */ }
    public function scalar(string $sql, array $p = []): mixed { /* fetchColumn */ }
    public function exec(string $sql, array $p = []): int { /* rowCount */ }
    public function ping(): bool { try { $this->scalar('SELECT 1'); return true; } catch (...) { return false; } }
}
```

Rules learned from the source:
- `SHOW GLOBAL STATUS`/`SHOW VARIABLES` → reduce to associative arrays (`pairs()`).
- **Never interpolate identifiers/values that come from the server into DDL/DML without escaping.** Workbench builds PS-setup statements with `escape_sql_string`/literal interpolation; in PHP prefer prepared statements for values (`KILL`, `SET GLOBAL`, setup_objects rows) and a strict identifier whitelist/`backtick`-escape for table/column names (the `sys.<view>` and `setup_*` names are from a fixed catalog, not user input — keep them constant).
- Keep a dedicated **poller connection** separate from the **interactive** connection so a long report doesn't freeze live metrics (replicate the two-connection design; in a single-process loop, two PDO handles suffice).
- Wrap query errors to detect connection loss (codes 2002/2003/2013) and auto-reconnect, exactly like `handle_sql_disconnection`.

---

## 17. Suggested Caching Strategy

- **Hot cache** (always in memory): latest `StatusSnapshot` (assoc array + ts), `serverVars`, `plugins`, parsed `version`. Refreshed by the 3 s poller; every page reads from here.
- **Per-graph rings**: `RingBuffer` per metric (≈160 samples) — recomputed each tick, never persisted by default.
- **Report results**: not cached (match Workbench) — but consider a short TTL (e.g. 30 s) so re-selecting a report tab is instant; invalidate on explicit Refresh.
- **Reference data**: load `sys_reports.json` and `variable_metadata.json` once at startup.
- **UI prefs**: persist column widths/units, connection refresh rate, custom variable groups to a JSON settings file (mirrors `grt.root.wb.state`).
- **Optional history** (an enhancement Workbench lacks): append snapshots to SQLite/RRD for long-range dashboards.

---

## 18. Suggested Sampling Engine

Replicate the monitor's delta math centrally:

```php
final class Sampler {
    private array $prev = [];                 // metric => [value, ts]
    public function rate(string $key, float $value, float $ts): ?float {
        $r = null;
        if (isset($this->prev[$key])) { [$pv,$pt] = $this->prev[$key];
            if ($ts > $pt) $r = ($value - $pv) / ($ts - $pt); }
        $this->prev[$key] = [$value, $ts]; return $r;
    }
    public function resetAll(): void { $this->prev = []; }  // call on server_started
}
```

- First sample after start/connect returns `null` (skip the point) — prevents the "uptime-sized spike".
- For composite metrics (sum of many `Com_*`), compute the sum first, then `rate()`.
- Reset on detected restart (compare `Uptime` dropping, or on reconnect).
- Match interval semantics: Workbench divides by *actual* elapsed (`ts−oldTs`), not the nominal 3 s — do the same for correctness when ticks jitter. (The Server Status sidebar divides by the nominal interval; prefer the actual-elapsed approach used by the dashboard.)

---

## 19. Suggested Timeseries Engine

```php
final class TimeSeries {                 // one per dataset
    /** @var list<array{0:float,1:float}> [value, ts] */ private array $pts = [];
    public function __construct(private int $windowSeconds = 160) {}
    public function add(float $v, float $ts): void {
        $this->pts[] = [$v, $ts];
        while (count($this->pts) > 1 &&
               $this->pts[count($this->pts)-1][1] - $this->pts[0][1] > $this->windowSeconds)
            array_shift($this->pts);
    }
    public function ceiling(): float {            // Workbench "nice max"
        $max = 0.0; foreach ($this->pts as [$v]) $max = max($max, $v);
        if ($max <= 0) return 100.0;
        $s = (string)(int)$max; $lead = (int)$s[0] + 1;
        $scale = (int)($lead . str_repeat('0', strlen($s) - 1));
        return max($scale, 100);
    }
}
```

- Window in **seconds** (time-based), not sample-count — robust to jitter/pauses, exactly like Workbench's pixel-width trim.
- For braille rendering, map each cell column to a time bucket; interpolate/aggregate points falling in the bucket. Newest at the right edge (right→left fill), matching Workbench.
- Multi-series graphs (SQL statements: select / write / ddl) keep independent series with shared scale = max ceiling across series.

---

## 20. Risks / Complexities

1. **`eval` of expressions on server data** (Workbench uses Python `eval` on `%(Var)s`-formatted strings and `${expr}` in tooltips). **Do not replicate with PHP `eval()`.** Precompile to closures; the expressions are static. Tooltip `${…}` mini-eval is the only place server values feed an expression — port those as fixed PHP computations.
2. **Picoseconds vs nanoseconds vs 1024 vs 1000.** `sys.x$` latency is picoseconds (formatters /1e6,/1e9,/1e12); dashboard `scale_value` is 1024-based; sidebar traffic label is 1024-based; report byte formatters are 1000-based. Mixing these silently produces wrong numbers — keep two explicit scalers.
3. **Version drift of `sys` views & PS tables.** `setup_timers` removed in 8.0; `setup_objects.ENABLED` added 5.6.3; report views appear/disappear. Always feature-detect (the `SHOW FULL TABLES FROM sys` + table-exists try/except pattern).
4. **Threading → event loop.** PHP is single-process; emulate the poller/UI split cooperatively. Long reports must run without freezing the loop — use a short statement timeout and/or run them between ticks; show a "running…" state.
5. **Connection loss mid-poll.** Replicate reconnect logic (error codes 2002/2003/2013) so the dashboard survives restarts.
6. **Privilege failures** must degrade to friendly screens (Workbench's validation framework) rather than crashing a page.
7. **Large `PROCESSLIST_INFO`** can be huge — truncate (Workbench uses `SUBSTR(...,255)`/stores full in a tag).
8. **MariaDB divergences** (see §23) — PS/sys layout differs; `SHOW SLAVE STATUS` → `SHOW REPLICA STATUS` in 8.0/MariaDB differences.

---

## 21. Features Worth Simplifying

- **Dashboard layout**: drop absolute 1024×700 pixel coordinates → responsive 3-panel grid.
- **Charts**: arcs/level meters → bars/percentages; polylines → braille sparklines.
- **Visual EXPLAIN** → text `EXPLAIN FORMAT=JSON` / `EXPLAIN ANALYZE`.
- **Host CPU via WMI/VBScript upload (Windows)** → drop; use SSH `uptime`/`/proc/stat` or omit when no remote channel.
- **sys-schema auto-installer** → out of scope; just detect & instruct (or run the bundled script only if explicitly requested).
- **Per-column unit menus & width persistence** → keep unit toggle, simplify persistence.
- **Custom variable groups UI** → optional; the built-in category grouping is the valuable part.
- **Thread-stack JSON tree** → nice-to-have; can ship later.

## 22. Features Worth Expanding

- **Persistent history** (SQLite/RRD) → multi-hour dashboards, something Workbench lacks.
- **Alerting**: turn `set_thresholds` into actual notifications (e.g. connections > 90% of max).
- **Diff mode**: snapshot `SHOW GLOBAL STATUS` and show deltas between two points (great for benchmarking).
- **Report scheduling / export to file** on a cron.
- **Flavor-aware metrics**: surface MariaDB/Percona-specific status vars when detected.
- **Headless/JSON output mode** for piping into other tools (the TUI as a metrics source).

---

## 23. MariaDB / Percona Compatibility Notes

The source is **MySQL-oriented** and uses `is_supported_mysql_version_at_least` gates that assume Oracle MySQL version semantics. For a portable tool:

- **Flavor detection**: parse `@@version` / `@@version_comment` (`MariaDB`, `Percona`). MariaDB reports versions like `10.x`/`11.x` and `5.5.5-10.x` legacy prefixes — your `Version` parser must handle the `5.5.5-` MariaDB prefix and treat MariaDB ≥10 as feature-rich.
- **Dashboard**: works everywhere — all the `Bytes_*`, `Com_*`, `Innodb_*`, `Threads_*` status vars exist on MySQL/MariaDB/Percona. A few `Com_*` differ (MariaDB lacks some 8.0 role counters; guard the additive expressions so missing keys default to 0 — the calc already coerces missing→0).
- **Performance Reports**: MariaDB **does not ship the Oracle `sys` schema** by default (it has its own perfschema coverage and lacks many `sys.x$` views). Reports should feature-detect `sys` and **fall back to direct `performance_schema` aggregation queries** or hide unavailable reports. Percona is MySQL-compatible here.
- **PS Setup**: MariaDB's `performance_schema.setup_*` exist but instruments/consumers names and availability differ; `setup_timers`/`performance_timers` semantics differ; treat the tree generically (it's already name-driven).
- **Processlist**: `performance_schema.threads` columns differ slightly on MariaDB; the `SHOW FULL PROCESSLIST` fallback is the safe cross-flavor path.
- **Replica**: MySQL 8.0 prefers `SHOW REPLICA STATUS`; MariaDB uses `SHOW SLAVE STATUS` (and multi-source variants). Detect and choose.
- **Percona**: adds extra status vars (e.g. userstat, extra InnoDB metrics) — opportunity to expand (see §22).

Recommendation: a `Flavor` enum {MySQL, MariaDB, Percona} + capability flags resolved at connect time; gate reports/PS-extras on actual table/view presence rather than version math.

---

## 24. Security / Privilege Considerations

| Operation | Privilege needed |
|---|---|
| `SHOW GLOBAL STATUS`, `SHOW VARIABLES`, `SELECT 1` | none beyond `USAGE` |
| `SHOW FULL PROCESSLIST` / full `performance_schema.threads` | `PROCESS` (else you see only your own threads) |
| `SHOW SLAVE/REPLICA STATUS` | `REPLICATION CLIENT` / `REPLICATION_SLAVE_ADMIN` (error 1227 otherwise) |
| `SET GLOBAL <var>` | `SYSTEM_VARIABLES_ADMIN` (8.0) or `SUPER` |
| `SET PERSIST` / `RESET PERSIST` | `SYSTEM_VARIABLES_ADMIN`/`PERSIST_RO_VARIABLES_ADMIN` (the code shows the exact "SUPER or SYSTEM_VARIABLES_ADMIN" error) |
| `SET @@GLOBAL.GTID_MODE` | `SUPER`/`SYSTEM_VARIABLES_ADMIN` |
| `KILL CONNECTION/QUERY` (others' threads) | `PROCESS` + `CONNECTION_ADMIN`/`SUPER` |
| `SELECT` from `performance_schema.*`, `sys.*` | `SELECT` on those schemas |
| `UPDATE/INSERT/DELETE` on `performance_schema.setup_*`, `threads.INSTRUMENTED` | privileged (effectively admin; historically `SUPER`, modern: appropriate dynamic privileges) |
| Install `sys` schema | `SELECT, INSERT, CREATE, DROP, ALTER, SUPER, CREATE VIEW, CREATE ROUTINE, ALTER ROUTINE, TRIGGER` (per `get_missing_grants`) |

Design guidance for the PHP tool:
- **Probe-then-degrade**: like Workbench, catch error 1142/1227/1146 and show targeted guidance instead of failing hard. Disable write actions when the account lacks privileges.
- **Least privilege by default**: the Dashboard + basic Server Status + Variables (read) + own-thread processlist all work on a near-`USAGE` account. Document a recommended monitoring grant: `SELECT, PROCESS, REPLICATION CLIENT` (+ `SELECT` on `performance_schema`/`sys` for reports).
- **Credential handling**: never log passwords (Workbench has `strip_password`); read from env/secret store; support SSH key auth via phpseclib; never echo DSNs with passwords to the TUI.
- **SQL safety**: prepared statements for all value interpolation (KILL ids, SET values, setup_objects rows); fixed whitelists for schema/view/column identifiers (they come from a static catalog, not user input). The original interpolates literals — your port should tighten this.
- **TLS**: support `PDO::MYSQL_ATTR_SSL_*` so monitoring traffic can be encrypted.

---

## 25. Incremental Implementation Roadmap

**Phase 0 — Foundations (1–2 wk).** PDO connection + optional phpseclib SSH tunnel; `ServerContext` + `StatusPoller` (3 s `SHOW GLOBAL STATUS`); `Version`/flavor detection; event loop (`stream_select` + timers); minimal TUI shell with a 6-item sidebar and page lifecycle (`validate→build→refresh`).

**Phase 1 — Dashboard (1–2 wk).** Calc engine (Raw/Rate/TupleRate/MakeTuple); `TimeSeries` + braille sparkline; port the widget table (Network/MySQL/InnoDB, pre/post-8.0 variants); counters/round/level renderers; 3 s refresh from cache. *Delivers the flagship view on a low-priv account.*

**Phase 2 — Server Status + Variables (1–2 wk).** Info card (Uptime→running-since), feature/dir/SSL/replica panels with `tristate`; sidebar gauges with threshold colors; Variables dual-tab with ported `variable_metadata.json`, search, grouping, `[rw]` edit, 8.0 persistence.

**Phase 3 — Client Connections (1 wk).** PS-threads path + `SHOW FULL PROCESSLIST` fallback; counters strip; filters; kill/kill-query; details/attributes/MDL tabs; refresh-rate selector.

**Phase 4 — Performance Reports (1 wk).** Port `sys_reports.json`; `SHOW FULL TABLES FROM sys` discovery; report runner + unit formatters; sortable/exportable table; unit toggle. Feature-detect `sys`; MariaDB fallback stubs.

**Phase 5 — Performance Schema Setup (1.5–2 wk).** Six `setup_*` models with change tracking; instrument tree + tri-state; minimal-diff commit (RLIKE/IN/keyed-UPDATE); Easy-Setup detection + toggles; privilege-aware read-only mode.

**Phase 6 — Polish & expansions.** Reconnect handling, alerting on thresholds, optional SQLite history, diff/benchmark mode, JSON output, MariaDB/Percona-specific metrics.

Each phase is independently shippable; Phases 0–2 already reproduce the most-used Workbench experience on a minimal-privilege account.

---

## Appendix A — Dashboard widget table (exact expressions)

Status vars consumed (all from `SHOW GLOBAL STATUS` unless noted; `*` denotes `SHOW VARIABLES`):

**Network panel** (`GLOBAL_DASHBOARD_WIDGETS_NETWORK`)
- Incoming traffic (timeline + counter): `CSingleDifferencePerSecond("%(Bytes_received)s")`
- Outgoing traffic (timeline + counter): `CSingleDifferencePerSecond("%(Bytes_sent)s")`
- Client connections (timeline): `CRawValue("%(Threads_connected)s")`
- Connections level meter: value `CRawValue("%(Threads_connected)s")`, max `CRawValue("%(max_connections)s")`*  (init)
- Tooltip vars: `Threads_running, Connections, Connection_errors_{accept,internal,max_connections,peer_address,select,tcpwrap}`

**MySQL panel** (PRE_80 / POST_80)
- Table Open Cache efficiency (round meter): `CRawValue("%(Table_open_cache_hits)s/(%(Table_open_cache_hits)s+%(Table_open_cache_misses)s+0.0)")`
- SQL statements executed (3-series timeline): `CTupleDifferencePerSecond("(%(Com_select)s, <writes>, <ddl>)")` where
  - writes = `Com_insert+Com_update+Com_delete`
  - ddl = sum of all `Com_create_*`,`Com_alter_*`,`Com_drop_*` (POST_80 adds `Com_create_role/Com_drop_role/Com_alter_user_default_role`, removes `Com_alter_db_upgrade`)
- SELECT/INSERT/UPDATE/DELETE counters: `CSingleDifferencePerSecond("%(Com_select)s")` etc.
- CREATE/ALTER/DROP counters: `CSingleDifferencePerSecond("<sum of respective Com_* family>")`

**InnoDB panel** (`GLOBAL_DASHBOARD_WIDGETS_INNODB`)
- Buffer pool read reqs (counter): `CSingleDifferencePerSecond("%(Innodb_buffer_pool_read_requests)s")`
- Buffer pool write reqs (counter): `CSingleDifferencePerSecond("%(Innodb_buffer_pool_write_requests)s")`
- Buffer pool usage (round meter): `CRawValue("(%(Innodb_buffer_pool_bytes_data)s/%(Innodb_page_size)s)/(%(Innodb_buffer_pool_pages_total)s+0.0)")`  (`Innodb_page_size` is a status var here)
- Disk reads not from pool (counter): `CSingleDifferencePerSecond("%(Innodb_buffer_pool_reads)s")`
- Redo log bytes written (counter): `CSingleDifferencePerSecond("%(Innodb_os_log_written)s")`
- Redo log writes (counter): `CSingleDifferencePerSecond("%(Innodb_log_writes)s")`
- Doublewrite writes (counter): `CSingleDifferencePerSecond("%(Innodb_dblwr_writes)s")`
- InnoDB disk writes (timeline+counter): `CSingleDifferencePerSecond("%(Innodb_data_written)s")`
- InnoDB disk reads (timeline+counter): `CSingleDifferencePerSecond("%(Innodb_data_read)s")`

Colors: read = `(60,178,191)`, write = `(253,138,39)`, connections green = `(124,193,80)`; statement series = yellow/blue/purple.

## Appendix B — Performance Reports catalog (category → caption → sys view)

**High Cost SQL (`problems`)**: Statement Analysis → `x$statement_analysis`; Statements in Highest 5% by Runtime → `x$statements_with_runtimes_in_95th_percentile`; Using Temp Tables → `statements_with_temp_tables`; With Sorting → `statements_with_sorting`; Full Table Scans → `statements_with_full_table_scans`; Errors or Warnings → `statements_with_errors_or_warnings`.

**Database Schema Statistics (`schema`)**: Schema Object Overview (High Overhead) → `schema_object_overview`; Schema Index Statistics → `x$schema_index_statistics`; Schema Table Statistics → `x$schema_table_statistics`; Schema Table Statistics (with InnoDB buffer) → `x$schema_table_statistics_with_buffer`; Tables with Full Table Scans → `schema_tables_with_full_table_scans`; Unused Indexes → `schema_unused_indexes`.

**Hot Spots for I/O (`io`)**: Top File I/O Activity → `x$io_global_by_file_by_bytes`; Top I/O by File by Time → `x$io_global_by_file_by_latency`; Top I/O by Event Category → `x$io_global_by_wait_by_bytes`; Top I/O in Time by Event Categories → `x$io_global_by_wait_by_latency`; Top I/O Time by User/Thread → `x$io_by_thread_by_latency`.

**Wait Event Times (Expert) (`wait`)**: Global Waits by Time → `x$waits_global_by_latency`; Waits by User by Time → `x$waits_by_user_by_latency`; Wait Classes by Time → `x$wait_classes_global_by_latency`; Waits Classes by Average Time → `x$wait_classes_global_by_avg_latency`.

**InnoDB Statistics (`innodb`)**: InnoDB Buffer Stats by Schema → `x$innodb_buffer_stats_by_schema`; InnoDB Buffer Stats by Table → `x$innodb_buffer_stats_by_table`.

**User Resource Use (`user_resource_use`)**: Overview → `x$user_summary`; I/O Statistics → `x$user_summary_by_file_io_type`; Statement Statistics → `x$user_summary_by_statement_type`.

**Memory Usage (`memory`)**: Total Memory → `x$memory_global_total`; Top Memory by Event → `x$memory_global_by_current_bytes`; Top Memory by User/Host/Thread → `x$memory_by_{user,host,thread}_by_current_bytes`.

(Column lists, types, and default widths are in `res/scripts/sys/sys_reports.js`; types map: Integer→int, LongInteger→bigint, Float, Time→picoseconds w/ unit, Bytes, String, StringLT.)

## Appendix C — Default PS instrument/consumer sets

```
DEFAULT_INSTRUMENTS_57 = ['wait/io/file/%', 'wait/io/table/%',
                          'wait/lock/table/sql/handler', 'statement/%', 'idle']
DEFAULT_INSTRUMENTS_56 = ['wait/io/file/%', 'wait/io/table/%', 'statement/%',
                          'wait/lock/table/sql/handler', 'idle']
DEFAULT_CONSUMERS_57   = ['events_statements_current', 'events_transactions_current',
                          'global_instrumentation', 'thread_instrumentation', 'statements_digest']
DEFAULT_CONSUMERS_56   = ['events_statements_current', 'global_instrumentation',
                          'thread_instrumentation', 'statements_digest']
```
`memory/%` instruments are always excluded from enable/disable accounting because they cannot be disabled.

---

### Provenance / line references (for verification)
- Status poll loop & caches: `plugins/wb.admin/backend/wb_admin_control.py:215,556-587,692-738`
- Calc engine & widget tables: `plugins/wb.admin/frontend/wb_admin_performance_dashboard.py:209-293,299-650,679-776`
- Chart figures & auto-scale: `library/python/workbench/graphics/charting.py:34-50,53-256,259-482`
- Reports runner & JSON: `plugins/wb.admin/frontend/wb_admin_perfschema_reports.py:38-65,178-182,333-371,465-551` + `res/scripts/sys/sys_reports.js`
- PS setup models & commit SQL: `plugins/wb.admin/backend/wb_admin_perfschema_instrumentation_be.py:173-233,314-350,398-467,492-549,631-698,700-796`
- Easy Setup detection/toggle + defaults: `plugins/wb.admin/frontend/wb_admin_perfschema_instrumentation.py:40-62,149-270,1378-1440`
- Server Status & gauges: `plugins/wb.admin/frontend/wb_admin_server_status.py:128-173,250-461` + `wb_admin_monitor.py:28-345`
- Client Connections: `plugins/wb.admin/frontend/wb_admin_connections.py:286-355,603-630,689-706,841-892,1107-1151`
- Variables: `plugins/wb.admin/frontend/wb_admin_variables.py:49-51,156-167,218-248,488-498,735-768` + `wb_admin_variable_list.py`
- Lifecycle/registration: `plugins/wb.admin/frontend/wb_admin_main.py:53-104,109-279` + `wb_admin_grt.py:348-360` + `wb_admin_utils.py:165-359`
