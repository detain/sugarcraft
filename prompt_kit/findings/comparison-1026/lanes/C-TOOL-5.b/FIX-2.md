# FIX-2 — C-TOOL-5.b round 2 (2026-10-28): REVIEW-2 is a PHANTOM review — dispositions only, zero code change

Amended fb441bbf7 → 2fecd5f26, tree byte-identical (both 1e36f2c9b), single commit, worktree clean,
%ae/%ce detain 1/1, zero trailers. The cited REVIEW-2.md does not exist in this lane dir (only
BRIEF/REVIEW-1/REPORT); findings taken verbatim from the fixer brief. Evidence, all on the lane tree:
- MAJOR-1: HooksDefinitionTest.php / hooks.php / HooksDefinition.php exist NOWHERE (repo-wide find 0
  hits; --filter 'HooksDefinition' → "No tests executed!"; 'autoTable' matches 0 src/tests/docs files).
  The design (design-tool.md:395) demands preference-order/absent-binary/explicit-wins/untrusted-no-arm —
  all pinned in real files: FormatAutoTableTest::testCandidatesRideInPreferenceOrder,
  testHostNamesAreHostLookupsAndMissingNamesAnswerFalse; FormatRunnerTest::testAnExplicitEntryWinsOverTheArmedTable;
  PostEditFormatHookTest::testTheLaunchRefusesTheArmedTableForAnUntrustedProject. 'format.autoTable.width'
  has no analog (formatAuto is a Bool); the exact form is unpinnable as written.
- MINOR-1: files absent; real helpText lang/en.php:3282 DOES document the auto table; :3281 never claims "script-only".
- MINOR-2: README.md:940-943 is context-estimate prose; format claims live at README:1293-1294+:2413, guard-checked.
- MINOR-3: FormatRunner.php has ZERO catch/getMessage tokens (grep -c=0); child failure output is KEPT in
  the note — pinned by testTheRestoreNoteNamesTheReasonAndKeepsTheFormattersOutput.
- MINOR-4: formatAuto IS in SETTINGS.md (:305/:306/:1248/:1402/:1680); tests/Config/Settings/SettingsSchemaDocDriftTest green.
- NITs: no run() method to type (`: int` N/A); no resolve('formatter') in Bootstrap::hooks(); HOOKS.md:1161
  example matches the real withCommands/formatAuto keys.

Gates (cwd candy-code, plain pipe, -u LINES -u COLUMNS, no PtyRun), all exit 0:
FormatAutoTable|FormatRunner|PostEditFormatHook 53T/278A; SettingsSchemaDocDrift 19T/180A;
ReadmeRosterDrift|TrustKeyDocumentationDrift|ConfigWriteProducer|ReadmeSettingsTierClaim 43T/679A;
recheck set DocFigureProseDrift|EnvRosterDrift|GlobDialectDifferential|DuplicatedTestHelperDrift|ChildWallClockBudget 166T/53766A.
