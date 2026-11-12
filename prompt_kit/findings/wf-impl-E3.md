# wf-impl track E3 — docs for what landed after C3 (C4 + C5), the tenth built-in skill, census pins

Scope: `candy-code/docs/{WORKFLOWS,SKILLS,HOOKS,PERMISSIONS,AGENTS,FLEET-DRIVING}.md`,
`candy-code/README.md` (workflow + skills passages), root `docs/_data/candy-code.body.html` →
regenerated `docs/lib/candy-code.html`, `tests/Skills/BuiltInSkillsTest.php`,
`src/Skills/SkillRegistry.php` (MAX_COMPILED_PATTERNS docblock only),
`tests/Support/ReadPathCensusTest.php`. Every sentence was checked against `src/` at HEAD
(fcec32113 + C6 in flight).

## 1. What changed

### WORKFLOWS.md
- YAML schema block: `config.maxTokens`/`maxCost`, task `preset:`/`effort:`, stage `announce:`.
  Defaults sentence (DocFigureProseDriftTest regex kept): no ceiling by default;
  `stopOnFirstFailure` is PHP-builder only; top-level/`config:` keys have NO roster, so a typo
  like `maxToken:` is silently ignored (verified: no top-level roster in WorkflowRegistry).
- NEW § "`config.maxTokens` and `config.maxCost` are hard run budgets": what counts (settled
  results incl. failed attempts, cache-carried rows, prior totals on resume — whole-run spend),
  checked before every dispatch (wave refused whole) and every pool retry, at-or-past refuses,
  refusal texts, in-flight overshoot, stop = PAUSE (record for registry + tool runs; none for
  `runWorkflow()`/`runFromPhp()`), raising via edited YAML or `budgetTokens:` (run/runWorkflow/
  runPlan/resume/resumePlan; tool `budget`), tighter-of rule, same ceiling re-pauses,
  `{{budget.remaining}}`, `budgetBelow`; headless reports a budget stop as status `paused`,
  exit 1 (verified `workflowExitCode`: only escalation → 3).
- Loop-only `{budgetBelow: N}`: E1's "lands with run budgets" note replaced — live, checked
  after each round against spend so far, never holds without a token ceiling (linter warns).
- NEW § "`preset:` runs a task as an agent preset; `effort:` …": every task surface,
  resolution tiers (Bootstrap::agentRoster order, later tier replaces), every door wired,
  loader spelling refusal vs engine pre-flight refusal texts (unknown / no roster), lint error
  text, merge table (tools when none declared, model when absent/inherit, effort/isolation when
  unset, body/disallowedTools/maxTurns always, permissionMode stricter-only), bypass stays
  bypass, skills not carried, identity-hash only when set; effort closed set + the
  provider-ignores note `[effort: X sent, but provider "p" ignores reasoning effort]`.
- NEW § "`announce:` and the run's announcements": settles-only (failed stages too; skip,
  cache carry, resumeFrom carry announce nothing), refusal, not resume identity, the four
  mandatory kinds with their exact lines, `WorkflowResult::$announcements`,
  `setAnnouncementSink` meta, not hook events, per-surface display (tool live line + JSON;
  `/workflow` `Announcements:` block after the run; headless `  announce:` / `result.announcements`).
- Refusal list: maxTokens/maxCost, preset/effort, announce. Lint roster: preset (blank/unknown),
  blank announce, hand-built maxTokens/maxCost errors; budgetBelow / `{{budget.remaining}}`
  warnings. `/workflow run` + headless lint pass preset names. Interpolation table:
  `{{budget.remaining}}`. Cache hash: preset/effort when set.
- Running one / pause: tool-run files in `.running/` (`.json`, `.plan`, `.stages/`), budget
  stop keeps the record, tool records kept at every terminal; `/workflow status|resume <runId>`.
