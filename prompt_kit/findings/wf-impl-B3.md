# wf-impl track B3 — PermissionGate::wouldAsk(), AskFreeGrant on the gate, Workflow-tool array context

## 1. What was implemented

### `PermissionGate::wouldAsk(ToolDeclaration): bool` + `askReason(ToolDeclaration): ?string` (P2-1 follow-up)

`src/Permissions/PermissionGate.php`, additive. Same purity law as `refuses()`. It does not mutate anything: no Auto strike counter, no auto reason, no forced-ask mark. It answers whether **some** call that the declaration admits could be answered `Ask`. `true` is the safe answer, so anything it can't judge from the declaration resolves to `true`. `wouldAsk()` returns `askReason() !== null`.

**What a declaration admits:**
- A bare name (`Bash`) admits every call to that tool.
- An argument-scoped declaration (`Bash(vendor/bin/phpunit *)`) admits exactly the calls that `Allow <that pattern>` would grant.
- A name glob (`mcp__git__*`) is presumed to ask, except under bypass. Reason: `"mcp__git__*" is a name pattern, and a tool it names may ask`.
- A malformed pattern also presumes ask. Reason: `"Bash(x" is not a tool pattern this gate can judge (it …)`.

**Judgement order** (mirrors `decide()`):
1. `bypass-permissions` never asks. An Ask rule falls through to the mode there, and the mode allows.
2. A channel-scope refusal, or a skill turn-deny covering the whole declaration, means every call is refused. A refusal is not a question.
3. Configured rules, first match wins:
   - A rule covering the **whole** declaration settles it. A rule covers the whole declaration when it is name-only, or when its argument pattern equals the declaration's scope after whitespace normalisation.
     - `Allow` or `Deny` → no ask.
     - `Ask` → asks, unless an "always" session rule or a turn grant covers the declaration.
   - A rule covering **some** calls behaves differently. An `Ask` one asks (reason: `an Ask rule "Bash(git *)" covers some Bash calls`). An `Allow`/`Deny` one leaves the remaining calls to the next rule.
   - Shell and patch proxies apply, as in `PermissionRule::namesCall`: `Ask PtyRun(rm *)` binds `Bash`.
   - **No glob subsumption.** `Allow Bash(vendor/bin/*)` does NOT cover a `Bash(vendor/bin/phpunit *)` declaration (safe answer).
4. The mode, for calls no rule settled:
   - `default` / `accept-edits`: ask for anything outside the Read class.
   - `plan`: asks only when `evaluatePlan(name)` is `Ask`. `Bash`, `Edit` and the write tools never ask there, because they are allowed or denied.
   - `dont-ask`: never asks.
   - `auto`: asks for every non-read tool. It also asks for every tool when no classifier is set, and for reads when a reviewer is set and `autoReviewScope` is `all`.
   - No-ask tools never ask, except `SendMessage` (its `session:` target).
5. An "always" answer or a turn grant answers the Ask, except when the Ask is forced: `ShellSend`, `PtyRun` (it may carry steps), and every `auto` Ask.

**Reason strings:**
- `the "default" mode asks for Bash`
- `the "auto" mode judges each Edit call and may ask`
- `an Ask rule "Gr*" covers Grep`
- `… covers some Read calls`

**Not seen by it (documented):** hooks that ask on their own, such as ProtectFilesHook's policy-file writes and user `PreToolUse` scripts.

**Tests:** `tests/Permissions/PermissionGateWouldAskTest.php` (54 tests) covers:
- the mode × tool matrix;
- reasons;
- auto without a classifier;
- name-only and scoped allow;
- rule order;
- the shell proxy;
- bypass;
- session, turn and forced grants;
- turn-deny and channel refusals;
- globs and malformed declarations;
- the no-mutation strike check;
- a **soundness sweep**. For every mode × 8 rule sets × sample calls, wherever `wouldAsk` says no, a real `evaluate()` never answers Ask.

### AskFreeGrant switched to the gate (P2-1)

In `src/Agents/AskFreeGrant.php`, the signature is now `askRisk(array $tools, array $gates, array $declarations = [])`. The `$declarations` parameter is new and optional.

