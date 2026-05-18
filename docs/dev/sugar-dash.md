# sugar-dash — Developer Guide

`sugar-dash` provides extension points for custom dashboard modules,
the plugin system with JSON protocol, and the module registry.

---

## Extension points

### Module interface (`Module`)

The `Module` interface extends `SugarCraft\Core\Model`, aligning with
the Elm-architecture pattern used throughout SugarCraft:

```php
use SugarCraft\Dash\Module\Module;
use SugarCraft\Core\Msg;

interface Module extends Model
{
    public function name(): string;
    public function init(): ?\Closure;             // invoked once; may return startup Cmd
    public function update(Msg $msg): array{0: Module, 1: ?\Closure};  // returns [nextModule, ?Cmd]
    public function view(): string;
    public function minSize(): array{0: int, 1: int}; // [width, height]
}
```

**Update return type:** `update(Msg): array{0:Module,1:?Cmd}` — PHP lacks
tuple types, so destructure with `[$module, $cmd] = $module->update($msg)`.
When `$cmd` is not `null`, call it to produce the next `Msg` to process.

### BaseModule abstract class

`BaseModule` provides sensible defaults for all `Module` methods. Subclasses
only need to implement `name()`, `update()`, and `view()`.

```php
use SugarCraft\Dash\Module\BaseModule;
use SugarCraft\Core\Msg;

final class MyModule extends BaseModule
{
    public function name(): string
    {
        return 'my-module';
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        // Return [nextModule, ?Cmd] — use withState() for immutable state updates
        return [$this->withState(['counter' => ($this->getState()['counter'] ?? 0) + 1]), null];
    }

    public function view(): string
    {
        return 'Counter: ' . ($this->getState()['counter'] ?? 0);
    }
}
```

#### `withState()` pattern

`BaseModule::withState(array $overrides): static` merges state overrides
into the current state and returns a new instance. It uses `clone` + direct
property mutation (avoids the `readonly`-property-wither trap):

```php
// Inside BaseModule subclass update():
return [$this->withState(['key' => 'new value']), null];

// Access current state:
$state = $this->getState(); // array<string, mixed>
```

### LegacyModule (deprecated)

`LegacyModule` is the old array-state interface kept for one release to
avoid breaking third-party modules. New code should implement `Module`.

```php
interface LegacyModule
{
    public function name(): string;
    public function init(): array;              // returns metadata array
    public function update(array $state): array;
    public function view(array $state, int $width, int $height): string;
    public function minSize(): array{0: int, 1: int};
}
```

**LegacyModuleAdapter** wraps a `LegacyModule` to satisfy the `Module`
contract, enabling the `Registry` to host both old and new modules:

```php
use SugarCraft\Dash\Module\LegacyModuleAdapter;

$adapted = LegacyModuleAdapter::from(fn(): LegacyModule => new MyLegacyModule());
// $adapted implements Module
```

### Module Registry

`Registry` provides dynamic module instantiation by name:

```php
use SugarCraft\Dash\Registry\Registry;

// Register a module (auto-detects legacy vs new-style)
Registry::register('my-module', fn(): Module => new MyModule());

// Get a module constructor by name
$ctor = Registry::get('my-module');
$module = $ctor();

// Check and list
Registry::has('my-module');        // bool
Registry::list();                   // list<string>

// Reset (useful for testing)
Registry::reset();
```

### Plugin system

The `Plugin` namespace provides a JSON-based plugin protocol:

```php
use SugarCraft\Dash\Plugin\Request;
use SugarCraft\Dash\Plugin\Response;
use SugarCraft\Dash\Plugin\PluginSdk;

$sdk = new PluginSdk();
$sdk->run();  // reads Request from stdin, writes Response to stdout
```

`ExternalModule` wraps a binary command as a `Module`:

```php
use SugarCraft\Dash\Plugin\ExternalModule;

$ext = ExternalModule::fromCommand('my-dashboard-module --width %d --height %d');
// $ext implements Module
```

### WeatherModule — built-in module example

`Modules\Weather\WeatherModule` demonstrates a production-ready `Module`
implementation with network fetch, caching, and graceful degradation.

#### Architecture

```
WeatherModule
├── HttpClient (interface — fetch(string $location): WeatherSnapshot)
│   └── WttrInClient (implements HttpClient; hits https://wttr.in/<loc>?format=j1)
├── WeatherSnapshot (readonly DTO: tempC, condition, location, fetchedAt)
└── TickMsg (internal Msg; 30-minute refresh interval)
```

The module ticks every 30 minutes (`Cmd::tick(1800.0, ...)`). On each
`TickMsg`, `fetchWeather()` is called:

