# SugarCraft/sugar-crumbs

## Metadata
- **URL:** https://github.com/sugarcraft/sugar-crumbs
- **Language:** PHP 8.3+
- **License:** MIT
- **Status:** 🟢 v1 ready
- **Upstream:** KevM/bubbleo (69 stars, MIT)
- **Description:** PHP port of KevM/bubbleo — NavStack (navigation stack) and Breadcrumb components for terminal UIs. Immutable push/pop navigation with hierarchical breadcrumb rendering.

---

## Architecture Overview

### Core Components

sugar-crumbs is a focused navigation library providing three primitives:

| Class | Role | File |
|---|---|---|
| `NavStack` | LIFO stack with push/pop/peek/filter | `src/NavStack.php` (197 lines) |
| `Breadcrumb` | Renders NavStack as a string trail | `src/Breadcrumb.php` (211 lines) |
| `Shell` | Combines NavStack + Breadcrumb | `src/Shell.php` (66 lines) |
| `NavigationItem` | Single stack item (title + data) | `src/NavigationItem.php` (48 lines) |
| `Closable` | Interface for enter/leave lifecycle | `src/Closable.php` (23 lines) |
| `Escape` | Separator escaping for titles | `src/Escape.php` (30 lines) |
| `Url` | Path derivation/parsing | `src/Url.php` (43 lines) |
| `Lang` | i18n facade | `src/Lang.php` (22 lines) |

### Immutable + Fluent Pattern

All mutating operations return new instances (immutable + fluent):

```php
// NavStack: mutable push/pop in-place but returns popped item
$stack->push('Settings');
$popped = $stack->pop(); // modifies $stack in-place, returns popped item

// Shell: fully immutable - withPush/withPop return new Shell
$shell = Shell::new()->withPush('Home')->withPush('Settings');
$shell2 = $shell->withPop();  // new Shell instance, $shell unchanged
```

### Dependency Structure

```
sugar-crumbs
├── candy-core (dev-master)   — TUI framework foundation, Width util, Msg types
└── candy-zone (dev-master)  — Manager for mouse-click zone tracking (optional)
```

---

## Navigation Stack Architecture

### NavStack (`src/NavStack.php`)

The central data structure — a classic last-in, first-out stack:

```php
final class NavStack
{
    private const SEPARATOR = ' > ';
    private array $items = [];  // list<NavigationItem>

    public function push(string $title, mixed $data = null): self
    public function pop(): ?NavigationItem
    public function current(): ?NavigationItem       // top of stack
    public function parent(): ?NavigationItem       // item below top
    public function depth(): int
    public function isEmpty(): bool
    public function items(): array                   // returns list<NavigationItem>
    public function updateTop(mixed $data): self    // replace top's data
    public function clear(): self
    public function setItems(array $items): self      // bulk replace (for Shell immutability)
    public function view(string $separator = ' > '): string
    public function viewHtml(): string               // accessibility: aria-current="page" on last item
    public function filter(string $term): self        // type-ahead filtering
}
```

**Key design decisions:**

1. **`pop()` mutates in-place** — unlike Go bubbleo's `Pop()` which returns `tea.Cmd`, PHP's `pop()` modifies the stack directly and returns the popped `NavigationItem|null`. This is simpler for PHP's synchronous execution model.

2. **`setItems()` for Shell immutability** — Shell's `withPush()`/`withPop()` create a new NavStack copy via `setItems()` so Shell itself remains immutable.

3. **`filter()` method** — type-ahead filtering returns a **new NavStack** containing only items where title OR data matches (case-insensitive substring):

   ```php
   public function filter(string $term): self
   {
       $filtered = \array_filter($this->items, static function(NavigationItem $item) use ($term): bool {
           $titleMatch = \stripos($item->title, $term) !== false;
           $dataMatch = $item->data !== null && \stripos((string) $item->data, $term) !== false;
           return $titleMatch || $dataMatch;
       });
       $new = new self();
       $new->items = \array_values($filtered);
       return $new;
   }
   ```

4. **`viewHtml()` accessibility** — renders semantic HTML with `aria-current="page"` on the last (current) item, HTML-escaped titles, and `<nav class="breadcrumb" aria-label="Breadcrumb">` wrapper.

### NavigationItem (`src/NavigationItem.php`)

```php
final class NavigationItem implements Closable
{
    public readonly string $title;
    public readonly mixed $data;  // arbitrary payload

    public function onEnter(): void {}  // no-op by default
    public function onLeave(): void {} // no-op by default
    public function title(): string { return $this->title; }
}
```

