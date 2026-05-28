# Step 06.03 — candy-core Component composition with lifecycle hooks

**Source:** `leftover_updates_later.md` candy-core §3.3
**Branch:** `ai/core-component-lifecycle`

## Deliverable

Add `on_mount` / `on_unmount` lifecycle hooks to a Component interface
that can be composed inside a Model. Today everything is one flat Model.

## Files

**Create:**
- `candy-core/src/Component.php` — interface extending Model with:
  - `onMount(): ?Cmd` — fired when first added to the tree.
  - `onUnmount(): ?Cmd` — fired when removed.
- `candy-core/src/Composite.php` — Model implementation that holds a
  set of children Components and dispatches Msgs to each by id; calls
  `onMount` / `onUnmount` as the child set evolves.

**Modify:**
- `Program` reconciles lifecycle hooks across ticks (same shape as
  Subscriptions in 06.01).

**Tests:**
- `candy-core/tests/ComponentLifecycleTest.php` — add three components
  to a Composite; one is removed mid-session; assert `onUnmount` fired
  exactly once.

## Acceptance

- `cd candy-core && vendor/bin/phpunit --filter "Component|Composite"` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
