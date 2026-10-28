# C-SEC-6.a — DONE

Verdict: shipped single commit da90aceb0 on cc/C-SEC-6.a (base e4a705244, worktree /home/sites/cc-cmp-C-SEC-6.a, never pushed). SavedRules service extracted; /permissions saved + forget <n|pattern> live in TUI and command.exec; persistRules delegates byte-identically; live gate stays frozen per launch and both replies teach next-launch + /permissions mode.

## Files (9)
- NEW src/Permissions/SavedRules.php — list()/path()/append()/remove() via SettingsWriter (You tier); append keeps the exact chat.permission.persisted/not_persisted shapes; remove writes only on a hit.
- src/Permissions/PermissionPrompt.php — persistRules → 3-line delegate; unused Chat/SettingsTier imports dropped.
- src/Host/Commands/PermissionsCommand.php — saved/forget arms + helpers; docblock four subcommands; doorless → no_writer errorReply.
- lang/en.php — 9 keys (alphabetical) + cmd.permissions.hint widened.
- docs/PERMISSIONS.md (@@911 `p` bullet only) · docs/COMMANDS.md (gen-command-docs --write; --check rc0).
- tests/Config/Settings/SettingsWriterCensusTest.php — IN-STEP: persistRules->write row replaced by SavedRules append->write + remove->write.
- NEW tests/Permissions/SavedRulesTest.php (13T) · NEW tests/Host/Commands/PermissionsSavedRulesCommandTest.php (9T).

## Gates (plain pipe, cwd candy-code, -c phpunit.xml)
- New pair: OK 22T/88A. Combined (new+Census+FsmParity+PermissionsCommandTest): OK 80T/5246A.
- Red-before (base src file-copy + rm SavedRules): 10E+12F → restored tip md5-clean.
- Mutations: M1 dedup-neuter 4F; M2 index off-by-one 3F; M3 miss-writes-anyway initially GREEN (SettingsWriter::write short-circuits only an empty delta, not unchanged values) → cured with door-knock counter pin, re-proved 1F; M4 2F; M5 5F.
- Final 14-file family (GlobDialect, DocFigure, ReadmeRoster, EnvRoster, TrustKey, ConfigWriteProducer, ReadPathCensus, StderrEmitterCensus, CommandDocsDrift, PermissionsIntro, Census, FsmParity, PermissionsCommandTest, KeyBindingDrift): 585T/72612A, rc=1 solely on PermissionsIntroDriftTest::testThePatternToolRosterIsTheWholeCatalog — PROVEN INHERITED at base: 'Schedule' absent from the docs roster line 760 (git show e4a705244); not my hunk; the Schedule-tool lane owes that in-step row. Not fixed here (sibling-collision risk).

## NEW test files (gate must add durations.tsv rows)
- candy-code/tests/Permissions/SavedRulesTest.php
- candy-code/tests/Host/Commands/PermissionsSavedRulesCommandTest.php

## Deviations / notes
- forget target parsed with trim(substr($arg,6)) not a regex — a preg literal was GlobDialect corpus-admitting and reddened the 304,700 figure.
- reportField applied to the saved-list action column too (untrusted config bytes).
- Bare '/permissions forget' answers usage + numbered list as a non-error teaching reply.
- TestCase::run() is final — command-test helper named ask(); errorReply carries the Message $error flag, isRefused() stays false for these refusals (pinned).
- Only lang/en.php exists in candy-code/lang → LangParity N/A.
