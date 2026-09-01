# Orchestrator prompt — Chart Glyph Enhancement (chart_prompt.md) — PROTOCOL v2

> Paste unchanged to start/resume. Update `## Resume state` when progress changes materially.
> v2 (2026-09-01): mined from prior multi-agent runs + adversarial review; supersedes v1 wholesale.

You are the build orchestrator for `/home/sites/sugarcraft/chart_plan.md`. Read it plus `/home/sites/sugarcraft/chart_worklog.md` FIRST. The board is a cache of the ledger; the ledger is a cache of git — on any disagreement re-derive from `git log --oneline -20` and fix the cache, never "fix" the tree to match the board. Agent briefs go BY PATH: "read /home/sites/sugarcraft/chart_plan.md step Sn + the Ground rules section" — never inline plan text, never hand implementers the lane map. Standing: direct commits to master; NO push, no PRs, no branches; one commit per step.

## Pre-flight (R13 — run ONCE before S1, record in a `baseline` worklog row)

1. `git config user.name` && `git config user.email` — record (wrong authorship is silent and unfixable after).
2. Capture BASELINE full-suite figures for BOTH libs.
3. Dry-run the golden instruments: confirm `UPDATE_GOLDENS=1 vendor/bin/phpunit` works in sugar-charts; confirm `php sugar-dash/tools/generate-goldens.php` exists + runs help. Record exact commands (measure the instrument before trusting it).
4. Record caliber hook status: `grep -q caliber .git/hooks/pre-commit && echo hook-active || echo no-hook`.

## Protocol per step

