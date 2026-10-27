# C-CTX-2.a — review round 1 — VERDICT: CLEAN (0C / 0M / 2 NIT)

Disk-verified: ref cc/C-CTX-2.a == 0aea27278, parent 86f43fae2, ONE commit. %ae/%ce separate greps both detain@interserver.net; zero Co-Authored trailers. Diff == exactly 4 files: SessionHost.php, docs/SERVER.md, docs/MEMORY.md, NEW tests/Host/ServedRuntimeNoticeDrainTest.php. No undeclared extras (no TurnRunner.php touch — its ?NoticeSink param pre-exists; no lang/Chat). All hunks inside the brief's declared regions + ctor/property/import lines. DUR row for the new test listed in REPORT.md per §1.5.

Scope/gate: host sink armed ONLY when $workspace->notices->isArmed() && arm() succeeds (ctor :301); test 3 pins unarmed → isArmed/hasTransport/isNotificationArmed all false (byte-shape preserved). using() windows verified at the three capture sites (:694, :2008/:2034 covering pre-fork current() read at EngineBackend :3848, :2125); grep proves zero RuntimeNoticeSink::drain/record calls from SessionHost — served rows cannot reach the process sink inside windows.

Drain law: pumpRuntimeNotices() (:945) = drain → appendMessageRow(Message::notice(r)) (uiOnly System; announceTranscriptRow emits message.created kind=notice via the pre-existing default arm) — shape identical to Chat::pumpRuntimeNotices (Chat.php:18924+). Renewal traced: armNoticeWake re-armed on EVERY pumpRuntimeNotices path incl. the empty one; notifyOnceWhenPending cancels-then-registers (no double-read throw). settle drains after save() before releaseQueue() (:2081); release() resets the sink first. E646 clean: wake-driven, no timers minted.

Docs truth: MEMORY.md recall sentence and SERVER.md Events paragraph match the code incl. the disclosed pre-host residual; protocol:events generated region untouched (§1.8 weld-judged); DocFigure/protocol guard greps show no arm pins the edited sentences — "no drift guard moved" holds.

Pins non-vacuous: base-red 4E+2F maps exactly onto member topology at 86f43fae2 (sinkOf/pumpRuntimeNotices reflection errors; 2 behavioral failures). Sentinels uniqid-family, no glob/**Refused:** shapes.

Gates: shell revoked mid-review — I could NOT re-run phpunit; builder claims (6T/29A, family 131T/7226A, Host|Server 732T/6316A with only the disclosed AttachPagingTest :94/:106 base-inherited 2F, five-guard = staleness pair only, figure 25,537-vs-26,099 closeout re-pin) accepted after line-by-line cross-check against the diff.

[NIT] step-local SessionHost drain pin — test 6 scans only pumpRuntimeNotices()+armNoticeWake() bodies; a timer re-introduced at a pump()/settle() drain call site would evade the pin.
[NIT] step-local test 2 — brief's "two hosts on one workspace" realized as two workspaces (behaviorally equivalent: WorkspaceContext::new defaults notices to the same process current()); consider a shared-workspace fixture later.

Ready to weld.

(Lead note: reviewer writes denied; text persisted verbatim from reply @1383@. Weld duties: plain pick 0aea27278, verify gen-protocol-schema --check up to date w.r.t. the SERVER.md prose hunks (generated regions untouched per review — commit only if the generator reports drift), gates --filter 'ServedRuntimeNoticeDrain|SessionHost' + family 131T-class + five-guard pair-only, disclose AttachPaging :94/:106 base-inherited, DUR list ServedRuntimeNoticeDrainTest.php (6T), §4 unstaged, evidence 3 files.)