**Key difference from Go bubbleo:** Go's `NavigationItem` holds a `tea.Model` which receives all Update/View calls when on top of the stack. PHP's `NavigationItem` holds `mixed $data` — the actual component logic (rendering, update handling) lives separately in the consuming application. This makes sugar-crumbs **framework-agnostic** — it produces strings, not bubble tea models.

### Closable Interface (`src/Closable.php`)

```php
interface Closable
{
    public function onEnter(): void;  // becomes current item
    public function onLeave(): void;   // no longer current item
    public function title(): string;
}
```

**Comparison to Go bubbleo:** Go uses a single `Close() error` method for resource cleanup when popping. PHP splits this into `onLeave()` (popped) and `onEnter()` (pushed onto), allowing side-effects in both directions.

---

## Breadcrumb Rendering

### Breadcrumb (`src/Breadcrumb.php`)

```php
final class Breadcrumb
{
    private string $separator  = ' › ';
    private string $truncator  = '… ';
    private int     $maxWidth   = 0;  // 0 = no limit
    private ?\Closure $itemRenderer = null;
    private ?Manager $zoneManager = null;

    public function setSeparator(string $s): self
    public function setTruncator(string $s): self
    public function setMaxWidth(int $w): self
    public function setItemRenderer(\Closure $fn): self   // fn(NavigationItem, int): ?string
    public function withZoneManager(?Manager $manager): self
    public function render(NavStack $stack): string
    public function renderTitles(array $titles): string
}
```

**Truncation algorithm (`truncate()` method, lines 148-171):**

Items are kept from **most-recent to oldest** until they fit within `maxWidth`. The oldest dropped item is replaced with the truncator prefix:

```php
// Example: maxWidth=30, titles=['Very Long Root', 'Medium Parent', 'Current Page']
// Output: "… Medium Parent › Current Page"  (fits within 30 chars)
```

**Zone-based mouse handling (via candy-zone `Manager`):**

When a `Manager` is attached via `withZoneManager()`, each crumb is wrapped in a named APC zone marker:

```php
// Output: "crumb-0 Home › crumb-1 Settings › crumb-2 Display"
private function wrapAllCrumbs(array $titles): string
{
    $wrapped = [];
    foreach ($titles as $i => $title) {
        $wrapped[] = $this->zoneManager->mark("crumb-{$i}", $title);
    }
    return \implode($this->separator, $wrapped);
}
```

The parent component then calls `Manager::scan()` on the output to record bounding boxes, and routes `MouseMsg` through `Manager::anyInBoundsAndUpdate()`. This keeps zone tracking **out of the crumb renderer** — composition over inheritance.

---

## Shell Change Detection

### Shell (`src/Shell.php`)

```php
final class Shell
{
    public function __construct(
        public readonly NavStack $stack,
        public readonly Breadcrumb $breadcrumb,
    ) {}

    public static function new(?Breadcrumb $breadcrumb = null): self
    public function withPush(string $title, mixed $data = null): self  // immutable
    public function withPop(): self                                   // immutable
    public function renderBreadcrumb(): string
    public function pushDirectory(string $path): self                // parses "/a/b/c" → push each segment
}
```

**`pushDirectory()` — filesystem path parsing:**

```php
public function pushDirectory(string $path): self
{
    $segments = \array_filter(\explode('/', \trim($path, '/')));
    $newStack = (new NavStack())->setItems($this->stack->items());
    $acc = '';
    foreach ($segments as $segment) {
        $acc .= '/' . $segment;
        $newStack->push($segment, $acc);  // data = cumulative path
    }
    return new self($newStack, $this->breadcrumb);
}
```

Example: `pushDirectory('/home/user/projects/sugarcraft/src')` pushes:
- `('home', '/home')`
- `('user', '/home/user')`
- `('projects', '/home/user/projects')`
- `('sugarcraft', '/home/user/projects/sugarcraft')`
- `('src', '/home/user/projects/sugarcraft/src')`

**Shell change detection:** The Shell itself does **not** have an explicit "shell changed" listener. The change detection pattern is:
1. Application tracks current working directory (or URL path) externally
2. On change, calls `Shell::pushDirectory()` with the new path
3. Renders `Shell::renderBreadcrumb()` to show the new trail

This is a **pull** model — the application polls or observes and pushes changes, rather than the Shell subscribing to filesystem events.

---

## Type-Ahead Filtering

### NavStack::filter()

The `filter(string $term): self` method provides type-ahead filtering by searching both title and data:

```php
// Case-insensitive substring match on title OR data
$filtered = $stack->filter('dis');
// Returns new NavStack with only matching items

$filtered = $stack->filter('/set');
// Matches items where data contains '/set'
```

**Filtering semantics:**
- Case-insensitive substring matching (`stripos`)
- Matches against `NavigationItem::$title`
- Also matches against `NavigationItem::$data` (cast to string via `(string)$item->data`)
- Returns **new NavStack** — original unchanged
- Empty result if no matches

