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

- **step 01.06** (slim-deprecated-facades): The step file's approach (`extends Posix\PosixFoo`) is architecturally impossible — all Posix classes are `final`: `PosixMasterPty`, `PosixSlavePty`, `PosixChild`, `PosixPtySystem`, `PosixPtyPair`, `PosixProcess`, `PosixTermios`, `PosixPump`. Cannot extend final classes. Additionally: `Spawn::proc()` is the lower-level primitive that `PosixSlavePty::spawn()` calls (circular), and `Child` is the parent class that `PosixChild` extends (backwards delegation). Attempted Master+PosixMasterPty and Pty+PosixMasterPty delegation — both fail at PHPUnit load time with "cannot extend final class". Needs supervisor decision: (a) unfinal the Posix classes, (b) use composition instead of inheritance, or (c) accept the facades as-is since Child(43 LOC) and Master(24 LOC) are already minimal.

---

## Carry-forward

(Items discovered during a step that should be tackled later — usually
in a follow-up step or a deferred phase. Each entry: one short line +
the step that surfaced it.)

- sugar-bits, sugar-charts, sugar-dash, candy-sprinkles, candy-vt need path-repo entries for local sugarcraft/* deps to work without GitHub network access (step 01.02)

---

---

## Cross-phase observations

(Patterns or surprises that span phases — e.g. "every i18n step needs
to add a path-repo entry for sugar-wishlist". Put one-liners here so
later steps don't rediscover.)

- Posix\* classes are `final` per project convention — any plan that says "extend Posix\Foo" must use composition instead. Reviewed during step 01.06.

---

## Done log

(One line per completed real step. Helps the supervisor and any
late-joining session see what already shipped.)

step 01.01 · PR#490 · plans: add x-windows.md stub plan + MATCHUPS.md TODO
review for step 01.01 · clean · PR#490
step 01.02 · PR#491 · PARTIAL — add .gitignore + @dev→dev-master + CI cache keys; composer.lock deletion NOT executed (see open findings)
fix for step 01.02 · PR#492 · resolved 3 findings
step 01.03 · PR#493 · candy-pty: split onIdle from onSigwinch; de-TODO recorder-tap comment
fix for step 01.03 · PR#494 · resolved 3 findings
tests-ci for step 01.03 · clean
step 01.04 · PR#496 · candy-pty: add PumpOptions::sshDefault() named constructor
review for step 01.04 · clean · PR#496
docs for step 01.04 · PR#497 · document PumpOptions::sshDefault() in README + docs/lib/candy-pty.html
step 01.05 · PR#498 · candy-core: drop stty shell-outs from PosixBackend
review for step 01.05 · clean · PR#498
step 01.06 · PR#499 · candy-pty: slim Pty facade via composition (Spawn/Child/Master left at minimal sizes; original step prescription was structurally impossible)
