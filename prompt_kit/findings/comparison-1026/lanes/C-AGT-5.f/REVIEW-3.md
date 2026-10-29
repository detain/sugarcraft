# C-AGT-5.f REVIEW-3 (independent, round 3) — tip 7d5eeda73

**VERDICT: APPROVE — 0 CRITICAL / 0 MAJOR / 0 step-local MINOR.** All r2 findings genuinely closed on disk; full step contract re-verified against current code.

Chain (all cat-file-verified): 6d0c79851 (merge-base) → 07ea10067 (skeleton) → 4c1dbe12d (wiring src+docs) → cdbd0e488 (CRIT+MAJOR cures) → 7d5eeda73 (MINOR duration pins). Brief-listed 56e724559 does not exist (wake-text drift, not a lane finding); d83e4eed4 is a weld commit, not a step parent. All 4 commits AE=CE=detain@interserver.net, zero trailers. Diff master...HEAD = exactly the 10 brief-declared files; no weld-only files (suite-figure.json / durations.tsv / dist/) touched.

## r2 findings — closed
- **CRITICAL (committed-name fatal)** CLOSED: Chat.php:2966 reads `$msg->toolCall->name` (property; ToolCall.php:39 `public readonly string $name`, no name(), no __call). Fix direction correct. Pinned behaviorally: test :211-255 drives the REAL production path (HookManager PreToolUse→ask → Chat::update → requestPermission → dispatchOutboundWebhook via workspace service) and assertSame('Bash', payload.tool) — the old committed form fatals there. Superseded-ask guard: dispatch sits after the armAsk null check; test :184/:196 pin no-dial on carry-on and dark launch.
- **MAJOR (untracked DoD test)** CLOSED: tests/Automation/OutboundWebhookWiringTest.php tracked at HEAD (cat-file -e; absent at 4c1dbe12d). 11T/59A at tip.
- **MINOR (non-discriminating duration pins)** CLOSED: settle test uses spaced stamps (time()-3 / +3) → assertSame(3.0, duration_seconds) :181; NEW testTheServedWindowArithmeticIsNotAConstant :312-352 announces explicit ms stamps (TURN_STARTED, 2×TOOL_STARTED, +2500ms completion) through the real feed path → assertSame(2.5, duration_seconds) + assertSame(2, tool_calls). Constant-0.0 or dropped-/1000 or forgotten counter cannot pass — arithmetic red under mutation by construction. The same-ms real-turn test keeps honest assertIsFloat/>=0.0.

## Step contract — current code (narrative clash settled)
WebhookDispatcher is a **workspace service** registered in Bootstrap::workspace() (:2170-2176) only when outboundWebhookEndpoints() is non-empty; NO webhookDialer accessor exists anywhere — FIX-1's account matches disk; r1/orchestrator-preamble accessor claims are stale narrative.
- **TUI shape live**: Bootstrap::chat() :1238 → buildChat → describeWorkspace :1745-1747 → Chat ctor `workspace:` :1536; emitters: settle (HandlesTurnCompletion.php:352-372, real window from turnHistory rows, max(0.0,…), null-prompt→honest 0.0), permission.asked (Chat.php:2966), schedule.fired (Chat.php:20091, launched/spawn_failed, quiet skipped).
- **Serve shape live**: ServerContext::feedFor(SessionHost) :319 wraps the SessionFeed::attach listener (hear→offer fires announceOutbound for EVERY event, Throwable-swallowed so the mirror can't kill the feed); feedFor called at :471/:535/:616. TURN_STARTED/TOOL_STARTED/TURN_COMPLETED window bookkeeping (ms in, seconds out); PERMISSION_REQUESTED rides askData()['tool'] (TranscriptProjector.php:498/:578 real emitter). Schedules: ServerContext::background() passes workspace service as BackgroundEvents 6th ctor arg; pumpSchedules dispatches fireDue verdicts (fireDue is eager — behavior-safe).
- **User-tier law by construction**: webhooksOut absent from LAYERED_KEYS and PROJECT_TIER_KEYS (test :106-114 pins all-three-legs); Bootstrap::workspace() reads user layer only; present-but-unusable → PermissionConfigException → exit-2 launch refusal naming endpoint (test :134-153).
- **E646**: lane src diff adds ZERO timers/timeout code. Per-POST bounds (2.0/10.0s) live in the already-welded C-AGT-5.e engine = webhook transport bounds, not LLM request timeouts. Test drain guard (15s) is test-side — allowed.
- **Config law matches docs**: OutboundWebhookConfig enforces https (http only to literal loopback: 127.*/::1/localhost), refuses URL credentials, MIN_SECRET_BYTES=16 — as SERVER.md/lang help claim.
- **Docs truth**: SERVER.md's "inbound only" sentence replaced with accurate paragraph incl. honest "session.ended … no emitter posts it yet"; SETTINGS.md regenerated + word figure flipped. en.php label/help/refuse all consumed.

## Gates run (plain pipe, env -u LINES -u COLUMNS, cwd=candy-code)
- --filter OutboundWebhookWiringTest → **11T/59A OK**.
- --filter 'OutboundWebhook|Webhook|BackgroundEvents|Automation|PermissionPrompt' → **229T/3870A OK** (matches FIX-1 claim EXACTLY).
- --filter 'PermissionFsmExtractionParityTest|PermissionRequest' → **17T/144A OK** (victim pins survive the ->name cure).
- --filter 'GlobDialectDifferential|DuplicatedTestHelperDrift|ReadmeSuiteFigureDrift|DocFigureProseDrift' → 123T, **only the 2 staleness-pair reds** (25,537 pinned vs 28,209 live — cumulative drift incl. the step's +11T; not charged). GlobDialect/DuplicatedTestHelperDrift/DocFigure green; the `(?:.*)` matcher literal is deliberately parenthesised to stay out of the corpus (documented :226-228) and holds.
- 2 PHPUnit deprecations = foreign AccessorDeprecationTest base fingerprint (r2-judged, not charged).

## Weld-ledger notes (report, not charged)
- DUR row for tests/Automation/OutboundWebhookWiringTest.php: my live run shows **11T/59A** — the fixer ledger's "15T/42A" figure does not match this tip; welder should derive the row from a green run at tip, not the ledger.
- +11T closeout re-pin + staleness pair ride as known; standing reds (AttachPaging, SkillTool SwallowingCatch, PermissionsIntroDrift, ForeignAgentPresetWiringTest:472, --worktree) untouched and not charged.

No fabricated path found anywhere: every leg dials a fake React\Http\Browser recording real signed POSTs (HMAC re-derived in assertSignedPost), driven through production chokes only.
