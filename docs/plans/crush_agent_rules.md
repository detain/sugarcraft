# Rules handed to every agent working the crush_code plan

**Durable copy.** Workflows reference this file by path; the original lived in a session-scoped tmp
directory that does not survive a compact or a client restart. If a workflow script points at a
`/tmp/claude-*` path, repoint it here: `docs/plans/crush_agent_rules.md`.


Repo: /home/sites/sugarcraft (monorepo root). Lib: /home/sites/sugarcraft/sugar-crush.
Bash CWD does NOT persist between calls — use absolute paths or chain with &&.

## Forbidden
- NO git stash/checkout/reset/clean/commit/rm. You do NOT commit; the supervisor does.
- NO `composer install`/`update` — it replaces vendor/sugarcraft/* symlinks with Packagist copies.
  The only visible signal is the skipped count going 1 -> 2, and then every figure you report is void.
- NO global pkill (even the [p]hpunit bracket trick — it kills sibling agents). Kill only PIDs you started.
- NO `caliber` anything.
- Do not edit: sugar-crush/phpunit.xml, /home/sites/sugarcraft/.sugar-crush/config.json,
  docs/plans/plans_cleaning.md, sugar-crush/python_port/.
- Never `-c core.hooksPath=/dev/null`, never `--no-verify`.
- Never delete dormant/unwired code because it looks incomplete. Wire it or document it as a seam.

## DO NOT READ THE TWO BIG FILES IN FULL — AND DO NOT TRUST A SIZE YOU WERE QUOTED

`src/Cli/Bootstrap.php` and `src/Chat.php` are both far too large to read whole. `grep -n` for the
symbol, then `sed -n 'A,Bp'` a narrow window. Same for any file over ~800 lines.

**Measure the size rather than believing a brief.** A brief in round 30 quoted Chat.php at "~6,100
lines" when it was **10,381** (504,205 B) and Bootstrap.php at "212 KB" when it was 223,382 B /
4,253 lines. The rule holds at any size, which is exactly why the number should never have been in
it — a hardcoded size is a claim that decays while the instruction it decorates does not. `wc -l`
costs nothing.

## EVERY FIGURE IN YOUR BRIEF IS PROVISIONAL — MEASURE, THEN SAY SO

Any suite baseline, commit SHA or behind-count written into your brief was true when the brief
was written and may not be true when you read it. Master moves while you work: the supervisor
commits, and **CI pushes regenerated demo GIFs on its own schedule**. Across one round, three
separate agents were each handed a stale baseline and each had to correct it — one was told
`574aca95`/3 commits, measured 5, and a later agent in the same round measured 7.

So:

1. **`git fetch origin master` first, then measure.** Report the SHA you actually observed.
2. **Never quote a brief's figure as your own measurement.** If they disagree, say both and name
   which one you measured. That disagreement is a finding worth reporting, not noise.
3. **If your tree is dirty you cannot rebase** — `git pull --rebase` refuses it, and `stash` /
   `commit` / `checkout` are forbidden to you. That is expected mid-implementation. State your
   baseline as the lane's own HEAD, verify that the commits you are behind do not touch your file
   set, and compute what your figure becomes after the rebase rather than pretending you rebased.
4. A count you did not measure yourself is exactly the defect this plan tracks. **A baseline is a
   claim, and it decays.**

## Running tests — KEEP CONTEXT SMALL
- While iterating, run ONE test FILE: `cd /home/sites/sugarcraft/sugar-crush && vendor/bin/phpunit tests/X/YTest.php`
  (~0.05-6s). Never run `tests/Cli` as a DIRECTORY — it hangs >4min.
- Run the FULL suite at most TWICE (once to see where you are, once at the end):
  `cd /home/sites/sugarcraft/sugar-crush && vendor/bin/phpunit > /tmp/o.txt 2>&1; echo "rc=$?"; tail -5 /tmp/o.txt`
  REDIRECT, never pipe — `phpunit | tail` reports tail's exit code. Judge by rc, never the banner.
  Needs a 600000 ms timeout (~3 min).
- Skipped MUST be exactly 1 (a known McpClient skip). 2 means the symlinks broke; say so loudly.
- Never paste more than ~15 lines of test output into your report. Summarise.
- tests/SystemPromptWiringTest::testARealChatKeystrokeTurnDeliversBothHalves is a KNOWN pre-existing
  timing flake. Don't skip it, don't weaken it, don't report it as your finding.

BASELINE at commit 6c1e51c8: sugar-crush 7618 tests, 89206 assertions, 1 skipped, rc 0.

## Conventions
declare(strict_types=1); first line. PSR-12, PHP 8.3+. final unless extension is the contract.
Immutable+fluent: with*() returns a new instance via mutate(). Bare accessors (no get prefix).
::new() not ::create()/::make(). Comment WHY not WHAT. php-cs-fixer is NOT installed — don't reformat.

**If you state a number or a claim in a comment or a report, state the DOMAIN it holds over.** This
project's most common defect, 28 rounds running, is a figure measured on path A written as a property
of path B.

Adding a src/*.php file moves FIVE censuses: BuiltInToolCorpusTest (file count, declaration count,
symbol-kind counts), BinSugarcrushWiringTest::crushSourceFiles,
ContainedPathInventoryTest::ROUTED_CALL_SITES, ReadPathCensusTest::READ_PATHS.

## Mutations
- A NAME IS NOT A DEFINITION. Write every mutation as the exact edit: old text -> new text, both
  quoted, applicable mechanically. "MU3" tells the next agent nothing.
- Apply one at a time, `php -l`, run a TARGETED test file (not the full suite), restore from a backup
  verified with md5sum, then the next.
- Ask what a new safety net makes VACUOUS. A clamp or fallback added by a fix can make older
  assertions prove nothing — that happened here and cost two rounds.
- A test pinning the PRESENCE of a clause rather than its TRUTH is worthless.

## Reporting
Your final text IS the return value — it goes to another agent, not a human. No preamble.
Be quantitative and BRIEF (aim under 800 words). Always include:
1. What you changed/found, file by file.
2. Full-suite figures as tests/assertions/skipped/rc with the baseline beside them.
3. Every mutation as a verbatim old -> new edit, with KILLED/SURVIVED and the killing test.
4. What you did NOT do and why. Descoping is allowed; silent descoping is not.
5. Anything you measured that CONTRADICTS what you were told. This is the most valuable thing you can
   return — the supervisor's notes have been wrong four times this session and an agent caught each.
Never claim a test "would have caught" something you did not run.