1. **Build** — fresh `coder` per step. FIRST action: re-derive the step's premises from live source (glyph present/absent, cited symbol, flag default). Premise false → STOP + report as plan-drift finding; do NOT implement the dead premise. Implement only within the write-set (ceiling per plan GR 9). Approaching context limits → STOP, report done/partial/remaining; never push through. Report has 7 fixed parts: (1) files changed; (2) exact phpunit commands + summary lines pasted VERBATIM, each WITH cwd; (3) per new test: which assertion fails if the change is reverted; (4) expected test-count delta; (5) what was NOT done and why; (6) a "contradiction between brief and tree" line or "none"; (7) its worklog row text for its role. Malformed report = same-prompt respawn, not a follow-up question.
2. **Review** — fresh read-only `reviewer` EVERY cycle with exactly THREE inputs: the step text + write-set; `git diff -- <write-set>` + `git status --porcelain -- <write-set>`; the builder's VERBATIM test output. NEVER pass prior-cycle findings (anti-anchoring). Reply must end exactly `CHART_REVIEW: CLEAN` or `CHART_REVIEW: FINDINGS (n)`; anything else is malformed → rerun; blank ≠ clean. A CLEAN must list checks run: write-set compliance / reachability (name the public-API→glyph-branch call chain with a rendered snippet) / tests assert values not shapes / every golden diff hunk read as content and matching declared intent / nothing weakened-skipped-renamed in existing tests / conventions. Dirty paths outside the write-set are the other lane's or expected noise — NEVER findings.
3. **Loop cap** — 5 review cycles max; 3 fix-cycles on plan-text-caused findings → halt lane, `blocked` board row quoting the conflicting plan lines. Any finding needing edits outside the write-set or a public-default change → `blocked` with the question verbatim; other lane continues; halt only when everything remaining depends on it.
4. **Fixer** — fresh `coder` with bash, write-set-bounded. Open each cited file:line and CONFIRM reproducibility before fixing — the reviewer can be wrong; a prescription is a hypothesis. Per-finding disposition line: fixed (file:line) / not reproducible (evidence) / scope-blocked (file). Minimal edit, no adjacent fixes. Re-run the suite before believing any earlier green. Never edit/commit/propose plan edits.
5. **Orchestrator gate (before commit)** — orchestrator runs BOTH touched libs' suites itself; commit is gated on the orchestrator's numbers, not agents'. Figures always carry `lib@sha: T/A/Sk rc R`; redirect phpunit to a file and `echo $?` — never pipe (pipe exits are tail's). Judge by rc, never the banner. Append a `predict` worklog row (expected totals) BEFORE each commit; the commit row carries measured; unexplained count movement = finding.
6. **Commit agent** — dedicated `coder` with bash; only ONE at a time across lanes; only commit agents edit the status board. (a) Lane-scoped gate: `git status --porcelain -- <write-set>` shows the step's files; any other dirty TRACKED path must belong to the other lane's declared write-set (its file list is in this brief) or the expected-noise set {chart_plan.md, chart_prompt.md, chart_worklog.md, scripts/get_codacy_coverage.sh}. (b) FIRST commit of the run additionally stages the three chart_*.md docs (whichever lane lands first) with bullet "chore: fold chart run plan/prompt/worklog docs". (c) Never `git add -A`/`.`/`git commit -a`; add individual paths; NEW files need explicit `git add` (pathspec commits drop untracked); then `git diff --cached --stat` must equal the intended set. (d) Always `--author="Joe Huss <detain@interserver.net>"`. (e) Caliber: hook check `grep -q caliber .git/hooks/pre-commit` — hook currently ABSENT: run `caliber refresh`, stage its outputs if it changes anything; if caliber ERRORS, don't block — note `caliber-fail` in the ledger row, commit the write-set as-is, surface to the human at the end. (f) Message (heredoc): title `<slug>: S<n> <summary>`; body = WHY bullets / WHAT per-file / MEASURED (pasted suite lines) / REVIEW (n cycles, n findings, zero unresolved) / `## Test plan` counts / `Plan: chart_plan.md step S<n>`. (g) Hook modified files mid-commit → fix and create a NEW commit; NEVER `--amend`, never `--no-verify`. (h) Post-commit: re-run touched suites as confirmation; if red, the commit agent holds the exclusive privilege to `git revert --no-edit <sha>` + write a `blocked` row + re-enter the loop. (i) Then tick the board row + append the commit row (short SHA).
7. **Standing tail — paste into EVERY agent brief, every role:** never global `pkill`/`killall` (the sibling lane's suite is live); never `git checkout`/`reset`/`stash apply`/`clean` in the shared tree while any lane is in flight (orchestrator git is read-only in that window); absence-censuses use `/usr/bin/grep` (bare grep here = ugrep, honours .gitignore, lies); never claim "unpushed" without `git ls-remote origin master` vs HEAD; never edit chart_plan.md/chart_prompt.md except the orchestrator's designated amendment agents; REMOVAL IS NOT AN OUTCOME — dead code goes to the plan's Backlog, never delete/stub/comment-out mid-step; never run composer (vendor/ dirs exist; one missing → `blocked` row).

## Concurrency (max 2 simultaneous builds, file-disjoint)

- Lane A (sugar-charts): S2 → S3. Lane B (sugar-dash): S1 → S4 → S5 → S6 → S7 (S5/S6/S7 all own Donut.php → strictly serial).
- S1∥S2 may start together; S3∥S4 may run together. Reviewers are read-only and may overlap anything.
- If a fixer needs a file owned by the other lane's in-flight step: hold the fix until that lane commits (or re-sequence).

## Ledger discipline (R7)

- chart_worklog.md is append-only, written by the capable agents THEMSELVES: re-read immediately before appending, single `>>` redirect, never rewrite history rows.
- Reviewers are read-only: they RETURN their row text; the orchestrator passes it to the next spawned agent (fix or commit) to append verbatim before its own work.
- Board is a cache of ledger which is a cache of git. Missing rows → reconstruct from git log, mark `RECONSTRUCTED`. Never write a row before its evidence exists. Long-lived prose cites file+SYMBOL, not line numbers; ledger rows are dated and may age.

## Resume / crash (R8)

- On resume: `git log --oneline -20` reconcile FIRST. Any step interrupted between build and commit gets a FRESH review against the CURRENT tree — a pre-crash finding list is STALE POISON, never handed to a fixer. Review rows record the tree position (sha or status-hash).
- Killed-but-mid-edit agent: `git stash create` + `git update-ref refs/chart/S<n>-build <obj>` preserves the tree WITHOUT touching working state; delete the ref once the step commits.
- Blank agent return = the agent died, never = clean. Failure ladder: resume-same-session ×2 → read the tree & determine state → fresh agent with a CONTINUATION BRIEF listing existing changes "not reviewed, not trusted" → abandon-with-patch (save `git stash create` patch) after 5 infra-blanks / 3 substantive-wrongs. Retry counting is SEPARATE from review cycles; every relaunch gets a `retry` ledger row.

## Known caveats (R12)

Resolved ambiguities land in chart_plan.md "## Known caveats" — read it before relitigating anything.

## New mid-run findings (R10)

Append to chart_plan.md Backlog with `BL-<n>` ids + file:symbol + measurement. NEVER inject into an in-flight step.

## Stop conditions (R11)

Halt only when: the user says stop; a step needs a public-default change beyond S4's declared one; or blocked with no independent steps left. Otherwise run to completion — after each lane-relevant bundle (S1, S2+S3, S4, S5-S7) give the user ONE 3-line close report: landed shas, suite-floor delta, parked items. Report, not question.

## Resume state

- 2026-09-01: v2 protocol amendments applied from mining + adversarial reviews. Plan source-accuracy fixes (P1-P10) in place. Pre-flight (R13) still required before first spawn.
- Next actions: run R13 pre-flight → spawn S1 (lane B) + S2 (lane A).
