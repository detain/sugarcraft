# Supervisor reconciliations — wave 1 (E1-E246)

Cross-batch conflicts the agents could not see, resolved by the supervisor. Each changes a
disposition away from what the owning batch wrote, with the reason.

| id | batch wrote | supervisor sets | why |
|---|---|---|---|
| E218 | NO-FIX | SUPERSEDED | batch08 called it "duplicates E204"; batch07 stamped E204 itself OPEN. A duplicate of an open item is superseded-BY-E204, not "nothing to schedule" — NO-FIX would delete the work under both ids. |
| E172 | PARTIAL | PARTIAL (kept) | batch07's E197 says E172's own Step reads "Supersede E172 rather than schedule it". But batch06 MEASURED the drain half still open at CommandLoader.php:154/:276. A superseding instruction written before the measurement does not outrank the measurement. Kept PARTIAL; E197 stays NO-FIX and its note carries the disagreement. |
| E125 | CLOSED | PARTIAL | batch04 closed it on the three copies the entry NAMES. batch05 independently found a fourth splitter at ChatConfigChangeDoorsDocumentationDriftTest.php:386 and stamped its own E144 OPEN on exactly that file. Closing E125 while E144 is open on the same defect is incoherent. |

## Structural defects found in the backlog FILE itself (wave 2)

- **Headings are not in numeric order.** `E333` sits physically between `E303` and `E304`; `E364`
  sits between `E332` and `E334`. Harmless to a positional reader, fatal to anyone who assumes a
  numeric scan is a complete scan.
- Previously known and confirmed: `E57`-`E61` and `E70`-`E72` are referenced in the body with **no
  heading** (8 ids, not the 3 §0-NOW-61 recorded); `E78` carries two extra "round-42 follow-up"
  sub-entries under the same id, stamped here as `E78`/`E78b`/`E78c`.
- Total: **645 headings, 643 distinct ids, 651 ids referenced.**

## Cross-batch items pending (wave 2)

| id | raised by | action |
|---|---|---|
| E396 | batch15's E429 | E429 is a refutation of E396's prescription, and E429's fix shipped. E396 must not be stamped on its own terms — check batch13's verdict and set SUPERSEDED if it was judged independently. |
| E324 | batch10's E293 | E293's residual concern was re-homed as E324 (batch11 range). Verify batch11 did not also stamp E324 CLOSED on E293's strength. |
| E448/E449 | batch15 | coupled: if the scope ruling is "executable", E448's promotion of ChildLifetimeScanner into candy-testing is the cheaper route for both. Schedule together. |

## Forward-reference agreement checks (wave 2 -> wave 3)

batch16 judged four entries by leaning on later entries that wave 3 will judge INDEPENDENTLY.
After wave 3 lands, confirm the pairs agree; a disagreement is a real finding, not noise.

| wave-2 verdict | rests on | wave-3 must independently support |
|---|---|---|
| E457 SUPERSEDED | E516 re-measured E457's headline as FALSE (`grep -qv` answers no only when the pattern is present) | E516 |
| E482 SUPERSEDED | E511 says E482's premise is inverted (TeamTest is the detector, not the offender) | E511 |
| E475 OPEN | E503 re-measured and said NOT FIXED | E503 |
| E481 PARTIAL | E514 records the public-test-method half landing | E514 |

⚠️ E482's own STEP (make the footprint diff subtractive) was NOT taken — `tests/Agents/TeamTest.php:76`
still assertSame's the whole real-home listing. SUPERSEDED here means "premise inverted", not "no
residual". If cross-lane flake recurs, re-derive rather than reinstate.

## Scheduling unlocks found during triage

- **E400's widening is unblocked.** It deferred widening `presentLibraries()` past `*/src` because of
  a merge hazard in E404; E404 is now CLOSED. `bin/`, `tools/`, `scripts/` remain unguarded today.
- **E424 is buildable today.** Its stated precondition (`src/Support/ProcessReaper.php` exists) is met.
- **E407 is the consumer of that same file** and neither ladder was migrated — schedule with E424.

## Wave 2 reconciliations applied

| id | batch wrote | supervisor sets | why |
|---|---|---|---|
| E396 | CLOSED | SUPERSEDED | batch13 stamped CLOSED but its own note says the entry's central claim is REFUTED — sites 2 and 4 closed with NO constructor change, via `PosixBackend::descriptorForStream()`. batch15's E429 is the refutation entry and its fix shipped. Two independent batches agreed without seeing each other; SUPERSEDED is the honest stamp, CLOSED would imply the Step was right. |

