# C-AGT-7.c — Webhook mode clamp + hygiene — LANDED

## What shipped (one commit, 7 files, all in ownership)

**AUT-6-P2 (ServerConfig):**
- NEW `hookRefusesMode(PermissionMode): bool` = `$mode->isBypass()` — the
  per-row hook door now refuses `bypass-permissions` ALONE (used to refuse the
  whole bypass class incl. `dont-ask`). `dont-ask` answers every ask by
  REFUSING, so it is the accepted strictest row; refusing it taught operators
  to omit the field, and omission inherits.
- `hookPermissionModeFallback()` = `$this->permissionMode->stricterOf(
  PermissionMode::Default)` — the inheritance clamp is now the STRICTER of the
  server mode and `default`: bypass/auto/accept-edits servers land mode-less
  hooks on `default`; servers already stricter (`plan`, `dont-ask`) keep their
  own mode. The old clamp flattened everything to `default`, which WIDENED a
  dont-ask server's hooks.
- `:590`-area docblock corrected: the asks a `default`-clamped hook makes are
  NOT dead letters — Serve.php spawns the hook session with
  `permissionAskTimeout: $config->askTimeoutSeconds`, and
  BackgroundSupervisor's `$permissionAskTimeout` IS the approvals-relay window
  (ISS-004); a watching human can answer, unanswered expires as a refusal.
  The clamp's promise: no inbound request runs a write nothing allowed and
  nobody approved.
- `answersAsksWithoutAHuman()` docblock truth-flipped (its question is still
  exactly two of six modes; the hook doors now ask narrower questions).

**AUT-6-P3 (HookWebhooks):**
- The `PromptFence::escape()` splice moved INSIDE `handle()`'s try: a PCRE
  failure (backtrack/recursion limit on a hostile body) answers the generic
  `503 hook_unavailable`, cause never reaches the client. Catch-comment
  truth-fixed re ISS-057 ($run logs its own cause via the Serve closure; the
  escape cause is PCRE's own message naming nothing private; class holds no sink).
- Rate window charged per address AND per hook OVERALL (`admit($key, $name,
  $rate)`): the bare-name key cannot collide with `hook|address` keys (name
  grammar has no `|`). When either window is over, NEITHER is charged — a
  refused delivery consumes no budget anywhere. 429 message: "…per address or
  per hook overall" (old text not pinned in any test).

**Docs moved in the same commit (guards+doc-rows law):**
- `lang/en.php` `settings.webhooks.help`: rate wording now per-address +
  per-hook-overall; bypass-permissions refused at launch; mode-less hook runs
  stricter of server mode and default.
- `docs/SERVER.md` :299 hooks row: same two truths, incl. the risk-note
  disclosure that accept-edits/auto servers hand mode-less hooks `default`.

**Tests (no new files → no durations row):**
- `ServerConfigHookModeInheritanceTest`: :54 flip — dont-ask server's hooks
  STAY dont-ask; provider re-cut to stricter-of expectations (accept-edits→
  default, auto→default, default→default, plan→plan); NEW provider
  `testTheHookRowDoorRefusesBypassAlone` (all six modes); class docblock
  rewritten.
- `ServerConfigWebhooksTest`: dont-ask-refused test → `dont-ask` accepted as
  the strictest row; bypass-refused test unchanged (needle still green).
- `HookWebhooksTest`: `testTheGlobalRateWindowCapsTheHookAcrossAddresses`
  (rate 1: addr .1 200, addr .2 429, window+1 → 200; deliveries 2 not 3) and
  `testAFailedEscapeAnswersTheGenericRefusalNotAThrow`
  (ini_set pcre.backtrack_limit=0 + 25 KB `<env>` body → 503, no delivery;
  ini_restore in finally — throw shape proven by measurement).

## Gate results (plain pipe, cwd=candy-code, env -u LINES -u COLUMNS)

- `--filter 'ServerConfig|HookWebhooks'`: **OK — 76 tests, 309 assertions**
  (2 PHPUnit deprecations: pre-existing foreign AccessorDeprecationTest
  provider-visibility, base-present).
- `--filter 'Lang|SettingsSchemaDocDrift'`: 575 tests, **1 failure =
  FOREIGN-INHERITED base red** —
  `ChatHostLangCatalogueTest::testEveryCatalogueKeyIsAskedForByChatOrHost`
  accuses `chat.effort.launch_unshaped`, minted at base by already-welded
  chain commit `a992413b0` (C-PRV-1.b) whose consumer `src/Cli/LaunchContext.php`
  is NOT in that test's scan list. Proven not this lane: the key exists at
  base `lang/en.php` (git show count 1), this lane's en.php diff is exactly
  the one settings.webhooks.help line, zero launch_unshaped contact, and no
  Chat/Host scan-list file was touched. Fix belongs to the effort-chain owner
  (widen the scan list) — outside C-AGT-7.c ownership.
- five-guard (`ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift`):
  133 tests, **only the allowed staleness pair** red (pinned 25,537 vs live
  26,129 — +592 drift carried in by sibling chain lanes; re-pin is the gate's
  duty, forbidden to lanes).

## Base-red discrimination (regression tests fail before the fix)

Restoring the two src files at base (cp-backups + md5s under
/tmp/opencode/cc-cmp/C-AGT-7.c/) reddens EXACTLY the new halves: 8 errors
(hookRefusesMode undefined ×6 datasets; escape throw escapes the route;
dont-ask row refused) + 6 failures (global-rate, dont-ask-keeps-its-mode,
stricter-of ×4 accept-edits/auto datasets). Tip restored md5-identical, gate
re-run green.

## Weld seams (reported, not edited — outside ownership)

1. `src/Cli/Serve.php` :429-431 comment is now FALSE: "…the bypass class was
   refused there, and an unmodeled row is clamped to the Default family" —
   post-this-step the row door refuses bypass ONLY and the clamp is
   stricter-of-default (dont-ask stays dont-ask). One-paragraph truth-flip for
   the weld (or C-AGT-5.d's chain owner; HookWebhooks/ServerConfig docblocks
   here already tell the new story).
2. `ChatHostLangCatalogueTest` scan-list gap (above) — foreign, needs its own
   lane.
3. `docs/SETTINGS.md` carries only the settings-table row for webhooks (the
   help TEXT is served from lang/en.php, edited here); no SETTINGS.md body
   claims about rates/modes needed moving — verified by grep.
4. Suite figure 25,537 → live 26,129: gate/closeout re-pin (NOT lane-owned:
   suite-figure.json + durations.tsv untouched, law honored; no NEW test
   files, so no durations row owed by this lane).
