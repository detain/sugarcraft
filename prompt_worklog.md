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

---

## Required entry format

Every step entry uses exactly this shape. Sections are mandatory; write `(none)` rather than
omitting one.

```markdown
### <STEP_ID> — <one-line what changed>   ·   <YYYY-MM-DD HH:MM>   ·   <commit sha>

**Status** done | blocked | declined
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

