# C-AGT-1.b — Fire `/loop` on served sessions

**DONE** · commit `38f0b37902f95f9e8a80005f28c658884b0b0337` on `cc/C-AGT-1.b` (base b24e81154, never pushed, author/committer detain verified 1/1, zero trailers).

## Shipped (per design block)
- `src/Background/Schedule.php`: `public readonly ?string $sessionId` (ctor tail param, never serialized — `fromArray`/`toArray` untouched; `mutate()`'s `get_object_vars` spread auto-carries it). Class docblock tells the served truth.
- `src/Background/ScheduleRegistry.php`: `dueEphemeralFor(string $sessionId, int $now): list<Schedule>` (due ∧ enabled ∧ own stamp; null-stamped TUI rows never answered) + `removeEphemeralFor(string $sessionId): int`.
- `src/Host/Commands/LoopCommand.php`: stamps `sessionId: $context->sessionId`; docblock serve half.
- `src/Host/SessionHost.php`: new `public tick(int $now): int` — for each due own-session row: `noteNextDue(name, nextDueAfter(now))`, `Message::notice(Lang::t('host.loop.queued'))` appended+save+announce, `submit(prompt, SubmitOptions::new()->withDelivery(QueueMode::Followup))`; returns fired count; registry absent → 0. `release()` first drops the session's ephemeral rows. Docblock names it the generic per-session clock C-AGT-4.e reuses. No timers minted (E646).
- `src/Protocol/ServerContext.php`: `pump()` computes `$now = (int)($this->clock)()`, fires `$host->tick($now)` for EVERY open host (idle included) at most once per wall-second via new `private ?int $loopTickAt` — rides the pump's existing injectable clock. Existing busy fold unchanged (tick→busy→same-pass pump folds).
- `lang/en.php`: `host.loop.queued` = "Loop '{name}' is due: its prompt queues for this session." (mirrors TUI `chat.schedule.loop_queued`; only en.php exists in candy-code).
- `docs/SCHEDULES.md`: new "## `/loop` on a served session" section (in-step with the guards; table row "belongs to the session that made it, and dies with it" is now literally true on serve).
- TUI pump (`Chat::pumpSchedules`) byte-unchanged. No new fork/child.

## Tests
- CREATE `tests/Host/SessionHostLoopFireTest.php` (5T): fires within one tick past due (notice row + real turn + re-arm + second tick silent); busy session queues (`queued()===['write tests']`, no cut-in, runs at settle); `release()` drops rows (get→null, tick→0); cross-session + unstamped rows never fire (clocks untouched); ServerContext::pump ticks idle hosts and never twice a second (rewind-inside-same-second stays unfired; next second advances).
- MODIFY `tests/Sessions/ScheduleRegistryTest.php` +2T: keyed due filter (own/due/enabled only, other tabs and file rows excluded) + keyed remove (counts, keeps other session + persisted).

## Gates (cwd candy-code, plain pipe, env -u LINES -u COLUMNS)
- `phpunit tests/Host/SessionHostLoopFireTest.php tests/Sessions/ScheduleRegistryTest.php`: OK 25T/106A.
- `--filter 'Loop|ScheduleRegistry|SessionHost|ServerContext'`: OK 317T/1470A (2 PHPUnit deprecations PRE-EXISTING at base — AccessorDeprecationTest non-public provider, commit 8368170df, collection-time, filter-independent).
- five-guard: 133T/15602A, failures = ONLY the staleness pair, which is already red at base (25537 vs 25954 live); tip shows 25961 = base + exactly my +7.

## DURATIONS duty (gate-owned files, not touched here)
- NEW test file needing a `scripts/parallel-tests-durations.tsv` row: **`candy-code/tests/Host/SessionHostLoopFireTest.php`** (5 methods).
- Suite-figure re-pin owed: +7T (25954→25961 live).

## Notes
- `registerLoopOn` drives LoopCommand through the same seams SessionHost::commandContext() uses (sessionId + registry service), so the stamp is production-shaped.
- scripted busy-backend helper copied byte-identical from SessionHostTest (`scripted()`) per DuplicatedTestHelperDrift discipline.
- Risk row (P-SESSION-TABS will want TUI per-session keying): the stamp field is shared grammar now — the TUI pump can filter on it without a schema change.
