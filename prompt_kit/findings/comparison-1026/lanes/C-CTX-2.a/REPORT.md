# C-CTX-2.a — served runtime-notice drain — CLOSED

Commit: 0aea27278 on cc/C-CTX-2.a (base 86f43fae2). Author/committer
detain@interserver.net verified by separate greps; zero Co-Authored trailers.
Tree clean; never pushed.

## Shipped (src/Host/SessionHost.php only + docs + new test)
- Per-session inbox: `private readonly NoticeSink $notices`, armed in the ctor
  only when `$workspace->notices->isArmed()` (tests/embedders stay unarmed).
- using() windows: dispatch() wraps runner()->start() and $run()/settleWith
  registration (EngineBackend reads current() pre-fork inside the window);
  requestAheadOfNeed() wraps $request['promise']() (covers dispatch tier AND
  the CTX-5 idle-timer caller); scheduleGoalJudge() wraps the judge call.
- TurnRunner::start now receives notices: $this->notices (was
  $workspace->notices) — the drain owner passes its own sink (E199 rule).
- Drain points: ctor arms the one-shot loop wake; pumpRuntimeNotices() drains
  → appendMessageRow(Message::notice(...)) (uiOnly, saved, announced
  message.created kind=notice) and renews the wake on EVERY path incl the
  empty one (Chat's pinned cadence); pump() drains before its early return;
  settleWith drains after save(), before releaseQueue(); release() resets the
  sink (watcher cancelled, both socket fds closed). No timers minted (E646).
- Docs same commit: SERVER.md ### Events prose block (per-session notice rows;
  pre-host launch rows named as documented residual — generated table untouched);
  MEMORY.md recall-row sentence now names SessionHost::pumpRuntimeNotices()
  for serve. No drift guard moved (checked DocFigure/Memory/Server-protocol/
  ProtocolSchema arms — none pin the edited sentences; all green).

## Regression test
NEW candy-code/tests/Host/ServedRuntimeNoticeDrainTest.php — 6T/29A.
**Add a durations.tsv row at gate (DUR).** Proven RED at base (reverted src
file, test untouched): 4 errors + 2 failures; green at tip.
Pins: turn-recorded notice lands one uiOnly System row + durable
message.created kind=notice; per-session containment (two hosts, cross-absent);
unarmed workspace → host never invents an arm (no transport, no watcher);
wake drains record→renew→record→drain again incl empty pump() renewal;
release disarms/closes; structural no-addTimer scan of the two drain methods.

## Gates (cwd worktree candy-code, plain pipe, -u LINES -u COLUMNS)
- --filter 'ServedRuntimeNoticeDrainTest': 6T/29A OK.
- --filter 'ServedRuntimeNotice|SessionHost|RuntimeNotice': 131T/7226A OK.
- tests/Host tests/Server: 732T/6316A — 2F tests/Server/AttachPagingTest :94/:106,
  PROVEN base-inherited (fails identically with base SessionHost restored).
- Five-guard + doc guards: 153T, reds = the known staleness PAIR only
  (figure 25,537 vs live 26,099 — foreign lanes' un-repinned drift; closeout
  re-pin owns it).
- 2 PHPUnit deprecations tree-wide are foreign (AccessorDeprecationTest
  non-public providers, rulings-1024 era), untouched by this lane.
