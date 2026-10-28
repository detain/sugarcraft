### C-CTX-2.e — The TUI settle consumes the same gate
- item: C-CTX-2 (sources: GAP-MEM-02w ("one decision table, two hosts"))
- kind: dormant-wire (consolidation) · effort S
- approach:
  - Replace the inline gating at `HandlesTurnCompletion.php:304-336` with `TurnSettleWork::plan()`. Chat wraps each closure in `Cmd::promise`, and the landers stay unchanged.
  - `Chat::scheduleAutoCommit()` and `schedulePromptSuggestion()` delegate to the plan.
  - Chat's duplicate auto-commit body is removed. It was moved to `AutoCommitter::turnCall()` in C-CTX-2.b, so nothing is dropped.
  - Behaviour is unchanged; the point is that the two hosts can no longer drift.
- files.create: none
- files.modify: `candy-code/src/Chat/Handlers/HandlesTurnCompletion.php` (AssistantMsg settle block), `candy-code/src/Chat.php` (`scheduleAutoCommit()`, `schedulePromptSuggestion()`)
- tests: modify `candy-code/tests/Chat/AutoCommitTurnTest.php` and `candy-code/tests/Chat/AutoMemoryConsolidationWiringTest.php`. Both stay green, and one parity assertion checks that the TUI and `SessionHost` schedule the same set for the same inputs.
- docs/guards: none
- serialized: [chat:scheduleAutoCommit, schedulePromptSuggestion] [hot:candy-code/src/Chat/Handlers/HandlesTurnCompletion.php]
- deps: C-CTX-2.b
- phase: normal
- ruling: none
- risks: the batch order must stay `doneCmd, suggest, consolidate, autoCommit, dream, judge, notify`. The goal judge and notify keep their gates.

