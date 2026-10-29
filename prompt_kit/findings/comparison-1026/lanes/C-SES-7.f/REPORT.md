# C-SES-7.f — BUILD LANDED

Commit: 31691640f (full 31691640f479c47fb76dd07496327d997b8bfaae) on cc/C-SES-7.f, base e91eb1d47. %ae==%ce==detain@interserver.net (separate greps), zero Co-Authored-By, tree clean, NEVER pushed.

Files (9, +1176/−7):
- src/Backend/RemoteBackend.php +355/−2 (persistent host->onEvent foreign inbox keyed by turnId; queueRemoteExec/queueRemoteResult/takeRemoteResults/remoteFeedPending/drainRemoteFeed; runTurn admission/finish mirrors; FEED_PUMP_SECONDS=0.5, FEED_PROMPTS_MAX=64)
- src/Host/RemoteSessionHost.php +46/−1 (open() fetches command.list once → serverCommands() cache; failure = empty map = fail-open all-local)
- src/Chat.php +71/−0 ([chat:dispatchCommand] remote arm → command.exec, promise resolves to pump Msg both arms; [chat:subscriptions] 'remote.feed' idle-null tick arm; inline FQNs, zero use-block edits)
- src/Chat/Handlers/HandlesSessionSurface.php +38/−0 (RemoteFeedPumpMsg arm: applyCommandResult each exec result, then fold drainRemoteFeed rows)
- src/App/RemoteFeedPumpMsg.php +30 (payload-free Msg)
- lang/en.php +1 (host.command.exec_failed; consumer src/Chat.php; NOT on the dead-key list)
- docs/SERVER.md +12/−4 (attachment-limits paragraph flipped to the shipped truth)
- tests/Server/AttachRemoteCommandsTest.php +310, tests/Server/AttachForeignTurnTest.php +313

Gates (cwd worktree candy-code, env -u LINES -u COLUMNS plain pipe):
- php -l: all 9 files clean.
- New files: 8T/74A OK.
- AttachRemoteCommands|AttachForeign|RemoteBackend|AttachResume: 26T/201A, 1F = RemoteBackendTest::testAnExistingSessionOpensFromItsSnapshotByUniquePrefix — INHERITED (reproduces in untouched main tree @6742a2ad3; zero commits e91eb1d47..HEAD touch the code; session.list no longer resolves a closed live session).
- AttachPagingTest: 2F — same inherited session.list-visibility root, reproduces untouched.
- SlashDispatch|CommandMethods|ConformanceTest: 52T/652A OK.
- Guard family (ChatHostLangCatalogue|LangParity|ServerProtocolDoc|DocFigure|ReadmeSuiteFigure|TuiLang|CliLang): 129T/8964A, 3F = 2 ReadmeSuiteFigure staleness pair (expected, welder re-pin; base pair was ALSO red: 25537 vs 27946-before-my-8) + 1 ChatHostLangCatalogue 6-key dead-red (chat.startup.personal, chat.attach.pdf_pages, chat.effort.launch_unshaped, chat.permission.not_persisted, chat.permission.persisted, chat.serviceTier.launch_unshaped) — ALL SIX reproduce in the untouched main tree; my key is NOT among them.
- No schema/roster change → protocol trio untouched, nothing to restore.

Mutations (cp+md5 restores verified 3/3 each round):
- M1 neuter arm (`==='server'`→never-match): 3F (status/compact/busy) ✓
- M1b broaden arm (`!=='server'`): theme test red ✓
- M3 done-gate removal: mid-stream fold red ✓
- M4 pump-arm neuter: 4F (both folds + status + busy) ✓
- M2 own-skip guard removal: NOT discriminating — adoption branch + done-gate double-protect the law (redundant defense, disclosed).

Deviations disclosed: dispatchCommand now :11670 (block cited :11256); SERVER.md para was :1101–1105 (block cited :1011–1014); subscription id inline literal 'remote.feed' (class-const region undeclared); exec replies render on promise resolution, not one tick later; foreign turns fold AT COMPLETION (never row-by-row — pinned); payload-carrying effects (compact-transcript, set-model…) are not replayed window-side — wire carries kind strings only, server copy already mutated, next snapshot/resume brings them back; custom file/mcp commands unchanged (already server-side via submit()'s file branch); server serializes turns so a real overlap never streams foreign deltas during the own turn — the queue-send test pins the interleave law in its honest form; open()'s fail-open catch arm is untested (fabrication of a command.list failure needs a hostile server).

Welder carries: durations.tsv rows for the 2 NEW files; suite-figure re-pin +8T (plus the pre-existing era drift +2409 the battery already owed); the 3 inherited session.list reds + 6 dead-key reds predate this lane and belong to their owners/closeout, NOT to this pick.
