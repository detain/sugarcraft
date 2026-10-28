# C-TUI-3.c — REVIEW-1 (round 1, independent reviewer)

**VERDICT: APPROVE — 0 critical / 0 major / 0 minor.**
Lane tip 4ccfb9ce46a720219f58d2c49c08d47a7d9b600c (parent 878047472), branch cc/C-TUI-3.c, worktree /home/sites/cc-cmp-C-TUI-3.c. Ready to weld.

## (a) Spec fidelity — PASS (mapping re-derived from real store code, REPORT trusted nowhere)

- `EnhancedSessionStore::listCheckpoints` (src/Session/EnhancedSessionStore.php:3091): SQL `ORDER BY "index" DESC` + `LIMIT`, undone=0 → **newest-first**, redo-stack rows excluded.
- `Checkpoints::rewindConversation` (src/Host/Commands/Checkpoints.php:68): `$checkpoints = $store->listCheckpoints($sessionId, $stepsBack)` then `$target = $checkpoints[min($stepsBack, count($checkpoints)) - 1]` — the LIMIT-k newest-first slice's index k−1 is the k-th-newest live checkpoint. The picker's row at 1-based newest-first position k ⇒ `stepsBack = k` maps EXACTLY onto that target (and on the fewer-checkpoints clamping, min() side included, because both sides read the same undone=0 DESC list).
- Consumer chain verified end-to-end: `RewindCommand.php:55-56` passes `$stepsBack ?? 1` straight into `rewindConversation` — the 3.d `Chat::rewindToCheckpoint(int)` door will receive the identical count. `Accept(stepsBack)` semantics are the same positions `/rewind n --chat` counts.
- Brief shape all present: pure widget (`update(KeyMsg): [self, ?MessagePickerAction]`, Escape→`close()`, Enter→`accept(stepsBack)`), rows newest-first, filter-as-you-type via `mb_stripos` containment with highlight reset, `view(cols, rows, Theme, shellChromeCols=0)`; `overlayGeometry` byte-identical to `HistorySearch::overlayGeometry`; `MessagePickerAction` follows `DirectoryPickerAction` closed-kind shape; `OpenMessagePickerMsg implements Msg` follows `OpenSettingsMsg`.
- Filtering keeps ORIGINAL stepsBack (rows carry it; matches never renumber) — the design's core subtlety, correctly implemented and pinned (see vacuity).

## (b) Vacuity pass on the 9 tests (90A) — PASS

Empirical mutations run in a /tmp hardlink copy (lane tree verified untouched, sha1 of the file == commit blob, `git status --porcelain` == 0):
- **M1 — `open()` off-by-one (`$stepsBack = 0`):** 2 red — `testRowsAreNewestFirstAndNumberedByRewindPosition` + `testAcceptReportsTheRowsStorePositionNotItsPlaceInTheHitList`. ⇒ **the accept-index-through-filter pin does NOT survive an off-by-one; it catches it.**
- **M2 — accept reports hit-place (`$this->selected + 1` instead of `$row['stepsBack']`):** exactly 1 red, the named target pin. Fully discriminating.
- **M4 — `overlayGeometry` clamp broken (`$inner` not `$inner - 4`):** `testViewKeepsEveryLineInsideTheTerminal` red ⇒ the 40/60/80/120 sweep asserts real geometry (per-line SGR strip + `Width::string <= cols`; the `width+4` rule line sits exactly at the boundary at cols=40, so the sweep is live on the edge).
- M3 — `fit()` clip neutered: green — NOT a test gap: `Style::width()` hard-clips the box output, so the user-observable contract is unchanged; `fit()` is defense-in-depth with no independently observable behavior to pin. Recorded as informational only.
- Empty-session test is non-vacuous (pins quiet nav/backspace/Enter + named empty state + Escape still closes).

## (c) Scope — PASS

