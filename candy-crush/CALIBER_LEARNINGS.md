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
| VertexProvider | Google Cloud SDK | GCP credentials | Google Cloud AI Platform |
| CustomProvider | Guzzle HTTP | Optional Bearer | OpenAI-compatible REST API |

Each transport requires different:
- Client construction patterns
- Error handling approaches
- Streaming implementations
- Authentication mechanisms

The `ProviderInterface` abstracts these differences, allowing `App` to remain provider-agnostic.

## Step 3.5: Vertex and Custom Providers Implementation

### Google Cloud AI Platform SDK Namespace Requirements

`VertexProvider` uses the Google Cloud AI Platform SDK:

```php
use Google\Cloud\AIPlatform\V1\PredictionServiceClient;
```

**Important**: The correct package name on Packagist is `google/cloud-ai-platform` (with hyphens), not `google/cloud-aiplatform` (with underscores). The namespace uses underscores (`AIPlatform\V1`) while the package name uses hyphens.

**Composer dependency**:
```json
"google/cloud-ai-platform": "^1.0"
```

### Vertex AI Endpoint Format

Vertex AI uses a specific endpoint format for model requests:

```php
$endpoint = "projects/{$this->projectId}/locations/{$this->location}/publishers/anthropic/models/{$request->model}";
```

**Format**: `projects/{project}/locations/{location}/publishers/{publisher}/models/{model}`

- `project`: GCP project ID
- `location`: GCP region (e.g., `us-central1`)
- `publisher`: Model publisher (`anthropic` for Claude models)
- `model`: Model identifier (e.g., `claude-3-sonnet@20240229`)

### OpenAI-Compatible Provider Factory Pattern

`CustomProvider::openAiCompatible()` demonstrates a factory pattern for OpenAI-compatible providers:

```php
public static function openAiCompatible(
    string $name,
    string $baseUrl,
    string $model,
    ?string $apiKey = null,
    bool $supportsStreaming = true,
    bool $supportsFunctionCalling = true,
): self {
    $headers = [
        'Content-Type' => 'application/json',
    ];

    if ($apiKey !== null) {
        $headers['Authorization'] = 'Bearer ' . $apiKey;
    }

    $client = new Client([
        'base_uri' => $baseUrl,
        'headers' => $headers,
    ]);

    return new self(
        name: $name,
        baseUrl: $baseUrl,
        model: $model,
        apiKey: $apiKey,
        httpClient: $client,
        supportsStreaming: $supportsStreaming,
        supportsFunctionCalling: $supportsFunctionCalling,
    );
}
```

**Design rationale**: The factory encapsulates all HTTP client setup, including header configuration for authentication. Consumers don't need to understand Guzzle to create a provider.

### Environment Variable API Key Loading

`CustomProvider::openAiCompatibleFromEnv()` provides a secure pattern for loading API keys from environment variables:

```php
public static function openAiCompatibleFromEnv(
    string $name,
    string $baseUrl,
    string $model,
    string $apiKeyEnvVar = 'CUSTOM_PROVIDER_API_KEY',
    bool $supportsStreaming = true,
    bool $supportsFunctionCalling = true,
): self {
    $apiKey = getenv($apiKeyEnvVar) ?: null;

    return self::openAiCompatible(
        name: $name,
        baseUrl: $baseUrl,
        model: $model,
        apiKey: $apiKey,
        supportsStreaming: $supportsStreaming,
        supportsFunctionCalling: $supportsFunctionCalling,
    );
}
```

**Security rationale**:
- API keys are never hardcoded in source code
- Keys are loaded at runtime from environment variables
- The pattern uses `getenv()` which reads from the process environment, not user input
- Default env var name `CUSTOM_PROVIDER_API_KEY` follows convention

**Usage pattern**:
```php
// Set environment before running
// export CUSTOM_PROVIDER_API_KEY="sk-..."

$provider = CustomProvider::openAiCompatibleFromEnv(
    name: 'ollama',
    baseUrl: 'http://localhost:11434/v1',
    model: 'llama3',
);
```

**Comparison**:

| Method | API Key Source | Use Case |
|--------|----------------|----------|
| `openAiCompatible()` | Passed directly as argument | Tests, known keys |
| `openAiCompatibleFromEnv()` | Environment variable | Production deployments |

**Factory method delegation**: `openAiCompatibleFromEnv()` retrieves the env var and delegates to `openAiCompatible()`, keeping the core factory logic in one place.

### Feature Flag Configuration

`CustomProvider` uses boolean flags to indicate capability support:

```php
private bool $supportsStreaming,
private bool $supportsFunctionCalling,
```

**Why flags?** Different OpenAI-compatible endpoints (Ollama, LM Studio, vLLM, etc.) support different features. The flags allow the provider to:
- Skip sending unsupported features to the API
- Fall back to non-streaming when streaming isn't available

```php
if ($request->tools !== null && $this->supportsFunctionCalling) {
    $params['tools'] = $this->formatTools($request->tools);
}
```

### Buffer-Based SSE Line Reading

The `completeStream()` implementation uses a buffer-based approach for reading SSE streams:

```php
$stream = $response->getBody();
$buffer = '';

while (!$stream->eof()) {
    $chunk = $stream->read(8192);
    $buffer .= $chunk;

    // Process complete lines in buffer
    while (($newlinePos = strpos($buffer, "\n")) !== false) {
        $line = substr($buffer, 0, $newlinePos);
        $buffer = substr($buffer, $newlinePos + 1);

        $line = trim($line);
        if (str_starts_with($line, 'data: ')) {
            $data = json_decode(substr($line, 6), true);
            if ($data === null) {
                continue;
            }
            if (isset($data['choices'][0]['delta'])) {
                yield $this->parseChunk($data);
            }
            if (isset($data['choices'][0]['finish_reason'])) {
                return;
            }
        }
    }
}
```

**Key techniques**:
- `read(8192)` reads up to 8KB chunks from the stream
- Buffer accumulates bytes until a newline is found
- `strpos()` finds newline positions for line splitting
- `str_starts_with()` filters to SSE `data: ` lines only
- `substr($line, 6)` strips the `data: ` prefix
- `trim()` handles any whitespace around lines

**Why buffer?** Network chunks don't align with lines. A single `read()` may contain partial lines, and lines may span multiple reads. The buffer ensures complete lines are processed.

### Stream End Detection

SSE streams end when `finish_reason` appears in the data:

```php
if (isset($data['choices'][0]['finish_reason'])) {
    return;  // Stream complete
}
```

**Why `finish_reason`?** The last chunk in an SSE stream typically contains `finish_reason: 'stop'` indicating normal completion. Other finish reasons include `'length'` (max tokens reached) or `'tool_calls'` (stopped for function calling).

### Non-Streaming Fallback

When streaming is disabled, `completeStream()` falls back to synchronous completion:

```php
public function completeStream(CompleteRequest $request): \Generator
{
    if (!$this->supportsStreaming) {
        yield $this->complete($request);
        return;
    }
    // ... streaming implementation
}
```

**Rationale**: The interface requires `completeStream()` to return a Generator. When streaming isn't supported, yielding the result of `complete()` provides a simple fallback while maintaining the Generator contract.

### Dual-Format Argument Parsing

Tool call arguments may be returned as either a JSON string or already-decoded array:

```php
'arguments' => is_string($tc['function']['arguments'] ?? '')
    ? json_decode($tc['function']['arguments'], true) ?? []
    : ($tc['function']['arguments'] ?? [])
```

**Why both?** Different API versions or configurations may vary. The code handles both defensively.

### Error Response with isError Flag

`CustomProvider` returns error responses using the `isError` flag:

```php
return new CompleteResponse(
    content: '',
    isError: true,
    errorMessage: $e->getMessage(),
);
```

**Design**: Rather than throwing exceptions, error responses are returned as `CompleteResponse` objects with `isError: true`. This allows callers to handle errors uniformly with successful responses.

### Embeddings Failure as Empty Array

`embeddings()` returns empty results on failure rather than throwing:

```php
} catch (GuzzleException $e) {
    return new EmbeddingsResponse(embeddings: []);
}
```

**Rationale**: Embeddings are optional functionality. A failure to get embeddings should not halt the application — empty results are gracefully handled downstream.

### Provider Naming Flexibility

`CustomProvider` accepts a custom name, allowing providers to identify themselves:

```php
private string $name,

public function name(): string
{
    return $this->name;
}
```

**Use cases**:
- `'ollama'` for local Ollama instances
- `'lm-studio'` for LM Studio
- `'vllm'` for vLLM endpoints
- Any descriptive identifier for debugging

### Provider Feature Comparison

