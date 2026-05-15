# Sugar-Toast Library Research

**Date:** 2026-05-13
**Library:** sugar-toast (PHP port of DaltonSW/bubbleup)
**Upstream:** https://github.com/daltonsw/bubbleup

---

## Executive Summary

Sugar-toast currently implements a solid baseline of toast notification functionality. Research across Go, Rust, and Python ecosystems reveals several enhancements that would significantly improve the library. This document catalogs patterns from upstream bubbleup, Rust's `ratatui-notifications`, Python's `textual`, and other libraries to produce prioritized recommendations.

**Current State:** Sugar-toast has 6 positions, 4 alert types, dynamic width, auto-dismiss, and immutability patterns. Missing: action buttons, progress toasts, stacking control, animations, ESC dismiss, and 9-point positioning.

---

## 1. Current Implementation Analysis

**Source:** `/home/sites/sugarcraft/sugar-toast/src/`

### Strengths
- Immutable/fluent with*() pattern — excellent
- Alert expiry via `microtime(true)` based timestamps
- Multiple symbol sets (NerdFont, Unicode, ASCII)
- Clean separation: Toast, Alert, Position, ToastType, SymbolSet
- Composite-based rendering over background

### Gaps vs. Ecosystem
| Feature | sugar-toast | bubbleup (Go) | ratatui-notifications | textual |
|---------|-------------|---------------|----------------------|---------|
| Positions | 6 | 6 | 9 | CSS-controlled |
| Action buttons | ❌ | ❌ | ❌ | ❌ |
| Progress toasts | ❌ | ❌ | ❌ | ❌ |
| ESC dismiss | ❌ | ✅ | ❌ | ❌ |
| Max concurrent | ❌ | ❌ | ✅ | ❌ |
| Overflow strategy | ❌ | ❌ | ✅ | ❌ |
| History/dismissed log | ❌ | ❌ | ✅ | ❌ |
| Animations | ❌ | ❌ | ✅ | ✅ |
| Custom alert types | ToastType enum | AlertDefinition | Level enum | severity param |
| Hover pause | ❌ | ❌ | ❌ | ❌ |

---

## 2. Comparative Analysis by Category

### 2.1 Notification Positioning

#### Current (sugar-toast)
```php
enum Position {
    case TopLeft;
    case TopCenter;
    case TopRight;
    case BottomLeft;
    case BottomCenter;
    case BottomRight;
}
```

**Source:** `sugar-toast/src/Position.php:L12-L19`

#### Enhanced: ratatui-notifications (Rust)
Rust's library provides **9 anchor positions** including edge midpoints:

```rust
// Source: https://docs.rs/ratatui-notifications/latest/ratatui_notifications/all.html
enum Anchor {
    // Corners
    TopLeft, TopRight, BottomLeft, BottomRight,
    // Edges
    TopCenter, MiddleLeft, MiddleRight, BottomCenter,
    // Center
    MiddleCenter,
}
```

**Source:** https://docs.rs/ratatui-notifications/latest/ratatui_notifications/all.html

#### Recommendation
**Add 3 edge-center positions** (TopCenter already exists as TopCenter, need MiddleLeft, MiddleRight, MiddleCenter):

```php
enum Position {
    // Existing 6
    case TopLeft;
    case TopCenter;
    case TopRight;
    case BottomLeft;
    case BottomCenter;
    case BottomRight;
    // New 3 edge/center positions
    case MiddleLeft;
    case MiddleRight;
    case MiddleCenter;
}
```

**Effort:** Low (1-2 hours) — enum cases + offset calculations

---

### 2.2 Auto-Dismiss Timing

#### Current Implementation
```php
// Alert stores expiresAt as float (seconds since epoch)
// Toast applies duration at alert creation time
public function alert(ToastType $type, string $message, ?float $expiresAt = null): self
{
    $alert = new Alert($type, $message, $expiresAt);
    if ($expiresAt === null && $clone->duration !== null) {
        $alert = $alert->withExpiry($clone->duration);
    }
    // ...
}
```

**Source:** `sugar-toast/src/Toast.php:L98-L107`

#### Enhanced: ratatui-notifications (Rust)
```rust
// Source: https://docs.rs/ratatui-notifications/latest/ratatui_notifications/
pub enum AutoDismiss {
    After(Duration),  // Dismiss after specific duration
    Never,            // Persistent - requires explicit dismiss
}
```

