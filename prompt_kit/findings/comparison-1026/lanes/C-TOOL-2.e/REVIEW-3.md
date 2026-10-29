# C-TOOL-2.e — REVIEW-3 (round 3, 2026-10-29)

VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 step-local MINOR / 1 NIT.

Tip 9a20c6632 (5 commits on base d83e4eed4, merge-base re-derived; all %ae+%ce detain, 0 Co-Authored; tree clean). 11 files, all BRIEF-declared.

## r1/r2 findings cured (disk-verified)
- M1 (PDF const drift): the two fixtures ARE byte-identical (AttachmentWireTest:43 ≡ DocumentAttachmentWireTest:26). Cure is the licensed ACCEPTED_CONST_DUPLICATION 'PDF' row (alphabetical slot, PNG_B64 row-shape, real adjudication text). Mutation-reason: without the row the identical pair is an unrecorded hit → guard red; with the pair absent the staleness arm reddens the row — structural, and the guard RUNS GREEN: --filter DuplicatedTestHelperDriftTest = 14T/199A OK. (Row is the minimal correct cure: both suites assert base64 of the same synthetic doc; consolidation would couple two censuses.)
- m (doc over-claim): ADDING_A_PROVIDER.md now says "The three C-TOOL-2.e legs fail closed on the bytes" (line 33); "named in the text on every leg" grep = 0; Anthropic pass-through truthfully attributed to FileMentions capture-time sniff (sniffDocument:273, :324). The both-directions pin (contains scoped sentence + absence-forbidden phrase + forged-bytes behavior legs) is a real regression guard — anthropicBlocks pass-through confirmed (forged bytes still build the CTX-2 block; encoder unmodified by the lane).

## Feature correctness + reachability (all four legs)
- bedrockBlocks: RAW-bytes document block, name shaved via /[^a-zA-Z0-9_-]/→_, substr 64, ltrim '-_' → [a-zA-Z0-9]-leading ≤64, 'document' fallback — hand-traced edge cases ('!!!'→document; 80+tail→exactly 64). Live at BedrockProvider:897.
- geminiParts: application/pdf inlineData after images; live at GeminiGenerateContentWire:524 (covers Gemini + Vertex Gemini arm).
- openAiContent(documentsAsBlocks): file part, data:application/pdf URL, filename collapses \p{C}\s (sp\x01ec.pdf→"sp ec.pdf", empty→document.pdf); ONLY chat arms pass the flag — OpenAIProvider:981 inside formatMessages, reached solely after the API_RESPONSES early-returns at complete():731/completeStream():802; Responses wire (:255), Sglang (:2491), Custom (:889) call unflagged → CTX-2 decline byte-pinned. supportsDocuments = API_CHAT && visionTable (refuses operator override, deliberate + documented); supportsVision truth-table preserved by the visionTable split.
- Flips: Bedrock = supportsVision family rule; Gemini true; Vertex anthropic||gemini (PaLM declines); Sglang/Custom false, declines unchanged. Consumers real: EngineBackend :6219/:6392 + AnthropicMessagesWire + FallbackProvider delegate.
- Forged/oversized (> DOCUMENT_MAX_BYTES) → nameUndelivered line names them, never inlines — pinned per-leg.

## Gates (cwd=candy-code -c phpunit.xml env -u LINES -u COLUMNS, this worktree)
- DocumentAttachmentWireTest|AttachmentWireTest: 55T/0F. BedrockProviderTest|GeminiProviderTest|VertexProviderTest|OpenAIProviderTest: 293T/0F (2 house deprecations). Five-guard family: 134T/2F = staleness pair ONLY (expected standing state; closeout re-pin carries this lane's live-enum delta: DocumentAttachmentWireTest = 15 rows, AttachmentWireTest method-neutral rename).
- Bans: git diff d83e4eed4..HEAD for Codex*/ProviderFactory, suite-figure.json, lang, dist, durations.tsv = 0 lines. Disclosed in-step Gemini/VertexProviderTest polarity flips read honestly (assertFalse→assertTrue with new reasoning).

## NIT (step-local, no action)
- nameUndelivered's sentence says "as blocks" also on the OpenAI file-PART wire (AttachmentEncoding.php ~:320); generic-terms wording, harmless.

## Carries (closeout, not findings)
- NEW test file DocumentAttachmentWireTest.php has NO durations.tsv row at tip (lane-forbidden file) — row MUST mint at weld/closeout or the 15 tests run in zero shards.
- suite-figure/README staleness pair absorbs +15T.
