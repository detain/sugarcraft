# WELD — C-EXT-3.e (batch-10, 2026-10-28)

- Picks: f18b8cd86 -> cf017b14c, c607a755c -> 985c84e07, b7f980576 -> fdfb6ad96
  (r2 CLEAN 0C/0M/0m/1n, REVIEW-2 canonical; REVIEW-1 rode b7f980576; chain includes the
  post-r1 hygiene commit c607a755c reviewed in r2 — dated note in §4).
- Conflicts: docs/COMMANDS.md /skills-row vs /instructions-row region (both-sides fused, the
  lane's extended /skills grammar won its own row). b7f980576 adds tracked lane BRIEF/REPORT/
  REVIEW-1; identical-on-disk untracked copies moved aside (backup /tmp/opencode/cc-compare/
  b10-disk-backup), committed versions land (committed REPORT is a strict superset with the
  FIX ROUND 1 section).
- Gates at merged tree: trio filter 33T/216A EXACT; tests/Skills 616T OK; guard family +
  census battery 206T/57257A/2F = standing-only pair (PAGE_QUOTES + OneSidedHome) — matches
  the lane's expected 2 reds; the transient 3rd red was C-AGT-5.d's, removed with that lane.
- DUR MINT x3: LearnedSkillsTest 0.101/18, SkillWorkshopTest 0.037/8, SkillsHistoryUndoTest
  0.045/7 (per-file junit sums; T counts match brief exactly).
- +33T figure note (closeout re-pin). §4: ✅ fdfb6ad96. NIT commit-prose drift = closeout.
- Absorption: git cherry '-' x3 + patch-id proof; worktree+branch pruned.
