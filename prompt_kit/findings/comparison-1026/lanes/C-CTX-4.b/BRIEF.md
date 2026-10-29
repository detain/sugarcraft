### C-CTX-4.b — `SOUL.md` and `USER.md` persona files
- item: C-CTX-4 (sources: GAP-PA-04)
- kind: gap · effort S
- approach:
  - `InstructionFileLoader::loadPersonal()` (:524) also reads `~/.candy-code/SOUL.md` and `~/.candy-code/USER.md`, OpenClaw's names.
  - Each is fenced like the personal AGENTS.md, contained to the personal dir, and budgeted.
  - `ContextSources` prices them as personal-tier rows, so the Context panel and P-CTX-BREAKDOWN show them.
- files.create: none
- files.modify: `candy-code/src/Context/InstructionFileLoader.php` (`loadPersonal()`), `candy-code/src/Host/ContextSources.php` (`priceInstructionFiles()`)
- tests: modify `candy-code/tests/Context/PersonalInstructionFileTest.php`: both files load fenced; a symlink escape is refused and recorded; absent files cost nothing
- docs/guards: `candy-code/docs/MEMORY.md` (personal tier); keep `candy-code/tests/Config/MemoryDocumentationDriftTest.php` green
- serialized: [docs:MEMORY.md] [hot:candy-code/src/Context/InstructionFileLoader.php] [hot:candy-code/src/Host/ContextSources.php]
- deps: none
- phase: normal
- ruling: none
- risks: none.

