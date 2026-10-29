### C-PRV-3.c — `ultrathink` raises effort for one turn
- item: C-PRV-3 (sources: GAP-MOD-N1 (sic: CC-new#7 effort keyword), CC-new#7)
- kind: gap · effort S
- approach:
  - A typed prompt that contains the word `ultrathink` (case-insensitive, whole word, outside code fences) raises effort for that dispatch only.
  - `TurnRunner::start()` serves both hosts (Chat at `Chat.php:12731`, SessionHost at `SessionHost.php:1785`). It reads the latest typed User row through a pure `Providers\EffortKeyword` detector.
  - When `EffortGate::verdict()` says the pair shapes effort, the runner applies `$backend->withReasoningEffort(<top tier>)` to that run only. C-PRV-5.a's clamp keeps the top tier legal per family. Nothing is persisted, and tool continuations of the same turn keep the tier.
  - On a non-shaper it records one uiOnly row: "ultrathink: <provider>/<model> has no effort control; sent at the normal tier".
  - The keyword stays in the prompt text (Claude Code parity).
- files.create: `candy-code/src/Providers/EffortKeyword.php`, `candy-code/tests/Host/UltrathinkTurnTest.php`
- files.modify: `candy-code/src/Host/TurnRunner.php` (`start()`)
- tests: UltrathinkTurnTest: a keyword turn carries the top tier and the next turn does not; a non-shaper gets one notice; a word inside backticks is ignored.
- docs/guards: `candy-code/docs/SETTINGS.md` hand prose beside `reasoningEffort`; lang `chat.effort.ultrathink_*`
- serialized: [lang:chat.effort.ultrathink*] [docs:SETTINGS.md] [hot:candy-code/src/Host/TurnRunner.php]
- deps: C-PRV-1.a, C-PRV-5.a
- phase: normal
- ruling: none
- risks: a prompt that merely discusses the word "ultrathink" spends top-tier tokens. That is Claude Code's behaviour too, and it is documented.

