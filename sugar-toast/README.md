<img src=".assets/icon.png" alt="sugar-toast" width="160" align="right">

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-toast)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-toast)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/sugar-toast?label=packagist)](https://packagist.org/packages/sugarcraft/sugar-toast)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

# SugarToast

sugar-toast — a floating alert notification component for terminal UIs, for PHP 8.3+. Alerts composite over the host's background frame at one of nine anchor positions and never block input.

## Features

- **9 positions**: TopLeft, TopCenter, TopRight, BottomLeft, BottomCenter, BottomRight, MiddleLeft, MiddleCenter, MiddleRight
- **4 alert types**: Error, Warning, Info, Success — severity carried by icon + colour (add `withAccessibleText()` for a plain-text word; the chrome itself is identical across types)
- **Dynamic width**: full `maxWidth` by default; auto-fit to content engages ONLY via `withMinWidth(n>0)` — stacked boxes then anchor at ragged per-message offsets (shrink-to-fit vs stack-alignment trade)
- **Symbol sets**: NerdFont (icons), Unicode (symbols), Ascii (plain text)
- **Auto-dismiss**: time-based expiry, pruned by the host tick
- **Multiple alerts**: queue of toasts rendered in order
- **Progress toasts**: inline progress bar (0–100%) beneath the message body
- **Accessible text mode**: `withAccessibleText()` names the severity in words and prints progress percentages — a text channel beside the frame's colour/glyph language (WCAG 1.4.1)
- **Action buttons**: `[Label]` buttons attached to an alert with closure callbacks
- **Styled alerts**: per-alert background/foreground/border colour overrides
- **History log**: immutable, bounded record of dismissed alerts (default cap 100)
- **Pure renderer**: outputs ANSI strings; works with any TUI framework
- **Accessibility environment variables**: `REDUCE_MOTION`/`PREFERS_REDUCED_MOTION` drop blink; `NO_COLOR`/`CLICOLOR=0` drop all colour bytes (renderer palette, custom alert colours, inline message SGR); `CLICOLOR_FORCE` overrides. Environment-only checks — sugar-toast composites for its host and never probes a TTY itself

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

Nothing surprising in the defaults: position `TopLeft`, `maxWidth` 50, `minWidth` 0 (auto-fit
off), `duration` `null` (alerts persist until dismissed — persistence is the
explicit `Alert::$persistent` sentinel, not a magic `null` expiry),
`allowEscToClose` true, `maxConcurrent` `null` (unbounded), overflow
`DropOldest`, history limit 100, symbol set `Unicode`.

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
Position::BottomLeft
Position::BottomCenter
Position::BottomRight
Position::MiddleLeft
Position::MiddleCenter
Position::MiddleRight
```

## Overflow & Concurrency

```php
Overflow::DropOldest  // remove oldest alert to make room (default)
Overflow::DropNewest  // discard the new alert instead of enqueueing
Overflow::Enqueue     // allow queue to exceed maxConcurrent
```

Control the maximum number of concurrent alerts with `withMaxConcurrent(int|null)`:

- Pass an integer `>= 1` to cap the queue size — `0` and negatives throw
- Pass `null` for unlimited (default) — expired alerts still leave the queue on
  the next write, but persistent (non-expiring) alerts accumulate until the
  host calls `clear()` or `dismiss()`

When the cap is reached, the configured `Overflow` strategy determines
behaviour. `Enqueue`'s surplus is NOT transient: persistent (non-expiring)
alerts accumulate in the queue and stay there until the host clears or
replaces them, so under `Enqueue` the queue length only ever grows.

Per-alert SIZE is bounded too (lib-reviews-1102 M6: one hostile 32 KB string
measured 14 s inside `view()`): `Toast::MAX_MESSAGE_CELLS` (fixed at 8192)
truncates an over-long message with a visible `" …[truncated]"` marker, and
`Toast::MAX_ACTIONS_PER_ALERT` (fixed at 64) paints only the first 64 buttons
— the rest fold into a visible `"[+N more]"` row. The caps bind the RENDER
only: the full message text and the complete action list still ride the
`Alert` into history and `activeAlerts()`, so nothing is silently lost to a
host that reads the data instead of the pixels.

## Stacking & the frame

The frame is NOT pinned to `viewportHeight`: `view()` composites a canvas
whose height is `max(background rows, viewportHeight, total stack height)`.
Deep stacks therefore GROW THE FRAME DOWNWARD past the viewport — on a real
24-row terminal a ten-alert TopLeft stack emits thirty rows and the last
ones scroll off (delta hosts should know the frame can dirty more rows than
the screen). Bottom- and middle-anchored stacks count their offsets against
that inflated height, so with enough persistent alerts the oldest boxes land
below the physical screen too; the "clamped" wording only ever meant "inside
the frame", never "inside the viewport".

Overlapping stacks (the pile-up case: more box rows than the anchor has
room for) composite NEWER alerts over older ones — an older sibling's
message or action row can be visually occluded with no signal in the frame.
Expiry reflow also MOVES surviving boxes (no stable anchors yet); a
stable-row/`+N more` badge policy is an open owner-ruling seam (see lane
PROGRESS), so hosts with accessibility-critical stacks should cap
`maxConcurrent` or use `Enqueue` deliberately — its surplus never drains on
its own.

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

The `Alert` constructor itself accepts a `persistent` flag alongside an
explicit `$expiresAt`: it takes the pair without complaint, but the
sentinel wins — `isExpired()` short-circuits on persistence, so the stored
timestamp stays unread while the flag stands.

A later `withExpiry()`/`withExtendedExpiry()` on a persistent alert clears the
sentinel (asking for a timer is asking for a timer). `Toast::cancelAlert()`
routes through the same sentinel.

Persistent alerts never expire, so `pruneExpired()` cannot remove them — take
them down with `dismiss()` (moves live alerts into the history log and stops
rendering until you `clear()`) or `clear()` (drops the queue without
recording). While dismissed, the queueing doors `alert()`, `progressToast()`
and `push()` throw; call `clear()` first. The queue-editing doors do not
check the flag — `removeAlert()`, `pruneExpired()`, `cancelAlert()` and
`extendAlert()`/`extendAll()` operate on the emptied queue and no-op
silently on a dismissed instance.

## Loop Integration

Expired alerts leave the queue on every write (`alert()`/`progressToast()`/
`push()`), and `pruneExpired()` removes them explicitly — but a quiet queue with
no incoming writes still needs something to *call* it. Rather than poll on a
fixed interval, ask the toast when the next prune is due and
schedule a single timer for exactly that moment:

```php
$delay = $toast->secondsUntilNextExpiry();   // seconds until the soonest expiry, or null
if ($delay !== null) {
    // sugar-toast ships no message class of its own — wire this to your
    // host's own timer. Cmd::tick(float $seconds, \Closure $onTick) is
    // sugar-core's one-shot example:
    $cmd = Cmd::tick($delay, function () use (&$toast) {
        $toast = $toast->pruneExpired();
        // Repaint, then reschedule from the new secondsUntilNextExpiry();
        // when hasActiveAlert() is false there is nothing left to arm.
    });
}
```

- **`nextExpiry(): ?float`** — the soonest expiry instant (seconds since epoch)
  among queued alerts that auto-dismiss, or `null` if none expire. May be in the
  past when an alert is already due. Expiry is read from the wall clock
  (`CLOCK_REALTIME`), so a system clock step — an NTP sync, for instance — can
  stretch or cut an alert's on-screen lifetime.

- **`secondsUntilNextExpiry(): ?float`** — the same as a delay from now, clamped
  to `>= 0.0` (an already-due alert yields `0.0`), or `null` if nothing expires.

## Internationalization

User-facing strings are internationalized via `SugarCraft\Toast\Lang::t()`.
All translatable strings live as bare keys in `lang/en.php`; the `toast` lookup
namespace is baked into `src/Lang.php`, not a nesting level inside the arrays.

**Available keys** (`lang/en.php`):

| Key               | Default string                    | Parameters   |
|-------------------|-----------------------------------|--------------|
| `type.info`       | `Info`                            | —            |
| `type.warning`    | `Warning`                         | —            |
| `type.error`      | `Error`                           | —            |
| `type.success`    | `Success`                         | —            |
| `dismiss`         | `Press any key to dismiss`        | —            |
| `count`           | `{count} notification(s)`         | `{count}`    |

> **Note:** The `dismiss` and `count` keys are provided as convenience strings
> for host-rendered dismiss-hint and counter UI. They are **not** emitted or
> rendered by `view()` itself — the library outputs only the alert overlay.

`lang/` ships every LOCALES.md code (17 files: en, fr, de, es, pt, pt-br,
zh-cn, zh-tw, ja, ru, it, ko, pl, nl, tr, cs, ar); keys and `{count}` placeholders are
contract — only values translate. To add one, copy `lang/en.php` to
`lang/<code>.php` and translate the values. The lookup chain follows
`SugarCraft\Core\I18n\T`: exact locale → base language → `en` → raw key.

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
override as `alert()`. The progress value (0.0–1.0) is clamped automatically when finite; `NAN`/`INF` are refused with an `InvalidArgumentException` rather than rendering a lying bar.

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

`Toast` maintains an immutable history of every DISMISSED and every
overflow-EVICTED alert via `HistoryLog`:

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

Recording rules: `dismiss()` files EVERY queued survivor — including alerts
that had expired but not yet been pruned; a `DropOldest` eviction files the
evictee; `DropNewest` refusals (never displayed) and `clear()` (a bulk reset,
not a dismissal) file nothing.

`HistoryLog` is immutable and implements `\Countable`; its constructor accepts only a `list<Alert>` (garbage payloads and string-keyed arrays throw) — `dismiss()` returns a new `Toast` with an
updated log; prior instances are unchanged. The log is capped at 100 entries
by default, oldest-first eviction; `withHistoryLimit(?int)` adjusts it
(`null` = unbounded). A newly LOWERED limit caps FUTURE pushes — entries
already over the new cap survive until the next push evicts them.

## API Summary

| Method | Description |
|--------|-------------|
| `Toast::new(int $maxWidth = 50)` | Factory |
| `->withPosition(Position)` | Screen position (9 positions) |
| `->withDuration(?float $seconds)` | Auto-dismiss after N seconds; `null` disables |
| `->withMaxWidth(int)` | Maximum alert width in cells. Non-positive values are not rejected — the renderer floors the effective cap to 1 cell (same for a `Toast::new(0)` width) |
| `->withMinWidth(int)` | Minimum alert width in cells |
| `->withSymbolSet(SymbolSet)` | NerdFont, Unicode, or Ascii symbols |
| `->withAllowEscToClose(bool)` | Preference flag the host reads to decide if Escape dismisses (the renderer does not handle input) |
| `->withAccessibleText(bool $on = true)` | Opt-in text channel: severity word in the header + numeric percentage on progress rows; default off keeps every frame byte-identical |
| `->withMaxConcurrent(?int $n)` | Cap concurrent alerts (`null` = unlimited; `< 1` throws `InvalidArgumentException`) |
| `->withOverflow(Overflow)` | Strategy when cap exceeded: DropOldest, DropNewest, Enqueue |
| `->withHistoryLimit(?int $n)` | Cap history log, oldest evicted first (default 100; `null` = unbounded) |
| `->alert(ToastType\|string, string, ?float $expiresAt = null, ?array $actions = null, ?string $id = null)` | Add alert (string type = case-insensitive; `null` expiry inherits `withDuration()`; `id` updates a live alert in place; `$expiresAt` below the epoch floor throws — durations belong in `withDuration()`) |
| `->progressToast(ToastType\|string, string, float $progress, ?float $expiresAt = null, ?array $actions = null, ?string $id = null)` | Add alert with progress bar (0.0–1.0, finite — NAN/INF throw); same `id` = same box; wrong-slot `$expiresAt` throws |
| `->push(Alert $alert): self` | Enqueue a pre-built `Alert` (styled/actioned) |
| `->cancelAlert(int $index): self` | Make a queued alert persistent (never auto-dismisses) |
| `->removeAlert(string $id): self` | Retire the queued alert with this id, unrecorded (companion of id updates) |
| `->extendAlert(int $index, float $additionalSeconds): self` | Push one alert's expiry later |
| `->extendAll(float $additionalSeconds): self` | Push every expiring alert's expiry later |
| `->allowEscToClose(): bool` | Read the Escape-dismiss preference flag (host consumes it; the renderer handles no input) |
| `->accessibleText(): bool` | Read the accessible-text preference flag |
| `->error(string $message): self` | Convenience queue for `ToastType::Error` (inherits duration/expiry rules of `alert()`) |
| `->warning(string $message): self` | Convenience queue for `ToastType::Warning` |
| `->info(string $message): self` | Convenience queue for `ToastType::Info` |
| `->success(string $message): self` | Convenience queue for `ToastType::Success` |
| `->hasActiveAlert(): bool` | True if non-expired alerts queued |
| `->nextExpiry(): ?float` | Soonest expiry instant (epoch seconds, wall clock — an NTP step shifts it) of an auto-dismissing alert, or `null` |
| `->secondsUntilNextExpiry(): ?float` | Delay until the next expiry, clamped `>= 0.0`, or `null` — schedule one prune tick |
| `->dismiss() / clear() / pruneExpired()` | Lifecycle: `dismiss()` files EVERY queued alert (expired included) into history and stops rendering until `clear()`, which also resets the flag but files nothing; `pruneExpired()` drops expired alerts silently |
| `->history(): list<Alert>` | Return all recorded alerts — dismissed AND overflow-evicted (`DropOldest`); cumulative, `clear()` never resets it |
| `->activeAlerts(): list<Alert>` | The live queue exactly as `view()` sees it — expired filtered, queue order; the index space `cancelAlert()`/`extendAlert()` and `actionRects()`'s `alertIndex` share |
| `->actionRects(string $background = '', int $viewportWidth = 80, int $viewportHeight = 24): list<array{action:Action,alert:Alert,alertIndex:int,actionIndex:int,row:int,col:int,width:int}>` | Absolute frame cells of every painted `[Label]` button (the `"[+N more]"` fold row is not clickable) |
| `->actionAt(int $col, int $row, string $background = '', int $viewportWidth = 80, int $viewportHeight = 24): ?Action` | The dispatch door: which Action (if any) covers a frame position — first match in queue order |
| `Lang::t(string $key, array $params = []): string` | Translate a known key ('dismiss', 'count', 'type.*') — the inherited sugar-core helper behind `ToastType::label()` |
| `->view(string $background, int $w, int $h): string` | Render toast layer over background; both dimensions must lie in `1..Toast::MAX_VIEWPORT_DIMENSION` (10 000) or an `InvalidArgumentException` is thrown before any allocation — the frame is a real `width × height` cell grid, so bogus winsizes fail loud instead of exhausting memory. Boxes that cannot fit ellipsize (`…`): the icon and the line indent drop out on very narrow frames, and message/action content degrades instead of vanishing. |

| Class | Method | Description |
|-------|--------|-------------|
| `Alert` | `withProgress(float)` | Attach progress bar (0.0–1.0, finite out-of-range clamped, NAN/INF throw) |
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
| `Action` | `->label: non-empty-string` | Button label (readonly; normalised at construction — inline SGR kept, other control/escape constructs stripped, printable-empty refused) |
| `Action` | `->callback: \Closure(): void` | Callback (readonly) |
| `HistoryLog` | `push(Alert, ?int $limit = null): self` | Append alert, evicting oldest past `$limit`; new log |
| `HistoryLog` | `all(): list<Alert>` | Return all entries |
| `HistoryLog` | `count(): int` | Entry count |
| `Position` | `xOffset(int $alertWidth, int $viewportWidth): int` | X offset of a box in a viewport (left edge 0, center floored, right flush) |
| `Position` | `yOffset(int $alertHeight, int $viewportHeight, int $stackedHeightBefore = 0): int` | Y offset; top families stack downward from the top, bottom and middle families stack upward against `$stackedHeightBefore`, clamped to row 0 on short viewports |
| `ToastType` | `label(): string` | Translated severity word (`Lang::t('type.*')`) — surfaced in the frame by `withAccessibleText()` |
| `ToastType` | `icon(SymbolSet $set): string` | Icon glyph for this type under the given symbol set |
| `ToastType` | `nerdIcon(): string` | NerdFont icon symbol for the type |
| `ToastType` | `unicodeIcon(): string` | Unicode symbol (✖ ⚠ ℹ ✔) — fallback when no NerdFont |
| `ToastType` | `asciiPrefix(): string` | Plain ASCII prefix (`[E]` `[W]` `[I]` `[S]`) |
| `ToastType` | `color(): string` | ANSI SGR color code for the type's icon (31/33/34/32) |

| Enum | Cases |
|-------|-------|
| `Position` | TopLeft, TopCenter, TopRight, BottomLeft, BottomCenter, BottomRight, MiddleLeft, MiddleCenter, MiddleRight |
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
  whole; nothing ever returns as printable `]8;;…` garbage. The same walker
  fails closed on an *unterminated* sequence opening too: an OSC/DCS introducer
  (`\e]8;;…` and friends) that never reaches its ST swallows the remainder of
  **that line** — framing never survives as printable garbage, but a host
  re-feeding partial deltas loses the trailing text on the truncated row
  (the trade lives in the `consumeStringSequence()` docblock).

The full fleet-wide contract lives in
[`docs/TRUST-BOUNDARY.md`](https://github.com/detain/sugarcraft/blob/master/docs/TRUST-BOUNDARY.md)
— §1 "the terminal is a sink".

## License

MIT

## Credits & inspiration

Design antecedent: [DaltonSW/bubbleup](https://github.com/DaltonSW/bubbleup); SugarCraft is developed as a native PHP project.