**Comparison to other implementations:**

| Library | Filter Type | Ranking | Notes |
|---|---|---|---|
| sugar-crumbs | Substring | None | Title + data, case-insensitive |
| bubbles List | Fuzzy (sahilm/fuzzy) | Score + indices | Character index reporting |
| bubblelister | Substring | None | Pluggable prefixer/suffixer |
| promptkit | Fuzzy | Score | Auto-insert common prefix |

---

## Url Derivation

### Url (`src/Url.php`)

```php
final class Url
{
    public static function derive(NavStack $stack): string
    // ['home', 'settings', 'display'] → '/home/settings/display'

    public static function parse(string $path): NavStack
    // '/home/settings/display' → NavStack with 3 items
}
```

- `derive()` uses `rawurlencode()` on each segment
- `parse()` uses `rawurldecode()` and ignores empty segments
- Round-trip safe: `Url::parse(Url::derive($s))->depth() === $s->depth()`

---

## Escape Handling

### Escape (`src/Escape.php`)

```php
final class Escape
{
    private const SEPARATOR = ' > ';

    public static function title(string $title): string
    // 'Settings > Display' → 'Settings\ > Display'

    public static function unescape(string $title): string
    // 'Settings\ > Display' → 'Settings > Display'
}
```

Prevents separator collision when titles contain ` > `. The escape character is backslash `\`.

---

## Comparison: sugar-crumbs vs bubbleo

### Architectural Differences

| Aspect | bubbleo (Go) | sugar-crumbs (PHP) |
|---|---|---|
| **Framework coupling** | Tight — uses `tea.Model` interface | Loose — pure string output |
| **Navigation model** | Message-driven via `PushNavigation`/`PopNavigation` | Direct method calls |
| **NavigationItem** | Holds `tea.Model` (component receives Update/View) | Holds `mixed $data` (payload only) |
| **Lifecycle hooks** | `Close() error` on pop only | `onEnter()` + `onLeave()` |
| **Shell model** | `tea.Model` (Init/Update/View) | Plain PHP class (no tea.Model) |
| **Window** | Explicit `window.Model` for dimension offsets | Not needed (no tea.WindowSizeMsg) |
| **Styles** | Lipgloss styles for framing | Plain strings (candy-sprinkles optional) |
| **Menu component** | Included — wraps bubbles/list | **Not ported** (Menu is in bubbleo, not sugar-crumbs) |
| **Type-ahead filter** | Not present | `NavStack::filter()` |
| **Path parsing** | Not present | `Shell::pushDirectory()` |
| **URL derive/parse** | Not present | `Url` class |
| **Separator escaping** | Not present | `Escape` class |
| **Truncation** | Not present | `Breadcrumb::setMaxWidth()` |
| **HTML render** | Not present | `NavStack::viewHtml()` with ARIA |
| **i18n** | Not present | `Lang` class with `crumbs` namespace |
| **Zone mouse tracking** | Not present | `Breadcrumb::withZoneManager()` |

### bubbleo Components NOT in sugar-crumbs

1. **Menu component** (`menu/model.go`) — wraps `bubbles/list` for choice selection. sugar-crumbs does not port this; use `SugarCraft\Bits\ItemList` or `SugarCraft\Prompt\Form` instead.

2. **Styles package** — lipgloss-based styling constants. sugar-crumbs is unstyled by default; apply `SugarCraft\Sprinkles\Style` to output strings as desired.

3. **Window model** — offset-based dimension management. Not needed without bubbletea's `tea.WindowSizeMsg`.

4. **Message-driven navigation** — `PushNavigation`/`PopNavigation` messages with `tea.Cmd` return values. sugar-crumbs uses direct method calls suitable for PHP's synchronous model.

5. **`tea.Sequence` ordering** — bubbleo's `tea.Sequence(pop, cmd)` ensures pop executes before subsequent command. PHP has no equivalent since `pop()` modifies in-place.

---

## File References

### Source Files
- `/home/sites/sugarcraft/sugar-crumbs/src/NavStack.php` — 197 lines
- `/home/sites/sugarcraft/sugar-crumbs/src/Breadcrumb.php` — 211 lines
- `/home/sites/sugarcraft/sugar-crumbs/src/Shell.php` — 66 lines
- `/home/sites/sugarcraft/sugar-crumbs/src/NavigationItem.php` — 48 lines
- `/home/sites/sugarcraft/sugar-crumbs/src/Closable.php` — 23 lines
- `/home/sites/sugarcraft/sugar-crumbs/src/Escape.php` — 30 lines
- `/home/sites/sugarcraft/sugar-crumbs/src/Url.php` — 43 lines
- `/home/sites/sugarcraft/sugar-crumbs/src/Lang.php` — 22 lines

### Tests
- `/home/sites/sugarcraft/sugar-crumbs/tests/NavStackTest.php` — 275 lines (push/pop, filter, view, Shell, pushDirectory)
- `/home/sites/sugarcraft/sugar-crumbs/tests/NavStackHtmlTest.php` — 142 lines (viewHtml, ARIA, HTML escaping)
- `/home/sites/sugarcraft/sugar-crumbs/tests/EscapeTest.php` — 87 lines (round-trip escaping)
- `/home/sites/sugarcraft/sugar-crumbs/tests/ClosableTest.php` — 60 lines (onEnter/onLeave no-ops)
- `/home/sites/sugarcraft/sugar-crumbs/tests/UrlDerivationTest.php` — 128 lines (round-trip, encode/decode)
- `/home/sites/sugarcraft/sugar-crumbs/tests/LangCoverageTest.php` — 66 lines (i18n wiring verification)

### Examples
- `/home/sites/sugarcraft/sugar-crumbs/examples/basic.php` — NavStack + Breadcrumb demo
- `/home/sites/sugarcraft/sugar-crumbs/examples/navigation.php` — push/pop/filter/pushDirectory demo

### Config
- `/home/sites/sugarcraft/sugar-crumbs/phpunit.xml` — PHPUnit 10 config, `failOnWarning=true`
- `/home/sites/sugarcraft/sugar-crumbs/lang/en.php` — i18n strings (separator, truncator)

---

## Analysis

**sugar-crumbs** is a faithful, well-extended PHP port of KevM/bubbleo that deliberately decouples from the bubbletea framework. Where bubbleo uses `tea.Model` interfaces and message-driven navigation, sugar-crumbs uses plain PHP classes with direct method calls and produces raw strings — making it usable with any TUI framework (not just candy-core/bubbletea).

### Strengths

1. **Framework-agnostic** — breadcrumb output is just strings. Works with any TUI framework or even non-TUI contexts (URL display, CLI output).

2. **Immutable Shell** — `withPush()`/`withPop()` return new instances, making state predictable.

3. **Type-ahead filtering** — `filter()` method enables quick navigation through deep stacks.

4. **Path parsing** — `pushDirectory()` maps filesystem paths to navigation items with cumulative data.

5. **Truncation with ellipsis** — `setMaxWidth()` keeps breadcrumbs readable in constrained terminals.

6. **HTML rendering with accessibility** — `viewHtml()` produces semantic HTML with `aria-current="page"` for screen readers.

7. **Zone-based mouse tracking** — optional `Manager` integration for clickable breadcrumbs.

8. **Separator escaping** — prevents corruption when titles contain ` > `.

9. **Comprehensive test coverage** — 678+ lines of tests covering all public APIs.

### Gaps

1. **No Menu component** — bubbleo's menu (wrapping bubbles/list) is not ported. Use `SugarCraft\Bits\ItemList` or `SugarCraft\Prompt\Form` for selection UI.

2. **No message-driven navigation** — bubbleo's `PushNavigation`/`PopNavigation` messages with `tea.Cmd` return values have no PHP equivalent. Applications must call methods directly.

3. **No animation/transition support** — direct `View()` output with no animated transitions between stack states.

4. **No built-in back-button handling** — applications handle ESC key / backspace to pop the stack. The library provides the primitives but not the keybinding.

5. **Closable lifecycle asymmetry** — Go's `Close()` is only called on pop (leave). PHP's `onEnter()`/`onLeave()` are both no-ops by default with no automatic invocation in the base NavStack.

### Strategic Position

sugar-crumbs is the navigation primitive for SugarCraft TUI applications. Its framework-agnostic design makes it a general-purpose library for any PHP application needing hierarchical navigation breadcrumbs. The `Closable` interface provides a hook for resource cleanup on navigation changes, and the `filter()` method enables type-ahead search without fuzzy ranking.

---

## Related Reports

- `/home/sites/sugarcraft/repo_map/KevM_bubbleo.md` — Primary upstream (Go)
- `/home/sites/sugarcraft/repo_map/charmbracelet_bubbletea.md` — Framework sugar-crumbs can integrate with
- `/home/sites/sugarcraft/repo_map/sugarcraft_candy-zone.md` — Mouse zone tracking for clickable breadcrumbs
- `/home/sites/sugarcraft/repo_map/sugarcraft_candy-core.md` — TUI framework foundation
- `/home/sites/sugarcraft/repo_map/treilik_bubblelister.md` — List widget alternative for selection UI
- `/home/sites/sugarcraft/repo_map/erikgeiser_promptkit.md` — Prompt library with fuzzy filtering
