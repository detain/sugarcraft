# C-TOOL-2.d — Read PDFs: tool_result document block + pdftotext text leg

**DONE.** Commit `a14d85245255279d7d37ebfb356b205a92b80c3f` on `cc/C-TOOL-2.d` (base cf0cfd0be), author/committer Joe Huss <detain@interserver.net> (verified `%ae`=1, `%ce`=1 separately, zero Co-Authored-By), 14 files, +1204/−47. Never pushed.

## Files
- src: `Tools/ToolResult.php`, `Messages/ToolResultMessage.php` (ctor tails documentBytes/documentName, withToolCallId 8-positional carry, accessors + hasDocument), `Runtime.php` (settle comment, resultMessage builder, encodeResult/decodeResult keys), `Backend/EngineBackend.php` (encodeEvent/decodeEvent finished arms carry the pair raw-binary-safe), `Providers/Concerns/AnthropicMessagesWire.php` (TOOL_RESULT_DOCUMENT_MAX_BYTES=1MiB + justification docblock; abstract supportsDocuments(); toolResultDocument() chain; tool_result arm media list [text?,image?,document?] byte-preserving legacy shapes), `Providers/AttachmentEncoding.php` (shared anthropicDocument() — user-attachment leg now calls it, identical output), `Tools/BuiltIn/Read.php` (pdfTextAvailable? ctor seam; PDF arm → pdfAnswer: extractor-first via CapturesProcessOutput::runCaptured `pdftotext -layout` (no new proc_open token; 30s hang-guard const PDF_TEXT_TIMEOUT_SECONDS, E646-clean) → pageExtracted in N:/footer house shape; else carry ≤1MiB with self-visibility caption, else honest notice — NO '@-mention' road advice anywhere; capability-neutral description() sentence).
- docs: `MEDIA.md` "PDFs Read through the tool (C-TOOL-2)" subsection (budget rationale, one-road-per-read, OpenAI chat role:tool documented decline → C-TOOL-2.g sibling, Gemini/Bedrock moot).
- tests: `Tools/ToolResultTest.php` extended; `Tools/BuiltIn/ReadBinaryScanTest.php` PDF pins polarity-flipped IN-STEP (§1.8: notice-era → carried-document, seam=false injected, renames narrate history); `Providers/PromptStabilityTest.php` document-bearing prefix arm (Sglang never smuggles base64/%PDF); `Support/DuplicatedTestHelperDriftTest.php` 2 adjudicated roster rows (VERTEX_MODEL duplication, CAPTION drift).

## New candy-code test files (gate must add durations.tsv rows)
- `candy-code/tests/Providers/ToolResultDocumentBlocksTest.php`
- `candy-code/tests/Tools/BuiltIn/ReadPdfTextTest.php`

## Gate results (cwd candy-code, env -u LINES -u COLUMNS, plain pipe, -c phpunit.xml)
- Targeted `--filter 'ReadPdfText|ReadBinaryScan|ToolResultTest|ToolResultDocumentBlocks|PromptStability|AttachmentWire'` → `Tests: 122, Assertions: 730, OK`.
- Guards `StderrEmitterCensus|ReadPathCensus|DenialPrefixRoster|GlobDialect|DocFigureProseDrift|ReadmeRosterDrift|ToolDescriptionSentenceFloor|DuplicatedTestHelperDrift` → `Tests: 285+, Assertions: 26,855+, OK`.
- Wire `Anthropic|Vertex|SseDecodeFrameBound|SystemPromptTransmissionMatrix` → `479 OK`.
- Codec neighborhood `EngineBackend|ResultFrame|EventFrame|FrameCodec|ForkSafety|Wiring|Catalog|Corpus` → 2171 tests / 8 failures, ALL 8 reproduced byte-identically at PRISTINE BASE via stash/re-run → fully inherited, zero new.
- `php tools/gen-tool-docs.php --check` → 'tool docs: up to date' rc=0. `grep -c proc_open src/Tools/BuiltIn/Read.php` → 0.
- Closeout carry: ReadmeSuiteFigureDrift/suite-figure staleness pair will redden on live enum (+2 new files ≈ +21T) — re-pin duty, NOT touched (forbidden file).
- 2 pre-existing PHPUnit deprecations: tests/Config/AccessorDeprecationTest.php:168/:202 non-public data providers (foreign, fires every run).

## Inherited foreign base reds at cf0cfd0be (surfaced by codec gate; NOT mine, NOT touched)
ChatHostLangCatalogueTest; CliLangCatalogueTest (unsorted marketplace block); PermissionsIntroDriftTest (Schedule row); ForeignAgentPresetWiringTest (Host/Commands/SchedulesCommand.php reader); MemoryPromptWiringTest; MouseWiringTest ×3 (Zone injection sentinel — live-git-snapshot family). Likely C-SHL-2.f/C-SESS-2.x lanes' roster flips never landed.

## Deviations (judged)
1. EngineBackend result-frame payload + settleFromResultFrame NOT extended: root-Message image fields feed TUI paint via Message.php (outside lane files); PDFs need no paint; ToolFinished event codec (threaded) is the child→parent ToolResult channel; parent-side replay rebuilds tool rows text-only (toTypedMessages image parity proves the doctrine). Commented in encodeEvent.
2. Capability seam is a lazy `pdfTextAvailable` ctor tail (null → Bootstrap::capabilityPresent('pdftotext') at read time) rather than a ToolBuildContext boot bool: ToolBuildContext/LaunchContext/Bootstrap.php outside lane ownership. description() never consults it (golden-prompt byte stability; pinned by description-equality arm).
3. ONE-ROAD-PER-READ: successful extraction returns paged text carrying NO bytes (no double-send; document block is the second door, never a third copy).
4. Budget decision: TOOL_RESULT_DOCUMENT_MAX_BYTES = 1,048,576 (1MiB), docblock-justified (per-step re-send × base64 inflation; order of magnitude below the 20,971,520 user @-mention cap which has its own DOCUMENT_BASE64_BUDGET; over-cap = notice only, never cut bytes). Pinned: ===1024*1024, !==TEXT_MAX_BYTES, <=intdiv(DOCUMENT_MAX_BYTES,10).
5. pageExtracted is a simple in-memory pager — justified by the 262,144-byte capture bound; form-feed \f stripped from pdftotext output before gating; capped-extractor honesty suffix.
6. PHP forbids external trait-const fetch — Read/tests reference AnthropicProvider::TOOL_RESULT_DOCUMENT_MAX_BYTES (consuming class); const still owned by the wire trait.
7. Image-over-cap notice keeps its pre-existing '@-mention' wording (out of step scope); all PDF answers carry NO '@' — pinned.
