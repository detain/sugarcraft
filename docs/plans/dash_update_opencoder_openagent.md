# Sugar-Dash: Comprehensive Reorganization & Enhancement Plan

**Generated:** 2026-05-13  
**Status:** PLANNING  
**Based on:** Analysis of 7 upstream Go repositories + current sugar-dash state

---

## Executive Summary

Sugar-dash has made substantial progress implementing a TUI dashboard library, but its current organization under `src/Grid/` is architecturally misaligned with the original 18-package vision. This plan proposes a comprehensive reorganization into distinct subsystems with implementation priorities based on dependency order and value delivered.

**Key Problems Identified:**
1. **Flat directory structure** — 222 files in `src/Grid/` makes navigation impossible
2. **Multiple competing layout systems** — `Layout`, `GridLayout`, `FlexLayout`, `StackedGrid`, `HStack`, `VStack`, `ZStack`, `Stack` without clear hierarchy
3. **Documented technical debt** — 35 entries in CALIBER_LEARNINGS.md indicate accumulated issues
4. **Missing core abstractions** — No `Drawable` equivalent, no universal renderable contract
5. **No plugin/extensibility system** — despite original plan calling for Module/Registry/Plugin

**Value from Upstream Analysis:**
- **termui** provides the Drawable interface pattern and braille canvas math
- **bubbletea-tilelayout** provides the iterative constraint solver algorithm
- **bubbleboxer** provides the layout-tree/Model separation pattern
- **lattice** provides the JSON plugin protocol and constructor registry
- **Homedash** provides TickEpoch stale-discard and RingBuffer patterns
- **go-tealeaves** provides mature component implementations to study
- **bubble-grid** provides column-based stacking algorithms

---

## Part I: Current State Analysis

### 1.1 What Exists vs Original Plan

| Phase  | Package              | Original Plan Status         | sugar-dash Status              |
| ------ | -------------------- | ---------------------------- | ------------------------------ |
| 1.1    | Grid\                | Item/Sizer, StackedGrid, Frame | ✅ Implemented (Layout.php, GridLayout.php, FlexLayout.php, StackedGrid.php) |
| 1.2    | Boxer\               | Boxer, Node, ModelMap, CreateLeaf | ❌ Missing — need to implement  |
| 1.3    | Layout\              | Direction, Size, Tile, TileLayout | ✅ Implemented (Layout.php is flex-like, but not constraint-based) |
| 1.4    | RatioGrid\           | XRatio/WidthRatio, NewCol/NewRow | ❌ Missing — need to implement  |
| 2.1    | GridTable\           | Column, Row, CellValue, GridModel | ❌ Missing — no sort/filter/page |
| 2.2    | Tree\                | Node, TreeModel, BranchStyle | ⚠️ Partial — Tree.php exists but no provider interface |
| 2.3    | StatusBar\           | MenuItem, StatusIndicator    | ⚠️ Partial — some implementations exist |
| 2.4    | Modal\               | ConfirmModel, ListModel      | ⚠️ Partial — Modal.php exists |
| 2.5    | Select\              | Option, Model, auto-positioning | ⚠️ Partial — Select.php exists |
| 2.6    | Toast\               | NoticeKey/Definition/Position | ⚠️ Partial — Toast.php exists |
| 2.7    | Tabs\                | TabPane, FocusLeft/Right     | ⚠️ Partial — Tabs.php exists |
| 2.8    | Plot\                | MarkerBraille/Dot, Canvas, Sparkline, Gauge | ⚠️ Partial — multiple chart classes exist |
| 3.1    | Drawable\            | Drawable interface, Buffer, ParseStyles | ❌ Missing — no universal renderable contract |
| 4.1    | Module\              | Module interface, ImagePlacer | ❌ Missing — no plugin system |
| 4.2    | Registry\            | Register/Get/List/Reset      | ❌ Missing — no constructor registry |
| 4.3    | Plugin\              | JSON protocol, ExternalModule | ❌ Missing — no external plugin support |
| 4.4    | Modules\             | Clock, System, Greeting, etc. | ❌ Missing — no built-in modules |
| 5.1    | Keys\                | KeyRegistry                  | ❌ Missing — no centralized key binding |
| 5.2    | Theme\               | SystemPalette, presets       | ⚠️ Partial — some theme handling exists |
| 5.3    | Position\            | CalculateCenter, MeasureRenderedView | ⚠️ Partial — some positioning exists |
| 5.4    | Output\              | RenderBar, RenderStat        | ⚠️ Partial — some output helpers exist |