| Feature | VertexProvider | CustomProvider |
|---------|----------------|-----------------|
| Streaming | Placeholder (not implemented) | Full support |
| Function Calling | Not supported | Configurable (default on) |
| Vision | Not supported | Not supported |
| Embeddings | Empty stub | Full support via `/embeddings` |
| Authentication | GCP credentials | Optional API key |
| Transport | Google Cloud SDK | Guzzle HTTP |

This comparison helps when selecting which provider to use for a given use case.

## Step 3.6: ProviderFactory Implementation

### Factory Dispatch Pattern for Multiple Provider Types

`ProviderFactory` uses a `match` expression to dispatch to provider-specific creation methods:

```php
private function instantiateProvider(string $type, array $config): ProviderInterface
{
    return match ($type) {
        'openai' => $this->createOpenAI($config),
        'anthropic' => $this->createAnthropic($config),
        'claude-code' => $this->createClaudeCode($config),
        'sglang' => $this->createSglang($config),
        'bedrock' => $this->createBedrock($config),
        'vertex' => $this->createVertex($config),
        'custom' => $this->createCustom($config),
        default => throw new \RuntimeException("Unsupported provider type: {$type}"),
    };
}
```

**Design rationale**: The `match` expression provides exhaustive type checking — adding a new provider type requires adding a new arm, and PHP will warn if the `default` case is removed. This is safer than a series of `if/elseif` chains.

**Alternative considered**: A registry pattern (`$registry[$type]()`) — more flexible but adds indirection. For a fixed set of known provider types, `match` is simpler and equally extensible.

### Environment Variable Resolution Patterns

`resolveEnv()` handles two shell-style variable patterns:

```php
public function resolveEnv(?string $value): ?string
{
    // Pattern: ${VAR} or ${VAR:-default}
    return preg_replace_callback(
        '/\$\{([A-Z_][A-Z0-9_]*)(?::-([^}]*))?\}/',
        function (array $matches): string {
            $varName = $matches[1];
            $default = $matches[2] ?? null;

            $envValue = getenv($varName);

            if ($envValue === false || $envValue === '') {
                return $default ?? '';
            }

            return $envValue;
        },
        $value
    );
}
```

**Why regex-based?**
- Single pass through the string replaces all occurrences
- Captures both forms `${VAR}` and `${VAR:-default}` in one pattern
- The `(?::-([^}]*))?` optional group handles defaults cleanly

**Pattern breakdown:**
- `[A-Z_][A-Z0-9_]*` — matches valid env var names (uppercase, underscore, digits)
- `(?::-([^}]*))?` — optionally matches `:-default` where `[^}]*` captures default value
- Env var name restriction to uppercase is correct — Linux environment variables are conventionally uppercase

**Edge case handling:**
- Unset env (`getenv()` returns `false`) → returns default
- Empty env (`''`) → also returns default (shell semantics for credential-like values)
- Empty default (`${VAR:-}`) → returns `''`

### Recursive Environment Variable Resolution

`resolveEnvVars()` recursively processes config arrays:

```php
private function resolveEnvVars(array $config): array
{
    foreach ($config as $key => $value) {
        if (is_string($value)) {
            $config[$key] = $this->resolveEnv($value);
        } elseif (is_array($value)) {
            $config[$key] = $this->resolveEnvVars($value);
        }
    }

    return $config;
}
```

**Why recursive?** Config may contain nested arrays (e.g., tool configurations with base URLs). Each string value is processed individually.

### Factory Method vs Constructor Injection Comparison

Provider creation can happen via factory or direct constructor:

**Factory approach (`ProviderFactory::create()`):**
```php
$config = ['type' => 'openai', 'apiKey' => '${OPENAI_API_KEY}'];
$provider = $factory->create($config);
```

**Constructor approach (direct instantiation):**
```php
$client = OpenAI::client(apiKey: getenv('OPENAI_API_KEY'));
$provider = new OpenAIProvider($client, 'gpt-4o');
```

**Trade-offs:**

| Aspect | Factory | Constructor |
|--------|---------|-------------|
| Env resolution | Built-in | Manual |
| Validation | Centralized at boundary | Per-method |
| Type checking | Runtime string matching | Compile-time class |
| Extensibility | Add arms to match | Add classes to switch |
| Testing | Easier to mock factory | Must mock each provider |

**When to use factory:**
- Configuration comes from external sources (files, env vars, APIs)
- Provider type is determined at runtime
- Want centralized validation

**When to use constructor:**
- Provider is known at development time
- Maximum type safety desired
- Simple case with no env substitution needed

### Config Validation at Factory Boundary

The factory validates configuration at the boundary before creating providers:

```php
public function create(array|string $config): ProviderInterface
{
    // Parse JSON string to array if needed
    if (is_string($config)) {
        $config = $this->parseJson($config);
    }

    // Validate config is now an array
    if (!is_array($config)) {
        throw new \InvalidArgumentException('Config must be an array or valid JSON string');
    }

    // Early Exit - must have 'type' key
    if (!isset($config['type'])) {
        throw new \InvalidArgumentException('Config must have a "type" key');
    }

    $type = $config['type'];

    // Early Exit - validate provider type
    if (!$this->isValidType($type)) {
        throw new \InvalidArgumentException("Unknown provider type: {$type}");
    }

    // Resolve environment variables in all string values
    $config = $this->resolveEnvVars($config);

    // Validate required keys for this type
    $this->validateRequiredKeys($type, $config);

    // Create the appropriate provider
    return $this->instantiateProvider($type, $config);
}
```

**Validation order rationale:**
1. Parse early — fail fast on invalid JSON before type validation
2. Type presence — without `type`, nothing else makes sense
3. Type validity — unknown types should fail before env resolution
4. Env resolution — transforms values before checking required fields
5. Required keys — final check that all needed values are present

**Why validate at boundary?**
- Fail fast before any provider instantiation
- Single place to check all validation rules
- Provider constructors remain simple
- Error messages are user-friendly (config-level, not provider-level)

### TYPE_SCHEMAS as Single Source of Truth

The `TYPE_SCHEMAS` constant drives both validation and documentation:

```php
private const TYPE_SCHEMAS = [
    'openai' => [
        'required' => ['apiKey'],
        'optional' => ['organization', 'model'],
    ],
    'anthropic' => [
        'required' => ['apiKey'],
        'optional' => ['baseUrl', 'model'],
    ],
    // ...
];
```

**Benefits:**
- One definition covers both validation logic and documentation
- Adding a provider type requires updating only this constant
- The schema is self-documenting — required vs optional is explicit
- Can be introspected for dynamic UIs (e.g., provider configuration forms)

### Empty String Validation for Required Keys

`validateRequiredKeys()` rejects both missing and whitespace-only values:

```php
private function validateRequiredKeys(string $type, array $config): void
{
    $schema = self::TYPE_SCHEMAS[$type];
    $required = $schema['required'];

    foreach ($required as $key) {
        if (!isset($config[$key]) || (is_string($config[$key]) && trim($config[$key]) === '')) {
            throw new \RuntimeException("Provider type '{$type}' requires '{$key}' to be set");
        }
    }
}
```

**Why `trim()` check?** A value of `'   '` (spaces) for `apiKey` is functionally empty and should fail. The `trim()` ensures whitespace-only credentials are rejected.

### JSON String Parsing at Factory Boundary

`parseJson()` handles JSON config strings with proper error handling:

```php
private function parseJson(string $json): array
{
    // Early exit on empty string
    if (trim($json) === '') {
        throw new \InvalidArgumentException('JSON string cannot be empty');
    }

    $data = json_decode($json, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new \InvalidArgumentException('Invalid JSON: ' . json_last_error_msg());
    }

    if (!is_array($data)) {
        throw new \InvalidArgumentException('JSON must decode to an array');
    }

    return $data;
}
```

**Why check `!is_array()`?** `json_decode()` can return `null` for invalid JSON (detected via `json_last_error()`) but also for valid JSON that isn't an array (e.g., `"hello"` decodes to a string). Both cases should be errors.

### Guard Clauses Throughout ProviderFactory

The factory uses guard clauses at function tops for early exit:

```php
// In create():
if (is_string($config)) { ... }     // Handle JSON string
if (!is_array($config)) { ... }      // Validate array
if (!isset($config['type'])) { ... } // Must have type
if (!$this->isValidType($type)) { ... } // Known type

// In resolveEnv():
if ($value === null) { return null; }   // Nothing to resolve
```

**Benefits:**
- Reduces nesting — main logic isn't indented under early conditions
- Documents preconditions at the top of each method
- Fail-fast ensures invalid states don't propagate

### Anthropic via CustomProvider Pattern

`createAnthropic()` reuses `CustomProvider::openAiCompatible()`:

