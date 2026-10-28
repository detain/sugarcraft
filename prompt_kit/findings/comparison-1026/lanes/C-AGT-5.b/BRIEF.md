# BRIEF: C-AGT-5.b — `/schedules add`, fire outcomes, web schedules table
wave  · area agt · kind gap · effort M · effort  · phase normal
item: C-AGT-5 (sources: GAP-AUT-07, p1-miss: schedules UX)
deps: C-AGT-5.a
serialized: [commands:2910-schedules.php] [hot:candy-code/src/Host/BackgroundEvents.php] [hot:candy-code-web/src-web/components/panels/BackgroundPanel.vue] [lang] [docs:COMMANDS.md] [docs:README.md] [docs:SCHEDULES.md]
ruling: none
risks: C-TOOL-3's web Processes tab may also land in BackgroundPanel. Mark it hot.
## files.create
none
## files.modify
`candy-code/src/Host/Commands/SchedulesCommand.php` (`run`, `listing`, new `add`), `candy-code/src/Background/ScheduleRegistry.php` (last outcome), `candy-code/src/Host/BackgroundEvents.php` (`pumpSchedules`), `candy-code/builtin-commands/2910-schedules.php` (hint), `candy-code/lang/en.php` (`cmd.schedules.hint`, `host.schedules.add*`, `host.schedules.outcome*`), `candy-code/docs/COMMANDS.md` + `candy-code/README.md` (regenerated), `candy-code/docs/SCHEDULES.md`, `candy-code-web/src-web/components/panels/BackgroundPanel.vue`
## tests
modify `candy-code/tests/Host/Commands/LoopSchedulesCommandTest.php` (`add` happy path, bad interval, bypass refused, read-only refused), `candy-code/tests/Host/BackgroundEventsSchedulesTest.php` (an outcome is recorded); create `candy-code-web/src-web/components/panels/__tests__/backgroundSchedules.spec.ts`
## docs/guards


## approach

- `/schedules add <name> <interval> <prompt> [--mode m] [--provider p]` goes through `ScheduleFactory`, stamped with the host root. It is mutating, so it is refused in read-only windows; `READ_VERBS` is unchanged.
- The registry records each row's last `fireDue` outcome (fired, error or refusal) in memory. `BackgroundEvents::pumpSchedules()` (`src/Host/BackgroundEvents.php:221`) stops discarding results, so serve users finally see refusals.
- `/schedules list` renders a `TranscriptTable` with a last-outcome column.
- Web: a Schedules subsection in `BackgroundPanel.vue` runs `command.exec /schedules` and renders the table markdown, with enable/disable/delete buttons through `command.exec`. No protocol change.
