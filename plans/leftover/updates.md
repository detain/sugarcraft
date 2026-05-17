## Pre-step 01.07 health check findings

**2026-05-17 — Clean, no showstoppers found.** Details:

- **CI Status**: ✅ `php tools/check-path-repos.php` reports "closure clean" across all 46 libs. CI workflows (`.github/workflows/`) are structurally sound — no obvious issues.
- **Composer validation**: ✅ All key libs (`candy-core`, `candy-pty`, `sugar-bits`, `sugar-charts`, `candy-shell`) pass `composer validate --no-check-all`. Root warns only about the `version` field on the monorepo root — expected/benign.
- **Untracked files**: ✅ Only `.claude/scheduled_tasks.lock` — benign, matches task description.
- **MATCHUPS.md**: ✅ Properly formatted. No duplicate rows. The Python "duplicate Upstream" finding was a false alarm — "Upstream" appears in two table *header* rows (one per section: Charmbracelet libs + Apps), which is correct. 53 data rows, no inconsistencies.
- **PHP syntax**: ✅ Sampled 20 `src/*.php` files — all pass `php -l`. Also verified `PosixBackend.php`, `PosixPump.php`, `RealProcess.php`, `Process.php` individually.
- **Step 07 preconditions**: ⚠️ P3-LO-01 and CC-LO-08 are NOT YET in the done log — step 01.07 is the next logical step (matching `step-07-realprocess-deletion.md`). Pre-step grep was run during this health check; `RealProcess` has **active callers** in `candy-shell/src/Command/SpinCommand.php:71` and `candy-shell/tests/Process/RealProcessTest.php` — deletion is NOT safe. The step file's own logic handles this correctly (keep as deprecated alias if callers exist). No blocker — the step can proceed on its defined path.
- **leftover_updates.md**: ✅ Reviewed. No urgent items missed. Sequencing in that file (P5-LO-01 first, CC-LO-02 second, step 07 fifth) is consistent with current done-log state.
- **Symlinks**: ✅ All `composer.json` files in consumer libs resolve to real files — no broken symlinks. Verified `candy-pty`, `candy-shell`, `sugar-bits`, `sugar-charts`, `sugar-dash`, `candy-sprinkles`, `candy-vt`.
- **candy-shell dep on candy-pty**: ✅ Already satisfied — `candy-shell/composer.json` already has `sugarcraft/candy-pty: dev-master` in `require`.

---

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

- **step 01.06** (slim-deprecated-facades): ~~RESOLVED via PR#499~~ — hybrid approach: composition for Pty (138 LOC), Spawn/Child/Master left minimal per revised targets (≤324 total achievable). Original step prescription (`extends Posix\Foo`) was structurally impossible since all Posix classes are `final`.

---

## Carry-forward

(Items discovered during a step that should be tackled later — usually
in a follow-up step or a deferred phase. Each entry: one short line +
the step that surfaced it.)

- (none currently)

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
candy-core-gitignore · PR#500 · candy-core: add composer.lock to .gitignore (untracked 72KB lock file issue)
path-repo-5-libs · PR#501 · sugar-bits/sugar-charts/sugar-dash/candy-sprinkles/candy-vt: add path-repo entries for local sugarcraft/* deps (leftover 01.02)
step 01.07 · PR#502 · candy-shell: RealProcess kept as deprecated alias; Process interface aligned with candy-pty/Contract
review for step 01.07 · clean · PR#502
docs for step 01.07 · clean · PR#502
tests-ci for step 01.07 · PR#503 · add stdoutBytes/stderrBytes forwarding tests to FakeProcess
step 01.08 · PR#504 · candy-pty: add SUGARCRAFT_PTY_BACKEND env var for backend selection
fix for step 01.08 · PR#505 · add deferred-backend-exception CALIBER entry
docs for step 01.08 · PR#506 · document SUGARCRAFT_PTY_BACKEND in end-user/admin/dev docs
step 01.09 · PR#507 · candy-pty: PtyPool ReactPHP test + MultiPump example + Expect withRecorder
review for step 01.09 · clean · PR#507
docs for step 01.09 · clean
 step 01.10 · PR#508 · candy-vcr: RecordCommand polish — SIGINT rescue + env-allow-secrets + cassette doc + ShirleyHtopTest
 review for step 01.10 · clean · PR#508
 tests-ci for step 01.10 · PR#509 · add testFilteredHostEnvWithEmptyStringSkipsAllFiltering for --env-allow-secrets empty-regex path
 docs for step 01.10 · PR#510 · document --env-allow-secrets in end-user hub-admin dev docs + CALIBER entries
review for step 01.10 · clean · PR#508
tests-ci for step 01.10 · PR#509 · add testFilteredHostEnvWithEmptyStringSkipsAllFiltering for --env-allow-secrets empty-regex path

## Open review findings — 01.08

- [x] candy-pty/CALIBER_LEARNINGS.md: new UnsupportedPlatformException + forDeferredBackend() pattern not logged — needs [pattern:deferred-backend-exception] entry so phase-12 implementers know to remove the throw when wiring sidecar/pecl
