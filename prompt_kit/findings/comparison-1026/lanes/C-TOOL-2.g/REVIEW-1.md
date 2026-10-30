# C-TOOL-2.g REVIEW-1 — OpenAI Responses tool-result input_file parts

VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 MINOR / 0 NIT (builder's 5 disclosures all adjudicated in the builder's favor; 4 weld carries below).

Lane: tip 25a6d642c on cc/C-TOOL-2.g, single commit, base a6c45be43 (master 94432cfd1). Diff 6 files +553/−18: src/Providers/Concerns/OpenAiResponsesWire.php, src/Providers/AttachmentEncoding.php, src/Providers/OpenAIProvider.php, docs/MEDIA.md, NEW tests/Providers/ResponsesToolResultMediaTest.php, lanes/C-TOOL-2.g/REPORT.md.

## Disclosures adjudicated

1. **OpenAIProvider.php deviation — ACCEPTED (forced, minimal).** PHP traits cannot reference another trait's constants, so the wire's document rung cannot name `AnthropicProvider::TOOL_RESULT_DOCUMENT_MAX_BYTES` (lives behind the Anthropic wire trait per the C-PRV-2.b law) directly. The host-mediated shape is not a second divergent pattern: `responsesToolResultDocumentBudget()` mirrors the pre-existing `responsesToolResultImageBudget()` verbatim (same abstract-hook-in-wire / one-line-return-in-host split, same cross-family constant), and `responsesDocumentModelGate()` mirrors the same split for the capability rung, returning the default-model `visionTable()` lookup — the table, not the user override, matching C-TOOL-2.e law. Zero behavior lines beyond the two hooks.
2. **Gate choice — VERIFIED correct.** `supportsDocuments()` code is byte-unchanged (`$this->api === self::API_CHAT && self::visionTable($this->defaultModel)`); the chat-completions decline stands. Consulting it from the Responses wire would be dead-on-arrival (API_CHAT arm), so the host gate hook is genuinely needed. USER-attachment routing untouched: wire USER rows still call `AttachmentEncoding::openAiContent($msg)` single-arg (carry-documents=false), so Responses USER rows keep declining — the flipped docblock now states exactly that scope and is truthful.
3. **Flat `input_file` shape — VERIFIED against upstream contract.** `{type:"input_file",filename,file_data}` is the official Responses schema (filename required alongside file_data — `filePartName()` always supplies one). Chat's nested `{type:file,file:{...}}` is the declined arm's defect spelling; grep confirms no other `input_file` construction anywhere in src/.
4. **PDF-only truthfulness — VERIFIED end-to-end.** `FileMentions::sniffDocument` (src/Attachments/FileMentions.php:273-276) accepts only the `%PDF-` magic; the sole production `documentBytes` setter is `Read::pdfAnswer()` (Read.php:994, PDF branch only — Runtime.php:4097 and EngineBackend.php:6003 are round-trip passthrough carriers); MEDIA.md's edited sentence sits inside a paragraph already scoped to "a PDF entering through either door presents identically" — no overclaim. The wire's re-sniff rung is defense-in-depth against forged non-PDF bytes (pinned).
5. **Fixture const rename — VERIFIED.** `MEDIA_PNG_BYTES` renamed inside the lane's own new test file only; diff file-set touches no guard/roster file (no tests/Support entries).

## Category checks

- **Traceability/scope:** all 5 brief-named files landed as described; the single out-of-brief file is disclosure #1 (accepted). No edits outside declared regions.
- **Completeness:** no stubs/TODOs; both hooks have real host implementations; the ToolResult arm is fully wired.
- **Correctness (traced):** normal path (caption + in-budget PDF + image) emits text→image→document in the same order `anthropicBlocks()` uses (OpenAiResponsesWire; parity with AnthropicMessagesWire::toolResultDocument :995-1011 rung-for-rung: isError → hasDocument → model gate → `''`/strlen>budget (strict >, shared 1 MiB const) → re-sniff → shared encoder). Edge: `documentBytes:''` declines at the `''` rung (pinned); non-PDF forged bytes decline at re-sniff (pinned); over-budget declines BEFORE any base64 encode (budget compares raw strlen — no encode cost on the decline path). "No media ⇒ plain string output" law preserved (accumulator emits string `output` unless a media part exists — pinned).
- **Security:** filename passes pre-existing `filePartName()` (`\p{C}` sweep incl. bidi overrides, space-collapse, fail-closed `document.pdf` fallback on preg false) and JSON encoding — no wire injection. No new proc_open/exec/dial/env; no new Lang keys (decline paths reuse existing text shape). E646: zero timers touched.
- **Conventions:** strict_types, PSR-12, final untouched, docblocks cite WHY (deviation, gate asymmetry, flat-vs-nested rationale all documented at site).
- **Byte-stability (prompt cache):** tool-result rows are appended after the cached prefix; part ordering is deterministic; `PromptPrefixByteStabilityTest`+`PromptStability` green in the reviewer's 166T family run.
- **Shared-spelling law:** new pin asserts `responsesInputFile()['file_data'] === dataUrl(...) === 'data:application/pdf;base64,'.base64_encode(...)` — one encoder spelling, mutation-visible.

## Mutation-reason (10 pins)

Gate neuter→`true`: text-only decline pin reds; →`false`: rides pin reds (vision-override pair pins the asymmetry). Budget neuter to `>=`: boundary-rides pin reds; removed: budget+1 decline pin reds. Order swap in accumulator: order pin reds. `''` rung removed: c_empty pin reds. Re-sniff removed: forged-bytes pin reds. dataUrl fold reverted: shared-spelling pin reds. isError rung removed: c_err pin reds. Each leg fails exactly its own pin — no vacuous pair.

## Gate runs (reviewer, worktree candy-code, class-name filters)

- `ResponsesToolResultMediaTest` → **OK 10T/18A** (+2 PHPUnit deprecations, see carry 4).
- Siblings `(OpenAiResponsesReasoningTest|OpenAiResponsesWireTest|ToolResultImageBlocksTest|ToolResultDocumentBlocksTest|DocumentAttachmentWireTest|AttachmentWireTest)` → **OK 111T/355A** — builder claim reproduced exact.
- Wider OpenAI family + byte-stability guards → **OK 166T/923A**.
- Guards `DuplicatedTestHelperDrift|GlobDialect|SymbolCitationDrift|DocFigureProseDrift` → **OK 129T/22826A** — GlobDialect GREEN at lane tip (the rostered master figure-red is not a lane property).
- Builder's "259T/762A provider family" width not reproduced literally (path-arg runs are forbidden shape); targeted 166T/923A + 111T siblings cover the touched surface.

## Weld carries (§4)

1. `scripts/parallel-tests-durations.tsv` row for the NEW file `candy-code/tests/Providers/ResponsesToolResultMediaTest.php` (unlisted = runs in zero shards).
2. Suite-figure.json + README pair re-pin carrying +10T/+18A (staleness pair is rostered standing — GREEN-equivalent here).
3. Record the accepted OpenAIProvider.php deviation (disclosure #1) in the weld note so the trait-constant mediation pattern is on-ledger for future wire traits.
4. Non-lane nit for the owner ledger: `AccessorDeprecationTest::runtimeEraPairs()/eraPairs()` are private data providers — PHPUnit 10.5 deprecations fire at suite-build under every filter; pre-existing in an untouched file, worth a one-line public fix sometime.
