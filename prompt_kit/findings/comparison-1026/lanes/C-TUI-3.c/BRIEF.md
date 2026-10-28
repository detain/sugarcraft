### C-TUI-3.c — Message-picker core
- item: C-TUI-3 (sources: GAP-UI-15)
- kind: gap · effort S
- approach: A pure `MessagePicker` in the HistorySearch shape.
  - Its rows are the session's conversation checkpoints, read from the store (the positions `/rewind n --chat` counts), each labelled with the prompt it restores.
  - Newest first; filter-as-you-type.
  - `update(KeyMsg)` returns `[self, ?MessagePickerAction]`, with `Accept(stepsBack)` or `Close`.
  - `view(cols, rows, Theme)`.
- files.create: `candy-code/src/Tui/MessagePicker/MessagePicker.php`; `candy-code/src/Tui/MessagePicker/MessagePickerAction.php`; `candy-code/src/Tui/MessagePicker/OpenMessagePickerMsg.php`
- files.modify: `candy-code/lang/en.php` (`tui.msgpicker.*`)
- tests: create `candy-code/tests/Tui/MessagePicker/MessagePickerTest.php` (ordering, filter, accept index = stepsBack, an empty session)
- docs/guards: `tools/check-one-type-per-file.php`; LangParityTest
- serialized: [lang:tui.msgpicker.]
- deps: none
- phase: normal
- ruling: none
- risks: none notable.

