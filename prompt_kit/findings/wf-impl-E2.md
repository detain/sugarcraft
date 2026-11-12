# wf-impl track E2 — P2-5 example library + built-in `workflow-authoring` skill

Scope: `candy-code/examples/workflows/**`, `candy-code/workflows/**`,
`candy-code/src/Skills/BuiltIn/workflow-authoring/SKILL.md` (new).

## 1. What was implemented

### P2-5 · example library (`candy-code/examples/workflows/`)

Every file loads (`ExampleWorkflowsLoadTest`) and lints with 0 errors (`ExampleWorkflowsLintTest`,
plus a scratch linter run with the tool universe and preset roster). The only warnings left are
run-context seeds (`{{base}}`, `{{spec}}`, …) and the linter's shared-checkout warning (see
cross-track 6). Each was also driven end to end through the real `WorkflowEngine` with a fake
executor that answers schema'd agents with schema-shaped JSON: all completed. That run checked
the accumulate rounds and dry stop, the map document, panel votes, a rejected vote dropping one
item under minSuccess, `when` + `from: {stage, pointer}`, and `key: ""` dedup of a context list.
Every header says what the file does, its parameters, and how to run it (`/workflow run`,
headless `candy-code workflow run … --param`, the Workflow tool's `{plan, context}`).

| File | Shape |
|---|---|
| `review-changes.yaml` (new) | `find`: 2 parallel finders, `tools: [Read, Grep, "Bash(git *)"]`, `outputSchema: {$ref: findings}`, `loop: {max: 4, accumulate: {pointer: /findings, key: /id}, until: {dry: 2}}`, `capture: true`, `announce` → `verify`: `type: map` `from: {stage: find, pointer: /items, key: /id, max: 150}`, `minSuccess: 50%`, one `type: verification` step (the task decides `holds` via `$ref: verdict`; the verifier panel uses `votes: 3`, `lenses`, `quorum: 2`, `reject: '/^REJECT\b/m'`, and ESCALATE) → `report`: `effort: low`, `input: {from: [verify], as: results}`. `config.maxTokens`, `maxAgents` and named `schemas:` |
| `migrate.yaml` (new) | `scout` (schema'd `sites` list) → `apply`: map over `/sites` (`key: /id`, `max: 200`, `minSuccess: 90%`, `announce`), with steps `transform` (`isolation: worktree`, `Bash(composer install *)`, `Bash(vendor/bin/phpunit *)`, schema'd `change` report) and `verify` (`type: verification`; a skeptic reads the kept tree and rejects on failed tests or out-of-scope changes) → `report` (`effort: low`, names the branches to merge) |
| `judge-panel.yaml` (new) | 3 designers with distinct angles (`$ref: design`) → 3 judges, each `input: {from: [designs], as: all}` (`$ref: scores`), with a comment that says why the barrier is justified → synthesis that grafts runner-up ideas |
| `research.yaml` (new) | `sweep`: by_tree (Glob) / by_content (Grep) / by_history (`Bash(git log *)`, `Bash(git show *)`) / by_docs, all `$ref: notes`, `capture: true` → `critic` (`$ref: gaps`) → `round_two`: parallel, `when: {stage: critic, pointer: /gaps, empty: false}`, `from: {stage: critic, pointer: /gaps, key: /id, max: 12}` → `synthesize` (`input as: all`) |
| `fix-wave.yaml` (rewritten, name kept) | `from: {context: files, key: ""}` (`key: ""` = the whole string is its identity: dedups the list, so no two agents edit one file, and names agents `wave_<file>`), `minSuccess: 90%`, `$ref: report` → `summarize` reads `{{wave.failed}}` + `input`. The header states that `files` must be a JSON list: it works from the Workflow tool / PHP, while `/workflow run` and `--param` pass strings and the stage refuses with "… which is a string, not an array" |
| `review-loop.yaml` | The `signoff` verifier is now a panel: `votes: 3`, `lenses: [spec-coverage, correctness, regressions]`, `quorum: 2`, keeping the fail-closed `reject` and `retries: 1`. The header was rewritten: the stale "schema rides only the first request" note is gone, and ESCALATE outranks a met quorum |
| `docs-drift-sweep.yaml` | Header only. The stale "loop re-runs only plain stages in v1" and "not constrained to the output schema" notes now state the enforced StructuredOutput and point to review-changes for loop-until-dry. Run lines added |
| `lint-then-fix.yaml` | Header "Run it" block only (its structure is pinned by `WorkflowRegistryTest` / `WorkflowRetriesTest`) |
| `workflows/deep-research.php` | Docblock only. The stale "loop: re-runs plain stages only" claim is replaced with a pointer to research.yaml's data-driven variant (`DeepResearchWorkflowTest` pins the stage names) |

**`preset:` is not used in the examples, on purpose.** It now resolves on every door (C5 wired
`setAgentRosterSource`), and the built-ins `coder`/`reviewer` exist everywhere. But a project's
own preset of the same name changes behaviour under the plan. In this repo `.candy-code/agents/coder.md`
sets `isolation: worktree` and `reviewer.md` sets `effort: high` + `permissionMode: plan`, and
those merge in whenever the task leaves the key unset. A portable example should not change
meaning per checkout.

### Built-in skill `src/Skills/BuiltIn/workflow-authoring/SKILL.md`

- Discovered with no roster edit: `SkillLoader::loadBuiltInSkills()` lists it (verified). The
  frontmatter parses through `Frontmatter::parse` + `SkillFrontmatter::fromParsed` and
  `SkillLoader::loadSkillManifest()`.
- `description` is 276 chars with no product name (the listing test bans `candy-`).
- `paths: ["*workflows/*.yaml", "*workflows/*.yml"]`. In the PathGlob dialect a `*` crosses `/`,
  so these match `.candy-code/workflows/x.yaml` and `examples/workflows/x.yaml`, but not
  `src/Workflows/*.php` or `docs/WORKFLOWS.md` (all measured). A leading `**` was avoided on
  purpose, because docs/SKILLS.md's leading-`**` behaviour note (pinned by
  `PathsGlobDocumentationTest` / `DocFigureProseDriftTest`) is about built-ins whose nudges moved
  with the matcher change, which a new skill is not.
