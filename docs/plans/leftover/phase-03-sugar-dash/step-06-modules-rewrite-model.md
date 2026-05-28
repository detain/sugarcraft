# Step 03.06 — Rewrite sugar-dash built-in modules to candy-core Model contract

**Source:** `leftover_updates_later.md` Dash-04 (depends on step 02.04)
**Branch:** `ai/dash-modules-model`

## Deliverable

Built-in modules in `sugar-dash/src/Modules/{Clock,System,Greeting,Uptime,Generic}/`
were rewritten to the new `Module` interface in step 02.04. This step
verifies they're idiomatic candy-core Model implementations — immutable
state, `[Module, ?Cmd]` returns, proper Msg handling, Cmd dispatch for
periodic refresh.

## Files

**Modify (`sugar-dash/src/Modules/<Name>/<Name>Module.php`):**
- `ClockModule` — uses `Cmd::tick(1000)` for 1Hz refresh; immutable
  `withTime(\DateTimeImmutable)` updates.
- `SystemModule` — periodic `Cmd::async(fn() => readProcStat())` for
  /proc/stat sampling; multiple sub-states (CPU, memory, disks).
- `GreetingModule` — static, no `update()` work.
- `UptimeModule` — read `/proc/uptime`, periodic tick.
- `GenericModule` — runs an arbitrary command on `interval`,
  displays stdout.

**Replace** any `proc_open(...)` invocations in modules with
`\SugarCraft\Pty\Posix\PosixProcess`.

## Tests

- `sugar-dash/tests/Modules/<Each>ModuleTest.php` — scripted-input
  behaviour test:
  - Feed a sequence of Msgs.
  - Assert `[Module, ?Cmd]` tuple shape.
  - Snapshot `view()` output.

## Acceptance

- `cd sugar-dash && vendor/bin/phpunit --filter Module` green (all 5
  built-ins + adapter + spec conformance).
- Every module's `update()` returns `[$this, $cmd]` shape, never
  mutates `$this`.
- `dashboard-live.php` (step 03.07) can boot any of them.

## Notes

- The shape MUST match `\SugarCraft\Core\Model` exactly for `Program`
  to drive them in step 03.07.
- Use the `Cmd::` factory family from candy-core for side effects.
  Never side-effect inside `view()` or `update()` directly.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
