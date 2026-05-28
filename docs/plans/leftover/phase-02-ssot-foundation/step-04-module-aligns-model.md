# Step 02.04 — Align sugar-dash Module\Module contract with candy-core Model

**Source:** `leftover_updates_later.md` SSOT-09 + Dash-04
**Branch:** `ai/dash-module-aligns-model`
**Bundle hint:** standalone but precondition for step 03.07 (dashboard-live)

## Deliverable

`sugar-dash/src/Module/Module.php` today uses `array<string,mixed>` for
state. `\SugarCraft\Core\Model` is the established Elm-style contract
in candy-core (`init(): ?Closure`, `update(Msg): [Model, ?Cmd]`,
`view(): string`). Two incompatible contracts in one monorepo.

Redefine `Module::update()` to return `array{0:Module, 1:?Cmd}` aligned
with Core's `Model`. Keep the old array-state contract alive as
`LegacyModule` for one release so existing modules don't break.

## Files

**Modify:**
- `sugar-dash/src/Module/Module.php` — interface signatures change:
  - `update(Msg $msg): array` returning `[Module, ?Cmd]` (was
    `array $state, mixed $msg`).
  - Inherit `init(): ?Closure` and `view(): string` from candy-core's
    Model.
  - Add `name(): string` and `minSize(): array{0:int, 1:int}` since
    those are module-specific.
- `sugar-dash/src/Module/BaseModule.php` — abstract helper providing
  default `init() = null`, `minSize() = [30, 4]`.

**Create:**
- `sugar-dash/src/Module/LegacyModule.php` — old-style contract.
  Marked `@deprecated since v0.x, use Module`.
- `sugar-dash/src/Module/LegacyModuleAdapter.php` — wraps a LegacyModule
  so the registry can host both.

**Modify (built-in modules in `sugar-dash/src/Modules/`):**
- `Clock/ClockModule.php`, `System/SystemModule.php`,
  `Greeting/GreetingModule.php`, `Uptime/UptimeModule.php`,
  `Generic/GenericModule.php` — rewrite each to return
  `[Module, ?Cmd]` from `update()`. Use `withState(...)` withers per
  CLAUDE.md immutability rule.

**Modify:**
- `sugar-dash/src/Registry/Registry.php` — accept both `Module` and
  `LegacyModule` (auto-wrap legacy via the adapter).

## Tests

- `sugar-dash/tests/Module/ModuleSpecConformanceTest.php` — fixture
  asserts every built-in module conforms to the new contract.
- `sugar-dash/tests/Module/LegacyModuleAdapterTest.php` — old-style
  module wrapped correctly.
- `sugar-dash/tests/Modules/<Each>ModuleTest.php` updated to the new
  signature.

## Acceptance

- `cd sugar-dash && vendor/bin/phpunit --filter Module` green.
- `Module` interface has the same shape as `\SugarCraft\Core\Model`
  plus the module-specific `name()` and `minSize()` additions.
- All 5 built-in modules conform.
- `LegacyModule` still works for backwards-compat tests.

## Notes

- `Cmd` is candy-core's command type — modules return `Cmd::tick(...)`
  for periodic refresh, `Cmd::http(...)` for fetches, etc.
- The headline `dashboard-live.php` example in step 03.07 depends on
  this — modules MUST behave as candy-core Models so Program can run
  them.
- Don't break the `Registry` API; existing callers should keep working
  via the adapter.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