**Legend:** ✅ Done | ⚠️ Partial/Inconsistent | ❌ Missing

### 1.2 Current File Inventory

```
src/Grid/
├── composer.json              # Package metadata
├── Layout.php                 # Flex-like layout (600 lines)
├── GridLayout.php             # CSS Grid-style (427 lines)
├── FlexLayout.php             # Flexbox layout (682 lines)
├── StackedGrid.php            # Multi-column stacking (265 lines)
├── HStack.php, VStack.php, ZStack.php, Stack.php  # Stack variants
├── Frame.php                  # Bordered frame (274 lines)
├── Item.php, Sizer.php        # Core interfaces
├── [200+ component files]     # Charts, inputs, displays, diagrams
├── examples/                  # ~149 example files
├── lang/                      # i18n files
└── tests/Grid/                # 179 test files
```

### 1.3 Technical Debt (from CALIBER_LEARNINGS.md)

The CALIBER_LEARNINGS.md documents 35 accumulated patterns/gotchas including:

1. **Readonly+wither conflict** — Classes with `readonly` constructor-promoted properties AND clone-mutate withers fail at runtime
2. **Dual-state collections** — Console/Log keep filtered/unfiltered arrays, withers must update both
3. **Chart size defaults** — `?? 20` literals clip data when collection exceeds default
4. **Treemap cell gaps** — `+$cellWidth + 1` overflows and drops cells
5. **str_pad byte-counting** — ANSI-prefixed lines get under-padded
6. **Inline secondary classes** — PSR-4 violation — classes defined in other class files
7. **OHLC chart loops** — `chartHeight × pointCount` rows instead of `chartHeight`
8. **Y-axis label inversion** — Chart grid top-down but labels bottom-up

---

## Part II: Upstream Repository Findings

### 2.1 Critical Patterns to Implement

#### Pattern A: Universal Drawable Interface (from termui)

**What:** Every renderable widget implements:
```go
type Drawable interface {
    GetRect() image.Rectangle
    SetRect(int, int, int, int)
    Draw(*Buffer)
    sync.Locker
}
```

**Why valuable:** Provides a universal contract for layout systems to interact with widgets. Sugar-dash currently lacks this — layout classes call `render()` directly but don't have a unified way to query/set widget bounds.

**PHP Implementation:**
```php
interface Drawable {
    public function getRect(): Rect;
    public function setRect(int $x1, int $y1, int $x2, int $y2): void;
    public function draw(Buffer $buffer): void;
}
```

#### Pattern B: Layout Tree + Model Separation (from bubbleboxer)

**What:** Tree structure holds only addresses (`ModelMap` is separate `map[string,tea.Model]`)

**Why valuable:** Decouples layout composition from model instances. Models can be updated independently of restructuring the layout tree.

**PHP Implementation:**
```php
class Boxer {
    private Node $root;
    private array $modelMap = [];  // address => Item

    public function createLeaf(string $address, Item $model): Node;
    public function editLeaf(string $address, callable $fn): error;
}
```

#### Pattern C: Iterative Constraint Solver (from bubbletea-tilelayout)

**What:** ≤100 iteration residual distribution for weight-based sizing with min/max/fixed constraints

**Algorithm:**
1. Calculate available space minus fixed sizes
2. Distribute remaining based on weights (float64 0-1)
3. Apply min/max clamping
4. If any item resized, repeat (≤100 iterations)

**Why valuable:** More powerful than simple flex distribution — handles complex constraint chains.

#### Pattern D: Constructor Registry with Auto-Registration (from lattice)

**What:**
```go
func init() {
    registry.Register("clock", NewClockModule)
}
```

**Why valuable:** Built-in modules self-register, no central registration needed.

#### Pattern E: TickEpoch Stale Discard (from Homedash)

**What:**
```go
type Model struct {
    tickEpoch uint64
}
case tea.FocusMsg:
    m.tickEpoch++
    return m, tea.Batch(collectSystemCmd(), collectDockerCmd())
case SystemTickMsg:
    if msg.Epoch != m.tickEpoch {
        return m, nil  // Discard stale tick
    }
```

**Why valuable:** Prevents stale data from being displayed after window focus regain.