```php
private function createAnthropic(array $config): ProviderInterface
{
    $baseUrl = $config['baseUrl'] ?? 'https://api.anthropic.com';
    $apiKey = $config['apiKey'];
    $model = $config['model'] ?? 'claude-sonnet-4-6';

    $headers = [
        'Content-Type' => 'application/json',
        'x-api-key' => $apiKey,
        'anthropic-version' => '2023-06-01',
    ];

    if ($baseUrl !== 'https://api.anthropic.com') {
        $headers['anthropic-dangerous-direct-browser-access'] = 'true';
    }

    // Use CustomProvider as Anthropic implementation
    return CustomProvider::openAiCompatible(
        name: 'anthropic',
        baseUrl: $baseUrl . '/v1',
        model: $model,
        apiKey: $apiKey,
        supportsStreaming: true,
        supportsFunctionCalling: false,
    );
}
```

**Why reuse CustomProvider?** Anthropic's API is OpenAI-compatible at the `/v1` endpoint. Using `CustomProvider` avoids duplicating HTTP client setup while adding Anthropic-specific headers. This is a **composition over duplication** pattern.

## Step 4.1: Skill Value Object

### Frontmatter Parsing with Regex Split

The `Skill::parse()` method uses a regex to split YAML frontmatter from markdown content:

```php
if (preg_match('/^---\s*\n(.*?)\n---\s*\n/s', $content, $matches)) {
    $frontmatter = $matches[1];
    $body = substr($content, strlen($matches[0]));
    $meta = Yaml::parse($frontmatter);
} else {
    $meta = [];
    $body = $content;
}
```

**Regex breakdown:**
- `^---\s*\n` — matches opening `---` followed by optional whitespace and newline
- `(.*?)` — non-greedy capture of frontmatter content (the `/s` flag makes `.` match newlines)
- `\n---\s*\n` — matches closing `---` with optional whitespace and trailing newline

**Why non-greedy (`*?`)?** Ensures we capture only the first `---...---` block, allowing content after the frontmatter to contain `---` markers.

### YAML vs JSON for Configuration

SKILL.md uses YAML frontmatter rather than JSON:

```yaml
# YAML — human-readable, supports comments, multi-line strings
---
name: payment-gateway
paths:
  - 'include/**/*.php'
---
```

**YAML advantages for skill metadata:**
- Comments allowed (e.g., `# Optional`)
- Trailing commas tolerated
- Multi-line strings with `|` or `>` fold
- More natural to read and write

**JSON alternatives considered:**
- `SKILL.json` — cleaner parsing (`json_decode`) but no comments, noisier syntax
- `SKILL.yaml` — equivalent to frontmatter approach but requires separate file

The frontmatter approach keeps the skill definition in a single file with clear separation between metadata (top) and content (bottom).

### Immutable Value Object Pattern

`Skill` uses `final readonly class` for immutability:

```php
final readonly class Skill
{
    public function __construct(
        public string $name,
        public string $description,
        // ...
    ) {}
}
```

**Benefits:**
- `final` prevents extension that might add mutable state
- `readonly` makes all properties immutable after construction
- Promoted parameters eliminate boilerplate property declarations and getters

**Alternative considered:** Getter methods (`getName()`, `getDescription()`). Rejected because:
- More verbose
- Direct property access (`$skill->name`) is clearer
- PHP 8.1+ readonly semantics are well-understood

### with*() Builder for Immutable Updates

The `withName()` method provides immutable updates:

```php
public function withName(string $name): self
{
    return new self(
        name: $name,
        description: $this->description,
        userInvocable: $this->userInvocable,
        disableModelInvocation: $this->disableModelInvocation,
        allowedTools: $this->allowedTools,
        disallowedTools: $this->disallowedTools,
        model: $this->model,
        effort: $this->effort,
        context: $this->context,
        paths: $this->paths,
        content: $this->content,
        sourcePath: $this->sourcePath,
    );
}
```

**Why return `self` with new instance?**
- Caller can chain: `$newSkill = $skill->withName('new-name')`
- Original `$skill` remains unchanged
- Follows TEA immutable state transition pattern

**Trade-off:** Manual property copying is verbose. A `mutate()` helper (as used in `App`) would reduce boilerplate but would work differently here since `Skill` uses readonly properties.

### Keyword Matching for Skill Selection

`matchesPrompt()` uses a simple keyword extraction and matching algorithm:

```php
public function matchesPrompt(string $prompt): bool
{
    $keywords = array_filter(explode(' ', strtolower($this->description)));

    foreach ($keywords as $keyword) {
        if (strlen($keyword) > 3 && stripos($prompt, $keyword) !== false) {
            return true;
        }
    }

    return false;
}
```

**Design choices:**
- `strtolower()` on description before splitting — ensures consistent comparison
- `strlen($keyword) > 3` filter — excludes common articles/prepositions that always match
- `stripos()` — case-insensitive matching

**Limitation:** Short but meaningful terms (PHP, SQL, API, LLM) won't match the 3-character threshold. These are typically in the skill `name`, not `description`. Acceptable trade-off for a simple heuristic.

**Alternative considered:** TF-IDF or embedding similarity. Rejected for simplicity — this is a first-pass filter, not a ranking system.

### System Prompt Contribution Pattern

`systemPromptContribution()` formats skill content for LLM injection:

```php
public function systemPromptContribution(): string
{
    return "\n\n## Skill: {$this->name}\n\n{$this->content}";
}
```

**Why format as markdown section?**
- `## Skill:` header creates a visually distinct section in multi-skill prompts
- Preserves skill markdown formatting (lists, code blocks, headers)
- Double newline prefix ensures separation from preceding content

**Usage:** Appended to system prompts when the skill is matched:

```php
$systemPrompt = "You are a helpful coding assistant.";
foreach ($matchedSkills as $skill) {
    $systemPrompt .= $skill->systemPromptContribution();
}
```

### Convention: camelCase Properties, kebab-case YAML, snake_case Serialization

`Skill` uses three naming conventions for three different contexts:

| Context | Example | Rationale |
|---------|---------|-----------|
| PHP Properties | `userInvocable` | PHP convention (camelCase) |
| YAML Keys | `user-invocable` | YAML convention (kebab-case) |
| Serialized Keys | `user_invokable` | API/JSON convention (snake_case) |

The translation happens at parse time (YAML → PHP) and serialization time (PHP → snake_case):

```php
// Parsing: YAML kebab-case → PHP camelCase
userInvocable: $meta['user-invocable'] ?? true

// Serialization: PHP camelCase → array snake_case
'user_invokable' => $this->userInvocable
```

This layering maintains convention-appropriate naming at each boundary.

## Step 4.2: SkillLoader and SkillRegistry

### Recursive Directory Scanning with SKIP_DOTS

`loadFromDirectory()` uses `RecursiveDirectoryIterator` with `SKIP_DOTS`:

```php
$iterator = new \RecursiveIteratorIterator(
    new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
);
```

**Why SKIP_DOTS?** Without it, the iterator would yield `.` and `..` entries in addition to real files and directories. The `SKIP_DOTS` flag tells the iterator to skip these special directory entries automatically.

**Alternative considered:** Checking `$file->getBasename() !== '.' && $file->getBasename() !== '..'`. Rejected in favor of `SKIP_DOTS` — it's the built-in, semantically clear solution.

### Priority-Based array_merge Pattern

`loadAll()` uses chained `array_merge()` for priority handling:

```php
$skills = array_merge($builtin, $user);   // user overrides builtin
$skills = array_merge($skills, $project); // project overrides both
```

**Why array_merge with string keys?** When keys are strings and duplicate, `array_merge()` keeps the last value. This provides a clean priority system:
- Built-in skills loaded first (lowest priority)
- User skills merged second (override builtins with same name)
- Project skills merged last (override both with same name)

**Alternative considered:** Nested arrays with manual priority checking. Rejected — `array_merge()` is idiomatic PHP and the intent is clear.

### fnmatch for Path Pattern Matching

`getForPaths()` uses `fnmatch()` for glob-style path matching:

```php
if (fnmatch($pattern, $path)) {
    $matches[] = $skill;
    break 2;
}
```

**fnmatch() patterns:**
- `*.php` — matches any `.php` file in the current directory
- `**/*.php` — matches any `.php` file recursively (globstar)
- `include/**/*.tpl` — matches `.tpl` files within `include/`
- `src/*/Tests/*.php` — matches in nested directories

**break 2 pattern:** Once a skill matches, `break 2` exits both the path loop and pattern loop, preventing duplicate skill entries in the results array.

### Disabled Skills Tracking Pattern

Disabled skills use a separate tracking array:

