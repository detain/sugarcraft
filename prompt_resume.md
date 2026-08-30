# prompt_resume.md — the entry point for the prompt-architecture plan

> **This file has two jobs, and which one it is doing depends on when you read it.**
>
> 1. **Before the plan starts** (its current state) it is a **start prompt**: hand this file to a
>    fresh agent with no prior conversation and the plan begins correctly.
> 2. **After the plan starts** it is **rewritten after every step** so that it becomes a **resume
>    prompt**: hand it to a fresh agent and the plan picks up from wherever it actually is and runs
>    to the end.
>
> The rewrite instructions are in §R at the bottom. They are part of the file on purpose — whoever
> rewrites it is reading it.

**Current state: Phase 3, 20 of 62 steps merged. TWO AGENTS ARE RUNNING (see §8 In-flight batch). P3.S5 is IMPLEMENTED BUT NOT MERGED — it is `blocked (review-cycle)` AND `blocked (scope-escalation)`, and merging it as-is WOULD RED CI. Its worktree /home/sites/prompt-step-P3.S5 is LEFT IN PLACE ON PURPOSE — do not delete it. Start at §8 'Next step'.**

---

## 1. Who you are and what you are doing

You are the **orchestrator** for the sugar-crush prompt-architecture plan, running in
`/home/sites/sugarcraft` on branch `master`.

You do not write production code. You spawn agents, verify their work against test output you run
yourself, merge it, commit it, and maintain two bookkeeping files. Everything that touches
`sugar-crush/src/` or `sugar-crush/tests/` goes through a spawned step agent, every time, including
changes that feel too small to be worth spawning for.

## 2. Read these first, in this order

1. **`/home/sites/sugarcraft/prompt_plan.md`** — your complete operating manual. Read it in full
   before doing anything else. §1 is the execution contract, §2 is concurrency, §3 is bookkeeping,
   §4 onward are the phases, §16 is the lessons every agent you spawn must be given, §17 is the
   invariants, §18 is what not to build. Inside §1, **§1.10 (removal is not an available outcome)**
   and **§1.11 (what counts as a test)** go to every agent you spawn, alongside §16 and §17.
2. **`/home/sites/sugarcraft/prompt_worklog.md`** — the record. It holds the conventions, the
   required entry format, one worked example, and every step entry so far. Read the format; you will
   be writing in it after every step.
3. **`/home/sites/sugarcraft/prompt_expand.md`** — the 4,063-line research dossier the plan
   executes. Do **not** read it end to end now. Each step names the sections its agent must read;
   read those sections when you brief that agent, and read §0 and §1 now so you understand the lead
   finding.
4. `CLAUDE.md`, `AGENTS.md`, `CONTRIBUTING.md` — repo conventions.

## 3. What has been built so far

Phases 0-2 are closed; Phase 3 is in progress. Five things a fresh agent needs about the **current**
shape of the code:

1. **The prompt reaches the model.** `Runtime::buildSystemPrompt()` (`sugar-crush/src/Runtime.php`)
   assembles seven layers and `Runtime::run()` puts them on `CompleteRequest::$systemPrompt`. Six of
   the seven providers transmit it on **both** `complete()` and `completeStream()`;
   `tests/Providers/SystemPromptTransmissionMatrixTest.php` pins the wire slot per protocol against
   a roster derived from `src/Providers/`. A cross-phase reviewer traced one prompt end to end and
   measured **assembled 5099 B == golden 5099 B == wire 5099 B** with `messages[0].role = 'system'`.
2. **But not for every provider.** `VertexProvider::googleBody()` still drops the entire assembled
   prompt for Google publisher models, on both paths — the plan's founding defect, live in the
   seventh provider. Being fixed as `P1.audit-fix-1`.
3. **The prompt is deterministic and golden-pinned.** Clock, platform and cwd are injectable;
   `tests/fixtures/prompt/golden-system-prompt.txt` pins the assembly byte-for-byte,
   `golden-agent-prompt.txt` pins `Agent::systemPrompt()`, and `tests/Prompt/PromptFixture.php` is
   the harness later prompt tests build on.
4. **`<env>` is LAST.** P3.S1 moved it from layer 2 to layer 7 — stable layers first, volatile last —
   so the cacheable prefix survives the first file write of a session. `Agent::systemPrompt()`
   deliberately uses the **opposite** order: two assemblers, see `prompt_plan.md` §17.2.
5. **The write-signal exists but is not wired.** P3.S2 added
   `EnvironmentBlock::withWriteSinceLastRender()`, defaulting to `true` so production output is
   unchanged. Nothing in `src/` calls it. P3.S5 is the scheduled wiring step, and its brief has been
   corrected to record that it reaches only one of the four construction sites.
6. **The reorder is now measured, not asserted.** P3.S4 pinned it: the shared prefix between two
   consecutive prompts on a dirty tree went **3,095 -> 4,670 bytes** of the same 4,844-byte prompt —
   a reorder, not an addition, moving 1,575 B in front of the first differing byte. But `<repo-map>`
   is **not** stable either: a turn that creates a `.php` file diverges at byte 3,188, ahead of
   everything P3.S1 lifted. Memoisation saves that within a turn, not across turns.

## 4. How to resume (replaces "Your first actions" once the plan has started)

1. Confirm you are in `/home/sites/sugarcraft` on `master` with a clean tree
   (`git status --porcelain` — nothing should be dirty; untracked files outside the plan's own
   bookkeeping are possible, inspect before trusting).
