# Qwen3.8-Flash-Next support — implementation plan & execution protocol (sugar-crush)

Status: COMPLETE (Q1–Q10 committed; §15 audit zero-findings)
Audit: PENDING — §15 loop runs post-Q10-commit
Started 2026-09-01 · Rev 2 2026-09-01: consolidated after dual review — kickoff is SERIAL (Q1 first), review rubric is §13, closure auditor is §15 · Repo: /home/sites/sugarcraft (branch master, commit-locally, NEVER push)
Commit ledger: Q1 27eafe5bf · Q2 52ea65370 · Q3 674f14f0a · Q4 95bde6ad8 · Q5 99d754f7a · Q6 78e1da2d1 · Q7 4cad0ed12 · Q8 7f4d641de · Q9 d92acd43e · Q10 pending(post-commit record; lag-by-one)
Companion files: `qwen_worklog.md` (live state — update after EVERY agent), `qwen_prompt.md` (start/resume brief).
Condensed audit evidence lives in Part III of this file; raw probe captures in /tmp/qwen-probes/.

---

# PART I — EXECUTION PROTOCOL

## 1. Step lifecycle
Each step Q1–Q10 runs exactly this loop, executed by the orchestrator — and every action in it (status checks, sha captures, commits included) is a SPAWNED CODER TASK (§12); the orchestrator never executes anything itself:
1. **BUILDER** — fresh `task(subagent_type=coder)` per step (never reused across steps). Input: this file's step spec (Part II) verbatim + Part III evidence it cites + conventions block (§8). Output: code+tests implemented, RED-BEFORE-GREEN evidence pasted for every new behavioral test (§13 category 6), gates run, worklog entry appended (§7), 8-section report (files touched, decisions, gate output tail, deviations, follow-ups).
2. **REVIEWER** — fresh read-only coder task (§12 carve-out). Input: step spec, pathspec-scoped diff `git diff {STEP_START_SHA}..HEAD -- <step touch-list paths>` + `git status` + `git show --stat` (§2), §13 checklist. MUST NOT receive prior findings and MUST NOT be told a previous review happened. Output: findings list with severity BLOCKER/MAJOR/MINOR/NIT, each with file:line. **Verdict contract:** the report's LAST LINE is exactly `STEP_REVIEW_RESULT: CLEAN` or `STEP_REVIEW_RESULT: FINDINGS`, and it states `reviewed-at <sha>` (HEAD at review time — if HEAD moved mid-review, the review is VOID, re-run, §2). A bare/blank verdict or missing §13 category-by-category accounting = FAILED review → re-run with a new reviewer; counts toward the cycle cap.
3. **FIXER** (only if BLOCKER or MAJOR) — fresh coder task: reproduce each finding first (§10 fixer discipline), fix, update/add tests, re-run gates, append per-finding dispositions + worklog entry. Back to 2 (new reviewer, cycle++). **Cap 5 cycles** → escalate: an escalation is a COMPLETED step, not a failure — record the question VERBATIM, the file:line that prompted it, and the options under `Awaiting user decision:` in the LOG, set status `awaiting-user`, PARK the step, and continue with any step not depending on it (Lane A steps whose input the parked step produces must still wait — the lane is serial; genuinely independent steps proceed). Never guess the user's answer; carry the open question on every resume.
4. Loop breaks at **CLEAN** (no BLOCKER/MAJOR; MINOR/NIT recorded in worklog Follow-ups).
5. **COMMIT** — orchestrator spawns committer task (single-slot queue — §2): verify gate green, stage EXACT file list from worklog, `git commit` on master with template (§9), NO push. Record SHA in worklog; that SHA is the next step's STEP_START_SHA and the META `NEXT_START_SHA`.

