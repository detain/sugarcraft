# REVIEW-1 — C-AGT-7.c (webhook mode clamp + hygiene)

Lane: branch cc/C-AGT-7.c @ 30288d150 (7 files, +267/−80) on c36cc1982-era base.
Reviewer: fresh step reviewer, 2026-10-27.

VERDICT: CLEAN

## Gates (reviewer-run, cwd candy-code, plain pipe, env -u LINES -u COLUMNS)

- `--filter 'ServerConfig|HookWebhooks'`: **OK — 76 tests, 309 assertions** (2 pre-existing
  foreign AccessorDeprecationTest deprecations). Matches builder claim.
- `--filter 'Lang|SettingsSchemaDocDrift|TrustKey'`: 593T, **1 failure =
  ChatHostLangCatalogueTest accusing chat.effort.launch_unshaped** — BASE-PROVEN foreign:
  the key exists in master's lang/en.php (git show grep = 1) and its consumer
  src/Cli/LaunchContext.php is outside that test's scan list (src/Chat.php, src/Host,
  SessionPermissionMemo, PermissionPrompt, PendingAsk, EngineBackend, GoalLoop, EffortGate).
  The lane's en.php diff is exactly the one settings.webhooks.help line; zero scan-list
  file touched. Allowed carry, not a lane finding.
- five-guard: 133T, **only the allowed staleness pair** (pinned 25,537 vs live 26,129 —
  chain drift, weld/gate re-pin duty).

## Axis checks

1. **Mode clamp.** `hookRefusesMode()` = `$mode->isBypass()` — bypass alone refused.
   `hookPermissionModeFallback()` = `stricterOf(PermissionMode::Default)`; read the ladder
   (bypass 0 < auto 1 < accept-edits 2 < default 3 < plan 4 < dont-ask 5): looser servers
   clamp to default, stricter servers (plan/dont-ask) keep their mode — **Default can never
   be widened** by a webhook. Pins exist: ServerConfigWebhooksTest bypass refusal under
   BOTH flag sets (:81-88, needle "refused for hooks even with --allow-bypass");
   `testADontAskModeIsAcceptedAsTheStrictestRow` (webhooksValue stores the enum — verified);
   `testTheHookRowDoorRefusesBypassAlone` (all 6 cases via cases()); flipped
   `testADontAskServerKeepsDontAskForAModelessHook`; provider sweep accept-edits/auto→default,
   default→default, plan→plan, both flag sets. The base clamp fails the new
   accept-edits/auto rows (base inherits themselves) — discriminating by construction.
2. **HookWebhooks.** Escape splice moved INSIDE handle()'s try — PromptFence::escape()
   throws RuntimeException on preg_replace null (verified src :313-323), so a PCRE failure
   now answers the generic 503 (message contains no cause, no body: test asserts
   `'"kind":"hook_unavailable"'` and absence of `<env>`). Global window: `admit($key,$name,$rate)`
   charges per `name|address` AND per bare `name`; name grammar
   `/^[a-z0-9][a-z0-9_-]{0,63}$/` excludes `|` → keys uncollidable. Hand-traced: on either
   window full, early-return AFTER pruning that window only — refused delivery charges nothing
   (second window's local pruned list discarded; charge loop runs only when both pass).
   Globality is per-process (one HookWebhooks instance per serve) — matches "per hook overall".
   Old 429 text pinned nowhere (grep). Mutation reasoning for both new behaviors is
   deterministic (revert admit to single-window → second-address 429 assert reds; revert
   splice position → escape test reds with the throw escaping handle). Live mutation RUNS
   were impossible in this reviewer (shell cp/sed -i/edit denied); discrimination proven by
   reading + the builder's base-restore claim.
3. **Docs/guards in-step.** SERVER.md :299 is in the MANUAL region (generated protocol
   regions start :426/:529 — untouched). lang settings.webhooks.help wording matches code;
   SETTINGS.md carries only the settings TABLE row (grep :1218/:1351 — no help body), so no
   SETTINGS.md drift. The flipped `askTimeoutSeconds` relay truth verified live: Serve.php:443
   passes `permissionAskTimeout: $config->askTimeoutSeconds` on the hook spawn and
   BackgroundSupervisor:605 documents it as the relay window (ISS-004).
4. **Scope.** Exactly the 7 declared files (git diff --stat). No suite-figure.json, no
   durations.tsv, no dist/, no new test files → no durations row owed. §1.9: no glob-shaped
   or `**Refused:**`-shaped literals added (GlobDialect green in five-guard).
5. **Seam report correct.** Serve.php :429-431 "clamped to the Default family" comment IS
   now false; Serve.php is not in the brief's files.modify → report-only carry is right.
6. **Commit law.** One commit; `%ae`=`%ce`=detain@interserver.net (separate greps); 0
   Co-Authored.

## Notes (non-findings)

- NIT (evidence, step-local): /tmp/opencode/cc-cmp/C-AGT-7.c/ holds only the two `.tip.php`
  backups, not the base copies + md5 list the REPORT describes; the mutation ledger is thin.
  Substance unaffected — every claimed discrimination re-derived statically above holds.