2. Confirm the last step entry in `prompt_worklog.md` (under `## ENTRIES`, newest first) matches the
   plan's last commit in `git log`. If it does not — a step is missing its entry, or an entry has no
   commit — **reconstruct the missing entry before doing anything else** (`prompt_plan.md` §3.3).
   Note: a step's bookkeeping commit (this file + the worklog entry) can sit on top of the step's
   own commit; both belong to the same step. P0.S1's step commit is `19533373e`; its bookkeeping
   commit carries the worklog entry and this resume.
3. Confirm the commit identity (silent failure otherwise):
   ```sh
   git -C /home/sites/sugarcraft config user.name    # must print: Joe Huss
   git -C /home/sites/sugarcraft config user.email   # must print: detain@interserver.net
   ```
4. **Audit for stale worktrees before you spawn anything.** `git -C /home/sites/sugarcraft worktree
   list`. For every `/home/sites/prompt-step-<ID>` listed, run the status/log checks of
   `prompt_plan.md` §1.12. **A worktree listed here that is not a step you spawned in this session
   is stale by definition.** `/home/sites/crush-lane-{a,b,c}` will also appear if they are
   worktrees — they belong to the other plan. Leave them completely alone.
5. Confirm you will not modify `docs/plans/crush_code_*.md` or `left_steps.md`. They are read-only to
   you, in both directions: you take knowledge out, you put nothing in.
6. Then read §8 below and do exactly what `Next step` says.

## 5. The sequencing gate — checked

**CHECKED 2026-08-26 — decision: proceed.** Phases 0–4 are safe to run alongside the other plan
(`docs/plans/crush_code_*.md`): they add no new files under `sugar-crush/src/` and touch no
lane-owned files except `Chat.php`/`ContextCompactor.php` in Phase 4. **Do not start Phase 5 or
Phase 6 while the other plan has a round in flight** — they add most of the ~11 new `src/` files and
will fight the file-count census. Re-check before Phase 5 by asking the supervisor rather than
reading the lane worktrees.

Collisions still live (re-check before the named phases):

| Collision | Detail |
|---|---|
| `sugar-crush/tests/Tools/BuiltInToolCorpusTest.php` + `sugar-crush/src/Context/RepoMapBlock.php` | **RESOLVED 2026-08-29 — this was never a real collision during this plan.** The `src/` cardinality assertions were removed by `8706d2ec4` (*"decouple BuiltInToolCorpusTest and RepoMapBlock from the src/ file-count census"*), which `git merge-base --is-ancestor 8706d2ec4 19533373e` confirms is an **ancestor of P0.S1** — so it was already gone when this plan took its baseline. Found independently by two retrospective reviewers. MEASURED: zero integer `assertSame` in that file; zero `297 files` in `RepoMapBlock.php`; adding a `src/` file (298) leaves the census set at `OK (103 tests, 9432 assertions)` — nothing reds. `BuiltInToolCorpusTest.php:285-287` now carries `CENSUS_RESOLUTION = 'this file deliberately asserts NO cardinality over src/…'`. Phases 5/6/10 need **no** thin batches on this account; plan their concurrency from §2.1 like any other phase. Still an ordinary hot *file* if two steps edit it. See `prompt_plan.md` §17.1. |
| `sugar-crush/src/Backend/EngineBackend.php` | Held by an in-flight lane; wanted by this plan's P7.S3. |
| `sugar-crush/src/Chat.php` + `sugar-crush/src/Context/ContextCompactor.php` | The other plan carries a long-standing, untouched backlog of context-window / compaction / spend-cap findings in exactly these two files. This plan's Phases 4 and 8 rewrite them. |
| `sugar-crush/src/Tools/BuiltIn/Bash.php` | The other plan has two open items rewriting its **behaviour**; this plan's P9.S3 rewrites its **description**. |
| `sugar-crush/src/Agents/AgentDefinition.php` | The other plan's `C7` — `$defaultTools` is inert — gates what this plan's P7.S5 preset prompts are allowed to claim. |
| `sugar-crush/tests/Support/` (whole directory) | Assigned wholesale to an in-flight lane. This plan's P2.S4 wants `PromptFixture.php` there; P2.S4 carries the workaround. |
| `sugar-crush/tests/` tree-wide census tests | `SymbolCitationDriftTest`, `SwallowingCatchCensusTest`, `DuplicatedTestHelperDriftTest`, `ChildWallClockBudgetTest`, `EnvRosterDriftTest` and others walk the whole tree. **Every test file this plan adds can red one of them.** Several are owned by in-flight lanes. |

## 6. The loop you run, in short

Full detail in `prompt_plan.md` §1. The short form:

- Pick the next phase's next batch. Five steps at a time, chosen so their declared file lists are
  **disjoint**. Fewer when the phase does not offer five.
- One git worktree per step, branched from current `master`:
  `git -C /home/sites/sugarcraft worktree add /home/sites/prompt-step-<STEP_ID> -b prompt/<STEP_ID> master`
- **Then give that worktree a `vendor/`, or nothing in it can run a test.** `sugar-crush/vendor/` is
  gitignored, so a fresh worktree has no autoloader and no `vendor/bin/phpunit`, and agents may not
  run `composer install`. Hard-link it in and verify it points at the **worktree**:
  ```sh
  cp -al /home/sites/sugarcraft/sugar-crush/vendor \
         /home/sites/prompt-step-<STEP_ID>/sugar-crush/vendor
  cd /home/sites/prompt-step-<STEP_ID>/sugar-crush && php -r '
    $p = require "vendor/composer/autoload_psr4.php";
    echo $p["SugarCraft\\Crush\\"][0], PHP_EOL;'
  ```
  That must print `/home/sites/prompt-step-<STEP_ID>/sugar-crush/src`. **Do not use `ln -s`** — a
  symlinked `vendor/` makes the autoloader resolve to the **main repo's** `src/`, so the agent's own
  edits never load and every test result is about the wrong code. Full detail and the measurements:
  `prompt_plan.md` §1.2 action 2.
