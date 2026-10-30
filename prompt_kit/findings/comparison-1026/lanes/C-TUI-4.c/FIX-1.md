# C-TUI-4.c FIX-1 (round 1) — statusLine session JSON on stdin (GAP-UI-23)

RESULT: BOTH MAJORs CURED + NIT-1 CURED; NIT-2 left by instruction and recorded
for closeout. Targeted gates green at the fix tip; 4/4 mutation proofs
discriminating, `cp` + `md5sum -c` restores verified, tree clean after each.

- Worktree `/home/sites/cc-cmp-C-TUI-4.c`, branch `cc/C-TUI-4.c`.
- r1 tip (parent): `de0f3a81befc4eaa590dbb5801425f1c04d7c6e2`
- **Fix tip: `33d93aca971db140db3811b654b8f68de175d66b`** (cat-file'd via
  `git log -1 --format=%H`; `%ae` grep-detects detain = 1, `%ce` = 1 read
  separately, 0 `Co-Authored` in the body). 5 files +186/−9. This FIX-1.md
  rides a follow-up commit (a commit cannot carry its own SHA).

## MAJOR-1 — the unpriced-session money lie
`src/Config/StatusLineSession.php`: `public readonly float $totalCostUsd` →
`?float`, the `@param` line flipped, and a new "WHY SPEND IS NULLABLE" class
paragraph naming `Renderer::spendIndicator()` as the gate being honoured.
`src/Chat/Handlers/HandlesRunControl.php` (gather): added
`$reported = $this->hasReportedSpend() || $turnUsage !== null;` — the bar's own
condition verbatim — and `totalCostUsd: $reported ? <the old sum> : null`.
So null arrives EXACTLY when nothing has been billed and no in-flight turn
carries a figure; a real zero after a priced turn still serialises as `0`.
Docs flipped in the SAME commit: `docs/SETTINGS.md` example JSON now shows
`"cost": {"total_cost_usd": null}` and the hand-written prose after it names
the spend case (and the "a zero after a priced turn is a measurement"
counterpart). That region sits OUTSIDE every generated marker
(`settings:begin` is at :1213), so it was hand-edited;
`php tools/gen-settings-doc.php --check` → "settings docs: up to date", rc=0.
Tests: the fixture's `$totalCostUsd` is now `?float`; the pre-session document
test passes `totalCostUsd: null` and pins both the decoded null and the raw
`"total_cost_usd":null` bytes; NEW
`testASpendNothingBilledIsAbsentFromTheDocumentAsANumber` pins the decoded null
plus an absence leg. `lang/en.php`'s help string was NOT touched (no cost-value
claim in it), so no Help/EnvRoster flip is owed.

## MAJOR-2 — production wiring now pinned
Two new methods in `tests/Config/StatusLineCommandTest.php`, mirroring the
existing `cat`-echo idiom (that file already carries a durations row, so no new
file → no new row minted here):
- `testTheTickDeliversTheSessionDocumentToAReaderCommand` — builds a real
  `Chat` (EchoBackend), configures `cat` through `StatusLineCommand::configure`,
  dispatches a real `StatusLineTickMsg`, drives the armed Cmd through the loop to
  its `StatusLineResultMsg` (bounded 20 s leash, same shape as
  `StatusLineSegmentTest::awaitSettled`, distinctly named `settleTick` for the
  helper-drift walk), folds the settle, then decodes `StatusLineCommand::line()`
  and pins the top-level key ORDER, `model`/`cost` key sets, `cwd` = the
  configured root, and `cost.total_cost_usd === null` on the live path.
- `testTheTickArmPassesTheGatherIntoTheArmedCommand` — source-slice legs: the
  tick arm contains `StatusLineCommand::cmd($this->statusLineStdin())` exactly
  once and a bare `cmd()` zero times; the gather carries the spend gate, the
  `$reported ?` ternary and the NIT-1 guard.

## NIT-1 — `""` cannot reach `cwd`/`project_dir`
Gather passes `projectDir: $this->projectRoot() ?: null` (`Chat::projectRoot()`
returns `''` when both the stored root and `getcwd()` fail), so the docblock's
"never the empty string" claim now holds. Pinned structurally.

## NIT-2 — OUT OF SCOPE, left untouched, FOR CLOSEOUT
`candy_code.context_percent` can be computed against `ContextWindow::FALLBACK_
TOKENS` (100k) while the payload ships no `context_limit` key, so a script
cannot tell a real window from the fallback. Same behaviour as the bar
(`Renderer.php:3300-3306`), so it is not a regression this lane introduced — it
needs its own ruling (add a `context_limit` key, or one SETTINGS.md sentence).
NOT cured here, per instruction.

