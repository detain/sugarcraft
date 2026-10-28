# WELD — C-AGT-5.b (`/schedules add`, fire outcomes, web schedules table)

Welded 2026-10-28 by welder (sole master writer). Repo /home/sites/sugarcraft, master. NEVER pushed.

## Pick

- Lane: branch `cc/C-AGT-5.b` tip `5f551912ee1ae8a4f56882f007c7da1d0e5a86d2` on base `8fd05587a` (1 commit; 15 files, +916/−63; root-scoped `/schedules` through the factory, fireDue last-outcome recording, web schedules table, docblock-only Chat.php touch).
- Cherry-picked onto master (at `d036ee00e`) with ZERO conflicts → **`93448849594e9c779e12a6979f2d5ac7e721f05d`** — `C-AGT-5.b: /schedules add through the factory, recorded fire outcomes, root-scoped delete guard`.
- Absorption proof: `git patch-id --stable` IDENTICAL both sides — `3189d8669415d7bef798c4e2ed3014512b3e7e61` for `5f551912e` and `934488495`. `git cherry master cc/C-AGT-5.b` → `-`.
- Author law on the pick: `%ae`/`%ce` separate greps both `detain@interserver.net`; zero Co-Authored/Co-developed trailers.

## Reviews

- r1: 0C/0M/2MIN/3NIT — MINOR-1 (pick-hash drift) superseded by final tip `5f551912e`; MINOR-2 (web half) CURED in that amend. (`REVIEW-1.md` collected from `/tmp/opencode/cc-cmp-lanes/C-AGT-5.b/` per lead roster and rides this weld commit.)
- r2 cure-review: **CLEAN** 0C/0M/0MIN/1NIT (`REVIEW-2.md`).

## Merged-tree gates (plain pipe, `env -u LINES -u COLUMNS`, cwd `candy-code` / `candy-code-web`)

- `--filter 'Schedule|Schedules'` → **Tests: 171, Assertions: 756, Failures: 1** — EXACT vs expectation; the sole failure is `Memory\DreamPassTest::testChatSchedulesThePassAtTurnCloseAndReportsWhatChanged` — named standing foreign red (test-name token-collides with the filter; unrelated to schedules, present before this pick). Not attributed to this lane.
- `--filter ScheduleCrossRootDeleteTest` → **OK: 8T/25A** EXACT.
- Sanity on the touched guard `--filter MenuBarLabelDerivationTest` → 11T/176A/**1F** — see foreign-pr disclosure below; the schedules row itself (`'schedules' => 'List, add, enable'`) matches Actual in the diff, the failure line is the ADDED `'pr'` row.
- vitest `--no-file-parallelism`: `backgroundSchedules.spec.ts` **5/5**; with neighbor `panels.spec.ts` combined **16/16** (5+11).

## Foreign 'pr' MenuBar roster red (standing — C-AGT-6.d debt, NOT this pick)

`MenuBarLabelDerivationTest::testEverySlashOnlyRowReadsAsThisTable` reds 1F at lane base `8fd05587a`, at lane tip `5f551912e` (reproduced in the lane worktree, porcelain clean), and at pre-pick master `d036ee00e` alike. Root cause: `builtin-commands/2860-pr.php` + `src/Commands/PrCommand.php` already exist at the lane base (C-AGT-6.d weld chain), so the live derivation yields the extra `'pr' => 'Push the current'` row, while the test's expected roster has **no `pr` row at base or tip** (absent in the file at both). The lane touches no pr file and only adds the `schedules` expected row (+2/−1). Cure belongs to the C-AGT-6.d/W14 wave-loop ledger, per program law the welder does not fix foreign reds.

## 10:34 sweep-incident disclosure (from builder REPORT, re-verified by r2)

At 10:34:02 a lead-side process committed `8fd05587a` and ran checkout/clean over `candy-code/tests` inside this lane's worktree — reverting uncommitted test edits and deleting the untracked new test file. Src/lang survived; the builder rebuilt everything and r2 verified the rebuild byte-clean against the shipped tip. Honestly disclosed deviation #1; lanes' uncommitted work is at risk under this pattern (program-level caution, not a lane defect).

## Riders

- **Durations row LANDED separately**: commit `65061ab0f128e4b6b901ec836c1081482ce0ee5a` — `candy-code/tests/Background/ScheduleCrossRootDeleteTest.php\t0.042\t8` (junit per-testcase time sum 0.042, 8 cases; line 851, desc-invariant — 4 pre-existing tail violations 4==4 before/after, set-equality exactly +1, tsv 1527→1528).
- `tests/Host/Commands/LoopSchedulesCommandTest.php` = EXISTS_AT_BASE modify → NO new row (row present at line 645, untouched).
- `backgroundSchedules.spec.ts` is vitest → never a tsv row.
- **No regen riders**: `git show --name-only` confirms zero `src/Protocol/` in the pick; COMMANDS.md edit is in the hand-edit table region ("edit it by hand" header, not a generated block); no web dist rebuild for this lane alone (dist rides the C-CTX-2.d regen commit below in this same session).

## Carries

- **NIT (r2, accept-as-is)**: `parseScheduleLine` in BackgroundPanel.vue matches English-only tokens (`[on]`, `(fire)`, U+2014 em-dash) when re-deriving buttons from the server listing. Lawful today (en-only lang; graceful verbatim rendering otherwise, header comment declares "the panel never re-derives meaning the command did not print"). **Flag for the C-TUI-6.h locale wave** if server-side strings ever localize.
- Owner ruling (a) ROOT-SCOPE honored: delete guard moved to `ScheduleFactory::deleteRefusal`, same-root model deletes free, cross-root model rows need `include_operator_rows`, operator sentence byte-frozen (closing C-AGT-5.a r1 MINOR-2).
- +8T/+25A closeout figure carry (suite-figure.json/README re-pin is GATE duty; welder never touches it).

## Ceremony

- `weld: C-AGT-5.b` commit = §4 ✅ mark (full pick hash `934488495…`) + `lanes/C-AGT-5.b/{BRIEF,REPORT,REVIEW-1,REVIEW-2,WELD}.md` + run-state.json (inflight `C-AGT-5.b` removed → welded, `welded_count` 152→153, tip → pick hash).
- `git cherry master cc/C-AGT-5.b` → `-` proof, then worktree `/home/sites/cc-cmp-C-AGT-5.b` + branch `cc/C-AGT-5.b` pruned.
