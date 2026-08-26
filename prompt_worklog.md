# prompt_worklog.md — the record of the prompt-architecture plan

**Plan** `prompt_plan.md` · **Dossier** `prompt_expand.md` · **Entry point** `prompt_resume.md`

This file is the **append-only record** of what actually happened, step by step. It is the state that
makes this plan resumable after a context loss, a session limit, or a machine restart. `prompt_plan.md`
says what is *supposed* to happen; this file says what *did*.

---

## Conventions

**Order.** Newest first. New entries go directly under the `## ENTRIES` marker, above the previous
one. Never edit an existing entry except to correct a factual error, and when you do, leave the
original text visible with a strikethrough or a `CORRECTED:` line — a quietly rewritten entry is
indistinguishable from a fabricated one.

**Who writes.** The **orchestrator only**, in the main repo directory
(`/home/sites/sugarcraft`). Step agents *return* their entry as text in their final report; the
orchestrator appends it verbatim. If five agents in five worktrees all appended here, every merge
would conflict on this file and the entries would interleave.

**When.** Immediately after a step's merge and commit, **before the next step is spawned**. A step is
not complete until its entry is here and `prompt_resume.md` has been rewritten. See `prompt_plan.md`
§3 for what to do if an entry is missing.

**Numbers.** Every number in an entry came from a command that was run. Quote the command and its
output. If the orchestrator's own verification run disagrees with the step agent's reported numbers,
**both** go in the entry, each labelled with the command that produced it. Do not average them, do
not pick the nicer one.

**Honesty markers.** Use these, they are load-bearing:
- `UNVERIFIED:` — a claim nobody measured. Say why not.
- `RECONSTRUCTED` — an entry rebuilt after the fact from `git log`/`git show` because the original
  was never written. Everything in it is inference, not record.
- `CORRECTED:` — a prior entry's claim that turned out to be wrong, with the measurement that
  overturned it.
- `DECLINED:` — a plan step deliberately not done, with the reason.
- `RECOVERED:` — work that reached this entry after an agent died mid-step and a later agent picked
  it up (`prompt_plan.md` §1.8). Say which rung of the ladder was used, how many launch attempts it
  took, and what §1.8.4 found in the worktree. A step that took five launches and one that took one
  are indistinguishable afterwards unless this is written down.

---

## Required entry format

Every step entry uses exactly this shape. Sections are mandatory; write `(none)` rather than
omitting one.

```markdown
### <STEP_ID> — <one-line what changed>   ·   <YYYY-MM-DD HH:MM>   ·   <commit sha>

**Status** <exactly one of the five below — the word in backticks, nothing else>
**Worktree** /home/sites/prompt-step-<STEP_ID>  (removed | left in place because …)
**Base** <sha the worktree branched from>

**Goal (restated in one sentence)**
<what had to become true>

**What changed**
- <path>: <what and why>
- <path>: <what and why>

**Tests added or changed**
- <path::testName> — <what it asserts, and what it would catch>
- ...
**Deletion experiment**: <what was deleted/mutated to prove the test bites, and what happened.
Write `not applicable` only when the step adds no guard.>

**MEASURED**
```
$ <command>
<verbatim output>
```
<repeat for every number claimed anywhere in this entry>

**Suite result**
```
$ cd sugar-crush && vendor/bin/phpunit
<the verbatim summary line: Tests: N, Assertions: N, ...>
```
Baseline for comparison: <the figure from P0.S1 or the last phase close>
Delta: <+N tests, +N assertions, N new skips — and a sentence on any skip added>

**Review loop**
- Cycle 1 — reviewer <agent id/label>: <N findings> → <one line each>
- Cycle 1 fix — <what was changed>
- Cycle 2 — reviewer <new agent>: NO FINDINGS (checks performed: <list>)
Total cycles: <N>

**Invariants touched**
<Which of prompt_plan.md §17's invariants this step touched, and how each was kept or
deliberately changed. `(none)` if it touched none. If it added a file under sugar-crush/src/,
state the new census figures here — see §17.1.>

**Surprises / things the plan got wrong**
<Anything the step discovered that contradicts prompt_plan.md or prompt_expand.md. This section
is the most valuable one in the entry; do not leave it empty out of politeness. `(none)` only
when genuinely nothing surprised you.>

**Follow-ups created**
<New work discovered but not done, with enough detail to act on later. `(none)` if none.>
```

### The five `Status` values

