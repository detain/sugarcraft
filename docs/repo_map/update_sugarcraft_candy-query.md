# Overview
candy-query is a terminal SQLite browser built on the SugarCraft stack (PHP 8.3+, PSR-4, immutable/fluent patterns, `readonly` classes). It ports the core workflow of `jorgerojas26/lazysql` (Go, multi-driver SQL browser) to PHP. At v1, it is SQLite-only with 9 source files, 7 test files with 60+ test methods, 2 VHS demos, and 16 locales. The architecture cleanly separates Database (PDO wrapper), SchemaBrowser (PRAGMA introspection), ResultTable (horizontal-scrolling renderer), App (SugarCraft Model), and Renderer (ANSI view). **Biggest opportunity**: Multi-driver interface, inline row editing TUI, query result sorting, and schema tree sidebar. **Biggest missing**: Pagination controls, tabs for multiple tables, connection management, query snippet picker UI.

---

# Internal Capability Summary

## Current Architecture

```
candy-query/src/
├── App.php              # SugarCraft Model — 3-pane state machine (415 lines)
├── Database.php        # PDO/SQLite wrapper + CSV/SQL export (234 lines)
├── SchemaBrowser.php    # PRAGMA introspection → typed value objects (229 lines)
├── Renderer.php         # Stateless 3-pane ANSI renderer (124 lines)
├── ResultTable.php      # Horizontal-scrolling result table (461 lines)
├── ResultPager.php      # Immutable cursor-based pager (150 lines)
├── CellEditor.php       # Cell-level UPDATE by PK (104 lines)
├── SnippetStore.php     # File-backed JSON snippet store (186 lines)
├── ExplainView.php     # EXPLAIN QUERY PLAN → colour tree (229 lines)
├── Pane.php             # 3-pane focus enum (26 lines)
└── Lang.php             # i18n facade (22 lines)
```

## Current Features
- **3-pane TUI**: Tables list, Rows preview, Query editor — Tab cycles focus
- **SQLite browsing**: `Database::tables()`, `Database::rows()` with LIMIT 100, `Database::query()` for arbitrary SQL
- **Schema introspection**: `SchemaBrowser` exposes `SchemaTable`, `SchemaColumn`, `SchemaIndex`, `SchemaForeignKey` via PRAGMA queries
- **Horizontal scrolling**: `ResultTable::scrollLeft()`/`scrollRight()` with offset-based visible column slice
- **Query history**: In-buffer Up/Down navigation, Ctrl+F/Shift+Ctrl+F favorites
- **CSV import/export + SQL dump**: `Database::exportCsv()`, `exportSql()`, `importCsv()`
- **EXPLAIN QUERY PLAN tree**: `ExplainView` parses SQLite tree prefixes → 6-colour-tagged ANSI tree
- **JSON pretty-print**: Collapses/expands based on visible width
- **16 locales**: i18n via `Lang::t()`
- **2 VHS demos**: Query execution, query history cycling

## Strengths
- Clean immutable/fluent patterns throughout (all `with*()` return new instances)
- SugarCraft `Model` contract (`init()`/`update()`/`view()`) precisely followed
- Comprehensive test coverage via `:memory:` PDO fixtures (60+ methods)
- EXPLAIN QUERY PLAN colour tree not found in upstream Go project
- UTF-8 safe backspace in query editor
- PRAGMA results wrapped in typed value objects catching mis-indexed access at construction

## Weaknesses
- SQLite-only — `Database` is a sealed concrete class with no interface
- No row editing TUI — `CellEditor` exists but not wired into `App`
- No pagination controls in TUI — `ResultPager` exists but no keybindings
- No schema sidebar — tables pane shows flat list, no expandable tree
- No tabs — single table/query active at a time
- No connection management — opens one file at a time
- No query snippet picker UI — `SnippetStore` exists but no TUI integration
- No row filtering (`WHERE`-clause filter) or column sorting
- No CSV import TUI — `importCsv()` exists but not exposed in app
- Query history not persisted across sessions
- No read-only mode to block mutation queries

---

# Relevant External Repositories