**PHP Implementation:**
```php
class DashboardModel {
    private int $tickEpoch = 0;
    private bool $focused = false;

    public function onFocus(): void {
        $this->focused = true;
        $this->tickEpoch++;
    }

    public function isTickStale(int $tickEpoch): bool {
        return $tickEpoch !== $this->tickEpoch;
    }
}
```

#### Pattern F: RingBuffer for Sparkline History (from Homedash)

**What:** 60-sample circular buffer for CPU/RAM history

**Why valuable:** Efficient fixed-size history without array shifting overhead.

```php
class RingBuffer {
    private array $data;
    private int $size;
    private int $index = 0;
    private int $count = 0;

    public function push(float $value): void {
        $this->data[$this->index] = $value;
        $this->index = ($this->index + 1) % $this->size;
        $this->count = min($this->count + 1, $this->size);
    }

    public function toArray(): array {
        if ($this->count < $this->size) {
            return array_slice($this->data, 0, $this->count);
        }
        return array_merge(
            array_slice($this->data, $this->index),
            array_slice($this->data, 0, $this->index)
        );
    }
}
```

#### Pattern G: Semaphore Pool for Parallel Stats (from Homedash)

**What:** 5-worker semaphore pool for concurrent Docker stats

**Why valuable:** Limits concurrent operations to avoid resource exhaustion.

```php
class SemaphorePool {
    private int $maxWorkers;
    private \SplQueue $available;
    private \SplQueue $pending = [];

    public function __construct(int $maxWorkers = 5) {
        $this->maxWorkers = $maxWorkers;
        $this->available = new \SplQueue();
        for ($i = 0; $i < $maxWorkers; $i++) {
            $this->available->push($i);
        }
    }

    public function execute(callable $task): \React\Promise\PromiseInterface {
        // ... await slot, execute task, release slot
    }
}
```

#### Pattern H: Plugin Protocol JSON over STDIN/STDOUT (from lattice)

**What:**
```json
// Lattice → Plugin (stdin)
{"type":"init","config":{"key":"val"}}
{"type":"update"}
{"type":"view","width":40,"height":10}

// Plugin → Lattice (stdout)
{"name":"TITLE","interval":10,"min_width":30,"min_height":5}
{"content":"rendered output"}
```

**Why valuable:** Language-agnostic plugins work for Go, Python, Bash, any language.

### 2.2 Mathematical Algorithms to Consider

| Algorithm                | Source             | Formula                                              |
| ------------------------ | ------------------ | ---------------------------------------------------- |
| **Braille canvas 2x4**     | termui             | Cell at (x,y) in virtual pixels → OR `BRAILLE[y%4][x%2]` |
| **Iterative constraint**   | bubbletea-tilelayout | Repeat until stable: `w = floor(avail × weight)` + clamp |
| **Column height allocation** | bubble-grid       | `expandHeight = (total - natural) / expandingCount`    |
| **Bresenham's line**       | termui             | `error += delta; if error > threshold: y++, error -= dx` |
| **Flex column GCD**        | go-tealeaves       | GCD reduction before distributing fractional widths   |
| **LAB color interpolation** | go-tealeaves       | `blend = back.BlendLab(fore, step)` for fade animations |

### 2.3 Component Patterns from go-tealeaves

The tealeaves suite provides mature reference implementations for:

| Component    | Key Features from tealeaves                                  |
| ------------ | ------------------------------------------------------------ |
| **Grid**       | Flex columns with GCD reduction, frozen columns, 8 border presets |
| **Layout**     | 5-phase constraint solving, FocusManager, StackLayoutModel   |
| **Modal**      | Wither pattern, inline edit buffer, scrollbar calculation    |
| **Tree**       | 5 BranchStyle presets, provider interface, drilldown model   |
| **StatusBar**  | Two-zone layout, separator modes, polymorphic indicator style |
| **Fields**     | Auto-positioning above/below based on available space        |
| **Notify**     | LAB color lerp for fade, 9 positions, single-active notices  |
| **Utils**      | KeyRegistry, centered modal positioning, ANSI-safe cutting   |

---

## Part III: Proposed Reorganization

### 3.1 New Directory Structure

