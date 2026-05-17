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

## Open review findings — <step ID>

(The review subagent writes findings here. The fix subagent clears each
item by checking it off as it lands. When the section is empty after a
fix pass, the fix subagent removes the heading entirely.)

_none yet_

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
step 01.02 · PR#491 · drop-consumer-locks: delete 46 composer.lock files + add .gitignore + fix @dev→dev-master + update CI cache keys
