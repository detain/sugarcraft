# WELD — C-CTX-3.d (batch-12, 2026-10-28) — WELDED onto master 965be930f (post lane-1)

- Pick-map (`git cherry-pick -x`): e08dddd3e → ffafa16ae, f0f2a0922 → 8754885cb (§4 cites it).
  - ffafa16ae: README.md tools:roster GENERATED-region conflict (HEAD side carried batch-10
    SkillWorkshop + thirty-four) resolved `--ours`; fused by gen-tool-docs.php --write at weld
    (union line: roster keeps SkillWorkshop/thirty-four AND gains the lane's Memory recall gloss).
    docs/MEMORY.md auto-merged.
  - f0f2a0922: stale UNTRACKED disk REPORT.md blocked the pick; removed it (tracked superset
    wins; old disk copy archived /tmp/opencode/cc-compare/b12-disk-backup-CTX3d-REPORT.md).
    First --continue silently did NOT apply (reflog: sequencer empty at ffafa16ae, test-method
    grep 0) — re-picked cleanly: 3 cap-edge test methods + tracked REPORT landed.
- Welder cures (review carries), commit de0c54646 `weld-cure:`:
  - NIT-1: MemoryTool.php withShellOwner docblock truth — Null = a caller the engine has not
    bound THIS turn (standalone build; launch-built rows until the per-turn bind), not
    "every launch-built or standalone caller".
  - NIT-3: TaskTool.php delegation-side `new MemoryTool(...)` now passes $memoryRoot as the
    tool root so a child's `sessions` recall scopes the delegation's own project root.
  - Gate after cures: MemoryTool|MemoryRecallSources|TaskTool|CompactionJournal|SessionSearch
    = 236T/980A/0F OK.
- Gates on merged tree:
  - MemoryRecallSourcesTest: 14T/81A/2D EXACT green.
  - Reviewer narrow battery (MemoryTool|MemoryRecallSources|SessionSearch|CompactionJournal|
    DreamPass|DreamMemoryView|TaskToolAgentMemory|HostCompactionMemoryFlush|
    MemoryFlushBeforeCompaction|ToolDocDrift|ReadmeRosterDrift|DocFigureProseDrift):
    252T/4817A/1F — sole red DreamPassTest:288 standing foreign (base-proven at 237224a7a).
    A-count +26 vs reviewer's 4791 = merged-tree drift, T exact.
  - WIDE `Memory|Recall` band (prompt shorthand, wider than the reviewer's list) additionally
    surfaces MemoryPromptWiringTest (6E/1F, InstructionDiffTracker.php:337 null-$seen
    TypeError) + BootstrapSkillSkipsTest (3F, one-shot exit-code legs). PROVEN INHERITED:
    file-revert probe (all lane-2 touched files back to 965be930f) reproduces identical
    6E/1F + 3F; InstructionDiffTracker.php is not in the pick set at all. Foreign standing,
    disclosed, not chased.
- DUR mint: candy-code/tests/Tools/BuiltIn/MemoryRecallSourcesTest.php 0.29/14; monotonicity
  violations hold at 4.
- Figure carry: +14T suite-figure.json (§7 closeout duty; NOT re-pinned here).
- README regen (tool-docs) is part of this weld; gen-protocol-schema/gen-command-docs/
  gen-settings-doc all "up to date"; no Protocol/web paths touched → npm/dist skip
  (batch-11 precedent).