## Entries that must be scheduled TOGETHER (found across wave 2)

- **E342 + E358** — the same eight inverted `O_NONBLOCK` failure messages (`ForkedChildTest.php:203,230,292,315`, `ChatTest.php:873,894`, `EngineBackendTest.php:402,419`) plus the stale justification at `EngineBackendTest.php:379`. One repair closes both. **E319 (batch11) names three of the same files** — check it is the same defect before scheduling three entries for one fix.
- **E324 alone, not with E329** — E329's Step says schedule both; E329's own four sites are done, so E324 is now the whole of it.
- **E407 + E424** — both consume `src/Support/ProcessReaper.php`, which now exists.
- **E448 + E449** — coupled on the scope ruling.

## Round-61 brief corrections (from batch22, the newest entries)

- **E641's scope is 7 sites, not 1.** RESUME §6 lane b corrected in place.
- **E651 is not trivial.** Its citation is FALSE, not approximate (real calls `:116`/`:270`). RESUME corrected.
- **E640 is conditionally NO-FIX.** Only "nothing to schedule" while `Bash(git *)` is the sole
  argument-scoped declaration in the built-in set. A second one makes it schedulable — it wants a
  marker, not a close.
- **E647: do not read `ProcessExecutorTest.php:911` as a close.** That test pins the FRAME only; the
  "worker runs with no tools" defect is untouched at `ProcessExecutor.php:985`.
- All four ranked fail-opens (E639/E643/E644/E645) INDEPENDENTLY CONFIRMED OPEN. Ranking stands.

## Wave 3 findings that touch round 61

- **E575 belongs in lane a.** Its unreachable-branch note names only `AgentPresetRegistry`, but
  `src/Agents/ForeignAgentPresetRegistry.php:479` carries the identical `"Invalid YAML frontmatter
  in:"` branch and the entry never mentions it. Lane a already owns that file for E645 — bundle it.
  Also: the existing note at `AgentPresetRegistryTest.php:392-404` identifies WHICH branch is
  unreachable but never names an input that reaches it, so it does NOT satisfy the entry's second
  remedy. Do not read it as half-done.

## Duplicate entries to collapse, not schedule twice

- **E569 == E576** — the same paragraph, closed by the same edit (batch19).
- **E204 == E218** — already reconciled (E218 -> SUPERSEDED).
- **E342 == E358**, and **E319** overlaps three of the same files.

## Two backlog figures re-measured during triage and found stale

- **E630** — recorded `candy-pty/composer.lock` at 113 KB / 2026-08-22. MEASURED 2026-09-10:
  **115,257 B, dated 2026-09-10 02:08** (this session's refresh). Still gitignored. Recorded in
  RESUME section 3 item 4.
- **E632** — reasons about **297** files under `sugar-crush/src`. MEASURED: **317**. All four `278`
  sites remain at `BuiltInToolCorpus.php:40,41,135,509,514`. Round 61 lane c owns this file — the
  brief must carry 317, not 297, or the lane re-derives a stale number.

## Base moved mid-triage — stated, not smoothed

The sweep was briefed at `3bf356bd6`. The user committed `170bd49be` ("update") during it. A triage
agent caught the mismatch and reported it rather than proceeding silently — the supervisor had not
noticed. MEASURED: the diff is **root `composer.lock` only** (11+/11-), nothing under `src/` or
`tests/`, so no verdict is affected. Recorded in RESUME §0-NOW-62's header.

## Additional cross-batch merges found in wave 3

- **E488 == E515** — the same fix (`IDLE_DEFERRAL` in `tools/check-path-repos.php:602-670`). Merge the ids.
- **E490 -> track at E623**, not here. Ownership moved to E550/E593/E623, which ran the campaign;
  E623 explicitly DECLINES to retire E490. STEP 3 landed as `candy-pty/tests/Support/HangWatchdog.php`.
- **E496** — an entry whose explicit instruction was OVERRIDDEN: it said deleting the source-text
  guard was "not a licence" (rule 6) and the scan WAS deleted. The replacement doc-block gives a
  measured reason, so it reads deliberate — but it is on the record as an override, not a close.
- **E508** — its own premise was measured FALSE (payload size irrelevant: 10 MiB to a live reader
  = 0.028s; 65,629 B to a deaf one = 15.006s). The 15s bound is still unthreaded, but E508's stated
  reason for wanting it is gone.
