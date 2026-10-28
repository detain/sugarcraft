# C-AGT-5.c — Heartbeat schedule kind (W15, effort M, phase normal)

Item: C-AGT-5 (sources: GAP-PA-02)
Deps: C-AGT-5.b (WELDED a812ea659 — build on its landed code; worktree cut at a812ea659)

## Files (create/modify — STAY INSIDE)
- modify candy-code/src/Background/Schedule.php [region: kind]
- modify candy-code/src/Background/ScheduleSpawner.php [region: schedule-heartbeat]
- modify candy-code/src/Chat.php [region: pumpBackgroundSessions ONLY]
- modify candy-code/src/Host/Commands/SchedulesCommand.php [--heartbeat arm]
- modify candy-code/src/Tools/BuiltIn/ScheduleTool.php [kind]
- modify candy-code/lang/en.php [chat.schedule.heartbeat_* keys, en-only per 6.3-h 11-locale sweep LAST]
- modify candy-code/docs/SCHEDULES.md
- CREATE candy-code/tests/Chat/HeartbeatScheduleTest.php
- modify candy-code/tests/Sessions/ScheduleTest.php [kind round-trip]

## Serialized regions (plan 2 caps)
[chat:pumpBackgroundSessions] [lang: chat.schedule.*] [docs:SCHEDULES.md]

## Approach (verbatim from plan)

- A row gains `kind: "task"|"heartbeat"`. For a heartbeat, `ScheduleSpawner` appends "reply exactly HEARTBEAT_OK when nothing needs attention".
- When the daemon settles, Chat's `pumpBackgroundSessions()` suppresses the queued announcement turn for an `HEARTBEAT_OK` answer, and otherwise announces it and rings `terminalNotification()` (OSC 9).
- On serve the run stays an ordinary `bg.completed` row. Web push is C-WEB-5's job (LATE), so it is NOT a dependency.
- `/schedules add --heartbeat` and the tool's `kind` param pass it through.

## Tests (verbatim)
- create `candy-code/tests/Chat/HeartbeatScheduleTest.php` (an OK answer queues no turn and rings nothing; any other answer announces and notifies); modify `candy-code/tests/Sessions/ScheduleTest.php` (`kind` round-trip)

## Risks (verbatim)
- `pumpBackgroundSessions` is also edited by C-AGT-4.e. Keep them in different waves.

## Laws
Obey plan 1, 2, 6. One cohesive commit on the lane branch; author split-var A='detain';B='interserver.net';MAIL + GIT_* env + --author, %ae/%ce separate greps, zero trailers, explicit-path staging; NEVER suite-figure.json/durations.tsv/dist; no git stash (/tmp cp-harness); in-step guard law (ReadmeRosterDrift, DocFigure, CommandDocs/MenuBarLabel/PaletteSuperset/BuiltInCommands if roster rows move, LangParity, DuplicatedTestHelperDrift byte-identical-or-rename, FrontmatterKeyAudit if touched); test-literal hazards (GlobDialect live corpus 304,700; DenialPrefixRoster shapes); E646 (no blanket LLM timeouts); red-before proof for every regression test; gates targeted-only plain pipe env -u LINES -u COLUMNS cwd candy-code, PtyRunToolTest NEVER in filter; REPORT.md <=30 lines verdict-first to prompt_kit/findings/comparison-1026/lanes/C-AGT-5.c/REPORT.md in the MAIN tree (uncommitted); NEW *Test.php list for welder durations duty; NEVER push; NEVER edit files outside the declared set/regions.
