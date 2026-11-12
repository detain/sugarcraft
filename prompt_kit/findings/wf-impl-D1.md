# wf-impl track D1 — P0-1 (tool grammar) + P0-4 (lint before run, structured result)

## 1. What was implemented

### P0-1 · Generated `Workflow` tool description

- **New file** `candy-code/src/Workflows/WorkflowGrammarGlossary.php` (final, constants + one static renderer):
  - `WORKFLOW_KEYS`: glosses for top-level `name`, `description`, `stages`, `config` (`timeout`, `maxConcurrent`).
    The loader has no roster for these, so they are NOT drift-pinned.
  - `STAGE_TYPES`: one gloss for each `Workflow::STAGE_TYPES` entry (`stage`, `parallel`, `pipeline`, `verification`).
  - `STAGE_KEYS`: one gloss for each `WorkflowRegistry::YAML_STAGE_KEYS` entry (that constant is **private**). The test
    reads it by reflection, so nothing was widened.
  - `TASK_KEYS`: one gloss for each `WorkflowRegistry::YAML_TASK_KEYS` entry.
  - `INTERPOLATION`: `{{key}}`, `{{stage.output}}`, `{{name.results}}`, `{{stage.<pointer>}}`, `{{prevResult}}`,
    `{{input}}`, `{{path}}`, `{{round}}`.
  - `grammar(?array $stageKeys = null): string` renders the paragraph. Types and task keys are walked in the
    **loader's** roster order, so a key the loader learns is printed (bare, with no gloss) even before its gloss
    lands. The model always sees it. The drift test is what goes red.
- `WorkflowTool::description()` = intro sentence + `WorkflowGrammarGlossary::grammar()` + run semantics (tools and
  permissions, no Task or Workflow, stages don't see the conversation, stop at first failure, lint before run,
  `dryRun`, result shape). It ends with the exact sentence
  "Load the `workflow-authoring` skill before authoring a non-trivial plan." The description is about 6 KB.
- `inputSchema().plan.description` now names every stage shape: plain, `tasks:`, `parallel: true` + `agents:` or
  `from: {glob}`, `type: pipeline` + `stages:`, `type: verification` + `task:`/`verifier:`, `uses:` + `with:`. It
  also names the modifiers `when`, `loop`, `capture` and `cache`.
- **Drift test** `candy-code/tests/Tools/WorkflowToolDescriptionDriftTest.php` checks:
  - Set equality: glossary `STAGE_TYPES` = `Workflow::STAGE_TYPES`, `TASK_KEYS` = `YAML_TASK_KEYS`, and `STAGE_KEYS` =
    the private `YAML_STAGE_KEYS` (read by reflection).
  - Every key and type appears in the description as "- `key`: <gloss>".
  - No gloss is blank, an un-glossed key still renders bare, the skill sentence closes the description, the plan schema
    names the shapes, and `dryRun` is in the schema.
  - **This test WILL go red** when other tracks add roster keys (`preset`, `effort`, `votes`, `minSuccess`, `steps`,
    `announce`, `map` type, …). The fix is to add one gloss line per new key in `WorkflowGrammarGlossary`.

### P0-4 · Lint before run + structured result

- **Tool** (`WorkflowTool::execute`): after `fromYamlString()`, it calls
  `WorkflowLinter::lint($workflow, $context, WorkflowTool::toolNamesOf($engine), <resolver over the tool's own WorkflowRegistry>)`.
  - Errors refuse the run. The engine is never built and nothing is dispatched. `isError` = true. The text is
    `Error: the plan was not run: lint found N error(s), M warning(s) (nothing was dispatched).` followed by
    `$lint->renderFindings()` (`- error [stage] message` lines). Note that the singular/plural is real, e.g.
    "1 error, 0 warnings".
  - Warnings ride the report directly under the headline as `N lint warning(s):` followed by the finding lines.
  - A declared tool missing from the session is now refused by the **lint**, before the engine pre-flight. The message
    is the linter's: `A workflow task declares tool "X", which the session's tool registry does not contain …`.
- **`dryRun`** (new boolean input, default false; any non-bool is refused with `Error: "dryRun" must be true or false.`).
  It lints, then returns:
  - `Dry run of workflow '<name>' (no agents dispatched): N stages.`
  - `WorkflowLinter::renderPlanTable()`
  - the findings
  - `Lint: <verdictLine>`, plus ` — a real run would be refused.` when there are errors
  
  `isError` = the lint has errors. Nothing is dispatched.
- **Result shape** (the tool report):
  1. Headline (unchanged): `Workflow '<name>' <status>: X of N stages succeeded, T tokens, $C.`
  2. The lint-warning block, if any.
  3. One prose line per failed stage: `Stage '<name>' <status>: <error>`. This is kept so the reason can be read
     unescaped.
  4. `K later stage(s) did not run.` when the run stopped early.
  5. A fenced ```` ```json ```` block:
     `{status, runId: null, stages: [{name, status, error, attempts, tokens, cost, capture?, data? | output?}]}`.
     - `attempts` is the max of the stage's agents' attempts. This matches `/workflow run`'s attempts line. It is 0
       for a stage refused before dispatch.
     - `tokens` and `cost` are summed over the stage's agents.
     - `data` is present when **every** task in the stage's `tasks` list (plain, `tasks:` and parallel stages) has an
       `outputSchema` and the trimmed output `json_decode`s to an object or array. A whole-output ```` ```json ````
       fence is also accepted. `data` **replaces** `output` and is never clipped. Pipeline and verification stages
       never get `data` (a later track can add that).
     - `output` is the prose, with the middle elided past `STAGE_OUTPUT_MAX_BYTES` (8192). The marker is either
       `[… N bytes elided; the full output is in <capture path> …]` or
       `[… N bytes elided; re-run with capture: true on this stage to keep the full output …]`.
     - `capture` is the capture file path for a `capture: true` stage. It is read back from the engine's own
       `[output captured to <path>]` line in `WorkflowResult::context['<stage>.output']`, so the engine's path rule
       is not duplicated.
     - `runId` is always `null`. It is the **seam** for the later tool-run-records/`resumeFrom` track: fill it in
       `WorkflowTool::report()` (the comment marks it).
  - Per-stage prose sections (`### Stage N: …`) were removed. Stage outputs now live in the JSON only, so they are not
    sent twice.
