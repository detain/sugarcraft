# REVIEW-1 — C-TOOL-2.d (documentBytes thread + pdftotext text leg)

- Reviewer: fresh round-1 independent review, 2026-10-28.
- Worktree: /home/sites/cc-cmp-C-TOOL-2.d, branch `cc/C-TOOL-2.d`.
- Tip: `a14d85245255279d7d37ebfb356b205a92b80c3f` — `git cat-file -e` OK. Parent/base: `cf0cfd0be` — cat-file OK, confirmed ANCESTOR. `%ae`=`%ce`=detain@interserver.net, subject `C-TOOL-2.d: Read PDFs — tool_result document block + pdftotext text leg`, zero trailers. No phantom hashes anywhere in this review or in the builder REPORT (all cited SHAs git-log-resolve on the branch).

## VERDICT: CLEAN (0 CRITICAL / 0 MAJOR / 0 step-local MINOR; advisories + CLOSEOUT-ONLY below)

The step matches the design block; both legs are real, reachable, and pinned; the five declared deviations are each defensible on disk (judged below).

## 1. Traceability and scope

`git diff --numstat cf0cfd0be...HEAD`: exactly the 14 declared files, +1204/−47, matching the builder REPORT line-for-line:
src×7 (ToolResult.php, ToolResultMessage.php, Runtime.php, EngineBackend.php, Read.php, Concerns/AnthropicMessagesWire.php, Providers/AttachmentEncoding.php), docs/MEDIA.md, tests ToolResultTest / ReadBinaryScanTest (flip) / PromptStabilityTest / DuplicatedTestHelperDriftTest, NEW tests/Tools/ReadPdfTextTest.php (285 L) + tests/Providers/ToolResultDocumentBlocksTest.php (362 L).
- NO `candy-code-web/dist/`, NO `tests/Config/Support/suite-figure.json`, NO `scripts/parallel-tests-durations.tsv` (new-test-file rows correctly left to the gate per §1.5 — builder REPORT flags them).
- DuplicatedTestHelperDriftTest edit = 2 roster rows (VERTEX_MODEL→ACCEPTED_CONST_DUPLICATION, CAPTION→ACCEPTED_CONST_DRIFT), each carrying an adjudication comment. This is a legitimate §1.8 in-step guard move forced by the new test file reusing image-sibling fixture consts; the guard's logic was NOT loosened. Not scope creep.
- EngineBackend / AnthropicMessagesWire edits stay inside the serialized regions declared by the brief. No undeclared file touched.

## 2. Completeness

No stubs/TODOs/deferred throws beyond the design's own declines (OpenAI-chat document block DECLINED with a real rationale in docs + code comment; Gemini/Bedrock moot — no function calling, unchanged doctrine). `pdfAnswer` has no silent paths: every refusal branch writes the fixed notice.

## 3. Correctness / production reachability (entry-point trace, live)

- Read BINARY branch is reached from a live PDF Read via `binaryAnswer` (now an instance method with offset/limit); door1 extractor arms on `pdfTextCapable()` = injected seam `?? Bootstrap::capabilityPresent('pdftotext')` (memoized PATH stat walk, no subprocess); door2 carries `%PDF-`-sniffed bytes when ≤1 MiB. Hand-traced: normal in-budget PDF → `documentBytes`+caption (no `@`-mention advice — banned and pinned); edge: 1 MiB+1-byte PDF → notice-only, no carry (pinned at cap±1).
- Thread: Runtime `settle()` resultMessage threads the pair positionally (matches ToolResultMessage ctor tail); `encodeResult` adds `documentBytes`/`documentName` to the serialize-based frame (raw-binary-safe, `allowed_classes=>false` kept); `decodeResult` tolerant `is_string` restore — exact mirror of the image trio in the same array. EngineBackend `encodeEvent('finished')`/`decodeEvent` carry both ways. `anthropicBlocks` tool_result arm becomes a media list [text?, image?, document?]; legacy image-only/text-only shapes byte-preserved (pinned). `toolResultDocument()` chain: isError → hasDocument → supportsDocuments() → nonempty → ≤1 MiB → `FileMentions::sniffDocument` RE-SNIFF (forged-label defense) → `AttachmentEncoding::anthropicDocument` (shared with the user-attachment leg — encoder-sharing law pinned by test). `supportsDocuments()` abstract added; all three composing providers implement (AnthropicProvider :230, VertexProvider :423, GeminiProvider :270); imports verified clean.
- The wire leg fires only on real provider `complete()` — the new tests capture bodies through the transport seam with REAL provider classes, so "survives into a real send" is demonstrated, not asserted by mock.
- E646: the only timer is the 30 s pdftotext subprocess hang-guard (kill-after, not a request timeout on any LLM call). §1.10: zero new `proc_open` tokens in the diff (grep-verified); the text leg rides the existing `CapturesProcessOutput::runCaptured` / `ProcessContainment::spawnSpec` rostered site.
- No fabricated success path: I confirmed `/usr/bin/pdftotext` exists on this host, so the extractor-success arms executed for real (the 122/730 figure I reproduced is consistent with them running).

## 4. Deviations judged (builder-declared, honestly)

