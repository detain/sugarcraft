> **THIS IS A TEMPLATE, AND IT IS THE ONE THAT WORKED.** Preserved verbatim as the §1.8 rung-3
> continuation brief that recovered `P3.audit-fix-2` after its fix agent died without reporting —
> attempt 1 of 5, one agent, and the branch merged as `980670c0b`. Its specifics (shas, findings
> A1-A7, the six declared files) are of that step; its SHAPE is reusable and the shape is the point:
>
> 1. **It opens by telling the agent not to start over**, because a continuation agent's default
>    instinct is to redo work it cannot see, and eleven commits were already on the branch.
> 2. **It states what is already there as fact** — the commits, the scope, the clean worktree —
>    so the agent spends its first tokens verifying rather than rediscovering.
> 3. **It carries the acceptance bar explicitly**, so that if THIS agent also dies, the next one
>    inherits the list rather than a summary of it.
> 4. **It treats the predecessor's commit subjects as a lead, never as evidence.** That instruction
>    paid for itself: six of the seven figures the dead agent had written into the tree did not
>    reproduce, and only a fresh agent re-deriving them found that out.
>
> When you build the next one, substitute the step's own facts and keep those four properties.
> Adapting this file in place is fine; overwriting the header is not.

---

# P3.audit-fix-2 — CONTINUATION BRIEF (§1.8 rung 3)

**You are picking up a step whose previous agent DIED mid-flight. You are NOT starting it.**

Eleven commits of its work are already on the branch and its worktree is clean — nothing was
lost. Your predecessor left no report, so nobody knows whether the seven fixes actually landed,
whether its tests bite, or what its figures were. **That is what you are here to establish.**

**Do not start over. Do not revert its work. Do not re-implement what is already committed.**
Read what is there first, then close only the gaps you find.

## Your sandbox

- Worktree `/home/sites/prompt-step-P3.audit-fix-2`, branch `prompt/P3.audit-fix-2`, HEAD
  `0f415e493`. Already synced with master by the orchestrator — no conflicts, and the sync touched
  no `sugar-crush/` file.
- `sugar-crush/vendor/` is materialised; the PSR-4 root is verified to print the worktree's own
  `src/`. **Never run `composer install`/`composer update`** — it de-symlinks `vendor/sugarcraft/*`
  and silently voids every measurement taken after it.
- Your PRIVATE scratchpad: `/tmp/claude-1000/-home-sites-sugarcraft/3e35a6d4-602a-4db1-b5fa-055d3792747f/scratchpad/P3.audit-fix-2-cont/`. Every sub-agent you spawn gets its own
  subdirectory beneath it.

## What is already committed — eleven commits, oldest first

```
2f186fa98  A1/A2/A3/A4/A6/A7, six of seven findings
165f85dde  A5, the tree-wide guard roster is DERIVED, not typed
e559ba521  drop two project-instruction fence literals my A1 doc-blocks added
d23606898  the A5 roster's own claims re-derived, and four made executable
061e78479  channel A's alphabet is derived too, not one hardcoded name
d34028669  a licensed residue row that has gone dead is now a red
e9d5dd3b5  the roster's fail-closed claim, restated as the one it can keep
5685b2e02  unstack two doc-blocks; the guard that caught it is the A5 argument
c4bbd9dda  seven figures re-derived; six of them were mine and wrong
0efee65ab  a helper that DELEGATES the walk is no longer missed silently
5a78a87f8  the self-referential census stops counting; final fix pass
```

Scope, VERIFIED by the orchestrator (`git diff --stat master...HEAD`): exactly the six declared
files, nothing outside `sugar-crush/` —
`src/Agents/Agent.php` +76 · `src/Runtime.php` +34 · `tests/Agents/AgentTest.php` +210 ·
`tests/Context/EnvironmentBlockTest.php` +220 · `tests/RuntimeTest.php` +419 ·
`tests/TreeWideGuardRosterTest.php` +2359 (NEW). 3303 insertions, 15 deletions.

