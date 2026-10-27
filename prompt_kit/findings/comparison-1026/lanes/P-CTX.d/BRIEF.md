### P-CTX.d — Chat context-snapshot producer (TurnRunner-held)
- item: P-CTX-BREAKDOWN (sources: owner pick)
- kind: feature · effort S
- approach:
  - The pane must never measure in `view()`: `promptSectionSizes()` builds a Runtime, and the first call can touch disk.
  - Follow TodoPane's "read, never fetched" law (`src/Tui/Components/TodoPane.php:103-115`): `TurnRunner` gains `heldContext(?sid)`/`saveContext(?sid, ContextCategories)`, memory only and per session (like `heldTodos`, :725).
  - `Chat::measureContextCmd()` is a public Cmd. It runs `ContextSources::measure($this->commandContext(), priceInstructionFiles(root), contextTokens())` → `ContextCategories::from()` → `saveContext`, and answers a new `ContextMeasuredMsg`, which Chat routes as a no-op repaint.
  - It is scheduled on turn settle (HandlesTurnCompletion), after compaction lands, after `/clear` and after a model switch. App calls it when the pane docks (P-CTX.e).
  - No Chat constructor field.
- files.create: `candy-code/src/App/ContextMeasuredMsg.php`
- files.modify: `candy-code/src/Host/TurnRunner.php` (`heldContext()`, `saveContext()`), `candy-code/src/Chat.php` (new `measureContextCmd()`; route arm for `ContextMeasuredMsg` in the session-surface family), `candy-code/src/Chat/Handlers/HandlesTurnCompletion.php` (settle schedule), `candy-code/src/Chat/Handlers/HandlesSessionSurface.php` (the arm)
- tests: create `candy-code/tests/Host/TurnRunnerContextTest.php` (per-session hold; overwrite); create `candy-code/tests/Chat/ContextSnapshotScheduleTest.php` (a settle emits the Cmd; running it fills `heldContext`; the RouteTraitParityTest arm is unique)
- docs/guards: `tests/Chat/RouteTraitParityTest` (or its real name: the "no class claimed twice" pin) covers the new arm
- serialized: [chat:measureContextCmd (new method) + session-surface route arm] [hot:candy-code/src/Chat/Handlers/HandlesTurnCompletion.php] [hot:candy-code/src/Host/TurnRunner.php]
- deps: P-CTX.a
- phase: normal
- ruling: none
- risks: measuring on every settle costs one prompt assembly from the session memo (cheap, per `Runtime::promptSectionSizes` docblock). Debounce if two settles land in one tick.

