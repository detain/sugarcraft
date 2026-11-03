<img src=".assets/icon.png" alt="sugar-toast" width="160" align="right">

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-toast)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-toast)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/sugar-toast?label=packagist)](https://packagist.org/packages/sugarcraft/sugar-toast)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

# SugarToast




sugar-toast — a floating alert notification component for terminal UIs, for PHP 8.3+. Alerts float to the top of your TUI like bubbles in soda.

## Features

- **9 positions**: TopLeft, TopCenter, TopRight, MiddleLeft, MiddleCenter, MiddleRight, BottomLeft, BottomCenter, BottomRight
- **4 alert types**: Error, Warning, Info, Success — each with distinct styling
- **Dynamic width**: fixed or auto-sizing between minWidth and maxWidth
- **Symbol sets**: NerdFont (icons), Unicode (symbols), Ascii (plain text)
- **Auto-dismiss**: duration-based expiry support
- **Multiple alerts**: queue of toasts rendered in order
- **Progress toasts**: inline progress bar (0–100%) beneath the message body
- **Action buttons**: `[Label]` buttons attached to an alert with closure callbacks
- **Styled alerts**: per-alert background/foreground/border colour overrides
- **History log**: immutable, bounded record of dismissed alerts (default cap 100)
- **Pure renderer**: outputs ANSI strings; works with any TUI framework

## Install




```bash
composer require sugarcraft/sugar-toast
```

## Quick Start




```php
use SugarCraft\Toast\{Position, Toast, ToastType};

$toast = Toast::new(50)  // max width 50
    ->withPosition(Position::TopRight)
    ->withDuration(10.0);  // seconds

// Add alerts
$toast = $toast->alert(ToastType::Success, 'File saved!');
$toast = $toast->alert(ToastType::Error, 'Connection failed');

// Render into a viewport
$bg = str_repeat("background content\n", 20);
echo $toast->view($bg);
```

## Alert Types




```php
ToastType::Error
ToastType::Warning
ToastType::Info
ToastType::Success
```

Each type also provides a localized label via `ToastType::label()` (e.g.
`ToastType::Success->label()` → `"Success"` in English).

`alert()` accepts either a `ToastType` enum case or a lowercase string
(`"error"`, `"warning"`, `"info"`, `"success"`). Passing an unknown string
throws `InvalidArgumentException`.

## Positions




```php
Position::TopLeft
Position::TopCenter
Position::TopRight
Position::MiddleLeft
Position::MiddleCenter
Position::MiddleRight
Position::BottomLeft
Position::BottomCenter
Position::BottomRight
```

## Overflow & Concurrency




```php
Overflow::DropOldest  // remove oldest alert to make room (default)
Overflow::DropNewest  // discard the new alert instead of enqueueing
Overflow::Enqueue     // allow queue to exceed maxConcurrent
```

Control the maximum number of concurrent alerts with `withMaxConcurrent(int|null)`:

- Pass an integer to cap the queue size
- Pass `null` for unlimited (default) — expired alerts still leave the queue on
  the next write, but persistent (non-expiring) alerts accumulate until the
  host calls `clear()` or `dismiss()`



When the cap is reached, the configured `Overflow` strategy determines behaviour.

## Persistent Alerts




By default, alerts inherit `withDuration()` for auto-dismiss timing. A `null`
`$expiresAt` means "use the default", not "never expire" — persistence is its
own explicit sentinel on `Alert`. To get a persistent alert that never expires
automatically, either keep the toast's duration unset (the default, or
`withDuration(null)`) so queued alerts inherit nothing, or mark the alert
itself persistent — `withoutExpiry()` and its alias `withCancelledExpiry()`
set the sentinel, and every queueing door (`alert()`, `progressToast()`,
`push()`) honours it, so the escape hatch works even on a toast that has
`withDuration()` configured:

```php
$toast = Toast::new(50)->withPosition(Position::TopRight);  // no duration set
$toast = $toast->alert(ToastType::Info, 'Connected');       // never expires

// Per-alert escape hatch from an inherited expiry — persistent is explicit,
// so push() will NOT re-arm the toast's duration on this alert:
$alert = (new Alert(ToastType::Info, 'Connected'))->withoutExpiry();
$toast = $toast->push($alert);
```