`blocked` on its own is not enough. The three ways a step can block need **different** recovery
actions, and a resuming orchestrator has to know which one applies without reading the whole entry
body. Use exactly one of these, spelled exactly like this:

| Status | What it means | What the next orchestrator does |
|---|---|---|
| `done` | Merged, verified by the orchestrator's own test run, bookkeeping written. | Nothing. Move on. |
| `blocked (review-cycle)` | Six reviews, still findings (`prompt_plan.md` §1.2 action 6). The code may be partly written. | Read the standing findings in this entry. Decide whether to re-scope the step, split it, or escalate. Do **not** just re-run the loop unchanged — it already failed five times. |
| `blocked (agent-failure)` | The agent slot exhausted its attempts under `prompt_plan.md` §1.8 — five for blank/aborted returns, three for substantive-but-wrong ones. Nothing is wrong with the *step*; the agents failed. | Walk §1.8.4 against the worktree first: a dead agent usually left work behind, and the right re-entry point is often mid-loop, not the start. If blanks persist past a fresh attempt, the step text is probably the problem — read it as a brief and consider that it is unfollowable. |
| `blocked (user-escalation)` | Dormant/unwired code the step may not remove and cannot wire in scope (§1.10). **The step is parked**: its worktree and branch are left in place. | Do **not** unpark it by guessing. Check whether the user has answered the question carried in `prompt_resume.md` §8. If not, continue with steps that do not depend on it. |
| `declined` | The step was deliberately not done. | Read the `DECLINED:` reason. It is a decision, not a gap. |

A step can end `done` **and** carry a follow-up or an escalation about something adjacent that it
finished around; that goes in `Follow-ups created`, and the status stays `done`. Parking is only for
a step whose own work could not be completed.

---

## Batch entries

A batch is the five (or fewer) steps the orchestrator spawns concurrently. Two one-line entries
bracket it. They exist because the merge order is decided at **spawn** time and used at **merge**
time, and a context loss between the two destroys it (`prompt_plan.md` §1.3).

**Batch-open** — written immediately after spawning, before any agent reports back:

```markdown
### BATCH <PHASE>.B<n> OPEN · <YYYY-MM-DD HH:MM>
Steps: <STEP_ID>, <STEP_ID>, <STEP_ID>, <STEP_ID>, <STEP_ID>
Merge order: <the same ids, in the order they will be merged> (<"plan order" | the reason it differs>)
Worktrees: /home/sites/prompt-step-<ID> ×5
Base: <master sha all five branched from>
```

**Batch-close** — written after the last of them merges:

```markdown
### BATCH <PHASE>.B<n> CLOSE · <YYYY-MM-DD HH:MM>
Merged, in this actual order: <ID>@<sha>, <ID>@<sha>, <ID>@<sha>
Did not merge: <ID> (<status>) — or `(none)`
Suite after the last merge: Tests: <N>, Assertions: <N>, Skipped: <N>
```

Both are one-liners on purpose. They are the only artefact that maps "five commits landed in this
window" back to "these five steps, in this order", and they cost nothing to write.

---

A **phase-close** entry uses the same shape with `<STEP_ID>` = `<PHASE> CLOSE`, `What changed`
replaced by a list of the phase's step ids and their commits, and an extra section:

```markdown
**Phase review**
- Cycle 1 — phase reviewer: <N findings across steps> → <one line each>
- Cycle 1 fix — step <PHASE>.audit-fix-1, commit <sha>
- Cycle 2 — phase reviewer: NO FINDINGS
**Cross-step problems found** <the ones no single-step review could have seen>
```

---

## Worked example

The following is a **fabricated example** showing the format. It is not a record of real work; no
such commit exists. It is kept here permanently as the format reference.

### EXAMPLE.S0 — example provider transmits systemPrompt   ·   2026-08-26 14:02   ·   `0000000ex`

**Status** done
**Worktree** /home/sites/prompt-step-EXAMPLE.S0 (removed)
**Base** `abc1234de`

**Goal (restated in one sentence)**
`ExampleProvider` must put `CompleteRequest::$systemPrompt` on the wire as a leading
`role: "system"` message on both the complete and the stream path.

**What changed**
- `sugar-crush/src/Providers/ExampleProvider.php`: `buildParams()` now prepends
  `['role' => 'system', 'content' => $request->systemPrompt]` when the field is a non-empty string.
  Guarded on non-empty, not merely non-null, so an empty assembled prompt does not emit a blank
  system turn that costs tokens and confuses the tool-call parser.
- `sugar-crush/tests/Providers/ExampleProviderTest.php`: three new tests (below).