```
sugar-dash/
├── composer.json
├── phpunit.xml
├── README.md
├── CALIBER_LEARNINGS.md
├── src/
│   ├── SugarCraft/           # Root namespace
│   │   └── Dash/
│   │       ├── Drawable/     # [NEW] Universal renderable interface + Buffer
│   │       ├── Layout/       # [RENAME/MERGE] Grid, Boxer, Layout, RatioGrid
│   │       │   ├── Grid.php
│   │       │   ├── Boxer.php
│   │       │   ├── RatioGrid.php
│   │       │   ├── ConstraintLayout.php  # [NEW] iterative solver
│   │       │   └── Interfaces.php
│   │       ├── Components/   # [NEW BUNDLE] GridTable, Tree, StatusBar, Modal, Select, Toast, Tabs, Plot
│   │       │   ├── GridTable/
│   │       │   ├── Tree/
│   │       │   ├── StatusBar/
│   │       │   ├── Modal/
│   │       │   ├── Select/
│   │       │   ├── Toast/
│   │       │   ├── Tabs/
│   │       │   └── Plot/
│   │       ├── Module/       # [NEW] Module interface + Registry + Plugin
│   │       ├── System/       # [NEW] Built-in modules: Clock, System, Greeting
│   │       └── Util/         # [RENAME] Keys, Theme, Position, Output
│   │
│   └── [Layout components currently in Grid/ split here]
│       ├── Frame.php         # Moved to Components/ or Layout/
│       ├── Stack.php         # Moved to Layout/
│       └── etc.
```

### 3.2 Proposed Namespace Changes

| Current Namespace                  | Proposed Namespace                    |
| ---------------------------------- | ------------------------------------- |
| `SugarCraft\Dash\Grid`               | `SugarCraft\Dash\Layout` (for Layout sub-packages) |
| `SugarCraft\Dash\Grid\Layout`        | `SugarCraft\Dash\Layout\Flex`          |
| `SugarCraft\Dash\Grid\GridLayout`    | `SugarCraft\Dash\Layout\Grid`          |
| `SugarCraft\Dash\Grid\StackedGrid`   | `SugarCraft\Dash\Layout\Stacked`       |
| `SugarCraft\Dash\Grid\Frame`         | `SugarCraft\Dash\Components\Frame`     |
| `SugarCraft\Dash\Grid\Chart\*`       | `SugarCraft\Dash\Components\Plot\*`    |
| `SugarCraft\Dash\Grid\Input\*`       | `SugarCraft\Dash\Components\Input\*`   |
| (new) `SugarCraft\Dash\Drawable`     | `SugarCraft\Dash\Drawable`             |
| (new) `SugarCraft\Dash\Module`       | `SugarCraft\Dash\Module`               |
| (new) `SugarCraft\Dash\System`       | `SugarCraft\Dash\System`               |

---

## Part IV: Implementation Plan

### Phase 1: Core Reorganization (Weeks 1-2)

#### 1.1 Create Drawable Package

**Priority:** HIGH — foundational interface needed before layout systems

| Task | Description | Files |
| ---- | ----------- | ----- |
| 1.1.1 | Create `Drawable` interface with `getRect()`, `setRect()`, `draw()`, `getLock()` | `src/Drawable/Drawable.php` |
| 1.1.2 | Create `Rect` value object | `src/Drawable/Rect.php` |
| 1.1.3 | Create `Buffer` class with `getCell()`, `setCell()`, `fill()`, `setString()` | `src/Drawable/Buffer.php` |
| 1.1.4 | Create `Cell` with `rune`, `style`, `fg`, `bg`, `modifier` | `src/Drawable/Cell.php` |
| 1.1.5 | Create `Style` with color constants and modifier flags | `src/Drawable/Style.php` |
| 1.1.6 | Implement `ParseStyles()` for `[text](fg:red,bg:blue)` syntax | `src/Drawable/ParseStyles.php` |
| 1.1.7 | Create `Block` base class implementing `Drawable` with border/padding/inner | `src/Drawable/Block.php` |
| 1.1.8 | Write tests for Buffer, ParseStyles, Style | `tests/Drawable/` |

**Reference:** termui's `render.go`, `block.go`, `style_parser.go`, `drawille/drawille.go`

#### 1.2 Reorganize Layout Package

**Priority:** HIGH — current layout classes need consolidation

