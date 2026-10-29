### C-TOOL-2.e — User PDF attachments on Bedrock/Gemini/OpenAI-chat
- item: C-TOOL-2 (sources: p1-miss: documents on non-Anthropic wires)
- kind: gap · effort M
- approach: CTX-2 declined `document` blocks outside the Anthropic family (`candy-code/src/Providers/AttachmentEncoding.php` class docblock; `BedrockProvider::supportsDocuments()` :445 returns false; `GeminiProvider::supportsDocuments()` :259 false). Add each wire's native shape:
  - Bedrock Converse: `document{format:"pdf", name, source:{bytes}}`, with the name sanitised to Converse's charset.
  - Gemini/Vertex-Gemini: `inlineData{mimeType:"application/pdf"}`.
  - OpenAI chat/completions: `{type:"file", file:{filename, file_data:"data:application/pdf;base64,…"}}`.
  Flip `supportsDocuments()`:
  - Bedrock: the Claude-3+/Nova family allow-list it already uses for vision (:454).
  - GeminiProvider and Vertex's Gemini arm: true.
  - OpenAIProvider: official OpenAI vision models only.
  - SGLang and Custom stay false; they keep the "named, never dropped" decline.
  Bedrock first, as the plan-review asked.
- files.create: `candy-code/tests/Providers/DocumentAttachmentWireTest.php`
- files.modify: `candy-code/src/Providers/AttachmentEncoding.php` (bedrockBlocks, geminiParts, openAiContent, class docblock), `candy-code/src/Providers/BedrockProvider.php` (supportsDocuments), `candy-code/src/Providers/GeminiProvider.php` (supportsDocuments), `candy-code/src/Providers/VertexProvider.php` (supportsDocuments :411, Gemini arm), `candy-code/src/Providers/OpenAIProvider.php` (supportsDocuments :253), `candy-code/docs/ADDING_A_PROVIDER.md` (capability notes)
- tests: create the file above (one shape pin per wire; declined wires still name the document); modify `candy-code/tests/Providers/AttachmentWireTest.php`
- docs/guards: `docs/ADDING_A_PROVIDER.md` in-step
- serialized: [hot:candy-code/src/Providers/AttachmentEncoding.php] [hot:candy-code/src/Providers/OpenAIProvider.php]
- deps: none
- phase: normal
- ruling: none
- risks: OpenAIProvider is also C-PRV-2's file; schedule them in different waves.

