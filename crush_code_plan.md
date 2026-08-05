# Sugar-Crush Multi-Agent Orchestration Implementation Plan

**Version:** 1.0  
**Date:** 2026-08-04  
**Status:** Draft  
**Target:** sugar-crush v2.0  

---

## Executive Summary

**Sugar-crush** is a PHP terminal AI coding agent — a port of charmbracelet/crush — providing a TUI-based chat shell with multi-provider LLM support, built-in coding tools, skills system, hooks, sub-agents, MCP integration, and SQLite session persistence.

This plan addresses the critical gaps between sugar-crush's current capabilities and the multi-agent orchestration features provided by OpenCode and Claude Code.

### What Needs to Be Built

Sugar-crush currently implements sequential sub-agent execution via PHP Generators. To match OpenCode and Claude Code, it needs:

1. **Parallel agent spawning** (up to 5 concurrent agents)
2. **Agent teams** with lead/teammate architecture
3. **Worktree isolation** for conflict-free parallel file operations
4. **Inter-agent messaging** via mailbox/inbox system
5. **Dynamic workflow orchestration** with parallel/pipeline primitives
6. **A named permission-mode system** so every agent — main session or teammate — runs under an explicit policy, from a manual mode that prompts on every write to a sandboxed bypass mode for containers, instead of relying on hooks alone to gate dangerous actions
7. **A formal agent preset schema** (tools, model, permission mode, max turns, memory scope, isolation, effort, initial prompt) so sub-agent definitions stop being bespoke constructor calls and become a documented, composable, file-based contract
8. **Nested instruction-file discovery** so any `candy-*`/`sugar-*`/`honey-*` subdirectory can carry its own `CLAUDE.md`/`AGENTS.md` that layers onto the root file automatically the first time an agent touches a file in that lib, instead of every session paying the token cost of all 52 libs' conventions up front
9. **A structured hook lifecycle** — pre/post tool use, session start/end, prompt submit, pre-compact, teammate idle, task created/completed — with documented per-event exit-code semantics, extending today's allow/deny/modify hook surface into something predictable enough to build quality gates on

### Approach

Implement in 7 phases, each building on the previous:

| Phase | Focus | Risk | Duration |
|---|---|---|---|
| 1 | Parallel Execution Engine | Medium | 2-3 weeks |
| 2 | Agent Teams Architecture | High | 3-4 weeks |
| 3 | Worktree Isolation | Medium | 2 weeks |
| 4 | Dynamic Workflows | High | 3-4 weeks |
| 5 | UI/UX Enhancements | Low | 2 weeks |
| 6 | Context & Memory | Medium | 2 weeks |
| 7 | Integrations | Low | 2-3 weeks |

Two cross-cutting pieces don't fit cleanly into a single phase number: the Agent Preset Configuration Schema needs to exist before Phase 1's worker pool has anything to schedule, so it lands alongside Phase 1 as **Phase 0**; Permission Modes and the Hook Lifecycle need Phase 2's task list to hook `TaskCreated`/`TaskCompleted` into, so they land right after Phase 2 as **Phase 2B**. Nested Instruction File Discovery and the Skill Loading Model both extend Phase 6 and Phase 7 respectively and are executed as steps inside those phases rather than as their own phase.

---

## Execution Protocol for Orchestrated Implementation (OpenCode + MiniMax-M2.7)

Everything above this line describes *what* to build. Everything from here down describes *how a machine builds it unattended*: this plan is going to be executed by a hierarchy of agents running on OpenCode against a `MiniMax-M2.7` backend with a 200K-token context window, with no human watching each individual tool call. This section is the complete, literal operating manual for that run. Read it once before starting anything — every role, every prompt, every loop, and every stopping condition is spelled out here on purpose, so that nobody running this has to guess what "review the step" or "move on" actually means in practice.

### The rule that shapes everything else: keep every agent's context small

`MiniMax-M2.7` tops out at 200K tokens of context. The working target for this run is to keep **every single agent's context under roughly 150K tokens**, leaving headroom for tool-call overhead, the model's own reasoning tokens, and the occasional larger-than-expected file. The way this plan hits that target is not by making individual agents "be careful" — it's structural: **every agent that touches code is spawned fresh, does one small job, and is thrown away.** It never carries a growing conversation history across multiple review cycles. A step that goes through five rounds of review-and-fix does not produce one agent with a context that grows five times bigger — it produces five separate agents, each starting from zero, each reading only the current state of the files on disk plus a short, explicit prompt. This is the single most important idea in this whole protocol: **loops don't accumulate context, because nothing is reused between loop iterations except what's explicitly written into the next agent's prompt.**

The second half of staying small is that the plan itself has already been broken into roughly 110 small steps (see the "Step Manifest" table added to every phase below), each scoped to one class, one small tightly-coupled cluster of files, or one focused piece of logic — never "build all of Phase 2." A step's Builder Agent should only ever need to read a handful of existing files (2-6 typically) plus the one subsection of this plan document that describes its step. If a step ever looks like it needs to touch more than about 5 files or read more than a few hundred lines of existing code to understand what to do, that is a sign the step was scoped too big and should be split into two steps before starting — the Phase Agent should do this splitting itself rather than push a giant step through the loop.

### The three-tier hierarchy

```
Plan Orchestrator                  (exactly one instance, lives for the entire run)
 └── Phase Agent                    (exactly one at a time per phase, spawned in dependency order)
      └── for every Step in that phase's Step Manifest, in order:
           1. Step Builder Agent    — implements the step
           2. Step Review Agent     — checks the work against the full checklist below
           3. Step Fix Agent        — only spawned if step 2 found problems; addresses all of them
           (repeat steps 2-3 until Step Review Agent reports zero findings, or the cycle cap is hit)
           4. Step Commit Agent     — commits the finished step directly to master and pushes
```

Nobody at the Plan Orchestrator or Phase Agent tier is allowed to read or write a single line of source code, run a test, run `composer`, or perform any research themselves. Their entire job is deciding **which agent to spawn next** and **recording what happened** in a small progress file. Every actual unit of work — writing code, reviewing code, fixing code, running `vendor/bin/phpunit`, running `git commit` — happens inside a Step-tier agent, spawned fresh for that one job, and discarded the moment it reports back.

### Tool access per role — set this up before starting

