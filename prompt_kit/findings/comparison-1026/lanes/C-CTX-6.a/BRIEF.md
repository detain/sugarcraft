### C-CTX-6.a — Served idle alarm survives a running turn
- item: C-CTX-6 (sources: CTX-5-P1)
- kind: fix · MINOR · effort S
- approach:
  - Root cause: the served alarm is armed only on submit (`SessionHost.php:562`) as a one-shot `addTimer` (:656-661), and it returns on `isBusy()` (:678) with the timer already nulled and never re-armed. Idleness is measured from `lastPromptAt` (:704).
  - So a 65-minute turn loses the fold, and a 59-minute turn gets a billed fold a minute after it settles. The TUI re-checks every 60 s (`App.php:4691-4697`).
  - Fix:
    - stamp `lastSettledAt` in `settleWith()`;
    - measure idleness from max(prompt-accepted, turn-settled);
    - re-arm the alarm when it fires during a turn, and on every settle.
- files.create: none
- files.modify: `candy-code/src/Host/SessionHost.php` (`armIdleAutoCompaction()`, `onIdleAutoCompaction()`, `settleWith()`)
- tests: modify `candy-code/tests/Host/SessionHostIdleAutoTest.php`: a mid-turn fire re-arms; idleness counts from settle; a settle re-arms
- docs/guards: none
- serialized: [hot:candy-code/src/Host/SessionHost.php]
- deps: none
- phase: normal
- ruling: none
- risks: none.