```php
private array $disabledSkills = [];

public function disable(string $name): void
{
    $this->disabledSkills[$name] = true;
}

public function isDisabled(string $name): bool
{
    return isset($this->disabledSkills[$name]);
}
```

**Why separate array?**
- `isset()` on the tracking array is O(1) lookup
- Doesn't require modifying the skill object itself
- Allows disable/enable without removing from `all()` results (filtered at query time)

**Type annotation `array<string, true>`:** The array values are always `true` (the presence of the key indicates disabled status). This is more precise than `array<string, Skill>` which the spec incorrectly showed.

### Skill Relevance Sorting

`findForPrompt()` sorts matches by relevance using substring counting:

```php
usort($matches, function (Skill $a, Skill $b) use ($prompt) {
    $aMatches = substr_count(strtolower($a->description), strtolower($prompt));
    $bMatches = substr_count(strtolower($b->description), strtolower($prompt));
    return $bMatches <=> $aMatches;
});
```

**Why substring count?** Simple heuristic that prioritizes skills whose descriptions mention the prompt terms more frequently. A description containing "PHP" 3 times ranks higher than one containing "PHP" once.

**Spaceship operator (`<=>`):** Returns -1, 0, or 1 directly, providing correct 3-way sorting:
- `$bMatches <=> $aMatches` puts higher counts first (descending order)

**Limitation:** This counts substring occurrences, not word boundaries. A description containing "PHPPHP" would count as 3 "PHP" occurrences. Acceptable for a first-pass heuristic.

### Early Exit Guard Clauses

Both classes use guard clauses for fail-fast behavior:

```php
// In loadFromDirectory()
if (!is_dir($dir)) {
    return [];
}

// In get()
if ($this->isDisabled($name)) {
    return null;
}
```

**Benefits:**
- Reduces nesting in main logic
- Documents preconditions explicitly
- Fails immediately rather than propagating invalid state

### Fail-Safe Loading with Error Logging

Invalid skills are logged and skipped rather than aborting:

```php
try {
    $skill = Skill::fromFile($file->getPathname());
    $skills[$skill->name] = $skill;
} catch (\Throwable $e) {
    error_log("Failed to load skill from {$file->getPathname()}: {$e->getMessage()}");
}
```

