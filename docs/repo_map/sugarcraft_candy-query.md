# SugarCraft/candy-query — Innovation & Comparison Report

## Overview

**candy-query** is a terminal SQLite browser built on the SugarCraft stack, porting the Go ecosystem `jorgerojas26/lazysql` to PHP. It is `final` classes, `declare(strict_types=1)`, PSR-4, PHP 8.3+, and requires only `ext-pdo_sqlite`. The architecture is deliberately narrow at v1 — SQLite-only — with a clear interface path to MySQL/Postgres once a second driver lands.

**Key statistics:**
- **9 source files** in `src/` (App, Database, SchemaBrowser, Renderer, ResultTable, ResultPager, CellEditor, SnippetStore, ExplainView, Pane enum, plus 4 schema value objects)
- **7 test files** with 60+ test methods covering all major operations
- **2 example scripts** (play.php, query-history.tape)
- **16 locales** (en + 15 translated)
- **2 VHS demos** (.vhs/play.gif, .vhs/query-history.gif)
- **1 bin entry point** (`bin/candy-query`)

**Status:** 🟢 v1 ready — SQLite browser with full test coverage, schema introspection, query history/favorites, CSV/SQL export, and EXPLAIN QUERY PLAN visualization.

**Upstream:** [jorgerojas26/lazysql](https://github.com/jorgerojas26/lazysql) — Go, tview-based, multi-driver (Postgres, MySQL, SQLite, MSSQL)

---

## Architecture

### Package Structure

```
candy-query/
├── bin/candy-query                  # CLI entry point (31 lines)
├── src/
│   ├── App.php                      # SugarCraft Model — 3-pane TUI (415 lines)
│   ├── Database.php                 # PDO/SQLite wrapper + CSV/SQL export (234 lines)
│   ├── SchemaBrowser.php             # PRAGMA-based schema introspection (229 lines)
│   ├── Renderer.php                 # Stateless 3-pane ANSI renderer (124 lines)
│   ├── ResultTable.php              # Horizontal-scrolling result renderer (461 lines)
│   ├── ResultPager.php              # Immutable cursor-based pager (150 lines)
│   ├── CellEditor.php               # Cell-level UPDATE by PK identity (104 lines)
│   ├── SnippetStore.php             # File-backed JSON snippet store (186 lines)
│   ├── ExplainView.php              # EXPLAIN QUERY PLAN colour tree (229 lines)
│   ├── Pane.php                     # 3-pane focus enum (26 lines)
│   └── Lang.php                     # i18n facade (22 lines)
├── lang/                            # 16 locales (en + 15 translated)
├── tests/
│   ├── AppTest.php                  # 16 test methods (pane cycling, query editing, history)
│   ├── DatabaseTest.php             # 14 test methods (CRUD, CSV/SQL export)
│   ├── SchemaBrowserTest.php         # 8 test methods (PRAGMA parsing, schema objects)
│   ├── ResultTableTest.php           # 21 test methods (scrolling, NULL, JSON, rendering)
│   ├── ResultPagerTest.php           # 12 test methods (pagination, bounds)
│   ├── CellEditorTest.php           # 7 test methods (cell/row updates)
│   ├── SnippetStoreTest.php         # 14 test methods (CRUD, search, file persistence)
│   ├── ExplainViewTest.php          # 10 test methods (tag classification, depth parsing)
│   ├── RendererTest.php             # (renderer snapshot tests)
│   └── PaneEnumTest.php             # (pane enum tests)
├── examples/
│   └── play.php                     # In-memory demo script (31 lines)
├── .vhs/
│   ├── play.tape / play.gif         # Query execution demo
│   └── query-history.tape / query-history.gif  # History cycling demo
├── composer.json                    # Requires candy-core, candy-sprinkles
├── phpunit.xml                      # Bootstrap vendor/autoload.php, colors=true
├── README.md                        # 160 lines — architecture table, usage examples
└── CALIBER_LEARNINGS.md            # 4 patterns (pragma-schema, immutable-cursor-pager, etc.)
```

### Three-Pane TUI Model

The `App` class is a SugarCraft `Model` implementing the canonical `init()`/`update()`/`view()`/`subscriptions()` contract. It holds three mutually exclusive panes:

| Pane | Content | Navigation |
|------|---------|------------|
| `Pane::Tables` | Scrollable list of table names | `j/k`, `Enter` loads table |
| `Pane::Rows` | Paginated preview of selected table (100 rows default) | `j/k`, vim keys |
| `Pane::Query` | Freeform SQL editor buffer | Full char input, `Ctrl+R` runs |

**Focus cycling** via `Tab` — `Pane::next()` enum method (lines 18-25 of `Pane.php`).

**Query history** (upstream lazysql has this via a modal; candy-query keeps it in-buffer):
- `Up/Down` arrows navigate history when in Query pane
- `Ctrl+F` favorites the current query buffer
- `Ctrl+Shift+F` removes from favorites
- History is a `list<string>` (newest-first) passed as App state

**Immutable state** — every state transition returns `new self(...)` with the updated field. No mutations.

### Dependency Usage of SugarCraft Components

candy-query depends only on `candy-core` and `candy-sprinkles`:

| Component | Usage in candy-query |
|-----------|---------------------|
| `SugarCraft\Core\Model` | `App` implements `Model` interface |
| `SugarCraft\Core\Msg\KeyMsg` | All keyboard input routed through `KeyMsg` |
| `SugarCraft\Core\KeyType` | Key classification (`Char`, `Up`, `Down`, `Enter`, `Tab`, etc.) |
| `SugarCraft\Core\Cmd` | `Cmd::quit()` returned on `q`/`Esc` |
| `SugarCraft\Core\Util\Color` | All ANSI colour constants (`Color::hex('#fde68a')`) |
| `SugarCraft\Sprinkles\Style` | Every rendered string wrapped in `Style::new()->...->render()` |
| `SugarCraft\Sprinkles\Border` | `Border::rounded()` for pane frames |
| `SugarCraft\Sprinkles\Layout` | `Layout::joinHorizontal()` for tables+rows composition |
| `SugarCraft\Sprinkles\Position` | `Position::TOP` for layout joining |
| `SugarCraft\Core\I18n\Lang` | `Lang::t()` for i18n strings |

---

## Component-by-Component Analysis

### 1. Database (`src/Database.php` — 234 lines)

**Role:** Thin PDO/SQLite wrapper. The only stateful dependency of the app; tests use `:memory:` to exercise all paths without fixture files.

**Key design:** The class comment states the intent to promote to an interface the day a non-SQLite driver lands. Currently a sealed concrete.

**Core methods:**
```php
// File: src/Database.php, lines 25–31
public static function open(string $path): self
{
    if ($path !== ':memory:' && !is_file($path)) {
        throw new \RuntimeException(Lang::t('database.no_file', ['path' => $path]));
    }
    return new self(new \PDO('sqlite:' . $path));
}
```

**`tables()`** (lines 34–49): Queries `sqlite_master` for `type IN ('table','view')` excluding `sqlite_%` tables, returns `list<string>` sorted by name.

**`rows(string $table, int $limit = 100)`** (lines 54–59): Fetches `SELECT *` with `LIMIT`. Uses `sprintf` with double-quote escaping (`str_replace('"', '""', $table)`) for identifier safety.

**`query(string $sql)`** (lines 67–75): Runs arbitrary SQL via `$pdo->prepare()`. Returns `list<array<string,mixed>>` for SELECTs; returns `[['affected' => N]]` for mutation statements (checked via `columnCount() > 0`).

**CSV import** (lines 88–125): Reads CSV with `fgetcsv`, builds prepared `INSERT` statement, executes row-by-row with transaction safety via `try/finally`. First row must be headers matching table columns.

**CSV export** (lines 137–171): Uses `PRAGMA table_info` to get column names, writes with `fputcsv`, then iterates rows with `fputcsv`.

**SQL dump export** (lines 183–233): Generates `CREATE TABLE` + `INSERT INTO` statements for all user tables. Handles `NULL` values, string escaping, proper `ORDER BY` for deterministic output.

**All identifiers** are double-quote-escaped (`str_replace('"', '""', $name)`) — no prepared statement parameter substitution for identifiers.

### 2. SchemaBrowser (`src/SchemaBrowser.php` — 229 lines)

**Role:** Exposes SQLite schema via three PRAGMA queries, returning immutable typed value objects.

**Pattern established in CALIBER_LEARNINGS.md:** SQLite PRAGMA results are untyped scalar arrays. Wrap each in a dedicated private method returning typed value objects to catch mis-indexed row access at construction time.

**`refresh()`** (lines 28–52): Loads all table names from `sqlite_master`, then iterates calling `loadTable()` for each.

**`loadColumns()`** (lines 67–87): Runs `PRAGMA table_info`, returns `list<SchemaColumn>`:
```php
// File: src/SchemaBrowser.php, lines 77–84
$columns[] = new SchemaColumn(
    cid: (int) ($row['cid'] ?? 0),
    name: (string) ($row['name'] ?? ''),
    type: (string) ($row['type'] ?? ''),
    notNull: (bool) ($row['notnull'] ?? false),
    defaultValue: $row['dflt_value'] ?? null,
    primaryKey: (bool) ($row['pk'] ?? false),
);
```

**`loadIndexes()`** (lines 92–118): Runs `PRAGMA index_list` then `PRAGMA index_info` for each index to get column names.

**`loadForeignKeys()`** (lines 123–143): Runs `PRAGMA foreign_key_list` returning `list<SchemaForeignKey>` with full FK metadata (id, from/to column, on_update, on_delete).

**`dropTable()`** (lines 150–155): Runs `DROP TABLE IF EXISTS` and returns refreshed browser.

**Schema value objects** (`SchemaTable`, `SchemaColumn`, `SchemaIndex`, `SchemaForeignKey`) are all `@readonly` classes with bare accessors and no methods.

### 3. ResultTable (`src/ResultTable.php` — 461 lines)

**Role:** Renders SQL result sets with horizontal scrolling, JSON pretty-print, styled NULL token, and column auto-sizing.

**Immutable** — all configuration via constructor or `with*()` builders; all navigation returns `new self(...)`.

**Key constants:**
```php
public const NULL_TOKEN = 'NULL';
public const DEFAULT_MAX_CELL = 40;
public const DEFAULT_PAGE_SIZE = 25;
```

**Column auto-sizing** (lines 434–450): Computes per-column widths from the full row set at construction time. Width = max(mb_strlen(header), max(mb_strlen(cell))) clamped to `maxCellWidth`.

**Horizontal scrolling** (lines 99–126, 198–219):
- `scrollLeft()` / `scrollRight()` return new instance with shifted `offset`
- `visibleColCount()` derives from `visibleWidth / cellWidth()`
- `visibleColumns()` returns `array_slice($columns, $offset, visibleColCount())`
- `canScrollLeft()` / `canScrollRight()` guard the bounds

**JSON handling** (lines 390–407): Uses `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` when `jsonPretty` is true AND `visibleWidth >= 80`. For narrow widths, collapses to single line via `preg_replace('/\s+/', ' ', $json)`.

**NULL formatting** (lines 352–358): Styled with italic pink (`Color::hex('#f9a8d4')`).

**`renderPlain()`** (lines 275–289): Returns plain text without ANSI codes for copy/export.

**Scroll hint** (lines 336–347): Renders `◀  cols 1–5 of 12  ▶` style indicator.

### 4. ResultPager (`src/ResultPager.php` — 150 lines)

**Role:** Cursor-based pagination for SQL result sets. Immutable + fluent.

**Pattern established in CALIBER_LEARNINGS.md:** Cursor-based pagination over an in-memory result-set is naturally immutable. Storing `$rows` as a constructor arg keeps the pager stateless between navigation calls.

**Key methods:**
```php
public function __construct(
    public readonly array $rows,
    public readonly int $pageSize = 25,
    public readonly int $offset = 0,
) { ... }

public function nextPage(): self    // offset += pageSize
public function prevPage(): self    // offset -= pageSize
public function goToPage(int $page): self  // 1-based page number
public function withPageSize(int $size): self
```

**Bounds clamping:** All navigation methods clamp offset to valid range to prevent edge-case overruns.

### 5. App — The SugarCraft Model (`src/App.php` — 415 lines)

**Role:** Central state machine implementing `Model`. Holds tables list, selected table rows, query buffer, pane focus, error/status strings, and query history/favorites.

**State fields:**
```php
public readonly Database $db;
public readonly array $tables = [];           // list<string>
public readonly int $tableCursor = 0;
public readonly ?string $selectedTable = null;
public readonly array $rows = [];             // list<array<string,mixed>>
public readonly int $rowCursor = 0;
public readonly string $queryBuf = '';
public readonly Pane $pane = Pane::Tables;
public readonly ?string $error = null;
public readonly ?string $status = null;
public readonly array $queryHistory = [];      // newest first
public readonly array $queryFavorites = [];
public readonly int $historyIndex = -1;      // -1 = current buffer
public readonly ?string $savedBuf = null;      // temp storage during history nav
```

**`update()` routing** (lines 71–91): Dispatches `KeyMsg` to `editQuery()`, `handleTablesKey()`, or `handleRowsKey()` based on current pane.

**Query history navigation** (lines 282–363):
- `historyUp()`: If `historyIndex === -1` (at current buffer), save buffer and navigate to index 0. If `historyIndex > 0`, decrement. Navigates to older queries (lower index = older).
- `historyDown()`: If `historyIndex > 0`, increment (newer). If at 0, restore `savedBuf` and set `historyIndex = -1`.
- Deduplication in `runQuery()`: Only prepends if `($history[0] ?? '') !== $trimmed`.

**`runQuery()`** (lines 182–215): Wraps `trim($this->queryBuf)` check, calls `$this->db->query()`, populates rows on success or `error` field on `PDOException`.

**`loadTable()`** (lines 217–241): Calls `$this->db->rows($name)`, sets `selectedTable`, resets `rowCursor`.

**UTF-8 safe backspace** (lines 401–409): `dropLast()` counts UTF-8 continuation bytes (`(ord($s[$i]) & 0xc0) === 0x80`) to avoid splitting multi-byte characters.

### 6. Renderer (`src/Renderer.php` — 124 lines)

**Role:** Stateless renderer — pure function from `App` to ANSI string. Uses `candy-sprinkles` Border/Layout/Position/Style.

**Three-pane layout** (lines 23–47):
```php
$tables = self::tablesPane($a);
$rows   = self::rowsPane($a);
$top    = Layout::joinHorizontal(Position::TOP, $tables, '  ', $rows);
$query  = self::queryPane($a);
```

**Focused pane** gets a brighter border colour (`#7dd3fc` cyan vs `#4a3868` dim).

**Status line:** Error in red bold, status in green, otherwise empty.

### 7. CellEditor (`src/CellEditor.php` — 104 lines)

**Role:** Cell-level UPDATE by primary-key identity. Narrow scope — INSERT/DELETE handled by `Database` directly.

**Prepared statement pattern** for all values:
```php
// File: src/CellEditor.php, lines 39–44
$sql = "UPDATE \"{$safeTable}\" SET \"{$safeCol}\" = :newval WHERE \"{$safePk}\" = :rowid";
$stmt = $this->pdo->prepare($sql);
$stmt->bindValue(':newval', $newValue);
$stmt->bindValue(':rowid', $rowId);
```

**`updateRow()`** (lines 55–84): Batches multiple column updates in a single `UPDATE` statement using indexed bind values (`:val0`, `:val1`, etc.) to avoid identifier collision.

### 8. SnippetStore (`src/SnippetStore.php` — 186 lines)

**Role:** File-backed JSON store for named SQL snippets. Immutable — every mutation returns a new instance; `flush()` is the explicit persist call.

**Pattern established in CALIBER_LEARNINGS.md:** File-backed JSON store works best as immutable value object with separate `flush()`. Guard corrupt files at `load()` with no-op fallback.

**`load()`** (lines 39–68): Returns empty store on missing or corrupt JSON. Uses `JSON_THROW_ON_ERROR`.

**`flush()`** (lines 75–93): Creates directory if needed (`mkdir($dir, 0755, true)`), writes with `LOCK_EX`.

**`add()`** (lines 98–119): Guards empty name/sql as no-op. Duplicate names replace existing entry.

**`search()`** (lines 152–165): Case-insensitive substring match on both name and SQL content.

### 9. ExplainView (`src/ExplainView.php` — 229 lines)

**Role:** Parses SQLite's `EXPLAIN QUERY PLAN` flat output into a structured, colour-coded ANSI tree.

**Colour-coded operation types:**
| Tag | Colour | Trigger keywords |
|-----|--------|-------------------|
| `SEARCH` | cyan `#7dd3fc` | search |
| `SCAN` | yellow `#fde68a` | scan, default |
| `USING` | green `#6ee7b7` | using |
| `JOIN` | purple `#c084fc` | join |
| `SUBQUERY` | pink `#f9a8d4` | subquery, correlated |
| `COMPOUND` | orange `#fb923c` | compound, union |

**Depth parsing** (lines 161–171): Counts `|--` or `` `-- `` pairs from the start of the detail string. Each pair = depth 1. Two spaces per depth level for indentation.

**Tag classification** (lines 179–203): Checked in specific-to-general order (COMPOUND first, SUBQUERY second, etc.) to avoid early matches on general keywords.

**`toArray()`** (lines 91–102): Returns JSON-serialisable array with `depth`, `tag`, `detail`, `indent` for programmatic use.

---

## Database Browsing Patterns

### Table Listing

candy-query lists tables via `Database::tables()` which queries `sqlite_master`:
```sql
SELECT name FROM sqlite_master
WHERE type IN ('table','view') AND name NOT LIKE 'sqlite_%'
ORDER BY name
```

lazysql (upstream) uses a tree view with expand/collapse per table showing columns, indexes, foreign keys, and constraints as sub-nodes. It supports refreshing the tree (`R` key).

**candy-query approach:** Tables pane shows flat alphabetical list. Enter on a table name loads its first 100 rows into the rows pane. Schema details (columns, indexes, FKs) are exposed through the separate `SchemaBrowser` class but not rendered in the TUI at v1 — the README shows the API usage but the App itself only shows row previews.

### Row Browsing

**candy-query:** Loads up to 100 rows via `Database::rows()` on table selection. Rows pane shows fixed-width columns (12 chars each), truncates at 11 + `…`. Shows first 13 rows with cursor on current row (reverse style). No pagination controls in the TUI itself at v1.

**lazysql:** Full table view with:
- Pagination (`<`/`>` for prev/next page, configurable `DefaultPageSize` up to 300)
- Column sorting (`K`/`J` for asc/desc)
- Row filtering (`/` opens WHERE-clause input)
- Inline editing (`c` on a cell)
- INSERT (`o` to append new row, `O` to duplicate)
- DELETE (`d` to delete row)
- JSON viewer (`z`/`Z` for cell/row)
- CSV export (`E`)
- Sidebar with schema tree

### Schema Introspection

**candy-query SchemaBrowser** exposes three PRAGMA queries as typed objects:
- `PRAGMA table_info` → `SchemaColumn` (cid, name, type, notNull, defaultValue, primaryKey)
- `PRAGMA index_list` + `PRAGMA index_info` → `SchemaIndex` (name, unique, columns)
- `PRAGMA foreign_key_list` → `SchemaForeignKey` (id, column, foreignTable, foreignColumn, onUpdate, onDelete)

**lazysql** shows these in a collapsible tree sidebar:
- Tables → Columns (with type, nullable, PK, default)
- Indexes (with unique flag, columns)
- Constraints (CHECK, UNIQUE, etc.)
- Foreign Keys (with ON UPDATE/DELETE actions)

---

## Query Execution

### Execution Model

candy-query uses **PDO prepared statements** for all query execution:
```php
// Database.php, lines 67–75
public function query(string $sql): array
{
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute();
    if ($stmt->columnCount() > 0) {
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    return [['affected' => $stmt->rowCount()]];
}
```

**Key differences from lazysql:**
- lazysql uses database-driver-specific Go libraries (pgx for Postgres, go-sql-driver for MySQL, etc.)
- candy-query uses PDO which provides a uniform interface but with less driver-specific optimization
- No query parameterization in candy-query — all values are embedded in the SQL string directly via prepared statement binding

### Query History

candy-query maintains query history as an in-memory array passed through App state:
- Up arrow navigates to older queries (toward index 0 from -1 = current buffer)
- Down arrow navigates to newer queries
- `Ctrl+F` adds current buffer to `queryFavorites`
- `Ctrl+Shift+F` removes from favorites
- History persists only for the session (not across restarts)

**lazysql** has a separate Query History modal (`Ctrl-_`) with save/delete/copy/search capabilities and tab-based browsing.

### Snippet Store

candy-query has `SnippetStore` for persistent named snippets:
- `add(name, sql, description)` / `delete(name)` / `find(name)` / `search(term)`
- `flush()` persists to `/tmp/candy-query-snippets.json`
- Search is case-insensitive substring on both name and SQL

**Not wired into the TUI at v1** — the class exists and is tested but the App doesn't expose a snippet picker UI.

### EXPLAIN QUERY PLAN

candy-query has `ExplainView` which:
- Runs `EXPLAIN QUERY PLAN {sql}` via `Database`
- Parses depth from `|--` / `` `-- `` tree prefixes
- Classifies operations by keyword into 6 colour-coded tags
- Renders as indented ANSI tree

**lazysql** shows query plans in a preview pane below the SQL editor.

---

## Comparison with Upstream lazysql

### Features Present in Both

| Feature | candy-query | lazysql |
|---------|-------------|---------|
| Table listing | ✅ flat list | ✅ tree view |
| Row preview | ✅ 100 rows | ✅ paginated (up to 300) |
| SQL editor | ✅ in buffer | ✅ separate view/modal |
| Query execution | ✅ `Ctrl+R` | ✅ `Ctrl+E` |
| Query history | ✅ in-buffer nav | ✅ modal |
| Vim keybindings | ✅ `j/k` in list panes | ✅ global |
| Schema introspection | ✅ via SchemaBrowser class | ✅ sidebar tree |
| Error display | ✅ status line | ✅ inline |
| CSV export | ✅ via Database | ✅ via `E` |
| SQL dump | ✅ via Database | ❌ |
| Row count status | ✅ | ✅ |
| Quit key | ✅ `q` (except query pane) | ✅ `q` global |

### Features Only in lazysql

- **Multi-driver support**: Postgres, MySQL, SQLite, MSSQL (candy-query is SQLite-only at v1)
- **Connection management**: Save/edit/delete database connections (candy-query opens one file at a time)
- **Tabs**: Multiple tables open simultaneously (candy-query is single-pane)
- **Sidebar with schema tree**: Columns, indexes, FKs, constraints visible in sidebar
- **Table filtering**: `WHERE`-clause filter input
- **Row editing**: Inline cell editing, INSERT, DELETE (candy-query has `CellEditor` class but no TUI integration)
- **JSON viewer**: Formatted JSON display for JSON values
- **Sorting**: Ascending/descending sort on columns
- **Pagination controls**: Previous/next page keybindings
- **External editor**: Open SQL in `$SQL_EDITOR`
- **Copy to clipboard**: Cell/row copy
- **Configurable keybindings**: TOML-based keymap customization
- **SSH tunnel support**: Pre-connection commands in config
- **Environment variables**: In connection URLs
- **Read-only mode**: Block mutation queries

### Features Only in candy-query

- **EXPLAIN QUERY PLAN viewer**: Colour-coded ANSI tree render
- **SQL dump export**: Full database as SQL script
- **Horizontal-scrolling result table**: `ResultTable` with scrollLeft/scrollRight
- **JSON pretty-print toggle**: Collapses or expands based on column width
- **Query favorites**: Ctrl+F/Shift+Ctrl+F to favorite/unfavorite
- **Prepared statement architecture**: `Database::query()` uses `PDO::prepare()` consistently

### Architectural Differences

| Aspect | candy-query (PHP) | lazysql (Go) |
|--------|-------------------|--------------|
| Runtime | SugarCraft/Core `Model` + `Program` | tview `Application` |
| State | Immutable `readonly` properties | Mutable struct fields |
| Rendering | `Renderer::render(App): string` | tview primitives |
| Input | `KeyMsg` dispatch | tcell events |
| Database | PDO (single driver at v1) | Multiple driver libs |
| Style system | candy-sprinkles `Style` | tview styles |
| i18n | 16 locales via `Lang::t()` | No i18n |

---

## Other SQL Browser/Client Ports

### Related Reports in repo_map/

- **charmbracelet/bubbletea** — upstream TUI framework (Go)
- **charmbracelet/gum** — CLI tool picker (Go, ported as candy-shell)
- **Evertras/bubble-table** — table component (Go, ported as sugar-table)
- **charmbracelet/bubbles** — component library (Go, ported as sugar-bits)
- **erikgeiser/promptkit** — prompt library (Go, ported as sugar-readline)

No other SQL browser TUI ports exist in the repo_map. Notable SQL-related tools outside the map:

- **vladbalcos/mitzasql** — Python TUI SQL client (referenced in lazysql README alternatives)
- **TaKO8Ki/gobang** — Go TUI SQL client (referenced in lazysql README alternatives)

---

## Innovation Points

### SugarCraft-specific Enhancements

1. **EXPLAIN QUERY PLAN tree renderer** — Not in upstream. Parses SQLite's flat `|--` prefix notation into a depth-indented, colour-coded ANSI tree with 6 operation-type categories.

2. **Horizontal-scrolling result table** — `ResultTable::scrollLeft()`/`scrollRight()` with `visibleColCount()` deriving visible columns from character budget. Auto-sizes columns to widest value at construction.

3. **Query favorites** — `Ctrl+F` to favorite, `Ctrl+Shift+F` to unfavorite. Inline with the query buffer — no modal required.

4. **Immutable pager + table** — `ResultPager` and `ResultTable` are immutable value objects. Every navigation returns a new instance. Pattern enforced at type level.

5. **UTF-8 safe backspace** — `App::dropLast()` counts UTF-8 continuation bytes to avoid splitting multi-byte characters when deleting.

6. **Prepared statement with `columnCount()` dispatch** — `Database::query()` distinguishes SELECT (return rows) from mutation (return affected count) by checking `columnCount() > 0`.

7. **PRAGMA schema as typed value objects** — `SchemaBrowser` wraps raw scalar PRAGMA arrays into `SchemaColumn`, `SchemaIndex`, `SchemaForeignKey` value objects. Catches mis-indexed access at construction rather than call site.

8. **SnippetStore with corrupt-file resilience** — `SnippetStore::load()` returns empty store on missing or corrupt JSON rather than throwing, keeping call sites clean.

9. **CSV + SQL export** — `Database::exportCsv()` and `exportSql()` provide file export without requiring the full TUI.

10. **Multi-locale support** — 16 locales (en + 15 translated) via `SugarCraft\Core\I18n\Lang` facade.

---

## Gaps and Future Work

### Known Gaps at v1

1. **No multi-driver support** — `Database` is a sealed SQLite concrete. README states promoting to an interface is a "one-class job" once the second driver lands, but no interface exists yet.

2. **No row editing TUI** — `CellEditor` class exists with `updateCell()`, `updateRow()`, `readCell()` but is not wired into the App. No INSERT or DELETE operations in the TUI.

3. **No pagination controls in TUI** — `ResultPager` exists but App only shows 13 rows with no next/prev keybindings. `Database::rows()` has a `LIMIT 100` hardcoded.

4. **No schema sidebar** — Tables pane shows only table names. No expandable tree showing columns, indexes, FKs.

5. **No tabs** — Only one table/query can be active at a time. lazysql supports multiple open tabs.

6. **No connection management** — Opens one SQLite file at a time via CLI argument. No way to add/edit/delete connections from within the TUI.

7. **No query snippet picker** — `SnippetStore` exists and is tested but has no TUI integration.

8. **No row filtering** — `WHERE`-clause filter like lazysql's `/` key is not implemented.

9. **No sorting** — Column sort by clicking or `K`/`J` keys not implemented.

10. **No CSV import TUI** — `Database::importCsv()` exists but is not exposed through the TUI.

---

## File References

### Core Source Files
- `/home/sites/sugarcraft/candy-query/src/App.php` — 415 lines, SugarCraft Model
- `/home/sites/sugarcraft/candy-query/src/Database.php` — 234 lines, PDO wrapper + export
- `/home/sites/sugarcraft/candy-query/src/SchemaBrowser.php` — 229 lines, PRAGMA introspection
- `/home/sites/sugarcraft/candy-query/src/ResultTable.php` — 461 lines, scrolling result renderer
- `/home/sites/sugarcraft/candy-query/src/ResultPager.php` — 150 lines, cursor pagination
- `/home/sites/sugarcraft/candy-query/src/Renderer.php` — 124 lines, 3-pane ANSI renderer
- `/home/sites/sugarcraft/candy-query/src/CellEditor.php` — 104 lines, cell-level UPDATE
- `/home/sites/sugarcraft/candy-query/src/SnippetStore.php` — 186 lines, JSON snippet persistence
- `/home/sites/sugarcraft/candy-query/src/ExplainView.php` — 229 lines, query plan visualizer
- `/home/sites/sugarcraft/candy-query/src/Pane.php` — 26 lines, focus enum
- `/home/sites/sugarcraft/candy-query/src/Lang.php` — 22 lines, i18n facade

### Tests
- `/home/sites/sugarcraft/candy-query/tests/AppTest.php` — 16 methods (pane cycling, query editing, history, favorites)
- `/home/sites/sugarcraft/candy-query/tests/DatabaseTest.php` — 14 methods (CRUD, CSV/SQL export/import)
- `/home/sites/sugarcraft/candy-query/tests/SchemaBrowserTest.php` — 8 methods (PRAGMA parsing)
- `/home/sites/sugarcraft/candy-query/tests/ResultTableTest.php` — 21 methods (scrolling, NULL, JSON, rendering)
- `/home/sites/sugarcraft/candy-query/tests/ResultPagerTest.php` — 12 methods (pagination, bounds)
- `/home/sites/sugarcraft/candy-query/tests/CellEditorTest.php` — 7 methods (cell/row updates)
- `/home/sites/sugarcraft/candy-query/tests/SnippetStoreTest.php` — 14 methods (CRUD, search, persistence)
- `/home/sites/sugarcraft/candy-query/tests/ExplainViewTest.php` — 10 methods (tag classification, depth)

### Examples & Demos
- `/home/sites/sugarcraft/candy-query/examples/play.php` — In-memory demo (31 lines)
- `/home/sites/sugarcraft/candy-query/.vhs/play.tape` / `play.gif` — Query execution demo
- `/home/sites/sugarcraft/candy-query/.vhs/query-history.tape` / `query-history.gif` — History cycling demo

### Configuration
- `/home/sites/sugarcraft/candy-query/composer.json` — Requires candy-core, candy-sprinkles; path repos
- `/home/sites/sugarcraft/candy-query/phpunit.xml` — Bootstrap vendor/autoload.php, colors=true
- `/home/sites/sugarcraft/candy-query/bin/candy-query` — CLI entry point (31 lines)

### Documentation
- `/home/sites/sugarcraft/candy-query/README.md` — 160 lines, architecture table, usage examples
- `/home/sites/sugarcraft/candy-query/CALIBER_LEARNINGS.md` — 4 accumulated patterns
- `/home/sites/sugarcraft/candy-query/lang/en.php` + 15 locales — i18n strings

---

## Analysis

**candy-query** is a well-structured, focused SQLite browser that ports the core lazysql workflow (browse tables, preview rows, run SQL) to PHP with the SugarCraft stack. Its architecture demonstrates several mature patterns: immutable state throughout, typed schema value objects from raw PRAGMA arrays, horizontal-scrolling result rendering, and a clean separation between the database layer and the TUI model.

**Strengths:**
- Clean SugarCraft `Model` integration — follows the `init()`/`update()`/`view()` contract precisely
- Immutable + fluent patterns throughout (ResultPager, ResultTable, SnippetStore)
- Comprehensive test coverage with `:memory:` PDO fixtures (60+ test methods)
- EXPLAIN QUERY PLAN visualization as a first-class feature
- CSV import/export and SQL dump capabilities outside the TUI
- 16-locale i18n support
- VHS demo recordings for CI regression testing

**Gaps relative to upstream:**
- SQLite-only (no multi-driver interface yet)
- No row editing TUI (CellEditor exists but not wired up)
- No pagination controls, no schema sidebar, no tabs
- No query snippet picker UI
- No row filtering or column sorting

**Strategic position:** candy-query at v1 is a solid foundation for a SQLite-focused workflow tool. The `Database` → `SchemaBrowser` split correctly separates data access from TUI concerns. The explicit TODO in the README ("promoting to an interface is a one-class job once the second driver lands") signals a clear extension path. The EXPLAIN QUERY PLAN renderer is a genuinely useful feature not found in the upstream Go project.

---

## Related Reports

- `/home/sites/sugarcraft/repo_map/jorgerojas26_lazysql.md` — Primary upstream (Go, multi-driver)
- `/home/sites/sugarcraft/repo_map/charmbracelet_bubbletea.md` — SugarCraft runtime foundation
- `/home/sites/sugarcraft/repo_map/sugarcraft_sugar-bits.md` — Component library used by the TUI
- `/home/sites/sugarcraft/repo_map/sugarcraft_candy-sprinkles.md` — Styling system
- `/home/sites/sugarcraft/repo_map/sugarcraft_sugar-skate.md` — SQLite-backed KV store (shares backend technology)