A later `withExpiry()`/`withExtendedExpiry()` on a persistent alert clears the
sentinel (asking for a timer is asking for a timer). `Toast::cancelAlert()`
routes through the same sentinel.

Persistent alerts never expire, so `pruneExpired()` cannot remove them — take
them down with `dismiss()` (moves live alerts into the history log and stops
rendering until you `clear()`) or `clear()` (drops the queue without
recording). While dismissed, `alert()` throws; call `clear()` first.

## Loop integration




Expired alerts leave the queue on every write (`alert()`/`progressToast()`/
`push()`), and `pruneExpired()` removes them explicitly — but a quiet queue with
no incoming writes still needs something to *call* it. Rather than poll on a
fixed interval, ask the toast when the next prune is due and
schedule a single timer for exactly that moment:

```php
$delay = $toast->secondsUntilNextExpiry();   // seconds until the soonest expiry, or null
if ($delay !== null) {
    // e.g. in a sugar-core app:
    $cmd = Cmd::tick($delay, fn () => new ToastTickMsg());
}

// when the tick fires:
$toast = $toast->pruneExpired();
if ($toast->hasActiveAlert()) {
    // reschedule for the next expiry, repeat
}
```

- **`nextExpiry(): ?float`** — the soonest expiry instant (seconds since epoch)



  among queued alerts that auto-dismiss, or `null` if none expire. May be in the
  past when an alert is already due.

- **`secondsUntilNextExpiry(): ?float`** — the same as a delay from now, clamped



  to `>= 0.0` (an already-due alert yields `0.0`), or `null` if nothing expires.

## Internationalization




User-facing strings are internationalized via `SugarCraft\Toast\Lang::t()`.
All translatable strings live in `lang/en.php` under the `'toast'` namespace.

**Available keys** (`lang/en.php`):

| Key              | Default string                    | Parameters   |
|------------------|-----------------------------------|--------------|
| `type.info`       | `Info`                            | —            |
| `type.warning`    | `Warning`                         | —            |
| `type.error`      | `Error`                           | —            |
| `type.success`    | `Success`                         | —            |
| `dismiss`        | `Press any key to dismiss`        | —            |
| `count`           | `{count} notification(s)`         | `{count}`    |

> **Note:** The `dismiss` and `count` keys are provided as convenience strings
> for host-rendered dismiss-hint and counter UI. They are **not** emitted or
> rendered by `view()` itself — the library outputs only the alert overlay.

To add a locale, copy `lang/en.php` to `lang/<code>.php` and translate the
values. The lookup chain follows `SugarCraft\Core\I18n\T`:
exact locale → base language → `en` → raw key.

**Using the facade:**

```php
use SugarCraft\Toast\Lang;
use SugarCraft\Toast\ToastType;

$label = ToastType::Error->label();        // 'Error' (i18n-aware)
$prompt = Lang::t('dismiss');            // 'Press any key to dismiss'
$counter = Lang::t('count', ['count' => 3]); // '3 notification(s)'
```

**Adding new translatable strings:**

```php
// In any source file:
use SugarCraft\Toast\Lang;

// Simple key:
$msg = Lang::t('dismiss');

// With placeholder:
$counter = Lang::t('count', ['count' => $n]);
```

## Progress Toasts




Add a progress bar beneath the message body using `progressToast()`. Pass a
stable `id` and re-queuing with the same id **updates the box in place** —
without it, every tick appends another box, and on the default unlimited
concurrency the queue leaks a fresh toast per frame:

```php
$toast = $toast->progressToast(ToastType::Info, 'Downloading...', 0.65, id: 'download');
// Re-render as progress updates — same id, same box, replaced at its slot:
$toast = $toast->progressToast(ToastType::Info, 'Downloading...', 0.80, id: 'download');
// Finished — retire it without filing a dismissal in history:
$toast = $toast->removeAlert('download');
```

An id-carrying re-queue replaces the live alert at its queue position (order
among siblings preserved), bypasses the overflow policy and never consumes an
extra `maxConcurrent` slot — it is an update, not an append. Each tick
re-inherits the expiry, so a progress bar whose host stopped ticking
eventually expires on its own instead of freezing on screen forever.
`alert()` and `push()` accept ids too; `removeAlert(string $id)` retires a
single alert by identity. `progressToast()` accepts the same `$expiresAt`
override as `alert()`. The progress value (0.0–1.0) is clamped automatically.

