### C-AGT-5.f — Outbound webhooks wiring + `webhooksOut` setting
- item: C-AGT-5 (sources: p1-miss: outbound webhooks, p2-miss: outbound webhooks)
- kind: gap · effort M
- approach:
  - Register a `webhooksOut` setting, user tier only, so a project can never add egress, through `SettingDefinition` in `ServerSettings.php`, read at restart.
  - `Bootstrap::workspace()` registers one `WebhookDispatcher` service.
  - TUI emitters: the turn settle in `HandlesTurnCompletion::handleTurnCompletionMessages()` (beside `terminalNotification`, :343), `Chat::requestPermission()` (:2761), and `pumpSchedules()` fired results.
  - Serve emitters: `ServerContext` subscribes each host's `SessionEvent` stream (`TURN_COMPLETED`, permission asked) when it builds the host's feed, and `BackgroundEvents` reports `schedule.fired`.
  - Replace "there is no outbound webhook" in `docs/SERVER.md` (:308-310).
- files.create: none
- files.modify: `candy-code/src/Config/Settings/Definitions/ServerSettings.php`, `candy-code/src/Cli/Bootstrap.php` (`workspace()` service), `candy-code/src/Chat.php` (`requestPermission`, `pumpSchedules`), `candy-code/src/Chat/Handlers/HandlesTurnCompletion.php` (`handleTurnCompletionMessages`), `candy-code/src/Protocol/ServerContext.php` (host feed subscription), `candy-code/src/Host/BackgroundEvents.php` (`pumpSchedules` emit), `candy-code/lang/en.php` (`settings.webhooksOut.*`), `candy-code/docs/SETTINGS.md` (regenerated), `candy-code/docs/SERVER.md` (outbound section)
- tests: create `candy-code/tests/Automation/OutboundWebhookWiringTest.php` (a TUI settle and a served `TURN_COMPLETED` each enqueue one signed POST; a project-tier `webhooksOut` is ignored)
- docs/guards: `php tools/gen-settings-doc.php` + `candy-code/tests/Config/Settings/SettingsSchemaDocDriftTest.php`; `candy-code/tests/Config/DocFigureProseDriftTest.php` (the settings count word "one hundred and twenty-nine" moves); `candy-code/tests/Config/ReadmeSettingsTierClaimTest.php` if the README names tiers; `docs/SERVER.md`
- serialized: [bootstrap:workspace] [chat:requestPermission, pumpSchedules] [hot:candy-code/src/Chat/Handlers/HandlesTurnCompletion.php] [hot:candy-code/src/Protocol/ServerContext.php] [hot:candy-code/src/Host/BackgroundEvents.php] [lang: `settings.webhooksOut.*`] [docs:SETTINGS.md] [docs:SERVER.md]
- deps: C-AGT-5.e, C-AGT-5.c
- phase: normal
- ruling: none
- risks: the settings-count prose is shared with every other area adding a setting. `docs/SETTINGS.md` needs one owner per wave.

