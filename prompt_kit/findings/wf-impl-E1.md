# wf-impl track E1 — docs truth pass for everything landed (P0-1 … P2-4)

Scope: `candy-code/docs/WORKFLOWS.md` + the workflow passages of `docs/HOOKS.md`,
`docs/PERMISSIONS.md`, `docs/AGENTS.md`, `docs/FLEET-DRIVING.md`, `docs/ARCHITECTURE.md`,
`candy-code/README.md`; one mechanical re-pin in `tests/Config/DocFigureProseDriftTest.php`.
Every claim was checked against `src/` at HEAD (notes were out of date in places: B3's
`forked (N ask-free agents)` wording, 373458056's scoped-grant judgement, the panel dispatch site).

## 1. What changed

### WORKFLOWS.md
- YAML schema block: `maxConcurrent` default comment, `maxAgents`, a top-level `schemas:` example.
- Defaults sentence rewritten (unset = default width, never "no cap" unless `0`; `maxAgents: 1000`).
- NEW § "Width, the fan-out ceiling and the agent budget" (P0-3): `min(16, max(1, CPUs-2))`,
  CpuCount detection order + fallback 4, `0` = no cap + lint warning, 4096 ceiling + error text,
  `config.maxAgents` counting rules (waves reserve up front, retries counted at settle, `uses:`
  shares outer ceiling, resume continues `agentsDispatched`).
- `when:`: `empty:` op (exact, untrimmed), `pointer:` leaves (typed row, string-form compare,
  fail-closed misses); map-step `when:` exception.
- `loop:`: roster `max/until/accumulate`, `{{previous}}`, token shadowing; "stage-only" paragraph
  replaced by "What loops" (plain/parallel/verification/map; pipeline + uses refused; looped
  `from:` replays first round's list); NEW `accumulate:` subsection (contributors per stage type,
  identity/dedup, `{{seen}}`/`{{fresh}}`, `{"items","keys","rounds"}` shape, not persisted);
  loop-only leaves `{dry: N}` (needs accumulate) and `{budgetBelow: N}` (documented as needing
  `config.maxTokens`, which lands with run budgets — inert today, loop runs to `max`).
- `outputSchema` section rewritten for StructuredOutput (P0-2): validation + error format,
  compact JSON output, non-object wrap/unwrap, exact-document reply accepted, one re-prompt then
  failure message, capability refusal on `supportsFunctionCalling()`, gate/hook bypass by
  instance + rationale, supported vs unenforced keywords, notes to live stream, headless unchanged.
- NEW § `schemas:` (P2-4): naming, object-root law, `$ref` refusals, cache-hash digest, no PHP spelling.
- `type:` spellings include `map`; pipeline-vs-map naming note.
- NEW § Verifier panels (P1-4): votes/lenses/quorum, mechanical failures reject, ESCALATE
  precedence, invalid pattern closed, retries re-run panel, result names, failure text,
  `{{stage.votes}}`, placement pre-flight.
- `from:` section rewritten (P1-1): three sources, `key`/`chunk`/`max` (+ output format),
  item tokens (0-based `{{index}}`, spliced last), loud failures, empty-data-completes,
  pause-record replay incl. data records.
- NEW § `minSuccess` (P1-3) incl. `{{stage.failed}}`, stopOnFirstFailure exemption.
- NEW § `type: map` (P1-2): shape, steps, item scope, step `when`, skip passes prevResult,
  failure drops item, output document, budget/keys, loop/cache, PHP builder; "Four limits"
  (whole-stage resume, no per-step hooks, width counts chains, inline executors serialise).
- Refusal list: every new key (maxAgents, schemas/$ref, when pointer/empty/loop leaves, loop
  accumulate/dry/attachment, from sources, minSuccess, votes/lenses/quorum, map/steps); map steps
  added to the unknown-key surfaces; stage-numbering note now says 1-based everywhere (P2-3).
- Interpolation: table gains `.failed`/`.votes`, `.output` for every stage type, array context →
  JSON; NEW engine-token table (prevResult, input, path, item, index, round, previous, seen,
  fresh, lens); result-name table gains fan-out items, panel votes, map steps/stage.
- `/workflow lint` roster updated to the linter's new mirrors; NEW "`/workflow run` lints first"
  (TUI + host). Granularity line mentions map. Cache section: from/loop/step-when not hashed.
- Headless: lint step, exit-1 row, NEW "Lint before dispatch" subsection, `result.lint`.
- Stage lifecycle events: map one-per-round, lint-refused plans announce nothing.
- `Workflow` tool section rewritten (P0-1/P0-4/P2-1): generated description, lint-before-run,
  `dryRun`, any-JSON `context` + error lines, forking rule ("could not ask", wouldAsk, scoped
  grants, first-task-grant limit, hook-ask fail-closed, liveness/teardown/check-child-lifetimes),
  JSON result block (`data`/`output` elision markers/`capture`/`dispatch` strings/`runId` seam).
- Three limits: task-key list + `minSuccess`; map pointer; panel keys under limit 3.
- PHP form: votes/lenses/quorum, map/mapStep/mapVerification, minSuccess, maxAgents, from shapes;
  outputSchema → StructuredOutput. ESCALATE section: panel precedence.
- "round 1/2" phrasing avoided (DanglingPlanCitationTest reads it as a campaign ID).

### Other docs
- PERMISSIONS.md: NEW § "Could a declaration ask? `wouldAsk()`" (judgement order, reasons,
  blind spots); StructuredOutput exemption paragraph under "A sub-agent's mode".
- HOOKS.md: map has no per-item events; lint-refused plans raise no WorkflowComplete;
  StructuredOutput bypasses PreToolUse; forked Workflow-tool agents fail closed on hook asks.
- AGENTS.md: Workflows paragraph (from:, map, panels, lint, StructuredOutput).
- FLEET-DRIVING.md: stages don't use `json_schema_applied`; verb lints (exit 1 refused + lint).
- ARCHITECTURE.md: StructuredOutputTool outside the catalog; ForksAskFreeAgents fork path.
- README.md: Workflows bullet (fan-out sources, map, panels, StructuredOutput, width, maxAgents,
  lint); worktree-isolation limitation corrected (stages DO honour `isolation: worktree`);
  CLI cheat-sheet lint note.
- GLOSSARY.md: has no workflow passages; nothing to change.

### Test re-pin (tests/Config, mechanical)
`DocFigureProseDriftTest::testWorkflowDefaultFiguresSurviveTheirSignatures`: docblock regex →
`0 is the explicit "no cap"`; WORKFLOWS regex → `unset (the default width…)`; dispatch-site
count 5 → 6 (panel votes); `runGuardedFromWorkflow` window 10600 → 20000 and regex admits the
verification arm's `, null, $votes)` (exactly 3 matches tree-wide, measured).

## 2. Test status (targeted)
Green: DocFigureProseDriftTest (103), WorkflowRegistryTest (99), WorkflowSubcommandTest (26),
SymbolCitationDriftTest, DanglingPlanCitationTest, ToolDocDriftTest, all tests/Config doc-drift
tests, HookEnvironmentDocumentationDriftTest, PermissionModeDescriptionTest, every test that reads
HOOKS/PERMISSIONS/ARCHITECTURE/FLEET-DRIVING/AGENTS.md — except:
- `tests/Cli/BootstrapLaunchFormatConstantsTest::testEveryDocPageQuotingAPromotedFormatIsOnTheReadersRoster`
  red ONLY because the untracked `candy-code/workflow-update.md` quotes `candy-code: …` (my
  WORKFLOWS.md hit was reworded away). Resolves when that scratch file leaves candy-code/.
- `tests/Cli/ProjectTierRefusalInventoryTest::testTheDotPathEnumerationIsDerivedFromSrc` red on
  `Cli/Subcommands.php|.candy-code/workflows` (B2's headless lint) — needs an inventory row; not mine.
- `tests/Tools/WorkflowToolDescriptionDriftTest` red on C4's in-flight `preset`/`effort` keys.
- `ReadmeSuiteFigureDriftTest` — suite figure, end-of-run.

## 3. Not done / cross-track requests
- C4 features (preset/effort/maxTokens/maxCost/announce) deliberately undocumented; `budgetBelow`
  carries the one "needs config.maxTokens, lands with run budgets" note — revisit when it lands.
- `WorkflowTool::inputSchema()` `plan.description` (B2/D1 file) still lists stage shapes by hand
  without `type: map` + `steps:` and without `minSuccess` — add them.
- `ProjectTierRefusalInventoryTest` row for Subcommands' `.candy-code/workflows` (owner of tests/Cli).

## 4. Commits
- `f0a9c5b20` docs truth pass + DocFigureProseDriftTest re-pin