| Task | Description | Files |
| ---- | ----------- | ----- |
| 1.2.1 | Create `Layout/Interfaces.php` with `Item`, `Sizer`, `Layout` interfaces | `src/Layout/Interfaces.php` |
| 1.2.2 | Create `Layout/Grid.php` — ratio-based nested rows/columns (from termui) | `src/Layout/Grid.php` |
| 1.2.3 | Create `Layout/ConstraintLayout.php` — iterative solver with Weight/Min/Max/Fixed | `src/Layout/ConstraintLayout.php` |
| 1.2.4 | Create `Layout/Boxer.php` — address-based tree + ModelMap separation | `src/Layout/Boxer.php` |
| 1.2.5 | Create `Layout/StackedGrid.php` — column-based with expandVertical | `src/Layout/StackedGrid.php` |
| 1.2.6 | Deprecate `GridLayout.php` in favor of `Layout/Grid.php` | Migration guide |
| 1.2.7 | Deprecate `FlexLayout.php` in favor of `Layout/Flex.php` | Migration guide |
| 1.2.8 | Write tests for new layout classes | `tests/Layout/` |

**Reference:** bubbleboxer (Boxer), bubbletea-tilelayout (ConstraintLayout), termui (Grid), bubble-grid (StackedGrid)

#### 1.3 Create RingBuffer & TickEpoch Utilities

**Priority:** MEDIUM — useful for dashboard patterns

| Task | Description | Files |
| ---- | ----------- | ----- |
| 1.3.1 | Create `RingBuffer` class (60-sample circular buffer) | `src/Util/RingBuffer.php` |
| 1.3.2 | Create `TickEpoch` trait for stale tick discard | `src/Util/TickEpoch.php` |
| 1.3.3 | Create `SemaphorePool` for parallel operations | `src/Util/SemaphorePool.php` |
| 1.3.4 | Write tests | `tests/Util/` |

**Reference:** Homedash

### Phase 2: Component Library (Weeks 3-4)

#### 2.1 Plot/Canvas Components

**Priority:** HIGH — charts are core dashboard feature

| Task | Description | Files |
| ---- | ----------- | ----- |
| 2.1.1 | Create `Plot/Plot.php` base class implementing `Drawable` | `src/Components/Plot/Plot.php` |
| 2.1.2 | Create `Plot/Canvas.php` with `SetPoint()`/`SetLine()` using braille math | `src/Components/Plot/Canvas.php` |
| 2.1.3 | Create `Plot/Sparkline.php` using `RingBuffer` for history | `src/Components/Plot/Sparkline.php` |
| 2.1.4 | Create `Plot/Gauge.php` with percentage bar | `src/Components/Plot/Gauge.php` |
| 2.1.5 | Create `Plot/BraillePlot.php` using MarkerBraille (2x X, 4x Y) | `src/Components/Plot/BraillePlot.php` |
| 2.1.6 | Migrate existing chart classes to `Components/Plot/` | Migration |
| 2.1.7 | Write tests (snapshot + behavior) | `tests/Components/Plot/` |

**Reference:** termui `widgets/plot.go`, `drawille/drawille.go`

#### 2.2 StatusBar & KeyRegistry

**Priority:** MEDIUM — keyboard navigation is essential

| Task | Description | Files |
| ---- | ----------- | ----- |
| 2.2.1 | Create `KeyRegistry` with `KeyMeta` (Binding, StatusBar, HelpModal, Category) | `src/Util/KeyRegistry.php` |
| 2.2.2 | Create `StatusBar/Model.php` with two-zone rendering | `src/Components/StatusBar/Model.php` |
| 2.2.3 | Create `StatusBar/MenuItem.php` and `StatusIndicator.php` | `src/Components/StatusBar/MenuItem.php` |
| 2.2.4 | Support separator modes (pipe, space, bracket) | `src/Components/StatusBar/SeparatorMode.php` |
| 2.2.5 | Write tests | `tests/Components/StatusBar/` |

**Reference:** go-tealeaves `teastatus/`, `teautils/key_registry.go`

#### 2.3 Modal & Toast

**Priority:** MEDIUM — notification system

| Task | Description | Files |
| ---- | ----------- | ----- |
| 2.3.1 | Create `Modal/ConfirmModel.php` with wither pattern | `src/Components/Modal/ConfirmModel.php` |
| 2.3.2 | Create `Modal/ListModel.php` generic with inline edit | `src/Components/Modal/ListModel.php` |
| 2.3.3 | Create `Toast/NoticeDefinition.php` and `Position.php` | `src/Components/Toast/NoticeDefinition.php` |
| 2.3.4 | Create `Toast/Model.php` with LAB color lerp for fade | `src/Components/Toast/Model.php` |
| 2.3.5 | Support 9 positions (TL, TC, TR, BL, BC, BR, C, Unspecified) | `src/Components/Toast/Position.php` |
| 2.3.6 | Write tests | `tests/Components/Modal/`, `tests/Components/Toast/` |

