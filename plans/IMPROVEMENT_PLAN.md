# SugarCraft Improvement Plan

**Generated**: 2026-05-09
**Project**: SugarCraft PHP TUI Monorepo
**Scope**: 46 libraries/apps

---
All Commits should be done by name Joe Huss email detain@interserver.net
Commits should be frequent and descriptive.  make a PR and accept it and delete old branch after each item is done.
Do not wait for me between items but keep going on instead until everything is done or you have questions that need answered.

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Cross-Cutting Improvements](#2-cross-cutting-improvements)
3. [Foundation Libraries (candy-core, candy-sprinkles, honey-bounce)](#3-foundation-libraries)
4. [Terminal/Virtual Terminal (candy-vt, candy-pty)](#4-terminalvirtual-terminal-libraries)
5. [Components (sugar-bits, sugar-charts, sugar-prompt)](#5-component-libraries)
6. [Styling & Rendering (candy-shine, candy-sprinkles)](#6-styling--rendering-libraries)
7. [SSH/Network (candy-wish, candy-metrics, sugar-wishlist)](#7-sshnetwork-libraries)
8. [CLI Shell & Tools (candy-shell, sugar-glow, candy-freeze)](#8-cli-shell--tools)
9. [Image/Media (candy-mosaic, candy-flip)](#9-imagemedia-libraries)
10. [Games (candy-tetris, sugar-crush, candy-mines, honey-flap)](#10-game-libraries)
11. [Apps (super-candy, sugar-stash, candy-query, sugar-tick)](#11-full-applications)
12. [Smaller Components (various sugar-* libs)](#12-smaller-components)
13. [Documentation & Website](#13-documentation--website)
14. [Testing & CI/CD](#14-testing--cicd)
15. [Prioritized Roadmap](#15-prioritized-roadmap)

---

## 1. Executive Summary

### Project Health Assessment

| Category | Status | Notes |
|----------|--------|-------|
| Core Architecture | 🟢 Strong | Elm-inspired architecture well-implemented |
| Code Quality | 🟡 Mixed | Most libs follow PSR-12, some need strict_types enforcement |
| Test Coverage | 🟡 Inconsistent | Some libs have extensive tests (candy-core: 29), others minimal |
| Documentation | 🟡 Incomplete | Most READMEs are skeletal |
| PHP Version Parity | 🟡 Mixed | Some 8.2+/8.3+ requirements create fragmentation |
| CI/CD | 🟢 Good | Comprehensive matrix testing, coverage reporting |

### Key Issues Found

1. **Missing strict_types**: Several files lack `declare(strict_types=1)`
2. **Inconsistent immutability**: Not all classes follow the `with*()` + `mutate()` pattern
3. **Test gaps**: Many libs have single-digit test counts
4. **Documentation debt**: READMEs need quickstart examples and API docs
5. **Version fragmentation**: candy-vt/vcr require 8.2+, candy-wish requires 8.3+
6. **Missing VHS demos**: Most libs lack `.vhs/` demo files
7. **No PHPStan/Psalm**: No static analysis in CI pipeline

---

## 2. Cross-Cutting Improvements

### 2.1 PHPStan/Psalm Integration

**Current State**: No static analysis in CI pipeline
**Target**: PHPStan Level 5-6 minimum, Level 8 goal

**Implementation**:
```bash
# Add to each lib's composer.json
"require-dev": {
    "phpstan/phpstan": "^1.10",
    "phpstan/phpstan-phpunit": "^1.3"
}
```

**Priority**: HIGH
**Effort**: Medium
**Impact**: Catches type errors before runtime

### 2.2 Strict Types Enforcement

**Current State**: ~15% of files missing `declare(strict_types=1)`
**Target**: 100% compliance

**Files needing strict_types** (partial list):
- `candy-core/src/Util/Width.php`
- `candy-core/src/Util/Tty.php`
- `honey-bounce/src/Vector.php`
- `sugar-bits/src/TextInput/TextInput.php` (partial)
- `sugar-charts/src/Canvas/Cell.php`

**Priority**: HIGH
**Effort**: Low
**Impact**: Type safety, better IDE support

### 2.3的统一Immutability Pattern

**Current State**: Inconsistent `with*()` implementation across libs
**Target**: All state-bearing classes use readonly + with*()

**Canonical Pattern** (from candy-core):
```php
final readonly class Foo
{
    public function __construct(
        public string $name,
        public int $value,
    ) {}

    private function mutate(callable $fn): self
    {
        $clone = clone $this;
        $fn($clone);
        return $clone;
    }

    public function withName(string $name): self
    {
        return $this->mutate(fn($c) => $c->name = $name);
    }
}
```

**Libs needing refactoring**:
- sugar-bits/TextArea (height tracking is mutable)
- sugar-bits/Table (columns stored as array, not readonly)
- sugar-charts/Canvas (pixel grid mutation)
- candy-tetris/Board (direct cell array mutation)

**Priority**: MEDIUM
**Effort**: High
**Impact**: Predictable state, better testing

### 2.4 Centralized Error Handling

**Current State**: Each lib has ad-hoc exception handling
**Target**: `SugarCraft\Core\Exception` namespace

**Proposed Structure**:
```
SugarCraft\Core\Exception\
├── InvalidArgumentException
├── RuntimeException
├── TerminalException
├── RenderException
└── ProgramException
```

**Priority**: MEDIUM
**Effort**: Low
**Impact**: Consistent error API

### 2.5 Async/Await Support

**Current State**: Synchronous-only, relies on ReactPHP event loop
**Target**: Coroutine-based async commands

**Reference**: Go's `tea.NewProgram` supports concurrent commands natively

**Proposed Addition**:
```php
namespace SugarCraft\Core;

interface Cmd
{
    public function run(): \Generator;
}

// SugarCrush::ai() could then:
yield from $this->backend->send($msg);
```

**Priority**: LOW (requires significant design)
**Effort**: High
**Impact**: Better concurrency story

### 2.6 Unified Logger Interface

**Current State**: Multiple libs use ad-hoc logging or none
**Target**: `SugarCraft\Core\Log\Logger` as standard interface

**Reference**: candy-log already exists but is separate

**Proposed**: Extract Logger interface to candy-core, implement in candy-log

**Priority**: MEDIUM
**Effort**: Medium
**Impact**: Debuggability, observability

---

## 3. Foundation Libraries

### 3.1 candy-core (THE core TUI runtime)

**Purpose**: Elm-architecture TUI runtime - the heart of SugarCraft
**Current Tests**: 29 tests
**Status**: 🟢 v1 ready

#### Current Strengths
- Comprehensive Msg/Cmd type system
- Solid InputReader with key/mouse parsing
- Well-tested Renderer with SGR state machine
- Good i18n infrastructure with Lang wrapper

#### Improvements Identified

**A. Program Lifecycle Hooks**
```php
// Missing lifecycle callbacks
class Program
{
    public function withBeforeRender(callable $fn): self;
    public function withAfterRender(callable $fn): self;
    public function withErrorHandler(callable $handler): self;
}
```
**Priority**: MEDIUM | **Effort**: Low | **Benefit**: Better extensibility

**B. Batch Message Processing**
```php
// Currently: each Msg processed sequentially
// Proposed: batch updates for performance
public function updateBatch(array $msgs): array {
// Returns array of [Model, ?Cmd] tuples
}
```
**Priority**: MEDIUM | **Effort**: Medium | **Benefit**: Performance for high-frequency updates

**C. Cursor Shape Support**
```php
// Go bubbletea supports cursor shapes (block, underline, bar)
// PHP port missing these SGR sequences
public const CURSOR_BLOCK = "\x1b[2 q";
public const CURSOR_UNDERLINE = "\x1b[4 q";
public const CURSOR_BAR = "\x1b[6 q";
```
**Priority**: LOW | **Effort**: Low | **Benefit**: UX parity

**D. Bracketed Paste Mode**
```php
// Go supports bracketed paste for multiline input
// Currently PHP only handles single-line paste
public function enableBracketedPaste(): bool;
public function disableBracketedPaste(): bool;
```
**Priority**: MEDIUM | **Effort**: Medium | **Benefit**: Better paste handling

**E. Mouse Tracking Enhancements**
```php
// Current: basic mouse button tracking
// Missing: wheel events, touch events, drag tracking
public function withMouseWheel(): self;
public function withMouseDrag(): self;
public function withMouseTouch(): self;
```
**Priority**: MEDIUM | **Effort**: Medium | **Benefit**: Feature parity

**F. Focus Management**
```php
// Missing: explicit focus in/out messages
Msg::FocusLost
Msg::FocusGained
Msg::ViewportResized(width: int, height: int)
```
**Priority**: HIGH | **Effort**: Low | **Benefit**: Required for form components

**G. Performance Profiling**
```php
// Missing: render time tracking
public function getLastRenderTime(): float;
public function getTotalRenderTime(): float;
public function enableProfiling(): self;
```
**Priority**: LOW | **Effort**: Medium | **Benefit**: Debugging tool

**H. Alternative Event Loop Support**
```php
// Currently coupled to ReactPHP
// Should support: evenloop/ev, Icicle, AMPHP
public function withEventLoop(EventLoopInterface $loop): self;
```
**Priority**: LOW | **Effort**: High | **Benefit**: Flexibility

**I. Streaming Render Output**
```php
// Currently: full buffer render each frame
// Could support: incremental diff rendering
public function renderIncremental(Model $model): \Generator;
```
**Priority**: LOW | **Effort**: High | **Benefit**: Performance

#### Feature Ideas Based on Similar Software

| Feature | Description | upstream Ref |
|---------|-------------|--------------|
| Multiple viewports | Tabs/panes with independent scroll | `charmbracelet/bubbletea` v0.30+ |
| Overlay/modal support | Built-in overlay rendering | `rmhubbert/bubbletea-overlay` |
| Custom key bindings | Per-component keymap override | `charmbracelet/huh` |
| Undo/redo support | Command history for text inputs | `erikgeiser/promptkit` |
| Autocomplete menu | Popup autocomplete list | `charmbracelet/bubbles` TextInput |
| Internationalization | Full RTL support, locale-aware formatting | `charmbracelet/bubbletea` i18n |
| Theme persistence | Save/load theme preferences | `charmbracelet/glow` |

### 3.2 candy-sprinkles (Declarative Styling)

**Purpose**: Terminal styling and layout (port of lipgloss)
**Current Tests**: 15 tests
**Status**: 🟢 v1 ready

#### Current Strengths
- Comprehensive Style system with fluent API
- Good border implementation
- Table, Tree, ItemList components solid

#### Improvements Identified

**A. Missing Style Properties**
```php
// Missing from current implementation
$style->overline()           // Horizontal line above text
$style->strikethrough()      // Crossed-out text
$style->invisible()          // Hidden text (not same as transparent bg)
$style->reverse()            // Swap foreground/background
$style->blink()              // Blinking text (rarely supported)
$style->dim()                // Reduced intensity
```
**Priority**: MEDIUM | **Effort**: Low | **Benefit**: Feature parity

**B. Gradient Support**
```php
// Missing: horizontal text gradients
$style->foregroundGradient('#ff0000', '#0000ff');
$style->backgroundGradient($colors); // For borders/backgrounds
```
**Priority**: LOW | **Effort**: Medium | **Benefit**: Visual appeal

**C. Inline Style Parsing**
```php
// Missing: parse inline style strings like Go
Style::Parse("\x1b[1;31mBold Red\x1b[0m");
Style::FromLegacy("1;31");  // Parse SGR legacy format
```
**Priority**: MEDIUM | **Effort**: Medium | **Benefit**: Interop

**D. Conditional Styles**
```php
// Missing: styles that adapt to terminal capabilities
$style->ifMonochrome(fn() => $monochromeStyle);
$style->ifTrue($condition, fn() => $style);
```
**Priority**: LOW | **Effort**: Low | **Benefit**: Flexibility

**E. Layout Debug Mode**
```php
// Missing: visualize layout boundaries
$layout->debugBorders();
$layout->debugWhitespace();
```
**Priority**: MEDIUM | **Effort**: Medium | **Benefit**: Debugging aid

#### Feature Ideas

| Feature | Description | upstream Ref |
|---------|-------------|--------------|
| Shadow effects | Box shadows for floating elements | `lipgloss` (planned) |
| Rounded corners | Various border radii | `lipgloss` v0.11+ |
| Nested styles | Multiple foreground/background layers | `lipgloss` |
| Custom border characters | Unicode box-drawing alternatives | `lipgloss` |
| Align within columns | Horizontal alignment in table cells | `bubbles` Table |

### 3.3 honey-bounce (Physics)

**Purpose**: Damped spring physics + Newtonian simulation
**Current Tests**: 3 tests
**Status**: 🟢 v1 ready

#### Current Strengths
- Clean Spring implementation
- Good Vector/Point math

#### Improvements Identified

**A. Spring Collections**
```php
// Missing: managing multiple springs
class SpringCollection
{
    /** @var array<string, Spring> */
    private array $springs = [];

    public function add(string $id, Spring $spring): void;
    public function remove(string $id): void;
    public function tick(): array; // Returns all updated values
    public function get(string $id): float;
}
```
**Priority**: MEDIUM | **Effort**: Medium | **Benefit**: UI animation support

**B. Physics Integrators**
```php
// Missing: different integration methods
enum Integrator {
    case Euler;
    case Verlet;      // For cloth/rope simulation
    case RK4;         // Runge-Kutta 4th order
    case Symplectic;  // Better energy preservation
}
```
**Priority**: LOW | **Effort**: Medium | **Benefit**: Physics accuracy

**C. Collision Detection**
```php
// Missing: basic collision primitives
interface Collider {
    public function intersects(Collider $other): bool;
    public function contains(Point $point): bool;
}

class Circle implements Collider { ... }
class Rectangle implements Collider { ... }
class AABB implements Collider { ... } // Axis-aligned bounding box
```
**Priority**: LOW | **Effort**: Medium | **Benefit**: Game support

**D. Animation Easing Functions**
```php
// Missing: standard easing functions
enum Easing {
    case Linear;
    case QuadraticIn;
    case QuadraticOut;
    case QuadraticInOut;
    case CubicIn;
    case CubicOut;
    case CubicInOut;
    case ElasticOut;
    case BounceOut;
    case BackOut;  // Overshoot then settle
}
```
**Priority**: MEDIUM | **Effort**: Low | **Benefit**: Smooth animations

#### Feature Ideas

| Feature | Description | upstream Ref |
|---------|-------------|--------------|
| Sprite animation | Keyframe interpolation | Custom |
| Particle system | Emitter, particles, forces | Custom |
| Rigid body dynamics | Mass, velocity, angular momentum | Custom |
| Constraint solver | Distance, angle, pinned constraints | `matter.js` port |
| Path following | Spline interpolation | Custom |

---

## 4. Terminal/Virtual Terminal Libraries

### 4.1 candy-vt (In-Memory Terminal Emulator)

**Purpose**: ANSI byte stream to cell grid (port of charmbracelet/x/vt)
**Current Tests**: 22 tests
**Status**: 🟡 in progress (requires PHP 8.2+)

#### Current Strengths
- Comprehensive ANSI parser
- Good handler architecture
- Sixel support

#### Improvements Identified

**A. DECSC Support (Cursor Save/Restore)**
```php
// Missing: DECSC (Save Cursor) and DECRC (Restore Cursor)
// Should save/restore: cursor position, attributes, wrap mode, origin mode
public const DECSC = "\x1b7";  // Actually \x1b[s
public const DECRC = "\x1b8";  // Actually \x1b[u
```
**Priority**: HIGH | **Effort**: Medium | **Benefit**: App compatibility

**B. Soft Reset**
```php
// Missing: DECSTR (Soft Reset)
public const DECSTR = "\x1b[!p";
// Should reset: scroll region, keyboard mode, etc.
```
**Priority**: MEDIUM | **Effort**: Medium | **Benefit**: Terminal compatibility

**C. Window Title (OSC 0, 1, 2, 21)**
```php
// Missing: OSC 0 (set icon name + title), OSC 2 (set title)
public function setTitle(string $title): void;
public function setIconName(string $name): void;
```
**Priority**: MEDIUM | **Effort**: Low | **Benefit**: UX

**D. Mouse Protocol Support**
```px
// Missing: DECSET 1000/1001/1002/1003/1005/1006/1015
// SGR mouse reporting
public function enableSgrMouse(): void;
public function enableUrxvtMouse(): void;
public function disableMouse(): void;
```
**Priority**: HIGH | **Effort**: Medium | **Benefit**: Mouse apps

**E. Alt Screen Buffer**
```php
// Missing: alternate screen buffer support (DECSET 47, 1047, 1048, 1049)
public function enableAltScreen(): void;
public function disableAltScreen(): void;
public function isAltScreen(): bool;
```
**Priority**: HIGH | **Effort**: Medium | **Benefit**: Full terminal emulation

**F. Synchronized Output (Syncbomb)**
```php
// Missing: OSC 2026 (synchronized output for performance)
public const OSC_SYNC_START = "\x1b]2026=start\x1b\\";
public const OSC_SYNC_STOP = "\x1b]2026=stop\x1b\\";
```
**Priority**: LOW | **Effort**: Medium | **Benefit**: Performance

**G. UTF-8 Width Algorithm**
```php
// Current: uses simple strlen/mb_strwidth
// Should implement: East Asian Width algorithm (WARN, F, H)
public static function unicodeWidth(string $codepoint): int;
```
**Priority**: MEDIUM | **Effort**: Medium | **Benefit**: Correct Unicode handling

#### Feature Ideas

| Feature | Description | upstream Ref |
|---------|-------------|--------------|
| Bracketed paste mode | OSC 2004 | `x/vt` |
| QuickEdit mode | Windows terminal mode | `x/vt` |
| Title restoration | OSC 22/23 (push/pop title) | `x/vt` |
| Selection mode | OSC 52 (clipboard) | `x/vt` |
| Hyperlinks | OSC 8 | `x/vt` (partially done) |

### 4.2 candy-pty (Pseudo-Terminal)

**Purpose**: PTY abstraction via FFI
**Current Tests**: (in parent)
**Status**: 🟢 v1 ready (requires PHP 8.1 with FFI)

#### Current Strengths
- Clean open/spawn/resize API
- Signal forwarding

#### Improvements Identified

**A. Signal Handling Improvements**
```php
// Missing: more signal support
public function onSignal(int $signo, callable $handler): void;
public function ignoreSignal(int $signo): void;
public function getSignals(): array;
```
**Priority**: MEDIUM | **Effort**: Low | **Benefit**: Control

**B. PTY Pair (pty/ty persistence)**
```php
// Missing: ptypair for slave/master separation
class PtyPair
{
    public readonly Pty $master;
    public readonly Pty $slave;
    public static function open(): self;
}
```
**Priority**: LOW | **Effort**: High | **Benefit**: Flexibility

**C. Non-blocking I/O**
```php
// Missing: select/poll/epoll support
public function waitForReadability(int $timeoutMs = -1): bool;
public function waitForWritability(int $timeoutMs = -1): bool;
```
**Priority**: MEDIUM | **Effort**: Medium | **Benefit**: Async support

**D. Window Size Notifications**
```php
// Missing: SIGWINCH handling from child
public function onWindowSizeChange(callable $callback): void;
```
**Priority**: MEDIUM | **Effort**: Medium | **Benefit**: Responsive terminal apps

#### Feature Ideas

| Feature | Description | upstream Ref |
|---------|-------------|--------------|
| Multiple PTY support | Simultaneous child processes | Custom |
| PTY forwarding | SSH session PTY forwarding | `charmbracelet/wish` |
| Terminal info database | Terminfo/termcap parsing | Custom |

---

## 5. Component Libraries

### 5.1 sugar-bits (Pre-built TUI Components)

**Purpose**: 15 pre-built components (port of charmbracelet/bubbles)
**Current Tests**: 22 tests
**Status**: 🟡 in progress

#### Current Strengths
- Good component coverage
- Consistent API design

#### Improvements Identified

**A. TextInput Enhancements**
```php
// Missing: placeholder text styling
$input->withPlaceholderStyle(Style::new()->foreground(Color::Grey));

// Missing: prefix/suffix support
$input->withPrefix('$ ');
$input->withSuffix(' >');

// Missing: vim keybindings mode
$input->withVimMode(true);

// Missing: history (up/down arrows)
$input->withHistory(['cmd1', 'cmd2']);
$input->withHistoryLimit(100);
```
**Priority**: HIGH | **Effort**: Medium | **Benefit**: User experience

**B. TextArea Improvements**
```php
// Missing: line number gutter styling
$area->withLineNumberStyle(Style::new()->dim());

// Missing: soft wrap toggle
$area->withSoftWrap(false);

// Missing: read-only mode
$area->withReadOnly(true);
```
**Priority**: MEDIUM | **Effort**: Low | **Benefit**: Editor features

**C. FilePicker Enhancements**
```php
// Missing: file type icons
$picker->withFileIcons(true);

// Missing: hidden files toggle
$picker->showHidden(false);

// Missing: directory-first sorting
$picker->withDirectoryFirst(true);

// Missing: multiple selection
$picker->withMultiSelect(true);
```
**Priority**: MEDIUM | **Effort**: Medium | **Benefit**: UX

**D. ProgressBar Enhancements**
```php
// Missing: showing percentage
$progress->withShowPercent(true);

// Missing: displaying current/total
$progress->withShowValue(true, '%d/%d');

// Missing: error state
$progress->withError('Failed to load');
```
**Priority**: MEDIUM | **Effort**: Low | **Benefit**: UX

**E. Spinner Enhancements**
```php
// Missing: sequential frames
$spinner->withSequential(true);

// Missing: non-deterministic mode
$spinner->withIndeterminate(true);
```
**Priority**: LOW | **Effort**: Low | **Benefit**: Flexibility

**F. Timer/Stopwatch Enhancements**
```php
// Missing: pause/resume
$timer->pause();
$timer->resume();

// Missing: multiple timers
// Missing: timer events (onTick, onComplete)
```
**Priority**: MEDIUM | **Effort**: Medium | **Benefit**: Usability

**G. Viewport Enhancements**
```php
// Missing: smooth scrolling
$viewport->withSmoothScroll(true);

// Missing: scroll indicators
$viewport->withScrollIndicators(true);
```
**Priority**: LOW | **Effort**: Medium | **Benefit**: UX

**H. Help Component Enhancements**
```php
// Missing: keybinding descriptions
// Missing: collapsible sections
// Missing: search/filter
```
**Priority**: LOW | **Effort**: Medium | **Benefit**: Help text organization

**I. Missing Components**
```php
// Not yet ported from Go bubbles:
- CycleList (cyclic selector)
- Pager (page navigation)
- Text transform (uppercase, lowercase, titlecase)
```
**Priority**: MEDIUM | **Effort**: High | **Benefit**: Completeness

#### Feature Ideas

| Feature | Description | upstream Ref |
|---------|-------------|--------------|
| Autocomplete popup | Floating autocomplete menu | Custom |
| Syntax highlighting | Code editor with highlighting | Custom |
| Diff viewer | Side-by-side comparison | Custom |
| Tree with collapse | Collapsible tree nodes | `bubbles` (planned) |
| Color picker | Visual color selector | Custom |
| Date picker | Calendar-based date selection | `sugar-calendar` |

### 5.2 sugar-charts (Terminal Charts)

**Purpose**: Terminal chart rendering (port of nimblemarkets/ntcharts)
**Current Tests**: 18 tests
**Status**: 🟡 in progress

#### Current Strengths
- Good chart type coverage
- Braille grid rendering

#### Improvements Identified

**A. Chart Title and Labels**
```php
// Missing: chart title
$chart->withTitle('Monthly Sales', Position::Top);

// Missing: axis labels
$chart->withXLabel('Month');
$chart->withYLabel('Revenue');

// Missing: data point labels
$chart->withDataLabels(true);
$chart->withDataLabelFormatter(fn($v) => number_format($v));
```
**Priority**: HIGH | **Effort**: Low | **Benefit**: Readability

**B. Legend**
```php
// Missing: chart legend
$chart->withLegend(LegendPosition::Right);
$chart->withLegendStyle($style);
```
**Priority**: HIGH | **Effort**: Medium | **Benefit**: Multi-series charts

**C. Interactive Charts**
```php
// Missing: hover tooltip
$chart->withHoverTooltip(true);

// Missing: click handling
$chart->onClick(fn($point) => ...);

// Missing: zoom/pan
$chart->withZoom(true);
```
**Priority**: LOW | **Effort**: High | **Benefit**: Data exploration

**D. Additional Chart Types**
```php
// Not yet implemented:
- PieChart
- DonutChart
- AreaChart
- StackedBarChart
- RadialBarChart
- GanttChart
```
**Priority**: MEDIUM | **Effort**: High | **Benefit**: Completeness

**E. Animation**
```php
// Missing: animated chart rendering
$chart->withAnimation(true);
$chart->withAnimationDuration(1000);
```
**Priority**: LOW | **Effort**: High | **Benefit**: Visual appeal

**F. Data Aggregation**
```php
// Missing: automatic data bucketing
$chart->withAutoBucket(true, 10);

// Missing: moving average overlay
$chart->withMovingAverage(7);
```
**Priority**: MEDIUM | **Effort**: Medium | **Benefit**: Data analysis

#### Feature Ideas

| Feature | Description | upstream Ref |
|---------|-------------|--------------|
| Sparkline enhancements | Multiple series, annotations | Custom |
| Real-time updates | Live updating charts | Custom |
| CSV/JSON import | Direct data loading | Custom |
| Export to text | ASCII table export | Custom |
| Candlestick for crypto | OHLC with volume | Custom |

### 5.3 sugar-prompt (Interactive Forms)

**Purpose**: Form library with multi-page Group support (port of charmbracelet/huh)
**Current Tests**: 13 tests
**Status**: 🟢 v1 ready

#### Current Strengths
- Good field type coverage
- Theme system

#### Improvements Identified

**A. Validation Integration**
```php
// Missing: real-time validation display
$input->withValidation(fn($v) => !empty($v), 'Value required');

// Missing: async validation
$input->withAsyncValidation(
    fn($v) => $this->checkUniqueness($v),
    'Value already exists'
);
```
**Priority**: HIGH | **Effort**: Medium | **Benefit**: UX

**B. Conditional Fields**
```php
// Missing: show field based on another field's value
$confirm->withChildren([
    'name' => new Input('Name'),
    'email' => new Input('Email')->visibleWhen('name', fn($v) => strlen($v) > 0),
]);
```
**Priority**: MEDIUM | **Effort**: Medium | **Benefit**: Dynamic forms

**C. Field Dependencies**
```php
// Missing: computed field values
$form->withComputed('fullName', fn($data) => $data['first'] . ' ' . $data['last']);

// Missing: field auto-focus based on completion
```
**Priority**: MEDIUM | **Effort**: Medium | **Benefit**: Smart forms

**D. Progress Indication**
```php
// Missing: form completion progress
$form->withProgress(true);

// Missing: section navigation
$form->withSections(['Personal', 'Payment', 'Confirm']);
```
**Priority**: MEDIUM | **Effort**: Low | **Benefit**: UX

**E. Error Summary**
```php
// Missing: summary of all validation errors at end
$form->withErrorSummary(true);
```
**Priority**: MEDIUM | **Effort**: Low | **Benefit**: Form completion

**F. Missing Field Types**
```php
// Not yet implemented:
- ColorField (color picker)
- DateField (date picker - sugar-calendar exists)
- TimeField
- DateTimeField
- RangeField (slider)
- TagField (multi-select with creation)
- OTPField (one-time password)
```
**Priority**: MEDIUM | **Effort**: High | **Benefit**: Completeness

#### Feature Ideas

| Feature | Description | upstream Ref |
|---------|-------------|--------------|
| Wizard mode | Multi-step with back button | `huh` (planned) |
| Auto-save | Form state persistence | Custom |
| Field macros | Reusable field patterns | Custom |
| Custom renderer | Override field rendering | Custom |

---

## 6. Styling & Rendering Libraries

### 6.1 candy-shine (Markdown Rendering)

**Purpose**: Markdown to ANSI with syntax highlighting (port of charmbracelet/glamour)
**Status**: 🟡 in progress

#### Current Strengths
- Uses league/commonmark
- Theme support

#### Improvements Identified

**A. Syntax Highlighting**
```php
// Missing: actual syntax highlighting engine
// Currently just relies on Glamour themes but no actual tokenization
$renderer->withSyntaxHighlighting('php', HighlightRules::php());
$renderer->withLineNumbers(true);
```
**Priority**: HIGH | **Effort**: High | **Benefit**: Code block rendering

**B. Table Support**
```php
// Missing: GFM table rendering
$renderer->withTableSupport(true);
```
**Priority**: MEDIUM | **Effort**: Medium | **Benefit**: GFM compatibility

**C. Task List Support**
```php
// Missing: GFM task lists
$renderer->withTaskList(true);  // Renders [x] [ ] as styled checkboxes
```
**Priority**: MEDIUM | **Effort**: Low | **Benefit**: GFM compatibility

**D. Custom Renderers**
```php
// Missing: custom node renderer for extensions
$renderer->withRenderer('callout', fn($node) => ...);
```
**Priority**: LOW | **Effort**: Medium | **Benefit**: Extensibility

**E. Smart Typography**
```php
// Missing: typographic replacements
$renderer->withSmartQuotes(true);
$renderer->withSmartDashes(true);
$renderer->withEllipsis(true);
```
**Priority**: LOW | **Effort**: Low | **Benefit**: Polished output

#### Feature Ideas

| Feature | Description | upstream Ref |
|---------|-------------|--------------|
| Custom elements | Admonitions, badges, tabs | Custom |
| Footnotes | GFM footnotes | Custom |
| Math rendering | LaTeX math formulas | Custom |
| Mermaid diagrams | Diagram rendering | Custom |

### 6.2 candy-kit (CLI Presentation Helpers)

**Purpose**: CLI presentation helpers (port of charmbracelet/fang)
**Status**: 🟢 v1 ready

#### Current Strengths
- Good component coverage
- Theme system

#### Improvements Identified

**A. Progress Indicators**
```php
// Missing: progress bar for long operations
$kit->progress('Installing...', $total, fn($p) => ...);
```
**Priority**: MEDIUM | **Effort**: Medium | **Benefit**: UX

**B. Table Output**
```php
// Missing: formatted table output
$kit->table($headers, $rows);
```
**Priority**: MEDIUM | **Effort**: Low | **Benefit**: Data display

**C. Spinner Variants**
```php
// More spinner styles
$kit->spinner('⠋⠙⠹⠸⠼⠴⠦⠧⠇⠏');
```
**Priority**: LOW | **Effort**: Low | **Benefit**: Visual variety

#### Feature Ideas

| Feature | Description | upstream Ref |
|---------|-------------|--------------|
| Timer output | Show elapsed time | Custom |
| Certificate output | ASCII art certificate | Custom |
| Diff output | Side-by-side diff | Custom |

---

## 7. SSH/Network Libraries

### 7.1 candy-wish (SSH Server)

**Purpose**: SSH server middleware framework (port of charmbracelet/wish)
**Current Tests**: 13 tests
**Status**: 🟢 v1 ready (requires PHP 8.3+)

#### Current Strengths
- Clean middleware pattern
- Good auth support

#### Improvements Identified

**A. SFTP Support**
```php
// Missing: SFTP subsystem
class SftpMiddleware extends Middleware
{
    public function withSftpServer(SftpServerInterface $server): self;
    public function withSftpRoot(string $root): self;
}
```
**Priority**: HIGH | **Effort**: High | **Benefit**: File transfer

**B. SCP Support**
```php
// Missing: SCP file transfer
class ScpMiddleware extends Middleware { ... }
```
**Priority**: MEDIUM | **Effort**: Medium | **Benefit**: File transfer

**C. Session Reconnection**
```php
// Missing: handle client reconnect with same session ID
public function withSessionPersistence(Duration $ttl): self;
```
**Priority**: MEDIUM | **Effort**: Medium | **Benefit**: Reliability

**D. Connection Limits**
```php
// Missing: per-user or total connection limits
public function withMaxConnections(int $max): self;
public function withPerUserLimit(int $max): self;
```
**Priority**: MEDIUM | **Effort**: Low | **Benefit**: Security

**E. Terminal Resize**
```php
// Missing: handle terminal resize events
public function onWindowChange(callable $handler): self;
```
**Priority**: MEDIUM | **Effort**: Low | **Benefit**: UX

**F. Keepalive**
```php
// Missing: SSH keepalive to detect dead connections
public function withKeepalive(int $intervalSeconds): self;
```
**Priority**: MEDIUM | **Effort**: Low | **Benefit**: Reliability

#### Feature Ideas

| Feature | Description | upstream Ref |
|---------|-------------|--------------|
| Port forwarding | Local/remote port forwarding | SSH standard |
| Jump host | Bastion host support | SSH config |
| Public key agent | SSH agent forwarding | Custom |
| Command restrictions | Allowed commands only | Custom |
| Session logging | Log all output | Custom |

### 7.2 candy-metrics (Telemetry)

**Purpose**: Telemetry primitives (port of charmbracelet/promwish)
**Status**: 🟢 v1 ready (requires PHP 8.2+)

#### Improvements Identified

**A. Additional Metric Types**
```php
// Missing: histogram
$registry->histogram('request_duration', [0.1, 0.5, 1.0, 5.0]);
$registry->observe('request_duration', 0.75);

// Missing: counter with labels
$counter->inc(['method' => 'GET', 'path' => '/api']);
```
**Priority**: HIGH | **Effort**: Medium | **Benefit**: Observability

**B. Push Gateway**
```php
// Missing: Prometheus push gateway support
$backend = new PrometheusPushGateway('localhost:9091', 'job_name');
```
**Priority**: MEDIUM | **Effort**: Medium | **Benefit**: Flexibility

**C. OpenTelemetry Export**
```php
// Missing: OpenTelemetry exporter
$exporter = new OtelExporter('collector:4317');
$registry->addExporter($exporter);
```
**Priority**: LOW | **Effort**: High | **Benefit**: Standard support

#### Feature Ideas

| Feature | Description | upstream Ref |
|---------|-------------|--------------|
| StatsD backend | Full StatsD protocol | Custom |
| CloudWatch | AWS CloudWatch metrics | Custom |
| Datadog | Datadog API integration | Custom |

### 7.3 sugar-wishlist (SSH Endpoint Launcher)

**Purpose**: SSH endpoint launcher (port of charmbracelet/wishlist)
**Status**: 🟢 v1 ready

#### Improvements Identified

**A. Dynamic Endpoints**
```php
// Missing: runtime-registered endpoints
$config->registerEndpoint('prod-deploy', '/path/to/script', fn() => ...);
```
**Priority**: MEDIUM | **Effort**: Medium | **Benefit**: Flexibility

**B. Endpoint Groups**
```php
// Missing: named groups for organization
$config->withGroup('production');
$config->withGroup('staging');
```
**Priority**: MEDIUM | **Effort**: Low | **Benefit**: Organization

**C. Access Control**
```php
// Missing: per-endpoint user restrictions
$endpoint->withAllowedUsers(['admin', 'deploy']);
```
**Priority**: MEDIUM | **Effort**: Low | **Benefit**: Security

#### Feature Ideas

| Feature | Description | upstream Ref |
|---------|-------------|--------------|
| Endpoint aliases | Shortcut names | Custom |
| Endpoint history | Recent executions | Custom |
| Shared configs | Team-wide endpoint sharing | Custom |

### 7.4 candy-serve (Git Server)

**Purpose**: SSH-based Git server (port of charmbracelet/soft-serve)
**Status**: 🟢 v1 ready

#### Improvements Identified

**A. Git LFS**
```php
// Missing: full LFS implementation
// Currently: basic handler, needs transfer adapter
$lfs->withStorageBackend($backend);
$lfs->withConcurrentTransfers(4);
```
**Priority**: HIGH | **Effort**: High | **Benefit**: Git functionality

**B. Branch Protection**
```php
// Missing: require signed commits, branch restrictions
$repo->protect('main')->requireSignatures();
$repo->protect('main')->requireReview(2);
```
**Priority**: MEDIUM | **Effort**: High | **Benefit**: Security

**C. Webhooks**
```php
// Missing: push/commit webhooks
$repo->onPush(fn($push) => $this->notify($push));
$repo->onTag(fn($tag) => $this->release($tag));
```
**Priority**: MEDIUM | **Effort**: Medium | **Benefit**: Integration

**D. Access Control Lists**
```php
// Missing: per-repo, per-branch ACLs
$acl->allow('team', 'read', 'repo');
$acl->deny('contractor', 'write', 'main');
```
**Priority**: HIGH | **Effort**: Medium | **Benefit**: Security

**E. Repository Forks**
```php
// Missing: fork functionality
$repo->fork('original', 'my-copy');
```
**Priority**: LOW | **Effort**: High | **Benefit**: GitHub-like experience

#### Feature Ideas

| Feature | Description | upstream Ref |
|---------|-------------|--------------|
| GitHub API compatibility | Issue tracker, PRs | Custom |
| Markdown wiki | Per-repo wiki | Custom |
| Activity feed | Recent changes stream | Custom |
| Stars/watchers | Repository popularity | Custom |

---

## 8. CLI Shell & Tools

### 8.1 candy-shell (CLI Shell)

**Purpose**: 13 subcommand CLI shell (port of charmbracelet/gum)
**Current Tests**: 19 tests
**Status**: 🟡 in progress

#### Current Strengths
- Good command coverage
- Good Style parsing

#### Improvements Identified

**A. Shell Completion**
```php
// Missing: shell completion for gum itself
$app->withCompletion('bash');
$app->withCompletion('zsh');
$app->withCompletion('fish');
```
**Priority**: HIGH | **Effort**: High | **Benefit**: UX

**B. Configuration File**
```php
// Missing: config file support (~/.gumrc)
$app->loadConfig('~/.candyshellrc');
$app->getConfig('format.date');
```
**Priority**: MEDIUM | **Effort**: Medium | **Benefit**: Personalization

**C. Custom Commands**
```php
// Missing: plugin/extension system
$app->registerCommand(MyCommand::class);
```
**Priority**: LOW | **Effort**: High | **Benefit**: Extensibility

**D. More Filter Options**
```php
// Missing: fuzzy match alternatives
$filter->withScoring(fn($item, $query) => $score);

// Missing: persistent selection
$filter->withKeepFilter(true);
```
**Priority**: MEDIUM | **Effort**: Low | **Benefit**: Flexibility

**E. Interactive choose**
```php
// Missing: checkboxes (multi-select) for choose
$choose->withCheckboxes(true);
$choose->withMinSelect(1);
$choose->withMaxSelect(5);
```
**Priority**: MEDIUM | **Effort**: Medium | **Benefit**: UX

#### Feature Ideas

| Feature | Description | upstream Ref |
|---------|-------------|--------------|
| Interactive prompts | GUI-like dialogs | Custom |
| Template engine | Reusable output templates | Custom |
| Output format options | JSON, YAML, CSV output | Custom |

### 8.2 sugar-glow (Markdown Pager)

**Purpose**: Markdown CLI viewer (port of charmbracelet/glow)
**Status**: 🟢 v1 ready

#### Improvements Identified

**A. Search Within Document**
```php
// Missing: / search within document
$viewer->withSearch(true);
```
**Priority**: MEDIUM | **Effort**: Medium | **Benefit**: Navigation

**B. Syntax Highlighting**
```php
// Missing: code block syntax highlighting
$viewer->withHighlighting('php');
$viewer->withHighlightingTheme('monokai');
```
**Priority**: HIGH | **Effort**: High | **Benefit**: UX

**C. Image Support**
```php
// Missing: inline image rendering (Kitty/Sixel)
$viewer->withImages(true);
```
**Priority**: MEDIUM | **Effort**: Medium | **Benefit**: Visual content

**D. Hyperlink Support**
```php
// Missing: clickable links
$viewer->withLinks(true);
$viewer->onLinkClick(fn($url) => openInBrowser($url));
```
**Priority**: MEDIUM | **Effort**: Medium | **Benefit**: UX

#### Feature Ideas

| Feature | Description | upstream Ref |
|---------|-------------|--------------|
| Sidebar TOC | Table of contents | Custom |
| Bookmarks | Remember position | Custom |
| Annotations | Highlight and note | Custom |

### 8.3 candy-freeze (Screenshot Tool)

**Purpose**: Code/terminal to SVG (port of charmbracelet/freeze)
**Status**: 🟢 v1 ready

#### Improvements Identified

**A. Additional Window Styles**
```php
// Missing: more window decorations
$freeze->withWindowStyle('windows-terminal');
$freeze->withWindowStyle('iterm');
$freeze->withWindowStyle('hyper');
```
**Priority**: MEDIUM | **Effort**: Medium | **Benefit**: Variety

**B. Background Options**
```php
// Missing: background color/image
$freeze->withBackground('#1a1a2e');
$freeze->withBackgroundImage('bg.png');
```
**Priority**: MEDIUM | **Effort**: Low | **Benefit**: Aesthetics

**C. PNG Output**
```php
// Missing: PNG export (currently SVG only)
$freeze->toPng('output.png', 2x);
```
**Priority**: HIGH | **Effort**: High | **Benefit**: Compatibility

**D. Animation Support**
```php
// Missing: capture animated content (as GIF)
$freeze->withAnimation(true);
$freeze->withAnimationDuration(2000);
```
**Priority**: LOW | **Effort**: High | **Benefit**: Capability

#### Feature Ideas

| Feature | Description | upstream Ref |
|---------|-------------|--------------|
| Watermark | Add branding | Custom |
| QR code | Embed URL | Custom |
| Social cards | Twitter/OG image generation | Custom |

---

## 9. Image/Media Libraries

### 9.1 candy-mosaic (Image Renderer)

**Purpose**: Image-to-cell (Sixel, Kitty, iTerm2, half-block)
**Current Tests**: 10 tests
**Status**: 🟢 v1 ready

#### Current Strengths
- Multiple protocol support
- Async rendering

#### Improvements Identified

**A. Chafa Integration**
```php
// Missing: chafa backend for better quality
$mosaic->withBackend(Backend::Chafa);
$mosaic->withChafaOptions('--colors=256', '--work=n');
```
**Priority**: HIGH | **Effort**: High | **Benefit**: Image quality

**B. Transparency Handling**
```php
// Missing: background color for transparency
$mosaic->withBackgroundColor('#000000');
$mosaic->withCheckerboard(true); // Checkerboard pattern
```
**Priority**: MEDIUM | **Effort**: Low | **Benefit**: Transparency

**C. Animation Support**
```php
// Missing: GIF/video to frame sequence
$frames = $mosaic->extractFrames('animation.gif');
$mosaic->renderFrames($frames, $outputStream);
```
**Priority**: MEDIUM | **Effort**: High | **Benefit**: Media support

**D. Sixel Improvements**
```php
// Missing: Sixel palette optimization
$mosaic->withSixelColors(16); // 16, 256, or 256^3
$mosaic->withSixelOptimization(true);
```
**Priority**: LOW | **Effort**: Medium | **Benefit**: Quality

#### Feature Ideas

| Feature | Description | upstream Ref |
|---------|-------------|--------------|
| Cached rendering | Store renders for reuse | Custom |
| Thumbnail generation | Quick preview | Custom |
| Batch processing | Process multiple images | Custom |

### 9.2 candy-flip (ASCII GIF Viewer)

**Purpose**: ASCII GIF viewer
**Status**: 🟢 v1 ready (requires ext-gd)

#### Improvements Identified

**A. Playback Controls**
```php
// Missing: speed control
$player->withFps(10);
$player->withSpeed(2.0); // 2x speed

// Missing: frame stepping
$player->nextFrame();
$player->prevFrame();
```
**Priority**: MEDIUM | **Effort**: Low | **Benefit**: UX

**B. ImageMagick Backend**
```php
// Missing: ImageMagick backend for better quality
$player->withBackend(Backend::ImageMagick);
```
**Priority**: MEDIUM | **Effort**: Medium | **Benefit**: Quality

**C. Video Support**
```php
// Missing: video file support (mp4, webm)
$player->loadVideo('video.mp4');
```
**Priority**: MEDIUM | **Effort**: High | **Benefit**: Media support

#### Feature Ideas

| Feature | Description | upstream Ref |
|---------|-------------|--------------|
| GIF creation | Record terminal session | Custom |
| Frame extraction | Extract frames as images | Custom |

---

## 10. Game Libraries

### 10.1 candy-tetris (Tetris Clone)

**Purpose**: Tetris with SRS, 7-bag, NES scoring
**Status**: 🟢 v1 ready

#### Current Strengths
- Proper SRS rotation
- 7-bag randomization
- NES scoring

#### Improvements Identified

**A. Levels & Speed Curve**
```php
// Missing: level progression
$game->withLevelProgression(true);
$game->withStartLevel(5);
$game->getSpeedForLevel(10); // Returns ms per drop
```
**Priority**: MEDIUM | **Effort**: Medium | **Benefit**: Gameplay

**B. Next Piece Preview**
```php
// Missing: 6-piece bag preview
$renderer->withNextPreview(true);
```
**Priority**: MEDIUM | **Effort**: Low | **Benefit**: UX

**C. Ghost Piece**
```php
// Missing: ghost piece showing landing position
$renderer->withGhostPiece(true);
$renderer->withGhostStyle($style);
```
**Priority**: HIGH | **Effort**: Low | **Benefit**: UX

**D. Hold Piece**
```php
// Missing: piece holding (swap current with held)
$game->withHold(true);
$game->canHold(); // Boolean
```
**Priority**: HIGH | **Effort**: Medium | **Benefit**: Standard feature

**E. Lock Delay**
```php
// Missing: piece lock delay (SRS-style)
$game->withLockDelay(500); // ms
$game->withLockResets(15); // Max resets
```
**Priority**: HIGH | **Effort**: Medium | **Benefit**: Gameplay

**F. Scoring Variants**
```php
// Missing: different scoring systems
$game->withScoringSystem(ScoringSystem::GUIDELINE); // Or: NES, ATARI, CUSTOM
```
**Priority**: MEDIUM | **Effort**: Medium | **Benefit**: Variety

**G. Persistence**
```php
// Missing: high score table
$game->loadHighScores('~/.candy-tetris/scores.json');
$game->saveHighScores();
```
**Priority**: MEDIUM | **Effort**: Medium | **Benefit**: Replayability

#### Feature Ideas

| Feature | Description | upstream Ref |
|---------|-------------|--------------|
| Multiplayer | Tetris battle | Custom |
| Marathon mode | Fixed lines goal | Custom |
| Ultra mode | Timed scoring | Custom |
| Combo system | Consecutive clears | Custom |

### 10.2 sugar-crush (AI Chat TUI)

**Purpose**: Chat-shell for AI assistants (port of charmbracelet/crush)
**Status**: 🟢 v1 ready

#### Current Strengths
- Clean backend abstraction
- Message rendering

#### Improvements Identified

**A. Streaming Response**
```php
// Missing: token-by-token streaming display
$chat->withStreaming(true);
$chat->onToken(fn($token) => $this->appendToken($token));
```
**Priority**: HIGH | **Effort**: Medium | **Benefit**: UX

**B. Message Attachments**
```php
// Missing: file attachments
$chat->attachFile('/path/to/file');
$chat->attachImage($imagePath);
```
**Priority**: MEDIUM | **Effort**: Medium | **Benefit**: Functionality

**C. Markdown Rendering**
```php
// Missing: render markdown in responses
$chat->withMarkdown(true);
$chat->withCodeHighlighting(true);
```
**Priority**: HIGH | **Effort**: Medium | **Benefit**: UX

**D. Conversation Management**
```php
// Missing: save/load conversations
$chat->saveConversation('project-x');
$chat->loadConversation('project-x');
$chat->listConversations();
```
**Priority**: MEDIUM | **Effort**: Medium | **Benefit**: Persistence

**E. System Prompt**
```php
// Missing: configurable system prompt
$chat->withSystemPrompt('You are a helpful PHP assistant...');
$chat->withSystemPromptTemplate('You are {{role}}...');
```
**Priority**: MEDIUM | **Effort**: Low | **Benefit**: Flexibility

**F. Tool Use**
```php
// Missing: function calling / tool use
$chat->registerTool('bash', fn($cmd) => shell_exec($cmd));
$chat->onToolCall(fn($tool, $args) => ...);
```
**Priority**: HIGH | **Effort**: High | **Benefit**: AI capability

#### Feature Ideas

| Feature | Description | upstream Ref |
|---------|-------------|--------------|
| Multiple AI backends | OpenAI, Anthropic, local | Custom |
| Prompt templates | Reusable system prompts | Custom |
| Token counting | Cost estimation | Custom |
| Citation highlighting | Link to sources | Custom |

### 10.3 candy-mines (Minesweeper)

**Purpose**: Minesweeper with first-click safety, flood-fill
**Status**: 🟢 v1 ready

#### Improvements Identified

**A. Difficulty Levels**
```php
// Missing: preset difficulties
$game->withDifficulty(Difficulty::EASY);     // 9x9, 10 mines
$game->withDifficulty(Difficulty::MEDIUM);   // 16x16, 40 mines
$game->withDifficulty(Difficulty::EXPERT);   // 30x16, 99 mines
$game->withCustom($width, $height, $mines);  // Custom
```
**Priority**: HIGH | **Effort**: Low | **Benefit**: Accessibility

**B. Flagging**
```php
// Missing: flag mechanic
$game->flag($x, $y);
$game->unflag($x, $y);
$game->toggleFlag($x, $y);
$game->autoFlag(true); // Auto-flag when certain
```
**Priority**: HIGH | **Effort**: Medium | **Benefit**: Core mechanic

**C. Chord Click**
```php
// Missing: chord click (click on number to reveal adjacent)
$game->withChord(true);
```
**Priority**: MEDIUM | **Effort**: Medium | **Benefit**: Standard feature

**D. Timer & Stats**
```php
// Missing: timer display
$game->withTimer(true);

// Missing: stats tracking
$stats->gamesPlayed();
$stats->winRate();
$stats->bestTime(Difficulty::EASY);
```
**Priority**: MEDIUM | **Effort**: Low | **Benefit**: Engagement

#### Feature Ideas

| Feature | Description | upstream Ref |
|---------|-------------|--------------|
| Achievement system | Badges for performance | Custom |
| Leaderboards | Global high scores | Custom |
| Custom themes | Different tile styles | Custom |

### 10.4 honey-flap (Flappy Bird)

**Purpose**: Flappy Bird with HoneyBounce physics
**Status**: 🟢 v1 ready

#### Current Strengths
- Uses HoneyBounce for physics

#### Improvements Identified

**A. Multiple Levels**
```php
// Missing: level progression
$game->withLevel(2); // Harder pipes
$game->withProceduralGeneration(true);
```
**Priority**: MEDIUM | **Effort**: Medium | **Benefit**: Replayability

**B. Collectibles**
```php
// Missing: coins/stars to collect
$game->withCollectibles(true);
$game->getScore(); // Combined pipe + collectible score
```
**Priority**: LOW | **Effort**: Medium | **Benefit**: Variety

**C. Power-ups**
```php
// Missing: temporary abilities
$game->withPowerUp(PowerUp::SLOW_TIME);
$game->withPowerUp(PowerUp::SMALL_BIRD);
```
**Priority**: LOW | **Effort**: High | **Benefit**: Gameplay variety

**D. High Score Persistence**
```php
// Missing: save high scores
$game->saveHighScore();
$game->loadHighScores();
```
**Priority**: MEDIUM | **Effort**: Low | **Benefit**: Replayability

#### Feature Ideas

| Feature | Description | upstream Ref |
|---------|-------------|--------------|
| Parallax backgrounds | Multi-layer scrolling | Custom |
| Sound effects | Audio feedback | Custom |
| Daily challenges | Special levels | Custom |

---

## 11. Full Applications

### 11.1 super-candy (File Manager)

**Purpose**: Dual-pane file manager (port of superfile)
**Status**: 🟢 v1 ready

#### Current Strengths
- Dual pane
- Keyboard navigation

#### Improvements Identified

**A. File Operations**
```php
// Missing: file operations
$manager->copy();
$manager->move();
$manager->delete();
$manager->rename();
$manager->mkdir();

// Missing: operation queue/undo
$manager->withUndo(true);
```
**Priority**: HIGH | **Effort**: High | **Benefit**: Usability

**B. File Preview**
```php
// Missing: preview panel
$manager->withPreview(true);
$manager->getPreview($file); // Text, image, hex

// Missing: quick view (space bar)
```
**Priority**: HIGH | **Effort**: High | **Benefit**: UX

**C. Tabs**
```php
// Missing: multiple tabs per pane
$manager->openNewTab();
$manager->closeTab($id);
$manager->switchTab($id);
```
**Priority**: MEDIUM | **Effort**: High | **Benefit**: Multi-directory

**D. Bookmarks/Favorites**
```php
// Missing: bookmark frequently used directories
$manager->addBookmark('~/dev');
$manager->listBookmarks();
```
**Priority**: MEDIUM | **Effort**: Medium | **Benefit**: Navigation

**E. Search**
```php
// Missing: file search
$manager->search($query);
$manager->searchIn($directory, $query);
```
**Priority**: HIGH | **Effort**: Medium | **Benefit**: Usability

**F. Permissions View**
```php
// Missing: chmod/chown
$manager->chmod('755');
$manager->chown('user:group');
```
**Priority**: MEDIUM | **Effort**: Medium | **Benefit**: System admin

#### Feature Ideas

| Feature | Description | upstream Ref |
|---------|-------------|--------------|
| SFTP mount | Browse remote servers | Custom |
| Batch rename | Multi-file rename | Custom |
| Disk usage visualization | Treemap of directory sizes | Custom |
| File associations | Open with specific app | Custom |

### 11.2 sugar-stash (Git TUI)

**Purpose**: Three-pane git TUI (port of lazygit)
**Status**: 🟢 v1 ready

#### Current Strengths
- Three-pane layout
- Basic git operations

#### Improvements Identified

**A. Interactive Rebase**
```php
// Missing: interactive rebase editor
$git->rebase('-i');
$git->squash($commit);
$git->drop($commit);
```
**Priority**: HIGH | **Effort**: High | **Benefit**: Core git feature

**B. Merge Conflict Resolution**
```php
// Missing: merge conflict handling
$git->startMerge($branch);
$git->resolveConflict($file, $resolution); // 'ours', 'theirs', 'custom'
$git->markResolved($file);
```
**Priority**: HIGH | **Effort**: High | **Benefit**: Core git feature

**C. Stash Management**
```php
// Missing: stash UI
$git->listStashes();
$git->applyStash($id);
$git->dropStash($id);
$git->branchFromStash($id);
```
**Priority**: MEDIUM | **Effort**: Medium | **Benefit**: Usability

**D. Submodules**
```php
// Missing: submodule UI
$git->manageSubmodules();
$git->updateSubmodule($name);
```
**Priority**: LOW | **Effort**: Medium | **Benefit**: Git feature

**E. Subtrees**
```php
// Missing: git subtree UI
$git->addSubtree($prefix, $repo, $branch);
$git->pullSubtree($prefix);
```
**Priority**: LOW | **Effort**: High | **Benefit**: Git feature

#### Feature Ideas

| Feature | Description | upstream Ref |
|---------|-------------|--------------|
| Bisect assistance | Find bad commit | Custom |
| Work in progress | WIP branch view | Custom |
| Gitflow support | Branch visualization | Custom |
| Stacked diffs | Phabricator-style | Custom |

### 11.3 candy-query (SQLite Browser)

**Purpose**: SQLite browser TUI
**Status**: 🟢 v1 ready

#### Current Strengths
- Database browsing
- Basic query execution

#### Improvements Identified

**A. Table Designer**
```php
// Missing: visual table creation
$query->createTable('users', function($t) {
    $t->integer('id')->primaryKey()->autoIncrement();
    $t->string('name')->notNull();
    $t->unique(['name']);
});
```
**Priority**: MEDIUM | **Effort**: High | **Benefit**: UX

**B. Data Editing**
```php
// Missing: edit cell inline
$query->editCell('users', $rowId, 'name', 'New Value');
$query->insertRow('users', $data);
$query->deleteRow('users', $rowId);
```
**Priority**: HIGH | **Effort**: Medium | **Benefit**: Core feature

**C. Import/Export**
```php
// Missing: CSV import
$query->importCsv('users.csv', 'users');

// Missing: SQL dump
$query->exportSql('backup.sql');
$query->exportCsv('users.csv', 'users');
```
**Priority**: MEDIUM | **Effort**: Medium | **Benefit**: Data portability

**D. Query History**
```php
// Missing: query history with favorites
$query->saveQuery('my-query', 'SELECT * FROM users WHERE active = 1');
$query->listSavedQueries();
```
**Priority**: MEDIUM | **Effort**: Low | **Benefit**: Usability

**E. Schema Diagrams**
```php
// Missing: visualize table relationships
$query->showSchemaDiagram();
```
**Priority**: LOW | **Effort**: High | **Benefit**: Understanding

#### Feature Ideas

| Feature | Description | upstream Ref |
|---------|-------------|--------------|
| Multiple databases | Tabbed database views | Custom |
| SQL explain | Query plan visualization | Custom |
| Index management | Create/drop indexes | Custom |
| Transaction support | Visual transaction control | Custom |

### 11.4 sugar-tick (Time Tracker)

**Purpose**: Privacy-first coding-time tracker
**Status**: 🟢 v1 ready

#### Current Strengths
- Privacy-first (local JSONL)
- Heartbeat tracking

#### Improvements Identified

**A. Project Management**
```php
// Missing: project definitions
$tick->createProject('Website Redesign', '#ff0000');
$tick->setCurrentProject($projectId);
$tick->listProjects();
```
**Priority**: HIGH | **Effort**: Medium | **Benefit**: Organization

**B. Reports**
```php
// Missing: report generation
$tick->weeklyReport();
$tick->monthlyReport();
$tick->exportReport('pdf');
```
**Priority**: HIGH | **Effort**: Medium | **Benefit**: Value proposition

**C. Goals**
```php
// Missing: daily/weekly goals
$tick->setDailyGoal(8 * 60); // 8 hours
$tick->getGoalProgress(); // Returns percentage
```
**Priority**: MEDIUM | **Effort**: Medium | **Benefit**: Motivation

**D. Integrations**
```php
// Missing: external integrations
$tick->syncWithWakaTime('api-key');
$tick->exportToCsv('timesheet.csv');
```
**Priority**: MEDIUM | **Effort**: Medium | **Benefit**: Compatibility

**E. Idle Detection**
```php
// Missing: auto-pause on idle
$tick->withIdleDetection(true);
$tick->setIdleTimeout(5 * 60); // 5 minutes
```
**Priority**: MEDIUM | **Effort**: Medium | **Benefit**: Accuracy

#### Feature Ideas

| Feature | Description | upstream Ref |
|---------|-------------|--------------|
| Calendar view | Timeline visualization | Custom |
| Team sync | Aggregate team time | Custom |
| Invoice generation | Billable hours export | Custom |
| Break tracking | Track breaks separately | Custom |

---

## 12. Smaller Components

### 12.1 candy-log (Logging Library)

**Priority**: MEDIUM
**Current State**: Basic logging

#### Improvements
- Structured log fields
- Log rotation
- Syslog support
- Log aggregation format

### 12.2 sugar-readline (Readline Prompts)

**Priority**: MEDIUM
**Current State**: Basic prompts

#### Improvements
- Emacs and Vi mode
- Paste from history
- Auto-suggestions
- Syntax highlighting in input

### 12.3 sugar-skate (KV Store)

**Priority**: LOW
**Current State**: Basic key/value

#### Improvements
- TTL support
- Namespace separation
- Atomic operations
- Batch operations

### 12.4 sugar-stickers (Flexbox/Table)

**Priority**: MEDIUM
**Current State**: Basic layout

#### Improvements
- Gap support
- Justify-content variants
- Nested layouts
- Percentage widths

### 12.5 sugar-toast (Notifications)

**Priority**: LOW
**Current State**: Basic toasts

#### Improvements
- Action buttons
- Stacking management
- Progress toasts
- Auto-dismiss configuration

### 12.6 sugar-calendar (Date Picker)

**Priority**: MEDIUM
**Current State**: Basic picker

#### Improvements
- Date range selection
- Disable specific dates
- Min/max date constraints
- Locale-aware formatting

### 12.7 sugar-crumbs (Breadcrumbs)

**Priority**: LOW
**Current State**: Basic breadcrumbs

#### Improvements
- Clickable ancestors
- Truncation handling
- Dropdown for overflow
- Custom separators

### 12.8 sugar-veil (Overlay)

**Priority**: LOW
**Current State**: Basic overlay

#### Improvements
- Multiple overlay layers
- Transition animations
- Click-outside-to-close
- Focus trap

---

## 13. Documentation & Website

### 13.1 README Improvements

**Current State**: Most READMEs are skeletal

**Target**: Each README should have:
1. One-paragraph description
2. Quickstart example (3-5 lines)
3. Installation instructions
4. API overview with common usage
5. Links to upstream project and SugarCraft docs
6. Contributing guidelines

### 13.2 API Documentation

**Current State**: Inline PHPDoc only

**Target**: Use phpDocumentor to generate docs at `sugarcraft.github.io/sugarcraft/`

### 13.3 Website Enhancements

**Current State**: Basic tile grid

**Improvements**:
```php
// Missing features for docs/index.html:
- Search functionality
- Filter by category (Foundation/Components/Apps)
- Filter by status (🟢/🟡)
- Dark/light mode toggle
- Version selector
- API doc search
- Live examples/demo embed
- Contributor leaderboard
- Changelog / release notes
```

### 13.4 Website Demo Embeds

**Current State**: Static GIF links

**Improvements**:
- Embed live demos where possible
- Show code alongside rendered output
- Copy-to-clipboard for examples

---

## 14. Testing & CI/CD

### 14.1 Test Coverage Goals

**Current State**: Inconsistent coverage

| Library | Tests | Coverage Target |
|---------|-------|-----------------|
| candy-core | 29 | 80%+ |
| candy-sprinkles | 15 | 70%+ |
| candy-vt | 22 | 70%+ |
| sugar-bits | 22 | 60%+ |
| sugar-charts | 18 | 60%+ |
| sugar-prompt | 13 | 70%+ |
| candy-shell | 19 | 60%+ |
| Others | 1-5 | 50%+ |

### 14.2 Integration Testing

**Missing**: No integration tests across library boundaries

**Proposed**:
```php
tests/integration/
├── ProgramWithComponents/
│   ├── TextInput_in_Program.phpt
│   ├── Table_in_Program.phpt
│   └── FilePicker_in_Program.phpt
├── ProgramLifecycle/
│   ├── Init_Update_View.phpt
│   └── Concurrent_Messages.phpt
└── CrossLibrary/
    ├── Shine_with_Bits.phpt
    └── Prompt_with_Charts.phpt
```

### 14.3 Property-Based Testing

**Missing**: No property-based/fuzzing tests

**Proposed**:
```php
// Use infection/phpunit for mutation testing
// Use fakerphp/faker for property-based data
```

### 14.4 CI Enhancements

**Current State**: Basic PHPUnit + coverage

**Proposed additions**:
```yaml
# PHPStan analysis
- name: PHPStan
  run: vendor/bin/phpstan analyse src --level=5

# Mutation testing
- name: Infection
  run: vendor/bin/infection --min-msi=70

# Coding standard
- name: Code Style
  run: vendor/bin/phpcs src tests

# Dead code detection
- name: Composer Validate
  run: composer validate --strict
```

### 14.5 VCR Testing Expansion

**candy-vcr** is underutilized for VCR-style testing

**Proposed**:
```php
// Record expensive API calls in tests
$recorder->record(function() {
    $ai->complete('Hello');
});

// Then replay in subsequent test runs
$player->replay('api-call-001.yaml');
```

---

## 15. Prioritized Roadmap

### Phase 1: Quick Wins (1-2 sprints)

| Item | Library | Effort | Impact |
|------|---------|--------|--------|
| Add strict_types to all files | ALL | Low | High |
| PHPStan Level 5 CI | ALL | Medium | High |
| Ghost piece | candy-tetris | Low | High |
| Hold piece | candy-tetris | Medium | High |
| Flagging | candy-mines | Medium | High |
| SSH keepalive | candy-wish | Low | Medium |
| Lock delay | candy-tetris | Medium | Medium |
| vim keybindings | sugar-bits/TextInput | Low | High |
| Validation in forms | sugar-prompt | Medium | High |

### Phase 2: Core Quality (2-4 sprints)

| Item | Library | Effort | Impact |
|------|---------|--------|--------|
| Property-based testing | candy-core | High | High |
| BRACKETED PASTE | candy-core | Medium | High |
| Focus management | candy-core | Medium | High |
| SFTP support | candy-wish | High | High |
| Mouse protocol | candy-vt | Medium | High |
| Alt screen buffer | candy-vt | Medium | High |
| Syntax highlighting | candy-shine | High | High |
| Lock delay | candy-tetris | Medium | High |
| File preview | super-candy | High | High |
| File operations | super-candy | High | High |
| Merge conflicts | sugar-stash | High | High |
| Interactive rebase | sugar-stash | High | High |

### Phase 3: Feature Parity (4-6 sprints)

| Item | Library | Effort | Impact |
|------|---------|--------|--------|
| Undo/redo | super-candy | High | High |
| Chafa backend | candy-mosaic | High | Medium |
| Tool use | sugar-crush | High | High |
| Streaming response | sugar-crush | Medium | High |
| Multiple tabs | super-candy | High | Medium |
| LFS support | candy-serve | High | High |
| Search | super-candy | Medium | Medium |
| Chart legends | sugar-charts | Medium | High |
| Axis labels | sugar-charts | Low | High |
| Import/Export | candy-query | Medium | Medium |

### Phase 4: Polish (6+ sprints)

| Item | Library | Effort | Impact |
|------|---------|--------|--------|
| Async commands | candy-core | High | High |
| Multiple viewports | candy-core | High | High |
| Window decorations | candy-freeze | Medium | Medium |
| PNG output | candy-freeze | High | Medium |
| SSH agent forwarding | candy-wish | High | Medium |
| Webhooks | candy-serve | Medium | Medium |
| Branch protection | candy-serve | Medium | Medium |
| Animation | sugar-charts | High | Medium |
| Tool calling | sugar-crush | High | High |
| Integration tests | ALL | High | High |

---

## Appendix A: Missing Upstream Ports

Based on `CONVERSION.md` Phase 9+ and `UPSTREAM_OPPORTUNITIES.md`:

| Go Project | Description | Suggested Prefix | Priority |
|------------|-------------|------------------|----------|
| `charmbracelet/gum` spin | Shell spinner integration | candy-spin | LOW |
| `charmbracelet/viewport` | Scrollable viewport component | (in sugar-bits) | DONE |
| `charmbracelet/textinput` | (in sugar-bits) | DONE |
| `charmbracelet/bubbletea` v2 | Major version incoming | - | LOW |
| `d夫enca/bubblegit` | Git TUI | sugar-git | LOW |
| `tursodad/libvje` | Terminal UI components | - | LOW |

---

## Appendix B: Technical Debt

| Item | Libraries Affected | Remediation |
|------|-------------------|-------------|
| Inconsistent `with*()` patterns | sugar-bits, sugar-charts | Refactor to readonly + mutate |
| Missing PHPDoc | 30%+ of methods | Add comprehensive docs |
| No interfaces for backends | sugar-crush, sugar-post | Add BackendInterface |
| String enum alternative | candy-vt mode handlers | Consider enum class |
| Mutable static state | candy-metrics Registry | Consider DI |

---

## Appendix C: Performance Considerations

| Item | Libraries | Recommendation |
|------|-----------|----------------|
| Render buffering | candy-core | Consider zlib compression for escape sequences |
| Large file viewing | super-candy | Lazy loading with viewport |
| Chart data size | sugar-charts | Limit data points, add sampling |
| Image rendering | candy-mosaic | Cache rendered results |
| Long file lists | super-candy | Virtual scrolling |

---

*This plan should be reviewed quarterly and updated based on user feedback and emerging needs.*
