# STEP 8.1 — Final integration, smoke plan, reconciliation

> Read `plans/candy-query-fix/COMMON.md` first. **Agent:** `oac:coder-agent`.
> Audit refs: all of PART 1; `query_update.md` 14-issue table. **Final closeout.**

## Why
The remediation touched every admin subsystem. This step proves the whole thing hangs together,
documents the manual-smoke steps CI can't run (no live DB), and ensures nothing was left
half-resolved in `updates.md`.

## Goal
- Full suite green across every touched lib.
- A written end-to-end manual smoke plan (SQLite + scratch MySQL) committed to docs.
- `updates.md` reconciled: every `DEFERRED:`/`NOTE:`/`BLOCKER:` resolved or explicitly accepted.
- `query_update.md`'s 14-issue table re-checked and annotated closed/tracked.

## Files
- `candy-query/README.md` / a `docs/` smoke doc (the manual smoke plan).
- `query_update.md` (annotate the 14-issue table with final status).
- Any small integration glue surfaced by the cross-cutting review.
- Tests as needed for any final glue.

## Do
1. `cd /home/sites/sugarcraft/candy-query && composer update --quiet && vendor/bin/phpunit` —
   green. Run the suites of any other lib touched across the project (candy-async, sugar-dash,
   sugar-charts, candy-core, etc. as applicable).
2. Write a manual smoke plan: `php bin/candy-query <sqlite>` (browser regression) and `php
   bin/candy-query --driver=mysql …` reaching each admin page; verify kill/edit/commit/export
   round-trips against a scratch MySQL. Capture it in docs (it documents what fakes couldn't).
3. Walk `updates.md`: resolve or explicitly mark-accepted every open item; remove the rest.
4. Re-check the 14 issues in `query_update.md`; annotate each closed (with the step) or tracked
   (with a `DEFERRED:` reference).
5. Do a final cross-cutting read of the key path: `App` key-routing → each admin page `update()`
   → action/query → async cache → render. Confirm no page is still display-only and no sync DB
   query sits on the render path. Fix any small remaining glue; larger gaps → `DEFERRED:` for a
   follow-up session and report to the supervisor.

## Acceptance criteria
- [ ] Full suite green; touched sibling libs green.
- [ ] Manual smoke plan committed.
- [ ] `updates.md` has no unresolved `BLOCKER:`/`RESEARCH NEEDED:`; deferrals are explicit.
- [ ] `query_update.md` 14-issue table annotated.
- [ ] Every admin page is interactive end-to-end (no display-only pages; no sync DB on render).

## Out of scope / defer
- Genuinely new features beyond the audit → record as a fresh backlog in `updates.md`/README.
