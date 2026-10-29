# WELD — C-AGT-4.b (batch-15, 2026-10-29)

Pick: c4fb3580f -> 5e30f5e94 (cherry-pick -x, ZERO conflicts on master 6742a2ad3). Author/committer detain@interserver.net (separate greps), zero Co-Authored.

Collision pre-check (lead-flagged): C-AGT-4.b vs already-landed C-AGT-5.b/5.c in the Chat/agents neighborhood auto-merged. Content-verified BOTH intents live on the merged tree: Chat.php carries the lane's handleAgentsCommand/cyclePrimaryAgent machinery AND the landed SchedulesCommand permission_mode write; KeyBindingRegistry/HandlesKeys arms green (registry count 155 in-step rode the pick). No design re-decided.

Gates (cwd candy-code, linked vendor via refresh-deps --mode=linked --libs=candy-code, env -u LINES -u COLUMNS plain pipe):
- PrimaryAgentSwitch 11T/35A EXACT vs REVIEW-1;
- AgentsCommandTest|SessionHostCommandsTest|AgentsNewAcceptTest 65T/272A EXACT vs REVIEW-1;
- KeyBindingDrift|KeyBindingRegistry|CommandDocsDrift 265T/3152A EXACT vs REVIEW-1;
- generators --check all rc0 (command/tool/settings/protocol); no regen commit owed.

Standings NOT chased (content-proven foreign: none of these files sit in either pick):
- MemoryPromptWiringTest::testAnAgentScopeNoteDoesNotReachThePrompt — InstructionDiffTracker null-$seen 1E (logged standing since batch-12; fixer's live edits visible as main-tree dirt);
- ForeignAgentPresetWiringTest::testNoSourceFileOutsideAgentReadsAnAgentsPermissionMode — roster lacks Host/Commands/SchedulesCommand.php, the C-AGT-5.c-era write (reproduces in untouched main tree at 6742a2ad3 — I verified).

Carries minted here: durations.tsv row PrimaryAgentSwitchTest 0.51/11. REVIEW-1's suite-figure/README re-pin carry stays section-7/battery duty (staleness pair stands red pre-existing on master — the era drift 25,537-vs-27,9xx is owed by the battery, not this weld). REVIEW-1 carries for C-AGT-4.c (wire the primaryAgentOverride reader) unchanged.
