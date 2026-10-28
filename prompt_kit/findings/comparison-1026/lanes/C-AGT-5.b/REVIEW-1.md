# C-AGT-5.b review — REVIEW-1 (cc-cmp step reviewer, 2026-10-28)

VERDICT: FINDINGS (0 CRITICAL / 0 MAJOR / 2 MINOR / 3 NIT — none block the pick)

## Phantom-first
- 6f4de4575 cat-file = commit, but it is NOT the tip: branch tip is a3a1f573c (master..HEAD == 1, parent = base 8fd05587a, the §6.3 stamp commit; ruling text read on disk in plan_comparison.md §6.3). The claimed SHA is a superseded pre-amend incarnation: its tree lacks docs/COMMANDS.md + docs/SCHEDULES.md (the diff 6f4de4575..HEAD is exactly those two regenerated pages — the claimed 10-file state would have reddened CommandDocsDrift check-mode; the amend fixed that and is the correct final shape). Stale claim, honest tip.
- %ae and %ce verified on SEPARATE format greps: 1 and 1 detain. Zero Co-authored/signed/generated trailers. porcelain 0 before and after all runs.
- Builder REPORT.md absent (recorded per spawn). Additionally BRIEF.md named by the spawn (`lanes/C-AGT-5.b/BRIEF.md`) does not exist — review grounded on design-agt.md §C-AGT-5.b + plan §6.3 ruling.

## Diff vs declared scope
12 files. All within the ruling's named policy homes + the pre-ruling brief's modify list minus web/README/builtin-commands (see MINOR-2). Chat.php is a 3-line docblock touch at the handleSchedulesCommand home; merge-base scan of all 8 other live cc/* branches shows ZERO other Chat.php claimants at this base → region cap honored. builtin-commands/2910-schedules.php needs no edit (hint is `Lang::t('cmd.schedules.hint')` — the lang change carries it; CommandDocsDriftTest confirms). README roster line names the command without verbs — correctly untouched (ReadmeRosterDrift green).

## CRITICAL SEMANTICS — delete guard (verified non-vacuous)
ScheduleFactory::deleteRefusal arms door→tag→root, in that order.
- cross-root model row, no door → refused (names the root, the door, and the human surface);
- cross-root model row, door=true → null (deletable);
- same-root model row, door=false → null (no flag needed);
- unbound model row → refused as "another project root (unbound)" (matches the SCHEDULE.md "unbound included" sentence);
- operator row → C-AGT-5.a sentence pinned byte-for-byte via assertSame, plus door-leg.
ScheduleCrossRootDeleteTest covers ALL THREE ruling polarities PLUS the unbound edge and two tool-level e2e legs driven through the launch catalog (fromCatalog at a second root over the shared sandboxed roster — proves the choke, not just the helper), and description()/schema() prose pins. Re-reasoned mutations: removing the root check reddens the cross-root, unbound, and tool-foreign legs; removing the flag-door reddens the door-legs (test 4/5-door/tool-through). Non-vacuous both directions. `/schedules delete` (human surface) and ScheduleTool create/list arms are unchanged in the diff — ruling's "operator rows unchanged / human surface free" honored.

## add / outcomes
Command `add` routes through the same ScheduleFactory::create the tool uses (no parallel persistence path; registry->add+persist with the ghost-withdrawal from the C-AGT-5.a era intact). READ_VERBS stays ['list']; isReadOnlyVerb('add')-false pinned. Flag peel (space and = forms, value-must-not-start-with-'-', valueless flag refused not swallowed), prompt verbatim, factory sentences passed through verbatim, bad-interval/floor/bypass-class refusal legs all pinned with no-file-written asserts. fireDue records every decision (action strings verified to match all six fireDue emits at ScheduleRegistry.php:536-589); listing appends " — last:" only when recorded (legacy line byte-preserved). BackgroundEvents diff is docblock-only: the "pump stops discarding" design line was implemented one layer deeper (record inside fireDue, so TUI and serve pumps share it) — disclosed in the commit message, behavior-equivalent, acceptable deviation.

## E646 / rosters / laws
No new timeouts anywhere on the schedule path (timeoutSeconds untouched; add exposes none). No fork/proc_open. strict_types first line, final classes, one type per file. en-only lang is lawfully en-only (lang/ ships just en.php; §6.3-h 11-locale wave lands last; LangParity green).

## Guard runs (plain pipe, env -u LINES -u COLUMNS, cwd worktree candy-code; PtyRun never in filter)
- `--filter 'Schedule|Schedules'`: **Tests: 171, Assertions: 756, Failures: 1** — sole failure tests/Memory/DreamPassTest.php:288 (Cmd-shape assertion in Chat::update dispatch; lane's Chat.php is docblock-only → foreign, matches the spawn's DreamPass/AsyncCmd NOT-findings list).
- Direct: ScheduleCrossRootDelete + LoopSchedulesCommand + BackgroundEventsSchedules = **OK (31 tests, 135 assertions)**; ScheduleFactoryTest + ScheduleToolTest + Sessions/ScheduleTest = **OK (55 tests, 194 assertions)** (C-AGT-5.a regression incl. byte-frozen sentence green).
- `--filter 'LangParity|DocFigureProseDrift|ReadmeRosterDrift|CommandDocs'`: **OK, 114 tests / 7043 assertions** (+2 PHPUnit deprecations — pre-existing shape).
- ODDITY: one standalone `--filter CommandDocsDriftTest` run failed testTheCheckModeAgreesWithTheTest ("stale docs/COMMANDS.md", the exec'd CLI arm); every re-run since (2x full class, 3x method) green, and the in-process "generated docs are up to date" arm was green in every run including the failed one. Transient exec-child flake, not doc drift — tip carries the regenerated pages; weld battery will settle it.
- php -l clean on all 7 src/test mains.

## Durations duty
Exactly ONE new candy-code *Test.php: tests/Background/ScheduleCrossRootDeleteTest.php → **1 durations.tsv row owed at weld** (currently absent, correctly). LoopSchedulesCommandTest is modify (row already present); no SchedulesHostCommandTest exists (spawn's guess was wrong — checked the actual A/M list via git show --name-status).

## Findings
- MINOR-1 (step-local, provenance): the spawn's claimed commit SHA 6f4de4575 is not the branch tip (a3a1f573c is; same subject/base, tree gains the required in-commit docs regen). Wave bookkeeping must pick a3a1f573c.
- MINOR-2 (out-of-scope pending ruling): design-agt.md C-AGT-5.b names BackgroundPanel.vue + backgroundSchedules.spec.ts (web schedules table); neither the commit nor the spawn's lane framing carries them. Orchestrator to confirm web was carved (the block's BackgroundPanel hot-file risk against C-TOOL-3 was noted) or dispatch the half.
- NIT: deleteRefusal passes an unbound model row when the caller's hostRoot is itself null (null===null arm). Unreachable in real launches (create refuses a null root; ToolBuildContext always carries one) — worth a one-line comment or `$hostRoot !== null &&` guard later.
- NIT: lastOutcome is name-keyed and never cleared, so re-creating a same-named row in one process shows the previous row's last verdict until the next pump overwrites it (memory-only, self-healing).
- NIT: gen-command-docs.php --check could not be execed directly (reviewer shell permission map); the CommandDocsDriftTest check-mode arm covers it — 3/3 green after the one flake.
- Foreign NOT-findings observed: staleness pair family untouched; DreamPass:288 (above); no weld-only files committed (tsv/figure/dist absent from the diff).