**Tests added or changed**
- `ExampleProviderTest::testCompletePayloadLeadsWithTheSystemPrompt` — asserts
  `$payload['messages'][0]` is `['role' => 'system', 'content' => <exact bytes>]`. Catches a
  provider that accepts the field and drops it.
- `ExampleProviderTest::testStreamPayloadLeadsWithTheSystemPrompt` — the same assertion against
  `completeStream()`. Separate on purpose: `complete()` passing says nothing about the stream path,
  and that exact conflation is what hid this defect in `OpenAIProvider`.
- `ExampleProviderTest::testNullSystemPromptPrependsNothing` — asserts `messages[0]['role']` is
  `'user'`, i.e. no empty system turn.

**Deletion experiment**: removed the three added lines from `buildParams()` and re-ran the class.
Result: `Tests: 3, Failures: 2` — the two payload tests went red, the null-case test stayed green
(correctly, it passes on the old code too). Restored. The two red tests are the ones that bite.

**MEASURED**
```
$ /usr/bin/grep -c 'systemPrompt' sugar-crush/src/Providers/ExampleProvider.php
0        # before
3        # after
```
```
$ cd sugar-crush && vendor/bin/phpunit --filter ExampleProviderTest
OK (14 tests, 41 assertions)
```

**Suite result**
```
$ cd sugar-crush && vendor/bin/phpunit
Tests: 10331, Assertions: 161042, Skipped: 118.
```
Baseline for comparison: `Tests: 10328, Assertions: 161029, Skipped: 118` (P0.S1)
Delta: +3 tests, +13 assertions, 0 new skips.

**Review loop**
- Cycle 1 — reviewer A: 2 findings. (1) The stream test asserted `str_contains($body, 'system')`
  rather than the decoded payload structure — would pass if the word appeared anywhere. (2) No
  test for the empty-string case, only null.
- Cycle 1 fix — stream test rewritten to `json_decode` the request body and assert on
  `messages[0]`; empty-string case folded into `testNullSystemPromptPrependsNothing` via a data
  provider.
- Cycle 2 — reviewer B (fresh): NO FINDINGS. Checks performed: reachability (named
  `Runtime::run():307` → `EngineBackend::complete():463` as the live path), revert-and-fail
  (confirmed against the deletion experiment above), value-vs-shape assertions, bounds (systemPrompt
  is already capped upstream by the section budgets), untrusted text (none introduced), deleted
  behaviour (diff subtracts nothing), declared scope (2 files, both declared).
Total cycles: 2

**Invariants touched**
§17.2 item 7 (exact fence spellings) — untouched, this step does not assemble. No file added under
`sugar-crush/src/`, so §17.1's census literals are unchanged at 297/316.

**Surprises / things the plan got wrong**
The plan's file list omitted `sugar-crush/tests/Providers/ExampleProviderStreamingTest.php`, which
also builds payloads and needed one assertion updated. Declared-scope violation reported rather than
silently widened; the orchestrator approved the widening before the fix agent proceeded.

**Follow-ups created**
`ExampleProvider::formatTools()` sorts tool keys with `usort` on an unstable comparator, so the
`tools[]` array order can vary between identical requests. That voids a cache prefix at position 0
(tools render before system). Not in scope for this step; belongs with Phase 10.

---

## ENTRIES

*(newest first — the first real entry goes directly below this line)*

### P0 CLOSE — Phase 0 complete: baseline, census, probe · 2026-08-26 04:35 · <pending sha>

**Status** done

**Worktree** (none — all three steps were orchestrator-executed read-only measurements)

