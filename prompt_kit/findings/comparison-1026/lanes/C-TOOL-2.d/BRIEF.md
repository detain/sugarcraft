### C-TOOL-2.d — Read PDFs: tool_result `document` block + pdftotext text
- item: C-TOOL-2 (sources: GAP-TL-20, SHL-3-P1, p2-miss: SHL-3 PDF as document, k2-sum TL-20 downgrade)
- kind: gap · effort M
- approach: Read returns only a notice for a PDF (`candy-code/src/Tools/BuiltIn/Read.php:750-763`). Its "mention the file (@path)" advice is a road the model cannot take, because @-mentions resolve only on user prompts. Mirror the image path (SHL-3 T2), split across two legs.
  - **Document leg:**
    - `Tools\ToolResult` and `Messages\ToolResultMessage` gain `documentBytes` (+name). Read carries in-cap PDFs (`FileMentions::DOCUMENT_MAX_BYTES`, and a tighter tool-result byte budget const).
    - `AnthropicMessagesWire::toolResultDocument()` emits a `document` block inside `tool_result` when `supportsDocuments()`, not an error, and the bytes sniff `%PDF-`. It shares an encoder with `AttachmentEncoding`'s user-attachment leg (:89).
    - The fields thread through the same codecs as `imageBytes`: `Runtime::settle()` (~:3531-3562), `Runtime::encodeResult/decodeResult` (:3823/:3853), `EngineBackend::runCompleteInChild` (:4898, ~:5046-5056) and `settleFromResultFrame` (:5247, ~:5304-5321).
  - **Text leg:** when `DetectsCapabilities::capabilityPresent('pdftotext')` is true, Read runs `pdftotext -layout <path> -` through the existing `CapturesProcessOutput::runCaptured` helper (no new proc_open token) and pages the text with the existing offset/limit args. The notice wording is fixed: no "@-mention" advice to the model.
  - Gemini and Bedrock wires have no tool calling (`supportsFunctionCalling()` false), so tool-result media is moot there. OpenAI chat `role: tool` stays the documented decline; see C-TOOL-2.g for Responses.
- files.create: `candy-code/tests/Providers/ToolResultDocumentBlocksTest.php`, `candy-code/tests/Tools/BuiltIn/ReadPdfTextTest.php`
- files.modify: `candy-code/src/Tools/BuiltIn/Read.php` (PDF branch + description), `candy-code/src/Tools/ToolResult.php`, `candy-code/src/Messages/ToolResultMessage.php` (field, withToolCallId copy), `candy-code/src/Runtime.php` (settle, encodeResult, decodeResult), `candy-code/src/Backend/EngineBackend.php` (runCompleteInChild message codec, settleFromResultFrame), `candy-code/src/Providers/Concerns/AnthropicMessagesWire.php` (anthropicBlocks tool_result arm, new toolResultDocument), `candy-code/src/Providers/AttachmentEncoding.php` (shared `anthropicDocument()` helper), `candy-code/docs/MEDIA.md` (Read-PDF paragraph)
- tests: create the two above (supportsDocuments true/false, cap edge, is_error stays text, `%PDF-` sniff; pdftotext arm with an injected capability seam, skipped when the binary is absent); modify `candy-code/tests/Tools/BuiltIn/ReadBinaryScanTest.php` (PDF polarity flip), `candy-code/tests/Tools/ToolResultTest.php`, `candy-code/tests/Providers/PromptStabilityTest.php` (document-bearing prompt pin)
- docs/guards: `docs/MEDIA.md` in-step; Read's gloss is unchanged, so no gen-tool-docs
- serialized: [runtime:settle,encodeResult,decodeResult] [hot:candy-code/src/Backend/EngineBackend.php] [hot:candy-code/src/Providers/Concerns/AnthropicMessagesWire.php]
- deps: none
- phase: normal
- ruling: none
- risks: a 20 MiB PDF in history re-sends every turn; the tool-result byte budget must be far below the user-attachment cap (lane decision, recorded in the const docblock).

