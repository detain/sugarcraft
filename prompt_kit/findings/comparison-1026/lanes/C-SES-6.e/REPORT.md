# C-SES-6.e — Update-available notice: DONE (tip in `tip` row below; branch cc/C-SES-6.e off 093cd25e1)

tip: bd54894faf003d0cf5c4591252d7e0982ad4fa19 (code commit; this stamp commit follows)

## What shipped
Phar-only, once-per-24h, async release-list check that paints an update notice on the STARTUP summary from the PREVIOUS check's cache — `view()` never waits on the network. Opt-out `CANDY_CODE_NO_UPDATE_CHECK=1`.

## Files
- NEW `candy-code/src/Cli/UpdateCheck.php` (451 L): gates (phar identity via `Phar::running(false)===''`→no-op; opt-out; owned-home; 24h freshness), async `React\Http\Browser` walk ON the loop (no fork/child — no child-lifetime roster row), reuses `SelfUpdate::selectRelease()/selectDevRelease()`, own async `rel="next"` pagination (5-page cap, same-origin `https://api.github.com/repos/detain/sugarcraft/releases` route pinned — foreign Link hosts never dialed), cache write via `AtomicFileWriter::write(...,0600)`, `cachedNotice()` per-process memoized (key path\0version), corrupt/failed checks degrade to no-notice with a once-latched `Log::sub('UpdateCheck', …)`, paint-time RE-validation of the server-supplied version (shape gate: `Version::isComparable`/HEX sha; rendered through `StartupSummary::oneLine` choke; nothing interpolated into anything executed; no cost figures).
- NEW `candy-code/tests/Cli/UpdateCheckTest.php` (578 L) — **18 tests / 88 assertions** (runtime, this tree). Hermetic: injected `$cachePath`/`$now`/`$pharPath`/`$fetcher` (transport double — synchronous `React\Promise\resolve(new GuzzleHttp\Psr7\Response)`, `use (&$queue)` by-ref), temp dir 0700 cleanup, sandboxed-HOME `cachePath()` test with `finally` restore, real `~/.candy-code` and GitHub never touched.
- `src/Support/StartupSummary.php` +19/−4: `update` cheap-fact (tryFact-wrapped, oneLine-wrapped), compose row between place and knowledge, docblock rows renumbered.
- `src/Cli/Bootstrap.php` +26/−0 (region [bootstrap:chat]): `scheduleUpdateCheck()` = `Loop::get()->futureTick(fn → UpdateCheck::refreshIfStale())` try/catch'd; NonInteractive never reaches chat().
- `lang/en.php` +6: `startup.update.available` + `cli.help.screen` 4-line roster entry (the Help roster lives ONLY in this heredoc — Help.php has zero env literals).
- `docs/ENVIRONMENT.md` +3/−2: own row (full disclosure incl. two-launch staleness + composer-never-checked), flag-style enumeration, AND the truth-flip: the DISABLE_MODEL_METADATA row's "the one launch-time egress" is now "one of … two … neither is an LLM call".
- `README.md` +1/−1: model-db paragraph names the second non-LLM egress + its switch.
- `tests/Support/ReadPathCensusTest.php` +6: `Cli/UpdateCheck.php|file_get_contents` OWNED_HOME row (in-step guard law).
- `tests/Support/StartupSummaryTest.php` +54/−1: +2 tests (update-row paint; absent-key drops) + failsoft pin re-derived cheapFacts wraps 1→2 + reads `UpdateCheck::cachedNotice(` (guard flip in same commit).

## Two-launch staleness pin
`testTheLaunchThatRefreshesNeverShowsItsOwnDiscovery`: notice null → refreshIfStale discovers 0.5.0 (1 request) → memo still null in-process → `resetForTesting()` (= next launch) → notice paints from cache with **zero** requests — frame never networks. Companion `testTheCacheIsReadOncePerProcessEvenAfterItDisappears` (memo proof: unlink after first read).

## Gates (cwd candy-code, plain pipe, PtyRun excluded by filter shape)
- `UpdateCheckTest|StartupSummaryTest|EnvRosterDriftTest|HelpTest`: 239T / 140,995A — 6F = **standing only** (SERVICE_TIER + 5 KeyHelp names match the standing list).
- Combined `+ReadPathCensus|LangParity|CliLangCatalogue|SelfUpdate|ModelMetadata|StderrEmitterCensus|GlobDialectDifferential|BootstrapLaunchFormatConstants|DocFigureProseDrift`: 557T / 173,342A — 9F all standing (PAGE_QUOTES, cli-block sort, SERVICE_TIER, 5 KeyHelp, ReadPathCensus-derived).
- `TuiLangTest|LangLaunchLocaleTest`: 22T/1,467A OK. `EnvRosterDriftTest` alone: 37T/40,236A, its 2 mention-census arms green for both directions.
- ReadPathCensus-derived failure diff names ONLY foreign rows (`Server/ServerConfig.php|file_get_contents => 2`, `Session/Import/RemoteSessionPush.php|file_get_contents`) — my row resolves clean; not mine (my diff touches no Server/Session files).
- Suite-wide "PHPUnit Deprecations: 2" is ambient (untouched SelfUpdateTest/ModelMetadataTest show the same 2).
- In-step root-cause fixes proven: `\getenv` (T_NAME_FULLY_QUALIFIED) → bare `getenv(self::…)`; const rename `OPT_OUT_ENV`→`UPDATE_CHECK_OPT_OUT_ENV` (scanner `constValue` is keyed by SHORT name — collided with ModelMetadata's same-named const and misresolved the read).

## Mutations (6/6 discriminating, cp+md5 restore, post md5 `da7c5fed…` every time)
M1 fresh-gate→2F (fresh + failed-keeps-clock) · M2 phar-gate→1F (composer) · M3 memo→2F (two-launch + read-once) · M4 same-origin→1F (foreign-link) · M5 failure-writes→3F (byte-keep, no-cache, non-list) · M6 paint-shape→1F (planted-version).

## §7 figure delta for the welder
**+20 tests** (18 new file + 2 StartupSummaryTest). New test file for the durations row: `candy-code/tests/Cli/UpdateCheckTest.php` (18T/88A runtime here). StartupSummaryTest durations row exists (grew +2T/+8A-lines); assertion delta re-derive on the weld's green run.

## Proof protocol trio untouched
`git show --name-only` of the code commit lists exactly the 9 paths above — no `docs/protocol/*.json`, no SERVER.md, no generated.ts, no dist.

## Seams disclosed
- `nextPageUrl`/page-walk duplicated (SelfUpdate's are PRIVATE; widening SelfUpdate was out of files-list) — candidate consolidation seam.
- failure-with-existing-cache keeps prior bytes + `touch` restarts the clock (ModelMetadata law); test includes the PHP stat-cache caveat (clearstatcache before mtime read).
- Help-screen entry is the 4-line heredoc shape (roster lives only in `cli.help.screen`).
- Composer-mode users get no notice by design (identity gate) — documented in ENVIRONMENT row.
- Collisions: none hit — Bootstrap/chat hunk, en.php, README, ENVIRONMENT hunks stayed in declared regions.
