---
status: not-started
phase: 1
updated: 2026-06-30
goal: Fix all actionable sugar-toast audit findings: viewport clamping, cancellable timers, null message handling, actions API, and verify findings 9 and 10
---

# Implementation Plan: sugar-toast Audit Fixes

## Goal

Fix all 6 actionable findings from the `sugar-toast` audit (viewport bounds clamping, cancellable/extendable auto-dismiss timers, null message handling, public actions API on `Toast::alert()`), and verify Findings 9 and 10 as already-implemented/wontfix.

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Clamp `maxWidth` to `viewportWidth` in `View()` | Prevents overflow when `maxWidth > viewportWidth`; `Position::xOffset()` then produces negative values causing visual glitches | Investigation: `src/Toast.php:L430-L441` |
| Add `Toast::cancelAlert()` and `Toast::extendAlert()` | Once an alert is queued with `expiresAt`, it cannot be modified; users need to reset or extend timers dynamically | Investigation: `src/Toast.php:L182-L208`, `src/Alert.php:L33-L36` |
| Handle `null` message in `Alert` constructor | Type-hint is `string`, passing `null` coerces to `"null"` string at concatenation/wrap time | Investigation: `src/Alert.php:L19-L25` |
| Add `?list<Action> $actions` param to `Toast::alert()` | `Alert` supports actions but `Toast::alert()` exposes no way to pass them; README documents `->withActions()` which does not exist on Toast | Investigation: `src/Toast.php:L182`, README:L196-L217 |
| Stacked/queued toasts are already implemented | `maxConcurrent` + `Overflow` enum + tests confirm stacking works | Investigation: `tests/ToastMaxConcurrentTest.php`, `tests/GoldenRenderTest.php` |
| Examples directory already exists | `examples/basic.php` and `examples/types.php` present and functional | Investigation: glob results |

## Phase 1: Viewport Bounds Clamping [PENDING]

- [ ] 1.1 Clamp `maxWidth` to `viewportWidth` inside `View()` at `src/Toast.php:L430` — set `$contentWidth = min($this->maxWidth, $viewportWidth)` — **← CURRENT**
- [ ] 1.2 Verify `renderAlertToBuffer()` uses the clamped `$contentWidth` via `$alertBuf->width()` in the `Region` placement (line 444)
- [ ] 1.3 Add test: `Toast::View()` with `maxWidth=80, viewportWidth=40` — Right-positioned alert `xOffset` must not be negative
- [ ] 1.4 Add test: alert wider than viewport renders without overflow past the viewport boundary

## Phase 2: Cancellable / Extendable Auto-Dismiss Timers [PENDING]

- [ ] 2.1 Add `Alert::withCancelledExpiry(): self` — returns new Alert with `expiresAt = null` (persistent) — **← CURRENT**
- [ ] 2.2 Add `Alert::withoutExpiry(): self` — alias for `withCancelledExpiry()`
- [ ] 2.3 Add `Toast::cancelAlert(int $index): self` — sets queue[$index] expiry to `null` via `withCancelledExpiry()` clone
- [ ] 2.4 Add `Toast::extendAlert(int $index, float $additionalSeconds): self` — extends `$expiresAt` by `$additionalSeconds` from now
- [ ] 2.5 Add `Toast::extendAll(float $additionalSeconds): self` — extends all auto-dismissing alerts at once
- [ ] 2.6 Add unit tests in `tests/AlertTimerTest.php` covering cancel, extend, and extendAll

## Phase 3: Null Message Handling [PENDING]

- [ ] 3.1 Change `Alert` constructor `$message` parameter type from `string` to `?string` — **← CURRENT**
- [ ] 3.2 In `renderAlert()` at `src/Toast.php:L470`, guard `$alert->message ?? ''` — render empty string instead of `"null"`
- [ ] 3.3 Add unit test: `new Alert(ToastType::Info, null)` does not produce `"null"` in output

## Phase 4: Action Buttons Public API on Toast::alert() [PENDING]

- [ ] 4.1 Add `?list<Action> $actions = null` parameter to `Toast::alert()` — **← CURRENT**
- [ ] 4.2 Pass `$actions` through to `new Alert($resolvedType, $message, $expiresAt, null, $actions)` in `Toast::alert()`
- [ ] 4.3 Add `?list<Action> $actions = null` to `Toast::progressToast()` for API consistency
- [ ] 4.4 Fix README line 211: remove invalid `->withActions([$action])` chain; replace with named `actions:` parameter usage example
- [ ] 4.5 Add integration test: `Toast::alert(ToastType::Info, 'msg', actions: [$action])` renders `[Label]` in output

## Phase 5: Verify Stacked/Queued Toasts — Finding 9 [PENDING]

- [ ] 5.1 Confirm `testThreeStackedAlertsRendersAnsi` golden test passes — stacking already implemented — **← CURRENT**
- [ ] 5.2 Confirm `testMaxConcurrentAlertsRendersAnsi` exercises `Overflow::DropOldest` path
- [ ] 5.3 Mark Finding 9 as verified-wontfix; `maxConcurrent` + `Overflow` handles queue limiting

## Phase 6: Verify examples/ Directory — Finding 10 [PENDING]

- [ ] 6.1 Confirm `examples/basic.php` runs without fatal error — **← CURRENT**
- [ ] 6.2 Confirm `examples/types.php` runs without fatal error
- [ ] 6.3 Mark Finding 10 as verified-wontfix; `examples/` already exists with 2 files

## Phase 7: Final Verification [PENDING]

- [ ] 7.1 Run `vendor/bin/phpunit` in sugar-toast — all tests must pass — **← CURRENT**
- [ ] 7.2 Run `composer validate` in sugar-toast
- [ ] 7.3 Verify no regression in golden file snapshot tests

## Notes

- 2026-06-30: Investigation complete — `examples/` confirmed present (basic.php, types.php). Stacking confirmed via `ToastMaxConcurrentTest` + `GoldenRenderTest`. `->withActions()` chain in README (line 211) is invalid — `Toast` has no `withActions()` method; the fix is adding `actions:` parameter to `alert()`. Findings 9 and 10 are already resolved and recorded as verified-wontfix for audit trail completeness.
