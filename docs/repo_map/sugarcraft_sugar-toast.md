# SugarToast — Innovation & Comparison Report

## Metadata

- **Package**: `sugarcraft/sugar-toast`
- **Source**: `sugar-toast/` directory in SugarCraft monorepo
- **Composer**: `sugarcraft/sugar-toast`
- **Namespace**: `SugarCraft\Toast`
- **PHP**: `^8.3`
- **Dependencies**: `sugarcraft/candy-core` (dev-master)
- **Status**: 🟢 v1 ready
- **Upstream Primary**: [daltonsw/bubbleup](https://github.com/daltonsw/bubbleup) (Go, ~43 stars, MIT)
- **Upstream Secondary**: [charmbracelet/bubbles](https://github.com/charmbracelet/bubbles) (progress bar reference)
- **Documentation**: `sugar-toast/README.md`, `sugar-toast/CALIBER_LEARNINGS.md`
- **Tests**: 13 test files, all passing

---

## Package Architecture

### Source Files (8 PHP files)

| File | Lines | Role |
|------|-------|------|
| `src/Toast.php` | 528 | Main model — queue, rendering, alert lifecycle |
| `src/Alert.php` | 56 | Individual alert data — type, message, expiry, progress, actions |
| `src/ToastType.php` | 90 | Enum — Error/Warning/Info/Success with icons and colors |
| `src/Position.php` | 54 | Enum — 9 screen positions (Top/Middle/Bottom × Left/Center/Right) |
| `src/Action.php` | 34 | Value object — `readonly string $label` + `\Closure(): void $callback` |
| `src/SymbolSet.php` | 15 | Enum — NerdFont / Unicode / ASCII |
| `src/Overflow.php` | 20 | Enum — DropOldest / DropNewest / Enqueue |
| `src/HistoryLog.php` | 47 | Immutable log — `list<Alert>` with `push()` and `all()` |
| `src/Lang.php` | 22 | i18n facade extending `SugarCraft\Core\I18n\Lang` |

### Rendering Pipeline

```
Toast::View($background, $viewportWidth, $viewportHeight)
  ├── Filter expired alerts
  ├── Two-pass height computation (totalAlertLines)
  └── Per-alert compositeLines():
        ├── x = position->xOffset(alertWidth, viewportWidth)
        ├── y = position->yOffset(alertHeight, viewportHeight, cumulativeHeight)
        ├── composite by display-cell slicing (Width::truncateAnsi, Width::dropAnsi)
        └── ANSI-safe (no mid-grapheme cuts)
```

### Key Design Decisions

1. **Immutable + fluent**: All `with*()` setters return `$clone` via `clone $this` + property mutation
2. **Pure renderer**: `View()` accepts any background string — works with any TUI framework
3. **Display-cell slicing**: All string cuts use `Width::string()` / `Width::truncateAnsi()` instead of `strlen`/`substr` — fixes the UTF-8 multibyte border truncation bug
4. **Queue-based multi-alert**: Upstream (bubbleup) has single-alert only; SugarToast has `array $queue` with overflow strategies
5. **History log**: `dismiss()` records non-expired alerts to immutable `HistoryLog`
6. **Action callbacks**: `Action` value object with `Closure(): void` stored in `Alert::$actions[]`
7. **String-to-enum coercion**: `Toast::alert(ToastType|string)` accepts lowercase strings via `ToastType::tryFrom(strtolower($type))`

---

## Feature Inventory

### Notification Types

- **4 types**: `ToastType::Error` (red 31), `ToastType::Warning` (yellow 33), `ToastType::Info` (blue 34), `ToastType::Success` (green 32)
- **Upstream has 4** (Info/Warn/Error/Debug) but **SugarToast does NOT have Debug** — Debug was dropped from port
- Each type provides three icon sets via `SymbolSet`:
  - `NerdFont`: 󰬅 / 󱈸 /  / 󰃤 (actual NerdFont codepoints, not ASCII)
  - `Unicode`: ✖ / ⚠ / ℹ / ✔ (ASCII-incompatible but widely supported)
  - `Ascii`: [E] / [W] / [I] / [S] (maximum compatibility)
- Each type has a `->label()` method returning i18n-aware string via `Lang::t('type.' . $this->value)`

### 9 Screen Positions

**Position enum cases** (vs upstream's 6):
```php
TopLeft / TopCenter / TopRight      // same as upstream
MiddleLeft / MiddleCenter / MiddleRight  // NEW — not in upstream
BottomLeft / BottomCenter / BottomRight   // same as upstream
```

**Y-offset calculation** (key algorithm in `Position::yOffset()`):
- **Top** positions: `$totalAlertLines` (stack downward from y=0)
- **Bottom** positions: `$viewportHeight - $alertHeight - $totalAlertLines` (stack upward)
- **Middle** positions: `floor(($viewportHeight - $alertHeight) / 2) - $totalAlertLines` (center + stack inward)

> **CALIBER_LEARNINGS.md** documents a past bug: Top positions returned y=0 ignoring `$totalAlertLines`, causing overlapping toasts whose stale SGR resets leaked past the box border.

### Auto-Dismiss Mechanism

- `Alert::$expiresAt: ?float` stores Unix timestamp (or null = persistent)
- `Alert::isExpired()`: `microtime(true) >= $this->expiresAt`
- `Toast::alert()` applies `->withExpiry($duration)` only when `$expiresAt === null` AND `$this->duration !== null`
- Per-call override: `Toast::alert($type, $msg, $expiresAt)` where `$expiresAt` is a Unix timestamp
- `Toast::pruneExpired()` removes expired alerts from queue (non-destructive to history)

### Queue System

- `Toast::$queue: array<Alert>` — ordered list
- `withMaxConcurrent(?int $n)` caps concurrent alerts (null = unlimited)
- `withOverflow(Overflow)` controls what happens when cap is exceeded:
  - `DropOldest`: `array_shift()` removes oldest before appending new (default)
  - `DropNewest`: returns `$clone` without appending
  - `Enqueue`: appends even when over cap

### Progress Bar Integration

- `Toast::progressToast(ToastType|string, string $msg, float $progress, ?float $expiresAt)` adds alert with inline progress bar
- Progress value 0.0–1.0 clamped via `max(0.0, min(1.0, $progress))`
- **Rendering**: Unicode block chars `█` (filled) + `░` (empty), flush with borders
- Example output line: `│████████░░░░░░░░░░░░░░░░░░│` (50 cells wide)

> Upstream bubbleup has **no progress bar** — SugarToast added this feature.

### Action Buttons

- `Action` value object: `public readonly string $label`, `public readonly \Closure(): void $callback`
- Attach via `Alert::withActions(array<Action>)`
- Rendered as `[Label]` lines inside the toast box
- Callback is stored but **not invoked automatically** — caller must call `$action->callback()` in their key/mouse handler

> Upstream bubbleup has **no action buttons** — SugarToast added this feature.

### History Log

- `HistoryLog`: immutable collection, `private readonly array $entries`
- `dismiss()` calls `historyLog->push($alert)` for each non-expired alert before clearing
- `getHistory(): list<Alert>` returns full chronological record
- Immutability: `Toast` clone gets a new `HistoryLog` instance on dismiss

> Upstream bubbleup has **no history log** — SugarToast added this feature.

### ESC Dismiss

- `withAllowEscToClose(bool)` flag stored in `Toast::$allowEscToClose`
- Default: `true`
- **Note**: The flag exists and is stored, but the actual key-handling loop is the consumer's responsibility — `Toast::View()` does not process keyboard input; it just renders. The flag serves as documentation and consumer guidance.

### Dynamic Width

- `withMaxWidth(int $w)`: hard maximum in cells
- `withMinWidth(int $w)`: minimum for dynamic sizing (0 = disabled)
- `resolveWidth(int $messageLen)`: computes actual width = `max(minWidth, min(messageLen + iconSpace + 4, maxWidth))`
- All width calculations use display-cell counting (`Width::string()`) not byte counting

---

## Quality & Safety Features

### Regression Guards (Tests)

| Test File | Coverage |
|----------|----------|
| `ToastBorderTest.php` | UTF-8 multibyte border integrity, ANSI not leaking past borders, display-cell alignment |
| `PositionMiddleTest.php` | Middle positions stack correctly; top/bottom unaffected |
| `OverflowTest.php` | DropOldest/DropNewest/Enqueue enum cases |
| `ToastMaxConcurrentTest.php` | Queue overflow behavior at exact limit |
| `ToastProgressTest.php` | Progress clamping (0.0–1.0), rendering, expiry |
| `ToastHistoryLogTest.php` | Immutability, expired alerts excluded from log |
| `ToastPersistentTest.php` | Null expiry = never expires |
| `ToastEscCloseTest.php` | Flag behavior, hasActiveAlert() logic |
| `ActionTest.php` | Callback invocation, `Action::make()` factory |
| `LangCoverageTest.php` | All `Lang::t()` keys exist in `lang/en.php` |
| `ToastCustomTypeTest.php` | String-to-enum coercion, case-insensitivity |
| `ToastAnimationTest.php` | Action buttons rendered, animation stub |
| `ToastBorderTest.php` | Regression: byte-slice overlay bug |

**Total test count**: ~90 assertions across 13 files

### Bug Fixes Documented in CALIBER_LEARNINGS.md

1. **[antipattern:byte-slice-overlay]**: Original `compositeLines()` used `substr()` on byte boundaries — sliced box-drawing chars mid-grapheme (╭───…─ became ╭───…─ + dangling UTF-8 continuation byte). **Fix**: use `Width::truncateAnsi()` / `Width::dropAnsi()` for display-cell-aware slicing.

2. **[gotcha:top-stacking-overlap]**: `Position::yOffset()` for Top positions returned y=0 ignoring `$totalAlertLines`. Stacked Top toasts all composited at y=0, overlapping, leaving stale SGR resets that `Width::dropAnsi()` harvested into the row tail. **Fix**: Top positions now add `$totalAlertLines` (stack downward).

3. **[antipattern:hand-built-sgr]**: `renderAlert()` built SGR sequences with `"\x1b[{$color}m"` literals. **Fix**: uses `Ansi::CSI . $color . 'm' . $icon . Ansi::reset() . ' '`.

---

## Comparison with Upstream (daltonsw/bubbleup)

### Feature Parity Matrix

| Feature | bubbleup (Go) | SugarToast (PHP) | Delta |
|--------|-------------|-----------------|-------|
| Alert types | 4 (Info/Warn/Error/Debug) | 4 (Info/Warn/Error/Success) | Replaced Debug→Success |
| Screen positions | 6 (corners/edges) | 9 (+ Middle*) | Enhanced |
| Dynamic width | Yes (minWidth) | Yes (minWidth) | Parity |
| Symbol sets | NerdFont / Unicode / ASCII | NerdFont / Unicode / ASCII | Parity |
| Auto-dismiss | Yes (tick-based) | Yes (timestamp-based) | Reimplemented |
| ESC dismiss | Yes (flag) | Yes (flag) | Parity |
| **Queue/multiple alerts** | ❌ (single alert) | ✅ | **SugarToast enhancement** |
| **Progress bar** | ❌ | ✅ | **SugarToast enhancement** |
| **Action buttons** | ❌ | ✅ | **SugarToast enhancement** |
| **History log** | ❌ | ✅ | **SugarToast enhancement** |
| **Custom type registration** | ✅ (RegisterNewAlertType) | ❌ (fixed 4 types) | Downgrade |
| Fade animation | ✅ (LAB color lerp) | Stub (animationDuration > 0 hint only) | Downgrade |
| BubbleTea TEA model | ✅ (full) | N/A (pure renderer) | Different architecture |
| i18n | ❌ | ✅ (Lang::t facade) | **SugarToast enhancement** |

### Key Architectural Differences

1. **TEA vs Pure Renderer**: bubbleup implements the full Elm architecture (`Init()`/`Update()`/`View()`) as a BubbleTea sub-model. SugarToast's `View()` is a stateless pure function — it takes a background string and returns a composite string. No message loop, no tick commands.

2. **Single alert vs Queue**: bubbleup's `activeAlert *alert` holds one alert at a time. SugarToast has `private array $queue = []` supporting unlimited concurrent alerts with overflow strategies.

3. **Timestamp vs Tick-based expiry**: bubbleup uses 100ms tick intervals (`tickCmd()` → `tickMsg`) and increments `curLerpStep` for animation. SugarToast uses Unix timestamps (`microtime(true)`) — expiry is checked on render, not driven by a timer.

4. **LAB color blending**: bubbleup uses `backColor.BlendLab(foreColor, curLerpStep)` for fade animation. SugarToast has only a stub (`animationDuration` field, no visual effect yet).

5. **Custom alert types**: bubbleup's `RegisterNewAlertType(AlertDefinition{Key, ForeColor, Style, Prefix})` allows runtime registration of new types. SugarToast is fixed to 4 built-in types.

6. **Dynamic width in Go**: bubbleup's `render()` uses `lipgloss.Width()` + `hangingWrap()` with lipgloss style. SugarToast implements word-wrapping manually with `Width::string()` for display-cell counting.

### What SugarToast Does Better

1. **Multiple concurrent alerts** — queue with overflow strategies
2. **Progress bar support** — built-in via `progressToast()`
3. **Action buttons with callbacks** — `Action` value object
4. **History log** — immutable record of dismissed toasts
5. **9 screen positions** — adds MiddleLeft/MiddleCenter/MiddleRight
6. **i18n** — `Lang::t()` facade with `lang/en.php` and `LangCoverageTest`
7. **Display-cell slicing** — proper UTF-8 multibyte handling (fixes a class of bugs upstream doesn't address)

### What bubbleup Does Better

1. **Custom alert type registration** — runtime extensibility
2. **Real fade animation** — LAB color blending via 100ms tick loop
3. **Full BubbleTea integration** — works as a TEA sub-model out of the box
4. **Custom style override** — `AlertDefinition.Style lipgloss.Style` for border customization

---

## Comparison with charmbracelet/bubbles (Progress Reference)

The `charmbracelet/bubbles` library provides a `Progress` component used as a reference for progress bar implementation patterns:

| Aspect | bubbles Progress | SugarToast Progress |
|--------|----------------|-------------------|
| Rendering | Half-block Unicode (`▌`) for 2x color resolution | Full-block `█`/`░` for simpler display |
| Animation | 60fps via Harmonica easing | Static (no animation) |
| Gradient fill | Yes (color blending) | No (solid fill) |
| Percentage | Yes | No (just the bar) |
| Integration | TEA component | Toast overlay |

**SugarToast's progress bar** is simpler but serves a different use case — overlaying a progress indicator inside a floating notification rather than as a standalone component.

---

## Related SugarCraft Libraries

| Library | Relationship |
|---------|-------------|
| `candy-core` | Provides `SugarCraft\Core\Util\Width` (display-cell measurement) and `SugarCraft\Core\Util\Ansi` (SGR helpers) — both used heavily |
| `sugar-veil` | General-purpose floating overlay compositor; sugar-toast uses similar overlay pattern |
| `honey-bounce` | Animation easing library; referenced in CALIBER_LEARNINGS.md for future CubicBezier fade animation |

---

## Innovation Assessment

### SugarToast is a **significant enhancement** of the upstream bubbleup library, not a mere port.

It addresses the most glaring gap in bubbleup (single-alert limitation) with a well-designed queue system and adds three genuinely useful features absent from the upstream: progress bars, action buttons, and a history log.

**Strengths**:
- Clean immutable + fluent API matching SugarCraft conventions
- Proper UTF-8 multibyte handling throughout (display-cell slicing vs byte slicing)
- Queue overflow strategies are thoughtful and well-tested
- i18n wiring with `LangCoverageTest` prevents translation drift

**Weaknesses / Technical Debt**:
- Animation stub (`withAnimationDuration()`) is a no-op — honey-bounce integration is documented as "deferred step 09.17" in CALIBER_LEARNINGS.md
- Action button callbacks stored but never auto-triggered — consumer must call them manually in key handler, which is not obvious from the README
- No custom alert type registration (upstream has `RegisterNewAlertType()`)
- The `allowEscToClose` flag is stored but the actual ESC key handling is entirely the consumer's responsibility — the flag documents intent but doesn't enforce it
- No programmatic removal of individual alerts (only `clear()`, `dismiss()`, `pruneExpired()`)

---

## Test Coverage Summary

```
sugar-toast/
├── src/
│   ├── Toast.php        — main model + rendering
│   ├── Alert.php         — alert data object
│   ├── ToastType.php     — 4-type enum with icons/colors
│   ├── Position.php      — 9-position enum with xOffset/yOffset
│   ├── Action.php        — callback value object
│   ├── SymbolSet.php     — NerdFont/Unicode/Ascii enum
│   ├── Overflow.php      — DropOldest/DropNewest/Enqueue enum
│   ├── HistoryLog.php    — immutable alert collection
│   └── Lang.php          — i18n facade
├── lang/
│   └── en.php           — 6 translation keys
├── tests/               — 13 PHPUnit test files (~90 assertions)
└── examples/
    ├── basic.php        — basic usage demo
    └── types.php       — all types, positions, durations, symbol sets
```

---

## Files

- **Primary source**: `/home/sites/sugarcraft/sugar-toast/src/Toast.php` (528 lines)
- **Main test**: `/home/sites/sugarcraft/sugar-toast/tests/ToastTest.php`
- **Regression guard**: `/home/sites/sugarcraft/sugar-toast/tests/ToastBorderTest.php`
- **Patterns & gotchas**: `/home/sites/sugarcraft/sugar-toast/CALIBER_LEARNINGS.md`
- **Upstream bubbleup model.go**: https://github.com/daltonsw/bubbleup/blob/main/model.go