(a) **settleFromResultFrame/result-frame skip — DEFENSIBLE, step complete.** The result frame is the ROOT-Message paint channel; Message.php has no document field and the UI never painted image bytes either (image hoisting exists solely for the image strip). PDF has no paint; the transcript-visible text is the caption, which always travels. The child→parent ToolFinished codec and the in-child re-send path ARE threaded. No silent drop of a channel the design named that actually mattered; brief's file list never included Message.php.
(b) **lazy `pdfTextAvailable` seam — honest.** `description()` is capability-neutral by construction (names both doors + budget + notice fallback, never promises extracted text unconditionally) and is byte-stable across seam polarities (pinned equality test). When pdftotext is absent the tool does not lie; it takes the document door or the notice.
(c) **one-road-per-read — a real design choice, not a lost leg.** Image has no extractor door to mirror; text-first is strictly cheaper for context. Pinned behaviorally (extracted pages carry `documentBytes === null`). On hosts without pdftotext the same PDF goes the document door, so no capability is lost end-to-end.
(d) **1 MiB `TOOL_RESULT_DOCUMENT_MAX_BYTES` — justified and triple-pinned** (===1MiB, !==TEXT_MAX_BYTES, ≤DOCUMENT_MAX_BYTES/10), distinct from the 20 MiB `FileMentions::DOCUMENT_MAX_BYTES` (verified `20 * 1024 * 1024` = 20,971,520, matching MEDIA.md's quoted figure).
(e) **trait-const read via `AnthropicProvider::TOOL_RESULT_DOCUMENT_MAX_BYTES` — correct.** PHP forbids fetching a trait constant from outside the composing class; the const's ownership stays in the trait, the reference goes through a consumer. Doctrine-mirror of the house pattern.

## 5. Tests / vacuity pass

Every new pin was read. No vacuous assertions found: at-cap fixture is computed from the pinned const (rides/over flip tested); non-doc-model arm asserts the caption STRING (not just absence of document); the byte-identical legacy-shape arms have positive content pins; success arms are capability-guarded with the fall-through arm pinned separately (never silently vacuous); PromptStability arm asserts Sglang/OpenAI wires never show base64/`%PDF` AND the prefix strictly extends. GlobDialect/ReadPathCensus/StderrEmitterCensus/DenialPrefixRoster ran green over the new literals.

Reproduced gates (cwd candy-code, linked vendor, `env -u LINES -u COLUMNS`, plain pipe):
- Targeted `ReadPdfText|ReadBinaryScan|ToolResultTest|ToolResultDocumentBlocks|PromptStability|AttachmentWire`: **Tests: 122, Assertions: 730, OK** (builder claim EXACT).
- Guards `StderrEmitterCensus|ReadPathCensus|DenialPrefixRoster|GlobDialect|DocFigureProseDrift|ReadmeRosterDrift|ToolDescriptionSentenceFloor|DuplicatedTestHelperDrift`: **Tests: 299, Assertions: 27051, OK**.
- Codec neighborhood `EngineBackend|ResultFrame|EventFrame|FrameCodec|ForkSafety|Wiring|Catalog|Corpus`: **Tests: 2171, Assertions: 13715, Failures: 8** — exactly the 8 builder-listed, names+datasets identical. **Base attribution re-derived:** the same 8 arms (identical names + MouseWiring datasets) fail in the main tree at `52da3dce3` (which does not contain the lane commit; cf0cfd0be is its ancestor) — Tests 66, Failures 8 for the narrowed class filter. The 8 are pre-existing foreign (standing-red roster); the builder's "byte-identical at base" claim reproduces as the identical failure set, zero new failures at tip.

## 6. Conventions

PSR-12, `declare(strict_types=1)` preserved in all touched files, final classes, immutable `with*` via mutate (`get_object_vars` spread carries the new pair automatically — verified in ToolResult::mutate path), `::new()` factories untouched, comments say WHY (deviation rationales in docblocks). No new user-facing chrome strings needing `Lang::t` (tool-result content is the model-facing channel; matches existing Read notice doctrine; the CliLang/ChatHost catalogue reds are roster drift, not this lane's omission — Read adds no Lang keys).

## 7. Advisory / harness disclosures (not findings)

- A1: I could not re-run `php tools/gen-tool-docs.php --check` — reviewer shell permission map denies bare `php` script execution (three invocation shapes tried). Builder reports rc=0 ("tool docs up to date"); corroborated indirectly by my green ReadmeRosterDrift + ToolDescriptionSentenceFloor runs. Weld/gate should keep the generator in its battery as usual.
- A2: 2 PHPUnit deprecations present in targeted runs — pre-existing foreign AccessorDeprecationTest family (disclosed by builder, reproduced).

## CLOSEOUT-ONLY (out-of-scope carries, no weld block)

1. durations.tsv rows for the 2 new test files + suite-figure re-pin at the wave gate (lane correctly left these; §1.5).
2. Legacy Chat-native `storeToolResult`/`collectToolResult` path (Chat.php) does not thread document — that path is dead in production per Doctor.php:26-33 and uses the legacy public-property `src/ToolResult` type; if it is ever revived, the pair must be threaded there too (image legs it identically today).
3. If a future step paints documents in the UI, the root-Message/result-frame gap noted in deviation (a) is where it would start; nothing to do now.
