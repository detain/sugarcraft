### C-CTX-3.e — Memory export and prompt-visible import scope
- item: C-CTX-3 (sources: GAP-MEM-14)
- kind: gap · effort S
- approach:
  - Imports land in the agent scope (`ForeignMemoryImporter.php:254, 317`, `MemoryScope::Local`), outside the index and recall.
  - `/memory import … --scope project|user` routes through `MemoryWriter`, so imported notes are indexed and recalled.
  - `/memory export [path]` writes one markdown bundle with frontmatter per note, 0600, jailed like `/export`.
  - Both run in the host `MemoryCommand`, which the TUI router already reaches, so there is no Chat change.
- files.create: `candy-code/tests/Host/Commands/MemoryExportCommandTest.php`
- files.modify: `candy-code/src/Host/Commands/MemoryCommand.php` (`export` verb, import scope flag), `candy-code/src/Memory/ForeignMemoryImporter.php` (scope parameter)
- tests: MemoryExportCommandTest (bundle shape, 0600 mode, jail refusal); modify `candy-code/tests/Commands/MemoryImportCommandTest.php` (`--scope project` notes appear in recall)
- docs/guards: `candy-code/docs/MEMORY.md` (import/export); lang `host.memory.export*`, `host.memory.usage.*`
- serialized: [lang:host.memory.*] [docs:MEMORY.md]
- deps: none
- phase: normal
- ruling: none
- risks: none.

