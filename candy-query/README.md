<img src=".assets/icon.png" alt="candy-query" width="160" align="right">

# CandyQuery

![demo](.vhs/play.gif)

Terminal SQLite browser on the SugarCraft stack — port of [`jorgerojas26/lazysql`](https://github.com/jorgerojas26/lazysql), SQLite-only at v1.

```bash
composer require candycore/candy-query
candy-query path/to/db.sqlite
```

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

| File         | Role                                                                  |
|--------------|-----------------------------------------------------------------------|
| `Database`   | Thin PDO/SQLite wrapper. Throws `RuntimeException` on missing files; promotes PDO errors to exceptions for the App's catch arm. |
| `Pane`       | Enum for pane focus + `next()`.                                       |
| `App` (Model)| Tables list, rows pane, in-progress SQL editor buffer, error string, status string. Pure-state. |
| `Renderer`   | Three rounded-border panes — tables, rows, query — with the focused pane getting a brighter accent. |

The PDO connection is the only stateful dependency; tests use a `:memory:` SQLite to exercise the full transition surface (load tables, switch panes, run query, error handling) without fixture files.

Multi-driver (MySQL / Postgres / Mongo) is a planned follow-up — the current `Database` class is a SQLite-only concrete; promoting it to an interface is a one-class job once the second driver lands.

## Test

```bash
composer install
vendor/bin/phpunit
```
