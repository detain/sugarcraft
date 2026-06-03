# Caliber Learnings

Accumulated patterns and anti-patterns from development sessions.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.

<!-- Add learnings here as they are discovered during development -->

## Step 1.2: Provider Interface and Message Classes

### `final readonly` DTOs

Using `final readonly class` for all request/response DTOs enforces immutability at the type level.
The constructor sets all properties as `public`, eliminating boilerplate accessors while maintaining
`readonly` semantics. This pattern is cleaner than traditional getters for simple data containers.

```php
final readonly class CompleteRequest
{
    public function __construct(
        public string $model,
        public array $messages,
        public ?array $tools = null,
        // ...
    ) {}
}
```

### `toArray()` for Serialization

Message classes implement `toArray()` to produce provider-compatible arrays. This separates
serialization logic from the message domain object, allowing messages to remain pure while
API adapters handle translation. The `toArray()` method uses `snake_case` keys (`tool_calls`,
`tool_call_id`, `is_error`) matching OpenAI's API convention.

### `\Generator` for Streaming

The `completeStream()` method returns `\Generator` rather than `\Iterator` or an array.
Generators are memory-efficient for streaming responses — they yield chunks as they arrive
without buffering the entire response. Callers iterate with `foreach` and each iteration
represents a partial response token.

### Nullable Fields Without Paired Sentinel

Unlike the `candy-sprinkles/src/Style.php` pattern with paired `$XSet` booleans, these DTOs
use PHP's native nullable types (`?string`, `?array`). The trade-off is accepting `null`
as a valid state rather than distinguishing "not set" from "explicitly set to null."

### Doc-Block for Enum-like String Literals

The PHPDoc `@param 'input'|'output' $direction` documents string literal types that PHP 8.3
cannot yet enforce via enum. This provides IDE autocompletion and type checking via static
analysis tools while maintaining broad PHP version compatibility.

### PHP 8.3 `readonly` Properties

All DTOs use PHP 8.3's `readonly` feature rather than `private` with getter methods.
This makes property access direct (`$request->model`) rather than method-based
(`$request->getModel()`), reducing indirection while the `final` modifier prevents
extension that might add mutable state.

## Step 1.3: Tool Interface, ToolCall, and ToolResult

### Tool Interface as Contract

The `Tool` interface serves as a contract between the agent and tool implementations.
Each tool is responsible for validating its own input — the interface method `inputSchema()`
provides machine-readable validation rules that can drive both AI tool selection and
runtime validation.

### Input Schema Consistency

BuiltIn tools follow OpenAI's tool schema format for `inputSchema()`, using `snake_case`
property names (`file_path`, `old_string`, `new_string`). This consistency allows the
schema to be passed directly to AI providers that support tool calling.

### Error Handling via Value Objects

Tool implementations return `ToolResult` with `isError: true` rather than throwing exceptions.
This keeps the execution flow predictable and allows the caller to decide how to surface
errors. The `toolCallId` field enables correlating results with the original tool calls.

### bash -c with escapeshellarg

The `Bash` tool uses `exec("bash -c " . escapeshellarg($command), ...)` pattern. This:
1. Allows shell syntax (pipes, redirects, variables) to work as expected
2. Prevents command injection by escaping the entire command string
3. Uses `bash` explicitly rather than relying on `/bin/sh` for consistency

### set_error_handler for File Operations

The `Read` tool uses `set_error_handler()` to convert PHP's error events (warnings for
permission issues, missing files) into exceptions that can be caught and converted to
`ToolResult` with appropriate error messages. Remember to call `restore_error_handler()`
in both success and failure paths.

### URL Validation for WebFetch

`WebFetch` validates that URLs start with `http://` or `https://` before attempting to
fetch. This prevents common mistakes like providing just a domain name and provides
immediate feedback rather than a generic failure from `file_get_contents()`.

## Step 2.1: Pane Enum for TUI Navigation

### PHP 8.1 Backed Enum with String Values

The `Pane` enum uses `enum Pane: string` — a backed enum with `string` backing type. This allows:

- String comparison: `$pane === Pane::Chat` or `$pane->value === 'chat'`
- Serialization: `Pane::from($string)` and `$pane->value`
- Switch/match friendly: exhaustive matching on enum cases

```php
enum Pane: string
{
    case Chat = 'chat';
    case Input = 'input';
    // ...
}
```

### Enum `next()` as State Machine Transition

The `next()` method implements a fixed cycling order as an enum method. This keeps navigation logic encapsulated within the enum itself rather than scattered across a navigation service:

```php
public function next(): self
{
    return match ($this) {
        self::Chat => self::Input,
        self::Input => self::Files,
        // ...
    };
}
```

**Trade-off**: The cycle order is hardcoded. If the TUI layout changes (e.g., new panes added), this method must be updated. For complex navigation graphs, consider a separate navigation map.

### Enum `label()` for Display Names

Separating display logic (`label()`) from the enum value keeps the enum clean while providing localized/human-readable names. This pattern works well when enum cases are programmatic identifiers but users need readable text.

### `@internal` Doc-Block on Implementation Details

The `Pane` enum is marked `@internal` because it is an implementation detail of the TUI subsystem, not part of the public API. Consumers interact with panes through messages (`SelectPaneMsg`) rather than calling `Pane` methods directly.

## Step 1.5: AppBuilder Fluent Builder

### Builder Pattern vs with*() Methods

AppBuilder demonstrates when to use a separate builder class versus existing `with*()`
methods:

- **`with*()` on the object itself**: Best for state transitions on an already-valid object
  (TEA update cycle). The object is already constructed and partially configured.

- **Separate builder class**: Best when constructing from scratch with many optional
  fields, validation depends on multiple fields together, or you want to hide construction
  complexity from consumers.

### Validation at Build Time

Deferring validation to `build()` rather than each setter provides a better API:

```php
// Anti-pattern: validate on each setter
public function withProvider(ProviderInterface $provider): self
{
    if ($provider === null) {
        throw new \InvalidArgumentException('provider cannot be null');
    }
    // ...
}

// Better: validate once at build time
public function build(): App
{
    if ($this->provider === null) {
        throw new \LogicException('provider is required');
    }
    // ...
}
```

This avoids redundant validation calls during progressive configuration and allows
consumers to configure multiple fields before hitting an error.

### Cloning in Builders