- Spawn the step agent with the step text, its file list, the `prompt_expand.md` sections it names,
  and `prompt_plan.md` §1.10, §1.11, §16 and §17.
- The step agent implements **and updates the tests**, then spawns a review agent (brief in
  `prompt_plan.md` §1.4). Findings → fix agent → **a brand-new** review agent → loop. Break only on a
  clean review. Cap five cycles, then the step is blocked.
- **If any agent comes back empty, aborted, or truncated: it died — it did not "have nothing to
  say".** A blank reviewer is not `NO FINDINGS`; a blank step agent has not finished the step. Work
  the ladder in `prompt_plan.md` §1.8: resume the same agent if you can, otherwise read its worktree
  (§1.8.4) to find out how far it got, then relaunch a new agent **in that same worktree** with a
  continuation brief telling it what is already there and not to start over. Blank returns get five
  attempts, not three. Never write the missing report yourself.
- You run the tests yourself and record **your** numbers.
- Merge each step back into `master` in the main repo dir, one at a time, with a test run between
  merges. Commit directly to `master` with the detailed message format in §1.6. **Do not push.**
- Remove the worktree.
- **Append the worklog entry and rewrite this file.** The step is not complete until you have.
- Between batches, spawn a sync agent to bring every live worktree up to current `master`.
- At the end of each phase, spawn a phase review agent over all that phase's commits together.
  Findings → fix → **a new** phase reviewer → loop. Cap three cycles.

## 6a. Harness note — these documents were written for OpenCode, not Claude Code

**Confirmed by the user, 2026-08-29.** `prompt_plan.md`, this file, and `prompt_worklog.md` were
authored assuming OpenCode as the harness. Follow their **substance** in full — the step loop, the
review→fix→**new**-reviewer cycles, disjoint declared file lists, one worktree per step, per-step
bookkeeping, measure-don't-assert, and never removing dormant code. Do **not** follow their
agent-handling **mechanics** literally when the harness is Claude Code.

Specifically, these do not apply here and should not be attempted:

- PTY handling of any kind, and the `pkill -f 'phpunit.*prompt-step-<ID>'` watchdog in
  `prompt_plan.md` §19.
- Judging an agent's liveness by transcript mtime or by hunting a pid, and killing it by pid
  (`prompt_plan.md` §1.8.6). Use the harness's own completion notification.
- Rung 1 of the recovery ladder (`prompt_plan.md` §1.8.3) as an OpenCode capability. Under Claude
  Code, continue a spawned agent with `SendMessage` addressed to that agent; if that is not
  possible, drop straight to rung 2 (read the worktree) and rung 3 (a new agent in the same
  worktree with a continuation brief).
- OpenCode's `task` vs `delegate` spawn routing. Use the normal Agent tool.

Everything §1.8 says about *what must be true* still holds without change: a blank, truncated, or
aborted response means the agent **died** and is never a result; a reviewer that returns nothing has
**not** returned `NO FINDINGS`; the orchestrator runs its own tests and records its own numbers;
never write a dead agent's missing report yourself.

## 7. Non-negotiables

- Never modify `docs/plans/crush_code_*.md` or `left_steps.md`.
- Never touch `/home/sites/crush-lane-{a,b,c}`.
- Never `git push`, unless a push is genuinely required to complete a merge. If you think it is,
  stop and ask.
- Never run `caliber`. Never suppress a git hook (`--no-verify`, `core.hooksPath=/dev/null`).
- Never run a global `pkill`.
- Never run `composer install`/`composer update` without deciding to for a named reason — it
  de-symlinks `vendor/sugarcraft/*` and silently voids every measurement taken after it.
- Never weaken, skip, rename-out, or delete an existing test to make a change pass.
- **Never remove unfinished, dormant, unwired, or unreachable code — yours or anyone's.** Removal is
  not an outcome available to you or to any agent you spawn. The three permitted outcomes are: wire
  it, build it out, or **stop and ask the user**. This covers the quiet forms too — stubbing the
  body, dropping the last call site, deleting the enum case / parameter / config key that kept it
  alive, `@deprecated`-ing it aside, or deleting the test that pinned its dormancy. If an agent's
  diff removes one of these, reject it and re-spawn. Full rule: `prompt_plan.md` §1.10.
  **Escalating is a completed step, not a failed one** — record it in the worklog and in §8 below
  under `Awaiting user decision:`, verbatim, with `file:line`, what calls it (or that nothing does),
  and the options. Then wait for the user; do not decide it yourself.
- **Never accept an annotation or an existence check as a test.** `@covers`, `@test`, a descriptive
  method name, `method_exists()`, `class_exists()`, `is_callable()` and shape assertions
  (`assertNotNull`, `assertIsArray`, `assertTrue(count(...) > 0)`) all pass on wrong or absent
  behaviour. A real test calls the thing and asserts the value, asserts exact counts, covers both
  polarities and the pathological input — and goes **red when the change is reverted**. Require the
  step agent to state the deletion experiment it ran and what it showed. Full rule:
  `prompt_plan.md` §1.11 and §16.2.
- Never accept an agent's claim of completion without test output you ran yourself.
- **Never read an empty or aborted agent response as a result.** It means the agent died. Recover it
  (`prompt_plan.md` §1.8) — do not accept it, do not fill in what it would have said, and do not
  merge its worktree because the tests happen to be green.
- Never commit before confirming `user.name` / `user.email` are `Joe Huss` /
  `detain@interserver.net` (§4). A wrong author is silent and cannot be fixed afterwards
  without rewriting history.
