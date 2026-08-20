# crush_code.md — RESUME HERE

**Single entry point for continuing the `sugar-crush` audit plan.** Read this file
first, then `docs/plans/crush_code_worklog.md` for the round-by-round record.
Nothing here depends on a prior conversation's context.

---

## 0-NOW. STATE AT ROUND 31 — read this first, then §0 for the standing rules

**`master` = `ed57d46a`** (the DeepSeek-V4 sglang default). Live tree clean.
**Suite baseline: `7975 tests / 91097 assertions / 1 skipped / rc 0`** — the sglang lane's
post-rebase figure at `ed57d46a`, being re-measured by the supervisor as the post-hoc gate.
The prior gate was `7922 / 90961 / 1 / rc 0` at `f0585149` (Phase 6 items 1-2).
**Skips MUST stay 1** — `tests/MCP/McpClientTest.php:106`. A 2 means `vendor/sugarcraft/*` was
replaced by Packagist copies and every figure since is void.

**58 of 75 plan items.** Phase 2 complete except item 9 (the deliberately-last plugin epic);
Phase 7 complete; **Phase 6 items 1-2 complete**.

### THREE LANES. Do not create a fourth.

| lane dir | job | state |
|---|---|---|
| `/home/sites/crush-lane-cmd` | **P6.3 + P6.4** + the argument-scoped permission-rule hole | round 31, implement stage |
| `/home/sites/crush-lane-lsp` | **P8.10 + P8.11**, both inside `src/Context/` | round 31, implement stage |
| `/home/sites/crush-lane-sglang` | **LANDED** `ed57d46a`; next: the DSML parser (§0-DS), script ready | free, clean — **hold DSML until `lane-cmd` lands**, both add a `src/` file and would collide on the census literal |

The user authorised **2 concurrent plan lanes** plus the sglang task they asked for by name.
Four would exceed that.

Workflows (`resumeFromRunId` works only in-session):

- Round 31 · task `w91ulqast` → **run `wf_ea0ac65b-caf`**, script `…/workflows/scripts/crush-round31.js`
- sglang · task `w61n2892v` → **run `wf_7a9fd600-a06`**, script `…/workflows/scripts/crush-sglang-deepseek.js`

Each lane commits and pushes to `master` itself. Journals hold every agent's full return value —
`subagents/workflows/<run>/journal.jsonl`. **Read the journal before concluding a lane returned
nothing.**

**Every lane carries the supervisor's scratch paths in `.git/info/exclude`** (§5.2d). Keep that when
creating a lane: without it, `git add -A` in the commit recipe sweeps supervisor instrumentation into
`master`. A finished lane's `git status --porcelain` should be **empty**.

### THE SYNC DAEMON — the user asked that nothing fall behind

A persistent monitor runs `…/scratchpad/sync-lanes.sh` every 90s. It fetches the live tree and every
lane, `--ff-only` pulls the live tree (no agent works there), and **rebases a lane only while that
lane is clean AND has no process of its own running**. It emits only on change, and alerts if a lane
passes 6 behind and cannot auto-refresh. A dirty lane is never pulled, rebased, reset or stashed —
that is what corrupts a half-finished edit (§5.2c). **If you restart the session, re-arm it.**

Do not read a lane's behind-count without fetching first: `cp -a` copies remote-tracking refs, which
then age independently per lane, and a stale ref reports `0 behind` while the lane is 6 behind.

### TOOLING TRAP, now three deep

