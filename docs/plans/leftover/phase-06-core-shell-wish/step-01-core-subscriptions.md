# Step 06.01 — candy-core Subscriptions

**Source:** `leftover_updates_later.md` candy-core §3.1
**Branch:** `ai/core-subscriptions`

## Deliverable

Elm-style **Subscriptions** with reconciliation. Today `Program` keeps
re-issuing `TickRequest`; there's no first-class Subscription type to
declare "this Model wants a tick every 1s". Add a `Subscription` /
`Subscriptions` pair so models can declare intent.

## Files

**Create:**
- `candy-core/src/Subscription.php` — value object: kind (Tick / Key /
  Signal / Custom), parameters, message constructor.
- `candy-core/src/Subscriptions.php` — collection wrapper with
  `withTick(...)`, `withKey(...)`, `withSignal(...)`, fluent.
- `candy-core/src/Cmd/SubscribeCmd.php` — Cmd subclass that updates
  the Program's subscription set.

**Modify:**
- `candy-core/src/Program.php` — accepts an optional
  `subscriptions(Model): Subscriptions` callback; reconciles between
  ticks (adds new subs, removes dropped ones, leaves stable subs alone).
- `candy-core/src/Model.php` — optional `subscriptions(): ?Subscriptions`
  default returns null.

**Tests:**
- `candy-core/tests/SubscriptionsReconcileTest.php` — fixture model
  whose subscription set changes per Msg; assert Program tracks the
  set correctly.

## Acceptance

- Mirrors Elm subscription reconciliation semantics.
- `cd candy-core && vendor/bin/phpunit --filter Subscription` green.
- `Program` does not double-fire ticks after reconciliation.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
