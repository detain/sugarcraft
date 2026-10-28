# C-TOOL-3.c — Session-owned background shells — LANDED

Commit: 6f69565d33d2c55ec5a22fec59e53304257b779f on cc/C-TOOL-3.c (base 23fa8ab8e), 9 files, +572/−14.
Author/committer detain-verified (separate %ae/%ce greps), zero Co-Authored/Co-Developed trailers, never pushed.

## What landed
- BackgroundShellRegistry: rows gain `owner`; start(…,?owner 4th), status(…,?owner 2nd), read(…,?owner 4th), stop(…,?owner 2nd); foreign owner gets the SAME not-registered answer an unknown id gets (status null / read same error door / stop identical found:false array); ownedBy(?owner) lists visible rows in start order; rowVisible() law `null owner || null caller || equal`; parseRow lenient (missing key ⇒ untagged — pre-tag exports keep working); teardown/internal callers ownerless ⇒ see everything (one teardown preserved).
- NEW src/Tools/AcceptsShellOwner.php; Bash, ShellOutput, ShellStop implement withShellOwner (Bash rebuild carries shellOwner across withToolProgressEmitter and vice versa — pinned).
- EngineBackend::turnTools binds $this->sessionId right after $limits->applyTo — BEFORE the MutatesContextLedger/AcceptsToolProgress continue-arms (structural pin in ShellOwnershipTest).
- No new proc_open/spawn sites (fork law untouched); zero new emitters/sprintfs; E646-clean.

## NEW test file (gate owes durations.tsv row + suite-figure re-pin)
candy-code/tests/Tools/ShellOwnershipTest.php — 10 tests / 60 assertions.
Failing-before proven via /tmp cp-harness: against HEAD src, 6 errors + 6 failures (47T run); restored byte-exact (cmp 6/6), green.

## Gate figures (cwd candy-code, plain pipe, -u LINES -u COLUMNS)
- ShellOwnershipTest+BackgroundShellRegistryTest+ShellStopTest+ShellOutputTest: OK 59 tests / 350 assertions.
- BashBackgroundTest+BashToolProgressTest+ShellTailRingTest: OK 20 / 77.
- turnTools consumers (DelegationLoaderView, EngineBackendLedgerFrame, ModelPruneInTurn, TaskSpendPropagation, ToolCapSettings, BuiltIn/SkillToolFork): OK 67 / 417.
- five-guard filter (DuplicatedTestHelperDrift|GlobDialectDifferential|DocFigureProseDrift|ChildWallClockBudget): OK 129 / 15993 (2 pre-existing PHPUnit deprecations; my files contribute 0).
- SymbolCitationDriftTest + StderrEmitterCensusTest: OK 104 / 37468.
- tools/check-one-type-per-file.php: rc 0 (62 libs, 3198 files, 0 problems).
- tests/Tools/BashToolProgressTest etc. all green; PtyRunToolTest never run.

## Deviations
- ShellStop::execute initially dropped the owner arg — caught by the new pin, fixed in-step (same commit).
- Base-inherited FOREIGN red disclosed: ProjectTierRefusalInventoryTest 2F (dot-path inventory stale vs src/Memory/AgentMemory.php + src/Support/MacAltNotice.php planted by recent W11 picks; blamed files byte-identical to HEAD, my diff adds zero dot-paths). Not this lane's; closeout glance.

## Seams
- P-SESSION-TABS: C-TOOL-3.c IS its per-tab isolation substrate (row 'owner' + ownedBy + tool-level scoping landed) — land before P-SESSION-TABS per design risk note.
- C-TOOL-3.d /shells panel: use ownedBy(?session) + row 'owner' key; null-owner rows stay visible.
- C-TOOL-3.e web shells.* events: filter by the same registry visibility law (ownerless internal answers remain whole).
- Gate re-pin owed: new *Test.php ⇒ durations.tsv row + suite-figure.json/README pair (ReadmeSuiteFigureDrift staleness left untouched here per law).
