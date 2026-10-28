# C-TOOL-3.e — Web Processes section + shells.* methods — LANDED

Commit: eef374f6146757c4b57822c379f8113cbad65b54 on cc/C-TOOL-3.e (base master cf0cfd0be). Author/committer both detain@interserver.net (verified %ae/%ce separately), no Co-Authored-By, never pushed. 9 files, +994.

## What shipped
- `candy-code/src/Protocol/Methods/ShellsMethods.php` (new): `shells.list` (Read), `shells.output` (Read, two-part `out.err` cursor — int or token accepted, garbage refused naming the field), `shells.stop` (Write, sideEffects=true, the registry's idempotent ladder). Owner = the validated `sessionId` param used directly as the C-TOOL-3.c registry owner key — the call deliberately never opens `$call->host()` (workspace-level table; no session-open side effect; documented). No spawn door (Shell tool stays the one). No pid, no stdout/stderr/exit paths on the wire rows. Registry resolved via `workspace()->service(BackgroundShellRegistry::class)`; null table = `['items'=>[]]` on list (bg.list precedent) and `shells_unavailable` on output/stop.
- `Dispatcher.php`: `ShellsMethods::register($registry)` right after `BgMethods::register`; import added.
- `MethodSchemas.php`: shells.list/output/stop param+result schemas after the bg.stop block (oneOf(int,string) cursor; enum state running|finished|unknown).
- `Definitions.php`: `BACKGROUND_SHELL` const + row def ("never its output — shells.output reads that"), ksort handles ordering.
- `candy-code-web/src-web/stores/agents/shells.ts` (new): per-session-keyed CALLS (`shells.list {sessionId}`), fresh tail-wins output window per shell, stop-then-reload. No onHello/onEvent — no new event type by design.
- `candy-code-web/src-web/components/panels/ProcessesSection.vue` (new): rows with state badge, settle column (exit code / note / expired), age; expandable recent output on demand; write-gated Stop on running rows only; polls `shells.list` every 3000ms starting onMounted, cleared onUnmounted, re-armed on sessionId change, never polls without a session.
- `BackgroundPanel.vue`: `<ProcessesSection :session-id="sessionId" />` mounted inside the panel (after the schedules section). No SidePanels.vue, no stores/agents/panels.ts touches.

## Tests (targeted only)
- PHP `tests/Protocol/ShellsMethodsTest.php` (NEW, 7T/58A OK 1.6s): scope/sideEffects roster pin; owner-filtered list (own + untagged only) + exact row-key census (no pid/paths); foreign==unknown on output (identical sentence+kind) and all-false stop that provably kills nothing; windowed read + cursor threading ('6.3'→drain→rewind); stop idempotency ladder (wasRunning→no-op; settled exitCode 0); no-table launch (empty page + shells_unavailable pair); bad cursor/session refusals. Registry built with the temp-root + 2ms sleeper seams.
- PHP `tests/Protocol/` dir: 120T/1163A OK — includes ProtocolSchemaDriftTest + ServerProtocolDocumentationDriftTest GREEN on locally-regenerated surfaces.
- Web `processesSection.spec.ts` (NEW, 7/7): render+params, on-demand output, Stop→shells.stop{s1,sh-id}→reload, read-scope hides Stop, finished rows have no Stop, polling 1→2→3 frames on interval and DEAD after unmount (vi.useFakeTimers({toFake:['setInterval','clearInterval']}) keeps the harness flush real-timed), null-session never reads, and BackgroundPanel integration (section exists in panel, rows render).
- Web regressions: backgroundSchedules 5/5, panels 11/11, generated.spec + dormantRoster 6/6 (local regen), `vue-tsc --noEmit` clean.

## Protocol regen note
`php scripts/gen-protocol-schema.php --write` + `npm run gen:protocol` run locally; the 3 generated surfaces (schema.json, SERVER.md, generated.ts) are LEFT UNCOMMITTED per the regen law — the welder regenerates on the fused tree. Staged with explicit-path `git commit -o`.

## Risks / welder notes
- ShellsMethodsTest spawns REAL `sh -c` children (printf / sleep 30 killed via the ladder with the sleeper seam); teardown calls registry->teardown(). 1.6s runtime; if a CI box lacks setsid the `grouped` field is deliberately not pinned true.
- `dormantRoster`/`generated` guards will be green at weld only if the welder regenerates before judging (they pass locally with regen).
- No suite-figure/durations changes committed (gate duty); ShellsMethodsTest.php is a NEW PHP test file needing a durations.tsv row at closeout, processesSection.spec.ts a new web spec.

## FIX-1 (r1 MINOR, round 1) — commit 264ffa6d81acc13a87bf2fd56fcf375681566042 on cc/C-TOOL-3.e (parent eef374f6146757c4b57822c379f8113cbad65b54, review hash intact)
- ProcessesSection.vue: Stop click now routes through the BackgroundPanel `act()` idiom — local `error` ref + `act()` try/catch (`describe(failure)`), `stop()` hoists sessionId to a const and awaits `act(() => shells.stop(...))`; the proc-error `<p>` shows `error || shells.error`. A rejecting `shells.stop` is now a visible alert line, never an uncaught rejection. NIT (cursor ordering) untouched — CLOSEOUT carry.
- processesSection.spec.ts: +1 case "a REJECTING shells.stop surfaces as the error line, never an uncaught rejection" — wires `wire.fail('shells.stop', ...)`, asserts the alert text, an empty `process.on('unhandledRejection')` collector, and NO fake reload (shells.list stays 1). Non-vacuity proven: reverting the .vue fix reddens exactly this test (mutation run: 1 failed | 7 passed); fixed code green.
- Targeted vitest serial (--no-file-parallelism, plain pipe, env -u LINES -u COLUMNS, cwd candy-code-web): processesSection + panels + backgroundSchedules = 3 files / 24 tests passed (23 pre-fix + 1 new), vue-tsc exit 0.
- Only the two lane files staged (git commit -o); generated trio + SERVER.md dirt left unstaged per regen law. Author/committer detain verified (each grep == 1), zero Co-Authored-By, no caliber, not pushed.
