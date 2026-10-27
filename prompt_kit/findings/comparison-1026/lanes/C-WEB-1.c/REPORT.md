# C-WEB-1.c — @-mention index refreshes at every turn end (TUI + serve)

**VERDICT: DONE.** `MentionIndex::forget()` is now the first statement of `TurnRunner::complete()`, so the process-wide `files()` memo is dropped at every turn close — completed, cancelled, failed, and parked-completion paths — refreshing @-mention candidates for both the TUI and `serve` (`fs.search` routes through the same memo). Committed as `2381b0d28` on `cc/C-WEB-1.c` in `/home/sites/cc-cmp-C-WEB-1.c` (single commit, plain one-line `-m`, author+committer `detain@interserver.net`, zero trailers). Not pushed.

## Changes

- `candy-code/src/Host/TurnRunner.php` (+13): `use SugarCraft\Code\Attachments\MentionIndex;`; `complete()` calls `MentionIndex::forget();` as its literal FIRST statement, before the `$state` early-return guard — cancelled turns still ran tools, so they must refresh too. Docblock extended with the C-WEB-1.c paragraph (one bounded re-list per turn end, same 10s `GitRunner` ceiling as today's first `@`).
- `candy-code/src/Attachments/MentionIndex.php` (29±): truth-flip. Class docblock no longer claims candidates are "invisible until the process restarts" — names `TurnRunner::complete()` as the production refresh caller (TUI + serve), stale window narrowed to mid-turn-open files; `FileMentions::complete()` non-memoized fallback sentence kept. `forget()` docblock rewritten: production caller named; tests use it as reset hygiene. Grep confirmed no README/docs prose pinned the old phrases — no doc rows owed.
- `candy-code/tests/Server/FsSearchTest.php` (+75): new turn-end refresh test — git-init fixture, prime the memo with a search, start a turn, plant an untracked file mid-turn, search misses (memo holds), settle the turn, search lists the new file. No `forget()` anywhere in the test body (design-block requirement). Helper named `backendWithOneTurn()` — collision-grepped unique vs the Host test's `singleTurnBackend()` (DuplicatedTestHelperDrift).
- `candy-code/tests/Host/TurnRunnerMentionFreshnessTest.php` (NEW, 3 tests): git-skips unless `GitRunner::available()`; drives `TurnRunner::start()` directly with an anonymous single-turn backend. Pins: (a) file written mid-turn absent from `files()`/`rank()` before completion, present after; (b) CANCELLED turn (cancel → settle) refreshes; (c) PARKED path (tool events → `completeParked()`) refreshes.

## Gates (targeted, cwd `candy-code`, plain pipe, `-c phpunit.xml`)

- Pre-fix regression shape: new Host test = 3 failures exactly on the post-completion asserts.
- Post-fix `--filter 'TurnRunnerMentionFreshnessTest|FsSearchTest'`: **OK 11 tests / 35 assertions**.
- Adjacent `--filter 'MentionIndex|FileMentions|MentionFilePopup|ComposerVimMode|TurnRunner|TurnController|SessionHost|FsMethods|FsSearch'`: **OK 162 tests / 969 assertions**.
- Whole-dir `tests/Host tests/Attachments tests/Server`: **OK 737 tests / 6465 assertions**.
- Guards `--filter 'ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift|SuiteSkipRoster|SymbolCitationDrift|TreeWideGuardRoster|StderrEmitterCensus'`: 282 tests, 4 failures, both families expected/inherited (see below).
- Mutation: commenting the `forget()` line → 4/4 freshness tests FAIL; restore md5-verified identical.

## Inherited reds (not this lane's files; for welder/gate)

1. `ReadmeSuiteFigureDrift` staleness pair: pinned 25,537T vs live 25,679T — pre-existing drift (base ≈ 25,675) plus this lane's +4; suite-figure re-pin is gate duty.
2. `TreeWideGuardRoster` ×2: `candy-code/tests/Cli/SelfUpdateTest.php` `glob($phar.'.tmp-'.\chr(42))` sites (base lines 1617/1635) are unlicensed — the roster row only licenses the `livePharPath()` spelling. **Proven present at base `960772c97`**; outside this step's file set, cannot fix without breaking scope law.

## New test files → durations.tsv row owed by gate

- `candy-code/tests/Host/TurnRunnerMentionFreshnessTest.php` (3 tests)

`tests/Server/FsSearchTest.php` is pre-existing (modified only). No `suite-figure.json` / `durations.tsv` / `dist/` / protocol-file touches by this lane.

Tip re-read from git log: `2381b0d28dbfa1cdf6e398ff92bf91d941268849`, tree clean.