```rust
let notif = Notification::new("This will disappear...")
    .auto_dismiss(AutoDismiss::After(Duration::from_secs(5)))
    .build()
    .unwrap();

let persistent = Notification::new("Click to dismiss")
    .auto_dismiss(AutoDismiss::Never)
    .build()
    .unwrap();
```

#### Enhanced: goaster (Go web)
```go
// Source: https://github.com/indaco/goaster
// Progress bar for auto-dismiss countdown
toaster := goaster.NewToaster(
    goaster.WithAutoDismiss(true),
    goaster.WithProgressBar(true),  // Visual countdown indicator
)
```

#### ntm (Go TUI) — Progress Toasts
```go
// Source: https://github.com/Dicklesworthstone/ntm/commit/74a0ecf
type Toast struct {
    Persistent bool    // If true, don't auto-dismiss
    Progress   float64 // Progress value 0.0-1.0 (only used if > 0)
}

func (tm *ToastManager) PushProgress(id, message string, progress float64) {
    tm.Push(Toast{
        ID:         id,
        Message:    message,
        Progress:   progress,
        Persistent: true, // Progress toasts don't auto-dismiss
    })
}
```

#### Recommendation
**Add 3 enhancements:**

1. **Persistent toasts** — `Alert::withPersistent()` returns Alert that never auto-dismisses

```php
public function withPersistent(): self
{
    return new self($this->type, $this->message, null);
}
```

2. **Progress toasts** — New `Alert::withProgress(float $progress)` method

```php
final class Alert {
    public function __construct(
        public readonly ToastType $type,
        public readonly string $message,
        public readonly ?float $expiresAt = null,
        public readonly ?float $progress = null,  // 0.0-1.0
    ) {}
}
```

3. **Countdown progress indicator** — Add visual rendering in View()

**Effort:** Medium (4-6 hours)

---

### 2.3 Stack Management

#### Current Implementation
All alerts render at same position, offset by Y. No limit on concurrent alerts.

```php
public function View(string $background, int $viewportWidth = 80, int $viewportHeight = 24): string
{
    // Renders ALL active alerts, no stacking control
    foreach ($active as $alert) {
        $alertStr = $this->renderAlert($alert);
        $alertLines = $this->splitLines($alertStr);
        $x = $this->position->xOffset($alertWidth, $viewportWidth);
        $y = $this->position->yOffset($alertHeight, $viewportHeight);
        // Overwrites previous at same position!
        $bgLines = $this->compositeLines($bgLines, $alertLines, $x, $y, $alertWidth);
    }
}
```

**Source:** `sugar-toast/src/Toast.php:L185-L215`

**Problem:** When multiple alerts exist, they render on top of each other at the same position. The last one wins.

#### Enhanced: ratatui-notifications (Rust)
```rust
// Source: https://github.com/5ocworkshop/ratatui-notifications
let notifications = Notifications::new()
    .max_concurrent(Some(5))           // Max 5 visible at once
    .overflow(Overflow::DiscardOldest); // Remove oldest when full

pub enum Overflow {
    DiscardOldest,  // Remove oldest when full
    DiscardNewest,  // Refuse newest when full
    AllowGrowth,    // Allow more than max (for one-time bursts)
}
```

#### ntm (Go) — Stack Height & Position Lookup
```go
// Source: https://github.com/Dicklesworthstone/ntm/commit/74a0ecf
// ToastAtPosition returns toast ID at Y offset for click-to-dismiss
func (tm *ToastManager) ToastAtPosition(yOffset int) string {
    if len(tm.toasts) == 0 || yOffset < 0 {
        return ""
    }
    currentY := 0
    for _, t := range tm.toasts {
        toastHeight := 3
        if t.Progress > 0 {
            toastHeight = 4
        }
        if yOffset >= currentY && yOffset < currentY+toastHeight {
            return t.ID
        }
        currentY += toastHeight
    }
    return ""
}

// ToastStackHeight returns total rendered height
func (tm *ToastManager) ToastStackHeight() int { ... }
```

#### Recommendation
**Add stack management:**

1. **Max concurrent limit** — `Toast::withMaxConcurrent(int $n)`

```php
private int $maxConcurrent = 0;  // 0 = unlimited
```

2. **Overflow strategy enum**

```php
enum Overflow {
    case DiscardOldest;
    case DiscardNewest;
    case AllowGrowth;
}

private Overflow $overflow = Overflow::DiscardOldest;
```

3. **Stack offset calculation** — Fix Y offset to account for multiple stacked toasts

