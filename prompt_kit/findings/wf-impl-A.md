# wf-impl track A — P2-3 docs/examples truth pass

Scope: `candy-code/docs/WORKFLOWS.md`, `candy-code/examples/workflows/*.yaml` (4 files),
`candy-code/workflows/deep-research.php`. No behaviour change; current grammar only (no
map/votes/minSuccess/from.stage/empty/pointer keys used or documented).

## 1. What changed (per P2-3 row)

### WORKFLOWS.md
- **"`timeout` and `reject` have no YAML spelling"** (The PHP form): rewritten. Every `TaskBuilder`
  method now listed (adds `input()`), each has a YAML spelling (`YAML_TASK_KEYS`; `agent:`/`type:`
  for `agent()`; `reject:` + `rejectMinOccurrences:` for `reject($p, atLeast)`). Added the
  stage-level PHP spellings: `capture`/`cache` named args of
  `stage()/parallel()/pipeline()/withVerification()/uses()`, `when()`/`loop()` attach to the last
  stage. The `reject:` "(or `task:`) map" parenthetical now says it parses on every task surface
  and pre-flight refuses it off the verifier. Added a pointer from `input()` to the join section.
- **`stageInputHashes` "nothing acts on it / future C13"**: now says the opt-in `cache:` door
  compares it on resume; recipe corrected (stage type + prompt templates + model + schema; a
  `uses:` stage hashes included name + `with:` templates).
- **0-based vs 1-based stage numbers**: did not change code (not owned). Prose now states the
  truth per surface: loader `stage #N` 0-based; linter `Stage #N`/`stage #N` and the Workflow tool
  report `Stage N:` 1-based.
- **Limit 1 `tasks:` key list** was missing timeout/reject/rejectMinOccurrences/stallTimeout/
  restarts/input; now lists all of `YAML_TASK_KEYS`, and names `capture`/`cache`/`when`/`loop` as
  stage keys.
- **"review→fix→re-review loop an engine feature"** (reject section) was misleading: verdict
  retries re-dispatch a fresh VERIFIER against the same task result; the fixer never re-runs
  (verified in `WorkflowEngine::executeVerificationStage`). Prose now says "gate", explains resume
  re-runs the whole stage, and points to `loop:` over a `tasks:` list + a verification gate
  (review-loop.yaml) for an in-run fix loop.
- **`/workflow lint` error/warning roster** completed from `WorkflowLinter` (added `.output` refs,
  duplicate result keys, unregistered tools, uses-chain cycle/depth; warnings: unnamed stage,
  empty plan, shared-checkout parallel stage, unsatisfiable `rejectMinOccurrences`).
- **New paragraph** under the `/workflow` command list: `/workflow run` pair parsing is
  quote-aware (`key="two words"`), and malformed pairs (no `=`, empty key, `@` key, unterminated
  quote) are refused before dispatch (`WorkflowCommand::parseRunArguments`).

### Examples (all load; `WorkflowLinter::lint()` = 0 errors each)
- All headers: removed "run-context values are whitespace-split, no value may contain a space";
  replaced with the quoting spelling. Removed "nothing in this grammar can gate" / "the loop lives
  with the DRIVER" / "WORKFLOWS.md is the source of truth for what the grammar can gate".
- Hyphenated agent names snake_cased (`style_fixer`, `shard_one`, `readme_table_fixer`, …).
- `{{parallelStage.output}}` readers switched to `input: {from: [...], as: all}` + `{{input}}`
  (see linter bug below — also the better pattern).
- **review-loop.yaml** rewritten: `build` → `fix` (plain stage, `tasks: [verdict(reviewer),
  address(coder)]`, `loop: {max: 3, until: {stage: verdict, matches: '/^VERDICT: APPROVE[ \t]*$/m'}}`
  — the clause names the TASK `verdict`, which no stage is called, so StageCondition reads the
  results map, overwritten each round) → `signoff` (`type: verification`, task `summary`,
  verifier `final_review` with `retries: 1`, an `ESCALATE:` instruction, and a fail-closed
  `reject: '/\A(?![\s\S]*^VERDICT: APPROVE[ \t]*$)/m'` that rejects any reply lacking an APPROVE
  line). Honest caveat in header: `address` runs in the approving round too (no per-task `when:`),
  its prompt no-ops there.
- **lint-then-fix.yaml**: lint lines now `category | file:line | finding`; `fix` gated by
  `when: {stage: lint, matches: '/^(style|correctness) \|/m'}`; `verify` joins `[lint, fix]`.
- **docs-drift-sweep.yaml**: `fix` and `recheck` gated by the same `when:` on audit tags;
  `recheck` joins `[fix]`. Header says honestly why the re-sweep is still the driver's call:
  `loop:` refuses parallel stages (and `uses:`) in v1.
- **fix-wave.yaml**: keeps static shards; comments now say why (`from:` fans out a glob, not a
  context list), that shard outputSchema is not guaranteed (tools-before-answer), and that
  `when:` exists but this pass has nothing to gate.

### workflows/deep-research.php
- Task names snake_case (`research_planner`, `docs_explorer`, …, `report_writer`).
- `investigate` → `->parallel('investigate', [...], capture: true)`.
- `synthesize` → `->input(['from' => ['investigate'], 'as' => 'all'])`, emits `GAP: ` lines.
- `refine` (nothing looped on it) REMOVED; replaced by `follow_up` (one researcher,
  `->when(['stage' => 'synthesize', 'matches' => '/^GAP: /m'])`) — a single bounded round,
  docblock explains `loop:` cannot repeat a parallel stage.
- `report` → `->input(['from' => ['plan','investigate','synthesize','follow_up'], 'as' => 'all'])`,
  tools `['Read']` only (no Write/Edit). `DeepResearchWorkflowTest` still green.

## 2. Not done
- 1-based loader error numbering: code change in `WorkflowRegistry.php` (not owned); doc made truthful instead.
- Drift-guard tests (key-roster-in-docs, examples-lint-clean): not in my file list. See requests.

## 3. Cross-track requests
- **WorkflowLinter bug (false ERROR):** `checkInterpolation()`/`collectProducers()` record `.output`
  producers only for `stage` and pipeline steps, and say "parallel and verification stages never
  write `.output`". False: `WorkflowEngine` writes `$context[<stage>.output]` for EVERY stage type
  after settle (WorkflowEngine.php ~2158; parallel output = agents joined by "\n", verification =
  combined task+verifier). Every original example tripped it. Fix: note `.output` for all stage
  types (incl. `uses:`), and fix the message.
- **WorkflowLinter false WARNINGs:** `{{input}}` (task carries `input:`), `{{round}}` (stage has
  `loop:`), `{{prevResult}}` (tasks-list task after the first, pipeline step, verifier), `{{path}}`
  (`from:` stage) are engine-injected but warn as unknown tokens.
- **WorkflowEngine::stageInputHash() docblock** still says "THIS engine only records the hash —
  no caching, no opt-in"; `cache:` consumes it now.
- **Drift test**: add `ExampleWorkflowsLintTest` (each example + deep-research lints 0 errors) once
  the linter `.output` fix lands.
- `DocFigureProseDriftTest::testWorkflowDefaultFiguresSurviveTheirSignatures` currently fails on
  `src/Workflows/Workflow.php`'s docblock (uncommitted edit by another track, maxConcurrent
  default) — not caused by track A.

## 4. Commits
- 905d83be0 — docs/examples truth pass (wf-impl P2-3)
