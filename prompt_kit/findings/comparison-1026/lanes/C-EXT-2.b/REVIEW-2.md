VERDICT: FINDINGS — CRITICAL x1, MAJOR x2 (round-2 loop broken upstream; the round-1 tree itself is healthy)

Lane: C-EXT-2.b · Worktree: /home/sites/cc-cmp-C-EXT-2.b · Branch: cc/C-EXT-2.b · Base: ebf05989e
Reviewer: cc-cmp-step-reviewer, round 2 · Date: 2026-10-27
Under review: commit 417385a64 "C-EXT-2.b: review fixes (round 2)" per orchestrator brief.

## Headline

The fix commit under review does not exist. `git -C /home/sites/cc-cmp-C-EXT-2.b cat-file -t 417385a64`
and the same from /home/sites/sugarcraft both fail ("unknown revision" / "Not a valid object name").
All worktrees share one object DB, and `git log --all --oneline | grep -i 'round 2'` lists 20+ other
lanes' "review fixes (round 2)" commits (incl. sibling C-EXT-2.a at 39e523d36) — none for C-EXT-2.b.
The branch tip is still the single round-1 commit 8ef5643d8 off base ebf05989e; the worktree reflog
(.git/worktrees/cc-cmp-C-EXT-2.b/logs/HEAD) records only create@ebf05989e, two `reset: moving to HEAD`,
and the one `commit:` of 8ef5643d8 (epoch 1793147609) — no amend, no second commit.
`git status --porcelain` in the worktree is EMPTY: there is not even uncommitted round-2 work.
Main tree `candy-code/src/Skills/ActiveSkillEffects.php` is likewise unmodified.

## Findings

[CRITICAL] Fix commit 417385a64 is a phantom — fabricated success path at the loop level.
Nothing to scope-verify: neither the ActiveSkillEffects.php docblock hunks nor the REPORT.md
Round-2 section exist anywhere on disk. REPORT.md (main-tree lane dir, mtime 20:35) has ZERO
matches for round-2 / 417385 / per-root. Fix: rule round-2 a NO-OP, do not weld any round-2
claim for this lane, and re-dispatch only after the r1 finding itself is re-adjudicated (below).

[MAJOR] The r1 MINOR being "fixed" does not describe this lane's code.
candy-code/src/Skills/ActiveSkillEffects.php (257 lines, `final readonly class`, methods
derive(:90)/apply(:152)/none(:173)/isEmpty(:178)/turnMessages(:193)/resolve(:213)/rulesFrom(:236))
contains NO capture(), NO maxBytes, NO byte-budget logic — grep count 0 for maxBytes|capture(|budget.
The cited :129 "per-root maxBytes budget" sentence and :114-116 "first skills win" region match
nothing. Across the whole lane diff the only budget/maxBytes tokens are docs/SKILLS.md prose: a
PRE-EXISTING context line about SkillPathNudge's maxOutputBytes/Read 1.375x (not touched by this
lane) and the ADDED `effort` row whose "thinking budget" is reasoning-effort vocabulary, unrelated
to a read budget. maxBytes lives only in untouched files SkillPromptLine.php / SkillPathNudge.php.
The described defect-shape (shared read budget across roots) belongs to SkillPathNudge/registry
body-reading territory this lane never edited. Fix: re-attribute the r1 MINOR to its true lane or
void it; the brief's "fix 417385a64" cannot be honored here.

[MAJOR] Evidence integrity: r1's record for this lane is missing and its axes do not describe this tree.
prompt_kit/findings/comparison-1026/lanes/C-EXT-2.b/ contains ONLY BRIEF.md + REPORT.md —
REVIEW-1.md is absent on disk (sibling C-EXT-2.a/ has both REVIEW-1.md and REVIEW-2.md).
The brief's r1 axes "15 files +687/-26" contradict the live tree: `git diff --stat ebf05989e...HEAD`
= 22 files, +1383/-67 (exactly the 22 paths the builder's REPORT.md declares). Whatever r1 reviewed,
it was not this tip. Fix: recover or re-run r1 against 8ef5643d8 before round-2 disposition.

## Axes that PASS (round-1 tree, unchanged)

- Scope: no serialized-region straying — suite-figure.json, parallel-tests-durations.tsv, and
  candy-code-web/dist are absent from the diff; the three DUR-listed new test files
  (ActiveSkillEffectsTest.php, PermissionGateTurnGrantsTest.php, RuntimeSkillEffectsTest.php)
  all exist in 8ef5643d8, matching the builder's rows.
- §1.9 literals: no glob-shaped string literals in the three new test files or the new src file;
  no '**Refused:**'-shaped denial literals; GlobDialectDifferential green inside the five-guard.
- Author law on 8ef5643d8: %ae = detain@interserver.net, %ce = detain@interserver.net (separate
  greps), %an = Joe Huss, `git log ebf05989e..HEAD --format=%B | grep -ci Co-Authored` = 0.
  Single commit off base; worktree porcelain clean. (Master moved externally during review —
  C-CTX-2.e welds — per program law, not expected of this lane.)

## Gates (plain pipe, cwd=/home/sites/cc-cmp-C-EXT-2.b/candy-code, env -u LINES -u COLUMNS)

- `--filter 'Skill|Gate'`: OK — Tests: 2192, Assertions: 11391 (2 pre-existing PHPUnit
  deprecations), exit 0.
- `tests/Skills`: OK (580 tests, 2185 assertions) — byte-exact to the builder's REPORT figure.
- Five-guard `--filter 'ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift'`:
  Tests: 133, Assertions: 15781, Failures: 2 — EXACTLY the staleness pair (pinned 25,537 vs live
  26,238 in ReadmeSuiteFigureDriftTest :93 and :120). No guard accuses the lane's prose. The +701
  live drift is the weld-era figure re-pin duty, as r1-class predictions stated (~26,2xx confirmed).

## Budget-pin vacuity answer (per brief item)

"If the shared sentence were false, which pin reddens?" — None. No such docblock sentence, no
capture() method, and no budget-field test exists in this lane's diff; the red-before/green-after
claim for the fix is vacuous because the fix, the code it describes, and any pin of it are all
absent from cc/C-EXT-2.b. (The lane's genuine budget-adjacent surface — docs/SKILLS.md effort row —
is pinned by DocFigureProseDrift, which is green.)

## Disposition

Round 2 CANNOT close clean: the artifact under review is nonexistent (CRITICAL). The round-1
commit 8ef5643d8 itself re-verifies green on every checked axis (scope, literals, author law,
gates), so if the orchestrator's r1 MINOR is re-voided as misattributed, the lane needs no code
change at all — only an honest REVIEW-1 record and, if the staleness pair is the sole remaining
red, the closeout re-pin. Recommended: void the r1 MINOR for this lane (with the grep evidence
above), recover/re-run r1, and re-dispatch round 2 only if a real defect survives that review.
