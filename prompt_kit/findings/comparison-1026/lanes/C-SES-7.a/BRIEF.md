# C-SES-7.a — Session importer core (CC / Codex / OpenCode / candy-code)
area: ses · kind: gap · effort M · phase: normal
deps: C-SES-2.a (imports must index into the persisted FTS index, not trigger a per-process rebuild)
serialized: [lang:session.import.]
ruling: none
risks: The foreign formats drift between versions. Readers must refuse unknown shapes loudly (fixtures pinned by version), never guess.

## Files
- candy-code/lang/en.php
- candy-code/src/Session/Import/CandyCodeExportReader.php
- candy-code/src/Session/Import/ClaudeCodeTranscriptReader.php
- candy-code/src/Session/Import/CodexTranscriptReader.php
- candy-code/src/Session/Import/ImportFormat.php
- candy-code/src/Session/Import/ImportRefusedException.php
- candy-code/src/Session/Import/ImportedTranscript.php
- candy-code/src/Session/Import/OpenCodeExportReader.php
- candy-code/src/Session/Import/SessionImportLedger.php
- candy-code/src/Session/Import/SessionImporter.php
- candy-code/tests/Session/Import/SessionImporterTest.php
- candy-code/tests/Session/Import/TranscriptReadersTest.php

## files.create
`candy-code/src/Session/Import/SessionImporter.php`, `candy-code/src/Session/Import/ImportFormat.php`, `candy-code/src/Session/Import/ImportedTranscript.php`, `candy-code/src/Session/Import/CandyCodeExportReader.php`, `candy-code/src/Session/Import/ClaudeCodeTranscriptReader.php`, `candy-code/src/Session/Import/CodexTranscriptReader.php`, `candy-code/src/Session/Import/OpenCodeExportReader.php`, `candy-code/src/Session/Import/SessionImportLedger.php`, `candy-code/src/Session/Import/ImportRefusedException.php`, `candy-code/tests/Session/Import/SessionImporterTest.php`, `candy-code/tests/Session/Import/TranscriptReadersTest.php`

## files.modify
`candy-code/lang/en.php` (`session.import.*`: the notice row and the refusals)

## tests
create:
- `candy-code/tests/Session/Import/TranscriptReadersTest.php`: fixtures per format; tool rows flattened; secrets redacted;
- `candy-code/tests/Session/Import/SessionImporterTest.php`: an imported session becomes body-searchable; no usage rows; re-import appends only the delta; the bounds refuse.

## docs/guards
none (the CLI and RPC surfaces come in C-SES-7.b and C-SES-7.e)

## Approach

- **Formats.** `SessionImporter` auto-detects four formats:
- candy-code's own export JSON (`Exporter::toJson`, read back via `Message::fromArray`);
- Claude Code project JSONL;
- Codex rollout JSONL;
- the OpenCode `export` JSON.
- **Bounds.** At most 50k items and 64 MiB (the OpenClaw bounds), with a typed refusal beyond either.
- **Safety.**
- Every text is redacted through `Memory/SecretRedactor`.
- Foreign tool calls and results are flattened to text rows, never replayable tool rows.
- A UI-only first row marks the session as imported, untrusted reference material.
- **Persistence.** The result is persisted as a new `main` session with provider `import:<source>`, through `EnhancedSessionStore::saveTranscript()`, so FTS indexing rides the incremental sync. No `usage_records` rows are written: the session is unpriced, so it shows `≥`.
- **Incremental re-import.** A `SessionImportLedger` side table (`session_imports(source_key PRIMARY KEY, session_id, items, digest, imported_at)`) is opened on `$store->path()`, the way `ShareStore` opens its table. A re-import appends only the new items.
- **One type per file.** Each reader, DTO and enum gets its own file.
