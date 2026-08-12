# Sugar-Crush Feature Remediation & Enhancement Plan

**Version:** 1.0
**Date:** 2026-08-10
**Status:** Draft
**Target:** sugar-crush v2.1 — closing the gaps documented in `crush_feat.md`
**Spec source:** `/home/sites/sugarcraft/crush_feat.md` (this document does not repeat that content — every step below points at a specific section/recommendation in it)

---

## Executive Summary

`crush_feat.md` is a 12-section research dossier comparing sugar-crush against opencode, Claude Code, and the broader AI-coding-CLI landscape. Its dominant finding: **sugar-crush has already built the right subsystem for nearly every feature area investigated — Skills, SessionStore/Picker/Tabs, the BackgroundSupervisor daemon, candy-mouse/candy-zone hit-testing, candy-mosaic image rendering, root CLAUDE.md/AGENTS.md loading — but in most cases it was never wired into the live `bin/sugarcrush` runtime path.** On top of that, it found a small number of real bugs (SGLang streaming tool-calls silently dropped, `--help` opening a blocking TUI instead of printing usage) and a shorter list of genuinely missing features (non-interactive CLI mode, a repo-map, auto-commit hooks, file-level checkpoints).

This plan turns that dossier into an executable, phased build — using the same multi-agent orchestration mechanism (`sugarcrush-orchestrator` → Phase Lead → Builder/Reviewer/Fixer/Committer, running unattended on OpenCode against a MiniMax-M2.7 backend) that built the original agent-orchestration infrastructure in `crush_code_plan.md`. **It is not a copy of that mechanism — it is a hardened revision of it**, informed directly by what actually happened the first time: `crush_code_update.md`'s independent audit found the original run's self-reported progress could not be trusted (three contradicting tracking layers, misleading commit messages, at least one fabricated-success feature, and the exact "wired in tests but not in production" pattern repeated across four different phases), and the resulting remediation pass (`sugar-crush/.sugar-crush-build/remediation-progress.json`, items R1–R31) took as many as 5 review cycles per item to actually converge — and even then, two of its items (R19/R20, both `SessionStore`/session-tab wiring) were explicitly marked "done" while *disclosing* — not fixing — the exact gap that `crush_feat.md` independently rediscovered days later. See "Lessons Applied From The Previous Build" below for the full accounting and exactly which process rules each lesson produced.

### What needs to be built

Grouped by risk/file-overlap into five **Waves** (not a strict phase-1-through-N sequence — see "Why Waves, not Phases" below):

| Wave | Focus | Files touched | Concurrency |
|---|---|---|---|
| 1 | Foundational, low-risk, file-disjoint work: SGLang provider fixes, environment/context-file wiring, Skills matching infrastructure, cross-tool skill/agent import, CLI non-interactive mode, `Edit` diff generation, candy-mosaic dependency | `src/Providers/*`, `src/Context/*`, `src/Skills/SkillMatcher.php`, `src/Tools/BuiltIn/SkillTool.php`, `src/Cli/*`, `src/Tools/BuiltIn/Edit.php`, `composer.json` | Up to 9 fully independent tracks in parallel |
| 2 | The `Chat.php`/`Renderer.php` cluster — tool-call pipeline unification, permission-prompt UI, mouse wiring, image-render wiring, command-palette unification, session/command auto-summarization | `src/Chat.php`, `src/Renderer.php`, `src/Tools/*`, `src/Message.php`, `src/Palette/*`, `src/Commands/*` | **One continuous serialized track** — see "The Chat.php/Renderer.php rule" |
| 3 | Live-wiring that depends on Wave 2's `Chat.php` changes landing first: session store/tabs/dashboard, Skills `AppBuilder` population, candy-core Ctrl+Tab decode (cross-lib) | `src/Cli/Bootstrap.php`, `src/App/AppBuilder.php`, `src/Sessions/*`, `src/Tui/Components/AgentDashboardPane.php` (new), `candy-core/src/InputReader.php` | 3 tracks, file-disjoint from each other |
| 4 | Final validation: integration/E2E tests proving Wave 1–3 features are reachable from a real `bin/sugarcrush` invocation, documentation catch-up | `tests/Integration/*`, `README.md`, `CHANGELOG.md`, `sugar-crush/CALIBER_LEARNINGS.md` | 3 tracks, mostly parallel-safe |
| 5 | The whole-plan audit — not a build wave, a **verification pass** run via `/sugarcrush-feat-review` after Wave 4 lands | none (read-only, spawns fix tracks back into the relevant wave if it finds problems) | n/a |

### Approach

Reuse the proven three-tier agent hierarchy from `crush_code_plan.md` (`sugarcrush-orchestrator` → `sugarcrush-phase-lead` → Builder/Reviewer/Fixer/Committer), cloned into a parallel `sugarcrush-feat-*` agent family so the original plan's agents and history stay untouched as a historical record. Every process rule below either matches the original protocol unchanged, or is a direct, named response to a specific failure documented in `crush_code_update.md`. Two entirely new pieces this time: an **Agent Failure Retry** protocol (spawn a fresh agent to redo a task that came back wrong, rather than trying to patch a bad response in place) and a **Final Plan Review** pass (`/sugarcrush-feat-review`) that re-audits the *entire* finished plan the same way the original build was independently re-audited after the fact — except this time it's a formal, repeatable command instead of a one-off manual effort.

---

## Lessons Applied From The Previous Build

Every rule in the Execution Protocol below traces back to one of these. Read this section before the protocol — it's the "why," and skipping it makes several of the protocol's rules look like arbitrary process overhead instead of what they actually are: direct fixes for things that verifiably went wrong last time.

**Lesson 1 — Self-reported progress tracking cannot be trusted on its own.** The original run ended up with *three* separate, mutually contradicting tracking layers: the top-level `plan-progress.json` said only P0 was done; every per-phase `phase-P*-progress.json` claimed all its own steps were done; and a third layer, `.opencode/memory/phase-P5-progress.md`/`phase-p6-progress.md`, showed those same phases' steps as entirely `not_started`. None of the three agreed with either of the others, and none of them agreed with the actual state of the code.
→ **Rule produced:** one canonical tracking file per scope, explicitly annotated as canonical; every review — step-level or the final whole-plan review — re-derives status from `git log`/`git diff`/running the actual test suite, never from a JSON file's own claim. See "Progress tracking" and "Final Plan Review" below.

**Lesson 2 — Commit messages were frequently misleading, sometimes badly.** A commit claiming to "remove" a field left it present and load-bearing. A commit claiming to add `BackgroundSupervisor` added three unrelated support classes and not the supervisor itself. A "phase completion — all verified and wired" commit's actual diff was cosmetic fixes plus a wholesale rewrite of a progress file marking four unrelated phases done with zero corresponding work. A "stale test removal" commit deleted a fully-passing 47-test suite under a misleading pretext.
→ **Rule produced:** the Step Review Agent's category 1 (Requirements Traceability) now explicitly requires comparing the commit-message-to-be (or the builder's own summary) against the *actual diff content*, not just checking the diff exists. A builder/fixer claiming to have "removed," "wired," or "verified" something is a claim the reviewer must independently re-derive from source, every single time — never accepted at face value. See the Step Review Agent checklist, category 1 and category 11 (new).

**Lesson 3 — The single most repeated, most severe failure pattern: components that are individually correct and well-tested, with no path from `bin/sugarcrush`'s real boot sequence to them ever being reached.** This happened to `PathJail` (never constructed for a live agent's tools), `AgentViewPane`/`AgentStatusBar`/`KeyboardHandler`/`BackgroundSupervisor`/`SessionTabs` (all correct in isolation, all unreachable — `Renderer` called a stub that always printed "(no active agents)"), the entire Skills subsystem (never populated at app bootstrap), `McpRouter` (a correct, tested class that `McpClient` never actually consulted), and the `dev-sglang` provider config (byte-for-byte correct, read by nothing). Two of these were explicitly re-disclosed rather than fixed in the R19/R20 remediation items — and `crush_feat.md`, researching independently days later with no knowledge of any of this, **rediscovered the exact same Skills-never-populated and SessionStore-never-called gaps from scratch.** This is not a one-off miss; it is the load-bearing failure mode of this whole codebase's development pattern.
→ **Rule produced:** a new, named, mandatory review category — **Production Reachability** — added as category 11 of the Step Review checklist, distinct from and in addition to Test Coverage. "The class works and is tested" is not sufficient; the reviewer must trace the actual call path from `bin/sugarcrush` (or the nearest already-established production entry point — `Bootstrap::chat()`, `AppBuilder::build()`) to the new/fixed code and confirm it fires with zero special test-only setup. Every step in this plan whose spec section in `crush_feat.md` uses the words "never wired," "never called," "dead code," or "disconnected" is *only* considered done when this category passes — a disclosed-but-unfixed gap is not an acceptable outcome for this plan the way it was treated as one in R19/R20.

**Lesson 4 — Fabricated success is worse than an honest failure.** `/share`'s `ShareUploader::upload()` performed no I/O of any kind — the session content was hashed and discarded, and the "signed" URL used a literal hardcoded secret committed to the public source tree with a 32-bit truncated HMAC, forgeable by anyone with the repo. The command reported success the whole time.
→ **Rule produced:** any step where a genuine implementation is out of scope (no real backend target specified, external dependency unavailable, etc.) must make the feature **honestly fail or honestly report "not implemented"** — never fabricate a success path. This is now an explicit, named check in the Step Review checklist (category 3, Correctness) and is called out again in "What honest incompleteness looks like" below.

**Lesson 5 — Even the tracking-of-tracking can drift, in a genuinely funny/instructive way.** The remediation's own final tracking pass (`R31-finalize`) tried to record how many review cycles *its own item* took — but each correction to that count was itself a new commit, which meant the count was stale the instant it was written, four times in a row, before it finally accounted for the commit *about to be made* rather than only commits already visible.
→ **Rule produced:** whichever agent updates a progress/tracking file must count forward — including the commit it is about to make — not just what's visible in `git log` before it writes. Called out explicitly in "Progress tracking" below.

**Lesson 6 — Concurrent, unrelated processes can touch the same repo mid-run.** During the original audit, something else running on the machine actively edited `ProcessExecutor.php` and deleted its test file mid-review. The reviewing agent caught it by pinning its verdict to a `git archive` snapshot taken before continuing.
→ **Rule produced:** every Step Review Agent snapshots `git rev-parse HEAD` at the start of its review and re-confirms it hasn't moved before finalizing a verdict; if it has, the review is voided and restarted against the new HEAD rather than silently mixing verdicts from two different states of the tree.

**Lesson 7 — An automated instruction-pattern scanner can false-positive on an agent legitimately discussing a security-relevant class name (e.g. `PermissionMode::BypassPermissions`).** Two of fourteen original review agents' outputs were flagged this way; both were confirmed as false positives on manual check. (This session's own background-agent notifications hit the identical false-positive — flagged for discussing `permissions-allow-deny`/`bypass-permissions`/`system-reminder-tag` patterns while researching Claude Code's own permission-mode vocabulary. Same non-issue, same resolution: read the flagged content, confirm it's legitimate domain discussion, move on.)
→ **Rule produced:** noted here so nobody running this plan panics the first time it happens — a flagged agent output discussing `PermissionMode`, hook exit codes, or similar security vocabulary from this very codebase is expected and not, on its own, a sign of a compromised agent.

