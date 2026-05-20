# SugarToast — Caliber Learnings

Accumulated patterns and gotchas discovered while building and auditing
sugar-toast.

---

## [pattern:sugar-toast-i18n] Per-library i18n facade

When adding i18n to a leaf library that has no internal deps other than
`candy-core`, follow the canonical pattern established by sugar-wishlist,
sugar-calendar, and sugar-table:

1. **`lang/en.php`** — flat `array<string, string>` keyed by translation
   key (e.g. `'type.info'`, `'dismiss'`). Return type hint via
   `@return array<string, string>` docblock. `declare(strict_types=1)` at
   top.

2. **`src/Lang.php`** — `final class Lang` wrapping
   `SugarCraft\Core\I18n\T`. Bakes the library namespace (`'toast'`) into
   every key. Mirrors the sugar-wishlist/calendar/table facade pattern.
   Registers the namespace + lang dir on every call (safe; `T::register`
   is idempotent).

3. **Translation keys** — use dot notation (`type.info`), not camelCase.
   Avoid generic keys like `'label'` — prefix with context (`type.label`).

4. **`ToastType::label()`** — the canonical i18n accessor for toast type
   labels. Returns `Lang::t('type.' . $this->value)`. Call sites use this
   instead of hardcoding `'Info'` / `'Warning'` etc.

5. **`LangCoverageTest`** — scans `src/` for `Lang::t()` call patterns
   and verifies every key exists in `lang/en.php`. Prevents silent missing
   translations. Include one test per key + a wildcard-pattern test for
   dynamic keys.

6. **`candy-core` dep** — the `T` class lives in `candy-core`, so every
   i18n lib needs `sugarcraft/candy-core: dev-master` in `require` plus a
   path-repo entry in `repositories`.

---

## [pattern:middle-position-stacking] MiddleLeft/MiddleCenter/MiddleRight center vertically and stack toward bottom edge

Middle positions use the same `totalAlertLines` accumulation logic as bottom
positions. Each alert is offset by the cumulative height of all previously
rendered alerts at the same position, so stacks grow downward from the
vertical center rather than upward from the top.

---

## [pattern:toast-stack-y-offset-fix] Two-pass rendering for y-offset computation

Render computes `totalAlertLines` in a first pass across all alerts, then
uses a running `yOffset` accumulator in the second pass to assign each
alert its cumulative vertical position. This prevents overlapping stacks
when multiple toasts share the same position.

---

## [gotcha:toast-type-symboldata] SymbolSet data lives on the enum

`ToastType` provides `nerdIcon()`, `unicodeIcon()`, `asciiPrefix()`, and
`color()` directly on the enum case. The `icon(SymbolSet $set)` method
dispatches to the appropriate method. No separate data class is needed.

---

## [pattern:toast-persistent-null-expiry] Null expiry = never expires

`Alert::$expiresAt` stores `?float` (Unix timestamp or null). When `null`,
`Alert::isExpired()` always returns `false`, creating a persistent alert.
The `Toast::alert()` method applies `withDuration()` only when `$expiresAt`
is `null` AND `$this->duration !== null` — meaning an alert without a
per-call expiry only auto-expires if a default duration was set on the Toast
instance. To create a truly persistent alert, pass `null` as `$expiresAt` AND
ensure no default duration is configured (or configure `withDuration(null)`).

---

## [pattern:toast-overflow-strategy] Overflow enum controls queue bound behaviour

When `withMaxConcurrent(int)` caps the queue, `withOverflow(Overflow)` picks
the strategy when a new alert would exceed the cap:
- `DropOldest` — `array_shift()` discards the oldest alert before appending
- `DropNewest` — returns the clone without appending the new alert
- `Enqueue` — appends anyway, allowing temporary overruns

`DropOldest` is the default so the queue is always bounded by the most recent
$N` alerts.

---

## [pattern:toast-string-type-lookup] String-to-enum type coercion in alert()

`Toast::alert(ToastType|string $type, ...)` accepts either a `ToastType`
enum case or a lowercase string. `ToastType::tryFrom(strtolower($type))` is
used for resolution; an unknown string throws `InvalidArgumentException`.
This allows consumer code to pass dynamically-composed type names without
matching the enum exactly. Case-insensitivity is intentional — user input is
unpredictable and the failure mode (exception) is clearer than silent
no-op.

---

## [pattern:action-value-object] Action with readonly label + Closure callback

`Action` is a minimal value object holding a user-visible label and a zero-arg
callback. The class is `final` with two `readonly` constructor parameters and
no internal state. Construction is via the named factory `Action::make()` for
fluent call-chains, but direct construction is also valid since the ctor is
public. Callbacks are stored as `\Closure(): void` — no parameters, no return
value, keeps the interface small and impossible to misuse.

```php
$action = Action::make('Retry', function (): void {
    // reconnect logic
});
// Trigger:
$action->callback();
```

---

## [pattern:toast-history-log] HistoryLog records dismissed toasts; immutable log with push-returning-new-instance

`HistoryLog` is an immutable collection of `Alert` entries representing every
toast dismissed via `Toast::dismiss()`. It is constructed with a private
`list<Alert> $entries` and exposes `push(Alert): self` (returns a new log
with the alert appended), `all(): list<Alert>`, and `count(): int`. The
immutable pattern means `Toast::$historyLog` is replaced with a new log
instance on every `dismiss()` call — prior instances remain valid for inspection
or undo stacks. The `Toast` instance itself is also immutable (clone+mutate),
so `getHistory()` on a given `Toast` always reflects only the dismissals that
occurred on that instance's lineage.