**Reference:** go-tealeaves `teamodal/`, `teanotify/`, `teafields/`

#### 2.4 Tree Component

**Priority:** MEDIUM — hierarchical data display

| Task | Description | Files |
| ---- | ----------- | ----- |
| 2.4.1 | Create `Tree/NodeProviderInterface.php` for rendering customization | `src/Components/Tree/NodeProviderInterface.php` |
| 2.4.2 | Create `Tree/Node.php` with `AncestorIsLastChild()` for prefix building | `src/Components/Tree/Node.php` |
| 2.4.3 | Create `Tree/TreeModel.php` with 5 `BranchStyle` presets | `src/Components/Tree/TreeModel.php` |
| 2.4.4 | Create `Tree/BranchStyle.php` enum (Default, Compact, ASCII, Wide, Minimal) | `src/Components/Tree/BranchStyle.php` |
| 2.4.5 | Create `Tree/DrillDownModel.php` for breadcrumb navigation | `src/Components/Tree/DrillDownModel.php` |
| 2.4.6 | Write tests | `tests/Components/Tree/` |

**Reference:** go-tealeaves `teatree/`

#### 2.5 GridTable

**Priority:** LOW — complex, can defer

| Task | Description | Files |
| ---- | ----------- | ----- |
| 2.5.1 | Create `GridTable/Column.php` with chainable `With*` methods | `src/Components/GridTable/Column.php` |
| 2.5.2 | Create `GridTable/Row.php` with stable uint32 ID | `src/Components/GridTable/Row.php` |
| 2.5.3 | Create `GridTable/GridModel.php` with sort/filter/page/scroll | `src/Components/GridTable/GridModel.php` |
| 2.5.4 | Support 8 border presets | `src/Components/GridTable/BorderPreset.php` |
| 2.5.5 | Support frozen columns | `src/Components/GridTable/FrozenColumn.php` |
| 2.5.6 | Write tests | `tests/Components/GridTable/` |

**Reference:** go-tealeaves `teagrid/`

### Phase 3: Module/Plugin System (Week 5)

#### 3.1 Module Interface & Registry

**Priority:** MEDIUM — extensibility is a key differentiator

| Task | Description | Files |
| ---- | ----------- | ----- |
| 3.1.1 | Create `Module/ModuleInterface.php` with `Name()`, `Init()`, `Update()`, `View()`, `MinSize()` | `src/Module/ModuleInterface.php` |
| 3.1.2 | Create `Module/ImagePlacerInterface.php` optional interface | `src/Module/ImagePlacerInterface.php` |
| 3.1.3 | Create `Module/Registry.php` with thread-safe `Register()`/`Get()`/`List()` | `src/Module/Registry.php` |
| 3.1.4 | Create `Module/Config.php` with `Get(key, envVar, fallback)` cascade | `src/Module/Config.php` |
| 3.1.5 | Create `Module/ModuleConfig.php` per-module configuration | `src/Module/ModuleConfig.php` |
| 3.1.6 | Write tests | `tests/Module/` |

**Reference:** lattice `pkg/module/`, `pkg/registry/`

#### 3.2 Plugin Protocol

**Priority:** LOW — complex, can defer post-1.0

| Task | Description | Files |
| ---- | ----------- | ----- |
| 3.2.1 | Create `Plugin/Request.php` with Type (init/update/view), Config, Width, Height | `src/Plugin/Request.php` |
| 3.2.2 | Create `Plugin/Response.php` with Name, Content, MinWidth, MinHeight, Interval, Error | `src/Plugin/Response.php` |
| 3.2.3 | Create `Plugin/ExternalModule.php` for JSON over STDIN/STDOUT | `src/Plugin/ExternalModule.php` |
| 3.2.4 | Create `Plugin/Runner.php` for plugin lifecycle management | `src/Plugin/Runner.php` |
| 3.2.5 | Write tests with mock plugins | `tests/Plugin/` |

**Reference:** lattice `pkg/plugin/`, `internal/plugin/`

#### 3.3 Built-in Modules

**Priority:** LOW — can defer, reference implementations only