- Workflow tool section: inputs `budget` + `resumeFrom`; `runPlan()`/`resumePlan()`; presets;
  forking rule FIXED (E2's flag, verified in `dispatchOne()`: `$batchDispatch` while a map runs
  → `executeAll` → ForksAskFreeAgents) — parallel/from agents, panel votes AND map step agents
  fork when ask-free; first-task-grant bullet replaced by the per-agent grant fact; "Not
  resumable" replaced by runId format/claim, record/plan/capture files, retention 20, prefix
  rule + whole-declaration identity (announce/cache excluded), context rule, this-call spend,
  refusal texts, `budget`; result headline + JSON (`runId`, `carried`, `budget`,
  `announcements`, row `carried`, `capture` on every stage) and WHY capture is report-side
  (engine capture would replace `{{stage.output}}` → break `when: equals` / truncate prompts);
  paused runs are error results.
- Three limits: task-key list + `preset`/`effort`, `announce` stage key; limit 2 rewritten
  ("`agent:` is a LABEL; `preset:` is the preset reference"). PHP form: preset/effort/announce/
  maxTokens/maxCost builder methods.

### Other docs
- README: Skills "Ships 10 built-ins … `workflow-authoring`"; Workflows bullet gains budgets,
  preset/effort, announce, tool runId/resumeFrom; the stale "parallel agents are all handed the
  first task's `tools`" corrected (per-agent grant; untooled agent inherits session tools).
- SKILLS.md:32 nine → ten + `workflow-authoring`. AGENTS.md Workflows paragraph: `preset:`.
  PERMISSIONS.md: preset permissionMode stricter-only for workflow tasks. HOOKS.md: budget stop
  raises no terminal, resumeFrom-carried stages raise nothing, announcements aren't hook events.
  FLEET-DRIVING.md: the model path now leaves its per-stage crash record (resumable by runId
  once orphaned); checklist item 6 updated.
- Root body.html: "Ten built-ins"; Workflows feature mentions presets, budgets, resumable plans.
  `php tools/gen-docs.php` (only docs/lib/candy-code.html changed), `php tools/check-docs-assets.php` exit 0.

### Test pins
- BuiltInSkillsTest: count 9→10 (two asserts, renamed tests), expectation row + file-provider
  row for workflow-authoring; the `\bcandy-` ban narrowed to `/\bcandy-(?!code(?![\w-]))/i` —
  exempts the product's own `candy-code` binary and `.candy-code/` dir, still catches every
  other `candy-*` incl. `candy-code-web`. Skill text untouched.
- SkillRegistry MAX_COMPILED_PATTERNS docblock: ten built-ins, six distinct globs (adds
  `*workflows/*.yaml`, `*workflows/*.yml` — written unescaped: the test matches the literal
  pattern and `*/` does not occur in them), seven entries, max two, 170x. NOTE E2's suggested
  `*workflows\/*.yaml` spelling would have failed the literal check.
- ReadPathCensusTest: CpuCount `file_get_contents => 2` (PROCESS_DERIVED, probe()),
  WorkflowEngine file_get_contents 4 (readOwnJson), glob 2 (prunePlanRuns), scandir 2
  (removeCaptureDir), fopen 1 (claimPlanRunId). Green against HEAD at the time of the run.

## 2. Test status (targeted)
Green: DocFigureProseDriftTest (103), ClaimFamiliesDocumentationDriftTest, RootSurfaceDriftTest,
ReadmeRosterDriftTest, BuiltInSkillsTest (146), CompiledPatternCacheBoundTest, ReadPathCensusTest
(19), and a sweep of all 101 test files that read the docs I touched: 99 green.
Red, end-of-run: `ReadmeSuiteFigureDriftTest` (suite figure 31838 vs live 32339).
Red, not mine: `BootstrapLaunchFormatConstantsTest::testEveryDocPageQuotingAPromotedFormatIsOnTheReadersRoster`
— the ONLY extra page is the untracked `candy-code/workflow-update.md` (confirmed from the diff).

## 3. Not done / cross-track
- Suite figure / durations tsv: BuiltInSkillsTest gains one data-provider case (+1 test).
- If C6 changes WorkflowEngine's read sinks again, ReadPathCensusTest needs the row delta.

## 4. Commits
- `b8ab9ffb5` test pins (BuiltInSkillsTest, MAX_COMPILED_PATTERNS docblock, ReadPathCensus)
- `c87baba31` docs (WORKFLOWS, README, SKILLS, AGENTS, HOOKS, PERMISSIONS, FLEET-DRIVING, root body + generated page)

ReadPathCensusTest re-run after the docs commit: still green (no C6 sink delta at that point).
