# BETWEEN-STEP — FIX

> First read `plans/candy-query-fix/COMMON.md`. **Agent:** `oac:coder-agent`.
> The supervisor named the real step that just completed; you are fixing the REVIEW findings for it.

## Do

1. Read `plans/candy-query-fix/updates.md`. Collect every `NOTE (review of STEP <id>): …`
   and `BLOCKER:` item that belongs to the step just reviewed.
2. Fix them, smallest-diff-first, honouring all COMMON.md conventions. If a finding is wrong
   or not worth fixing, leave a `NOTE:` explaining why instead of silently ignoring it.
3. If a finding needs information you don't have → `RESEARCH NEEDED:` in `updates.md` and STOP
   if it blocks you (the supervisor will get a researcher and re-spawn this FIX).
4. Verify per COMMON.md §4 (`composer update` then `vendor/bin/phpunit` green; `php -l`).
5. **Remove** the review-finding items you resolved from `updates.md` (leave only genuine
   `DEFERRED:`/`NOTE:` carry-forwards).

## Ship

Per COMMON.md §5: branch `ai/candy-query-fix-<stepid>`, commit (author Joe Huss
<detain@interserver.net>), push, `unset GITHUB_TOKEN && gh pr create`, `unset GITHUB_TOKEN &&
gh pr merge <n> --merge --delete-branch`, `git checkout master && git pull --ff-only`. End on master.

If REVIEW reported "clean — no findings": confirm there's nothing to do, remove that note,
and end on master with no PR (record `NOTE: FIX skipped for STEP <id> — review clean`).

> Reminder: `unset GITHUB_TOKEN` immediately before EVERY `gh` command.
