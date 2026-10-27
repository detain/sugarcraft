# P-TABS.d — Tab-Chat factory (Bootstrap::tabChat) — CLOSED

- Commit: `068b6f48e764b258e2ad2f69bb299a45f6291ac4` on `cc/P-TABS.d` (base 30d4f91cb, worktree /home/sites/cc-cmp-P-TABS.d). Author/committer `detain@interserver.net` (verified `%ae`=1, `%ce`=1, separate greps), zero Co-Authored trailers. Never pushed.

## Shipped
- `Bootstrap::chat()` → one-line delegate `return self::buildChat($root, null, true);`
- NEW `private static buildChat(?string $root, ?string $sessionId, bool $launch): Chat` — the whole former chat() body. Launch-only in it: the three process decisions (StatusLineCommand::configure, applyMediaRenderMode, setStaticSpinner — each CLEARS as well as sets, the risk line), openSession(), the notices/refusals/skips/frontmatter/drain tail, withLaunchNotices, picker. Tab path returns right after `withSessionLocking()`; `drainsRuntimeNotices: $launch`.
- NEW `public static tabChat(?string $root, ?string $sessionId): Chat` — shares the memoized workspace (new private `sharedWorkspace`/`describeWorkspace` + two statics in the chat region: launch re-describes and re-memos exactly as before; tab rides the launch's instance, cold-falls-back to a fresh describe), opens the named session's transcript+lock through the SAME `Chat::withSessionLocking()` machinery a lock-holder-less second open turns into the existing read-only window, lazily mints (row mirrors openSession's tail: selectedProviderLabel, cwd, gitBranchAt, defensive mint closure, prune guarded), fresh `liveToolEvents` per Chat (constructor default; no arg).
- `LaunchContext::app()` chain end: `->withTabFactory(static fn (?string $sessionId): Chat => Bootstrap::tabChat($root, $sessionId))`.
- `App/App.php`: ctor field `public readonly ?\Closure $tabFactory = null`, `withTabFactory()`, mutate carry. Field only — routing is P-TABS.e. No new() arg (defaults aren't passed there; paneAutoShow precedent).
- Chat.php UNTOUCHED — no seam needed (read-only window, locking, notice seeding all reused as-is).

## In-step anchor travel (§1.8 — five source pins sliced chat()'s body)
- BootstrapHomeStandInNoticeTest, BootstrapTerminalGuidanceNoticeTest, ClaudeSettingsHooksSkipRecordTest: drain scans re-anchored chat→buildChat (substr_count==1 preserved; launch-gate keeps the "nothing else reaches it" semantics).
- BackgroundReconnectAfterRestartTest: `backgroundStatuses: array_fill_keys(...)` regex scan → buildChat.
- ToolResultRenderModeTest: wiring-order scan → buildChat, `^ {8}` → `^ +` (call nested one level by the if($launch) gate); count==1 + order-before-mosaic preserved.
- No other `Bootstrap::class, 'chat'` body slices remain (swept; LaunchContextDelegatesTest anchors stay true — name-list + getFileName, both extraction-proof).

## Gates (cwd candy-code in worktree, plain pipe, env -u LINES -u COLUMNS)
- Pre-fix regression proof: base src restored (cp-backup + git-show 30d4f91cb) → TabChat `Tests: 9, Errors: 8, Failures: 1` (undefined tabChat / tabFactory-null); fixed files restored, md5-verified 3/3 OK.
- `--filter 'Bootstrap|Launch|TabChat'`: **OK — Tests: 1067, Assertions: 6104** (2 ambient PHPUnit deprecations pre-existing tree-wide: AccessorDeprecationTest non-public providers, fire on any run).
- `--filter 'Session|Chat'`: **Tests: 3314, Assertions: 26727, Failures: 1** — the failure is `RemoteBackendTest::testAnExistingSessionOpensFromItsSnapshotByUniquePrefix`, reproduced IDENTICAL at base src (verified same-turn, md5-checked); pre-existing, foreign, NOT cured here (outside ownership).
- Five-guard `ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift`: **Tests: 133, Assertions: 15383, Failures: 2** — only the allowed staleness pair (pinned 25,537 vs live 25,702; +9T is this lane's new file's methods + concurrent lanes).

## For the gate (durations)
- NEW test file: `candy-code/tests/Cli/TabChatFactoryTest.php` (9T/31A) — needs a `scripts/parallel-tests-durations.tsv` row at weld (not touched here, per law).
- suite-figure.json untouched.

## Deviations
- TabChatFactoryTest uses `BackendSelectionEnvSandboxTrait` alongside HomeSandboxTrait (provider-chain ambient-env safety; house pattern from ProviderSwitchKeepsTaskToolTest) — additive, no src impact.
- Memoized-workspace choice: brief said "shares the memoized workspace($root)" but workspace() was NOT memoized; the memo (two statics) was added in the chat region so the launch's per-call reset semantics (projectTierRefusals/LaunchNotices/RuntimeNoticeSink re-arm per chat()) are byte-preserved for the launch and skipped for tabs.

## Round 2 (fixer, 2026-10-27)
- Finding (reviewer MINOR): the `openTabSession()` @return docblock (Bootstrap.php:1727) claimed the `mint` key is always present, but the existing-row branch returns without it (consumer reads `$opened['mint'] ?? null` — behavior-safe; the docblock was the lie). Note: the reviewer's prose said "tabChat()"; the file location :1729 is openTabSession()'s docblock — that is what was fixed.
- Fix: docblock only, no behavior, no tests. `mint: ?\Closure` → `mint?: ?\Closure` (house style, mirroring the sibling `openSession()` @return at :4157) + one clause naming the case: "A named session whose row ALREADY exists carries no mint to return, so the key is simply absent there."
- Commit: 8ff7a2b33212a332844662fc139c93b5a7be465f on cc/P-TABS.d (1 file, +3/-2). Author %ae and %ce separately grepped detain@interserver.net, zero Co-Authored-By, no caliber, not pushed.
- Gates (cwd candy-code, plain pipe, env -u LINES -u COLUMNS): `php -l src/Cli/Bootstrap.php` clean. `--filter 'TabChatFactory|Bootstrap|Launch'`: **OK — Tests: 1067, Assertions: 6104** (2 ambient PHPUnit deprecations as in round 1). The foreign RemoteBackend unique-prefix failure did not surface in this run (it lives in the Session|Chat window; builder had disclosed it pre-existing anyway).
