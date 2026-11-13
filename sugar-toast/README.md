<img src=".assets/icon.png" alt="sugar-toast" width="160" align="right">

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-toast)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-toast)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/sugar-toast?label=packagist)](https://packagist.org/packages/sugarcraft/sugar-toast)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

# SugarToast

sugar-toast — a floating alert notification component for terminal UIs on PHP 8.3+. Alerts composite over the host's background frame at one of nine anchor positions and never block input.

## Features

- **9 positions**: TopLeft, TopCenter, TopRight, BottomLeft, BottomCenter, BottomRight, MiddleLeft, MiddleCenter, MiddleRight
- **4 alert types**: Error, Warning, Info, Success — severity carried by icon + colour (add `withAccessibleText()` for a plain-text word; box shape and border glyphs are the same across types)
- **Dynamic width**: full `maxWidth` by default; auto-fit to content engages ONLY via `withMinWidth(n>0)` — stacked boxes then anchor at ragged per-message offsets (shrink-to-fit vs stack-alignment trade)
- **Symbol sets**: NerdFont (icons), Unicode (symbols), Ascii (plain text end
  to end). Ascii routes the whole frame through ASCII: icons `[E]` `[W]` `[I]`
  `[S]`, borders `+ - |` and the progress bar `# .`. NerdFont and Unicode keep
  the rounded box-drawing frame (`╭ ─ ╮ │ ╰ ╯`) and block bar (`█ ░`).
- **Auto-dismiss**: time-based expiry, pruned by the host tick
- **Multiple alerts**: queue of toasts rendered in order
- **Progress toasts**: inline progress bar (0–100%) beneath the message body
- **Accessible text mode**: `withAccessibleText()` names the severity in words and prints progress percentages — a text channel beside the frame's colour/glyph language (WCAG 1.4.1) — recommended for any host whose output may be read as plain text (screen readers, copy-paste, log extraction); see Accessibility
- **Action buttons**: `[Label]` buttons attached to an alert with closure callbacks
- **Styled alerts**: per-alert background/foreground/border colour overrides
- **History log**: immutable, bounded record of dismissed, overflow-evicted and refused (DropNewest) alerts (default cap 100)
- **Pure renderer**: outputs ANSI strings; works with any TUI framework
- **Accessibility environment variables**: `REDUCE_MOTION`/`PREFERS_REDUCED_MOTION` drop blink; `NO_COLOR`/`CLICOLOR=0` drop all colour bytes (renderer palette, custom alert colours, inline message SGR); `CLICOLOR_FORCE` overrides
  - The gates are deliberately not spec-literal — a literal `NO_COLOR=0` counts as unset (suppression off), and `CLICOLOR` must read exactly `0` to drop colour (`CLICOLOR=00` keeps it); the divergence law lives in the colour-decision docblock
  - Environment-only checks — sugar-toast composites for its host and never probes a TTY itself

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

Nothing surprising in the defaults:

| Default | Value |
|---|---|
| position | `TopLeft` |
| `maxWidth` | 50 |
| `minWidth` | 0 (auto-fit off) |
| `duration` | `null` — alerts persist until dismissed; persistence is the explicit `Alert::$persistent` sentinel, not a magic `null` expiry |
| `allowEscToClose` | true |
| `maxConcurrent` | `null` (unbounded) |
| overflow | `DropOldest` |
| history limit | 100 |
| symbol set | `Unicode` |

Note the one thing the quickstart does not show: an expiry never fires on its
own. sugar-toast keeps no timers — a timed alert leaves the screen at the next
rendered frame after its instant (rendering draws only live alerts; the queue
itself is pruned on writes and by the public `pruneExpired()` door), so a live
program needs a repaint tick. The [Loop Integration](#loop-integration)
section wires exactly that, and the bundled demos (`examples/basic.php`,
`examples/types.php`, `examples/accessibility.php`) show the whole surface
running.

## Alert Types

```php
ToastType::Error
ToastType::Warning
ToastType::Info
ToastType::Success
```

Each type also provides a localised label via `ToastType::label()` (e.g.
`ToastType::Success->label()` → `"Success"` in English).

`alert()` accepts either a `ToastType` enum case or a case-insensitive string
(`"error"`/`"ERROR"`, `"warning"`, `"info"`, `"success"`). Passing an unknown
string throws `InvalidArgumentException`.

**These four are the whole vocabulary — custom alert types are unsupported.**
Upstream bubbleup registers custom types
(`RegisterNewAlertType(AlertDefinition{Key, ForeColor, Prefix})`); sugar-toast
deliberately dropped that seam, so no key/glyph/prefix can be added at
runtime and an unknown name is a hard throw, not a registration hook. The
parity path for a bespoke look is a styled built-in: choose the severity you
want the icon and label to carry, then override its colours per alert with
`withBackgroundColor()`/`withForegroundColor()`/`withBorderColor()` (see
Styled Alerts). If you are migrating from bubbleup with custom types, map
each `AlertDefinition` onto the nearest built-in plus colour overrides
before you hit the throw.

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
Overflow::DropNewest  // discard the new alert, filing it into history (never silently lost)
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

Per-alert SIZE is bounded too: `Toast::MAX_MESSAGE_CELLS` (fixed at 8192)
truncates an over-long message with a visible `" …[truncated]"` marker, and
`Toast::MAX_ACTIONS_PER_ALERT` (fixed at 64) paints only the first 64 buttons
— the rest fold into a pressable `"[+N more]"` row that is a rect in `actionRects()` (flagged by a non-zero `fold`) and expands through `expandAt()` / `withActionsExpanded()` up to `Toast::MAX_EXPANDED_ACTIONS` (512); past that ceiling it is dim bracketless prose again. The caps bind the RENDER
only: the full message text and the complete action list still ride the
`Alert` into history and `activeAlerts()`, so nothing is silently lost to a
host that reads the data instead of the pixels.

## Stacking & The Frame

The frame is `max(background rows, viewportHeight)` rows tall and a stack
never outgrows it: when the boxes cannot be painted inside that canvas
WITHOUT overlapping, `view()` keeps whole boxes while they fit and folds the
rest into one dim `+N hidden` row at the far end of the stack (locale-aware,
the `hidden` lang key). Which alerts survive follows the overflow policy —
`DropNewest` keeps the oldest, every other policy keeps the newest — and at
least one box always paints (an alert taller than the canvas is the host's
to size). Hidden alerts are not dismissed: they stay in `activeAlerts()` and
return when the stack shrinks, and `actionRects()` `alertIndex` values stay in
`activeAlerts()` space across a fold. A stack that fits paints byte-for-byte
as it always did; the fold row carries no button and never lands in
`actionRects()`. The middle anchors pile UP from the centre, so a middle stack
stops fitting well before its height reaches the canvas (that is where the
old pile-up occlusion lived) and folds earlier than the top/bottom families.
Expiry reflow still MOVES surviving boxes (no stable anchors), so hosts with
accessibility-critical stacks should cap `maxConcurrent` or use `Enqueue`
deliberately — its surplus drains only as timed alerts expire: with
persistent alerts the queue never drains on its own.

Two placement laws ride the same geometry. `view()` bounds the canvas before
allocating it: each dimension must lie in `1..Toast::MAX_VIEWPORT_DIMENSION`
(4 096 cells) and the product must fit `Toast::MAX_VIEWPORT_CELLS`
(1 000 000 cells), or an `InvalidArgumentException` is thrown before any
allocation — the frame is a real `width × height` cell grid, so bogus
winsizes fail loud instead of exhausting memory. Boxes that cannot fit
ellipsize with `…`: on very narrow frames the drop clears the whole styled
prefix — icon and the accessible severity word (`withAccessibleText()`) go
together, with the line indent — and message/action content degrades instead
of vanishing. And `Position::xOffset()` is honest about oversize: its
right/centre arms go **negative** when a box is wider than the viewport;
`view()` clamps with `max(0)`, and hosts calling the accessor directly must
clamp the same way.

## Persistent Alerts

By default, alerts inherit `withDuration()` for auto-dismiss timing. A `null`
`$expiresAt` means "use the default", not "never expire" — persistence is its
own explicit sentinel on `Alert`. To get a persistent alert that never expires
automatically, either keep the toast's duration unset (the default, or
`withDuration(null)`) so queued alerts inherit nothing, or mark the alert
itself persistent — `withCancelledExpiry()` and its deprecated alias
`withoutExpiry()` (kept working until 2.x) set the sentinel. The sentinel reaches the queue through `push()` alone:
`alert()` and `progressToast()` build fresh non-persistent alerts, so on a
toast with `withDuration()` configured they inherit an expiry whatever the
intent — cancel one after the fact with `cancelAlert()`. `push()` skips
duration inheritance for a sentinel-carrying alert, so the escape hatch works
even on a toast that has `withDuration()` configured:

```php
$toast = Toast::new(50)->withPosition(Position::TopRight)->withDuration(5.0);
$toast = $toast->alert(ToastType::Info, 'Connected');       // expires in 5 s — inheritance works
$toast = $toast->push(Alert::new(ToastType::Info, 'Pinned')->withCancelledExpiry()); // escape hatch: survives anyway

// Per-alert escape hatch from an inherited expiry — persistent is explicit,
// so push() will NOT re-arm the toast's duration on this alert:
$alert = Alert::new(ToastType::Info, 'Connected')->withCancelledExpiry();
$toast = $toast->push($alert);
```

Note the trap the recipe sidesteps: its first line keeps the toast's duration
unset. On a toast that has `withDuration()` armed, a plain `alert()` inherits
an expiry the moment it queues — the sticky box quietly self-destructs on the
inherited timer unless you take the sentinel path above or release the
duration with `withDuration(null)`.

**The queueing doors take two expiry knobs.** `$expiresAt` is an *absolute*
epoch instant (`microtime(true)` space): a duration-shaped (sub-epoch) value
is born expired and NAN/INF never expire — both are thrown away rather than
honoured in silence. `$ttlSeconds` is the *relative* form: the door resolves
it against the current clock itself, and the two knobs are mutually exclusive
— passing both throws, naming both parameters. With neither, the door
inherits `withDuration()`; with one, the alert carries exactly what you gave
it (`null` duration + no knob = never expires).

The `Alert` constructor itself accepts a `persistent` flag alongside an
explicit `$expiresAt`: it takes the pair without complaint, but the
sentinel wins — `isExpired()` short-circuits on persistence, so the stored
timestamp stays unread while the flag stands.

A later `withExpiry()`/`withExtendedExpiry()` on a persistent alert clears the
sentinel (asking for a timer is asking for a timer). `Toast::cancelAlert()`
routes through the same sentinel.

Persistent alerts never expire, so `pruneExpired()` cannot remove them — take
them down with `dismiss()` (files EVERY queued alert into the history log —
including ones already expired but not yet pruned — and stops
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
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Msg;

final class RepaintMsg implements Msg {}  // host-defined: sugar-toast ships no
                                          // message class of its own
$delay = $toast->secondsUntilNextExpiry();  // seconds until the soonest expiry, or null
if ($delay !== null) {
    // sugar-core's one-shot timer: Cmd::tick(float $seconds, \Closure $produce)
    // where $produce MUST return the Msg to dispatch into update() — a null
    // (or empty) body dispatches nothing, and the chain dies at the first expiry.
    $cmd = Cmd::tick($delay, static fn (): Msg => new RepaintMsg());
}
```

The tick is one-shot, so the host re-arms from inside `update()` — that is also
where the expired alert actually leaves the screen (the returned model renders
the frame without it):

```php
if ($msg instanceof RepaintMsg) {
    $this->toast = $this->toast->pruneExpired();
    $next = $this->toast->secondsUntilNextExpiry();
    return [
        $this,
        // Reschedule for the new soonest expiry; when nothing is left to
        // expire there is nothing to arm (null = no timer).
        $next === null ? null : Cmd::tick($next, static fn (): Msg => new RepaintMsg()),
    ];
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

Every user-facing string the renderer emits — type labels, the `+N more` action
fold, the `…[truncated]` message marker and the `+N hidden` stack fold — is
internationalized via `SugarCraft\Toast\Lang::t()`; host-supplied message and
label text passes through untouched. All translatable strings live as bare keys
in `lang/en.php`; the `toast` lookup namespace is baked into `src/Lang.php`,
not a nesting level inside the arrays. Border and bar glyphs are not text and
stay locale-neutral. The English bytes are pinned by the cap tests; every other
locale is pinned by the locale-parity guard (`LangCoverageTest`).

**Available keys** (`lang/en.php`):

| Key               | Default string                    | Parameters   |
|-------------------|-----------------------------------|--------------|
| `type.info`       | `Info`                            | —            |
| `type.warning`    | `Warning`                         | —            |
| `type.error`      | `Error`                           | —            |
| `type.success`    | `Success`                         | —            |
| `dismiss`         | `Press any key to dismiss`        | —            |
| `count`           | `Notifications: {count}`          | `{count}`    |
| `more`            | `+{count} more`                   | `{count}`    |
| `truncated`       | `…[truncated]`                    | —            |
| `hidden`          | `+{count} hidden`                 | `{count}`    |

> **Note:** The `dismiss` and `count` keys are provided as convenience strings
> for host-rendered dismiss-hint and counter UI. They are **not** emitted or
> rendered by `view()` itself — the library outputs only the alert overlay.

`lang/` ships every LOCALES.md code (17 files: en, fr, de, es, pt, pt-br,
zh-cn, zh-tw, ja, ru, it, ko, pl, nl, tr, cs, ar); keys and `{count}` placeholders are
contract — only values translate. To add one, copy `lang/en.php` to
`lang/<code>.php` and translate the values. The lookup chain follows
`SugarCraft\Core\I18n\T`: exact locale → base language → `en` → raw key.
That chain makes a new key a mandatory two-part step, with two distinct
failure modes: a key missing from `lang/en.php` renders as its raw key in
every locale, while a key missing from one of the other 16 locale files
renders the English fallback instead of a translation — silently.

**Using the façade:**

```php
use SugarCraft\Toast\Lang;
use SugarCraft\Toast\ToastType;

$label   = ToastType::Error->label();             // 'Error' (i18n-aware)
$prompt  = Lang::t('dismiss');                     // 'Press any key to dismiss'
$counter = Lang::t('count', ['count' => 3]);       // 'Notifications: 3'
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
single alert by identity. `progressToast()` accepts the same `$expiresAt` and
`$ttlSeconds` overrides as `alert()`. The progress value (0.0–1.0) is clamped on attach
(`withProgress()`) and again at render; the `Alert` constructor itself
refuses only non-finite values and stores everything else as given. `NAN`/`INF` are refused with
an `InvalidArgumentException` rather than rendering a lying bar.

## Action Buttons

Attach clickable buttons to an alert. Either build an `Alert` and enqueue it
with `push()`, or pass `actions:` to `alert()` directly:

```php
use SugarCraft\Toast\{Action, Alert, Toast, ToastType};

$action = Action::new('Retry', function (): void {
    // reconnect logic here
});

$alert = Alert::new(ToastType::Error, 'Connection lost')
    ->withActions([$action]);
$toast  = $toast->push($alert);

// Equivalent shorthand:
$toast = $toast->alert(ToastType::Error, 'Connection lost', actions: [$action]);
```

The library renders the `[Label]` buttons; dispatching them is the host's
job — and sugar-toast ships the geometry to do it with. `Action` is a value object
with `readonly string $label` (normalised at construction: only inline SGR
survives, see "Security & Trust Boundary") and `readonly \Closure(): void
$callback`. `actionRects()` returns a hit-test rectangle for every rendered
button — `{action, alert, alertIndex, actionIndex, row, col, width, fold}` in the
same absolute frame coordinates `view()` composites in — and `actionAt()`
answers "which action covers this cell?". sugar-toast stays zone-neutral:
it emits no mouse zones and pulls in no mouse dependency; the host owns
input.

```php
use SugarCraft\Toast\{Action, Alert, Position, Toast, ToastType};

// Host-side context the dispatch call needs: the frame the toast is painted
// over (`view()`'s background), its dimensions, the click in frame coords
// (subtract your pane offset), and the callbacks the buttons run.
$background = str_repeat("background content\n", 20);
$cols = 80;
$rows = 24;
$clickCol = 12;
$clickRow = 3;
$reconnect = fn () => null;  // your reconnect
$giveUp = fn () => null;     // your bail-out path

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
$hotkeys = [];
foreach ($toast->actionRects($background, $cols, $rows) as $n => $rect) {
    $hotkeys['alt+' . ($n + 1)] = $rect['action'];
}
```

**The `[+N more]` fold.** An alert with more than 64 buttons ends in a
`[+N more]` row. It appears in `actionRects()` as one extra rect whose
`fold` is the hidden-button count (`0` on every real button) and whose
`action` is a harmless no-op stand-in — `actionAt()` never returns it, so
existing dispatch loops are unaffected, though a hotkey table built by
enumerating rects should skip `fold > 0` entries. The mouse door is
`$next = $toast->expandAt($col, $row)` (a new `Toast` with that alert
expanded, or `null` when the cell is not a fold row); the keyboard door is
`$toast->withActionsExpanded($alertIndex)` (`activeAlerts()` index space;
pass `false` to collapse). Expansion is host state on the immutable `Toast`,
so the host re-renders with the returned instance.

`actionRects()`/`actionAt()`/`activeAlerts()` all ignore expired alerts, so
the clickable set is exactly what is on screen.

**Hit-test the frame you rendered.** The doors read the last `view()` by
default: omit the frame arguments and `actionRects()`/`actionAt()` answer
about exactly the bytes on screen (the layout memo lives until any state
changes, so it can never go stale). Passing frame arguments is still
allowed — but arguments that DIFFER from the last render throw
`\LogicException` instead of quietly describing a screen nobody is
looking at. `frame()` hands the whole thing over as a value: composed
`rows`, the top box's `x`/`y`, `actionRects()`, `actionAt($col, $row)`,
`boxRows()` — the frame as lines — and `placeAt($col, $row)`, which hands
back just the boxes' bounding rectangle re-anchored at a host-chosen cell,
so parsing rendered ANSI to locate (or inset) the box is no longer required.

## Styled Alerts

Each alert accepts explicit background, foreground, and border colours; an
unset (`null`) override means UNSTYLED — no fill, terminal-default text and
border. It is deliberately NOT the type palette: the type's colour reaches
only the icon.

```php
use SugarCraft\Core\Util\Color;
use SugarCraft\Toast\{Alert, Toast, ToastType};

$alert = Alert::new(ToastType::Success, 'Deployed')
    ->withBackgroundColor(Color::hex('#1a2f23'))
    ->withForegroundColor(Color::hex('#9ece6a'))
    ->withBorderColor(Color::hex('#9ece6a'));

$toast = $toast->push($alert);
```

One consequence to know before relying on theme remaps: the type palette and
the 16-colour SGR codes reach the terminal as packed 24-bit RGB (the renderer
decodes them through sugar-core's canonical table), not as `31`/`33`-style
slot numbers — so a user's 16-colour theme remaps will restyle the rest of the
frame but will **not** restyle toasts.

## Accessibility

The frame speaks in glyphs and colour; sugar-toast hands hosts the knobs that
carry the same meaning without either. The renderer itself handles no input
and owns no screen-reader surface, so the pieces below form the recommended
HOST CONTRACT:

1. **Escape dismisses.** Read `allowEscToClose()` each frame; when the host
   sees Escape and the flag is on, call `dismiss()`. The flag is a
   preference the host consumes — the renderer never acts on it.
2. **Keyboard access to buttons.** `actionRects()` enumerates every painted
   `[Label]` button in absolute frame cells — bind Alt+<n> or a focus ring
   to the enumeration and route activations through `actionAt($col, $row)`
   (see Action Buttons).
3. **A dismiss prompt in words.** `Lang::t('dismiss')` is a convenience
   string for host-rendered UI; `view()` never emits it (see
   Internationalization).
4. **A text channel for severity.** `withAccessibleText()` writes the
   severity word into each header and numeric percentages onto progress
   rows — the only text beside the glyph/colour language, and the knob that
   keeps meaning alive for screen readers, copy-paste and log extraction
   (WCAG 1.4.1). Default off keeps every frame byte-identical.

Environment policy: `REDUCE_MOTION`/`PREFERS_REDUCED_MOTION` drop blink and
`NO_COLOR`/`CLICOLOR=0` drop every colour byte (gates and their deliberate
non-spec-literal edges are spelled out in the Features list above). When the
coloured icon is dropped by suppression, severity retreats to the plain
`[E]`/`[W]`/`[I]`/`[S]` ASCII prefix — the meaning survives the channel loss
(see Styled Alerts); the `Ascii` symbol set keeps icon, border and progress bar
ASCII end-to-end. The other two sets assume UTF-8 output.

Deep stacks fold into a `+N hidden` row rather than occluding each other, but
expiry reflow moves boxes, so accessibility-critical hosts should cap
`maxConcurrent` deliberately (see Stacking & The Frame and Overflow &
Concurrency).

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
evictee; a `DropNewest` refusal files the refused arrival (it was never
displayed, so history is its only trace); `clear()` (a bulk reset,
not a dismissal) files nothing.

`HistoryLog` is immutable and implements `\Countable`; `HistoryLog::new()` and the constructor accept only a `list<Alert>` (garbage payloads and string-keyed arrays throw) — `dismiss()` returns a new `Toast` with an
updated log; prior instances are unchanged. The log is capped at 100 entries
by default, oldest-first eviction; `withHistoryLimit(?int)` adjusts it
(`null` = unbounded). A newly LOWERED limit caps FUTURE pushes — entries
already over the new cap survive until the next push evicts them.

## API Summary

| Method | Description |
|--------|-------------|
| `Toast::new(int $maxWidth = 50)` | The one construction door — queue with a 50-cell width cap |
| `->withPosition(Position)` | Anchor the boxes; all nine positions, see Positions |
| `->withDuration(?float $seconds)` | Default TTL inherited by queueing doors; `null` never expires, non-finite/`<= 0` throw — see Persistent Alerts |
| `->withMaxWidth(int)` | Width cap in cells; `<= 0` throws (the refusal message names the reason) — see Features |
| `->withMinWidth(int)` | Fit-content floor — master switch: `0` (default) FIXED-WIDTH full cap, positive engages auto-fit; negative throws — see Features |
| `->withSymbolSet(SymbolSet)` | Glyph set (NerdFont/Unicode/Ascii) — Ascii also swaps borders for `+ - |` and the bar for `# .` |
| `->withAllowEscToClose(bool)` | Preference flag the host reads to decide Escape dismissal (the renderer handles no input) — see Accessibility |
| `->withAccessibleText(bool $on = true)` | Opt-in text channel: severity word in the header + percentage on progress rows; default off keeps frames byte-identical — see Accessibility |
| `->withHostZoneCarry(bool $on = true)` | Carry the host's U+E000..U+E001 zone markers through the composite; `off` blits opaque chrome — see Security & Trust Boundary |
| `->withMaxConcurrent(?int $n)` | Cap concurrent alerts; `null` = unlimited, `< 1` throws — see Overflow & Concurrency |
| `->withOverflow(Overflow)` | Strategy when the cap is reached — see Overflow & Concurrency |
| `->withHistoryLimit(?int $n)` | History cap, oldest evicted first; default 100, `null` unbounded, `0` no-history, negatives throw — see History Log |
| `->alert(ToastType\|string, string, ?float $expiresAt = null, ?array $actions = null, ?string $id = null, ?float $ttlSeconds = null)` | Queue an alert; string type is case-insensitive — expiry knobs in Persistent Alerts, update-in-place in Progress Toasts |
| `->progressToast(ToastType\|string, string, float $progress, ?float $expiresAt = null, ?array $actions = null, ?string $id = null, ?float $ttlSeconds = null)` | Queue an alert with a progress bar (clamps on attach, NAN/INF throw) — same id = same box, see Progress Toasts |
| `->push(Alert $alert): self` | Enqueue a pre-built `Alert` (styled/actioned) |
| `->cancelAlert(int $index): self` | Make a queued alert persistent (never auto-dismisses) |
| `->removeAlert(string $id): self` | Retire the queued alert with this id, unrecorded (companion of id updates) |
| `->extendAlert(int $index, float $additionalSeconds): self` | Re-arm one queued alert's timer to N seconds from NOW (a reset, not a push-back) |
| `->extendAll(float $additionalSeconds): self` | Re-arm every expiring alert's timer to N seconds from NOW |
| `->allowEscToClose(): bool` | Read the Escape-dismiss preference flag (host consumes it) |
| `->accessibleText(): bool` | Read the accessible-text preference flag |
| `->error(string $message, ?float $expiresAt = null, ?array $actions = null, ?string $id = null, ?float $ttlSeconds = null)` | Convenience door for `ToastType::Error` — full `alert()` tail delegated verbatim |
| `->warning(string $message, ?float $expiresAt = null, ?array $actions = null, ?string $id = null, ?float $ttlSeconds = null)` | Convenience door for `ToastType::Warning` — full `alert()` tail delegated verbatim |
| `->info(string $message, ?float $expiresAt = null, ?array $actions = null, ?string $id = null, ?float $ttlSeconds = null)` | Convenience door for `ToastType::Info` — full `alert()` tail delegated verbatim |
| `->success(string $message, ?float $expiresAt = null, ?array $actions = null, ?string $id = null, ?float $ttlSeconds = null)` | Convenience door for `ToastType::Success` — full `alert()` tail delegated verbatim |
| `->hasActiveAlert(): bool` | True if non-expired alerts queued |
| `->nextExpiry(): ?float` | Soonest expiry instant (epoch seconds, wall clock) of an auto-dismissing alert, or `null` |
| `->secondsUntilNextExpiry(): ?float` | Delay until the next expiry, clamped `>= 0.0`, or `null` — schedule one prune tick |
| `->dismiss() / clear() / pruneExpired()` | Lifecycle: `dismiss()` files every queued alert into history and mutes rendering until `clear()`; `clear()` resets the dismissed state but files nothing into history; `pruneExpired()` drops expired alerts silently — see History Log |
| `->history(): list<Alert>` | Every recorded alert — dismissed, `DropOldest` evictees, `DropNewest` refusals; cumulative, `clear()` never resets it — see History Log |
| `->activeAlerts(): list<Alert>` | The live queue exactly as `view()` sees it — the index space `cancelAlert()`/`extendAlert()`/`actionRects()` share |
| `->actionRects(?string $background = null, ?int $viewportWidth = null, ?int $viewportHeight = null): list<array{action:Action,alert:Alert,alertIndex:int,actionIndex:int,row:int,col:int,width:int,fold:int}>` | Frame cells of every painted `[Label]` button — omitted args read the last render, mismatched args throw; the expandable `[+N more]` fold is a rect flagged `fold > 0` |
| `->frame(?string $background = null, ?int $viewportWidth = null, ?int $viewportHeight = null): ?ToastFrame` | The rendered frame as a value object (`boxRows()`/`actionRects()`/`actionAt()`); `null` when nothing renders |
| `->actionAt(int $col, int $row, ?string $background = null, ?int $viewportWidth = null, ?int $viewportHeight = null): ?Action` | The dispatch door: which Action (if any) covers a frame cell — top-most (newest) box wins where rows overlap |
| `->withActionsExpanded(int $alertIndex, bool $on = true): self` | Expand (or collapse) one alert's `[+N more]` action fold — `activeAlerts()` index; out of range is a no-op |
| `->expandAt(int $col, int $row, ?string $background = null, ?int $viewportWidth = null, ?int $viewportHeight = null): ?Toast` | The mouse door for the fold: the Toast with that alert expanded when the cell is a `[+N more]` row, else `null` |
| `ToastFrame::foldAt(int $col, int $row): ?int` | `activeAlerts()` index whose fold row covers the cell, or `null` |
| `ToastFrame::boxRows(): list<string>` | The composed frame as lines (no trailing-newline artefact) — the full viewport-sized frame; use `placeAt()` for the box rectangle alone |
| `ToastFrame::placeAt(int $col, int $row): ToastFrame` | Re-anchor the painted boxes at a host-chosen cell (inset): a frame whose `boxRows()` are only the boxes' bounding rectangle, background stripped, with `x`/`y`/`bounds` and every hit rect translated to the new origin |
| `Lang::t(string $key, array $params = []): string` | Translate a known key ('dismiss', 'count', 'more', 'truncated', 'hidden', 'type.*') — see Internationalization |
| `->view(string $background, int $viewportWidth = 80, int $viewportHeight = 24): string` | Render the toast layer over the background — frame bounds and ellipsize laws in Stacking & The Frame |

| Class | Method | Description |
|-------|--------|-------------|
| `Alert` | `withProgress(float)` | Attach progress bar (0.0–1.0; out-of-range clamps on attach, NAN/INF throw) |
| `Alert` | `withActions(list<Action>)` | Attach action buttons |
| `Alert` | `isExpired(): bool` | Check expiry |
| `Alert` | `withExpiry(float $duration)` | Set expiry from now |
| `Alert` | `new(ToastType, ?string, ?float, ?float, array, ?Color, ?Color, ?Color, bool $persistent = false, ?string $id = null)` | Factory — constructor mirror (`type`/`message` positional, the rest named); the one seed door, see Persistent Alerts |
| `Alert` | `->type: ToastType` | Severity — drives icon, palette and the accessible label (public readonly) |
| `Alert` | `->message: ?string` | User-visible message text (`null` renders as empty) |
| `Alert` | `->expiresAt: ?float` | Absolute seconds-since-epoch expiry; `null` = INHERIT the toast duration — see Persistent Alerts |
| `Alert` | `->progress: ?float` | Bar 0.0–1.0 or `null`; the constructor stores as given, `withProgress()` clamps, NAN/INF throw |
| `Alert` | `->actions: list<Action>` | Clickable action buttons |
| `Alert` | `->backgroundColor: ?Color` | Box background fill (`null` = UNSTYLED, see Styled Alerts) |
| `Alert` | `->foregroundColor: ?Color` | Message-text colour (`null` = UNSTYLED, see Styled Alerts) |
| `Alert` | `->borderColor: ?Color` | Box-glyph (border) colour (`null` = UNSTYLED, see Styled Alerts) |
| `Alert` | `->persistent: bool` | Explicit never-expire sentinel — queueing doors skip duration inheritance while it stands, see Persistent Alerts |
| `Alert` | `->id: ?string` | Stable identity for update-in-place — see Progress Toasts |
| `Alert` | `withoutExpiry()` | DEPRECATED forwarder to `withCancelledExpiry()` (kept working until 2.x) |
| `Alert` | `withCancelledExpiry()` | Canonical persistence door: set the sentinel + clear the expiry (used by `Toast::cancelAlert()`) |
| `Alert` | `withExtendedExpiry(float)` | Reset the expiry to N seconds from NOW |
| `Alert` | `withBackgroundColor(?Color)` | Override box background (`null` = no fill, plain render) |
| `Alert` | `withForegroundColor(?Color)` | Override text colour (`null` = terminal default, not the type colour) |
| `Alert` | `withBorderColor(?Color)` | Override border colour (`null` = terminal default, not the type colour) |
| `Action` | `new(string $label, \Closure(): void $callback)` | Factory |
| `Action` | `->label: non-empty-string` | Button label (non-empty string; readonly) — inline SGR kept, other control/escape constructs stripped, printable-empty refused |
| `Action` | `->callback: \Closure(): void` | Callback (readonly) |
| `HistoryLog` | `new(array $entries = []): self` | Factory mirroring the sibling `::new()`s; seeds from a `list<Alert>`, garbage throws |
| `HistoryLog` | `push(Alert, ?int $limit = null): self` | Append alert, evicting oldest past `$limit`; new log |
| `HistoryLog` | `all(): list<Alert>` | Return all entries |
| `HistoryLog` | `count(): int` | Entry count |
| `Position` | `xOffset(int $alertWidth, int $viewportWidth): int` | X offset of a box in a viewport (left 0, centre floored, right flush) — **negative** when the box outgrows the viewport, clamp like `view()` — see Stacking & The Frame |
| `Position` | `xOffsetClamped(int $alertWidth, int $viewportWidth): int` | `xOffset()` floored at column 0 — the exact column `view()` paints at; use it instead of hand-clamping |
| `Position` | `yOffset(int $alertHeight, int $viewportHeight, int $stackedHeightBefore = 0): int` | Y offset; top families stack downward, bottom/middle upward against `$stackedHeightBefore`, clamped to row 0 |
| `ToastType` | `label(): string` | Translated severity word (`Lang::t('type.*')`) — surfaced by `withAccessibleText()` |
| `ToastType` | `icon(SymbolSet $set): string` | Icon glyph for this type under the given symbol set |
| `ToastType` | `nerdIcon(): string` | NerdFont icon symbol for the type |
| `ToastType` | `unicodeIcon(): string` | Unicode symbol (✖ ⚠ ℹ ✔) — fallback when no NerdFont |
| `ToastType` | `asciiPrefix(): string` | Plain ASCII prefix (`[E]` `[W]` `[I]` `[S]`) |
| `ToastType` | `color(): string` | ANSI SGR colour code for the type's icon (31/33/34/32 — emitted as packed RGB, see Styled Alerts) |

| Enum | Cases |
|-------|-------|
| `Position` | TopLeft, TopCenter, TopRight, BottomLeft, BottomCenter, BottomRight, MiddleLeft, MiddleCenter, MiddleRight |
| `ToastType` | Error, Warning, Info, Success |
| `Overflow` | DropOldest, DropNewest, Enqueue |
| `SymbolSet` | NerdFont, Unicode, Ascii |

## Shared Foundations

- **[sugar-core](https://github.com/sugarcraft/sugar-core)** — the runtime
  foundation the API tables already lean on: cell width/font metrics, colour
  conversion (`Color::hex`), the `Sanitize` choke point, and the `I18n` base
  behind `Lang`.

- **[sugar-cellbuffer](https://github.com/sugarcraft/sugar-cellbuffer)** — alert queue
  composited via `Buffer`; each toast is a sub-`Buffer` composited into the
  screen `Buffer` for per-cell positioning and overlap resolution.

- **[sugar-testing](https://github.com/sugarcraft/sugar-testing)** (require-dev —
  test infrastructure) — golden-file snapshot tests via `assertGoldenAnsi()`
  pin canonical queue layouts.

## Security & Trust Boundary

Toast content is app-supplied — and in a TUI the terminal is a sink. `view()`
renders alert text through the sugar-cellbuffer grid and re-encodes it, so the
output stream contains only the SGR sequences the renderer itself emits:

One exception to "re-encodes it": with an empty or dismissed queue `view()`
returns the `$background` string **unchanged** — no grid round-trip happens at
all, so that frame carries exactly the bytes the host passed in, whatever they
are.

- **Control bytes can't escape**: raw ESC payloads, OSC hyperlinks (`\e]8;;…\e\\`)
  and BEL in a message are never forwarded — the output carries no OSC, no BEL,
  and no escape sequence the library did not build from a parsed style **from
  message or label content** (a host OSC-8 run living in the background frame is
  a different channel and IS re-emitted intact — see the hyperlink bullet below). A
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
  applies to attacker-authored *toast text*, never to the host's own markers. A host that would rather have unbroken chrome than carried
  markers can opt out with `withHostZoneCarry(false)`: the box then blits
  opaquely and any zone span running under it loses its covered half.
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

## Credits & Inspiration

Design antecedent: [DaltonSW/bubbleup](https://github.com/DaltonSW/bubbleup); SugarCraft is developed as a native PHP project.