| Repo | Relevance | Major Applicable Concepts | Priority |
|------|-----------|-------------------------|----------|
| `jorgerojas26/lazysql` | 🟢 Direct upstream | Multi-driver (SQLite/MySQL/Postgres/MSSQL), tree sidebar, tabs, inline editing, external editor, sorting, filtering, JSON viewer | **Critical** |
| `Evertras/bubble-table` | 🟢 Component reference | Sorting, filtering, pagination, frozen columns, horizontal scroll, style precedence | **High** |
| `charmbracelet/bubbletea` | 🟢 Framework upstream | Elm architecture, command pattern, subscriptions, mouse handling, synchronized output | **High** |
| `charmbracelet/bubbles` | 🟡 Component reference | TextInput (undo/redo, suggestions), TextArea (soft-wrap, line info), List (fuzzy filter), Viewport (scroll, mouse wheel) | **High** |
| `charmbracelet/gum` | 🟡 CLI reference | Filter (fuzzy), choose (grid), spin (PTY), table, pager | **Medium** |
| `ratatui/ratatui` | 🟡 Framework reference | Dirty-flag rendering, layout caching, virtual scrolling, widget trait system, modular workspace | **High** |
| `textualize/textual` | 🟡 Framework reference | Weakref DOM, command palette, pilot testing, CSS selectors, reactive state, scroll viewport | **Medium** |
| `treilik/bubblelister` | 🟡 List component | Cursor by ID not index, visible-range-only rendering, stable sort, prefix/suffix interfaces | **Medium** |
| `rmhubbert/bubbletea-overlay` | 🟢 Overlay reference | Viewable interface, composite rendering, positioning semantics | **Medium** |

---

# Feature Gap Analysis

## Critical Priority

### 1. Multi-Driver Database Interface
- **Title**: Extract `Database` into an interface enabling MySQL/Postgres drivers
- **Description**: `Database` is explicitly designed as "promote to interface the day a second driver lands." Currently sealed concrete SQLite-only.
- **Why it matters**: Caps the product at SQLite-only. Every competing SQL browser supports multiple databases.
- **Source**: lazysql (upstream) — supports Postgres, MySQL, SQLite, MSSQL
- **Implementation idea**: Extract `interface DatabaseInterface { tables(), rows(), query(), importCsv(), exportCsv(), exportSql() }`, keep `SQLiteDatabase` as first impl, create `MySQLDatabase` stub. The `App::start()` factory only needs `DatabaseInterface`.
- **Estimated complexity**: Medium — requires interface + first alternative driver implementation
- **Expected impact**: Unlocks production use cases beyond SQLite

### 2. Row Editing TUI
- **Title**: Wire `CellEditor` into the App with inline cell editing
- **Description**: `CellEditor` exists with `updateCell()`, `updateRow()`, `readCell()` but is not connected to any pane or keybinding.
- **Why it matters**: Users cannot edit data — a database browser without editing is severely limited.
- **Source**: lazysql uses `c` key for cell edit, `o`/`O` for INSERT, `d` for DELETE
- **Implementation idea**: Add `e` key in Rows pane to enter edit mode; show editable fields; `Enter` to confirm, `Esc` to cancel. Need to show primary key column.
- **Estimated complexity**: Medium — requires editable state in App + key routing
- **Expected impact**: Core workflow capability

### 3. Pagination Controls in TUI
- **Title**: Connect `ResultPager` to keyboard controls (`<`/`>` or `PageUp`/`PageDown`)
- **Description**: `ResultPager` exists with `nextPage()`/`prevPage()`/`goToPage()` but no TUI controls. `Database::rows()` has `LIMIT 100` hardcoded.
- **Why it matters**: Tables with >100 rows cannot be fully browsed. Only first 13 rows shown in render.
- **Source**: bubble-table has `WithPageSize()`, prev/next navigation; lazysql supports up to 300 rows per page
- **Implementation idea**: Add `,`/`.` keys for prev/next page in Rows pane; show "page N/M" in status; ResultPager already immutable and works
- **Estimated complexity**: Low — only needs key routing in App::handleRowsKey
- **Expected impact**: Browsing large tables

## High Value Priority

