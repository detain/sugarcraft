# candy-sql Research: SQLite Browser TUIs

**Date:** 2026-05-13 · **Refreshed:** 2026-11-14 (SQL-O22)
**Upstream:** jorgerojas26/lazysql (3,672 stars, Go/tview) — see staleness note below
**Context:** PHP 8.3+ SugarCraft monorepo, Charmbracelet-inspired TUI patterns

> **STALENESS BANNER (SQL-O22 / once-029):** the star counts in §1 are a
> frozen 2026-05 landscape — do NOT treat them as current; a 2026-11 re-
> survey is a research task, not recorded here. Everything about CANDY-SQL
> itself below was re-derived from the code on 2026-11-14, and future
> reviews should re-derive from `README.md` + `src/` directly — this file
> is a design antecedent, not the state authority (once-031). The file also
> moved: candy-sql is an APP, so it now lives under `docs/research/apps/`
> with its INDEX row following (it previously sat under `libraries/`).

---

## 1. Competitive Landscape

### 1.1 Go Implementations

| Tool | Stars | Framework | SQLite | Key Strength |
|------|-------|-----------|--------|--------------|
| **lazysql** | 3,672 | tview | ✓ | Multi-RDBMS, tabs, connections manager |
| **termdbms** | 1,814 | BubbleTea | ✓ | Undo/redo, vim editing, CSV export |
| **teaqlite** | 9 | BubbleTea | ✓ | Minimal, pagination, cell editing |
| **asql** | 0 | BubbleTea | ✓ | Side-by-side compare, AI assistant |

### 1.2 Rust Implementations

| Tool | Stars | Framework | SQLite | Key Strength |
|------|-------|-----------|--------|--------------|
| **tursotui** | 5 | ratatui | ✓ (Turso) | Fuzzy search, async, multi-tab |
| **sqv** | 146 | ratatui | ✓ | Fast virtual scrolling, rich cell editors |
| **gobang** | 3,267 | ratatui | ✓ | Multi-DB (MySQL, PG, SQLite) |
| **dbiewlite** | — | ratatui | ✓ | WASM, DuckDB, parquet |

### 1.3 PHP Implementation (candy-sql) — re-derived 2026-11-14 from `src/`

| Feature | Current State |
|---------|---------------|
| Schema browsing | `SchemaBrowser` sidebar + per-flavor `Schema\{Mysql,Postgres,Sqlite}SchemaProvider` exposing columns, indexes and FK relationships (`SchemaColumn`/`SchemaIndex`/`SchemaForeignKey`) |
| Query execution | `Ctrl+R`/`Ctrl+E`/`Ctrl+Enter` (aliases share one arm), `Enter` stays a newline in the editor |
| Parameter binding | `?` / `:name` prompts on the run path, driver-bound via `prepare`/`execute` (SQL-O23); bound runs skip the EXPLAIN route |
| Result rendering | auto-sized columns, horizontal scroll with a cell cursor, JSON pretty-print, styled NULL token; `ResultTable::rows(limit, offset)` is a paging API (table preview pages 100-row windows via PgDn/PgUp, SQL-O11); the QUERY fetch stays capped at 100 rows (disclosed limitation) |
| Data editing | cell edit (`e`), row insert (`o`), row delete (`D`) — confirm-then-write DML spine (`DmlBuilder`/`DmlRunner`, transactional, row-count verified) |
| Transactions | `:tx` / `:tx commit` / `:tx rollback` colon commands on the sync session with a `[TX]` status badge (SQL-O12) |
| History | ↑/↓ editor recall; PLUS a recorded status-history store with an in-app `H` trend reader (SQL-O13) |
| Favorites | Ctrl+F / Ctrl+Shift+F, session state (no cross-restart persistence — disclosed) |
| Async execution | ReactPHP transports with per-dispatch cancellation (`CancellableQuery`) and `Esc` cancel-in-flight (SQL-O08) |
| Clipboard | `y` yanks the cell under the cursor via OSC 52 with a plain-text fallback for incapable TERMs (SQL-O20) |
| Key help | `?` overlay generated from one machine-readable `App\KeyMap` roster, cross-checked against the README tables cell-for-cell (SQL-O21) |
| i18n | 16 locales; locale-parity and no-raw-literals guards in CI |

---

## 2. Feature Comparison Matrix

_Candy-sql columns re-derived from the code on 2026-11-14 (SQL-O22); the
competitor columns remain the 2026-05 snapshot._

