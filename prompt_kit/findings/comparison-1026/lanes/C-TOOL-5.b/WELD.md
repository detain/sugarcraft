# WELD — C-TOOL-5.b (Default formatter table `formatAuto`) — W12 · 2026-10-28

- **Pick:** 2fecd5f26 → master **3ecf83d95**, zero conflicts; SETTINGS.md, lang/en.php and
  src/Cli/Bootstrap.php auto-merged (append regions); patch-id `44358745ac8df487ede57949cf74c77f2c399354`
  identical lane-side/merged-side → no conflict-resolution content to diff.
- **No regen:** the pick's 14-file diff touches zero protocol/schema/SERVER.md/generated.ts/dist
  files (grep over name-status = 0) and all three doc generators `--check` rc0 on the merged tree
  (gen-command-docs / gen-tool-docs / gen-settings-doc). Verified, per brief.
- **Durations:** `scripts:` commit **7321edb18** — row `candy-code/tests/Format/FormatAutoTableTest.php`
  `0.013 / 6` (junit per-testcase sum at merged tip), landed inside the 0.013 tie block after
  Tools/BuiltIn/WorktreeJailedSearchToolsTest, before Attachments/FileMentionsTest 0.012.
  Set-equality 1487+1=1488 both directions: exactly one new `*Test.php` in the pick;
  FormatRunnerTest (0.372/23, now 30 methods) and PostEditFormatHookTest (0.284/9, now 14 methods)
  are modified-existing files with pre-existing rows, untouched per precedent. Desc invariant held
  (the 4 pre-existing violations unchanged, zero added).
- **Weld cure (this commit):** `tests/Hooks/BuiltIn/PostEditFormatHookTest.php:24-25` class docblock
  truth — "registers the hook only when the map names a command" predates the lane's own auto-arm;
  now reads "...or `formatAuto` arms the built-in table for a trusted project." Comment-only; no
  guard needle pins the old sentence (grep 'names a command' hits only SettingsPaneTest footer prose
  and DocFigureProseDriftTest's SETTINGS-bullet regex, which already carries the or-arm wording);
  guard re-run after the edit: HooksMcpSettings|PostEditFormat|DuplicatedDocBlockLine|DocFigure
  109T/4060A green.

## Gates (merged tree, cwd candy-code, plain pipe, env -u LINES -u COLUMNS)

- 'FormatAutoTableTest|FormatRunnerTest' 36T/196A green — the briefed **53T/278A** reproduces under
  the reviewer's 4-class filter 'HooksDefinition|FormatAutoTable|FormatRunner|PostEditFormat'
  = 53T/278A EXACT (HooksDefinitionTest contributes 0 — the phantom file, see anomaly note).
- 'LayeredSettings' **109T/427A** EXACT; bare 'LayeredSettingsTest' = 99T/383A — same
  filter-shape misdescription as C-MCP-2.b's briefed 13T/99A (unsuffixed filter includes
  BootstrapLayeredSettingsTest).
- 'HooksMcpSettingsTest|PostEditFormatHookTest' 14T/64A green.
- Doc guards 'ReadmeRosterDriftTest|SettingsSchemaDocDriftTest' 29T/258A green.
- PtyRunToolTest excluded per instruction.

## Anomalies recorded (per lead ruling: REVIEW-4 arbiter CLEAN; cure-only, CLOSED)

- **Phantom review chain:** a round-2 "5 findings" channel list (HooksDefinitionTest 4 pins /
  hooks.php / FormatRunner try-catch / README:940-943 / format.autoTable.width) drove the FIX-2/FIX-3
  counter-cycle; FIX-2's counter-claim "REVIEW-2.md does not exist" was itself false (the file is
  on disk, VERDICT CLEAN at fb441bbf7). REVIEW-4 re-derived every named artifact from disk: ALL
  five absent repo-wide (`git cat-file`/`git grep` 0); the design's four real test demands are
  landed in FormatAutoTableTest + FormatRunnerTest + PostEditFormatHookTest. The on-disk REVIEW-2.md
  is CLEAN; the 5-finding set never existed in any file. Net: real-but-misdescribed narration over a
  clean code state; no code change owed from it.
- **NIT commit-body prose:** "FormatRunnerTest +6" — actual delta is **+7** (23→30 methods,
  grep-verified on both trees; the durations set-equality is unaffected — modified-existing file).
  Commit body stays as landed; recorded here.
- Round-1 REVIEW-1 dispositions all rode the single lane commit: phantom-closeout CRITICAL fixed
  (commit made), DocFigureProseDriftTest bodyExcerpt window 9000→12000 in-step, LspLauncher cite
  fixed; LayeredSettings::userTierOnlyKeys() hub edit is disclosed-design-forced (§2 hub rule) and
  named in the commit body + lane REPORT.
