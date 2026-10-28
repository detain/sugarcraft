# P-AGENT.b WELD (batch-3, welder tick-128, 2026-10-29)

- Picks: 19830f687 → f1ec9a0d7; 552cc4e71 → 9fc915cd9 (§4 cites tip master-hash 9fc915cd9 = pick of lane tip 552cc4e71).
- Cherry-pick onto e14c3cc7c: ZERO conflicts. Both patch-ids SAME (git show | git patch-id). HandlesRunControl.php union with C-CTX-5.c (878047472) confirmed disjoint by review and re-proven behaviorally below.
- Merged-tree gates (cwd candy-code, plain pipe, env -u LINES -u COLUMNS, -c phpunit.xml):
  - `--filter '(KeyBindingDrift|KeyBindingRegistry|AgentShellCommands|AgentComposer|AgentView|AgentWindowViewport|AgentInterruptSend)'` = **361T/5065A OK** EXACT vs REVIEW-2 (2 deprecations = base-present AccessorDeprecationTest noise).
  - C-CTX-5.c re-run duty: `--filter Idle` = **256T/20371A OK** EXACT; `--filter CacheTtlIdleFoldTest` = **14T/59A OK** EXACT.
  - `--filter KeyHelpTest` = **63T/5F**, failure names exactly the 5 disclosed standing base reds (SlashHelpNoLongerOpens, FooterSaysThatTheSecondQuestionMark, FooterFitsTheBox, ScrollingThroughItShowsEveryLiveBinding, ClippedKeyHelpFieldEndsWithAnEllipsis); mutation-domain roster test GREEN on merged tree (duty 4: 878047472..HEAD added no un-rostered message-key constructors).
  - `--filter KeyBindingDrift` = **224T/1836A OK**.
- durations.tsv (scripts commit): +2 rows — tests/App/AgentWindowViewportTest.php 0.366/5 + tests/Agents/AgentInterruptSendTest.php 0.015/4 (standalone junit /tmp/opencode/cc-cmp-agtb-weld.xml per-file sums; desc-invariant insert; tsv 1550→1552, asc-violations 4==4).
- Regen: none (zero src/Protocol/**, zero web paths; en.php/docs are soft-doc, carried both-sides in-pick).
- Carry: suite-figure +14T (lane claim; live-enum truth rides closeout re-pin); ReadmeSuiteFigureDrift staleness pair standing.
- Stash: cc/P-AGENT.b stash@{0} (verified dup of commit) dropped post-pick; stash@{1} (C-CTX-5.c re-registered) + stash@{2} (foreign) left untouched.
- run-state: C-AGT-4.b unblocked (its Deps named P-AGENT.b).
- Absorption: git cherry master cc/P-AGENT.b = '-' '-' (both), patch-ids SAME → worktree + branch pruned.