### 2.1 Schema Browsing

| Feature | lazysql | termdbms | tursotui | candy-sql |
|---------|---------|----------|----------|-------------|
| Tree view (tables/views/indexes) | ✓ | Limited | ✓ | ✓ (`SchemaBrowser` + per-flavor providers) |
| Column types | ✓ | ✓ | ✓ | ✓ (`SchemaColumn`) |
| FK relationships | ✓ | ✗ | ✓ | ✓ (`SchemaForeignKey`, per-flavor providers) |
| Row counts | ✓ | ✗ | ✓ | ✗ (no total-count display; windowed preview only) |
| DDL viewing | ✓ | ✗ | ✓ | ✗ (scheduled: SQL-P row, not yet built) |
| Search/filter | ✓ | ✓ | ✓ | ✓ (`/` filter line over table names, SQL-O10 era) |

**Status (2026-11):** schema tree, column/index/FK metadata and the filter line
shipped; DDL viewing and a total-row-count display remain open gaps.

### 2.2 Query Execution

| Feature | lazysql | termdbms | tursotui | candy-sql |
|---------|---------|----------|----------|-------------|
| SQL editor | ✓ | ✓ | ✓ | ✓ |
| Ctrl+Enter execute | ✓ | ✗ | ✓ | ✓ (plus Ctrl+R/Ctrl+E aliases) |
| External editor | ✓ | ✗ | ✗ | ✗ |
| Parameter binding | ✓ | ✗ | ✓ | ✓ (`?`/`:name` prompt → bound `prepare`/`execute`, SQL-O23) |
| Explain query | ✗ | ✗ | ✓ | ✓ (per-flavor `Explain\*Provider` trio, SQL-O09) |
| Query history | ✓ | ✓ | ✓ | ✓ (editor recall + recorded status-history `H` trend, SQL-O13) |
| Saved queries/snippets | ✓ | ✓ | ✓ | ✓ session-scoped (favorites; no persistence — disclosed) |

**Status (2026-11):** every Priority-2/4/5 recommendation from §4 has shipped;
external-editor handoff remains the one open row.

### 2.3 Result Rendering

| Feature | lazysql | termdbms | tursotui | candy-sql |
|---------|---------|----------|----------|-------------|
| Virtual scrolling | ✓ | Limited | ✓ | ✓ (viewport windowing in `ResultTable`) |
| Horizontal scroll | ✓ | ✓ | ✓ | ✓ (cell cursor with scroll-follow) |
| JSON pretty-print | ✓ | ✓ | ✓ | ✓ |
| NULL visualization | ✓ | ✓ | ✓ | ✓ (styled NULL token) |
| Type-aware display | ✓ | ✗ | ✓ | ✓ |
| Copy cell | ✓ | ✓ | ✓ | ✓ (`y` → OSC 52 + plain fallback, SQL-O20) |
| Pagination | ✓ | ✓ | ✓ | ✓ (100-row windows, PgDn/PgUp; QUERY fetch itself stays 100-capped — disclosed) |

### 2.4 Data Editing

| Feature | lazysql | termdbms | tursotui | candy-sql |
|---------|---------|----------|----------|-------------|
| Cell edit | ✓ | ✓ | ✓ | ✓ (`e`, confirm-then-write `DmlRunner`) |
| Row insert | ✓ | ✓ | ✓ | ✓ (`o` column stepper) |
| Row delete | ✓ | ✓ | ✓ | ✓ (`D`, DELETE preview + confirm) |
| Undo/redo | ✗ | ✓ | ✓ | ✗ (staged commits on PerfSchema ≠ editor undo) |
| CSV import | ✓ | ✓ | ✗ | ✓ (`i` file line, SQL-O07) |
| CSV export | ✓ | ✓ | ✓ | ✓ (`x`/`Ctrl+S`, full-table streaming with COUNT verify) |

**Status (2026-11):** the DML spine is live and transactional; a cross-edit
undo/redo stack is the remaining gap and is NOT scheduled.

### 2.5 Where candy-sql now EXCEEDS lazysql (once-031 process ledger)

Recorded so future reviews stop calling these "drift" — they are deliberate
divergences from the 2026-05 baseline:

- **Destructive-arm model:** `KILL`-style actions (`K`/`X` → `y`, `D` two-press,
  `:tx rollback` re-arm) require an explicit second intent; any other key
  cancels. lazysql fires writes on first press.
