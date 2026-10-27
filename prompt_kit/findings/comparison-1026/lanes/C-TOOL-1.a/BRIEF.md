### C-TOOL-1.a — Background-shell two-part cursor
- item: C-TOOL-1 (sources: SHL-1-P1)
- kind: fix · MAJOR · effort M
- approach: Root cause `candy-code/src/Background/BackgroundShellRegistry.php:503-546` (`read()`): one global cursor over stdout‖stderr, split as `stdoutOffset=min(cursor,stdoutSize)` / `stderrOffset=cursor-stdoutSize`. Valid only while stdout is frozen; when stdout grows after stderr was read, stdout bytes are skipped and stderr is replayed (reproduced in plan-review-p2). The docblock's "identical arithmetic while both streams grow" (:540) is false. Fix: a two-part cursor, one offset per stream, each advancing in its own file. The wire form is an opaque token `"<out>.<err>"` (ShellOutput `cursor` becomes a string). A bare integer is still accepted and decoded with today's arithmetic, so a cursor threaded before the upgrade keeps working on a frozen stdout. Default fresh-read tail-wins is kept per stream. The shrink remap (`lastStdoutSize`) collapses into the per-stream rule. ShellOutput paints `[cursor: 150.50]`. Rejected alternative: merging the streams with `2>&1`, because ShellOutput returns stdout and stderr separately.
- files.create: none
- files.modify: `candy-code/src/Background/BackgroundShellRegistry.php` (read(), its docblock, class docblock cursor paragraph), `candy-code/src/Tools/BuiltIn/ShellOutput.php` (class docblock, inputSchema `cursor`, execute cursor parse, paint `[cursor: …]`), `candy-code/src/Tools/BuiltIn/Bash.php` (only if its background-start answer tells the model the cursor shape)
- tests: modify `candy-code/tests/Sessions/BackgroundShellRegistryTest.php` (add `testInterleavedGrowthDeliversEveryByteExactlyOnce`: a live shell writes out/err/sleep/out/err with threaded reads, and each stream's concatenation equals its file bytes; keep `testAStdoutThatShrinksAloneDoesNotStrandTheUnreadStderr` green), `candy-code/tests/Tools/ShellOutputTest.php` (schema and paint pins; a legacy-int cursor decode), `candy-code/tests/Tools/BashBackgroundTest.php` (threaded-cursor flows)
- docs/guards: none. `tools/gen-tool-docs.php` renders names and glosses only, and the gloss is unchanged.
- serialized: [hot:candy-code/src/Background/BackgroundShellRegistry.php]
- deps: none
- phase: normal
- ruling: none
- risks: a token-shaped cursor is a schema change the model must re-learn mid-session; the integer-compat decode covers resumed sessions.

