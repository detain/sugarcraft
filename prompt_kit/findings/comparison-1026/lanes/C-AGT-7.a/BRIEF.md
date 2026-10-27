### C-AGT-7.a — Schedule hygiene: watch tick, bypass ceiling, run limit
- item: C-AGT-7 (sources: AUT-1-P2, AUT-1-P3, AUT-1-P4)
- kind: fix · MINOR/NIT · effort M
- approach:
  - P2: `Chat::subscriptions()` arms the 2 s tick only when a row is enabled or a daemon is active (`src/Chat.php:18118-18128`), so a row hand-written after an empty launch never fires in that TUI. Add a slow 30 s watch tick, armed whenever the registry has a backing path (`ScheduleRegistry::isWatchable()`), that reloads by digest. C-AGT-4.e's mailbox drain reuses it.
  - P3: `Schedule` coerces only unknown modes (:110), so a spelled `bypass-permissions` row runs unattended. Refuse bypass-class rows at load with a named note and skip the row, matching the webhook refusal (`ServerConfig.php:753`).
  - P4: the docblock "no timeout anywhere" (:207) is false, because `spawnSession()` defaults to 3600 s. Correct it, and accept an optional row `timeoutSeconds` (bounded, at least 60) that `ScheduleSpawner` passes through. The default 3600 is documented. This is a session cap, not an LLM request timeout (E646).
- files.create: none
- files.modify: `candy-code/src/Chat.php` (`subscriptions`), `candy-code/src/Background/Schedule.php` (ctor floor, `fromArray`, `toArray`, docblocks), `candy-code/src/Background/ScheduleRegistry.php` (`load` note, `isWatchable`), `candy-code/src/Background/ScheduleSpawner.php` (`timeoutSeconds`), `candy-code/lang/en.php` (`host.schedules.none` text, bypass note), `candy-code/docs/SCHEDULES.md`
- tests: modify `candy-code/tests/Chat/ScheduleLoopFireTest.php` (a file row written after an empty launch fires within one watch tick), `candy-code/tests/Sessions/ScheduleTest.php` (bypass refused with a note; `timeoutSeconds` round-trip and bounds), `candy-code/tests/Sessions/ScheduleRegistryTest.php`
- docs/guards: `docs/SCHEDULES.md`; the `ForeignAgentPresetWiringTest` permissionMode census rows for `Schedule.php`/`ScheduleRegistry.php` already exist (the comment widens if the rationale changes)
- serialized: [chat:subscriptions] [lang: `host.schedules.*`] [docs:SCHEDULES.md]
- deps: C-AGT-1.a, C-AGT-1.b
- phase: normal
- ruling: none. Refusal was chosen over a `schedules.allowBypass` key, for parity with webhooks.
- risks: an always-armed 30 s tick wakes idle TUIs twice a minute. The pump must return `$this` unchanged when nothing moved, or every wake repaints.