- **CancellableQuery machinery:** async transports carry per-dispatch
  cancellation tokens; `Esc` cancels an in-flight read (SQL-O08) — lazysql
  has no cancel.
- **CommitPlanner / ChangeTracker:** the Performance Schema page stages a
  reviewable multi-change commit (show, then `c`/`y` to fire) instead of
  writing on click.
- **Colon transaction control** (`:tx …`) with a `[TX]` badge on a sync session.
- **Generated key help:** the `?` overlay renders from `App\KeyMap`, which is
  cross-checked against the README tables — a roster that cannot lie.
- **16-locale i18n** with CI parity guards; upstream is English-only.
- **Admin dashboard suite:** eight pages, alert dedupe, GTID editor, TLS gate,
  reconnect circuit breaker — beyond the lazysql feature set entirely.

---

## 3. Architectural Patterns

### 3.1 LazySQL (Go/tview)

```go
// Connection manager with TOML config
type Database struct {
    conn *pgx.Conn
    config ConnectionConfig
}

// Multi-tab interface with tview
type View struct {
    tabs     *tview.TabView
    tree     *tview.TreeView
    table    *tview.Table
    editor   *tview.TextView
}

// Query execution with error handling
func (v *View) ExecuteQuery(query string) error {
    rows, err := v.db.conn.Query(ctx, query)
    // handle columns, types, pagination
}
```

**Takeaway:** Connection abstraction allows multi-RDBMS. Tab-based UI separates concerns.

### 3.2 termdbms (Go/BubbleTea)

```go
// BubbleTea model pattern
type Model struct {
    db       *sqlite.DB
    tables   []Table
    selected Table
    viewMode ViewMode // table|query|format
    cursor   Cursor
    undoStack []Action
}

func (m Model) Update(msg Msg) (Model, Cmd) { /* ... */ }

func (m Model) View() string {
    return tea.JoinHorizontal( /* layout */ )
}
```

**Takeaway:** Undo/redo via action stack. View modes for table/query/format. Border styling with Lip Gloss.

### 3.3 tursotui (Rust/ratatui)

```rust
// Ratatui immediate mode
loop {
    terminal.draw(|f| {
        let chunks = Layout::default()
            .direction(Horizontal)
            .constraints([20, 0, 40].as_ref())
            .split(f.size());
        // render schema, editor, results
    })?;
    match event::read()? {
        Event::Key(key) => state.handle_key(key),
        _ => {}
    }
}
```

**Takeaway:** Async/await via Tokio. crossterm for events. Explicit layout chunks. Fuzzy search with ratatui-search.

### 3.4 candy-sql (PHP/Charmbracelet ports)

```php
// Current: BubbleTea-inspired Model, state split into seven readonly groups
final class App implements Model
{
    public function __construct(
        public readonly ConnectionState $connection,
        public readonly BrowseState $browse,
        public readonly QueryState $query,
        public readonly UiState $ui,
        public readonly AdminState $admin,
        public readonly ExportState $io,
        public readonly EditState $edit,
    ) {}

    public function update(Msg $msg): array { /* handle keys */ }
    public function view(): string { return Renderer::render($this); }
}
```

**Takeaway:** Immutable/fluent pattern. Pure renderer. Enum for pane state;
the 2026-05 single-bag constructor grew into seven named state groups so each
prompt/overlay surface owns its own slot.

---

## 4. Prioritized Recommendations

_Historical (2026-05): Priorities 1, 2, 3, 5, 6 and 7 below shipped (see §2
for the per-feature status as re-derived on 2026-11-14); Priority 4 shipped
as session-scoped favorites. Treat §4-§8 as the original plan-of-record, not
a to-do list._

### Priority 1: Schema Browser (High Impact, Medium Effort)

**Why:** Users cannot see column types, indexes, or FK relationships. This is the most visible gap vs competitors.

**Implementation:**

1. Add `Database::columns(string $table): array<array{name,type,notnull,dflt_value,pk}>` using `PRAGMA table_info`
2. Add `Database::indexes(string $table): array` using `PRAGMA index_list`
3. Add `Database::foreignKeys(string $table): array` using `PRAGMA foreign_key_list`
4. Enhance rows pane header to show column types
5. Optionally: Add tree pane for schema hierarchy (tables → columns → indexes)

**Effort:** 2-3 days

---

