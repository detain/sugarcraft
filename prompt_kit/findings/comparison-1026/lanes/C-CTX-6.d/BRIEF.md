### C-CTX-6.d — `/memory open` edits enter the memory journal
- item: C-CTX-6 (sources: VIS-2-P1)
- kind: fix · NIT · effort S
- approach:
  - Root cause: `Chat::memoryOpenArm()` (`Chat.php:15628-15660`) answers before `MemoryCommand`'s `MemoryHistoryCommand::record` (`MemoryCommand.php:60-62, 82-84`), so `/memory open` edits never reach `/memory log`/`undo`.
  - Fix: `EditorCommand::openFile()` takes an optional on-saved closure, run on exit 0 when the file's hash changed. `memoryOpenArm()` passes one that records the note in the memory history.
- files.create: `candy-code/tests/Chat/MemoryOpenJournalTest.php`
- files.modify: `candy-code/src/Commands/EditorCommand.php` (`openFile()`, `openCollect()`), `candy-code/src/Chat.php` (`memoryOpenArm()`)
- tests: MemoryOpenJournalTest: `/memory open` followed by a stubbed editor edit, then `/memory log`, shows the entry; an unchanged file records nothing
- docs/guards: none
- serialized: [chat:memoryOpenArm] [hot:candy-code/src/Commands/EditorCommand.php]
- deps: none
- phase: normal
- ruling: none
- risks: none.

