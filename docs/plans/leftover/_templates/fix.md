# Fix subagent — between-step substep

A review subagent surfaced findings under
`updates.md → ## Open review findings — <step ID>`. Your job is to
close every bullet there with a follow-up PR.

Read `_templates/subagent_brief.md` and `updates.md` first.

## Your task

1. Pick up every unchecked bullet under the review-findings heading.
2. Fix each in a focused commit on a new branch
   `ai/<original-slug>-review-fix`.
3. Run the touched lib's `vendor/bin/phpunit` to confirm tests pass.
4. Ship one PR with all fixes bundled (the items are by definition
   tightly related — they're all defects in the same step).
5. When the PR merges, **edit `updates.md`** to delete the entire
   `## Open review findings — <step ID>` section (heading and all).
   Append a line under "Done log":
   `fix for step <ID> · <PR#> · resolved N findings`.

## What NOT to do

- Do not add new features beyond what the findings list called out.
- Do not modify other steps' findings.
- Do not silently ignore a finding because it looks hard. If you
  cannot resolve one, leave it checked-off-as-deferred:
  ```markdown
  - [→] <finding>  — deferred to <new step ID added to Carry-forward>
  ```
  Then append the actual carry-forward entry under that section.

## Edge cases

- **No findings (clean review):** the review subagent should have
  skipped adding the heading. If it's empty, simply do nothing and
  report "no fixes needed" to the supervisor.
- **Findings conflict with the step's acceptance criteria:** that is
  a Blocker. Append to "Blockers" with a one-line description and
  stop.
- **Findings require an architectural decision:** Blocker, same as above.

## Process reminders (every subagent, every time)

- `unset GITHUB_TOKEN` **before every** `gh` invocation. Always. No
  exceptions.
- The full cycle ends on `master`: branch → commit → push →
  `unset GITHUB_TOKEN && gh pr create` →
  `unset GITHUB_TOKEN && gh pr merge <N> --merge --delete-branch` →
  `git checkout master && git pull --ff-only`. Confirm `git status`
  shows clean working tree on master before stopping.
