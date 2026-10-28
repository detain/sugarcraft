# FIX-1 — C-AGT-5.a round-2 fixes (2026-10-28)

Before tip: d9784ce96 → after tip: 3d1cf8626 (amended, same subject + round-2 line; branch cc/C-AGT-5.a, worktree /home/sites/cc-cmp-C-AGT-5.a, base cc14518d5 unchanged).

## MINOR-1 — FIXED (ghost row after persist-failure refusal)

- Fix landed in `candy-code/src/Background/ScheduleFactory.php::create()` (the step's own file per BRIEF files.create, and the exact locus REVIEW-1 prescribed — `$row->name` exists only in the factory): `add()` followed by try/catch around `persist()`; on `RuntimeException` the factory now `$registry->remove($row->name)` and rethrows, so the tool's refusal wording rides the exception byte-untouched while the roster matches the file. A factory-side placement (vs the tool catch) also protects the future `/schedules add` caller (C-AGT-5.b) that routes through the same single creation path, and cannot mis-delete on the name-clash arm (clashes raise InvalidArgumentException from add(), before any rostering).
- The tool's `@throws` docblock line updated to the truth: RuntimeException = failed write-back (lock contention, unwritable home) with the row withdrawn before escape.
- Pin: `ScheduleToolTest::testAPersistFailureWithdrawsTheRowSoTheListNeverLies` — self-process flock on `schedules/.file.lock` forces the claim race to lose; asserts refusal wording (`another host is writing schedules.json`), `list` absent of the row (the ghost line the unfixed tree prints), and schedules.json never written.
- Proof: red-before = committed factory + new pin → exactly the ghost-listed failure (`[on] (file) ghost-shift — every 1h...`); green with fix. Mutation = remove()-neutered factory → pin reddens again. cp harness under /tmp/opencode/C-AGT-5a-fix/, md5 restore verified (62e191999c770a46fdeb644843f80535), no git stash.

## MINOR-2 — NOT TOUCHED (per instruction)

Delete cross-root scope = owner-ruling carry, already recorded in run-state weld_notes. `deleteRow()` untouched; code left as-is.

## Gates (cwd candy-code, plain pipe, env -u LINES -u COLUMNS, PtyRun-never)

- `--filter 'Schedule|ScheduleFactory'` = 154T/689A/1F — the 1F is DreamPassTest:288, foreign per REVIEW-1 (fails isolated and on main tree without lane files); delta vs builder's 153T/682A/1F is exactly +1T/+7A (the new pin).
- `ScheduleToolTest|ScheduleFactoryTest` alone = 27T/103A 0F (was 26T/96A).
- `--filter ForeignAgentPresetWiring` = 17T/57A 0F; `--filter LangParity` = 7T/2839A 0F (2 pre-existing deprecations).

## Provenance

Single amended commit 3d1cf8626; diff vs d9784ce96 = only the two files (+49/−2), 16-file set otherwise identical; zero suite-figure/durations/dist touches; `%ae`=detain×1, `%ce`=detain×1 (separate greps), zero Co-Authored/Caliber lines; porcelain clean; never pushed.