```php
public function yOffset(int $alertHeight, int $viewportHeight, int $totalAlertLines = 0): int
{
    // Currently $totalAlertLines is never passed!
    // Need to calculate and pass total stack height
}
```

**Effort:** Medium (4-5 hours)

---

### 2.4 Action Buttons

#### Current State
No action button support. Toasts are display-only.

#### Enhanced: win11toast (Python Windows)
```python
# Source: https://github.com/GitHub30/win11toast
from win11toast import toast

# Single button
toast('Hello', 'Hello from Python', button='Dismiss')

# Multiple buttons
toast('Hello', 'Click a button', buttons=['Approve', 'Dismiss', 'Other'])

# Button with action
toast('Hello', 'Hello from Python',
      button={'activationType': 'protocol',
              'arguments': 'https://google.com',
              'content': 'Open Google'})
```

#### goaster (Go web)
```go
// Source: https://github.com/indaco/goaster
toaster := goaster.NewToaster(
    goaster.WithButton(true),  // Show close button
)
```

#### Recommendation
**Add action button support:**

```php
final class Alert {
    public function __construct(
        public readonly ToastType $type,
        public readonly string $message,
        public readonly ?float $expiresAt = null,
        public readonly ?float $progress = null,
        public readonly array $actions = [],  // ['label' => 'callback_id']
    ) {}

    public function withAction(string $label, string $callbackId): self
    {
        return new self(
            $this->type,
            $this->message,
            $this->expiresAt,
            $this->progress,
            [...$this->actions, $label => $callbackId],
        );
    }
}
```

**Effort:** Medium-High (6-8 hours) — requires key event handling integration

---

### 2.5 Keyboard Interaction

#### Current State
No keyboard interaction.

#### bubbleup (Go)
```go
// Source: https://github.com/daltonsw/bubbleup
m.alert = bubbleup.NewAlertModel(50, false, 10*time.Second).
    WithAllowEscToClose()

// Check in Update() to avoid conflicts
func (m myModel) Update(msg tea.Msg) (tea.Model, tea.Cmd) {
    switch msg := msg.(type) {
    case tea.KeyMsg:
        switch msg.String() {
        case "esc":
            if !m.alert.HasActiveAlert() {
                return m, tea.Quit  // Only quit if no alert active
            }
        }
    }
    // Pass to alert model
    outAlert, outCmd := m.alert.Update(msg)
    m.alert = outAlert.(bubbleup.AlertModel)
    return m, tea.Batch(alertCmd, outCmd)
}
```

#### Recommendation
**Add ESC dismiss:**

```php
private bool $allowEscToClose = false;

public function withAllowEscToClose(bool $allow = true): self
{
    $clone = clone $this;
    $clone->allowEscToClose = $allow;
    return $clone;
}

public function hasActiveAlert(): bool
{
    $active = array_filter($this->queue, fn(Alert $a): bool => !$a->isExpired());
    return count($active) > 0;
}
```

**Effort:** Low (2-3 hours)

---

### 2.6 Animation Support

#### Current State
No animations — toasts appear/disappear instantly.

#### ratatui-notifications (Rust)
```rust
// Source: https://docs.rs/ratatui-notifications/latest/
pub enum Animation {
    Slide(SlideDirection),
    Fade,
    Expand,
}

pub enum SlideDirection {
    Top, Right, Bottom, Left,
}

// Usage
let overlay = Overlay::new()
    .anchor(Anchor::Right)
    .slide(Slide::Right);
```

#### textual (Python)
Textual's toast system inherently supports CSS animations via the widget system.

#### Recommendation
**Add animation enum and slide-in rendering:**

```php
enum Animation {
    case None;
    case SlideTop;
    case SlideBottom;
    case SlideLeft;
    case SlideRight;
    case Fade;
}

private Animation $animation = Animation::None;
```

For PHP TTY context, animation would be rendered via multi-frame output with cursor positioning.

**Effort:** Medium (5-7 hours) — requires animation loop integration

---

### 2.7 Custom Alert Types

#### Current (sugar-toast)
```php
enum ToastType: string {
    case Error   = 'error';
    case Warning = 'warning';
    case Info    = 'info';
    case Success = 'success';
}
```

**Source:** `sugar-toast/src/ToastType.php:L12-L17`