| Task | Description | Files |
| ---- | ----------- | ----- |
| 3.3.1 | Create `System/ClockModule.php` with tea.Tick self-reschedule | `src/System/ClockModule.php` |
| 3.3.2 | Create `System/SystemModule.php` with CPU/mem/GPU stats | `src/System/SystemModule.php` |
| 3.3.3 | Create `System/GreetingModule.php` with time-based greeting | `src/System/GreetingModule.php` |
| 3.3.4 | Create `System/UptimeModule.php` | `src/System/UptimeModule.php` |
| 3.3.5 | Register modules via `init()` auto-registration | Each module file |

**Reference:** lattice `internal/modules/`

### Phase 4: Utilities & Polish (Week 6)

#### 4.1 Theme System

**Priority:** MEDIUM — consistent styling is important

| Task | Description | Files |
| ---- | ----------- | ----- |
| 4.1.1 | Create `Theme/SystemPalette.php` with semantic color slots | `src/Theme/SystemPalette.php` |
| 4.1.2 | Create `Theme/Theme.php` with derived component styles | `src/Theme/Theme.php` |
| 4.1.3 | Create preset themes (Dark, Light, Adaptive, Default) | `src/Theme/Preset.php` |
| 4.1.4 | Write tests | `tests/Theme/` |

**Reference:** go-tealeaves `teautils/palette.go`, `teautils/theme.go`

#### 4.2 Position & Output Helpers

**Priority:** LOW — utility functions

| Task | Description | Files |
| ---- | ----------- | ----- |
| 4.2.1 | Create `Position/CalculateCenter.php` | `src/Util/Position/CalculateCenter.php` |
| 4.2.2 | Create `Position/MeasureRenderedView.php` (ANSI-aware) | `src/Util/Position/MeasureRenderedView.php` |
| 4.2.3 | Create `Output/RenderBar.php`, `RenderStat.php`, `RenderGauge.php` | `src/Util/Output/RenderBar.php` |
| 4.2.4 | Create `Output/WrapCells.php` with runewidth awareness | `src/Util/Output/WrapCells.php` |
| 4.2.5 | Write tests | `tests/Util/` |

**Reference:** go-tealeaves `teautils/positioning.go`, `teautils/render.go`

#### 4.3 Technical Debt Fixes

**Priority:** HIGH — address CALIBER_LEARNINGS.md issues

| Task | Description | Issue Fixed |
| ---- | ----------- | ----------- |
| 4.3.1 | Fix readonly+wither conflict in all affected classes | CALIBER #1 |
| 4.3.2 | Add dual-state collection handling to withers | CALIBER #2 |
| 4.3.3 | Replace `?? 20` literals with configurable defaults | CALIBER #3 |
| 4.3.4 | Fix Treemap cell gap overflow | CALIBER #4 |
| 4.3.5 | Use runewidth for ANSI-safe string operations | CALIBER #5 |
| 4.3.6 | Extract inline secondary classes to PSR-4 files | CALIBER #6 |
| 4.3.7 | Fix OHLC chart loop bounds | CALIBER #7 |
| 4.3.8 | Fix Y-axis label inversion in charts | CALIBER #8 |

---

## Part V: Migration Strategy

### 5.1 Backward Compatibility

1. **Create `Grid/` as alias namespace** pointing to reorganized packages
2. **Deprecate classes with notices** pointing to new locations
3. **Provide migration script** to update `use` statements
4. **Maintain `Grid\*` facade classes** that delegate to new implementation

### 5.2 Deprecation Timeline

| Version | Action |
| ------- | ------ |
| 0.x     | New structure available, old still works, deprecation notices |
| 1.0     | Old structure removed, migration guide published |

### 5.3 composer.json Updates

```json
{
    "autoload": {
        "psr-4": {
            "SugarCraft\\Dash\\": "src/",
            "SugarCraft\\Dash\\Layout\\": "src/Layout/",
            "SugarCraft\\Dash\\Components\\": "src/Components/",
            "SugarCraft\\Dash\\Drawable\\": "src/Drawable/",
            "SugarCraft\\Dash\\Module\\": "src/Module/",
            "SugarCraft\\Dash\\System\\": "src/System/",
            "SugarCraft\\Dash\\Util\\": "src/Util/",
            "SugarCraft\\Dash\\Theme\\": "src/Theme/"
        }
    },
    "repositories": [
        {"type": "path", "url": "../candy-core", "options": {"symlink": true}},
        {"type": "path", "url": "../candy-sprinkles", "options": {"symlink": true}}
    ],
    "require": {
        "php": "^8.3",
        "sugarcraft/candy-core": "@dev",
        "sugarcraft/candy-sprinkles": "@dev"
    }
}
```

---