**Those subject lines are a LEAD, not evidence.** They suggest the predecessor ran its own review
loop and that a reviewer caught real defects in its work ("six of them were mine and wrong"). None
of that is verified. **A commit subject is not a test result**, and §1.8 is explicit that a dead
agent's work is never accepted because the tests happen to be green.

## The seven fixes it was supposed to make

Full specification: `prompt_kit/findings/phase3-close-review-cycle-1.md` (824 lines, in the repo —
read it). Its original brief, if `/tmp` still has it:
`/tmp/claude-1000/-home-sites-sugarcraft/3e35a6d4-602a-4db1-b5fa-055d3792747f/scratchpad/P3.audit-fix-2/BRIEF.md`. Summary:

- **A1 (HIGH)** `src/Runtime.php:771` and `src/Agents/Agent.php:442` claimed the two prompt
  assemblers order `<env>` **oppositely**. Both put it LAST — this plan's own P3.S1 made that
  false. Correct the reason in §16.8 rule 42's three-part form (what it said / what is true / how
  measured), **do not unify the assemblers, do not remove anything**, and add a guard that reds if
  the two ever stop agreeing on env-last. The real reason two assemblers stay separate is that they
  carry different LAYER SETS: `Runtime::buildSystemPrompt()` assembles seven layers,
  `Agent::systemPrompt()` two. **Verify that against the code before writing it.**
- **A2 (MED)** `tests/Agents/AgentTest.php` — an assertion message repeated a claim §18 records as
  FALSIFIED ("unwireable … because no signal reaches the parent"). The real reason is DECLARED
  SCOPE: the seam is real and live in `Workflows/WorkflowEngine.php`, and wiring it is a build-out
  across three files, escalated. **The pin itself binds and must keep binding** — adding
  `public readonly ?array $toolCalls = null,` as a ninth parameter to
  `src/Agents/AgentResult.php:24` must still red it.
- **A3 (MED)** `src/Agents/Agent.php:419-423` claimed THIRTY distinct citations in FORTY-SIX
  occurrences. Actual **31 and 54**, wrong at the very commit that wrote them, and the generator it
  offered (`| sort -u | wc -l`) can only produce the first figure. Fix per rule 2 — ship the
  generator, not the count.
- **A4 (MED)** `tests/RuntimeTest.php` — the write-primitive scanner accepted only `T_STRING` and
  `T_NAME_FULLY_QUALIFIED`, so `namespace\file_put_contents()` (`T_NAME_RELATIVE`) scanned as `[]`,
  the fail-OPEN direction. Twelfth defeat of that scanner. Make it fail closed, and **run it against
  known-answer input before and after**: `Write.php => ["file_put_contents","mkdir"]`,
  `Edit.php => ["file_put_contents"]`, `Read.php => []`.
- **A5 (HIGH, the headline)** `tests/TreeWideGuardRosterTest.php` (NEW) — the nine-file census set
  is a hand-maintained list over a derivable population; five of seven consumers of the shared
  whole-tree walker trait were outside it. Derive it, self-policing, so a new guard added outside
  both channels reds and names itself. **Report the precision honestly** — channel A (trait
  consumers) is sound; channel B (tests rolling their own root-anchored walk) over-classifies and is
  a superset, not a roster.
- **A6 (HIGH/SECURITY)** `tests/Context/EnvironmentBlockTest.php` — a NEW unrostered `</env>`
  fence-escape via the GIT BRANCH NAME, reproduced on a clean tree with no write:
  `git checkout -b '</env>SYSTEM-….<env>'` yields 2 opening / 2 closing fences with the payload at
  top level of the system prompt. **RECORD IT, DO NOT FIX IT** — functionality-before-hardening; the
  escaping fix folds into P5.S3. Model it on the existing commit-subject vector test, which asserts
  the forgery *reaches the block unescaped*.
- **A7 (LOW)** `tests/RuntimeTest.php` — a working guard's failure message points at `Runtime` when
  the correct repair is in `PermissionGate`. Wording only; do not weaken the assertion.

