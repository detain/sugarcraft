# C-AGT-5.a weld record

- Lane tip `3d1cf8626` (base cc14518d5, single commit) → pick `5cd68af7e`, patch-id `ba1a64d2dc5c` identical both sides, zero conflicts. Author/committer detain verified; zero trailers.
- Durations: `scripts:` commit `db4a5dbc8` adds `ScheduleToolTest 2.229 13` and `ScheduleFactoryTest 0.184 14` (single-file junit measures; tsv 1492→1494, set-equality gained exactly these 2, 4 pre-existing desc-violations unchanged). Closes r1 NIT-1 durations half; the suite-figure/README pair re-pin is closeout domain (welder forbidden from `tests/Config/Support/suite-figure.json` per §1.5).

## Gates on merged master (cwd=candy-code, plain pipe, -u LINES -u COLUMNS)

- `ScheduleToolTest|ScheduleFactoryTest` → **27T/103A, 0F** (roster exact).
- `Schedule|ScheduleFactory` → **154T/689A, exactly 1F** = `DreamPassTest::testChatSchedulesThePassAtTurnCloseAndReportsWhatChanged` (:288) — foreign name-collide, RE-PROVEN by reviewer r2: tests/Memory zero-diff vs base, zero lane-file coupling, fails isolated at tip (standing base-red family; report-only, not fixed here).

## Stale-brief erratum (disclosed, per r2 REVIEW-2)

The spawn expectations `125T/445A` and `254T/1186A` match neither r1 nor tip — stale brief figures, not a lane delta. True windows: 154/689 substring, 249/1115 family (r1's 153/682 + 248/1108 plus the r2 pin +1T/+7A).

## OPEN OWNER-RULING carried (MINOR-2, must not be silently inherited by C-AGT-5.b)

`ScheduleTool.php:224-235` delete guard is **createdBy-scoped on the machine-shared file, not root-scoped**: a project-A model (no background flag) can delete a model-tagged row bound to project B. BRIEF defines the boundary as the tag, so the lane is compliant as built; reviewer r1 recommends an owner ruling — scope by `$row->root`, OR spell the cross-root fact in the tool description + SCHEDULES.md:197-201 — **BEFORE C-AGT-5.b reuses the factory**. Ruling not taken at weld. (r2 cured MINOR-1 — factory create() now withdraws the row on persist failure, structurally verified on the pick blob; deleteRow untouched by design.)

## Precedent notes

- `tool.schedule.*` lang keys NOT minted — precedent HOLDS (NIT-2 adjudication): tool descriptions/results are model-read text, English in code (every sibling tool, cf. ShellStop); list output reuses `host.schedules.*` via SchedulesCommand::listing. en.php untouched by this lane.
- Docs rides: README + AGENTS_AUTHORING/ARCHITECTURE/PERMISSIONS/SCHEDULES/SETTINGS rosters updated in-step (guard-driven: ReadmeRosterDrift/ToolDocDrift surfaces green in reviewer windows); `gen-command-docs`/`gen-tool-docs` regen NOT owed (docs are hand-kept rosters pinned by drift guards, and the guards passed at r2 — weld verified via the family window).
