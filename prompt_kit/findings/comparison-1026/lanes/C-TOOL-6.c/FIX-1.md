
## ROUND 1 — FIXER (2026-10-31, ~11:0x)

Commit `6a40ea08c` on cc/C-TOOL-6.c (4th pick atop 0fb92d81d; base 97201c6af unchanged). Docblock-only, 2 files +3/−2.

- MINOR-1 TAKEN: ScriptRunner.php:59 'recorded while it was alive' → 'measured at signal time' (the same sentence's 'all in the one `finally` of run()' carries the reviewer's 'in the finally' clause verbatim in effect; single-line hunk, no reflow). The orphan pin testNoOrphanOfTheScriptsOwnSurvivesTheRun polices the reality; no test can pin a docblock — none fabricated. §1.8 non-owed proof: zero derived-from-prose figures moved (Script family 26T/145A/1S byte-identical).
- NIT TAKEN same commit: ScriptRun.php:39 capBreaches enumeration gains `return` (written at ScriptRunner.php:484); line reflowed to ≤80 cols. php -l both files clean.
- MINOR-2 DISCLOSED here (evidence, not repo — REPORT.md history not edited): the builder batch line under-disclosed the 3rd batch failure — GlobDialectDifferentialTest::testTheCorpusFigureInTheDocBlockIsTheDerivedOne (docblock 310,245 vs derived 560×556=311,360), foreign/accepted-roster (PathGlob.php untouched by the range; lane files carry zero corpus-admitted glob-shaped literals — reviewer Q4), and the builder's 207T/28087A totals do not reproduce (measured 206T/28247A, 3F).

Gates (cwd=candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe): Script family anchored '(ScriptRunnerTest|ScriptBridgeTest)::' = 26T/145A/1 skipped (unchanged); bare TreeWideGuardRosterTest = 17T/3886A GREEN; bare DuplicatedTestHelperDriftTest = 14T/204A GREEN (Deprecations: 2 = accepted-foreign AccessorDeprecationTest pair). PtyRunToolTest::testExpectMissIsLoudAndTheScriptStillContinues excluded by filter shape. BashSandbox/Bubblewrap not co-filtered (base predates db1441a61 — known-foreign, report-never-cure). Law: %ae/%ce grep-count 1 each, 0 Co-Authored; porcelain 0 after commit; cat-file 'commit' on 6a40ea08cb3c7eec9487a84620b0cbb1ad1f2fd2 same shell.