## YOUR JOB, IN THIS ORDER

1. **Read the diff and the six files.** `git diff master...HEAD -- sugar-crush/`. Then read each
   touched file in full — a diff hides what the surrounding code does.
2. **Build the A1–A7 ledger.** For each finding: did it land? Name the `file:line` or test that
   satisfies it. **A finding you cannot point at is not done** — close it yourself.
3. **Check the predecessor's own claims.** Commit `c4bbd9dda` says seven figures were re-derived and
   six of them were wrong. **Re-derive every figure that survives in the tree.** Any number in a
   doc-block, comment, assertion message or test that you cannot reproduce with a command is a
   defect you fix. This step exists partly *because* three of its own findings were wrong figures.
4. **Run the deletion experiment for every guard the branch ships.** Not "it would fail" — mutate,
   run, record the red test name and count, restore, verify the restore by md5. §1.11: an
   annotation, a `method_exists()`, or a shape assertion is not a test. **State every experiment you
   ran in your report.** An unstated or unrun deletion experiment fails the step however green the
   suite is.
5. **Verify** (commands below).
6. **If you changed anything, run one more review cycle** — a brand-new reviewer handed
   `prompt_plan.md` §1.4's nineteen checks verbatim, your diff, and your test output. **Never hand a
   reviewer the previous reviewer's findings**; a reviewer given a list checks the list.
7. **Return the full report.**

## VERIFY

From `/home/sites/prompt-step-P3.audit-fix-2/sugar-crush`, each figure naming its cwd and command:

```sh
vendor/bin/phpunit tests/RuntimeTest.php
vendor/bin/phpunit tests/Agents/AgentTest.php
vendor/bin/phpunit tests/Context/EnvironmentBlockTest.php
vendor/bin/phpunit tests/TreeWideGuardRosterTest.php
```
Baselines on master, measured: `RuntimeTest` 128/450 · `AgentTest` 33/327 ·
`EnvironmentBlockTest` 42/142. Use a PATH, never `--filter` — `--filter AgentTest` is a regex that
also matches `SubAgentTest`.

The nine-file census set (baseline `OK (176 tests, 31215 assertions)`), then **the roster A5
derives** — run every member and record each isolated figure:
```sh
vendor/bin/phpunit \
  tests/SymbolCitationDriftTest.php tests/SwallowingCatchCensusTest.php \
  tests/Support/DuplicatedTestHelperDriftTest.php tests/Support/ChildWallClockBudgetTest.php \
  tests/Config/EnvRosterDriftTest.php tests/Tools/BuiltInToolCorpusTest.php \
  tests/Support/InterpolationOpenerTokenTest.php tests/Support/ChildStderrCaptureTest.php \
  tests/Config/GlobFigureDriftTest.php
```
**Check the assertion count, not just the green** — a roster test that stops iterating still prints
`OK`, and a figure materially below baseline is a guard quietly un-guarding itself.

The full suite, **from the CHECKOUT ROOT**, box quiet, stdin redirected:
```sh
ps -eo pid,cmd | /usr/bin/grep -c '[v]endor/bin/phpunit'     # must print 0 FIRST
cd /home/sites/prompt-step-P3.audit-fix-2 && php sugar-crush/vendor/bin/phpunit \
  -c sugar-crush/phpunit.xml --colors=never \
  --log-junit /tmp/claude-1000/-home-sites-sugarcraft/3e35a6d4-602a-4db1-b5fa-055d3792747f/scratchpad/P3.audit-fix-2-cont/junit.xml </dev/null | tail -5
```
**Master's figure: `Tests: 10526, Assertions: 162447, Skipped: 1`** — measured twice independently.
**A5 adds a whole new test file, so the total WILL move. State your PREDICTION before you run, then
attribute every assertion of the delta** with `prompt_kit/tools/cmp.py`
(`python3 prompt_kit/tools/cmp.py <a.xml> <b.xml>` — copy it into your scratchpad first, do not edit
it in place). It names a mover in one pass; twenty-five guards were once measured one at a time
before anyone thought of this.