### Priority 2: Pagination (High Impact, Low Effort)

**Why:** Viewing only 100 rows is severely limiting. Every competitor supports pagination.

**Implementation:**

1. Add `page` and `pageSize` state to `App`
2. Modify `Database::rows()` to accept `OFFSET` and `LIMIT`
3. Add `<` / `>` or `PgUp`/`PgDn` key handling in rows pane
4. Show "page X of Y" in status bar

**Effort:** 1 day

---

### Priority 3: Cell-Level Data Editing (High Impact, High Effort)

**Why:** Read-only browsing is useful, but full CRUD is expected for a database browser.

**Implementation:**

1. Add `editMode`, `editRow`, `editCol` state to `App`
2. Add `Database::update()` and `Database::delete()` methods
3. Enter key or `e` enters edit mode on focused cell
4. Escape cancels, Enter commits
5. Add undo stack (simplified, single-level or full history)

**Effort:** 3-4 days

---

### Priority 4: Saved Queries/Snippets (Medium Impact, Low Effort)

**Why:** Users re-run the same queries. History is not enough without naming.

**Implementation:**

1. Persist `queryFavorites` to JSON file in `~/.config/candy-sql/`
2. Add `S` key to open snippet browser overlay
3. Named snippets with Enter to load

**Effort:** 1-2 days

---

### Priority 5: Query Explain Plan (Medium Impact, Low Effort)

**Why:** Users need to understand query performance.

**Implementation:**

1. Add `Database::explain(string $sql): array`
2. Add `E` key in query pane for explain toggle
3. Display `EXPLAIN QUERY PLAN` results in a formatted overlay

**Effort:** 1 day

---

### Priority 6: Horizontal Scrolling (Medium Impact, Low Effort)

**Why:** Tables with many columns cannot be read.

**Implementation:**

1. Track horizontal scroll offset in rows pane
2. Add `h`/`l` or arrow keys for horizontal navigation
3. Show column position in status: `[3/12]`

**Effort:** 1 day

---

### Priority 7: JSON/Null Formatting (Low Impact, Low Effort)

**Why:** JSON data is unreadable when stored as strings.

**Implementation:**

1. Detect JSON strings with `json_decode` validation
2. Pretty-print JSON in cell display
3. Visual indicator for NULL values (e.g., `NULL` in dim color)

**Effort:** 1 day

---

## 5. Not Recommended for Phase 1

| Feature | Reason |
|---------|--------|
| Multi-database/tabs | Complex state management; out of scope for single-db TUI |
| External editor integration | Unix-only ($EDITOR); adds platform complexity |
| AI assistant | Adds external dependency; not core to database browsing |
| CSV import UI | Already exists in `Database::importCsv()`; needs keyboard binding only |
| Fuzzy object search (`Ctrl+P`) | Nice-to-have; requires additional UI overlay |
| Themes | Nice-to-have; current TokyoNight theme is sufficient |

---

## 6. Implementation Order

```
Week 1:
├── Pagination (1d)
├── Schema columns/types (1d)
├── Horizontal scrolling (1d)
└── JSON/Null formatting (1d)

Week 2:
├── Cell editing (3d)
└── Saved queries (1d)

Week 3:
├── Explain plan (1d)
├── Snippet browser overlay (1d)
└── Polish: undo stack, error handling (2d)
```

**Total estimate:** 11-12 days

---

## 7. Files to Modify

| File | Changes |
|------|---------|
| `src/Database.php` | Add `columns()`, `indexes()`, `foreignKeys()`, `update()`, `explain()` methods |
| `src/App.php` | Add pagination state, edit mode state, horizontal scroll offset |
| `src/Renderer.php` | Render column headers with types, pagination controls, NULL/JSON formatting |
| `src/Pane.php` | Add `Schema` pane enum case |
| `composer.json` | Add `sugarcraft/sugar-core` dep if not present |

---

## 8. References

- **LazySQL:** https://github.com/jorgerojas26/lazysql (upstream)
- **termdbms:** https://github.com/mathaou/termdbms
- **tursotui:** https://github.com/mikeleppane/tursotui
- **sqv:** https://github.com/mendrik-private/sqv
- **BubbleTea vs Ratatui:** https://www.glukhov.org/developer-tools/comparisons/tui-frameworks-bubbletea-go-vs-ratatui-rust/
- **SQLite PRAGMAs:** https://www.sqlite.org/pragma.html