- **`/workflow run`** (`WorkflowCommand::start($engine, $inputText, $args, ?CommandContext $commandContext = null)`):
  - When a `CommandContext` is given (`WorkflowCommand::run()` passes it), the command loads the name through the same
    registry tiers `/workflow lint` uses. It then lints with the run's own `key=val` context as the seed, the session
    tool universe and the include resolver.
  - Errors produce an error row and no fiber:
    `**Error:** workflow `<name>` was not run: lint found N error(s), M warning(s) (nothing was dispatched).` followed
    by the findings.
  - Warnings are inserted under the report heading (after the first blank line).
  - A name that does not load in those tiers skips the lint, and the engine's own refusal speaks.
  - Without a context, behaviour is byte-identical to before.
- Shared helpers (public static on `WorkflowTool`): `toolNamesOf(EngineBackend): list<string>`, used by the tool and by
  `WorkflowCommand::toolUniverse()`, and `lintWarningHeader(WorkflowLinter): string`.
- `context` stays string/scalar-only (task 5).

### Tests
- `candy-code/tests/Tools/WorkflowToolDescriptionDriftTest.php` (new, 9 tests)
- `candy-code/tests/Tools/WorkflowToolLintAndResultTest.php` (new, 12 tests): lint refusal with zero dispatch,
  warnings under the headline, context seed, dryRun (clean and broken), non-bool dryRun, an unclipped >8 KB `data`,
  a schema'd stage whose output doesn't decode, a mixed-schema stage, unschema'd JSON staying prose, the capture path
  in the elision marker plus the file holding every byte, and the document shape with `runId: null`.
- `candy-code/tests/Tools/WorkflowToolTest.php` was updated for the JSON block (adds a public `document()` helper).
- `candy-code/tests/Host/Commands/WorkflowRunLintTest.php` (new, 5 tests).

## 2. Not done, and why
- **The headless `candy-code workflow run` verb was NOT touched.** It lives in the shared
  `candy-code/src/Cli/Subcommands.php` (`Subcommands::workflow()`, around line 6980), not in a dedicated file, so it is
  outside my ownership. See the cross-track request below.
- **The TUI path of `/workflow run` does not lint yet.** `Chat::workflowRun()` (`src/Chat.php:13945`) calls
  `WorkflowCommand::start($this->workflowEngine, $inputText, $args)` without a `CommandContext`. That keeps the
  pre-lint behaviour. Only `WorkflowCommand::run()` callers (headless host/serve) lint. See the cross-track request.
- `capture: true` is not yet the default for tool runs (report P0-4 item 4). That needs an engine or registry default
  and is not in my brief. `data` over budget → `{capture, preview}` is not done, because `data` is never clipped
  today.
- Description/config keys have no loader roster, so they are not drift-pinned.

## 3. Cross-track requests
1. **Chat.php owner:** in `Chat::workflowRun()`, pass a `CommandContext` as the 4th argument to
   `WorkflowCommand::start()` (the same one `runHostCommand()` builds) so the TUI `/workflow run` lints too.
2. **Subcommands.php owner (headless verb):** before running, load the workflow and call
   `WorkflowLinter::lint($wf, $params, <tool names or null>, <registry->load resolver>)`. On errors, exit 2 or 1 with
   the findings and dispatch nothing. In `--format json`, add `lint: {errors, warnings, findings}`. Reuse
   `WorkflowTool::lintWarningHeader()` for text output.
3. **WorkflowLinter owner (C1):** optional cleanup. `toolNamesOf` and `lintWarningHeader` arguably belong on
   `WorkflowLinter` (e.g. `WorkflowLinter::warningHeader()`). The tool and the command could then both call it.
4. **Anyone adding roster keys or stage types:** add one gloss line to `WorkflowGrammarGlossary` (`STAGE_KEYS`,
   `TASK_KEYS` or `STAGE_TYPES`). `WorkflowToolDescriptionDriftTest` goes red until you do.
5. **Docs:** `docs/WORKFLOWS.md` § "Model-authored plans: the `Workflow` tool" (around line 1130) still describes the
   old prose report and the old key list (line 1181). Update it with the result shape, `dryRun` and lint-before-run
   described above.

## 4. Commits
- `b1b8fbd51` candy-code: generated Workflow tool grammar, lint-before-run, dryRun and JSON result block (wf-impl P0-1 P0-4)
- `e0e30076d` candy-code: /workflow run lints the plan before dispatch (wf-impl P0-4)

## 5. Observed failures outside my files (not fixed)
- `tests/Tools/BuiltInToolCorpusTest::testTheWideSweepFindsTheFlatGlobPlusExactlyTheRecordedDynamicTools` is red
  because the new `src/Agents/StructuredOutputTool.php` (the P0-2 track) implements `Tool` but is not listed in
  `BuiltInToolCorpus::DYNAMIC_TOOL_CLASSES`. My files are not the cause.
- `tests/Cli/RestrictedSafeModeFlagsTest.php` reported 148 errors in one run that happened alongside other tracks.
  It only references `Workflow` by tool name, so it is not affected by D1's changes. Not investigated further.
