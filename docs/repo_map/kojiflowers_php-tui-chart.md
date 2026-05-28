# kojiflowers/php-tui-chart

## Metadata
- **URL:** https://github.com/kojiflowers/php-tui-chart
- **Language:** PHP
- **Stars:** Unknown (likely very few - personal hobby project)
- **License:** Unknown (no LICENSE file in repo)
- **Description:** A PHP wrapper class for generating [Toast UI Charts](https://nhnent.github.io/tui.chart/latest/tutorial-example03-01-line-chart-basic.html) - a JavaScript chart library. This wrapper generates the necessary JavaScript boilerplate from PHP, allowing developers to define chart data in PHP and emit JavaScript that renders charts in the browser.

## Feature List
- **Line Chart** - Time series / multi-series line visualization via `lineChart` type
- **Bar Chart** - Horizontal bar chart via `barChart` type
- **Column Chart** - Vertical bar chart via `columnChart` type
- **Data preparation pipeline** - Handles CSV, TSV, JSON, and plain arrays
- **Automatic data re-organization** - The `keypair` mode reorganizes row-based data into TUI Chart's series format
- **Axis labeling** - Supports custom X and Y axis titles
- **Chart dimensions** - Configurable width and height
- **Chart titles** - Optional title text
- **JSON output** - Emits JavaScript with embedded JSON data and options
- **`__toString()` magic** - Allows direct `echo $chart` output

## Key Classes and Methods

### `PhpTuiChart\Draw`
The main entry point class.

- **`__construct($type, $chart_data = array())`** — Instantiates chart of given type (`lineChart`, `barChart`, `columnChart`) and immediately renders
- **`render() : string`** — Returns finalized JavaScript for embedding in HTML page
- **`__toString() : string`** — Magic method proxying to `render()`

### `PhpTuiChart\Builder\Builder`
Core chart builder that constructs JavaScript output.

- **`__construct($type, $chart_data)`** — Processes data and assigns chart type; calls `processChartData()`, `assignChart()`, `buildChart()`
- **`buildChart() : string`** — Assembles the final `<script>` tag containing container lookup, JSON-parsed data/options, and `tui.chart.{type}()` call
- **`processChartData()`** — Triggers `buildChartData()` and `buildChartOptions()`
- **`buildChartData() : string`** — Returns categories + series as JSON
- **`buildChartDataCategories()`** — Returns raw categories array
- **`buildChartDataSeries() : array`** — Transforms input data into TUI series format; if `keypair` is true, reorganizes row-based key-value pairs into series-based structure
- **`buildChartOptions() : string`** — Merges user-provided dimensions/title/axis labels with defaults, returns as JSON
- **`assignChart()`** — Sets the TUI Chart initialization snippet: `tui.chart.{$type}(container, data, options);`

### `PhpTuiChart\Builder\DataPrep`
Trait providing data import and transformation utilities.

- **`run($data_file, $file_type = false) : bool|string`** — Router for `prepTsv()`, `prepCsv()`, `prepJson()`, `prepArray()`
- **`prepTsv($data_file) : string`** — Parses TSV file with header row into JSON array
- **`prepCsv($data_file) : string`** — Parses CSV file with header row into JSON array
- **`prepJson($data_file) : string`** — Returns raw file contents as JSON
- **`prepArray($array) : string`** — Wrapper for `json_encode()`
- **`findDataRanges($data) : array`** — Analyzes data to find low/high ranges per series (utility for auto-scaling)

## Notable Algorithms / Named Patterns

### Builder Pattern
`Builder` class orchestrates construction of complex JavaScript output. It uses a fluent-like approach internally, chaining `processChartData()` → `assignChart()` → `buildChart()`.

### Data Reorganization (Key-Pair Mode)
When `keypair => true`, the `buildChartDataSeries()` method transforms data from row-oriented format:
```php
// Input (rows per category)
[
    ['Tesla' => 20, 'Chevy' => 40, ...],
    ['Tesla' => 30, 'Chevy' => 40, ...],
]

// Output (series per brand)
[
    ['name' => 'Tesla', 'data' => [20, 30, 50]],
    ['name' => 'Chevy', 'data' => [40, 40, 60]],
    ...
]
```

### Default Options Merging
`buildChartOptions()` applies user overrides to a `$default_options` array rather than building from scratch. Pattern: merge user values into defaults.

### Magic `__toString()`
Both `Draw` and `Builder` implement `__toString()` enabling direct echo/print output of rendered JavaScript.

## Strengths
- **Simple API** — One class, one method call to generate a working chart
- **Data format flexibility** — Supports arrays, CSV, TSV, JSON file input
- **Familiar TUI Chart types** — Uses exact Toast UI Chart type names (lineChart, barChart, columnChart) making original docs applicable
- **Lightweight** — No external dependencies beyond Composer autoloader; outputs JavaScript rather than implementing rendering in PHP
- **`__toString()` magic** — Clean output pattern: `echo new Draw('lineChart', $data)`

## Weaknesses
- **No tests** — Zero test coverage; no PHPUnit or any testing framework
- **Hardcoded defaults** — `$default_options` is a protected property, not configurable from outside
- **No type safety** — No `declare(strict_types=1)`, no return types, no property types
- **Insecure JavaScript output** — Uses string concatenation for JSON embedding; `JSON.parse('{$this->chart_data_json}')` is vulnerable if JSON contains quotes or special characters
- **No validation** — No checks for required keys (`data`, `categories`) until build-time
- **Limited chart types** — Only three chart types (line, bar, column) vs. TUI Chart's full arsenal (pie, area, bubble, scatter, heatmap, treemap, map, etc.)
- **No error handling** — File operations (`fopen`, `file_get_contents`) not wrapped in try/catch
- **Global JS dependencies** — Requires external CDN links for TUI Chart JS, Raphael, and tui-code-snippet; no fallback or optional local path
- **No PSR compliance** — No `declare(strict_types=1)`, no PSR-4 autoloading (uses classmap), no namespace convention beyond simple `PhpTuiChart\*`
- **No aggregation/processing** — Cannot compute min/max, moving averages, or bucket data on the PHP side
- **Fixed aspect rendering** — No auto-scaling, no responsive behavior
- **Deprecated CDN URLs** — Uses rawgit.com which has been deprecated/shut down

## SugarCraft Mapping

### `sugar-charts`
**Direct competitor** — Both are chart libraries, but for fundamentally different environments:

| Aspect | php-tui-chart | SugarCraft/sugar-charts |
|--------|---------------|--------------------------|
| **Rendering** | Browser (HTML+JS via TUI Chart) | Terminal (ANSI/SGR escape codes) |
| **Architecture** | Single `Draw` class | Canvas/Graph primitives + chart classes |
| **Chart types** | lineChart, barChart, columnChart | Sparkline, BarChart, LineChart, Heatmap, OHLC, Scatter, Picture |
| **Data format** | Row-based or series-based arrays | Iterable arrays, `Bar` objects, `HeatPoint`, tuples |
| **Styling** | TUI Chart theme options | Sprinkles `Theme` with ANSI colors |
| **Output** | `<script>` tag with JS | ASCII/Unicode string |

**SugarCraft's `sugar-charts`** is a native PHP terminal charting library producing ANSI escape sequences. **php-tui-chart** is a PHP wrapper that generates JavaScript for a browser-based third-party charting library.

### Specific sugar-charts components that cover similar ground:
- `BarChart\BarChart` — vertical bar chart (corresponds to php-tui-chart's `columnChart`)
- `BarChart\Bar` — single bar value object (comparable to php-tui-chart's internal data normalization)
- `Canvas\Canvas` + `Canvas\Graph` — drawing primitives (php-tui-chart has none)
- `Aggregation\*` — time-series aggregation (php-tui-chart has no server-side aggregation)

**No direct port opportunity** — php-tui-chart fundamentally depends on a browser JS runtime. SugarCraft/sugar-charts is a native PHP/terminal solution. The data structure transformation logic (keypair reorganization) could inform `Aggregation` patterns, but the rendering layer cannot be mapped.

---

## Analysis

**kojiflowers/php-tui-chart** is a thin PHP wrapper that converts PHP array data into JavaScript invocations of the Toast UI Chart library. It solves the problem of generating chart boilerplate: developers define their data in PHP, call `new Draw('lineChart', $data)`, and `echo` the result to emit a `<script>` tag that renders the chart in the browser.

The architecture is intentionally simple: `Draw` is a facade that delegates to `Builder`, which transforms PHP arrays into TUI Chart's expected JSON format (`{categories: [...], series: [{name: '...', data: [...]}, ...]}`) and emits the JavaScript initialization call. The `DataPrep` trait adds file-input capabilities (CSV/TSV/JSON parsing) but these are never actually called in the normal flow — the `run()` method is defined but never invoked by `Builder`.

The library reveals its hobby-project origins: no `declare(strict_types=1)`, no tests, no Composer package metadata beyond a classmap autoloader, no error handling around file I/O, and reliance on deprecated CDN URLs for TUI Chart's JS dependencies. The `keypair` data reorganization algorithm is the most interesting piece of logic — it transposes row-oriented key-value data into TUI Chart's series format, which is the opposite transformation of what most charting APIs expect.

In the SugarCraft ecosystem, this library has no direct port value because it wraps a browser-based JS library. The closest SugarCraft analog is `sugar-charts`, which renders charts natively in the terminal using ANSI escape codes. However, php-tui-chart's data preparation patterns (CSV/TSV parsing, data range detection, keypair reorganization) could inform future `Aggregation` utilities in sugar-charts if time-series or multi-dataset processing is needed.
