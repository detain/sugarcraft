# C-SES-7.c REVIEW-1 (fresh read-only reviewer, round 1)

Lane: cc/C-SES-7.c @ 00a07c290 (4 commits, merge-base 574e69f1d; master 716523235).
Verdict: **FINDINGS — 1 MAJOR, 0 CRITICAL, 2 NIT.**

## MAJOR-1 — Tab sessions mint a real row under `--incognito` (Bootstrap.php:1356,1391 / openTabSession :1806-1812 / Chat.php:11493-11494)

`buildChat()` gates only the STORE handed to Chat (`$keptStore = self::incognito() ? null : $sessionStore` :1368) and `openSession()` returns no `mint` on its incognito arm (:4342ish). But the TAB path is ungated: at :1356 a non-launch build routes to `self::openTabSession($sessionStore, $sessionId)` — the REAL store — which always returns a `mint` closure calling `$store->createSession(...)` (side effect: `pruneEmptySessions` too), and :1391 passes `sessionMint: $opened['mint'] ?? null` into Chat with no incognito check. Chat's submit fires `($this::sessionMint)()` on the first real prompt regardless of the null store (Chat.php:11493, and `ensureSessionRow()` :8388).

Reproduction (static, traced): `candy-code --incognito` → open a new tab (App tab factory, LaunchContext:2597 `Bootstrap::tabChat($root, null)`; process-global `self::incognito()` stays true) → send any message → a session row (id, provider, model, cwd, git branch) lands in the on-disk session store and appears in `/sessions`. This falsifies the item's core promise ("nothing kept") and the lane's own README paragraph ("no session row appears in the session list") for every tab except the initial launch tab. Not a fabricated path — `--incognito` + tabs compose in production.

Cure: gate the minter in buildChat — `sessionMint: self::incognito() ? null : ($opened['mint'] ?? null)` (and, for full honesty, skip the `pruneEmptySessions` side effect under incognito, e.g. by checking `self::incognito()` before calling openTabSession's store side effects). Add a regression pin: incognito + `tabChat`-shaped build → first submit leaves `listSessions()` empty (the current IncognitoLaunchTest covers only the launch-path `openSession`).

## Verified clean

- **Scope/regions.** ArgvParser + ParsedArgs edits are the bare-switch arm, tail ctor param + `from()` pass-through — DISJOINT from co-tenant C-SES-7.b's `SUBCOMMAND_FLAGS['session']` region; lane tail has no import param (7.b not in merge-base). No file outside the brief's declared set touched.
- **Refusal law.** `--incognito` + any reopen spelling (`--continue`/`-c`/`--resume`/`-r`, bare and `=`-attached) refused via `cli.argv.incognito_with_session` AFTER the continue+resume conflict check; `--incognito=x` → unknownFlags. All three pin groups exist and pass.
- **Suppression completeness (except MAJOR-1).** Launch TUI: no row, no minter, no transcript (persistTranscript null-store/EnhancedStore guard :2168-2183; TranscriptStore write methods null-guard; flush nothing-scheduled). Turn-end: consolidation + dream dropped (HandlesTurnCompletion :340-341) with a discriminating control test; suggestion/auto-commit passes KEPT = declared deviation matching the brief's memory-passes-only scope. `-p`: openSession + persist + C-SES-1.a ledger append all skipped (`$ownsSession && !$args->incognito` :332; persist closure early-return :717; json `session_id` absent — pinned; recursive no-.jsonl pin). Memory READS stay on per design risk note (`@session:` reader :495 — read-only).
- **Rosters honest.** StderrEmitterCensus ArgvParser 41→42 tracks the new usage-envelope emission; Subcommands OPTIONS row + LaunchFlagsTest help-needle + fish/zsh/bash completion dialects all pass; cli.* lang keys sit in sorted guard position.
- **Tests not vacuous.** Settle-drop pin has a positive control (non-incognito schedules MemoryConsolidatedMsg, backend asked, note lands in MemoryStore; incognito arms all zero). Clone-carry pin checks withSize preserves flag, withSessionStore doesn't flip. Status-bar pin paints the segment only for incognito.
- **Builder gate claims reproduced.** Targeted runs (cwd=candy-code, plain pipe, -c phpunit.xml):
  - `--filter IncognitoLaunchTest` → `Tests: 4, Assertions: 25` OK (2 deprecations = pre-existing AccessorDeprecationTest non-public providers, not lane).
  - `--filter 'ArgvParserTest|NonInteractiveSessionPersistenceTest|StderrEmitterCensusTest'` → `Tests: 248, Assertions: 22973` OK.
  - `--filter LaunchFlagsTest` → `Tests: 106, Assertions: 504` OK.

## NITs (step-local, cheap)

- NIT-1 Bootstrap.php new `useIncognito()`/`incognito()`: `public static function` at column 0 — mirrors the pre-existing 8-line column-0 family in the file (PSR-12 drift, family not lane's doing); indent while touching.
- NIT-2 lang/en.php `chat.incognito.status` inserted mid `chat.mode.*` group (unguarded block); a chat.incognito adjacency slot reads cleaner.

## Weld carries (disclosed, confirmed owed)

durations.tsv row for tests/.../IncognitoLaunchTest.php; suite-figure.json + README re-pin (+8T); foreign standing reds (--worktree CliLangCatalogue/BinCandyCodeDispatch, SERVICE_TIER KeyHelp/Help, ReadmeSuiteFigureDrift staleness pair) reproduce at master and are not the lane's.
