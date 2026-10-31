STEP=C-TUI-6.f LANE=/home/sites/cc-cmp-C-TUI-6.f
FIX-1 (round 1). Fixer = sole writer; builder + r1 reviewer terminal. Base picks 0247fe160 + 9592ea7a1 kept intact (never amended); two follow-up fix commits on cc/C-TUI-6.f.

## MAJOR-1 — TreeWideGuardRosterTest RED at tip: CURED (3a2db4ea7)
- RED-BEFORE (BARE class, cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS, at 9592ea7a1): Tests: 17, Assertions: 3884, Failures: 2 — both legs name `Protocol/WebStringsTest.php => scandir($this->dir)` (assertions at TreeWideGuardRosterTest.php:1190 and :2047). Matches reviewer Q4 + lead reproduction.
- CURE: added exactly one roster entry `'Protocol/WebStringsTest.php' => ['scandir($this->dir)'],` to WALKS_A_DIRECTORY_THE_TEST_MADE, in array-alphabetical slot after the Protocol/ContextSourcesTest.php row, with a two-line WHY comment (tearDown() sweeps the webstrings-<uniqid> dir setUp() mkdir'd under sys_get_temp_dir() before its own rmdir — never leaves the sandbox; WebStringsTest.php:27-28 mkdir, :34 sweep, :39 rmdir verified on disk). Tight file+exact-expression key; no anchor widened (guard's own instruction honored).
- Diff proof: `git show --stat 3a2db4ea7` = 1 file, 3 insertions(+), 0 deletions — union law held: Agents/Live/AgentLiveRegistryTest, Cli/LoginSubcommandTest (:645), Tools/BashSandboxTest (:806), Cli/SubcommandsMcpServeTest (:678) and every other sibling row byte-untouched (add-only diff, zero '-' lines).
- GREEN-AFTER at tip 915f030d1: BARE TreeWideGuardRosterTest = Tests: 17, Assertions: 3887, 0 failures (2 PHPUnit Deprecations = pre-existing baseline, present in the red run too).

## MINOR — ServerMethods::strings() en-fallback catch unpinned: CURED WITH DISCRIMINATION (915f030d1)
- Pin: testTheWireAnswersJunkLocaleTagsInEnglishRatherThanFaulting, added INSIDE existing tests/Protocol/WebStringsTest.php (no new file ⇒ no durations row). Real handler leg via the house in-process fixture (ProtocolFixture::new()->client()->call('server.strings', ['locale' => 'not a locale!'])) asserting ['locale'] === 'en' + non-empty ['strings'].
- Discrimination PROVEN by mutation: stripped the try/catch in src/Protocol/Methods/ServerMethods.php (python-applied, count-1 assert) → exactly 1E at WebStringsTest.php:161 via WireClient.php:108 (Dispatcher.php:373 Throwable → generic RPC error → call() raises LogicException), the 6 pre-existing unit tests stayed green; cp+md5 restored (13e4edf0…). Non-discriminating risk assessed first: sanitize()/candidates() read on disk show grammar-valid-but-unshipped tags (e.g. 'zz') fall to the ladder's English floor WITHOUT the catch, so the tag MUST be grammar-refusing — 'not a locale!' (spaces/bang survive trim/lowercase/underscore/`@.`-tail normalization, fails TAG_PATTERN) exercises the catch leg exactly.
- Figures: WebStringsTest now 7T/82A (was 6T/79A: +1T/+3A); pair filter 'WebStringsTest::|WebStringsDriftTest::' = 9T/87A green.
- WELDER NOTE: durations.tsv row for WebStringsTest.php must read 7T (carry said 6T/79A).

## NITs — NOT acted (weld-ledger items per brief): .ts line-parse regex quote caveat; per-key T::translate design deviation note.

## Laws check
- DuplicatedTestHelperDriftTest BARE at tip: Tests: 14, Assertions: 204, 0 failures GREEN.
- Residue grep of own diff (`if (false|&& false|\|\| true`): CLEAN before both commits.
- §1.8 non-owed: fix diff = tests/TreeWideGuardRosterTest.php + tests/Protocol/WebStringsTest.php ONLY — no docs/lang/settings/protocol-trio/dist surface in either commit (git show --name-only both). Trio stays weld regen duty.
- lang/en.php, candy-code-web/**, forbidden files byte-untouched; no composer/npm/refresh-deps/caliber; no full suite; PtyRunToolTest::testExpectMiss never entered any filter (all runs class-anchored/BARE-roster); GlobDialect/ReadmeSuiteFigureDrift not re-run (accepted-red, zero wildcard/doc-figure surface added — 'not a locale!' is not glob-shaped, uniqid sentinel already in file).
- BashSandbox|Bubblewrap not co-run (base predates db1441a61 cure, pairing caveat honored — not run at all here).
- Commits: %ae/%ce grep -c detain = 1/1 each, zero Co-Authored trailers, explicit-path git commit -o.

TIP: 915f030d1ceb7c700a530da6e76f42bb153a0cdb (git cat-file -t → commit), picks=4 (2 lane + 2 fix), porcelain 0. Weld-eligible: MAJOR landed.
