### C-TOOL-3.a — Tool-tail slot store + PtyRun live progress
- item: C-TOOL-3 (sources: GAP-SH-08, k2-dorm `ToolProgress` unpainted)
- kind: dormant-wire · effort S
- approach: Core half of the live tail.
  - (1) `ToolTailSlots`: a process-global store shaped like `candy-code/src/Media/PreviewSlots.php` (MAX_SLOTS backstop). It holds the newest tail text, `truncated` and `seq` per toolCallId, and drops out-of-order seq. It is never serialized (the same leak law).
  - (2) PtyRun streams progress. It implements `AcceptsToolProgress` (`candy-code/src/Tools/AcceptsToolProgress.php`), which EngineBackend::turnTools already binds (:3218). `PtyDriver::run()` (`candy-code/src/Support/PtyDriver.php:93`) gains `?\Closure $onChunk`, fed from `collectWindow()`. PtyRun strips ANSI from each chunk with `\SugarCraft\Core\Util\Ansi::strip()` before feeding a `ShellTailRing` the way Bash feeds one (`Bash.php:531-541`). Bash's ring does no stripping of its own; the renderer sanitises at paint time (C-TOOL-3.b).
- files.create: `candy-code/src/Tui/ToolTailSlots.php`, `candy-code/tests/Tui/ToolTailSlotsTest.php`, `candy-code/tests/Tools/PtyRunToolProgressTest.php`
- files.modify: `candy-code/src/Tools/BuiltIn/PtyRun.php` (implements, withToolProgressEmitter, drive()), `candy-code/src/Support/PtyDriver.php` (run, collectWindow)
- tests: create the three above (slot bound, seq ordering, clear; PtyRun emits frames, gated like the existing pty tests)
- docs/guards: none
- serialized: none
- deps: none
- phase: normal
- ruling: none
- risks: PtyRun output is screen-shaped; the tail is a best-effort plain-text view, not the final screen.