**Lesson 8 — Pushing to `master` after every single step, from many concurrent tracks, is a real collision risk once true parallelism is in play.** The remediation pass's ground rules suspended per-step pushes entirely in favor of one push at the very end of the whole effort. That's safer against push races but riskier against losing local work if the run is interrupted for days.
→ **Rule produced:** a middle path — **commit locally after every step** (so an interruption never loses more than one step's work), but **serialize the actual `git commit`/`git push` operations** across all concurrently-running tracks through a single-slot queue, even when their Builder/Reviewer/Fixer work is running fully in parallel. See "The git concurrency rule" below.

**Lesson 9 — A specific git mechanic worth encoding verbatim.** For an already-tracked file you only modified the contents of, `git commit -m "..." -- path/to/File.php` picks up the working-tree change without a separate `git add` step. This does **not** work for brand-new untracked files — `git commit -- newfile.php` fails with "pathspec did not match any files" if it's never been added. New files need `git add path/to/NewFile.php` immediately before the commit, named individually, never `-A`/`.`.
→ **Rule produced:** baked verbatim into the Step Commit Agent's instructions below.

**Lesson 10 — "Tests pass" needs a baseline to be a meaningful claim.** The remediation ground rules required confirming "0 new failures/errors versus the pre-fix baseline (2,854 tests / 7,202 assertions / 0 failures / 0 errors / 86 warnings / 1 skipped)" — a concrete number captured once, up front, that every subsequent step's test run is compared against, not just "phpunit exited 0" in isolation.
→ **Rule produced:** Wave 0 (bootstrap) captures this baseline for real, and every Step Review Agent's category 7 (Regression Safety) compares against it explicitly, not against a vague "should still pass."

---

## Why Waves, not Phases

`crush_code_plan.md` organized its 108 steps into 9 strictly sequential phases (P0 → P7). That was the right call when almost every phase built new infrastructure from scratch with genuinely serial dependencies (Phase 1's worker pool has to exist before Phase 2's teams can use it, etc.). This plan's work is different in shape: it's mostly independent *fixes and wiring* across many already-existing subsystems, and `crush_code_update.md`'s own remediation effort found that grouping strictly by "what phase does this belong to" left real, checkable parallelism on the table — its Wave 1 ran 17 fully independent fix-tracks concurrently precisely because it grouped by **file overlap**, not by subject-matter phase.

This plan does the same: a step's Wave assignment is determined by whether its file list overlaps `Chat.php`/`Renderer.php` (the two busiest, most-shared files in the codebase — confirmed as such by `crush_code_update.md`'s own observation that "`Chat.php` is the busiest shared file in the whole codebase"). Anything that doesn't touch either of those two files is eligible for Wave 1's full parallelism. Anything that does is serialized into Wave 2, in dependency order, as one continuous track — never run two Wave-2 steps' Builder/Review/Fix/Commit loops concurrently, even if they nominally don't conflict, because `Chat.php`/`Renderer.php` are large enough that "nominally disjoint" line ranges still collide constantly in practice (this is a direct lesson from `crush_code_plan.md`'s own step-ordering advice, reinforced by the audit).

---

## The Chat.php/Renderer.php rule

If a step's file list includes `src/Chat.php` or `src/Renderer.php`, it belongs to Wave 2, and Wave 2 steps run **one at a time, start to finish, in the order given in the Wave 2 Step Manifest** — Builder, Review, Fix-loop, Commit, fully complete, before the next Wave 2 step's Builder Agent is spawned. This is slower than parallelizing everything, and that's an accepted, deliberate tradeoff: it is the single rule most directly responsible for `crush_code_update.md`'s Wave 2 (its own "Chat.php cluster") landing clean, versus the amount of cross-contamination and misleading-diff findings documented everywhere else in that audit.

---

## Execution Protocol for Orchestrated Implementation (OpenCode + MiniMax-M2.7)

This section is the complete, literal operating manual for the unattended run, in the same spirit as `crush_code_plan.md`'s own protocol section — read it once, in full, before starting anything.

### The rule that shapes everything else: keep every agent's context small

`MiniMax-M2.7` tops out at 200K tokens. The hard target for this plan is stricter than the original's: **every agent must finish before it hits roughly 150K tokens**, leaving 50K of real headroom for tool-call overhead, the model's own reasoning tokens, and the occasional larger-than-expected file — not as an aspiration, as the number every step below was actually scoped against. This is achieved structurally, not by asking agents to "be careful": **every agent that touches code is spawned fresh, does one small job, and is discarded.** A step that goes through five review-and-fix cycles produces five separate, from-scratch agents, not one agent whose context grows five times over. Nothing is reused between loop iterations except what is explicitly written into the next agent's prompt.

**Concrete sizing guidance, tighter than the original plan's:** a step should touch **1-3 non-test source files** it needs to actually read and reason about (test files and brand-new small files don't count against this — reading a 40-line new test you're about to write costs nothing; reading and understanding a 400-line existing class you're modifying costs real context). A step should require reading roughly **under 600 lines of pre-existing code** to understand what to do, combined across every file it touches. A step that bundles two genuinely separate concerns — even if they'd naturally end up in the same file — should still be two steps if the *reasoning* required for each is independent (e.g. "add a new parser class" and "wire that parser into a factory" are two different kinds of reasoning even though they might touch overlapping files).

**This is a scope constraint, not an instruction-brevity constraint.** The Builder/Reviewer/Fixer/Committer prompt templates below stay exactly as detailed as they are — the fix for context pressure is never "give the agent a shorter prompt," it's "give the agent a smaller job." A step with a tightly-scoped 1-3-file job still gets the full prompt template, full convention checklist, full documentation/reachability requirements; it just has less *work* to do inside that template, which is what actually keeps the resulting context small.

**Every step manifest below has already been split with this budget in mind** — several steps that would have been one unit of work in the original plan's looser 5-file/few-hundred-line guidance are broken into lettered sub-steps here (`W2.S1a`, `W2.S1b`, ...) specifically because `Chat.php`, `Renderer.php`, `Runtime.php`, and `EngineBackend.php` are large, complex files where "read enough to understand the existing pattern" alone can consume real context before any writing starts. If, despite this, a Builder Agent finds mid-step that it's approaching its budget before finishing — it's had to read more files than expected, or the existing code is more tangled than the spec implied — **stop, report back exactly what was read and what's still incomplete, and let the Phase Lead split the remainder into a new step** rather than pushing through into the danger zone. A half-finished step reported honestly is recoverable; a step that silently ran long and produced rushed, under-reasoned code in its last 20K tokens is not.

### The three-tier hierarchy, plus a fourth role this time

```
Plan Orchestrator                  (exactly one instance, lives for the entire run)
 └── Phase Lead                     (exactly one at a time per Wave, spawned in Wave order)
      └── for every Step in that Wave's Step Manifest, in order (Wave 2: strictly serial; Wave 1/3/4: parallel where file-disjoint):
           1. Step Builder Agent    — implements the step, per crush_feat.md's spec pointer
           2. Step Review Agent     — checks the work against the full 11-category checklist
           3. Step Fix Agent        — only spawned if step 2 found problems; addresses all of them
           (repeat 2-3 until PASS, cycle cap 5, OR the Agent Failure Retry protocol below fires)
           4. Step Commit Agent     — commits the finished step, queued through the git concurrency rule

Plan Auditor                       (NEW — not part of the normal build loop; spawned once, after every
                                     Wave reports done, by the /sugarcrush-feat-review command)
 └── re-derives the status of every single step in every Wave from git log + live source, independent
     of every progress-tracking file above; any gap it finds gets a fresh Fix Agent spawned directly
     back into the relevant Wave's track, then re-reviewed by a fresh Step Review Agent as normal.
```

Nobody at the Plan Orchestrator or Phase Lead tier reads or writes a single line of source code, runs a test, runs `composer`, or does any research themselves — same rule as before, for the same reason (keeps their own context tiny and their job mechanical: decide who to spawn next, record what happened).

### Tool access per role