#### bubbleup (Go)
```go
// Source: https://github.com/daltonsw/bubbleup
// Register custom alert type
alert.RegisterNewAlertType(bubbleup.AlertDefinition{
    Key:       "Counter",
    ForeColor: "#FF69B4", // Hot pink
    Prefix:    "#",
})
```

#### Recommendation
**Extend ToastType to support custom types:**

```php
final class ToastType {
    public function __construct(
        public readonly string $key,
        public readonly string $color,
        public readonly string $icon,
        public readonly SymbolSet $iconSet = SymbolSet::Unicode,
    ) {}

    // Predefined types
    public static function error(): self { ... }
    public static function warning(): self { ... }
    public static function info(): self { ... }
    public static function success(): self { ... }

    // Custom type
    public static function custom(string $key, string $color, string $icon): self { ... }
}
```

**Effort:** Medium (4-5 hours) — requires backward-compatible API design

---

### 2.8 History / Dismissed Log

#### ntm (Go)
```go
// Source: https://github.com/Dicklesworthstone/ntm/commit/74a0ecf
type ToastManager struct {
    toasts  []Toast
    history []Toast  // Ring buffer of dismissed toasts
}

func (tm *ToastManager) GetHistory() []Toast {
    return tm.history
}
```

#### Recommendation
**Add dismissed toast history:**

```php
private array $history = [];
private int $maxHistory = 20;

public function dismiss(): self  // Currently just sets dismissed flag
{
    $clone = clone $this;
    // Move active alerts to history before clearing
    foreach ($clone->queue as $alert) {
        if (!$alert->isExpired()) {
            $clone->history[] = $alert;
        }
    }
    if (count($clone->history) > $clone->maxHistory) {
        array_shift($clone->history);
    }
    $clone->dismissed = true;
    return $clone;
}

public function getHistory(): array { ... }
```

**Effort:** Low (2-3 hours)

---

## 3. Prioritized Recommendations

| Priority | Feature | Benefit | Effort | Risk |
|----------|---------|---------|--------|------|
| P1 | ESC dismiss | User experience | Low | Low |
| P1 | Stack offset fix | Correctness | Low | Low |
| P1 | 9 positions | Flexibility | Low | Low |
| P2 | Persistent toasts | Flexibility | Low | Low |
| P2 | Max concurrent + overflow | Robustness | Medium | Medium |
| P2 | Custom alert types | Extensibility | Medium | Medium |
| P3 | Progress toasts | Long-running ops | Medium | Medium |
| P3 | Action buttons | Interactivity | Medium-High | High |
| P3 | History log | Debugging | Low | Low |
| P4 | Animations | Polish | High | Medium |

---

## 4. Implementation Plan

### Phase 1: Quick Wins (1-2 sessions)
1. Add `MiddleLeft`, `MiddleRight`, `MiddleCenter` to Position enum
2. Add `withAllowEscToClose()` and `hasActiveAlert()` methods
3. Fix stack Y-offset calculation (pass totalAlertLines)
4. Add dismissed history with `maxHistory` limit

### Phase 2: Core Enhancements (2-3 sessions)
1. Add `withMaxConcurrent()` and `Overflow` enum
2. Add persistent toast support (`withPersistent()`)
3. Extend `ToastType` to support custom types via factory methods
4. Add progress support to `Alert` and rendering

### Phase 3: Advanced Features (3-4 sessions)
1. Action button system with callback registration
2. Animation framework (slide, fade)
3. Hover pause for auto-dismiss timers
4. Click-to-dismiss position detection

---

## 5. Detailed Implementation Sketches

### 5.1 ESC Dismiss Integration

```php
// sugar-toast/src/Toast.php (additions)

private bool $allowEscToClose = false;

public function withAllowEscToClose(bool $allow = true): self
{
    $clone = clone $this;
    $clone->allowEscToClose = $allow;
    return $clone;
}

public function hasActiveAlert(): bool
{
    $active = array_filter($this->queue, fn(Alert $a): bool => !$a->isExpired());
    return count($active) > 0;
}

/**
 * Handle an ESC key event. Returns true if the toast consumed the event.
 */
public function handleEsc(): bool
{
    if (!$this->allowEscToClose || $this->dismissed) {
        return false;
    }
    $active = array_filter($this->queue, fn(Alert $a): bool => !$a->isExpired());
    if (count($active) === 0) {
        return false;
    }
    // Dismiss the most recent alert
    $this->queue = array_values($active);
    array_pop($this->queue);
    return true;
}
```

### 5.2 Stack Position Calculation Fix

