# C-EXT-3.e — SkillWorkshop + learned-skill store + /skills history/undo — DONE

Commits on cc/C-EXT-3.e (base e6188662a, never pushed, author detain verified, zero Co-Authored):
- f18b8cd86 main build (22 files: src/Tools/BuiltIn/SkillWorkshop.php, src/Skills/LearnedSkills.php,
  src/Skills/LearnedSkillRevision.php, SkillsHostCommand history/undo arms, Runtime WRITE_CAPABLE row,
  lang/en.php cmd.skills.*+host.skills.* keys, SKILLS.md §Learned skills, gen-tool-docs pages
  README/COMMANDS/PERMISSIONS/SETTINGS/ARCHITECTURE/AGENTS_AUTHORING, guard flips: ToolCatalogTest tail,
  BuiltInToolCorpus row, ReadmeRosterDrift/ReadmeSettingsTierClaim thirty-four + DocFigure thirty-nine word
  maps, ReadPathCensus rows Skills/LearnedSkills.php|scandir NAMES_ONLY + |file_get_contents OWNED_HOME,
  3 new test files) — landed by the earlier incarnation session.
- c607a755c this session's guard-law cures: eight refusal pins moved to capture-then-assert
  (SwallowingCatchCensus law: ExpectationFailedException extends RuntimeException, try{…fail()}catch(\RuntimeException)
  swallows); SkillWorkshop docblock respells `{@see ProposedSkills}` (SkillsHostCommandTest draft-reachability
  string census over src/Tools/** — the tool uses only LearnedSkills; store keeps the wrapping).

Owner ruling honored (R-EXT-2): model-written skills land live immediately behind the Write-class gate,
announced with path+revision+undo door, every landing versioned in history/<n>.md BEFORE the live write
(structural pin), window MAX_REVISIONS=8 with whole-file overflow to history/archive/, undo restores the
previous bytes or removes rev 1, shadow floor (project/built-in names refused), sanitiser/caps/redactor
reused from the draft store. /skills history|undo commands + usage arms shipped; dream pass untouched.

Gates (cwd candy-code, env -u LINES -u COLUMNS vendor/bin/phpunit -c phpunit.xml, plain pipe, ACTUALS):
- 'LearnedSkillsTest|SkillWorkshopTest|SkillsHistoryUndoTest' ×3 runs: 32T/190A/0F each (stable).
- '…|SwallowingCatchCensusTest': 34T/247A green.
- 'SkillsHostCommandTest|SlashDispatchTest|HelpTest::': 189T — only the 5 foreign KeyHelpTest reds below.
- Lane battery 'LearnedSkills|SkillWorkshop|SkillsHistory|SkillsHost|ToolCatalog|ToolDocDrift|ReadmeRosterDrift|
  ReadmeSettingsTierClaim|RuntimeTest|DocFigureProseDrift|ReadPathCensus|SymbolCitationDrift|DuplicatedDocBlockLine|
  GlobDialect|DuplicatedTestHelperDrift|OneSidedHome|SwallowingCatch|TreeWideGuardRoster|StderrEmitterCensus|
  EnvRosterDrift|TrustKey|ConfigWriteProducer|CliLangCatalogue|ChatHostLangCatalogue|BuiltInTool|ToolSignature':
  857T/96,229A — 4 reds, all foreign-inherited (below).
- 'CommandRegistry|HostCommands|CommandsTest|LangTest|ProposedSkills|TuiLang': 159T/2,862A green.
- gen-tool-docs --check rc0; php -l every touched file clean.

Foreign-inherited base reds at e6188662a (NOT owned, NOT fixed — proofs listed):
- KeyHelpTest ×5: KeyHelpTest.php + KeyBindingRegistry.php byte-identical to base (cmp), and re-running
  the filter with base lang/en.php restored gives the SAME five names → session-cycle keybindings landed
  without the KeyHelp roster re-pin.
- ChatHostLangCatalogueTest ×1: five chat.* keys present in BASE lang with zero base src askers (git show grep).
- CliLangCatalogueTest ×1: cli.bundles.marketplace.* sort disorder — no cli.* key in this lane's diff.
- SwallowingCatchSrcCensusTest ×1: SkillTool.php::execute bare-empty catch; file untouched by this lane.
- OneSidedHomeSandboxTest ×1: tests/Context/InstructionWalkPastRootTest.php exists at base, unrostered.

Deviations: orchestrator brief's "prompt-catalog cache regen (tools/prompt-catalog.php)" — NO such file
exists repo-wide (phantom; C-AGT-6.c idiom was the worktree flag). gen-tool-docs is the real roster trio
and it is green in-step.

WELDER CARRIES: durations.tsv rows for 3 NEW test files (welder duty):
candy-code/tests/Skills/LearnedSkillsTest.php, tests/Tools/BuiltIn/SkillWorkshopTest.php,
tests/Host/Commands/SkillsHistoryUndoTest.php; suite-figure re-pin for the +35ish T live-enum delta.
Worktree /home/sites/cc-cmp-C-EXT-3.e clean; probe worktree + /tmp backups removed.

## FIX ROUND 1 — reviewer MINOR: undo() head==1 checked its removals

Cure (LearnedSkills.php undo(), head==1 path): both removal legs now verify. The live-file
unlink is checked first (`@unlink === false && file_exists` — the house MemoryStore shape,
retry-tolerant) and throws naming the surviving path when nothing could be removed; the
revision-1 unlink is then checked the same way and throws naming the bytes that survived
(live gone, history/1.md intact — retry once the block clears). Order stays the reversed
write law (history covers the bytes until the live file is gone). The history @rmdir and
the existing dir-rmdir residue leg stay: by then both recorded files are gone, so a
surviving directory is named residue, never a false "removed". Docblock bullet + @throws
updated; docs/SKILLS.md sentence updated in-step (no guard owns the sentence; doc guards green).

Pin (LearnedSkillsTest::testUndoOfTheSoleRevisionRefusesLoudlyWhenARemovalIsBlocked):
chmod-0500 seals — skill dir blocks the live unlink, history dir blocks the revision unlink.
Non-root: capture-then-assert the loud RuntimeException (SwallowingCatchCensus law), assert
exactly the promised bytes survive, unseal, retry completes to "removed". Root polarity
asserts honest success (no skips, no SuiteSkipRoster churn; AtomicEditWriteTest precedent).

ACTUALS (cwd candy-code, env -u LINES -u COLUMNS, -c phpunit.xml, plain pipe):
- Trio after fix: 33T/216A/0F — per-file 18/110 (LearnedSkills) + 8/56 (SkillWorkshop) + 7/50
  (SkillsHistoryUndo); pre-fix commit-state trio 32T/199A. Delta +1T/+17A.
- Guard set (tests/Skills + PathsGlob/ClaimFamilies/DocFigure/EnvRoster/InertFrontmatter/
  SwallowingCatchCensus/ReadmeRosterDrift + SkillsHostCommandTest): 777T/46,250A/0F.
- Census battery (GlobDialect/DuplicatedTestHelperDrift/DuplicatedDocBlockLine/OneSidedHome/
  ReadPathCensus/SymbolCitationDrift/TreeWideGuardRoster/StderrEmitterCensus/
  BootstrapLaunchFormatConstants/ChildWallClock): 206T/56,710A — 2 reds
  (BootstrapLaunchFormatConstants PAGE_QUOTES order, OneSidedHome InstructionWalkPastRoot),
  BOTH reproduced byte-identical at base e6188662a in a throwaway probe worktree (probe removed):
  foreign-inherited, not this round.
- tests/Config window: 679T/61,336A, 9 reds = the 2-red suite-figure staleness pair (welder
  re-pin, lane now +33T) + 7 reds reproduced byte-identical at base e6188662a in the same
  probe (4 files run there: 74T/710A/7F, identical names; foreign-inherited).
- Mutations: M1 revert live-leg check → exactly 1 red (new pin :244); M2 revert revision-leg
  check → exactly 1 red (new pin :277, leg-1's 11 assertions stay green). cp+md5 restores
  verified (src ef3b0473df2755aac2bfe37830b66f59).

WELDER CARRIES (supersedes the line above): durations.tsv rows for the same 3 NEW test files
(no new file this round); suite-figure re-pin is now +33T for the trio era (216A).
