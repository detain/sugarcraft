# C-SES-7.c — Incognito launches (`--incognito`) — builder REPORT (fresh re-build after churn destruction)

Verdict: COMPLETE. Branch `cc/C-SES-7.c` @ tip `00a07c290c3766b3475dc17d9fb71578a9c984a1` (cat-file: commit; %ae/%ce detain = 1/1; 0 Co-Authored-By).

## Commits (all on cc/C-SES-7.c; nothing pushed)
- `ba532d284` — implementation: incognito launches, nothing kept (10 files, +183/-7)
- `a98c29207` — merge of skeleton `ee43a76be` (branch had been cut from master-ledger 574e69f1d, not the skeleton; skeleton is now an ancestor, merge clean)
- `00a07c290` — test pins + help-line alignment + ArgvParser stderr census row 41→42 (in-step)

## Landed vs BRIEF (all four arms)
1. FLAG: skeleton parser carry + `LaunchContext::$incognito` + `useIncognito()/incognito()`, `Bootstrap` delegates, registered in `bin/candy-code` beside `useSessionLaunch`; refused with `--continue`/`--resume` (keys `cli.argv.incognito_with_session` / `cli.argv.hint.incognito_no_session`). Bare switch only — `--incognito=x` lands in unknownFlags (pinned).
2. TUI: `Bootstrap::openSession()` incognito arm returns id/name/history/picker with NO mint, no prune, no row; `buildChat()` passes `sessionStore: null` (`$keptStore`) + `incognito: self::incognito()` to Chat; Chat carries a real field (promoted readonly param + accessor + mutate-map carry — first-keystroke reset pinned). `HandlesTurnCompletion`: consolidation + dream dropped for incognito (suggestion + auto-commit kept — BRIEF declares only memory passes); flush rides the null store. Memory READS stay on (memoryStore not nulled). `Renderer::renderStatusBar` paints `incognito · ` segment via `incognitoBadge` (firstFitting, restFloor honored).
3. `-p`/run: gate `if ($ownsSession && !$args->incognito)` skips AUT-2 open → persist closure (and the C-SES-1.a ledger append inside it) never arms; JSON envelope carries no `session_id` key. In-process `Bootstrap::useIncognito()` registered on the non-incognito path to clear a stale static.
4. Serve: untouched (C-SES-7.e, already welded).

## Deviations (documented)
- BRIEF `cli.flag.incognito`: tree has no `cli.flag.*` family → house `cli.completion.option.incognito` (completion desc).
- Guard-forced beyond files.modify: `lang/en.php` help-screen heredoc entry — `HelpTest::testEveryFlagTheParserRecognizesIsDocumented` was RED at the skeleton without it (§1.8 guard+doc same change).
- `SessionStore::setIncognito` NOT implemented (prior-generation claim; BRIEF design is sessionStore:null). Launch notice + PERMISSIONS rows not declared → not added.
- ArgvParser stderr census row 41→42 rode the tests commit (skeleton's refusal emitter; census flips in-step).

## Tests
- New `tests/Chat/IncognitoLaunchTest.php` (4T): no-row/no-minter openSession + control; settle-pass drop (consolidation+dream+summary empty, control schedules MemoryConsolidatedMsg + note lands); flag survives loop clones (withSize, withSessionStore); status-bar segment only for incognito (reflection + stripZoneMarkers).
- `ArgvParserTest` +3 (bare parse, every reopen spelling refused with own hint, attached value refused); `NonInteractiveSessionPersistenceTest` +1 (incognito one-shot: row absent, no .jsonl sidecar under home, json envelope keyless; setUp/tearDown clear the static); `LaunchFlagsTest` +2 needles (help regex+prose; all three completion dialects — fish `-l incognito` verified against Subcommands::4501 render). +8 tests total.

## Gate result lines (cwd=candy-code, env -u LINES -u COLUMNS, plain pipe)
- `--filter IncognitoLaunchTest`: Tests: 4, Assertions: 25, OK
- `--filter ArgvParserTest`: Tests: 141, Assertions: 425, OK
- `--filter LaunchFlagsTest`: Tests: 106, Assertions: 504, OK
- `--filter NonInteractiveSessionPersistenceTest`: Tests: 12, Assertions: 89, OK
- `--filter StderrEmitterCensus|IncognitoLaunchTest`: Tests: 99, Assertions: 22484, OK (HEAD re-run)
- `--filter DocFigureProseDrift|ReadmeRosterDrift|ReadmeSettingsTierClaim|GlobDialectDifferential|DuplicatedTestHelperDrift|EnvRosterDrift|KeyBindingDrift`: Tests: 397, Assertions: 46797, OK
- `--filter CliLangCatalogue|BinCandyCodeDispatch`: 139T, 1F = `--worktree` completion census (FOREIGN standing red — reproduces identically at main-tree HEAD 07e49e872)
- `--filter LaunchFlagsTest|HelpTest`: 6F — 4× KeyHelpTest + HelpTest CANDY_CODE_SERVICE_TIER roster leg (ALL FOREIGN standing reds — reproduce identically at main-tree HEAD; the parser-flag scrape itself is GREEN with my help entry)
- `--filter ReadmeSuiteFigureDrift`: 2F = expected staleness pair (live 28458 vs pinned 25537; carry below)
- 2 PHPUnit Deprecations ride every run (pre-existing family, present at skeleton baselines too).

## Carries (weld/gate duty — lane-forbidden files)
- NEW test file `tests/Chat/IncognitoLaunchTest.php` needs a `scripts/parallel-tests-durations.tsv` row (else runs in zero shards).
- `suite-figure.json` + README headline re-pin: +8 tests (staleness pair red by design until then).
- Branch carries the merge commit `a98c29207` (skeleton ∪ impl); weld pick set = `git log ee43a76be..HEAD` minus the merge if cherry-picking onto master.

## Notes
- Co-tenant bans honored: Subcommands touched only the OPTIONS row; NonInteractive only the session-open/persist gate region; no C-SES-7.b/d, C-SEC-7.a, C-WEB-5.a, C-TOOL-2.f, C-SES-8.d, C-PRV-3.d files.
- Session (this lane) suffered transient double-application churn once (duplicate statics repaired pre-commit; all counts verified singular at HEAD) and one silent test-edit revert (re-applied; final state = gates above). Ghost `REVIEW-1.md` left in place for r2.
