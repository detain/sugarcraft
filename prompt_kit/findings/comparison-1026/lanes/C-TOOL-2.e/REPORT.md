# C-TOOL-2.e — User PDF attachments on Bedrock/Gemini/OpenAI-chat

Lane: cc-cmp builder · branch `cc/C-TOOL-2.e` @ /home/sites/cc-cmp-C-TOOL-2.e · base `d83e4eed4`
Spec: design-tool.md :241 (BRIEF.md absent on disk; plan_comparison.md :3079).

## Status

CLOSED. Three commits on `cc/C-TOOL-2.e`: `1a0d724b3` (step 0, Bedrock), `fe49217ac` (Gemini + OpenAI-chat wires), `e2d8c01e5` C-TOOL-2.e: document the native document wires in ADDING_A_PROVIDER — cat-file OK (commit), 1 file +17, docs-only. Tree clean at tip.

## Commits

- `1a0d724b3` C-TOOL-2.e: carry user PDFs as Bedrock Converse document blocks (step 0 skeleton) — cat-file OK. 4 files, +209/-20. AttachmentEncoding gains partitionDocuments/nameUndelivered/bedrockDocumentName + bedrockBlocks document leg (format `pdf`, RAW bytes, Converse-charset name); BedrockProvider::supportsDocuments → supportsVision() family allow-list; AttachmentWireTest flip + new DocumentAttachmentWireTest (8T).
- `fe49217ac` C-TOOL-2.e: carry user PDFs natively on the Gemini and OpenAI-chat wires — cat-file OK (commit). 8 files, +217/-47. geminiParts inlineData leg; openAiContent chat-arm opt-in `file` part; GeminiProvider/Vertex flips; OpenAIProvider supportsDocuments = API_CHAT && extracted visionTable (override does not buy documents); test pins flipped in-step (347T/857A green on the six direct classes; 14T/35A in DocumentAttachmentWireTest).

## Design shipped (commit 2)

- `AttachmentEncoding::geminiParts`: well-formed PDFs ride `['inlineData'=>['mimeType'=>'application/pdf','data'=>base64]]` after images; refused ones name themselves via `nameUndelivered` (Vertex's Gemini arm shares this encoder unchanged).
- `AttachmentEncoding::openAiContent(UserMessage, bool $documentsAsBlocks = false)`: only the OpenAIProvider CHAT arm (formatMessages, :721/:794, both past the `api===RESPONSES` early return) passes the flag → `['type'=>'file','file'=>['filename'=>…,'file_data'=>'data:application/pdf;base64,…']]`. SGLang (SglangProvider:2491), Custom (CustomProvider:889) and the Responses arm (OpenAiResponsesWire:255) keep the no-flag CTX-2 naming line — Responses maps only image_url, so a `file` part would silently drop there.
- OpenAIProvider::supportsDocuments = `api===API_CHAT && visionTable(model)` — the operator `supportsVision` override buys images only, never documents (CustomProvider precedent). supportsVision refactor `?? visionTable()`; table logic moved byte-identical into private static visionTable().
- GeminiProvider::supportsDocuments → true. VertexProvider → isAnthropic || isGemini (legacy PaLM neither).
- Fail-closed on all three native legs (unchanged from step 0): a doc is carried iff bytes start `%PDF-` and strlen ≤ `FileMentions::DOCUMENT_MAX_BYTES` = 20 MiB (= 20*1024*1024, src/Attachments/FileMentions.php:65 — inbound capture ceiling, reused as encoder backstop; declared mime never trusted — bytes decide). Engine-side per-request `EngineBackend::DOCUMENT_BASE64_BUDGET` = 30 MB base64 (:352) untouched. Refused docs name themselves: `[Document attachment X was not sent: the wire carries only well-formed PDFs up to 20,971,520 bytes as blocks.]`.
- filePartName: control/space runs collapse to single spaces (trim), `''`→`document.pdf`; dots/spaces stay (model reads it back). bedrockDocumentName (step 0): non-charset→`_`, cap 64, ltrim `-_`, empty→`document`.
- No new proc_open; no new timers (E646 clean); no new Lang keys (wire-text naming lines, English literals pre-exist).

## In-step guard flips (same commit, §1.8)

- AttachmentWireTest: testDocumentsBecomeBlocksOnlyWhereTheWireCarriesThem rewritten (no-flag openai keeps CTX-2 line byte-identical; openai opt-in + gemini carry pins; bedrock pins kept); testDocumentsAreOnlyTheAnthropicMessagesFamily → testTheDocumentWiresAreAnthropicMessagesAndTheNativeArms (openai-chat true, responses-arm false pin via factory `api`, gemini true, vertex-gemini arm true; custom/sglang overrides still false).
- GeminiProviderTest:94 assertFalse→assertTrue.
- VertexProviderTest testSupportsDocumentsOnTheClaudeArmOnly → …OnBothNativeArms (gemini arm true; LEGACY_GOOGLE_MODEL stays false).

## Gates (exact)

- `php -l`: clean ×9 touched files.
- `--filter 'DocumentAttachmentWireTest|AttachmentWireTest|GeminiProviderTest|VertexProviderTest|OpenAIProviderTest|BedrockProviderTest'`: OK 347T/857A (+2 ambient deprecations, identical at base).
- `--filter 'Document|Attachment|Encoding|Provider'`: 3545T — 4F+1E = EXACTLY five foreign standing reds, each isolated-reproducible, zero symbol overlap with this lane's files (verified by grep): HelpTest CANDY_CODE_SERVICE_TIER (weld-18c ModelProviderSettings), MediaSettingsTest openrouter mediaKinds, AttachPagingTest ×2 (RemoteSessionHost), EnumSpellingTest memory-fixture (AgentPresetRegistry). Zero new reds from this change.
- ReadmeSuiteFigureDrift staleness pair: confirmed EXACTLY the 2 expected reds at tip (headline↔live + artifact↔live) — merge/gate re-pin duty, lane-forbidden file.
- Final battery at tip `e2d8c01e5`: `--filter 'Document|Attachment|Encoding|Provider'` 3545T — reds remain EXACTLY the five foreign standing reds (HelpTest CANDY_CODE_SERVICE_TIER, MediaSettingsTest openrouter mediaKinds, AttachPagingTest ×2 RemoteSessionHost, EnumSpellingTest memory fixture), each isolated-reproducible at base, zero symbol overlap with this lane (grep-proven). Direct classes `DocumentAttachmentWireTest|AttachmentWireTest|GeminiProviderTest|VertexProviderTest|OpenAIProviderTest|BedrockProviderTest|AnthropicProviderTest|CodexProviderTest`: OK 380T/997A. No-opt-in consumers `SglangProviderTest|CustomProvider|Responses`: OK 205T/618A. Doc guards `DocFigure|ReadmeRoster|SymbolCitation|DuplicatedDocBlock`: OK 116T/22214A. php -l clean ×9 code files. CodexProvider/ProviderFactory byte-untouched across all three commits (co-tenant law).

## DUR carry (for the gate's durations.tsv)

- `candy-code/tests/Providers/DocumentAttachmentWireTest.php` — NEW file: 14 tests / 35 assertions after commit 2 (8 step-0 + 6 new); OK in 0.5s wall incl. bootstrap (fixture-only, no I/O). Gate must add a row or it runs in zero shards.

## Co-tenant law respected

CodexProvider.php + ProviderFactory.php untouched (PRV-4.b landed at 18c). Providers dir edits limited to declared four files.