AppBuilder clones `$this` in each `with*()` method, not because `readonly` enforces it
(builders don't use `readonly`), but to maintain immutability semantics for consumers:

```php
public function withModel(string $model): self
{
    $clone = clone $this;
    $clone->model = $model;
    return $clone;
}
```

A consumer who writes `$b2 = $builder->withModel('gpt-4')` expects `$builder` to remain
unchanged. Without cloning, both would reference the same object.

### Default Values in Builders

Setting default values in the builder's properties (rather than in `build()`) communicates
the defaults to consumers via IDE autocompletion and reflection:

```php
private string $model = 'claude-sonnet-4-6';
private Pane $pane = Pane::Chat;
```

This is cleaner than scattered default assignments in `build()`.

### Method Chaining and Readability

The fluent interface trades one-character saved (`$app = App::new(...)` vs
`$app = (new AppBuilder())->with...()->build()`) for significantly better readability
when many fields need configuration. The progressive disclosure of configuration makes
the intent clearer than a constructor with 10 positional arguments.

## Step 1.4: App State Class (TEA Pattern)

### The Elm Architecture (TEA) Pattern

The Elm Architecture models application state as a single immutable data structure
transformed by pure functions. CandyCrush implements:

- **Model**: `App` class holds all state as `readonly` properties
- **Msg**: Internal message types describe events (`UserInputMsg`, `ToolResultMsg`, etc.)
- **Cmd**: Side-effects to execute are returned alongside new state
- **Update**: `App::update(Msg $msg)` transforms state based on message type

The key insight is that `update()` returns `[newApp, command]` — state updates and
side-effects are explicitly paired, making the data flow traceable.

### Private Constructor with Factory

The `App` class uses a private constructor with `App::new()` as the single factory:

```php
private function __construct(
    public readonly ProviderInterface $provider,
    public readonly string $model,
    // ...
) {}

public static function new(ProviderInterface $provider, string $model): self
{
    return new self(
        provider: $provider,
        model: $model,
        messages: [],
        // ... sensible defaults
    );
}
```

This enforces construction through the factory, ensuring all fields have valid defaults
and the instance is fully initialized before use.

### The mutate() Helper Pattern

Each `with*()` method calls `mutate()` to create a clone with modified fields:

```php
private function mutate(mixed ...$changes): self
{
    $clone = clone $this;
    foreach ($changes as $k => $v) {
        $clone->$k = $v;
    }
    return $clone;
}
```

This technique works with `readonly` properties because:
1. `clone` creates a shallow copy with all properties copied
2. Within the same class, we can modify `readonly` properties (they're only enforced from outside)
3. The modified clone is returned, leaving the original untouched

### readonly Properties with Public Promotion

Using constructor property promotion with `readonly`:

```php
public function __construct(
    public readonly ProviderInterface $provider,
    // ...
) {}
```

This is equivalent to declaring the property `readonly` with a getter, but with less
boilerplate. Access is direct: `$app->provider` rather than `$app->getProvider()`.

### msg instanceof Pattern in update()

The `update()` method uses `match (true)` with `instanceof` checks:

```php
public function update(Msg $msg): array
{
    return match (true) {
        $msg instanceof UserInputMsg => $this->handleUserInput($msg),
        $msg instanceof SelectPaneMsg => [$this->withPane($msg->pane)->withError(null), null],
        // ...
    };
}
```

This provides exhaustive type narrowing — PHP validates that all `Msg` implementations
are handled. Adding a new `Msg` type without a match arm causes a compile-time error.

### Internal Type Organization

Msg and Cmd interfaces with implementing classes live in the same file as `App`:

```php
// Msg types (internal)
interface Msg {}

final readonly class UserInputMsg implements Msg { ... }

// Cmd types (internal)
interface Cmd {}

final readonly class RunCompletionCmd implements Cmd { ... }
```

These are `internal` in the sense they are not part of the public API — they're
implementation details of the TEA runtime. The `final readonly` modifier on each
prevents extension and mutation.

### Array Spreading for withMessage()

Adding messages uses array spreading rather than array_push:

```php
public function withMessage(Message $msg): self
{
    return $this->mutate(messages: [...$this->messages, $msg]);
}
```

This is functionally equivalent to `array_push($this->messages, $msg)` but:
- Creates a new array literal (immutable intent)
- Returns the new array directly (no out param)
- Works cleanly with readonly array properties

## Step 2.2: TUI Renderer Framework

### Static Class with Cached State

The renderer uses a static class pattern with cached terminal size:

```php
private static ?array $terminalSize = null;

public static function getTerminalSize(): array
{
    if (self::$terminalSize !== null) {
        return self::$terminalSize;
    }
    // ... detect and cache
}
```

**Design decision**: Static caching is acceptable here because:
1. Terminal size doesn't change during a render cycle
2. The cache is resettable via `resetSizeCache()` for test isolation
3. Avoids repeated expensive `Tty::size()` syscalls

The alternative (passing size through the entire call chain) would pollute pane signatures.

### Sidebar Contextual Delegation

Using the same sidebar region for multiple purposes via delegation:

```php
private static function leftSidebar(App $a, int $cols, int $rows): string
{
    if ($a->pane === Pane::Files) {
        return FilesPane::render($a, $width, $rows);
    }
    if ($a->pane === Pane::Tools) {
        return ToolsPane::render($a, $width, $rows);
    }
    return FilesPane::render($a, $width, $rows);
}
```

This avoids dedicated layout regions for each sidebar variant, reusing screen real estate efficiently. The trade-off is that pane focus determines what content appears — no two sidebars can be visible simultaneously.

### Static Pane Components

All pane components use static methods rather than instantiated objects:

```php
final class ChatPane
{
    public static function render(App $a, int $cols, int $rows): string
    {
        // ...
    }
}
```

**Rationale**:
- No shared mutable state between renders
- No dependency injection complexity
- Each pane is independently testable with mock `App`
- Pane coordination happens in `Renderer`, not within panes themselves

**Trade-off**: Cannot use constructor injection for dependencies. If panes need complex dependencies (e.g., a file system service), this pattern would need revisiting.

### Terminal Dimension Fallback

Graceful degradation when terminal size detection fails:

```php
try {
    $size = (new Tty(STDOUT))->size();
} catch (\Throwable) {}

self::$terminalSize = ['rows' => 60, 'cols' => 200];
```

Using `60x200` as fallback matches common terminal defaults and ensures the TUI remains usable in redirected/non-TTY contexts. The exception swallowing is intentional — we prefer a usable fallback over propagating failures.

### Height Calculation Accounting for Regions

Pane height calculations account for other layout regions:

```php
$height = $rows - 6; // Account for menu, input, status
```

This `6` is the sum of:
- Menu bar: 1 row + 1 border line
- Input pane: 3 rows (top border, content, bottom border)
- Status bar: 1 row

Hardcoding this magic number is brittle — a future design change (adding a second status line, for example) would require updating every pane. A centralized layout constants class would be preferable.

### Pure Function Rendering Contract

The `render(App $a): string` signature enforces a pure function contract:

- Same `App` input → same string output
- No side effects during rendering
- No internal state mutation

This makes the renderer trivially testable: construct an `App`, call `Renderer::render()`, assert on the output string. Snapshot testing works well here for catching unintended layout regressions.

### Composition via Layout Primitives

Rendering uses `sugar-sprinkles` layout primitives rather than manual string concatenation:

```php
$middle = Layout::joinHorizontal(Position::TOP, $leftPane, $chatPane, $rightPane);
```

This abstraction:
- Handles border alignment automatically
- Provides consistent spacing between panes
- Centralizes layout logic in one place

The trade-off is a dependency on `sugar-sprinkles` for rendering. Without it, manual string construction would be required.

## Step 2.3: TUI Component Classes

### Consistent Component Interface

All seven pane components follow an identical structure:

```php
final class SomePane
{
    public static function render(App $a, int $width, int $rows): string
    {
        $body = /* build content from App state */;

        $st = Style::new()
            ->border(Border::rounded()->withTitle(' title '))
            ->padding(0, 1)
            ->width($width);

        $st = $a->pane === Pane::SomePane
            ? $st->borderForeground(Color::hex('#00ffaa'))
            : $st->borderForeground(Color::hex('#ff66aa'));

        return $st->render($body);
    }
}
```

**Benefits of this uniformity**:
- Each new pane is quick to implement by copying the pattern
- Consumers (Renderer) have a predictable interface
- Focus logic is visually consistent across all panes
- Testing approach is identical for all components

### Pane Focus Highlighting via Conditional Border Color

The focus highlighting pattern uses a ternary that applies a different border color:

```php
$st = $a->pane === Pane::X
    ? $st->borderForeground(Color::hex('#00ffaa'))  // cyan — focused
    : $st->borderForeground(Color::hex('#ff66aa')); // pink — unfocused
```

**Why this works well**:
- Color change is the only visual difference between focused/unfocused states
- The same border style (rounded) is used regardless of focus
- The ternary returns a **new `Style` instance**, preserving immutability
- Two colors are sufficient — no need for hover/active/disabled states in a TUI

**Alternative considered**: Using `bold()` or `reverse()` on the border. Rejected because color is more immediately distinguishable at a glance and doesn't interfere with the rounded border aesthetic.

### Immutable Styling via Method Chaining

The pattern `$st->borderForeground(...)` returns a new `Style` instance. This allows the ternary to work without mutating the original:

```php
$st = Style::new()->border(...)->padding(...)->width(...);
$st = $a->pane === Pane::X
    ? $st->borderForeground(Color::hex('#00ffaa'))
    : $st->borderForeground(Color::hex('#ff66aa'));
```

This is a natural consequence of `sugar-sprinkles` `Style` being immutable.

### Empty State Messaging

Sidebar panes use a consistent empty state pattern:

```php
if ($files === []) {
    $body = Style::new()->foreground(Color::hex('#7d6e98'))
        ->render('(no files attached)');
} else {
    $lines = [];
    foreach ($files as $file) {
        $lines[] = /* render file item */;
    }
    $body = implode("\n", $lines);
}
```

**Pattern rationale**:
- Muted color (`#7d6e98`) indicates placeholder text
- Parentheses `(like this)` denote empty/zero state
- The else branch always produces at least one line, avoiding empty border rendering issues

### Emoji in TUI Rendering

`FilesPane` uses `📄` emoji as file icons:

```php
$lines[] = Style::new()
    ->foreground(Color::hex('#c5b6dd'))
    ->render('📄 ' . basename($file));
```

**Considerations**:
- Emoji renders in most modern terminals with proper Unicode support
- Emoji width is typically 2 columns (wider than ASCII), which affects layout calculations
- For strict TUI column-counting, consider using ASCII alternatives (`[F]`, `[+]`)
- The TokyoNight theme context makes emoji acceptable for this use case

### Static Components with App Dependency

Components receive the entire `App` state rather than specific slices:

```php
public static function render(App $a, int $width, int $rows): string
{
    $files = $a->contextFiles;
    // ...
}
```

**Trade-off analysis**:
- **Pro**: Components access only what they need without interface changes
- **Pro**: No need for multiple render signatures or data object abstractions
- **Con**: Components are coupled to `App` structure
- **Con**: Testing requires constructing a full `App` instance

For a small application like CandyCrush, this coupling is acceptable. Larger applications might benefit from component-specific data objects passed to render.

### MenuBar Static Simplicity

`MenuBar` renders a static menu structure without interactivity:

```php
public static function render(App $a): string
{
    $menus = [
        'File' => 'New,Open,Save,Export,Quit',
        // ...
    ];

    $items = [];
    foreach ($menus as $name => $submenus) {
        $items[] = Style::new()->foreground(Color::hex('#fde68a'))->bold()->render($name);
    }

    return ' ' . implode('   ', $items) . ' ';
}
```

**Design decision**: Menu interactivity (dropdowns, keyboard navigation) would be handled separately by the input handling system. The MenuBar component only concerns itself with rendering the top bar as a visual element. Pane switching via Tab is the primary navigation mechanism.

## Step 2.4: Menu System with Keyboard Handling

### Static UI State vs Application State

The `MenuBar` uses a static property for active menu index:

```php
private static int $activeMenu = 0;
```

**Design decision**: This is UI-only state that doesn't affect business logic. Passing it through `App` would inflate the TEA model with presentation concerns. The menu index is transient — it resets on escape and only affects rendering, not application behavior.

**Alternative considered**: Storing `activeMenu` in `App`. Rejected because:
1. Menu navigation is a minor interaction that shouldn't pollute the core state
2. The command returned (`MenuSelectedMsg`) already triggers the business logic
3. Static state is acceptable for purely presentational UI elements

### Separating Keyboard Handling from Rendering

`MenuBar` combines rendering and keyboard handling in one class:

```php
public static function render(App $a): string { ... }
public static function handleKey(string $key, int $currentMenu): array { ... }
```

**Rationale**: The menu is a self-contained UI element. Keeping rendering and input handling together follows the principle of encapsulation — the component knows its own behavior. The alternative (a separate `MenuController`) would add indirection for a simple state machine.

### Keyboard Handler Returns Tuple

The `handleKey()` method returns `[newMenuIndex, ?MenuSelectedMsg]`:

```php
public static function handleKey(string $key, int $currentMenu): array
{
    return match ($key) {
        'left', 'h' => [self::cycleMenu($currentMenu, -1), null],
        'enter', 'o' => self::selectMenuItem($currentMenu),
        // ...
    };
}
```

**Why return both values?**
- The menu index is UI state — needed for the next render
- The message is business logic — triggers `App::update()`

This mirrors the TEA pattern of `[newModel, command]` and allows the caller to update UI state separately from dispatching business logic.

### Menu Item Selection Returns Message

When Enter is pressed, `selectMenuItem()` returns a `MenuSelectedMsg`:

```php
private static function selectMenuItem(int $menuIndex): array
{
    $menuNames = array_keys(self::MENUS);
    $menuName = $menuNames[$menuIndex - 1] ?? '';

    return [$menuIndex, new MenuSelectedMsg($menuName, '')];
}
```

**Note**: The `item` field is empty in this implementation — the menu name is enough to identify which menu was activated. The actual menu item handling would occur in `App::update(MenuSelectedMsg)`.

### Vim Key Bindings

Menu navigation supports both arrows and vim keys:

```php
'left', 'h'  // vim: h = left
'right', 'l' // vim: l = right
```

This follows the project's precedent (seen in `candy-pty/src/Lang.php` with vim key handling) and accommodates power users. The trade-off is potential confusion for users unfamiliar with vim conventions.

### Separator Items in Menu Definitions

The `MENUS` constant uses `'---'` as a separator:

```php
'Provider' => ['OpenAI', 'Anthropic', 'Claude Code', ..., '---', 'Custom...'],
```

**Implementation note**: The `'---'` string is a convention, not a special type. Renderers and handlers must treat this as a non-selectable divider. Current implementation doesn't enforce this — any item can be "selected."

**Alternative**: A dedicated `MenuSeparator` class would make the intent explicit and allow type-safe handling. Not implemented yet due to simplicity of string convention.

### Array Index Arithmetic

Menu index handling uses 1-based indexing:

```php
$menuIndex = 1;
foreach (self::MENUS as $name => $items) {
    $isActive = self::$activeMenu === $menuIndex;
    // ...
    $menuIndex++;
}
```

But `selectMenuItem()` converts to 0-based for array access:

```php
$menuNames = array_keys(self::MENUS);
$menuName = $menuNames[$menuIndex - 1] ?? '';
```

**This 1-based external / 0-based internal pattern** is deliberate:
- External representation (user-visible menu position) starts at 1
- Internal array access uses 0-based indexing
- The `- 1` conversion makes the boundary explicit

**Potential issue**: If `$menuIndex` ever exceeds `count(MENUS)`, the `?? ''` fallback masks the error silently. A bounds check before array access would be safer.

### Dual-Purpose Escape Key

`Escape` and `q` both close the menu:

```php
'escape', 'q' => [self::closeMenu(), null],
```

**Rationale**:
- `Escape` is the standard UI convention for dismissing overlays
- `q` is the vim convention for quitting/backing out
- Supporting both accommodates different user mental models

This duplicates the close behavior in two key bindings, but the clarity benefit outweighs the minor redundancy.

## Step 2.5: Keyboard Handling System

### Centralized Input Dispatch

The `KeyboardHandler` class centralizes all keyboard input processing:

```php
final class KeyboardHandler
{
    public function handle(string $key, App $app): array
    {
        // Tab, arrows, menu shortcuts, escape, ctrl+*
    }
}
```

**Design decision**: A single handler avoids scattered key logic across components. The handler returns `[newApp, command]` following the TEA pattern, keeping input processing separate from command execution.

**Alternative considered**: Distributing key handling to individual panes. Rejected because:
1. Global shortcuts (Ctrl+N for new session) need centralized handling anyway
2. A single entry point makes it easier to reason about input processing
3. Menu bar handling requires cross-cutting access to App state

### Marker Interface for Commands

The `KeyCmd` interface is intentionally empty:

```php
interface KeyCmd
{
}
```

**Rationale**: Commands are identified by their class, not by data. `NewSessionCmd` means "start a new session" — no additional fields needed. This follows the command pattern where the command class itself is the message.

**Trade-off**: Complex commands needing data (e.g., `SelectProviderCmd(providerName)`) would need a different approach. Current commands are all stateless, so the marker interface suffices.

### Command Class Simplicity

All six command classes are identical in structure:

```php
final readonly class NewSessionCmd implements KeyCmd
{
}
```

**Why `final readonly`?**
- `final`: Prevents extension that might add mutable state
- `readonly`: Immutability after construction
- Empty body: The class name itself is the command

This minimal structure is possible because commands carry no data. If commands needed parameters, they would require constructor arguments.

### Priority Chain for Input Processing

`KeyboardHandler::handle()` uses an if-else priority chain:

```php
if ($key === 'tab') { ... }           // Pane cycling (highest priority)
if (in_array($key, [...], true)) { }  // Navigation
if ($currentMenu > 0) { ... }          // Menu shortcuts
if ($key === 'escape') { ... }         // Escape handling
if (str_starts_with($key, 'ctrl+')) { } // Ctrl combinations
```

**Ordering rationale**:
1. Tab is a global navigation shortcut — immediate handling
2. Navigation keys are common — early exit for vim/arrow users
3. Menu handling only when a menu is active
4. Escape is a dismiss action — only if nothing else matched
5. Ctrl combinations are application-wide commands

**Alternative considered**: A priority queue or registry pattern. Rejected as over-engineered for the current key set. The if-else chain is clear and sufficient.

### Returning Command vs State Change

`KeyboardHandler::handle()` can return:
- `[newApp, null]` — State change only (e.g., Tab cycles pane)
- `[app, new SomeCmd()]` — Command for side effects
- `[app, null]` — No change (unrecognized key)

**Why this matters**: Some keys (Tab, arrows) produce immediate state changes. Others (Ctrl+N) need side effects that the TEA runtime executes. Separating these concerns keeps the handler pure.

### Vim Key Support Rationale

Supporting `h`/`j`/`k`/`l` alongside arrow keys:

```php
if (in_array($key, ['up', 'k', 'down', 'j', 'left', 'h', 'right', 'l'], true)) {
    return $this->handleNavigation($key, $app);
}
```

**Rationale**:
- Established project convention (see `candy-pty/src/Lang.php`)
- Power users familiar with vim already use these keys
- No ambiguity — vim keys don't conflict with other bindings
- Terminal apps traditionally accommodate both input styles

**Trade-off**: Users unfamiliar with vim may be confused by `h`/`l` for left/right. This is a common TUI convention that users can learn.

### Pane State in App vs Menu State Static

`Pane` state lives in `App::$pane` (TEA model):
```php
public function withPane(Pane $pane): self
```

`MenuBar` active menu is static:
```php
private static int $activeMenu = 0;
```

**Why the difference?**
- Pane focus affects application behavior (which pane receives input) — business logic
- Menu activation is purely presentational — UI state

This follows the principle from Step 2.4: business state belongs in `App`, UI-only state can use static properties.

### Handling Ctrl+Key Prefixes

The handler strips the `ctrl+` prefix to match:

```php
if (str_starts_with($key, 'ctrl+')) {
    return $this->handleCtrl(substr($key, 5), $app);
}

private function handleCtrl(string $key, App $app): array
{
    return match ($key) {
        'n' => [$app, new NewSessionCmd()],
        // ...
    };
}
```

**Implementation note**: The caller (typically the terminal input library) produces keys like `'ctrl+n'` or `'ctrl+n'`. The handler normalizes to lowercase and strips the prefix. If your input library produces different formats, this mapping may need adjustment.

### Null Command Means No Side Effect

When `KeyboardHandler` returns `[app, null]`, no command executes:

```php
if ($key === 'tab') {
    return [$app->withPane($app->pane->next()), null];
}
```

**This is intentional**: Not every keypress needs a side effect. Tab cycles pane focus via immutable state update — the view re-renders with the new pane focused, no command needed.

**Contrast with Ctrl+N**: Creating a new session requires side effects (clearing messages, resetting session ID), so a command is returned for the runtime to execute.

## Step 3.1: OpenAI Provider Implementation

### Provider Interface as Dependency Injection Target

The `ProviderInterface` enables dependency injection for AI backends:

```php
public function __construct(
    public readonly ProviderInterface $provider,
    // ...
) {}
```

**Benefit**: `App` is provider-agnostic. Switching from OpenAI to Claude Code only requires passing a different provider instance — no changes to `App` or its consumers.

**Trade-off**: The interface must be stable. Every method added to `ProviderInterface` requires implementation in all providers.

### `\Generator` for Streaming Responses

Streaming uses PHP generators rather than arrays:

```php
public function completeStream(CompleteRequest $request): \Generator
{
    $stream = $this->client->chat()->createStreamed($params);
    foreach ($stream as $chunk) {
        yield $this->parseChunk($chunk);
    }
}
```

**Memory efficiency**: Generators yield chunks one at a time without buffering the entire response. For long streaming responses, this prevents memory exhaustion.

**Sequential iteration**: The caller receives chunks in order via `foreach`, making content accumulation straightforward (just append each delta).

### Zero-Value Fields in Streaming Responses

Streaming `CompleteResponse` objects return `0` for `tokensUsed` and `0.0` for `costUsd`:

```php
private function parseChunk(mixed $chunk): CompleteResponse
{
    return new CompleteResponse(
        content: $delta['content'] ?? '',
        reasoning: null,
        toolCalls: null,
        tokensUsed: 0,      // Not available per-chunk
        costUsd: 0.0,       // Not available per-chunk
    );
}
```

**Rationale**: OpenAI's streaming API only provides usage statistics in the final chunk. Per-chunk responses cannot include accurate usage data.

**Consumer responsibility**: Callers must accumulate content and track the final chunk for usage/cost if needed.

### Tool Call Parsing from Nested Arrays

OpenAI returns tool calls in a nested structure:

```php
$toolCalls = array_map(
    fn($tc) => ToolCall::fromArray([
        'id' => $tc['id'],
        'name' => $tc['function']['name'],
        'arguments' => json_decode($tc['function']['arguments'], true) ?? [],
    ]),
    $message['tool_calls']
);
```

**Key insight**: The arguments come as a JSON string that must be decoded. Using `?? []` provides a safe fallback if decoding fails.

### `array_filter()` for Conditional Array Keys

Assistant messages use `array_filter()` to remove null values:

```php
$msg instanceof AssistantMessage => array_filter([
    'role' => 'assistant',
    'content' => $msg->content(),
    'tool_calls' => $msg->toolCalls(),
]),
```

**Why this matters**: OpenAI's API rejects messages with null `content` fields. `array_filter()` removes keys with `null` values, producing a clean message structure.

**Alternative**: Explicitly building the array with conditionals:

```php
$arr = ['role' => 'assistant', 'content' => $msg->content()];
if ($msg->toolCalls() !== null) {
    $arr['tool_calls'] = $msg->toolCalls();
}
```

`array_filter()` is more concise when all fields might be null.

### Model-Specific Configuration via `match` Expressions

Context window and pricing use `match` expressions keyed on model name:

```php
public function contextWindow(): int
{
    return match ($this->defaultModel) {
        'gpt-4o' => 128_000,
        'gpt-4-turbo' => 128_000,
        'gpt-4' => 8_192,
        'gpt-3.5-turbo' => 16_385,
        default => 8_192,
    };
}
```

**Benefits**:
- Exhaustive matching catches unsupported models at runtime
- `default` clause provides safe fallback
- Easy to extend with new models

**Trade-off**: Model list must be kept in sync with OpenAI's offerings. Pricing may become stale.

### Provider Composition with External Client

`OpenAIProvider` wraps an external `OpenAI\Client`:

```php
public function __construct(
    private Client $client,
    private string $defaultModel = 'gpt-4o',
) {}
```

**Design**: The client is injected, not created internally. This enables:
- Testing with mock clients
- Configuration of the client (timeouts, base URL, etc.) before creating the provider
- Shared clients across multiple providers

### systemPrompt Prepending

System prompts are prepended to the messages array:

```php
if ($request->systemPrompt !== null) {
    $params['messages'] = array_merge(
        [['role' => 'system', 'content' => $request->systemPrompt]],
        $params['messages']
    );
}
```

**Rationale**: OpenAI's API expects system messages as the first element in the messages array. This ensures correct ordering regardless of how messages were passed in.

### Embeddings Response Extraction

Embeddings response parsing extracts from a nested array:

```php
return new EmbeddingsResponse(
    embeddings: array_map(
        fn($item) => $item['embedding'],
        $response->toArray()['data'] ?? []
    )
);
```

**Defensive access**: `?? []` ensures we handle missing `data` key gracefully. The `array_map` then extracts just the embedding vectors from each item.

### `final readonly` Class for Provider

`OpenAIProvider` uses `final readonly`:

```php
final readonly class OpenAIProvider implements ProviderInterface
```

**Rationale**:
- `final`: Prevents extension that might add provider-specific behavior that breaks the interface contract
- `readonly`: Immutability after construction — the provider's configuration doesn't change
- Combined with `implements ProviderInterface`: Type-safe guarantee of the contract

This pattern is consistent with other CandyCrush value objects and DTOs.

## Step 3.2: SGLANG Provider Implementation

### Guzzle vs SDK Client

`SglangProvider` uses `GuzzleHttp\Client` directly instead of provider-specific SDKs:

```php
public function __construct(
    private string $baseUrl,
    private string $model,
    private ?string $apiKey,
    private Client $httpClient,
) {}
```

**Rationale**: SGLANG exposes an OpenAI-compatible API, so no SGLANG-specific SDK is needed. Guzzle is a generic HTTP client sufficient for the task.

**Trade-off**: SDKs like `openai-php/client` provide typed response objects and automatic error handling. Raw Guzzle returns JSON arrays that must be manually parsed.

### Manual SSE Streaming Parsing

When using Guzzle with `stream: true`, SSE responses require manual parsing:

```php
$stream = $response->getBody();
while (!$stream->eof()) {
    $line = $stream->readLine();
    if (str_starts_with($line, 'data: ')) {
        $data = json_decode(substr($line, 6), true);
        if ($data !== null && isset($data['choices'][0]['delta'])) {
            yield $this->parseChunk($data);
        }
    }
}
```

**Key techniques**:
- `readLine()` reads until newline without buffering entire response
- `str_starts_with()` filters to SSE `data:` lines only
- `substr($line, 6)` strips the `data: ` prefix
- Null check prevents yielding on malformed JSON

**Why not use SDK streaming?** The OpenAI SDK handles SSE parsing internally. Guzzle requires this handling to be explicit.

### OpenAI-Compatible Message Formatting Reuse

`formatMessages()` and `formatTools()` are identical to `OpenAIProvider` because SGLANG uses the same schema. This demonstrates the value of the OpenAI-compatible API design — providers are interchangeable with minimal duplication.

### Dual-Format Argument Parsing

SGLANG may return tool call arguments as either a JSON string or a decoded array:

```php
'arguments' => is_string($tc['function']['arguments'] ?? '')
    ? json_decode($tc['function']['arguments'], true) ?? []
    : ($tc['function']['arguments'] ?? [])
```

**Why both formats?** Different SGLANG versions or configurations may vary. The code handles both defensively.

### Embeddings Failure as Empty Array

Unlike `complete()` which throws on error, `embeddings()` returns empty results on failure:

```php
} catch (GuzzleException $e) {
    return new EmbeddingsResponse(embeddings: []);
}
```

**Rationale**: Embeddings are optional functionality. A failure to get embeddings should not halt the application — empty results are gracefully handled downstream.

### Factory Method for Client Construction

The `openAiCompatible()` factory encapsulates client setup:

```php
public static function openAiCompatible(
    string $baseUrl,
    string $model = 'MiniMax-M2.7',
    ?string $apiKey = null,
): self {
    $headers = ['Content-Type' => 'application/json'];
    if ($apiKey !== null) {
        $headers['Authorization'] = 'Bearer ' . $apiKey;
    }

    $client = new Client([
        'base_uri' => $baseUrl,
        'headers' => $headers,
    ]);

    return new self($baseUrl, $model, $apiKey, $client);
}
```

**Benefits**:
- Consumer doesn't need to know about Guzzle configuration
- Header setup (auth, content-type) is encapsulated
- Factory provides sensible defaults (null API key, default model)

### Zero-Cost Self-Hosted Model

`costPer1kTokens()` returns `0.0` for SGLANG:

```php
public function costPer1kTokens(string $model, string $direction): float
{
    // SGLANG models are typically self-hosted, low cost
    return 0.0;
}
```

**Design note**: Self-hosted models have no per-token cost. The zero return is a sentinel value — consumers needing actual cost tracking should extend or wrap the provider.

### `final readonly` with Dependency Injection

The provider combines `final readonly` with injected dependencies:

```php
final readonly class SglangProvider implements ProviderInterface
{
    public function __construct(
        private string $baseUrl,
        private string $model,
        private ?string $apiKey,
        private Client $httpClient,
    ) {}
}
```

**Why readonly client?** The HTTP client is expensive to construct and should be reused. Marking it readonly documents that the client doesn't change after construction.

**Immutability of provider config**: Once constructed with a baseUrl/model/apiKey, the provider's behavior is fixed. This aligns with the TEA pattern where state transitions produce new instances, not mutations.

## Step 3.3: Claude Code Provider Implementation

### CLI Wrapper Pattern

The `ClaudeCodeInvocation` class implements a CLI wrapper that encapsulates subprocess management:

```php
final readonly class ClaudeCodeInvocation
{
    public function __construct(
        private string $claudePath = 'claude',
        private string $configDir = '~/.claude',
        private ?string $sessionId = null,
    ) {}

    public function execute(array $args, ?callable $onChunk = null): string
    {
        $cmd = array_merge([$this->claudePath], $this->baseArgs(), $args);

        $process = proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['ANTHROPIC_API_KEY' => getenv('ANTHROPIC_API_KEY') ?: '', ...]
        );
        // ...
    }
}
```

**Design rationale**: Separating CLI invocation from provider logic enables:
1. Testability via mock injection
2. Reusability for other CLI tools
3. Clean separation between process spawning and prompt/response handling

### Subprocess Communication via proc_open()

Using `proc_open()` over `exec()` or `shell_exec()`:

- Provides full control over stdin/stdout/stderr pipes
- Enables streaming with callbacks (`onChunk`)
- Allows environment variable propagation to subprocess
- Returns exit code for error handling

```php
$process = proc_open($cmd, [
    0 => ['pipe', 'r'],  // stdin
    1 => ['pipe', 'w'],  // stdout
    2 => ['pipe', 'w'],  // stderr
], $pipes);
```

### Environment Variable Propagation

Subprocesses don't inherit PHP's environment by default. Explicitly passing variables:

```php
[
    'ANTHROPIC_API_KEY' => getenv('ANTHROPIC_API_KEY') ?: '',
    'ANTHROPIC_AUTH_TOKEN' => getenv('ANTHROPIC_AUTH_TOKEN') ?: '',
    'ANTHROPIC_BASE_URL' => getenv('ANTHROPIC_BASE_URL') ?: '',
]
```

This ensures the Claude Code CLI can authenticate even when PHP's environment differs from the shell environment.

### Print Mode (-p) for Headless Operation

The `-p` flag enables headless print mode — a single prompt/response interaction without interactive UI:

```php
public function printModeArgs(string $prompt, array $options = []): array
{
    $args = ['-p', $prompt];
    // ...
}
```

**Why print mode?**
- Interactive mode requires a TTY and user input
- Print mode accepts a prompt via CLI args and returns structured JSON
- Suitable for programmatic use in the CandyCrush TEA cycle

### Streaming Cannot Use yield in Closure

The streaming implementation cannot reuse `execute()` because `yield` cannot be used inside a closure passed to a function:

```php
// This doesn't work — yield in closure
public function execute(array $args, ?callable $onChunk = null): string
{
    // Cannot yield here from a closure
}

// Streaming opens process directly
public function completeStream(CompleteRequest $request): \Generator
{
    $process = proc_open($cmd, [...], $pipes, ...);
    while (!feof($pipes[1])) {
        yield $this->parseChunk(...);  // yield works here
    }
}
```

**Alternative considered**: Using a callback-based approach where `execute()` calls `$onChunk($data)` for each line. Rejected because it doesn't integrate well with PHP's generator-based streaming API that `completeStream()` must return.

### Line-By-Line SSE Parsing

Claude Code streaming uses newline-delimited JSON (similar to SSE):

```php
$buffer = '';
while (!feof($pipes[1])) {
    $chunk = fread($pipes[1], 8192);
    $buffer .= $chunk;

    while (($pos = strpos($buffer, "\n")) !== false) {
        $line = substr($buffer, 0, $pos);
        $buffer = substr($buffer, $pos + 1);

        if (str_starts_with($line, 'data: ')) {
            $data = json_decode(substr($line, 6), true);
            // process $data
        }
    }
}
```

**Key technique**: Buffer accumulates bytes until a newline is found, then extracts complete JSON lines. This handles partial chunks gracefully.

### Prompt Building for CLI Input

Unlike HTTP-based providers that send structured JSON, the CLI expects plain text prompts:

```php
private function buildPrompt(array $messages): string
{
    $parts = [];
    foreach ($messages as $msg) {
        $parts[] = match (true) {
            $msg instanceof UserMessage => "User: {$msg->content()}",
            $msg instanceof AssistantMessage => "Assistant: {$msg->content()}",
            $msg instanceof SystemMessage => "System: {$msg->content()}",
            $msg instanceof ToolResultMessage => "Tool Result: {$msg->content()}",
            default => "User: {$msg->content()}",
        };
    }
    return implode("\n\n", $parts);
}
```

**Format rationale**: The `User:`, `Assistant:`, `System:` prefix pattern is natural for LLM CLI tools and matches what Claude Code expects.

### Graceful Degradation on Parse Failure

When JSON parsing fails, return raw output as content:

```php
if ($data === null) {
    return new CompleteResponse(
        content: $output,  // Return raw on parse failure
        // ...
    );
}
```

**Rationale**: If Claude Code outputs non-JSON (e.g., error messages to stderr), it's better to surface that as content than to throw an exception. The user sees the raw error message.

### Dual-Format Argument Parsing

Tool call arguments may be a JSON string or decoded array:

```php
'arguments' => is_string($tc['arguments'] ?? null)
    ? json_decode($tc['arguments'], true) ?? []
    : ($tc['arguments'] ?? [])
```

**Why both?** Different Claude Code versions or invocation modes may vary. Defensive parsing handles both formats without requiring version detection.

### Zero Cost for CLI-Based Providers

```php
public function costPer1kTokens(string $model, string $direction): float
{
    // Claude Code handles its own billing
    return 0.0;
}
```

**Design note**: CLI-based providers delegate billing to the external tool. Users manage their own Claude Code subscription and API costs separately.

### Provider Selection at Runtime

Like other providers, `ClaudeCodeProvider` is instantiated and injected at runtime:

```php
$invocation = new ClaudeCodeInvocation();
$provider = new ClaudeCodeProvider($invocation);

$app = (new AppBuilder())
    ->withProvider($provider)
    ->withModel('claude-sonnet-4-6')
    ->build();
```

The `App` class remains provider-agnostic — any `ProviderInterface` implementation works.

## Step 3.4: AWS Bedrock Provider Implementation

### AWS SDK vs Raw HTTP Client

`BedrockProvider` uses the official AWS SDK instead of raw HTTP:

```php
use Aws\Bedrock\BedrockClient;
use Aws\Exception\AwsException;

$client = new BedrockClient([
    'region' => $region,
    'version' => 'latest',
]);
```

**Benefits over raw HTTP**:
- Automatic AWS Signature Version 4 request signing
- Credential resolution from IAM roles, environment, or config files
- Built-in retry logic and timeout handling
- Region-based endpoint routing

**Trade-off**: The AWS SDK is heavier than a simple HTTP client. For lightweight deployments, consider whether full AWS SDK is necessary.

### AWS Exception Handling

AWS SDK throws `AwsException` which contains:

```php
} catch (AwsException $e) {
    throw new \RuntimeException('Bedrock completion failed: ' . $e->getMessage(), 0, $e);
}
```

**Pattern**: Wrapping `AwsException` in `RuntimeException` maintains provider-agnostic error handling. Callers catch `RuntimeException` without knowing AWS is the backend.

### Different Request Parameter Naming

AWS Bedrock uses `modelId` instead of `model`:

```php
$params = [
    'modelId' => $request->model,  // Not 'model' like OpenAI
    'messages' => $this->formatMessages($request->messages),
];
```

**Why the difference?** AWS services often have their own parameter naming conventions. The provider must translate between CandyCrush's generic `model` concept and AWS's `modelId`.

### Different Message Content Structure

Bedrock expects content as an array of objects:

```php
'content' => [['text' => $msg->content()]],  // Not just a string
```

**Format comparison**:
- OpenAI/SGLANG: `'content' => 'Hello'`
- Bedrock: `'content' => [['text' => 'Hello']]`

This difference requires provider-specific message formatting even when both use HTTP.

### System Prompt as Separate Parameter

Bedrock handles system prompts differently:

```php
if ($request->systemPrompt !== null) {
    $params['system'] = [['text' => $request->systemPrompt]];
}
```

**Format difference**:
- OpenAI/SGLANG: System as first message with `role: 'system'`
- Bedrock: Separate `system` array parameter with `[{text: ...}]`

**Advantage**: System prompt is explicitly separated from messages, making it clearer in the API structure.

### System Message Wrapping Convention

When system messages appear in the messages array, Bedrock wraps them as user messages:

```php
$msg instanceof SystemMessage => 'user', // System wrapped as user
```

**Rationale**: Bedrock doesn't have a `role: 'system'` in the messages array. System prompts must be in the dedicated `system` parameter. If a system message appears in messages, converting to user maintains the text while fitting Bedrock's schema.

### Nested Response Structure

Bedrock responses use a deeply nested structure:

```php
$output = $data['output']['message'] ?? [];
$content = $output['content'] ?? [];
$content[0]['text'] ?? ''  // Deeply nested
```

**Comparison**:
- OpenAI: `data['choices'][0]['message']['content']`
- Bedrock: `data['output']['message']['content'][0]['text']`

**Why so nested?** AWS services use consistent response envelopes with `output` as the payload container. This allows AWS to add metadata (usage, metrics) at the top level without conflicting with the actual output.

### Different Usage Token Field Names

Bedrock uses separate input/output token counts:

```php
tokensUsed: ($data['usage']['inputTokens'] ?? 0) + ($data['usage']['outputTokens'] ?? 0),
```

**Field comparison**:
- OpenAI: `usage.total_tokens` (combined)
- Bedrock: `usage.inputTokens` + `usage.outputTokens` (separate)

This matters for token tracking. The provider sums them for a combined count that matches other providers' behavior.

### Streaming Chunk Structure

Bedrock streaming uses a binary frame structure:

```php
foreach ($stream as $chunk) {
    if (isset($chunk['chunk']['bytes'])) {
        $data = json_decode($chunk['chunk']['bytes'], true);
        // process $data
    }
}
```

**Structure comparison**:
- OpenAI/SGLANG: SSE lines `data: {...}`
- Bedrock: AWS binary frame with `chunk.bytes` containing JSON string

**Why binary?** AWS uses a more efficient binary protocol for streaming. The `bytes` field contains a JSON string that must be decoded separately.

### Completion Field for Streaming Deltas

Streaming deltas arrive in the `completion` field:

```php
if (isset($data['completion'])) {
    return new CompleteResponse(
        content: $data['completion'],
        // ...
    );
}
```

**Format comparison**:
- OpenAI streaming: `delta.content`
- Bedrock streaming: `completion`

This is a provider-specific field name that differs from non-streaming responses (which use `output.message.content`).

### Guardrail Configuration in Streaming

Streaming requests include empty `guardrailConfig`:

```php
$params = [
    'modelId' => $request->model,
    'messages' => $this->formatMessages($request->messages),
    'guardrailConfig' => [],  // Required even if not using guardrails
    'inferenceConfig' => [...],
];
```

**Why required?** Even when not using Bedrock Guardrails, the parameter must be present. Omitting it may cause API errors.

### Zero Cost for Uncalculated Responses

`costUsd` returns `0.0` for Bedrock responses:

```php
return new CompleteResponse(
    // ...
    costUsd: 0.0, // Calculate from usage if needed
);
```

**Rationale**: AWS provides raw token counts but not direct cost. Calculating cost requires applying the model's pricing formula. The provider could calculate this, but it was omitted for simplicity — consumers can calculate from `tokensUsed` and `costPer1kTokens()`.

### Region Constants for Default Values

Using class constants for default regions:

```php
private const REGION_US = 'us-east-1';
private const REGION_EU = 'eu-west-1';
```

**Why constants?** Region endpoints are stable but might change. Having them in one place makes updates easier. Default region constants also document which regions are "known good" for the provider.

### Approximate Pricing Warning

Pricing in `costPer1kTokens()` is marked as approximate:

```php
// Pricing varies by model and region - these are approximations
return match ($model) {
    // ...
};
```

**Rationale**: AWS pricing varies by region and can change. Hardcoded values may become stale. The comment alerts consumers that they should verify current pricing.

### Model Capability Variations

Function calling support depends on the model:

```php
public function supportsFunctionCalling(): bool
{
    return false; // Depends on model
}
```

**Limitation**: Some Bedrock models (Claude 3 Sonnet, etc.) support function calling, but not all. The provider returns `false` conservatively. A more sophisticated provider could check the specific model and return the appropriate value.

### Empty Embeddings Implementation

Embeddings return empty with a comment about required models:

```php
public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
{
    // Use Titan or Cohere for embeddings via Bedrock
    return new EmbeddingsResponse(embeddings: []);
}
```

**Why empty?** Embeddings on Bedrock require separate models (Titan, Cohere) with different API calls. The stub returns empty rather than implementing incomplete functionality.

**Improvement opportunity**: A full implementation would accept an embeddings model parameter and use the appropriate Bedrock embedding model.

### Provider Transport Pattern Summary

The four providers demonstrate different transport mechanisms:

| Provider | Transport | Auth | Request Style |
|----------|-----------|------|---------------|
| OpenAIProvider | Guzzle HTTP | Bearer token | OpenAI native |
| SglangProvider | Guzzle HTTP | Optional Bearer | OpenAI-compatible |
| ClaudeCodeProvider | proc_open() | Environment vars | CLI args |
| BedrockProvider | AWS SDK | AWS SigV4 | AWS native |

Each transport requires different:
- Client construction patterns
- Error handling approaches
- Streaming implementations
- Authentication mechanisms

The `ProviderInterface` abstracts these differences, allowing `App` to remain provider-agnostic.
