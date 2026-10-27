### C-AGT-1.a — Bind persisted schedules to a project root
- item: C-AGT-1 (sources: AUT-1-P1, p1-miss: schedules UX)
- kind: fix · MAJOR · effort M
- approach: Root cause: a row is `name/every/prompt/provider/permissionMode/nextDue/enabled` (`src/Background/Schedule.php:136-195`), and `ScheduleSpawner::closure` spawns at the HOST's root (`src/Background/ScheduleSpawner.php:58`). One home-global file is evaluated by every TUI and serve, and the first lock wins (`ScheduleRegistry::fireDue` :391ff), so a row runs in whichever repo's host claims it. Fix:
  - add a realpath'd `root` field to `Schedule` (`fromArray`/`toArray`; required for persisted rows, ignored for `/loop`);
  - give the registry its host root: `ScheduleRegistry::loadDefault(?string $hostRoot)` / `atPath($path, $hostRoot)`. Bootstrap's single construction site passes `$root`, so Chat and BackgroundEvents need no edit;
  - `fireDue()` skips a row whose root ≠ host root BEFORE claiming its lock, without advancing it (new result action `skipped-foreign`). A registry with no host root fires no persisted row;
  - a rootless row loads as a `loadErrors()` note ("not bound to a project; add `\"root\"` — will not fire").
  - `/schedules` shows each row's root (or "unbound").
  - Write `docs/SCHEDULES.md`, the first page that documents the `schedules.json` format, root binding and the permission floor.
- files.create: `candy-code/docs/SCHEDULES.md`
- files.modify: `candy-code/src/Background/Schedule.php` (ctor, `fromArray`, `toArray`), `candy-code/src/Background/ScheduleRegistry.php` (ctor, `loadDefault`, `atPath`, `fireDue`, `load`), `candy-code/src/Background/ScheduleSpawner.php` (spawn at `$row->root` when set; docblock), `candy-code/src/Host/Commands/SchedulesCommand.php` (`listing`), `candy-code/src/Cli/Bootstrap.php` (`workspace()`, the `ScheduleRegistry::loadDefault()` service line ~1910), `candy-code/lang/en.php` (`host.schedules.none`, `host.schedules.row`, new `host.schedules.unbound*`), `candy-code/docs/README.md` (index row for SCHEDULES.md)
- tests: modify `candy-code/tests/Sessions/ScheduleTest.php` (root parse/realpath/round-trip), `candy-code/tests/Sessions/ScheduleRegistryTest.php` (a rootless row is a load note and never fires; a foreign-root row is skipped and not advanced), `candy-code/tests/Sessions/ScheduleCrossHostFireTest.php` (host /a fires a /a row, host /b never does), `candy-code/tests/Host/BackgroundEventsSchedulesTest.php` and `candy-code/tests/Chat/ScheduleLoopFireTest.php` (fixtures gain `root`), `candy-code/tests/Host/Commands/LoopSchedulesCommandTest.php` (listing shows root/unbound)
- docs/guards: new `docs/SCHEDULES.md` plus its `docs/README.md` index row. `ReadPathCensusTest` / `ProjectTierRefusalInventoryTest` rows for `ScheduleRegistry` are unchanged (same file, same read). The `ForeignAgentPresetWiringTest` permissionMode census is unchanged (no new reader).
- serialized: [bootstrap:workspace] [lang: `host.schedules.*`] [docs:SCHEDULES.md] [docs:README.md (docs/ index)]
- deps: none
- phase: normal
- ruling: none
- risks: existing hand-written rows stop firing until they gain a `root`. The load note and `/schedules` say so loudly; this is the prescribed fix.