- Never `ln -s` a worktree's `vendor/`. Use `cp -al` and verify the PSR-4 root (§6). A symlinked
  `vendor/` silently runs every test against the main repo's `src/`.
- Never delete a worktree without first checking it for uncommitted changes and unmerged commits
  (`prompt_plan.md` §1.12).
- Never write a worklog number you did not measure.
- **ORCHESTRATION-RULE-2 — no agent may create a scratch git repository anywhere but its own
  scratchpad, and must verify `pwd` before any `git init` / `git commit`.** ADDED 2026-08-30 after a
  P3.S5 reviewer ran a throwaway-repo setup inside `/home/sites/sugarcraft` itself: it OVERWROTE the
  repo's identity config to `t <a@b.c>` and left a stray commit on **master**. Repaired (identity
  restored, master reset, junk file gone, every plan commit verified still authored `Joe Huss`), and
  nothing was ever pushed — but §7 already warns that a wrong author is *"silent and cannot be fixed
  afterwards without rewriting history"*, and this was caught ONLY because the step agent
  self-reported it. **Put this prohibition in every step brief, and re-check
  `git config user.name` / `user.email` after every step, not only before committing.**
- Use `/usr/bin/grep` for anything that must see the whole tree — the shell's `grep` is `ugrep` and
  its recursive scans honour `.gitignore`.

## 8. Where you are right now

