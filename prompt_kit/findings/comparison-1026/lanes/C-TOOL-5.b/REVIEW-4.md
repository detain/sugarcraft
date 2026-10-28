# REVIEW-4 (arbiter, round 4) — C-TOOL-5.b — 2026-10-28

VERDICT: CLEAN — design fully landed; REVIEW-2 was real-but-misdescribed; the
"5 findings" channel list is a phantom set, every named artifact verified absent.

Location note: written at prompt_kit/findings/comparison-1026/lanes/C-TOOL-5.b/
(the only lanes/C-TOOL-5.b/ directory on disk; worktree has none).

## History resolution (disk)
- REVIEW-2.md EXISTS (this dir) and reads "VERDICT: CLEAN (0C/0M/0 minors)" over
  fb441bbf7 (tree 1e36f2c9b, identical to tip 2fecd5f26). It carries NO 5-finding
  list; the brief's "5 findings incl HooksDefinitionTest autoTable pins" matches
  only FIX-2/FIX-3's *quoted channel text*, not any file on disk.
- FIX-2's counter-claim ("REVIEW-2.md does not exist") is FALSE — it exists here,
  03:11, ratified by FIX-3's own read. Net: both narrations partially confabulated;
  the code state is clean.

## Per-finding disk re-derivation (claimed → reality)
1. MAJOR "HooksDefinitionTest 4 pins (incl format.autoTable width)":
   candy-code/tests/Hooks/HooksDefinitionTest.php ABSENT at branch AND master
   (git cat-file fails both; ls-files complete Hooks/ dir has no such file);
   no HooksDefinition class repo-wide (git grep 0). design-tool.md §C-TOOL-5.b
   names NO such file and NO autoTable.width/format.width key — `git grep -i
   format.autoTable|format.width|autoTable.width` = 0 hits; 'autoTable' hits are
   35 class-name mentions. Design's 4 test demands ARE landed: preference order
   (FormatAutoTableTest:56, FormatRunnerTest:359), absent-binaries-skipped
   (FormatAutoTableTest:127), explicit-wins (FormatRunnerTest:375), untrusted-no-
   arm (FormatRunnerTest:401, PostEditFormatHookTest:161-untrusted + :134-armed);
   registration-with-only-formatAuto pinned via real Bootstrap::hooks() (armed
   test asserts hook present with commands()===[]). MOOT.
2. MINOR "hooks.php:96-106 helpText lies script-only": candy-code/hooks.php
   ABSENT (only builtin-commands/1390-hooks.php, 21 lines, 0 format tokens).
   Real helpText lang/en.php:3281-3282 has zero 'script' substrings; :3282
   truthfully names the table (gofmt/rustfmt/ruff-black/shfmt/terraform/
   project-prettier), explicit-wins, trustedProjectHooks gate, off-by-default. MOOT.
3. MINOR "README:940-943 script-only sentence": blob lines 940-943 are the
   context-pruning/Prune-Compress-Recall prose (verbatim). Format claims ride
   README:1284+ (guard-checked roster regions, moved in-step 129→130, 73→74 with
   formatAuto reason clause). False cite. MOOT.
4. MINOR "FormatRunner catch swallows getMessage": grep -c 'catch|getMessage'
   = 0 on the blob. Child failure output rides EVERY FormatReport
   (guard(): $output = trim(stderr+stdout) :314) with SPECIFIC restore reasons
   (:344 'the command exited %d after already rewriting the file'); pinned by
   testTheRestoreNoteNamesTheReasonAndKeepsTheFormattersOutput (:319). MOOT.
5. MINOR "SETTINGS.md formatAuto missing / roster not derived": 4 presences
   (:306 producer row w/ Bootstrap::hooks()→withAuto, :1248 tier table, :1402
   restart, :1680 user-tier); userTierOnlyKeys roster row added in-step
   (LayeredSettings.php:620) and twin-pinned by exact-list assert
   (LayeredSettingsTest:66-68). SettingsSchemaDocDrift green. MOOT.

## Design compliance (design-tool.md:389-403)
All demands PRESENT: FormatAutoTable (21 extensions, preference order,
project-local prettier only :76-120, memoized host lookups), withAuto injectable
?callable $binaryPresent LspLauncher shape (FormatRunner:177-187), trust-fold
AND-gate (:189-193 + Bootstrap:6068-6071 strict ===true), register-when-commands-
OR-armed (Bootstrap:6072), user-tier bool default false (HooksMcpSettings
:70-81), suppression-stays-off refinement (disclosed, defensible).

## Hygiene at 2fecd5f26 (reproduced here)
- 14-file diff = declared 11 + 3 disclosed in-step (LayeredSettings row,
  DocFigureProseDriftTest :2241 regex + :2290 window 9000→12000, LayeredSettings
  twin-pin). suite-figure/durations/phpunit.xml/dist untouched (weld-owned).
- Single commit, %ae grep -c detain = 1, %ce = 1 (separate), 0 trailers.
- Gates (cwd candy-code, plain pipe, env -u LINES -u COLUMNS, no PtyRun):
  'HooksDefinition|FormatAutoTable|FormatRunner|PostEditFormat' →
  "Tests: 53, Assertions: 278" OK (HooksDefinition leg 0 tests — absent, as ruled);
  'ReadmeRosterDrift|EnvRosterDrift|SettingsSchemaDocDrift|DocFigureProseDrift|LangParity' →
  "Tests: 164, Assertions: 44872" OK (= FIX-3's 157T/42031A + LangParity 7T/2841A,
  exact decomposition; zero staleness reds in this family at tip);
  'LayeredSettings' → "Tests: 109, Assertions: 427" OK.
- porcelain 0 after gates. "PHPUnit Deprecations: 2" is config-standing
  (phpunit.xml byte-untouched by the lane).

## Advisory (non-blocking, step-local NIT)
- candy-code/tests/Hooks/BuiltIn/PostEditFormatHookTest.php:24-25 class docblock
  still says hooks() "registers the hook only when the map names a command" —
  falsified by the lane's own auto-arm. One-line fix at weld:
  "…only when the map names a command or `formatAuto` arms the table for a
  trusted project".