### 4. Schema Sidebar Tree
- **Title**: Replace flat table list with expandable tree showing columns, indexes, FKs
- **Description**: Tables pane shows alphabetically sorted flat list. `SchemaBrowser` already exposes full schema but it's not rendered.
- **Why it matters**: Users need to see column types, primary keys, foreign keys to write queries. Every competitor has this.
- **Source**: lazysql sidebar with collapsible tree; bubble-table with expandable rows; ratatui tree widget
- **Implementation idea**: Add `SchemaBrowser` instance to App state; on `Enter` on table expand to show columns; show type + PK/FK annotations inline
- **Estimated complexity**: High — requires reworking the tables pane UI, tree expand/collapse state
- **Expected impact**: Core usability feature

### 5. Column Sorting
- **Title**: Add asc/desc sort keybindings on column headers or `K`/`J` keys
- **Description**: No sorting capability — results appear in database order.
- **Why it matters**: Users browsing data need sorted views. Essential feature in every table component.
- **Source**: bubble-table `SortByAsc()`/`SortByDesc()`; lazysql `K`/`J` for asc/desc; Evertras/bubble-table multi-column stable sort
- **Implementation idea**: Add sort state to App (`sortColumn`, `sortDirection`); on `K`/`J` toggle asc/desc on current column; re-run query with `ORDER BY`
- **Estimated complexity**: Low-Medium — needs query modification, sort state in App
- **Expected impact**: High usability improvement

### 6. Row Filtering / WHERE Clause
- **Title**: Add `/` key to open WHERE-clause filter input
- **Description**: No filtering — users must write SQL to filter results.
- **Why it matters**: Browsing a 100k-row table without filtering is impractical. Standard TUI table feature.
- **Source**: bubble-table built-in text input with `/` trigger, case-insensitive contains filter; lazysql `/` opens WHERE input
- **Implementation idea**: Add filter mode in Query pane — `/` switches to filter input; build `WHERE` clause from input; apply to `Database::rows()` call
- **Estimated complexity**: Medium — filter state, filter input mode, query modification
- **Expected impact**: Essential for large tables

### 7. Query Snippet Picker UI
- **Title**: Wire `SnippetStore` into a TUI picker accessible from Query pane
- **Description**: `SnippetStore` class is fully implemented and tested but has no TUI integration.
- **Why it matters**: Users cannot browse/insert saved snippets from UI. Requires manual typing.
- **Source**: lazysql has a snippet modal; gum filter uses fuzzy matching
- **Implementation idea**: `Ctrl+S` opens snippet picker overlay; type to filter snippets via `SnippetStore::search()`; `Enter` inserts selected
- **Estimated complexity**: Medium — needs overlay/composite rendering + picker state
- **Expected impact**: Developer experience improvement

## Medium Priority

### 8. Tabbed Interface (Multiple Open Tables)
- **Title**: Support multiple open tables/queries as tabs
- **Description**: Only one table or query result can be active at a time.
- **Why it matters**: Comparing data across tables requires constant switching. Power users need multiple views.
- **Source**: lazysql has tab support; ratatui tabs widget; textual tabbed content
- **Implementation idea**: Add `tabs` array + `activeTab` index to App; `Ctrl+T` new tab; `Ctrl+W` close tab; show tab bar in Renderer
- **Estimated complexity**: High — App state restructure, Renderer update, tab keybindings
- **Expected impact**: Power-user workflow

### 9. Query History Persistence
- **Title**: Persist query history across sessions to file
- **Description**: Query history lives only in App state for current session.
- **Why it matters**: Losing history on exit is disruptive — users expect persistence.
- **Source**: Could reuse `SnippetStore` pattern — file-backed JSON with `flush()`
- **Implementation idea**: Use same pattern as `SnippetStore`; save history to `~/.config/candy-query/history.json`; load on `App::start()`
- **Estimated complexity**: Low — similar to SnippetStore implementation
- **Expected impact**: UX polish