```
Phase:            3, and it is CLOSE but NOT closable. 4 of 5 steps merged: P3.S1 379ecc7d6,
                   P3.S2 dabcd27f7, P3.S3 74cabae7f, P3.S4 f2af06eaa, plus retro-track fixes
                   P3.audit-fix-1 6aff0bad1 and P1.audit-fix-1 03d8fed37.
                   P3.S5 is IMPLEMENTED AND GREEN ON ITS OWN, but NOT MERGED. See below.

Next step:        **P3.S5-escalation-1 — unblock the P3.S5 merge.** This is the whole job; nothing
                   else in Phase 3 can proceed past it.

                   WHAT IS WRONG. P3.S5 wires the write-signal so a no-write step suppresses the git
                   diff. That deliberately inverts an invariant pinned by
                   sugar-crush/tests/Integration/SystemPromptWiringTest.php:232
                   (`testEveryStepOfOneTurnGetsTheIdenticalSystemPrompt`), which is OUTSIDE P3.S5's
                   declared file list. The agent correctly escalated instead of reaching for it.
                   VERIFIED BY THE ORCHESTRATOR: that test is GREEN run from sugar-crush/ and RED run
                   from any git-repo cwd. .github/workflows/ci.yml:397 is
                   `php ${{ matrix.lib }}/vendor/bin/phpunit -c ${{ matrix.lib }}/phpunit.xml` with
                   NO `cd` and NO `working-directory:`, so CI's cwd IS the red one.
                   MERGING P3.S5 AS-IS WOULD RED CI. That is why it is unmerged.

                   WHAT TO DO. Spawn ONE agent, in the EXISTING worktree
                   /home/sites/prompt-step-P3.S5 (branch prompt/P3.S5 @ d046550d3, vendor/ already
                   hardlinked and working). Widen its declared list by exactly one file —
                   tests/Integration/SystemPromptWiringTest.php — and have it apply the measured
                   inversion preserved at .sugar-crush-prompt/P3.S5-ESCALATION-patch.md (gitignored;
                   COPY it into the worktree). The test must be INVERTED, NOT DELETED (§1.10): it
                   still pins the frozen triple, and the only licensed difference is the two diff
                   sections coming off the tail. The patch was MEASURED by the step agent: applied it
                   gives `OK (11 tests, 70 assertions)` from BOTH cwds, and with the P3.S5 wiring
                   removed it fails with "the second step must be the first with exactly the two diff
                   sections cut" — so it is not vacuous. Then a FRESH review budget, then merge.
                   Do NOT apply it yourself — the orchestrator does not write test code.

                   THEN, BEFORE PHASE 3 CAN CLOSE, in this order:
                     1. P3.S5-fix-1 — cycle-6 findings 1-4, all inside P3.S5's ALREADY-declared
                        Runtime.php (finding 5 is RuntimeTest.php, also declared). Finding 2 first:
                        it is WRONG-GREEN. Verbatim text is in the P3.S5 worklog entry and the full
                        review is at .sugar-crush-prompt/P3.S5-cycle6-review.txt.
                     2. P3.S4-fix-1 — the eight standing findings from P3.S4's sixth review. See
                        Open follow-ups item 1.
                     3. THE SECOND ASSEMBLER DISPOSITION, still owed and now recorded in code at
                        Runtime.php:540-561: EITHER schedule a P3.S6 for the Agent assembler OR add a
                        §18 row saying why the Agent path deliberately keeps the diff.
                     4. THEN the Phase 3 close review (§1.7), cap three cycles.

Steps done:       20 of 62 MERGED, + 1 retro-fix (RETRO-FIX-1) + 2 orchestration rules
                   (ORCHESTRATION-RULE-1, ORCHESTRATION-RULE-2). Phase 3 at 4 of 5 merged, with the
                   fifth implemented and blocked.
Phases done:      3 of 12
Last commit:      run `git -C /home/sites/sugarcraft log --oneline -1`.
Baseline:         Tests: 10351, Assertions: 160648, Skipped: 1  (P0.S1, never edited)
Latest suite:     **THE FIGURE BELOW IS DISPUTED — READ In-flight batch (C) FIRST.**
                   Previously recorded here as MASTER, stdin from /dev/null:
                   Tests: 10421, Assertions: 161281, Skipped: 1, Failures: 0
                   RE-MEASURED 2026-08-30 on master @1d07627b9 from the REPO ROOT (the CI form):
                   Tests: 10421, Assertions: 161280, Errors: 1, Failures: 1, Skipped: 1.
                   Same test count, one fewer assertion, and NOT green. The recorded "Failures: 0"
                   was therefore almost certainly measured from sugar-crush/, not from the repo root.
                   THE CWD IS PART OF THE MEASUREMENT AND WAS NEVER RECORDED WITH IT. Record it from
                   now on: every suite figure in this file must name the cwd it was taken from.
                   census 6-file set: 103 tests / 9448 assertions
                   tests/Providers/PromptStabilityTest.php: 13 tests / 229 assertions
                   golden-system-prompt.txt md5: 7efcc4882f0597440518fc02799a923a
                   All three path-repo gates exit 0 — RUN THEM FROM THE REPO ROOT, not from
                   sugar-crush/; from the wrong cwd php cannot find tools/check-path-repos.php and
                   all three "fail" with exit 1. That misread has happened twice.
                   Two tests fail ONLY under a pty with a live terminal
                   (Chat\CompactModelSummaryTest, MouseModalGuardTest). ALWAYS redirect stdin
                   from /dev/null.

                   THE P3.S5 BRANCH, for comparison: Tests: 10446, Assertions: 161473, Skipped: 1,
                   0 failures — but ONLY when run from sugar-crush/. From the repo root it is
                   1 failure. Both measured by the orchestrator.

                   ASSERTION-TOTAL INSTABILITY — MECHANISM IDENTIFIED. A COMMENT-ONLY commit can move
                   the suite total: `Config/GlobFigureDriftTest` is a PER-PARAGRAPH stale-figure
                   census. MEASURED 2026-08-29: it reads sugar-crush/docs/SETTINGS.md and the
                   LayeredSettings doc-block, NOT repo-root prompt_*.md — so plan/resume bookkeeping
                   commits are census-neutral. Compare per-file counts, or diff `--log-junit`
                   per-testcase `assertions=` attributes, before calling an assertion delta real.

In-flight batch:  TWO AGENTS RUNNING, spawned 2026-08-30. Neither has reported yet.

                   (A) P3.S5-escalation-1 — the step agent, in the EXISTING worktree
                       /home/sites/prompt-step-P3.S5 (branch prompt/P3.S5 @ d046550d3). Declared list
                       WIDENED BY THE ORCHESTRATOR to exactly one file:
                       sugar-crush/tests/Integration/SystemPromptWiringTest.php. Job: apply the
                       measured inversion from .sugar-crush-prompt/P3.S5-ESCALATION-patch.md (copied
                       into the worktree), INVERT NOT DELETE (§1.10), run the deletion experiment,
                       verify from BOTH cwds, commit to prompt/P3.S5, then run its own review cycle
                       capped at 3. It was SENT A CORRECTION mid-flight — see (C) below.
                       MERGE ORDER: it is the only step; merge it alone after its review closes.

                   (B) A READ-ONLY INVESTIGATION agent in /home/sites/sugarcraft, answering: is CI
                       actually red on master today for sugar-crush, or is the "CI form" not what CI
                       effectively runs? It edits NOTHING. See the finding below.

                   (C) **A PREMISE IN THIS FILE IS FALSE AND HAS BEEN CORRECTED TO AGENT (A).**
                       This file said master was green in the CI form. IT IS NOT.
                       MEASURED by the orchestrator on master @1d07627b9, clean tree, stdin from
                       /dev/null, from the REPO ROOT:
                         Tests: 10421, Assertions: 161280, Errors: 1, Failures: 1, Skipped: 1.
                       sugar-crush/tests/Agents/AgentTest.php::testSystemPromptMatchesCommittedGolden
                       FAILS at AgentTest.php:355 in that form, IN ISOLATION, ON MASTER, with no
                       P3.S5 involved. Its own assertion message says the fixture repo resolves from
                       a RELATIVE path (vendor/prompt-fixture/agent-repo) and that you must "run
                       phpunit from sugar-crush/". So MASTER IS ALREADY CWD-SENSITIVE.
                       ci.yml has NO working-directory: and NO defaults: anywhere — verified — so
                       either CI is red on master today, or the CI form is not what CI runs.
                       AGENT (B) IS RESOLVING THIS. Until it reports, do NOT claim in any commit
                       message, comment or worklog entry that P3.S5 "unblocks CI" or that master is
                       green in the CI form. Both are unproven and the second is measured false.
                       The P3.S5 inversion is still correct and still wanted on its own merits —
                       P3.S5 genuinely inverts that assertion's premise and §1.10 requires the
                       assertion be inverted rather than deleted.

Live worktrees:   /home/sites/sugarcraft         master, clean, at the commit above
                  /home/sites/prompt-step-P3.S5  prompt/P3.S5 @ d046550d3, 6 commits, NO AGENT
                                                  RUNNING. **LEFT IN PLACE DELIBERATELY — DO NOT
                                                  DELETE.** Tree clean, vendor/ hardlinked and
                                                  verified (PSR-4 root resolves INTO the worktree).
                                                  This is where the escalation work happens.
                   Every other step worktree and review sandbox is removed; every other prompt/* and
                   review/* branch is deleted.
                   /home/sites/crush-lane-{a,b,c} are NOT worktrees of this repo and belong to the
                   other plan — leave them alone.

Blocked on:       **P3.S5 — two blocks at once.**
                   (1) `blocked (scope-escalation)` — the CI red above. This is the one that must be
                       cleared first; it is the merge blocker.
                   (2) `blocked (review-cycle)` — six review cycles ran; the sixth returned six
                       findings and the cap stopped the agent. Recorded verbatim in the worklog.
                   ALSO STANDING: P3.S4 is recorded `blocked (review-cycle)` and was MERGED anyway
                   (grounds in its worklog DISPOSITION). Its eight findings are P3.S4-fix-1 and they
                   BLOCK THE PHASE 3 CLOSE.

Awaiting user decision: TWO, both from P1.audit-fix-1 (VertexProvider), unchanged. The step MERGED
                   (03d8fed37) with both honoured and untouched. They block nothing.

                   (1) THE GOOGLE ARM TARGETS AN ENVELOPE ITS OWN PINNED TEST MODEL IS NOT SERVED BY.
                   `VertexProvider::googleBody()` builds the PaLM 2 `chat-bison` `:predict` shape —
                   `{"instances":[{"context":…,"examples":[…],"messages":[…]}],"parameters":{…}}` —
                   and `instances[0].context` genuinely IS the standing-instruction field OF THAT
                   ENVELOPE. But `gemini-1.5-pro-002`, the model id BOTH test files pin as "the
                   Google model", is not served by that envelope at all. Gemini on Vertex takes
                   `:generateContent` / `:streamGenerateContent` with a top-level `systemInstruction`
                   object. So the transmission fix is correct for the envelope the code builds, and
                   the envelope the code builds is wrong for the model the tests name.
                   The docblock the agent cited for `context` (cloud.google.com "Design chat
                   prompts") 301s to a navigation index — the PaLM-era page is RETIRED. The field
                   name was corroborated instead against an independent raw-REST Go implementation
                   of the same endpoint (uber/go-vertex-ai types.go: `json:"context"`,
                   `json:"examples,omitempty"`, `json:"messages"`).
                   DECISION NEEDED: switch this arm to `:generateContent` + `systemInstruction`?
                   That is a different endpoint, method AND body — a redesign, not a fix — so §1.10
                   sends it to you rather than to an agent. Doing nothing leaves a provider arm that
                   transmits correctly into an envelope its pinned model does not accept.

                   (2) `author` vs `role`, SECOND PRE-EXISTING DEFECT IN THE SAME ENVELOPE. The same
                   corroborating source says the message key in the `instances` envelope is
                   `author`; `VertexProvider::formatMessages()` emits `role`. Deliberately NOT
                   fixed: fixing it would require changing the existing green test
                   `testCompleteSelectsPredictAndTheInstancesEnvelopeForGoogleModels`, outside that
                   step's declared list. Recorded in the docblock as an UNFIXED, sourced observation.
                   If decision (1) goes to `:generateContent`, this becomes moot — a reason to settle
                   (1) first.

Retro-review track: REPORTED and closed out — all five agents complete, findings in prompt_worklog.md
                   under RETRO-RR1..RR5, remaining dispositions queued below. Shared brief
                   .sugar-crush-prompt/retro-review-brief.md (gitignored; COPY it into any worktree
                   that needs it). Headline results, all four now fixed:
                     * THE PLAN'S OWN §17.1 WAS FALSE — the src/ file-count census it called "the
                       single largest constraint on this plan's parallelism" was removed by
                       8706d2ec4, an ANCESTOR of P0.S1. FIXED (486d1f4b4). Phases 5/6/10 are NOT
                       serialised by it; re-plan their batches from §2.1.
                     * THE PHASE 2 CLOSE DELETED THE REPO-ROOT .gitattributes GUARD. FIXED
                       (RETRO-FIX-1).
                     * VertexProvider dropped the whole system prompt for Google models on BOTH
                       paths. FIXED (P1.audit-fix-1), with the two open decisions above.
                     * NOTHING BUT THE REGENERABLE GOLDEN PINNED `<env>` LAST. FIXED
                       (P3.audit-fix-1).

Open follow-ups:  QUEUED WORK. Items 1-6 are code and need step agents; 7-11 are documents.
                   Items marked BLOCKS THE PHASE 3 CLOSE must land before §1.7.

                   1. **P3.S4-fix-1 — BLOCKS THE PHASE 3 CLOSE.** The eight standing findings from
                      P3.S4's sixth review, verbatim in its worklog entry. All in
                      tests/Providers/PromptStabilityTest.php. Highest value first: **F5** (78% of
                      the equality-pinned STABLE_LAYERS_BYTES region is prose this file does not own
                      — a four-byte edit in RepoMapBlock reds it with a message naming two causes
                      neither of which happened, and Phase 5 steps are licensed to edit exactly that
                      prose), then **F2** and **F6** (wrong-green fixture holes: a hostile
                      status.showUntrackedFiles, and log.date=true making git exit 128 while the
                      suite stays green), then **F1** (a twelfth knob, GIT_DIFF_OPTS, beats the
                      pinned diff.context), then the comment-accuracy set F3/F4/F7/F8.
                   2. **P3.S5-fix-1 — BLOCKS THE PHASE 3 CLOSE.** Cycle-6 findings 1-4 in
                      Runtime.php and 5 in RuntimeTest.php, both already declared by P3.S5.
                      **Finding 2 is the one with teeth and is WRONG-GREEN:** a genuinely
                      write-capable tool typed into the `$readOnly` array instead of
                      WRITE_CAPABLE_TOOL_NAMES leaves the suite fully green (112/398) while the
                      engine permanently suppresses the diff after every write by that tool. The
                      drift test forces *a* decision, not a *correct* one.
                   3. **THE SECOND ASSEMBLER DISPOSITION — BLOCKS THE PHASE 3 CLOSE.** Owed since
                      P3.S5 stated the gap in code at Runtime.php:540-561. EITHER a P3.S6 wiring the
                      Agent assembler OR a §18 row. Agents\Agent::systemPrompt() builds its own
                      block, is never marked, and pays five git subprocesses per render at all EIGHT
                      of its call sites (the source says "nine" — that is cycle-6 finding 1).
                   4. **`$perStepRerender` needs its own step.** P3.S3's Surprise 1. MEASURED by
                      P3.S5: there is NO Runtime-only half — it needs EnvironmentBlock.php AND
                      Agents/Agent.php, and it moves golden-system-prompt.txt. My earlier claim in
                      this file that "P3.S5 is the step that CAN do it" was WRONG; that is recorded
                      as P3.S5 Surprise 2. Schedule it with the goldens in scope.
                   5. P1.audit-fix-2 — unblocked. RR2 F1 (BLOCKING — P1.S5's ClaudeCode streamed-Usage
                      contract test does not bite: its fixture has NO `usage` key, so the expected 0
                      is also what a dead instrument returns); RR2 F3 (both derived provider rosters
                      use a non-recursive glob and a hardcoded namespace, so an implementer under
                      src/Providers/Extra/ leaves BOTH green — and that directory already has two
                      subdirectories); RR2 F4 (the matrix walks CLASSES not factory TYPES: 7 types
                      collapse to 6 classes, and `anthropic` reuses CustomProvider's OpenAI wire
                      shape against api.anthropic.com, untested); RR2 F7 (six stale citations).
                      Files: tests/Providers/ProviderRequestResponseTest.php,
                             tests/Providers/SystemPromptTransmissionMatrixTest.php.
                   6. P2.audit-fix-1 — unblocked (P3.audit-fix-1 and P3.S3 both merged). RR3 F2
                      (BLOCKING — BOTH golden leak scans pass on an EMPTY golden AND miss any
                      absolute path not at column 0: /opt/, /srv/, /root/, /builds/, /workspace/ are
                      all invisible); RR3 F5 (the golden pins a DOUBLED separator before every skill
                      body and this ships to the model — Skill.php:109 already returns "\n\n" and
                      Runtime.php:1807 prepends another); RR3 F8 (both goldens carry generator-host
                      bytes nothing pins); RR3 F9 (the pinHostLines drop — AgentTest.php:566-568
                      still calls it pending).
                      Files: tests/BaseSystemPromptTest.php, tests/Agents/AgentTest.php,
                             src/Runtime.php, both golden fixtures.
                   7. P3.audit-fix-2 — RR4 F2 (testNoAdditionalWorkingDirectoriesLineIsEmitted passes
                      on a COMPLETELY DEAD render() — measured, `return "";` leaves it OK); RR4
                      F5 + F7 (EnvironmentBlock's inline comment states the subprocess count
                      unconditionally after P3.S2 made it conditional; two docblocks name
                      PromptStabilityTest as the pin for live-polling when that test stays GREEN with
                      the diff hardwired off).
                      **ADD P3.S4's escalations 2-3 and P3.S5's escalations 4-5, all same file:**
                      `color.ui=always` injects 19-21 raw ANSI escape bytes into the model's system
                      prompt (EnvironmentBlock shells out to git without `--no-color`);
                      EnvironmentBlock.php:853-855 reads the branch with `shell_exec(… 2>/dev/null)`
                      + `trim()`, so a non-zero exit renders an empty `Current branch:` with no
                      marker while `gitField()` two methods down renders `unavailable (git exited N)`;
                      stale claims at :95, :112, :616 — **:112 still reads "that caller does not exist
                      yet", which P3.S5 made false**; and Agents\Agent::systemPrompt()'s docblock is
                      false where it says `capture()` shells out to git (measured: capture() runs 0
                      subprocesses; the five happen at render()).
                   8. RR1 F2 — src/Chat.php:8514-8523 + :12145-12149 describe the Bedrock E19 defect
                      in the present tense as the live justification for a message position, when
                      P1.S4 fixed it. DEFERRED: Chat.php is cross-plan contended (§2.6) and Phase 4
                      rewrites it. RR1 F6 — OpenAIProviderTest.php:626-628 `assertTrue(true)` under a
                      comment claiming the call cannot be inspected, which P1.S3's own test disproves
                      5 lines later.
                   9. WORKLOG RECONSTRUCTION (RR3 F7 + RR5 F6) — P2.S2, P2.S4, BATCH P2.B2 CLOSE and
                      the Phase 2 close are `##` narrative, not `###` entries. §3.3 was not followed
                      and THE PLAN IS AT FOUR MISSING ENTRIES. Because `## ENTRIES` is itself `##`,
                      those four headings are its SIBLINGS — so twenty headings, including P1 CLOSE,
                      P0 CLOSE and every Phase-0/1 step entry, now nest under `## P2.S2`. Genuinely
                      lost: P2.S4's deletion experiments (recorded only as "A/B/C RED→GREEN" — its
                      guards are UNPROVEN until re-run), all four Surprises sections, and the phase
                      close's Cross-step problems found — whose absence is the direct reason the
                      .gitattributes deletion went unrecorded.
                  10. PLAN EDITS — §17.2's citations have rotted (~20 sites; RR5 F9 has the
                      per-constraint status table). The "constraint that rules out unification" cites
                      BaseSystemPromptTest.php:135 as the contradicting half, but that file now makes
                      NO env-relative claim at all. Unification is still ruled out, by four OTHER
                      sites. Constraint 1's "18 reflection sites" is really 23 across 4 files.
                  11. DOC EDITS + progress.json — sugar-crush/docs/ARCHITECTURE.md:229-266 documents
                      the pre-P3.S1 order and asserts the INVERTED cache claim as fact (P11.S2
                      schedules it, nine phases away); README.md:1053 says the environment block is
                      "prepended"; AgentTest.php:316 says "layer 2 of 7";
                      tests/Integration/SystemPromptWiringTest.php:167-173.
                      .sugar-crush-prompt/progress.json is dormant machinery (58 of 61 steps still
                      `not_started`, enumerates 61 when the plan has 62). §1.10: wire it or build it
                      out, NEVER delete.

