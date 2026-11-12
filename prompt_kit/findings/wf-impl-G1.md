# wf-impl G1 — final tree-wide guard fixes

All 8 guard failures from the K=24 sharded run fixed (ReadmeSuiteFigureDriftTest left to the orchestrator).
No new test files, so no `scripts/parallel-tests-durations.tsv` row was needed (that file's pre-existing
working-tree diff is the orchestrator's and was NOT committed here).

1. **RuntimeNoticeSinkDeliveryTest::testNoSourceFileCarriesStackedDocComments** — three stacked blocks merged/moved:
   - `src/Cli/Subcommands.php` `workflowReport()`: summary block + `@param $lint` block merged into one.
   - `src/Workflows/WorkflowEngine.php`: P1-8 inserted `settleRecord()` (with its own doc) under
     `withdrawPauseRecord()`'s doc-block, stranding it; that block moved back down onto `withdrawPauseRecord()`.
   - `src/Workflows/WorkflowLinter.php` `checkBudgetAndAnnounce()`: dropped the bare `@param $findings`
     block (the real block below already carries the same `@param`). No ACCEPTED_STACKED_DOC_PAIRS rows added.
2. **ForeignAgentPresetWiringTest::testNoSourceFileOutsideAgentReadsAnAgentsPermissionMode** — gate decided, code
   unchanged. New readers: `Agents/StagePreset.php` (copies the roster Agent's mode into the DTO),
   `Workflows/WorkflowEngine.php` (`stageGate()`: re-modes the session gate only when the preset mode is STRICTLY
   stricter and the session is not bypass; `stageAgent()` carries it), `Agents/EngineExecutor.php` (hands the stage
   gate to SubAgentGrantHook only when the mode is non-Default AND stricter than the session's). The roster is built
   by `Agent::fromPreset()` (Bootstrap::agentRoster), which pins a foreign preset's mode to Default, so a foreign
   preset can at most narrow an accept-edits/auto session to Default — never loosen. Recorded all three in the
   roster with that reasoning.
3–5. **DuplicatedTestHelperDriftTest** —
   - `agent()` (3 copies, the new `AgentWorkerPoolInjectedForkTest` differing only in the agent name) →
     extracted to `tests/Support/BuildsPoolSubAgentTrait.php` (name `PoolTestAgent`; nothing asserts on it),
     used by `AgentWorkerPool{ForkFailure,TeardownForkTotal,InjectedFork}Test`.
   - `refusal()` (StageConditionPointerTest / StageConditionLoopLeafTest, two tokens apart — the `$allowLoopLeaves`
     flag) → extracted to `tests/Support/RefusesStageConditionTrait.php` (flag defaults to parse()'s default).
   - `request()` (EngineExecutorStructuredOutputTest vs StageOutputSchemaTest, literal + then signature) → made the
     copies agree (`?array $tools = null`, `'check the lib'`).
   - `PLAN` const (WorkflowToolResumeTest / WorkflowPlanRunRecordTest, identical) → one provider:
     `WorkflowPlanRunRecordTest::PLAN` is public, the resume test references it.
6. **SourceSizeRatchetTest** — extracted the launch WorkflowEngine assembly (E663 pool wiring + C5's deferred
   preset-roster source) into `src/Cli/WorkflowEngineWiring.php::engine()`; `Bootstrap::workflowEngine()` keeps the
   registry/tier/tool-universe decisions and delegates. Bootstrap 12068 → 12032 lines; ceiling lowered 12_060 → 12_032.
7. **InterpolationOpenerTokenTest** — the new walker is `Subcommands::parseParamPairs()` (C6's `key:=<json>` splitter),
   which counts `[{`/`]}` CHARACTERS of a CLI argument, not PHP tokens. Recorded as a KNOWN_GAPS row in the same
   family as the Awk/Sed rows (the gap is in the selection, not the walker).
8. **GlobDialectDifferentialTest** — `src/Util/PathGlob.php` census re-measured from the test's derivation:
   327,180 pairs (574 patterns x 570 paths), both figures updated. The "thirteen rows / ten wider, three narrower"
   figures derive from the 33-row differential, not the corpus, and are unchanged (GlobFigureDriftTest green).

Verified green: every file above, plus ReadPathCensusTest, TreeWideGuardRosterTest, SymbolCitationDriftTest,
WorkflowProviderHandoffTest, WorkflowEnginePresetRosterTest, FeatWiringReachabilityTest, SwallowingCatchSrcCensusTest,
HomeDirectoryPathReaderInventoryTest, ContainedPathInventoryTest, GlobFigureDriftTest, DuplicatedDocBlockLineTest,
the touched suites; `php tools/check-one-type-per-file.php` and `php tools/check-child-lifetimes.php` 0 problems.
