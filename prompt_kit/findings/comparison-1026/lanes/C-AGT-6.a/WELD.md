# C-AGT-6.a WELD (batch-5 lane 8) — 2026-10-28

Lane: cc/C-AGT-6.a @ 96f14da66 (parent c4c13f101), REVIEW-1 CLEAN 0C/0M/0 step-local.

## Pick
- `git cherry-pick -x 96f14da66` -> master **7e322e44d**.
- ONE conflict: `candy-code/lang/en.php` EOF block (HEAD tui.diffreview vs lane commit block).
  Resolved KEEPING BOTH (append-merge, both blocks live); `php -l` clean; no key collision.

## Gates (merged tree, cwd candy-code, plain pipe)
- `--filter 'BuiltInCommands|CommandDocsDrift|ReadmeRosterDrift|ForeignCommandDiscovery|PaletteSupersetDispatch|InFlightInputQueue'`: OK 73T/1541A (+2T/+12A derived-row growth vs reviewer's 71T/1529A — merged-batch providers; 0F/0E).
- tests/Host/Commands alone OK 140T/2046A + tests/Workspace/AutoCommitterTest.php OK 15T/214A (reviewer's 146T composition differs by batch-5 merged dir growth; every leg 0F/0E).
- `--filter 'CommitHostCommandTest|AutoCommitterTest'`: OK 22T/313A EXACT (7+15, per REVIEW-1).
- PaletteSuperset 'commit' row order-correct (2850 < 2860) green inside the 73T; ForeignCommandDiscovery R7 temp-repo plant green.
- gen trio `--check`: command/tool rc0; settings reported README stale — see rider.

## Weld scripts commit 9b1853cc1
- durations.tsv row CommitHostCommandTest 1.770/7 (junit-measured merged tree; duration-sorted pure +1, tsv 1563 rows).
- candy-code/README.md settings-numeral rider 134->135: gen-settings-doc --write regen.
  Attribution (git log -S proof): the numeral change owed to the diffStyle addition at pick
  18822cc20 (C-TUI-2.c) was NEVER committed — batch-5 step-3 record claimed the rider landed;
  disk says it did not. Cured here; SETTINGS.md was already consistent (135). Post-cure: all
  three generator --check rc0.

## Carries
- Closeout note (REVIEW-1 [2]): `AutoCommittedMsg::notice()` unconditional "/undo reverts it."
  wording inherited by the TUI /commit row — judgment (ii) ACCEPTABLE; notice()-over-promise
  note stands for the §7 closeout.
- Out-of-scope base-red noted by reviewer: ChatHostLangCatalogueTest::testEveryCatalogueKeyIsAskedForByChatOrHost
  red at lane tip from PRE-EXISTING keys (chat.startup.personal, chat.attach.pdf_pages,
  chat.effort.launch_unshaped, chat.permission.*) — not introduced here; wave-loop glance owed.

## Absorption + figure
- +10 methods carry (7 new file + 3 AutoCommitterTest) for the §7 re-pin.
- git cherry: both minus? (single pick) — `git cherry master cc/C-AGT-6.a` '-'; patch-id SAME.
- Worktree ../cc-cmp-C-AGT-6.a + branch cc/C-AGT-6.a pruned post-proof.
- §4 marked: `#### ✅ 7e322e44d C-AGT-6.a — /commit with a drafted message`.
