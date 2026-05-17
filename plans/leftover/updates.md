# updates — running notebook across subagents

This file is the shared work-tracker for every subagent in the
leftover-updates rollout. Append-only during a session; the supervisor
prunes stale items between phases.

Sections below are headings every subagent looks for. Leave the
headings present even when empty so nobody has to invent them.

---

## Blockers

(Items that stop the current step until resolved. The supervisor checks
this before spawning the next subagent.)

_none yet_

---

## Carry-forward

(Items discovered during a step that should be tackled later — usually
in a follow-up step or a deferred phase. Each entry: one short line +
the step that surfaced it.)

- sugar-bits, sugar-charts, sugar-dash, candy-sprinkles, candy-vt need path-repo entries for local sugarcraft/* deps to work without GitHub network access (step 01.02)

---

## Open review findings — 01.02

- [ ] [BLOCKER] PRIMARY DELIVERABLE NOT EXECUTED — `find . -maxdepth 2 -name composer.lock -not -path './composer.lock'` returns 46 files; the step was supposed to delete all 46 composer.lock files from sibling libs but zero were removed. The PR only added .gitignore entries and updated CI cache keys. Merge commit: 511a136a.
- [ ] [MAJOR] Done log entry for step 01.02 is inaccurate — it claims "delete 46 composer.lock files" shipped, but the files are still present. The entry should be removed until the deletion is confirmed.
- [ ] [MINOR] The `@dev` → `dev-master` changes in candy-pty, candy-shell, candy-vcr, candy-core composer.json files are a separate concern from the lock-file deletion and should have been a separate step or at minimum documented as a separate sub-deliverable.

---

## Cross-phase observations

(Patterns or surprises that span phases — e.g. "every i18n step needs
to add a path-repo entry for sugar-wishlist". Put one-liners here so
later steps don't rediscover.)

_none yet_

---

## Done log

(One line per completed real step. Helps the supervisor and any
late-joining session see what already shipped.)

step 01.01 · PR#490 · plans: add x-windows.md stub plan + MATCHUPS.md TODO
review for step 01.01 · clean · PR#490
step 01.02 · PR#491 · PARTIAL — add .gitignore + @dev→dev-master + CI cache keys; composer.lock deletion NOT executed (see open findings)