## Gates (cwd `candy-code`, `env -u LINES -u COLUMNS vendor/bin/phpunit -c
phpunit.xml --filter …`, plain pipe; no full/serial suite; PtyRun never matched)
| gate | reviewer r1 | at fix tip |
|---|---|---|
| `'StatusLineSession|StatusLineCommand'` | 58T/175A OK | **61T/194A OK** (+3T/+19A) |
| `tests/Config/StatusLineSessionTest.php` alone | 8T/29A | **9T/33A** |
| `tests/Config/StatusLineCommandTest.php` alone | 48T/134A | **50T/149A** |
| `--filter StatusLine` (adds Segment + Bootstrap-wiring families) | not run | **101T/4385A OK** |
| `'SettingsSchemaDocDrift|EnvRosterDrift|GlobDialect|ReadmeRosterDrift'` | 80T/40,914A OK | **80T/40,914A OK** (byte-identical) |
| `php tools/gen-settings-doc.php --check` | not run (map denied) | **rc=0** |
| `php -l` every touched file | — | clean (5/5) |

2 PHPUnit deprecations ride every window — base-tree, per r1.
Standing reds hit in passing, NOT mine: `DuplicatedTestHelperDrift::
testNoDuplicatedConstIsUnrecordedInEitherPolity` (HEAVY_CHARS pair) and
`TreeWideGuardRosterTest` x2 (`Cli/NonInteractiveSessionPersistenceTest.php`
RecursiveDirectoryIterator + `Cli/ServeArgsTest.php` scandir) — all three
REPRODUCED BYTE-IDENTICAL at my cut base `88118dfce` in a throwaway worktree
(since pruned), so they are foreign-inherited. The standing list names the
ServeArgs row; the `NonInteractiveSessionPersistence` row is an adjacent
foreign addition the welder may want to roster on its owning lane's behalf.

## Mutations (each `cp`-restored, `md5sum -c` OK; tree verified clean)
- **M1** revert nullability (`?float` → `float`): 3 errors —
  `testTheTickDeliversTheSessionDocumentToAReaderCommand`,
  `testAnUnknownSessionStillAnswersACompleteDocument`,
  `testASpendNothingBilledIsAbsentFromTheDocumentAsANumber`.
- **M2** delete the tick arm's argument (`cmd($this->statusLineStdin())` →
  `cmd()`): exactly the 2 NEW pins red — behavioural + structural. This is the
  regression r1 said was unpinnable; it now cannot survive.
- **M3** gate passes `0.0` instead of `null`: exactly 1 red —
  `testTheTickDeliversTheSessionDocumentToAReaderCommand`, i.e. only the
  production path can see this difference (the unit fixtures pass the value
  explicitly), which is the argument for MAJOR-2's existence.
- **M4** revert NIT-1 (`?: null` dropped): the structural leg reddens (the
  behavioural red in that same run is M3's carry-over — M4 was applied without
  first restoring M3; M4 alone is caught by the source-slice leg).

## Weld carries (restated + updated for this round)
- NEW durations row: `candy-code/tests/Config/StatusLineSessionTest.php` —
  **9 tests** (final count).
- EXISTING row `scripts/parallel-tests-durations.tsv:43`
  (`candy-code/tests/Config/StatusLineCommandTest.php 6.432 41`) → **50 tests**
  now (r1 tip had 48; +2 here, both spawn-driven `cat`/fork cases). File NOT
  edited (lane-forbidden) — re-time it.
- `candy-code/tests/Renderer/StatusLineSegmentTest.php` untouched (:329, 25 tests).
- Figure delta, whole lane vs master: builder r1 measured **+15T/+85A**; this
  round adds **+3T/+19A** → **+18T/+104A**. r1's note that the lane REPORT.md
  said "6 new tests" where the file had 7 is corrected: the TRUE new-method
  count is **7 in StatusLineCommandTest** at the r1 tip (→ 9 now) and **8 in
  StatusLineSessionTest** at the r1 tip (→ 9 now). Count from the tree.
- No roster row owed: zero new `proc_open` sites, zero new read sinks, zero new
  emitters/sprintfs, no new env var (so no `Help.php`/`lang/en.php` roster flip
  and no `EnvRosterDrift` consequence — the flipped `settings.statusLine.help`
  string is a value-only edit to a pre-existing key).
- E646 honoured: no `sleep`/`usleep`/`stream_select`/Timer added to any src
  file; `$deadline = microtime(true) + self::TIMEOUT_SECONDS;` byte-untouched.
  The only new timer is the test-side 20 s leash inside `settleTick`.
- SETTINGS.md change is in the hand-written region only; nothing regrew, so a
  cherry-pick alongside C-SEC-7.a / C-CTX-4.e text-merges. No drift from those
  lanes observed in my copy at cut time.
