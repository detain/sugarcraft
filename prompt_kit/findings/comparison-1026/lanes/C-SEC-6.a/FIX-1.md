# C-SEC-6.a FIX-1 (r1 findings applied, 2026-10-28)

Amended single commit on `cc/C-SEC-6.a` (base `e4a705244`); pre-fix tip was `da90aceb0`.

## MAJOR 1 — bare `/permissions forget` threw on an unreadable config — FIXED
`src/Host/Commands/PermissionsCommand.php`: the usage branch called `savedBody()`
unguarded and the `forget_none` branch re-read it unguarded. Restructured
`forgetRule()` to run ONE guarded `savedBody` read shared by both the bare-verb
and no-match replies; failure routes through a new `readFailed()` helper — the
same `host.permissions.read_failed` refusal `savedRules()` answers with (which
now calls the helper too, so the sentence cannot drift between verbs).
Pin: `PermissionsSavedRulesCommandTest::testBareForgetOnAnUnreadableConfigRefusesExactlyLikeSaved`
— corrupt config, bare forget: errored refusal asserted, identical body to
`/permissions saved` on the same file, no usage/table text above the failure.

## MINOR 2 — SavedRules::list() fabricated allow rows / over-counted — FIXED
`src/Permissions/SavedRules.php`: added ONE loadable-row filter `loadableRow()`
(array + string pattern + action PermissionAction::tryFrom accepts — mirroring
`Bootstrap::permissionRules()`'s skip predicate; grammar deeper than this stays
the loader's jurisdiction, disclosed in the docblock). `list()` shows and counts
only loadable rows (no fabricated `allow`, no phantom `''` pattern); `remove()`
numbers `<n>` over the same filter, so the displayed count and the positional
target agree. Rows the filter rejects cannot ride a rewrite anyway — the
settings schema refuses any `permissionRules` array still holding them — so a
landing forget normalizes them out (measured: pre-normalize keep-as-is version
died on `InvalidArgumentException: permissionRules[0] has no string 'pattern'`).
Pin: `SavedRulesTest::testListShowsAndNumbersOnlyTheRowsTheLaunchWouldLoad`.

## NITs 3/4 — REPORT.md prose (main tree, uncommitted) — FIXED
8 keys → 9 keys; "short-circuits unchanged values" → "short-circuits only an
empty delta".

## Red-before proof (swap of da90aceb0 src files, fix copies kept in
/tmp/opencode/csec6a-fix/, restore cmp-clean)
- `testBareForgetOnAnUnreadableConfigRefusesExactlyLikeSaved`: ERRORS —
  `RuntimeException: …config.json holds a JSON list, not an object; fix it by
  hand first` escaping run() via forgetRule — the reviewer's probe shape.
- `testListShowsAndNumbersOnlyTheRowsTheLaunchWouldLoad`: FAILS — fabricated
  `['pattern' => '', 'action' => 'allow']` + `action 'nope'` rows in list().

## Gates (plain pipe, env -u LINES -u COLUMNS, cwd worktree candy-code, -c phpunit.xml; PtyRunToolTest never in filter)
- New pair: OK — Tests: 24, Assertions: 100 (was 22T/88A; +2T/+12A).
- Combined (SavedRules|PermissionsCommand|PermissionPrompt|PermissionFsm|
  SettingsWriter|PermissionsIntro|LangParity|DocFigureProseDrift|ReadmeRosterDrift):
  352T/13727A, Failures: 1 — ONLY the standing FOREIGN
  `PermissionsIntroDriftTest::testThePatternToolRosterIsTheWholeCatalog`
  (C-AGT-5.a owed Schedule roster row; NOT fixed here per instruction).
- Guard family (GlobDialect|EnvRosterDrift|TrustKey|ConfigWriteProducer|
  ReadPathCensus|StderrEmitterCensus|CommandDocsDrift|SettingsWriterCensus|
  PermissionFsmExtractionParity|KeyBindingDrift): OK 447T/68244A.
