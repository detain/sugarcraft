### C-CTX-3.f — Standing intents
- item: C-CTX-3 (sources: GAP-MEM-N3)
- kind: gap · effort M
- approach:
  - Add an `intent` memory type with a `when:` trigger: a path glob, a tool name or a keyword.
  - The model saves one with `Memory save type=intent when=…`.
  - At tool time, `RulePathNudge` consults intent notes through the existing `PathTrigger`/`KeywordTrigger` classes (`src/Context/Triggers/`), at the same seam that delivers path-scoped rules. A matching intent rides the tool result like a nudge, once per intent per turn.
  - Intents are listed in `/memory list` under their own heading.
- files.create: `candy-code/tests/Context/StandingIntentTest.php`
- files.modify: `candy-code/src/Memory/MemoryWriter.php` (`TYPES`), `candy-code/src/Memory/MemoryEntry.php` (`when` frontmatter), `candy-code/src/Tools/BuiltIn/MemoryTool.php` (save schema), `candy-code/src/Context/RulePathNudge.php` (intent lookup)
- tests: StandingIntentTest: a path intent fires on a matching Read and is silent elsewhere; a keyword intent fires once per turn; a malformed `when` is refused at save
- docs/guards: tool gen (`ToolDocDriftTest`); `candy-code/docs/MEMORY.md` (intents)
- serialized: [lang:host.memory.intent*] [docs:MEMORY.md] [hot:candy-code/src/Tools/BuiltIn/MemoryTool.php] [hot:candy-code/src/Context/RulePathNudge.php]
- deps: none
- phase: normal
- ruling: none
- risks: intents written by the model steer later turns. They are tagged and listed so the user can see and delete them.