| Role | Allowed tools | Explicitly forbidden |
|---|---|---|
| Plan Orchestrator | spawn-agent, Read (its own progress file + this plan document only) | Bash, Write, Edit, any tool that touches `src/`, `tests/`, or any lib directory |
| Phase Agent | spawn-agent, Read (its own progress file + its phase's section of this plan document), Write (its own progress file only) | Bash, Edit, any tool that touches `src/`, `tests/`, or any lib directory |
| Step Builder Agent | Read, Write, Edit, Bash, Glob, Grep | — full access, scoped by instruction rather than by tool restriction |
| Step Review Agent | Read, Glob, Grep, Bash (read-only commands only: `phpunit`, `php-cs-fixer --dry-run`, `composer validate`, `composer update`, `git status`, `git diff`, `git log`) | Write, Edit, `git commit`, `git push`, `git checkout`, `git reset`, any command that changes repository state |
| Step Fix Agent | Read, Write, Edit, Bash, Glob, Grep | — same as Builder, but scoped by instruction to only the findings list it was handed |
| Step Commit Agent | Read, Bash (git commands only) | Write, Edit — it stages and commits files that are already finished, it never needs to change their content |

If OpenCode's default sub-agent role is the restricted read-only kind (glob/grep/ls/view only, no bash or file writes), do not use that default role for Builder, Fix, or Commit. Define these as their own custom agents in the OpenCode configuration with the explicit tool lists above — the whole point of the Builder/Fix/Commit roles is that they can actually change files and run commands. This has already been done for this repo — see "Concrete OpenCode wiring" a few paragraphs down for the actual agent names and where each is configured.

### Progress tracking — how the orchestrator and phase agents avoid losing track without reading huge transcripts

Before anything else happens, a one-time **bootstrap** runs, spawned by the Plan Orchestrator itself (this is the one exception where the Plan Orchestrator spawns something other than a Phase Agent — purely to set up bookkeeping, it is still not the Plan Orchestrator doing the work itself). There is no dedicated "bootstrap agent" role — it's just the Builder and Commit roles used once, back to back, before any phase starts:

1. The Plan Orchestrator spawns a Step Builder Agent to create a directory at the repo root called `.sugar-crush-build/`, create `plan-progress.json` inside it containing a JSON object with one key per phase (`P0`, `P1`, `P2`, `P2B`, `P3`, `P4`, `P5`, `P6`, `P7`) each initialized to `{"status": "not_started"}`, and add the line `.sugar-crush-build/` to the repo's `.gitignore` file (these are process artifacts for this build run, not part of the shipped product).
2. The Plan Orchestrator then spawns a Step Commit Agent to commit that with the message `sugar-crush: bootstrap orchestration state directory` and push directly to `master`.
3. Only after that completes does the Plan Orchestrator move on to spawning the first Phase Agent for `P0`.

From that point on:
- The **Plan Orchestrator** reads and writes only `.sugar-crush-build/plan-progress.json`. After each Phase Agent finishes (or gets blocked), the Plan Orchestrator updates that phase's entry to `"status": "done"` or `"status": "blocked"` and moves on. It never needs to remember anything else — if the Plan Orchestrator itself were restarted mid-run, re-reading this one small file tells it exactly where to resume.
- Each **Phase Agent** reads and writes its own file, `.sugar-crush-build/phase-<id>-progress.json` (e.g. `phase-P1-progress.json`), with one entry per step: `{"stepId": "P1.S1", "status": "done" | "in_progress" | "blocked", "reviewCycles": 2, "lastFindings": [...]}`. Same idea: a Phase Agent never has to remember its own history across many step spawns, because after every single step it writes the outcome to this file and could, in principle, be restarted from it.

This is the mechanism that lets the Plan Orchestrator and Phase Agent stay tiny: they are not accumulating a transcript of 110 steps' worth of code review; they are reading and writing a few kilobytes of JSON.

### Concrete OpenCode wiring

The six roles above map onto real, already-configured OpenCode agents in this repo rather than needing to be invented from scratch at run time:

| Plan role | OpenCode agent | Spawn tool | Defined in |
|---|---|---|---|
| Plan Orchestrator | `sugarcrush-orchestrator` | n/a — this is `mode: primary`, the user switches to it directly rather than anyone spawning it | `.opencode/agents/sugarcrush-orchestrator.md` |
| Phase Agent | `sugarcrush-phase-lead` | `task` | `.opencode/agents/sugarcrush-phase-lead.md` |
| Step Builder Agent | `coder` (existing agent, reused as-is) | `task` | `.opencode/agents/coder.md` |
| Step Review Agent | `sugarcrush-reviewer` | `delegate` | `.opencode/agents/sugarcrush-reviewer.md` |
| Step Fix Agent | `coder` (same existing agent, a fix-scoped task) | `task` | `.opencode/agents/coder.md` |
| Step Commit Agent | `sugarcrush-committer` | `task` | `.opencode/agents/sugarcrush-committer.md` |

OpenCode routes a spawn through one of two different tools depending on whether the target agent is write-capable or read-only, and this is not optional or interchangeable — calling the wrong one fails immediately with a routing error instead of doing what was intended. **`task`** is for anything whose permission profile allows `edit`, `write`, or a broad-enough `bash` (`coder`, `sugarcrush-phase-lead`, `sugarcrush-committer`). **`delegate`** is for agents with `edit`/`write` both denied and `bash` scoped down to an explicit allowlist (`sugarcrush-reviewer`) — `delegate` still runs every command that agent's permission profile allows, it's purely about spawn routing, not a further restriction. `sugarcrush-committer` looks narrowly scoped in the same way `sugarcrush-reviewer` does, but it is deliberately configured with `bash: {"*": "allow", ...specific dangerous commands denied...}` rather than `bash: {"*": "deny", ...specific commands allowed...}`, specifically so it gets classified write-capable and routed through `task` — the dangerous operations (`git push --force`, `git reset`, `git checkout --`, `git clean`, `git add -A`/`.`, `git commit --amend`) are still hard-denied individually, just via the opposite default. If a Phase Lead ever gets a routing error naming one of these agents, the fix is to retry the identical spawn with the other tool, never to change what was asked of the agent.

Their permission profiles (what each is allowed to `read`/`write`/`edit`/`bash`, and which specific `git`/`composer`/`php` command prefixes each is allowed to run) are registered under `"agent"` in `.opencode/opencode.jsonc`, following the exact same permission-block style already used there for the repo's existing `coder`/`reviewer`/`researcher`/`scribe`/`explore`/`build`/`plan` agents. `coder` needed no changes at all — its existing profile (`read`/`write`/`edit`/`glob`/`grep`/`bash` all `allow`, forbidden from ever running `git commit` per its own prompt) already matches exactly what a Step Builder or Step Fix Agent needs, which is why this plan reuses it rather than defining a new one. `sugarcrush-orchestrator` and `sugarcrush-phase-lead` are `mode: subagent`-adjacent delegation-only agents mirroring the repo's existing `build`/`plan` agents (`edit`/`bash` denied, `task` allowed), with one narrow addition: `write` allowed so each can maintain its own `.sugar-crush-build/*.json` progress file — a rule enforced by their prompts rather than by permission scoping, the same way `coder` is technically allowed to run `git commit` but is instructed never to. `sugarcrush-reviewer` mirrors the existing `reviewer` agent's shape (read-only, no `edit`/`write`, `git diff`/`log`/`show`/`blame` allowed) with an expanded `bash` allowlist covering `composer *` and `php *` so it can actually run this repo's test suite, plus `git status*` which the generic `reviewer` doesn't need but this one does. `sugarcrush-committer` is the one genuinely new capability — nothing in the existing agent set is allowed to `git commit`/`git push`, since day-to-day work in this repo goes through PRs — so it exists solely to perform the direct-to-master commits this orchestrated run calls for, with `git add`/`git commit`/`git push origin master` allowed and nothing else.

Kick off (or resume) a run with the `/sugarcrush-build` command, which switches to `sugarcrush-orchestrator` and points it at this document.

### Reading the Step Manifest tables

Every phase section below (Phase 0 through Phase 7 plus Phase 2B) now ends with a **Step Manifest** table, right before the `---` separator that leads into the next phase. Each row has a Step ID (e.g. `P1.S1`), a short title, the file(s) it creates or modifies, which earlier step IDs it depends on, and a pointer to exactly where in that phase's own text the full detail already lives (almost always a `#### N. ClassName.php` sub-heading, or a named prose subsection). The manifest does **not** repeat the class signatures, field lists, or acceptance criteria already written out earlier in the phase — that would just be the same information twice, wasting context for no reason. Instead, the Phase Agent hands the Builder Agent a pointer ("read the subsection titled `#### 2. AgentResult.php` in the Phase 1 section of this document") and the Builder Agent reads that specific slice itself.

Steps are executed **in the order listed, one at a time**, by default. A step may only be started once every step ID listed under its "Depends On" column has status `"done"` in the phase's progress file. Do not run two steps' review/fix/commit loops concurrently even if their builds happen to overlap in time — concurrent commits to the same files is exactly the kind of collision this repo has been bitten by before (this is also why `docs/MATCHUPS.md` and `README.md` specifically must never be touched by two steps running at once). Running two steps' *Builder* agents concurrently is an acceptable optimization only when neither step's file list overlaps with the other's at all — treat this as an optional speed-up, not the default, and never apply it to two steps in the same phase unless you've manually confirmed zero file overlap.

### Spawning a Phase Agent

The Plan Orchestrator spawns exactly one Phase Agent at a time, following this order (this is the same dependency order already laid out in "Implementation Order and Dependencies" below, repeated here so it's not missed): **P0 → P1 → P2 → P2B → P3 → P4 → P5 (can start any time after P1) → P6 (can start any time after P1) → P7**. Keep it simple and run them one after another in that order unless you have specifically confirmed two phases share no files — P5, P6, and P7 are the ones flagged in the dependency section as safe to parallelize with each other, but even then, do this only as an optimization once the sequential path is proven to work.

Prompt template for spawning a Phase Agent:

```
You are the Phase Agent for <PHASE_ID> ("<PHASE_TITLE>") of the sugar-crush build.

Read the section of /home/sites/sugarcraft/crush_code_plan.md that starts at the
heading "## Phase <N>: <PHASE_TITLE>" (or "## <SECTION_TITLE>" for Phase 0 / Phase 2B)
and ends at the next "---" separator. Pay special attention to the "Step Manifest"
table at the end of that section — it lists every step you are responsible for,
in the order to execute them, with their dependencies.

Your job, and ONLY your job:
1. Read your progress file at .sugar-crush-build/phase-<PHASE_ID>-progress.json
   (if it doesn't exist yet, create it with every step from the Step Manifest set
   to {"status": "not_started"}).
2. Pick the next step whose status is "not_started" and whose dependencies are
   all "done".
3. Run that step through the Builder -> Review -> Fix loop described in the
   "Execution Protocol" section of the plan document, using the exact prompt
   templates given there.
4. When the step's review comes back completely clean, spawn the Step Commit
   Agent for it, then update this step's status to "done" in your progress file.
5. If a step fails to come back clean after 5 full review cycles, mark it
   "blocked" in your progress file, stop working on this phase, and report back
   to whoever spawned you that phase <PHASE_ID> is blocked on step <STEP_ID>,
   including the full findings list from the last review.
6. Repeat from step 2 until every step in the manifest is "done", then report
   back that phase <PHASE_ID> is complete.

You do not write code. You do not run tests. You do not read or edit any file
under src/, tests/, or any lib directory yourself. You only read this plan
document and your own progress file, and you only spawn the Step Builder,
Step Review, Step Fix, and Step Commit agents described below.
```

### Spawning a Step Builder Agent

Prompt template:

```
You are the Step Builder Agent for step <STEP_ID> ("<STEP_TITLE>") of the
sugar-crush build.

Read ONLY this: the subsection of /home/sites/sugarcraft/crush_code_plan.md
pointed to by step <STEP_ID> in the Phase <PHASE_ID> Step Manifest (it will be
named like "#### N. ClassName.php" or a named prose subsection). That is your
complete specification. Do not read the rest of the plan document unless the
subsection itself tells you to look at a specific other section for a detail
you need (e.g. a shared enum defined in an earlier step).

Files you are expected to create or modify for this step:
<FILE_LIST_FROM_MANIFEST_ROW>

Do this, in order:
1. Read the existing files listed above that already exist, and read 2-3
   sibling files in the same directory to confirm the coding conventions in
   use (declare(strict_types=1), PSR-4 namespace, final classes, readonly
   properties, with*()/mutate() pattern, bare accessors, ::new() factories —
   see AGENTS.md at the repo root if you need the full convention list).
2. Implement exactly what the specification subsection describes. Do not add
   functionality it doesn't ask for. Do not leave TODOs or stub methods unless
   the specification explicitly says this step defers something to a later step.
3. Write PHPUnit tests for every public method you added or changed, in the
   matching tests/ directory, following the existing test file naming pattern
   in that lib.
4. Run the tests yourself: `cd <lib-directory> && composer install --quiet &&
   vendor/bin/phpunit`. If phpunit fails in a way that looks dependency-related
   rather than code-related, run `composer update` first (this repo's
   composer.lock/vendor/ go stale between sessions) and try again.
5. Confirm the tests pass with 0 failures and 0 errors before you finish.
6. Report back a summary under 200 words: what you created/changed, and
   confirmation that tests pass. This summary is for the log only — it is not
   graded, the Review Agent will independently re-check everything.

Use absolute paths in every Bash command, or chain commands with && — your
working directory does not persist between separate Bash tool calls.
```

### Spawning a Step Review Agent — the full checklist

This is the most important prompt in the whole protocol. The Review Agent is a completely fresh agent every single time it's spawned — even on the second, third, fourth, or fifth cycle for the same step, it has no memory of what an earlier Review Agent found or what a Fix Agent claimed to have fixed. It re-derives everything from the current state of the files on disk. This is deliberate: a reviewer that "remembers" being told something was fixed is a reviewer that can be talked out of catching a fix that didn't actually work.

On OpenCode, `sugarcrush-reviewer` is read-only and must be spawned via the **`delegate`** tool, not `task` — see "Concrete OpenCode wiring" above.

Prompt template:

```
You are the Step Review Agent for step <STEP_ID> ("<STEP_TITLE>") of the
sugar-crush build. You are reviewing work that a previous agent claims to have
completed. You have no information about what that agent did or said beyond
what is written below and what you can see on disk right now — treat every
claim of "done" as unverified until you personally confirm it.

Read ONLY this: the subsection of /home/sites/sugarcraft/crush_code_plan.md
pointed to by step <STEP_ID> in the Phase <PHASE_ID> Step Manifest. That is the
requirement you are checking the code against.

Files this step was scoped to touch:
<FILE_LIST_FROM_MANIFEST_ROW>

Run `git status` and `git diff` (or `git diff HEAD` if changes are already
staged) scoped to the repo root to see exactly what has changed since the last
commit. Then work through every one of the following ten categories, in order,
and do not skip any of them even if an earlier category already found problems:

1. REQUIREMENTS TRACEABILITY
   - Re-read the exact specification subsection word for word.
   - List every file the step was supposed to create or modify (from the list
     above). Confirm every one of them actually exists and was actually
     touched.
   - Confirm no file outside that list was touched (scope creep) — if
     something outside the list WAS touched, that is a finding, not something
     to silently allow.

2. COMPLETENESS
   - Every method/property named in the specification is present with a
     matching signature (name, visibility, parameter types, return type,
     readonly-ness where specified).
   - No method is an empty body, a bare `throw new \Exception('not
     implemented')`, or a TODO comment, unless the specification explicitly
     says this method is deferred to a later step.
   - Every acceptance-criteria bullet that applies to this step is checked
     individually and marked true or false — "looks fine" is not a check.

3. CORRECTNESS
   - Trace the logic by hand for at least one normal input and one edge case
     (empty array, null, zero, a very large value, or concurrent access —
     whichever is relevant to this specific class).
   - For anything touching concurrency (flock, SQLite writes, process
     spawning, file locking), specifically reason about the race condition the
     code claims to prevent and confirm it actually prevents it.
   - Look for off-by-one errors, inverted comparisons, and swapped arguments.

4. CONVENTION AND STYLE COMPLIANCE
   - `declare(strict_types=1);` is the first line of every new PHP file.
   - The namespace matches the PSR-4 slug-to-namespace mapping for this lib.
   - Public classes are `final` unless the specification explicitly says this
     one is meant to be extended.
   - Every `with*()` method returns a new instance via `mutate()` and never
     mutates `$this` in place.
   - Nullable fields carry a paired `bool $xSet` sentinel where the existing
     codebase convention calls for it.
   - Accessors are bare (no `get` prefix). Factories use `::new()` or a named
     factory, never `::create()`/`::make()`/`::default()`.
   - Comments (if any) explain a non-obvious WHY, not a restatement of the
     code.

5. CODE QUALITY AND SIMPLIFICATION
   - No dead code, no unused imports, properties, or parameters.
   - No premature abstraction for a case that only needed 2-3 similar lines.
   - No copy-pasted logic that should be a single shared private method.
   - Error handling exists only at real boundaries (invalid external input,
     process/IO failure) and is absent everywhere internal state is already
     guaranteed by the type system or an earlier check.

6. TEST COVERAGE
   - A test file exists for every new class in this step.
   - Every public method has at least one test.
   - The specific test case names given in this plan's "Testing Strategy"
     section for this class (if any are named there) are present, by that
     name or a clear equivalent, and each one asserts something meaningful —
     not just "does not throw."
   - Edge cases are covered: empty input, boundary values, concurrent access
     where relevant, and failure paths, not only the happy path.
   - Actually run the tests yourself right now: `cd <lib-directory> &&
     composer install --quiet && vendor/bin/phpunit`. If it fails in a way
     that looks dependency-related, run `composer update` first and re-run
     before concluding the code itself is broken.
   - Confirm tests were not weakened to pass: no commented-out assertions, no
     artificially loosened expectations, no markTestSkipped() used to dodge a
     hard case.

7. REGRESSION SAFETY
   - Run the full test suite for every lib touched by this step (not just the
     new tests) and confirm nothing that previously passed now fails.
   - If this step touched a file shared by other libs (for example something
     under candy-core), note which sibling libs depend on it in your report so
     a human or a later phase knows to spot-check them.

8. SECURITY
   - Any new file-path handling is checked against path traversal.
   - Any new shell command construction uses escapeshellarg() on every
     variable piece and never string-concatenates untrusted input directly
     into a command string.
   - Any new SQL uses parameter binding, never string interpolation.
   - Any new external network or process response is handled without blind
     deserialization or eval of untrusted content.

9. COULD IT BE DONE BETTER
   - Is there a simpler way to express this using a helper that already exists
     in candy-core or candy-sprinkles, instead of a new one?
   - Is naming consistent with sibling classes already in this codebase?
   - Is there an obvious performance problem — N+1 SQLite queries, unnecessary
     copies of large arrays, synchronous I/O inside a hot loop?

10. YOUR VERDICT
   - List every problem you found as: severity (blocker / major / minor /
     nit), file path, line number if applicable, a one-sentence description,
     and a one-sentence suggested fix.
   - Even if you found nothing, briefly confirm in your own words that you
     actually walked through all nine categories above — a clean report with
     no evidence of checking is not trustworthy and should be treated as a
     failed review, not a passing one.
   - End your report with exactly one of these two lines, verbatim, as the
     very last line of your output:
       STEP_REVIEW_RESULT: PASS
     or
       STEP_REVIEW_RESULT: FINDINGS
```

### Spawning a Step Fix Agent

Only spawn this if the Review Agent's last line was `STEP_REVIEW_RESULT: FINDINGS`. The Fix Agent gets the complete findings list in one shot and is expected to address every item in it, not just the first one.

Prompt template:

```
You are the Step Fix Agent for step <STEP_ID> ("<STEP_TITLE>") of the
sugar-crush build. A Review Agent found the following problems in the current
implementation. Fix ALL of them — do not stop after the first one, and do not
make any change outside what's needed to address this list (this is not a
general refactor pass).

Findings to fix:
<VERBATIM_FINDINGS_LIST_FROM_REVIEW_AGENT>

For reference, the original specification for this step is in the subsection
of /home/sites/sugarcraft/crush_code_plan.md pointed to by step <STEP_ID> in
the Phase <PHASE_ID> Step Manifest — read it if you need to confirm what the
correct behavior should be.

After making the fixes, run the tests yourself: `cd <lib-directory> &&
composer install --quiet && vendor/bin/phpunit`, and confirm 0 failures, 0
errors, before reporting back. Report back a summary under 200 words of what
you changed for each finding.
```

After the Fix Agent reports back, the Phase Agent immediately spawns a **brand new** Step Review Agent (using the exact same prompt template as before, from scratch — not a continuation) to re-check the step in full. This repeats until a Review Agent's last line is `STEP_REVIEW_RESULT: PASS`, or until 5 full review cycles have run for this one step without a pass, whichever comes first.

### Spawning a Step Commit Agent

Only spawn this once a Review Agent has returned `STEP_REVIEW_RESULT: PASS`. This repository's normal workflow uses branches and pull requests, but **for this automated build run specifically, commits go directly to the `master` branch — no branches, no pull requests.** This is an explicit, intentional exception for this orchestrated run, not a change to how humans should work in this repo day to day.

On OpenCode, `sugarcrush-committer` is write-capable (deliberately, so it can actually run `git commit`/`git push`) and must be spawned via the **`task`** tool, not `delegate` — see "Concrete OpenCode wiring" above.

Prompt template:

```
You are the Step Commit Agent for step <STEP_ID> ("<STEP_TITLE>") of the
sugar-crush build. The work for this step has already been reviewed and
approved — your only job is to commit it directly to master and push it.

1. Run `git status` and confirm the only files that changed are:
   <FILE_LIST_FROM_MANIFEST_ROW, plus their matching test files>
   If anything else shows as changed, do NOT commit — report back that
   unexpected files were found and list them, and stop.
2. Confirm the current branch is master: `git branch --show-current`. If it is
   not master, stop and report back rather than committing to the wrong
   branch.
3. Stage exactly the expected files by name (never `git add -A` or
   `git add .`):
   git add <FILE_1> <FILE_2> ...
4. Commit with this exact message format:
   git commit -m "sugar-crush: <STEP_ID> <short lowercase description>"
   --author "Joe Huss <detain@interserver.net>"
5. Push directly to master:
   git push origin master
6. Report back the commit hash and confirm the push succeeded.
```

### Worked example, start to finish

To make this completely concrete, here is exactly what happens for one real step: `P1.S1`, creating the `AgentStatus` enum.

1. The Phase Agent for P1 checks its progress file, sees `P1.S1` is `not_started` with no dependencies, and spawns a Step Builder Agent with the prompt template above, filling in `<STEP_ID>` = `P1.S1`, `<STEP_TITLE>` = "AgentStatus enum", and `<FILE_LIST_FROM_MANIFEST_ROW>` = `src/Agents/AgentStatus.php`, `tests/Agents/AgentStatusTest.php`.
2. The Builder Agent reads the `#### 3. AgentStatus.php` subsection of the Phase 1 section (the eight-case enum shown there), writes the file, writes a small test confirming all eight cases exist and `::from()`/`::tryFrom()` behave correctly, runs `vendor/bin/phpunit`, sees it pass, and reports back a two-sentence summary.
3. The Phase Agent spawns a fresh Step Review Agent with the full ten-category checklist. It runs `git diff`, sees exactly the two expected files changed, confirms the enum matches the eight cases specified, confirms `declare(strict_types=1)` is present, confirms the test file exists and covers every case, runs the tests itself and sees them pass, and finds nothing wrong. Its last line is `STEP_REVIEW_RESULT: PASS`.
4. The Phase Agent updates `P1.S1` to `"status": "done"` in its progress file and spawns a Step Commit Agent, which stages exactly those two files, commits with the message `sugar-crush: P1.S1 add AgentStatus enum`, and pushes to master.
5. The Phase Agent moves on to `P1.S2`.

If step 3 had instead found, say, that the test file was missing a case for `AgentStatus::TimedOut`, the Review Agent's report would list that as a `major` finding with a one-line suggested fix, end with `STEP_REVIEW_RESULT: FINDINGS`, and the Phase Agent would spawn a Step Fix Agent with that exact finding, then loop back to a brand-new Step Review Agent to re-check from scratch.

### What "blocked" means and what happens next

If a step is still returning findings after 5 full review cycles, the Phase Agent stops working on that step and that phase entirely — it does **not** skip ahead to the next step, because later steps in the same phase very likely depend on this one being correct, and later phases depend on the whole phase being correct. The Phase Agent marks the step and the phase `"blocked"` in its progress file, including the full findings list from the fifth review, and reports this up to the Plan Orchestrator. The Plan Orchestrator marks the phase `"blocked"` in its own progress file and halts the entire run rather than continuing to a later phase — this is intentional. A later phase built on top of a known-broken foundation just compounds the problem, and a human needs to look at why five review cycles couldn't converge before the run continues.

---

## Local Development & Testing Provider

Every phase above needs an LLM behind it during development, and paid provider APIs make that expensive and rate-limited for the kind of high-volume, throwaway calls a dev loop generates — spinning up 5 concurrent teammates just to verify `AgentWorkerPool` handles timeouts correctly shouldn't cost real API credits every time the test suite runs. Sugar-crush's SGLang provider already exists in the provider list; the concrete move is to point it at a reachable inference server and treat that as the default backend for everything except final acceptance testing against the real target providers.

The dev/test SGLang endpoint is `https://skynet2.interserver.net/v1`, serving the `MiniMax-M2.7` model, available for unlimited use during development — no token budget to watch, no rate limit to work around, which makes it the right default for anything that needs to actually run rather than be mocked: exercising the Phase 1 worker pool with real concurrent completions instead of a canned `EchoProvider` response, validating that a Phase 2 teammate's tool-call output actually parses the way `AgentResult` expects, smoke-testing a Phase 4 workflow's `{{stageName.output}}` interpolation against real (if smaller) model output, or letting sugar-crush's own agents use themselves recursively while building sugar-crush — dogfooding the tool inside its own development loop.

```json
{
  "providers": {
    "dev-sglang": {
      "type": "sglang",
      "baseUrl": "https://skynet2.interserver.net/v1",
      "model": "MiniMax-M2.7",
      "apiKey": null
    }
  },
  "defaultProvider": "dev-sglang"
}
```

This slots into the existing `ProviderInterface` factory the same way any other SGLang-backed endpoint would — no new provider type, just a config entry pointing at this instance. It's the recommended default for the `AgentPoolConfig`, `TeamConfig`, and `WorkflowEngine` test fixtures throughout this plan wherever a real, non-echo completion is needed to validate behavior, reserving `EchoProvider` for the narrower case of testing the TUI/rendering layer with zero network dependency at all, and reserving the paid providers (OpenAI, Anthropic, Bedrock, Vertex) for acceptance tests that specifically need to validate cross-provider behavior before a release.

### Step Manifest

This is the first real step of Phase 0, continued below in the Agent Preset Configuration Schema section (steps P0.S2 onward). Full detail for the step below lives in this section's prose and JSON example above.

| Step ID | Title | Files | Depends On | Where to look |
|---|---|---|---|---|
| P0.S1 | Wire dev-sglang provider config | `.sugar-crush/config.dev.json` (or the project's existing provider config file — Builder Agent should locate the existing provider config format used by `ProviderInterface`'s factory before adding this entry) | — | This section's JSON example |

---

## Agent Preset Configuration Schema

Every phase below assumes sub-agents and teammates are built from *presets* rather than one-off constructor calls scattered through `AgentManager`. Formalizing that preset shape early means Phase 1's worker pool, Phase 2's teammates, and Phase 4's workflow stages can all consume the exact same definition instead of three slightly different ad hoc structs.

A preset is a small Markdown file with YAML frontmatter, stored under `.sugar-crush/agents/<name>.md` (project-scoped, committed) or `~/.sugar-crush/agents/<name>.md` (user-scoped), parsed into a readonly `AgentPreset` DTO the same way `Style` and other immutable value objects are built elsewhere in the codebase.

```yaml
---
name: security-auditor
description: Reviews a diff or directory for OWASP-class issues; use before merging anything touching auth, input parsing, or SQL.
tools: [Read, Grep, Glob, Bash]
disallowedTools: [Write, Edit]
model: sonnet
permissionMode: plan
maxTurns: 25
skills: [security-audit, php-best-practices]
mcpServers: [git]
memory: project
effort: high
isolation: worktree
color: red
---
```

Field-by-field:

- `name` — unique, lowercase-and-hyphens identifier; colons are reserved for plugin-namespaced presets (`sugarcraft:security-auditor`).
- `description` — the text the lead/orchestrator matches against when deciding whether to delegate to this preset; needs to read like a trigger condition, not a title.
- `tools` / `disallowedTools` — an explicit allowlist (defaults to the full built-in tool set when omitted) plus a subtractive list layered on top, so "everything except Write and Edit" doesn't require enumerating every remaining tool.
- `model` — a shorthand (`sonnet`, `opus`, `haiku`) or `inherit` to match whatever model the parent session is running, useful when a teammate should stay on the same model tier as the lead without hardcoding it.
- `permissionMode` — one of the six modes documented below; a security-review preset defaulting to `plan` means it can read and grep freely but never edits anything even if the parent session is running in `acceptEdits`.
- `maxTurns` — hard stop on agentic turns, independent of any timeout; prevents a preset from looping indefinitely even when individual tool calls are fast.
- `skills` — preloaded at spawn time rather than left for auto-discovery, cutting the latency of the preset's first turn.
- `mcpServers` — narrows which configured MCP servers this preset can see; omitted means it inherits the full set, which is rarely what you want for anything touching untrusted external tools.
- `memory` — `user`, `project`, or `local`, controlling which `MemoryStore` scope (Phase 6) the preset reads from and writes learnings back to.
- `background` — when true, this preset always runs as a background task rather than blocking the spawning turn.
- `effort` — reasoning/iteration intensity from `low` to `max`, letting a cheap lint-fixer preset and an expensive architecture-review preset coexist without a global setting forcing them to the same tier.
- `isolation` — `worktree` routes this preset through Phase 3's `WorktreeManager` automatically; omitted means it shares the caller's working directory.
- `color` — display color in the Agent View panel (Phase 5).
- `initialPrompt` — auto-submitted as the preset's first turn when spawned directly as a named session rather than delegated to mid-conversation.

### New Classes

```php
namespace SugarCraft\Crush\Agents;

final class AgentPreset
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $tools = [],
        public readonly array $disallowedTools = [],
        public readonly string $model = 'inherit',
        public readonly PermissionMode $permissionMode = PermissionMode::Default,
        public readonly ?int $maxTurns = null,
        public readonly array $skills = [],
        public readonly array $mcpServers = [],
        public readonly MemoryScope $memory = MemoryScope::User,
        public readonly bool $background = false,
        public readonly Effort $effort = Effort::Medium,
        public readonly ?Isolation $isolation = null,
        public readonly ?string $color = null,
        public readonly ?string $initialPrompt = null,
    ) {}
}

final class AgentPresetRegistry
{
    public function __construct(private readonly array $searchPaths) {}

    public function load(string $name): AgentPreset;
    public function list(): array;
    public function resolve(string $taskDescription): ?AgentPreset; // description matching for auto-delegation
}
```

### Files to Create
- `src/Agents/AgentPreset.php`
- `src/Agents/AgentPresetRegistry.php`
- `src/Agents/Effort.php` (enum: Low, Medium, High, XHigh, Max)
- `src/Agents/MemoryScope.php` (enum: User, Project, Local)
- `src/Agents/Isolation.php` (enum: None, Worktree)

### Step Manifest

Continues Phase 0's numbering from P0.S1 above. `PermissionMode` is created here as a bare enum only (just the six cases, no gating logic yet) because `AgentPreset` needs to type against it immediately; the full `PermissionGate`/`SafetyClassifier` logic that actually uses this enum is built later in Phase 2B.

| Step ID | Title | Files | Depends On | Where to look |
|---|---|---|---|---|
| P0.S2 | PermissionMode enum (bare, 6 cases only) | `src/Permissions/PermissionMode.php` | — | "Permission Modes and Hook Lifecycle" section, first PHP code block (just the enum, ignore PermissionGate/PermissionRule in that block for now) |
| P0.S3 | Effort enum | `src/Agents/Effort.php` | — | "Files to Create" list above |
| P0.S4 | MemoryScope enum | `src/Agents/MemoryScope.php` | — | "Files to Create" list above |
| P0.S5 | Isolation enum | `src/Agents/Isolation.php` | — | "Files to Create" list above |
| P0.S6 | AgentPreset DTO | `src/Agents/AgentPreset.php` | P0.S2, P0.S3, P0.S4, P0.S5 | Field-by-field list + `AgentPreset` PHP code block, this section |
| P0.S7 | AgentPresetRegistry | `src/Agents/AgentPresetRegistry.php` | P0.S6 | `AgentPresetRegistry` PHP code block, this section |
| P0.S8 | Example presets + registry tests | `.sugar-crush/agents/security-auditor.md`, at least 2 more example preset files, `tests/Agents/AgentPresetRegistryTest.php` | P0.S7 | YAML example in this section; Builder Agent should invent 2 more plausible presets (e.g. a `coder` and a `reviewer`) consistent with the field list |

---

## Phase 1: Parallel Agent Execution Engine

### Goal
Replace sequential Generator-based sub-agent execution with concurrent parallel execution supporting up to 5 agents simultaneously.

### Why Sequential Execution is a Bottleneck

The current implementation in `src/Agents/AgentManager.php` uses PHP Generators to iterate through agents one at a time. Each agent must complete fully before the next agent starts. This creates several critical bottlenecks:

First, when analyzing a large codebase, you might want to explore multiple directories in parallel. With sequential execution, exploring the frontend, backend, and database layers happens one after another instead of simultaneously. A task that could complete in 2 minutes with parallel execution takes 6 minutes sequentially.

Second, while waiting for an LLM response, the current code blocks. With multiple agents, one agent can be waiting for its LLM response while another agent is processing a tool result. This overlap is impossible with sequential execution.

Third, when one agent encounters an error, it can block the entire pipeline. With parallel execution, one agent's failure doesn't necessarily stop others.

Fourth, resource utilization is poor. Modern computers have multiple CPU cores, but sequential execution uses only one. Parallel execution can utilize all cores simultaneously.

### Current State

`src/Agents/AgentManager.php`:
The executeSubAgent method uses a simple foreach loop that yields results one at a time. There is no concept of concurrent execution, worker pools, or backpressure management.

### Target State

The new system uses a worker pool pattern where agents are scheduled onto workers that can execute in parallel. The pool enforces a maximum concurrency limit, typically 5 agents at once, which balances throughput with system resource usage.

### New Classes to Create

#### 1. `src/Agents/AgentWorkerPool.php`
```php
namespace SugarCraft\Crush\Agents;

final class AgentWorkerPool
{
    public function __construct(
        private readonly int $maxConcurrent = 5,
        private readonly ?ExecutorInterface $executor = null,
    ) {}

    public function executeAll(array $agents, CompleteRequest $request): \Generator;
    public function executeOne(SubAgent $agent, CompleteRequest $request): AgentResult;
    public function getActiveCount(): int;
    public function getQueueSize(): int;
    public function cancel(string $agentId): void;
    public function cancelAll(): void;
}
```

#### 2. `src/Agents/AgentResult.php`
```php
namespace SugarCraft\Crush\Agents;

final class AgentResult
{
    public function __construct(
        public readonly string $agentId,
        public readonly AgentStatus $status,
        public readonly ?string $output = null,
        public readonly ?\Throwable $error = null,
        public readonly int $tokensUsed = 0,
        public readonly float $costUsd = 0.0,
        public readonly ?\DateTimeImmutable $startedAt = null,
        public readonly ?\DateTimeImmutable $completedAt = null,
    ) {}

    public function isSuccess(): bool;
    public function isFailure(): bool;
    public function durationMs(): int;
}
```

#### 3. `src/Agents/AgentStatus.php`
```php
namespace SugarCraft\Crush\Agents;

enum AgentStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Running = 'running';
    case Streaming = 'streaming';
    case Completed = 'completed';
    case Failed = 'failed';
    case Stopped = 'stopped';
    case TimedOut = 'timed_out';
}
```

#### 4. `src/Agents/ExecutorInterface.php`
```php
namespace SugarCraft\Crush\Agents;

interface ExecutorInterface
{
    public function execute(SubAgent $agent, CompleteRequest $request): AgentResult;
    public function executeStream(SubAgent $agent, CompleteRequest $request): \Generator;
    public function cancel(string $agentId): void;
    public function cancelAll(): void;
}
```

#### 5. `src/Agents/ProcessExecutor.php`
Process-based executor using `proc_open()` for true parallelism.
```php
final class ProcessExecutor implements ExecutorInterface
{
    public function __construct(
        private readonly string $binaryPath,
        private readonly ?int $timeoutSeconds = 300,
    ) {}
}
```

### Files to Modify

| File | Change |
|---|---|
| `src/Agents/AgentManager.php` | Add `executeAll()` method, keep `executeSubAgent()` for backwards compat |
| `src/Agents/SubAgent.php` | Add `timeout`, `maxRetries`, `isolation` fields |
| `src/Chat.php` | Wire in `AgentWorkerPool` instead of direct `AgentManager` calls |
| `composer.json` | No new deps if using `proc_open` |

### Execution Strategy Deep Dive

**Option A: Process Pool (Recommended)**

This approach forks separate PHP processes for each agent using the proc_open function. Each agent runs in complete isolation with its own memory space, meaning a crash in one agent cannot bring down others.

The parent process acts as a coordinator, managing a pool of worker processes. Communication happens through standard input and output streams using JSON messages. The parent sends configuration and context to each worker, the worker executes the agent, and streams results back.

Advantages: True parallelism using multiple CPU cores. Complete fault isolation. If an agent causes a segfault or runs away with memory, only that worker process is affected. The parent can detect this and restart the worker if needed.

Disadvantages: Higher overhead from process creation (roughly 50-100ms per worker). JSON serialization and deserialization on both ends adds latency. Memory is not shared, so large context must be copied to each worker.

**Option B: Async/Event Loop**

This approach uses an event loop (typically ReactPHP) to run multiple agents cooperatively within a single process. Rather than true parallelism, agents yield control when waiting for I/O and the event loop schedules their execution.

The event loop monitors multiple pending operations (LLM requests, file I/O) and resumes agents when their operations complete. This is similar to how Node.js handles async operations.

Advantages: Much lower overhead than process pool (nearly zero for context switching). Lower memory footprint since all agents share the same memory space. Easier to share state between agents.

Disadvantages: A bug in one agent can corrupt shared state affecting all agents. If any agent hits an infinite loop, it can block the entire event loop. True parallelism is not achieved - only one agent executes at a time during CPU-bound work.

**Option C: Hybrid**

This approach combines process pool for CPU-intensive work with async for communication-heavy coordination. Agents themselves run in separate processes, but inter-agent communication (mailbox, task list) uses async messaging within the parent process.

This is the most complex option but provides the best of both worlds: fault isolation for agents plus efficient coordination messaging.

Recommended default is Option A (Process Pool) for its simplicity and fault isolation properties. Option B can be used when system resources are limited or when tight integration between agents is required.

### IPC Protocol Design

When using the Process Pool approach, parent and worker processes communicate through stdin and stdout streams using JSON messages. Each message is a single line terminated by a newline character, making parsing straightforward.

The protocol works as follows: The parent process spawns a worker with a startup message containing the agent configuration and initial context. The worker acknowledges with a ready message. Then the parent sends an execute message with the full request. The worker responds with streaming messages as it processes, then a final complete or error message.

Message types from worker to parent include: ready (worker initialized), streaming (partial output), tool_call (agent wants to run a tool), progress (status update with percentage), complete (final result with output and metrics), error (something went wrong with error details).

Message types from parent to worker include: execute (start processing), cancel (stop processing), heartbeat_response (acknowledge heartbeat).

A heartbeat mechanism ensures workers that crash or hang are detected. The worker sends a heartbeat message every 5 seconds while active. If the parent does not receive a heartbeat for 15 seconds, it considers the worker dead and kills it, then optionally restarts a new worker.

Error conditions are serialized as JSON and sent through the normal output stream. The error message includes the error type, message, and stack trace if available. The parent can then decide whether to retry the agent or mark it as failed.

### Worker Pool Sizing

Determining the optimal number of concurrent agents requires balancing throughput against system resources. The key factors are CPU cores, memory per agent, and the nature of the agent work.

For CPU-bound agents (agents doing heavy computation, code generation with large context), a good starting point is to set maxConcurrent to the number of CPU cores divided by 2. This leaves headroom for the operating system and avoids thrashing from too many processes competing for CPU time. For example, an 8-core machine would run 4 concurrent agents.

For I/O-bound agents (agents waiting for LLM responses, file operations), more concurrency is possible because agents spend most of their time waiting rather than using CPU. A safe default is 5 agents regardless of cores, which matches Claude Code's default and provides good throughput without overwhelming system resources.

Memory estimation per agent requires considering: the LLM context window (which gets copied to the worker), the agent's conversation history, and temporary buffers for file operations. A typical PHP agent using an 8K context window might need 100-200MB of memory per worker. On a system with 8GB of total memory, leaving 2GB for the operating system and parent process means 6GB available, supporting roughly 30-60 concurrent agents at the conservative estimate.

The pool can be configured dynamically based on detected resources. On startup, the system can query CPU core count and available memory to set sensible defaults. A formula approach: min(maxConcurrent setting from config, floor(availableMemoryBytes / 200MB), cpuCores). This prevents both memory exhaustion and CPU thrashing.

### Error Handling Strategy

When a child process crashes or behaves unexpectedly, the parent must handle it gracefully without losing the overall workflow.

Segfault handling: When a PHP process crashes with a segfault, the proc_open return code indicates failure. The parent detects this immediately on the next read or wait operation. The agent is marked as failed with a specific error type indicating crash, and the error message includes whatever output was captured before the crash. The worker process is discarded and a new worker can be spawned for the next task.

Zombie process prevention: When a child terminates, it becomes a zombie until the parent calls wait() to collect its exit status. The parent must do this promptly to prevent zombie accumulation. Using proc_terminate() followed by proc_close() ensures the process is properly cleaned up. The parent also registers a signal handler for SIGCHLD to be notified when children exit so it can clean up immediately.

Timeout escalation: When an agent exceeds its timeout, the parent sends a SIGTERM signal giving the agent 5 seconds to clean up gracefully. If it does not terminate, SIGKILL is sent to forcefully terminate it. This two-phase approach allows agents to write checkpoint data if needed while still allowing force-kill of runaway processes.

Recovery options: After a worker crash, the parent can either restart a fresh worker immediately or wait until the next task is scheduled. The configuration controls whether to retry a failed task with a new worker or mark it as permanently failed. Maximum retry count is tracked to prevent infinite retry loops.

Partial results: If an agent was producing streaming output before it failed, the partial output is preserved and returned to the caller. The caller can decide whether partial results are useful or whether the task must be completely re-run.

### Backpressure Handling

When the incoming task rate exceeds the pool's processing capacity, backpressure mechanisms prevent memory exhaustion and maintain system stability.

Queue overflow protection: The task queue has a maximum size configured separately from maxConcurrent. When a new task arrives and the queue is full, the system can either block the caller until space becomes available, reject the task immediately with an error, or apply a timeout after which the task is rejected. The default behavior is to block with a 30-second timeout.

Memory pressure detection: The parent process monitors total memory usage across all workers. If memory usage exceeds 80% of available system memory, new task scheduling is paused until workers complete and memory is freed. This prevents the system from entering swap death where it becomes unresponsive due to disk thrashing.

Worker saturation signaling: Each worker reports its current utilization level back to the parent during heartbeat messages. This allows the parent to make smarter routing decisions, sending lighter tasks to heavily loaded workers and heavier tasks to idle workers when possible.

Load shedding strategy: Under extreme load, the system can shed load by rejecting new tasks entirely rather than queuing them indefinitely. This maintains responsiveness for tasks already in progress. A circuit breaker pattern tracks recent rejection rates and opens the circuit (rejects all new tasks) if the rejection rate exceeds a threshold, then closes it again when the system stabilizes.

### Configuration
```php
final class AgentPoolConfig
{
    public function __construct(
        public readonly int $maxConcurrent = 5,
        public readonly int $defaultTimeoutSeconds = 300,
        public readonly int $maxRetries = 2,
        public readonly bool $stopOnFirstFailure = false,
        public readonly ExecutorType $executorType = ExecutorType::Process,
    ) {}
}
```

### Acceptance Criteria
1. Can launch 5 agents concurrently
2. Each agent executes in isolated context
3. Results returned as they complete (not all-or-nothing)
4. Timeout per agent (default 5 min)
5. Cancellation support
6. Existing `executeSubAgent()` tests still pass
7. No new required dependencies

### Step Manifest

P1.S5 is split into two steps because `ProcessExecutor` bundles two genuinely separate concerns (spawning/running a process, and the heartbeat/timeout/crash-recovery machinery around it) — trying to review both at once is exactly the kind of oversized step this protocol is meant to avoid.

| Step ID | Title | Files | Depends On | Where to look |
|---|---|---|---|---|
| P1.S1 | AgentStatus enum | `src/Agents/AgentStatus.php`, `tests/Agents/AgentStatusTest.php` | — | `#### 3. AgentStatus.php` |
| P1.S2 | AgentResult class | `src/Agents/AgentResult.php`, `tests/Agents/AgentResultTest.php` | P1.S1 | `#### 2. AgentResult.php` |
| P1.S3 | ExecutorInterface | `src/Agents/ExecutorInterface.php` | P1.S2 | `#### 4. ExecutorInterface.php` |
| P1.S4 | AgentPoolConfig | `src/Agents/AgentPoolConfig.php`, `tests/Agents/AgentPoolConfigTest.php` | — | "### Configuration" code block, this phase |
| P1.S5 | ProcessExecutor — spawn and run | `src/Agents/ProcessExecutor.php` (basic execute/executeStream, no heartbeat yet) | P1.S3 | `#### 5. ProcessExecutor.php` + "### IPC Protocol Design" |
| P1.S6 | ProcessExecutor — heartbeat, timeout escalation, crash recovery | `src/Agents/ProcessExecutor.php` (extend), `tests/Agents/ProcessExecutorTest.php` | P1.S5 | "### Error Handling Strategy" + "### Backpressure Handling" |
| P1.S7 | AgentWorkerPool | `src/Agents/AgentWorkerPool.php`, `tests/Agents/AgentWorkerPoolTest.php` (the 5 cases named in Testing Strategy) | P1.S4, P1.S6 | `#### 1. AgentWorkerPool.php` |
| P1.S8 | Modify AgentManager.php: add `executeAll()` | `src/Agents/AgentManager.php` (modify) | P1.S7 | "### Files to Modify" table, this phase |
| P1.S9 | Modify SubAgent.php: timeout/maxRetries/isolation fields | `src/Agents/SubAgent.php` (modify) | P1.S7 | "### Files to Modify" table, this phase |
| P1.S10 | Wire AgentWorkerPool into Chat.php | `src/Chat.php` (modify) | P1.S8 | "### Files to Modify" table, this phase |

---

## Phase 2: Agent Teams Architecture

### Goal
Implement the lead + teammates model with shared task list, atomic task claiming, and inter-agent messaging via mailbox system.

### Team Model Overview

The team model implements a lead plus teammates pattern where one designated lead agent coordinates the work while teammate agents execute tasks in parallel. This mirrors how a human software team would work: a tech lead breaks down the project and assigns pieces, developers work on their pieces simultaneously, and the lead integrates the results.

The lead agent runs in the main session and maintains the overall context of the project. Teammates are spawned as independent agents, each with their own isolated context and worktree. The lead decides when to spawn teammates, what tasks to assign, and when to synthesize results.

Communication happens through two channels: a shared task list where tasks are posted and claimed, and a mailbox system for direct messages between agents. The lead can send specific instructions to a teammate, teammates can notify the lead when they complete a task or encounter a blocker, and teammates can occasionally communicate directly with each other when their tasks intersect.

Task lifecycle: A task is created by the lead (or automatically by a workflow) and enters the pending state. Teammates can see all pending tasks and claim ones that match their capabilities. Once claimed, the task moves to in_progress. When completed, the task result is stored and the status changes to completed. If a task fails, it moves to failed and can optionally be retried or reassigned.

### Overview
```
Main Session (Lead Agent)
  ├── Teammate: coder (独立Claude Code session)
  ├── Teammate: reviewer (独立Claude Code session)
  ├── Teammate: tester (独立Claude Code session)
  └── Shared Task List (SQLite + file locking)
```

### New Classes

#### 1. `src/Agents/Team.php`
```php
namespace SugarCraft\Crush\Agents;

final class Team
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $leadAgentId,
        public readonly \DateTimeImmutable $createdAt,
    ) {}

    /** @return Teammate[] */
    public function getTeammates(): array;
    public function addTeammate(Teammate $teammate): void;
    public function removeTeammate(string $teammateId): void;
    public function getTaskList(): TaskList;
    public function getMailbox(): Mailbox;
}
```

#### 2. `src/Agents/Teammate.php`
```php
namespace SugarCraft\Crush\Agents;

final class Teammate
{
    public function __construct(
        public readonly string $id,
        public readonly string $teamId,
        public readonly string $name,
        public readonly AgentType $type,
        public readonly string $model,
        public readonly array $tools,
        public readonly ?string $worktreePath = null,
        public readonly ?string $branch = null,
    ) {}

    public function getInboxPath(): string;
    public function getStatus(): TeammateStatus;
}
```

#### 3. `src/Agents/TeammateStatus.php`
```php
enum TeammateStatus: string
{
    case Idle = 'idle';
    case Active = 'active';
    case Waiting = 'waiting';
    case Completed = 'completed';
    case Failed = 'failed';
    case Interrupted = 'interrupted';
}
```

#### 4. `src/Agents/TaskList.php`
```php
namespace SugarCraft\Crush\Agents;

final class TaskList
{
    public function __construct(
        private readonly string $dbPath,  // SQLite
    ) {}

    public function addTask(Task $task): string;  // returns taskId
    public function claimTask(string $taskId, string $teammateId): bool;  // atomic
    public function updateTaskStatus(string $taskId, TaskStatus $status): void;
    public function completeTask(string $taskId, string $result): void;
    public function failTask(string $taskId, string $error): void;

    /** @return Task[] */
    public function getPendingTasks(): array;
    public function getTasksByStatus(TaskStatus $status): array;
    public function getTasksForTeammate(string $teammateId): array;
    public function getTask(string $taskId): ?Task;

    public function addDependency(string $taskId, string $dependsOn): void;
    public function getUnblockedTasks(string $teammateId): array;
}
```

#### 5. `src/Agents/Task.php`
```php
namespace SugarCraft\Crush\Agents;

final class Task
{
    public function __construct(
        public readonly string $id,
        public readonly string $teamId,
        public readonly string $title,
        public readonly string $description,
        public readonly string $prompt,
        public readonly ?string $assignedTo = null,
        public readonly TaskStatus $status = TaskStatus::Pending,
        public readonly ?string $result = null,
        public readonly ?string $error = null,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $claimedAt = null,
        public readonly ?\DateTimeImmutable $completedAt = null,
        /** @var string[] */
        public readonly array $dependsOn = [],
    ) {}
}
```

#### 6. `src/Agents/TaskStatus.php`
```php
enum TaskStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Failed = 'failed';
    case Blocked = 'blocked';
}
```

#### 7. `src/Agents/Mailbox.php`
```php
namespace SugarCraft\Crush\Agents;

final class Mailbox
{
    public function __construct(
        private readonly string $basePath,  // ~/.sugar-crush/teams/{team}/inboxes/
    ) {}

    public function send(string $fromTeammateId, string $toTeammateId, Message $message): void;
    public function receive(string $teammateId): \Generator;  // yields messages
    public function peek(string $teammateId): array;
    public function markRead(string $teammateId, string $messageId): void;
    public function getUnreadCount(string $teammateId): int;
}
```

#### 8. `src/Agents/TeamMessage.php`
```php
namespace SugarCraft\Crush\Agents;

final class TeamMessage
{
    public function __construct(
        public readonly string $id,
        public readonly string $fromTeammateId,
        public readonly string $toTeammateId,
        public readonly string $type,  // 'task_assigned'|'task_result'|'idle'|'error'
        public readonly array $payload,
        public readonly \DateTimeImmutable $sentAt,
        public readonly bool $read = false,
    ) {}
}
```

### Files to Create

| File | Purpose |
|---|---|
| `src/Agents/Team.php` | Team aggregate root |
| `src/Agents/Teammate.php` | Teammate entity |
| `src/Agents/TeammateStatus.php` | Teammate state enum |
| `src/Agents/TaskList.php` | Task management with SQLite |
| `src/Agents/Task.php` | Task entity |
| `src/Agents/TaskStatus.php` | Task status enum |
| `src/Agents/Mailbox.php` | Inter-agent messaging |
| `src/Agents/TeamMessage.php` | Message value object |
| `src/Agents/TeamManager.php` | Creates/manages teams |

### Files to Modify

| File | Change |
|---|---|
| `src/Agents/AgentManager.php` | Add team management methods |
| `src/Agents/SubAgent.php` | Add team-related fields |
| `composer.json` | Add `ext-sqlite3` check |

### Task Claiming Algorithm

The task claiming process must be atomic to prevent two teammates from claiming the same task simultaneously. This is achieved using file-based locking at the filesystem level.

When a teammate wants to claim a task, it opens a lock file specifically for that task ID. The lock file is created if it does not exist. The teammate acquires an exclusive lock using flock(), which blocks until the lock is available.

Once holding the lock, the teammate performs several checks. First, it verifies the task still exists and is in pending status. If another teammate already claimed it, the task would no longer be pending. Second, it checks that all task dependencies have been completed. A task that depends on another task cannot be claimed until its dependency finishes. Third, it verifies the task is assigned to this teammate or is unassigned (nil/empty assignment means anyone can claim).

If all checks pass, the task status is updated to in_progress and the teammate ID is recorded as the assignee. The lock is then released. The entire operation happens within the lock's critical section, ensuring no other claim can interleave.

If any check fails, the lock is released immediately and the claim is rejected. The teammate can then try a different task.

The lock file itself is stored alongside the SQLite database, one lock file per task. Lock files are cleaned up when their associated task is completed or when the team is dissolved.

### Inter-Agent Messaging Flow

The mailbox system enables direct communication between teammates and between teammates and the lead agent. Messages are stored as JSON files in each teammate's inbox directory, with one file per message.

When a teammate completes a task, it sends a result message to the lead agent. This message includes the task ID that completed, the output generated, any files that were modified, and metadata like execution time and token usage. The lead receives this message on its next polling cycle or immediately if using event-driven wake-up.

When the lead assigns a new task, it sends a task_assigned message to the target teammate. This message includes the task description, any context from previous tasks, deadline if applicable, and priority level.

Teammates can also send peer-to-peer messages when their tasks intersect. For example, if the api-dev teammate needs input from the db-dev teammate about schema details, it can send a direct message. This avoids routing through the lead unnecessarily.

Idle notifications are sent automatically when a teammate has no pending tasks and has been idle for a configurable period. This tells the lead that the teammate is available for new work.

Error messages are sent when a teammate encounters a problem it cannot resolve, such as a missing dependency or conflicting change from another teammate. The lead can then intervene, perhaps reassigning tasks or providing additional context.

The lead processes incoming messages in priority order: errors first, then task results, then idle notifications, then peer messages. This ensures problems are addressed quickly while results are captured before assigning new work.

### Mailbox Durability and Delivery

Each teammate's inbox is a single append-only file — one JSON object per line — rather than a JSON array that gets rewritten on every send. That makes a send an O(1) append regardless of how many messages are already queued, and it means a crash mid-write can corrupt at most the last unfinished line rather than the whole inbox. Reading validates every line independently: a malformed line is reported and skipped rather than aborting the read, so one bad message can't take down delivery of everything after it.

Delivery is event-driven rather than polled. Writing to an inbox also touches a companion wake marker that the recipient's event loop watches, so an idle lead or teammate resumes the moment a message arrives instead of on the next polling tick. This matters most for the lead: with five teammates working in parallel, polling on a fixed interval means the lead is either checking too often (wasted cycles) or too rarely (teammates sit "done" for seconds before anyone notices).

Quality gates on team output are enforced through the `TeammateIdle`, `TaskCreated`, and `TaskCompleted` hook events (see Permission Modes and Hook Lifecycle below) rather than being baked into the mailbox itself — `TeammateIdle` can hand a teammate more work instead of letting it sit idle, `TaskCreated` can reject a task that duplicates in-flight work, and `TaskCompleted` can refuse to let a task close if it doesn't meet a project's definition of done.

### Configuration
```php
final class TeamConfig
{
    public function __construct(
        public readonly int $maxTeammates = 5,
        public readonly int $defaultTimeoutSeconds = 600,
        public readonly bool $allowPeerMessaging = true,
        public readonly bool $autoAssignTasks = true,
        public readonly string $inboxPath = '~/.sugar-crush/teams/',
    ) {}
}
```

### Acceptance Criteria
1. Can create a team with lead + up to 5 teammates
2. Tasks can be added to shared task list
3. Task claiming is atomic (no double-claiming)
4. Task dependencies block until prerequisite completes
5. Messages delivered between agents
6. Idle notifications when teammate completes
7. Team state persists across restarts

### Step Manifest

`TaskList` is split into two steps (P2.S7 core CRUD, P2.S8 the atomic claim + dependency logic) because the atomic-claiming code is the trickiest concurrency logic in this whole phase and deserves a review cycle entirely of its own rather than being reviewed alongside simpler CRUD methods.

| Step ID | Title | Files | Depends On | Where to look |
|---|---|---|---|---|
| P2.S1 | TeammateStatus enum | `src/Agents/TeammateStatus.php` | — | `#### 3. TeammateStatus.php` |
| P2.S2 | TaskStatus enum | `src/Agents/TaskStatus.php` | — | `#### 6. TaskStatus.php` |
| P2.S3 | Task DTO | `src/Agents/Task.php`, `tests/Agents/TaskTest.php` | P2.S2 | `#### 5. Task.php` |
| P2.S4 | Teammate entity | `src/Agents/Teammate.php`, `tests/Agents/TeammateTest.php` | P2.S1 | `#### 2. Teammate.php` |
| P2.S5 | TeamMessage value object | `src/Agents/TeamMessage.php` | — | `#### 8. TeamMessage.php` |
| P2.S6 | TeamConfig | `src/Agents/TeamConfig.php` | — | "### Configuration" code block, this phase |
| P2.S7 | TaskList — schema and core CRUD | `src/Agents/TaskList.php` (addTask, getPendingTasks, getTask, updateTaskStatus, completeTask, failTask) | P2.S3 | `#### 4. TaskList.php` |
| P2.S8 | TaskList — atomic claimTask() and dependency logic | `src/Agents/TaskList.php` (extend: claimTask, addDependency, getUnblockedTasks), `tests/Agents/TaskListTest.php` (the 5 cases named in Testing Strategy) | P2.S7 | "### Task Claiming Algorithm" |
| P2.S9 | Mailbox | `src/Agents/Mailbox.php`, `tests/Agents/MailboxTest.php` (the 4 cases named in Testing Strategy) | P2.S5 | `#### 7. Mailbox.php` + "### Mailbox Durability and Delivery" |
| P2.S10 | Team aggregate root | `src/Agents/Team.php` | P2.S4, P2.S8, P2.S9 | `#### 1. Team.php` |
| P2.S11 | TeamManager | `src/Agents/TeamManager.php` | P2.S6, P2.S10 | "### Files to Create" table, this phase |
| P2.S12 | Modify AgentManager.php: team management methods | `src/Agents/AgentManager.php` (modify) | P2.S11 | "### Files to Modify" table, this phase |
| P2.S13 | Modify SubAgent.php: team-related fields | `src/Agents/SubAgent.php` (modify) | P2.S11 | "### Files to Modify" table, this phase |
| P2.S14 | Integration: TeamLifecycleTest | `tests/Integration/TeamLifecycleTest.php` | P2.S12, P2.S13 | "### Integration Tests" section under Testing Strategy |

---

## Permission Modes and Hook Lifecycle

Sub-agent presets and the main session both need a policy for what happens when a tool call is about to touch the filesystem, the shell, or the network. Today that's entirely the hooks system's job; splitting it into a small set of named *modes* plus a documented *hook lifecycle* gives both a simpler default story (pick a mode) and a more powerful escape hatch (write a hook) without forcing every user into the hook-authoring workflow just to get sane defaults.

### Permission Modes

Six modes, ordered from most to least restrictive:

**Default (manual)** — the safest starting point. Every file edit, shell command, and network request prompts before running; only reads execute silently. Protected paths (anything matching secrets, `.env`, or other sensitive config) prompt even for reads.

**AcceptEdits** — reads, file edits, and common filesystem commands (`mkdir`, `touch`, `mv`, `cp`, `rm`, `rmdir`) run without prompting, scoped strictly to the working directory or any explicitly added directories. Commands prefixed with environment variables or wrapped in `timeout`/`nice`/`nohup` are treated as safe. Protected paths still prompt regardless of mode. This is the natural mode for iterative review sessions where the diff is being watched as it happens.

**Plan** — research and propose only. Reads and shell exploration run freely to build a plan, but no edit lands until the plan is approved. On approval the user picks what happens next: switch to `auto` for the rest of the task, drop into `acceptEdits`, stay in `default`, or keep planning. This is the mode a `security-auditor`-style preset (see the schema above) should default to — it can read and grep an entire lib but is structurally incapable of "fixing" what it finds mid-review.

**Auto** — everything runs, gated by a background safety classifier that reviews each action before it executes rather than prompting the user for each one. The classifier's block list is a fixed category set rather than a freeform judgment call: curl/wget-into-shell pipelines, sending data to unrecognized external endpoints, production deploys or migrations, mass deletion against cloud storage, granting IAM/repo permissions, deleting anything that existed before the session started, force-push or `reset --hard`, `terraform destroy`/`pulumi destroy`, opening PRs against a different repo or org than the one the session started in, posting automation comments, opening interactive shells or port-forwards to sensitive infrastructure, printing live credentials into the transcript, and routing package installs around an internal registry to a public one. If the classifier blocks the same action three times in a row, or twenty times total in a session, `auto` mode pauses itself and falls back to prompting — a runaway loop can't quietly keep hammering a blocked action forever.

**DontAsk** — auto-denies anything that would otherwise prompt; the session never blocks waiting on a human. Only pre-approved allowlist rules, read-only shell commands, and hook-approved calls execute. This is the mode for CI runners and scripted batch jobs where every permitted action needs to already be enumerated — it fails closed rather than open.

**BypassPermissions** — disables prompts and the safety classifier entirely. The only remaining guards are: explicit deny rules still deny, MCP tools flagged as requiring interaction still prompt, and a hardcoded circuit breaker refuses `rm -rf /` or `rm -rf ~` regardless of mode. Can only be set at launch, never switched into mid-session, and the first interactive use shows a one-time warning. This mode is for disposable containers and VMs only.

```php
namespace SugarCraft\Crush\Permissions;

enum PermissionMode: string
{
    case Default = 'default';
    case AcceptEdits = 'accept-edits';
    case Plan = 'plan';
    case Auto = 'auto';
    case DontAsk = 'dont-ask';
    case BypassPermissions = 'bypass-permissions';
}

final class PermissionGate
{
    public function __construct(
        private readonly PermissionMode $mode,
        /** @var PermissionRule[] */
        private readonly array $rules,
        private readonly ?SafetyClassifier $classifier = null,
    ) {}

    public function evaluate(ToolCall $call): PermissionDecision;
}

final class PermissionRule
{
    // e.g. Bash(composer update *), Read(./.env), mcp__git__*
    public function __construct(
        public readonly string $toolPattern,
        public readonly PermissionAction $action, // Allow | Deny | Ask
    ) {}
}
```

Rules follow a `ToolName(pattern)` syntax layered across three files with increasing specificity: `~/.sugar-crush/settings.json` (user-wide), `.sugar-crush/settings.json` (project, committed), and `.sugar-crush/settings.local.json` (project, gitignored, machine-specific) — the same three-tier precedence sugar-crush already uses for its own config discovery.

### Hook Lifecycle Events

Hooks remain the escape hatch for anything a mode can't express. Widening the event surface to a fixed, documented set makes hook authors' exit codes mean the same thing everywhere instead of each hook type inventing its own convention:

| Event | Fires | Typical use |
|---|---|---|
| `PreToolUse` | after the agent decides on a tool call, before it runs | validate, rewrite, or block the call |
| `PostToolUse` | after a tool call completes | audit results, or (via `continueOnBlock`) flag a problem without ending the turn |
| `Stop` | when the agent is about to stop | veto the stop and redirect with a reason |
| `SubagentStop` | when a sub-agent/teammate is about to stop | same idea, scoped to delegated work |
| `SessionStart` | at session boot | inject extra context, set a session title |
| `SessionEnd` | at session teardown | cleanup, logging, cost-summary export |
| `UserPromptSubmit` | before the user's prompt reaches the agent | validate or inject context; no matcher, since it isn't tool-scoped |
| `PreCompact` | before compaction runs | a `trigger` field distinguishes a manual `/compact` from the automatic threshold pass |
| `TeammateIdle` | a teammate is about to go idle with nothing left to do | hand it more work instead of letting it sit idle |
| `TaskCreated` | a task is being added to the shared task list | reject duplicate or out-of-scope task creation |
| `TaskCompleted` | a task is being marked complete | refuse the completion if it doesn't meet a defined bar, forcing more work before the status flips |

Exit codes carry the same three-way meaning across every event, but *where* the message lands differs. `0` allows the action (stdout may be shown to the user or folded into context depending on the event). `1` is a non-blocking deny — stderr is shown to the user but execution continues past that hook. `2` is a hard block, but its effect is event-specific: on `PreToolUse`/`Stop`/`TaskCreated` it stops the action outright and feeds stderr back to the agent so it can adjust; on `PostToolUse`/`SubagentStop`/`TaskCompleted` the action has already happened, so exit code 2 instead surfaces stderr back to the agent afterward — this is what `continueOnBlock` is for, letting a `PostToolUse` hook flag a problem with a completed edit without discarding it; on `Notification`/`PreCompact`/`SessionStart` stderr only ever reaches the user, never the agent, since there's nothing for the agent to act on; on `UserPromptSubmit`, exit code 2 discards the prompt entirely rather than passing anything to the agent.

This is a strict superset of the existing MODIFY-capable hook contract — `AuditHook`, `ConfirmRemoveHook`, `ProtectFilesHook`, and `ScriptHook` all keep working unchanged — it just gives every hook a documented home in the lifecycle instead of everything hanging off generic pre/post tool matchers.

### Files to Create
- `src/Permissions/PermissionMode.php`
- `src/Permissions/PermissionGate.php`
- `src/Permissions/PermissionRule.php`
- `src/Permissions/PermissionAction.php`
- `src/Permissions/SafetyClassifier.php`
- `src/Hooks/HookEvent.php` (enum of the eleven events above)
- `src/Hooks/HookDispatcher.php`
- `src/Hooks/HookDispatchResult.php` (required as return type and factory-method target by `HookDispatcher`)
- `tests/Hooks/HookDispatchResultTest.php`

### Files to Modify
- `src/Agents/AgentManager.php` — resolve each spawned agent's `PermissionGate` from its preset before wiring tools
- `src/Agents/TaskList.php` — invoke `TaskCreated`/`TaskCompleted` hooks around `addTask()`/`completeTask()`
- `src/Hooks/HookRegistry.php` — extend matcher/event vocabulary

### Acceptance Criteria
1. Default mode prompts on every write; nothing lands silently
2. AcceptEdits auto-approves scoped filesystem writes but still prompts outside the working directory
3. Plan mode blocks all edits regardless of what tools were requested
4. Auto mode's classifier blocks every listed dangerous-action category and pauses itself after repeated blocks
5. DontAsk denies anything not pre-approved, without ever blocking on user input
6. BypassPermissions cannot be entered mid-session and still refuses `rm -rf /` / `rm -rf ~`
7. `TeammateIdle`/`TaskCreated`/`TaskCompleted` hooks can redirect or reject team work
8. Exit code 2 on `PostToolUse` surfaces via `continueOnBlock` without discarding the completed action

### Step Manifest

The `PermissionMode` enum itself was already created in Phase 0 (step P0.S2) — the steps below build the actual gating logic that uses it.

| Step ID | Title | Files | Depends On | Where to look |
|---|---|---|---|---|
| P2B.S1 | PermissionRule + PermissionAction | `src/Permissions/PermissionRule.php`, `src/Permissions/PermissionAction.php` | P0.S2 | `PermissionRule` PHP code block, this section |
| P2B.S2 | PermissionGate core (Default, AcceptEdits, Plan modes) | `src/Permissions/PermissionGate.php` (evaluate() for the three non-classifier modes) | P2B.S1 | "**Default**", "**AcceptEdits**", "**Plan**" paragraphs + `PermissionGate` PHP code block |
| P2B.S3 | SafetyClassifier + Auto mode | `src/Permissions/SafetyClassifier.php`, `src/Permissions/PermissionGate.php` (extend: Auto mode) | P2B.S2 | "**Auto**" paragraph, this section |
| P2B.S4 | DontAsk + BypassPermissions modes + rm -rf circuit breaker | `src/Permissions/PermissionGate.php` (extend: DontAsk, BypassPermissions) | P2B.S3 | "**DontAsk**", "**BypassPermissions**" paragraphs |
| P2B.S5 | HookEvent enum + HookDispatcher core | `src/Hooks/HookEvent.php`, `src/Hooks/HookDispatcher.php` | — | "### Hook Lifecycle Events" table + exit-code paragraph |
| P2B.S6 | Wire TeammateIdle/TaskCreated/TaskCompleted into TaskList | `src/Agents/TaskList.php` (modify, from P2.S8) | P2.S8, P2B.S5 | "### Files to Modify" table, this section |
| P2B.S7 | Wire PermissionGate into AgentManager | `src/Agents/AgentManager.php` (modify, from P2.S12) | P2.S12, P2B.S4 | "### Files to Modify" table, this section |
| P2B.S8 | PermissionGateTest + HookDispatcherTest | `tests/Permissions/PermissionGateTest.php` (7 cases), `tests/Hooks/HookDispatcherTest.php` (5 cases) | P2B.S6, P2B.S7 | Testing Strategy section, "PermissionGateTest" and "HookDispatcherTest" blocks |

---

## Phase 3: Worktree Isolation

### Goal
Implement git worktree isolation per agent to prevent file conflicts during parallel execution.

### Why File Isolation Is Required

When multiple agents run in parallel and all operate on the same working directory, several problems arise that can corrupt the codebase or lose work.

First, race conditions on the same file: If two agents decide to edit the same file simultaneously, one agent's changes can overwrite the other's. Even with good merge strategies, there is no mechanism to detect this has happened until later.

Second, git command conflicts: When one agent runs git pull while another runs git commit, the operations can interfere with each other, potentially leaving the repository in an inconsistent state that requires manual recovery.

Third, build artifact conflicts: Multiple agents running npm build or composer install in the same directory can corrupt each other's dependency trees or generate conflicting artifacts.

Fourth, no rollback capability: If one agent's changes turn out to be problematic, it is difficult to disentangle those changes from other agents' work since everything is mixed together in the same checkout.

### Solution: Git Worktree Per Agent

The solution is to give each agent its own git worktree. Git worktrees allow multiple working directories to be checked out from the same repository, each on a different branch. This provides complete file system isolation between agents.

When an agent is spawned, a new worktree is created at a dedicated location (such as .sugar-crush/worktrees/agent-name/). The agent's worktree is checked out to a dedicated branch with a unique name based on the agent ID and timestamp.

All file operations for that agent are constrained to its worktree. Even if the agent's prompt contains path traversal attempts or misbehaving tools, the PathJail ensures files remain within the worktree boundaries.

When the agent completes, its changes can be merged back into the main branch, or discarded if they are not wanted. The worktree itself is then deleted, cleaning up the file system.

### New Classes

#### 1. WorktreeManager
Manages creation, deletion, and cleanup of git worktrees per agent.

Methods:
- createWorktree(string $agentId, ?string $branch = null): string - creates new worktree
- removeWorktree(string $agentId): void - removes worktree
- getWorktreePath(string $agentId): string - returns worktree path
- listWorktrees(): array - lists all managed worktrees
- cleanupStaleWorktrees(): int - removes orphaned worktrees

#### 2. WorktreeConfig
Configuration for worktree isolation per team/agent.

Fields:
- basePath: string - where worktrees are created (default: .sugar-crush/worktrees/)
- autoCleanup: bool - cleanup on team解散
- isolationMode: 'worktree'|'branch'|'path' (worktree is most isolated)

#### 3. PathJail
Extended path containment that routes file operations to agent worktree.

### Files to Create
- src/Agents/WorktreeManager.php
- src/Agents/WorktreeConfig.php
- src/Agents/PathJail.php (extends existing PathJail concept)

### Files to Modify
- src/Tools/BuiltIn/Edit.php - route to worktree path
- src/Tools/BuiltIn/Read.php - route to worktree path
- src/Tools/BuiltIn/Bash.php - constrain to worktree
- src/Agents/Teammate.php - add worktreePath field

### Implementation Details

Worktree creation per agent:
1. Agent starts with own branch: agent-{id}-{timestamp}
2. New worktree created at .sugar-crush/worktrees/{agentId}/
3. File operations Edit/Read/Glob/Grep jail to worktree path
4. Bash tool can access worktree but git commands affect only that worktree
5. On agent completion, worktree can be merged or discarded

Atomic task claiming also claims the worktree.

### Path Isolation Layer
final class PathJail
{
    public function __construct(
        private readonly string $agentWorktreePath,
        private readonly PathJailConfig $config,
    ) {}

    public function jailPath(string $path): string
    {
        // Prepend worktree path if not already absolute
        if (!str_starts_with($path, '/')) {
            return $this->agentWorktreePath . '/' . $path;
        }
        return $path;
    }

    public function isAllowed(string $path): bool
    {
        // Ensure path is within worktree
        return str_starts_with(realpath($path), $this->agentWorktreePath);
    }
}

### Worktree Include and Cleanup Policy

A freshly created worktree only carries whatever git itself tracks, which means anything covered by `.gitignore` — `.env`, per-lib composer auth tokens, locally generated `vendor/` — is missing by default. A teammate that immediately fails because its worktree has no `.env` is a bad first impression. A `.worktreeinclude` file, using the same glob syntax as `.gitignore`, lists exactly which normally-ignored paths should still be copied into every new worktree at creation time, so an agent working in `.sugar-crush/worktrees/{agentId}/` has the same local configuration the main checkout does.

Cleanup follows the worktree's naming, not a single blanket policy. A worktree created for a *named* task or session — the kind a human explicitly asked for and might want to inspect afterward — prompts before removal rather than disappearing silently. A worktree spun up for an *unnamed*, ephemeral sub-agent run is removed automatically as soon as that run ends cleanly (no uncommitted diff left behind); if it does have uncommitted changes, it's left alone so nothing gets lost. On top of both, a periodic sweep — the same age-based pruning approach sugar-crush's SQLite session store already uses — removes worktrees older than a configurable `worktreeCleanupPeriodDays`, catching anything abandoned mid-task that neither of the two immediate-cleanup paths caught.

For CI runners or hosts where dozens of parallel worktrees would otherwise bloat the primary checkout's disk, a `SUGAR_CRUSH_WORKTREES_DIR` environment variable relocates the entire `.sugar-crush/worktrees/` tree off the repo disk without changing any agent-facing behavior.

Files to modify, continued:
- `src/Agents/WorktreeManager.php` — add `.worktreeinclude` resolution and the two-tier (named/unnamed) cleanup policy
- `.sugar-crush/config.json` — new `worktreeCleanupPeriodDays` and `worktreeIncludeFile` settings

### Acceptance Criteria
1. Each agent gets isolated worktree on spawn
2. File edits in one worktree do not affect others
3. PathJail prevents accidental access outside worktree
4. Worktrees cleaned up on team completion
5. Can merge worktree changes back to main branch
6. Conflict detection when worktrees modify same files

### Step Manifest

| Step ID | Title | Files | Depends On | Where to look |
|---|---|---|---|---|
| P3.S1 | WorktreeConfig | `src/Agents/WorktreeConfig.php` | — | `#### 2. WorktreeConfig`, this phase |
| P3.S2 | WorktreeManager — core create/remove/list | `src/Agents/WorktreeManager.php` (createWorktree, removeWorktree, getWorktreePath, listWorktrees) | P3.S1 | `#### 1. WorktreeManager`, this phase |
| P3.S3 | WorktreeManager — .worktreeinclude + cleanup policy + sweep | `src/Agents/WorktreeManager.php` (extend: cleanupStaleWorktrees, .worktreeinclude resolution, named/unnamed policy) | P3.S2 | "### Worktree Include and Cleanup Policy" |
| P3.S4 | PathJail | `src/Agents/PathJail.php`, `tests/Agents/PathJailTest.php` | — | "### Path Isolation Layer" PHP code block |
| P3.S5 | Route Edit/Read/Bash tools through PathJail | `src/Tools/BuiltIn/Edit.php`, `src/Tools/BuiltIn/Read.php`, `src/Tools/BuiltIn/Bash.php` (all modify) | P3.S4 | "### Files to Modify" list, this phase |
| P3.S6 | Modify Teammate.php: worktreePath field + wire into task claiming | `src/Agents/Teammate.php` (modify) | P3.S3, P2.S8 | "### Files to Modify" list, this phase; "Atomic task claiming also claims the worktree" note |
| P3.S7 | WorktreeManagerTest | `tests/Agents/WorktreeManagerTest.php` (the 4 cases named in Testing Strategy) | P3.S3 | Testing Strategy section, "WorktreeManagerTest" block |

---

## Phase 4: Dynamic Workflow Orchestration

### Goal
Implement a workflow system for scripted orchestration of multiple agents, supporting parallel execution, pipelines, verification phases, and workflows with up to 5-16 concurrent agents.

### Why Workflows Matter

Manual multi-agent coordination works well for simple cases, but complex projects need repeatable, documented processes. Workflows provide this by encoding the coordination logic in a declarative or programmatic format.

A refactoring project might need: an architect agent to analyze the current code and design the new structure, multiple coder agents to implement different pieces in parallel, a reviewer agent to verify correctness, a tester agent to run and verify tests, and finally a scribe agent to update documentation. Doing this manually requires the human to track what stage each piece is at and manually assign new tasks at the right time. A workflow encodes this entire process.

Workflows also provide auditability and reproducibility. You can see exactly what happened in a previous run by looking at the workflow history. You can rerun a failed workflow from the beginning or from a checkpoint. You can share the workflow definition with your team so everyone knows the standard process.

### Core Concepts

A workflow consists of stages that execute in order, with support for parallel execution within a stage. Each stage declares an agent type, a prompt template, and what tools the agent can use.

The parallel primitive allows multiple agents to run concurrently within a stage. All agents in a parallel stage must complete before the next stage begins. This is useful for fan-out research or implementing multiple features simultaneously.

The pipeline primitive chains stages where each stage receives the output from the previous stage as input. This is useful for data transformation pipelines where one agent's output feeds directly into the next agent's input.

The verification primitive runs a second agent to check the first agent's work. If the verification agent finds problems, the workflow can automatically retry or escalate to the lead agent.

### Workflow Definition Format

#### Option A: PHP DSL (Recommended for type safety)
```php
<?php
// workflows/RefactorService.php

use SugarCraft\Crush\Workflows\Workflow;
use SugarCraft\Crush\Workflows\Tasks;

return new Workflow(
    name: 'refactor-service',
    description: 'Refactor a microservice with tests and docs',
    
    build: fn(WorkflowBuilder $b) => $b
        ->stage('analyze', Tasks::agent('architect')
            ->prompt('Analyze {{service}} for refactoring opportunities')
            ->tools([Read, Grep, Glob]))
        
        ->parallel('implement', [
            Tasks::agent('coder', 'implement-api')
                ->prompt('Implement API changes for {{service}}'),
            Tasks::agent('coder', 'implement-tests')
                ->prompt('Write tests for {{service}}'),
        ])
        
        ->stage('verify', Tasks::agent('reviewer')
            ->prompt('Review implementation, find bugs'))
        
        ->stage('fix', Tasks::agent('coder')
            ->prompt('Fix bugs found: {{verify.bugs}}'))
        
        ->stage('docs', Tasks::agent('scribe')
            ->prompt('Update docs for {{service}}'))
        
        ->maxConcurrent(5)
        ->timeout(3600),
);
```

#### Option B: YAML (More accessible)
```yaml
# workflows/refactor-service.yaml
name: refactor-service
description: Refactor a microservice with tests and docs

stages:
  - name: analyze
    agent: architect
    prompt: "Analyze {{service}} for refactoring opportunities"
    tools: [Read, Grep, Glob]
  
  - name: implement
    parallel: true
    agents:
      - name: implement-api
        type: coder
        prompt: "Implement API changes for {{service}}"
      - name: implement-tests
        type: coder  
        prompt: "Write tests for {{service}}"
  
  - name: verify
    agent: reviewer
    prompt: "Review implementation, find bugs"
  
  - name: fix
    agent: coder
    prompt: "Fix bugs found: {{verify.bugs}}"
  
  - name: docs
    agent: scribe
    prompt: "Update docs for {{service}}"

config:
  maxConcurrent: 5
  timeout: 3600
```

### New Classes

#### 1. WorkflowEngine
Orchestrates workflow execution.
```php
final class WorkflowEngine
{
    public function __construct(
        private readonly AgentWorkerPool $pool,
        private readonly WorkflowRegistry $registry,
    ) {}

    public function run(string $workflowPath, array $context): WorkflowResult;
    public function runFromPhp(string $workflowClass, array $context): WorkflowResult;
    public function resume(string $workflowId): WorkflowResult;
    public function pause(string $workflowId): void;
    public function getStatus(string $workflowId): WorkflowStatus;
}
```

#### 2. WorkflowBuilder
Fluent builder for workflow definitions.
```php
final class WorkflowBuilder
{
    public function stage(string $name, TaskBuilder $task): self;
    public function parallel(string $name, array $tasks): self;
    public function pipeline(string $name, array $stages): self;
    public function maxConcurrent(int $n): self;
    public function timeout(int $seconds): self;
    public function build(): Workflow;
}
```

#### 3. WorkflowRegistry
Discovers and loads workflows.
```php
final class WorkflowRegistry
{
    public function __construct(
        private readonly string $workflowsPath = '~/.sugar-crush/workflows/',
    ) {}

    public function load(string $name): Workflow;
    public function list(): array;
    public function register(Workflow $workflow): void;
}
```

#### 4. WorkflowResult
Result of workflow execution.
```php
final class WorkflowResult
{
    public function __construct(
        public readonly string $workflowId,
        public readonly WorkflowStatus $status,
        /** @var StageResult[] */
        public readonly array $stageResults,
        public readonly array $context,
        public readonly int $totalTokens,
        public readonly float $totalCost,
        public readonly \DateTimeImmutable $startedAt,
        public readonly ?\DateTimeImmutable $completedAt,
    ) {}
}
```

### Workflow Primitives

#### parallel(tasks[])
Runs tasks concurrently. Completes when all tasks done or one fails (configurable).
```php
->parallel('fan-out', [
    Tasks::agent('explorer', 'explore-auth')
        ->prompt('Research auth system'),
    Tasks::agent('explorer', 'explore-api')
        ->prompt('Research API layer'),
    Tasks::agent('explorer', 'explore-db')
        ->prompt('Research database schema'),
])
```

#### pipeline(stages[])
Chains stages where each stage receives output from previous.
```php
->pipeline('process', [
    Tasks::agent('fetch')
        ->prompt('Fetch data from {{input}}'),
    Tasks::agent('transform')
        ->prompt('Transform: {{prevResult}}'),
    Tasks::agent('validate')
        ->prompt('Validate: {{prevResult}}'),
])
```

#### withVerification(task, verifier)
Runs task then verifier tries to break it.
```php
->withVerification(
    Tasks::agent('coder')->prompt('Implement {{feature}}'),
    Tasks::agent('reviewer')->prompt('Find security bugs in implementation'),
)
```

### Context Passing
- {{variable}} - interpolates from workflow context
- {{stageName.output}} - interpolates from previous stage output
- {{agent.results}} - interpolates from agent result

### Files to Create
- src/Workflows/Workflow.php
- src/Workflows/WorkflowEngine.php
- src/Workflows/WorkflowBuilder.php
- src/Workflows/WorkflowRegistry.php
- src/Workflows/WorkflowResult.php
- src/Workflows/WorkflowStatus.php
- src/Workflows/StageResult.php
- src/Workflows/TaskBuilder.php
- src/Workflows/Tasks.php (factory class)

### Files to Modify
- composer.json - add symfony/yaml for YAML workflows
- src/Chat.php - add /workflow command

### Acceptance Criteria
1. Can define workflow in PHP DSL
2. Can define workflow in YAML
3. parallel() runs tasks concurrently
4. pipeline() chains output to input
5. Workflows can be paused and resumed
6. Context can be passed between stages
7. Max 5-16 concurrent agents configurable
8. Verification phases work

### Step Manifest

Several classes here (`Workflow`, `WorkflowStatus`, `StageResult`, `TaskBuilder`, `Tasks`) are named in "Files to Create" but don't have a full field-by-field stub written out above — for those steps, the Builder Agent should infer the exact shape from how they're used in the PHP DSL and YAML examples earlier in this phase (e.g. `Tasks::agent('architect')->prompt(...)->tools([...])` implies `Tasks` is a static factory returning a `TaskBuilder`). That inference is expected, normal work for a Builder Agent, not something to escalate.

| Step ID | Title | Files | Depends On | Where to look |
|---|---|---|---|---|
| P4.S1 | WorkflowStatus enum | `src/Workflows/WorkflowStatus.php` | — | "### Files to Create" list, this phase |
| P4.S2 | StageResult | `src/Workflows/StageResult.php` | P4.S1 | "### Files to Create" list, this phase |
| P4.S3 | WorkflowResult | `src/Workflows/WorkflowResult.php`, `tests/Workflows/WorkflowResultTest.php` | P4.S2 | `#### 4. WorkflowResult` |
| P4.S4 | TaskBuilder | `src/Workflows/TaskBuilder.php` | — | "### Workflow Primitives" DSL usage examples |
| P4.S5 | Tasks factory | `src/Workflows/Tasks.php` | P4.S4 | "### Workflow Primitives" DSL usage examples |
| P4.S6 | Workflow value object | `src/Workflows/Workflow.php` | P4.S1 | "#### Option A: PHP DSL" example, this phase |
| P4.S7 | WorkflowBuilder | `src/Workflows/WorkflowBuilder.php`, `tests/Workflows/WorkflowBuilderTest.php` | P4.S5, P4.S6 | `#### 2. WorkflowBuilder` |
| P4.S8 | WorkflowRegistry — PHP DSL loading | `src/Workflows/WorkflowRegistry.php` (load from .php files, list, register) | P4.S6 | `#### 3. WorkflowRegistry` |
| P4.S9 | WorkflowRegistry — YAML loading | `src/Workflows/WorkflowRegistry.php` (extend), `composer.json` (add symfony/yaml) | P4.S8 | "#### Option B: YAML" example, this phase |
| P4.S10 | WorkflowEngine — sequential stage execution | `src/Workflows/WorkflowEngine.php` (run/runFromPhp, sequential stages only) | P4.S7, P4.S9, P4.S3 | `#### 1. WorkflowEngine` |
| P4.S11 | WorkflowEngine — parallel() primitive | `src/Workflows/WorkflowEngine.php` (extend) | P4.S10, P1.S7 | "#### parallel(tasks[])" |
| P4.S12 | WorkflowEngine — pipeline() + context interpolation | `src/Workflows/WorkflowEngine.php` (extend) | P4.S11 | "#### pipeline(stages[])" + "### Context Passing" |
| P4.S13 | WorkflowEngine — withVerification() | `src/Workflows/WorkflowEngine.php` (extend) | P4.S12 | "#### withVerification(task, verifier)" |
| P4.S14 | WorkflowEngine — pause()/resume()/getStatus() persistence | `src/Workflows/WorkflowEngine.php` (extend), `tests/Workflows/WorkflowEngineTest.php` | P4.S13 | "### Acceptance Criteria" item 5, this phase |
| P4.S15 | Wire /workflow command into Chat.php | `src/Chat.php` (modify) | P4.S14 | "### Files to Modify" list, this phase |
| P4.S16 | Integration: WorkflowExecutionTest + WorkflowResumptionTest | `tests/Integration/WorkflowExecutionTest.php`, `tests/Integration/WorkflowResumptionTest.php` | P4.S15 | "### Integration Tests" section under Testing Strategy |

---

## Phase 5: UI/UX Enhancements

### Goal
Add interactive agent management TUI panel, background session management, per-agent streaming display, and stall detection visualization.

### New Features

### Agent View Panel

The Agent View is a split-pane interface showing all active agents and their states in real-time. It provides a cockpit-style dashboard where users can monitor and interact with multiple agents simultaneously.

The main panel displays a scrollable list of agents, each showing: the agent's name or role (such as coder-1, reviewer-2), the current status (Working, Waiting, Streaming, etc.), a brief description of the current operation (such as "Reading auth.php" or "Generating API tests"), the elapsed time since the agent started, and token usage and cost accumulated so far.

Status indicators use color coding to communicate state at a glance. Green indicates the agent is actively processing work and making progress. Yellow means the agent is waiting for input or blocked on a dependency. Blue indicates streaming output is being received from the LLM. Red means the agent has failed or encountered an error. Gray indicates the agent has completed successfully or been stopped.

Keyboard navigation allows users to browse the agent list without leaving the keyboard. Arrow keys move the selection highlight between agents. Pressing Enter switches from list view to peek view, showing the last N lines of output from the selected agent. From peek view, pressing Enter again enters attach mode where all keyboard input goes to that agent. Escape returns to the previous view.

Quick action keys provide shortcuts: the C key cancels the selected agent, R requests the selected agent to resume if stalled, S stops all agents, Q quits the agent view and returns to normal chat mode.

The agent view can be toggled with a command like /agents or via a keyboard shortcut like Ctrl-A. It can also run in detached mode where agents execute in the background without blocking the main conversation.

### 2. Background Sessions

Background sessions allow agents to continue working while you handle other tasks. Each background session runs independently with its own context, agent configuration, and working directory.

Starting a background session from the TUI opens a new session that continues running in the supervisor process even after the main TUI closes. The session processes tasks, reports progress through the supervisor, and stores results for later retrieval.

The supervisor process manages all background sessions as child processes. It monitors their health, handles timeouts, and routes messages between the TUI and each session. Health monitoring reuses the same heartbeat contract as the Phase 1 worker pool: each background session reports a heartbeat on a fixed interval, and the supervisor marks it stalled (not immediately killed — a background session is allowed to run longer than a foreground one) if heartbeats stop arriving. When you reopen the TUI, the supervisor reconnects to existing sessions over the same IPC channel it uses to spawn them and restores their state to the Agent View panel, including whatever partial output had already streamed in while the TUI was closed.

Sessions can be named and tagged for organization. You might have a research session, a refactoring session, and a documentation session all running simultaneously, each tagged by purpose.

When a background session completes or fails, the supervisor sends a notification through the TUI status bar. You can configure notification preferences to receive alerts when sessions complete, fail, or need your input.

### Per-Agent Streaming Display

When multiple agents run simultaneously, each agent streams its output to a dedicated pane or area within the Agent View. This gives you real-time visibility into what each agent is doing without switching contexts.

Each streaming display shows the agent name, its current status, the model being used, tokens consumed so far, and the live output buffer. The output buffer shows the last several lines of the agent's most recent response, updating as new tokens arrive.

The display uses color coding to distinguish agents and their states. A green left border indicates an agent actively producing output. A yellow border indicates an agent waiting for tool results. A red border indicates an error condition. A gray border indicates a completed or stopped agent.

You can expand any agent's streaming display to full-screen to see the complete output in context. You can also collapse it back to minimal status view to save screen space.

#### Stall Detection Display

Stall detection identifies when an agent has stopped making progress despite being in a running state. This catches situations like network timeouts that did not trigger an error, LLM API rate limiting causing long delays, or agents caught in a loop without producing visible output.

The stall detector tracks each agent's output rate measured in tokens per second. When the rate drops below a threshold for a sustained period (configurable, default 30 seconds), the agent is flagged as potentially stalled. The TUI displays a warning indicator on the agent's tile in the Agent View.

You can configure what happens when a stall is detected. The default is to send the agent a status check message asking it to report its current state. If the agent does not respond within the timeout period, it is marked as stalled and the TUI prompts you to either wait longer, interrupt the agent, or restart it.

Stall detection operates in the supervisor process independently of the agent's own loop, so it can catch stalls even when the agent's process appears healthy from the outside.

### Split Pane Views

Split panes display multiple agent sessions simultaneously in a tmux-like layout within the TUI. This lets you monitor several agents working in parallel without switching between sessions.

The TUI supports horizontal and vertical splits. A horizontal split divides the screen top and bottom, useful for watching a background agent work while continuing to interact with the main session. A vertical split divides left and right, useful for side-by-side comparison of two agent sessions.

Each pane operates independently with its own scrollback buffer, input handling, and state. You can focus a pane with keyboard navigation and send input to the focused pane while others continue running.

The layout persists across terminal resizes. When you expand the terminal window, panes expand proportionally. You can also manually resize panes by dragging the divider with the mouse or using keyboard shortcuts.

Split panes integrate with the Agent View panel. When you dispatch a new agent from a split pane, it automatically opens in that pane. When an agent finishes, the pane shows its final output until you close it or navigate away.

Two distinct implementations are worth supporting rather than picking one. An in-process split renders entirely inside sugar-crush's own buffer-diffed renderer — no external dependency, works over any SSH connection, but panes share one process's CPU budget. A multiplexer-backed split instead shells out to hand each agent its own real pane inside an already-running tmux or iTerm2 session, giving each agent a genuinely separate process and scrollback at the cost of requiring one of those two tools to be present. Sugar-crush should default to the in-process renderer and only switch to the multiplexer-backed mode when it detects `$TMUX` or an iTerm2 session is already active, since that's the case where a user's muscle memory already expects real panes.

### Multi-Session Tabs and Sharing

Background sessions let work continue unattended, but there's no way today to have two or more sessions visually open and switchable within one running TUI instance the way editor tabs work. Adding tabs means the TUI keeps a list of open sessions, each with its own scrollback, agent-view state, and input focus, switched with a keybinding (`Ctrl-Tab` / `Ctrl-Shift-Tab`) rather than requiring a fresh `--resume` from a cold start every time a different piece of work needs checking on.

Pairing tabs with a `/share` command turns a session into something a teammate can review without shell access to the machine that ran it. `/share` serializes the current session using the same export formats sugar-crush already produces (Markdown, JSON, plain text) and uploads it to a short-lived signed URL. Because the session store is already SQLite-backed and fully exportable, this is a thin hosting-and-auth layer over data the system already has, not a new persistence model. The URL expires after a configurable window (default 7 days) and, unlike the live session, is read-only: a viewer can follow the transcript and tool calls but cannot inject new turns into the original agent's context.

### Files to Create
- src/Tui/AgentViewPane.php - Agent list pane
- src/Tui/SessionTabs.php - tab list, focus tracking, keybinding wiring
- src/Commands/ShareCommand.php - /share export + upload
- src/Tui/AgentOutputPane.php - Per-agent output display
- src/Tui/AgentStatusBar.php - Status indicators
- src/Commands/AgentsCommand.php - /agents CLI command

### Files to Modify
- src/Tui/Renderer.php - Add agent view rendering
- src/Tui/KeyboardHandler.php - Add keyboard shortcuts
- src/Chat.php - Add /agent and /agents commands

### Implementation Details

Agent View state machine:
1. List agents: Show all known agents with status
2. Peek: Show last N lines of agent output
3. Attach: Full focus on single agent output
4. Detach: Return to multi-agent view

### Acceptance Criteria
1. /agents command shows all active agents
2. Can peek at individual agent output
3. Can cancel agents from the view
4. Split pane mode for parallel output
5. Stall detection shows visual warning
6. Background agents don't block main input

### Step Manifest

This phase is mostly UI code without exact class stubs — each step's Builder Agent should design the implementation to match the prose description for that piece plus the existing `src/Tui/Renderer.php` buffer-diff rendering conventions already used elsewhere in sugar-crush.

| Step ID | Title | Files | Depends On | Where to look |
|---|---|---|---|---|
| P5.S1 | AgentStatusBar | `src/Tui/AgentStatusBar.php` | P1.S7 | "### Files to Create" list, this phase; status color coding paragraph |
| P5.S2 | AgentViewPane (list view) | `src/Tui/AgentViewPane.php` | P5.S1 | "### Agent View Panel" |
| P5.S3 | AgentOutputPane (peek/attach) | `src/Tui/AgentOutputPane.php` | P5.S2 | "### Per-Agent Streaming Display" |
| P5.S4 | Keyboard shortcuts for agent view | `src/Tui/KeyboardHandler.php` (modify) | P5.S3 | "Keyboard navigation" + "Quick action keys" paragraphs |
| P5.S5 | Background session supervisor | `src/Tui/AgentViewPane.php` (extend, or a new `src/Sessions/BackgroundSupervisor.php`) | P1.S6 | "### 2. Background Sessions" |
| P5.S6 | Stall detection | (extend AgentOutputPane / supervisor with tokens/sec tracking) | P5.S3, P5.S5 | "#### Stall Detection Display" |
| P5.S7 | Split pane rendering (in-process) | `src/Tui/Renderer.php` (extend) | P5.S2 | "### Split Pane Views", first three paragraphs |
| P5.S8 | Multiplexer-backed split pane detection | `src/Tui/Renderer.php` (extend: $TMUX / iTerm2 detection + fallback) | P5.S7 | "Two distinct implementations are worth supporting..." paragraph |
| P5.S9 | SessionTabs | `src/Tui/SessionTabs.php` | P5.S5 | "### Multi-Session Tabs and Sharing", first paragraph |
| P5.S10 | ShareCommand (/share) | `src/Commands/ShareCommand.php` | P5.S9 | "### Multi-Session Tabs and Sharing", second paragraph |
| P5.S11 | AgentsCommand (/agents, /agent) wired into Chat.php | `src/Commands/AgentsCommand.php`, `src/Chat.php` (modify) | P5.S4, P5.S8 | "### Files to Create"/"### Files to Modify" lists, this phase |
| P5.S12 | Manual UI verification pass | none (verification only — no files) | P5.S11 | "### Acceptance Criteria", this phase — Review Agent should manually exercise `/agents` in a real terminal session and confirm each acceptance criterion visually, in addition to the normal checklist |

---

## Phase 6: Context and Memory Management

### Goal
Implement automatic context compaction when window fills, cross-session persistent memory, and improved session resumption.

### Auto-Compaction System

When the conversation history grows large, it consumes more tokens and eventually hits the context window limit. The auto-compaction system addresses this by periodically summarizing old messages to free up context space while preserving important information.

The compaction trigger uses a tiered threshold approach based on current context usage. When usage reaches 70 percent of the context window, the system sends a reminder to the lead agent about upcoming compaction. At 85 percent, automatic compaction begins in the background. At 95 percent, foreground compaction blocks new input until space is freed.

Compaction operates in stages rather than summarizing everything at once. Stage one preserves full messages for the most recent N exchanges (typically 10), as these are most relevant to ongoing work. Stage two condenses older exchanges into single-line summaries capturing what happened and any key decisions made. Stage three groups similar exchanges together (such as multiple similar file reads or repeated grep searches) into a single summarized entry. Stage four handles files and code snippets by replacing full file contents with metadata plus diffs for the relevant portion. Stage five removes navigation and intermediate steps while preserving the final destination or result.

The summarization prompt given to the LLM asks it to preserve: architectural decisions and their rationale, any errors encountered and how they were resolved, file locations and their purposes, configuration values and why they were chosen, and any work-in-progress state that matters for continuation.

After summarization, the compacted context is verified to actually free enough space. If compaction was insufficient, additional passes run until the context is below the 70 percent threshold.

Compaction has to account for skills, not just raw message history. A skill invoked earlier in the conversation stays in context as standing instructions, but it isn't re-read on later turns — so when compaction runs, each carried-forward skill is capped at roughly 5,000 tokens of its own content, and the combined budget across every skill still in context is capped at roughly 25,000 tokens. Past that combined cap, the least-recently-invoked skill's content is the first to be dropped; if its guidance still matters, the agent needs to re-invoke it rather than assume it silently survived. This runs as its own pass, separate from message-history compaction, so a handful of large skills can't eat the entire compaction budget before any conversation history is touched.

Compaction also isn't purely threshold-driven. A manual `/compact` lets the user collapse history on demand regardless of current usage, independent of the automatic 70/85/95 percent triggers. And for sessions that have simply sat idle rather than grown large — over an hour inactive and above 100K tokens is a reasonable default — the system should offer a choice rather than act unilaterally: compact now, or resume the full session exactly as it was left.

### Persistent Memory System

The persistent memory system stores learnings and context across sessions so agents can remember project-specific patterns, conventions, and decisions without re-learning them each session.

Storage locations determine the scope and sharing of memory. Project-level memory lives in .sugar-crush/memory/ and is version-controlled with the project repository. This allows the entire team to benefit from shared learnings about the project. User-level memory lives in ~/.sugar-crush/memory/ and is private to the current user across all projects. This stores personal preferences and general coding patterns. Agent-level memory lives in .sugar-crush/agent-memory/<agent-name>/ and is specific to a particular agent role, useful for agent-type-specific learnings like testing strategies or security patterns.

Memory entries are stored as Markdown files with YAML frontmatter containing metadata. Each entry has a type (such as pattern, convention, decision, preference), tags for categorization, project and user scope, created and modified timestamps, and content describing the memory.

The memory system exposes commands for management. /memory list shows all memories matching a filter. /memory add creates a new memory entry. /memory search <query> finds relevant memories using full-text search. /memory edit <id> modifies an existing memory. /memory delete <id> removes a memory. /memory clear --scope project --confirm removes all project memories.

When an agent starts, it loads relevant memories based on the project and agent type. These memories are injected into the agent's context as a system message, allowing the agent to act on accumulated knowledge immediately.

Beyond the three storage tiers, each `MemoryStore` scope needs a lightweight always-loaded index distinct from the full set of memory entries — a single `MEMORY.md` per scope, capped at roughly the first 200 lines or 25KB, loaded automatically at the start of every session so an agent's baseline understanding of accumulated project knowledge doesn't require it to run `/memory search` before doing anything. Individual memory entries stay addressable and searchable beyond that cap; the index is a summary layer, not the full store. This index is what the `memory` field on an agent preset (see the schema near the top of this document) actually points at — `memory: project` means "load `.sugar-crush/memory/MEMORY.md` at spawn," not "load every memory entry ever recorded for this project."

### Session Resumption Improvements

Session resumption allows returning to a previous session after closing the terminal or restarting the computer. The current session store already persists messages, but resumption can be improved in several ways.

The session index tracks which sessions exist, their last activity timestamp, which project they belong to, and a summary of their current state generated on each user message. This lets the resumption UI show meaningful descriptions like "working on user authentication refactor" rather than just timestamps.

When resuming, the system first loads the session metadata and recent messages from SQLite. It then reconstructs the application state including which files were open, which tools were available, and any agent context. The agent receives the session summary plus recent messages to reconstruct its understanding of the current state.

Context replay handles the gap between sessions. Any events that happened in the session after the last user message (such as agent tool executions or background work) are replayed into the new session context so the agent has full awareness of recent activity.

The resumption UI accessed via the sessions list shows active sessions sorted by last activity. Each session shows its project, summary, duration, and cost so far. Selecting a session restores the full TUI state to exactly where it was.

Session management benefits from a few more explicit, named affordances beyond restoring state on resume. Naming a session at launch or via `/rename` gives it a stable handle for `--resume <name>` later instead of relying on timestamp ordering. `/branch` forks the current session into a copy at that exact point — useful when a teammate wants to try a riskier approach without losing the ability to fall back to the original conversation. Checkpointing takes an automatic snapshot before each prompt is processed, and a double-press of an idle-prompt key (or `/rewind`) restores both the code and the conversation to one of the last 100 checkpoints — a much finer-grained undo than reverting a git commit, since it also rewinds what the agent believes happened. The session picker itself should support the same kind of keyboard-first navigation as the rest of the TUI: arrow keys to browse, Enter to resume, Space to preview without committing, and a filter that narrows the list to sessions tied to the current git branch — valuable once dozens of sessions have accumulated in the SQLite store across a long-running project.

### Nested Instruction File Discovery

The monorepo already carries a root `CLAUDE.md` and `AGENTS.md`, but loading every one of the 52 libs' conventions into every session regardless of what's actually being touched is wasted context. Instead, when an agent's Read/Edit/Glob tool successfully touches a file inside a given lib directory — `candy-shine/`, `sugar-crush/`, whichever — the system checks that directory (and anything between it and the working root, but not the root itself, which is already loaded at session start) for its own `CLAUDE.md` or `AGENTS.md` and injects it into context if it hasn't been injected already this session. Each nested file is injected exactly once; a second read of a file in the same lib doesn't duplicate it, and editing the nested file mid-session doesn't retroactively update what's already in context — a new session is what picks up the change.

This means a task confined to `sugar-crush/` never pays the token cost of `candy-tetris/`'s or `honey-bounce/`'s lib-specific `CALIBER_LEARNINGS.md` patterns, while root-level conventions (PSR-4, immutable + fluent `with*()`, the ship-as-you-go PR cadence) still apply everywhere because the root file loads unconditionally. For guidance that shouldn't depend on an agent happening to open the right file — cross-cutting references like `docs/MATCHUPS.md` or every lib's `CALIBER_LEARNINGS.md` at once — an explicit `instructions` array in `.sugar-crush/config.json` accepts literal paths or glob patterns (`"candy-*/CALIBER_LEARNINGS.md"`) that force-load regardless of what the agent has touched.

```php
namespace SugarCraft\Crush\Context;

final class InstructionFileLoader
{
    public function __construct(
        private readonly string $repoRoot,
        /** @var string[] glob patterns from config, force-loaded every session */
        private readonly array $forcedInstructions = [],
    ) {}

    public function loadRoot(): array; // CLAUDE.md + AGENTS.md at repo root, always
    public function loadForPath(string $touchedPath, SessionState $session): ?string; // nested file, once per session
    public function loadForced(): array; // resolves glob patterns from config
}
```

### New Classes

#### 1. ContextCompactor
Handles automatic context compaction.
```php
final class ContextCompactor
{
    public function __construct(
        private readonly CompactorConfig $config,
    ) {}

    public function shouldCompact(array $messages, int $tokenLimit): bool;
    public function compact(array $messages): array;  // Returns compacted messages
    public function getSavingsPercentage(): int;
}
```

#### 2. MemoryStore
Persistent cross-session memory.
```php
final class MemoryStore
{
    public function __construct(
        private readonly string $memoryPath,
    ) {}

    public function add(string $content, string $scope = 'user'): string;  // returns id
    public function search(string $query): array;
    public function list(string $scope = 'user'): array;
    public function delete(string $id): void;
    public function clear(string $scope): void;
}
```

#### 3. SessionMeta
Enhanced session metadata for better resumption.
```php
final class SessionMeta
{
    public function __construct(
        public readonly string $sessionId,
        public readonly string $summary,
        public readonly array $tasks,
        public readonly array $modifiedFiles,
        public readonly array $agentStates,
        public readonly \DateTimeImmutable $lastActivity,
    ) {}
}
```

### Files to Create
- src/Context/ContextCompactor.php
- src/Context/CompactorConfig.php
- src/Context/InstructionFileLoader.php
- src/Memory/MemoryStore.php
- src/Memory/MemoryEntry.php
- src/Session/SessionMeta.php
- src/Session/EnhancedSessionStore.php

### Files to Modify
- src/Chat.php - Add /memory command
- src/Runtime.php - Wire in ContextCompactor
- src/Session/SessionStore.php - Add enhanced metadata

### Acceptance Criteria
1. Context auto-compacts at 95% threshold
2. Compaction preserves critical information
3. /memory commands work correctly
4. Memory persists across sessions
5. Session resume shows meaningful summary
6. Agent state restored on resume
7. Auto-learned memories appear in context

### Step Manifest

`InstructionFileLoader` (P6.S14-S15) was designed alongside this phase's memory/compaction work but has no dependency on it — its two steps can run any time after Phase 1, including in parallel with the rest of this phase's steps, since it touches entirely different files.

| Step ID | Title | Files | Depends On | Where to look |
|---|---|---|---|---|
| P6.S1 | CompactorConfig | `src/Context/CompactorConfig.php` | — | "### New Classes", this phase |
| P6.S2 | ContextCompactor — shouldCompact() + stages 1-2 | `src/Context/ContextCompactor.php` (shouldCompact, stage 1 recent-N preserve, stage 2 condense-to-summary) | P6.S1 | "### Auto-Compaction System", first three paragraphs |
| P6.S3 | ContextCompactor — stages 3-5 | `src/Context/ContextCompactor.php` (extend: stage 3 group-similar, stage 4 file-to-diff, stage 5 remove-nav) | P6.S2 | "### Auto-Compaction System", stage list paragraph |
| P6.S4 | ContextCompactor — skill-aware compaction | `src/Context/ContextCompactor.php` (extend), `tests/Context/ContextCompactorTest.php` (the 4 cases named in Testing Strategy) | P6.S3 | "Compaction has to account for skills..." paragraph |
| P6.S5 | Wire /compact + idle-session heuristic | `src/Chat.php` (modify), `src/Runtime.php` (modify) | P6.S4 | "Compaction also isn't purely threshold-driven..." paragraph |
| P6.S6 | MemoryEntry value object | `src/Memory/MemoryEntry.php` | — | "### New Classes", this phase |
| P6.S7 | MemoryStore | `src/Memory/MemoryStore.php`, `tests/Memory/MemoryStoreTest.php` | P6.S6 | `#### 2. MemoryStore` |
| P6.S8 | MEMORY.md index generation | `src/Memory/MemoryStore.php` (extend: index generation, 200-line/25KB cap) | P6.S7 | "Beyond the three storage tiers..." paragraph |
| P6.S9 | Wire /memory command into Chat.php | `src/Chat.php` (modify) | P6.S8 | "The memory system exposes commands..." paragraph |
| P6.S10 | SessionMeta + EnhancedSessionStore | `src/Session/SessionMeta.php`, `src/Session/EnhancedSessionStore.php` | — | `#### 3. SessionMeta` |
| P6.S11 | Session naming + /branch | `src/Session/SessionStore.php` (modify), `src/Chat.php` (modify) | P6.S10 | "Session management benefits from..." paragraph |
| P6.S12 | Checkpointing + /rewind | `src/Session/EnhancedSessionStore.php` (extend), `src/Chat.php` (modify) | P6.S11 | same paragraph, checkpointing sentences |
| P6.S13 | Session picker keyboard navigation | `src/Tui/` (session picker component — Builder Agent should locate the existing session-list UI code and extend it) | P6.S12 | same paragraph, session picker sentence |
| P6.S14 | InstructionFileLoader — loadRoot() + loadForced() | `src/Context/InstructionFileLoader.php` (loadRoot, loadForced glob resolution) | — | "### Nested Instruction File Discovery", `InstructionFileLoader` PHP code block |
| P6.S15 | InstructionFileLoader — loadForPath() nested injection | `src/Context/InstructionFileLoader.php` (extend), `src/Tools/BuiltIn/Read.php`/`Edit.php`/`Glob.php` (modify to call it), `tests/Context/InstructionFileLoaderTest.php` (the 4 cases named in Testing Strategy) | P6.S14 | "### Nested Instruction File Discovery", first two paragraphs |

---

## Phase 7: Integrations

### Goal
Add Git MCP server, LSP client integration, deep research bundled workflow, and more built-in skills.

### Git MCP Server

Implement a Model Context Protocol server that provides structured Git operations. This gives agents access to 29 Git operations organized into groups.

Operation groups:
- git_context: Repository snapshot, config, aliases
- git_history: Log, show, blame, reflog
- git_commits: Add, commit, amend, revert, reset
- git_branches: List, create, delete, checkout
- git_worktree: Add, list, remove worktrees
- git_flow: Git-flow workflow support
- git_lfs: LFS tracking and migration

Example agent use:
```
Agent: "Commit all changes with message 'fix auth regression'"
MCP Tool: git_commit(path="/repo", message="fix auth regression", all=true)
Result: commit abc123
```

### Remote MCP Authentication

MCP servers that require OAuth shouldn't need a human to hand-provision a client ID before sugar-crush can talk to them. Dynamic client registration (RFC 7591) lets sugar-crush register itself with a server on first connection and receive credentials automatically. The resulting tokens are stored at `~/.local/share/sugar-crush/mcp-auth.json`, one entry per server, refreshed automatically ahead of expiry rather than failing mid-session when a token lapses. A `sugar-crush mcp auth <server>` command exists for the manual path — triggering or re-triggering the flow after a token's been revoked, or for servers that don't support dynamic registration and need a token pasted in directly.

Per-agent MCP routing keeps every sub-agent from seeing every configured server by default. An agent preset's `mcpServers` field (see the Agent Preset Configuration Schema) names only the servers that preset actually needs — an architect preset might get a docs-search server while a coder preset gets database access — and wildcard deny patterns like `"untrusted_*": "deny"` block whole families of tools regardless of which preset is currently active, independent of what any individual preset's allowlist says.

Files to create, continued:
- src/MCP/OAuthClientRegistration.php
- src/MCP/McpAuthStore.php

### LSP Client Integration

Language Server Protocol for code intelligence:

Features:
- Go-to definition
- Find references
- Hover documentation
- Symbol search
- Diagnostics
- Code actions

Implementation:
- Connect to language servers via stdio or TCP
- Cache LSP responses per file
- Fallback to basic grep if LSP unavailable
- Support multiple languages simultaneously

### Deep Research Workflow

The deep research workflow coordinates multiple specialized agents to investigate a topic thoroughly, gathering information from documentation, code analysis, web search, and expert sources, then synthesizing findings into a comprehensive report.

The research begins with a planner agent that breaks down the research question into specific areas to investigate. It identifies key concepts that need understanding, questions that need answers, sources that need checking, and dependencies between topics (some things must be understood before others).

The investigation phase runs multiple researcher agents in parallel, each tackling a different area. One agent might explore the official documentation and tutorials. Another might analyze example code and real-world usage patterns. A third might search for common pitfalls and how to avoid them. A fourth might look for integration challenges and compatibility concerns.

As researchers report back, the synthesizer agent collects findings and identifies gaps or conflicting information. It can request additional research on specific points, ask follow-up questions, or direct a researcher to dig deeper on a particular aspect.

The synthesis phase produces a structured report with sections for background and context, key findings organized by topic, code examples demonstrating concepts, references to sources and further reading, and open questions or areas requiring more research.

The workflow supports iterative refinement. If the research reveals the original question was based on incorrect assumptions, the workflow can re-plan and begin a second research iteration with corrected understanding.

### Skill Loading Model

Skills should load in three stages rather than all at once, keeping a large skill library cheap to keep registered. At startup, only each skill's name and description enter context — on the order of 100 tokens per skill — which is what the orchestrator matches against when deciding whether a task calls for `security-audit` versus `phpunit-master`. The full `SKILL.md` body loads only once a task actually matches that description. Anything under the skill's `scripts/`, `references/`, or `assets/` subfolders loads later still, pulled in only when the in-progress task actually needs that specific file. Keeping `SKILL.md` itself under roughly 500 lines / 5,000 tokens and pushing detail one level deep into reference files is what makes this worth doing — a skill that front-loads everything into its main file defeats the staged loading.

Skill storage mirrors the same tiering as instruction files: project skills live in `.sugar-crush/skills/` and are committed, user skills live in `~/.sugar-crush/skills/` and are personal across every project, and — since each of the 52 libs already carries its own `CALIBER_LEARNINGS.md` and conventions — a lib can also carry `<lib>/.sugar-crush/skills/`, loaded only once an agent is actually working inside that lib, using the same on-demand nested-loading mechanism as the instruction files described in Phase 6.

Two frontmatter flags control who can reach a skill. `disable-model-invocation` keeps a skill from being auto-triggered by task matching at all — appropriate for anything with side effects that shouldn't fire just because the code looks ready, like a deploy or commit skill. `user-invocable: false` does the opposite: it hides a skill from the manual command surface while leaving auto-invocation intact, which fits background knowledge an agent should silently draw on — something like "how `candy-vcr`'s tape renderer differs from upstream `vhs`" — that a human would never type as a command but that should still shape behavior when relevant.

Most skills run inline, folding their content into the calling conversation as standing instructions for the rest of the session. A skill can instead be marked to run in a *context fork*: its body becomes the prompt for a spawned sub-agent that has no access to the calling conversation's history, and only that sub-agent's final result returns to the main thread. This is the right shape for something like a full `security-audit` pass across a whole lib — running it inline would permanently bloat the calling session's context with every file it touched along the way, while a forked pass returns just the findings.

Files to create, continued:
- src/Skills/SkillLoader.php — progressive disclosure (name/description → body → assets)
- src/Skills/SkillDiscovery.php — resolves project/user/nested-per-lib search paths

### Additional Built-in Skills

More skills for PHP ecosystem:

#### 1. laravel-best-practices
- Laravel coding standards
- Eloquent optimization
- Service container patterns
- Blade conventions

#### 2. symfony-best-practices
- Symfony coding standards
- Service definition
- Event dispatcher patterns
- Form handling

#### 3. security-audit
- OWASP top 10 checks
- SQL injection detection
- XSS prevention
- CSRF protection
- Authentication patterns

#### 4. phpstan-master
- PHPStan level 9 configuration
- Custom rules
- Baseline management
- Integration patterns

#### 5. testing-strategies
- PHPUnit best practices
- Mock patterns
- Test organization
- Coverage goals

#### 6. api-design
- REST conventions
- JSON:API patterns
- Authentication flows
- Error handling

#### 7. explore-codebase
- Fast read-only pass for tracing an unfamiliar lib's structure before editing it, packaged so any preset can invoke it without a full sub-agent spawn
- No side effects worth gating, so it stays eligible for auto-invocation

#### 8. mcp-authoring
- Scaffolds a new MCP tool inside a lib (e.g. `candy-query`) that wants to expose itself over the protocol
- Generates the tool schema, the `McpServer` wiring, and a smoke test

#### 9. worktree-workflow
- Walks a teammate through claiming a task, creating its worktree, and opening the merge-back PR per the ship-as-you-go cadence
- Keeps the worktree lifecycle consistent across teammates instead of each one improvising the git steps

#### 10. matchups-sync
- Keeps `docs/MATCHUPS.md` and `PROJECT_NAMES.md` in sync whenever a new port lands
- Marked `user-invocable: false` and run automatically at the end of any workflow stage that adds a lib, since concurrent hand-edits to these two files are an explicit collision risk

### Files to Create

Git MCP Server:
- src/MCP/GitMcpServer.php
- src/MCP/GitCommandHandlers.php
- src/MCP/GitOperationResult.php

LSP:
- src/LSP/LspClient.php
- src/LSP/LspConnection.php
- src/LSP/LspCache.php

New Skills:
- skills/laravel-best-practices/SKILL.md
- skills/symfony-best-practices/SKILL.md
- skills/testing-strategies/SKILL.md
- skills/api-design/SKILL.md
- skills/explore-codebase/SKILL.md
- skills/mcp-authoring/SKILL.md
- skills/worktree-workflow/SKILL.md
- skills/matchups-sync/SKILL.md

### Files to Modify

- src/MCP/McpServer.php - Add Git server support
- src/Skills/SkillRegistry.php - Register new skills
- composer.json - Add LSP client library

### Acceptance Criteria

1. Git MCP server handles all 29 operations
2. LSP provides go-to-definition for PHP
3. Deep research workflow runs successfully
4. All new skills discoverable and loadable
5. Skills apply context to relevant agent prompts

### Step Manifest

| Step ID | Title | Files | Depends On | Where to look |
|---|---|---|---|---|
| P7.S1 | GitOperationResult | `src/MCP/GitOperationResult.php` | — | "### Git MCP Server", operation groups list |
| P7.S2 | GitCommandHandlers — git_context + git_history | `src/MCP/GitCommandHandlers.php` (git_context, git_history operation groups) | P7.S1 | same section |
| P7.S3 | GitCommandHandlers — git_commits + git_branches | `src/MCP/GitCommandHandlers.php` (extend) | P7.S2 | same section |
| P7.S4 | GitCommandHandlers — git_worktree + git_flow + git_lfs | `src/MCP/GitCommandHandlers.php` (extend), `tests/MCP/GitCommandHandlersTest.php` | P7.S3 | same section |
| P7.S5 | GitMcpServer wiring | `src/MCP/GitMcpServer.php`, `src/MCP/McpServer.php` (modify) | P7.S4 | "### Files to Modify" list, this phase |
| P7.S6 | OAuthClientRegistration | `src/MCP/OAuthClientRegistration.php` | — | "### Remote MCP Authentication", first paragraph |
| P7.S7 | McpAuthStore + `mcp auth` command | `src/MCP/McpAuthStore.php`, `tests/MCP/McpAuthStoreTest.php` | P7.S6 | "### Remote MCP Authentication", first paragraph |
| P7.S8 | Per-agent MCP routing | `src/MCP/McpServer.php` (extend: mcpServers allowlist + wildcard deny) | P7.S7, P0.S6 | "### Remote MCP Authentication", second paragraph |
| P7.S9 | LspConnection | `src/LSP/LspConnection.php` | — | "### LSP Client Integration" |
| P7.S10 | LspCache | `src/LSP/LspCache.php` | — | "### LSP Client Integration" |
| P7.S11 | LspClient | `src/LSP/LspClient.php`, `tests/LSP/LspClientTest.php` | P7.S9, P7.S10 | "### LSP Client Integration" |
| P7.S12 | SkillLoader | `src/Skills/SkillLoader.php` | — | "### Skill Loading Model", first paragraph |
| P7.S13 | SkillDiscovery | `src/Skills/SkillDiscovery.php`, `tests/Skills/SkillLoaderTest.php` (the 4 cases named in Testing Strategy) | P7.S12 | "### Skill Loading Model", second paragraph |
| P7.S14 | Wire disable-model-invocation/user-invocable/context-fork flags | `src/Skills/SkillRegistry.php` (modify) | P7.S13 | "### Skill Loading Model", third and fourth paragraphs |
| P7.S15 | Deep research workflow definition | `workflows/deep-research.php` (or `.yaml`, per Phase 4's `WorkflowRegistry`) | P4.S16 | "### Deep Research Workflow" |
| P7.S16 | Built-in skills batch 1 | `skills/laravel-best-practices/SKILL.md`, `skills/symfony-best-practices/SKILL.md`, `skills/testing-strategies/SKILL.md`, `skills/api-design/SKILL.md` | P7.S14 | "#### 1." through "#### 6." skill lists, this phase |
| P7.S17 | Built-in skills batch 2 | `skills/explore-codebase/SKILL.md`, `skills/mcp-authoring/SKILL.md`, `skills/worktree-workflow/SKILL.md`, `skills/matchups-sync/SKILL.md` | P7.S14 | "#### 7." through "#### 10." skill lists, this phase |
| P7.S18 | Integration: Git MCP + LSP smoke test | `tests/Integration/GitMcpServerTest.php` | P7.S5, P7.S11 | "### Acceptance Criteria", items 1-2, this phase |

---

## Implementation Order and Dependencies

If this build is being run by the agent hierarchy described in "Execution Protocol for Orchestrated Implementation" above, the Plan Orchestrator follows the fixed order **P0 → P1 → P2 → P2B → P3 → P4 → P5 → P6 → P7** given there rather than re-deriving it from the dependency notes below — those notes exist to explain *why* that order is correct, not to be re-parsed at runtime.

### Phase Dependencies

Phase 0 (Local Dev Provider + Agent Preset Schema) has no dependencies and runs first — everything else either consumes the `AgentPreset` type or benefits from having the free dev/test LLM endpoint wired up before any other phase starts writing code that needs to actually run against a model.

Phase 1 (Parallel Execution) must complete before:
- Phase 2 (Agent Teams) - depends on worker pool
- Phase 5 (UI) - depends on agent status tracking
- Phase 6 (Context) - depends on agent execution tracking

Phase 2 (Agent Teams) must complete before:
- Phase 2B (Permission Modes and Hook Lifecycle) - depends on the task list to hook TaskCreated/TaskCompleted into
- Phase 3 (Worktree) - depends on team infrastructure
- Phase 4 (Workflows) - depends on task list

Phase 2B (Permission Modes and Hook Lifecycle) must complete before Phase 3, since Phase 3's worktree-per-agent flow is expected to run under a resolved `PermissionGate`.

Phase 3 (Worktree) can start anytime after Phase 2B begins

Phase 4 (Workflows) depends on Phases 1, 2, 3

Phase 5 (UI) can start after Phase 1

Phase 6 (Context) can start after Phase 1

Phase 7 (Integrations) can start anytime, depends on Phase 2 for Git MCP

### Recommended Implementation Order

0. Phase 0: Local Dev Provider + Agent Preset Schema - Foundations everything else types against
1. Phase 1: Parallel Execution - Foundation for everything
2. Phase 6: Context Compaction - Low risk, high value
3. Phase 5: UI Basics - Agent status display
4. Phase 2: Agent Teams - Core orchestration
5. Phase 2B: Permission Modes and Hook Lifecycle - Depends on Phase 2's task list
6. Phase 3: Worktree Isolation - Depends on Phase 2B
7. Phase 4: Workflows - Depends on 1, 2, 2B, 3
8. Phase 7: Integrations - Can parallelize with above

This differs slightly from the strictly sequential `P0 → P1 → P2 → P2B → P3 → P4 → P5 → P6 → P7` order the Plan Orchestrator follows in the Execution Protocol — the orchestrator's order favors simplicity (one phase at a time, no interleaving) over the extra parallelism a human team could exploit here (e.g. running Phase 6 or Phase 5 alongside Phase 2). Once the orchestrated build has proven itself on a full sequential run, revisiting this list to parallelize Phase Agents is a reasonable follow-up, not a starting point.

### Risk Assessment

| Phase | Risk Level | Reason |
|---|---|---|
| 1 | Medium | Process pool complexity, IPC design |
| 2 | High | New architectural patterns, SQLite contention |
| 3 | Medium | Git worktree edge cases |
| 4 | High | Workflow DSL design, execution engine |
| 5 | Low | UI enhancements, well-understood |
| 6 | Medium | Compaction algorithms need tuning |
| 7 | Low | Integration work, well-scoped |
| Permission Modes | Medium | Safety classifier categories need careful tuning to avoid false blocks |
| Agent Preset Schema | Low | Mostly a formalization of fields already implicit in SubAgent/Teammate |
| Nested Context Loading | Low | Mechanical extension of existing CLAUDE.md discovery, well-scoped |

### Parallelization Opportunities

After Phase 1:
- Phase 6 (Context) can run in parallel with Phase 2
- Phase 5 (UI) can run in parallel with Phase 2
- Phase 7 (Integrations) can start anytime

After Phase 2:
- Phase 3 (Worktree) can run in parallel with Phase 4

### Contingency Plans

If Phase 1 (Parallel Execution) proves too complex:
- Start with async/event-loop approach instead of process pool
- Use ReactPHP instead of proc_open
- Defer true parallelism to v2.1

If Phase 2 (Agent Teams) hits architectural issues:
- Start with simpler lead-only model (no peer messaging)
- Use file-based task list instead of SQLite
- Defer peer messaging to v2.1

If Phase 4 (Workflows) design is problematic:
- Start with YAML-only (no PHP DSL)
- Simple sequential execution first
- Add parallel/pipeline as v2 features

---

## Reference Architecture

### OpenCode Agent Team Architecture

OpenCode implements agent teams with peer-to-peer messaging:

Structure:
- Lead agent coordinates
- Teammates are independent processes
- JSONL inbox for each teammate (append-only, O(1) writes)
- Event-driven delivery (no polling, auto-wake on message)
- Two-level state machines: member status + execution status

Communication flow:
1. Lead spawns teammate with initial context
2. Teammate processes task, may message peers directly
3. Teammate completes, sends result to lead inbox
4. Lead synthesizes, may spawn more teammates
5. Lead merges teammate changes

State machine - Member status: active, idle, shutdown, interrupted, errored
State machine - Execution status: pending, reasoning, tool_use, waiting, etc.

Key innovation: Delegate mode - lead restricted to coordination-only tools.

### Claude Code Agent Teams Architecture

Claude Code uses leader-centric model:

Structure:
- Lead (main session) assigns tasks
- Shared task list with atomic claiming
- Teammates report to lead only (no peer messaging)
- Mailbox system for lead-to-teammate communication

Communication flow:
1. Lead creates team, assigns initial tasks
2. Teammates claim tasks from shared list
3. Teammates work independently
4. Teammates send results back to lead
5. Lead synthesizes and decides next steps

Task claiming: File locking prevents race conditions
Dependencies: Tasks can block on other task completion
Idle notifications: Lead auto-wakes when teammate finishes

### Sugar-Crush Target Architecture

Target architecture for sugar-crush v2:

Layer 0: Safety and Context
- PermissionGate (mode-based + rule-based tool gating)
- HookDispatcher (lifecycle events, exit-code semantics)
- InstructionFileLoader (root + nested CLAUDE.md/AGENTS.md)
- AgentPresetRegistry (agent definition resolution)

Layer 1: Core Runtime
- Chat (TEA Model)
- Runtime (tool execution)
- Providers (LLM abstraction, including the dev-sglang testing endpoint)

Layer 2: Agent Orchestration
- AgentWorkerPool (parallel execution)
- TeamManager (team lifecycle)
- TaskList (shared task management)
- Mailbox (inter-agent messaging)

Layer 3: Isolation
- WorktreeManager (git worktree per agent, .worktreeinclude, cleanup sweep)
- PathJail (file operation containment)

Layer 4: Workflows
- WorkflowEngine (orchestration)
- WorkflowRegistry (discovery)
- TaskBuilder (fluent API)

Layer 5: UI/Tools
- TUI (agent view, split panes, session tabs)
- Skills (progressive disclosure, nested per-lib loading, context fork)
- Hooks (pre/post tool guards, team quality gates)

### Key Class Relationships

AgentWorkerPool has-a ProcessExecutor
Team has-many Teammate
Team has-a TaskList
Team has-a Mailbox
Teammate has-a WorktreeManager
WorkflowEngine uses TaskList
WorkflowEngine uses AgentWorkerPool
PermissionGate evaluates ToolCall before Runtime executes it
AgentPreset has-a PermissionMode
AgentPresetRegistry resolves AgentPreset by description match
InstructionFileLoader feeds Chat's context assembly
SkillLoader feeds Chat's context assembly alongside InstructionFileLoader

---

## Testing Strategy

### Unit Tests

Each new class has corresponding test file:

AgentWorkerPoolTest:
- testExecuteAllConcurrently: 5 agents run in parallel, verify timing
- testMaxConcurrentRespected: never exceed configured limit
- testTimeout: agent that exceeds timeout gets TimedOut status
- testCancellation: cancelAll stops pending agents
- testExecuteOne: single agent execution with result

TaskListTest:
- testAddTask: new task appears in pending list
- testClaimTask: first claimer wins, second gets false
- testClaimTaskWithDeps: blocked until deps complete
- testCompleteTask: status changes, result stored
- testConcurrentClaim: file lock prevents race condition

MailboxTest:
- testSendMessage: message appears in recipient inbox
- testReceiveMessage: yields message from inbox
- testPeekUnread: returns messages without marking read
- testMarkRead: message marked as read

WorktreeManagerTest:
- testCreateWorktree: new worktree with branch exists
- testRemoveWorktree: worktree directory deleted
- testGetWorktreePath: returns correct path
- testIsolatePath: paths are jailed to worktree

ContextCompactorTest:
- testShouldCompactAt95Percent: returns true when near limit
- testStage1RemovesToolResults: successful Read results removed
- testStage5PreservesDecisions: architectural decisions kept
- testCompactionReducesTokens: token count decreases

PermissionGateTest:
- testManualModePromptsOnWrite: default mode never auto-approves a file edit
- testAcceptEditsScopedToWorkingDirectory: writes outside working dir still prompt
- testPlanModeBlocksAllEdits: edits rejected regardless of requested tool
- testAutoModeClassifierBlocksDangerousCategories: force-push, mass delete, etc. all rejected
- testAutoModePausesAfterRepeatedBlocks: 3 consecutive or 20 total blocks flips back to prompting
- testDontAskDeniesWithoutPrompting: unlisted tool call denied, session never blocks
- testBypassStillGuardsRootDeletion: rm -rf / rejected even in bypass mode

HookDispatcherTest:
- testExitCode2BlocksPreToolUse: tool call never executes, stderr fed back to agent
- testExitCode2OnPostToolUseUsesContinueOnBlock: action already ran, stderr surfaced without discarding result
- testUserPromptSubmitExitCode2DiscardsPrompt: prompt never reaches the agent
- testTeammateIdleHookAssignsMoreWork: idle teammate receives new task instead of going idle
- testTaskCompletedHookCanRejectCompletion: task stays in_progress when hook exits 2

InstructionFileLoaderTest:
- testRootFileAlwaysLoaded: CLAUDE.md/AGENTS.md at repo root present in every session
- testNestedFileInjectedOnFirstTouch: candy-shine/CLAUDE.md loads only after a file in that dir is read
- testNestedFileNotReinjected: second read of same lib doesn't duplicate context
- testForcedInstructionsResolveGlobs: candy-*/CALIBER_LEARNINGS.md pattern loads every match

SkillLoaderTest:
- testOnlyNameAndDescriptionLoadedAtStartup: full SKILL.md body absent until match
- testDisableModelInvocationSkipsAutoTrigger: skill never auto-fires, still runs manually
- testUserInvocableFalseHidesFromCommandSurface: skill absent from slash menu, still auto-triggers
- testContextForkRunsInIsolatedSubAgent: forked skill has no access to calling conversation history

### Integration Tests

Test interactions between components:

TeamLifecycleTest:
- Create team with 3 teammates
- Add tasks, claim, complete
- Verify task results returned to lead
- Cleanup removes all resources

WorkflowExecutionTest:
- Load YAML workflow
- Execute with mock agents
- Verify parallel stages run concurrently
- Verify pipeline stages run sequentially
- Verify context passed between stages

WorkflowResumptionTest:
- Start long workflow
- Simulate interrupt mid-execution
- Resume workflow from saved state
- Verify completed stages not re-run

### E2E Tests

Full agent coordination tests:

FanOutResearchTest:
- Spawn 5 explorer agents on different directories
- Each does independent research
- Lead synthesizes results
- Verify no file conflicts

MultiAgentRefactorTest:
- Team: architect + 2 coders + reviewer
- Architect plans refactor
- Coders implement in parallel (isolated worktrees)
- Reviewer verifies
- Lead merges changes

### Mocking Strategies

For unit tests:
- Mock ExecutorInterface for AgentWorkerPool
- Mock ProcessExecutor without actual processes
- Use in-memory SQLite for TaskList tests
- Mock file system with vfsStream

For integration tests:
- Use EchoProvider (offline) for LLM calls
- Real SQLite with test database
- Real file system with temporary directories

For E2E tests:
- Use Claude Code CLI with mock mode if available
- Real process execution with timeout
- Disposable git repositories

### Test Coverage Goals

Phase 1: 90% coverage on AgentWorkerPool, ExecutorInterface, ProcessExecutor
Phase 2: 90% coverage on Team, TaskList, Mailbox
Phase 3: 85% coverage on WorktreeManager
Phase 4: 85% coverage on WorkflowEngine
Phase 5: 80% coverage on UI components
Phase 6: 85% coverage on ContextCompactor, MemoryStore
Phase 7: 80% coverage on new integrations

---

## Rollout Checklist

### Phase 1: Parallel Execution Engine

Before release:
- All unit tests pass
- Integration tests pass with 5 concurrent agents
- ProcessExecutor handles segfault without crashing parent
- Timeout works correctly
- Cancellation works correctly
- Backward compatible with existing code
- No new required PHP extensions

### Phase 2: Agent Teams

Before release:
- Team can be created with lead + up to 5 teammates
- Tasks can be added, claimed, completed
- Task claiming is atomic under concurrent access
- Mailbox delivers messages between agents
- Idle notifications work
- Team cleanup removes all resources

### Permission Modes and Hooks

Before release:
- All six permission modes enforce their documented behavior
- Auto mode's safety classifier covers every listed dangerous-action category
- BypassPermissions unreachable mid-session, still blocks root/home deletion
- Hook exit codes 0/1/2 behave per-event as documented, including continueOnBlock
- TeammateIdle/TaskCreated/TaskCompleted hooks integrate with Phase 2's task list
- Existing AuditHook/ConfirmRemoveHook/ProtectFilesHook/ScriptHook unchanged

### Phase 3: Worktree Isolation

Before release:
- Each agent gets own worktree on spawn
- File edits in one worktree don't affect others
- PathJail prevents access outside worktree
- Worktrees cleaned up properly
- Merging worktree changes works
- No git conflicts between worktrees

### Phase 4: Dynamic Workflows

Before release:
- PHP workflow DSL parses correctly
- YAML workflow parses correctly
- parallel() executes tasks concurrently
- pipeline() chains stages correctly
- Context interpolation works
- Workflows can be paused and resumed
- Verification phases work

### Phase 5: UI/UX

Before release:
- /agents command shows agent list
- Can peek at agent output
- Can cancel agents from view
- Split pane mode works
- Stall detection shows warning
- Background agents don't block input

### Phase 6: Context and Memory

Before release:
- Context compacts at 95% threshold
- Critical information preserved after compaction
- /memory add/search/list/delete work
- Memory persists across restarts
- Session resume shows summary
- Agent state restored on resume

### Phase 7: Integrations

Before release:
- Git MCP server handles all operations
- LSP provides go-to-definition
- Deep research workflow runs
- New skills discoverable
- Skills inject context correctly

### Nested Context and Skill Loading

Before release:
- Nested CLAUDE.md/AGENTS.md files inject once per session, on first touch of their directory
- Root instruction files still load unconditionally at session start
- Forced `instructions` globs in config resolve correctly across all 52 libs
- Skill progressive disclosure keeps unmatched skills to name+description only
- disable-model-invocation and user-invocable flags behave independently
- Context-fork skills return only their final result to the calling session

### Breaking Changes Checklist

For each phase, verify:
- No changes to existing public API signatures
- Existing tests still pass
- Configuration files remain compatible
- Session data from old versions migrates safely
- Deprecation notices added for removed features

### Performance Targets

Phase 1:
- 5 agents complete in under 3x single agent time
- Memory per agent under 50MB additional

Phase 2:
- Task claim latency under 10ms
- Mailbox delivery latency under 100ms

Phase 3:
- Worktree creation under 500ms
- Path isolation adds under 5ms per file operation

Phase 4:
- Workflow parsing under 100ms
- Workflow execution matches manual agent speed

Phase 5:
- Agent view renders at 60fps
- Split pane switch under 100ms

### Documentation Requirements

Before each phase ships:
- README updated with new features
- SKILL.md files for new skills
- Example workflows in docs/
- Migration guide if breaking changes
- Changelog entry with version number

---

END OF PLAN