- Every YAML/JSON snippet parses (Symfony Yaml). Every full plan, and every fragment once wrapped
  in a plan with the schemas it uses, loads through `fromYamlString` and lints with 0 errors.
  Every `when:` alternative parses through `StageCondition::parse`.
- **Corrections vs the reference skill** (checked against src):
  - `tools: []` does NOT mean "no tools". `resolveRequestTools([])` → null → the session's tools,
    and `AgentManager::grantRefusalFor` returns null for an empty declaration, so it is not
    policed either. The skill says this and grants `[Read]` to read-only stages.
  - `config.stopOnFirstFailure` is not a YAML key (PHP only). Unknown top-level and `config:`
    keys are ignored, not refused, so a typo like `maxToken:` silently means "no ceiling".
  - After an accumulate loop, downstream reads `/items`, not `/findings` (C2's
    `{"items","keys","rounds"}`). `from: {stage: X}` needs a typed row under the stage name
    (single-task schema'd stage, accumulate or map), never a plain parallel stage.
  - minSuccess applies to parallel/map only, as N or "NN%" rounded **up** (80% of 4 = 4), never
    0, and the stopOnFirstFailure exemption applies.
  - Panels: a failed or timed-out vote counts as a rejection. ESCALATE outranks a met quorum.
    Retries re-run the panel, not the task. In a map, a rejected verification drops the item:
    the skill teaches the "task decides holds, panel checks the decision" shape so refutations
    complete.
  - Presets: the task's own keys win (tools only when non-empty). The permission mode applies
    only when stricter. `skills` are not carried. An unknown preset is a lint error.
  - outputSchema: enforced through StructuredOutput, one re-prompt and then the agent fails. The
    enforced and unenforced keyword lists come from B1.
  - isolation: every isolated task gets a fresh tree under `.candy-code/worktrees/`, and no
    later step can enter it, so the editing agent runs its own checks (the migrate pattern).
  - On the tool path, parallel agents **and map step agents** fork when ask-free. The code is
    `dispatchOne`'s batch door inside a map; docs say otherwise, see cross-track 5.
  - `/workflow run` / `--param` values are strings, which matters for `from: {context}`.
  - Headless exit codes are 0/1/2/3, with 3 meaning escalated or paused by a signal.
  - Tool: `{status, runId, carried, budget, announcements, stages:[… carried?, data?|output?, capture?, dispatch?]}`.
    Every stage is captured **report-side only** (prompts unchanged). `resumeFrom` carries the
    longest settled+unchanged prefix. Omitting `context` reuses the stored one, and a different
    context carries nothing. `budget` takes the tighter of it and maxTokens, and a resume's
    totals and budget count only that call. `/workflow status|resume <runId>` reach tool runs.
    All of this was verified against C5's landed commits `c001dfa8a` and `73e8a2baa`.

## 2. Not done / caveats

- The skill is ~33 KB (507 lines), somewhat longer than the reference, because the corrections
  add rows. Built-ins have no size cap (SkillFileReader caps at 1 MiB; the 16 KiB cap is for
  proposed skills only).
- review-changes' map: `minSuccess` cannot be 0, so if more than half the verdicts are
  overturned or fail, the stage fails and `report` never runs. This is documented in the header.
- **FINAL PASS:** re-check the skill's §2/§13 tool lines if C5 changes the tool again after
  `73e8a2baa` (the JSON keys, capture-on-report, the resumeFrom/context rule, `budget`
  semantics, and `/workflow status|resume <runId>` reaching tool runs).

## 3. Cross-track requests

1. **`tests/Skills/BuiltInSkillsTest.php`** (red: 2 tests). Count 9 → 10: docblocks at L49,
   L218 and L535, `assertCount(9, …)` at L491 and L622. Rename
   `testLoadBuiltInSkillsReturnsAllNineSkills` if desired. Add the expectation row:
   `'workflow-authoring' => ['name' => 'workflow-authoring', 'description' => <the frontmatter line>, 'userInvocable' => true, 'effort' => null, 'allowedTools' => null, 'paths' => ['*workflows/*.yaml', '*workflows/*.yml']]`.
   In `testNoBuiltInSkillNamesASugarCraftPathOrRunsADestructiveGitCommand`, the `\bcandy-` ban
   also hits the product's own names: the binary `candy-code workflow run` and the config dir
   `.candy-code/workflows/`, `.candy-code/agents/`, `.candy-code/worktrees/` (8 lines). Those are
   not monorepo libs, so suggest `/\bcandy-(?!code\b)/i`. The listing test (`candy-` regex) is
   already green.
2. **`src/Skills/SkillRegistry.php` MAX_COMPILED_PATTERNS docblock** (`CompiledPatternCacheBoundTest`
   red). It should read: "the ten shipped built-ins declare six distinct `paths:` globs
   (`composer.json`, `composer.lock`, `**\/*.php`, `**\/*Test.php`, `*workflows\/*.yaml`,
   `*workflows\/*.yml`), across seven `paths:` entries, and no skill declares more than two.
   1,024 is 170x that". The "~200 skills each declaring five" phrase is unchanged.
3. **`docs/SKILLS.md:32`** (`ClaimFamiliesDocumentationDriftTest` red): "nine directories" →
   "ten", and add `workflow-authoring` to the list. **`candy-code/README.md:2846`**: "Ships 9
   built-ins" → 10, and name `workflow-authoring` (no test pins it).
4. **Root `docs/_data/candy-code.body.html`** "Nine built-ins" → "Ten built-ins", then run
   `php tools/gen-docs.php` (`RootSurfaceDriftTest` red).
5. **docs/WORKFLOWS.md** (tool section):
   - "Sequential stages, tasks:, pipeline, verification and **map steps always run inline (a
     map's chains therefore serialise)**" is false. Inside a map, `dispatchOne` takes the pool's
     batch door (`$batchDispatch`), so ask-free step agents fork and the chains overlap.
   - "every agent of a parallel stage still runs on the FIRST task's tool grant" is stale since
     C4's `39635fa28`.
   - The YAML-schema "Defaults are … `stopOnFirstFailure: false`" line reads as if it were a YAML
     key. It is PHP-only, and unknown top-level/config keys are ignored.
   - Document that a later step cannot enter an earlier step's worktree.
6. **WorkflowLinter `checkIsolationSanity`**: it warns "Every agent in this parallel stage writes
   the shared checkout" for any 2+ agent parallel stage without a worktree, including all-`[Read, Grep]`
   stages (judge-panel, research). Suggest warning only when some agent's tools are omitted/`[]`
   or include a write-class tool.
7. **`tools: []` gloss** (`WorkflowGrammarGlossary::TASK_KEYS['tools']` says "`[]` = none", and so
   does the tool description): false, see §1. Either fix the gloss to "`[]` or omitted = this
   session's tools", or make the engine treat `[]` as genuinely toolless (a behaviour change with
   security upside: today `[]` is an unpoliced full grant).
8. **CLI JSON values**: the `/workflow run key=[…]` and `--param` JSON-list requests (C2, B3) are
   still open. Until then fix-wave runs only from the Workflow tool or PHP.
9. Ideas (no owner yet): a config-key roster so `config.maxToken:` typos fail closed; a way for a
   map verification to treat "refuted" as a non-casualty (or `minSuccess: 0`); an
   `isolation: {reuse: <step>}` so a verify step can run in the transform's tree. Also, schema runs
   keep the worktree note on the live stream only, so an isolated schema'd agent must self-report
   its tree (migrate's `change.tree`).

No new test files, so there is no suite-figure or durations-tsv impact from E2.

## 4. Commits

- `f7fb3515b` example library: review-changes, migrate, judge-panel, research, fix-wave on `from: {context}`, review-loop panel, stale notes (P2-5)
- `2f4a784a2` built-in workflow-authoring skill (P0-1 P2-5)
- `3370b0ce4` skill matches C5's landed tool run records (P1-8 P0-4 P1-6)
