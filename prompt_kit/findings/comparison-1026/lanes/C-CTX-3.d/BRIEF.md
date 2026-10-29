### C-CTX-3.d — Model recall over past sessions and the compaction journal
- item: C-CTX-3 (sources: GAP-MEM-11/12, GAP-SES-11 (model half))
- kind: gap · effort M
- approach:
  - The FTS5 session search is user-only, and the compaction journal feeds only the dream pass.
  - The `Memory` tool's `recall` action gains `source: notes|sessions|journal` (default `notes`, so the existing schema keeps its meaning):
    - `sessions` queries `SessionSearchIndex` (bm25 + snippet), limited to sessions of this project root and excluding the current one;
    - `journal` reads recent `CompactionJournal` entries.
  - Results are bounded (N hits × snippet bytes), passed through `SecretRedactor`, and fenced as untrusted data.
- files.create: `candy-code/tests/Tools/BuiltIn/MemoryRecallSourcesTest.php`
- files.modify: `candy-code/src/Tools/BuiltIn/MemoryTool.php` (schema, `recall()`), `candy-code/src/Session/SessionSearchIndex.php` (snippet query scoped by cwd), `candy-code/src/Memory/CompactionJournal.php` (bounded read view)
- tests: MemoryRecallSourcesTest: session hits are scoped to the root and exclude the current session; journal hits are bounded; secrets are redacted; default `notes` is unchanged
- docs/guards: `php tools/gen-tool-docs.php --write` (Memory gloss and schema) under `ToolDocDriftTest`; `candy-code/docs/MEMORY.md` (recall sources)
- serialized: [docs:MEMORY.md] [docs:README.md (tool gen region)] [hot:candy-code/src/Tools/BuiltIn/MemoryTool.php]
- deps: C-SES-2 (other area: session-search fixes, which make the FTS hits reachable)
- phase: normal
- ruling: none
- risks: cross-session recall can surface another session's secrets or prompts. Redaction and root scoping are mandatory, not optional.

