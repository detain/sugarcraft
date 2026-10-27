### C-TOOL-1.c — Format hook skips failed edits, never blesses stale
- item: C-TOOL-1 (sources: EDT-1-P1)
- kind: fix · MAJOR · effort S
- approach: Root cause `candy-code/src/Hooks/BuiltIn/PostEditFormatHook.php` execute() (:97-120). It formats failed edits too ("exactly as the lint hook reasons") and then calls `$this->ledger?->record($abs, null)`, which stores the CURRENT hash. `HookContext` (`candy-code/src/Hooks/HookContext.php:21-31`) has no failure bit, and only gate refusals skip PostToolUse. So when a stale Write is refused, the formatter rewrites the user's file and blesses it, and the model's retry then overwrites the user's edits. Fix, two independent guards. (1) `HookContext` gains `public bool $toolFailed = false` plus `withToolFailed()`; `Runtime::settle()` (:3410) passes `$result->isError()`; the format hook returns early when it is set. (2) The hook records a kept rewrite only when the ledger's pre-format `staleness($abs, $before)` was null, so it never blesses an already-stale file. The lint hook keeps linting failed edits, because lint reads and format writes. The script-hook stdin payload is NOT widened.
- files.create: none
- files.modify: `candy-code/src/Hooks/HookContext.php` (ctor field, withHeartbeat/withToolInput/withToolOutput copies, new withToolFailed), `candy-code/src/Runtime.php` (settle() ~:3410), `candy-code/src/Hooks/BuiltIn/PostEditFormatHook.php` (execute + docblock), `candy-code/docs/HOOKS.md` ("Post-edit format" section ~:1070-1110: failed edits are not formatted; a stale file is never recorded)
- tests: modify `candy-code/tests/Hooks/BuiltIn/PostEditFormatHookTest.php` (`testAFailedEditIsNeitherFormattedNorRecorded`, `testAStaleFileIsNotBlessedByTheFormatter`), `candy-code/tests/Hooks/HookContextTest.php` (field round-trip through every `with*`), `candy-code/tests/Hooks/PostToolUseAttributionTest.php` (a refused Write reaches PostToolUse with `toolFailed` true)
- docs/guards: `docs/HOOKS.md` sentence in-step; `HookEnvironmentDocumentationDriftTest` is unaffected because the payload is unchanged
- serialized: [runtime:settle] [docs:HOOKS.md]
- deps: none
- phase: normal
- ruling: none
- risks: the other `new HookContext(` sites (7 in src/) rely on the defaulted trailing param; a positional caller would break, so use named args.