**Base** `59411203c` → `19533373e` → `e98684167` (P0.S1's two commits; P0.S2/S3 added no commits of their own until this close entry)

**Goal (restated in one sentence)** Close Phase 0 with all three steps landed and the plan's measurement rails in place.

**What changed**
- `prompt_worklog.md`: P0.S2 + P0.S3 entries above (P0.S1 entry already in place).
- `.sugar-crush-prompt/progress.json`: P0.S2/S3 → done; baseline sha recorded (`59411203c`).
- `prompt_resume.md`: §8 rewritten for the Phase 1 start.

**Tests added or changed**
(none.)

**Deletion experiment**
(none.)

**MEASURED**
Suite status unchanged since P0.S1 baseline (no production or test file touched in Phase 0): 10351/160648/1. Phase commits contain only `.gitignore`, `prompt_worklog.md`, `prompt_resume.md`, and the gitignored progress.json.

**Suite result** Not re-run this phase (nothing in the phase changes the suite). Last measured at P0.S1: 10351/160648/1. Delta: 0.

**Phase review** None — deliberate: Phase 0's commits contain no `sugar-crush/src/` or `sugar-crush/tests/` change, so §1.7's cross-step seam review has no code to walk, and the one load-bearing deliverable (the P0.S2 census) was self-corrected during the phase (bare-variable pattern → arrow-access pattern, see P0.S2 entry). Follow-up: Phase 1's phase review agent should spot-check the P0.S2 census cells against the provider sources.

**Cross-step problems found**
- None within the phase; the census pattern correction is recorded in P0.S2's entry.

**Invariants touched** (none.)

**Surprises / things the plan got wrong**
- Phase 0 required no step agents at all: all three steps are measurement/bookkeeping and were executed directly by the orchestrator. This is consistent with §3.2 (bookkeeping is the orchestrator's) and with "you run the tests yourself" — noted for the record since the general loop implies agents.
- The Phase 0 "phase review agent" step of §1.7 was judged not applicable (no code); recorded here so a later reader knows it was a decision, not an omission.

**Follow-ups created**
- Phase 1 close: spot-check P0.S2 census cells.
- P4.S2: re-probe for usage payload with cache fields before fixing the fixture shape.

---

### P0.S3 — provider probe: models endpoint + system-message honouring · 2026-08-26 04:30 · <pending sha>

**Status** done

**Worktree** (none — read-only network probe, executed by the orchestrator)

**Base** `19533373e`

**Goal (restated in one sentence)** Confirm the default provider endpoint is reachable and that a plain `{"role":"system"}` message is honoured, and record the actual responses.

**What changed**
(none — read-only probe; no file touched.)

**Tests added or changed**
(none.)

**Deletion experiment**
(none.)

**MEASURED**
```sh
curl -sS --max-time 25 https://skynet2.interserver.net/v1/models
```
```
{"object":"list","data":[{"id":"deepseek-ai/DeepSeek-V4-Flash-0731","object":"model","owned_by":"local"}]}
```
CURL_EXIT 0. **`max_model_len` is NOT reported** — the `/v1/models` payload carries no such field. The plan asked to "confirm max_model_len reported": answer is it is absent; there is no advertised context limit to rely on from the endpoint.

```sh
curl -sS --max-time 60 -X POST https://skynet2.interserver.net/v1/chat/completions \
  -H 'Content-Type: application/json' -d '{"model":"deepseek-ai/DeepSeek-V4-Flash-0731","max_tokens":20,
  "messages":[{"role":"system","content":"Respond with exactly the single word BANANA and nothing else, no punctuation."},
  {"role":"user","content":"What fruit am I thinking of?"}]}'
```
```
{"id":"190d476a40834a02ab4a2252d7f17a9e","object":"chat.completion","created":1787717848,
"model":"deepseek-ai/DeepSeek-V4-Flash-0731","choices":[{"index":0,"message":{"role":"assistant",
"content":"BANANA","reasoning_content":null,"tool_calls":null},"logprobs":null,
"finish_reason":"stop","matched_stop":1}],"usage":{"prompt_tokens":27,"total_tokens":31,
"completion_tokens":4,"prompt_tokens_details":null,"reasoning_tokens":0},
"metadata":{"weight_version":"default"}}
```
CURL_EXIT 0. **Plain `{"role":"system"}` IS honoured**: the model followed the system instruction exactly ("BANANA", stop). Also recorded: usage shape has `prompt_tokens`/`completion_tokens`/`total_tokens`/`reasoning_tokens`, `prompt_tokens_details: null`, no cache fields — relevant to P4.S1 (Usage buckets) and P10 (cache breakpoints).

**Suite result** Not re-run this step (read-only). Last measured at P0.S1: 10351/160648/1.

**Review loop** (none — no agent work.)

**Invariants touched** (none.)

**Surprises / things the plan got wrong**
- `max_model_len` is not reported by this server, so "confirm max_model_len" resolves to "absent — record that", not to a number.
- The probe is the first live evidence for P4.S2's "REAL-shaped usage payload" requirement: the actual response has no cache token fields, so P4.S2 will need to copy the usage shape from a real cached response or state why the fixture differs.

**Follow-ups created**
- P4.S2 should re-probe for a usage payload with cache fields (`prompt_tokens_details` was null here) before deciding the fixture shape.

---

### P0.S2 — census: which CompleteRequest properties each provider reads · 2026-08-26 04:30 · <pending sha>

**Status** done

**Worktree** (none — read-only census, executed by the orchestrator)

**Base** `19533373e`

**Goal (restated in one sentence)** For all 7 providers × all 14 public properties of `CompleteRequest`, measure line-level read counts so the plan's transmission work starts from a verified table.

**What changed**
(none — read-only census; no file touched.)

**Tests added or changed**
(none.)

**Deletion experiment**
(none.)

**MEASURED**
Methodology correction (see Surprises): the plan's "grep -c the property name" pattern was first run as `\$<prop>\b`, which matches **bare local variables** (`$model = ...`) and misses property reads (`$request->systemPrompt`). That first run reported systemPrompt=0 for every provider including OpenAIProvider, contradicting the dossier's known read at OpenAIProvider.php:90-92. The census below uses the arrow-access pattern `-><prop>\b` (quoted command):

```sh
cd /home/sites/sugarcraft/sugar-crush/src/Providers && for prop in model messages tools systemPrompt \
  temperature maxTokens jsonSchema topP topK minP repetitionPenalty stop extraTemplateKwargs \
  reasoningEffort; do for prov in SglangProvider CustomProvider OpenAIProvider BedrockProvider \
  ClaudeCodeProvider EchoProvider VertexProvider; do /usr/bin/grep -c "\->$prop\b" "$prov.php"; done; done
```

| property | Sglang | Custom | OpenAI | Bedrock | ClaudeCode | Echo | Vertex |
|---|---|---|---|---|---|---|---|
| model | 10 | 3 | 3 | 1 | 0 | 0 | 1 |
| messages | 2 | 2 | 2 | 2 | 2 | 2 | 3 |
| tools | 5 | 4 | 4 | 0 | 4 | 0 | 2 |
| **systemPrompt** | **0** | **0** | **2** | **4** | **2** | **0** | **2** |
| temperature | 1 | 2 | 2 | 2 | 0 | 0 | 2 |
| maxTokens | 1 | 2 | 2 | 2 | 0 | 0 | 2 |
| jsonSchema | 4 | 0 | 0 | 0 | 0 | 0 | 0 |
| topP | 1 | 0 | 0 | 2 | 0 | 0 | 2 |
| topK | 1 | 0 | 0 | 0 | 0 | 0 | 2 |
| minP | 1 | 0 | 0 | 0 | 0 | 0 | 0 |
| repetitionPenalty | 1 | 0 | 0 | 0 | 0 | 0 | 0 |
| stop | 1 | 0 | 0 | 4 | 0 | 0 | 4 |
| extraTemplateKwargs | 1 | 0 | 0 | 0 | 0 | 0 | 0 |
| reasoningEffort | 4 | 0 | 0 | 0 | 0 | 0 | 0 |

Read sites for systemPrompt (grep -n, verbatim):
```
OpenAIProvider.php:90   if ($request->systemPrompt !== null) {
OpenAIProvider.php:92       [['role' => 'system', 'content' => $request->systemPrompt]],
BedrockProvider.php:164  if ($request->systemPrompt !== null) {            (complete path)
BedrockProvider.php:165  $params['system'] = [['text' => $request->systemPrompt]];
BedrockProvider.php:214  if ($request->systemPrompt !== null) {            (stream path)
BedrockProvider.php:215  $params['system'] = [['text' => $request->systemPrompt]];
ClaudeCodeProvider.php:80   'systemPrompt' => $request->systemPrompt,     (JSON payload key)
ClaudeCodeProvider.php:105  'systemPrompt' => $request->systemPrompt,
VertexProvider.php:489  * therefore hoisted here and joined onto the request's own systemPrompt.  (docblock)
VertexProvider.php:495  if ($request->systemPrompt !== null && $request->systemPrompt !== '') {
VertexProvider.php:496  $parts[] = $request->systemPrompt;
SglangProvider.php:0  CustomProvider.php:0  EchoProvider.php:0
```
ClaudeCodeProvider also carries 2 array-style `'systemPrompt'` occurrences (payload keys, included in the 2 above).

**Not-read cells** (grep returned 0, file named): systemPrompt — SglangProvider.php, CustomProvider.php, EchoProvider.php (Echo is a stub, n/a); temperature/maxTokens — ClaudeCodeProvider.php, EchoProvider.php; jsonSchema — all except SglangProvider.php; topP — Custom, OpenAI, ClaudeCode, Echo; topK — Custom, OpenAI, Bedrock, ClaudeCode, Echo; minP/repetitionPenalty/extraTemplateKwargs/reasoningEffort — all except SglangProvider.php; stop — Custom, OpenAI, ClaudeCode, Echo; tools — Bedrock, Echo; model — ClaudeCode, Echo.

**Headline** (confirms the lead finding at line level): the **default** provider (Sglang, 0 reads) and CustomProvider (0) never read `systemPrompt`; OpenAI reads it in `complete()` only (both occurrences are at :90-92; `completeStream()` has none — the interactive path drops it); Bedrock, ClaudeCode, Vertex read it.

**Suite result** Not re-run this step (read-only). Last measured at P0.S1: 10351/160648/1.

**Review loop** (none — no agent work.)

**Invariants touched** (none.)

**Surprises / things the plan got wrong**
- The plan's P0.S2 wording ("grep -c quoted" of property names) is ambiguous in a way that produces a wrong census: the bare-name pattern counts locals, not reads. Arrow-access `->prop` is the correct pattern. Recorded here so P0.S2-style audits elsewhere use it.
- Bedrock reads systemPrompt on BOTH paths (dossier's "E19" concern about hoisting history SystemMessages is a different matter and remains P1.S4).

**Follow-ups created**
(none.)

---

### P0.S1 — baseline suite + tracking rails · 2026-08-26 04:16 · `19533373e`

**Status** done

**Worktree** (none — bookkeeping + measurement step, executed directly by the orchestrator in the main repo; no `sugar-crush/src/` or `sugar-crush/tests/` file was touched, so no step agent was spawned)

**Base** `59411203c` (master, clean tree before this entry)

**Goal (restated in one sentence)** Record a dated, verbatim baseline of the full sugar-crush suite and stand up the plan's tracking files, so every later delta has a fixed reference point.

**What changed**
- `.gitignore`: added `/.sugar-crush-prompt/` — the tracking dir is local orchestrator state, never committed.
- `.sugar-crush-prompt/progress.json` (new, gitignored): phase/step status map covering all 12 phases and 61 steps (`not_started` except P0.S1), plus the baseline figures below.
- `prompt_worklog.md`: this entry.
- `prompt_resume.md`: rewritten per §R — banner (`Current state: Phase 0, step P0.S1 complete`), §4 → "how to resume", §5 → gate decision line, §8 state block.

**Tests added or changed**
(none — this step adds no tests; the suite was run, not modified.)

**Deletion experiment**
(none — no guard added this step.)

**MEASURED**
Full suite, main repo, no worktree, no `composer install` first:

```sh
cd /home/sites/sugarcraft/sugar-crush && vendor/bin/phpunit
```

```
PHPUnit 10.5.64 by Sebastian Bergmann and contributors.
Runtime:       PHP 8.3.6
Configuration: /home/sites/sugarcraft/sugar-crush/phpunit.xml
...
Time: 06:27.985, Memory: 344.04 MB

OK, but some tests were skipped!
Tests: 10351, Assertions: 160648, Skipped: 1.
```

EXIT 0. (Stderr noise during the run — WorktreeManager refusals, provider-unavailable notices, hook refusals — is deliberate test-expected output from the suite itself.)

Consistency check: `git log -1` subject on the base commit reads "plan: close round 60 - floor 10351/160648" — the other plan's floor matches this baseline exactly.

**Suite result** Verbatim summary above. This is the **Baseline** figure: Tests 10351, Assertions 160648, Skipped 1. Delta: n/a (first measurement). Any later skip added must be called out against this.

**Review loop** (none — no agent work this step.)

**Invariants touched** (none — no production or test file touched.)

**Surprises / things the plan got wrong**
- No caliber pre-commit hook is installed (`grep -q caliber .git/hooks/pre-commit` → no-hook). AGENTS.md would have me run `caliber refresh` manually before committing, but prompt_plan.md §1.9 and §7 forbid running caliber. Commits proceed with the hook absent; nothing is suppressed (`--no-verify` never used).
- The three `crush-lane-{a,b,c}` trees are NOT worktrees of this repo (absent from `git worktree list`) — they are separate checkouts, so their in-flight state is unobservable without reading them, which is forbidden. The sequencing gate therefore rests on the plan's own phase-safety analysis (§5): phases 0–4 add no `src/` files and touch no lane-owned files except `Chat.php`/`ContextCompactor.php` in Phase 4; phases 5+ add ~11 `src/` files and will fight the file-count census. Re-check before Phase 5.

**Follow-ups created**
- P0.S2 census and P0.S3 provider probe are next; they run concurrently and are read-only.

---