**How each tool is judged:**
- Each effective tool is judged by `askReason()` on every non-null gate. The tool is ask-free when no gate would ask.
- So a `Bash` allowed by a name-only `Allow Bash` rule now forks, and so does any agent under `bypass-permissions` or `dont-ask`.
- When the agent's `tools:` grant scopes a tool and has **no** bare entry for it, each scope is judged instead (`Bash(vendor/bin/phpunit *)` + an identical allow rule → forkable). Malformed or non-string entries narrow nothing.
- `StructuredOutput` is always free.
- `RelaysPermissionAsks` and `DelegatesToEngine` tools always ask.
- With no gate at all, the old Read-class floor applies.

**Reason format:**
- `may ask: Bash, WebFetch` (names; the mode is implied).
- `may ask: an Ask rule "Read(*.env)" covers some Read calls` (a rule is named verbatim).

**Dispatch row:** the `Workflow` tool's `dispatch` value on an all-forked stage is now `parallel: forked (N ask-free agents)` (was "read-only agents"). The tool description now says agents run concurrently "only when none of their tools could ask permission under this session's mode and rules".

**Tests:**
- `tests/Agents/AskFreeGrantTest.php`: 10 tests, including allowed-outright, every gate must agree, scoped grant, and relay.
- `tests/Tools/WorkflowToolForkTest.php`: new `testAWriteToolTheSessionAllowsOutrightForks` (allow rule and bypass), plus updated strings.

### Workflow tool `context` takes any JSON value (P1-1 cross-track from C2)

In `src/Tools/BuiltIn/WorkflowTool.php`:
- **Schema:** `context.additionalProperties` is now `{}` (any JSON value). The description now says that arrays and objects render as compact JSON and that lists feed `from: {context: key}`.
- **Validation errors:**
  - `Error: "context.<k>" is not allowed: keys starting with "@" are reserved for the workflow engine.`
  - `Error: "context.<k>" must be a JSON value (string, number, boolean, null, array or object).` This fires when `json_encode` fails (NAN/INF).
- Values pass through to the engine unchanged.
- The plan description now lists the `from:` sources (`{glob}`, `{stage, pointer}`, `{context}`).

**Tests** (in `tests/Tools/WorkflowToolTest.php`; no new file, so no durations-tsv entry is needed):
- `testAListContextDrivesADataFanOutEndToEnd`: a 3-item list drives one agent per item with `{{item}}`/`{{index}}`, and the next stage's `{{files}}`/`{{opts}}` render as compact JSON.
- `testTheContextSchemaAdmitsAnyJsonValue`.
- The bad-argument test was updated for NAN/INF.

## 2. Not done / caveats
- `ProtectFilesHook` (policy-file writes ask in every mode) is NOT modelled. A forked agent that hits it has that one call refused (fail closed, grandchild reason) and carries on. This is the trade-off the brief asked for: a session-allowed write tool forks.
- There is no glob subsumption between argument patterns (see above). Only identical scopes count as covered.

## 3. Cross-track requests
1. **EngineExecutor owner** (`src/Agents/EngineExecutor.php::forkRefusal`, not mine): pass the agent's grant so scoped declarations are judged on their scope. One line:
   `AskFreeGrant::askRisk($tools, [$this->engine->permissionGate(), $agent->permissionGate], $agent->agent->tools)`.
   Until then a scoped-only grant is judged as the bare tool, which is the safe answer.
2. **Docs** (WORKFLOWS.md, § the `Workflow` tool):
   - Forking criterion: "no gate would ask", not "read-only".
   - `dispatch` value `forked (N ask-free agents)`.
   - `context` accepts any JSON value; arrays render as JSON; lists feed `from: {context}`.
   - The two new error lines.
3. **`/workflow run` CLI** (C2's request still open): accept `key=[…]` JSON values.

## 4. Commits
- `9191ea239` PermissionGate::wouldAsk()/askReason() — could a declaration be asked, non-mutating
- `460b2658a` AskFreeGrant judges each tool by PermissionGate::askReason(); allowed write tools fork
- `0a8e773c7` Workflow tool context takes any JSON value; a list drives from: {context} fan-outs