**Why catch `\Throwable`?** Catches all errors and exceptions, including `Error` subclasses (which `Exception` doesn't catch). Ensures one malformed skill doesn't prevent loading other valid skills.

**Why `error_log` instead of throwing?** Loading should be resilient — a single broken skill shouldn't prevent the entire skill system from initializing. Errors are logged for debugging but don't halt execution.

### Reflection for Relative Path Discovery

`loadBuiltInSkills()` uses reflection to find its own source file:

```php
$reflection = new \ReflectionClass($this);
$dir = dirname($reflection->getFileName()) . '/BuiltIn';
```

**Why reflection?** The built-in skills directory is co-located with `SkillLoader.php` (`src/Skills/BuiltIn/`). Using reflection finds this path relative to the actual source file, regardless of where the package is installed or how autoloading works.

**Alternative:** Hardcoded path. Rejected — would break if the package is installed in a non-standard location or if the directory structure changes.

### ARRAY_FILTER_USE_KEY for Filter-by-Name

The `all()` method uses `ARRAY_FILTER_USE_KEY` to filter:

```php
return array_filter(
    $this->skills,
    fn($name) => !$this->isDisabled($name),
    ARRAY_FILTER_USE_KEY
);
```

**Why ARRAY_FILTER_USE_KEY?**
- The callback receives the key (skill name), not the value
- Avoids iterating over skill objects when only names are needed for the check
- More idiomatic than a `foreach` loop with manual filtering

**Alternative:** `foreach` with `if (!$this->isDisabled($name))`. Works but more verbose.

### Glob-to-fnmatch Conversion for ** Patterns

`getForPaths()` converts globstar `**` patterns to fnmatch-compatible patterns:

```php
// If direct match failed, try converting glob ** to fnmatch patterns
if (!$patternMatched && str_contains($pattern, '**')) {
    // Convert /**/ to /*/ (matches one directory level)
    $pattern1 = str_replace('/**/', '/*/', $pattern);
    // Convert /** at end to /* (matches one directory or zero)
    $pattern2 = str_replace('/**', '/*', $pattern);
    // Also try without the ** entirely (matches zero directories)
    $pattern3 = str_replace('/**', '', $pattern);

    foreach ($paths as $path) {
        if (fnmatch($pattern1, $path) || fnmatch($pattern2, $path) || fnmatch($pattern3, $path)) {
            $patternMatched = true;
            break;
        }
    }
}
```

**Why convert?** PHP's `fnmatch()` doesn't support globstar `**` — only `*` for single-directory wildcards. The conversion handles three cases:

| Glob Pattern | Conversion | fnmatch Equivalent | Matches |
|-------------|------------|-------------------|---------|
| `include/**/Tests/*.php` | Replace `/**/` → `/*/` | `include/*/Tests/*.php` | One intermediate directory |
| `src/**` at end | Replace `/**` → `/*` | `src/*` | One subdirectory or none |
| `src/**` anywhere | Remove `/**` entirely | `src/` | Zero directories (exact match) |

**Why three variants?** A skill path like `src/**` could match:
- `src/Tests/Example.php` (one directory level)
- `src/Example.php` (zero directory levels)

Testing all three conversion variants ensures the pattern matches regardless of how many intermediate directories exist.

## Step 4.3: Built-in Skills

### SKILL.md Frontmatter Specification

Built-in skills use YAML frontmatter with a specific schema:

```yaml
---
description: Brief description of what this skill does. Use when user mentions X or works with Y.
user-invocable: true
disable-model-invocation: false
allowed-tools: "Read,Grep,Bash,Composer"
effort: high
paths:
  - "**/*.php"
  - "composer.json"
---
# Skill Title

Content goes here as markdown...
```

**Required frontmatter fields:**

| Field | Type | Description |
|-------|------|-------------|
| `description` | `string` | Human-readable description used for prompt matching — should include trigger phrases ("Use when...") |
| `paths` | `array` | Glob patterns for automatic skill triggering |

**Optional frontmatter fields:**

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `user-invocable` | `bool` | `true` | Whether skill appears in user-facing skill list |
| `disable-model-invocation` | `bool` | `false` | Skip LLM context injection when matched |
| `allowed-tools` | `?string` | `null` | Comma-separated tool names to suggest |
| `disallowed-tools` | `?string` | `null` | Comma-separated tools to disable |
| `model` | `?string` | `null` | Specific model to use for this skill |
| `effort` | `string` | `"medium"` | Complexity: `planning`, `medium`, or `high` |
| `context` | `string` | `"thread"` | `thread` (per conversation) or `global` |

**Frontmatter key naming:**
- YAML keys use kebab-case (`user-invocable`, `disable-model-invocation`)
- PHP properties use camelCase (`userInvocable`, `disableModelInvocation`)
- Serialized keys use snake_case (`user_invokable`, `disable_model_invocation`)

### Skill Triggering by File Path Patterns

Skills are automatically triggered when file paths match their `paths` patterns:

```php
public function getForPaths(array $paths): array
{
    $matches = [];

    foreach ($this->all() as $skill) {
        foreach ($skill->paths as $pattern) {
            foreach ($paths as $path) {
                if (fnmatch($pattern, $path)) {
                    $matches[] = $skill;
                    break 2;  // Exit both loops once matched
                }
            }
        }
    }

    return $matches;
}
```

**fnmatch() glob patterns:**

| Pattern | Meaning | Example Matches |
|---------|---------|-----------------|
| `*.php` | Single directory, specific extension | `foo.php`, `bar.php` |
| `**/*.php` | Recursive — any depth | `foo/bar.php`, `foo/bar/baz.php` |
| `**/*Test.php` | Recursive test files | `tests/Unit/FooTest.php` |
| `composer.json` | Exact filename match | `composer.json` |
| `include/**/*.tpl` | Specific subdirectory tree | `include/foo/bar.tpl` |

**Path triggering priority:**
1. Skills with matching path patterns are included via `getForPaths()`
2. Skills are deduplicated via `break 2` — matching once is sufficient
3. Multiple skills can match the same file
4. Skills are NOT automatically disabled by path matching — `getForPaths()` and `findForPrompt()` are separate queries

**Common path patterns for built-in skills:**

```yaml
# PHP-focused skills
paths:
  - "**/*.php"

# Test-focused skills
paths:
  - "**/*Test.php"
  - "**/tests/**/*.php"

# Composer-related skills
paths:
  - "composer.json"
  - "composer.lock"

# Frontend skills
paths:
  - "**/*.js"
  - "**/*.mjs"
  - "**/public_html/js/**"

# Template skills
paths:
  - "**/*.tpl"
  - "**/*.blade.php"
```

### Skill Content Structure for LLM Guidance

Skill content is markdown that provides specialized guidance to LLMs. The content is injected via `systemPromptContribution()`:

```php
public function systemPromptContribution(): string
{
    return "\n\n## Skill: {$this->name}\n\n{$this->content}";
}
```

**Effective skill content structure:**

```markdown
# Skill Name

Brief introduction explaining when this skill applies.

## Key Principles

1. First principle
2. Second principle
3. Third principle

## Common Patterns

Describe established patterns with code examples:

```php
// Good example
$result = $obj->method();

// Bad example (and why)
$result = $obj->{"method"}();
```

## Anti-Patterns

What to avoid and why:

```php
// Never do this because...
```

## Decision Tree

How to choose between approaches:

- If X → use A
- If Y → use B
- Otherwise → use C
```

**Skill content guidelines:**

1. **Lead with "when to use"** — The first sentence should explain when this skill is relevant
2. **Use concrete examples** — Show both good and bad code patterns
3. **Be prescriptive** — Don't hedge with "might consider" — say "do X" or "avoid Y"
4. **Include code blocks** — LLM can reference specific syntax
5. **Keep it scannable** — Use headers, lists, and short paragraphs
6. **Match trigger phrases** — The description should include phrases users might type

**Example — complete php-best-practices SKILL.md:**

```yaml
---
description: PHP best practices, PSR-12 compliance, type safety, and modern PHP patterns. Use when reviewing or writing PHP code.
user-invocable: true
disable-model-invocation: false
allowed-tools: "Read,Grep,Bash"
effort: high
paths:
  - "**/*.php"
---
# PHP Best Practices Skill

When working with PHP code, enforce these standards:

## Type Safety
- Always use `declare(strict_types=1);` at the top of every file
- Prefer union types and nullable types over docblocks
- Use `never`, `void`, `true` return types when appropriate

## PSR-12 Compliance
- Namespaces use uppercase
- Classes use PascalCase
- Methods and functions use camelCase
- Constants use UPPER_SNAKE_CASE
- Opening braces on same line, closing on new line
- 4 spaces for indentation

## Modern PHP Patterns
- Use readonly properties for immutable data
- Use constructor property promotion
- Use match expressions instead of switch
- Use nullsafe operator (?->) when appropriate
- Use anonymous classes for simple wrappers

## Error Handling
- Throw exceptions with meaningful messages
- Use specific exception types
- Always catch and handle or re-throw
- Never suppress errors with @

## Performance
- Use isset() over array_key_exists() for performance
- Prefer preallocation in loops
- Use yield for large iterables
- Cache require/include results
```

### Reflection-Based Path Discovery for Built-in Skills

Built-in skills use reflection to find their own source location:

```php
public function loadBuiltInSkills(): array
{
    $reflection = new \ReflectionClass($this);
    $dir = dirname($reflection->getFileName()) . '/BuiltIn';

    return $this->loadFromDirectory($dir);
}
```

**Why reflection instead of hardcoded path?**
- Works regardless of where the package is installed (Composer, git clone, phar)
- Works regardless of autoloading configuration
- Survives directory structure changes during refactoring
- The built-in skills directory stays co-located with `SkillLoader.php`

**Alternative considered — hardcoded path:**
```php
$dir = __DIR__ . '/BuiltIn';  // Breaks if package is installed elsewhere
```

**Alternative considered — config-based:**
```php
$dir = $config['builtinSkillsPath'];  // Requires consumer configuration
```

Reflection is the most robust solution — it discovers the path relative to the actual source file.

### Skill Override Pattern with Priority Chain

The `loadAll()` method implements a priority chain for skill overrides:

```php
public function loadAll(string $projectRoot = '.'): array
{
    $skills = [];

    // Built-in first (lowest priority)
    $builtin = $this->loadBuiltInSkills();

    // User skills override builtins
    $user = $this->loadUserSkills();
    $skills = array_merge($builtin, $user);

    // Project skills override both
    $project = $this->loadProjectSkills($projectRoot);
    $skills = array_merge($skills, $project);

    return $skills;
}
```

**Why array_merge with string keys?**
- When duplicate string keys exist, `array_merge()` keeps the **last** value
- This means later arrays override earlier ones with the same skill name
- Priority: project > user > built-in

**Practical use cases:**
1. **Override built-in** — Create `~/.candy-crush/skills/php-best-practices/SKILL.md` with custom content
2. **Disable built-in** — Create a skill with the same name that documents "this skill is deprecated"
3. **Project-specific** — Add skills in `.candy-crush/skills/` that only apply to a specific project

**Implementation note:** `array_merge()` re-indexes numeric keys. For skills with string keys (skill names), the behavior is exactly what we want — later definitions win.

## Step 4.4: Skill Integration

### SkillManager as Composition Root

`SkillManager` coordinates `SkillLoader` and `SkillRegistry` as internal components:

```php
final class SkillManager
{
    public function __construct(
        private SkillLoader $loader,
        private SkillRegistry $registry,
    ) {}
}
```

**Benefits of composition root pattern:**
- `SkillLoader` and `SkillRegistry` are internal implementation details
- Consumers interact only with `SkillManager`'s public interface
- Easy to mock for testing (inject mock loader/registry)
- Single place to change if skill loading changes

**Alternative considered:** Static factory methods on `App`. Rejected because:
- Static coupling makes testing harder
- `App` should remain focused on state management
- Separate `SkillManager` follows single responsibility

### Immutable App with Skills Pattern

The `App` class maintains two skill-related properties:

```php
public readonly array $enabledSkills;           // Skill[] - currently active
public readonly SkillRegistry $availableSkills;  // All loadable skills
```

**Immutable state transitions:**

```php
// Enable returns NEW App instance
$app = $manager->enable($app, 'phpunit-master');

// Disable returns NEW App instance
$app = $manager->disable($app, 'php-best-practices');
```

**Why immutable skill state?**
- TEA pattern consistency — `update()` returns `[newModel, command]`
- Enables undo/rollback — keep reference to old `App`
- Supports concurrent sessions — each session has own `App` with different skills
- Thread-safe for async operations

**with*() method pattern:**

```php
public function withEnabledSkills(array $v): self
{
    return $this->mutate(enabledSkills: $v);
}

public function withAvailableSkills(SkillRegistry $registry): self
{
    return $this->mutate(availableSkills: $registry);
}
```

**Note:** `availableSkills` type changed from `array` to `SkillRegistry` to match the actual usage in `findSkillsForTask()`.

### Skill Contribution to System Prompts

`applySkillsToSystemPrompt()` injects skill content into LLM context:

```php
public function applySkillsToSystemPrompt(string $baseSystemPrompt): string
{
    $result = $baseSystemPrompt;

    foreach ($this->enabledSkills as $skill) {
        if ($skill instanceof Skill) {
            $result .= $skill->systemPromptContribution();
        }
    }

    return $result;
}
```

**Output structure:**

```
You are a helpful coding assistant.

## Skill: php-best-practices

[Skill markdown content here]

## Skill: security-audit

[Security audit guidance here]
```

**Design rationale:**
- Skills are appended, not prepended — base prompt sets context first
- Each skill uses `## Skill:` header for visual distinction
- `instanceof Skill` check handles edge case of non-Skill entries in array
- Skills contribute in order they appear in `enabledSkills` array

**Why append rather than merge?**
- Base prompt provides core identity
- Skills add specialized guidance
- Later skills can override earlier guidance if needed
- Order matters — first skill's advice may be overridden by later skills

### Runtime Skill Enable/Disable Pattern

`SkillManager` provides runtime skill management that works with immutable `App`:

```php
public function enable(App $app, string $skillName): App
{
    $current = $app->enabledSkills;
    $skill = $this->registry->get($skillName);

    if ($skill === null) {
        return $app;  // Early exit: skill not found
    }

    foreach ($current as $s) {
        if ($s->name === $skillName) {
            return $app;  // Early exit: already enabled
        }
    }

    return $app->withEnabledSkills([...$current, $skill]);
}

public function disable(App $app, string $skillName): App
{
    $current = $app->enabledSkills;

    return $app->withEnabledSkills(
        array_filter($current, fn($s) => $s->name !== $skillName)
    );
}
```

**Key techniques:**
- **Early exit guards** — return original `App` if no change needed
- **Already-enabled check** — prevents duplicate entries
- **array_filter for disable** — removes by name without manual loop
- **Spread operator `[...$current, $skill]`** — creates new array with added skill

**applyToApp() for bulk operations:**

```php
public function applyToApp(App $app, array $skillNames): App
{
    $skills = [];

    foreach ($skillNames as $name) {
        $skill = $this->registry->get($name);
        if ($skill !== null) {
            $skills[] = $skill;
        }
    }

    return $app->withEnabledSkills($skills);
}
```

**Why resolve names to Skill objects?**
- `skillNames` are strings from config/CLI
- `registry->get()` handles disabled-state checking
- Returns `null` if skill doesn't exist or is disabled
- Bulk operation applies multiple skills at once

### Skill Discovery via findSkillsForTask()

`findSkillsForTask()` queries available skills by prompt matching:

```php
public function findSkillsForTask(string $task): array
{
    return $this->availableSkills->findForPrompt($task);
}
```

**Delegation pattern:**
- `App` delegates to `availableSkills` (which is a `SkillRegistry`)
- `SkillRegistry::findForPrompt()` does the actual matching
- `App` provides a convenient facade on top of the registry

**Usage pattern:**

```php
// In the TUI runtime loop
$taskSkills = $app->findSkillsForTask($userInput);
foreach ($taskSkills as $skill) {
    $app = $skillManager->enable($app, $skill->name);
}

// Or show user what skills matched
$matchCount = count($taskSkills);
```

**Why separate find from enable?**
- Discovery is query-only — doesn't modify state
- User confirms before enabling (or auto-enable based on config)
- Enables "preview" functionality in UI

### getUserInvocable() for Command Palette

`getUserInvocable()` filters skills that users can invoke directly:

```php
public function getUserInvocable(): array
{
    return $this->registry->getUserInvocable();
}
```

**Used for:**
- Command palette listings
- `/skills` command output
- User preference UI for skill selection

**Filtering logic in `SkillRegistry`:**

```php
public function getUserInvocable(): array
{
    return array_values(array_filter(
        $this->all(),
        fn($skill) => $skill->userInvocable
    ));
}
```

**Why `array_values()`?** Re-indexes the array so JSON serialization produces a clean array `[{...}, {...}]` rather than `{"0": {...}, "1": {...}}`.

### disableFromConfig() for Configuration-Based Disabling

`disableFromConfig()` bulk-disables skills from configuration:

```php
public function disableFromConfig(array $disabled): void
{
    $this->registry->disableMultiple($disabled);
}
```

**Usage:**

```php
// candy-crush.json
// {
//     "disabledSkills": ["php-best-practices", "security-audit"]
// }

$config = json_decode(file_get_contents('candy-crush.json'), true);
$manager->disableFromConfig($config['disabledSkills'] ?? []);
```

**Why `void` return?** Disabling is a mutation of the registry state, not of an `App` instance. The registry is internal to `SkillManager` and can be modified directly.

**Alternative:** Could return a new `SkillManager` with modified registry. Not done because:
- Registry is internal
- Config loading happens once at startup
- Simpler to just mutate

### SkillManager Method Return Types

All mutation methods follow a consistent pattern:

| Method | Input | Output |
|--------|-------|--------|
| `loadAll()` | `string $projectRoot` | `void` (mutates registry internally) |
| `applyToApp()` | `App $app, array $skillNames` | `App` (new instance) |
| `enable()` | `App $app, string $skillName` | `App` (new instance) |
| `disable()` | `App $app, string $skillName` | `App` (new instance) |
| `disableFromConfig()` | `array $disabled` | `void` (mutates registry) |

**Pattern rationale:**
- Methods that modify `App` return new `App` (immutable)
- Methods that modify registry return `void` or `self` (internal mutation)
- This distinction is clear from method signatures

### Dependency Injection in SkillManager

`SkillManager` uses constructor injection for its dependencies:

```php
public function __construct(
    private SkillLoader $loader,
    private SkillRegistry $registry,
) {}
```

**Benefits:**
- Dependencies are explicit and required
- Easy to mock for unit testing
- Can swap implementations (e.g., cached registry, remote loader)

**Usage with manual wiring:**

```php
$manager = new SkillManager(
    new SkillLoader(),
    new SkillRegistry()
);
```

**Usage with dependency injection container:**

```php
// If using a DI container...
$manager = $container->get(SkillManager::class);
// Container injects SkillLoader and SkillRegistry automatically
```

## Step 5.1: Hook Interface and Registry

### HookEvent as Backed Enum

`HookEvent` uses a PHP 8.1 backed enum with `string` backing type:

```php
enum HookEvent: string
{
    case PreToolUse = 'PreToolUse';
    case PostToolUse = 'PostToolUse';
}
```

**PHP 8.3 compatibility note:** String literal union types (`'PreToolUse'|'PostToolUse'`) require PHP 8.4+. A backed enum was chosen to maintain PHP 8.3 compatibility while providing type-safe event identification. This pattern is recommended when you need enum-like semantics but must support PHP 8.3.

**Why an enum rather than string constants?** Backed enums provide:
- Compile-time exhaustiveness checking in `match` expressions
- IDE autocompletion for valid values
- Self-documenting intent — `HookEvent::PreToolUse` is clearer than `'PreToolUse'`
- Type safety — `HookEvent` as a parameter type rejects arbitrary strings

### Context Immutability with with*() Builders

`HookContext` is `final readonly` — once constructed, it cannot be modified. Hooks that need to communicate changes downstream use the `with*()` builder pattern:

```php
public function withToolInput(string $input): self
{
    return new self(
        sessionId: $this->sessionId,
        toolName: $this->toolName,
        toolArgs: $this->toolArgs,
        toolInput: $input,
        toolOutput: $this->toolOutput,
        model: $this->model,
        provider: $this->provider,
        projectRoot: $this->projectRoot,
    );
}
```

**Why manual property copying?** Unlike the `mutate()` helper used in `App`, `HookContext` uses `readonly` properties which cannot be modified even via `clone`. Each `with*()` method must construct a new instance by copying all other fields explicitly.

**Alternative considered — `with()` accepting an array:**
```php
public function with(array $changes): self
{
    return new self(...array_merge([
        'sessionId' => $this->sessionId,
        // ...
    ], $changes));
}
```
Rejected because it loses named parameter clarity and makes refactoring harder (changing a property name breaks array keys silently).

### Regex-Based Hook Matching

`findMatches()` uses `preg_match()` with hook-supplied regex patterns:

```php
if (preg_match('/' . $hook->matcher() . '/i', $toolName)) {
    $matches[] = $hook;
}
```

**The `/i` flag** makes matching case-insensitive, so a `matcher()` of `'Bash'` matches both `'Bash'` and `'bash'`.

**Regex injection risk:** Since the `matcher()` pattern is injected directly into the regex, hooks must be trusted code. If hooks came from untrusted user configuration, the pattern should be validated or a simpler `fnmatch()` glob approach would be safer.

**Common patterns:**

```php
// Exact tool name
'matcher()' => 'Bash'

// Any of several tools
'matcher()' => 'Read|Edit|Grep'

// Tool name prefix
'matcher()' => 'Read.*'

// All tools (wildcard)
'matcher()' => '.*'
```

### ALLOW/DENY/MODIFY Result Pattern

`HookResult` uses a three-action pattern that maps cleanly to execution flow decisions:

```php
public const ALLOW = 'allow';
public const DENY = 'deny';
public const MODIFY = 'modify';
```

**Why three actions rather than two (allow/deny)?** The `MODIFY` action allows hooks to participate in input transformation without stopping the chain. This enables:
- Sanitization hooks that clean dangerous commands but still allow execution
- Logging hooks that observe without interfering
- Rewriting hooks that redirect intent (e.g., translating `delete` to `trash`)

**Factory methods over constructors:**
```php
HookResult::allow()       // message defaults to ''
HookResult::deny($msg)   // message required — why the action was denied
HookResult::modify($in, $msg)  // new input + optional reason
```

Requiring a message for `deny()` forces hooks to explain why they blocked execution — important for debugging and user feedback.

### Hook Chain Execution Pattern

`executeHooks()` implements a **first-result-wins** chain with modification tracking:

```php
$firstModifyResult = null;
foreach ($matches as $hook) {
    $result = $hook->execute($context);

    if ($result->isDenied()) {
        return $result;  // DENY stops everything
    }

    if ($result->isModified()) {
        $context = $context->withToolInput($result->modifiedInput);
        $firstModifyResult ??= $result;  // capture first MODIFY
    }
}
return $firstModifyResult ?? HookResult::allow();
```

**Why track `$firstModifyResult`?** If multiple hooks return MODIFY, the final return value should be the first MODIFY (not ALLOW). This ensures:
- The most semantic modification wins
- The chain preserves the "intent" of the first rewriting hook
- Later ALLOW hooks don't erase earlier modifications

**Why not return last MODIFY?** Returning the last MODIFY would mean later hooks could "undo" earlier modifications. Returning the first preserves the earliest intent in the chain.

### Regex vs Glob Pattern Matching

Hooks use PCRE regex (`preg_match`) rather than glob (`fnmatch`):

| Aspect | Regex (`preg_match`) | Glob (`fnmatch`) |
|--------|---------------------|-----------------|
| Pattern | `/Bash/i` | `*Bash*` |
| Alternation | `Read\|Edit\|Grep` | Not supported |
| Anchors | `^Bash$` | Implicit |
| Modifiers | `/i`, `/s`, `/m` | Not supported |
| Use case | Complex matching | Simple wildcards |

For the hook use case, regex provides more expressiveness (alternation, character classes) while glob would be simpler but less powerful.

### Disabled Hooks Tracking Pattern

`HookRegistry` tracks disabled hooks in a separate array:

```php
private array $disabled = [];

public function disable(string $name): void
{
    $this->disabled[$name] = true;
}

public function isDisabled(string $name): bool
{
    return $this->disabled[$name] ?? false;
}
```

**Why a separate array rather than removing from `$hooks`?**
- `unregister()` removes the hook entirely (user requested removal)
- `disable()` temporarily pauses the hook (user wants to re-enable later)
- The distinction matters for debugging — `disabled` hooks can be listed and re-enabled
- Querying `isDisabled()` is O(1) via `isset()` on the tracking array

**Type annotation `array<string, true>`:** Values are always `true` — the presence of the key indicates disabled status. This is more precise than `array<string, HookInterface>`.

## Step 5.2: Built-in Hooks Patterns

### Built-in Hook Pattern

Built-in hooks are `final readonly` classes in `Hooks\BuiltIn` namespace implementing `HookInterface`:

```php
namespace SugarCraft\Crush\Hooks\BuiltIn;

final readonly class MyHook implements HookInterface
{
    public function name(): string
    {
        return 'my-hook';
    }

    public function event(): HookEvent
    {
        return HookEvent::PreToolUse;
    }

    public function matcher(): string
    {
        return '^ToolName$';
    }

    public function execute(HookContext $context): HookResult
    {
        // Check conditions and return HookResult::allow() or HookResult::deny()
    }
}
```

**Benefits of this pattern:**
- `final` prevents extension that might break the hook contract
- `readonly` ensures the hook instance is immutable after construction
- Built-in namespace groups safety-critical hooks separately from custom hooks
- Constructor can accept configuration (e.g., `AuditHook` accepts optional `$logFile`)

### Regex-Based Tool Matching

Hooks use `preg_match()` with case-insensitive matching (`/i` flag applied in `HookRegistry::findMatches()`):

```php
// Exact match - tool name must be 'rm' exactly
public function matcher(): string
{
    return '^rm$';
}

// Match multiple tools
public function matcher(): string
{
    return '^(bash|Edit)$';
}

// Match all tools (audit pattern)
public function matcher(): string
{
    return '.*';
}
```

**Matcher semantics:**
- Anchors `^` and `$` are implicit in `preg_match()` calls within `findMatches()`
- The `/i` flag makes matching case-insensitive, so `'^rm$'` matches `rm`, `RM`, `Rm`
- Pattern `.*` matches any tool name (catch-all for post-execution hooks)

**Common matcher patterns:**

| Pattern | Matches |
|---------|---------|
| `^rm$` | Only the `rm` tool |
| `^bash$` | Only the `bash` tool |
| `^(bash\|Edit\|Write)$` | bash, Edit, or Write tools |
| `.*` | All tools |

### PreToolUse vs PostToolUse Event Types

`PreToolUse` and `PostToolUse` serve different purposes:

| Aspect | PreToolUse | PostToolUse |
|--------|------------|-------------|
| Timing | Before tool execution | After tool execution |
| Access to output | No (`toolOutput` is empty) | Yes (full output available) |
| Typical use | Validation, modification | Audit logging, response transformation |
| Can deny? | Yes | Yes |
| Can modify input? | Yes | No (tool already executed) |

**PreToolUse is for prevention:**
```php
public function execute(HookContext $context): HookResult
{
    if ($this->isDangerous($context->toolInput)) {
        return HookResult::deny('Operation not allowed');
    }
    return HookResult::allow();
}
```

**PostToolUse is for observation:**
```php
public function execute(HookContext $context): HookResult
{
    $this->log($context->toolName, $context->toolInput, $context->toolOutput);
    return HookResult::allow();  // Always allow - tool already executed
}
```

### Hook Safety Patterns (Deny vs Allow)

Hooks should follow these safety patterns:

**Deny pattern for dangerous operations:**
```php
public function execute(HookContext $context): HookResult
{
    if ($this->isDangerous($context->toolInput)) {
        return HookResult::deny('This operation is not allowed');
    }
    return HookResult::allow();
}
```

**Deny-first with clear messages:**
```php
if (preg_match('/rm\s+-[rf]+\s+/', $input)) {
    return HookResult::deny(
        'This hook prevents recursive/force rm. Use interactive rm instead.'
    );
}
```

**PostToolUse should always allow:**
```php
public function execute(HookContext $context): HookResult
{
    // Log the execution for audit purposes
    $this->logToFile($context);

    // Tool already executed - cannot deny at this point
    return HookResult::allow();
}
```

**Why always allow in PostToolUse?** By definition, the tool has already executed when a `PostToolUse` hook runs. Denying has no effect on the already-completed operation. PostToolUse hooks should use `DENY` only in exceptional cases where the output reveals a problem that should halt further processing.

**Constructor injection for testability:**
```php
public function __construct(?string $logFile = null)
{
    $this->logFile = $logFile ?? sys_get_temp_dir() . '/default.log';
}
```

Accepting optional constructor parameters allows:
- Injecting test doubles for unit testing
- Customizing file paths in production
- Changing behavior without modifying the hook class

## Step 5.3: Hook Configuration Patterns

### YAML Configuration Loading Pattern

`HookConfig::loadFromFile()` demonstrates safe YAML configuration loading with graceful degradation:

```php
public static function loadFromFile(string $path): array
{
    if (!file_exists($path)) {
        return [];
    }

    $content = file_get_contents($path);
    if ($content === false) {
        return [];
    }

    return self::parse($content);
}

public static function parse(string $content): array
{
    try {
        $data = Yaml::parse($content);
    } catch (\Exception $e) {
        return [];
    }

    $hooks = [];
    $hooksData = $data['hooks'] ?? [];

    foreach ($hooksData as $event => $configs) {
        foreach ($configs as $config) {
            $hooks[] = [
                'event' => $event,
                'matcher' => $config['matcher'] ?? '.*',
                'command' => $config['command'] ?? '',
                'description' => $config['description'] ?? '',
            ];
        }
    }

    return $hooks;
}
```

**Key patterns:**
- **Early exit guards** — `file_exists()` and `file_get_contents()` false check return empty arrays immediately
- **Exception handling at boundary** — YAML parse exceptions are caught and converted to empty results
- **Defensive defaults** — `?? '.*'` and `?? ''` provide sensible fallbacks for missing keys
- **Silent failure** — No logging or exceptions on config errors (acceptable for optional configuration)

**Why return `[]` on all failures?** The philosophy is "configuration is optional" — if the config file is missing, malformed, or empty, the system should proceed with defaults rather than crashing. This differs from required configuration which should throw.

### External Script Execution via proc_open

`ScriptHook::execute()` uses `proc_open()` for subprocess management:

```php
public function execute(HookContext $context): HookResult
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open(
        $this->command,
        $descriptors,
        $pipes,
        $context->projectRoot,
        $env
    );

    if (!is_resource($process)) {
        return HookResult::allow();
    }

    fclose($pipes[0]);

    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    if ($exitCode === 0) {
        return HookResult::allow(trim($output));
    }

    return HookResult::deny(trim($errors) ?: "Hook exited with code $exitCode");
}
```

**Why `proc_open()` over `exec()` or `shell_exec()`?**
- Full control over stdin/stdout/stderr pipes
- Environment variables can be explicitly passed
- Exit code is available for error handling
- Supports streaming input/output if needed

**Descriptor array format:**
- `0 => ['pipe', 'r']` — stdin is a readable pipe (we close it immediately since no input needed)
- `1 => ['pipe', 'w']` — stdout is a writable pipe (we read from it)
- `2 => ['pipe', 'w']` — stderr is a writable pipe (we read from it)

**Pipe management discipline:**
- `fclose($pipes[0])` immediately after `proc_open` since we don't write to stdin
- `stream_get_contents()` reads entire output before closing
- All pipes closed before `proc_close()` to avoid hanging

### Environment Variable Passing to Child Processes

Environment variables are passed as an associative array to `proc_open()`:

```php
$env = [
    'CRUSH_SESSION_ID' => $context->sessionId,
    'CRUSH_TOOL_NAME' => $context->toolName,
    'CRUSH_TOOL_INPUT' => $context->toolInput,
    'CRUSH_TOOL_OUTPUT' => $context->toolOutput,
    'CRUSH_MODEL' => $context->model,
    'CRUSH_PROVIDER' => $context->provider,
];
```

**Key principles:**

1. **Explicit is better than implicit** — Child processes don't inherit PHP's environment by default. Variables must be explicitly passed.

2. **Prefix convention** — `CRUSH_` prefix prevents collisions with system environment variables and clearly identifies the source.

3. **Working directory** — Passed as the 4th argument to `proc_open()` (`$context->projectRoot`), not via environment.

4. **Null for no inheritance** — When `proc_open()` receives an array (not null) for the environment, only those variables are passed. If the child needs system variables (PATH, etc.), they must be explicitly included:

```php
// If you need to preserve system environment:
$env = array_merge(getenv(), [
    'CRUSH_SESSION_ID' => $context->sessionId,
    // ...
]);
```

**Security consideration:** Passing user-controlled data as environment variables is safer than command-line arguments (which could be parsed by shells) or string concatenation (which risks injection). The script receives data through established channels without interpretation.

### Hook Manager Composition Pattern

`HookManager` demonstrates composition over inheritance — it coordinates existing components rather than duplicating their logic:

```php
final class HookManager
{
    public function __construct(
        private HookRegistry $registry,
    ) {}

    public function loadFromFile(string $path): void
    {
        $configs = HookConfig::loadFromFile($path);

        foreach ($configs as $config) {
            $hook = ScriptHook::fromConfig($config);
            $this->registry->register($hook);
        }
    }

    public function registerBuiltIns(): void
    {
        $this->registry->register(new BuiltIn\ProtectFilesHook());
        $this->registry->register(new BuiltIn\ConfirmRemoveHook());
        $this->registry->register(new BuiltIn\AuditHook());
    }

    public function preToolUse(HookContext $context): HookResult
    {
        return $this->registry->executeHooks(HookEvent::PreToolUse->value, $context);
    }

    public function postToolUse(HookContext $context): HookResult
    {
        return $this->registry->executeHooks(HookEvent::PostToolUse->value, $context);
    }
}
```

**Benefits of composition:**
- **Delegation not duplication** — `HookManager` doesn't reimplement registration or execution logic
- **Single responsibility** — It orchestrates; `HookRegistry` manages; `HookConfig` parses
- **Testability** — Each component can be mocked independently
- **Flexibility** — Consumers can use `HookRegistry` directly if they don't need `HookManager`'s convenience methods

**Factory method pattern for configuration:**
```php
$hook = ScriptHook::fromConfig($config);
```

The `fromConfig()` static factory accepts an array (from YAML parsing) and constructs the appropriate `ScriptHook` instance. This isolates the construction logic from the configuration format — changing the YAML schema doesn't require changing the hook classes.

**Local namespace reference:**
```php
$this->registry->register(new BuiltIn\ProtectFilesHook());
```

Within `SugarCraft\Crush\Hooks` namespace, `BuiltIn\X` resolves to `SugarCraft\Crush\Hooks\BuiltIn\X` via PHP's namespace resolution rules.

## Step 6.1: Agent Value Object Implementation

### Immutable Value Object Pattern

`Agent` uses the immutable value object pattern with `final readonly class`:

```php
final readonly class Agent
{
    public function __construct(
        public string $name,
        public string $description,
        public string $prompt,
        public string $model,
        public string $provider,
        public array $tools,
        public array $skillNames,
        public array $hooks,
        public bool $isActive,
    ) {}
}
```

**Why `final readonly`?**
- `final` prevents extension that might add mutable state
- `readonly` enforces immutability after construction
- Constructor property promotion exposes fields as public without separate property declarations

**Immutability benefits:**
- Safe to pass across components without defensive copying
- No need to track which code might modify state
- Enables functional update patterns: `newAgent = $agent->withName('new')`

### with*() Immutable Builder Pattern

Each `with*()` method creates a clone with one modified field:

```php
public function withName(string $name): self
{
    return new self(
        name: $name,
        description: $this->description,
        prompt: $this->prompt,
        model: $this->model,
        provider: $this->provider,
        tools: $this->tools,
        skillNames: $this->skillNames,
        hooks: $this->hooks,
        isActive: $this->isActive,
    );
}
```

**Design rationale:**
- Returns a **new instance**, leaving the original unchanged
- All other fields are copied verbatim from `$this`
- The return type `self` ensures the method returns the correct type
- This pattern supports the TEA architecture's immutable state transitions

**Alternative considered:** A separate `AgentBuilder` class. Rejected because:
- `Agent` is already constructed with all fields
- Only two fields need modification (`name`, `isActive`)
- A full builder would be overkill for this use case

### fromArray/toArray Serialization Pattern

`Agent` provides bidirectional serialization:

```php
public static function fromArray(array $data): self
{
    return new self(
        name: $data['name'] ?? '',
        description: $data['description'] ?? '',
        prompt: $data['prompt'] ?? '',
        model: $data['model'] ?? 'claude-sonnet-4-6',
        provider: $data['provider'] ?? 'anthropic',
        tools: $data['tools'] ?? [],
        skillNames: $data['skills'] ?? [],
        hooks: $data['hooks'] ?? [],
        isActive: $data['is_active'] ?? false,
    );
}

public function toArray(): array
{
    return [
        'name' => $this->name,
        'description' => $this->description,
        'prompt' => $this->prompt,
        'model' => $this->model,
        'provider' => $this->provider,
        'tools' => $this->tools,
        'skills' => $this->skillNames,
        'hooks' => $this->hooks,
        'is_active' => $this->isActive,
    ];
}
```

**Key design decisions:**
- `fromArray()` uses null coalescing (`??`) for safe defaults
- `toArray()` uses `snake_case` keys (`is_active`, `skillNames` → `skills`)
- The `'skills'` key in output differs from `skillNames` internal name — this mismatch requires explicit mapping
- Round-trip: `Agent::fromArray($agent->toArray())` produces an equivalent instance

### Type Constant Pattern for Enum-like Strings

`AgentDefinition` uses string constants instead of PHP enums:

```php
public const TYPE_CODER = 'coder';
public const TYPE_REVIEWER = 'reviewer';
public const TYPE_DEBUGGER = 'debugger';
public const TYPE_ARCHITECT = 'architect';
public const TYPE_TESTER = 'tester';
public const TYPE_DEVOPS = 'devops';
```

**Why constants instead of backed enums?**
- String constants serialize cleanly to JSON/YAML configuration files
- Backs enums require `->value` access when serializing
- Configuration-driven code often reads strings directly from config

**Trade-off:** No exhaustive type checking at compile time. Adding a new type requires updating `match` expressions manually. For more safety, consider a hybrid: string constants for serialization, backed enums for internal handling.

### Factory Method Pattern for Type Instantiation

`AgentDefinition` provides named factory methods for each agent type:

```php
public static function coder(string $name = 'coder'): self
{
    return new self(
        type: self::TYPE_CODER,
        name: $name,
        description: 'General coding assistant',
        prompt: 'You are a coding assistant. Help write, modify, and understand code.',
        defaultTools: ['Read', 'Edit', 'Bash'],
        defaultSkills: [],
    );
}
```

**Benefits of factory methods:**
- Self-documenting: `AgentDefinition::reviewer()` clearly creates a reviewer agent
- Default parameter values reduce boilerplate for common cases
- Each factory encapsulates the specific tools/skills for that agent type
- Consumers don't need to know the internal structure of each definition

**Factory method naming:** Uses the type string as the method name (`coder`, `reviewer`, etc.) rather than `createCoder()`. This is shorter and matches the type constant names.

### fromType() Match Dispatch Pattern

```php
public static function fromType(string $type, string $name): ?self
{
    return match ($type) {
        self::TYPE_CODER => self::coder($name),
        self::TYPE_REVIEWER => self::reviewer($name),
        self::TYPE_DEBUGGER => self::debugger($name),
        self::TYPE_ARCHITECT => self::architect($name),
        self::TYPE_TESTER => self::tester($name),
        self::TYPE_DEVOPS => self::devops($name),
        default => null,
    };
}
```

**Design rationale:**
- `match` expression provides exhaustive type checking
- `default => null` handles unknown types gracefully
- Returns `?self` (nullable) — callers must handle the `null` case
- Enables configuration-driven agent creation from YAML/JSON

**Alternative:** Throwing an exception for unknown types. `null` was chosen to allow consumers to:
- Log a warning and skip unknown types
- Provide a fallback default type
- Differentiate between "invalid type" and "valid but unhandled"
