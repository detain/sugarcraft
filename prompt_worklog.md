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

### P3.S2 — Emit the working diff only on the step after a write   ·   2026-08-28   ·   8a31f239c (merged dabcd27f7)

**Status** `done`
**Worktree** /home/sites/prompt-step-P3.S2 (removed at close)
**Base** 9737ba185 (master at branch point)

**Goal (restated in one sentence)**
The two size-capped git diff sections (Staged/Unstaged) render only on the step AFTER a write tool ran, defaulting to emit; golden prompt byte-identical; every invariant green; byte delta recorded.

**What changed**
- `sugar-crush/src/Context/EnvironmentBlock.php`: 5th constructor param `bool $writeSinceLastRender = true` (default keeps pre-P3.S2 behaviour — suppression only explicit, never implicit), bare accessor `writeSinceLastRender()`, immutable fluent `withWriteSinceLastRender(bool): self` (new instance). Both gitDiffSection calls in gitStatusSnapshot() gated on the flag (subprocess count 5→3 when suppressed). Docblock rewritten per P3.S1 reality: 'WHAT IT COSTS IN PROMPT CACHE' paragraph now states env is emitted LAST on Runtime::buildSystemPrompt() (the RepoMapBlock re-prefill argument gone; the Agent::systemPrompt() tail claim preserved); the 'lever' paragraph replaced with the implemented signal + cross-turn semantics, quoted verbatim: 'Refining the first prompt of a turn to start suppressed after a quiet turn belongs to the caller that wires this signal into the engine loop, and that caller does not exist yet; the signal existing with a truthful default is what this step ships, and the wiring step decides whether a quiet turn earns a quiet opening.' (EnvironmentBlock.php:110-114).
- `sugar-crush/tests/Context/EnvironmentBlockTest.php`: +2 tests.

**Tests added or changed**
- `EnvironmentBlockTest::testTheDiffIsEmittedOnlyOnTheStepAfterAWrite` (EnvironmentBlockTest.php:697) — drives 3 renders: #1 default emits both diff labels; #2 `withWriteSinceLastRender(false)` + no write → assertStringNotContainsString for both diff labels while Status/branch lines survive; #3 after a write + re-armed true → body +rewritten-after-the-write present. Revert of the gate reddens #2 (at :713); inversion reddens #1 (at :707).
- `EnvironmentBlockTest::testWriteSinceLastRenderDefaultsToTrueAndIsAnImmutableSetter` (EnvironmentBlockTest.php:735) — default true, setter returns new instance (source untouched), 5th ctor param accepted directly.

**Deletion experiment**: (A) gate removed (if true) → RED at :713 'does not contain Staged changes (git diff'; (B) never emit (if false) → RED at :707; live-poll test (PromptStabilityTest:428) under never-emit stays GREEN via the Status: --porcelain field (scratch.txt untracked) — confirming the live-poll invariant is status-dependent, not diff-dependent. Both restored → green.

**MEASURED**
```
$ cd sugar-crush && vendor/bin/phpunit tests/Context/EnvironmentBlockTest.php
OK (39 tests, 112 assertions)
$ vendor/bin/phpunit tests/Providers/PromptStabilityTest.php
OK (10 tests, 46 assertions)
$ vendor/bin/phpunit tests/BaseSystemPromptTest.php
OK (12 tests, 87 assertions)      # golden byte-identical
$ vendor/bin/phpunit tests/SymbolCitationDriftTest.php tests/SwallowingCatchCensusTest.php tests/Support/DuplicatedTestHelperDriftTest.php tests/Support/ChildWallClockBudgetTest.php tests/Config/EnvRosterDriftTest.php tests/Tools/BuiltInToolCorpusTest.php
OK (103 tests, 9420 assertions)   # census 6-file set
$ vendor/bin/phpunit tests/Providers
OK (846 tests, 2047 assertions)
# Byte deltas (with-diff vs suppressed):
#   deterministic fixture (1 tracked rewrite + 1 untracked): 612 B → 301 B (delta 311 B)
#   this-checkout dirty tree: 8954 B → 693 B (delta 8261 B)
#   reviewer's independent figures: clean tree 735 B → 616 B (delta 119 B);
#   dirty scratch 3666 B → 289 B (delta 3377 B)
# Hard constraint testNoAdditionalWorkingDirectoriesLineIsEmitted untouched and green.
```

**Suite result**
```
$ cd sugar-crush && vendor/bin/phpunit
OK, but some tests were skipped!
Tests: 10404, Assertions: 160919, Skipped: 1.
```
Baseline for comparison: 10351/160648/1 (P0.S1). Delta: +53 tests, +271 assertions, 0 new skips (cumulative; this step +2 tests, +26 assertions vs P3.S1 close 10402/160893/1). Verified in a NON-tty agent-bash environment: two tests — `Chat\CompactModelSummaryTest::testWithASummarizerCompactReturnsACmdAndRewritesNothingYet` and `MouseModalGuardTest::testMidTurnUnderAnOverlayTheAgentsClickAndCtrlAAgreeOnEverythingButTheirWords` ('command palette' data set) — fail ONLY when phpunit runs under a pty with a live terminal (renderer viewport depends on tty size); they pass in non-tty env. Environment artifact, not a regression — tests untouched.

**Review loop**
- Cycle 1 (fresh reviewer): NO MERGE-BLOCKING FINDINGS. F1 MODERATE §16.1 — the suppression polarity is reachable ONLY from tests at merge time: all four production construction sites use bare capture() with default true (Runtime.php:1850, App/App.php:553, Agents/Agent.php:417, Cli/Bootstrap.php:1462); production output byte-identical to pre-change (proven by golden-green). Per §16.1 this is a finding, not a completion — recorded here verbatim. F2 NIT — test docblock wording — FIXED in d05728826.
- Cycle 2 (fresh reviewer, never saw cycle 1): NO MERGE-BLOCKING FINDINGS. F1 MODERATE §16.1 (same finding, planned; recorded at merge).
- Total cycles: 2.

**§16.1 finding (MODERATE, review F1) — RESOLVED BY SCHEDULING**: wiring step P3.S5 added to the plan (plan edit 5dec5a6d4) — 'Wire the write-signal into the engine loop' (Runtime.php + Backend/EngineBackend.php + tests/RuntimeTest.php), not yet executed.

**Invariants touched**
- §17.2 — none broken. Golden byte-identical (BaseSystemPromptTest 12/87). No src/ file added (EnvironmentBlock.php modified in place; census unchanged 103/9420; Providers unchanged 846/2047). Strict types, final readonly, immutable with* via new self, 5th param defaulted (3rd slot untouched).

**Surprises**
- Worktree-checkout measurement quirk: EnvironmentBlock correctly reports 'Is directory a git repo: No' for the sugar-crush/ subdir in a worktree checkout (.git lives at the worktree root); measured at the repo root instead.

**Follow-ups created**
- P3.S1 F1 (EnvironmentBlock.php:66-75 stale docblock) — RESOLVED by this merge: the docblock was rewritten as part of the P3.S2 diff (see What changed; verbatim quote at EnvironmentBlock.php:110-114). No new follow-ups from this step. Standing items carry forward unchanged: F2 MemoryBlock.php:52-54 → Phase 6; F3 PromptStabilityTest.php:411,435; F4 AgentTest.php:312-327; obs A ensureFixtureRepo() hardening (later golden-touching steps P3.S3/S4, P5.S4-P5.S6, P9.S5).

### P3.S1 — Move <env> to the end of the system prompt   ·   2026-08-28   ·   9a1c6fa5e, 0571d1c48 → merged 379ecc7d6

**Status** `done`
**Worktree** /home/sites/prompt-step-P3.S1 (removed after merge)
**Base** 924c71a0d (Phase 2 close)

**Goal (restated in one sentence)**
Reorder buildSystemPrompt()'s layers by mutation frequency — stable first (base heredoc, repo map, project instructions, memory, skills), volatile <env> (git status + diffs) last, matching Claude Code's placement of its git block — with every old ordering pin inverted rather than deleted, and the golden regenerated showing a pure block move.

**What changed**
- `sugar-crush/src/Runtime.php`: moved the <env> append (`$base .= "\n\n" . $this->environmentSnapshot($app)->render();`, byte-identical) from directly after the base heredoc to the end of `buildSystemPrompt()`, after the SkillMatcher listing. Constructor signature, per-Runtime memoisation and all three snapshot accessors untouched (§17.2 constraints 2, 9). Rewrote the ordering-rationale docblock and both assembly comments to the new WHY (mutation-frequency ordering, cache-prefix argument, prompt_expand.md §4.4/§9.2).
- `sugar-crush/tests/RuntimeTest.php`: inverted the pin (env now asserted AFTER `<project-instructions>`) and renamed the test to `testBuildSystemPromptOrdersProjectInstructionsBeforeEnvironmentBlock`.
- `sugar-crush/tests/Integration/SystemPromptWiringTest.php`: inverted two pins — `testBothHalvesLandInOneSystemPromptWithEnvironmentLast` (renamed from …EnvironmentFirst) and the first chain link of `testTheFixtureAssemblesEveryControlledHalfInTheRealOrder` (`assertLessThan($mapAt, $envAt)` → `assertLessThan($envAt, $mapAt)`; the other four chain links unchanged) — with docblocks explaining the inversion.
- `sugar-crush/tests/Integration/FeatWiringReachabilityTest.php`: inverted the pin and renamed the test to `testARealLaunchDeliversTheEnvironmentBlockAfterProjectInstructions`.
- `sugar-crush/tests/Context/RepoMapBlockTest.php`: inverted the pin (`assertGreaterThan($envEnd, $mapAt)` → `assertLessThan($envEnd, $mapAt)`) and renamed the test to `testTheBlockReachesTheSystemPromptBeforeTheEnvironmentBlock`.
- `sugar-crush/tests/Integration/MemoryPromptWiringTest.php`: inverted the pin (`assertLessThan($memory, $env)` → `assertLessThan($env, $memory)`) and renamed the test to `testTheMemoryBlockSitsBeforeTheEnvironmentBlockInThePrompt`.
- `sugar-crush/tests/BaseSystemPromptTest.php`: base prompt no longer defined as "everything before the first `<env>`" (that slice is now the whole prompt); added `BASE_END_MARKER = 'commands to follow.'` — the final line of the base heredoc, unique in the assembly — and `basePrompt()` slices at it, with docblock stating the delimiter change and the assertNotFalse message naming the marker. Nine test methods consume `basePrompt()`. Also refreshed the seven-layer docblock of `testSystemPromptMatchesCommittedGolden` to the new order (1 base → 2 repo-map → 3 project-instructions → 4 project-memory → 5 skill contributions → 6 SkillMatcher listing → 7 `<env>` block).
- `sugar-crush/tests/fixtures/prompt/golden-system-prompt.txt`: REGENERATED (reason below). `<env>` moved from layer 2 to the final position; nothing else changed.

**Tests added or changed**
No test methods added or removed — six ordering assertions inverted in place (§16.2 "Invert, do not delete"), one slice mechanism replaced, five names realigned with their inverted bodies. Each would red if `<env>` returned to second place or the marker were reverted:
- `RuntimeTest::testBuildSystemPromptOrdersProjectInstructionsBeforeEnvironmentBlock` — `assertLessThan(strpos($result, '<env>'), strpos($result, '<project-instructions>'))`.
- `SystemPromptWiringTest::testBothHalvesLandInOneSystemPromptWithEnvironmentLast` — same polarity via `soleSystemPrompt()`.
- `FeatWiringReachabilityTest::testARealLaunchDeliversTheEnvironmentBlockAfterProjectInstructions` — same polarity via a real launch.
- `SystemPromptWiringTest::testTheFixtureAssemblesEveryControlledHalfInTheRealOrder` — chain now ends with env; `assertLessThan($envAt, $mapAt)`.
- `RepoMapBlockTest::testTheBlockReachesTheSystemPromptBeforeTheEnvironmentBlock` — `assertLessThan($envEnd, $mapAt)`.
- `MemoryPromptWiringTest::testTheMemoryBlockSitsBeforeTheEnvironmentBlockInThePrompt` — `assertLessThan($env, $memory)`.
- `BaseSystemPromptTest::basePrompt()` (feeds nine tests) — `assertNotFalse($markerAt, '…no longer ends with its end-of-base marker…')`.

