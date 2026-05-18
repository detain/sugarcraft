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

---

## Testing your extension

Use `BaseModule` subclasses directly in unit tests:

```php
use SugarCraft\Dash\Modules\Clock\ClockModule;
use SugarCraft\Core\Msg\TickMsg;

$module = new ClockModule(showDate: true);
$this->assertSame('clock', $module->name());
$this->assertSame([20, 5], $module->minSize());

// Drive update cycle
[$next, $cmd] = $module->update(Msg::tick());
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
