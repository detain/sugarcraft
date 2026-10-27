# C-TOOL-7.a weld finding — ReadPathCensus roster left un-armed (MAJOR, guard red on master)

Found at Unit 4's gate (2026-10-27), NOT by Unit 3's own filter list.

`candy-code/tests/Support/ReadPathCensusTest::testTheCensusIsDerivedFromSrcAndFullyClassified`
is RED on merged master:

    -    'Background/BackgroundShellRegistry.php|scandir' => 2,
    +    'Background/BackgroundShellRegistry.php|scandir' => 4,

Attribution PROVEN (not foreign, not C-EXT-2.a):
* live `scandir` sinks in `src/Background/BackgroundShellRegistry.php` at 85afa9f90
  (pre-Unit-3): 2 — :408 own-dir, :839 /proc. Roster verdicts then: 2 → GREEN.
* at the welded chain: 4 — the launch-sweep adds :548 `@scandir(\dirname($this->directory))`
  and :568 `@scandir($path)`. `git diff --name-only 85afa9f90..72c0272d4` shows
  ReadPathCensusTest.php was NEVER touched by the chain.
* the only key in the census diff is BackgroundShellRegistry — C-EXT-2.a's own row
  (`Skills/SkillLoader.php|new DirectoryIterator`, second verdict entry) IS satisfied.

Violation: plan §1.8 in-step guard law ("census rosters … move in the SAME commit").
Cure owed (code/guard, lead's call — welder does not fix): add two verdict entries to the
`Background/BackgroundShellRegistry.php|scandir` key —
  :548 NAMES_ONLY (lists the registry root's sibling `launch-<pid>` dirs; the name feeds only
       a pid liveness gate + retention check, then a recursive descend, nothing admitted as content)
  :568 NAMES_ONLY (descend inside the launcher's OWN 0700 tree lists `sh-*.(out|err|exit|outd|errd)`
       names for an mtime check and an unlink; no content read follows the listing)
and re-check `--filter 'ReadPathCensus|PromptStability'` (36T) → expect 36T/1020A/0F.
Suggested adjudication wording should also state the containment leg: the sweep only ever
descends directories matching `launch-<digits>` under its own 0700 root.

Also owed by the same chain (lower risk, disclosure only): no new `proc_open` token, so
DescriptorInheritanceGuard 15T stayed green — verified at weld.

Welder status: Unit 3 (C-TOOL-7.a) was already evidenced/pruned when the red surfaced at
Unit 4's gate, so its §4 mark stands; Unit 4 (C-EXT-2.a) picks + cure are on master but its
EVIDENCE COMMIT AND §4 MARK ARE HELD, welded list stays 58, branch/worktree cc/C-EXT-2.a kept.