**Deletion experiment**: two parts, both RED→restored→GREEN. (A) Temporarily moved the env append back to position 2 in Runtime.php → 7 failures: the golden pin + all six inverted assertions; Restore → green. (B) Mutated `BASE_END_MARKER` to 'commands to follow, or else.' → all nine marker-fed BaseSystemPromptTest tests red with the explicit marker message; Restore → green. Review cycle 2 independently repeated the revert mutation (scratch copy, tree md5-verified restored): same 7 failures.

**MEASURED**
```
$ cd /home/sites/prompt-step-P3.S1/sugar-crush && vendor/bin/phpunit tests/BaseSystemPromptTest.php tests/RuntimeTest.php tests/Integration/SystemPromptWiringTest.php tests/Integration/MemoryPromptWiringTest.php tests/Integration/FeatWiringReachabilityTest.php tests/Context/RepoMapBlockTest.php
OK (221 tests, 740 assertions)      # identical to pre-change baseline
$ vendor/bin/phpunit tests/SymbolCitationDriftTest.php tests/SwallowingCatchCensusTest.php tests/Support/DuplicatedTestHelperDriftTest.php tests/Support/ChildWallClockBudgetTest.php tests/Config/EnvRosterDriftTest.php tests/Tools/BuiltInToolCorpusTest.php
OK (103 tests, 9420 assertions)
$ vendor/bin/phpunit
OK, but some tests were skipped!
Tests: 10402, Assertions: 160893, Skipped: 1.
# post-merge on master: tests/Providers OK (846 tests, 2047 assertions); check-path-repos: no sibling path-repos (58 libs)
$ md5sum <old golden>    # pre-regeneration golden = P2.S2's committed bytes
e89d98c72975ca8c22914d7f6796ec7a
$ wc -c tests/fixtures/prompt/golden-system-prompt.txt   # old and new both 5099 bytes
5099
```
Golden old→new diff (block moved, nothing else changed — env body byte-identical, same 5099-byte total):
```
45a46,83
> <repo-map> … (stable block, moved before env) … </repo-map>
> <project-instructions> … </project-instructions>
> <project-instructions> … </project-instructions>
> <project-memory> … </project-memory>
> ## Skill: fixture-helper … - fixture-helper: Fixture skill for the golden prompt
89,127c127
< </env>
< <repo-map> … (the same stable block, in its old position after </env>)
< </project-memory>
< ## Skill: fixture-helper …
---
> </env>
```
Human-legible reason the new bytes are correct: the seven layers now appear in exactly the order the plan mandates — base heredoc (44 lines, unchanged), `<repo-map>`, two `<project-instructions>` documents, `<project-memory>`, skill body + listing, then `<env>` LAST. The env body is byte-identical to the old golden's env body (verified by extraction + exact reconstruction: old == base + ENV + B, new == base + B + ENV, reconstruction equality exact); the pinned fixture git state (Platform linux, Model claude-sonnet-4-6, date 2026-08-26, branch main, `A docs/notes.md` / `M src/Lib.php` / `?? scratch.txt`, commit 7be5249) is unchanged. Total length 5099 → 5099 bytes, which only a pure relocation produces; the pre-regeneration file's md5 (e89d98c7…) matches P2.S2's recorded golden md5. Regeneration procedure: /tmp script replicating `BaseSystemPromptTest::ensureFixtureRepo()` + `::goldenContext()` (pinned clock 2026-08-26, model claude-sonnet-4-6, injected 'linux').

**Suite result**
```
$ cd sugar-crush && vendor/bin/phpunit
OK, but some tests were skipped!
Tests: 10402, Assertions: 160893, Skipped: 1.
```
Baseline for comparison: 10351/160648/1 (P0.S1). Delta: +51 tests, +245 assertions, 0 new skips (cumulative across Phases 1-2; this step itself added no tests — the six target suites are byte-identical to their pre-change counts, 221/740).

**Review loop**
- Cycle 1 (fresh reviewer): verdict — core change correct, byte-pure, precisely scoped; all six pins + golden red under its own revert mutation; golden pure move verified by construction; production reachability confirmed (Runtime::run() → buildSystemPrompt() → EngineBackend.php:606 → Cli/Bootstrap → bin/sugarcrush). Findings: F1 [MODERATE, out-of-scope] EnvironmentBlock.php:68-71 'WHAT IT COSTS IN PROMPT CACHE' docblock now argues the opposite of the new assembly → deferred to P3.S2/S3 (file in their declared lists). F2 [MODERATE, out-of-scope] MemoryBlock.php:50-52 docblock premise false ('EnvironmentBlock::render() sits AHEAD of this block') → deferred to a later phase (Phase 6). F3 [MINOR, in-scope] BaseSystemPromptTest.php:543-556 seven-layer docblock stale → FIXED. F4 [MINOR, in-scope] five test names contradicting their inverted bodies → FIXED via renames (commit 0571d1c48). F5 [MINOR, out-of-scope doc] prompt_expand.md §11 constraint 6 ledger still states pre-step order → fixed in this bookkeeping commit. F6 [PROCESS] worklog entry absent → this entry.
- Cycle 2 (fresh reviewer, never saw cycle 1): **NO FINDINGS in scope**. Re-ran everything (all counts identical incl. full suite 10402/160893/1); repeated the revert mutation (same 7 red); golden verified byte-exact by extraction + reconstruction; 19/19 checks. Four non-blocking stale position-claim docblocks in out-of-scope files, reported not prescribed: EnvironmentBlock.php:66-75 (same as c1 F1), MemoryBlock.php:52-54 (same as c1 F2), tests/Providers/PromptStabilityTest.php:411+435 (section banners claim env 'sits at the very front of the prefix' — test itself position-free and green), tests/Agents/AgentTest.php:312-327 (docblock claims Runtime seats env EARLY 'layer 2 of 7' and the two assemblers are 'deliberately opposite' — now false; also references the old base-prompt slice semantics). All four → follow-ups below.
- Total cycles: 2.

**Invariants touched**
- §17.2 constraint 4 — deliberately CHANGED, as P3.S1 mandates: base prompt is now delimited by the explicit end-of-base marker, not "everything before the first `<env>`"; BaseSystemPromptTest docblock states it.
- §17.2 constraint 6 — deliberately CHANGED: the three ordering invariants' six assertion sites inverted, not deleted (env-after-instructions ×3, env-after-repo-map ×2, env-after-memory ×1); deletion experiment proved all six bite. The sixth site (SystemPromptWiringTest fixture-order chain) was not named in the step text — noted in Surprises.
- §17.2 constraints 1, 2, 3, 5, 7, 8, 9, 10, 11 — KEPT: `buildSystemPrompt(App): string` private shape (18 reflection sites untouched), constructor third-positional EnvironmentBlock untouched, `environmentSnapshot()` reflectable + `assertSame`-stable, exactly four `# ` headings intact, fence spellings unchanged, leading-whitespace contracts unchanged (`EnvironmentBlock::render()` still starts `<env>\n` and ends `\n</env>`; separators still `"\n\n"`), memoisation still per-Runtime, `substr_count` de-duplication pins untouched, empty-layer suppression untouched. `Agent::systemPrompt()` (opposite-order assembler) untouched — AppSkillDispatchTest/AgentTest pins unaffected.
- §16.2 golden discipline — followed: regeneration with stated reason, old→new diff pasted above, human-legible correctness argument; leak scan (`testGoldenSystemPromptLeaksNoHostPaths`) still green.

**Surprises**
- The plan text names five assertion sites but §17.2 item 6 says six: the sixth is SystemPromptWiringTest.php:316, the first chain link of `testTheFixtureAssemblesEveryControlledHalfInTheRealOrder` (added by P2.S4), which pins the same env-before-repo-map invariant as RepoMapBlockTest. It was inverted with the other five and its bite proven.
- Plan line numbers were stale by ~5 lines (pre-P2.S4 measurements); test names matched exactly.
- The regenerated golden has the same byte count as the old one (5099 → 5099), making the pure-move verification trivial and rigorous.
- Step text says "nine assertions … depend on it" — it is nine test *methods* (each carrying multiple assertions); count matches, wording imprecise (non-material).

**Follow-ups created**
- F1: `src/Context/EnvironmentBlock.php:66-75` docblock must be rewritten when P3.S2/S3 touch the file (env is now LAST on the buildSystemPrompt path; Agent::systemPrompt() tail claim at :72-73 remains true). P3.S2's goal quotes this docblock.
- F2: `src/Context/MemoryBlock.php:52-54` docblock premise ('EnvironmentBlock::render() sits AHEAD of this block') is false post-P3.S1 — revisit in Phase 6 (Context & Memory).
- F3: `tests/Providers/PromptStabilityTest.php:411,435` stale position comments ('environment block at the very front of the prefix') — prose only, test green and position-free.
- F4: `tests/Agents/AgentTest.php:312-327` docblock claims Runtime seats `<env>` EARLY (layer 2 of 7) and the two assemblers are 'deliberately opposite' — both false post-P3.S1; also references the retired base-prompt slice semantics.
- Standing obs A: `ensureFixtureRepo()` staleness hardening — recommended for later golden-touching steps (P3.S2/S3/S4, P5.S4-S5.S6, P9.S5).

