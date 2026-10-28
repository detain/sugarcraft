# REPORT — C-CTX-4.c — LANDED

Tip: 275819aed on cc/C-CTX-4.c (cat-file-verified commit; author+committer Joe Huss <detain@interserver.net>, 0 Co-Authored trailers; 14 files, +738/-138; tree clean; nothing pushed).

Shipped: (a) user-tier bool `instructions.walkToRoot` — ancestorRoot() climbs above the nearest .git to the topmost dir strictly below $HOME (raw `!==`-home guard closes within()'s resolve-onto hole); default-off byte-identical. (b) loadForced() accepts absolute/~ ONLY from user tier, anchored to home via ContainedPath::within (symlink escape refused by path, reason names 'outside the user home directory'); project tier keeps the exact loud refusal literals keyed as-spelled. (c) Bootstrap: forcedInstructions() reads readUserConfigWithoutProjectTier; new public projectForcedInstructions() = merged minus user (empty today; C-CTX-4.d seam); shared instructionPatterns() filter.

Red-before at parent 9fbb73173 (base-worktree copy w/ classmap-pinned src): "Tests: 16, Assertions: 10, Errors: 10, Failures: 5" — 15/16 fail; only the default-off parity pin passes.

Gates (plain pipe, cwd candy-code, -c phpunit.xml): InstructionWalkPastRootTest 17/17 OK; --filter Instruction 309T/1584A OK; LayeredSettings|SettingsSchemaDocDrift|ReadmeRoster|EnvRoster|TrustKey|ConfigWriteProducer 93T/39050A OK; GlobDialect 14/369 OK; ReadPathCensus 19/636 OK; BootstrapUserConfig|FeatWiring|StderrEmitterCensus|DuplicatedTestHelperDrift|LangParity 176T/24465A OK; gen-settings-doc --check rc0. ContainedPathInventory: 2 residual reds PROVEN base-reds at 9fbb73173 (AgentMemory/SkillLoader/PathJail/ContextHostCommand/TranscriptProjector rows — other lanes' unrostered sites, not my files); my 8->9 row + docblock total pass. ReadmeSuiteFigureDrift staleness pair untouched (known).

Mutations (cp+md5 restore c34c5d31): M1 walkToRoot-branch `false` → exactly 4 named walk tests red. M2 remove `$root !== $realHome` → exactly testARootThatIsHomeItselfDoesNotUnlockTheClimb red. M3 project tier flagged user → exactly 4 named refusal tests red (2 new + 2 flipped loader pins).

NEW test file (welder owes durations.tsv row): candy-code/tests/Context/InstructionWalkPastRootTest.php (17T/39A).

In-step guard flips: MEMORY.md census sentence eight→nine (names `ancestorRoot()`'s home floor), DocFigure arm 6→7 decisions + 'nine'; ContainedPathInventory ROUTED row 8→9 + word map SIXTY-ONE; ContainedPath docblock SIXTY→SIXTY-ONE; LayeredSettingsTest literal list; loader refusal pins moved to projectForcedInstructions (names/verbs kept).

Deviations: OUT-OF-DECLARED-SET (generator-forced): candy-code/README.md key-count + user-tier enumeration paragraph edits (the latter mandated by TrustKeyDocumentationDrift — fail-closed on the new key). src/Support/ContainedPath.php docblock — guard-forced roster. 'nine' added to DocFigure wordNumbers map + assertSame 6→7: arm edit was forced (census equality within-count==roster locks both instruments to 9). Test helper renamed testAUserTierAbsolutePatternIsHonouredUnderTheHomeAnchor per naming law; no '**Refused:**' literals; all wildcard fixtures chr(42)-concatenated (GlobDialect corpus verified green).

Seams (outside ownership, disclosed): FeatWiringReachabilityTest 'must not be honoured even when it arrives from the user's own config' docblock is now law-flipped-but-test-green (absolute still refused for non-contained paths — file not edited); excludedInstructions() keeps its own filter copy (DuplicatedTestHelper law: byte-identical rename skipped to stay hunk-local); LayeredSettings :918 hand list left 'named rather than numbered' (unguarded).

Deviation addendum (weld-time, R1 m2): candy-code/lang/en.php (+2 rows: label/help strings for instructions.walkToRoot) is an out-of-declared-set edit — guard-forced (SettingDefinition withLabel(Lang::t()) + LangParity) and correct, but was absent from the deviations paragraph above; recorded here before evidence commit per weld_notes.
