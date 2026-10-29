# C-AGT-5.f FIX-1 (round-2 repair, fixer session 2026-10-29)

Tree: /home/sites/cc-cmp-C-AGT-5.f, branch cc/C-AGT-5.f. Prior sessions' dirty work was JUDGED, not discarded: the two on-disk repairs were exactly the CRIT/MAJOR cures; the MINOR cure was NOT on disk (r2 correctly recorded it not-taken) and is now shipped. Note the orchestrator preamble's claims ("BackgroundEvents::webhooksOut accessor", "Chat.php:10618 $onEvent threaded to both pumps") are stale narrative — the live shape is the reviewer-adjudicated one: dialer mounted as a `WebhookDispatcher::class` workspace service (Bootstrap::workspace()) consumed by Chat::dispatchOutboundWebhook + ServerContext::announceOutbound, passed positionally into BackgroundEvents::new. No re-work needed; REPORT.md never claimed otherwise beyond the plan-of-record.

## Commits (cat-file'd, %ae+%ce detain separate-greps =1/1, zero Co-Authored)
- cdbd0e488f559cb1f30e7b108e51259e51fa94b5 — CRIT+MAJOR: commit the `->name()`→`->name` fatal cure (src/Chat.php:2966) + track tests/Automation/OutboundWebhookWiringTest.php (was 433L/10T untracked).
- 7d5eeda7373cdbb52fd17b35c0f9e105ac89cddd — MINOR: duration pins now discriminate (file now 11T/59A).

## Finding → cure
1. CRITICAL (Chat.php:2966 `SugarCraft\Code\ToolCall::name()` fatal on armed TUI prompt): on disk as the drop-parens hunk; COMMITTED in cdbd0e488. Mutation M1 (re-apply parens) → exactly testAnArmedApprovalEnqueuesOneSignedPermissionAskedPost ERRORs with the production fatal (1E/11).
2. MAJOR (lane tests untracked; committed branch had zero tests): file committed in cdbd0e488. Mutation M4 (neuter dispatchOutboundWebhook service lookup) → settle + armed dial legs fail, dark/queued/subscribed tests green (correct polarity; serve legs ride the separate ServerContext seam, pinned by their own tests).
3. MINOR (:173-174/:301-302 assertIsFloat+>=0.0; completedAt→0.0 mutation reddened nothing): CURED (never on disk before). TUI leg: spaced `?int $now` stamps (user row t−3, settle row t) → `assertSame(3.0, duration_seconds)` — M2 (constant 0.0 in HandlesTurnCompletion) now reddens exactly that test. Served leg: real-turn shape assertions kept (same-ms window is honestly non-deterministic), plus NEW 11th method testTheServedWindowArithmeticIsNotAConstant driving `$host->announce()` (public driver seam) with explicit tsMs: TURN_STARTED, 2×TOOL_STARTED, TURN_COMPLETED +2500ms → assertSame(2.5, duration) + assertSame(2, tool_calls) — M3 (constant 0.0 in ServerContext window math) reddens exactly it. No new timers/waits; E646-clean; PtyRunToolTest untouched.

## Gates (cwd=candy-code, env -u LINES -u COLUMNS plain pipe, -c phpunit.xml)
- --filter 'OutboundWebhook|Webhook|BackgroundEvents|Automation|PermissionPrompt': Tests 229, Assertions 3870, OK (2 PHPUnit deprecations = foreign AccessorDeprecationTest fingerprint, base-true per r2). Was 228/3864 → +1T/+6A is the new window method.
- OutboundWebhookWiringTest alone: 11T/59A green (all four mutations restored md5-verified: Chat.php 391cc2b7, HandlesTurnCompletion.php 7c1d7204, ServerContext.php 5730cacc; backups /tmp/opencode/agt5f-mut/).
- r1 victim pins --filter 'PermissionFsmExtractionParityTest|PermissionRequest': 17T/144A green.
- Guard sweep --filter 'GlobDialectDifferential|DuplicatedTestHelperDrift|ReadmeSuiteFigureDrift|DocFigureProseDrift': 123T/4713A, ONLY the 2 ReadmeSuiteFigureDrift staleness-pair reds (live enum moved +11T by the now-tracked test file — the EXPECTED shape; no glob-shaped literals, no helper drift).
- Working tree clean (0 dirty) at close.

## Welder carry (DUR + §7)
- tests/Automation/OutboundWebhookWiringTest.php is a NEW tracked test FILE: durations.tsv row owed by welder (lane-forbidden file), and suite-figure.json/README pair re-pin now +11T (NOT +10T as r1/r2 projected — the MINOR cure added one method) and ≥ ~59A on the live-enum side.
- No protocol-trio/docs touches in either fix commit (diff = src/Chat.php 1 line + the test file only); lang/SETTINGS/SERVER prose and the settings-count DocFigure arm rode the already-reviewed tip commits unchanged.
