# Candy-Zone Research: Mouse Zone Tracking Libraries

**Context:** candy-zone tracks mouse zones for TUIs, ported from Go's `lrstanley/bubblezone`. Part of SugarCraft monorepo (PHP 8.3+).

**Current Implementation:** `/home/sites/sugarcraft/candy-zone/src/` with 4 files: `Manager.php`, `Zone.php`, `MsgZoneInBounds.php`, `Zones.php`.

---

## 1. Library Comparisons by Language

### 1.1 Go Libraries

#### BubbleZone (lrstanley/bubblezone) — **UPSTREAM**

**Source:** [github.com/lrstanley/bubblezone](https://github.com/lrstanley/bubblezone)

**Architecture:**
- APC (Announcement Pause Code) escape sequences: `\x1b_` prefix, `\x1b\\` terminator
- `zone.Mark(id, content)` injects zero-width markers into rendered output
- `zone.Scan(output)` parses output, calculates bounding boxes, strips markers
- `zone.Get(id).InBounds(msg)` for hit testing
- `zone.Get(id).Pos(msg)` for relative coordinates within zone

**Zone Detection:**
```go
// Mark zones in child View()
view = zone.Mark("confirm", lipgloss.NewStyle().Render("OK"))

// Scan at root View()
content = zone.Scan(view)

// Hit-test in Update()
case tea.MouseReleaseMsg:
    if zone.Get("confirm").InBounds(msg) {
        // clicked confirm button
    }
```

**Click Handling:**
- Detects via `tea.MouseReleaseMsg` with `msg.Button == tea.MouseLeft`
- BubbleTea provides: `MouseButtonLeft`, `MouseButtonRight`, `MouseButtonWheelUp`, `MouseButtonWheelDown`
- Also supports full motion tracking via `tea.MouseModeCellMotion`

**Drag Detection:**
- NOT built-in — handled by user code tracking `MouseMotionMsg` after `MouseDownMsg`
- tcell (see below) has better drag support with button state tracking

**Hover States:**
- NOT built-in — handled by user code checking `MouseMotionMsg` continuously
- Motion tracking must be enabled: `tea.WithMouseCellMotion()`

**Key Limitation:** bubblezone v2 note states that `lipgloss/bubbletea v2` have native mouse event tracking features that may eventually replace it.

---

#### TCell (gdamore/tcell)

**Source:** [github.com/gdamore/tcell](https://github.com/gdamore/tcell)

**Architecture:**
- Full-featured terminal library with rich mouse event model
- `EventMouse` struct with `Buttons()` returning `ButtonMask`
- Supports drag detection: "click drag can be identified by a motion event with the mouse down, without any intervening button release"

**Mouse Event Model:**
```go
type EventMouse struct {
    btn ButtonMask  // buttons pressed
    mod ModMask     // modifiers (Shift, Ctrl, Alt, Meta)
    x   int         // x coordinate
    y   int         // y coordinate
}

const (
    Button1 ButtonMask = 1 << iota  // left (primary)
    Button2                          // right (secondary)
    Button3                          // middle
    Button4, Button5, Button6, Button7, Button8
    WheelUp, WheelDown, WheelLeft, WheelRight
    ButtonNone ButtonMask = 0
)
```

**Drag Detection (superior to bubblezone):**
```go
case *tcell.EventMouse:
    x, y := ev.Position()
    button := ev.Buttons()
    // Track when drag starts (button down, no prior position)
    if button != tcell.ButtonNone && ox < 0 {
        ox, oy = x, y  // drag starts
    }
    // When button released, drag ends
    if button == tcell.ButtonNone && ox >= 0 {
        // drag completed
    }
```

**Source:** `_demos/mouse.go` at `gdamore/tcell`

---

#### Termbox-go (nsf/termbox-go)

**Source:** [github.com/nsf/termbox-go](https://github.com/nsf/termbox-go)

**Architecture:**
- Minimalist API for text-based UIs
- Mouse events via `Event.Type == EventMouse` with `Event.MouseX`, `Event.MouseY`
- Keys: `MouseLeft`, `MouseRight`, `MouseMiddle`, `MouseRelease`, `MouseWheelUp`, `MouseWheelDown`
- `ModMotion` modifier flag for drag detection

**Mouse Event Parsing (from source):**
```go
// X10 mouse encoding support
// Extended modes: xterm 1006, urxvt 1015
switch b & 3 {
case 0:
    if b&64 != 0 {
        event.Key = MouseWheelUp
    } else {
        event.Key = MouseLeft
    }
case 1:
    if b&64 != 0 {
        event.Key = MouseWheelDown
    } else {
        event.Key = MouseMiddle
    }
case 2:
    event.Key = MouseRight
case 3:
    event.Key = MouseRelease
}
if b&32 != 0 {
    event.Mod |= ModMotion  // drag detected
}
```

---

### 1.2 Rust Libraries

#### Ratatui + ratatui-interact

**Source:** [docs.rs/ratatui-interact](https://docs.rs/ratatui-interact/latest/ratatui_interact/)

**Architecture:**
- Ratatui is the successor to `tui` crate — no built-in mouse support
- `ratatui-interact` provides `ClickRegionRegistry` for hit testing

**Click Region Pattern:**
```rust
let mut registry: ClickRegionRegistry<&str> = ClickRegionRegistry::new();

registry.clear();
registry.register(Rect::new(0, 0, 10, 1), "button1");
registry.register(Rect::new(15, 0, 10, 1), "button2");

// Check clicks during event handling
if let Some(clicked) = registry.handle_click(mouse_x, mouse_y) {
    println!("Clicked: {}", clicked);
}
```

**Components provided:**
- `Checkbox`, `Input`, `Button`, `Select`, `ContextMenu`, `MenuBar`, `PopupDialog`
- Focus management via `FocusManager` with Tab/Shift+Tab navigation
- `MousePointer` widget for visual cursor indicator

**Limitations:**
- Ratatui doesn't support hover states natively — requested in [Issue #1050](https://github.com/ratatui-org/ratatui/issues/1050)
- Egui-style `rect.clicked(&ctx)` API proposed but not implemented

---

#### tui-world

**Source:** [docs.rs/tui-world](https://docs.rs/tui-world)

**Architecture:**
- State and event management for TUIs built with ratatui
- `Pointer` struct manages mouse/pointer interactions for widgets

**Key types:**
```rust
pub struct Pointer {
    // Tracks widget areas and handles mouse click/drag/up events
}

pub struct Area {
    // Rectangular area defined by position and dimensions
}

pub struct World {
    // Container holding application state
}
```

---

### 1.3 Python Libraries

#### Textual (Textualize/textual)

**Source:** [github.com/Textualize/textual](https://github.com/Textualize/textual)

**Architecture:**
- Modern async TUI framework with CSS-like styling
- Message-passing system with event bubbling
- Built-in hover, click, drag support

**Mouse Events:**
```python
from textual.events import MouseDown, MouseMove, MouseUp

class MyWidget(Static):
    def on_mouse_down(self, event: MouseDown) -> None:
        self.capture_mouse()  # track mouse outside widget
        self.dragging = entity_at(event.offset)

    def on_mouse_move(self, event: MouseMove) -> None:
        if event.button == 1 and self.dragging:  # left button = drag
            self.dragging.x = event.screen_x - self.drag_offset.x
            self.dragging.y = event.screen_y - self.drag_offset.y

    def on_mouse_up(self, event: MouseUp) -> None:
        self.release_mouse()
        self.dragging = None
```

**Hover States:**
```python
# Simply implement on_mouse_move and check if position is within bounds
def on_mouse_move(self, event: MouseMove) -> None:
    if self.bounds.contains(event.offset):
        self.add_class("--hovered")
```

**Drag Detection (from textual discussion #2752):**
```python
class Tank(Widget):
    dragging: var[Entity | None] = var(None)
    drag_offset: var[Offset | None] = var(None)

    def on_mouse_down(self, event: events.MouseDown) -> None:
        self.capture_mouse()
        self.dragging = entity_at(event.offset, Entity.instances)
        if self.dragging is not None:
            self.drag_offset = event.offset - Offset(self.dragging.x, self.dragging.y)
```

**Source:** [Textual discussion #2752](https://github.com/Textualize/textual/discussions/2752)

---

#### Prompt-toolkit (python-prompt-toolkit)

**Source:** [github.com/prompt-toolkit/python-prompt-toolkit](https://github.com/prompt-toolkit/python-prompt-toolkit)

**Improvements (PR #1387):**
- Discriminate which mouse button was pressed
- Report click-drags (mouse movements while button held)
- Report mouse movements when no button pressed (hover)
- Realtime text selection during drag

**Mouse Event Types:**
```python
class MouseEventType(Enum):
    MOUSE_MOVE = "mouse move"
    MOUSE_DOWN = "mouse down"
    MOUSE_UP = "mouse up"
    SCROLL_UP = "scroll up"
    SCROLL_DOWN = "scroll down"

class MouseButton(Enum):
    LEFT = 1
    MIDDLE = 2
    RIGHT = 3
    NO_BUTTON = 0
```

**Source:** [PR #1387](https://github.com/prompt-toolkit/python-prompt-toolkit/pull/1387)

---

## 2. Feature Comparison Matrix

| Feature | bubblezone (Go) | tcell (Go) | termbox-go (Go) | ratatui-interact (Rust) | textual (Python) |
|---------|-----------------|------------|-----------------|-------------------------|------------------|
| **Zone Detection** | APC markers | Manual rect | Manual rect | ClickRegionRegistry | Widget system |
| **Click Handling** | `InBounds()` | ButtonMask | Key events | `handle_click()` | `on_mouse_down` |
| **Drag Detection** | Manual | Built-in motion tracking | ModMotion flag | Manual | Built-in `capture_mouse` |
| **Hover States** | Manual motion | Manual motion | Manual motion | Not supported | Built-in `on_mouse_move` |
| **Multi-button** | Yes | Yes (8 buttons + wheels) | Limited | Yes | Yes |
| **Async Zone Processing** | Yes (goroutine) | N/A | N/A | N/A | N/A |

---

## 3. Current Candy-Zone Implementation Analysis

**Source:** `/home/sites/sugarcraft/candy-zone/src/`

### 3.1 What Works Well

1. **Faithful bubblezone port** — `Manager::scan()` mirrors upstream algorithm exactly
2. **APC marker handling** — correctly parses `candyzone:S:<id>` and `candyzone:E:<id>`
3. **Grapheme width handling** — `Width::string()` handles CJK characters correctly
4. **Prefix namespacing** — `Manager::newPrefix()` for component isolation
5. **Zone facades** — `Zones` class mirrors package-level Go functions
6. **Good test coverage** — 20+ tests covering edge cases

### 3.2 Gaps vs. Upstream & Competitors

| Gap | bubblezone | tcell/textual | Impact |
|-----|-----------|---------------|--------|
| **Hover state tracking** | Manual via motion events | Built-in | High |
| **Drag detection** | Manual | Built-in (tcell) | High |
| **Click count (double-click)** | Not built-in | Built-in (tcell) | Medium |
| **Button release tracking** | Via `MouseReleaseMsg` | Via `ButtonNone` state | Medium |
| **Motion event filtering** | Manual | Built-in modifier flags | Low |

---

## 4. Prioritized Recommendations

### Priority 1: Hover State Tracking

**Problem:** No way to detect when mouse enters/exits a zone without manual `MouseMotionMsg` handling.

**Approach:** Add `ZoneHoverTracker` class that:
1. Tracks current hovered zone via `anyInBounds()` on motion events
2. Emits `MsgZoneEnter` / `MsgZoneExit` messages when zone changes
3. Stores last position for comparison

**Effort:** ~3-4 hours

**Design:**
```php
final class ZoneHoverTracker
{
    private ?string $currentZoneId = null;

    public function handleMotion(Manager $mgr, MouseMsg $msg): ?Msg
    {
        $hovered = $mgr->anyInBounds($msg);
        $hoveredId = $hovered?->id;

        if ($hoveredId !== $this->currentZoneId) {
            $exit = $this->currentZoneId;
            $this->currentZoneId = $hoveredId;
            return new MsgZoneHoverChange($exit, $hoveredId, $msg);
        }
        return null;
    }
}
```

---

### Priority 2: Drag Detection Helper

**Problem:** Users must manually track `MouseAction::Press` → motion → `MouseAction::Release` sequences.

**Approach:** Add `DragTracker` class that:
1. Records `Press` position and target zone
2. Tracks motion while button held
3. Emits `MsgZoneDragStart`, `MsgZoneDragMove`, `MsgZoneDragEnd`

**Effort:** ~4-5 hours

**Design:**
```php
final class DragTracker
{
    private ?DragState $state = null;

    public function handleMouse(Manager $mgr, MouseMsg $msg): ?Msg
    {
        return match ($msg->action) {
            MouseAction::Press => $this->onPress($mgr, $msg),
            MouseAction::Move => $this->onMove($msg),
            MouseAction::Release => $this->onRelease($msg),
        };
    }
}
```

---

### Priority 3: Click Count Support

**Problem:** No way to detect double-click, triple-click.

**Approach:** Add `ClickCounter` utility that:
1. Tracks timestamps and positions of recent clicks
2. Returns click count (1, 2, 3) based on time threshold (default 500ms)
3. Can be queried: `ClickCounter::getClickCount($x, $y)` returns count

**Effort:** ~2 hours

---

### Priority 4: Motion Event Mode Toggle

**Problem:** candy-zone assumes all motion events are relevant; no way to filter.

**Approach:** Add `setMotionTracking(bool)` to Manager:
1. When disabled (default), `anyInBounds()` only checks on button events
2. When enabled, checks on every `MouseMotionMsg`
3. Mirrors BubbleTea's `MouseMode` concept

**Effort:** ~1-2 hours

---

## 5. Implementation Plan

### Phase 1: Hover Tracking (3-4 hours)
- [ ] Create `src/MsgZoneHoverChange.php` message type
- [ ] Create `src/ZoneHoverTracker.php` class
- [ ] Add tests in `tests/ZoneHoverTrackerTest.php`
- [ ] Document usage in README

### Phase 2: Drag Detection (4-5 hours)
- [ ] Create `src/MsgZoneDrag.php` message types (Start, Move, End)
- [ ] Create `src/DragTracker.php` class
- [ ] Add tests
- [ ] Add example `examples/draggable.php`

### Phase 3: Click Count (2 hours)
- [ ] Create `src/ClickCounter.php` utility class
- [ ] Add tests for timing thresholds
- [ ] Update `MouseMsg` or create `MouseMsgWithClicks` wrapper

### Phase 4: Motion Mode Toggle (1-2 hours)
- [ ] Add `Manager::setMotionTracking(bool)`
- [ ] Update `anyInBounds()` to respect mode
- [ ] Update documentation

---

## 6. References

- **BubbleZone:** https://github.com/lrstanley/bubblezone
- **BubbleZone Ruby port:** https://github.com/marcoroth/bubblezone-ruby (shows zone iteration patterns)
- **TCell mouse demo:** https://github.com/gdamore/tcell/blob/main/_demos/mouse.go
- **Termbox-go mouse parsing:** https://github.com/nsf/termbox-go/blob/master/termbox.go (lines 200-280)
- **Ratatui-interact:** https://docs.rs/ratatui-interact/latest/ratatui_interact/
- **Textual drag discussion:** https://github.com/Textualize/textual/discussions/2752
- **Prompt-toolkit PR:** https://github.com/prompt-toolkit/python-prompt-toolkit/pull/1387