## Action Buttons




Attach clickable buttons to an alert. Either build an `Alert` and enqueue it
with `push()`, or pass `actions:` to `alert()` directly:

```php
use SugarCraft\Toast\{Action, Alert, Toast, ToastType};

$action = Action::new('Retry', function (): void {
    // reconnect logic here
});

$alert = (new Alert(ToastType::Error, 'Connection lost'))
    ->withActions([$action]);
$toast  = $toast->push($alert);

// Equivalent shorthand:
$toast = $toast->alert(ToastType::Error, 'Connection lost', actions: [$action]);
```

The library renders the `[Label]` buttons; dispatching them is the host's
job — and it ships the geometry to do it with. `Action` is a value object
with `readonly string $label` (normalised at construction: only inline SGR
survives, see "Security & trust boundary") and `readonly \Closure(): void
$callback`. `actionRects()` returns a hit-test rectangle for every rendered
button — `{action, alert, alertIndex, actionIndex, row, col, width}` in the
same absolute frame coordinates `view()` composites in — and `actionAt()`
answers "which action covers this cell?". sugar-toast stays zone-neutral:
it emits no mouse zones and pulls in no mouse dependency; the host owns
input.

```php
use SugarCraft\Toast\{Action, Alert, Position, Toast, ToastType};

$toast = Toast::new(60)
    ->withPosition(Position::TopLeft)
    ->alert(ToastType::Error, 'Connection lost', actions: [
        Action::new('Retry', fn () => $reconnect()),
        Action::new('Cancel', fn () => $giveUp()),
    ]);

// Mouse: map the terminal's click to frame coords (subtract your pane
// offset), then dispatch whatever button is under the cursor:
$action = $toast->actionAt($clickCol, $clickRow, $background, $cols, $rows);
if ($action !== null) {
    ($action->callback)();
}

// Keyboard: enumerate the same rects — e.g. Alt+<n> fires the n-th button,
// or draw a focus ring at {row, col, width} and activate on Enter.
foreach ($toast->actionRects($background, $cols, $rows) as $n => $rect) {
    $hotkeys['alt+' . ($n + 1)] = $rect['action'];
}
```

`actionRects()`/`actionAt()`/`activeAlerts()` all ignore expired alerts, so
the clickable set is exactly what is on screen.

## Styled Alerts

Each alert accepts explicit background, foreground, and border colours,
falling back to the type's default palette when unset (`null`):

```php
use SugarCraft\Core\Util\Color;
use SugarCraft\Toast\{Alert, Toast, ToastType};

$alert = (new Alert(ToastType::Success, 'Deployed'))
    ->withBackgroundColor(Color::hex('#1a2f23'))
    ->withForegroundColor(Color::hex('#9ece6a'))
    ->withBorderColor(Color::hex('#9ece6a'));

$toast = $toast->push($alert);
```

## History Log




`Toast` maintains an immutable history of every dismissed alert via
`HistoryLog`:

```php
// Dismiss live alerts: they move into the history log and rendering stops
// until clear()
$toast = $toast->dismiss();

// Retrieve the log
$history = $toast->history();  // list<Alert>

foreach ($history as $alert) {
    echo $alert->type->label() . ': ' . $alert->message . "\n";
}
```

`HistoryLog` is immutable — `dismiss()` returns a new `Toast` with an
updated log; prior instances are unchanged. The log is capped at 100 entries
by default, oldest-first eviction; `withHistoryLimit(?int)` adjusts it
(`null` = unbounded).

## API Summary