```php
// sugar-toast/src/Toast.php View() method fix
public function View(string $background, int $viewportWidth = 80, int $viewportHeight = 24): string
{
    if ($this->dismissed || $this->queue === []) {
        return $background;
    }

    $active = \array_values(
        \array_filter($this->queue, fn(Alert $a): bool => !$a->isExpired())
    );

    if ($active === []) {
        return $background;
    }

    $bgLines = $this->splitLines($background);

    // Calculate total stack height for proper positioning
    $stackOffset = 0;
    foreach ($active as $alert) {
        $alertStr = $this->renderAlert($alert);
        $alertLines = $this->splitLines($alertStr);
        $alertWidth = $this->maxWidth;
        $alertHeight = \count($alertLines);

        $x = $this->position->xOffset($alertWidth, $viewportWidth);
        $y = $this->position->yOffset($alertHeight, $viewportHeight, $stackOffset);

        $bgLines = $this->compositeLines($bgLines, $alertLines, $x, $y, $alertWidth);

        $stackOffset += $alertHeight;
    }

    return \implode("\n", $bgLines);
}
```

### 5.3 Progress Toast Rendering

```php
// In renderAlert(), add progress bar rendering
private function renderAlert(Alert $alert): string
{
    $width = $this->resolveWidth(\strlen($alert->message));
    $icon  = $alert->type->icon($this->symbols);
    $color = $alert->type->color();

    $prefix = "\x1b[{$color}m{$icon}\x1b[0m ";
    $header = $prefix . $alert->message;

    $top    = '╭' . \str_repeat('─', $width - 2) . '╮';

    $wrapped = $this->wordWrap($alert->message, $width - \strlen($icon) - 4);
    $middleLines = [];
    foreach ($wrapped as $wl) {
        $middleLines[] = '│' . \str_pad(' ' . $wl, $width - 2) . '│';
    }

    $lines = [$top, ...$middleLines];

    // Add progress bar if progress is set
    if ($alert->progress !== null) {
        $barWidth = $width - 4;
        $filled = (int)($barWidth * $alert->progress);
        $empty = $barWidth - $filled;
        $progressBar = '│' . \str_repeat('█', $filled) . \str_repeat('░', $empty) . '│';
        $lines[] = $progressBar;
    }

    $lines[] = '╰' . \str_repeat('─', $width - 2) . '╯';
    return \implode("\n", $lines);
}
```

---

## 6. References

### Upstream
- DaltonSW/bubbleup: https://github.com/daltonsw/bubbleup
- BubbleUp Go package: https://pkg.go.dev/go.dalton.dog/bubbleup

### Rust
- ratatui-notifications: https://docs.rs/ratatui-notifications/latest/ratatui_notifications/
- ratatui-notifications source: https://github.com/5ocworkshop/ratatui-notifications
- ratatui-toaster: https://docs.rs/ratatui-toaster/latest/ratatui_toaster/
- ratui-overlay: https://crates.io/crates/tui-overlay
- ratkit toast: https://crates.io/crates/ratkit

### Python
- textual Toast widget: https://textual.textualize.io/widgets/toast/
- pyqt-toast-notification: https://pypi.org/project/pyqt-toast-notification/
- win11toast: https://github.com/GitHub30/win11toast

### Go (TUI)
- goaster: https://github.com/indaco/goaster
- SCKelemen/tui: https://pkg.go.dev/github.com/SCKelemen/tui
- bubbletea-modal: https://github.com/sraaaaaaay/bubbletea-modal
- ntm (enhanced toasts): https://github.com/Dicklesworthstone/ntm/commit/74a0ecf

### Go (Desktop notifications)
- nikoksr/notify: https://github.com/nikoksr/notify
- soloterm/tnotify: https://github.com/soloterm/tnotify
- hattya/go.notify: https://github.com/hattya/go.notify

---

## 7. Open Questions

1. **Animation complexity** — PHP TTY animations require multi-frame rendering with cursor positioning. Is there demand for this, or is instant appear/disappear acceptable?

2. **Action button UX** — How should action button callbacks be registered? Options:
   - Callback map passed to `Toast::new()`
   - Event dispatcher pattern
   - Closure/callback per action

3. **Backward compatibility** — `ToastType` is currently an enum. Changing to a class with factory methods would break existing code. Preferred approach?

4. **Integration with TUI frameworks** — Sugar-toast currently renders ANSI strings. Should it provide adapters for specific TUI frameworks (e.g., myclabs/php-tui)?