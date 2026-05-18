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