## Part VI: Verification Plan

### 6.1 Test Coverage Requirements

| Package        | Test Coverage Target | Test Types |
| -------------- | -------------------- | ---------- |
| Drawable       | 90%                  | Unit (snapshot + behavior) |
| Layout/*       | 85%                  | Unit (snapshot + behavior) |
| Components/*   | 80%                  | Unit (snapshot + behavior) |
| Module/*       | 85%                  | Unit (mock plugin) |
| Theme/*        | 90%                  | Unit |

### 6.2 Stream-Write Pattern

All file writes must use the canonical pattern per AGENTS.md:
```php
// DO: Use ftell/fseek/stream_get_contents
$offset = ftell($fp);
fseek($fp, $offset);
$content = stream_get_contents($fp);

// DON'T: Use ftruncate; rewind between writes
```

### 6.3 Snapshot Testing

Chart and layout output must be verified against SGR byte snapshots:
```php
public function testFrameRenders(): void {
    $frame = new Frame(new Text("Hello"));
    $frame->setRect(0, 0, 10, 3);
    $output = $frame->render();

    $this->assertMatchesSnapshot($output);
    // Snapshot stored in __snapshots__/FrameTest::testFrameRenders.bin
}
```

---

## Part VII: Implementation Priorities

### Critical Path (Must Complete)

1. **Drawable interface** — without this, layout systems can't query widget bounds
2. **Layout reorganization** — current flat structure is unmaintainable
3. **ConstraintLayout** — enables complex dashboard layouts
4. **Plot/Canvas with braille** — core visualization feature

### High Value Add

5. **RingBuffer & TickEpoch** — dashboard patterns from Homedash
6. **StatusBar & KeyRegistry** — keyboard navigation
7. **Theme system** — consistent styling
8. **Technical debt fixes** — 35 documented issues

### Nice to Have (Post-1.0)

9. **Module/Registry** — plugin architecture
10. **Plugin protocol** — JSON over STDIN/STDOUT
11. **Built-in modules** — Clock, System, etc.
12. **GridTable** — complex data grid

---

## Appendix A: File Count Estimates

| Package          | New Files | Modified Files |
| ---------------- | --------- | -------------- |
| Drawable         | 8         | 0              |
| Layout/*         | 6         | 15 (migrate existing) |
| Components/*     | 25        | 50 (migrate existing) |
| Module/*         | 6         | 0              |
| System/*         | 5         | 0              |
| Theme/*          | 4         | 0              |
| Util/*           | 8         | 10 (migrate existing) |
| **Total**        | **62**    | **75**         |

---

## Appendix B: Key Interfaces Reference

### B.1 Drawable Interface
```php
interface Drawable {
    public function getRect(): Rect;
    public function setRect(int $x1, int $y1, int $x2, int $y2): void;
    public function draw(Buffer $buffer): void;
    public function getLock(): \RecursiveIteratorIterator|\RecursiveMutex;
}
```

### B.2 Module Interface
```php
interface ModuleInterface {
    public function name(): string;
    public function init(): ?\React\Promise\PromiseInterface;
    public function update(mixed $msg): ?\React\Promise\PromiseInterface;
    public function view(int $width, int $height): string;
    public function minSize(): array{width: int, height: int};
}
```

### B.3 Layout Interfaces
```php
interface Item {
    public function render(): string;
}

interface Sizer extends Item {
    public function setSize(int $width, int $height): Sizer;
    public function getInnerSize(): array{width: int, height: int};
}

interface Layout extends Sizer {
    public function add(Item ...$items): self;
    public function remove(string $id): self;
}
```

---

## Appendix C: References

| Source Repository         | Key Contribution                              |
| ------------------------- | --------------------------------------------- |
| shahar3/bubble-grid       | Column stacking with expandVertical           |
| mko88/bubbletea-tilelayout | Iterative constraint solver (≤100 iters)      |
| mikeschinkel/go-tealeaves | 8 mature packages, teagrid, tealayout, etc.   |
| treilik/bubbleboxer       | Address-based layout tree + ModelMap          |
| floatpane/lattice         | Constructor registry, JSON plugin protocol    |
| kts982/Homedash           | TickEpoch, RingBuffer, SemaphorePool          |
| sashakoshka/termui        | Drawable interface, braille canvas, ParseStyles |

---

*Plan generated from analysis of 7 upstream Go repositories and current sugar-dash state.*
*Save this file and update as implementation progresses.*
