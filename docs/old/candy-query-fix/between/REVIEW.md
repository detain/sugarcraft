# BETWEEN-STEP — REVIEW (read-only)

> First read `plans/candy-query-fix/COMMON.md`. **Agent:** `oac:code-reviewer`.
> The supervisor told you which real step just completed — review THAT step's diff and area.

This is a **read-only** review. No branch, no commit, no PR. Your output is a findings
list written into `plans/candy-query-fix/updates.md` for the FIX step to consume.

## What to review

1. **Correctness vs the step's acceptance criteria.** Open the step's instruction file
   (the supervisor named it) and check each acceptance criterion is actually met by the
   merged diff (`git log -1 --stat`, `git show`).
2. **Conventions** (COMMON.md §3): immutability (`mutate()`, no `$this->x =`), `final`,
   `::new()`, strict types, bare accessors, upstream-citing docblocks.
3. **SQL safety:** parameterized values; identifier escaping; no `eval` on server data;
   no password leakage. Pay attention to any new queries.
4. **TUI render invariants:** no over-wide lines, constant frame line count, no sync DB
   query on the keystroke path, no raw `\x1b` outside `CellValue.php`.
5. **Dead/unwired code:** did the step actually WIRE what it built (the recurring
   candy-query failure mode — a class with tests but no caller)? Trace the call path from
   `App`/key-routing to the new code.
6. **Regressions / scope creep:** anything touched that shouldn't have been.

## Output

For each finding write to `updates.md`:
```
NOTE (review of STEP <id>): <severity CRIT/HIGH/MED/LOW> — <file:line> — <issue> — <suggested fix>
```
If a finding is severe enough to block the next real step, prefix `BLOCKER:` instead.
If you find nothing actionable, write a single `NOTE (review of STEP <id>): clean — no findings`.

Do NOT fix anything yourself. Do NOT ship. Leave the tree on `master`, untouched.

> Reminder: `unset GITHUB_TOKEN` before any `gh` (you shouldn't need `gh` for a read-only review).