1. **Cache check** — read `~/.cache/sugar-dash/weather.json`. If the cached
   snapshot is younger than 1800 s (TTL), return it immediately.
2. **Live fetch** — call `$httpClient->fetch($location)` (default `"auto"`,
   IP-based detection). On success, write the snapshot to the cache atomically
   (temp file + `rename`).
3. **Fallback** — if the live fetch throws a `RuntimeException` and a stale
   cache exists, return the stale cache (no upper age limit — network outages
   are served stale indefinitely). If no cache exists, re-throw.

The `view()` method renders `"{temp}°C {condition}\n{location}"` or
`"—°C unavailable"` when no data is available at all.

#### WEATHER_LOCATION environment variable

Set `WEATHER_LOCATION` in the environment to override the default IP-based
detection. Examples: `"Seattle"`, `"London"`, `"37.7749,-122.4194"`
(coordinates), `"~Tokyo"` (airport code):

```bash
WEATHER_LOCATION=Seattle php examples/dashboard-live.php
```

The value is passed directly to the `WeatherModule` constructor as the
`$location` argument. The `WttrInClient` URL-encodes it via
`rawurlencode()` before appending to `https://wttr.in/`.

#### Cache location

`WeatherModule::cachePath()` returns
`"~/.cache/sugar-dash/weather.json"`. The directory is created with
`mkdir(..., 0755, true)` on first write. Override in tests by subclassing
`WeatherModule` and overriding `cachePath()` to return a temporary path.

#### Extending or replacing the HTTP client

Swap the HTTP layer without touching `WeatherModule`:

```php
use SugarCraft\Dash\Modules\Weather\WeatherModule;
use SugarCraft\Dash\Modules\Weather\HttpClient;
use SugarCraft\Dash\Modules\Weather\WeatherSnapshot;

final class MockWeatherClient implements HttpClient
{
    public function fetch(string $location): WeatherSnapshot
    {
        return new WeatherSnapshot(
            tempC: 22.0,
            condition: 'Partly cloudy',
            location: $location,
            fetchedAt: new \DateTimeImmutable(),
        );
    }
}

// In your model:
$weather = new WeatherModule(new MockWeatherClient(), 'test-location');
```

`HttpClient::fetch()` throws `RuntimeException` on network failure; the module
catches it and falls back to cache.

### NotificationQueue — dual-ring pattern

`Components\Toast\NotificationQueue` implements a dual-ring queue per
Homedash pattern:

```
items[max 20]  ── active, dismissable ring
history[max 50] ── append-only ring
```

At small max sizes, two plain PHP arrays with `array_shift`/`[]=` achieve
O(1) amortized push and O(1) dismiss — no true ring buffer required.
Do NOT use `list` type hint (PHP 8.4 only — project is PHP 8.3); use `array`
with `@var list<T>` doc annotation.

#### Core operations

```php
use SugarCraft\Dash\Components\Toast\{NotificationQueue, Notification, Level};

// Push a notification onto the active ring
$queue = NotificationQueue::new()
    ->push(Notification::info('System online'))
    ->push(Notification::warning('CPU > 80%', title: 'High Load'))
    ->push(Notification::error('Disk full', title: 'Storage Alert'));

// Peek at the head (oldest active notification)
$current = $queue->current();  // Notification|null

// Dismiss the head — moves it to history
$queue = $queue->dismiss();    // returns same instance if items is empty

// Fetch recent history (newest-first)
$recent = $queue->recent(5);   // list<Notification>

// All active items / all history
$all = $queue->all();          // list<Notification>
$hist = $queue->history();    // list<Notification>, oldest-first
```

#### Capacity eviction

When `items` is at capacity (20), pushing a new notification evicts the
oldest item into `history` before adding the new one. When `history` exceeds
50, its oldest entry is evicted. Both rings are bounded independently.

```php
// Adjust ring sizes
$queue = (new NotificationQueue(maxItems: 10, maxHistory: 25))
    ->push(Notification::info('msg'));

// Clone with different limits
$queue = $queue->withMaxItems(5)->withMaxHistory(100);
```

#### Level enum

`Level` is a PHP 8.1 enum with four cases:

```php
use SugarCraft\Dash\Components\Toast\Level;

Level::Info->icon();          // 'ℹ'
Level::Warning->icon();       // '⚠'
Level::Error->icon();        // '✖'
Level::Success->icon();      // '✓'

Level::Error->isError();    // true
Level::Warning->isHighlighted();  // true (Warning|Error)
Level::Info->isHighlighted();   // false
```

#### Toast adapters

`Toast` provides two factory adapters to bridge the Notification DTO into
styled toast output:

```php
use SugarCraft\Dash\Components\Toast\{Toast, NotificationQueue, Notification};

// From a single notification
$toast = Toast::fromNotification(Notification::warning('Check the logs'));

// From a queue — renders the current head, or null if queue is empty
$queue = NotificationQueue::new()->push(Notification::success('Done!'));
$toast = Toast::fromQueue($queue);  // Toast|null
```

Each `Level` maps to a distinct colour scheme (Info → blue, Warning → amber,
Error → red, Success → green). The adapter preserves the `title` if set.

### Breakpoint — responsive layout helper

`Layout\Breakpoint` provides four static methods for responsive layout
decisions. Default thresholds (90 / 140) are the Homedash convention values.

| Method | Behaviour | Defaults |
|--------|-----------|----------|
| `narrow(int $width, int $threshold = 90): bool` | `true` when width is below threshold | threshold 90 |
| `medium(int $width, int $narrow = 90, int $wide = 140): bool` | `true` when narrow ≤ width < wide | narrow 90, wide 140 |
| `wide(int $width, int $threshold = 140): bool` | `true` when width ≥ threshold | threshold 140 |
| `pick(int $width, array $thresholds): string` | Returns first bucket whose bound exceeds `$width`; last `null`-valued entry is catch-all | — |

#### Basic usage

```php
use SugarCraft\Dash\Layout\Breakpoint;

if (Breakpoint::narrow($width)) {
    // Collapse multi-column to single-column
}

if (Breakpoint::medium($width)) {
    // Standard terminal size
}

if (Breakpoint::wide($width)) {
    // Multi-column side-by-side fits
}
```

#### Generic pick

```php
$bucket = Breakpoint::pick($width, [
    'narrow' => 90,
    'medium' => 140,
    'wide'   => null,  // catch-all
]);

// $bucket === 'narrow' at width < 90
// $bucket === 'medium' at 90 ≤ width < 140
// $bucket === 'wide'   at width ≥ 140
```

#### StackedGrid responsive collapse

`StackedGrid::render()` automatically collapses to a single column when
`Breakpoint::narrow($this->width)` is `true` — all items from all columns
are concatenated vertically. This keeps layouts readable on small terminals
without any additional wiring in the calling code.

```php
use SugarCraft\Dash\Layout\Grid\{StackedGrid, Options, ItemOptions};
use SugarCraft\Dash\Foundation\Item;

$grid = new StackedGrid(new Options(fitScreen: true));

// Add items to two columns
$grid->addItem($leftPanel,  new ItemOptions(column: 0));
$grid->addItem($rightPanel, new ItemOptions(column: 1));

$grid->setSize(80, 24);   // narrow → single column
$grid->setSize(140, 24);  // wide → two columns side-by-side
```

Override the collapse threshold by passing a second argument:

```php
if (Breakpoint::narrow($width, threshold: 100)) { ... }
```

---

## Testing your extension

Use `BaseModule` subclasses directly in unit tests:

```php
use SugarCraft\Dash\Modules\Clock\ClockModule;
use SugarCraft\Dash\Modules\Clock\TickMsg;
use SugarCraft\Core\Msg;

$module = new ClockModule(showDate: true);
$this->assertSame('clock', $module->name());
$this->assertSame([20, 5], $module->minSize());

// Drive update cycle
[$next, $cmd] = $module->update(new TickMsg());
$this->assertInstanceOf(ClockModule::class, $next);

// Generic Msg also works (module checks instanceof TickMsg internally)
[$next, $cmd] = $module->update(new class implements Msg {});
$this->assertInstanceOf(ClockModule::class, $next);
```

For the `Registry`, call `Registry::reset()` in test teardown to avoid
cross-test pollution:

```php
protected function tearDown(): void
{
    Registry::reset();
    parent::tearDown();
}
```

---

## Versioning policy

| Surface | Stability |
|---------|------------|
| `Module`, `BaseModule`, `LegacyModule`, `LegacyModuleAdapter` | Stable |
| `Registry` | Stable |
| `Plugin\*` | Stable |
| `Modules\*` (built-in modules) | Stable |
| `@internal` classes | Not covered by semver |

---

## Foundation namespace — dual-SSOT primitives

`SugarCraft\Dash\Foundation\*` carries the inline-termui-derived primitives
sugar-dash uses internally. Most of these are **intentionally distinct**
from same-named canonical types elsewhere in the monorepo — different
upstream lineage means different API shapes.

