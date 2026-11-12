# wf-impl track C5: resumable Workflow-tool runs (P1-8), default capture (P0-4), tool `budget` (P1-7), narration (P2-2), preset roster wiring (P1-6 cross-track)

Files touched: `src/Workflows/{WorkflowEngine,WorkflowResult,WorkflowGrammarGlossary,WorkflowNarration(new)}.php`, `src/Tools/BuiltIn/WorkflowTool.php`, `src/Host/Commands/WorkflowCommand.php`, `src/Cli/Bootstrap.php` (workflowEngine only), `src/Cli/Subcommands.php` (workflow verb only); tests listed per item.

## 1. What was implemented

### Task 1 · Red fork test fixed
`tests/Tools/WorkflowToolForkTest::testEachAgentIsJudgedOnTheToolsItsRequestCarries` now pins the per-agent truth in BOTH declaration orders: dispatch `parallel: 1 forked, 1 inline (agent "shell" may ask: Bash)`, exactly one pid equal to the turn process, two distinct pids. Mixed forked/inline stages are now a pinned fact.

### P1-8 · Resumable Workflow-tool runs

**Run ID.** Every tool run is `WorkflowEngine::runPlan()`: ID `plan-<first 8 hex of sha256(plan YAML)>-<n>`, n = 1, 2, … — the first n with no record and no stored plan, claimed by exclusive create (`fopen x`) of the stored-plan file, so concurrent runs of the same plan never collide. No randomness.

**Files** (all under `<user workflows dir>/.running/`, the dir `/workflow` reads; mode 0600, dir via `PrivateDir`):
- `<runId>.json` — the same crash-safe record a registry run writes (O1 per-stage refresh while running, `pid`/`pidStartedAt` stamp), plus `planRun: true` and `planStageHashes` (see below).
- `<runId>.plan` — JSON `{runId, plan, context, storedAt}`: the plan YAML and the seed context. Not `.json`, so the record globs never see it.
- `<runId>.stages/<n>-<stage>.txt` — captures (see P0-4).

**Kept at every terminal.** A registry run withdraws its record on completed/failed/cancelled; a plan run REWRITES it as a clean record stamped with how it ended: `status` = `completed` | `failed` | `cancelled` | `paused` (budget stop / pause). `/workflow status <runId>` therefore answers `completed` etc. and shows the C14 stage ledger.

**Retention (decision).** The newest `WorkflowEngine::PLAN_RUN_RETENTION` = **20** plan runs are kept, by the stored plan's mtime (every start and resume refreshes it). When a new plan run starts, older runs' record, stored plan AND capture directory are deleted. A run whose writer is still live in another process is never pruned. Completed runs stay resumable (same plan + same context → 100% carried).

