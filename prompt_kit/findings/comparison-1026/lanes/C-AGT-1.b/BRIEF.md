### C-AGT-1.b — Fire `/loop` on served sessions
- item: C-AGT-1 (sources: GAP-AUT-08, k3-sum (live defect), k3-dorm#1, p1-miss: schedules UX (`/loop` for served sessions))
- kind: dormant-wire · MAJOR · effort M
- approach: Root cause: `LoopCommand` registers an ephemeral row (`src/Host/Commands/LoopCommand.php:65-72`), but `fireDue()` skips non-persisted rows (`ScheduleRegistry.php:395`), and the only ephemeral pump is `Chat::pumpSchedules()` (`src/Chat.php:18518`), so a browser `/loop` answers "registered" and never fires. Fix:
  - stamp each ephemeral `Schedule` with its owning `sessionId` (LoopCommand reads `CommandContext::$sessionId`);
  - add `ScheduleRegistry::dueEphemeralFor(string $sessionId, int $now)` and `removeEphemeralFor(string $sessionId)`;
  - add a public `SessionHost::tick(int $now): int` that advances each due loop row in memory, appends a notice row, and `submit()`s the prompt with `QueueMode::Followup`, so it waits behind a running turn. `release()` drops the session's rows, so loops die with their session or host;
  - in `ServerContext::pump()` (`src/Protocol/ServerContext.php:438`), call `tick()` for every open host, not only busy ones, at most once per second.
  - The TUI pump is unchanged.
  - `SessionHost::tick()` is the generic per-session clock that C-AGT-4.e reuses.
- files.create: none
- files.modify: `candy-code/src/Background/Schedule.php` (ephemeral `sessionId`, never serialized), `candy-code/src/Background/ScheduleRegistry.php` (`dueEphemeralFor`, `removeEphemeralFor`), `candy-code/src/Host/Commands/LoopCommand.php` (`run`), `candy-code/src/Host/SessionHost.php` (new `tick()`, `release()`), `candy-code/src/Protocol/ServerContext.php` (`pump()`), `candy-code/lang/en.php` (`host.loop.queued`), `candy-code/docs/SCHEDULES.md` (`/loop` on serve)
- tests: create `candy-code/tests/Host/SessionHostLoopFireTest.php` (a served `/loop` fires within one tick past due; a busy session queues it; `release()` removes the row; another session's row never fires here); modify `candy-code/tests/Sessions/ScheduleRegistryTest.php` (keyed due/remove)
- docs/guards: `docs/SCHEDULES.md` section. No new fork or child.
- serialized: [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code/src/Protocol/ServerContext.php] [lang: `host.loop.*`] [docs:SCHEDULES.md]
- deps: C-AGT-1.a
- phase: normal
- ruling: none
- risks: P-SESSION-TABS will need the same per-session keying in the TUI pump; today's Chat pump fires every in-process loop row.