`node --check` **passes** a workflow script that `import` rejects — it did exactly that this round on
a mangled nested-backtick template literal. Same family as `phpunit | tail` returning the pipe's exit
code, and as `node --check` not seeing a live `${...}`. **The only sufficient check is evaluating the
script the way the runtime does:** wrap the body in an `async function`, stub
`agent`/`parallel`/`phase`/`log`, run it, and inspect the *rendered* prompts for stray `` \` ``, live
`${`, and `undefined`. The probe lives at `…/scratchpad/probe31.mjs` and is worth keeping.

### OPEN FINDINGS (all recorded in `crush_code.md`, measured)

1. 🔴 **Argument-scoped permission rules match NOTHING** — `PermissionGate::ruleMatches()`
   (`:209-220`) compares only the tool name, so `Deny Bash(rm -rf *)` and `Deny Read(./.env)` both
   never fire while `PermissionRule.php:9` advertises exactly those forms. **BEING FIXED in round 31,
   lane `cmd`** — it is the prerequisite P6.4 needs and the plan did not know about.
2. 🔴 Prefix matching on the **real-call** path is pinned by no test (only the declaration path).
   Also round 31, same lane.
3. 🟡 `WorkflowEngine` never resolves `agent:` to a preset — **RE-MEASURED 2026-08-20: only this
   first clause survives.** Zero `AgentPreset` hits in the file; `$registry` is a `WorkflowRegistry`
   (workflow files, not a roster). The other three clauses of the original finding have decayed:
   `prompt: ''` is deliberate (inline comment says the system prompt comes from `CompleteRequest`);
   `$tasks[0]` has zero occurrences; and `pipeline`/`verification` DO have YAML spellings — four
   executors exist and `UnsupportedStageTypeException` names all four types. **Re-measure any open
   finding before briefing it** — two of those clauses would have sent an agent to fix working code.
4. 🟡 5 of 9 skill frontmatter keys inert; `App::dispatchSkill()`/`applySkillsToSystemPrompt()` have
   no caller, so `context: fork` does nothing on the CLI path.
5. 🟡 `agentRoster()` drops 10 of 16 preset fields incl. `permissionMode`.
6. 🟡 `HookManager.php:34`'s worked example names `confirm-remove`; the hook is **`confirm-rm`**.
   Guard keys by **event+name**.
7. 🟡 `CONTROL_PLANE` reserves a `/permissions` command with no row and no dispatch arm.
8. 🟡 **`sugar-crush/docs/PERMISSIONS.md` enumerates three `trustedProject*` grants and misses the
   fourth** (`trustedProjectSettings`, landed one commit after Phase 7 was marked complete), and the
   settings layering has no reference page at all. A page that enumerates trust grants and misses one
   is worse than no page. Route to the next docs bundle; prefer deriving the list from the constants.

### QUEUE

1. **P6.5** (keybindings remap + `statusLine` command) and **P6.6** (`--model` /
   `--permission-mode` CLI flags as the highest-precedence tier) — the next Phase 6 bundle, after
   round 31's lane `cmd` lands. Note P6.6 must confront that `LAYERED_KEYS` deliberately has **no
   `model`** (no reader exists; the previous lane declined to add inert surface).
2. **P3.x** — TextInput, candy-focus FocusRing, candy-sprinkles Table, candy-kit help screen.
   **Still blocked**: it touches `src/Chat.php` and so does the sglang task.
3. The open findings above, and a docs bundle for #8.
4. **P8.6** (VHS demos), P8.4, P8.8, P8.9, P8.13.
5. **Phase 2 item 9** (plugins) LAST, then the hardening backlog E1-E50.

### 0-DS. THE DEEPSEEK-V4 TASK — NOT A PLAN ITEM, and this is its only durable record

**STATUS: LANDED as `ed57d46a` (2026-08-20). Suite 7975 / 91097 / 1 / rc 0.** Everything below is
kept as the measured record. The ONE piece still outstanding is the DSML parser + streaming gap,
scripted and ready at `…/workflows/scripts/crush-dsml.js` — see the DSML entry further down.

**What the review round added on top of the implement stage:**

- 🔴 **The MiniMax truncation warning was misfiring on DeepSeek-V4.**
  `flagTruncationRiskInLatestToolResults()` warned about a MiniMax-M2.x `</parameter>` bug for
  DeepSeek requests. Now takes `$model` and returns early for the DeepSeek-V4 family; **unmeasured**
  models are still warned, and the text names the addressed model beside the measured one. Live
  proof DeepSeek does not have the bug: a body containing
  `<invoke name="x"><parameter name="y">z</parameter></invoke> DONE` came back **64/64 bytes,
  identical, `</parameter>` intact**. The decode *diagnosis* stays ungated but no longer asserts
  causation — `"This is the known MiniMax-M2.x bug"` became `"That matches the signature of…"`,
  i.e. cause inferred from shape, not from model.
- **The reviewer's disproof was itself wrong, and the fix agent reversed it.** The reviewer grepped
  `generateTitle|titleFrom|sessionTitle`, found nothing, and concluded title generation never reaches
  a provider. It does: `Chat.php:5906` → `Bootstrap::titleBackend()`, and `/compact`'s summaries →
  `Bootstrap::summaryBackend()`, both via `toollessBackend()` (no `$tools`). So the doc-block's claim
  was **true and merely uncited**. A reminder that a reviewer's grep is evidence about the grep.
- **Model-family matching over-matches, deliberately.** `deepseek-v40`, `DeepSeek-V4.5` and
  `DeepSeek-V4.1-Flash` all take the V4 arm. Accepted, with the asymmetry stated: a MISS costs
  `reasoning_effort` and the thinking then lands silently in `content`; a wrong sampling number on a
  probably-similar model does not. Pinned by an 11-row boundary test. Aliases (`dsv4`, `flash`,
  `local-model`) fall to the MiniMax defaults — mitigated by documentation, not code, because a
  one-shot warning could not tell an aliased V4 from a genuine MiniMax deployment.
- ⚠️ **A config value of `1` for `reasoningEffort` throws on EVERY request.** JSON `1` is an int, the
  DTO is `string|float|null`, the cast makes it `1.0`, and the server's bound is `le: 0.99`. The
  construction-time guarantee covers the **string tier only** — deliberately, since the name set is a
  closed pydantic literal while the float bound is one a later SGLang may widen. README now says
  **"Write `0.99`, not `1`."** Both sides pinned, and the `1.0` test says in its doc-block that it
  asserts a known-bad value is accepted *locally*, so a future range check fails it rather than
  passing vacuously.
- `contextWindow()` is a **transcribed constant, not a live read** — deliberately: it is a
  render-path accessor and `Chat`'s four context tiers recompute per frame, so a synchronous HTTP
  round trip would block the TUI on every redraw. Documented as decaying the way the 128,000 it
  replaced did, with the `curl` to re-verify.

**Two operational facts worth carrying:**

- **The server is not a stable dependency.** `https://skynet2.interserver.net/v1` returned nginx
  **502 for ~7 minutes** mid-task, on both `/v1/models` and `/v1/chat/completions`, and recovered on
  its own. Any future agent handed "the server is reachable" should verify rather than assume.
- **The `base_uri` trailing-slash trap was reproduced by accident and the guard is load-bearing.** A
  hand-built Guzzle client with `'base_uri' => '…/v1'` (no trailing slash) sent every request to
  `https://skynet2.interserver.net/chat/completions` — `/v1` silently dropped, per RFC 3986
  absolute-path resolution. `SglangProvider::openAiCompatible()` already guards it
  (`rtrim($baseUrl,'/') . '/'` plus a **relative** `'chat/completions'`). Anyone hand-constructing a
  client for this provider in a test or probe must replicate the trailing slash.

**The census literal is now 279** (`BuiltInToolCorpusTest`, bumped 278→279 by `LayeredSettings.php`).


The user switched their self-hosted SGLang server from `MiniMax-M2.7` to
**`deepseek-ai/DeepSeek-V4-Flash-0731`**. **The old model is GONE from that server**, so the
shipped sglang default 404s on the model name today. Their instructions, verbatim in substance:
make it the new default; add `reasoning_effort` supporting `low`/`high`/`max`; use
`temperature = 1.0` with `top_p = 0.95` for agentic and `1.0` otherwise; test tool parsing against
the live server; **"dont delete old handling we want it to support both ways"**; and (later) **"for
now set it to max as default for this model"**.

**Measured against the live server by the supervisor, 2026-08-20 — believe this over the model card:**

- Endpoint `https://skynet2.interserver.net/v1`, **no API key**. `GET /v1/models` →
  `deepseek-ai/DeepSeek-V4-Flash-0731`, `max_model_len` **393216**.
- **Tool calls come back as STRUCTURED OpenAI `tool_calls`, non-streaming AND streaming.**
  Non-streaming: `finish_reason: "tool_calls"`, `function.arguments` a JSON string. Streaming
  (`stream:true`, two-city prompt): 19 SSE chunks, 10 `delta.reasoning_content` deltas, **two
  parallel calls at `index` 0 and 1** accumulating correctly with distinct `call_…` ids.
  **So `OpenAiArrayToolCallParser` already covers this model and NO new parser class is needed.**
- The HF card says the model ships **no Jinja chat template** and documents no `--tool-call-parser`
  (only `--speculative-algorithm DSPARK --trust-remote-code`). **The deployment contradicts the
  card** — someone configured a parser. If any prompt shape ever yields a tool call as *text*, that
  is what `MinimaxXmlFallbackToolCallParser` exists for.
- **`reasoning_effort`: the server accepts MORE than the card's three.** Its own validation error is
  authoritative: `literal['none','minimal','low','medium','high','xhigh','max']` **or a constrained
  float**. Measured `low`→29, `high`→55, `max`→63 reasoning_tokens, and **`medium` works** (22).
  `bogus` → `{"object":"error"}` carrying that literal list. **Do not narrow to three** — that
  refuses values the server serves.
- **Omitting `reasoning_effort` is NOT neutral:** `reasoning_content` is `null`, `reasoning_tokens`
  0, and the model's thinking lands **inline in `content`**. An absent effort pollutes assistant
  text — an independent argument for defaulting it.
- **It was not configurable at all before this work.** Zero occurrences of
  `reasoning_effort`/`reasoningEffort` in `src/`, `bin/`, `tests/` or any config;
  `CompleteRequest` had 13 params and none was it. `extraTemplateKwargs` → `chat_template_kwargs`
  is the WRONG seam (server-side Jinja template, which this model lacks). Already correct and
  present: `'separate_reasoning' => true` at `SglangProvider.php:319`, which is what makes
  `reasoning_content` populate.
- **`temperature` defaulted to `0.7`** at `SglangProvider.php:310`, not 1.0, and `:323` sent
  `top_p => $request->topP` (null when unset). The fix must be **model-aware** — silently retuning
  MiniMax violates the user's keep-both-working rule.
- **CORRECTED BY THE IMPLEMENT AGENT: the default model lived in FOUR tracked places, not three.**
  My brief named three and missed `ProviderFactory::defaultConfig('sglang')['model']` (was line 363)
  — **the one that `$SUGARCRUSH_PROVIDER=sglang` actually reaches**, because `defaultConfig()` is
  `bin/sugarcrush`'s only hook into the provider system. Changing three of four would have left the
  plain `sglang` provider type 404ing on the model name. All four now fixed, and `defaultConfig`
  reads `SglangProvider::DEFAULT_MODEL` so the two cannot drift. The three my brief did name were:
  `src/Providers/SglangProvider.php:68`, `/.sugar-crush/config.dev.json`, and
  `/sugar-crush/.sugar-crush/config.dev.json`. **`config.dev.json` is NOT the md5 invariant** —
  that is `.sugar-crush/config.json` (no `.dev`). File 3 has a hardlink partner OUTSIDE the repo, so
  an in-place rewrite changes that path too while a `sed -i` breaks the link.
- **A SECOND BUG THE BRIEF NEVER MENTIONED, found by the implement agent:
  `SglangProvider::contextWindow()` hard-returned `196_608` while the live server reports
  `max_model_len: 393216`** — and its doc-block asserted that 196,608 *was* the live
  `--context-length`. So all four of `Chat`'s context tiers were sized against half the real budget
  while the comment claimed otherwise. The recurring defect (a number carrying the wrong domain)
  found in shipped code rather than in a review. Now model-aware: 393,216 for DeepSeek-V4, 196,608
  preserved for everything else. Note the residue the agent flagged rather than silently
  "improving": `LEGACY_DEFAULT_CONTEXT_WINDOW = 196_608` is a *MiniMax* figure now serving as the
  fallback for every third model — a guess. `0` would be honest but would disable all four tiers on
  MiniMax, so behaviour was preserved and the domain documented on the constant.
- **Measured bounds, sharper than the brief's "a constrained float":** `0.0` OK, `0.5` OK, `0.99` OK,
  `1.0` REJECTED (`le: 0.99`), `-0.5` and `1.5` REJECTED — so `0.0 <= x <= 0.99` on this deployment.
  The float is forwarded **without a local range check on purpose**, so a later SGLang widening the
  bound is not refused by us; out-of-range fails loudly at the server, whose 400 names the live
  bound. The seven NAMES are validated locally (closed, server-authoritative set), so a typo fails
  before any request is sent.
- **`reasoning_effort` is emitted TOP-LEVEL** in `buildParams()`, not under `chat_template_kwargs`.
  Confirmed no prompt shape produced a text tool call at any of the seven effort levels, with effort
  absent, or with `separate_reasoning` absent — **so no new parser class was needed** and the
  existing `OpenAiArrayToolCallParser` handled every live payload unmodified.
- **PHP encodes `1.0` as JSON `1`** (Guzzle's `json` option sets no `JSON_PRESERVE_ZERO_FRACTION`),
  so the DeepSeek defaults go on the wire as `"temperature":1,"top_p":1`. SGLang's pydantic coerces
  int to float and returns 200 — probed, and pinned in a test on the raw body so nobody later reads
  a capture as a lost decimal.
- **"Agentic" was pinned to "the request offered tools"** — the only agentic signal a
  `CompleteRequest` carries. `Runtime` always passes tools, so in practice `top_p` is 0.95 for chat
  turns and 1.0 for tool-less side calls (compaction summaries, titles).
- 🔴 **THE TEXT FALLBACK COVERS THE WRONG SHAPE FOR THE NEW DEFAULT MODEL.** The user pointed at
  `https://huggingface.co/deepseek-ai/DeepSeek-V4-Flash-0731/blob/main/encoding/README.md`
  (fetched 2026-08-20). DeepSeek-V4's **native** tool-call emission is **DSML markup**, not JSON and
  not MiniMax XML:

  ```
  <｜DSML｜tool_calls>
  <｜DSML｜invoke name="$TOOL_NAME">
  <｜DSML｜parameter name="$PARAM" string="true|false">$VALUE</｜DSML｜parameter>
  </｜DSML｜invoke>
  </｜DSML｜tool_calls>
  ```

  `string="true"` means the value is a raw string; `string="false"` means it is JSON (number,
  boolean, array, object) — so the flag is load-bearing and a parser that ignores it will hand the
  model's numbers over as strings. Tool results go back as `<tool_result>{json}</tool_result>`.
  Reasoning is delimited `<think>…</think>` between the `<｜Assistant｜>` prefix and the content.
  Other special tokens: `<｜begin▁of▁sentence｜>`, `<｜end▁of▁sentence｜>`, `<｜User｜>`.

  **Measured: the tree has ZERO occurrences of `DSML` in `src/`, `tests/` or `bin/`.** The parser
  directory holds exactly three files — `OpenAiArrayToolCallParser`, `ToolCallParserInterface`, and
  `MinimaxXmlFallbackToolCallParser`. So the wired text fallback parses a shape **this model never
  emits**. A fallback that exists, is wired, and covers the wrong model is the recurring defect at
  architecture level.

  **Why it is not academic:** the deployment returns structured `tool_calls` today only because
  someone passed `--tool-call-parser`, which **the HF card's own documented launch command omits**
  (it shows only `--speculative-algorithm DSPARK --trust-remote-code`). A restart without that flag
  turns every tool call into raw text that the OpenAI-array parser cannot see and the MiniMax
  fallback cannot match — the agent would silently do nothing on every tool call, which is the
  quietest possible failure.

  **Queued as an additive follow-up** (`DsmlToolCallParser` alongside, never replacing — the user's
  "support both ways" rule). Two sequencing constraints: it is the **first new `src/` file** in this
  task, so it moves the census literals (`BuiltInToolCorpusTest`, `ReadPathCensusTest`,
  `ContainedPathInventoryTest`) and must not run while another lane is also adding a `src/` file;
  and the streaming path still ignores the injected `ToolCallParserInterface` (pre-existing §12 D2
  gap), so a DSML parser wired only into the non-streaming path would still recover nothing while
  streaming — that gap has to be closed in the same bundle or the fix is half a fix.

  Also from that page, worth knowing but **not** actionable for us: `reasoning_effort` is implemented
  server-side as a **text prefix prepended before the system message**, and `"low"` maps to *no
  prefix*. So the levels are prompt shaping, not a sampling knob. The card documents prefixes for
  only `low`/`high`/`max` while the server accepts seven names — consistent with the deployment
  being ahead of the card, which is the standing rule for this model.

- **SUPERVISOR TO-DO once the sglang lane commits** (its agent correctly refused to edit these —
  they are supervisor-owned — and they go stale the moment that commit lands):
  `crush_code.md:1607` reasons from "`contextWindow()` correctly reports 196,608";
  `crush_code.md:2530` quotes the old `config.dev.json`; `crush_feat.md:1857` still describes the
  deployment as MiniMax. Fix all three AFTER the commit lands, not before — until then the tree does
  not yet say what they would be corrected to.

- **Follow-up deliberately deferred, and STILL deferred.** `src/Config/LayeredSettings.php` landed
  in `f0585149`, but round 31's lane `cmd` is now extending `LAYERED_KEYS` again for P6.3/P6.4. Add
  reasoning-effort to `LAYERED_KEYS` only after THAT lands, or two lanes rewrite the same constant.

### What landed this round

| commit | what |
|---|---|
| `8d15443c` | **Phase 7 items 3-6** — ten pages under `sugar-crush/docs/`, 2,691 lines, zero `.php` |
| `6b63022e` | Phase 7 marked complete; the docs review's five defects recorded |
| `1308a1d1` | the `WorktreeManager` fatal + the plan's wrong key list |
| `35f3c1ac` | the permission-matcher hole and five more docs-lane findings |

### OPEN FINDINGS worth picking up (all in `crush_code.md`, measured, none fixed)

1. 🔴 **Argument-scoped permission rules match NOTHING.** `PermissionGate::ruleMatches()`
   (`:209-220`) compares only `ToolCall::$name`. `Deny Bash(rm -rf *)` computes prefix
   `Bash(rm -rf ` → `str_starts_with('Bash', …)` false → **never fires**. `PermissionRule.php:9`
   advertises exactly that syntax. A user who writes an argument-scoped deny has denied nothing.
2. 🔴 Prefix matching on the **real-call** path is pinned by no test (only the declaration path).
3. 🟡 `WorkflowEngine` never resolves `agent:` to a preset — fabricates `new Agent(name, prompt:'')`;
   `executeStage()` runs `$tasks[0]` only; `pipeline`/`withVerification` have no YAML spelling.
4. 🟡 5 of 9 skill frontmatter keys inert; `App::dispatchSkill()`/`applySkillsToSystemPrompt()` have
   no caller, so `context: fork` does nothing on the CLI path.
5. 🟡 `agentRoster()` drops 10 of 16 preset fields incl. `permissionMode`.
6. 🟡 `HookManager.php:34`'s worked example names `confirm-remove`; the hook is **`confirm-rm`**, so
   the example names a hook the guard does not protect. Guard keys by **event+name**.
7. 🟡 `CONTROL_PLANE` reserves a `/permissions` command with no row and no dispatch arm.

### QUEUE

1. **P3.x** in `lane-lsp` — TextInput, candy-focus FocusRing, candy-sprinkles Table, candy-kit help
   screen. Needs **no new `src/` file**, so it will not collide with the census literals `lane-cmd`
   already moved (278→279). **Sequence it AFTER `lane-sglang` lands** — both touch `src/Chat.php`.
2. **P6.3/5 and P6.4/6** — the 4-deep chain behind P6.1/2, serial in `Bootstrap.php`.
3. The open findings above, especially #1.
4. **P8.6** (VHS demos), P8.4, P8.8, P8.9/10/11, P8.13.
5. **Phase 2 item 9** (plugins) LAST, then the hardening backlog E1-E50.

**Do not exceed the lane count the user set.** They asked for **2 concurrent plan lanes**; the third
(`lane-sglang`) exists because they explicitly requested that task. Four would exceed what they
authorised.


## 0. STATE AS OF THE 2026-08-20 COMPACT — read this first

**HEAD is `a2221578`, tree CLEAN, in sync with `origin/master` (0 ahead / 0 behind).**
**Suite baseline: `7782 tests / 90237 assertions / 1 skipped / rc 0`, ~3m12s** — supervisor-measured
in the live tree, against local sibling symlinks. **Skips MUST stay 1.** A 2 means
`vendor/sugarcraft/*` got replaced by Packagist copies and every figure since is void. The one
legitimate skip is `tests/MCP/McpClientTest.php:106`.

`sugar-crush/vendor/sugarcraft/` now has **18** symlinks, not 16 — see the dependency note below.

### FAN-OUT IS CURRENTLY **ON**, AT 2 LANES

The user asked for 2 concurrent lanes (their words: *"do the fan out but go with 2 concurrent lanes
not 3 for now"*), and said they will say when to change it. `docs/plans/crush_code_concurrency.md`
is the authority for the mechanics; §0b below is the summary.

**Workflow `wo6lx5vcd` (run id `wf_4ee49ce4-130`) is RUNNING**, two lanes in parallel, each
implement → review → fix:

| lane dir | bundle | scope |
|---|---|---|
| `/home/sites/crush-lane-cmd` | **C4b** | the rest of Phase 2 item 4 — the `` !`cmd` `` and `@file` template forms |
| `/home/sites/crush-lane-lsp` | **C6** | Phase 2 item 7 — WRITE `src/Tools/LspTool.php` (the plan wrongly says "add `implements Tool`"; no such class exists) |

Both lanes were snapshotted from a verified-quiescent tree at `a2221578` and each carries a
`.lane-provenance` file recording source HEAD, a `git status` hash and the timestamp. Both verified:
18 symlinks, 0 broken, `ReflectionClass` resolving INSIDE the lane.

**THE COMMIT GATE IS DIFFERENT IN FAN-OUT MODE — this is a deliberate change, not drift.** In solo
mode the supervisor ran the suite and committed. In fan-out the user's design is that each lane
commits and pushes to `master` from its own copy, so **the lanes commit themselves**, gated on: full
suite green in-lane with skipped == 1, `check-path-repos --no-lib-path-repos` rc 0, config md5
unchanged, no `.vhs/*.gif` and no `composer.lock` staged, and `git pull --rebase origin master`
before every push. The supervisor's remaining job is the POST-hoc gate: pull into
`/home/sites/sugarcraft` and run the full suite there.

**The LSP lane holds the CENSUS TOKEN this round.** It is adding a `src/*.php` file; the cmd lane was
explicitly forbidden from adding one. Two lanes both bumping `assertSame(278, …)` merges CLEANLY and
is silently wrong — that is the sharpest conflict class in the map.

### IF THE WORKFLOW RESULT IS LOST TO A COMPACT

The lanes are **independent git repositories**, so recovery is different from solo mode:

```sh
for L in /home/sites/crush-lane-cmd /home/sites/crush-lane-lsp; do
  echo "=== $L ==="; cat $L/.lane-provenance
  git -C $L log --oneline -3          # did it commit?
  git -C $L status --short            # or is the work still uncommitted?
  git -C $L rev-list --left-right --count HEAD...origin/master   # did it push?
done
```
A lane that committed AND pushed needs nothing but a `git pull` in the live tree. A lane that
committed but did not push: push it. A lane with uncommitted work: that work is still there — verify
and commit it. Lanes are disposable once their work is on `origin/master`; `rm -rf` them then.

### PLAN ITEMS: 49 of 75 done, 26 left

`a4be8263` closed **Phase 4 item 6** (real subcommands `doctor`/`models`/`session`/`mcp`/`completion`,
`--config <file>`, and a validated `--output-format` that no longer degrades silently to text at
exit 0). **Phase 2 item 4 is HALF done and is NOT in the 49** — the `CommandLoader` wiring and
`$ARGUMENTS`/`$1..$9` landed; `` !`cmd` `` and `@file` are what lane C4b is doing now.

Phases 0, 1, 5 complete. Phase 2 items 1, 2, 3, 5, 6, 8 complete.

### A SUPERVISOR-ONLY ACTION THAT ALREADY HAPPENED — `ddd9560d`

**`candy-focus` and `candy-kit` are now in `sugar-crush/composer.json`.** They were NOT dependencies,
so Phase 3 item 2 (candy-focus `FocusRing` for pane cycling) and Phase 3 item 5b (restyle the help
screen with candy-kit) were literally unimplementable by an agent — closing the gap needs a `require`
bump plus `composer update`, which every agent is forbidden to run. Measured: no OTHER dependency is
missing. Three namespaces looked absent (`Bits`, `Charts`, `Query`) but all three appear only inside
`src/Skills/BuiltIn/*/SKILL.md` documentation, never in code; `Pty` is correctly `require-dev`.

If you ever repeat this: add the `require` lines, snapshot the manifest, `check-path-repos --fix
--strict-closure`, `composer update`, `git checkout -- '*/composer.json'`, restore the snapshot, and
verify `--no-lib-path-repos` exits 0 and the manifest has no `repositories[]` block.

### TWO SECURITY-RELEVANT FIXES LANDED IN `a4be8263` — do not "re-find" them

Both were found by review rounds, not by the implementers' green suites, and both are functionality
class under §3 (data loss / losing control of `/exit`), so they were fixed now rather than deferred:

1. **`doctor` DELETED STORED CONVERSATIONS** — its probe reached the session store through the
   pruning accessor. Fixed with a `Bootstrap::sessionStore(bool $prune = true)` seam; the launch path
   is byte-identical.
2. **Repository content could SHADOW CONTROL BUILT-INS**, `/exit` and `/permissions` included — a
   checked-in `.sugar-crush/commands/exit.md` was enough. Fixed in the LOADER (not in `Chat`), so the
   popup, `/help` and dispatch read one already-reserved `CommandRegistry::CONTROL_PLANE` map instead
   of three that agree by luck. Non-reserved built-ins (`compact`, `rewind`, …) stay overridable.

### THE HAZARD DISCOVERED THIS ROUND — read before creating any lane

**Never `cp -a` a tree while an agent is running a mutation harness in it.** The copy freezes whatever
mutation was applied at that instant. Measured: a probe copy froze
`'fish' => self::zshCompletion()` where live had `fishCompletion()`, its suite showed 1 failure, and
the obvious wrong reading was that an unrelated dependency change had broken something. A lane born
that way reports false mutation kills all round, because a pre-broken test reads as killed.

**Corollary: a still `git status` is NOT evidence of an idle agent.** A mutation loop edits one file,
runs ONE test file (~0.05s), and restores from a checksummed backup, so the tree sits byte-identical
for minutes while a great deal happens. Judge liveness from the agent transcript's mtime and from
`pgrep -af 'bin/phpunit'`. (Beware: `pgrep -c -f 'bin/phpunit'` counts your own pgrep and its shell
wrapper — check the actual lines, not the count.)

### QUEUE AFTER C4b + C6

C4a/C4b close Phase 2 item 4 · then **P2.1/2** (the McpClient rename, tracker #12) · **P3.x** (now
unblocked by `ddd9560d`) · **P6.1/2** then the 4-deep chain behind it · **P8.3** (whose halves the
tracker has BACKWARDS — the render branch is written at `src/Tui/AgentOutputPane.php:58`; what is
missing is the hand-off, since `BackgroundSupervisor::getStallWarnings()` has no production caller) ·
Phase 7 docs · **P2.9** (plugins) explicitly LAST · then `#88` · then the hardening backlog E1-E50.

### W3'S TWO KNOWN-OPEN ITEMS (deliberate, recorded, not defects of omission)

1. **A mid-grey crossover band.** Over all 256 greys × 5 palettes, backgrounds around
   `#6c6c6c`-`#797979` can project two different shell tokens to the SAME colour (14/256 greys on
   `ansi`, 7/256 on `dracula`). **Legibility is unaffected** — 5 palettes × 24 backgrounds, zero
   sub-4.5 — so the user's bug stays fixed; what degrades is role DISTINCTION.
2. **The frame walk ignores SGR 2 (faint)**, which `src/Renderer.php` emits at 17 sites. Dracula's
   `shellMuted` measures 6.31:1 but is painted at roughly 2.70:1. Left open on purpose: modelling
   "half-way to the background" would pin a terminal-by-terminal guess as fact.

### AGENT CONTEXT BUDGET — a standing user instruction

**Per-agent context should finish around 200k, not 360k.** The analysis agent that produced the
concurrency map came in at 190k, which is the shape to aim for. In force: rules live in
`docs/plans/crush_agent_rules.md` and are read by path, never inlined; agents are told never to read
`src/Cli/Bootstrap.php` (212 KB) or `src/Chat.php` (~6,100 lines) in full; stages pass reports rather
than having each agent re-derive; mutations capped at 5-8 with single-FILE test runs while iterating
and the full suite at most twice.

---

## 0b. RUNNING MORE THAN ONE AGENT AT A TIME

**`docs/plans/crush_code_concurrency.md` is the authority.** Read its §0 and §1 and
nothing else unless you are changing the map. It carries the mode switch (ON, OFF, and
draining a lane that is mid-flight), the lane table for all 27 remaining items, the
directory recipe, and the collision rules.

The four things worth knowing without opening it:

1. **The ceiling is `src/Cli/Bootstrap.php`, not the machine.** 11 of the 27 remaining
   items (41%) must edit it, including the two largest. That is one strictly-serial
   lane; no lane design shortens it. 5 lanes defined, sustainable N = 3, hard cap 4.
   Concurrency buys the other 16 items running alongside — it does NOT give 3× throughput.
2. **Isolation = `cp -a` of the WHOLE repo**, never `sugar-crush/` alone. Measured: in a
   whole-repo copy all 16 `vendor/sugarcraft/*` symlinks resolve INSIDE the copy with
   zero repointing, the copy is a real git repo that can push, and a lane can safely edit
   a sibling lib. **This supersedes §7 below for full-repo lanes** — repointing there is
   not merely unnecessary, it IS the isolation bug. §7 remains correct for the lib-only
   sandbox it was written about. `git worktree` is ruled out: git refuses the same branch
   in two worktrees, and every lane must be on `master`.
3. **The census token.** The one collision that merges CLEANLY and is silently wrong: two
   lanes each adding a `src/*.php` file both edit `assertSame(277, …)` → `278`; git sees
   identical text, auto-merges, and leaves 278 when the truth is 279. One holder at a
   time. 5 of the 27 items add a source file.
4. **No lane ever commits a `.vhs/*.gif`.** CI regenerates and pushes them after every
   batch of changes, so master drift is guaranteed, not occasional. Since GIFs are binary
   a rebase conflict there is not hand-mergeable — and since no lane writes them, it can
   never happen. `git pull --rebase` before every push is routine, not defensive.

## 1. The standing directive

**Run the plan to 100% without pausing.** Do not stop at phase boundaries to report
and wait. After committing a bundle, immediately brief and spawn the next one in the
same turn. Reporting progress is fine; *ending the turn to await approval* is not.

Stop only for (a) a decision genuinely the user's that cannot be resolved from the
request, the code, or a sensible default, or (b) an explicit instruction to pause.

Stated 2026-08-18: *"do not stop anymore keep going until the plan is 100% completed
unless you cannot proceed further without a decision from me or i told you to pause"*.

## 2. The loop — every bundle, no exceptions

1. **Implement** — spawn an agent with a brief carrying the ground truth you measured
   yourself (never the plan's line numbers; see §5).
2. **Review** — spawn a **separate** adversarial agent on the diff. Never the same
   agent, never skip this.
3. **Fix** — spawn a fix agent with the findings.
4. **Verify** — the *supervisor* runs the full suite personally. Do not trust an
   agent's reported totals.
5. **Commit** direct to `master`. No branches, no PRs.

"Don't pause" means don't stop *between* rounds, not skip rounds.

## 3. Sequencing rules

- **Functionality first.** Security/hardening and audit-instrument correctness are
  deferred to the end. **Defer the FIX, never the FINDING** — record every deferred
  item in `docs/plans/crush_code_hardening_backlog.md` with its probe, in the
  What/Where/Severity/Evidence/Step/Blocked-on format. The user's goal is to
  daily-drive sugar-crush while the security pass is still being worked.
- **Counts as functionality, fix it now:** frame-corruption bugs (over-wide rows —
  the diff renderer paints one line per row), automatic data loss, a confirmed RCE
  path.
- **Counts as deferrable:** path-containment gates, permission-surface tightening,
  tool-capability filtering, mutation registers / censuses / inventories.
- **Never remove dormant code — wire it or document it as an intentional seam.** The
  audit's own research agents made several "delete this" recommendations that were
  explicitly overridden. Honor that override.
- **Serialise anything touching the same file.** A *suite run* that loads a file
  another lane is editing shifts `file(__FILE__)` ranges against already-loaded
  reflection and produces phantom failures. Serialise the runs, not just the writes.

## 4. Environment facts

- Commit with a **plain `git commit`**. Never `-c core.hooksPath=/dev/null`, never
  `--no-verify` — the user wants a hook they add later to actually fire. There is no
  active git hook here (`.git/hooks/` is samples only, `core.hooksPath` unset).
- **Never run `caliber` anything.** The Caliber hooks were removed from
  `~/.claude/settings.json`; backup at `~/.claude/settings.json.bak-precaliber-removal`.
  The tracked `<!-- caliber:managed -->` blocks in CLAUDE.md/AGENTS.md are correct for
  machines that *have* Caliber and were deliberately left in place.
- **Never run a global `pkill`** — the `[p]hpunit` bracket trick still kills sibling
  agents' test runs. Kill only PIDs you started.
- Full suite: `vendor/bin/phpunit`, ~2m20s.
  **Allow a 600000ms Bash timeout** or a 2-minute default kills it with exit 143 and
  you will misread that as a failure.
- `failOnRisky`/`failOnWarning` are load-bearing: a warning-only kill is red *purely*
  via exit code while the banner still prints "OK, but there were issues!". Check `$?`,
  never the banner.
- `php-cs-fixer` is NOT installed here, and this lib is not cs-fixer-clean repo-wide.
  Don't report its absence; don't normalise unrelated files.
- Never commit a per-lib `composer.lock`; no `repositories[]` in a lib manifest.
  Verify with `php tools/check-path-repos.php --no-lib-path-repos` (must exit 0).

## 5. THE recurring defect — twenty-six rounds running

**A number or a claim must never travel without its domain.** A count, width, limit,
or behavioural claim that is true of one thing, written next to a different thing.
It has appeared in *every single round*, including inside the work of the agent
fixing the previous round's instance of it, and in the supervisor's own notes.

**Round 18 looked like progress and round 19 priced it.** B3's implementer self-caught
three instances, including a test whose NAME asserted a completeness its body did not
have — real progress. It then reported "28 mutations, 28 killed, 0 survivors". The
independent reviewer ran **55 mutations and found 9 survivors** plus 17 confirmed
findings. So self-catching does not substitute for the review round; it just moves where
the round's findings come from. **Never accept an agent's own mutation score as
coverage** — the number that matters is what a reviewer who did not write the code can
still break.

The single sharpest recurrence: `testAConnectExceptionIsTransient` passes through the
`TransferException` fallback, so replacing its named clause
(`$link instanceof NetworkExceptionInterface`) with `if (false)` survived 2863 tests. The
round before had the identical shape. **A test named after a clause is not a test of that
clause.**

Its companion, found repeatedly since: **tests pin the PRESENCE of a clause and not
its TRUTH.** One review ran 18 mutations — 13 died, and **all 5 survivors made a
clause false while keeping its keywords intact.** So:

- Measure before writing. Name the domain in the same sentence. Say the **unit**
  (this codebase has *estimated* chars/4 tokens vs *provider-counted* tokens, and
  they get confused constantly).
- Prefer deriving a value at runtime over writing a literal.
- A docblock clause nothing asserts is this defect in prose form. Either pin it
  behaviourally or name it as an honest gap — never add a presence check that looks
  like coverage.
- **Changing a fact falsifies every place that described the old one.** Sweep `src/`,
  `tests/`, `README.md` and `sugar-crush/docs/` — a past round's sweep missed `tests/`
  and shipped a stale claim.

## 6. Files that must not be touched by agents

`sugar-crush/phpunit.xml` (the supervisor's) · `/home/sites/sugarcraft/.sugar-crush/config.json`
(git-tracked; md5 must stay `05480c743aff302fd6c06c5a4a4c2210`) ·
`docs/plans/plans_cleaning.md` and `sugar-crush/python_port/` (the user's own work —
both are **git-TRACKED**, 18 files under `python_port/`; an earlier revision of this
line said "untracked", which was a wrong reason for a right rule and invited the
reading that edits there are invisible) · `docs/plans/crush_code_worklog.md` and this
file (supervisor-owned).

`crush_code.md` is the plan — edit its status block inline as items land; it IS
tracked, contrary to an earlier belief.

## 7. Sandbox recipe for mutation testing (hand this to every agent)

`cp -a` — **never `cp -al`**, which preserves relative `vendor/sugarcraft/*` symlinks
that then dangle into a phantom `Interface "SugarCraft\Core\Model" not found`.
Re-point each relative symlink **explicitly** at `/home/sites/sugarcraft/<lib>`;
naive "absolutising" produced self-referential links for three separate agents. Copy
`.sugar-crush/`, `.vhs/`, `examples/`, `bin/`, `workflows/` too or ~10 fixture tests
fail. Assert `ReflectionClass::getFileName()` is inside the sandbox before believing
a run. Judge a mutation by whether the **targeted test file** flips green→red via
`$?` — never by suite totals.

## 8. Known-stable test facts

- The **1 legitimate skip** is `McpClientTest::testLoadConfigReturnsEmptyArrayWhenFileGetContentsFails`
  ("Would require mocking built-in functions"), in **`tests/MCP/McpClientTest.php`** —
  two files share that class basename, so cite the path, not the class. Leave it.
- `SystemPromptWiringTest::testARealChatKeystrokeTurnDeliversBothHalves` is a
  **pre-existing timing flake**. Don't skip it, don't weaken its assertion, don't
  report it as a finding.
- `src/Support/ToolIpcFiles.php:79` `private const STAT_REGULAR_FILE = 0o100000;` is
  an **octal file mode** — a false positive for any `100000` grep-and-replace.
- **The `tests/Cli` hang is DIRECTORY-scoped only, and this matters a lot.**
  `vendor/bin/phpunit tests/Cli` hangs (>4min, backlog E29) — but a single FILE inside it
  runs in **0.054s at rc 0**, and `--filter` against a single file is ~0.02s. Corrected
  2026-08-19: the old blanket "never judge green from a directory-scoped run" was
  discouraging the only affordable mutation harness in this suite. Use single-file runs
  freely for mutation loops; only the FINAL green/red judgement needs the full configured run.
- `tests/Cli/BootstrapSkillSkipsTest.php` run **alone** is rc=1 (`OK, but there were issues!
  Risky: 2`) on a clean tree and contributes 0 risky in the full suite — order-dependent,
  pre-existing, backlog **E36**. Do not chase it as a regression.
- **Six test files did not clear the backend-selection env chain** until `6bc5218b`; with
  either shell-out variable ambient the suite showed 1 error + 10 failures. Now handled by
  `tests/Support/BackendSelectionEnvSandboxTrait.php`, which holds the chain ONCE. If you add
  a `SUGARCRUSH_*` variable to backend selection, add it to that trait's `CHAIN`, not to a
  tenth hand-written list.
- `Chat::shouldPromptIdleCompaction()` **deliberately** duplicates `Runtime`'s
  version ("where Runtime instance is not directly available"). Don't collapse it by
  making `Chat` reach for a `Runtime` it deliberately does not hold.
- **The suite baseline moves every round, including between a bundle's implement and
  fix rounds. MEASURE `HEAD`'s total before briefing; never quote a remembered
  figure.** Bundle B2's brief quoted 6918/70996 when B1's fix round had already moved
  it to 6931/71073, and the implementing agent had to stash, run, and pop to find out.
  That is the supervisor committing the same defect §5 describes — a number written
  next to the wrong domain, here "the baseline" meaning two different commits.
- Two numbers in `tests/Tools/BuiltInToolCorpusTest.php` are censuses over `src/`
  (file count and declaration count) and `BinSugarcrushWiringTest::crushSourceFiles`
  is a data provider over every `src/*.php` file — so **adding a source file changes
  the suite total by more than the tests you wrote**, and both censuses plus their
  prose copies need updating in the same diff.
- **`vendor/bin/phpunit tests/Cli` HANGS at baseline** — over 4 minutes, killed at 250s —
  while the full configured run passes in ~2m26s and every `tests/Cli/*.php` file passes
  individually in under a second. A cross-test leak that `defaultTimeLimit=60` does not
  abort. Measured 2026-08-19 by B3's reviewer, pre-existing and not from that bundle. The
  consequence for every future round: **do not judge green from a directory-scoped run.**
  Judge from the full configured run, or from a single targeted FILE. Mutation work in
  particular has to use file-scoped or curated multi-directory sets.
- **`BASE_BACKOFF_MICROSECONDS = 500_000` → `1` survives 3188 tests**, because every backoff
  assertion is relational rather than literal. That is the "derive, don't hardcode" rule
  working as intended — but it means the prose figures ("500ms doubling, ~1.5s total") have
  no reader and will rot silently if the constant moves.

## 9. The plan lies about its own state — verify, don't trust

Corrected so far (all measured): every §12 line number is stale · §12's drafted text
for `Grep`/`Glob` would have *regressed* them by deleting guidance Phase 8 item 7
added · §12's `dispatchSkill()` fix does not compile (`App` has no
`environmentBlock`) · §12 asserts Grep is POSIX ERE — **it is GNU BRE** · lane D
F3–F7 already landed in `dad90b18` · the `Write` tool and `TerminalBackground::observe()`
are already wired · `StallDetector`'s call-site half is done and it is **not** blocked
on Phase 1 · `KEY_HELP_COLS` is 64, not the 58 the backlog claimed · tracker numbers
#83 and #85 each denote two different findings · #88's figure has eight successive
measurements, so re-measure it *after* a round lands, never before. · Phase 5 item 7's
"feed it from `AssistantMsg` usage data already flowing through `EngineBackend`/`Runtime`"
is false — usage dies at two seams: `Runtime::runBatch()` yields
`new AssistantMessage($content, $toolCalls, $reasoning)` and `Backend::complete()`
returns a `Message`, neither of which has any usage field, and
`grep tokensUsed src/Backend/EngineBackend.php` is empty. There are **three** seams,
not two — `completeAsync()`'s fork unserializes with `allowed_classes => false`. ·
Three providers compute an input/output split, not one: Bedrock, Vertex, **and**
`OpenAIProvider::calculateCost()`, which prices both halves and then reports only the
total. · `VertexProvider`'s *stream* emits the two halves as separate responses, so
streamed usage must be **summed**, not read off the last chunk — and that file's own
`completeStream()` docblock said the opposite. · **Phase 5 item 8 names a harmful
location**: `EngineBackend::runCompleteInChild()` wraps the whole agentic loop, so a
retry there replays every tool call the failed attempt already executed. The seam is
the four single-provider call sites (`Runtime::runBatch`/`runStreaming`,
`AgentManager::executeSubAgent`'s two branches). §10 recommendations 5 and 8 carry the
same instruction and are now marked ⚠ SUPERSEDED. · **Phase 5 item 9's
`MemoryStore::search()` route does not work**: `search()` is a case-insensitive
SUBSTRING match over content/type/tags across every scope, so a whole turn as the query
matches essentially nothing — recall built that way is permanently empty while looking
wired. · **Phase 5 item 10a's "additional working directories" line has no data
source** — zero hits for any multi-root concept in `src/`; the prerequisite is a
settings key plus a multi-root `PathJail` (backlog E26). · `MemoryScope::Local`
normalises to the on-disk scope **`agent`**, so the enum values are not the directory
names.

**Phase 2 measured 2026-08-19 (supervisor, read-only probes) — two queue items were
already done and one names a class that does not exist:**

- **Item 3 (`WorkflowEngine`/`WorkflowRegistry` in `Bootstrap::chat()`) is DONE.**
  `src/Cli/Bootstrap.php:374` passes `workflowEngine: self::workflowEngine($root, $permissionGate)`,
  and that factory (~390) deliberately uses `trustedConfigDirPath()` rather than
  `configDirPath()` because `WorkflowRegistry::load()` reaches a `.php` workflow through
  `require` — a directory whose contents get EXECUTED. Nothing to do.
- **Item 5 (`HookManager::loadFromFile()` in `Bootstrap::hooks()`) is DONE, and better
  than the plan's instruction.** `Bootstrap::hooks()` (1569-1599) calls
  `registerBuiltIns()` and then `loadEntries(self::hookFileEntries($path), $path)` per
  candidate file, fail-closed into `PermissionConfigException`, deduplicated by realpath,
  with an unreachable-ancestor refusal and a per-project trust opt-in. It does NOT call
  `HookManager::loadFromFile()` directly, on purpose: entries are read ONCE PER PROCESS
  so a session cannot install hooks into itself mid-session (a `>> ~/.sugar-crush/hooks.yaml`
  plus a Ctrl+P provider switch used to do exactly that). The plan's literal instruction
  would re-read the file on every hook-manager build and reopen that hole.
  Its stated prerequisite is also genuinely satisfied: `df0a563b` really is Phase 1
  item 2, and `ScriptHook::EXIT_ASK = 3` / `EXIT_MODIFY = 4` exist.
- **Item 7 names `LspTool`, which does not exist.** There is no `src/Tools/LspTool.php`.
  What exists is `src/LSP/` — `LspClient`, `LspConnection(Interface)`, `LspCache(Interface)`,
  `LspResponse`, and two exception types. So item 7 is "write the tool", not "add
  `implements Tool`" to something.
- **Item 4's own source confirms it is unwired**, so the queue entry is right for once:
  `src/Commands/CommandLoader.php`'s class docblock says "NOT YET REACHABLE FROM
  bin/sugarcrush: nothing constructs a CommandLoader in production yet" and defers the
  `$ARGUMENTS`/`$1`/`` !`cmd` ``/`@file` substitution. That docblock also claims
  `src/Chat.php` "is owned by a concurrent track" — stale prose to fix when item 4 lands.
- **Item 8 (`CommandBackend` → `StreamingCommandBackend`) is HARMFUL AS WRITTEN.** Full
  measurement in `/tmp/…/scratchpad/c1-measured.md`; the short form: both classes take
  `string|array` and receive the identical stdin payload, but `StreamingCommandBackend::complete()`
  does `rtrim($line, "\r\n")`, drops empty lines, and `implode('', $tokens)` — so the
  wrapper `CommandBackend`'s own docblock recommends (`curl … | jq -r '.content[0].text'`)
  comes back as one run-on line with every newline and blank line deleted. It also carries
  a blanket `$timeout = 120` total-request cap (against the standing directive) whose
  expiry message reports ITERATIONS where the user configured SECONDS, and a no-op
  ternary `is_array($this->command) ? $this->command : $this->command`. The two output
  protocols are mutually exclusive; wire the dormant seam behind its own opt-in instead of
  swapping the existing one.
- **Item 1's "duplicate `McpClient`" is a BASENAME collision, not a PSR-4 one.**
  `SugarCraft\Crush\McpClient` (stdio/JSON-RPC to Claude Code) and
  `SugarCraft\Crush\MCP\McpClient` (Guzzle HTTP) coexist legally. The rename is still
  worth doing — it is what disambiguates `tests/McpClientTest.php` from
  `tests/MCP/McpClientTest.php`, which has already caused one mis-citation of the single
  legitimate skip. The root class has **no `src/`, `bin/` or `examples/` call sites**; it is
  a dormant seam reached only from its own test.

## 10. Current state and the queue

**Current state: see the "Execution status" block at the top of `crush_code.md`** for
what is complete, and §11 below for what is next. Verify the suite yourself before
believing any number written anywhere.

**CURRENT STATE, 2026-08-19.** Last CODE commit is **`47ee2c86`** (bundle W1, the user's live render
bug — long replies wrap instead of being cut, and pane width is now an invariant). Before it,
**`3b0ba8fe`** (bundle C3, MCP tools behind a trust gate). Supervisor-verified **7577 / 87648 / 1,
exit 0** against LOCAL sibling symlinks. **A 2-skip run means you are not testing the monorepo** — see
the vendor section below.

**Bundle W2 is IN FLIGHT** (input blocked while a turn runs, user-reported). Nothing of it is
committed.

**Phase 2 items 1, 2, 3, 5, 6, 8 complete. Phases 0, 1 complete. PHASE 5 IS COMPLETE** —
item 10b was measured 2026-08-19 and found already done by Bundle A (`bf3495f5`); see
"BUNDLE B4 NEEDED NO CODE" below before re-planning it.
**48 of 75 plan items, 27 left.** See the `#N`-tracker section below before answering any question
about totals — the answer is not the sum of the series.

## BUNDLE W1 IS COMMITTED — `47ee2c86`. The user's live render bug is fixed.

**A user bug report that jumps the audit queue**, because frame corruption counts as functionality
under §3. Reported while daily-driving: long assistant lines "not wrapped but cut off", then a blank
line, then unrelated content.

**Four rounds: implement → review → fix A → fix B, then commit.** Supervisor-verified at every
gate. Final: **`Tests: 7577, Assertions: 87648, Skipped: 1`, rc=0, 3m01s** (entry baseline was
7387 / 76813 / 1). `Renderer.php` +457/-7 plus `tests/Renderer/PaneWidthInvariantTest.php` (187 tests,
10,773 assertions). Full round-by-round detail is in the worklog.

**Twelve of twelve mutations killed, each RE-VERIFIED BY ME with my own edits** — which is the whole
reason this bundle is trustworthy, see the next section.

**MY DIAGNOSIS WAS WRONG ABOUT THE MECHANISM — now CONFIRMED wrong by the review round.** I wrote
that the terminal soft-wraps the over-wide row and candy-core's absolute `cursorTo()` paints later
rows at stale coordinates. The implementer reports the hosted path never emits an over-wide row at
all: `ChatPane.php` wraps the body in `Style::new()->width($width)` and candy-sprinkles' `width()`
TRUNCATES (`candy-sprinkles/src/Style.php:1000-1004`, `Width::truncateAnsi`), with
`Tui/Renderer.php:394` clipping the composed frame. My 204-column measurement was real but taken
against standalone `Chat::view()` — a number written next to the wrong domain, §5 again, in the brief
whose whole job was ground truth. **The user's own words were "cut off", which IS truncation; I
replaced an accurate description with a theory.** The fix is the same either way, which is the only
reason it cost nothing.

**The absence that let it ship: no test among 7,387 measured row width against the terminal.** Proven,
not asserted — with the src change alone and the new file absent, the suite came out byte-identical to
baseline. Not one test rendered prose long enough to wrap at its fixture width.

## ⚠️ THE LESSON FROM W1 THAT MUST SURVIVE INTO EVERY LATER BUNDLE

**Re-verify an agent's mutation table yourself, with your own edits, before believing a bundle.**

W1's chain produced three false "it's dead" reports, each caught only by the next gate:

1. The implementation round reported **8 mutations, 8 killed**. An independent reviewer ran 29 and left
   **11 surviving**, including that the bundle's headline invariant was provably FALSE for emoji
   clusters.
2. Fix round A reported **all 11 now dead** — and disclosed, unprompted, that **5 of the 11 definitions
   were its own reconstructions** because the reviewer's harness took them as argv and never wrote them
   down. Comparing against the reviewer's table, **4 of those 5 were different mutations entirely.** I
   applied the reviewer's real definitions myself: **MU11, MU12, MU25, MU29 all still SURVIVED.**
3. Fix round B closed all four. I re-ran all four myself with my own edits before committing.

**And the reason those four resisted is the transferable insight:** all three `$labelRoom` mutations
were unkillable by ANY width assertion **by construction**, because fix round A's own `hardFit()`
truncates an over-wide tool row regardless of what the label arithmetic computed. **A fix can make its
neighbours' tests vacuous.** When a bundle adds a safety net, re-ask what the older assertions still
prove — often the answer is "nothing they used to".

Practical rule now in force: **write mutation definitions as the exact edit, verbatim, in the report.**
"MU11" is not a definition. `$labelRoom = … - Width::of($status) - 1;` → drop the `- 1` is.

## ✅ BUNDLE W2 IS COMMITTED — `a8d8ec75`. Input works mid-turn and Enter queues.

**Suite verified by me personally, not taken from the agent's report: 7602 / 88074 / 1, exit 0**
(entry baseline 7577 / 87648 / 1). 29 mutations defined as verbatim edits, all 29 killed by the new
`tests/Chat/InFlightInputQueueTest.php` (25 tests, 390 assertions). Invariants re-checked at the
gate: config md5 unchanged, stash list 9, 16 vendor symlinks so the skip count stayed 1,
`phpunit.xml` untouched, `check-path-repos --no-lib-path-repos` rc=0.

**The measured finding that shrank the bundle:** the async half was already done, so the user's
framing ("it shoudl be doing these requests asynchronously") was the one thing measurement
contradicted — nothing was blocking. It was one policy `return` plus a hidden caret.

**Six existing tests changed, and every one had pinned the OLD policy** —
`testKeystrokesIgnoredWhileInFlight` became `testTypingReachesTheDraftWhileATurnIsInFlight`: same
property, opposite value. That is the category to scrutinise hardest in any review, and it is why the
review brief pointed at those six diffs by name.

**Two drain decisions worth remembering:** the double-Escape cancel deliberately HOLDS the queue
(not dispatched, since it may be what the user was stopping; not dropped), and a spend-cap-refused
prompt goes back at the queue HEAD because `spendCapTurnRefusal()` keeps the draft and writes no
echo, so it would otherwise vanish with the restored draft.

## ✅ BUNDLE W5 IS COMMITTED — `f8fd9cfa`. Three commands no longer kill the app.

**A USER-REPORTED FATAL, and the most severe report so far.** A bare `/websearch` printed its usage
line and then died: *"Argument #1 ($msg) must be of type SugarCraft\Core\Msg, int given"*.

Three sites — `/share`, `/websearch`, `/agents` — ended their failure branch with
`return [$this, static fn() => print $output];`. **`print` is an EXPRESSION evaluating to `int 1`**,
so the closure is a `Cmd` returning an int; `Program::scheduleCmd()` dispatches whatever non-null a
Cmd returns and `dispatch()` requires a `Msg`. Any non-zero exit from those three took the app down,
and `/agents` is one Ctrl+A away.

Writing to stdout was the wrong shape even before the TypeError — the screen belongs to candy-core's
frame renderer. The `/agents` site's own comment said "output error but don't add to history", which
is why the failure had nowhere to appear. All three now route through one
`commandFailureResponse()`: the command is echoed, the output lands as **`Role::System`** (not
assistant — history is replayed to the provider, so a failure notice filed as a model turn becomes
something the model believes it said), and the Cmd is null.

**Why nothing caught it, which is the transferable part:** the suite covered these three commands
only on their SUCCESS paths, where the Cmd is null. The failure branch was the one line of each
handler no test entered — and it is the line that runs when a user gets an argument wrong, the
ordinary case rather than the exotic one. **Worse, one test pinned the bug AS the feature:**
`ChatTest::testPaletteEnterOnShareSessionDispatchesRealHandlerAndCloses` asserted
`assertNotNull($cmd)` and its comment named "the print-closure path" as its proof that dispatch
reached the real handler. Its claim was right and was kept; the evidence it cited was the crash.

**Proven against the unfixed code rather than asserted:** with the three call sites reverted, 6 of
the 7 new tests fail. That exercise also caught a vacuous pass in my own new test —
`testTheFailureNoticeIsNeverAnAssistantTurn` indexed `$added[1]` without counting first, so against
the unfixed build (which appended nothing) it compared a role against an undefined key and passed
with a warning. Suite: **7609 / 88105 / 1, exit 0.**

## ⚠️ BUNDLE W4 IS QUEUED — Tab does not complete a partial `/command`. USER-REPORTED.

Reported: Tab *"should expand your typed command to the full command currently highlighted .. currntly
it switches your active other window (like between skills/tools/agents/etc) which is fine normally but
when typing a /command and its showing matching command results the bhavior should chang"*.

**Measured as a PRECEDENCE problem, not a missing feature.** Bare Tab never reaches `Chat`:
`src/Tui/KeyboardHandler.php:174` claims it **unconditionally** inside the shell's "does the shell own
this key" predicate and cycles panes. `Chat` has no bare-Tab arm at all — its three `KeyType::Tab`
hits are a comment at 1343 and two Ctrl+Tab arms (1475, 4787) — so bare Tab falls to the match
default and leaves the buffer alone. The slash-menu state is already public
(`slashMenuMatches()`/`slashMenuMatchResults()`/`slashMenuIndex()`, ~8290-8343) and "the popup is
showing" is exactly `slashMenuMatches() !== []`.

**The idiom to follow already exists two lines below the Tab claim:** the `Escape` arm is conditional
on `$app->pane !== Pane::Chat`, and `shellOwnsKeyboard($app)` above it is the established
modal-owns-the-keyboard predicate. So a conditional Tab claim is the existing pattern.

**The test that matters** drives a real keystroke sequence through the shell — type `/comp`, then bare
Tab — because a test that calls Chat's Tab arm directly cannot see the precedence bug and would pass
on the broken build. The negative must be pinned too: with no slash menu open, bare Tab must still
cycle panes, which is the behaviour the user called "fine normally". And no keystroke may put a
literal `\t` in the buffer (`KeyHelpTest`'s byte map, asserted both ways).

## ⚠️ THE ORIGINAL W2 BRIEF (kept for its measurements)

**Second live bug report, same priority class as W1.** Verbatim: *"when i send a chat message and its
processing the request im unable to type new text into the chat . im alaso unable to use things like
Ctrl-P to bring up the command pallete … new messages should be typable and sendable (well really
queued for processing if its mid processing the previous message) during that time"*, then *"it shoudl
be doing these requests asynchronously anyways it shouldnt be blocking"*.

**Full measured brief: `/tmp/…/scratchpad/w2-measured.md` (128 lines).** The headline, because it
decides the size of the bundle: **the async half is already done.**
`Chat::scheduleBackendCompletion()` (`:5231`) returns `Cmd::promise(fn() => $backend->completeAsync(…))`
and `completeAsync()` forks a child. Driven proof the loop delivers keystrokes mid-turn: with
`inFlight: true`, an `Escape` KeyMsg mutates state (`lastEscapeAt` null → set).

**The defect is one policy return: `src/Chat.php:1141-1146`,** `if ($this->inFlight) { return [$this,
null]; }` — a blanket swallow, so everything lexically below it is dead for the whole turn. Measured
`inFlight` vs idle for the same KeyMsg: `Char 'x'` leaves `inputBuf` empty vs `'x'`; `Ctrl+P` leaves
`palette` null vs OPEN.

**Do not just delete the swallow** — its stated reason ("the user racing ahead and queuing another turn
into a half-formed history") is real. Split it: keys reach the input box and the overlays, and **Enter
enqueues instead of dispatching**.

Two constraints from the brief: `dispatchTurn()` (`:4502`) has exactly two callers and its docblock
warns a third copy is where the generation, cancellation token, checkpoint or title Cmd goes missing —
**the drain must call it**; and `scheduleParkedCompaction()` (`:6287`) already implements "hold a
submission, dispatch later" for the 85% tier, so reuse that shape rather than inventing queue state.

**The census trap:** `grep "'inFlight' => false" src/Chat.php` gives 26 — but 4 are comment prose and
one (`:4586`) is a serialized checkpoint payload, not a state transition. **21 are real writes**, and a
queue draining at only one strands the user's message. One of the two that settle a real turn is the
CANCEL path, where draining would send a message the user just tried to stop.

**Two more seams I measured after writing that brief, both load-bearing:**

1. **`Renderer.php`'s `$cursor = $chat->inFlight ? '' : '█';` HIDES the input cursor while a turn
   runs.** So even with typing unblocked the box still looks dead — this is the second half of the
   user's complaint and the feature is invisible without it. (Re-grep the line; `Renderer.php` was
   rewritten by W1.)
2. **The 21-site census collapses to ONE real drain point.** `finishToolCalls()` sets
   `'inFlight' => true` (`:2333`), so a tool-calling turn keeps running and settles at a LATER
   `AssistantMsg`. The only place an ordinary turn ends is `update()`'s AssistantMsg no-tool-calls exit
   (`:905-910`), which returns a **null Cmd** — and that null is exactly where the drained turn's Cmd
   goes. `:1106` is the cancel path and draining there is WRONG. The rest are command responses that
   set and clear `inFlight` inside their own response; the ones needing an individual decision are the
   compaction paths and `backgroundDispatch()`, because a PARKED submission deliberately holds
   `inFlight` true with no turn running.

**The subtle test this bundle can fail:** a queue that drains on any `AssistantMsg` fires MID-TURN on a
tool-calling turn. `finishToolCalls()` keeping `inFlight` true is what makes that a live hazard.

Brief: `/tmp/…/scratchpad/w2-brief.md` (184 lines) + `w2-measured.md` (128 lines), both self-contained.

## ⚠️ BUNDLE W3 IS QUEUED — the shell chrome ignores the theme. USER-REPORTED.

**Third live bug report, 2026-08-19, same priority class as W1/W2.** Reported as *"when showing the
menus up top none of them have borderes making where the menu listing txt starts/ends difficult to
tell at a glance ... (no the menu names the menu items list)"*, then corrected by the user a minute
later: *"i stand corrrectd.. there are borderes.. just foreground matchs background color so invis"*.

**The user's correction is the right diagnosis and it is sharper than the original report.** Do not
implement the first version of this bug — there is nothing to add. Measured:
`MenuBar::dropdownLines()` (`src/Tui/Components/MenuBar.php:431-440`) already draws a full box, and a
probe of `renderDropdown()` with menu 1 open returns 12 rows, every one exactly 18 cells wide, with
matched `┌─…─┐` / `│ … │` / `└─…─┘`. `Tui/Renderer.php:400` splices it in AFTER `clipWidth`/`clipTail`
specifically so it cannot be trimmed. Nothing is missing and nothing is clipped.

**The root cause is that `src/Tui/` is theme-blind, and it is the whole directory, not the menu.**

| | measured |
|---|---|
| `src/Tui/` files with hardcoded `Color::hex('#…')` | **10** (~37 distinct colors) |
| `src/Tui/` files that consult `Theme` | **0** — `SettingsPane`'s single `Theme` hit only *lists* theme names |
| `src/Renderer.php`, the transcript | fully themed: `$theme->border`, `$theme->userLabel`, `$theme->systemLabel` |

`MenuBar` does not import `Theme` at all (`grep -c Theme` → **0**) and hardcodes `#00ffaa` (active),
`#fde68a` (title), `#6b7280` (border), `#e5e7eb` (item text), `#7d6e98` (inactive tab).

**Why that produces "foreground matches background":** `Theme::adaptive()` DETECTS the terminal
background — `TerminalBackground::isDark()` over an OSC 11 query plus `COLORFGBG` — and returns a dark
or a light palette accordingly. So on a light terminal the transcript repaints for light and the shell
chrome stays painted for dark: a mid-gray `#6b7280` border and near-white `#e5e7eb` item text, both of
which disappear against a light background. The same applies to any `/theme` switch: it moves the
transcript and leaves the shell behind.

**A design constraint to settle BEFORE any agent starts editing, because there is no obviously right
answer and inventing one silently is how this goes wrong:** `Theme` exposes only `name`, `markdown`,
`border`, `userLabel`, `assistantLabel`, `systemLabel`. There is **no background token, no muted/dim
token and no selected/accent token** — and the shell needs all three (the dim border, the `(empty)`
and inactive-tab gray, the `#00ffaa` selected row). So this bundle must either add tokens to `Theme`
(and then every `Theme` factory, `byName()`, `adaptive()`, `default()` and `pair()` must set them) or
map the shell onto the four that exist and accept losing a distinction. **Measure which before
choosing** — and whichever it is, the fix is not complete until a light-background run is driven, since
that is the only configuration in which the reported symptom appears at all.

**QUANTIFIED, and it moves the surface one step from where BOTH of us put it.** `Color::luminance()`
is a proper WCAG relative luminance, so the contrast ratio against each palette's own background is
computable. Measured for all five of `MenuBar`'s hardcoded colours:

| hardcoded colour | role | on dark `#0e0e14` | on light `#fafafa` |
|---|---|---|---|
| `#00ffaa` | selected dropdown row | 14.55:1 | **1.27:1 — invisible** |
| `#fde68a` | menu title | 15.45:1 | **1.19:1 — invisible** |
| `#e5e7eb` | dropdown item text | 15.54:1 | **1.19:1 — invisible** |
| `#6b7280` | dropdown border | 3.98:1 | 4.63:1 — visible |
| `#7d6e98` | inactive pane tab | 4.17:1 | 4.42:1 — visible |

**All five pass on dark; three of five are invisible on light — and the two that SURVIVE are the
borders.** So the border glyphs are not the casualty: the item text, the titles and the selected-row
highlight are. The user's first report ("where the menu listing txt starts/ends difficult to tell")
described the text washing out, which makes a box with visible edges read as edgeless; the user's own
correction then named the border, and the measurement says the border is fine. **Both readings had the
mechanism right and the surface wrong, and only the ratio settles it.** Note the practical
consequence, which is worse than cosmetic: at 1.27:1 the user cannot see which row Enter would run.

**A coincidence worth naming, because it says where these colours came from:** `#6b7280` is exactly
the LIGHT palette's `muted` and `#e5e7eb` is exactly its `border` — the light palette's own tokens,
frozen into the wrong roles. These were eyeballed against one background and then rendered against
whatever the terminal is.

**This also confirms the detection half is working, which narrows the fix.** With the transcript
themed and the shell not, a light terminal produces exactly one broken surface — the shell — which is
what was reported. Had `TerminalBackground::isDark()` been wrong, the transcript would look wrong too.
So do NOT go looking at the detector.

**THE DESIGN QUESTION IS SETTLED, and cheaply — do not add new colours.** `Crush\Theme::pair()`
(`src/Theme.php:115`) is the ONLY construction site of `Crush\Theme` in the entire codebase (one
`new self(`), and it already receives a `SugarCraft\Sprinkles\Theme` carrying **13** tokens:
`foreground`, `background`, `primary`, `secondary`, `accent`, `muted`, `error`, `warning`, `success`,
`info`, `border`, `separator`, `cursor`. It projects **four** of them and discards nine. So the shell's
missing background/muted/accent tokens **already exist upstream in both palettes** —
`SprinklesTheme::dark()` and `::light()` — and widening `Crush\Theme` costs one constructor plus one
`pair()` body, with correct light AND dark values for free. Inventing hex values, or mapping the shell
onto the four existing fields and losing a distinction, are both the wrong answer now.

**THE TEST MUST PIN CONTRAST, NOT PRESENCE — this is the whole point of the bundle.** A test asserting
"`MenuBar` uses `$theme->border`" is the recurring defect in its purest form: it pins that a clause is
present and says nothing about whether the result is legible, and it would pass against a theme whose
border equals its background. Assert the RATIO: for every colour the shell paints, against the
resolved background, in BOTH palettes, require ≥3:1 for chrome/glyphs and ≥4.5:1 for text. Written that
way the assertion covers all ten `src/Tui/` files and every theme added later, and it would have
failed on the build the user is running — the table above is that test's output.

### W3 CORRECTED BY THE USER AGAIN — the contrast table above measures a background NOTHING PAINTS

**The user reported using the `ansi` theme, where the menu border is invisible — and asked whether
that contradicts the measurement. It does, and finding out why widened the bundle.**

All six themes, each shell colour against **that theme's own background token**:

| theme | bg token | selected `#00ffaa` | title `#fde68a` | item `#e5e7eb` | border `#6b7280` | inactive `#7d6e98` |
|---|---|---|---|---|---|---|
| dark | `#0e0e14` | 14.55 | 15.45 | 15.54 | 3.98 | 4.17 |
| light | `#fafafa` | **1.27** | **1.19** | **1.19** | 4.63 | 4.42 |
| dracula | `#282a36` | 10.77 | 11.43 | 11.50 | **2.95 FAILS** | 3.09 |
| tokyoNight | `#1a1b26` | 12.93 | 13.72 | 13.80 | 3.54 | 3.71 |
| ansi | `#000000` | 15.88 | 16.86 | 16.96 | 4.34 | 4.56 |
| adaptive | — | resolves to the `dark` or the `light` row at runtime via `TerminalBackground::isDark()` | | | | |

**Under `ansi` every shell colour PASSES against the token, and the user sees an invisible border. The
table is wrong, not the user — and the reason is a discarded measurement.**

`BackgroundColorMsg` (candy-core) carries the terminal's real background as `public readonly int $r`,
`$g`, `$b` plus a `hex()`. `TerminalBackground::observe()` (`src/Tui/TerminalBackground.php:89`) does:

    self::$observed = $msg->isDark();

**The app sends an OSC 11 query, receives the terminal's true background RGB, and reduces it to one
bit.** So no contrast decision anywhere in this app — including every number in the table above — is
taken against the real background. Each theme substitutes its own ASSUMED background token, and
`ansi`'s assumption is pure `#000000`. That assumption is what scores `#6b7280` at 4.34:1.

**The corroborating number is dracula's row: `#6b7280` on `#282a36` is 2.95:1 and already fails.** A
mid-dark terminal background — the ordinary case — puts that border under 3:1. The user's terminal is
in that range, which is precisely the reported symptom, and no theme token describes it.

**Consequence for the fix, and it is a prerequisite rather than a nicety:** the contrast assertion
prescribed above must be evaluated against the RETAINED background, so `observe()` has to keep the RGB
it is already handed (the `hex()` is right there) instead of collapsing to a bool. Without that the
test validates the shell against `#000000`/`#0e0e14` fictions and passes on exactly the build the user
is looking at. **Keep `isDark()` — the boolean has real callers and `adaptive()` needs it — and ADD the
retained colour beside it.** This is a widening of a live seam, not a rewrite, and it is dormant-code
wiring of the kind the standing directive protects: the RGB is already arriving.

**A SECOND, INDEPENDENT DEFECT the user's theme choice uncovered.** `Color::ansi(8)` emits
`\e[90m` — the palette entry the user's terminal actually controls — **only at `ColorProfile::Ansi`**.
Measured emissions: `Ansi` → `\e[90m`; `Ansi256` → `\e[38;5;244m`; `TrueColor` →
`\e[38;2;127;127;127m` (`toHex()` `#7f7f7f`). `ColorProfile::detect()` in this environment
(`TERM=screen-256color`) returns **`Ansi256`**. So the `ansi` theme — whose entire purpose is deferring
to the terminal's own 16 colours, and which `SprinklesTheme::ansi()` builds exclusively from
`Color::ansi(0..8)` — is silently up-converted to absolute 256-cube values on any terminal that
advertises more than 16 colours. **A user selecting `ansi` to make the app match their terminal does
not get that**, which is why this user's theme choice could not rescue the hardcoded shell. Whether
that belongs in W3 or in the ledger is an open call; it is a candy-core/profile-policy question, not a
`src/Tui/` one, so it is probably a separate item — but it must not be lost, because it makes the one
theme that would otherwise be immune to this whole bug class behave like the others.

**Two lessons, both instances of the chain's dominant defect:**

1. **I measured against the wrong background and reported ratios as if they described the screen.** The
   domain of every number in the first table is "this theme's declared background token", not "the
   user's terminal" — and the two differ by exactly the amount that makes the bug visible. §5, and it
   is the third time this session that a number of mine travelled without its domain.
2. **A test asserting contrast against a theme token would have inherited the same defect** and shipped
   green. The prescription "pin the ratio, not the presence" was right and insufficient: pin the ratio
   **against the retained real background**.

**Ordering:** W3 goes after W2 (it touches `src/Tui/` and `src/Theme.php`, W2 owns `src/Chat.php` and
`src/Renderer.php` — no file overlap, but the strictly-sequential rule is about suite runs, not files).
It is a live-bug bundle, so it precedes the audit queue, and `#88` moves behind it for the same reason
it moved behind W2: W3 will change the suite figure again.

## ✅ BUNDLE B4 NEEDED NO CODE — Phase 5 item 10b was already done

**Measured 2026-08-19 while the W2 agent was still running, read-only, off the files W2 owns.**
Item 10b asks to differentiate the hardcoded `AgentDefinition` preset prompts, "currently generic
one-liners that don't even mention the skills they're granted". They are not one-liners.
`git show bf3495f5 -- src/Agents/AgentDefinition.php` shows the generic one-liners as the `-` side
of the diff, and that commit's own message says **"Phase 5 items 1, 2, 3 and item 10's preset half."**

Three errors in the plan's own tracking of this item, all in the same direction:

1. **"10b (the preset prompts) untouched"** — written during Bundle B3, which closed 10a, asserting
   an absence nobody re-measured after Bundle A had closed the other half. `crush_code.md:25` then
   went further and called the earlier "Phase 5 is finished" note *wrong*; **the earlier note was
   right and the correction was the error.** §5 in the item tracker rather than in a code comment.
2. **"the five hardcoded presets"** — there are **six**. `fromType()` builds `coder`, `reviewer`,
   `debugger`, `architect`, `tester`, `devops`.
3. The parenthetical is now pinned in **both** directions, which is more than the item asked for:
   `AgentDefinitionTest::testEveryPresetNamesEverySkillItIsGranted` (a granted skill the prompt
   forgets) and `::testNoPresetPromptNamesASkillItIsNotGranted` (a prompt that tells a sub-agent to
   consult a skill it was never handed). The second reads the skill universe off
   `SkillLoader::loadBuiltInSkills()` instead of a literal list, so a skill added under
   `src/Skills/BuiltIn/` is covered the moment it exists.

**The transferable rule, and it is the workflow's step 1 for a reason:** a queue row is not evidence.
This is the second time in this plan that "verify before writing" turned a bundle into a no-op — the
other is Phase 6 item 1's `__DIR__` bug, flagged the same way in the bundle table. **Every bundle's
measure step must re-derive the defect from the source, and a bundle whose defect has evaporated
must be reported as closed rather than re-implemented.** An agent handed "differentiate the prompts"
with no measure step would have rewritten six perfectly good prompts and called it progress.

## 📏 C5 IS MEASURED — Phase 4 item 6, and one of its four parts is already done

**Read-only inventory, 2026-08-19.** The item bundles four unrelated changes; they are not equally
real.

| part | measured state |
|---|---|
| subcommands `mcp list`, `session list`/`delete`, `models`, `doctor`, `completion bash\|zsh\|fish` | **absent.** `run` is the ONLY subcommand — `ArgvParser.php:140`, `if ($arg === 'run' && !$promptRequested)`, an alias for `-p` |
| `--config <path>` | **absent** from the parser entirely; no `--config` token in `ArgvParser.php` |
| a 0/1/2 exit-code convention | **ALREADY DONE**, and thoroughly — `bin/sugarcrush` documents 2 = "nothing was attempted and a retry cannot help" at five separate exits, with 1 reserved for "ran and failed" |
| warn-not-silently-drop on unrecognised `--output-format` | **REAL DEFECT.** `NonInteractive.php:507` states it in its own docblock: *"Any value other than `self::FORMAT_JSON` falls back to plain text."* `--output-format xml` gets text, silently, exit 0 |

So C5 is three parts of work, not four. The `--output-format` half is the sharpest and the smallest:
the value is accepted verbatim by `ArgvParser` (`:208` `substr($arg, 16)`, `:215` `$argv[++$i]`) and
then compared for equality against `FORMAT_JSON` in three places (`:388` `emitErrorDocument`, `:524`
`format`), so an unrecognised value is not merely unvalidated — it is *indistinguishable from `text`*
at every consumer. Validation belongs in the parser (one place, before any dispatch), which is also
where `usageError` already lives, so it can reuse the exit-2 usage path rather than inventing a
warning channel.

**A trap for whoever implements the subcommands:** `bin/sugarcrush` parses argv and dispatches
`--help`/`--version` **before** touching `Bootstrap` or `Program`, deliberately, so they answer on a
machine with no provider, no config and no TTY. Every new subcommand is in that same class and must
dispatch in the same place — `mcp list` and `doctor` that open the alt-screen would be Phase 0
item 3's bug all over again. `doctor` in particular must stay distinct from the model-invoked
`doctor` tool, which is the wording the item chose on purpose.

## 🤖 THE WORKFLOW — how the rest of the plan gets executed (requested by the user 2026-08-19)

The user asked for a **workflow** to finish the remaining plan, and started it. The script is persisted
under the session directory and its path is printed in the `Workflow` tool result; a copy of the intent
lives here so it can be rebuilt from scratch.

### The shape, and why it is this shape

**One bundle at a time, strictly sequential, committing between bundles.** Not because parallelism is
hard, but because it is measurably wrong here: two uncommitted tracks in one working tree make every
reviewer flag the other track's diff, and a *suite run* that loads a file another lane is editing shifts
`file(__FILE__)` ranges against already-loaded reflection and produces phantom failures. Parallelism
inside a bundle is fine (independent review lenses); parallelism across bundles is not.

Per bundle, the workflow runs the §2 loop as separate agents, because the whole value is that the
reviewer did not write the code:

1. **measure** — read-only, produces the ground truth the brief will carry. Never trust the plan's line
   numbers (§9); they are stale by construction and C3 moved `Bootstrap.php` by ~486 lines.
2. **implement** — against the measured brief only.
3. **review** — adversarial, on the diff, with a mutation budget. **Separate agent, never skipped.**
4. **fix** — given the findings.
5. **re-verify** — a *fresh* agent that re-runs the review's mutations **from their verbatim
   definitions** and reports which still survive. This step exists because of W1: see the LESSON
   section above. Three "it's dead" reports in one bundle were false.
6. **commit** — only after the full suite is green, only that bundle's paths.

### The one honest compromise

Up to now **the supervisor ran the full suite personally at every gate** and that is what caught four
false kills in W1. A workflow cannot do that — step 5 is the substitute, and it is weaker: it is another
agent, not me. **So after each workflow run returns, re-run the suite yourself and spot-check the
bundle's mutations before trusting the commit.** If a workflow run reports green and a personal run
disagrees, believe the personal run and treat the whole batch as suspect.

### Bundle order (28 plan items left, grouped)

Docs go LAST among features so they describe what actually got built, and the plugin system is last of
all per the existing queue note.

| # | bundle | plan items |
|---|---|---|
| ~~0~~ | ~~**W2**~~ | **COMMITTED `a8d8ec75`** — 7602/88074/1, 29/29 mutations |
| ~~0b~~ | ~~**W3**~~ | **COMMITTED `6c1e51c8` + `fe7ce954`** — shell themed, bg RGB retained, ansi emits ansi |
| ~~0c~~ | ~~**W4**~~ | **COMMITTED `3bc51735`** — Tab completes the highlighted `/` command |
| ~~0d~~ | ~~**W5**~~ | **COMMITTED `f8fd9cfa`** — the `print`-returns-int fatal in three commands |
| ~~1~~ | ~~**B4**~~ | **DROPPED — already done** by Bundle A (`bf3495f5`), measured 2026-08-19. No code. Phase 5 is closed |
| 2 | **C5** | Phase 4 item **6** — split into **C5a** (`--output-format` validation + `--config`) and **C5b** (real subcommands). **RUNNING** under `wf_85ae4115-4fe`. The exit-code convention part is ALREADY DONE |
| 3 | **C4a** | Phase 2 item 4 part 1 — wire `CommandLoader` as an instance into `Chat`; `$ARGUMENTS`/`$1..$9`. **RUNNING** under `wf_85ae4115-4fe` |
| 4 | **C4b** | Phase 2 item 4 part 2 — `` !`cmd` `` (ReactPHP `Process`) + `@file` |
| 5 | **C6** | Phase 2 item **7** — WRITE `LspTool implements Tool` over the existing `src/LSP/LspClient.php` |
| 6 | **D** | Phase 3 items **2-5** — `candy-focus\FocusRing` in `Tui\Pane`; `sugar-veil` |
| 7 | **E** | Phase 6 items **1-6** — item 1's `__DIR__` bug is largely already fixed; verify before writing |
| 8 | **G1/G2** | Phase 8 items **3, 4, 6, 8, 9, 10, 11, 13, 15** — split into two bundles |
| 9 | **F** | Phase 7 items **3-6** — authoring/reference docs |
| 10 | **C7** | Phase 2 item **9** — unified `crush-plugin.json` + `PluginLoader`. Explicitly last |
| 11 | **#88** | the stale README suite figure — standalone, nothing else in flight |
| 12 | **hardening** | `crush_code_hardening_backlog.md` E1-E50. LAST, per the user's standing directive |

### Non-negotiables every workflow agent must be handed

These are not style preferences; each one is a bug this project already had.

- **Never** `git stash`/`checkout`/`reset`/`commit`/`clean` in a non-commit step. **Never**
  `composer install`/`update` (it silently replaces `vendor/sugarcraft/*` symlinks with Packagist copies
  — the only signal is the skip count going 1 → 2). **Never** a global `pkill`. **Never** `caliber`.
- **Skips must be exactly 1.** A 2-skip run is not testing the monorepo and its figures are void.
- Judge by `$?`, never the banner; **redirect, never pipe** (`phpunit | tail` reports `tail`'s code);
  never run `tests/Cli` as a DIRECTORY (hangs >4 min), single FILES are ~0.05s; full suite needs a
  **600000ms** timeout.
- **Defer the FIX, never the FINDING** — every deferred security item goes in the backlog with its probe.
- **Never delete dormant code** — wire it or document it as an intentional seam.
- **Write mutation definitions as the exact edit, verbatim.** "MU11" is not a definition.
- Adding a `src/*.php` file moves **five** censuses; update all five in the same diff.

### Chaining

The size guideline here is ~15 agents per run, and a full bundle is 6, so a run covers **2-3 bundles**.
Invoke with the next batch, read the result, re-verify personally, then invoke again. Do not try to put
all twelve bundles in one run.

## ORDER: W1 ✅ → W2 ✅ → W5 ✅ → W3 ✅ → W4 ✅ → C5 → C4a → … → `#88` → hardening

`#88` is the stale README suite figure. **Moved to AFTER the live-bug bundles, not straight after W1**,
and for a measurable reason: the figure has now been invalidated three times in one session (7,276 →
7,387 → 7,512) and W2 will move it again. Writing it between two bundles guarantees writing it wrong.
Take it from the verification run of the last live-bug commit, in a standalone commit with nothing else
in flight.

**Prepared edit: `/tmp/…/scratchpad/88-readme-figure.md`.** Read it first — it corrects an error in my
own earlier note here. That note said `README.md:551` carries a second stale figure, `4,337/12,587`.
**It is not stale and must not be touched**: it is introduced by "For scale rather than for accuracy:
the first figure to stand here …" and is a deliberate historical citation kept to show the drift.
Updating it destroys the point it makes. Only `:531`'s figure is live — plus its runtime and its
delta sentence, both of which are part of the measurement rather than decoration.

## BUNDLE C3 IS COMMITTED — `3b0ba8fe`. Phase 2 item 2 done.

Supervisor-verified TWICE, and the second run is the one that counts: **7387 / 76813 / 1, exit 0
against LOCAL sibling symlinks** (and 7387 / 76811 / 2 against Packagist copies — see the vendor
note below for why the two differ). Implementation + adversarial review (17 findings, 5 surviving
mutations) + two fix rounds. `src/` is **276** files.

**The security defect is closed, and I verified it myself rather than taking the agent's word.**
`.mcp.json` now requires the root to be listed under `trustedProjectMcp` in the user's own
`~/.sugar-crush/config.json`. Measured personally, three ways: untrusted root in `plan` → payload
never runs; untrusted in `default` → never runs; **grant written → runs** (the positive control
matters — a gate that simply broke MCP would also show "no payload" and would have looked like a
pass). The refusal is visible through the real `chat()` path, naming the root and the key to add.

Findings 6, 7, 10-14 landed in fix round B, plus two hand-offs: the `error_log()` diagnostic is now
asserted rather than silenced, and the `projectTierRefusals()` count got its missing domain — "TEN"
is true of dot-DIRECTORY paths, and `.mcp.json` is a bare dot-file the derivation cannot see, so
the figure a reader wants is **EIGHT** paths feeding that map.

**Two things fix round B reported and deliberately did not change — both still open, my call:**

1. `mcpClient()`'s untrusted branch is guarded `$canonicalRoot === false || !projectMcpIsTrusted(…)`,
   and the `false` arm is **unreachable** there (`is_file()` already succeeded on a path composed
   from `$canonicalRoot`). Same shape as the dead `stdClass` clause round A deleted — but here the
   dead arm is the fail-CLOSED direction on a security gate. **Decision: keep it, document it as
   deliberate belt-and-braces.** A later reader deleting it "because it is unreachable" is exactly
   how a gate acquires a hole, and the cost of keeping it is one branch.
2. Neither refusal branch writes `$mcpClients`, so an untrusted root re-stats and re-checks trust on
   every `tools()` call. Harmless and idempotent; the memo docblock reads as if every outcome is
   cached, which is the claim to correct, not the behaviour.

## ⚠️ VENDOR STATE IS NOW A THING TO CHECK, and it silently changes what "green" means

Mid-round, something ran `composer update` and replaced `sugar-crush/vendor/sugarcraft/*`'s
symlinks with real Packagist directories — so the suite stopped testing the monorepo's own
`candy-*` and started testing published copies, with no signal except a skip count moving 1 → 2
(`GitignoreAwarenessTest::testTheMonorepoPathRepoSymlinksAreNotFollowed` self-skips when there are
no symlinks). **A 2-skip run means you are not testing the monorepo.** It also left an unrelated
third-party bump in the tracked root `composer.lock` (aws-sdk 3.390.4 → 3.393.1 and others), which
I reverted rather than letting it ride along inside a feature commit.

Restore local wiring with the documented loop, and note it is `sugarcraft/*` scoped so third-party
versions do not move:

    php tools/check-path-repos.php --fix --strict-closure
    cd sugar-crush && composer update 'sugarcraft/*' --quiet
    cd .. && git checkout -- '*/composer.json'      # NEVER commit these
    php tools/check-path-repos.php --no-lib-path-repos   # must exit 0

`vendor/` is gitignored, so reverting the manifests keeps the symlinks AND a clean tree. **Tell
every agent not to run `composer install`/`update`** — it silently undoes this.

## THE `#N` TRACKER IS crush_code.md RENUMBERED — it adds nothing to the count

**Settled by the user, then verified in the worklog. Do not re-run this archaeology.**

The worklog is full of `#11`-`#90` references, and I mistook them for a second plan with a lost
defining document (I even inferred `crush_feat_plan.md:81`'s uncommitted `crush_code_update.md` as
the source). **Wrong.** There was never a plan beyond `crush_code.md` and this worklog. The `#N`
numbers are a FLAT TRACKER over `crush_code.md`'s own items, extended with new numbers as problems
were discovered — and the mapping is written down in the worklog itself:

    :195   #12 (`McpClient` rename)  -> P2.1 + the Bootstrap::mcpClient() half
    :194   #31 (P6.2 layered settings)
    :3417  "#17 / P2.7"              -> LspTool over the built LspClient
    :~194  #13 (P2.3 workflow wiring)
           "#14/#16/#17 — the rest of Phase 2 wiring"

So `#12`/`#13` are CLOSED (C1, C2, C3), `#14`/`#16` are Phase 2 items already inside the 28, and
`#17` is Phase 2 item 7. **The `#N` tail I reported as "unrecoverable" was Phase 2 wiring I was
already executing.** Discovered items went on to become the `E1`-`E45` backlog, which is the live
second series.

**Two lessons, both mine, both in this section's own history:**

- **I answered "how far along is the plan" by counting one series and calling it "the plan".** The
  user knew the number was wrong (~88-90) before I did.
- **I then explained the discrepancy with a missing-file theory built from an absence**, when the
  mapping was in the file I was already reading. An inference from "I cannot find X" is not
  evidence about X. Check for the notation before positing the document.

### The one genuine straggler, and it is not a plan item

**`#88` — the README whole-suite figure, OPEN.** `sugar-crush/README.md:531` says
"7,276 tests / 76,239 assertions" (bundle C1's number, `6bc5218b`); the tree is at 7,387 / 76,813.
`:551` carries a separate `4,337/12,587`. Update it AFTER W1 lands, in a standalone commit, once
nothing else is in flight — a figure committed mid-bundle is stale before it is pushed.

**`#63` `enforceTimeLimit` — CLOSED, and the worklog's "still waiting for a window" note is stale.**
I recorded it OPEN here from the worklog rather than the file and corrected it minutes later.
`phpunit.xml` already carries `enforceTimeLimit="true"` + `defaultTimeLimit="60"` with `php-invoker`
installed, plus 55 lines of measured reasoning: 60s sits ~6x above the slowest real test (9.321s,
`WebSearchToolTest::testHandlesRedirectResponse`); `memory_limit` is set because the limit bounds
TIME SPENT COMPUTING and not a thrashing process (a real `tokenize()` mutant ran past 600s at >4GB
RSS emitting one progress character in ten minutes); `failOnRisky` is what makes it bite, since a
timed-out test is recorded RISKY and risky alone exits 0. Accepted gap:
`tests/Agents/AgentWorkerPoolTest.php` arms and cancels `pcntl_alarm()` itself, and its
`pcntl_alarm(0)` clears the enforcing alarm. **Verify a "waiting" note against the file before
repeating it.**

`#89` (InstructionFileLoader containment — five escapes, not one) and `#90` (`BuiltInToolCorpus`
blindness, whose closure unblocked `#17`) are both CLOSED per worklog `:4646` and `:4769`.

### THE LIVE SERIES ARE TWO

1. **`crush_code.md`** — 75 numbered items across 9 phases, unchanged since `418c0888`. **47 done,
   28 left.** Verified by counting the plan section bounded at its `## Appendix` heading; an unbounded
   awk attributes every numbered line in the 2,000-line appendix to Phase 8 and reports 202.
2. **`crush_code_hardening_backlog.md`** — `E1`-`E45`, deferred to the end by the user's own rule.
   Note `crush_code.md:129` claims "50 items across 6 groups" against 45 actual entries — an
   unreconciled count that needs its domain like everything else.

## THE COUNT, and two items that had fallen out of the queue

**47 of 75 plan items complete (63%), 28 left.** Counted by item from `crush_code.md`'s phase
sections: Phase 0=14, 1=3, 2=9, 3=5, 4=7, 5=10, 6=6, 7=6, 8=15.

Two corrections the arithmetic forced, both of them errors in THIS file:

- **"PHASE 5 IS COMPLETE" was wrong.** Item **10b** — differentiate the five hardcoded
  `AgentDefinition` preset prompts, currently generic one-liners that do not mention the skills
  they grant — is untouched. B3 shipped 10a (the `EnvironmentBlock` OS-version line) only.
  Phase 5 is items 1-9 + 10a.
- **Phase 4 item 6 was missing from the queue entirely.** Real subcommands (`mcp list`,
  `session list`/`delete`, `models`, `doctor`, `completion bash|zsh|fish`), `--config <path>`, a
  0/1/2 exit-code convention, and warn-not-silently-drop on an unrecognised `--output-format`.
  Never done, just absent from §11.

The item count is not effort. Phase 2 item 4 alone is bigger than all of Phase 7; Phase 8's nine
remaining items are mostly small. And the hardening backlog (E1-E42, and growing as rounds land) is
a SECOND queue, deliberately held to the end.

**The two lessons this session added, both from C1:**

0. **A carefully verified argument can answer the wrong question.** For C3 I reasoned that
   `unrestricted: true` was safe because every main-agent tool call rides the PreToolUse chain
   exactly as `Bash` does, wrote the reasoning down, and asked the implementer to verify it end to
   end. It verified. It was also irrelevant: that gate sees tool CALLS and never sees
   `proc_open()`. Before trusting a safety argument, ask what it does NOT cover — and check
   whether the project already has a boundary for this threat class rather than reasoning a new
   one from scratch.
0b. **Do not edit `docs/plans/*` while a round is live.** Two agents in a row have reported
   `git status` moving under them because I committed backlog edits mid-round. Either hold docs
   edits until the round returns, or tell the agent up front that `docs/plans/*` will move and
   is not part of its bundle.
1. **A reproduction fixture can fail to reproduce, and then the test passes on the broken
   code.** My SIGTERM fixture (`trap '' TERM; sleep 8`) put the trap in a script file, so
   `proc_open`'s direct child was the `sh -c <script>` wrapper — which does NOT ignore SIGTERM.
   It died in ~50ms, orphaned the trapping shell, and the bug became invisible. Always confirm
   the fixture reproduces the defect BEFORE writing the assertion against it.
2. **An overstatement passed along is indistinguishable from one invented.** I forwarded a
   reviewer's "no newline AND no carriage return, for any command whatsoever" without checking
   the CR half, which was false and which the reviewer's own next finding contradicted. Reading
   a finding is not verifying it.

Older but still live: **"survives the full suite" is not "is correct" — it is only "nothing
measures this"**, and **a fix lifted from a reviewer's mutation is still a mutation**, chosen
to probe coverage rather than to be right.

**Historical: Bundle B3 was COMMITTED as `a72c5b0a`** (Phase 5 items 8, 9, 10a), with
its review and fix rounds done. Supervisor-verified on a clean tree: **7204 / 75944 / 1,
exit 0**. Worklog section "Bundle B3 — review + fix rounds" carries the nine mutation
survivors, the one real code bug (`MemoryBlock::MAX_BYTES` was not a ceiling — 11,119 bytes
measured against a 4,096 budget, with the false promise in the model-facing header), the
five corrections the fix agent made to the supervisor's brief, and the two review findings
that were against the supervisor's own backlog rather than the code.

**PHASE 5 IS COMPLETE as of 2026-08-19.** E21 committed as `261ac59d`, supervisor-verified at
**7237 / 76136 / 1, exit 0**. **In flight: C1** (Phase 2 items 1 and 8), implementation round,
brief at `/tmp/…/scratchpad/c1-brief.md` — self-contained, so re-spawn against it if that round
was lost. Then review → fix → verify → commit as usual.

**Two lessons from E21 that apply to every round from here:**

1. **"Survives the full suite" is not "is correct" — it is only "nothing measures this".** The
   `groupIntoPairs()` fix the supervisor prescribed came from a reviewer's mutation that
   survived all 7221 tests. Measured, it took `exchangesToSummarize()` from 10 exchanges to 0
   on any history with a reminder after each prompt — i.e. **every session that reaches 85%,
   since 70% fires first** — which would have made E21 fall back to the heuristic forever,
   silently, while looking wired. A fix prescribed from a mutation is still a mutation: chosen
   to probe coverage, not to be right.
2. **`ContextCompactor` had four victims, not the one the implementation round reported.** The
   unreported one: two consecutive assistant turns, where the second **overwrote** the first —
   and `/compact`'s landing report, the spend-cap refusal and the 95% refusal all append an
   assistant message onto a history already ending in one. All four fixed in `261ac59d`.

**PENDING EDIT TO `crush_code.md`, deliberately not yet made.** Its status block's
`**Complete:**` line reads "Phase 2 item 6" and must also list **Phase 2 items 3 and 5**,
which §9 records as measured already-done with no code required. E21's agent already updated
the Phase 5 half of that block, so item 6 and "Phase 5 items 1-7" are correct as they stand.
The edit is held back only because **C1's agent is live in `crush_code.md`** (it will mark
items 1 and 8) and a second writer there loses one of the two edits. Make it once C1 commits.

**C1's in-flight state, for recovery.** As of this note the working tree carries two
completed renames and nothing else: `sugar-crush/src/McpClient.php` →
`src/ClaudeCodeMcpClient.php` and `tests/McpClientTest.php` → `tests/ClaudeCodeMcpClientTest.php`
(both showing as `RM` in `git status`). If that round was lost mid-flight, the brief at
`/tmp/…/scratchpad/c1-brief.md` is self-contained — re-spawn against it; it is safe to re-run
over a tree where the renames already happened, but check whether the class and namespace
inside those two files were renamed too before assuming the item is half-done.


## 11. QUEUE — in order

- ~~**B1** Phase 5 items 4,5 — provider `contextWindow()` wiring + live 85%/95%
  compaction tiers.~~ **DONE `08cc1b6a`** (6931/71073/1, exit 0).
- ~~**B2** Phase 5 items 6,7~~ **DONE `738c586c`** (7089/75695/1, exit 0). Item 7
  complete; **item 6 is 🟡 partial** — `/compact` asks the model, the automatic 85% tier
  still uses the heuristic (backlog E21), which is the lossier path and where most real
  compactions happen. Pick E21 up before calling Phase 5 finished.
- ~~**B3** Phase 5 items 8,9,10a.~~ **DONE `a72c5b0a`** (7204/75944/1, exit 0).
- ~~**E21** — finish Phase 5 (wire the automatic 85% tier to the model).~~ **DONE `261ac59d`**
  (7237/76136/1, exit 0). It also fixed four silent-loss bugs in
  `ContextCompactor::groupIntoPairs()` and one spend-cap bypass it had itself introduced.
  **This row used to claim "PHASE 5 IS COMPLETE". That was wrong** — item **10b** (the five
  preset prompts) is untouched, so Phase 5 is items 1-9 + 10a. See §10's count.
- ~~**E33** — the 70% reminder piling up in permanent history.~~ **DONE `7ed551b6`**
  (7285/76294/1, exit 0). Deduplicated: strip unconditionally, append only when the tier fires.
  Also fixed a bug the review found and I had not thought to look for — **`/rewind` was
  reconstructing every non-`assistant` checkpoint row as a USER message**, so a rewound reminder
  came back as the user's own words on the provider wire, and the dedup's own role guard made it
  permanent. Same coercion mis-roled `_Request cancelled._`, the tier report and
  `_Permission denied_`. Fixed with a `'system'` arm, zero fixture churn. The `tool` case stays
  coerced **by necessity** — `Role` has three cases and no `tool`, and nothing serialises one.
  Residual: **E38** (a compaction folds the reminder's full text into a `[summary] ` line the
  dedup cannot match, so the pile-up changes shape rather than ending).
- ~~**C1** Phase 2 items 1,8 — the `ClaudeCodeMcpClient` rename and the streaming tier.~~
  **DONE `6bc5218b`** (7276/76239/1, exit 0). Item 8 carried far more than the plan said: the
  dormant class **could not return a newline from any command whatsoever**, so five doc sites
  were recommending a wrapper that cannot exist. Resolved by making a terminated blank line
  mean a literal newline. Also fixed an unbounded 100%-CPU spin, an escape hatch a
  `trap '' TERM` child held for 8s against a 1s deadline, and `CommandBackend` returning an
  EMPTY answer whenever the whole reply was `0` (`?: ''`). **Two claims withdrawn, not
  delivered:** `$onToken` fires but the blocked loop means nothing paints it — measured six
  callbacks, ZERO render ticks. Backlog **E34** (non-blocking rewrite) and **E35**
  (cancellation) carry the remainder. That is the bundle's one FUNCTIONAL deferral and it is
  deliberate.
- ~~**C2** Phase 2 item 3 — `WorkflowEngine`/`WorkflowRegistry` in `Bootstrap::chat()`.~~
  **ALREADY DONE** — `Bootstrap.php:374` passes it. Measured 2026-08-19, see §9. No work.
- ~~**C3** Phase 2 item 2 — MCP tools reachable.~~ **DONE `3b0ba8fe`** (7387/76813/1, exit 0
  against local siblings). Three rounds: implement, adversarial review, two fix rounds. The
  headline is not the wiring but the gate — see §10. `trustedProjectMcp` is a NEW key, verified by
  me in all three directions including the positive control. E40/E41/E42 carry the deferred
  remainder.
- ~~**W1** — the user's live render bug: long replies cut off at the pane edge.~~ **DONE `47ee2c86`**
  (7577 / 87648 / 1, exit 0). Four rounds. Twelve of twelve mutations killed, re-verified by me.
  **Read the "LESSON FROM W1" section above before running any later bundle** — three separate "it's
  dead" reports in this one chain were false, and a fix round made its neighbours' assertions vacuous.
- **W2 — IN FLIGHT: input is blocked while a turn runs. USER-REPORTED, ahead of the audit queue.**
  Typing and Ctrl+P are both dead mid-turn, and the input cursor is hidden as well. **Not an async
  problem — the async work is already done** (`completeAsync()` forks a child; a driven `Escape`
  mutates state mid-turn, proving the loop delivers keys). The defect is one policy return,
  `Chat.php:1141-1146`'s blanket `if ($this->inFlight)` swallow. Do NOT delete it — split it, and make
  **Enter enqueue** rather than dispatch. The drain must go through the existing turn-start path
  (`dispatchTurn()` has two callers and its docblock warns a third copy loses the generation stamp, the
  cancellation token, the checkpoint or the title Cmd); `scheduleParkedCompaction()` already implements
  hold-then-dispatch for the 85% tier. **One real drain site, not 21** — see the W2 section above.
- ~~**Phase 5 item 10b**~~ — **ALREADY DONE, no code written.** Closed by Bundle A (`bf3495f5`),
  measured 2026-08-19. The row said "it is what stops Phase 5 being finished"; nothing did.
- **Phase 4 item 6** — real subcommands (`mcp list`, `session list`/`delete`, `models`, `doctor`
  health-check distinct from the model-invoked tool, `completion bash|zsh|fish`), `--config <path>`,
  a 0/1/2 exit-code convention, warn-not-silently-drop on an unrecognised `--output-format`. **This
  row was missing from the queue entirely** until the item arithmetic caught it.
- **C4** Phase 2 item 4 — **the biggest remaining item.** Wire `CommandLoader::loadAll()`
  AND build the missing template-substitution engine (`$ARGUMENTS`, `$1`, backtick-cmd,
  `@file` — none exist; a `grep -rn ARGUMENTS src/` returns three hits, all unrelated prose).
  Shell-out must use ReactPHP `Process`, never blocking `shell_exec`. This is what makes the
  README's "loadable, not loaded" note obsolete.
  **THE STRUCTURAL BLOCKER THE PLAN DOES NOT MENTION**, measured 2026-08-19, full write-up at
  `/tmp/…/scratchpad/c4-measured.md`: `CommandRegistry` is **entirely static** — no
  constructor, no instance state, `all()` returns a hardcoded literal, and
  `grep -rn 'new CommandRegistry'` returns ZERO. There is no instance for loaded commands to be
  injected into, so the bundle's FIRST decision is static-merge-point vs instance registry (the
  latter touches `Renderer::renderSlashMenu()`, the palette, and the two tests below), not
  template syntax.
  **AND TWO INVENTORY TESTS WILL RED IN A MISLEADING WAY**, both in
  `tests/Commands/SlashDispatchTest.php`. `testEverySlashVisibleRegistryRowHasALiveDispatchHandler()`
  (:98) asserts **`$next->inFlight === false`** for every slash-visible row — but a file-based
  command MUST set `inFlight = true`, because sending its template to the model IS its
  behaviour. Its failure message will tell the implementer to add a dispatch arm, which is the
  wrong remedy. `testEveryDispatchArmIsAdvertisedOrDeliberatelyUnadvertised()` (:158) derives
  arm names from `dispatchCommand()`'s own SOURCE and needs the same third case. **Do not let
  either be relaxed** — both are load-bearing completeness inventories; teach them that a
  file-based row dispatches to the model by design and assert that positively.
  `CommandSpec::isFileBased()` already exists to express it. Also: `CommandLoader`'s class
  docblock defers this work because "`src/Chat.php` is owned by a concurrent track" — **that is
  now STALE** and should be corrected as part of the bundle.
- ~~**C5** Phase 2 item 5 — `HookManager::loadFromFile()` in `Bootstrap::hooks()`.~~
  **ALREADY DONE, and deliberately not by the route the plan names** — `Bootstrap::hooks()`
  loads entries once per process so a session cannot install hooks into itself mid-session.
  The prerequisite checks out too (`df0a563b` is Phase 1 item 2; `ScriptHook::EXIT_ASK = 3`).
  Measured 2026-08-19, see §9. No work.
- **C6** Phase 2 item 7 — **write** `LspTool implements Tool` over `src/LSP/LspClient.php`.
  The plan says "add `implements Tool`"; there is no `src/Tools/LspTool.php` at all, and
  measured 2026-08-19 the **whole `src/LSP/` subsystem has zero production users** — the grep
  for `LspConnection`/`new LspClient` outside `src/LSP/` is empty. So the item is four pieces
  of work, not one: write the tool; choose its surface over `LspClient`'s
  definitions/references/hover/symbols/codeActions/diagnostics API; construct a connection
  (`LspConnection::connect()` **spawns the server with `proc_open`**, so this needs server
  discovery/config); and degrade when no language server is installed, which is the common
  case and the real design work. **Its own bundle — do not fold it into C1.** Note
  `connect()`'s `float $timeout = 30.0` is a language-server request timeout, NOT an LLM
  completion timeout, so the no-blanket-timeout directive does not apply to it — say that in
  the brief so nobody "fixes" it. Full measurement in `/tmp/…/scratchpad/c1-measured.md`.
- **D** Phase 3 items 2-5 — `candy-focus\FocusRing` in `Tui\Pane`; `sugar-veil`
  `withClickOutsideDismiss()`; `candy-sprinkles\Table`; `strlen()` padding fixes.
- **E** Phase 6 items 1-6 — **item 1's `__DIR__` bug is largely already fixed**, so do
  not brief it as the cheap opener. Measured 2026-08-19: `WorktreeConfig`'s old
  `__DIR__ . '/../../../.sugar-crush/config.json'` read is now the named seam
  `defaultConfigDir()` (`\dirname(__DIR__, 3)`) with `ContainedPath` gating in three
  places, and its docblock records the measured escape it closed (a `.worktreeinclude`
  line of `../secret/id_rsa` read AND wrote outside the checkout). What is genuinely left
  is the half that file explicitly defers: `dirname(__DIR__, 3)` is the directory
  CONTAINING the package, which under a composer install is `vendor/sugarcraft/` and not
  where anyone's config lives — point it at the project root / user config dir the way
  `Bootstrap` does. Also still true: nothing in `src/` constructs a `WorktreeManager`, so
  wiring it is part of the item ("DORMANT IS NOT UNGATED" is that file's own doctrine).
  Then: layered settings files; `tools.allow`/`deny`; permission block;
  keybindings + statusLine; `--model`/`--permission-mode` flags.
- **F** Phase 7 items 3-6 — the authoring/reference docs. Also fix README's stale
  built-in-hooks list (omits `BashEscapeDenyHook`).
- **G** Phase 8 items 3,4,6,8,9,10,11,13,15 — `StallDetector` render branch (only the
  paint is left); split-pane compositor fate; VHS demos; repo-map; `Grep`'s missing
  `InstructionFileLoader` wiring; proactive git diff in `EnvironmentBlock`;
  `loadRoot()` monorepo-parent awareness; `Task` tool (epic); file-watching (note only).
- **Phase 2 item 9** — unified `crush-plugin.json` + `PluginLoader`. Explicitly the
  deferred larger half; do after items 1-7.
- **LAST — the hardening pass:** `docs/plans/crush_code_hardening_backlog.md`, 50+
  items and growing as each round appends.
