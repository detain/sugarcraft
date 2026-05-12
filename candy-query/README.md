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

## Demos

### SQL query execution

![play](.vhs/play.gif)

### Query history cycling

![query-history](.vhs/query-history.gif)

## Test

```bash
composer install
vendor/bin/phpunit
```