| Method | Description |
|--------|-------------|
| `Toast::new(int $maxWidth = 50)` | Factory |
| `->withPosition(Position)` | Screen position (9 positions) |
| `->withDuration(?float $seconds)` | Auto-dismiss after N seconds; `null` disables |
| `->withMaxWidth(int)` | Maximum alert width in cells |
| `->withMinWidth(int)` | Minimum alert width in cells |
| `->withSymbolSet(SymbolSet)` | NerdFont, Unicode, or Ascii symbols |
| `->withAllowEscToClose(bool)` | Preference flag the host reads to decide if Escape dismisses (the renderer does not handle input) |
| `->withMaxConcurrent(?int $n)` | Cap concurrent alerts (`null` = unlimited) |
| `->withOverflow(Overflow)` | Strategy when cap exceeded: DropOldest, DropNewest, Enqueue |
| `->withHistoryLimit(?int $n)` | Cap history log, oldest evicted first (default 100; `null` = unbounded) |
| `->alert(ToastType\|string, string, ?float $expiresAt = null, ?array $actions = null, ?string $id = null)` | Add alert (string type = case-insensitive; `null` expiry inherits `withDuration()`; `id` updates a live alert in place) |
| `->progressToast(ToastType\|string, string, float $progress, ?float $expiresAt = null, ?array $actions = null, ?string $id = null)` | Add alert with progress bar (0.0–1.0); same `id` = same box |
| `->push(Alert $alert): self` | Enqueue a pre-built `Alert` (styled/actioned) |
| `->cancelAlert(int $index): self` | Make a queued alert persistent (never auto-dismisses) |
| `->removeAlert(string $id): self` | Retire the queued alert with this id, unrecorded (companion of id updates) |
| `->extendAlert(int $index, float $additionalSeconds): self` | Push one alert's expiry later |
| `->extendAll(float $additionalSeconds): self` | Push every expiring alert's expiry later |
| `->allowEscToClose(): bool` | Read the Escape-dismiss preference flag (host consumes it; the renderer handles no input) |
| `->error/warning/info/success(string)` | Convenience alert helpers |
| `->hasActiveAlert(): bool` | True if non-expired alerts queued |
| `->nextExpiry(): ?float` | Soonest expiry instant (epoch seconds) of an auto-dismissing alert, or `null` |
| `->secondsUntilNextExpiry(): ?float` | Delay until the next expiry, clamped `>= 0.0`, or `null` — schedule one prune tick |
| `->dismiss() / clear() / pruneExpired()` | Lifecycle: `dismiss()` moves live alerts to history (rendering stops until `clear()`, which also resets the flag) |
| `->history(): list<Alert>` | Return all dismissed alerts |
| `->view(string $background, int $w, int $h): string` | Render toast layer over background (the legacy `View()` call sites keep resolving via PHP's case-insensitive method dispatch — there is no alias method) |

| Class | Method | Description |
|-------|--------|-------------|
| `Alert` | `withProgress(float)` | Attach progress bar (0.0–1.0, clamped) |
| `Alert` | `withActions(list<Action>)` | Attach action buttons |
| `Alert` | `isExpired(): bool` | Check expiry |
| `Alert` | `withExpiry(float $duration)` | Set expiry from now |
| `Alert` | `new(ToastType, ?string, ?float, ?float, array, ?Color, ?Color, ?Color, bool $persistent = false, ?string $id = null)` | Factory (ctor mirror; trailing persistent/id named args) |
| `Alert` | `withoutExpiry()` | Make this alert persistent (never auto-dismisses) — alias of `withCancelledExpiry()`, sets the explicit sentinel |
| `Alert` | `withCancelledExpiry()` | Set the persistent sentinel + clear the expiry (used by `Toast::cancelAlert()`) |
| `Alert` | `withExtendedExpiry(float)` | Move the expiry later by N seconds |
| `Alert` | `withBackgroundColor(?Color)` | Override box background (`null` = type default) |
| `Alert` | `withForegroundColor(?Color)` | Override text colour (`null` = type default) |
| `Alert` | `withBorderColor(?Color)` | Override border colour (`null` = type default) |
| `Action` | `new(string $label, \Closure(): void $callback)` | Factory |
| `Action` | `->label: non-empty-string` | Button label (readonly) |
| `Action` | `->callback: \Closure(): void` | Callback (readonly) |
| `HistoryLog` | `push(Alert, ?int $limit = null): self` | Append alert, evicting oldest past `$limit`; new log |
| `HistoryLog` | `all(): list<Alert>` | Return all entries |
| `HistoryLog` | `count(): int` | Entry count |
| `Position` | `xOffset(int $alertWidth, int $viewportWidth): int` | X offset of a box in a viewport (left edge 0, center floored, right flush) |
| `Position` | `yOffset(int $alertHeight, int $viewportHeight, int $stackedHeightBefore = 0): int` | Y offset; top families stack downward from the top, bottom and middle families stack upward against `$stackedHeightBefore`, clamped to row 0 on short viewports |
| `ToastType` | `nerdIcon(): string` | NerdFont icon symbol for the type |
| `ToastType` | `unicodeIcon(): string` | Unicode symbol (✖ ⚠ ℹ ✔) — fallback when no NerdFont |
| `ToastType` | `asciiPrefix(): string` | Plain ASCII prefix (`[E]` `[W]` `[I]` `[S]`) |
| `ToastType` | `color(): string` | ANSI SGR color code for the type's icon (31/33/34/32) |

| Enum | Cases |
|-------|-------|
| `Position` | TopLeft, TopCenter, TopRight, MiddleLeft, MiddleCenter, MiddleRight, BottomLeft, BottomCenter, BottomRight |
| `ToastType` | Error, Warning, Info, Success |
| `Overflow` | DropOldest, DropNewest, Enqueue |
| `SymbolSet` | NerdFont, Unicode, Ascii |

## Shared foundations

- **[sugar-cellbuffer](https://github.com/sugarcraft/sugar-cellbuffer)** — alert queue



  composited via `Buffer`; each toast is a sub-`Buffer` composited into the
  screen `Buffer` for per-cell positioning and overlap resolution.

- **[sugar-testing](https://github.com/sugarcraft/sugar-testing)** — golden-file



  snapshot tests via `assertGoldenAnsi()` pin canonical queue layouts.

## Security & trust boundary

Toast content is app-supplied — and in a TUI the terminal is a sink. `view()`
renders alert text through the sugar-cellbuffer grid and re-encodes it, so the
output stream contains only the SGR sequences the renderer itself emits:

- **Control bytes can't escape**: raw ESC payloads, OSC hyperlinks (`\e]8;;…\e\\`)
  and BEL in a message are never forwarded — the output carries no OSC, no BEL,
  and no escape sequence the library did not build from a parsed style. A
  forged `\e[2J` inside a message survives only as styled cells.
- **Inline SGR in a message is honoured as styling** (bold/colour etc. are
  re-encoded through the grid). Treat that as a feature boundary: if the text
  is attacker-authored, sanitise before enqueueing —
  `SugarCraft\Core\Util\Sanitize::untrusted()` (or `untrustedForDisplay()`)
  strips the markup you do not want interpreted.
- **PUA zone sentinels are ordinary printable text here**: the U+E000/U+E001
  markers that sugar-mouse overlay plumbing uses pass through the grid like any
  other glyph — a message containing forged markers WILL carry them into the
  composed frame. Hosts that parse zone markers from rendered output must run
  `Sanitize::untrustedForMarkedFrames()` on attacker-authored text BEFORE
  enqueueing it.
- **Your own zone markers survive the overlay**: when `view()` receives a
  marked background frame, the composite CARRIES the host's U+E000..U+E001
  spans through — covered sentinel cells are restored over the box, so every
  open marker keeps its close (pairing preserved in all nine positions;
  unmarked frames stay byte-identical). The deliberate cost is cosmetic: box
  chrome shows holes where a marker was restored. The sweep duty above still
  applies to attacker-authored *toast text*, never to the host's own markers.
- **Host OSC-8 hyperlinks survive compositing**: background rows are parsed,
  not echoed — an `\e]8;;url\e\\…\e]8;;\e\\` link run is re-attached to the
  grid as a real hyperlink and re-emitted intact on the rows the toast does
  not cover (and it round-trips through delta hosts that feed `view()`'s own
  output back as the next background). Rows the box covers are replaced
  whole; nothing ever returns as printable `]8;;…` garbage.

The full fleet-wide contract lives in
[`docs/TRUST-BOUNDARY.md`](../docs/TRUST-BOUNDARY.md)
— §1 "the terminal is a sink".

## License




MIT

## Credits & inspiration

Design antecedent: [DaltonSW/bubbleup](https://github.com/daltonsw/bubbleup); SugarCraft is developed as a native PHP project.
