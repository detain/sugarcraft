# BATCH-WELD-18P — recovery log
RE-ANCHORED: C-AGT-4.d was welded by batch-18o (master tip 7342d0e95) — dropped from this batch, picks reset.
Scope now: ONLY (a) C-SEC-7.c@4b678a480 (r2 CLEAN) + (b) C-AGT-5.f@7d5eeda73 (r3 CLEAN).
Weld worktree /home/sites/cc-cmp-weld-18p branch weld-18p. NO PUSH.

## Pick chains (re-derived git log 7342d0e95..cc/<step>)
1. C-SEC-7.c: 2bc6bb703 -> 873354b78 -> 4b678a480
2. C-AGT-5.f: 07ea10067 -> 4c1dbe12d -> cdbd0e488 -> 7d5eeda73

## Carries
- DUR rows: candy-code/tests/Backend/PlanModeToolRosterTest.php + candy-code/tests/Automation/OutboundWebhookWiringTest.php (derive from green run at tip; r3 says live 11T/59A for webhook, NOT ledger 15T/42A)
- +11T re-pin note (C-AGT-5.f); PlanMode family re-pin note (closeout duty; suite-figure.json NOT touched here)
- COMPARISON.md :383/:938/:3545 stale 'same in every mode' quotes = CLOSEOUT carry (do NOT edit COMPARISON.md)
- Protocol: C-AGT-5.f touches src/Protocol/ServerContext.php + SERVER.md prose => regen protocol trio + web build per weld law; verify no schema drift
- composer install SEGFAULTS in this worktree (rc=139, 3x); plan: copy main-tree linked vendor (same-era, 23 pkgs) into weld worktree

## Status log
- [x] reset weld-18p to 7342d0e95
- [x] picks: C-SEC-7.c 2bc6bb703->e850dd9a4 873354b78->62528ab70 4b678a480->7516e7e42; C-AGT-5.f 07ea10067->35174c5ea 4c1dbe12d->ec2f94cd7 cdbd0e488->aa1321be3 7d5eeda73->b8abbacd4 — ALL zero-conflict
- [x] vendor: cp -a from main tree (composer install segfaults in fresh worktrees, rc=139); linked symlinks resolve within weld tree
- [x] regen: gen-protocol-schema --write 'up to date'; gen-command/tool/settings-doc --write all 'up to date'; NO web touches in batch => no npm/dist rider. Protocol trio byte-clean.
- [x] gates @tip (plain pipe, cwd=candy-code): lane filter PlanMode|TurnControllerMcpSeam|OutboundWebhook|BackgroundEvents|Automation|Permission = 2411T/13476A 2F (BOTH foreign-inherited: PermissionsIntroDriftTest::testThePatternToolRosterIsTheWholeCatalog + ForeignAgentPresetWiringTest::testNoSourceFileOutsideAgentReadsAnAgentsPermissionMode — proven RED at base 7342d0e95 with byte-IDENTICAL diff shape, /tmp/opencode/weld18p-reds-{base,tip}.txt). PlanMode family 159T/293A green; OutboundWebhook 44T/124A green. five-guard = 134T/17182A, failures EXACTLY staleness pair (pinned 25,537 vs live 28,402, red-by-design).
- [ ] CARRIES for closeout: DUR rows (from green run at tip): PlanModeToolRosterTest.php + OutboundWebhookWiringTest.php (r3 live shape); +11T re-pin note; COMPARISON.md :383/:938/:3545 stale 'same in every mode' quotes stay CLOSEOUT carry (not edited here).
- [x] §4 marks: C-AGT-5.f (->b8abbacd4), C-SEC-7.c (->7516e7e42) flipped with batch-18p pick-map notes
- [x] evidence committed: lanes/C-SEC-7.c + lanes/C-AGT-5.f full sets + this log
- [x] run-state: welded 236->238 (SEC-7.c, AGT-5.f); weld_pending -= {SEC-7.c, AGT-5.f, AGT-4.d-stale-remnant}; inflight = lead brief dict (7 lanes); tip stamped 7342d0e95 pending meta stamp commit; batch18p_note added
- ff attempt expected BLOCKED: main tree dirty run-state.json (lead live) — stop+report per brief, lead completes ff
- [x] meta stamp: tip=10950ade2, tick batch18p
