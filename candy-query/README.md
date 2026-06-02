<img src=".assets/icon.png" alt="candy-query" width="160" align="right">

# CandyQuery

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-query)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-query)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/candy-query?label=packagist)](https://packagist.org/packages/sugarcraft/candy-query)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.1-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->


![demo](.vhs/play.gif)

Terminal SQLite browser on the SugarCraft stack — port of [`jorgerojas26/lazysql`](https://github.com/jorgerojas26/lazysql), SQLite-only at v1.

```bash
composer require sugarcraft/candy-query
```

## CLI usage

```bash
# SQLite via bare path (inferred as Flavor::Sqlite)
bin/candy-query app.sqlite

# Any DSN — MySQL, PostgreSQL, SQLite, SQLSRV
bin/candy-query --dsn mysql://user:pass@localhost:3306/dbname
bin/candy-query --dsn pgsql://user:pass@localhost:5432/dbname
bin/candy-query --dsn sqlsrv://localhost/dbname

# Explicit SQLite path via DSN
bin/candy-query --dsn sqlite:///absolute/path/to/db.sqlite
```

`App::start(database, ?Flavor $flavor = Flavor::Sqlite)` accepts an optional `$flavor` to override auto-detection.

## Keys

| Key                | Action                                          |
|--------------------|-------------------------------------------------|
| `Tab`              | Switch pane (Tables → Rows → Query)             |
| `↑/↓` or `j/k`     | Move cursor in active list pane                 |
| `Enter` / `Space`  | Load the highlighted table into the rows pane   |
| `Ctrl+R`           | Run the SQL in the query pane                   |
| `Backspace`        | Delete last character (query pane)              |
| `q` / `Esc`        | Quit (except inside the query pane — `q` types) |

## Architecture

| File              | Role                                                                                          |
|-------------------|----------------------------------------------------------------------------------------------|
| `ConnectionConfig`| Readonly value object: driver, host, port, user, pass, dbname, sslMode, dsn. Pass never echoed. |
| `ConnectionFactory`| Static factory: `fromDsn()`, `fromConfig()`, `fromArgv()`. Builds configured connections.       |
| `Flavor`          | Enum: `MySQL`, `MariaDB`, `Percona`, `Postgres`, `Sqlite`. Used to identify database flavor.    |
| `Version`         | Parser for server version strings. Handles MariaDB `5.5.5-` prefix. `isAtLeast(Version)` compares versions. |
| `Database`        | ⚠️ Deprecated thin alias to `SqliteDatabase`. Use `DatabaseInterface` for driver-agnostic code.   |
| `MysqlDatabase`   | `DatabaseInterface` implementation via PDO `mysql`. Implements `serverVersion()`, `driverName()`, `ping()`, `databases()`. |
| `PostgresDatabase`| `DatabaseInterface` implementation via PDO `pgsql`. Implements `serverVersion()`, `driverName()`, `ping()`, `databases()`. |
| `Pane`            | Enum for pane focus + `next()`.                                                              |
| `App` (Model)      | Tables list, rows pane, in-progress SQL editor buffer, error string, status string.         |
| `Renderer`        | Three rounded-border panes — tables, rows, query — with the focused pane getting a brighter accent. |
| `SchemaBrowser`   | Schema introspection via strategy pattern — delegates to driver-specific `SchemaProviderInterface` implementation based on `Flavor`. |
| `SchemaProviderInterface` | Interface for driver-specific schema introspection. Implement `tables()`, `columns()`, `indexes()`, `foreignKeys()`. |
| `SqliteSchemaProvider` | `SchemaProviderInterface` via PRAGMA queries (tables, columns, indexes, foreign keys). |
| `MysqlSchemaProvider` | `SchemaProviderInterface` via `INFORMATION_SCHEMA` queries. |
| `PostgresSchemaProvider` | `SchemaProviderInterface` via `pg_catalog` + `information_schema` queries. |
| `ResultPager`     | Cursor-based pagination for SQL result sets. Immutable + fluent `nextPage()`/`prevPage()`. |
| `CellEditor`       | Cell-level UPDATE by primary-key identity. `updateCell()`, `updateRow()`, `readCell()`.     |
| `SnippetStore`   | File-backed JSON store for named SQL snippets. Immutable + fluent `add()`/`delete()`/`find()`/`search()`. Persists to `/tmp/candy-query-snippets.json`. |
| `ExplainView`     | Renders `EXPLAIN` output as a colour-coded ANSI tree. Uses strategy pattern — delegates to driver-specific `ExplainProviderInterface` based on `Flavor`. |
| `ExplainProviderInterface` | Interface for driver-specific EXPLAIN parsing. Implement `explain(pdo, sql)` returning a list of explain rows. |
| `SqliteExplainProvider` | `ExplainProviderInterface` via `EXPLAIN QUERY PLAN`. Parses tree prefixes (`|--`, `` `-- ``) for depth. |
| `MysqlExplainProvider` | `ExplainProviderInterface` via `EXPLAIN`. Returns `EXPLAIN` formatted rows with tag/parent/detail/indent. |
| `PostgresExplainProvider` | `ExplainProviderInterface` via `EXPLAIN (ANALYZE, FORMAT JSON)`. Parses JSON structure for tree hierarchy. |
| `ResultTable`    | Renders SQL result sets with horizontal scrolling, JSON pretty-print (2-space indent), styled NULL token, and column auto-sizing. `scrollLeft()`/`scrollRight()` builders. |
| `ServerStatusPage` | Admin page displaying server info, features, directories, SSL, replication, and firewall panels. `r` refresh, `q` quit. |
| `ServerInfoCard`    | Info card with host, socket, port, version, uptime (computed to running-since). |
| `ReplicaStatusProvider` | Fetches replica status via `SHOW REPLICA STATUS` (MySQL 8+) or `SHOW SLAVE STATUS` (MySQL 5.x/MariaDB), graceful 1227 handling. |

The PDO connection is the only stateful dependency; tests use a `:memory:` SQLite to exercise the full transition surface (load tables, switch panes, run query, error handling) without fixture files.

Multi-driver support is now available via `DatabaseInterface`. `CsvExporter` and `SqlExporter` provide driver-agnostic export.

## Connection factory

`ConnectionFactory` provides three static methods to build a configured `PDO` connection:

```php
use SugarCraft\Query\Db\ConnectionFactory;
use SugarCraft\Query\Db\ConnectionConfig;

// From a DSN string
$pdo = ConnectionFactory::fromDsn('mysql://user:pass@localhost:3306/dbname?ssl-mode=REQUIRED');

// From a ConnectionConfig value object
$config = new ConnectionConfig(
    driver: 'mysql',
    host: 'localhost',
    port: 3306,
    user: 'user',
    pass: 'secret',
    dbname: 'dbname',
    sslMode: 'REQUIRED',
);
$pdo = ConnectionFactory::fromConfig($config);

// From command-line arguments (--db-driver, --db-host, --db-port, --db-user, --db-pass, --db-name, --db-ssl-mode)
$pdo = ConnectionFactory::fromArgv();
```

### DSN format

```
driver://user:pass@host:port/dbname?ssl-mode=MODE
```

| Part     | Description                          |
|----------|--------------------------------------|
| driver   | `mysql`, `pgsql`, `sqlite`, `sqlsrv` |
| user     | Database username                    |
| pass     | Database password (never echoed)      |
| host     | Server hostname or IP                |
| port     | Server port (default varies by driver)|
| dbname   | Database name                        |
| ssl-mode | Driver-specific SSL mode             |

`ConnectionConfig` is a readonly value object with 8 properties: `driver`, `host`, `port`, `user`, `pass`, `dbname`, `sslMode`, `dsn`.

## Schema introspection

`SchemaBrowser` uses a strategy pattern based on `Flavor` to delegate schema introspection to the appropriate `SchemaProviderInterface` implementation:

```php
use SugarCraft\Query\Schema\SchemaBrowser;
use SugarCraft\Query\Db\Flavor;

// Auto-detect flavor from PDO driver and use correct provider
$browser = (new SchemaBrowser($pdo))->refresh();

// Or specify explicitly
$browser = SchemaBrowser::forFlavor(Flavor::Sqlite, $pdo);
$browser = SchemaBrowser::forFlavor(Flavor::MySQL, $pdo);
$browser = SchemaBrowser::forFlavor(Flavor::Postgres, $pdo);

foreach ($browser->tables as $table) {
    echo $table->name;
    foreach ($table->columns as $col) {
        echo $col->name, ' ', $col->type, $col->primaryKey ? ' PK' : '';
    }
}
```

Available providers:
- **`SqliteSchemaProvider`** — PRAGMA queries (`table_info`, `index_list`, `foreign_key_list`)
- **`MysqlSchemaProvider`** — `INFORMATION_SCHEMA` queries
- **`PostgresSchemaProvider`** — `pg_catalog` + `information_schema` queries

Schema value objects (`SchemaTable`, `SchemaColumn`, `SchemaIndex`, `SchemaForeignKey`) are all `readonly` classes with bare accessors.

## Pagination

`ResultPager` wraps a full result-set and provides cursor-based navigation:

```php
$pager = new ResultPager($allRows, pageSize: 25);
while ($pager->hasNextPage()) {
    $pageRows = $pager->page();   // list of row arrays
    // process $pageRows
    $pager = $pager->nextPage();  // new immutable instance
}
```

`goToPage(1-based-int)`, `prevPage()`, and `withPageSize(int)` are also available. Page size defaults to 25 rows.

## Cell editing

`CellEditor` targets a table + primary-key column at construction, then performs single-cell or multi-cell updates by row identity:

```php
$editor = new CellEditor($pdo, 'users', 'id');
$editor->updateCell(rowId: 42, column: 'email', newValue: 'new@example.com');
$editor->updateRow(rowId: 42, cells: ['name' => 'Alice', 'email' => 'alice@example.com']);
$current = $editor->readCell(rowId: 42, column: 'email');
```

All identifiers are safely quoted via `str_replace('"', '""', $name)`. `updateCell` and `updateRow` return rows affected (0 or 1).

## Snippet store

`SnippetStore` persists named SQL snippets to a JSON file, surviving process restarts:

```php
$store = SnippetStore::load();                    // load from /tmp/candy-query-snippets.json
$store = $store->add('active-users', 'SELECT * FROM users WHERE active = 1', 'Fetch active users');
$store->flush();                                   // persist to disk

$store->find('active-users');                     // → Snippet|null
$store->search('users');                           // → list<Snippet> (name or SQL match)
$store->delete('active-users')->flush();          // remove and persist
```

`Snippet(name, description, sql, createdAt)` is a plain readonly value object. The store is immutable — every mutation returns a new instance.

## Query plan viewer

`ExplainView` uses a strategy pattern based on `Flavor` to delegate EXPLAIN parsing to the appropriate `ExplainProviderInterface` implementation:

```php
use SugarCraft\Query\QueryPlan\ExplainView;
use SugarCraft\Query\Db\Flavor;

// Auto-detect driver from PDO and use correct provider
$view = ExplainView::run($pdo, 'SELECT * FROM users JOIN orders ON users.id = orders.user_id');
echo $view->render();   // ANSI coloured tree
print_r($view->toArray());  // JSON-serialisable structure

// Or specify flavor explicitly
$view = ExplainView::forFlavor(Flavor::MySQL, $pdo);
$view = ExplainView::forFlavor(Flavor::Postgres, $pdo);
$view = ExplainView::forFlavor(Flavor::Sqlite, $pdo);
```

Each detail line is classified by op type: `SEARCH` (cyan), `SCAN` (yellow), `USING` (green), `JOIN` (purple), `SUBQUERY` (pink), `COMPOUND` (orange).

Available providers:
- **`SqliteExplainProvider`** — `EXPLAIN QUERY PLAN`, parses tree prefixes (`|--`, `` `-- ``) for depth
- **`MysqlExplainProvider`** — `EXPLAIN` formatted rows with tag/parent/detail/indent
- **`PostgresExplainProvider`** — `EXPLAIN (ANALYZE, FORMAT JSON)`, parses JSON structure for tree hierarchy

## Result table

`ResultTable` renders a SQL result set with horizontal scrolling, JSON pretty-print, and a styled NULL token:

```php
$table = ResultTable::fromRows($rows)
    ->withVisibleWidth(80)
    ->withJsonPretty(true)
    ->withNullToken('∅');

echo $table->render();       // ANSI coloured
echo $table->renderPlain();  // plain text for copy/export

if ($table->canScrollRight()) {
    $table = $table->scrollRight();
}
```

Columns auto-size to the widest value; cells exceeding `maxCellWidth` (default 40) are truncated with `…`. Array/object values are JSON-encoded with 2-space indent when `visibleWidth >= 80`, collapsed to a single line otherwise.

## Server Status page

`ServerStatusPage` displays a comprehensive overview of the MySQL/MariaDB server:

| Panel | Content |
|-------|---------|
| **Info Card** | Host, socket, port, MySQL version, uptime (formatted as duration + running-since timestamp) |
| **Features** | InnoDB, SSL, Fulltext, Events, Stored Programs, Partitioning, X Plugin — tristate: Yes/No/Unknown |
| **Directories** | datadir, tmpdir, log_error, pid_file |
| **SSL** | have_ssl, ssl_cipher, tls_version, ssl_ca, ssl_cert, ssl_key |
| **Replication** | Master Host/Port, IO/SQL Running state, Seconds Behind, Relay Log File/Pos — via `ReplicaStatusProvider` |
| **Firewall** | AWS RDS firewall status (Aurora_lwm sentinel) |

```php
use SugarCraft\Query\Admin\ServerStatus\ServerStatusPage;
use SugarCraft\Query\Admin\ServerContextInterface;

$page = ServerStatusPage::new($context);
echo $page->render();
```

`ReplicaStatusProvider` uses `SHOW REPLICA STATUS` on MySQL 8+ and `SHOW SLAVE STATUS` on MySQL 5.x/MariaDB, gracefully handling error 1227 (REPLICATION CLIENT privilege denied).

## Demos

### SQL query execution

![play](.vhs/play.gif)

### Query history cycling

![query-history](.vhs/query-history.gif)

## DatabaseInterface

`App` depends on `DatabaseInterface` rather than a concrete PDO/SQLite implementation. This decouples the UI from the database driver, enabling MySQL and Postgres support without changing application logic.

The interface defines 11 methods:

| Method | Description |
|--------|-------------|
| `tables()` | List all tables/views |
| `rows()` | Fetch rows from a table |
| `query()` | Execute a SQL query |
| `lastInsertId()` | Return the last insert ID |
| `quote()` | Quote a string for safe SQL |
| `exec()` | Execute SQL without results |
| `close()` | Close the connection |
| `serverVersion()` | Get database server version |
| `driverName()` | Get the driver name (e.g., `sqlite`, `mysql`) |
| `ping()` | Check connection is alive |
| `databases()` | List available databases |

### Deprecated: `Database` class

`src/Database.php` is deprecated — it is a thin alias to `SqliteDatabase`. New code should use `DatabaseInterface` directly:

```php
use SugarCraft\Query\Db\DatabaseInterface;

// Type-hint against the interface for driver-agnostic code
function processDb(DatabaseInterface $db): void { ... }
```

### Exporters

`Db\Export\CsvExporter` and `Db\Export\SqlExporter` provide driver-agnostic export:

```php
use SugarCraft\Query\Db\Export\CsvExporter;
use SugarCraft\Query\Db\Export\SqlExporter;

$csv = (new CsvExporter($db))->export('users');
$sql = (new SqlExporter($db))->export('users');
```

To add MySQL or Postgres support, implement `DatabaseInterface` and pass your implementation to `App::builder()->withDb($yourImpl)->build()`.

## Test

```bash
composer install
vendor/bin/phpunit
```