## 2. Lanes & serialization
- **Kickoff is SERIAL. There is no parallel dispatch anywhere in this plan.** Q1 (the `sugar-crush/.sugar-crush/config.dev.json` flip, E-72) runs FIRST, end to end (build → review → commit), before Q2 is dispatched. It is minutes of work, and two writers on one shared checkout is the hazard this ordering removes.
- **Lane A (serial, one agent at a time ever)**: Q2 → Q3 → Q4 → Q5 → Q6 → Q7 → Q8 → Q9 — every step edits `src/Providers/SglangProvider.php` and/or its sibling test files; concurrent builders would collide.
- **Lane B**: Q1 (pre-Lane-A, above), then Q10 (post-Q9; its docs describe final behavior).
- **Committer queue: only one committer task at a time system-wide.** A reviewer snapshots `git rev-parse HEAD` before finishing and **VOIDs + restarts if it moved mid-review** (fresh reviewer on the new HEAD; the voided attempt is logged `RECOVERED:`-style per §3 discipline).
- **Review input is pathspec-scoped**: review ONLY via `git diff {STEP_START_SHA}..HEAD -- <step touch-list paths>`; ignore out-of-lane dirt (the tree is shared; a full-tree diff would import another lane's in-flight edits as phantom findings). `qwen_worklog.md` and `qwen_prompt.md` are shared append surfaces — edits to them are EXEMPT from the scope-BLOCKER rule; code touches are not.

## 3. Failure handling — recovery ladder
- **A dead agent is not a result.** Empty/truncated output, or a missing required final line (e.g. a reviewer's `STEP_REVIEW_RESULT:`, §1.2/§13 cat.12) = DEAD AGENT, never a result; a blank reviewer is NEVER read as CLEAN. Before respinning anything, READ THE TREE: `git status`, `git diff --stat {start_sha}..` , the step's gate — and paste those outputs into a continuation brief containing verbatim: "a previous agent died mid-step; its uncommitted work is present, NOT REVIEWED, NOT TRUSTED — a starting point, not a foundation; judge against spec, fix or replace what's wrong, do not start over, do not delete anything that predates you." Rungs: (1) resume the SAME task once, asking to re-emit compactly; (2) respin a fresh agent with the tree-read brief; (3) the successor rebuilds from the tree under that brief; (4) typed block report to the user. Budget: 5 attempts for blank/aborted output; 3 for substantive-but-wrong; then status `blocked(agent-failure)`. Never ghost-write a dead agent's report; lost lines that get reconstructed carry a `RECONSTRUCTED:` prefix; every attempt and rung used is logged with `RECOVERED:`.
- Gate red after fix cycle → findings for next reviewer cycle, do not commit.
- Any change to a test listed in E-14/E-90 (pinned regression guards) requires a reviewer-accepted justification citing Part III. E-14's pins were verified 2026-09-01 to cover SINGLE-system inputs only — they are EXPECTED TO SURVIVE UNCHANGED under Q5; if any of them changes behavior, that IS a finding, not an amendment.

## 4. Hard rules (every agent brief must include these)
- NEVER `git push`, `git commit --amend` on pushed commits (everything here is local), force-push, or touch remotes. Direct-master commits are USER-AUTHORIZED for this plan and override AGENTS.md's PR flow — but NEVER push.
- NEVER run `composer install/update/bump`; NEVER commit a `composer.lock` change; NEVER add `repositories[]` to any lib manifest.
- Commits: author `Joe Huss <[EMAIL]>`, on master, message template §9, mechanics per §10 committer brief (message via `/tmp/opencode/qwen-Qn/msg.txt` + `git commit -F`). **Caliber exception (deliberate, documented deviation from AGENTS.md):** code-step commits NEVER hand-stage caliber-managed outputs (CLAUDE.md, .claude/, .cursor/, .cursorrules, .github/copilot-instructions.md, .github/instructions/, AGENTS.md, CALIBER_LEARNINGS.md, .agents/, .opencode/). Verified 2026-09-01: no caliber pre-commit hook is installed; commits therefore proceed WITHOUT any manual `caliber refresh` — config churn would break the touch-list contract (mature-plan precedent: prompt_plan §1.9). If the user later wants sync, it lands in a separate bookkeeping commit.
- Code style: `declare(strict_types=1);` first line, PSR-12, public classes `final` unless extension is contract, immutable/fluent `with*()` returning new instances via private `mutate()`, bare accessors (no `get`), doc-comments cite `Mirrors charmbracelet/<repo>.<Method>` where applicable, comments explain WHY.
- Tests: PHPUnit 10, namespace `SugarCraft\...\Tests`, every public method touched gets ≥1 new/updated test; stream-write deltas sliced with ftell/fseek (no ftruncate;rewind). Run gates from `/home/sites/sugarcraft/sugar-crush` via `vendor/bin/phpunit` in a NON-TTY bash (under a PTY exactly 2 tests fail as tty artifacts — CompactModelSummaryTest compact + MouseModalGuardTest cmd-palette — discount only there). Prefer path-lists (`vendor/bin/phpunit tests/Providers/FooTest.php tests/Providers/BarTest.php`) over bare broad `--filter` regexes wherever a filter could over- or under-match ('SglangProvider' matches six suites); where a broad filter IS intended, the step gate says so.
- `git stash` is BANNED in shared-tree mode — a stash grabs the whole index/worktree, including another lane's in-flight edits.
- Edit ONLY files listed in the step's touch-list. Discovering you need a file outside the list = STOP and report `scope-blocked (file)` BEFORE editing — widening is the orchestrator's PRE-DISPATCH decision, not the builder's in-flight improvisation. Out-of-lane forced edits are reportable findings, not silent deviations; reviewer judges (unlisted-file edits = BLOCKER).
- **Dormant-code rule:** never delete, stub, deprecate, or narrow existing code that looks unfinished — wire it, build it out within scope, or stop + escalate. "Unfinished" is not "unused"; builders do not prune a predecessor's roadmap.
- **Anchor-staleness protocol:** Part II/III file:line anchors are HYPOTHESES. Verify each anchor you rely on against the live tree before editing; drift → fix the reference in the report AND worklog (never silently act on a stale line).
- **Scratch/identity hygiene:** private scratch dir `/tmp/opencode/qwen-Qn/` only. NEVER `git init` anywhere under `/home/sites/sugarcraft` — a stray repo init once clobbered the git identity and junk-committed to master in this repo's history. After EVERY committer, re-check `git -C /home/sites/sugarcraft config user.name user.email` still reads Joe Huss / [EMAIL]. Bash cwd is never assumed — absolute paths and `git -C` everywhere.
- Worklog appends go through `flock` (§7). No edits to `docs/plans/crush_code_*.md`, `left_steps.md`, crush-lane-* files.
- Do not embed raw model special-token strings in any tool params/commit message — refer descriptively (e.g. "think-tag pair", "ChatML control tokens").

## 5. Worklog file & state machine
`/home/sites/sugarcraft/qwen_worklog.md` holds (a) a STATE TABLE — META rows (`NEXT_START_SHA`, `BASELINE`, `OPEN-USER-QUESTIONS`) plus one row per step: status ∈ `pending | building | reviewing | fixing | committed | blocked(review-cycle) | blocked(agent-failure) | awaiting-user | declined`, cycle count, start SHA, commit SHA, gates result, next action; (b) a LOG — append-only lines (§7); (c) FOLLOW-UPS. The orchestrator updates the STATE TABLE (via committer or a tiny bookkeeping coder task) at every transition — and AT SPAWN TIME: the in-flight fields (step, lane, role, start_sha) are written when the task is dispatched, so a session-death mid-flight is recoverable. On resume: read STATE TABLE → next action is unambiguous; LOG gives full history. A gates cell not re-measured after the latest commit reads `not re-run (last: <step>@<sha>)` — stale figures must be visibly stale.

## 6. Progress invariants
- STEP_START_SHA recorded in worklog before builder dispatch; in-flight fields written AT spawn (§5).
- Worklog gets an entry at: builder-done, each review verdict, each fix, commit (with SHA + stat line), lane dispatches.
- Every suite figure names cwd+sha (§14). Figures without a domain are how a plan runs red unnoticed.
- A step is DONE only when: CLEAN verdict + commit SHA present + gates green recorded — and, at closure, VERIFIED-DONE by the §15 auditor.

## 7. Worklog entry format (append via flock)
```
flock /home/sites/sugarcraft/qwen_worklog.lock -c 'cat >> /home/sites/sugarcraft/qwen_worklog.md' <<'LOG'
- 2026-09-01T12:00Z Q3/builder | outcome=done | files=a,b,c | gates="phpunit tests/Providers/X.php: 42 OK @<sha> sugar-crush" | report=<one-line essence>
LOG
```
Phases: `builder`, `review-cN`, `fix-cN`, `commit`. `review-cN` lines carry FULL findings (severity, file:line, one-line why) or an explicit pointer line saying where they live; `fix-cN` lines carry the per-finding dispositions (§10).

## 8. Conventions block (paste into every builder/fixer brief)
- PSR-4, PHP ^8.3; sugar-crush lib root `/home/sites/sugarcraft/sugar-crush`; run tests there.
- Immutability: `with*()` returns clone via private `mutate()`; nullable config fields paired with `bool $xSet` sentinel.
- Factories: `::new()`, never `::create()/::make()/::default()`.
- i18n via `Lang::t()` if user-facing strings introduced (unlikely here).
- Docblock every non-obvious deviation with WHY + cite probe evidence (e.g. "verified live 2026-09-01: server forwards top-level reasoning_effort into template; pydantic enum is wider than template's accepted set").

## 9. Commit message template
```
sugar-crush: Qn <slug-title>

Why: <1-3 lines, cite qwen.md Part III evidence ids>
What: <bullet list of code changes with file:line>
Tests: <added/updated list; counts; gate command + result>
Review: <N> cycles; findings: <severity:one-liners or 'clean'>
Follow-ups: <MINOR/NIT deferred, or none>
```

## 10. Agent brief templates
**Builder brief** = "You are implementing step Qn of a plan. READ FIRST: qwen.md Part I §3+§4+§8, Part III sections {EIDS}, and the step spec below. {SPEC}. Verify every file:line anchor you rely on against the live tree before editing (§4 — anchors are hypotheses; report drift). Work only in listed files; if you need one outside the list, STOP and report scope-blocked BEFORE editing. Scratch in /tmp/opencode/qwen-Qn/ only; never git init under /home/sites/sugarcraft; absolute paths and `git -C` everywhere. Run every NEW behavioral test RED against the pre-change source FIRST and paste the verbatim red (§13 cat.6), then implement, then paste green. Append worklog entry §7 format. Final report 8 sections. No commits — orchestrator commits after review."
**Reviewer brief** = "READ-ONLY review of step Qn. Diff: `git -C /home/sites/sugarcraft diff {START_SHA}..HEAD -- {TOUCH_LIST_PATHS}` + status + `git show --stat`; ignore out-of-lane dirt; worklog/prompt files exempt from scope-BLOCKER (§2). Snapshot `git rev-parse HEAD` before finalizing; if HEAD moved mid-review, VOID yourself. Spec: {SPEC}. Evidence: qwen.md Part III {EIDS}. Part I §13 is your rubric — account for ALL 12 categories explicitly. You have NOT seen and may not ask about any previous review. May write ONLY gitignored tool caches (§12); MUST NOT modify or stage tracked files. Output findings (severity, file:line, why, suggested fix), then per §13 cat.12: `reviewed-at <sha>` and the FINAL LINE exactly `STEP_REVIEW_RESULT: CLEAN` or `STEP_REVIEW_RESULT: FINDINGS`. Also verify: every behavioral change has a test that would fail without it; pinned-test changes (E-14/E-90) carry reviewer-acceptable justification; worklog entry exists."
**Fixer brief** = "Apply review findings for step Qn cycle {N}: {FINDINGS}. Before each fix, REPRODUCE the finding; not reproducible → report the evidence and do NOT fix. Mandatory per-finding disposition line in the report + worklog: `fixed (file:line) | not-reproducible (evidence) | scope-blocked (file)` — a silently skipped finding is a lie about the work. A second problem discovered while fixing = REPORT it, don't fix it. A need outside the touch-list = STOP with scope-blocked. Same rules as builder + keep fixes minimal and scoped to findings. Update tests. Worklog entry. Report per-finding dispositions."
**Committer brief** = "Commit step Qn. Worklog lists changed files: {LIST}. Run gate: {CMD}. If green: stage exactly those files (+ worklog/prompt); do NOT stage caliber-managed outputs (§4 exception). Write the rendered §9 message to /tmp/opencode/qwen-Qn/msg.txt and `git -C /home/sites/sugarcraft commit -F` that file — NEVER `-m` with a heredoc: backticks in the rendered text EXECUTE and corrupt the message. Post-commit forensics: `git show --stat HEAD` vs the touch list — equality or a named explanation; paste the claim-vs-diff line into LOG. Record commit SHA + META NEXT_START_SHA in the STATE TABLE in the same bookkeeping edit. Re-check `git -C /home/sites/sugarcraft config user.name user.email` (§4). Report SHA + shortstat. NO PUSH."

## 11. Milestones (full-suite gates)
Full `vendor/bin/phpunit` for sugar-crush runs at: after Q5 commit (serialization core changed), after Q9 commit (pre-Q10), and at Q10/closure (again independently by the §15 auditor). Every full-suite figure is compared against the §14 BASELINE row and records cwd+sha beside the number. Red full-suite → treat as findings, open a fix cycle on the last committed step before continuing.

## 12. Tooling model (who executes what)
- The orchestrator (build agent) has NO bash tool — it cannot run git, phpunit, curl, or flock directly. It spawns agents, reads files, and tracks state. Its own Read/grep are inspection only; never execution of plan work.
- ALL command execution happens inside coder subagent tasks via their bash tool: gate runs (`vendor/bin/phpunit <paths>`), git staging + commits on master (NEVER push), flock-guarded worklog appends, evidence greps, the Q10 live smoke. Every such command is embedded verbatim in the agent's brief and its output pasted into the agent's report.
- BUILDER / FIXER / COMMITTER = `task(subagent_type=coder)` write-capable. REVIEWER = `task(subagent_type=coder)` with an explicit READ-ONLY constraint: may run read-only bash (git diff/status/log, grep, re-running gate commands for verification) and may write ONLY gitignored tool caches (`.phpunit.cache/`, `.phpunit.result.cache`, `.php-cs-fixer.cache` — all covered by /home/sites/sugarcraft/.gitignore, verified 2026-09-01); MUST NOT modify or stage tracked files.
- Orchestrator-side state edits (STATE TABLE) are folded into the committer task whenever possible, otherwise a tiny dedicated bookkeeping coder task — the orchestrator never edits files itself.

## 13. Review checklist (the reviewer's rubric — account for EVERY category explicitly)
1. **Requirements & touch-list conformance + claim-vs-diff.** Step-spec Done-when clauses vs what the diff actually does. Compare commit-message/report claims LINE BY LINE against `git show --stat` + the pathspec diff. A false claim is a BLOCKER regardless of code quality.
2. **Production reachability.** Trace `bin/sugarcrush` → the changed code with ZERO test-only setup, and NAME THE FULL CHAIN in the review. Especially for config keys: Q3 = config.dev.json → ProviderFactory schema (:88-90) → createSglang (:706-723) → SglangProvider constructor → wire emission (:689). Code reachable only from tests = finding.
3. **Completeness.** No stubs, no TODOs, no dead branches introduced by this step.
4. **Conventions.** §8 + code-style rule of §4.
5. **REAL tests only.** Forbidden as SOLE assertions: `method_exists()`, `assertNotNull()`, `count(...) > 0`. Assert exact values; cover both polarities (positive AND negative paths); include pathological input.
6. **Red-before-green evidence (builder-run, §1.1).** Every new behavioral test carries pasted verbatim RED output against the pre-change source, then GREEN after implementation. The reviewer verifies the pastes (red must be THIS test failing for the stated reason, not an unrelated crash). `git stash`-derived evidence is INADMISSIBLE (banned, §4).
7. **Regression vs recorded baseline.** Suite figures compared against the §14 BASELINE row; every figure names cwd+sha. Skip-canary: a 2nd skip beyond baseline = vendor closure broke = stop + investigate (never absorb silently).
8. **Untrusted-text rule.** Any fixture copied from /tmp/qwen-probes or Part III is pasted VERBATIM (record byte count or sha256 beside it); nothing reconstructed from memory.
9. **No swallowed errors.** Silent catches, `@`-suppression, `?? null` past a should-throw — all findings.
10. **Deleted/weakened/renamed-out tests or dormant code = findings.** Cross-check the diff against the §4 dormant-code rule and E-14/E-90 pin survival.
11. **Done-when ledger.** Write the step's Done-when clauses as a numbered list; per clause name the evidence (file:line or test name). "Nearly matches" = finding. **Falsifiable-brief clause:** a step-spec claim or anchor may be declared FALSE by the reviewer — that judgment outranks the brief, and the orchestrator re-baselines the step from the tree.
12. **Verdict contract.** Report states `reviewed-at <sha>` (VOID + restart if HEAD moved mid-review, §2), accounts for all 12 categories, and its LAST LINE is exactly `STEP_REVIEW_RESULT: CLEAN` or `STEP_REVIEW_RESULT: FINDINGS`. Bare/blank verdict or missing checklist accounting = failed review → re-run with a new reviewer; counts toward the cap (§1.2). Reviewers are NEVER told a previous review or its findings happened (§1.2).

## 14. Baseline & progression figures
- After Q1 commits and BEFORE Q2 is dispatched, a bookkeeping coder runs the FULL sugar-crush suite in non-tty bash at the post-Q1 HEAD and records the §5 META `BASELINE` row: `Tests / Assertions / Skipped @ sha / cwd`. That row is NEVER edited afterwards.
- EVERY suite figure written into the worklog names cwd+sha. Figures without a domain are how a plan runs red unnoticed.
- **Skip-count canary:** a 2nd skip beyond the baseline figure = the vendor closure broke = stop and investigate (the §4 composer-ban makes this unlikely; it is still watched).
- **Predict-before-measure:** the committer states the PREDICTED Tests/Assertions delta in the LOG before running the full gates; a missed prediction is investigated, not excused.

## 15. Plan auditor (closure; after Q10)
- After Q10 commits, a FRESH READ-ONLY plan auditor runs, trusting NO STATE TABLE and NO LOG: it re-derives every Qn's status from `git log {BASELINE_sha}..HEAD -- <touch-list>` plus READING the actual diffs — never commit-message claims alone (claims misname what a step did; the diff is the fact) — plus grepping live source, plus re-running each step's gate and the FULL suite.
- Verdict per step: VERIFIED-DONE, or findings → the normal fix loop (§1.3) applies to the step.
- The loop uses BRAND-NEW auditors, capped at 3 rounds or until a zero-findings round; each round's output is appended VERBATIM to the LOG — an audit that appended nothing did not run. "Plan complete" == a zero-findings round.

---

# PART II — STEP CATALOG

Legend: `src/` and `tests/` are relative to `/home/sites/sugarcraft/sugar-crush/`. Evidence IDs are canonical; Part II cites, never renumbers. Gate column: exact targeted command run by builder AND committer.

## Q1 — Flip dev-sglang config to the Qwen model (Lane B, pre-Lane-A)
- **Depends**: none. **Runs FIRST, serially, before Q2 is dispatched (§2).**
- **Goal**: dev provider serves Qwen with a valid effort value immediately; smallest possible unblock.
- **Changes**: `sugar-crush/.sugar-crush/config.dev.json` → `providers.dev-sglang.model = "Qwen/Qwen3.8-Flash-Next"` (E-70), `reasoningEffort = "xhigh"` (E-40: `max` guarantees HTTP 400 while thinking is on). Do NOT touch the inert repo-root copy (E-72) — verify the target path before writing.
- **Code touch-list**: none.
- **Test touch-list**: `tests/Providers/ProviderFactoryTest.php` :786-797 (`testDefaultConfigModelFallbackResolvesFromProjectConfigForDevSglang`) — EXPECTED update: the live dev-config pin (:790) flips its model string to `Qwen/Qwen3.8-Flash-Next`; KEEP the baseUrl discriminator (:796-797) — it is what proves the value came from the project file. `SglangProvider::DEFAULT_MODEL` (:62) stays `deepseek-ai/DeepSeek-V4-Flash-0731` — src pins on it are NOT config reads and must not change. BUILDER also greps `grep -rn "DeepSeek-V4-Flash-0731" /home/sites/sugarcraft/sugar-crush/tests/` and updates any other LIVE-CONFIG assertion it finds, listing each in the report.
- **Gate**: `vendor/bin/phpunit tests/Providers/ProviderFactoryTest.php` plus `php -r 'var_dump(json_decode(file_get_contents("/home/sites/sugarcraft/sugar-crush/.sugar-crush/config.dev.json"),true)["providers"]["dev-sglang"]);'` output recorded.
- **Done when**: JSON valid, keys exact, updated pin green. Worklog note: sessions that append a SECOND system row keep 400-ing until Q5 (E-10); `xhigh` is already template-valid top-level (E-41), so plain single-system sessions work after Q1; Q4 is hardening, not unblocking.

## Q2 — Qwen family predicate + conservative context window (Lane A #1)
- **Depends**: Q1 committed (§2 serial kickoff; §14 baseline recorded before dispatch).
- **Goal**: provider recognizes the model id and stops lying about the window.
- **Evidence**: E-70 (model ids), E-71 (context), E-60 (window used by compaction tiers).
- **Code touch-list**:
  - `src/Providers/SglangProvider.php`: model-family token const near :110 (mirror the DEEPSEEK_V4_* const block); `isQwen3Next()` predicate next to `isDeepSeekV4()` (:765-768) matching `qwen3.8` case-insensitively against the served model id; `contextWindow()` (:432-437) → the CONSERVATIVE effective-input cap for the Qwen family, `min(1_000_000, 748_602 − 4096)` = **744_506** (748_602 = `max_req_input_len`, E-71; 4096 = max_tokens headroom, E-50 — `allow_auto_truncate=false` means over-long is a HARD error, so err small now; follow-up rows track the raw constants either way); expose `748_602` as its own const for later compaction math; `defaultReasoningEffort()` (:911-916) → `'xhigh'` for Qwen.
- **Test touch-list**:
  - `tests/Providers/SglangProviderTest.php` — extend contextWindow matrix (:163-238) with Qwen rows: exact cap value 744_506; unknown model keeps 196608; DeepSeek rows unchanged.
  - `tests/Providers/SglangProviderRequestBuildingTest.php` — family matrix (:454-645): Qwen gets no DeepSeek-only params (top_p stays omitted; temperature 0.7 per E-61).
- **Gate**: `vendor/bin/phpunit tests/Providers/SglangProviderTest.php tests/Providers/SglangProviderRequestBuildingTest.php`.
- **Done when**: predicate + cap value + default effort covered by new assertions (cap asserted EXACTLY); all pinned DeepSeek behavior green.

## Q3 — Make chat_template_kwargs configurable and wired (Lane A #2)
- **Depends**: Q2.
- **Goal**: `enable_thinking` / `preserve_thinking` / template-side effort reachable from config. Today `CompleteRequest::$extraTemplateKwargs` (`src/Providers/CompleteRequest.php` :51 docblock, :67 promoted prop — NOT in SglangProvider) is emitted at the wire (`src/Providers/SglangProvider.php:689`) but has NO setter anywhere and no config key (E-50).
- **Code touch-list**:
  - `src/Providers/ProviderFactory.php` — schema (:88-90) adds optional `templateKwargs` (associative, string keys) to the `sglang` block; construction site `createSglang()` (:706-723) passes it through, mirroring the `configuredReasoningEffort` pattern (:721, normalizer :762); keep containment/env-expansion rules (:186-211, :487-502).
  - `src/Providers/SglangProvider.php` — constructor (:259) accepts `array $extraTemplateKwargs = []` and feeds the existing wire at :689 (the `!== null && !== [] && !== ''` filter at :701-704 keeps empty config as key-absent); merge precedence: explicit per-request DTO > constructor config (documented, sentinel-checked).
  - `sugar-crush/.sugar-crush/config.dev.json` — OPTIONAL `"templateKwargs": {}` placeholder key (empty object; proves the path without changing behavior). (Shared file with Q1 — moot: Q1 commits first, §2.)
- **Test touch-list**:
  - `tests/Providers/ProviderFactoryTest.php` — config plumbing test near :150 (the schema-key assertions): templateKwargs parsed, bad types rejected.
  - `tests/Providers/SglangProviderRequestBuildingTest.php` — kwargs emission (:190 area): set→emitted under `chat_template_kwargs`; empty→key absent; per-request DTO overrides config.
- **Gate**: `vendor/bin/phpunit --filter 'RequestBuilding|ProviderFactory'` (intended broad: exactly two suites match).
- **Done when**: both emission and override paths asserted; no DeepSeek param regressions.

## Q4 — Effort sanitization + correct placement for Qwen (Lane A #3)
- **Depends**: Q2, Q3.
- **Goal**: never send a template-invalid effort again; route Qwen effort through `chat_template_kwargs` (E-40/E-41), keep top-level field for DeepSeek-family backends (docblock :691-697).
- **Evidence**: E-40 (accepted set low|medium|xhigh, default xhigh; template 400 text), E-41 (top-level forwarded; pydantic enum wider; high/max still 400 with thinking on; skipped check when thinking off), E-42 (kwargs effort reaches template), E-22 (enable_thinking:false → reasoning null, effort check skipped).
- **Code touch-list**:
  - `src/Providers/SglangProvider.php` — near :229-237 (REASONING_EFFORT_LEVELS) add template-safe set for Qwen; new private `sanitizeEffortForTemplate(?string, bool $thinkingOn): ?array` returning kwargs pair + optional notice; map `high|max→xhigh`, `minimal→low`, `none→` (drop effort; if Qwen: emit `chat_template_kwargs.enable_thinking=false` per E-22/E-42); unknown values throw `InvalidArgumentException` at build time (fail before network). Validation precedence stays request→config→model (:877-899). Qwen emits `reasoning_effort` INSIDE `chat_template_kwargs` (:689 merge, replaces :690-698 top-level ONLY for Qwen family).
  - **DTO-vs-sanitized collision (mandate, state in the docblock):** when a config-level kwarg and the sanitized effort both target `chat_template_kwargs.reasoning_effort`, the SANITIZED template value wins over config kwargs; an explicit PER-REQUEST DTO kwarg overrides both (Q3 precedence).
- **Test touch-list**:
  - `tests/Providers/SglangProviderRequestBuildingTest.php` — effort matrix (:675-834) new Qwen arm: every sglang-legal level incl. max/high/minimal/none/invalid; assert kwargs placement, mapped value, enable_thinking for `none`, throw for garbage; the collision rule (config kwarg loses to sanitized effort; per-request DTO kwarg wins); DeepSeek arm UNCHANGED (pin: top-level stays).
- **Gate**: `vendor/bin/phpunit --filter 'RequestBuilding'` (intended broad: one suite).
- **Done when**: with Q1's config, emitted body contains `chat_template_kwargs.reasoning_effort:"xhigh"` and no top-level effort for Qwen; each mapping has a test.

## Q5 — Single-system enforcement (Lane A #4; the headline fix)
- **Depends**: Q2. (Lane A is strictly serial; this ordering keeps SglangProvider.php edits conflict-free.)
- **Goal**: provider never emits more than one `system` message (E-10: template 400s otherwise). Fix in provider, mirroring Bedrock/Vertex hoists (E-11); sugar-crush bypasses the merge proxy (E-12).
- **Evidence**: E-10, E-11, E-12, E-13/E-53 (emission sites), E-14/E-54 (pins).
- **Code touch-list**:
  - `src/Providers/SglangProvider.php` `formatMessages()` (:963-981) + prepend site (:672-677): collect ALL SystemMessage rows (history + prepended prompt) in stable order — prompt FIRST, then history order; join non-empty contents with `"\n\n"`; emit exactly ONE `{role:system}` at index 0; when nothing but prompt exists, behavior identical to today; system content never contains image parts (no change needed, assert in tests).
- **Test touch-list**:
  - EXISTING PINS MUST STAY GREEN UNCHANGED: `tests/Providers/SglangProviderTest.php:402` (`testFormatMessagesWithSystemMessage`) / `:430` (`testFormatMessagesWithMultipleMessages`) and `tests/Providers/SystemPromptTransmissionMatrixTest.php:687` (`testSglangTransmitsSystemPromptAsTheLeadingSystemMessageOnBothPaths`) were verified 2026-09-01 to exercise SINGLE-system inputs only — the merge leaves them passing as-is. Per E-14, ANY behavior change in them IS a finding, not an amendment.
  - ALL new coverage lives in NEW `tests/Providers/SglangProviderSystemMergeTest.php` — cases: prompt-only; prompt+launch-notice+context-reminder (Chat.php :5747/:6422 shapes); cancel marker (:1504); queued-prompt notice (:6035 shape) and automatic-compaction-tier notice (:8953 shape); title one-shot (system at index 1 behind prompt, :7438); ordering preserved; double-newline join; empty-string system rows dropped; zero-system passthrough (no systemPrompt → first history system becomes the single system).
- **Gate**: `vendor/bin/phpunit tests/Providers/SglangProviderTest.php tests/Providers/SystemPromptTransmissionMatrixTest.php tests/Providers/SglangProviderSystemMergeTest.php`, then committer ALSO runs FULL suite (milestone §11).
- **Done when**: provider output for the E-13 scenario set contains exactly one system message at index 0 in every test; the E-14 pins survive UNCHANGED (their survival is the regression guarantee).

## Q6 — Streamed usage revival (Lane A #5)
- **Depends**: Q2 (family check reused), Q3 (kwargs not needed here; serial only due to file sharing).
- **Goal**: request + parse usage on streaming; today `tokensUsed: 0` hardcoded (SglangProvider.php:1166) and usage chunks dropped (E-27/E-55).
- **Evidence**: E-30 (usage chunk shape), E-55.
- **Code touch-list**:
  - `src/Providers/SglangProvider.php` — stream arm of `buildParams`/`completeStream` (:462, :642 regions): add `stream_options => {include_usage: true}` alongside `stream:true`; chunk-loop guard (:524): accept chunks where `choices` empty but `usage` present → emit final usage (extend the streamed result/event with tokensUsed incl. flat `reasoning_tokens` per E-31); `parseResponse` (:1027-1043): also read flat `usage.reasoning_tokens` (batch token pin at :1040).
  - `src/Usage.php` — DEFAULT: leave untouched. If a test fails against it after the stream changes, it becomes in-scope by that evidence (a failing test IS the reachability proof, §13 cat.2) — record which.
  - **Cost note:** the provider hardcodes `costUsd: 0.0` (:571, :1041, :1167) and `costPer1kTokens()` returns 0.0 (:439-443), so Q6 revives the usage READOUT only; the dollar-based `SUGARCRUSH_MAX_COST` cap (Bootstrap.php:6117) stays inert on sglang until a pricing map exists → worklog FOLLOW-UP.
- **Test touch-list**:
  - `tests/Providers/SglangProviderStreamingTest.php` — EXTEND (the 6 existing tests, incl. the captured-live-stream fixture test at :236, must stay green): usage-only zero-choice chunk yields final tokensUsed; `stream_options` present iff streaming.
  - NEW `tests/fixtures/qwen-usage-stream.txt` — verbatim copy of `/tmp/qwen-probes/08-stream-usage.txt` (§13 cat.8: paste, record byte count, never reconstruct).
  - `tests/Providers/SglangProviderTest.php` — batch: `total_tokens` + `reasoning_tokens` parsed from flat usage — extend `testCompleteMakesHttpPostAndReturnsCompleteResponse` (:520; the `tokensUsed` assert at :556), the test the ":560" draft anchor mispointed (:560 is `testCompleteWithToolCalls`).
- **Gate**: `vendor/bin/phpunit tests/Providers/SglangProviderTest.php tests/Providers/SglangProviderStreamingTest.php`.
- **Done when**: streamed final usage asserted; SUGARCRUSH usage readout sees nonzero tokens on sglang streams (one integration-flavored assertion in the streaming test on the yielded result shape).

## Q7 — finish_reason completeness (Lane A #6)
- **Depends**: Q6 (same regions).
- **Goal**: no silent loss of buffered tool calls; truncation visible (E-32).
- **Evidence**: E-32 (matched_stop/finish values), E-26, E-56 (retry context).
- **Code touch-list**:
  - `src/Providers/SglangProvider.php` — on stream end OR finish_reason `length`/`abort` with non-empty tool buffer: flush best-effort — decode complete JSON args, warn (TruncationGuard machinery :1266-1305 reuse) on incomplete ones, never emit half-decoded args; batch `parseResponse`: capture finish_reason, expose truncated flag on result; `stop` unaffected.
- **Test touch-list**:
  - `tests/Providers/SglangProviderTruncationGuardTest.php` — Qwen-family rows: buffered complete+partial calls at `length` → complete emitted + warning, partial dropped w/ warning.
  - `tests/Providers/SglangProviderStreamingTest.php` — synthetic `length`-finish stream fixture; assert assembly happened + flag.
- **Gate**: `vendor/bin/phpunit --filter 'TruncationGuard|Streaming'`.
- **Done when**: both new tests run RED against the pre-change source with the verbatim output pasted into the report (builder red-before-green, §13 cat.6 — run them before implementing, or against a `/tmp/opencode/qwen-Q7/` harness copy of `git show {start_sha}:src/Providers/SglangProvider.php`), and GREEN after.

## Q8 — Parse provider error bodies (Lane A #7)
- **Depends**: Q7 (serialization of error path stable).
- **Goal**: user sees `error.message` text, not clipped raw JSON (E-56).
- **Evidence**: E-56 (surfacing chain), E-40 (400 body shape), E-10 (template 400 text).
- **Code touch-list**:
  - `src/Providers/SglangProvider.php` — catch sites (:457-459, :574-576): if `RequestException::getResponse()` non-null, json-parse body, use `error.message` (fallback: existing behavior on unparseable); KEEP exception class `RuntimeException` wrapping so retry classification (TransientFailure :197, :411-420) is unchanged — 400 stays permanent.
- **Test touch-list**:
  - `tests/Providers/SglangProviderTest.php` — amend :652/:723 expectations (message now clean text); add: canned 400 bodies from E-10 and E-40 produce exactly the template message string in the RuntimeException; non-JSON body still falls back.
- **Gate**: `vendor/bin/phpunit tests/Providers/SglangProviderTest.php` (committer adds the TransientFailure suite file — locate via `grep -rln TransientFailure tests/`).
- **Done when**: both canned bodies surface verbatim; no retry-behavior regressions (TransientFailure tests green).

## Q9 — Wire artifacts: leading newlines & tool-call content (Lane A #8)
- **Depends**: Q6, Q7 (content stream regions).
- **Goal**: cosmetic correctness of the content channel for Qwen (E-21, E-25, E-26).
- **Evidence**: E-21 (content prefix), E-26 (stray newline deltas between parallel call groups), E-25.
- **Code touch-list**:
  - `src/Providers/SglangProvider.php` (content paths in parseChunk/parseResponse ONLY — scope to Qwen family to avoid touching DeepSeek behavior): when thinking on (family+kwargs), trim leading whitespace runs from first content delta / batch content; when finish_reason tool_calls and trimmed content is whitespace-only → emit no content at all.
- **Test touch-list**:
  - `tests/Providers/ReasoningExtractionTest.php` or StreamingTest (builder chooses, list in report): fixture asserting first content delta with leading double-newline arrives trimmed for Qwen but UNTOUCHED for a DeepSeek model; whitespace-only content dropped alongside tool_calls.
- **Gate**: `vendor/bin/phpunit tests/Providers/ReasoningExtractionTest.php tests/Providers/SglangProviderStreamingTest.php tests/Providers/SglangProviderTest.php`; committer runs FULL suite (milestone §11 — last code step).
- **Done when**: artifacts trimmed without regressing any golden/snapshot tests (if a golden fixture needs regeneration, that is a BLOCKER-level review item — golden changes require explicit evidence citation).

## Q10 — preserve_thinking policy, docs, live smoke (Lane B final)
- **Depends**: Q1 (config), Q3 (kwargs path), ALL of Lane A committed. Runs after Q9 commits (§2 — serial everywhere).
- **Goal**: token-cost policy applied; docs reflect reality; one-shot live verification script.
- **Evidence**: E-28 (preserve_thinking default true, replay cost), E-42, E-11.
- **Code touch-list**:
  - `sugar-crush/.sugar-crush/config.dev.json` — `"templateKwargs": {"preserve_thinking": false}` (policy: save replayed-reasoning tokens; revisit later — record follow-up either way).
  - NEW `scripts/qwen-live-smoke.php` — standalone: against https://skynet2.interserver.net/v1 runs (1) single tool call, (2) 3 parallel tool calls streaming incl. usage chunk, (3) multi-system merge check via a stubbed provider call if feasible else direct wire check that ONE system msg is emitted by building a real Chat message stack through SglangProvider::formatMessages (public seam or reflection-free helper) — no auth, exit non-zero on shape mismatch. Docblock cites Part III.
  - `docs/ENVIRONMENT.md` (:32 area) — dev-sglang now = Qwen/Qwen3.8-Flash-Next, effort semantics, kwargs knob.
  - `qwen.md` — mark header `Status: COMPLETE (Q1–Q10 committed; §15 audit zero-findings)` + each step's SHA appended by committers (orchestrator ensures).
- **Test touch-list**: `tests/Providers/SglangProviderRequestBuildingTest.php` — one case: config `templateKwargs.preserve_thinking=false` reaches the wire (extends Q3 matrix). Smoke script is manual-gate (CI not required); builder runs it once live and pastes trimmed output into worklog.
- **Gate**: `vendor/bin/phpunit --filter 'RequestBuilding'` (intended) + `php scripts/qwen-live-smoke.php` (live) + FULL suite (final).
- **Done when**: live smoke exits 0 printing the 3 verified shapes; final commit records the conservative window + single-system + usage in a real response. THEN the §15 plan-auditor loop closes the plan.

---

# PART III — CONDENSED AUDIT EVIDENCE (cite these ids in commits/reviews)

## E-10 System rule — template raises HTTP 400 `BadRequest` `"System message must be at the beginning."` when any system message sits at index > 0 (or a system appears with a non-system before it). Live: /tmp/qwen-probes/05a,05b. Only messages[0] system content is ever rendered; system blocks reject image parts.
## E-11 Fix pattern exists in-repo — `BedrockProvider.php:321-326` and `VertexProvider.php:1670-1675` hoist/strip system messages (withoutSystemMessages + prompt prepend). SglangProvider has no equivalent: history system rows pass through `formatMessages` untouched (:973) and `EngineBackend::toTypedMessages` catch-all (src/Backend/EngineBackend.php:1545) re-types unknown rows AS SystemMessage.
## E-12 The merge proxy (`/home/my/sglang/opencode-system-merge-proxy.py`, 79 lines) merges the leading run of system messages into one at index 0 — but it serves opencode only; sugar-crush's baseUrl points at skynet2 directly, so protection does NOT apply.
## E-13 sugar-crush multi-system emission sites (real sessions hit them; re-classified against live source 2026-09-01): Chat.php launch notices :5747-5765, 70% context reminder :6422-6426, cancellation notice :1504, queued-prompt notice :6035, command/palette in-flight refusals :6051/:6104/:6150, automatic-compaction-tier notice :8953 (the actual compaction-tier row), session-resume notice :9408, slash notices :8215, title one-shot :7438-7441, /compact :8772-8780; sub-agent re-typing ProcessExecutor.php:930-975 (:943). NOTE: :1370 emits `Message::assistant(...)` (a background/fork notice), NOT a system row — removed from this set.
## E-14 Regression guards EXPECTED TO SURVIVE UNCHANGED: `tests/Providers/SglangProviderTest.php:402` (`testFormatMessagesWithSystemMessage`) / `:430` (`testFormatMessagesWithMultipleMessages`); `tests/Providers/SystemPromptTransmissionMatrixTest.php:687` (`testSglangTransmitsSystemPromptAsTheLeadingSystemMessageOnBothPaths`). Verified 2026-09-01: each exercises a SINGLE-system input only, so the Q5 merge must leave them passing bit-for-bit. If any of them changes behavior, THAT IS A FINDING (investigate the merge logic; do not "amend" the pin). No test today asserts "at most one system" — new coverage lives in SglangProviderSystemMergeTest.
## E-20 Reasoning arrives split by server: field `reasoning_content` on message (batch) / delta (stream); `content` separate. sugar-crush already trusts this field (ReasoningExtractor case-1 :43-46) and sends `separate_reasoning:true` (:659) — VERIFIED CORRECT for this deployment; do not change the flag; wire-pinned `ReasoningExtractionTest.php:318`.
## E-21 When thinking ON, non-stream `content` begins with a two-newline prefix (observed consistently; probes 02a-c).
## E-22 `chat_template_kwargs.enable_thinking:false` → `reasoning_content:null`, no prefix, effort check skipped.
## E-24 Tool calls arrive standard OpenAI: array entries `{id, index, type:"function", function:{name, arguments}}`; ids `call_`+24 lowercase hex; `arguments` is a JSON STRING (newlines/quotes escaped, numbers typed); non-stream still includes `index`; `finish_reason:"tool_calls"`; `content:""` alongside (probes 06,10).
## E-25 Parallel calls: 3 tools requested → 3 array entries index 0..2 all honored (probe 07a).
## E-26 Stream tool-call fragmentation: per index — opener delta `{index,id,type,name,arguments:""}`, then continuation deltas `{index, arguments}` ONLY; stray newline `content` deltas appear BETWEEN call groups; channel order strictly reasoning→content→tool_calls→finish→(usage)→[DONE] (probes 07b, 08). sugar-crush's `resolveStreamedToolCalls` (:1190-1222, index-keyed, ??= id/name, concat args, assembly gate :1200) matches this shape — already SUPPORTED.
## E-27 `[DONE]` handled only by falling through the `choices[0].delta` guard (:524); same guard DROPS the zero-choice usage chunk; loop ends at EOF, not on finish_reason (never sends `stream_options`).
## E-28 `preserve_thinking` DEFAULT TRUE — assistant history `reasoning_content` is re-rendered into prompt (+tokens measured, probe 09b); `chat_template_kwargs.preserve_thinking:false` accepted, drops it (09c).
## E-29 Assistant history accepted with `content:""` OR the key ABSENT entirely, identical tokenization (77 vs 77, probes 11a/11b) → `array_filter` stripping (:968) is safe. Tool-role round-trip `{role:tool, tool_call_id, content}` fully accepted (09a).
## E-30 Streaming usage chunk only when `stream_options:{include_usage:true}` sent; shape: final event with `choices:[]` + `usage`. `[DONE]` after it.
## E-31 `usage` fields FLAT: `prompt_tokens, completion_tokens, total_tokens, reasoning_tokens` — NO `completion_tokens_details` anywhere; `prompt_tokens_details` appears only with images ({cached_tokens,image_tokens}). Vendor extras to ignore: `choices[].matched_stop`, top-level `metadata.weight_version`.
## E-32 finish_reasons observed: `"stop"` (matched_stop 248046), `"tool_calls"` (matched_stop typically null — probe-08 line 35 shows a tool_calls finish chunk CAN carry matched_stop 248046; treat the field as ignorable noise, re-verified 2026-09-01). sugar-crush acts ONLY on `tool_calls` (:1148/:1200); `length/abort/error` unmapped → buffered tool fragments silently lost (:1204-1219 never fires).
## E-40 Template effort set is EXACTLY `xhigh (default) | medium | low`; invalid → clean 400 `{"object":"error","message":"Unexpected reasoning effort X. Supported types are xhigh (default), medium, and low.","type":"BadRequest","code":400}` (probe 03). `xhigh`/`low` inject an effort paragraph in the system block; `medium` none (prompt_tokens vary).
## E-41 TOP-LEVEL `reasoning_effort` is forwarded to the template; sglang pydantic enum is wider (`none,minimal,low,medium,high,xhigh,max`) so `high`/`max` pass pydantic then 400 AT the template whenever thinking is on (probe 04c); with thinking off the template skips the check (04b). Current config.dev.json pins `"max"` → guaranteed 400 on every request. Prefer kwargs placement to skip pydantic noise.
## E-42 Effort via `chat_template_kwargs` works as expected; unknown effort there hits the template raise directly (03).
## E-50 `$extraTemplateKwargs` plumbed to wire (:689) but NO in-tree setter and NO config key — enable_thinking/preserve_thinking unreachable pre-plan. The prop itself lives on `src/Providers/CompleteRequest.php` (:51 docblock, :67 promoted param), not on the provider. Only config knobs today: reasoningEffort, toolCallParser (ProviderFactory :88-90). Absent params entirely: `stream_options`, `tool_choice`, `parallel_tool_calls`, `n`, `seed`, penalties. max_tokens default 4096 (:653).
## E-53 See E-13 (system emission sites).
## E-54 See E-14 (test pins).
## E-55 Usage dead-ends: batch reads only `total_tokens` (:1040); stream hardcodes `tokensUsed:0` (:1166) and drops usage chunks (E-27) → SUGARCRUSH_MAX spend cap + usage readout nonfunctional on sglang streaming; and cost is structurally zero anyway — `costUsd: 0.0` hardcoded (:571/:1041/:1167), `costPer1kTokens()` returns 0.0 (:439-443), while `SUGARCRUSH_MAX_COST` is dollar-denominated (Cli/Bootstrap.php:6117) → reviving the cap needs a pricing map (worklog FOLLOW-UP), not just this fix. `reasoning_tokens` has zero readers in src/.
## E-56 Error surfacing: `http_errors` ON → 4xx becomes Guzzle ClientException with CLIPPED raw body; provider wraps `RuntimeException('SGLANG request failed: ...')` (:457-459/:574-576); Chat renders it at Chat.php:7653-7662. 400 classified permanent (TransientFailure.php:197); 5xx/408/429 retried ≤3 (:411-420); stream retried only pre-first-token — RUNTIME ANCHOR RE-DERIVED 2026-09-01: `Runtime::runStreaming` at src/Runtime.php:1155 (retry rationale docblock :1070-1153; `$emitted` gate + `TransientFailure::isTransient` decision at :1257-1267) — the earlier ":1219-1235" was stale. No error.message JSON extraction.
## E-60 `contextWindow()` returns 196_608 (:432-437) driving Chat 70/85/95% compaction tiers — wrong for this server.
## E-61 Family defaults when NOT DeepSeek: temperature 0.7 (:781-786), top_p omitted (:842-851), default effort null (:911-916, overridden by config tier). Kept for Qwen in Q2.
## E-70 Model ids: canonical served `Qwen/Qwen3.8-Flash-Next` (single /v1/models entry, `max_model_len:1000000`); bare alias `Qwen3.8-Flash-Next` also accepted (probe 12/12b). Use canonical.
## E-71 Server caps: context_length 1,000,000 (YaRN 4.0 over native 262,144), max_req_input_len 748,602, max_total_num_tokens 748,608, allow_auto_truncate FALSE (over-long requests error, no silent truncation), incremental_streaming_output FALSE (deltas are increments — matches provider assumption, zero refs in repo), stream_interval 1, no auth.
## E-72 TWO copies of config.dev.json exist: `/home/sites/sugarcraft/.sugar-crush/config.dev.json` (repo root) and `/home/sites/sugarcraft/sugar-crush/.sugar-crush/config.dev.json`. The runtime reads the SECOND ONLY: ProviderFactory `CONFIG_PATH` (:72) + `packageRoot()` (:161-164, = dirname(src/..)) + `defaultConfigPath()` (:176) — verified against live source 2026-09-01. The repo-root copy is INERT (identical bytes today). VERIFY PATH BEFORE WRITING any config edit; a containment test suite exists precisely because overshoot-to-parent was a real bug (ProviderFactoryTest :584-610). Mirror-or-ignore decision for the root copy: worklog FOLLOW-UP (user).
## E-80 Vision: has_image_understanding TRUE — content-part arrays with `image_url` data URIs work (13); BAD image bytes fail as HTTP 500 InternalServerError (not 400); with images `prompt_tokens_details={cached_tokens,image_tokens}`. Audio unsupported. Multimodal architecture (qwen4_exp; vision token ids 248053-248057). NOTE: sugar-crush `supportsVision` stays FALSE through this plan — `formatMessages` cannot emit image parts; out of scope, tracked in worklog FOLLOW-UPs.
## E-90 Test inventory for response parse (touch targets; counts verified 2026-09-01): SglangProviderTest (37), SglangProviderStreamingTest (6, captured-live parallel-stream fixture test :236), ReasoningExtractionTest (14), SglangProviderRequestBuildingTest (41), SglangProviderDsmlStreamingTest (7), SglangProviderTruncationGuardTest (23), ToolCallParser unit tests, ProviderFactoryTest (:891-950 default-parser matrix, :150-151 schema effort key, :786-797 live dev-config model pin).
## E-91 Raw evidence: /tmp/qwen-probes/ (13 probes + exact request bodies p*-body.json). Template source: HF chat_template.jinja fetched 2026-09-01; config.json model_type qwen4_exp, Qwen4ExpForConditionalGeneration, 48L MoE 512-exp top-10, MTP, hybrid linear attention.
## E-92 Known test-env artifacts (from prior sessions): under a live TTY exactly 2 tests fail (CompactModelSummaryTest compact, MouseModalGuardTest cmd-palette) — run gates in non-tty bash; never weaken those tests.