| Dash Foundation | Canonical sibling | Status | Why distinct |
|-----------------|-------------------|--------|--------------|
| `Foundation\Color` | `\SugarCraft\Core\Util\Color` | **Alias** — same class via `class_alias` | True duplicate; sugar-dash now redirects via shim. Prefer the Core FQN in new code. |
| `Foundation\Style` | `\SugarCraft\Sprinkles\Style` | Both canonical | Dash carries `toAnsi(ColorProfile)` + public `?Color $foreground/$background`; Sprinkles carries lipgloss padding/margin/borders + private `$fg/$bg`. Consumers access `$style->foreground->r` on the Dash shape. |
| `Foundation\Theme` | `\SugarCraft\Sprinkles\Theme` | Both canonical | Dash has 10 colour slots + `bar()/text()/fg()/bg()/color()/highlight()` helpers; Sprinkles has 13 colour slots (adds muted/info/border/separator/cursor) with readonly properties only. |
| `Foundation\Rect` | `\SugarCraft\Core\Rect` | Both canonical | Dash uses the rectmath bounds model (`minX, minY, maxX, maxY`); Core\Rect uses the ratatui offset+size model (`x, y, width, height`). Choose by upstream semantics. |
| `Foundation\Buffer` | `\SugarCraft\Vt\Buffer\Buffer` | Both canonical | Dash Buffer is an immutable ANSI renderer (`Sizer`/`Drawable`); Vt Buffer is a mutable VT-output grid for terminal emulation. |
| `Foundation\Cell` | `\SugarCraft\Vt\Cell\Cell` | Both canonical | Dash Cell holds `(rune, Style)`; Vt Cell holds `(grapheme, Sgr, continuation, hyperlink)`. |
| `Foundation\StyleParser` | `\SugarCraft\Sprinkles\StyleParser` | Both canonical | Parses the same `[text](fg:red,bg:blue)` syntax, but produces Dash `Cell/Style` (which expose public `?Color $foreground`). NOT drop-in compatible with `Sprinkles\StyleParser`. |

**Implication for extension authors.** When implementing a custom `Module`
or wrapping a sugar-dash component, use the `SugarCraft\Dash\Foundation\*`
imports — not the Sprinkles/Core/Vt siblings. Type signatures across
sugar-dash assume the Dash shapes. The exception is `Color`, which is
the same class via alias and can be imported from either namespace.

Background: surfaced during the canonical-primitives audit (step 03.05).
See `sugar-dash/CALIBER_LEARNINGS.md` entries
`[pattern:dual-foundation-ssot]`, `[pattern:dual-style-ssot]`,
`[pattern:dual-theme-ssot]`, `[pattern:dual-rect-models]`,
`[pattern:dual-buffer-roles]`, `[pattern:dual-cell-shapes]` for the full
investigation log.

---

## Interactive dashboard example (`examples/dashboard-live.php`)

`sugar-dash/examples/dashboard-live.php` is the canonical end-to-end example
of the `Module` + `Core\Model` + `Program` stack. It wires together:

| Component | Role |
|-----------|------|
| `DashboardModel` implements `Model` | Root model driven by `Program`; holds modules, `FocusManager`, `Boxer` |
| `Boxer` | Address-tree layout engine; `Node::horizontal()` assembles panels |
| `FocusManager` | Tracks which panel has keyboard focus; `focusNext()`/`focusPrevious()` for Tab/arrow rotation |
| `ClockModule` / `SystemModule` / `WeatherModule` | Three panels each implementing `Module` (init/update/view) |
| `ProgramOptions` | `useAltScreen`, `catchInterrupts`, `hideCursor`, `openTty` for TTY session |
| `Cmd::tick` | Per-panel periodic refresh: Clock at 1Hz, System at 2Hz, Weather at 30min |

### Message routing

`DashboardModel::update()` uses `msgToAddress()` to direct tick Msgs to the
correct module:

```php
private function msgToAddress(Msg $msg): ?string
{
    return match (true) {
        $msg instanceof ClockTickMsg => '0',
        $msg instanceof SystemRefreshMsg => '1',
        $msg instanceof WeatherTickMsg => '2',
        default => null,  // broadcast to all
    };
}
```

Unknown Msgs (or `WindowSizeMsg`) are **broadcast** to all modules — they
each return `[self, null]` if they don't handle the msg.

### Keyboard handling

`handleKey()` intercepts `KeyMsg` before routing:

- `q` / `Ctrl-C` → return `false` so `update()` handles `QuitMsg`/`InterruptMsg`
- `Tab` / `Shift+Tab` → `FocusManager::focusNext()`/`focusPrevious()`
- `Up/Down/Left/Right` → `FocusManager::focusNext()` (all four cycle)

`DashboardModel` does **not** keep its own tick — it just routes tick Cmds
from the individual modules.

### Running the example

```bash
php examples/dashboard-live.php
```

The example checks `stream_isatty(STDOUT)` and exits cleanly in CI/pipe
environments rather than blocking on TTY input.
