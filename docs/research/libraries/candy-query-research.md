# candy-query Research: SQLite Browser TUIs

**Date:** 2026-05-13
**Upstream:** jorgerojas26/lazysql (3,672 stars, Go/tview)
**Context:** PHP 8.3+ SugarCraft monorepo, Charmbracelet-inspired TUI patterns

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

### 1.3 PHP Implementation (candy-query)

| Feature | Current State |
|---------|---------------|
| Schema browsing | Tables list only, no tree view |
| Query execution | Ctrl+Enter runs SQL |
| Result rendering | Fixed-width 12-char columns, 100 row limit |
| Data editing | **Not implemented** |
| Pagination | **Not implemented** |
| History | Up/Down arrow cycling |
| Favorites | Ctrl+F / Ctrl+Shift+F |

---

## 2. Feature Comparison Matrix

### 2.1 Schema Browsing

| Feature | lazysql | termdbms | tursotui | candy-query |
|---------|---------|----------|----------|-------------|
| Tree view (tables/views/indexes) | ✓ | Limited | ✓ | ✗ |
| Column types | ✓ | ✓ | ✓ | ✗ |
| FK relationships | ✓ | ✗ | ✓ | ✗ |
| Row counts | ✓ | ✗ | ✓ | ✗ |
| DDL viewing | ✓ | ✗ | ✓ | ✗ |
| Search/filter | ✓ | ✓ | ✓ | ✗ |

**candy-query gap:** No schema tree, no column metadata, no relationships.

**Specific improvement:** Add `PRAGMA table_info` and `PRAGMA foreign_key_list` queries to show column types and FKs in the rows pane header. Implement tree navigation for tables/views/indexes/triggers.

### 2.2 Query Execution

| Feature | lazysql | termdbms | tursotui | candy-query |
|---------|---------|----------|----------|-------------|
| SQL editor | ✓ | ✓ | ✓ | ✓ |
| Ctrl+Enter execute | ✓ | ✗ | ✓ | ✓ |
| External editor | ✓ | ✗ | ✗ | ✗ |
| Parameter binding | ✓ | ✗ | ✓ | ✗ |
| Explain query | ✗ | ✗ | ✓ | ✗ |
| Query history | ✓ | ✓ | ✓ | ✓ |
| Saved queries/snippets | ✓ | ✓ | ✓ | ✗ |

**candy-query gap:** No saved queries/snippets. No explain plan.

**Specific improvement:** Add snippet/favorites to query history. Implement `EXPLAIN QUERY PLAN` display.

### 2.3 Result Rendering

| Feature | lazysql | termdbms | tursotui | candy-query |
|---------|---------|----------|----------|-------------|
| Virtual scrolling | ✓ | Limited | ✓ | ✗ |
| Horizontal scroll | ✓ | ✓ | ✓ | ✗ |
| JSON pretty-print | ✓ | ✓ | ✓ | ✗ |
| NULL visualization | ✓ | ✓ | ✓ | ✗ |
| Type-aware display | ✓ | ✗ | ✓ | ✗ |
| Copy cell | ✓ | ✓ | ✓ | ✗ |
| Pagination | ✓ | ✓ | ✓ | ✗ |

**candy-query gap:** Fixed 100-row limit, no pagination, no horizontal scroll, no NULL/JSON formatting.

**Specific improvement:** Implement OFFSET/LIMIT pagination with page navigation. Use `is_null` check and `json_decode` for pretty-printing.

### 2.4 Data Editing

| Feature | lazysql | termdbms | tursotui | candy-query |
|---------|---------|----------|----------|-------------|
| Cell edit | ✓ | ✓ | ✓ | ✗ |
| Row insert | ✓ | ✓ | ✓ | ✗ |
| Row delete | ✓ | ✓ | ✓ | ✗ |
| Undo/redo | ✗ | ✓ | ✓ | ✗ |
| CSV import | ✓ | ✓ | ✗ | ✓ |
| CSV export | ✓ | ✓ | ✓ | ✓ |

**candy-query gap:** No data editing at all. CSV import/export exists in Database class but not wired to UI.

**Specific improvement:** Add cell-level editing mode. Wire CSV import/export to keyboard shortcuts.

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

### 3.4 candy-query (PHP/Charmbracelet ports)

```php
// Current: BubbleTea-inspired Model
final class App implements Model
{
    public function __construct(
        public readonly Database $db,
        public readonly array $tables = [],
        public readonly int $tableCursor = 0,
        public readonly ?string $selectedTable = null,
        public readonly array $rows = [],
        // ...
    ) {}

    public function update(Msg $msg): array { /* handle keys */ }
    public function view(): string { return Renderer::render($this); }
}
```

**Takeaway:** Immutable/fluent pattern. Pure renderer. Simple enum for pane state.

---

## 4. Prioritized Recommendations

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
1. Persist `queryFavorites` to JSON file in `~/.config/candy-query/`
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
| `composer.json` | Add `sugarcraft/candy-core` dep if not present |

---

## 8. References

- **LazySQL:** https://github.com/jorgerojas26/lazysql (upstream)
- **termdbms:** https://github.com/mathaou/termdbms
- **tursotui:** https://github.com/mikeleppane/tursotui
- **sqv:** https://github.com/mendrik-private/sqv
- **BubbleTea vs Ratatui:** https://www.glukhov.org/developer-tools/comparisons/tui-frameworks-bubbletea-go-vs-ratatui-rust/
- **SQLite PRAGMAs:** https://www.sqlite.org/pragma.html