### 10. Connection Management
- **Title**: In-app UI to add/edit/delete database connections
- **Description**: Opens one SQLite file at a time via CLI argument. No way to manage connections from within TUI.
- **Why it matters**: Production use requires connecting to different databases. Even SQLite-only could have an in-app file picker.
- **Source**: lazysql saves/loads/edits connections via config; gum file picker
- **Implementation idea**: Add connection config stored in `~/.config/candy-query/connections.json`; `Ctrl+N` opens connection picker; `o` key opens file dialog (via gum-style file picker or candy-file)
- **Estimated complexity**: Medium — connection config, picker UI, file dialog
- **Expected impact**: Usability for switching contexts

### 11. Fuzzy Snippet Search
- **Title**: Upgrade `SnippetStore::search()` to fuzzy matching
- **Description**: `search()` does case-insensitive substring match only.
- **Why it matters**: Fuzzy matching (like `fuzzy.Find()` in Go's `sahilm/fuzzy`) is superior for human input with typos.
- **Source**: `sahilm/fuzzy` used in charmbracelet/bubbles List; `charmbracelet/gum filter`; bubblelister
- **Implementation idea**: Use `sahilm/fuzzy` port or implement similar ranking; return matched character indices; highlight in picker UI
- **Estimated complexity**: Medium — fuzzy algorithm port or PHP equivalent
- **Expected impact**: Better snippet discovery

### 12. Read-Only Mode
- **Title**: Block mutation queries (DROP, DELETE, UPDATE, INSERT) in read-only config
- **Description**: Any SQL can be executed including destructive mutations.
- **Why it matters**: Browsing production databases requires safety against accidental mutations.
- **Source**: lazysql has read-only mode
- **Implementation idea**: Add `--read-only` flag / config option; in `Database::query()` detect mutation keywords and throw `RuntimeException`
- **Estimated complexity**: Low — keyword detection in query method
- **Expected impact**: Safety for production use

## Low Priority

### 13. External Editor for SQL
- **Title**: Open query in `$SQL_EDITOR` (vim, nano, etc.)
- **Description**: No external editor integration.
- **Why it matters**: Long SQL queries are easier to edit in a proper editor.
- **Source**: lazysql `Ctrl+E` opens `$SQL_EDITOR`; gum write `ctrl+e`
- **Implementation idea**: `Ctrl+E` forks `$SQL_EDITOR` via candy-pty/process, captures output on close, populates query buf
- **Estimated complexity**: Medium — PTY/process integration
- **Expected impact**: Power-user editing

### 14. CSV Import TUI
- **Title**: Wire `Database::importCsv()` into a TUI flow
- **Description**: CSV import exists but requires calling the method directly.
- **Why it matters**: Data import is a common workflow not accessible from TUI.
- **Source**: gum file + confirm pattern
- **Implementation idea**: `:importcsv` command in Query pane, or `Ctrl+I` to import selected table from file picker
- **Estimated complexity**: Medium — file picker integration, confirm flow
- **Expected impact**: Completeness

### 15. Mouse Click Support
- **Title**: Enable mouse click navigation in tables and rows panes
- **Description**: No mouse handling — keyboard only.
- **Why it matters**: Clicking table names / rows is expected UX for most users.
- **Source**: ratatui-interact (FocusManager, ClickRegion); textual click handling; bubbletea-overlay mouse on layers
- **Implementation idea**: Add `MouseMsg` handling to App::update(); route clicks to pane coordinate mapping; highlight clicked row
- **Estimated complexity**: Medium — MouseMsg routing, coordinate mapping
- **Expected impact**: Accessibility/usability

### 16. JSON Viewer for JSON Values
- **Title**: Toggle formatted JSON view with `z`/`Z` key
- **Description**: JSON values are shown as collapsed/expanded single line based on column width.
- **Why it matters**: Browsing JSON columns needs full formatting.
- **Source**: lazysql `z`/`Z` for cell/row JSON viewer
- **Implementation idea**: `z` key toggles JSON pretty-print in current column; render in a side panel or overlay
- **Estimated complexity**: Medium — overlay rendering for JSON detail view
- **Expected impact**: Usability for JSON columns

---

# Algorithm / Performance Opportunities

## Virtual Scrolling for Large Result Sets
- **Current**: `ResultTable` renders first `DEFAULT_PAGE_SIZE` (25) rows via `array_slice($this->rows, 0, self::DEFAULT_PAGE_SIZE)`. Full row set is held in memory.
- **External approach**: bubble-table and Evertras/bubble-table use `visibleRowCache` invalidated on data change. ratatui table with large datasets (#1004) shows 1-2s lag when rendering all rows.
- **Why external is better**: Only visible rows + buffer are rendered, avoiding O(N) per-frame cost.
- **Tradeoffs**: Variable-height rows complicate offset calculation. Requires stable IDs for cursor tracking.
- **Applicability**: High — `ResultTable` and `ResultPager` would benefit from virtual scrolling for 10k+ row tables.

## Dirty Flag Rendering
- **Current**: App::view() renders on every Program tick regardless of state change.
- **External approach**: ratatui's dirty-flag rendering (issue #1338) shows 50x CPU improvement for static content — buffer diffing every frame is expensive.
- **Why external is better**: Continuous rendering at 60fps even for unchanged output wastes CPU.
- **Tradeoffs**: Requires isDirty() state tracking; must clear at appropriate moments.
- **Applicability**: Medium — candy-core's Program loop should support dirty-flag rendering; the pattern would benefit all SugarCraft apps.

## Layout Caching
- **Current**: `ResultTable::computeColWidths()` runs a full pass over all rows at construction time. Renderer recomputes on every view() call.
- **External approach**: ratatui layout caching (PR #22) showed 7.5x speedup via reference-based constraints + fast hashing. candy-shine should cache layout.
- **Why external is better**: Column width computation is O(rows × cols) at construction — acceptable but not memoized across re-renders.
- **Tradeoffs**: Width cache invalidation needed when rows change.
- **Applicability**: Low-Medium — widths computed once at construction, not re-computed per render.

## Horizontal Scroll Performance
- **Current**: `ResultTable::scrollRight()` returns new instance, recomputes `visibleColCount()` via `floor($this->visibleWidth / cellWidth())`. `cellWidth()` uses average column width.
- **External approach**: bubble-table's `WithMaxTotalWidth` + horizontal scroll is simpler; frozen columns via `WithHorizontalFreezeColumnCount`.
- **Why external is better**: candy-query's average-based `cellWidth()` can under/overshoot visible count on heterogeneous column widths.
- **Tradeoffs**: Simpler to use fixed cell width; heterogeneous widths need per-column computation.
- **Applicability**: Medium — `visibleColCount()` could use sum of first N column widths instead of average.

---

# Architecture Improvements

## Extract Database Interface Now
The README explicitly states "promoting to an interface is a one-class job once the second driver lands." The interface contract is already clear from the 7 public methods. **Extract `DatabaseInterface` now** so that the `App` factory (`App::start(DatabaseInterface $db)`) is driver-agnostic from day one. This is low-cost now and enables the MySQL/Postgres port without restructuring.

## Schema Value Object Promotion
`SchemaBrowser` currently returns private value objects (`SchemaTable`, `SchemaColumn`, `SchemaIndex`, `SchemaForeignKey`). These should be promoted to first-class exported classes so `SnippetStore::load()`-style code can reference them externally. Add `@template` generic annotations for IDE autocomplete.

## CellEditor Integration
`CellEditor` requires `pdo` + `tableName` + `primaryKeyColumn` at construction. The App already knows the selected table and its schema. Pass `SchemaBrowser` to a `CellEditor` and let it auto-detect the primary key from `SchemaColumn::primaryKey`. This simplifies the wiring.

## Query Result as Shared Type
`Database::query()` returns `list<array<string,mixed>>` but this is not typed as a value object. Create a `QueryResult` readonly class containing `rows`, `columns` (derived), `affected` (for mutations), and `timing` (optional). This enables `ExplainView::run()` to accept a `QueryResult` rather than a `Database` directly, improving testability.

## Overlay/Modal System
For snippet picker, connection manager, and JSON viewer, consider a minimal `Overlay` helper following bubbletea-overlay patterns (`docs/repo_map/pr_rmhubbert_bubbletea-overlay.md`):
```php
interface Renderable { public function render(): string; }
function composite(Renderable $foreground, Renderable $background, Position $pos): string;
```
This avoids building a full framework and keeps compositing logic separate.

---

# API / Developer Experience Improvements

## Named Constructors for ResultTable
`ResultTable::fromRows($rows)` is the only factory. Add `ResultTable::fromDatabase(Database $db, string $sql)` for direct execution, and `ResultTable::fromPager(ResultPager $pager)` for wrapping paginated results.

## Builder Pattern for App
`App::start(Database $db)` is the entry point but constructing App manually requires 12 arguments. Add a `AppBuilder`:
```php
$app = AppBuilder::new()->withDatabase($db)->withReadOnly(true)->withPageSize(50)->build();
```

## Consistent Error Types
`Database::tables()` returns `[]` on failure — silent empty array hides errors. Consider `Result<List<string>>` or at minimum a `bool $hadError` output parameter. `Database::query()` throws `PDOException` which is correct.

## SnippetStore File Location
`/tmp/candy-query-snippets.json` should be `~/.config/candy-query/snippets.json` (respecting XDG on Linux). Use `getenv('HOME') . '/.config/candy-query/'` or the `~` expansion pattern.

---

# Documentation / Cookbook Opportunities

## Query Editor Cookbook
Document common SQL patterns: `SELECT * FROM tbl WHERE id > ?`, `JOIN` syntax, `PRAGMA` commands, `EXPLAIN QUERY PLAN` interpretation.

## SchemaBrowser API Examples
The README shows basic usage but doesn't show iterating indexes or foreign keys. Add examples for:
- Finding tables with FKs pointing to a given table
- Detecting tables without primary keys
- Listing all indexes for a table with their columns

## Explaining the Pane Architecture
The 3-pane model (Tables → Rows → Query) should be documented with a state diagram showing transitions. This helps contributors understand the update routing.

## i18n Contribution Guide
15 locales are translated. Document the translation workflow — how to add a new locale, which keys exist, where to find them.

---

# UX / TUI Improvements

## Status Line Enhancement
Currently shows row count or error. Consider: elapsed query time, selected table schema summary, current page indicator.

## Vim Keybindings Extension
`j/k` work in Tables and Rows panes. Add `g`/`G` for go-to-top/bottom, `Ctrl+U`/`Ctrl+D` for half-page scroll, `/` for search (as filter trigger).

## Help Overlay
Add `?` key to show help overlay listing all keybindings for current pane. Uses same overlay pattern as snippet picker.

## Syntax Highlighting in Query Editor
SQL keywords (SELECT, FROM, WHERE, etc.) could be coloured differently from identifiers. Use regex-based tokenizer at render time — no full SQL parser needed.

## Error Highlighting
PDOException messages are long. Truncate to 2 lines with expandable detail on `e` key or show only first line in status, full message in a dedicated error pane.

## Zebra Striping in ResultTable
Evertras/bubble-table uses `RowStyleFunc` for zebra striping. Add optional `withZebra()` style in `ResultTable` for alternating row background colours.

---

# Testing / Reliability Improvements

## Property-Based Testing
Add `PHPUnit` data providers generating random SQL queries and verifying `Database::query()` never throws on valid SQLite syntax. Use `fakerphp/faker` for schema generation.

## Fuzz Testing on Identifier Escaping
The `str_replace('"', '""', $name)` approach handles basic cases but could miss edge cases with Unicode identifiers. Add explicit tests for Unicode table/column names, names with backticks, names starting with numbers.

## Snapshot Tests for ANSI Output
`ResultTable` already does snapshot-style testing via `RendererTest`. Extend to `ExplainView` output and `ResultTable::render()` with various column widths to catch regression in ANSI formatting.

## Mutation Testing
Use `infection/infection` to verify tests actually catch bugs (i.e., mutating `offset + 1` to `offset + 2` should fail tests).

## Test SnippetStore Corruption Path
`SnippetStore::load()` guards against corrupt JSON but this path is not explicitly tested with an actual corrupt file — only unit-test mocks are used.

---

# Ecosystem / Integration Opportunities

## Plugin Architecture for Drivers
Following ratatui's third-party widget ecosystem pattern, declare `sugarcraft/database-*` packages as official driver extensions. A `composer require sugarcraft/database-mysql` installs the MySQL driver, and `App::start()` auto-discovers it.

## candy-query as a Teaching Example
The codebase is clean enough to serve as the canonical SugarCraft example app. Create `examples/` demonstrating each component in isolation: `Database` standalone, `SchemaBrowser` standalone, `ResultTable` standalone.

## Share ResultTable with sugar-bits
`ResultTable` in candy-query is a specialized result set renderer. Consider extracting a general-purpose `Table` component to `sugar-bits` that `ResultTable` composes, so other SugarCraft apps can use the scrolling table.

## Export sugar-query as a Reusable Component
The `App` class is `final` and directly constructs its own state. Consider extracting the `start(Database $db)` factory into a `BrowserApp` class that accepts a `DatabaseInterface`, making it reusable with MySQL/Postgres drivers without modification.

---

# Notable PRs / Issues / Discussions

## lazysql: Multi-Driver Architecture (upstream)
lazysql's Go architecture uses separate driver packages (`pgx` for Postgres, `go-sql-driver` for MySQL, `go-sqlite3` for SQLite, `go-mssql` for MSSQL). This is the target architecture for the Database interface. Each driver has identical interface methods returning native Go types. For PHP, PDO already provides this abstraction — the interface is straightforward.

## bubbletea-overlay: Viewable Interface (PR #19, Dec 2025)
`rmhubbert/bubbletea-overlay` relaxed from requiring `tea.Model` to requiring only `View() string`. This lesson applies directly: candy-query's overlay/snippet picker should accept `Renderable { render(): string }` rather than requiring `Model`. Full discussion in `docs/repo_map/pr_rmhubbert_bubbletea-overlay.md`.

## ratatui #1004: Table Performance with Large Datasets
Critical issue: 15k items causes 1-2s render lag. Root causes: O(N) conversion to vec at construction, `text().height()` called for all items, all items create Spans even when off-screen. **Solution**: dirty-flag rendering (50x CPU improvement), virtual scrolling (only render visible items + buffer). For candy-query: `ResultTable` holds full result set but only renders first 25 rows — already has a form of virtualization but could memoize per-offset column widths.

## textualize #6381: GC Stuttering with MarkdownViewer
MarkdownViewer creates hundreds of `MarkdownBlock` child widgets, each holding 3+ reference cycles through Styles objects, causing Python gen2 GC pauses of 50-200ms. **Lesson for candy-query**: The `Style` objects in candy-sprinkles should not hold strong references to parent App/Model objects. Use weak references or clear parent references on render completion.

## bubble-table: Fuzzy Filtering (bubbles List + sahilm/fuzzy)
The `sahilm/fuzzy` library provides `Find()` returning ranked matches with character indices for highlighted filtering. bubble-table uses this in `List` and `gum filter` uses it. For candy-query, fuzzy search in `SnippetStore` would be a direct benefit. The PHP port or equivalent algorithm is needed.

## ratatui #1855: Layout Constraint Solver Hanging
Cassowary constraint solver hangs with 100% CPU on certain constraint combinations. Root cause: non-deterministic `HashMap` behavior. Fix: `HashMap<Symbol, Row, BuildHasherDefault<SimpleStateHasher>>` for deterministic hashing. **Lesson**: If candy-shine uses any constraint solver, use deterministic hashing.

---

# Recommended Roadmap

## Immediate Wins (0–2 weeks)
1. Extract `DatabaseInterface` from `Database` — enables MySQL/Postgres future ports without restructuring
2. Add pagination controls (`<`/`>` keys in Rows pane) — connects existing `ResultPager`
3. Add column sorting (`K`/`J` keys in Rows pane) — rebuilds query with `ORDER BY`
4. Add query history persistence to `~/.config/candy-query/history.json` — use same pattern as `SnippetStore`

## Medium-term Improvements (1–3 months)
5. Wire `CellEditor` into Rows pane for inline cell editing
6. Add expandable schema tree sidebar — shows columns, indexes, FKs for selected table
7. Build query snippet picker overlay (`Ctrl+S`) — composite rendering with `SnippetStore::search()`
8. Implement fuzzy search in `SnippetStore::search()` — port `sahilm/fuzzy` algorithm
9. Add read-only mode (`--read-only` flag / config)
10. Add `AppBuilder` for cleaner App construction
11. Add `ResultTable::fromPager()` and `ResultTable::fromDatabase()` factories

## Major Architectural Upgrades (3–6 months)
12. Tabbed interface for multiple open tables/queries
13. Row filtering (`/` key → WHERE clause builder)
14. Mouse click support in Tables and Rows panes
15. Connection management UI (file picker + connection config)
16. Multi-driver support: MySQL driver implementing `DatabaseInterface`
17. JSON viewer overlay for JSON columns (`z`/`Z` key)

## Experimental Ideas
18. External editor integration (`$SQL_EDITOR`) via PTY
19. Fuzzy query auto-completion using LLM
20. TUI-side query plan visualization with cost estimation

---

# Priority Matrix

| Opportunity | Impact | Complexity | Risk | Recommended Priority |
|------------|--------|------------|------|---------------------|
| Extract DatabaseInterface | Critical | Low | Low | **P0 — Immediate** |
| Pagination controls | High | Low | Low | **P0 — Immediate** |
| Column sorting | High | Medium | Low | **P0 — Immediate** |
| History persistence | Medium | Low | Low | **P1 — Short-term** |
| Wire CellEditor into TUI | Critical | Medium | Medium | **P1 — Short-term** |
| Schema sidebar tree | High | High | Medium | **P1 — Short-term** |
| Snippet picker overlay | Medium | Medium | Low | **P1 — Short-term** |
| Fuzzy search in SnippetStore | Medium | Medium | Low | **P2 — Medium-term** |
| Read-only mode | High | Low | Low | **P1 — Short-term** |
| Row filtering | High | Medium | Medium | **P2 — Medium-term** |
| Tabbed interface | High | High | Medium | **P2 — Medium-term** |
| Mouse click support | Medium | Medium | Medium | **P2 — Medium-term** |
| Connection management | Medium | Medium | Low | **P2 — Medium-term** |
| Multi-driver (MySQL) | Critical | High | High | **P3 — Long-term** |
| JSON viewer overlay | Medium | Medium | Low | **P3 — Long-term** |
| Virtual scrolling | High | High | Medium | **P3 — Long-term** |

---

# Final Strategic Assessment

candy-query at v1 is a solid, well-tested SQLite browser that demonstrates the SugarCraft stack's strengths: immutable state, clean separation of concerns, PSR-4 structure, and comprehensive test coverage. The architecture's most critical gap is the absence of a `DatabaseInterface` — this is the single most important architectural improvement, because without it the entire app is SQLite-locked and the README's promise of "MySQL/Postgres is a one-class job" requires a risky refactor.

The feature gap relative to upstream lazysql is substantial but prioritized correctly. The immediate wins (pagination, sorting, history persistence) are low-complexity, high-impact additions that require no architectural change. The medium-term items (CellEditor wiring, schema sidebar, snippet picker) require thoughtful UI design but are well-scoped.

The most significant strategic opportunity is the multi-driver interface. If `DatabaseInterface` is extracted now, the path to MySQL support becomes a matter of implementing a second driver class — the `App` and all other components remain unchanged. This should be treated as the highest-priority architectural investment.

Secondary opportunities lie in UX parity with bubble-table and Evertras/bubble-table: fuzzy search in snippet store, zebra striping in `ResultTable`, mouse support, and column sorting are all standard in the ecosystem and expected by users migrating from Go-based TUIs.

The project would benefit from a documented plugin system for database drivers following ratatui's third-party ecosystem pattern. This is also the right time to formalize the `Renderable` interface for the overlay system, so that snippet picker, connection manager, and JSON viewer all compose consistently without framework coupling.

Testing infrastructure is strong but could add property-based and mutation testing to increase confidence in edge-case handling, particularly around identifier escaping and JSON encoding/decoding.
