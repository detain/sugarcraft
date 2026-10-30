# C-TOOL-2.g — OpenAI Responses `input_file` tool-result document parts

Status: DONE on branch `cc/C-TOOL-2.g` (base a6c45be43). Verdict-first: the
Responses wire now carries a tool's carried PDF as a native `input_file` part
on its own `function_call_output` row, under the same ladder discipline as
the Anthropic `tool_result` document leg; every refusal lands on the plain
caption string; the chat-completions decline is untouched.

## Files changed

- `candy-code/src/Providers/Concerns/OpenAiResponsesWire.php` — the
  ToolResultMessage arm became a media accumulator (caption, then
  `input_image`, then `input_file` — the Anthropic blocks() order); new
  `responsesToolResultDocument()` ladder (rung-for-rung parity: `is_error` →
  text, `hasDocument`, model gate, shared budget, re-sniff); image ladder's
  final return folded through the shared `AttachmentEncoding::dataUrl()`
  (byte-identical); two new abstract host hooks:
  `responsesToolResultDocumentBudget()` + `responsesDocumentModelGate()`.
- `candy-code/src/Providers/AttachmentEncoding.php` — NEW public
  `dataUrl()` (the one `data:` spelling; the two chat inline spellings now
  route through it, byte-identical) and NEW public `responsesInputFile()`
  (flat `type`/`filename`/`file_data` part, name through the existing
  `filePartName()` hygiene); the C-TOOL-2.e "file-ID construct" premise
  corrected in the class and `openAiContent()` docblocks (in-step, same
  commit — the decline it describes now stands only for user rows, and the
  prose says so).
- `candy-code/src/Providers/OpenAIProvider.php` — host implementations of
  the two hooks (budget = `AnthropicProvider::TOOL_RESULT_DOCUMENT_MAX_BYTES`
  — the trait-constant mediation law C-PRV-2.b established; gate =
  `self::visionTable($this->defaultModel)`), plus the matching truth-patch of
  the `supportsDocuments()` docblock sentence that carried the same fallen
  premise. NOT in the brief's files.modify — disclosed below.
- `candy-code/docs/MEDIA.md` — ONE line flipped: "the Responses-API sibling
  owns that leg" → names the `input_file` part and the step.
- `candy-code/tests/Providers/ResponsesToolResultMediaTest.php` — NEW, 10T/18A.

## Parity chain mirrored

AnthropicMessagesWire::toolResultDocument: `isError() || !hasDocument() ||
!capable → null` → `bytes === '' || strlen > budget → null` →
`FileMentions::sniffDocument → null` → shared encoder. The Responses ladder
runs the identical rungs; media ORDER matches `anthropicBlocks()`
(caption→image→document); the budget is the SAME constant
(1 MiB, cross-wire: a PDF that fits one row fits the other), mediated
through the host for the trait-constant reason C-PRV-2.b documented.

## Disclosed deviations (judged, not silent)

1. **`OpenAIProvider.php` touched (out of files.modify).** The new abstract
   budget + gate hooks make the host file load-bearing: a trait cannot read
   another trait's constant (PHP fatal) and cannot reach the provider's
   private `visionTable()` — the exact mediation C-PRV-2.b used for the
   image budget, which DID list OpenAIProvider in its §4 block. Additive
   only (two methods + one docblock truth-patch); collision-checked against
   the run's live lanes — no in-flight §4 claims OpenAIProvider.
2. **Capability rung is the vision TABLE, not `supportsDocuments()` and not
   `supportsVision()`.** `supportsDocuments()` is arm-gated to
   `API_CHAT` on this provider — consulting it here would make the leg
   dead-on-arrival (dormant-code law forbids shipping it that way), and
   flipping it would silently reroute the USER-attachment flow the C-TOOL-2.e
   ruling keeps declining (bytes would reach `openAiContent`'s default leg
   while EngineBackend stops withholding). Reusing `supportsVision()` would
   violate C-TOOL-2.e's own document law — "an override buys images, it does
   not teach a non-vision deployment to read PDFs" — so the gate is the raw
   table answer restated as a host hook. Pinned directly:
   `testVisionOverrideBuysImagesNotDocuments` (same blind-model provider:
   image rides, PDF stays text).
3. **Brief wording read as ladder-parity, not method-name-parity**: "SAME
   decision chain as toolResultImage()" honored rung-shape; the chain's
   capability rung legitimately differs per medium per the 2.e law.

## Chat-completions decline honored

`role: tool` string unchanged; `documentsAsBlocks` stays chat-arm-only; no
`image_url`-family tool-row parts added anywhere on the chat wire. The
recorded decline (AnthropicMessagesWire::toolResultImage docblock +
AttachmentEncoding prose) stays, scoped truthfully to user rows.

## Tests run (targeted only; cwd=candy-code -c phpunit.xml, plain pipe)

- `tests/Providers/ResponsesToolResultMediaTest.php` → OK (10 tests, 18 assertions)
- Reasoning+wire+media siblings (`OpenAiResponsesReasoningTest
  OpenAiResponsesWireTest ToolResultImageBlocksTest
  ToolResultDocumentBlocksTest DocumentAttachmentWireTest
  AttachmentWireTest`) → OK (111 tests, 355 assertions)
- Provider family (`OpenAIProviderTest SystemPromptTransmissionMatrixTest
  FallbackProviderTest OpenAIProviderCachedCostTest
  OpenAIProviderContextWindowTest OpenAIProviderStreamedToolCallsTest
  ProviderFactoryTest AnthropicProviderTest`) → OK (259 tests, 762 assertions)
- Backend attachment consumers (`DocumentAggregateBudgetTest
  EngineBackendAttachmentsTest`) → OK (21 tests, 93 assertions)
- Guards `--filter 'DuplicatedTestHelperDrift|GlobDialect|SymbolCitationDrift|DocFigureProseDrift'`
  → OK, 129 tests, 22826 assertions (2 PHPUnit deprecations = baseline:
  private data providers in `tests/Config/AccessorDeprecationTest.php`, a
  file this lane never touches — isolated with --display-all-issues)

## For the weld

- NEW test file needing a durations.tsv row:
  `candy-code/tests/Providers/ResponsesToolResultMediaTest.php` (10T/18A).
- Suite-figure staleness pair will redden for the closeout re-pin (+10T live
  enumeration; no assertions figure touched here).

## Other disclosures

- First-pass edit slip inside `AttachmentEncoding.php` (a replacement clipped
  the `anthropicBlocks()` docblock header) was caught by immediate re-read
  and repaired in-file before any gate ran; final `php -l` clean on all four
  src/test files and the 111-test sibling battery is green on the repaired
  shape.
- `PNG_BYTES` const renamed `MEDIA_PNG_BYTES` locally after
  DuplicatedTestHelperDrift accused the byte-identical twin name collision
  with `OpenAiResponsesReasoningTest` — kept in-file rather than rostering a
  human-decision polity row.
- No Lang keys (wire layer, no user strings), no env keys, no guards flipped;
  MEDIA.md row landed same commit (doc/guard law).