| Role | Allowed tools | Explicitly forbidden |
|---|---|---|
| Plan Orchestrator | spawn-agent, Read (its own progress file + this plan + `crush_feat.md`) | Bash, Write, Edit, anything touching `src/`, `tests/`, or any lib directory |
| Phase Lead | spawn-agent, Read (its own progress file + its Wave's section here + the `crush_feat.md` sections it points at), Write (its own progress file only) | Bash, Edit, anything touching `src/`, `tests/`, or any lib directory |
| Step Builder Agent | Read, Write, Edit, Bash, Glob, Grep | — full access, scoped by instruction |
| Step Review Agent | Read, Glob, Grep, Bash (read-only: `phpunit`, `php-cs-fixer --dry-run`, `composer validate`, `composer update`, `git status`, `git diff`, `git log`, `git show`, `git blame`, `git rev-parse HEAD`) | Write, Edit, `git commit`, `git push`, `git checkout`, `git reset`, anything that changes repository state |
| Step Fix Agent | Read, Write, Edit, Bash, Glob, Grep | — same as Builder, scoped by instruction to the findings list only |
| Step Commit Agent | Read, Bash (git commands only) | Write, Edit |
| Plan Auditor | Read, Glob, Grep, Bash (same read-only allowlist as Step Review Agent, plus `find /tmp` for leaked-artifact checks) | Write, Edit, any git state-changing command — it reports and hands findings to the Orchestrator to route into Fix Agents, it never fixes anything itself |

### Progress tracking

**One canonical file per scope, and only one.** This is the direct fix for Lesson 1.

- `.sugar-crush-build/feat-plan-progress.json` — one entry per Wave (`W1` .. `W5`), `{"status": "not_started"|"in_progress"|"done"|"blocked"}`. Written only by the Plan Orchestrator.
- `.sugar-crush-build/feat-wave-<N>-progress.json` — one entry per step (`{"stepId": "W1.S1", "status": ..., "reviewCycles": n, "lastFindings": [...], "commitHash": "..."}`). Written only by that Wave's Phase Lead.
- `.sugar-crush-build/feat-audit-log.json` — appended to only by the Plan Auditor (see "Final Plan Review" below), never by anything in the normal build loop. This file's existence and growing length is itself evidence the audit ran for real — an audit that never appends anything here didn't do its job.

None of these files are ever rewritten wholesale by anything other than the one role that owns them. Nobody "helpfully" pre-marks a step done because it looks straightforward — a step's status is `"done"` only after its own Step Review Agent returns `PASS` and its own Commit Agent confirms a real push, full stop. Per Lesson 5, whichever agent writes a `reviewCycles` or similar running count must count the commit it is about to make as already having happened, not just what `git log` shows before writing.

### Concrete OpenCode wiring

| Plan role | OpenCode agent | Spawn tool | Defined in |
|---|---|---|---|
| Plan Orchestrator | `sugarcrush-feat-orchestrator` | n/a — `mode: primary`, switched to directly | `.opencode/agents/sugarcrush-feat-orchestrator.md` |
| Phase Lead | `sugarcrush-feat-phase-lead` | `task` | `.opencode/agents/sugarcrush-feat-phase-lead.md` |
| Step Builder Agent | `coder` (existing agent, reused as-is, same as the original plan) | `task` | `.opencode/agents/coder.md` |
| Step Review Agent | `sugarcrush-feat-reviewer` | `delegate` | `.opencode/agents/sugarcrush-feat-reviewer.md` |
| Step Fix Agent | `coder` (same existing agent, fix-scoped task) | `task` | `.opencode/agents/coder.md` |
| Step Commit Agent | `sugarcrush-feat-committer` | `task` | `.opencode/agents/sugarcrush-feat-committer.md` |
| Plan Auditor | `sugarcrush-feat-final-reviewer` | `delegate` (read-only, same routing class as `sugarcrush-feat-reviewer`) | `.opencode/agents/sugarcrush-feat-final-reviewer.md` |

These are **clones**, not edits, of the original `sugarcrush-orchestrator`/`sugarcrush-phase-lead`/`sugarcrush-reviewer`/`sugarcrush-committer` agents — the originals still exist, still point at `crush_code_plan.md`, and remain usable if that plan is ever resumed or re-audited. `coder` is reused unchanged, exactly as the original plan reused it — its existing permission profile (`read`/`write`/`edit`/`glob`/`grep`/`bash` all allow, forbidden from `git commit`) already matches what a Step Builder or Step Fix Agent needs here too. `sugarcrush-feat-final-reviewer` is the one genuinely new role — see "Final Plan Review" below for what it does; its permission profile is a byte-for-byte copy of `sugarcrush-feat-reviewer`'s (read-only, same bash allowlist) since it has exactly the same trust level, just a different, wider-scoped job.

Permission profiles for all five new agents are added to `.opencode/opencode.jsonc` under `"agent"`, following the exact block shape already used for `sugarcrush-orchestrator`/`sugarcrush-phase-lead`/`sugarcrush-reviewer`/`sugarcrush-committer` (see that file for the literal JSON — it is not repeated here since this document is process, not config).

Kick off (or resume) a run with `/sugarcrush-feat-build`. Run the whole-plan audit at any point after the tracked Waves report done (or at any point mid-run, if you want a sanity check) with `/sugarcrush-feat-review`.

### Reading the Step Manifest tables

Same convention as the original plan, with one change: the "Where to look" column below points into **`crush_feat.md`**, not into this document, since `crush_feat.md` is where the actual specification/rationale/code sketches live. A Step Builder Agent's job is: read the plan section here for its Wave and this specific step row, then read *only* the pointed-to subsection of `crush_feat.md` (almost always `## N. Section Title` → a lettered subsection like `### E) Recommendations` → a specific numbered item like `E3`), then implement exactly that.

### Spawning a Phase Lead

The Plan Orchestrator spawns exactly one Phase Lead at a time, in Wave order: **W1 → W2 → W3 → W4**, then, once W4 reports done, the human (or the next `/sugarcrush-feat-review` invocation) triggers the Plan Auditor pass described under "Final Plan Review." Do not start W2 until every W1 step that W2 depends on (see W2's own manifest — some W2 steps depend on specific W1 steps, e.g. the mouse-wiring step depends on W1's `Renderer.php`-adjacent primitives already existing) has landed. W3 needs W2 fully done, not just partially — W3's session-store wiring touches `Chat.php` in small, additive ways that must land after Wave 2's larger `Chat.php` rewrite to avoid exactly the kind of collision Lesson 8 is about.

Prompt template:

```
You are the Phase Lead for Wave <WAVE_ID> ("<WAVE_TITLE>") of the sugar-crush feature build.

Read the section of /home/sites/sugarcraft/crush_feat_plan.md starting at the
heading "## Wave <N>: <WAVE_TITLE>" and ending at the next "---" separator.
Pay special attention to the Step Manifest table — it lists every step you are
responsible for, its file list, its dependencies, and where in
/home/sites/sugarcraft/crush_feat.md its real specification lives.

Your job, and ONLY your job:
1. Read your progress file at .sugar-crush-build/feat-wave-<WAVE_ID>-progress.json
   (create it, seeded from the Step Manifest at {"status": "not_started"}, if it
   doesn't exist yet).
2. If this is Wave 2: pick steps strictly in manifest order, one at a time, full
   loop to completion before starting the next. If this is Wave 1, 3, or 4: you
   may run multiple steps' Builder Agents concurrently ONLY if their file lists
   are completely disjoint (check this yourself against the manifest before
   doing it) -- but never run two steps' review/fix/commit loops concurrently,
   even then.
3. Run each step through the Builder -> Review -> Fix loop using the exact
   prompt templates in the "Execution Protocol" section of
   /home/sites/sugarcraft/crush_feat_plan.md.
4. If a Builder, Reviewer, or Fixer's response doesn't meet the Agent Failure
   Retry protocol's bar for a usable response, follow that protocol exactly --
   do not try to patch a bad response yourself, and do not silently accept one.
5. When a step's review comes back clean, spawn the Commit Agent (queued
   through the git concurrency rule if any other track is mid-commit), then
   mark the step "done".
6. If a step fails to converge after 5 full review cycles, mark it "blocked",
   stop this Wave, and report back including the full findings list from the
   fifth review.
7. Repeat until every step in your manifest is "done", then report Wave
   <WAVE_ID> complete.

You do not write code. You do not run tests. You do not read or edit any file
under src/, tests/, or any lib directory yourself.
```

### Spawning a Step Builder Agent

Prompt template — the parts in **bold** below are new relative to the original plan's template, added per Lessons 3 and 4:

```
You are the Step Builder Agent for step <STEP_ID> ("<STEP_TITLE>") of the
sugar-crush feature build.

Read ONLY this: the subsection of /home/sites/sugarcraft/crush_feat.md pointed
to by step <STEP_ID> in this Wave's Step Manifest (a heading like "## N. Section
Title", further narrowed to a lettered/numbered subsection such as "### E)
Recommendations" -> a specific item like "E3"). That is your complete
specification. Do not read the rest of crush_feat.md unless the pointed-to
subsection itself tells you to look at a specific other section for a shared
detail (e.g. a class defined in an earlier step).

Files you are expected to create or modify for this step:
<FILE_LIST_FROM_MANIFEST_ROW>

**This entire plan runs on `master`, always, for its whole duration -- there
are no feature branches, no PRs, and no worktrees anywhere in this build,
even though multiple steps build concurrently.** You are working directly in
the shared checkout on whatever branch it's already on (which should be
`master` -- if `git branch --show-current` ever shows anything else, stop and
report it to the Phase Lead rather than trying to fix it yourself). You never
run `git commit`, `git push`, `git branch`, `git checkout -b`/`git checkout
<branch>`, `git switch`, `git reset`, or `git worktree add` for any reason --
committing is the Step Commit Agent's job, and branch/worktree state is not
something any Builder, Reviewer, or Fixer in this plan ever touches. If you
think you need to touch git state to complete this step, that's a sign the
step's scope has grown beyond what it should be -- stop and report to the
Phase Lead instead of improvising.

Do this, in order:
1. Read the existing files listed above that already exist, and read 2-3
   sibling files in the same directory to confirm the coding conventions in
   use (declare(strict_types=1), PSR-4 namespace, final classes, readonly
   properties, with*()/mutate() pattern, bare accessors, ::new() factories --
   see AGENTS.md at the repo root for the full convention list).
2. Implement exactly what the specification subsection describes. Do not add
   functionality it doesn't ask for. Do not leave TODOs or stub methods unless
   the specification explicitly defers something to a later step.
3. **If the specification's "why" section describes something as "never
   wired," "never called," "dead code," or "disconnected," your job is not
   done when the new/fixed code merely exists and passes its own unit test --
   it is done when you have traced and confirmed the real call path from
   bin/sugarcrush (or the nearest established production entry point --
   Bootstrap::chat(), AppBuilder::build() -- confirm which one applies for
   this specific step) actually reaches your change with zero special
   test-only setup. State explicitly, in your report, which entry point you
   traced and what the call chain looks like.**
4. **If full implementation genuinely isn't possible within this step's scope
   (a missing external dependency, no real backend target specified, etc.),
   do not fabricate a success path. Make the feature honestly fail or
   honestly report "not implemented," say so explicitly in your report, and
   flag it for the Phase Lead rather than silently shipping fake success.**
5. Write PHPUnit tests for every public method you added or changed, in the
   matching tests/ directory, following the existing test file naming
   pattern. Where the specification's rationale cites a specific failure
   mode this fixes (a race condition, a security bypass, a silent-drop bug),
   write a test that would have caught that exact failure mode against the
   OLD code, not merely a test that the new method exists.
6. **Update documentation as part of this same step, not as a follow-up:**
   - In-code: every new public class/method gets a doc-comment citing what it
     does and, if it mirrors an upstream tool's behavior described in
     crush_feat.md, a one-line "Mirrors <tool>'s <feature>" citation, per this
     repo's own doc-comment convention.
   - .md files: if this step's file list in the manifest includes a doc
     target (README.md capability line, CHANGELOG.md entry, a specific lib's
     CALIBER_LEARNINGS.md), update it now. If the manifest doesn't name a doc
     target for this specific step, none is required for it individually --
     Wave 4 covers whole-plan documentation catch-up.
7. Run the tests yourself: `cd <lib-directory> && composer install --quiet &&
   vendor/bin/phpunit`. If phpunit fails in a way that looks
   dependency-related, run `composer update` first and retry.
8. Confirm 0 failures, 0 errors before finishing.
9. Report back under 250 words: what you created/changed, the production
   call-chain trace from step 3, confirmation tests pass, and whether step 4
   applied. This report is not graded -- the Review Agent independently
   re-checks everything including your reachability claim.

Use absolute paths in every Bash command, or chain with && -- your working
directory does not persist between separate Bash tool calls.
```

### Spawning a Step Review Agent — the full 11-category checklist

Categories 1-10 are the original plan's checklist, unchanged (it worked — the remediation pass reused its "spirit" too). **Category 11 is new**, added directly per Lesson 3, and category 1's wording is strengthened per Lesson 2.

```
You are the Step Review Agent for step <STEP_ID> ("<STEP_TITLE>") of the
sugar-crush feature build. You have no memory of any earlier review of this
step and no idea whether a previous "fix" actually worked -- treat every claim
of "done," "removed," "wired," or "verified" as unverified until you personally
confirm it against what's on disk right now.

Snapshot `git rev-parse HEAD` before starting. If it changes before you finish,
void this review and restart against the new HEAD (Lesson 6 -- something else
may be touching this repo concurrently).

Also run `git branch --show-current` and confirm it says `master`. This build
never uses any other branch, for any step, at any point. If it says anything
else, do not run the eleven-category checklist at all -- this is not a normal
FINDINGS-list item, because the Step Fix Agent (`coder`) has no git access
either and can't resolve it, and looping it through 5 review cycles would just
burn cycles on something no Fix Agent can touch. Instead, stop immediately and
report back to the Phase Lead as its own distinct condition ("wrong branch:
<name>, expected master") separate from a normal PASS/FINDINGS verdict, so the
Phase Lead escalates it straight to a block rather than spawning a Fix Agent
that has no way to act on it.

Read ONLY the subsection of /home/sites/sugarcraft/crush_feat.md pointed to by
step <STEP_ID>. Files this step was scoped to touch:
<FILE_LIST_FROM_MANIFEST_ROW>

Run `git status` and `git diff` (or `git diff HEAD` if staged). Work through
every one of the following eleven categories, in order, every time:

1. REQUIREMENTS TRACEABILITY -- every listed file actually exists and was
   actually touched; nothing outside the list was touched (scope creep is a
   finding, not something to silently allow). **Compare the Builder/Fixer's
   own summary of what it did against the actual diff content line by line --
   a claim of "removed X" that the diff shows still present, or "wired Y" that
   the diff shows only referenced in a comment, is itself a blocker-severity
   finding, independent of whatever else you find.**

2. COMPLETENESS -- every method/property the specification describes is
   present with a matching signature; no stub body, no bare `throw new
   \Exception('not implemented')`, no leftover TODO, unless explicitly
   deferred.

3. CORRECTNESS -- hand-trace at least one normal input and one edge case.
   For concurrency-touching code (flock, SQLite writes, process spawning),
   confirm the specific race condition claimed to be prevented actually is.
   **If this step's spec says a genuine implementation was out of scope, the
   code must fail or report honestly -- any fabricated success path (fake
   data, a hardcoded "success" return with no real work behind it) is an
   automatic blocker finding, full stop, regardless of test coverage.**

4. CONVENTION AND STYLE COMPLIANCE -- declare(strict_types=1) first line;
   correct namespace; final classes unless the spec says otherwise; with*()
   returns via mutate(); paired bool $xSet sentinels where convention calls
   for it; bare accessors; ::new() factories; comments explain WHY not WHAT.

5. CODE QUALITY AND SIMPLIFICATION -- no dead code, no unused
   imports/properties/parameters, no premature abstraction, no copy-pasted
   logic that should be one shared method, error handling only at real
   boundaries.

6. TEST COVERAGE -- a test file exists for every new class; every public
   method has at least one test; edge cases and failure paths covered, not
   just the happy path; run the tests yourself right now and confirm 0
   failures, 0 errors; confirm nothing was weakened to pass (no
   commented-out assertions, no loosened expectations, no markTestSkipped()
   dodging a hard case). If the spec cited a specific failure mode this fixes
   (a race, a bypass, a silent-drop), confirm a test actually encodes that
   exact scenario, not merely that the method exists.

7. REGRESSION SAFETY -- run the full test suite for every lib this step
   touched and confirm the result against the baseline captured in Wave 0
   (see "Baseline test count" below) -- 0 new failures/errors versus that
   number, not just "phpunit exited 0" in isolation. If this step touched a
   file shared by other libs, note which sibling libs depend on it.

8. SECURITY -- path-traversal checks on new file-path handling;
   escapeshellarg() on every variable piece of new shell commands, never
   string-concatenated untrusted input; parameter binding on new SQL, never
   string interpolation; no blind deserialization or eval of external
   responses.

9. COULD IT BE DONE BETTER -- simpler existing helper in candy-core/
   candy-sprinkles instead of new code; naming consistent with sibling
   classes; obvious performance problems (N+1 queries, unnecessary large-array
   copies, synchronous I/O in a hot loop).

10. DOCUMENTATION -- in-code doc-comments present on new public
    classes/methods per this repo's convention; if the manifest row named a
    .md doc target for this step, confirm it was actually updated and that
    the update is accurate (not just present) against what the code now
    actually does.

11. PRODUCTION REACHABILITY -- **the category this plan adds beyond the
    original protocol, directly because of how many times its absence caused
    a real problem last time.** If this step's spec section in crush_feat.md
    describes something as "never wired," "never called," "dead code," or
    "disconnected": independently trace the call path yourself, starting from
    bin/sugarcrush (or the specific established entry point the spec names --
    Bootstrap::chat(), AppBuilder::build()) through to this step's new/fixed
    code. Do not accept the Builder's own reachability trace from its report
    -- re-derive it. If you cannot find a real, unconditional call path that
    exercises this code in a normal `bin/sugarcrush` invocation with no
    special test setup, this is a blocker finding, even if every other
    category passes clean and even if the code is individually correct and
    well-tested. "Correct but unreachable" is not an acceptable outcome for
    any step in this plan.

12. YOUR VERDICT -- list every problem: severity (blocker/major/minor/nit),
    file path, line number if applicable, one-sentence description, one-
    sentence suggested fix. Even a clean pass must briefly show, in your own
    words, that you walked through all eleven categories above -- a blank
    report with no evidence of checking is a failed review, not a passing
    one. End with exactly one line, verbatim, as the very last line:
      STEP_REVIEW_RESULT: PASS
    or
      STEP_REVIEW_RESULT: FINDINGS
```

Command notes (same as the original plan): working directory does not persist between Bash calls; use `composer --working-dir=<lib>` explicitly; if phpunit fails dependency-related, `composer update` first.

### Agent Failure Retry protocol — NEW

This is distinct from the review/fix loop above. The review/fix loop handles a *substantively engaged* agent whose *work* has quality problems. This protocol handles the different, also-real failure mode: **an agent's response itself is not usable** — regardless of whether the underlying work might have been fine. Both `crush_code_update.md` and this session's own experience (background agents occasionally returning truncated, off-task, or malformed output) confirm this happens on long unattended runs.

**What counts as "not a proper response"** — any of these, from a Builder, Fixer, Reviewer, or Committer:

- **Empty or near-empty output** with no explanation, or output that is clearly truncated mid-sentence/mid-code-block.
- **Missing the required final line** — a Step Review Agent's output that doesn't end with exactly `STEP_REVIEW_RESULT: PASS` or `STEP_REVIEW_RESULT: FINDINGS` on its own last line. Don't guess which one was meant; treat this as a failure regardless of how confident the rest of the report sounds.
- **Off-scope or off-task** — the agent addressed a different step, a different file set, or answered a question nobody asked (a sign it lost track of its own prompt, possibly from a prior turn's context leaking in even though it should have started fresh).
- **Claims completion with zero evidence** — "I implemented X and it works" with no file list, no test output, no trace of having actually run anything. A report this thin is not trustworthy even if the underlying work might genuinely be fine — treat it the same as if the work weren't done, because there's no way to tell from here.
- **A visible error/crash surfaced instead of a normal report** — a tool-call failure, an unhandled exception dump, a timeout notice, or the agent explicitly saying it couldn't complete the task.
- **Obviously wrong content** — a response in the wrong language, garbled/repetitive text, a flat refusal to do a task that is clearly within scope and not actually harmful (this plan's tasks are ordinary PHP feature work; a refusal here is itself a signal something went wrong with the spawn, not a legitimate safety objection to re-litigate).
- **A permission-routing error** (e.g. "Agent X is read-only, use delegate") is a **different** case — not a failure of the agent's work, a failure of which spawn tool was used. Per the original plan's own rule, retry the identical spawn with the correct tool (`task` ↔ `delegate`); this does not count against the retry cap below.

**The protocol itself:**

1. Do **not** try to patch, continue, or reinterpret a bad response. Do not send it a follow-up message asking it to "please finish" or "please clarify" — a genuinely fresh spawn with no prior turns is the whole point of this architecture (Lesson: exactly the same reason the review loop never reuses a reviewer across cycles).
2. Spawn a **brand-new agent of the same role**, with the **exact same prompt** that was used the first time (do not modify the task to "explain what went wrong before" — the new agent has no context of the failure and doesn't need any; it just does the job from scratch).
3. If the second attempt also fails the "not a proper response" bar, spawn a third, identical, fresh attempt.
4. **Cap at 3 attempts per role-spawn.** If the third attempt also comes back unusable, treat this the same as a step that failed to converge after 5 review cycles: mark the step `"blocked"` in the relevant progress file, note specifically that it was blocked on agent-response reliability (not on a content/quality finding — this distinction matters for whoever picks up the block later), and stop working on that Wave/track, escalating up exactly as a normal block would.
5. **This never counts as, and is never conflated with, a review-cycle count.** A step going through 2 wasted Builder-agent attempts before getting a usable one, followed by a clean single-pass review, is a step with `reviewCycles: 1` in the progress file, not 3 — the retry counter and the review-cycle counter are tracked separately, and only the review-cycle counter feeds the 5-cycle block condition described elsewhere in this document.
6. Apply this protocol identically to every role in the hierarchy — Builder, Reviewer, Fixer, Committer, and (during the Final Plan Review) the Plan Auditor itself. A malformed Committer response ("I think I committed it") is exactly as untrustworthy as a malformed Builder response and gets the identical fresh-retry treatment, not a shortcut where the Phase Lead just assumes it probably worked and checks `git log` itself instead of spawning a real retry.

### Spawning a Step Fix Agent

Same shape as the original plan — spawned only when review returns `FINDINGS`, given the complete findings list in one shot, expected to address every item:

```
You are the Step Fix Agent for step <STEP_ID> ("<STEP_TITLE>") of the sugar-
crush feature build. A Review Agent found the following problems. Fix ALL of
them -- do not stop after the first one, and do not make any change outside
what's needed to address this list.

Findings to fix:
<VERBATIM_FINDINGS_LIST_FROM_REVIEW_AGENT>

For reference, the original specification is the subsection of
/home/sites/sugarcraft/crush_feat.md pointed to by step <STEP_ID>.

Same rule as the original Builder Agent: this build stays on `master` for its
entire duration, no branches, no PRs, no worktrees. You never run `git
commit`, `git push`, `git branch`, `git checkout -b`/`git checkout <branch>`,
`git switch`, `git reset`, or `git worktree add` -- fix the files, nothing in
git. If `git branch --show-current` isn't `master` when you check, stop and
report it rather than working around it.

If any finding was a "Production Reachability" (category 11) finding, your fix
must produce a real, traceable call path -- not merely add a comment claiming
one exists, and not merely add a second, test-only wiring path that doesn't
reach the real bin/sugarcrush entry point. Re-state the call chain explicitly
in your report, the same way the original Builder Agent was required to.

If any finding was a "fabricated success" (category 3) finding, your fix must
replace the fake success path with an honest failure/not-implemented report --
do not simply hide the fabrication better.

After fixing, run the tests yourself and confirm 0 failures, 0 errors, before
reporting back a summary under 250 words of what you changed for each finding.
```

After the Fix Agent reports, the Phase Lead immediately spawns a **brand new** Step Review Agent (full checklist, from scratch) to re-check. Repeat until `PASS` or 5 full review cycles, whichever comes first.

### The git concurrency rule

Parallel tracks (Wave 1, 3, 4) may run their Builder/Reviewer/Fixer loops fully concurrently as long as their file lists are disjoint, per the manifest. **The actual `git commit` + `git push` sequence is different — only one Step Commit Agent may be actively running at any moment, system-wide, even across concurrently-running tracks.** This is Lesson 8's direct fix: it preserves the fast, expensive, LLM-bound work's parallelism while serializing the fast, cheap git operations, which is exactly the part where two simultaneous `git push origin master` calls could race. In practice: when a track's step is ready to commit, the Phase Lead (or Plan Orchestrator, if two different Waves' Phase Leads are both trying to commit at once) queues the Commit Agent spawn and waits for any currently-running Commit Agent to finish and report back before spawning the next one. This adds negligible wall-clock time (commits are fast) and removes an entire class of race.

**Why this is a single-branch build, not just a single-commit-queue build.** Parallel execution across Wave 1/3/4 tracks is exactly the situation where it would be tempting for an agent to reach for a branch or a worktree per track "for isolation" — and that would be the wrong move here, on purpose. This plan does not use `crush_code_plan.md`'s own Phase 3 (`WorktreeManager`/per-agent branches) for its own execution — every track works directly in the one shared checkout, on `master`, the entire time, exactly because file-disjointness (not git isolation) is what this plan's Wave design already uses to make concurrency safe. A step whose work ends up on a branch instead of `master` is, functionally, a step that never landed — nothing else in the plan ever looks at any ref except `master`, so a stray branch is silent data loss with a green-looking local diff. Concretely:

- **Builder and Fix Agents never touch git state at all** — no `commit`, `push`, `branch`, `checkout`, `switch`, or `worktree add`. This is stated directly in their prompt templates above, and — because `coder` (the shared agent both roles reuse) also gets spawned for unrelated, non-plan tasks elsewhere in this repo where feature branches are the normal workflow — it is *not* enforced by `coder`'s own permission profile, only by instruction. Do not assume the permission layer is silently protecting this one; if a Builder or Fixer report ever mentions running a git command, treat that as an "not a proper response" per the Agent Failure Retry protocol, regardless of what else the report claims to have done.
- **The Step Commit Agent (`sugarcrush-feat-committer`) is hard-blocked at the permission layer**, not just by instruction, from creating or switching to any branch (`git branch <name>`, `git checkout -b`/`git checkout <branch>`, `git switch`, `git worktree add`) and from pushing to anything except `git push origin master` — only `git branch --show-current` (the check it runs in step 2 of its own template) is carved out as an allowed read of branch state. See `.opencode/opencode.jsonc`'s `sugarcrush-feat-committer` entry for the exact allow/deny list.
- **The Plan Auditor (`sugarcrush-feat-final-reviewer`) and `sugarcrush-feat-reviewer`** have no git-mutation commands in their bash allowlists at all — read-only by construction, so there's no permission surface to even worry about there.
- **The Plan Orchestrator and Phase Leads have `bash: deny` outright** — they never run git themselves; every git operation flows through a Committer spawn, which is the only role this plan ever routes actual git mutation through.

If any spawned agent at any tier ever reports having created, switched to, or pushed to a branch other than `master`, that is a blocking finding, full stop — treat it the same as a fabricated-success finding (category 3), not a minor process slip, and have the Phase Lead confirm `git branch --show-current` is `master` and `git log origin/master` reflects the work before considering the step recoverable.

### Spawning a Step Commit Agent

```
You are the Step Commit Agent for step <STEP_ID> ("<STEP_TITLE>") of the sugar-
crush feature build. The work has already passed an independent review. Commit
it directly to master and push -- this is an explicit, intentional exception
to this repo's normal branch+PR workflow for this automated run only.

1. Run `git status`. Confirm only the expected files changed:
   <FILE_LIST_FROM_MANIFEST_ROW, plus matching test files, plus any doc file
   named in this step's manifest row>
   If anything else shows as changed, do NOT commit -- report exactly what
   was unexpected and stop.
2. Confirm `git branch --show-current` says `master`. If not, stop and report.
3. For each file that is a MODIFICATION of an already-tracked file: no `git
   add` needed -- name it directly on the commit line (see step 4). For each
   file that is BRAND NEW (never tracked before): `git add <that file>`
   individually, right before committing, in addition to naming it on the
   commit line. Never `git add -A` and never `git add .`.
4. Commit with this exact message format:
   git commit -m "sugar-crush: <STEP_ID> <short lowercase description>"
   --author "Joe Huss <detain@interserver.net>" -- <FILE_1> <FILE_2> ...
   (the trailing `-- <files>` pathspec form works for already-tracked files
   without a prior `git add`; brand-new files must still have been `git add`ed
   in step 3 first, or this will fail with "pathspec did not match any files.")
5. Push directly to master: `git push origin master`
6. Report back the commit hash and confirmation the push succeeded.

If any part of this comes back ambiguous or you're not fully certain the
commit succeeded and pushed cleanly, say so explicitly in your report rather
than reporting success -- an uncertain commit report gets a fresh retry per
the Agent Failure Retry protocol; a false confident report does not.
```

### Baseline test count

Wave 0 (bootstrap, below) runs the full `sugar-crush` test suite once, before any Wave-1 work starts, and records the exact result (`N tests / M assertions / F failures / E errors / W warnings / K skipped`) into `.sugar-crush-build/feat-plan-progress.json` under a `"baseline"` key. Every subsequent Step Review Agent's category 7 (Regression Safety) compares its own full-suite run against this exact baseline, not a vague "should still pass."

### Worked example, start to finish

Step `W1.A1` — SGLang streaming tool-call parsing fix (`crush_feat.md` §12 D2):

1. Phase Lead for W1 sees `W1.A1` is `not_started`, no dependencies, spawns a Builder with `<STEP_ID>` = `W1.A1`, files = `src/Providers/SglangProvider.php`, `src/Providers/CustomProvider.php`, `tests/Providers/SglangProviderStreamingTest.php`.
2. Builder reads `crush_feat.md` §12 D2's code sketch, implements the per-index tool-call-fragment buffer for both providers, writes a streaming test that reproduces the original bug (asserts `toolCalls` is non-null after a `finish_reason: tool_calls` chunk with prior `delta.tool_calls` fragments — a test that would fail against the old code), runs the tests, confirms pass, reports back including the call-chain trace (this step doesn't have a "never wired" spec, so step 3 of the Builder template is a short "n/a, this is a provider-internal parsing fix, not a wiring gap" note).
3. Phase Lead spawns a fresh Reviewer. It runs `git diff`, confirms exactly the three expected files changed plus that `git branch --show-current` is still `master`, hand-traces the buffer logic against a 2-chunk and a 3-chunk streamed tool call, confirms the new test would have failed pre-fix (by briefly checking out the old logic mentally against the test), runs the tests itself, finds nothing wrong. Ends with `STEP_REVIEW_RESULT: PASS`.
4. Phase Lead queues a Commit Agent (checking no other track is mid-commit first). It stages, commits `sugar-crush: W1.A1 fix sglang/custom provider streaming tool-call parsing`, pushes directly to `origin master`.
5. Phase Lead marks `W1.A1` done, moves to `W1.A2`.

### What "blocked" means and what happens next

Unchanged from the original plan's rule, extended to cover the Agent Failure Retry protocol's own block condition: if a step is blocked (5 review cycles without a pass, OR 3 failed agent-response retries for the same role-spawn, OR an immediate "wrong branch" condition from the Reviewer per "The git concurrency rule" above — that one doesn't wait for 5 cycles, since no Fix Agent can act on it), the Phase Lead stops that entire Wave — it does not skip ahead, because later steps very likely depend on this one. It marks the step and Wave `"blocked"` with the full findings/failure detail, reports up to the Plan Orchestrator, which halts the whole run rather than continuing to a later Wave. A later Wave built on a known-broken foundation just compounds the problem, same as before.

---

## Final Plan Review — the `/sugarcrush-feat-review` command

This is the piece the original plan never had, and `crush_code_update.md`'s existence is the proof it was needed: an entire, separate, independent audit effort had to be manually organized after the fact because nothing in the original protocol ever re-checked the finished plan's own claims against reality. This plan makes that a formal, repeatable, on-demand step instead.

**What it does, mechanically:** `/sugarcrush-feat-review` switches to the `sugarcrush-feat-final-reviewer` agent (the **Plan Auditor**) and has it walk every Wave, every step, in order, doing exactly what `crush_code_update.md`'s original audit did — **trusting nothing from any progress-tracking file, re-deriving every verdict from `git log`, `git diff`, and actually running the code/tests.**

**Its process, per round, before touching any individual step:**
0. Run `git branch --show-current` (confirm `master`) and `git branch -a` (confirm there are no other local or remote branches this plan could have created — anything named like a step ID, a Wave, or otherwise clearly plan-related that isn't `master` is a finding on its own, reported as `BRANCH-HYGIENE.audit-fix` rather than tied to one step, since it's evidence the master-only rule was violated somewhere and the responsible step needs to be identified from `git log` on that stray branch).

**Its process, per step, within one audit round:**
1. Read the step's spec pointer in `crush_feat.md` (same pointer every Builder/Reviewer used).
2. Find the commit(s) that claim to implement it (`git log --oneline --all -- <files>`, matching the `sugar-crush: <STEP_ID>` message prefix — but per Lesson 2, never trust the message text alone, always read the actual diff).
3. Independently re-run every one of the 11 review categories from scratch, exactly as a normal Step Review Agent would, with special weight on category 11 (Production Reachability) and category 3's fabrication check — these are the two categories that produced the most severe findings in the original audit.
4. If the step passes clean: record a verified-done entry in `.sugar-crush-build/feat-audit-log.json` (this is the append-only file from "Progress tracking" above — every entry here is evidence real verification happened, not a checkbox).
5. If the step has a genuine gap: record the finding in the audit log and add it to this round's findings list.
6. Repeat across every step in the current scope. Because later steps in the round may re-touch files an earlier step's *previous* round's fix already changed, the Plan Auditor re-reads `git log` fresh before each step's check rather than working from a snapshot taken at the start of the round.

**This is one round, not the whole review.** `/sugarcrush-feat-review` does not stop after a single round finds problems and hand them to a human to re-invoke the command later — it **drives the full audit → fix → re-audit loop to completion itself, in one sitting**, exactly as that command's own instructions spell out:

- Every finding from a round is dispatched as a `<STEP_ID>.audit-fix` task, routed through the Plan Orchestrator exactly like a normal blocked-step recovery — a Fix Agent scoped to the finding, a fresh Step Review Agent to confirm, a Commit Agent — using the existing machinery, not a special audit-only code path, and with the same file-disjointness/serialization discipline as everywhere else in this plan when two findings in the same round overlap on files.
- Once every fix from a round has landed, a **brand-new** `sugarcrush-feat-final-reviewer` runs the next round from scratch — never assuming a just-fixed step is now fine without re-checking it, and never reusing the previous round's auditor.
- This repeats, round after round, capped at **10 rounds**, until a round comes back with zero findings.

**When to run it:** after Wave 4 reports done, as the capstone of the whole plan. It can also be run standalone, at any earlier point, as a spot-check — the auditor doesn't assume the whole plan is finished, only that whatever Waves/steps currently show any non-`not_started` status in the progress files are the ones it should independently verify.

**What "the plan is actually finished" means:** not "every Wave's progress file says done" (Lesson 1 — that's exactly the claim that turned out to be worthless last time) but **a `/sugarcrush-feat-review` invocation whose loop converges on a round with zero findings**, with a `feat-audit-log.json` showing real per-step verification entries proving it actually checked every step rather than fast-passing. A run that needed several rounds to get there still counts as finished once it converges — what matters is that the loop ran to a clean round, in one sitting, without needing a human to notice findings and manually re-trigger the command. If 10 rounds pass without converging, the loop stops itself and escalates to a human rather than continuing indefinitely — see the command's own instructions for exactly what gets reported in that case.

---

## Wave 0: Bootstrap

### Goal
Set up the one canonical tracking file, capture the baseline test count, and confirm the five `sugarcrush-feat-*` OpenCode agents plus the two commands are actually in place before any real work starts.

### Step Manifest

| Step ID | Title | Files | Depends On | Where to look |
|---|---|---|---|---|
| W0.S1 | Create tracking files, capture baseline | `.sugar-crush-build/feat-plan-progress.json` (new), `.sugar-crush-build/feat-audit-log.json` (new, seeded empty `[]`), `.gitignore` (add `.sugar-crush-build/` if not already present from the original build) | — | This Wave's own "Goal"; the "Progress tracking" and "Baseline test count" subsections of the Execution Protocol above |

This is a `coder`-via-`task` spawn from the Plan Orchestrator directly (the one exception, same as the original plan's own bootstrap), not a Phase Lead. Its task: create `.sugar-crush-build/feat-plan-progress.json` with `{"W1": {"status":"not_started"}, "W2": {"status":"not_started"}, "W3": {"status":"not_started"}, "W4": {"status":"not_started"}, "baseline": null}`; then run `cd /home/sites/sugarcraft/sugar-crush && composer install --quiet && vendor/bin/phpunit` and write the real result string into `"baseline"`; create `.sugar-crush-build/feat-audit-log.json` seeded `[]`; confirm `.sugar-crush-build/` is already in `.gitignore` (it should be, from the original build — add the line only if it's genuinely missing). Then a `sugarcrush-feat-committer`-via-`task` spawn commits `sugar-crush: W0.S1 bootstrap feature-plan tracking + baseline`. Only after this lands does the Plan Orchestrator spawn the Wave 1 Phase Lead.

**Before this step can run**, the five new agent files and two new commands (see "Concrete OpenCode wiring" above) and the corresponding `.opencode/opencode.jsonc` permission blocks must exist on disk — these are not built by an agent as part of the run, they are the pre-existing OpenCode configuration this plan depends on, delivered alongside this document (see the repo's `.opencode/agents/sugarcrush-feat-*.md`, `.opencode/commands/sugarcrush-feat-*.md`, and the `sugarcrush-feat-*` blocks in `.opencode/opencode.jsonc`).

---

## Wave 1: Foundational, file-disjoint fixes

### Goal
Everything in this Wave is either a self-contained bug fix or brand-new files with no overlap with `Chat.php`/`Renderer.php` or with any other Wave-1 track. All tracks below may run their full Builder→Review→Fix loops concurrently (commits still serialized per the git concurrency rule). The one exception noted below is the SGLang track, which is internally serial across its own five sub-steps because they all touch the same 2-3 provider files.

### Track A — SGLang/MiniMax provider fixes (`crush_feat.md` §12)

Internally serial (all five steps touch `SglangProvider.php`; `CustomProvider.php` and `OpenAIProvider.php` are touched by a subset — see each row). Runs concurrently with every other Wave-1 track.

| Step ID | Title | Files | Depends On | Where to look |
|---|---|---|---|---|
| W1.A1 | Streaming tool-call parsing (real bug fix) | `src/Providers/SglangProvider.php`, `src/Providers/CustomProvider.php`, `tests/Providers/SglangProviderStreamingTest.php`, `tests/Providers/CustomProviderStreamingTest.php` | — | §12 D2 |
| W1.A2 | Parser-agnostic reasoning extraction | `src/Providers/SglangProvider.php`, `src/Providers/CustomProvider.php`, `src/Providers/OpenAIProvider.php`, `tests/Providers/ReasoningExtractionTest.php` (new, shared) | W1.A1 | §12 D3 (the `extractReasoning()` sketch — build it once as a shared helper, not duplicated three times, per the repeated-code finding in §12 C) |
| W1.A3 | `extra_body`/sampling params + `CompleteRequest` fields | `src/Providers/SglangProvider.php`, `src/Providers/CompleteRequest.php`, `tests/Providers/SglangProviderRequestBuildingTest.php` | W1.A2 | §12 D4 |
| W1.A4 | `</parameter>` truncation-bug detection guard | `src/Providers/SglangProvider.php`, `tests/Providers/SglangProviderTruncationGuardTest.php` | W1.A3 | §12 D5 |
| W1.A5 | `ToolCallParserInterface` + its two implementations | `src/Providers/ToolCallParser/ToolCallParserInterface.php` (new), `src/Providers/ToolCallParser/OpenAiArrayToolCallParser.php` (new), `src/Providers/ToolCallParser/MinimaxXmlFallbackToolCallParser.php` (new), `tests/Providers/ToolCallParser/*Test.php` | W1.A4 | §12 D6 (class sketches only — no `ProviderFactory` wiring yet) |
| W1.A6 | Wire the parser abstraction into `ProviderFactory` + `contextWindow()` fix | `src/Providers/ProviderFactory.php`, `src/Providers/SglangProvider.php` (contextWindow → 196608), `tests/Providers/ProviderFactoryTest.php` | W1.A5 | §12 D6 (the `ProviderFactory::createSglang()` wiring), D8 |

**Note for W1.A5's builder**: the confirmed live deployment (see §12 D1) already runs `--tool-call-parser minimax-m2`, so the `MinimaxXmlFallbackToolCallParser` is a defense-in-depth safety net for a misconfigured *future* deployment, not a fix for a currently-broken one — do not treat its absence as evidence anything is currently failing.

### Track B — Context file & environment-info wiring (`crush_feat.md` §6)

| Step ID | Title | Files | Depends On | Where to look |
|---|---|---|---|---|
| W1.B1 | `EnvironmentBlock` builder | `src/Context/EnvironmentBlock.php` (new), `tests/Context/EnvironmentBlockTest.php` | — | §6 E2 |
| W1.B2a | `ImportResolver` class | `src/Context/ImportResolver.php` (new), `tests/Context/ImportResolverTest.php` | — | §6 E3 (the class itself only — no `InstructionFileLoader` wiring yet) |
| W1.B2b | Wire `ImportResolver` into `InstructionFileLoader::loadRoot()`/`loadForPath()` | `src/Context/InstructionFileLoader.php` | W1.B2a | §6 E3 ("Wire it into `InstructionFileLoader::loadRoot()`/`loadForPath()` right after `file_get_contents()`") |
| W1.B3a | Wire `loadRoot()`/`loadForced()` into `buildSystemPrompt()` | `src/Runtime.php`, `src/App/App.php` (new `?InstructionFileLoader $instructionLoader` field), `tests/RuntimeTest.php` (add: a root `AGENTS.md`'s content is present in `buildSystemPrompt()`'s output) | W1.B2b (so the content `Runtime.php` pulls in already has `@imports` resolved) | §6 E1 |
| W1.B3b | Wire `EnvironmentBlock` into `buildSystemPrompt()` + subagent prompts | `src/Runtime.php`, `src/Agents/Agent.php` (subagent prompt gets the same environment block) | W1.B1, W1.B3a | §6 E2's sketch, applied at the same call site W1.B3a just wired |
| W1.B3c | Integration test proving both are actually wired | `tests/RuntimeTest.php` (extend, or a new `tests/Integration/SystemPromptWiringTest.php` if `RuntimeTest.php` is getting large) | W1.B3b | §6 E5 |
| W1.B4 | `forcedInstructions` as real user config | `src/Cli/Bootstrap.php`, `tests/Cli/BootstrapUserConfigTest.php` | W1.B3c | §6 E4 |

**Note**: W1.B3 does not touch `Chat.php` — it wires `App`/`Runtime.php`, which is a different (also shared, but not Wave-2-designated) file. Confirm this remains true when implementing; if it turns out `Chat.php` construction must change too, re-scope this step into Wave 2 instead rather than touching `Chat.php` here.

### Track C — Skills matching infrastructure (`crush_feat.md` §7, foundational half only — AppBuilder wiring is Wave 3)

| Step ID | Title | Files | Depends On | Where to look |
|---|---|---|---|---|
| W1.C1a | `SkillMatcher` service (Level-1 metadata listing) | `src/Skills/SkillMatcher.php` (new), `tests/Skills/SkillMatcherTest.php` | — | §7 E2 (the system-prompt-listing half only) |
| W1.C1b | `Skill` tool (Level-2 on-demand body load) | `src/Tools/BuiltIn/SkillTool.php` (new), `tests/Tools/BuiltIn/SkillToolTest.php` | W1.C1a | §7 E2 (the `SkillTool` sketch) |
| W1.C2 | Lazy progressive-loading fix in `SkillLoader`/`SkillManager` | `src/Skills/SkillLoader.php`, `src/Skills/SkillManager.php`, `src/Skills/SkillRegistry.php` (paths-passthrough in `registerFromManifest()`, required so the new manifest `paths` key isn't silently dropped), `tests/Skills/SkillLoaderTest.php`, `tests/Skills/SkillManagerTest.php`, `tests/Skills/SkillRegistryTest.php` | W1.C1a, W1.C1b | §7 E3 |

### Track D — Cross-tool skill/agent/memory import (`crush_feat.md` §10)

| Step ID | Title | Files | Depends On | Where to look |
|---|---|---|---|---|
| W1.D1 | `SkillSource` enum + `Skill` field | `src/Skills/SkillSource.php` (new), `src/Skills/Skill.php` (add `source` field — this is a pre-1.0 constructor change, update every call site per §10's own note), `tests/Skills/SkillSourceTest.php` | — | §10 10.5 (1), the `SkillSource` enum sketch |
| W1.D2a | `ForeignSkillDiscovery` | `src/Skills/ForeignSkillDiscovery.php` (new), `tests/Skills/ForeignSkillDiscoveryTest.php` | W1.D1 | §10 10.5 (1), the `ForeignSkillDiscovery` sketch |
| W1.D2b | `ForeignAgentPresetRegistry` | `src/Agents/ForeignAgentPresetRegistry.php` (new), `tests/Agents/ForeignAgentPresetRegistryTest.php` | W1.D1 | §10 10.5 (1), the agent-preset field-mapping discussion |
| W1.D3 | `ForeignMemoryImporter` | `src/Memory/ForeignMemoryImporter.php` (new), `tests/Memory/ForeignMemoryImporterTest.php` | — | §10 10.5 (2), full code sketch |

**Note**: Track D's `SkillsPane.php` badge-rendering change is deferred to Wave 2 (it's a `Renderer`-adjacent UI change — confirm at implementation time whether `SkillsPane.php` itself is in the Wave-2-designated file set; if it's a standalone component file, not `Renderer.php` itself, it may stay in Wave 1 — re-check against the actual current file structure before assuming either way).

### Track E — CLI non-interactive mode (`crush_feat.md` §2)

| Step ID | Title | Files | Depends On | Where to look |
|---|---|---|---|---|
| W1.E1a | `ArgvParser` | `src/Cli/ArgvParser.php` (new), `tests/Cli/ArgvParserTest.php` | — | §2 E1 |
| W1.E1b | `Help` screen | `src/Cli/Help.php` (new), `tests/Cli/HelpTest.php` | — | §2 E5 (file-disjoint from E1a — safe to run concurrently with it) |
| W1.E2 | `NonInteractive` one-shot mode | `src/Cli/NonInteractive.php` (new), `tests/Cli/NonInteractiveTest.php` | W1.E1a | §2 E2, E3, E4 |
| W1.E3 | Wire into `bin/sugarcrush` | `bin/sugarcrush` | W1.E1b, W1.E2 | §2 E1's sketch of the new `bin/sugarcrush` body |

**Note**: `bin/sugarcrush` is a small, single-purpose file with no history of contention in this plan (only this track touches it in Wave 1) — safe to include here despite being a "shared" file in the sense that Wave 3 also touches it indirectly via `Bootstrap.php`; the two don't overlap on the file itself.

### Track F — `Edit` tool diff generation (`crush_feat.md` §1 recommendation 3, foundational half only)

| Step ID | Title | Files | Depends On | Where to look |
|---|---|---|---|---|
| W1.F1 | Generate a real diff in `Edit::execute()` | `src/Tools/BuiltIn/Edit.php`, `tests/Tools/BuiltIn/EditTest.php` (add: diff is present and correct for a multi-line change) | — | §1 E3 (the diff-generation half only — rendering it in `Renderer.php` is Wave 2) |

### Track G — candy-mosaic dependency + foundation (`crush_feat.md` §9, E1/E2 only)

| Step ID | Title | Files | Depends On | Where to look |
|---|---|---|---|---|
| W1.G1 | Add `candy-mosaic` dependency + path-repo | `sugar-crush/composer.json`, run `php tools/check-path-repos.php --fix` from repo root as part of this step | — | §9 E1 |
| W1.G2 | `ToolResult` image/attachment field + probe-once wiring | `src/ToolResult.php`, `src/Chat.php` **is not touched here** — only add a `?string $mosaicProtocol` capture point as a static/injectable value the Wave-2 step will consume; if this cannot be done without touching `Chat.php`'s constructor, stop and re-scope this into Wave 2 instead, `tests/ToolResultImageTest.php` | W1.G1 | §9 E1, E2 |

**Note for W1.G2's builder**: this is the one Wave-1 step most likely to discover it actually needs `Chat.php`. If so, don't force it — split what genuinely can land in Wave 1 (the `ToolResult` field itself, a standalone `Mosaic::auto()` capture helper) from what must move to Wave 2 (threading it into `Chat`'s constructor), and say so explicitly in your report so the Phase Lead can adjust the Wave 2 manifest.

---

## Wave 2: The Chat.php / Renderer.php cluster

### Goal
Every step below touches `src/Chat.php` and/or `src/Renderer.php`. Per "The Chat.php/Renderer.php rule" above, these run **strictly one at a time**, in the exact order listed, full Builder→Review→Fix→Commit loop to completion before the next step's Builder Agent is spawned. Do not parallelize any pair of these, even ones that look like they touch different line ranges.

> **Read before dispatching any Wave 2 step.** Wave 1's run already modified both files, so `git diff` against the Wave 0 baseline is no longer a clean slate:
> - `src/Renderer.php` gained `renderAssistantTurn()`/`renderReasoning()` in `28c55a6f` (§12 D3's TUI half — the W1.A2 row omitted it, but the spec requires it).
> - `src/Chat.php` gained the `?Mosaic $mosaic` constructor param, `mosaic()`, base64 image threading across the fork/IPC seam, and `ToolResult` passthrough in `8f794c19` (W2.S14a, landed early).
>
> **Why this happened, and the rule it produced.** Category 11 (Production Reachability) is a mandatory blocker, but Wave 1's file lists exclude the very files where production wiring lives (`Chat.php`, `Renderer.php`, `Bootstrap.php`). For any step whose spec says "surface X to the user," those two constraints cannot both be satisfied — so builders followed `crush_feat.md` (the specification) over this document's file lists, and reviewers then correctly flagged the result as scope creep. **A step's file list is a guide; `crush_feat.md` is the authority.** When the two conflict, the Builder Agent must say so in its report and the Phase Lead must re-scope, rather than the Builder silently widening scope or a Reviewer blocking work that the spec actually mandates. Never let an agent resolve the conflict by editing this plan (one did, to W1.C2's row).

### Step Manifest

| Step ID | Title | Files | Depends On | Where to look |
|---|---|---|---|---|
| W2.S1a | Add an `onEvent` callback threaded through `EngineBackend::complete()`/`Runtime::run()`/`Runtime::executeToolCalls()` | `src/Backend/EngineBackend.php`, `src/Backend.php`, `src/Runtime.php`, `tests/Backend/EngineBackendTest.php`, `tests/RuntimeTest.php` | W1.A1-A6 (uses the hardened provider streaming path) | §1 E1 (the event-callback half of the sketch only — no `Chat.php` changes in this step; the callback can be tested by asserting it fires with the right event objects, independent of who eventually consumes it) |
| W2.S1b | Reconcile the two `ToolCall`/`ToolResult` type pairs | `src/Tools/Tool.php`, `src/Tools/ToolCall.php`, `src/Tools/ToolResult.php`, `src/ToolCall.php`, `src/ToolResult.php` — read all four existing files first; the actual change is either a merge into one canonical pair or an adapter, whichever the spec/existing usage supports with less churn | W2.S1a | §1 D's finding on the two type pairs, §1 E1 |
| W2.S1c | Wire `Chat::beginToolCalls()`/`finishToolCalls()` to consume the `onEvent` stream from W2.S1a | `src/Chat.php` | W2.S1a, W2.S1b | §1 E1 (the `Chat.php` consumption half) |
| W2.S1d | Route `HookManager::preToolUse()`/`postToolUse()` gating through the now-unified path for every tool call | `src/Chat.php`, `src/Runtime.php`, `tests/ChatTest.php` | W2.S1c | §1 E1 (confirm hooks fire identically regardless of which pipeline originally dispatched a given tool call) |
| W2.S2 | Render the `Edit`/`Write` diff in the chat transcript | `src/Renderer.php` | W2.S1d | §1 E3 (the rendering half) |
| W2.S3a | `HookResult::ask()` action + `HookManager` wiring | `src/Hooks/HookResult.php`, `src/Hooks/HookManager.php`, `tests/Hooks/HookResultTest.php` | W2.S1d | §1 E2 (the `HookResult`/`HookManager` half only — no `Runtime.php`/`Chat.php`/`Renderer.php` yet) |
| W2.S3b | Blocking permission-request flow (`Deferred`, `PermissionRequestMsg`) | `src/Runtime.php`, `src/Chat.php`, `tests/ChatTest.php` | W2.S3a | §1 E2 (the async-blocking-on-UI-decision half) |
| W2.S3c | Render the permission-prompt modal via `Veil` | `src/Renderer.php` | W2.S3b | §1 E2 (the rendering half — reuse the existing Ctrl+P palette's `Veil` compositing, don't build a new overlay mechanism) |
| W2.S4 | Per-tool-call human-readable `description` field | `src/Tools/BuiltIn/Bash.php`, `Edit.php`, `Glob.php`, `Grep.php`, `Read.php`, `WebFetch.php`, `src/Message.php` (`describeToolCall()`), `tests/Tools/BuiltIn/*Test.php`, `tests/MessageTest.php` | W2.S1d | §3 E2 |
| W2.S5 | Collapse/expand tool output + hide-on-success default | `src/Renderer.php`, `src/Chat.php` (`expanded` map field) | W2.S4 | §1 E5 |
| W2.S6 | Denied/interrupted tool-call visual states | `src/Renderer.php`, `src/Chat.php` | W2.S3c, W2.S5 | §1 E7 |
| W2.S7 | Auto-generate session titles (background small-model call) | `src/Chat.php` (`scheduleTitleGeneration()`), `tests/ChatTest.php` | W2.S1d | §3 E1 |
| W2.S8a | Collapse `PaletteAction` + `CommandRegistry` into one source, fuzzy-match the `/` menu | `src/Palette/PaletteAction.php`, `src/Commands/CommandRegistry.php`, `src/Commands/CommandSpec.php`, `tests/Commands/CommandRegistryTest.php` | W2.S1d | §4 E1, E2 (data-model unification only — no `Chat.php`/`Renderer.php` in this step) |
| W2.S8b | Render fuzzy-match highlighting, category grouping, MRU bias | `src/Chat.php` (MRU-tracking state field), `src/Renderer.php`, `tests/ChatTest.php` | W2.S8a | §4 E3, E6, E7 |
| W2.S9 | File-based custom commands (`CommandLoader`) | `src/Commands/CommandLoader.php` (new), `src/Commands/CommandSpec.php`, `tests/Commands/CommandLoaderTest.php` | W2.S8a | §4 E4 |
| W2.S10 | `/mcp` slash command | `src/Chat.php`, `src/Commands/CommandRegistry.php` | W2.S8a | §4 E8 |
| W2.S11a | Enable mouse mode + wire `Mark`/`Scanner` into `Renderer`'s root render pass | `src/Chat.php` (`ProgramOptions` mouse mode), `src/Renderer.php` (foundational scan-once wiring, no specific zones yet) | W2.S8b (palette zones share the same `Scanner` pass) | §8 E1 |
| W2.S11b | Click-to-switch session tab | `src/Renderer.php`, `src/Chat.php` (zone-hit dispatch) | W2.S11a | §8 E2 |
| W2.S11c | Click-to-switch pane | `src/Renderer.php`, `src/Chat.php` | W2.S11b | §8 E3 |
| W2.S12a | Scrollwheel in chat transcript (scroll-offset state) | `src/Chat.php`, `src/Renderer.php` | W2.S11c | §8 E4 |
| W2.S12b | Click-to-expand tool-call | `src/Renderer.php`, `src/Chat.php` | W2.S12a | §8 E5 |
| W2.S13a | Click-to-select in palette/picker | `src/Renderer.php`, `src/Chat.php` | W2.S12b | §8 E6 |
| W2.S13b | Drag-vs-click disambiguation / text-selection passthrough guard | `src/Renderer.php` (or wherever `ZoneClickTracker` usage lives for this repo — confirm at implementation time) | W2.S13a | §8 E8 |
| W2.S14a | ~~Thread `Mosaic::auto()` into `Chat`'s constructor~~ **LANDED EARLY in `8f794c19` (Wave 1)** — `Chat` already takes `?Mosaic $mosaic` and exposes `mosaic()`. Verify before dispatching; do not rebuild. | `src/Chat.php` | W1.G1, W1.G2, W2.S1d | §9 E2 (plumbing only — the step W1.G2 deferred if it turned out to need `Chat.php`) |
| W2.S14b | Render tool-result images via `ImageLayer`/`ImageOverlay` | `src/Renderer.php` | W2.S14a | §9 E3 |
| W2.S15 | `SkillsPane.php` provenance badges | `src/Tui/Components/SkillsPane.php` | W1.D1, W1.D2a, W1.D2b | §10 10.5 (1), the `SkillsPane.php` render-change sketch |

**Regression discipline for this Wave specifically**: because every step here touches the same two files in sequence, each step's Review Agent must re-run the **full** `sugar-crush` test suite (not just the touched-file tests) against the Wave 0 baseline before passing — a regression introduced by an early step (e.g. `W2.S3b`) that only a much later step's tests happen to catch (e.g. `W2.S9`) is exactly the kind of thing strict sequential ordering is meant to prevent from compounding silently.

---

## Wave 3: Session/agent live-wiring (depends on Wave 2)

### Goal
Wave 2's `Chat.php` changes must be fully landed before this Wave starts — these steps make small, additive changes to the same file and would collide badly with Wave 2's larger rewrite if run concurrently with it.

### Step Manifest

| Step ID | Title | Files | Depends On | Where to look |
|---|---|---|---|---|
| W3.S1 | Seed a real session at boot | `src/Cli/Bootstrap.php`, `tests/Cli/BootstrapTest.php` | Wave 2 complete | §5 E1 |
| W3.S2 | Decode Ctrl+Tab/Ctrl+Shift+Tab (cross-lib: `candy-core`) | `candy-core/src/InputReader.php`, `candy-core/tests/InputReaderTest.php` | — | §5 E2 |
| W3.S3 | `/bg` and `/fork` commands | `src/Chat.php`, `src/Commands/CommandRegistry.php`, `tests/ChatTest.php` | W3.S1 | §5 E3 |
| W3.S4 | Real `subscriptions()` heartbeat/poll pump | `src/Chat.php`, `tests/ChatTest.php` | W3.S3 | §5 E4 |
| W3.S5a | Fix `agentDisplayState()`'s hardcoded zeros | `src/Agents/AgentManager.php`, `src/Agents/SubAgent.php` (`startedAt`/token-counter accessors), `tests/Agents/AgentManagerTest.php` | W3.S4 | §5 E6 |
| W3.S5b | `AgentDashboardPane` component | `src/Tui/Components/AgentDashboardPane.php` (new), `src/Renderer.php`, `tests/Tui/Components/AgentDashboardPaneTest.php` | W3.S5a | §5 E5 |
| ~~W3.S6a~~ | ~~Retire (or migrate) the dead `App`/`Tui\Renderer` engine files~~ **SUPERSEDED — DO NOT RUN. See the W3.M* migration rows below.** An attempt at this step deleted 3,504 lines (all of `Tui/Components/`, `Tui/Commands/`, `KeyboardHandler`, `SkillsPane`) and was reverted in `e995acf6`. | — | — | §5 E7 |
| ~~W3.S6b~~ | ~~Retire the pane component files~~ **SUPERSEDED — DO NOT RUN.** These files are being migrated ONTO, not retired. | — | — | §5 E7 |

> **W3.S6a/S6b were the wrong branch of §5 E7's conditional, and the plan's "dead code" framing was factually wrong. Read this before touching anything under `src/App/` or `src/Tui/`.**
>
> The `App`/`Pane`/`KeyboardHandler` system is **newer**, not dead: it was created 2026-06-03 in `candy-crush`'s Phase 1-3 and came across when candy-crush was absorbed into sugar-crush. `src/Chat.php` + `src/Renderer.php` are **older** (2026-05-02) and are what `bin/sugarcrush` still boots. The pane system was never switched on — which is the *same* "built but never wired" pattern `crush_feat.md`'s executive summary identifies as this whole project's dominant finding. The response to built-but-unwired is to WIRE it, exactly as `W3.S1` did for sessions. Deleting it is not on the table.
>
> The two are **complementary halves, not duplicates**: the pane system is the SHELL (pane layout, `MenuBar`, `KeyboardHandler`'s agent-view keys `c`/`r`/`s`/`q`, session tabs, agent view modes) and has none of the content rendering; `src/Renderer.php` is the CONTENT (tool results, diffs, permission modal, images, collapse/expand, slash menu, palette, mouse zones) and carries every feature Waves 1-2 built. §5 E7's delete branch was explicitly conditioned on there being no plan to move onto the pane shell. There now is one, so the **merge** branch applies.
>
> Decision: **full shell adoption.** `App` becomes the root `Model` and HOSTS `Chat` as a field; `ChatPane` delegates its body to the live `src/Renderer.php`; `KeyboardHandler`'s shell keys run first and fall through to `Chat`'s own arms. Nothing built is discarded on either side.

| W3.M1 | Make `App` a candy-core `Model` hosting `Chat`; `ChatPane` delegates to the live `Renderer` | `src/App/App.php`, `src/Tui/Renderer.php`, `src/Tui/Components/ChatPane.php`, `tests/App/AppModelTest.php` | Wave 2 complete | §5 E7 (the MERGE branch) |
| W3.M2 | Merge `KeyboardHandler`'s shell keys into `App::update()`, falling through to `Chat` | `src/App/App.php`, `src/Tui/KeyboardHandler.php`, `tests/Tui/KeyboardHandlerTest.php` | W3.M1 | §5 E7, §5 E2 |
| W3.M3 | `Bootstrap::app()`; `bin/sugarcrush` boots the pane shell | `src/Cli/Bootstrap.php`, `bin/sugarcrush`, `tests/Cli/BootstrapTest.php` | W3.M2 | §5 E7 |
| W3.M4 | Wire the remaining panes to real data (`SkillsPane` incl. W2.S15 badges, `FilesPane`, `ToolsPane`, `MenuBar`) | `src/Tui/Components/*.php` | W3.M3 | §5 E7; §1 E6 (`ToolsPane`); §10 10.5 (`SkillsPane`) |
| W3.S7 | Live session picker (`Ctrl+O`, persisted across turns) | `src/Chat.php`, `src/Tui/SessionPicker.php` | W3.S1 | §5 E8 |
| W3.S8 | Skills subsystem `AppBuilder` wiring (the capstone fix for the recurring "never populated" pattern) | `src/App/AppBuilder.php`, `tests/App/AppBuilderTest.php` | W1.C1a, W1.C1b, W1.C2 | §7 E1 |
| W3.S9 | Skills `paths`-based auto-scoping wired into the live tool-touch path | `src/Tools/BuiltIn/Read.php`, `Edit.php`, `Glob.php` (post-execution skill-match nudge) | W3.S8 | §7 E4 |

---

## Wave 4: Final validation & documentation

### Goal
Prove, with real integration tests run against the actual `bin/sugarcrush` entry point (not synthetic unit-level mocks), that the "production reachability" claims made throughout Wave 1–3 hold — this is the wave where the plan's own category-11 review discipline gets a dedicated, standalone test suite rather than relying only on each step's own reviewer having traced it correctly. Also catches documentation up.

### Step Manifest

| Step ID | Title | Files | Depends On | Where to look |
|---|---|---|---|---|
| W4.S1a | Reachability tests: session store, tabs, background sessions | `tests/Integration/FeatWiringReachabilityTest.php` (new — this single file accumulates all of W4.S1a-d's test methods, but each sub-step only adds its own methods and only needs to understand its own subsystem's wiring, not all of them at once) | Waves 1-3 complete | Executive Summary's "🟡 Fully built... never wired" table, the session-store/background-session rows |
| W4.S1b | Reachability tests: Skills subsystem | `tests/Integration/FeatWiringReachabilityTest.php` (extend) | W4.S1a | same table, the Skills row |
| W4.S1c | Reachability tests: mouse mode | `tests/Integration/FeatWiringReachabilityTest.php` (extend) | W4.S1b | same table, the mouse row |
| W4.S1d | Reachability tests: environment block / context-file loading | `tests/Integration/FeatWiringReachabilityTest.php` (extend) | W4.S1c | same table, the context-loading row |
| W4.S2 | tmux passthrough integration test (image rendering) | `tests/Renderer/ImageRenderingTest.php` (new) | W2.S14b | §9 E5 |
| W4.S3 | Prompt-stability / RadixAttention cache-friendliness test | `tests/Providers/PromptStabilityTest.php` (new) | W1.A1-A6 | §12 D7 |
| W4.S4 | `README.md` + `CHANGELOG.md` + `CALIBER_LEARNINGS.md` catch-up | `sugar-crush/README.md`, `sugar-crush/CHANGELOG.md` (new if it doesn't already exist from the original build's R30), `sugar-crush/CALIBER_LEARNINGS.md` | W4.S1d | "Documentation Requirements" below |
| W4.S5 | Full-suite regression confirmation against Wave-0 baseline | none (verification-only step — no files change) | W4.S1d, W4.S2-S4 | "Rollout Checklist" below |

`W4.S5`'s "Builder" is really just a verification run: `cd sugar-crush && composer install --quiet && vendor/bin/phpunit`, diffed against the Wave-0 baseline string, reported to the Wave-4 Phase Lead. If it shows any new failure/error, that is itself a blocking finding routed back through the normal Fix Agent machinery against whichever step's files the new failure traces to — do not let this step "pass" by simply noting the regression and moving on.

---

## Future Work — explicitly not in this plan

`crush_feat.md` §11 documents several genuinely valuable but architecturally novel features — a repo-map/symbol-graph, a deterministic auto-commit hook, file-system-level checkpoints distinct from the existing chat-state `/rewind`, a lint/test auto-run-and-fix loop, a two-dial sandbox execution boundary, per-command granular Bash approval, `@`-style explicit context providers, bang-prefix raw-shell passthrough, and an MCP marketplace/discovery command. None of these are steps in this plan.

This is a deliberate scoping decision, not an oversight: every step in Waves 1–4 above is either a **bug fix** or a **wiring fix** for something that already exists — bounded, verifiable work where "done" has a clear, checkable meaning (the code exists, is correct, is tested, and is reachable). The §11 items are, by contrast, **new architecture** — building a repo-map or a real sandboxing layer is a multi-week design-and-build effort in its own right, not a step that fits this plan's "one class or one small file cluster, review in a handful of cycles" shape. Folding them in here would reintroduce exactly the kind of oversized, under-specified step this plan's own "keep every agent's context small" rule and Lesson 3 (wiring gaps hide inside components too big to fully verify) both warn against.

If/when this work is picked up, it should get its own `crush_feat2_plan.md` (or similarly named successor), written the same way this one was — grounded in a real specification document (a `crush_feat.md`-style dossier per feature, not invented at Builder-Agent time), with its own Wave/file-disjointness analysis, because a repo-map's file footprint and a sandbox's file footprint almost certainly won't be disjoint from `Chat.php`/`Runtime.php`/`Tools/BuiltIn/Bash.php` in ways worth mapping out deliberately rather than discovering mid-run.

---

## Testing Strategy

### Unit tests

Every new/modified class gets its own test file per the existing repo convention — see each Wave's Step Manifest row for exact file names. Beyond "a test exists for every public method," every step whose `crush_feat.md` spec section names a **specific failure mode being fixed** (a race condition, a security bypass, a silently-dropped result, a dead-code path) must have at least one test that reproduces that exact failure mode against the pre-fix behavior — not merely a test that the new method exists and returns a plausible-looking value. This is Lesson 3/4's direct consequence applied to test design, not just to review: a test suite that only checks "the happy path returns something" is exactly the kind of test coverage that let the original build's wiring gaps go undetected for as long as they did.

### Integration tests

`W4.S1`'s `FeatWiringReachabilityTest.php` is this plan's centerpiece integration suite — see its Step Manifest row. In addition:

- `tests/Integration/ToolCallPipelineTest.php` (covered under W2.S1a-S1d's own test files, or split out here if they grow large) — drives a full `Chat` turn with 2+ tool calls through the unified pipeline and asserts real tool output (not simulated text) appears for every call, directly reproducing the original build's P1.S10/R14b bug class as a permanent regression guard.
- `tests/Integration/MousePaletteInteractionTest.php` (under W2.S11a-S13b) — drives a synthetic `MouseClickMsg` sequence against a rendered frame and asserts the correct `Msg`/state transition fires, for at least tab-switch, pane-switch, and palette-item-select.

### E2E tests

`W4.S1` plus a manual verification pass (not an automated test — some of this genuinely needs a real terminal): after Wave 4 lands, run `bin/sugarcrush` in a real terminal emulator that supports at least one image protocol (kitty, iTerm2, or a sixel-capable terminal) and confirm: mouse click switches session tabs; a tool call that returns an image renders inline; `/doctor` (if implemented as part of §9 E4) reports the detected protocol correctly. Record the result of this manual pass in the Wave 4 completion report — it is not gated by a Review Agent (there is no automated way to verify pixel output), but its absence should be flagged, not silently skipped.

### Mocking strategy

Unchanged from the original plan's conventions: `EchoProvider` (offline) for LLM calls in integration tests; real SQLite with a temp database for session/skill-store tests; real filesystem with temporary directories for path-jail/worktree-adjacent tests; `function_exists('pcntl_fork')`-gated tests for anything needing real multi-process concurrency, skipped gracefully where unavailable, per the remediation pass's own established pattern (see `tests/Agents/AgentWorkerPoolTest.php` and `tests/Integration/FanOutResearchTest.php` in the current codebase for the exact style to match).

### Test coverage goals

| Wave | Coverage target |
|---|---|
| Wave 1 (Track A, SGLang providers) | 90% on the touched methods of `SglangProvider`/`CustomProvider`/`OpenAIProvider` |
| Wave 1 (Track B, Context) | 90% on `EnvironmentBlock`, `ImportResolver` |
| Wave 1 (Track C/D, Skills/cross-tool) | 85% on `SkillMatcher`, `ForeignSkillDiscovery`, `ForeignAgentPresetRegistry`, `ForeignMemoryImporter` |
| Wave 1 (Track E, CLI) | 90% on `ArgvParser`, `NonInteractive`, `Help` |
| Wave 2 (Chat.php/Renderer.php cluster) | No single-number target — this cluster is measured by the full-suite regression-against-baseline rule instead, since coverage percentage on files this large and this central is a weaker signal than "did the baseline regress" |
| Wave 3 (session/agent wiring) | 85% on new code in `Bootstrap.php`, `AgentDashboardPane`, `AppBuilder.php` |
| Wave 4 | N/A — this Wave *is* the coverage-of-reachability check, not a unit-coverage target |

---

## Rollout Checklist

### Wave 1
- All Track A-G unit tests pass, each independently reviewed and committed
- Full suite shows 0 new failures/errors versus the Wave 0 baseline
- SGLang provider fixes verified against the confirmed live `skynet2.interserver.net` deployment's actual parser flags (§12 D1) — not just against a mocked SSE stream
- No new required PHP extensions beyond what `sugar-crush/composer.json` already declares

### Wave 2
- Tool-call pipeline unification: a real multi-tool-call turn shows real per-tool output live in the transcript, not a "thinking..." spinner followed by a final answer
- `Edit`/`Write` diffs render in the transcript
- Permission-prompt UI blocks on an `ask`-classified hook result and resumes correctly on both "once" and "always" replies
- Mouse mode is on by default, with `SUGARCRUSH_DISABLE_MOUSE`/`SUGARCRUSH_DISABLE_MOUSE_CLICKS` escape hatches both verified to actually disable what they claim to
- Command palette and `/` slash menu read from the same single `CommandRegistry` source — confirm by grep that `PaletteAction` no longer has an independent item list, or that whatever remains of it is explicitly the two palette-only pseudo-actions per §4 E1
- Full suite shows 0 new failures/errors versus the Wave 0 baseline, re-confirmed after every single Wave-2 step (not just once at the end of the Wave)

### Wave 3
- A fresh `bin/sugarcrush` invocation, with no manual setup, has a non-empty session in `/sessions` after the first turn
- Ctrl+Tab actually cycles session tabs in a real terminal (manual verification — this depends on `candy-core`'s `InputReader` change, which cannot be meaningfully unit-tested for "does the real terminal actually send this sequence" the same way the rest of this plan is)
- `/bg` backgrounds a task and it later reports completion without blocking the prompt
- The dead `App`/`Tui\Renderer` system is either fully retired (files deleted, confirmed via grep that nothing references them) or fully migrated onto (confirmed via the reachability test) — not left in a half-state where both the live and dead paths still exist side by side
- Skills are genuinely loaded and auto-triggerable in a real `bin/sugarcrush` session — confirmed by W4.S1's reachability test, not merely by `AppBuilderTest.php`'s own unit test

### Wave 4
- `FeatWiringReachabilityTest.php` passes, covering every row in the Executive Summary's "🟡 Fully built... never wired" table
- `README.md`'s capability list and test-count claims match reality at time of commit (the same class of drift `crush_code_update.md` found in the original build's docs — verify this one specifically, don't assume it's fine because it was addressed once before)
- `/sugarcrush-feat-review` has been run at least once against the fully-landed plan and returned zero findings

### Breaking changes checklist
For each Wave, verify: no changes to existing public API signatures beyond what each step's spec explicitly calls for (e.g. `Skill`'s constructor gaining a `source` field in W1.D1 is an intentional, spec-called-for exception); existing tests continue to pass; `.sugar-crush/config.json`/`config.dev.json` remain compatible; session data from pre-plan sessions still loads (relevant for Wave 3's `SessionStore` wiring — a session created before this plan ran must not break when `bin/sugarcrush` starts constructing/seeding sessions differently).

---

## Documentation Requirements

Every step's Builder Agent handles its own in-code documentation as part of the step itself (see the Step Builder Agent prompt template's step 6, above) — this section covers the whole-plan-level documentation that only makes sense once enough steps have landed to describe coherently.

Before Wave 4 is considered complete:
- `README.md`'s feature/capability list reflects every Wave 1-3 change that's user-visible (non-interactive CLI mode, mouse support, image rendering, auto-generated session titles, the command palette/slash-command unification, cross-tool skill import)
- `CHANGELOG.md` gets one entry per Wave, dated, summarizing what shipped (mirroring the original build's R30 precedent, which added this file for the first time — if it doesn't exist yet at the point this plan starts, W4.S4 creates it; if the original build's R30 already created it, W4.S4 appends to it rather than recreating it)
- `sugar-crush/CALIBER_LEARNINGS.md` gets an entry for any pattern this plan's own execution surfaces that's worth a future agent knowing about (e.g., anything discovered during implementation that contradicts or refines a `crush_feat.md` recommendation — the recommendations were researched, not guaranteed correct, and a Builder Agent that finds one doesn't quite match reality should say so here, not just silently work around it)
- Any new `SKILL.md`-adjacent file (none currently planned in Waves 1-4, but if a step's implementation ends up needing one) follows the existing repo convention for skill authoring
- A migration note if any step changes an on-disk format a running instance might already have (session DB schema, memory store layout) — none of the currently-planned steps are expected to require this, but if a Builder Agent discovers one does, flag it explicitly in the step's report rather than silently handling it

---

*This plan is a companion to `/home/sites/sugarcraft/crush_feat.md` (the specification) and a structural sibling to `/home/sites/sugarcraft/crush_code_plan.md` (the original orchestration mechanism this one is cloned from) and `/home/sites/sugarcraft/crush_code_update.md` (the audit whose findings shaped every process rule in this document's "Lessons Applied" section). Start or resume execution with `/sugarcrush-feat-build`. Run the whole-plan audit with `/sugarcrush-feat-review`.*