## Phase 2 — close (P2.S1..P2.S4)
Phase-close review over whole diff 0f3bf202f..HEAD (18 commits) VERDICT FINDINGS — 1 LOW gate-level: git diff --check exit 2 on intentional trailing-space bytes at golden-agent-prompt.txt:42 + golden-system-prompt.txt:84 (whitespace-only lines inside pinned sample-diff sections; byte-goldens pass). Fixed: repo-root .gitattributes `sugar-crush/tests/fixtures/prompt/** whitespace=-trailing-space` → diff --check exit 0. Suites on master: BaseSystemPromptTest 12/87, census 6-file 103/9420, Providers 846/2047, phase suites 174/520, check-path-repos exit 0. F1 fold PASS (SSE_BODY byte-identical, 155 bytes, verified structurally); F2 fold PASS (three distinct ''-semantics pinned in SystemPromptTransmissionMatrixTest). Phase-level 19-check: all PASS. Bookkeeping verified (af1e6079f touches only resume+worklog; §8 batch cleared). Master clean; no worktrees; no prompt/* branches. Steps done 14 of 61; phases done 3 of 12. Close commit 3d7c7e420.

## P2.B2 — batch close (P2.S2 + P2.S4)
Batch P2.B2 closed: P2.S2 (golden system prompt) merged 74148433d; P2.S4 (prompt-composition fixture) merged dfb618f16. Both reviewed APPROVE (19/19); Providers 846/2047 + check-path-repos exit 0 after each merge; all worktrees removed; branches prompt/P2.S2 + prompt/P2.S4 deleted. Master dfb618f16; steps done 14 of 61; phases done 2 of 12.

## P2.S4 — prompt-composition fixture (merged dfb618f16)
1aa8677e2 (5 files +406/-44) added tests/Prompt/PromptFixture.php (235 lines; closure-based buildSystemPrompt harness; docblock explains why not tests/Support/ — cross-plan lane + DuplicatedTestHelperDriftTest), 3 fixture-exercising tests in SystemPromptWiringTest (+111; testARealChatKeystrokeTurnDeliversBothHalves untouched), migrated 9 prompt tests (MemoryPromptWiring 3, RepoMapBlock 4, Runtime 2) with assertions character-identical (sorted assert-line multisets byte-identical 28/147/255), restored orphaned fixture-backed systemPrompt() helper in RepoMapBlockTest. edcad3ef1 fixed cycle-1 LOW finding (PSR-12 EOF trailing newline; 1 file +1/-1). Review: c1 FINDINGS (1 LOW) → fixed; c2 fresh reviewer APPROVE 19/19, no findings. Suites: SystemPromptWiring 11/65, MemoryPromptWiring 14/36, RepoMapBlock 62/163, Runtime 87/256, census 103/9400 (base 103/9390; +10 accepted by SymbolCitationDriftTest + ChildWallClockBudgetTest), Providers 846/2047, check-path-repos exit 0. Deletion experiments A/B/C RED→GREEN (cycle-1 agent). Merged --no-ff dfb618f16; worktree removed; branch prompt/P2.S4 deleted.

## P2.S2 — golden system prompt (merged 74148433d)
d19f06665 (9 files +533/-2) pinned Runtime::buildSystemPrompt() output to tests/fixtures/prompt/golden-system-prompt.txt (assertSame) + 10-fragment host-path leak scan with /^\//m guard + deterministic fixture-repo builder + regeneration-discipline docblock; 30a32a49b added the '/test/' leak fragment (cycle-1 LOW finding). Review: c1 FINDINGS (1 LOW) → fixed; c2 fresh reviewer APPROVE 19/19 (mutations measured: golden 't'→'x' RED at BaseSystemPromptTest.php:570; mid-file '/test/path/leak' RED at :602; restored byte-identical md5 e89d98c72975ca8c22914d7f6796ec7a). Suites: BaseSystemPromptTest 12 tests/87 assertions; census 6-file set 103/9410; Providers 846/2047 (post-merge); check-path-repos exit 0. Observations A/B/C non-blocking (A: stale ensureFixtureRepo hardening recommended for later golden-touching steps P3.S1/P5.S4-P5.S6/P9.S5; B: gitRun env gaps; C: AgentTest.php:571-578 pinHostLines comment stale-in-spirit — recorded follow-up). Merged --no-ff 74148433d; worktree removed; branch prompt/P2.S2 deleted. Orchestrator: coder-executed merge + Providers run.

### P2.B2 session status #3 · 2026-08-28 · reviewer delegate died (timeout, no output) → respawned via script(1)

**P2.S2 REVIEWER DELEGATE DIED** 2026-08-28 03:51:10: delegate `salty-rose-opossum` (agent=reviewer) hit the infrastructure's 900s delegation timeout with NO output — artifact is 384 bytes, no verdict, no partial review (started 03:36:05). Same failure class as the step agents today. No review ever produced → cycle 1 still counts as cycle 1; cap 5 unchanged.

**REVIEWER RESPAWNED via script(1)+setsid** (proven method from P2.S4 run 3): `setsid script -qec 'opencode run --dir /home/sites/prompt-step-P2.S2 --title "P2.S2 reviewer cycle 1 (script)" ...'` → script pid **3844012**, opencode pid **3844014**, RUNNING with brief loaded. Brief `/tmp/opencode/p2s2-reviewer-brief.md` = §1.4 verbatim (19 checks + run-the-code + report rules, prompt_plan.md:346-447) + P2.S2 step text verbatim (prompt_plan.md:1279-1299) + diff position (d19f06665 vs base 687e442a9, 9 files +533/-2) + agent-reported numbers (verify-don't-trust) + READ-ONLY hard rules (no Edit/Write/git-writes; phpunit allowed) + CRITICAL WARNING (final message = complete report; VERDICT: APPROVE | VERDICT: FINDINGS last line). Logs `/tmp/opencode/p2s2-reviewer-{bg,script}.log`. Monitor: short pgrep + ANSI-stripped tail; verdict = report completion.

**P2.S4 run 3** (setsid pids 3772627/3772629): still ALIVE 03:56, exploring migration candidates (SystemPromptWiringTest, MemoryPromptWiringTest, RepoMapBlockTest:1075+).

**Master HEAD**: c4ab685ac (status #2 bookkeeping), tree clean. Identity Joe Huss <detain@interserver.net>.

### P2.B2 session status #2 · 2026-08-28 · P2.S2 review cycle 1 started + P2.S4 run 2 died, run 3 respawned (setsid)

**P2.S2 REVIEW CYCLE 1 STARTED** 2026-08-28: delegate agent=reviewer, ID **salty-rose-opossum**, RUNNING. Fresh reviewer, 19-check bar (§1.4, prompt_plan.md:332). Prompt = §1.4 verbatim + P2.S2 step text + diff file list (9 files +533/-2) + orchestrator-measured numbers (BaseSystemPromptTest 12/86, Providers 846/2047, census 103/9410, deletion experiment t→x red/green). Read-only: cannot run git/phpunit; reads files in worktree, compares against base at main repo. On APPROVE → merge per declared order (P2.S2 → P2.S4, tests/Providers/ between, then worktree remove + branch -d). On FINDINGS → fix agent → NEW reviewer. Never reuse a reviewer; never tell a reviewer a previous review happened.

**P2.S4 RUN 2 DIED** 2026-08-28: "Error: Bad Gateway" mid-turn while pty_read + reads were pending; script exit 1. Worktree left PRISTINE (git log master..prompt/P2.S4 empty; only .opencode/package.json artifact). Run 2 logs rotated to `/tmp/opencode/p2s4-{bg,script}-run2.log` — script log contains run 2's COMPRESSED EXPLORATION SUMMARY (buildSystemPrompt assembly order; EnvironmentBlock/MemoryBlock/RepoMapBlock/InstructionFileLoader/App/Skill/SkillMatcher/MemoryStore APIs; PromptFixture design state — tests/Prompt/PromptFixture.php, ::new() + with*() via mutate(), Runtime+HookManager/HookRegistry+EnvironmentBlock ctor injection, reflection invoke on private buildSystemPrompt; migration candidates RepoMapBlockTest.php:1113-1222 + helper :1304, MemoryPromptWiringTest.php content tests; tests/Prompt/ non-existent; BaseSystemPromptTest.php OFF-LIMITS). = PRIOR ART for run 3 (brief points the agent at it first).

**P2.S4 RUN 3 RESPAWNED (setsid)** 2026-08-28: plain-`&` spawn attempt was killed by the bash tool's 120s timeout killing the process group ("Session terminated, killing shell..."). Respawned with `setsid script -qec 'opencode run ...' ... < /dev/null &` — script pid **3772627**, opencode pid **3772629**, RUNNING with brief loaded, logs `/tmp/opencode/p2s4-{bg,script}.log`. Brief = original + RESPAWN AMENDMENT (never end turn with async pending) + RESPAWN AMENDMENT 2 (strict synchronous-only — no pty/delegate; read /tmp/opencode/p2s4-script-run2.log FIRST as prior art; BaseSystemPromptTest.php off-limits; worktree verified pristine — start fresh).

**Master HEAD**: 96dc9a00b (session status bookkeeping #1), tree clean. Identity verified Joe Huss <detain@interserver.net>. Merge order unchanged: P2.S2 → P2.S4 with tests/Providers/ between; then worktree remove + `git branch -d` both; BATCH P2.B2 CLOSE + Phase 2 close (phase review over `git diff 0f3bf202f..HEAD`; F1/F2 fold spot-check) → Phase 3 P3.S1 fully serial.

### P2.B2 session status · 2026-08-28 · liveness check + P2.S2 done + P2.S4 respawned

**Liveness check 2026-08-28** (user ask "check if its still going if not retry"): P2.S2 agent COMPLETE — full 7-section report, commit **d19f06665** `prompt/P2.S2: pin the full assembled system prompt to a committed golden` (single squashed commit, author Joe Huss, not pushed). Suites per agent: BaseSystemPromptTest 12/86 green; Providers 846/2047 exact baseline; census set 103/9410 vs 9390 baseline (+20, all green: +20 fixture files, +10 test file, parent tree 10 below snapshot — measured by experiment); check-path-repos exit 0. Deletion experiment: one byte `t→x` in git-log subject → red, exact divergence named, restored → green. Deviations (in-scope, declared files only): fixture path depth fix (sugar-crush/vendor not monorepo vendor); `readGolden`→`readSystemPromptGolden` rename for the drift census. Orchestrator verification 2026-08-28: re-ran in worktree — BaseSystemPromptTest **12/86 OK**, Providers **846/2047 OK**. → AWAITING REVIEW (delegate agent=reviewer, 19-check bar) then merge per declared order.

**P2.S4 FIRST ATTEMPT DIED**: script(1) run exited cleanly 2026-08-28 10:20:49 (COMMAND_EXIT_CODE=0) with NO completion report — agent ended its turn while an exploration delegation + pty_read were pending ("Waiting on the exploration report now"). Worktree pristine (no commits; only `.opencode/package.json` runtime artifact). Logs rotated to `/tmp/opencode/p2s4-{bg,script}-run1.log`.

**P2.S4 RESPAWNED** 2026-08-28: same brief + **RESPAWN AMENDMENT** appended (never end turn with async pending — sync bash/Read/Grep only; if a tool result does not arrive in-turn treat as failed and re-run synchronously; final message MUST be the complete 7-section report). script(1) pid **3253714**, logs `/tmp/opencode/p2s4-{bg,script}.log`, worktree `/home/sites/prompt-step-P2.S4`.

**Master HEAD**: 9f531b566 (spawn bookkeeping), tree clean. Merge order unchanged: P2.S2 → P2.S4 with tests/Providers/ between; then worktree remove + `git branch -d` both; BATCH P2.B2 CLOSE + Phase 2 close (phase review over `git diff 0f3bf202f..HEAD`; F1/F2 fold spot-check) → Phase 3 P3.S1 fully serial.

### BATCH P2.B2 OPEN · 2026-08-26 17:2x

**Steps**: P2.S2 + P2.S4 CONCURRENT (file-disjoint). P2.S2 — golden system prompt (tests/BaseSystemPromptTest.php + tests/fixtures/prompt/golden-system-prompt.txt new + fixture tree new; depends P2.S1 merged; done = golden + one-byte-change red + leak scan /tmp/ /home/ author username + regeneration discipline comment). P2.S4 — prompt-composition harness (tests/Prompt/PromptFixture.php NEW — NOT tests/Support/ due to cross-plan lane collision, docblock must state why — + tests/Integration/SystemPromptWiringTest.php + ≥3 migrated prompt tests with character-identical assertions; depends P2.S1).

**Merge order declared at spawn**: P2.S2 → P2.S4 with tests/Providers/ between.

**Worktrees**: /home/sites/prompt-step-P2.S2 (branch prompt/P2.S2) + /home/sites/prompt-step-P2.S4 (branch prompt/P2.S4), both base 687e442a9, vendor cp -al + PSR-4 verified.

**Spawn mechanism**: script(1) wrapper pids 1484560 (P2.S2) + 1484561 (P2.S4) per user directive 2026-08-26 (no pty_spawn; task tool NOT in orchestrator toolset).

**Reviewers**: delegate (agent=reviewer) primary else script(1).

### P2.S3 — the golden agent prompt · 2026-08-26 16:5x · 6a6df4ddc
**Status**: done · **Worktree**: /home/sites/prompt-step-P2.S3 removed · **Base**: 0f3bf202f · **Commits**: 8fa2721d9 (step) + 6a6df4ddc (merge)
**Goal (restated)**: `Agent::systemPrompt()` golden-pinned — assembles in the OPPOSITE ORDER (agent text, then `<env>`); two assemblers deliberately separate; test comment names the two colliding assertions (AgentTest.php:251 vs BaseSystemPromptTest.php:135).
**What changed**: AgentTest.php +270 — testSystemPromptMatchesCommittedGolden (assertSame byte pin :355-359; deliberate-opposite-order docblock :315-326 naming both colliding assertions; regeneration discipline note :338-342; pinHostLines() normalization :332-336; deterministic fixture repo materialised at test time under vendor/prompt-fixture/agent-repo — nested .git impossible in git 2.43, 3 bypass attempts failed; GIT_CONFIG_GLOBAL/SYSTEM neutralized, pinned dates, core.abbrev 7, chmod 0644 umask-proof) + testGoldenAgentPromptLeaksNoHostPaths (Roo bug-class leak scan: no /tmp/ /home/ /Users/ C:\Users\ /my/ 'Joe Huss', no golden line starting '/'); golden-agent-prompt.txt +45 new (983B, 45 lines, agent-text-first); Agent.php UNTOUCHED.
**Tests added or changed**: 2 tests (golden byte-pin, red on any one-byte golden change; leak scan).
**Deletion experiment**: `focused`→`focusedx` one-byte golden mutation → FAILURES! Tests: 23, Assertions: 108, Failures: 1 ('Agent::systemPrompt() drifted from the committed golden' at AgentTest.php:355) → restored byte-identical → green.
**MEASURED**: AgentTest OK (23 tests, 108 assertions) @00:00.035 — agent's claimed 23/124 was WRONG, tree is authority; tests/Providers/ OK (846 tests, 2047 assertions) @00:01.722; census OK (103 tests, 9390 assertions) @00:12.139; main repo after merge OK (846 tests, 2047 assertions) @00:01.703.
**Review loop**: 1 cycle — top-magenta-gecko APPROVE 19/19, 3 nitpicks no-action (hard line-number citations :320/:323; absolute-vs-relative cwd in ensureFixtureRepo :346-351; /my/ derived from author home :382 — all belt-and-braces).
**Invariants touched**: none — no src/ files (census 297/316 held); Agent.php untouched.
**Surprises**: nested .git impossibility → test-time fixture materialization; AgentTest claim discrepancy 23/124 vs measured 108; GIT_CONFIG neutralization + pinned dates + core.abbrev 7 for determinism; chmod 0644 umask-proof.
**Follow-ups created**: pinHostLines() drop once P2.S1 injectability lands (noted in code).

### BATCH P2.B1 CLOSE · 2026-08-26 16:5x
Merged in actual order: P2.S1@e60a083d2, P2.S3@6a6df4ddc with tests/Providers/ between; Did not merge: (none); Suite after last merge: tests/Providers/ OK (846 tests, 2047 assertions) @00:01.703 Memory 64.50 MB.

### P2.S1 — injectable clock, platform, and cwd for prompt assembly · 2026-08-26 16:3x · e60a083d2
**Status**: done · **Worktree**: /home/sites/prompt-step-P2.S1 (removed) · **Base**: 0f3bf202f
**Goal (restated)**: two `buildSystemPrompt()` calls with the same injected clock/platform/cwd produce byte-identical output (assertSame), the 18 reflection sites still pass, and the third positional constructor slot stays `?EnvironmentBlock`.

**What changed**
- `sugar-crush/src/Context/EnvironmentBlock.php` (+22): constructor 4th param `?string $platform = null`; new bare `platform()` accessor; render line now `($this->platform ?? strtolower(PHP_OS_FAMILY))`; constructor docblock added (the ctor previously had none) citing `charmbracelet/crush.WithPlatform` — the platform is injectable so prompt assembly is golden-testable on any host.
- `sugar-crush/tests/RuntimeTest.php` (+42): two new tests — byte-identical prompt across two runtimes with same injected values, and platform-injected-not-polled (assertNotSame `darwin` + containment). `Runtime.php` deliberately untouched (injected block already wins via `??=`).

**Tests added or changed**
- `testBuildSystemPromptWithSameInjectedClockPlatformAndCwdIsByteIdenticalAcrossRuntimes` — assertSame across two runtimes + assertNotSame for `darwin`; red on revert.
- `testBuildSystemPromptPlatformIsInjectedNotPolledFromTheBuild` — contains/not-contains + accessor; red on revert.

**Deletion experiment** — replaced `($this->platform ?? strtolower(PHP_OS_FAMILY))` with `(strtolower(PHP_OS_FAMILY))` in EnvironmentBlock.php:
```
FAILURES! Tests: 87, Assertions: 254, Failures: 2   (both new tests red, at RuntimeTest.php:1751)
```
Restored via `git checkout --`; tree byte-identical; green again 87/256.

**MEASURED**
```sh
vendor/bin/phpunit tests/RuntimeTest.php
# OK (87 tests, 256 assertions) Time 00:00.132

vendor/bin/phpunit tests/Providers/
# OK (846 tests, 2047 assertions) Time 00:01.718

# census 6-file set (SymbolCitationDrift + SwallowingCatchCensus + DuplicatedTestHelperDrift + ChildWallClockBudget + EnvRosterDrift + BuiltInToolCorpus)
# OK (103 tests, 9390 assertions) Time 00:12.456

vendor/bin/phpunit tests/BaseSystemPromptTest.php tests/Context/RepoMapBlockTest.php
# OK (72 tests, 238 assertions) Time 00:00.264

# main repo after merge
vendor/bin/phpunit tests/Providers/
# OK (846 tests, 2047 assertions) Time 00:01.712
```

**Review loop** — Cycle 1 — reviewer `disturbing-azure-stork` (orchestrator-delegated): APPROVE, 19/19 checks PASS/N-A, 1 nitpick (no action): the not-contains polarity at :1752 would red on a Windows build host; irrelevant for Linux CI. Total cycles: 1.

**Invariants touched**: (none) — no `src/` files added (BuiltInToolCorpus census held 297); `__construct` third positional slot intact (RuntimeTest.php:1701); `buildSystemPrompt()` private one-App, 18/18 reflection sites green.

**Surprises / things the plan got wrong**: EnvironmentBlock's constructor had NO docblock at all — added one as part of this step. `RepoMapBlockTest` lives in `tests/Context/`, not `tests/`. Platform-injection edge on Windows hosts documented in test comment.

**Follow-ups created**: Phase 3 golden can rely on full byte-determinism (date + platform + cwd injectable); OS-version / PHP-version lines remain build-derived (out of scope).

### BATCH P2.B1 OPEN · 2026-08-26 16:2x

**Steps**: P2.S1 + P2.S3 CONCURRENT, file-disjoint. P2.S1 — injectable clock/platform/cwd for prompt assembly (sugar-crush/src/Runtime.php + sugar-crush/src/Context/EnvironmentBlock.php + sugar-crush/tests/RuntimeTest.php; HARD: `__construct(ProviderInterface, HookManager, ?EnvironmentBlock)` third positional slot taken (RuntimeTest.php:1701); `buildSystemPrompt(App): string` stays a private instance method, 18 reflection sites; done: two calls with same injected values byte-identical, assertSame). P2.S3 — golden agent prompt (sugar-crush/src/Agents/Agent.php + sugar-crush/tests/Agents/AgentTest.php + sugar-crush/tests/fixtures/prompt/golden-agent-prompt.txt new; HARD: do NOT unify `Agent::systemPrompt()` with `Runtime::buildSystemPrompt()` — two assemblers deliberately, AgentTest.php:251 vs BaseSystemPromptTest.php:135; done: agent golden exists + test comment states opposite order + names the two colliding assertions).
**Merge order declared at spawn**: P2.S1 → P2.S3, `tests/Providers/` run between merges.
**Worktrees**: /home/sites/prompt-step-P2.S1 (branch prompt/P2.S1) + /home/sites/prompt-step-P2.S3 (branch prompt/P2.S3), both base 0f3bf202f, vendor cp -al + PSR-4 verified.
**Spawn mechanism**: script(1) wrapper pids 1953095 (P2.S1) + 1953096 (P2.S3), per user directive 2026-08-26 (no pty_spawn; `task` tool NOT in orchestrator toolset — delegate/script(1) used).
**Reviewers**: delegate (agent=reviewer) primary, else script(1) wrapper.

### P1 CLOSE — Phase 1 (Transmission) closed · 2026-08-26 16:1x · e513409c5

**Status**: done · **Worktree**: (none — phase review ran in main repo) · **Base**: 19a46ac9f (phase start) → e513409c5

**Goal (restated)**: The model request built like `Runtime::run()` must carry the assembled seven-layer system prompt on the wire, in every provider's protocol field, on both batch and streaming paths.

**What changed**: All 7 steps merged to master in declared order: P1.S1 SglangProvider both paths (2d4f738f2), P1.S2 CustomProvider both paths (a27f60229), P1.S3 OpenAIProvider completeStream (99caad991), P1.S4 Bedrock Converse system array / E19 (0013e9730), P1.S5 streamed-Usage per-delta contract / E24 (193317de1), P1.S6 PromptStabilityTest rebuilt production-shaped (070d1f5fb), P1.S7 SystemPromptTransmissionMatrixTest + shared `providerImplementers()` roster (843432e13). Plus per-step fix commits and bookkeeping commits to e513409c5.

**Phase review**: Cycle 1 — phase reviewer sharp-blue-swordtail (delegate, read-only): APPROVE 19/19 checks, 0 blocking findings; P0.S2 census spot-check PASS (every provider that CAN transmit now reads `->systemPrompt`: Sglang 0→2, Custom 0→4, OpenAI 2→4, Bedrock 4→2 consolidated into systemBlocks(), ClaudeCode 2→2, Vertex 2→2, Echo 0→0 exempt). Cross-step problems found (non-blocking, folded into Phase 2 planning): F1 SSE-fixture byte-identity is comment-claimed not structural (was false once, fixed 51f6b90f5; shared constant or comparing assertion recommended); F2 three distinct `''`-semantics now permanently pinned (OpenAI transmits empty, Bedrock hard-fails at SDK validator, Sglang/Custom/Vertex omit) — latent today, unify when Phase 2 makes assembly deterministic. Reviewer sandbox denied phpunit — its suite numbers OBSERVED from worklog, not MEASURED. Total phase review cycles: 1.

**MEASURED**
```
$ vendor/bin/phpunit            # §1.7c full-suite checkpoint, main repo @e513409c5
OK, but some tests were skipped!
Tests: 10393, Assertions: 160779, Skipped: 1.
Time: 06:35.276, Memory: 344.05 MB
```
Delta vs P0.S1 baseline (10351/160648/1, never edited): **+42 tests, +131 assertions, same 1 skip, EXIT 0**. Tests/Providers/: OK 846/2047 (progression 808/1960 at phase start → 846/2047 at close). Census set: OK 103/9390.

**Deletion experiment** (phase-level, from step evidence): per-step deletion experiments all red-on-revert and restored byte-identical (P1.S1 2 red, P1.S2 3 red, P1.S3 1 red, P1.S4 4+1 red, P1.S5 3 red, P1.S6 2 red, P1.S7 1 red at :162). Phase removed nothing net (only superseded Bedrock inline blocks replaced by shared helper; old-shape PromptStability assertions adapted, none dropped).

**Surprises / things the plan got wrong**: Step agents died at inner-delegate waits repeatedly (P1.S1/S2/S3/S4 originals, P1.S7) — Rung 3 continuations recovered all; delegate tool degraded 07:53+ (4 zero-message timeouts) then recovered for later reviewers; pty_spawn degraded/recovered cycles, finally retired per user directive 2026-08-26 (no pty_spawn — use task tool with Coder subagent type; task tool NOT in orchestrator toolset, delegate/script(1) used); script(1) wrapper validated as TTY fallback; DsmlToolCallParser.php:230 pre-existing stdout diagnostic; P1.S6 fix agent's comment-only deletion-experiment waiver accepted; docblock-truth findings (P1.S6) and M1 (P1.S7) caught by reviewers.

**Follow-ups created**: (1) P4.S2 re-probe usage payload for cache fields before fixing fixture shape; (2) F1 SSE-fixture shared-const/assertion fold into Phase 2; (3) F2 `''`-semantics unification fold into Phase 2; (4) sequencing gate re-check before Phase 5/6 (census collision ahead — ~11 src/ files).

### P1.S7 — the transmission matrix test · 2026-08-26 15:4x · 843432e13
**Status**: done
**Worktree**: `/home/sites/prompt-step-P1.S7` — removed
**Base**: 8064f27aa
**Goal (restated)**: One test that walks EVERY provider `ProviderFactory` can build, hands each an identical `CompleteRequest` with a distinctive `systemPrompt` sentinel, and asserts the sentinel appears in the payload that provider would put on the wire — enumerating providers DYNAMICALLY (reflection over `src/Providers/`), not from a hand-written list; `EchoProvider` exempted with a named reason. A provider added later with no systemPrompt handling fails on day one.
**What changed**
- `sugar-crush/tests/Providers/SystemPromptTransmissionMatrixTest.php` NEW (619 lines, 15 tests/46 assertions, step commit `8a916b802`, +619): `TRANSMISSION_CONTRACT` const (Sglang/Custom/OpenAI → `messages[0]`, Bedrock → `system[0].text`, Vertex → `system`, ClaudeCode → `--system-prompt argv`; EchoProvider deliberately absent — test double, no wire, EchoProvider.php:18-23,84-91); sentinel `P1S7-SENTINEL-4f8a2c91` asserted via `substr_count(json_encode(...), SENTINEL) === 1` (rides the system slot and nowhere else); per-provider BOTH-paths transmission tests driven exactly as each provider's own suite drives it (Sglang/Custom Guzzle history `sentBody()`; OpenAI captured `create()`/`createStreamed()` params; Bedrock Aws MockHandler `getLastCommand()->toArray()`; Vertex injected predictor/streamer closure `body['system']`; ClaudeCode `printModeArgs()` `--system-prompt` argv, both json+stream-json); null-polarity per provider; deliberate `''`-polarity pins (OpenAI + Bedrock guards are `!== null` ONLY — `''` IS transmitted; Bedrock pinned via reflected `systemBlocks()` because AWS SDK Converse validator rejects zero-length text blocks, measured); roster test (dynamic via `providerImplementers()`, asserts exemption diff === `['EchoProvider']` WITH named reason + stale-entry reverse check).
- `sugar-crush/tests/Providers/ProviderRequestResponseTest.php` +27/-4: P1.S5's derived-roster scan extracted into NEW `public static providerImplementers(): array` (glob `src/Providers/*.php` + `class_implements(ProviderInterface)`, sorted, docblock: born in P1.S5, shared with P1.S7 so the two contracts cannot drift). No other logic changed.
- Fix commit `51f6b90f5` (+1/-1): `SSE_BODY` const at :107 made byte-identical to the `OpenAIProviderTest` fixture it claims to mirror (`"content":"hi"` → `"content":"Hello"`, `[DONE]\n` → `[DONE]\n\n`) — closes finding M1 (false provenance claim). Behavior-neutral: no assertion reads chunk content.
**Tests added or changed**
- `SystemPromptTransmissionMatrixTest::testEveryProviderImplementerHasATransmissionContract` — derived roster via `ProviderRequestResponseTest::providerImplementers()`; exemption diff === `['EchoProvider']` WITH named reason; stale-entry reverse `assertSame([])`.
- Per-provider both-paths transmission tests (6): Sglang/Custom/OpenAI/Bedrock/Vertex/ClaudeCode — sentinel in each wire payload via `assertSame` + `substr_count === 1` (e.g. :162/:166 for Sglang complete+stream, :295/:306 Bedrock system block, :367/:378 Vertex body['system'], :413/:423 ClaudeCode argv).
- Per-provider null-polarity tests (6): sentinel absent, exact shapes (`assertArrayNotHasKey('system')` Bedrock :321, Vertex :389; exact `['role'=>'user']` Sglang).
- `''`-polarity pins (2): OpenAI `''` IS transmitted (guard `!== null` only :90/:127); Bedrock `''` IS shaped into a block (guard `!== null` only :341) — pinned via ReflectionMethod because SDK validator rejects zero-length blocks.
**Deletion experiment**: mutated `SglangProvider.php:672` guard → `if (false && ...)`: FAILURES! Tests: 15, Assertions: 41, Failures: 1 — `testSglangTransmitsSystemPromptAsTheLeadingSystemMessageOnBothPaths` 'Failed asserting that two arrays are identical. - 'role' => 'system' + 'role' => 'user', - 'content' => 'P1S7-SENTINEL-4f8a2c91' + 'content' => 'Hi', SystemPromptTransmissionMatrixTest.php:162.' Restored → src/ byte-identical, green again (15/46). What it showed: guard kill drops the sentinel from the wire → matrix red on the first assertion of the first provider path — a provider added later with no handling fails on day one, exactly as the step demands. (45-not-46 note: failing test short-circuits its remaining assertions.)
**MEASURED**
```
# worktree (base 8064f27aa) — agent, continuation agent, reviewer and orchestrator identical
$ vendor/bin/phpunit tests/Providers/SystemPromptTransmissionMatrixTest.php
OK (15 tests, 46 assertions), Time 00:00.053
$ vendor/bin/phpunit tests/Providers/ProviderRequestResponseTest.php
OK (32 tests, 72 assertions), Time 00:00.028
$ vendor/bin/phpunit tests/Providers/
OK (846 tests, 2047 assertions), Time 00:01.697
$ vendor/bin/phpunit tests/SymbolCitationDriftTest.php tests/SwallowingCatchCensusTest.php tests/Support/DuplicatedTestHelperDriftTest.php tests/Support/ChildWallClockBudgetTest.php tests/Config/EnvRosterDriftTest.php tests/Tools/BuiltInToolCorpusTest.php
OK (103 tests, 9390 assertions), Time 00:12.408   # 9387→9390: DuplicatedTestHelperDriftTest fixed
# main repo after merge
$ vendor/bin/phpunit tests/Providers/
OK (846 tests, 2047 assertions)   # was 831/2001 pre-merge (+15/+46)
```
**Suite result**: Providers dir green at every stage (831/2001 → 846/2047); census 103/9380 → 103/9390 (census interplay: `offlineRuntimeClient(MockHandler $handler)` parameter list textually identical to BedrockProviderTest.php:801 — NOTHING added to `ACCEPTED_SIGNATURE_DIVERGENCE`, alias strategy: `use Aws\MockHandler;` + `use GuzzleHttp\Handler\MockHandler as GuzzleMockHandler;`); no skips added.
**Review loop** (RECONSTRUCTED — original step agent died at inner-coder wait):
- Cycle 1 — original step agent (pty_966581c9) TIMED OUT at inner-Coder-Agent wait (5400s) with NO commits; inner coder's uncommitted implementation (both files) inspected + verified by me (target 15/46 + 32/72 + 846/2047 green; census 1 failure) → Rung 3 continuation agent (pty_59384184) completed: committed `8a916b802`, fixed census failure, ran all suites + deletion experiment, full 7-section report.
- Cycle 1 — reviewer regulatory-brown-crane (delegate): **FINDINGS — 1× MINOR (M1)** — docblock :102-104 + `SSE_BODY` :107 claim 'byte-identical to the OpenAIProviderTest fixture' FALSE (delta `'hi'` vs `'Hello'`, `[DONE]\n` vs `[DONE]\n\n`; measured vs OpenAIProviderTest.php:642-648). 18/19 checks PASS/N-A; 19-check table otherwise clean (roster DYNAMICALLY derived via `providerImplementers()` not from TRANSMISSION_CONTRACT ✓; subtraction = only inline derivation relocated ✓; `''`-polarity pins honest ✓; deletion experiment corroborated ✓; done-when ledger ✓).
- Cycle 1 fix — script(1) agent (pid 447341): commit `51f6b90f5` (+1/-1) made SSE_BODY byte-identical; verified by me (diff read, target 15/46 + Providers 846/2047 green, worktree clean).
- Cycle 2 — reviewer hurt-cyan-dormouse (delegate): **APPROVE — 8/8 checks PASS, zero findings**; M1 closed; both fixtures compared byte-for-byte; behavior-neutrality structural + orchestrator-measured.
- Total cycles: 2 (1 finding fixed).
**Invariants touched**: (none — test-only change; no new src/ files so census cardinalities untouched; roster PASS; `BuiltInToolCorpusTest` implicit member not triggered). P1.S5 roster extraction shared → two contracts cannot drift.
**Surprises / things the plan got wrong**: original step agent died at inner-coder wait (2nd occurrence of that pattern) → Rung 3 continuation completed fully; coder's uncommitted implementation verified by me before continuation; MY census-fix prescription had TWO errors caught by the continuation agent (aliasing `Aws\MockHandler as MockHandler` would FATAL against the existing `GuzzleHttp\Handler\MockHandler` import; FOUR Aws call sites not two) — the agent's corrected fix implemented; user spawn-mechanism directive 2026-08-26: NO more pty_spawn — use task tool with Coder subagent type; task tool NOT present in orchestrator toolset (delegate = read-only only) → delegate/script(1) used, noted to user; M1 (provenance-truth claim) caught by cycle-1 reviewer — same class as P1.S6 docblock overstatement.
**Follow-ups created**: (none new — 3 standing carried: phase-1-close census-cell spot-check, P4.S2 cache-field re-probe, prompt_plan.md:1203 Goal-line over-claim correction).

### BATCH P1.B2 CLOSE · 2026-08-26 15:4x
**Merged in actual order**: P1.S6@070d1f5fb, P1.S7@843432e13 — one at a time, `tests/Providers/` run between merges.
**Did not merge**: (none).
**Suite after last merge**: tests/Providers/ OK (846 tests, 2047 assertions), Time 00:01.716, Memory 64.50 MB.
**Spawn-mechanism history (P1.S7)**: pty_966581c9 (original, timed out at inner-coder wait) → Rung 3 continuation pty_59384184 (completed, committed 8a916b802) → reviewer regulatory-brown-crane (delegate) → fix via script(1) pid 447341 (51f6b90f5) → cycle-2 reviewer hurt-cyan-dormouse (delegate, APPROVE 8/8). User directive 2026-08-26: no more pty_spawn — task tool with Coder subagent type; task tool NOT in orchestrator toolset; delegate/script(1) used.

### P1.S6 — rebuild PromptStabilityTest against CompleteRequest::$systemPrompt, retire MiniMax-M2.7 literal · 2026-08-26 13:2x · 070d1f5fb
**Status**: done
**Worktree**: `/home/sites/prompt-step-P1.S6` — removed
**Base**: 2550caf1a
**Goal (restated)**: Rebuild the repo's only prefix-cache guard against `CompleteRequest::$systemPrompt` — the shape production actually sends — asserting byte equality AND byte position of the prefix across two turns, retiring the stale MiniMax-M2.7 literal for `SglangProvider::DEFAULT_MODEL` (no second constant, no spelled id).
**What changed**
- `sugar-crush/tests/Providers/PromptStabilityTest.php` rebuilt 9 tests/37 assertions → 10/46 (step commit `d4da63824`, +125/-36): requests built like `Runtime::run()` (`systemPrompt:` named arg only, no `SystemMessage` inside `$messages`, model from `SglangProvider::DEFAULT_MODEL`); byte equality + byte position across two turns; net-new negative-polarity test (null AND `''` both mean unset); tool-schema byte stability; streaming-vs-batch prefix identity; EnvironmentBlock determinism tests kept; five old-shape `SystemMessage` constructions + import + MiniMax literals deleted — every old assertion survives adapted, none dropped/weakened; class docblock brief preserved verbatim + WHY block extended.
- Fix commit `0df904a6` (+18/-15): docblock WHY block reworded to the true claim (SystemMessage inside `$messages` IS a legal live shape via transcript Role::System notices → `EngineBackend::toTypedMessages()` :1510, encoded SglangProvider.php:973 — tests deliberately pin the Runtime::run() shape) + EOF trailing newline restored (PSR-12 §2.3).
**Tests added or changed**
- `PromptStabilityTest::testSystemPromptBytesAndOffsetAreIdenticalAcrossTurns` — byte equality AND byte position (strpos offsets) across two turns; red on revert (`assertIsInt($first)` fails, chunk absent) :194.
- `PromptStabilityTest::testSystemTurnLeadsTheMessageArraySoThereIsAPrefixToShare` — `"messages":[{"role":"system"` leads; red on revert :216.
- `PromptStabilityTest::testSystemPromptIsOmittedWhenUnsetOrEmpty` (NEW) — null AND `''` both unset, no system chunk, bodies byte-identical.
- Tool-schema byte stability, tool-order non-normalization, full-featured byte-identical bodies, streaming/batch prefix identity, EnvironmentBlock determinism + git-snapshot liveness (5 adapted tests).
**Deletion experiment**: mutated `SglangProvider.php:672` guard → `if (false && ...)`: FAILURES! Tests: 10, Assertions: 45, Failures: 2 — `testSystemPromptBytesAndOffsetAreIdenticalAcrossTurns` ('Failed asserting that false is of type int.' :194) + `testSystemTurnLeadsTheMessageArraySoThereIsAPrefixToShare` (starts-with :216). Restored → src/ byte-identical, target green again. (45 not 46 assertions: failing test short-circuits.) Fix commit: comment-only change — deletion-experiment waiver accepted (no behaviour to delete; suites green at exact counts).
**MEASURED**
```
# worktree (base 2550caf1a) — agent and orchestrator identical
$ vendor/bin/phpunit tests/Providers/PromptStabilityTest.php
OK (10 tests, 46 assertions), Time 00:00.047
$ vendor/bin/phpunit tests/Providers/
OK (831 tests, 2001 assertions), Time 00:01.716
$ vendor/bin/phpunit tests/SymbolCitationDriftTest.php tests/SwallowingCatchCensusTest.php tests/Support/DuplicatedTestHelperDriftTest.php tests/Support/ChildWallClockBudgetTest.php tests/Config/EnvRosterDriftTest.php tests/Tools/BuiltInToolCorpusTest.php
OK (103 tests, 9380 assertions)
# main repo after merge
$ vendor/bin/phpunit tests/Providers/
OK (831 tests, 2001 assertions)   # was 830/1992 pre-merge (830-9+10, 1992-37+46)
```
**Suite result**: target delta +1 test/+9 assertions vs old 9/37 file; Providers dir green at every stage (830/1992 → 831/2001); census unchanged 103/9380; no skips added.
**Review loop** (RECONSTRUCTED — cycle 1 reviewer died on its own compress-retry loop after writing its verdict to its bg log; verdict read from log):
- Cycle 1 — pty/script(1)-fallback reviewer (pid 2234225): **FINDINGS — 2 × MINOR** (A: docblock :46-59 blanket claim 'a SystemMessage instance inside $messages is a shape production NEVER sends' overstated — live path carries Role::System notices as SystemMessage via EngineBackend.php:1510, encoded SglangProvider.php:973; essential claim holds. B: missing EOF trailing newline, PSR-12 §2.3). 19/19 otherwise PASS/N-A; both suites + deletion experiment matched orchestrator.
- Cycle 1 fix — script(1) agent (pid 2729239): commit `0df904a6` reworded docblock + restored newline; verified by me (diff read in full, target 10/46 green, worktree clean).
- Cycle 2 — pty_spawn reviewer (pty_c3797ee2): **APPROVE — 19/19, findings A+B closed**; every reworded claim verified vs production file:line; EOF newline verified 3 ways; live run 10/46 @00:00.047 byte-for-byte orchestrator numbers.
- Total cycles: 2.
**Invariants touched**: (none). Roster PASS (test-only change). Census unchanged. `prompt_plan.md:1203` Goal line retains the retired over-claim — out of scope for the step (plan file not in declared list); follow-up created.
**Surprises / things the plan got wrong**: spawn-mechanism saga (createBackgroundProcess wedged at init → respawn via recovered pty_spawn); pty_spawn degraded/recovered cycles; script(1) wrapper validated for both agents and reviewers; cycle-1 reviewer died on compress-retry loop after writing verdict; cycle-2 reviewer used delegate(explore) + Coder-Agent (task tool) adaptations — delegate may be viable again (test once before relying); fix agent's comment-only deletion-experiment waiver accepted; DsmlToolCallParser.php:230 pre-existing stdout line; step-commit body title-only (observation — detailed message on merge commit).
**Follow-ups created**: (1) prompt_plan.md:1203 Goal line over-claim correction at phase 1 close; (2) standing: phase-1-close spot-check of P0.S2 census cells; (3) standing: P4.S2 re-probe usage payload for cache fields.

### BATCH P1.B2 OPEN · 2026-08-26 08:41
**Steps**: P1.S6 (rebuild PromptStabilityTest against `systemPrompt`) → P1.S7 (SystemPromptTransmissionMatrixTest) — SERIAL, in this order, one at a time.
**Merge order**: P1.S6, then P1.S7 — one at a time with `tests/Providers/` run between merges.
**Worktrees**: `/home/sites/prompt-step-P1.S6` (branch `prompt/P1.S6`) for P1.S6; fresh worktree for P1.S7 after P1.S6 merges.
**Base**: 2550caf1a (master HEAD at spawn; P1.S7 branches from master after P1.S6 merges).
**Spawn mechanism**: P1.S6 agent FIRST launched via `createBackgroundProcess` (task-omxo3ds, pid 633865) — the `pty_spawn` tool degraded at ~08:38 (3 consecutive failures incl. minimal command after session cleanup), `delegate` remains degraded since 07:53. **RECOVERED 2026-08-26 11:1x**: task-omxo3ds WEDGED AT INIT (run id b4010f47 — 13 log lines 08:38:19-24, then 3h07m silence; session.id never created, LLM runtime never selected, agent never read its brief; killed via targeted pids + orphaned snapshot git pid 1807237). createBackgroundProcess does NOT work for step agents (no TTY). `pty_spawn` RECOVERED ~11:1x — agent RESPAWNED as pty_3360ff67 (pid 1822271, notifyOnExit, timeout 5400). Fallback if pty_spawn breaks again: bash `script(1)` wrap (provides PTY).
**Reviewers**: pty-fallback `opencode run --dir <worktree>` read-only reviewer process (see P1.S5 entry); delegate tool still not trusted.
**Spawn mechanism (P1.S7)**: `pty_spawn` used directly (recovered — worked for P1.S6 cycle-2 reviewer) → **pty_966581c9** (pid 3462585, notifyOnExit, timeout 5400) in worktree `/home/sites/prompt-step-P1.S7` (branch `prompt/P1.S7`, base 8064f27aa, vendor cp -al + PSR-4 root verified). Fallback if it breaks again: bash `script(1)` wrap.

### P1.S5 — state the streamed-Usage delta contract with discriminating per-provider tests · 2026-08-26 08:00 · 193317de1
**Status**: done
**Worktree**: /home/sites/prompt-step-P1.S5 — removed
**Base**: 19a46ac9f · **Step commit**: a0b8bdf30 · **Merge**: 193317de1 (--no-ff, message /tmp/opencode/p1s5-merge.msg)
**Goal (restated in one sentence)**: E24 — ProviderInterface states whether streamed `tokensUsed`/`costUsd` are cumulative or per-delta, and a contract test goes red if any provider sums a cumulative wire total per chunk.
**What changed**
- `src/Providers/ProviderInterface.php`: `completeStream()` docblock added — streamed usage is per-delta, not cumulative; consumers sum across the stream (`Runtime::runStreaming()` then `Usage::sum()`); implementers whose wire reports cumulative totals must emit each total exactly once (terminal chunk, or disjoint bucket events as VertexProvider does); all-zero chunks compliant when the wire carried no usage; `@return \Generator<int, CompleteResponse>`.
- `tests/Providers/ProviderRequestResponseTest.php` (+353): `STREAMED_USAGE_CONTRACT` roster const (Sglang/Custom/OpenAI/Bedrock/Vertex = 30, ClaudeCode = 0) with three-family provenance docblock; derived-roster test `testEveryProviderImplementerHasAStreamedUsageContractFixture` (glob `src/Providers/*.php` + `class_implements`, EchoProvider exempt WITH named reason, stale-entry assertion); six per-provider tests. Sglang/Custom/OpenAI fixtures deliberately E24-hostile (cumulative usage 10/20/30 on EVERY chunk) asserting `assertContains($sum, [0, 30])` — current hardcoded-0 sums to 0, compliant terminal-once sums to 30, E24 per-chunk sums to 60 → red. Bedrock asserts terminal-metadata-once (30); Vertex asserts disjoint-bucket split (30); ClaudeCode asserts 0 (stream-json wire carries no usage).
**Tests added or changed**
- `tests/Providers/ProviderRequestResponseTest.php::testEveryProviderImplementerHasAStreamedUsageContractFixture` — derived roster, known-answer `['EchoProvider']` exemption, red on stale entry.
- `::testSglangStreamedUsageIsPerDeltaNotCumulative` (:495-530) — full `completeStream()`, SSE fixture 10/20/30 every chunk, `assertContains($sum, [0, 30])`; red if a provider reads the wire's cumulative total per chunk (60).
- `::testCustomStreamedUsageIsPerDeltaNotCumulative` (:532-567) — same shape.
- `::testOpenAiStreamedUsageIsPerDeltaNotCumulative` (:569-628) — real `ChatCreateStreamedResponse::from()` chunks, `parseChunk` via reflection, same discrimination.
- `::testBedrockStreamedUsageLandsOnceOnTheTerminalMetadataEvent` (:630-663) — ConverseStream event arrays as Aws EventParsingIterator yields, `assertSame(30)`.
- `::testVertexStreamedUsageIsSplitAcrossDisjointBucketEvents` (:665-699) — message_start 20 + message_delta 10 via injected closure, `assertSame(30)`.
- `::testClaudeCodeStreamedUsageIsPerDeltaNotCumulative` (:701-731) — `assertSame(0)`; wire carries no usage; proc_open child not unit-drivable, reflection justified and documented.
**Deletion experiment**
- (a) Docblock removal only (git apply -R src hunk): target STAYED GREEN (32/72) — as the plan predicts, "a docblock alone is documentation, not a contract"; not a finding, the guard is the tests.
- (b) THE REAL GUARD — E24 mutation, three one-line edits (SglangProvider.php:1152, CustomProvider.php:389, OpenAIProvider.php:257: `tokensUsed: 0` → read wire's cumulative total per chunk), applied by ORCHESTRATOR via python3 line-targeted edits:
  ```
  FAILURES!
  Tests: 32, Assertions: 72, Failures: 3
  Failed asserting that an array contains 60.  (at :524/:561/:622)
  ```
  Restored via `git restore`; `git diff --stat -- src/` empty (byte-identical); target green again 32/72. Identical 3-red result reproduced by the agent before commit and corroborated by the reviewer.
**MEASURED**
```
$ vendor/bin/phpunit tests/Providers/ProviderRequestResponseTest.php      (worktree)
OK (32 tests, 72 assertions)
$ vendor/bin/phpunit tests/Providers/                                     (worktree)
OK (811 tests, 1960 assertions)
$ vendor/bin/phpunit tests/SymbolCitationDriftTest.php tests/SwallowingCatchCensusTest.php tests/Support/DuplicatedTestHelperDriftTest.php tests/Support/ChildWallClockBudgetTest.php tests/Config/EnvRosterDriftTest.php tests/Tools/BuiltInToolCorpusTest.php
OK (103 tests, 9380 assertions)
$ vendor/bin/phpunit tests/Providers/                                     (MAIN repo, after merge 193317de1)
OK (830 tests, 1992 assertions)
$ vendor/bin/phpunit tests/Providers/ProviderRequestResponseTest.php tests/Tools/BuiltInToolCorpusTest.php tests/SymbolCitationDriftTest.php tests/Config/EnvRosterDriftTest.php   (reviewer's own runs, exit 0)
OK (32 tests, 72 assertions)
OK (80 tests, 5888 assertions)
```
Baseline: Tests 10351, Assertions 160648, Skipped 1 — delta vs baseline not re-measured this step (Providers dir +7 tests/+8 assertions vs 823/1984 after P1.S4).
**Suite result**: full suite not re-run this step; Providers dir green at every stage (agent, orchestrator, reviewer). No skips added.
**Review loop** (RECONSTRUCTED — original agent timed out at fix-spawn point; continuation agent completed fully with 7-section report)
- Cycle 1 — inner-session CodeReviewer (lost with the dead session): 1 MEDIUM finding — initial `assertSame(0)` tests with "0 IS THE CONTRACT-CONSISTENT ANSWER HERE" comments pinned the non-compliant behaviour and would red the correct fix; fixture could not distinguish E24 propagation from compliant terminal-once emission.
- Cycle 1 fix — orchestrator-directed trap-fixture redesign applied by continuation agent (pty_a4a07129), commit a0b8bdf30: `assertContains($sum, [0, 30])` + cumulative-10/20/30-every-chunk fixtures + misleading comments rewritten with provenance.
- Cycle 2 — fresh reviewer (pty-fallback process, delegate tool degraded): APPROVE, 19/19 checks pass, zero findings (reviewer ran its own suites: 32/72 target + 80/5888 census subset, both exit 0, byte-identical to mine).
- Total cycles: 2.
**Invariants touched**: (none). No new src/ files; no env vars/settings keys/commands added (roster PASS); census unchanged (103/9380).
**Surprises / things the plan got wrong**
- The `delegate` tool degraded mid-plan: 4 consecutive zero-message timeouts (3 concurrent at 05:50 + juicy-copper-gibbon 07:53) after 6 early successes (05:12-07:19). Pty-fallback reviewer pattern validated as replacement: `opencode run --dir <worktree>` with explicit READ-ONLY brief; this reviewer even ran its own suites via inner pty_spawn.
- Docblock-removal experiment staying green is expected and correctly NOT a finding (plan's own Done-when predicts it).
- DsmlToolCallParser stdout diagnostic = pre-existing parser-test log line, not a PHPUnit warning.
- 'gpt-4' literal in Sglang fixture construction matches the file's own established pattern and is inert (wire mocked).
**Follow-ups created**: (none).

### BATCH P1.B1 CLOSE · 2026-08-26 08:01
Merged, in this actual order: P1.S1@2d4f738f2, P1.S2@a27f60229, P1.S3@99caad991, P1.S4@0013e9730, P1.S5@193317de1
Did not merge: (none)
Suite after the last merge: Tests: 830, Assertions: 1992, Skipped: 0 (tests/Providers/ only)

### P1.S4 — hoist history SystemMessages into Bedrock Converse system array (E19) · 2026-08-26 07:25 · 0013e9730

**Status** done

**Worktree** /home/sites/prompt-step-P1.S4 (removed after merge)

**Base** `19a46ac9f`

**Goal (restated in one sentence)** Bedrock Converse receives the assembled system prompt AND every history `SystemMessage` as request-level `system` blocks on both `complete()` and `completeStream()` — never embedded as user-role messages — with the `system` key absent when neither is present.

**What changed**
- `sugar-crush/src/Providers/BedrockProvider.php`: both `complete()` and `completeStream()` now call `formatMessages($this->withoutSystemMessages($request->messages))` and set `$params['system'] = $system` from a new shared `systemBlocks(CompleteRequest)` when non-empty (`$system !== []` guard preserves the no-key wire shape). `systemBlocks()` emits the request `systemPrompt` block first, then each history `SystemMessage`'s text in history order. New private `withoutSystemMessages()` filters history (`array_values` + `array_filter` on `!instanceof SystemMessage`); the `formatMessages()` SystemMessage→`'user'` mapping is kept as total contract (only its trailing comment changed). Old inline paths genuinely replaced by the shared builder.
- `sugar-crush/tests/Providers/BedrockProviderTest.php`: +5 tests (section 15) asserting the BUILT payload via `$mock->getLastCommand()->toArray()` with real `BedrockRuntimeClient` + `Aws\MockHandler` (`offlineRuntimeClient()` :782-790 — deliberately not a PHPUnit double): complete/stream hoisting (history blocks in order), system-from-history-alone when no systemPrompt, no-`system`-key when neither present, and the measured `system user system system` adjacent-blocks collapse case. Then the fix cycle added the stream-path twin: `testCompleteStreamKeepsSystemAbsentWithoutPromptOrSystemMessages` (:742-759, `assertArrayNotHasKey('system', $sent)` :754 + exact `$sent['messages']` literal :755-758).

**Deletion experiment**
- Step agent's (verbatim): reverting the src hunk → `BedrockProviderTest` 4 failures (`system` array missing hoisted history blocks at `:668/:691/:713/:761`); `DELTEST_EXIT=1`; restored, tree byte-identical to baseline capture.
- Fix agent's (guard mutation): stream-path guard `:215-218` changed to unconditional `$params['system'] = $system;` (complete()'s block `:164` untouched) → filtered run `FAILURES! Tests: 1, Assertions: 1, Failures: 1` — `'Failed asserting that an array does not have the key "system".'` at `:754`; restored via `git restore` → SRC-CLEAN, guard byte-identical `:216-218`.

**MEASURED**
```
$ cd /home/sites/prompt-step-P1.S4/sugar-crush && vendor/bin/phpunit tests/Providers/BedrockProviderTest.php
OK (52 tests, 78 assertions)          # pre-fix
OK (53 tests, 80 assertions)          # post-fix (orchestrator re-ran: identical)

$ cd /home/sites/prompt-step-P1.S4/sugar-crush && vendor/bin/phpunit tests/Providers/
OK (809 tests, 1962 assertions)       # pre-fix
OK (810 tests, 1964 assertions)       # post-fix (orchestrator re-ran: identical)
# [DsmlToolCallParser fixture notice = pre-existing expected stderr, not a failure]

$ cd /home/sites/prompt-step-P1.S4/sugar-crush && vendor/bin/phpunit tests/SymbolCitationDriftTest.php tests/SwallowingCatchCensusTest.php tests/Support/DuplicatedTestHelperDriftTest.php tests/Support/ChildWallClockBudgetTest.php tests/Config/EnvRosterDriftTest.php tests/Tools/BuiltInToolCorpusTest.php
OK (103 tests, 9380 assertions)

$ cd /home/sites/sugarcraft/sugar-crush && vendor/bin/phpunit tests/Providers/   # main repo, after merge
OK (823 tests, 1984 assertions)
```
Environment: NO AWS credentials anywhere (no env vars, no `~/.aws`) — a real Bedrock call was impossible; the payload tests confirm the BUILT REQUEST SHAPE, not AWS-side API behaviour. The Converse 400 from E19 remains unconfirmed (Chat.php:8514-8525 documents the measured `system user system system` history tail).

**Suite result** Full suite not re-run this step; Providers dir in main repo after merge: 823/1984 OK (was 817/1972 after P1.S3). Baseline for comparison: 10351/160648/1 (P0.S1). Delta: +6 tests / +12 assertions in Providers dir; census unchanged (297 files — no new `src/` file).

**Review loop** `RECONSTRUCTED` — the step agent exited without its 7-section report; the loop below was orchestrated directly by the orchestrator.
- Cycle 1 — reviewer exact-purple-ox (orchestrator delegate, single-sequential): APPROVE with 1 MINOR finding (95% confidence). All 19 checks PASS/N/A; reachability `Bootstrap:1962 → ProviderFactory:584 'bedrock' → :882 → Runtime:589/460`; deletion polarity corroborated (4 red, first assert each; absent-key test correctly green on revert); value-not-shape throughout. FINDING: the 'no system key' polarity was pinned only on `complete()` (`:723-740`); the identical duplicated guard on the stream path (`BedrockProvider.php:216-218`) was UNPINNED — deleting it would emit `'system' => []` on the wire with no red test.
- Cycle 1 fix — agent pty_3ca4faa9 (completed fully, first agent to do so): test-only fix, commit `54aece70a` 'prompt/P1.S4: pin stream-path absent-system guard with twin test' (+19, only `BedrockProviderTest.php`). Guard NOT moved into `systemBlocks()` (minimal edit, src/ untouched).
- Cycle 2 — reviewer scary-azure-raccoon (new, single-sequential): APPROVE — 19/19 PASS/N/A, 0 findings. Polarity proven (`assertArrayNotHasKey` at :754 fails iff the payload carries the key; guard defeat ⇒ `'system' => []` ⇒ red, same MockHandler pipeline as :691-694); purely additive (+19/-0); scope clean. Reviewer noted: wholesale deletion of all 3 guard lines (no assignment) would pass the test but is CORRECT wire behaviour — the unconditional-assignment mutation is the faithful operationalization.
- Total cycles: 2.

**Invariants touched** (none — no new `src/` file, census unchanged; no new env var / settings key / command.)

**Surprises / things the plan got wrong**
- No real Bedrock call was made (no AWS credentials on this host) — stated explicitly per step text requirement; the payload tests are request-shape proof only.
- The P1.S4 fix agent was the FIRST step agent to complete with a full 7-section report — it followed the CRITICAL WARNING (synchronous coder, blocked on it, no dangling delegate).
- `.opencode/package.json` runtime artifact (same as S1/S2/S3) reverted before merge.
- `DsmlToolCallParser` fixture stderr line during `tests/Providers/` runs is pre-existing expected output, not a failure.

**Follow-ups created** (none)

---

### P1.S3 — OpenAIProvider::completeStream() transmits assembled systemPrompt · 2026-08-26 06:55 · 99caad991

**Status** done

**Worktree** /home/sites/prompt-step-P1.S3 (removed after merge)

**Base** `19a46ac9f`

**Goal (restated in one sentence)** The interactive-turn path `OpenAIProvider::completeStream()` leads the wire payload with the assembled system prompt when one is supplied, and sends nothing extra when it is null.

**What changed**
- `sugar-crush/src/Providers/OpenAIProvider.php`: `completeStream()` now prepends `[['role' => 'system', 'content' => $request->systemPrompt]]` after the tools block, before the client call (`:127-130`) — mirrors the `complete()` block (`:90-95`) exactly: same condition, same `array_merge` shape, same position. The guard is null-only (matching `complete()`), unlike Sglang/Custom's stricter non-empty check.
- `sugar-crush/tests/Providers/OpenAIProviderTest.php`: +2 tests (below), driving real `StreamResponse`/`CreateStreamedResponse` machinery so the exact `$params` array handed to `createStreamed()` is captured and asserted. Existing `complete()` tests untouched.

**Tests added or changed**
- `OpenAIProviderTest::testCompleteStreamPayloadLeadsWithSystemPrompt` — `assertSame` on the exact captured messages array (system prompt first, then user message) plus model + stream flag + chunk count (`:666-669`); red on revert.
- `OpenAIProviderTest::testCompleteStreamWithNullSystemPromptPrependsNothing` — exact shape when null; stays green on revert (by design).

**Deletion experiment** (run by orchestrator): `git apply -R` of the src hunk, filtered run: `FAILURES! Tests: 1, Assertions: 1, Failures: 1` — `testCompleteStreamPayloadLeadsWithSystemPrompt` red (expected `['role' => 'system']` at index 0, got user-first payload); null test stayed green (correct — it asserts absence). Restored; tree clean except runtime artifact.

**MEASURED**
```
$ cd /home/sites/prompt-step-P1.S3/sugar-crush && vendor/bin/phpunit tests/Providers/OpenAIProviderTest.php
OK (37 tests, 57 assertions)

$ cd /home/sites/prompt-step-P1.S3/sugar-crush && vendor/bin/phpunit tests/Providers/
OK (806 tests, 1957 assertions)

$ cd /home/sites/prompt-step-P1.S3/sugar-crush && vendor/bin/phpunit tests/SymbolCitationDriftTest.php tests/SwallowingCatchCensusTest.php tests/Support/DuplicatedTestHelperDriftTest.php tests/Support/ChildWallClockBudgetTest.php tests/Config/EnvRosterDriftTest.php tests/Tools/BuiltInToolCorpusTest.php
OK (103 tests, 9380 assertions)

$ cd /home/sites/sugarcraft/sugar-crush && vendor/bin/phpunit tests/Providers/   # main repo, after merge
OK (817 tests, 1972 assertions)
```

**Suite result** Full suite not re-run this step; Providers dir in main repo after merge: 817/1972 OK (was 815/1967 after P1.S2). Baseline for comparison: 10351/160648/1 (P0.S1). Delta: +2 tests / +5 assertions in Providers dir; census unchanged (297 files — no new `src/` file).

**Review loop** `RECONSTRUCTED` — the step agent exited without its 7-section report (see Surprises); the review loop below was orchestrated directly by the orchestrator.
- Cycle 1 — inner-session reviewer final-salmon-cardinal: empty artifact ("No text parts found" in the background-agents debug log).
- Cycle 2 — reviewer remaining-aquamarine-ladybug (orchestrator delegate, single-sequential): APPROVE. All 19 checks PASS/N/A. Guard mirrors `complete()`'s block (`:90-95`) exactly; reachability `Runtime.php:315 → runStreaming():323-324 (supportsStreaming true :34-37) → completeStream():460`; 0 deletions — all 35 pre-existing tests untouched; 1 nit below the ≥80% reporting bar: 130-char `array_merge` line at src:128 (PSR-12 soft limit, no enforcing fixer rule — reviewer declined a churn-only fix). Reviewer UNVERIFIED on the suite (read-only env) — used orchestrator's numbers.
- Total cycles: 2.

**Invariants touched** (none — no new `src/` file, census unchanged; no new env var / settings key / command.)

**Surprises / things the plan got wrong**
- The tree is **+91 total (+4 src, +87 tests)**, not +126 as first reported — the count in the continuation brief was a transcription slip; the tree is the authority.
- The step agent died at the same delegation point as its batch siblings (delegated final-salmon-cardinal, never got the result, session ended). Third occurrence in batch 1; rule stands: orchestrator-delegated reviews only.
- `.opencode/package.json` runtime artifact (same as P1.S1/S2) reverted before merge.

**Follow-ups created**
- Phase 1 close: spot-check P0.S2 census cells (carried).
- P4.S2: re-probe usage payload for cache fields (carried).

---

### P1.S2 — CustomProvider transmits assembled systemPrompt on both request paths · 2026-08-26 06:35 · a27f60229

**Status** done

**Worktree** /home/sites/prompt-step-P1.S2 (removed after merge)

**Base** `19a46ac9f`

**Goal (restated in one sentence)** CustomProvider's wire payload leads with the assembled system prompt on both `complete()` and `completeStream()` — including the `type:anthropic` path that rides the OpenAI chat/completions wire — when one is supplied, and nothing changes when it is null or empty.

**What changed**
- `sugar-crush/src/Providers/CustomProvider.php`: both `complete()` (`:155-160`) and `completeStream()` (`:210-215`) now prepend `[['role' => 'system', 'content' => $request->systemPrompt]]` via `array_merge` when systemPrompt is non-null **and** non-empty. Guard is stricter than OpenAIProvider's null-only check; the WHY comment explains an empty `''` on the wire would hand the backend an empty system role to reconcile against real history. The anthropic path is covered by construction — `ProviderFactory::createAnthropic()` returns a `CustomProvider` named `'anthropic'` over the OpenAI wire (`ProviderFactory.php:625-657`).
- `sugar-crush/tests/Providers/CustomProviderTest.php`: +4 tests (below).
- `sugar-crush/tests/Providers/CustomProviderStreamingTest.php`: +3 tests (below), incl. the streaming `''` polarity test (`:258-285`) added by the continuation agent — the step text requires the non-empty guard on BOTH paths, and only `complete()` had an `''` test before.

**Tests added or changed**
- `CustomProviderTest::testCompletePrependsSystemPromptToThePayload` — decoded wire `messages[0]` is `['role' => 'system']`, byte-identical content; `assertSame` exact array. Red on revert.
- `CustomProviderTest::testCompleteDoesNotPrependSystemPromptWhenNull` — exact `[['role' => 'user', 'content' => 'hi']]` shape. Green on revert (by design).
- `CustomProviderTest::testCompleteDoesNotPrependEmptySystemPrompt` — same, `''` treated as absent.
- `CustomProviderTest::testCompleteKeepsHistoricalSystemMessageInPlaceWhenPromptIsPrepended` — exact 3-element array: assembled prompt LEADS, historical `SystemMessage` stays in place, user message retained; neither dropped. Red on revert.
- `CustomProviderStreamingTest` ×3 — streaming prepend / null / `''`, `assertSame` on the full decoded wire messages array through the file's established MockHandler + SSE-body pattern (`makeProvider($client)` helper `:27`).

**Deletion experiment** (run by orchestrator): `git apply -R` of the src hunk, filtered run: `FAILURES! Tests: 3, Assertions: 3, Failures: 3` — complete-prepend, history-interaction and streaming-prepend all red; null/empty polarity tests stayed green (correct — they assert absence). Restored; tree byte-identical.

**MEASURED**
```
$ cd /home/sites/prompt-step-P1.S2/sugar-crush && vendor/bin/phpunit tests/Providers/CustomProviderTest.php tests/Providers/CustomProviderStreamingTest.php
OK (48 tests, 96 assertions)

$ cd /home/sites/prompt-step-P1.S2/sugar-crush && vendor/bin/phpunit tests/Providers/
OK (811 tests, 1959 assertions)

$ cd /home/sites/prompt-step-P1.S2/sugar-crush && vendor/bin/phpunit tests/SymbolCitationDriftTest.php tests/SwallowingCatchCensusTest.php tests/Support/DuplicatedTestHelperDriftTest.php tests/Support/ChildWallClockBudgetTest.php tests/Config/EnvRosterDriftTest.php tests/Tools/BuiltInToolCorpusTest.php
OK (103 tests, 9380 assertions)

$ cd /home/sites/sugarcraft/sugar-crush && vendor/bin/phpunit tests/Providers/   # main repo, after merge
OK (815 tests, 1967 assertions)
```

**Suite result** Full suite not re-run this step; Providers dir in main repo after merge: 815/1967 OK (was 808/1960 after P1.S1). Baseline for comparison: 10351/160648/1 (P0.S1). Delta: +7 tests / +7 assertions in Providers dir; census unchanged (297 files — no new `src/` file).

**Review loop** `RECONSTRUCTED` — the step agent (and its continuation) exited without a 7-section report (see Surprises); the review loop below was orchestrated directly by the orchestrator.
- Cycle 1 — inner-session reviewer exotic-beige-panda: empty artifact (agent died at delegation; artifact confirms "No text parts found").
- Cycle 2 — reviewer valuable-blush-tern: infrastructure failure — zero-message timeout at 900s (session created, prompt never arrived; see Surprises). Relaunched.
- Cycle 3 — reviewer neutral-fuchsia-jay (orchestrator delegate, single-sequential): APPROVE. All 19 checks PASS/N/A; done-when ledger complete (both-path prepend at `:152-161`/`:207-216`; guard both sites; exact-literal prepend; payload-shape assertion per path; interaction test = 3-element exact array). Reachability traced `bin/sugarcrush → Bootstrap → ProviderFactory:648,905-907 → complete()/completeStream()` via live hot paths (`Runtime.php:460,589` etc.). 2 non-blocking nits below the ≥80% reporting bar: history-interaction test only on `complete()` (optional symmetry); the three streaming tests triplicate the 3-line SSE fixture consistent with the file's pre-existing pattern. Reviewer UNVERIFIED on the suite (read-only env) — used orchestrator's numbers.
- Total cycles: 3.

**Invariants touched** (none — no new `src/` file, census unchanged; no new env var / settings key / command.)

**Surprises / things the plan got wrong**
- The P1.S2 continuation agent died at the SAME delegation point despite the CRITICAL WARNING (last line: "Reviewer exotic-beige-panda is in flight. Per the critical constraint, I will not end my turn — blocking until the result notification arrives." then the session ended). Blocking on an inner delegate apparently itself ends the session in this environment.
- **Three concurrent delegate launches race and receive ZERO input**: valuable-blush-tern, male-lime-ocelot and rival-rose-partridge (launched 05:50:20 together) all timed out at 900s with "getResult: No messages found" — sessions created, prompt never delivered. Single sequential delegates work (linguistic-blue-deer, neutral-fuchsia-jay both completed). Rule going forward: reviewers one at a time, wait for the notification before launching the next.
- Continuation agent's addition of the streaming `''` test was a real gap closure not in my earlier verification — the step text requires non-empty guard on both paths; only `complete()` had the polarity test initially.
- `.opencode/package.json` runtime artifact appears dirty in every step worktree (opencode writes it during runs); reverted before each merge. Not step work.

**Follow-ups created**
- Phase 1 close: spot-check P0.S2 census cells (carried).
- P4.S2: re-probe usage payload for cache fields (carried).

---

### P1.S1 — SglangProvider transmits assembled systemPrompt on both request paths · 2026-08-26 06:10 · 2d4f738f2

**Status** done

**Worktree** /home/sites/prompt-step-P1.S1 (removed after merge)

**Base** `19a46ac9f`

**Goal (restated in one sentence)** SglangProvider's wire payload leads with the assembled system prompt on both `complete()` and `completeStream()` when one is supplied, and nothing changes when it is null or empty.

**What changed**
- `sugar-crush/src/Providers/SglangProvider.php`: `buildParams()` (shared by `complete()` + `completeStream()`) prepends `[['role' => 'system', 'content' => $request->systemPrompt]]` to `$params['messages']` when systemPrompt is non-null **and** non-empty — same non-empty convention as `VertexProvider.php:495`; WHY comment cites prompt_expand.md §1.1. Placement after the separate_reasoning knob, before the optional-knob foreach.
- `sugar-crush/tests/Providers/SglangProviderRequestBuildingTest.php`: +4 tests (below). Nit-fix commit `cc808f1e9` replaced an inline mock-client harness with the file's established `provider($body)` helper (`:35-47`).

**Tests added or changed**
- `SglangProviderRequestBuildingTest::testSystemPromptIsPrependedToTheCompletePayload` — complete payload `messages[0]` is `['role' => 'system']` with byte-identical content; `assertSame` on exact messages array. Red on revert.
- `SglangProviderRequestBuildingTest::testSystemPromptIsPrependedToTheStreamingPayload` — SSE stream path, same assertions via `sentBody()`.
- `SglangProviderRequestBuildingTest::testNullSystemPromptPrependsNothing` — exact `[['role' => 'user', 'content' => 'Hi']]` shape when null. Stays green on revert (by design).
- `SglangProviderRequestBuildingTest::testEmptyStringSystemPromptPrependsNothing` — same, empty string treated as absent.

**Deletion experiment**: `git apply -R` of the src hunk (run by orchestrator): `FAILURES! Tests: 4, Assertions: 4, Failures: 2` — both prepend tests red (expected 'system', got 'user'); null/empty stayed green. Guard restored; tree clean.

**MEASURED**
```
$ cd /home/sites/prompt-step-P1.S1/sugar-crush && vendor/bin/phpunit tests/Providers/SglangProviderRequestBuildingTest.php tests/Providers/SglangProviderTest.php
OK (99 tests, 236 assertions)

$ cd /home/sites/prompt-step-P1.S1/sugar-crush && vendor/bin/phpunit tests/Providers/
OK (808 tests, 1960 assertions)

$ cd /home/sites/prompt-step-P1.S1/sugar-crush && vendor/bin/phpunit tests/SymbolCitationDriftTest.php tests/SwallowingCatchCensusTest.php tests/Support/DuplicatedTestHelperDriftTest.php tests/Support/ChildWallClockBudgetTest.php tests/Config/EnvRosterDriftTest.php tests/Tools/BuiltInToolCorpusTest.php
OK (103 tests, 9380 assertions)

$ cd /home/sites/sugarcraft/sugar-crush && vendor/bin/phpunit tests/Providers/   # main repo, after merge
OK (808 tests, 1960 assertions)
```

**Suite result** Full suite not re-run this step; Providers dir in main repo after merge: 808/1960 OK. Baseline for comparison: 10351/160648/1 (P0.S1). Delta: +4 tests / +0 assertions measured in Providers dir only; census unchanged (297 files — no new `src/` file).

**Review loop** `RECONSTRUCTED` — the step agent exited without its 7-section report (see Surprises); the review loop below was orchestrated directly by the orchestrator.
- Cycle 1 — reviewer wandering-amethyst-albatross: APPROVE, 1 nit — streaming test duplicated the mock-client harness inline (~11 lines) instead of reusing the file's `provider($body)` helper (`:35-47`); its justifying comment was false (`provider($sseBody)` would serve; `buildParams()` keys off `$request->model`). Remaining 18 checks PASS (reachability, deletion experiment, value-vs-shape, no subtraction, §1.11, conventions, roster).
- Cycle 1 fix — fix agent (pty_c89b4e3d) commit `cc808f1e9`: inline harness replaced by `$provider($sseBody)` call, assertion substance unchanged (+1/-14), tests re-run green (99/236, Providers 808/1960).
- Cycle 2 — reviewer linguistic-blue-deer (brand-new): NO FINDINGS. Checks performed: assertion survival at `:872-874` (assertSame role/content/tail), helper-model nuance (MiniMax-M2.7 vs `SglangProvider::DEFAULT_MODEL`) examined and dismissed as inert — `buildParams()` keys wire params on `$request->model` (`:647/:653/:684/:881`), `$this->model` used only by `contextWindow()` (`:432-437`) which is not on the complete/stream path; 19-check table all PASS/N/A. Reviewer UNVERIFIED on the suite (read-only env) — used orchestrator's numbers, high-confidence transfer (assertions byte-unchanged from cycle-1 measured commit).
- Total cycles: 2.

**Invariants touched** (none — no new `src/` file, census unchanged; no new env var / settings key / command.)

**Surprises / things the plan got wrong**
- The step agent exited without its final report — twice in a row across batch 1. Pattern: the inner agent delegates its own reviewer, then ends its turn while that review is in flight; the inner-session delegate artifacts complete empty ("No text parts found" in `background-agents-debug.log`). All reviews must be delegated by the orchestrator, never left to step agents; step briefs now carry a CRITICAL WARNING never to end a turn with async work pending.
- `.opencode/package.json` runtime artifact (a `@opencode-ai/plugin` version bump) appears dirty in every step worktree — opencode itself writes it during runs; reverted before each merge. Not step work.

**Follow-ups created**
- Phase 1 close: spot-check P0.S2 census cells (carried from P0 CLOSE).
- P4.S2: re-probe usage payload for cache fields (carried from P0 CLOSE).

---

### BATCH P1.B1 OPEN · 2026-08-26 04:50

**Steps** P1.S1, P1.S2, P1.S3, P1.S4, P1.S5 (five concurrent, disjoint files)

**Merge order** (declared at spawn): P1.S1 → P1.S2 → P1.S3 → P1.S4 → P1.S5, one at a time, `vendor/bin/phpunit tests/Providers/` between merges.

**Worktrees**
- `/home/sites/prompt-step-P1.S1` (branch `prompt/P1.S1`)
- `/home/sites/prompt-step-P1.S2` (branch `prompt/P1.S2`)
- `/home/sites/prompt-step-P1.S3` (branch `prompt/P1.S3`)
- `/home/sites/prompt-step-P1.S4` (branch `prompt/P1.S4`)
- `/home/sites/prompt-step-P1.S5` (branch `prompt/P1.S5`)

**Base** `19a46ac9f` (master at spawn). Vendor materialised via `cp -al` in all five; PSR-4 root verified to resolve to each worktree's own `src/`.

---

### P0 CLOSE — Phase 0 complete: baseline, census, probe · 2026-08-26 04:35 · 832f9ec0a

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

### P0.S3 — provider probe: models endpoint + system-message honouring · 2026-08-26 04:30 · 832f9ec0a

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

### P0.S2 — census: which CompleteRequest properties each provider reads · 2026-08-26 04:30 · 832f9ec0a

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