**`resumeFrom: <runId>`** (tool input, string) → `WorkflowEngine::resumePlan($runId, $workflow, $planYaml, ?$context, …, $budgetTokens)`.
- **Prefix rule (decision: a changed stage invalidates every later stage).** Walk the plan's stages in order against the record's settled rows (successes and `when:` skips, up to the first failure). Stage i is CARRIED (row, output and context reused; nothing dispatched; no spend) while it has the same name as row i AND the same whole-declaration hash. The first stage that differs, never settled, or failed — and EVERY stage after it, even unchanged ones — run live (their inputs may be the changed stage's outputs).
- **Identity = whole declaration** (`planStageHash`: sha256 of the JSON-encoded stage array incl. every task key, `when`, `loop`, `from`, `capture`, …; `announce` and `cache` excluded as presentation/opt-in). Deliberately STRICTER than the `cache:` door's `stageInputHash` (prompt/model/schema/preset/effort only), because a plan author edits any key between calls. Recorded for every settled success AND skip of a plan run (`planStageHashes` in the record). `stageInputHashes` is unchanged.
- **Context.** Omitted `context` reuses the stored one. A `context` that differs (canonical JSON, key-order-insensitive) from the stored one carries NOTHING (the hash cannot see what `{{key}}` renders to); the run starts from stage 1 under the same runId.
- The run keeps its runId; the record and stored plan are rewritten (so a later TUI resume runs the latest plan). Re-run stages re-expand `from:` fan-outs (only carried stages' wave lists are kept); `agentsDispatched` restarts at 0.
- **Spend:** the resumed result's totals (and any budget) count only THIS call's live spend; carried stages cost nothing. `WorkflowResult::$stagesCarried` (new trailing field, default 0, `withStagesCarried()`) says how many leading stages were carried.
- **Refusals** (`WorkflowNotRunningException`, nothing dispatched; the tool returns `Error: the plan was not resumed: <message>` with isError):
  - not `plan-…` / contains `/`, `..`, NUL: `'<id>' is not a Workflow-tool run ID (they read plan-<8 hex>-<n>); nothing was dispatched`
  - a non-plan record under that name: `'<id>' is a saved workflow's run, not a Workflow-tool run; resume it with /workflow resume. Nothing was dispatched`
  - no stored plan: `No Workflow-tool run found with ID '<id>' (no stored plan under <dir>); nothing was dispatched`
  - writer live elsewhere: `Workflow '<id>' is live in another process (pid N), so its record cannot be resumed here; nothing was dispatched`
  - non-string `resumeFrom` (tool): `Error: "resumeFrom" must be a runId string an earlier Workflow call returned.`
- A stored plan with no record (e.g. the run was refused at pre-flight) resumes as a fresh run under that ID.

**TUI/host `/workflow resume <runId>`**: `WorkflowEngine::resume()` sees `planRun: true` and delegates to `resumePlan()` with the STORED plan and context (prefix rule; same plan ⇒ continues after the settled prefix). The record is kept, not consumed. `/workflow status <runId>` works unchanged (record + ledger). `/workflow pause` of a finished plan run writes a paused plan record.

**Tool result.** JSON gains `runId` (replaces D1's null seam), `carried` (int), `budget` (`{tokens, spent}` or null), `announcements` (list), and per-row `carried: true` for carried stages. Headline keeps its `Workflow '<name>' <status>: X of N stages succeeded, T tokens[ of a B-token budget], $C.` lead, then ` K stages carried from the record at no cost.` (when K>0) and ` Run ID plan-…-n (resumeFrom).`; a paused run adds the line `The run is paused; call again with resumeFrom: <runId> (and a larger budget when a budget stopped it).`

Engine API (public): `runPlan()`, `resumePlan()`, `runRecordDirectory()`, consts `PLAN_RUN_RETENTION`, `PLAN_RUN_PREFIX`, `PLAN_SOURCE`.

Tests: `tests/Workflows/WorkflowPlanRunRecordTest.php` (9: id+kept record+stored plan+n increments, full carry at zero cost, edited stage + downstream re-run, failed run kept and resumed through `resume()`, changed context carries nothing, `when:` skip carried, unknown/foreign/malformed refusals, retention prune incl. captures, budget pause kept and resumed); `tests/Tools/WorkflowToolResumeTest.php` (7, below); `tests/Host/Commands/WorkflowCommandToolRunTest.php` (status of a tool run's record).

### P0-4 · Default capture on the tool path (decision: REPORT-side capture)
Every tool-run stage with output gets a capture file at the engine's own path rule, `<runId>.stages/<index>-<stage>.txt` (index 0-based = stage position), written by the tool after the run; the row's `capture` key names it and the elision marker reads `[… N bytes elided; the full output is in <path> …]`. **Why not `capture: true` on every stage:** the engine's S3 capture replaces `{{stage.output}}` for later stages with `[output captured to <path>]` + a 2 KiB preview, which would silently break `when: {stage: x, equals: PASS}` and truncate downstream prompts. The report-side capture leaves the run's context byte-identical; an explicit `capture: true` keeps its S3 meaning (its file is reused). If a capture write fails the row has no `capture` and the marker says `its capture file could not be written; re-run with capture: true …`. Carried stages are re-captured from their recorded rows. Tests: `WorkflowToolResumeTest::testEveryStageIsCapturedWithoutChangingWhatLaterStagesRead`; `WorkflowToolTest` / `WorkflowToolLintAndResultTest` updated (row keys now `…, cost, capture, output`).

### P1-7 · Tool `budget`
Input `budget: integer >= 1` (schema `minimum: 1`) → `budgetTokens`; the run's ceiling is `min(budget, config.maxTokens)` (C4's `tighterCeiling`). Non-int / <1: `Error: "budget" must be a whole number of tokens, at least 1.` The headline says `T tokens of a B-token budget` (B = the effective ceiling, also when only `config.maxTokens` is set) and JSON `budget: {tokens: B, spent: T}`. A budget stop returns `status: paused` with the record kept; resume with `resumeFrom` + a larger `budget` (budget counts only that call's spend). Test: `WorkflowToolResumeTest::testTheBudgetInputCapsTheRunAndTheHeadlineReportsSpendAgainstIt`.

### P2-2 · Narration
- **Tool:** `WorkflowNarration` (new, `src/Workflows/WorkflowNarration.php`) is installed as the engine's announcement sink. It emits `SubAgentActivity` beats on the turn's delegation emitter (`withEngine(…, $subAgentEmitter)` — the tool now keeps it): `started` (name `workflow`, task = workflow name, description `Workflow '<name>'`, parentCallId = the tool call id, id `workflow-<pid>-<callId>`), one `progress` beat per announcement carrying it as an `ActivityItem::text` (the live line under the Workflow row shows `"<line>"`), and `finished` with outcome complete / failed / cancelled / empty(paused) and the first failure as `error`. A throwing emitter is swallowed. Announcements also come back as `announcements: […]` in the JSON. (Not `AcceptsToolProgress`: that arm in `EngineBackend` `continue`s before the `DelegatesToEngine` bind, so the tool would lose its engine.)
- **`/workflow run|resume` (TUI + host):** `WorkflowCommand::describeWorkflowResult()` appends `\n\nAnnouncements:\n- <line>…` when the run announced anything (report unchanged otherwise). Lines land with the report, not live (see §2).
- **Headless verb:** text mode prints `  announce: <line>` under the stage rows (stdout, like B2's `lint warning:`); JSON adds `result.announcements` only when non-empty (document otherwise unchanged).
- Tests: `WorkflowToolResumeTest::testAnnouncementsStreamOnTheLiveLineAndRideTheResult`, `WorkflowCommandToolRunTest::testTheReportListsTheRunsAnnouncements`, `WorkflowSubcommandTest::testAnnouncementsRideTheReportInBothFormats`.

### P1-6 cross-track · Presets on every path
- Engine: `setAgentRosterSource(?\Closure)` — a DEFERRED roster, called once the first time a preset must be resolved or named (a throwing source = no roster); `presetNames(): ?list<string>` (sorted; null = no roster at all); static `presetsNamedBy(Workflow): list<string>` (every `preset:` at any depth). `setAgentRoster()` still wins and clears the source.
- `Bootstrap::workflowEngine()` sets the source to `Bootstrap::agentRoster($root ?? getcwd(), $provider, $model)` — built only when a plan names a preset; Chat's manager remains the fallback.
- Tool: source = `withAgentRoster(\Closure)` or `Bootstrap::agentRoster(root, provider name, model)`.
- Every lint caller passes `$presetNames` (only when the plan names a preset): the tool, `/workflow run` pre-run lint and `/workflow lint` (session `/workflow` engine's names, else the AgentManager's), and the headless verb (built from `Bootstrap::agentRoster`). Unknown preset ⇒ lint error ⇒ refused, nothing dispatched (headless: exit 1, `status: refused`).
- Tests: `tests/Workflows/WorkflowEnginePresetRosterTest.php` (4), `WorkflowToolResumeTest::testPresetsResolveAgainstTheSessionRosterAndLintNamesTheKnownOnes`, `WorkflowCommandToolRunTest::testLintChecksPresetsAgainstTheSessionEnginesRoster`, `WorkflowSubcommandTest::testAnUnknownPresetIsALintErrorBeforeAnyDispatch`.

### E1 cross-track · Generated `plan` schema description
`WorkflowGrammarGlossary::planDescription()` builds it from `Workflow::STAGE_TYPES` (new `STAGE_SHAPES` spelling per type, incl. `type: map` + `steps:`), the stage-key roster minus `SHAPE_STAGE_KEYS` (modifiers: `when`, `loop`, `capture`, `cache`, `minSuccess`, `announce`) and every `YAML_TASK_KEYS` entry. Drift test additions in `WorkflowToolDescriptionDriftTest` (STAGE_SHAPES set-equal to STAGE_TYPES; every modifier and task key named; `budget`/`resumeFrom` in the schema). The tool description now explains `budget`, `runId`/`resumeFrom` and the extended result shape.

### Inventory
`tests/Cli/ProjectTierRefusalInventoryTest`: row `Cli/Subcommands.php|.candy-code/workflows => REPOSITORY` (B2's headless lint registry mirror), doc-block figures 94→95 occurrences / 36→37 repository-chosen, word map extended. The tool no longer spells the `~/.candy-code/workflows` literal (it uses the registry default when no path resolved), so it needs no row.

## 2. Not done / decisions
- **No live streaming for TUI `/workflow run` or the headless verb.** TUI: the run's fiber cannot push transcript rows mid-run (the report is its only channel), so announcements land in the report. Headless: a live stderr line needs a new `fwrite(STDERR, …)` site, pinned by `tests/Cli/StderrEmitterCensusTest` (not my file) — printed on stdout with the report instead.
- `data` over budget → `{capture, preview}` not done (`data` is still never clipped; the capture file holds it anyway).
- Tool `plan` stays required on resume (no "omit plan to reuse the stored one" on the tool path; `/workflow resume` uses the stored plan).
- Report-side capture replaces "capture: true by default" (rationale above).

## 3. Cross-track requests
1. **`tests/Support/ReadPathCensusTest.php` owner:** `READ_PATHS` needs, for `Workflows/WorkflowEngine.php`: `file_get_contents` 3→4 (add `'SELF_LOCATED — readOwnJson(): a plan run\'s record or stored plan this engine wrote under .running/'`), `glob` 1→2 (`'SELF_LOCATED — prunePlanRuns(): its own .running/plan-*.plan names, for retention'`), `scandir` 1→2 (`'NAMES_ONLY — removeCaptureDir(): a pruned plan run\'s own <runId>.stages/ capture names, unlinked'`), new `fopen` => 1 (`'SELF_LOCATED — claimPlanRunId(): exclusive create (x) of its own .running/<runId>.plan; nothing is read'`). (Also still red on master for `Workflows/CpuCount.php|file_get_contents => 2`, P0-3's.)
2. **Docs agent (`docs/WORKFLOWS.md` § Workflow tool + CLI + `/workflow`):** document §1 above: runId format, record/plan/capture files, retention 20, `resumeFrom` prefix rule + whole-declaration identity + context rule + zero-cost carried spend, refusal texts, `budget`, headline/JSON shape (`runId`, `carried`, `budget`, `announcements`, row `carried`, `capture` on every stage), report-side capture vs `capture: true`, narration on the live line, `Announcements:` block, headless `announce:` lines / `result.announcements`, `/workflow status|resume <runId>` for tool runs, presets resolved on every path. Replace "a plan has no registry name, so runWorkflow() writes no pause file" (WORKFLOWS.md ~1161).
3. **suite-figure / `scripts/parallel-tests-durations.tsv`:** new test files `tests/Workflows/WorkflowPlanRunRecordTest.php`, `tests/Workflows/WorkflowEnginePresetRosterTest.php`, `tests/Tools/WorkflowToolResumeTest.php`, `tests/Host/Commands/WorkflowCommandToolRunTest.php`; tests added to `WorkflowSubcommandTest` (+2), `WorkflowToolDescriptionDriftTest` (+2).
4. **Skill (`workflow-authoring`, E2):** §13's "Capture files live under `<runId>.stages/…`" is now true for every tool stage; mention retention (20 runs) and that a changed `context` carries nothing.

## 4. Commits
- `c5af41bec` fork test pins the mixed forked/inline stage (C5 task 1)
- `c001dfa8a` engine: plan runs leave a resumable record, resumePlan prefix rule, retention (P1-8)
- `73e8a2baa` Workflow tool: runId/resumeFrom, report-side capture, budget, narration, preset roster, generated plan schema (P1-8 P0-4 P1-7 P2-2 P1-6)
- `389fa6e46` /workflow + headless: announcements, preset-aware lint, Bootstrap deferred roster, inventory row (P2-2 P1-6 P1-8)