Sequencing gate:  CHECKED 2026-08-29. Phase 3 fully serial S1→S2→S3→S4→S5.
                   src/ FILE-COUNT CENSUS — RESOLVED, it never applied to this plan (see §5).
                   Phases 5/6/10 are NOT serialised by it. Still live before Phase 5/6:
                   EngineBackend.php held by a lane, wanted by P7.S3 — NOTE P3.S5 now edits it too;
                   Chat.php + ContextCompactor.php for P4/P8; Bash.php behaviour vs P9.S3
                   description; AgentDefinition C7 for P7.S5; tests/Support/ wholesale; tree-wide
                   census tests walk the whole tree.
```

---

## §R. How to rewrite this file (read this before every rewrite)

**Rewrite this file after every single step, and after every phase close.** Not append — **replace**.
This file always describes the *current* state and the *next* action. History belongs in
`prompt_worklog.md`; if you find yourself adding a "previously…" section here, that content belongs
there instead.

### What must survive every rewrite, unchanged in substance

Sections **1, 2, 6, 6a, 7** and this section **§R**. They are the operating instructions and they do not
change as the plan progresses. Copy them forward verbatim. If you find an error in them, fix it and
say so in the worklog entry for the step that fixed it.

### What you replace every time

- **The banner** at the top: change `Current state: NOT STARTED` to a one-line statement of where the
  plan is (e.g. `Current state: Phase 4, step P4.S3 in flight; phases 0–3 closed`).
- **§3** — once Phase 1 has landed, replace the lead-finding explanation with a short "what has been
  built so far" summary: the three or four things a fresh agent needs to know about the *current*
  shape of the code, not the original defect. Keep it under fifteen lines. When it grows past that,
  you are writing history; move it to the worklog.
- **§4 (Your first actions)** — becomes "how to resume": confirm the tree is clean, confirm the last
  step in `prompt_worklog.md` matches the last commit in `git log`, and if it does not, **reconstruct
  the missing entry before doing anything else** (`prompt_plan.md` §3.3).
- **§5 (the sequencing gate)** — once the gate has been checked and cleared, replace it with a
  one-line record of the decision and the date, plus any collision that is still live. If a
  collision is still live, keep the row that describes it.
- **§8 (Where you are right now)** — always rewritten. Every field, every time.

### The §8 block's required fields

```
Phase:            <current phase id and title, or "between phases">
Next step:        <STEP_ID> — <one-line goal>
Steps done:       <N> of 62
Phases done:      <N> of 12
Last commit:      <sha> — <subject line>
Baseline:         Tests: <N>, Assertions: <N>, Skipped: <N>  (from P0.S1, never edited)
Latest suite:     Tests: <N>, Assertions: <N>, Skipped: <N>  (from your last verification run)
Retro-review track: <the retrospective review agents currently out, their scopes, and where their
                  findings are queued — or "none". This track runs ALONGSIDE the plan and never
                  gates it; findings become fix steps scheduled between plan steps.>
