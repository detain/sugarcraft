# C-SEC-4.a — Served sessions honour `trustedProjectCommands` — LANDED

- Branch/commit: `cc/C-SEC-4.a` @ `1c50da86b` (base fffeceaec, author+committer detain@interserver.net, zero trailers, NOT pushed).
- Fix: `$workspace->projectCommandsTrusted` now passed as `loadAll()`'s 2nd arg at both served callers —
  `candy-code/src/Host/SessionHost.php` ctor `customCommands` load (:241 region) and
  `candy-code/src/Protocol/Methods/CommandMethods.php` `files()` (:131 region).
  Body-only hunks in the two [hot] files; no schema/event/gen-chain/doc change (brief: docs/guards none).
- Test: CREATED `candy-code/tests/Host/ServedProjectCommandTrustTest.php` (4T/13A) mirroring the TUI
  precedence pins (CustomCommandDispatchTest compact shape): trusted override runs in the served host
  (template sent as a turn, loader reports the override, nothing refused) AND `command.list` lists it
  `source: file` over the real Dispatcher/WireClient; untrusted shadow refused in both surfaces
  (refusedCommands names the file + `trustedProjectCommands`, built-in answers, template never sent).
  Discrimination proven by git-stash runs: pre-fix exactly the 2 trusted tests FAIL
  (`overrides []` / `source 'builtin'`), post-fix all green.

## Gates (cwd candy-code, plain pipe, env -u LINES -u COLUMNS, -c phpunit.xml)
- `--filter 'ServedProjectCommandTrust|CommandLoader|CommandMethods|SessionHost|CommandList'`: OK — 95T/441A, 0F, 2 PHPUnit deprecations (pre-existing tree shape — unmodified SessionHubTest prints the same 2).
- Five-guard `ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift`: 133T/15363A, ONLY the permitted staleness pair red — pinned 25,537 vs live 25,684 (base live was 25,680; this lane adds +4T). Re-pin is gate duty.

## Durations.tsv gate duty (lane may not edit it)
NEW test file needing a row in `scripts/parallel-tests-durations.tsv` before sharded CI, else it runs in zero shards:
- `candy-code/tests/Host/ServedProjectCommandTrustTest.php` (4 tests, ~4.7s isolated).

## Inherited reds (NOT this lane; reproduced with lane edits stashed at pristine base fffeceaec)
- `SwallowingCatchCensusTest:606` 1F, `TreeWideGuardRosterTest:1086` + `:1943` 2F — same set with and without this lane's files; no failure text names any lane file.
