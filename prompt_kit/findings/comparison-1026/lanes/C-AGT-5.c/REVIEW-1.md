# C-AGT-5.c 'Heartbeat schedule kind' — REVIEW-1

VERDICT: CLEAN (0 CRITICAL / 0 MAJOR / 1 MINOR step-local / 3 NIT + report-only carries)

Lane tip 1d8dad74e603147567027477d59cf31c87f26a78 on cc/C-AGT-5.c (parent a812ea659 = welded C-AGT-5.b; review kept on the lane tip, not the moved master 37a4ef615). Independent re-derivation from disk; builder REPORT.md claims cross-checked line by line; worktree untouched by this reviewer (read-only; no cp/rm in the reviewer bash map — the two runtime base-proofs below were re-derived structurally and are flagged as harness-limited, not skipped silently).

## 1. Phantom-first — PASS

- `git cat-file -t 1d8dad74e` = commit; HEAD == tip SHA; branch `cc/C-AGT-5.c`; `master..HEAD` = exactly 1 line (`1d8dad74e C-AGT-5.c: Heartbeat schedule kind ...`).
- `status --porcelain` empty → tree clean. Author email `%ae` and committer `%ce` grepped SEPARATELY: both `detain@interserver.net`; body `grep -ci 'co-authored|signed-off'` = 0 trailers.
- `show --name-status`: exactly the claimed 11 files, +672/−21. One `A` (HeartbeatScheduleTest.php), ten `M`. No stray files, no weld-only artifacts (suite-figure.json / durations.tsv / dist untouched).

## 2. Declared set vs BRIEF — deviations adjudicated

BRIEF declared 9 files; the commit carries 11. Both extras were disclosed in REPORT.md and are adjudicated here:

1. **src/Background/ScheduleFactory.php** — not in files.list. It is the single `create()` path both user surfaces use (`/schedules add` and the Schedule tool). Without a kind door there, an unknown `kind` arriving via a hand-written schedules.json row or tool args would reach `Schedule::fromArray`/ctor only — the factory is the natural fail-loud boundary the feature's own "strict refusal by name" contract demands. The added code is a `strict in_array($kind, Schedule::KINDS, true)` membership check with a refusal naming both kinds plus the omit-case, and a `?? KIND_TASK` default passed through. Correct placement; minimal.
2. **host.schedules.{usage,none,add_usage}** lang edits (beyond the claimed `chat.schedule.*` region) — these are the same command's own strings; `--heartbeat` is unusable-but-documentable without them. The new `host.schedules.kind_heartbeat` = `(heartbeat) ` is consumed by SchedulesCommand's listing concat and survives the ChatHostLangCatalogue scan (the guard's failure names ONLY the three foreign unasked keys — see §6).

Wave-concurrency check: in-flight lanes are only cc/C-AGT-5.c and cc/C-MCP-3.b, whose claims (NonInteractive, mcp.elicitation.*, MCP.md, EngineBackend, McpToolBridge) are disjoint from both extra files. Historic host.schedules.* claimants (C-AGT-1.a/5.a/5.b/7.a) are welded ancestors. `git worktree list` re-read this session. → No concurrency breach; the MINOR below records the region-widening for the welder to stamp.

## 3. Semantics trace — PASS

- **Kind closed set**: Schedule consts KIND_TASK/KIND_HEARTBEAT/KINDS; ctor refuses off-roster spellings by throwing an InvalidArgumentException whose message names the offending value AND both legal kinds — never normalises/case-folds (`'HEARTBEAT'`, `'heart-brat'` refusals pinned in ScheduleTest; `'hearthbeat'` + int-3 provider rows added). fromArray: non-string → throw; unknown string → throw by name; absent/'' → KIND_TASK (legacy rows are task rows — the 11th-key round-trip test pins absence semantics and the always-spelled toArray position; `array_keys` order pin proves kind is written 11th and always spelled).
- **toArray round-trip**: field loop extended with 'kind'; `testTheFileRowCarriesExactlyThePlannedElevenKeys` renames the method (Nine→Eleven) — honest in-step flip, not a silent widen. withKind rides mutate() (get_object_vars merge), and `kind` was added to the mutate-carries field list.
- **Task byte-parity**: task rows never enter the heartbeat branches — the pump's per-transition work is gated on `isHeartbeat()` (reads the session's schedule-heartbeat TAG, set at spawn by tagsFor(), so a mid-flight row edit can't flip meaning), and ScheduleTool's receipt clause + spawner's HEARTBEAT_OK instruction + the extra tag are heartbeat-only. ScheduleToolTest green inside ToolDoc|ScheduleTool 23T/101A; the 15 legacy-field round-trips unchanged in ScheduleTest.
- **pumpBackgroundSessions hunks (19079–19236)**: heartbeat + Completed + `trim(output) === HEARTBEAT_OK` → no queued turn, no ring (status notice still queued — the OK test pins the notice's presence, so suppression is turn-only). Any other heartbeat settle (any answer, or Failed/TimedOut/Stopped) → announcement AND `$heartbeatAlerts[] = name`. Ring = `terminalNotification('candy-code: ' . Lang::t('chat.schedule.heartbeat.alert', ['name' => implode(', ', ...)]))` gated on `$heartbeatAlerts === [] || $polled->inFlight ? null : ...`, delivered via `releaseQueuedPrompts([$polled, $ring])` / the direct `[$polled, $ring]` return — verified releaseQueuedPrompts merges the cmd into the batch (:11058). The inFlight polarity matches the house precedent (HandlesTurnCompletion.php:342 notifies only when not mid-turn). With heartbeatAlerts empty (every task run) `$ring` is null and both return shapes equal the old code byte-for-byte.
- **Both surfaces reachable**: `/schedules add ... [--heartbeat]` (valueless peel before mode/provider accounting — grammar-consistent, and the add_flag refusal message still names only value-taking flags correctly) and the Schedule tool's `kind` inputSchema enum. `/schedules` listing marks heartbeat file rows `(file) (heartbeat) `.

## 4. Non-vacuity (reasoned from code; tree never mutated)

- **never-silent** (drop the HEARTBEAT_OK equality leg) → only the OK-answer test asserts suppression → 1F ✓. bell/off/AnAnswer/dead-status tests all use non-OK settles → unaffected.
- **always-silent** (heartbeat settles never announce/ring) → AnAnswer (announcement+ring bytes), NeverAnswered dead-status loop, midTurn queue-count, bell-mode, off-mode announcement → 5F ✓.
- **ring-never** (null the ring) → AnAnswer, NeverAnswered, bell-mode → 3F ✓ (off-mode expects no ring; midTurn expects none).
- **ring-ignores-inFlight** (drop the `$polled->inFlight` conjunct) → only the midTurn test arms a settle during an in-flight turn and asserts `ringBytes === []` — the pin genuinely checks the ABSENCE of OSC-9/bell mid-turn, so removing the gate flips exactly 1F ✓.
- Red-before 37T/91A/9E: HeartbeatScheduleTest is 9 methods, no providers, and references `Schedule::KIND_HEARTBEAT` + `new Schedule(..., kind:)` — neither exists at a812ea659 (readonly-prop count 12→13, zero new-method grep hits in the base blob), so each errors → 9E over 9T; base Sessions\ScheduleTest runs 28T (tip 30 = +2 methods, verified by blob diff) → 37T, with 91A being base ScheduleTest's total (erroring tests assert nothing). Consistent by construction; a runtime cp-harness swap is not available inside this reviewer's bash map (no cp/rm/mkdir) — disclosed, and the claim is corroborated because the identical green filters were run on the real tree.
- Guard trio: my `DocFigureProseDrift|GlobDialectDifferential|DuplicatedTestHelperDrift` run is 118T/4589A GREEN (claimed 119T/4592A — exact filter string not pinned in the report; every guard class green either way, the delta is a filter-spelling artifact). DuplicatedTestHelperDrift green covers the new file's helpers (alertLine/ringBytes uniquely named / byte-licensed). GlobDialect green: HeartbeatScheduleTest literals — the OSC-9 fixture is escape-byte text, no glob-shaped (`*`-with-no-space) and no `**Refused:**`-shaped tokens.

## 5. In-step guard law — PASS

- StderrEmitterCensusTest `src/Chat.php` row 1→2 shipped IN-STEP with a decision stamp (comment names the pumpBackgroundSessions addition and reuses the goal-settled "OSC 9 / bell seam, not a diagnostic" decision). Literal census independently re-derived: `'candy-code: '` appears exactly once in base Chat.php (:2866 goal_ended) and twice at tip (+ :19228 heartbeat). Guard green: StderrEmitterCensus 95T/21466A.
- CommandDocs: the `--heartbeat` usage edit passes CommandDocsDriftTest (6T/11A green) — the in-suite mirror of gen-command-docs --check. ToolDocDriftTest (same contract as gen-tool-docs --check, drift()==[]) green inside ToolDoc|ScheduleTool. Standalone `php tools/gen-*.php --check` is not runnable under this reviewer's map; the in-suite guards ARE the --check semantics.
- docs/SCHEDULES.md: field-table kind row, new '## The `heartbeat` kind' section (anchor slug matches both cross-refs), grammar block, tool-boundaries bullet — all consistent with the shipped behavior (silence-suppression + announce/ring + inFlight gate + kind enum).
- i18n: en-only per plan §6.3-h (community locales land LAST via C-TUI-6.h); the lang/ tree here contains only en.php; LangParity|LangLaunchLocale 20T/2968A green.

## 6. Gate reproduction (cwd worktree candy-code, plain pipe, env -u LINES -u COLUMNS, PtyRunToolTest never in any filter)

- HeartbeatScheduleTest: `Tests: 9, Assertions: 36` OK — EXACT.
- `HeartbeatScheduleTest|Sessions\ScheduleTest`: `Tests: 39, Assertions: 137` OK — EXACT. (A looser `ScheduleTest` substring also drags ContextSnapshotScheduleTest → 44T/157A, likewise green; the 39/137 figure pins the narrow filter.)
- `Schedule|Heartbeat|DreamPass`: `243T/1076A/1F` — EXACT; the single F is `DreamPassTest::testChatSchedulesThePassAtTurnCloseAndReportsWhatChanged` :288 (standing foreign, name-only confirmed).
- `Chat|Background`: `2243T/18257A/9F` — EXACT, and the 9 names + messages map 1:1 to the pre-declared foreign roster: ChatTest AsyncCmd/suggestions ×5 (:4219..:4325 family), AutomaticCompactionModelSummaryTest:1591 breaker, ChatHostLangCatalogueTest:90 whose Actual array is EXACTLY `chat.startup.personal, chat.attach.pdf_pages, chat.effort.launch_unshaped` — none of the lane's keys (proof the new strings are consumed and the red is C-PRV-1.b-sibling territory), PaletteSupersetDispatchTest:169 differing ONLY by the 'pr' row (C-AGT-6.d W15 carry), DreamPassTest:288. Zero NEW failure names. (Runtime base-set comparison unavailable in the reviewer's map — see §4; the message-level roster mapping plus the disjoint-hunk analysis is the substitute proof.)
- Guard trio family: green (118T/4589A, §4). LangParity|LangLaunchLocale: `20T/2968A` OK — EXACT. StderrEmitterCensus: `95T/21466A` OK — EXACT. ToolDoc|ScheduleTool: `23T/101A` OK — EXACT.
- 'PHPUnit Deprecations: 2' rides EVERY run in this tree (pre-existing baseline noise, unrelated to the lane).
- ReadmeSuiteFigureDrift staleness pair: NOT in any filter run this session; at tip the pinned era figure is +9T/+36A stale by construction (one new file) — report-only, closeout re-pin duty per law (lane correctly did NOT touch suite-figure.json).

## 7. E646 / spawn / durations

- Diff src lines contain zero additions matching proc_open/pcntl/addTimer/Loop::/sleep/stream_select — no new timers or spawn sites; heartbeat reuses the existing supervised-background path (E646-clean).
- durations.tsv duty list for the welder: exactly ONE new test file, `candy-code/tests/Chat/HeartbeatScheduleTest.php` (+9T). LoopSchedulesCommandTest/ScheduleFactoryTest/etc. pre-exist at base.

## Findings

- MINOR (step-local): BRIEF-declared set widened by ScheduleFactory.php (kind door) + host.schedules.* lang edits + kind_heartbeat listing mark — disclosed, adjudicated SOUND here (single create-path necessity; command-owned strings; no live-lane collision; all consumed by passing guards). Welder should record the region-widening in the pick log so W15 briefs stay truthful.
- NIT: guard-trio filter string not quoted in REPORT.md (claimed 119T/4592A; nearest reproducible green spelling is 118T/4589A) — quote `--filter` text in future reports.
- NIT: builder claims standalone `gen-command-docs/gen-tool-docs --check` rc0; not rerunnable in the reviewer map — the in-suite ToolDocDriftTest/CommandDocsDriftTest green is the equivalent contract.
- NIT: HeartbeatScheduleTest re-ran with the same 2 tree-wide PHPUnit deprecations as every other filter — cosmetic environment baseline, no action.
- Report-only (NOT findings): era staleness pair (+9T/+36A) for closeout re-pin; the 1F/9F foreign set enumerated in §6 exactly matches the wave's declared carry list — nothing lane-caused.

Bottom line: the feature does what §2's step block promises — a spelled 11th kind field refused-by-name on every intake path, HEARTBEAT_OK silence suppressing the queued turn, any other heartbeat settle announcing and (when idle) ringing through the established OSC-9/bell seam, task behavior byte-identical — with honest in-step census movement and every gate reproduced exactly on disk.
