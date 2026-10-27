# C-AGT-1.a — Bind persisted schedules to a project root — DONE

Commits: 549ffc8f6 + 881b635dc on cc/C-AGT-1.a (base cb65b377a), cumulative +580/−67 over 2 commits. Author+committer detain@interserver.net verified by separate %ae/%ce greps; zero Co-Authored trailers; never pushed.

## What landed
- `Schedule`: new readonly `?string $root` (ctor param appended LAST — no positional caller shifts); realpath'd at construction; a spelled-but-missing directory is REFUSED by name; `fromArray`/`toArray` carry it (eight-key wire row, `"root": null` written for unbound rows for round-trip fidelity).
- `ScheduleRegistry`: ctor + `loadDefault(?string $hostRoot)` + `atPath($path, ?string $hostRoot)` gain the host binding (normalized via realpath; unresolvable host root collapses to null = fires nothing, fail-closed). `fireDue()` decides binding FIRST in the loop body — a row whose root ≠ host root (or a host with no root) yields the new `skipped-foreign` action BEFORE the busy branch and BEFORE any lock claim: no clock move, no lock (pinned structurally). In-claim re-check widened to catch a peer re-binding the row mid-claim (rides `skipped-stale`). `load()` announces every rootless persisted row: `schedule 'X' is not bound to a project; add "root" to the row — it will not fire.` (raw inline English, deliberately NOT a Lang key: src/Background is outside the ChatHostLangCatalogue source set, so a key there would be census-reported unused).
- `ScheduleSpawner`: `$row->root ?? $root` — a bound row spawns in its own project; the host root is only the /loop fallback.
- `SchedulesCommand::listing`: rows now read `… next {next} @ {root}: …`; unbound rows print `@ unbound` (new key `host.schedules.unbound`; `host.schedules.none` rewritten to teach `root`).
- `Bootstrap::workspace()` [bootstrap:workspace region only]: the single `ScheduleRegistry::loadDefault()` service line passes `$root` — Chat and BackgroundEvents need no edit; ephemeral /loop rows are unaffected (Chat's pump iterates `due()` directly and skips persisted rows before fireDue; the gate sits after the `!$row->persisted` continue).
- `docs/SCHEDULES.md` (NEW first page: file format, root binding, permission floor, lock semantics, /schedules verbs, stopped-firing checklist) + `docs/README.md` index row.

## Guards moved (same commit)
- `DocFigureProseDriftTest` docs page-census 24→25 (the only guard the new page trips); page written to satisfy every arm (no `File.php:NNN`, no "at line N", no env-var names, no eight/8-char figures).
- Verified un-tripped, re-run green: EnvRosterDrift, GlobFigureDrift, ReadmeRosterDrift, ReadPathCensus (realpath is not a sink), ProjectTierRefusalInventory (same read site), ChatHostLangCatalogue (placeholder-exactness + key-usage arms), BootstrapLaunchFormatConstants, TreeWideGuardRoster, StderrEmitterCensus, DenialPrefixRoster.

## Tests (all MODIFICATIONS — zero new *Test.php, so no durations rows owed; CORRECTION round 2: the live enumeration is NOT unchanged — the lane adds 8 tests (live 25,545 vs pinned 25,537: +7 methods +1 dataset row), so the ReadmeSuiteFigureDrift staleness pair is expected RED on this branch, and the README/suite-figure.json re-pin is weld/gate duty, not this lane's)
- ScheduleTest 24T/68A; ScheduleRegistryTest 18T/71A; ScheduleCrossHostFireTest 8T/30A (incl. new real duel: /b host skipped-foreign, never claims `row1.lock`; /a fires once); BackgroundEventsSchedulesTest + ScheduleLoopFireTest + LoopSchedulesCommandTest 18T/87A combined; broad `--filter Schedule` sweep 101T green; guard families 91T (DocFigure) + 292T/64,370A (census set) green. The 2 "PHPUnit Deprecations" are foreign AccessorDeprecationTest provider-visibility noise, present at base.
- RED-BEFORE-GREEN-AFTER proved: with src/ changes stashed, the 7 new tests give 3 Errors + 3 Failures + 2 Warnings (all 7 non-green); with changes restored, 7/7 OK (27 assertions).
- Fixture churn (prescribed by the step): every persisted-row fire fixture now passes the host root AND `root:` on rows; rootless-note counts preserved in the refusal/coercion census tests by binding their good rows; the real-process child script gained `$argv[3]` (host root).

## Deviations (disclosed)
1. `toArray()` writes `"root": null` for unbound rows (omission vs null: chose round-trip fidelity).
2. Extra strictness: binding re-checked inside the row claim (`skipped-stale` widened) — closes the peer-rebind race, mirrors the file's own "due-ness decided twice" design.
3. A missing root DIRECTORY is refused by name at parse (row skipped) rather than degraded to unbound — two distinct loud outcomes, both pinned, both documented.
4. No `withRoot()` wither minted (mutate() carries root forward automatically; a pinned withers-carry test proves it).
5. SEAM (not my file): `candy-code/README.md`'s docs-page table has no SCHEDULES.md row yet — no guard covers that table (verified); welder/closeout may want the row.