Exactly the brief's 5 files (+563, one commit): `MessagePicker.php` / `MessagePickerAction.php` / `OpenMessagePickerMsg.php` created under `src/Tui/MessagePicker/`, `tests/Tui/MessagePicker/MessagePickerTest.php` created, `lang/en.php` modified ONLY: 7 keys `tui.msgpicker.*` soft-appended under the builder's own prefix adjacent to the `tui.history` block. All 7 have requesters in `MessagePicker::view()` in the SAME commit (no dead keys); no src code outside the new dir edited (0 existing-src modifications). `tools/check-one-type-per-file.php`: 62 libs / 3242 files / 0 problems. `OpenMessagePickerMsg` has ZERO callers repo-wide — confirmed by grep: it is the C-TUI-3.d wiring door, dormant-by-design per the plan's step split; **not** a reachability finding at this step. No `intdiv` use-import — matches `HistorySearch.php:257` convention exactly (no style deviation). Never committed (by §1.5 law): suite-figure.json, durations.tsv, dist/, protocol files.

## (d) Gates reproduced + laws — PASS

All runs cwd `candy-code` (lane worktree), `env -u LINES -u COLUMNS` plain pipe, `-c phpunit.xml`:
- `tests/Tui/MessagePicker/MessagePickerTest.php` → **OK (9 tests, 90 assertions)** — REPORT figure exact.
- `LangParityTest + TuiLangTest + GlobDialectDifferentialTest + GlobFigureDriftTest` → **OK (91 tests, 7353 assertions)** — glob corpus figures undrifted (the new `/\x1b\[[0-9;]*m/` and `/[\x00-\x1f\x7f]+/u` regex literals are `;`-exempt from the glob harvest; §1.9 clean, verified empirically not just by grep).
- `ChatHostLangCatalogueTest` at tip → fails **only** `testEveryCatalogueKeyIsAskedForByChatOrHost` with exactly the 5 stranded chat.* keys the REPORT disclosed. **Confirmed pre-existing at 878047472:** all 5 keys present in the parent's en.php (`git show 878047472:... | grep -c` = 5) and the lane's diff adds no chat./host. key and no requester file inside the guard's scan set (its src/Chat.php + src/Host family is byte-untouched). The guard does NOT accuse the new tui.msgpicker keys (they have same-commit requesters and sit outside its prefix scope; TuiLangTest green). Seam belongs to its owning lane/closeout, not this step.
- Author law: `%ae` grep -c detain = 1, `%ce` = 1 (separate greps), zero Co-Authored-By.
- Phantom law: tip ref resolves, parent is master's `878047472 C-CTX-5.c`, every claimed file read on disk, every REPORT figure re-derived true.

## Notes for the lead

- Disclosure (reviewer harness, no lane impact): my first mutation round used hardlink staging + `write_text`, which write-through leaked the 3 mutations onto the lane file. Caught same-session via an inconsistent M2 signature; repaired with `git checkout --` of the single path; verified sha1 == commit blob 9461ec31…, porcelain clean, and all mutation rounds re-run correctly in an isolated-inode tree. The tip object was never altered; the weld sees the pristine commit.

## CLOSEOUT-ONLY

- NEW test file for the durations TSV (gate duty, lane could not touch it per §1.5): `candy-code/tests/Tui/MessagePicker/MessagePickerTest.php` (9T, ~0.2s — one shard row).
- suite-figure.json/README re-pin carry: +9T / +90A from this lane (live-enumeration authority at weld, not lane arithmetic).
- Base-red seam carried for the owning lane/closeout glance: `ChatHostLangCatalogueTest::testEveryCatalogueKeyIsAskedForByChatOrHost` — 5 stranded chat.* keys (chat.startup.personal, chat.attach.pdf_pages, chat.effort.launch_unshaped, chat.permission.not_persisted, chat.permission.persisted) stranded already at 878047472; C-TUI-3.c adds none of them and its keys are clean.
- No doc rows owed by this step (pure core; README/docs land with C-TUI-3.d per the design block).
- /tmp/opencode/cc-cmp-lanes/C-TUI-3.c/mut — reviewer probe tree (hardlinks+symlinks, zero disk weight); disposable anytime.
