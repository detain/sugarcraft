# Startup prompt — paste into a fresh session (cwd: /home/sites/sugarcraft)

---

We're mid-way through making **candy-query stop hand-rolling TUI primitives and
adopt the SugarCraft foundation libs instead**, plus moving genuinely-reusable
bits up into those libs. Phase 1 (foundation + cleanups) is already done and
committed. You're doing **Phase 2: the candy-forms UI adoption**.

**Before doing anything, read these two files in full — they are the spec:**
1. `plans/CANDY_QUERY_UPSTREAM_PHASE2.md` ← the detailed execution plan (start here)
2. `plans/CANDY_QUERY_UPSTREAM.md` ← the original analysis (the "why")

**Working context:**
- Branch is already `ai/candy-query-upstream-extraction` (off `master`). Stay on it.
  `master` is the "before" for `git diff master`. Confirm with `git branch --show-current`.
- Baseline green: candy-core 637, candy-query 1091. sugar-dash has 38 PRE-EXISTING
  `GoldenSnapshotTest` failures on master — ignore them, don't "fix" unrelated goldens.
- candy-query tests are mostly **substring** assertions (not byte-exact goldens), so
  widget adoption is low-risk — but run the full lib suite after every step.

**How to work:**
- Execute the plan's steps **in order**, starting at **STEP 0** (add the `candy-forms`
  dependency + path-repo wiring via `php tools/check-path-repos.php --fix`).
- **One logical change per commit**, each with the touched lib's `vendor/bin/phpunit`
  green first. `vendor/` is gitignored and goes stale — run `composer update sugarcraft/*`
  in the lib before trusting a local failure. `php -l` touched files.
- Don't open PRs or push yet unless I ask — just commit to the branch so I can diff
  before/after. Author commits as `Joe Huss <detain@interserver.net>` and end messages with:
  `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`
- **Skip Caliber on this machine** — never run `caliber refresh`; if a hook stages
  Caliber-managed files, unstage them before committing.
- Preserve the **frame-diff invariants** when you get to the full-screen frame (B1):
  constant line count, no over-wide lines, no mid-frame `\x1b[2J`.

**Definition of done** is at the bottom of the phase-2 plan: the 3-pane browser + all 6
admin pages render via upstream widgets, no hand-rolled tabBar/list/scroll/card/badge/
separator/side-by-side, no raw `\x1b[…m` literals in page code, `BorderFrame`→`Kit\Frame`,
`ResultTable`/`ResultPager` retired, all suites green.

Start by reading the two plan files, confirm the branch and baseline test counts, then
do STEP 0 and report back before continuing.