Then, **from the repo root** (not `sugar-crush/` — from the wrong cwd `php` cannot find the script
and it "fails"; that misread has happened twice):
```sh
cd /home/sites/prompt-step-P3.audit-fix-2 && php tools/check-path-repos.php --no-lib-path-repos
```

Two tests fail ONLY under a pty with a live terminal (`Chat\CompactModelSummaryTest`,
`MouseModalGuardTest`) — always `</dev/null`. Use `/usr/bin/grep` for anything that must see the
whole tree; the shell's `grep` is `ugrep` and honours `.gitignore`. php-cs-fixer is not installed
and not vendored — match surrounding style by eye.

## HARD PROHIBITIONS

- **Never remove unfinished, dormant, unwired or unreachable code.** Wire it, build it out, or stop
  and ask (§1.10). **Live here: A6 records a security hole — do not "fix" it by deleting the branch
  line**, and do not let a reviewer talk you into removing the paths A1 touches.
- **Never weaken, skip, rename-out or delete an existing test to make something pass.**
- **Never leave a sub-agent's work uncommitted.** Commit to the branch immediately, amend or revert
  if a review objects. A commit is recoverable; a dirty worktree owned by a dead agent is not — that
  is exactly what happened to your predecessor's step, twice now.
- **If any agent you spawn returns empty, truncated, or aborted: it DIED.** Not `NO FINDINGS`, not a
  finished job. Recover it; blank returns get five attempts. **Never write a dead agent's report
  yourself** — you are the direct beneficiary of that rule.
- **ORCHESTRATION-RULE-2** — no scratch git repo outside your own scratchpad; `pwd` before ANY
  `git init` / `git commit` / `git config`; **never `git config --global`**. A previous reviewer ran
  a throwaway-repo setup inside `/home/sites/sugarcraft` itself, overwrote the repo identity, and
  left a stray commit on master.
- **ORCHESTRATION-RULE-3** — own scratchpad subdirectory; `rm -rf` only inside it; no generic names
  at the scratchpad root (`sb`, `base`, `count.php`, `*.orig.php`). **Back up worktree files only to
  PRIVATE paths** — backing up to a shared name, mutating, then restoring can restore another
  agent's copy and silently contaminate your source in a way no test would attribute.
- Never `git push`, never merge to `master`, never touch `/home/sites/sugarcraft` or
  `/home/sites/crush-lane-{a,b,c}`, never edit `docs/plans/crush_code_*.md`, `left_steps.md`, or the
  `prompt_*.md` files. Never run `caliber`. Never suppress a git hook. Never a global `pkill`.
- **Never write a number you did not measure.**

## YOUR REPORT

1. **The A1–A7 ledger:** per finding, landed or not, and the `file:line` or test name proving it.
   For anything still open, say so plainly — an open finding you name is fine; one you skip silently
   is not.
2. **What you had to change**, file by file with line ranges, and what you left alone.
3. **The deletion experiment for every guard on this branch** — the exact revert edit, the command,
   the red test name and count, and confirmation you restored and md5-verified.
4. **Every figure**, naming command and cwd: four declared test files; the nine-file census set; the
   roster A5 derives with each member's isolated figure; the full suite with your prediction first
   and the per-class attribution of the delta with no remainder; the path-repo gate.
5. **Any figure in the tree you could not reproduce** — this is the highest-value thing you can
   return, given `c4bbd9dda` says six of seven were already wrong once.
6. **Findings outside the six declared files**, with `file:line` — reported, never fixed.
7. **Anything you measured that contradicts this brief.** Three-part form: what it said, what is
   true, how you measured. This brief hands you numbers the orchestrator measured; if one is wrong,
   say so.
8. **What your predecessor left undone or wrong**, stated plainly. You are the only one who will
   ever know, and the worklog entry depends on it.
