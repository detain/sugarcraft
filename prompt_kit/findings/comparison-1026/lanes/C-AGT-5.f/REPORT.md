# C-AGT-5.f — Outbound webhooks wiring + `webhooksOut` setting

## Commits (branch cc/C-AGT-5.f, worktree /home/sites/cc-cmp-C-AGT-5.f)
- 07ea10067e595427e08097daaf3169a647924604 — skeleton: settings.webhooksOut.label/.help in candy-code/lang/en.php (cat-file OK, %ae+%ce detain, 0 Co-Authored)

## Status: in flight
- Deps read: C-AGT-5.e 23e4dae80 (WebhookDispatcher/OutboundWebhookConfig/WebhookEvent/WebhookSigner), C-AGT-5.c ff23948e7 (heartbeat schedule kind).
- refresh-deps --mode=linked --libs=candy-code: OK.

## Plan of record
1. ServerSettings.php: `webhooksOut` SettingDefinition (Map, user-tier only, Security, Complex UI), readerSymbol OutboundWebhookConfig::parse.
2. Bootstrap::workspace(): parse the user-tier key, register WebhookDispatcher::class workspace service only when endpoints exist (dark branch registers nothing — C-SES-4.d precedent).
3. TUI emitters: HandlesTurnCompletion::handleTurnCompletionMessages (beside terminalNotification), Chat::requestPermission, Chat::pumpSchedules fired results.
4. Serve emitters: ServerContext host-feed subscription (TURN_COMPLETED / permission asked), BackgroundEvents schedule.fired.
5. docs/SERVER.md outbound section (replace ":344 no outbound webhook" line — line-disjoint from C-SES-7.g /handoff region); docs/SETTINGS.md via gen-settings-doc.
6. tests/Automation/OutboundWebhookWiringTest.php (new file — DUR-carry list below).
7. In-step guards: DocFigureProseDrift settings-count word, ReadmeSettingsTierClaimTest if needed, SettingsSchemaDocDrift via regen.

## DUR-carry (new test files for the gate)
- (pending) candy-code/tests/Automation/OutboundWebhookWiringTest.php

## Gates
- (pending targeted runs)
