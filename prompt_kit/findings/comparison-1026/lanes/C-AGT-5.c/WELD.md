# C-AGT-5.c — WELD (W15 final member)

Master base at weld: 808859066 (weld: C-MCP-3.b). Lane cc/C-AGT-5.c tip 1d8dad74e603147567027477d59cf31c87f26a78 (base a812ea659 = welded C-AGT-5.b), ONE commit.

- **Pick:** ff23948e797c90df66a4fa423b6323414b3552d0 — cherry-picked CLEAN (auto-merge touched candy-code/lang/en.php and tests/Cli/StderrEmitterCensusTest.php with zero conflicts; merged hunks byte-identical to the lane diff, verified by diff-of-±lines — union false-positive risk not materialized, CTX-2.d prompt-suggestion / SEC-6.a permissions / MCP-3.b keys all intact).
- **Review:** r1 REVIEW-1.md CLEAN 0C/0M; all gates independently reproduced EXACT on the lane tip.

## Widening recorded (r1 MINOR, adjudicated sound — §1.8 in-step law)

BRIEF-declared 9 files; the commit carries 11. The two disclosed widenings are recorded here as reviewed-and-accepted:
1. `candy-code/src/Background/ScheduleFactory.php` — NOT in design files.list; it is the SOLE `create()` path both user surfaces (`/schedules add`, Schedule tool) flow through, and the strict `in_array($kind, Schedule::KINDS, true)` door there is the fail-loud boundary the feature's refuse-by-name contract demands. Minimal membership check + `?? KIND_TASK` default.
2. `host.schedules.*` lang strings (`usage`, `none`, `add_usage` `--heartbeat` edits + new `host.schedules.kind_heartbeat` listing mark) — same command's own strings, beyond the claimed `chat.schedule.*` region. No live-lane collision (W15 sweep: in-flight claims disjoint; historic claimants are welded ancestors).

## Structural red-before disclosure (from REVIEW-1 §4)

Red-before "37T/91A/9E" is corroborated STRUCTURALLY, not runtime-replayed: the reviewer's harness map lacks cp/rm so the base-blob swap could not be executed. The proof stands because `Schedule::KIND_HEARTBEAT` and `new Schedule(..., kind:)` exist in NEITHER base blob (readonly-prop count 12→13; zero new-method grep hits at a812ea659) → each of the 9 new tests errors over base; base Sessions\ScheduleTest = 28T vs tip 30 (+2 methods verified by blob diff). Builder-side identical green filters on the real tree corroborate.

## Merged-tree gates (cwd candy-code, plain pipe, env -u LINES -u COLUMNS, PtyRunToolTest never in filter)

- `HeartbeatSchedule`: `OK, but there were issues! Tests: 9, Assertions: 36, PHPUnit Deprecations: 2.`
- `Schedule|Heartbeat|DreamPass`: `Tests: 243, Assertions: 1076, Failures: 1` — sole F = DreamPassTest::testChatSchedulesThePassAtTurnCloseAndReportsWhatChanged :288 (standing FOREIGN, report-only).
- `StderrEmitterCensus`: `OK ... Tests: 95, Assertions: 21484` (+18A vs lane-tip 21466 = C-MCP-3.b census content on master, expected merged-tree growth).
- `CommandDocsDrift|DocFigureProseDrift|ReadmeRosterDrift`: `OK ... Tests: 108, Assertions: 4136` — fully green (staleness pair lives in ReadmeSuiteFigureDriftTest, outside this filter).
- Generators: `gen-command-docs --check` rc=0, `gen-tool-docs --check` rc=0, `gen-settings-doc --check` rc=0. src/Protocol/ untouched → schema leg N/A; no candy-code-web files in lane → web chain N/A.

## Carries / standing reds named

- **Closeout re-pin carry:** +9T/+36A against pinned era figure 25,537T (new file HeartbeatScheduleTest.php; suite-figure.json untouched per law).
- `durations.tsv` row `candy-code/tests/Chat/HeartbeatScheduleTest.php 0.055 9` landed at descending slot (line 799); file 1539→1540; desc-invariant violations 4→4; set-equality +1 (commit ea68b8eae, separate).
- MenuBarLabel/PaletteSuperset 'pr' row, ChatHostLangCatalogue (chat.startup.personal/attach.pdf_pages/effort.launch_unshaped), ChatTest AsyncCmd/suggestions ×5, AutomaticCompaction, ProjectTierRefusalInventory reds: **base-inherited foreign roster, NOT lane-caused** (REVIEW-1 §6 maps all 9 'Chat|Background' failures 1:1 to declared carry list, zero new names).
- i18n: en-only per plan §6.3-h — community locales land LAST via C-TUI-6.h.

## Proof + cleanup

`git cherry master cc/C-AGT-5.c` → `-` (absorbed). Worktree /home/sites/cc-cmp-C-AGT-5.c removed; branch cc/C-AGT-5.c deleted. No push (owner gates). W15 FULLY WELDED (10/10) — wave loop r1 next.