In-flight batch:  <the batch id, its steps, their worktree paths, and the declared MERGE ORDER —
                  or "none". Write it the moment you spawn a batch, not when you start merging.>
Live worktrees:   <paths, or "none". Each one: the step it belongs to, and whether that step is
                  in flight, parked (§1.2 action 6), or stale (§1.12).>
Blocked on:       <nothing | STEP_ID and the standing findings, verbatim>
Awaiting user decision: <nothing | STEP_ID, the file:line, and the question, verbatim — the
                  dormant-code escalations from prompt_plan.md §1.10 that no agent may resolve>
Open follow-ups:  <the follow-ups recorded in worklog entries that are not yet scheduled>
Sequencing gate:  <CHECKED YYYY-MM-DD — decision | UNCHECKED>
```

`Baseline` is written once, at P0.S1, and never edited afterwards. It is the number every later delta
is measured against, and a moving baseline makes every delta meaningless.

### Rules for the rewrite

- **`In-flight batch` is the field that survives a session loss.** Every other field describes work
  that is already *finished* and therefore already recoverable from `git log` and the worklog. Five
  agents running in five worktrees with a merge order you decided in your head is recoverable from
  nothing. Write it at spawn time. Clear it at batch close, not before.
- **A fresh agent handed only this file must be able to continue.** After writing it, reread it as if
  you had never seen this repository. If a sentence assumes something you learned in conversation,
  rewrite the sentence.
- **Never write a "Next step" you have not confirmed against `prompt_plan.md`.** Look it up; do not
  recall it.
- **Never carry a stale number forward.** If you did not run the suite this step, say
  `Latest suite: not re-run this step (last measured at <STEP_ID>)` rather than repeating an old
  figure as if it were current.
- **If a step is blocked, the blocking findings go in §8 verbatim**, not summarised. The next agent
  needs the actual text to act on it.
- **A dormant-code escalation is never resolved by a rewrite.** It leaves `Awaiting user decision:`
  only when the user has answered, and the answer goes into the worklog entry for the step that acts
  on it. Carrying it forward unanswered, every rewrite, is correct.
- **If you are about to be cut off** (context limit, session limit), rewriting this file is the last
  thing you do and the highest-value thing you can do. Do it before anything else you were planning
  to finish.