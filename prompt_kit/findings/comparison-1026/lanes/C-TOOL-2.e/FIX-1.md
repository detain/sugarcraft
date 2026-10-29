# C-TOOL-2.e — FIX-1 (round 1, verdict-file-missing class)

REVIEW-1.md absent on disk → correctness RE-DERIVED against the contract
(design-tool.md §C-TOOL-2.e, extracted to lanes/C-TOOL-2.e/BRIEF.md this round).

## Contract vs tip (pre-fix e2d8c01e5)

| Contract element | Verdict |
|---|---|
| Bedrock `document{format:pdf, name sanitized, source.bytes RAW}` | PRESENT, byte-pinned |
| Gemini/Vertex-Gemini `inlineData{application/pdf}` | PRESENT (shared geminiParts; arm pin) |
| OpenAI chat `{type:file, file_data data:application/pdf}` opt-in flag | PRESENT (chat arm :981 only; Responses/SGLang/Custom no-flag) |
| supportsDocuments flips: Bedrock=vision families, Gemini=true, Vertex=Claude∪Gemini (PaLM false), OpenAI=API_CHAT∧visionTable (override does not buy docs) | PRESENT, factory-level pins |
| SGLang/Custom stay false, named never dropped | PRESENT (Sglang:1058 false, Custom:348 false, byte-untouched) |
| CodexProvider + ProviderFactory untouched (§6 vs C-PRV-4.b) | VERIFIED 0-line diff d83e4eed4..HEAD |
| DocumentAttachmentWireTest one shape pin per wire + decline naming | PRESENT (14T pre-fix) |
| ADDING_A_PROVIDER.md in-step | PRESENT but DEFECTIVE (F1) |

## Findings re-derived

- **F1 (MAJOR, doc-truth):** the docs paragraph claimed the encoders fail closed
  on the bytes "on every leg". FALSE for the Anthropic Messages leg:
  `anthropicBlocks()` passes declared documents straight to `anthropicDocument()`
  (no `%PDF-` re-sniff, no encoder-level size check) — its PDF guarantee lives in
  the capture-time magic-number sniff (`FileMentions::sniffDocument`,
  DOCUMENT_MAX_BYTES refusal). The three C-TOOL-2.e legs do re-sniff. Also garbled
  "true-but-undeclared / declared-but-unbuilt" clause in the same sentence.
- No code, wire-shape, test, or serialization gaps found. Doc is guarded by
  NOTHING in the suite (grep: no test reads ADDING_A_PROVIDER.md) — contract says
  "in-step" only, but an unpinned doc-truth cure can silently rot; pinned anyway.

## Cures (commit 056298ca7)

- docs: scope the claim — "The three C-TOOL-2.e legs fail closed on the bytes
  themselves… a leg that outright declines documents names every one of them.
  The Anthropic Messages leg … trusts the inbound capture's magic-number sniff
  rather than re-reading the bytes at encode time." Rewrote the silent-drop/
  wire-error clause truthfully.
- tests: `DocumentAttachmentWireTest::testTheProviderDocScopesTheByteFailClosedClaimToTheLegsThatReSniff`
  — doc-side (scoped sentence present, "named in the text on every leg" absent)
  + code-side pair (forged PDF: anthropicBlocks still builds the block;
  bedrock/gemini/openai-opt-in refuse it). Reddening from both directions.

## Gates (cwd=candy-code, env -u LINES -u COLUMNS, -c phpunit.xml)

- `--filter DocumentAttachmentWireTest`: OK 15T/42A (+1T/+7A exact).
- `--filter 'Document…WireTest|AttachmentWireTest|Gemini|Vertex|OpenAI|Bedrock|Anthropic|Codex*ProviderTest'`: OK 381T/1004A (builder 380T/997A + my +1T/+7A).
- `--filter 'SglangProvider|CustomProvider|Responses'`: OK 459T/1409A.
- Doc guards `DocFigure|ReadmeRoster|SymbolCitation|DuplicatedDocBlock|ReadmeSuiteFigureDrift`: 120T/22240A, reds EXACTLY the 2 staleness-pair arms (lane-forbidden files).
- php -l clean ×2. The broad substring family `Document|Attachment|Encoding|Provider` was NOT re-run this round: dataset-name matches pull slow foreign classes (>15 min) and the change is docs+tests only, zero src line moved; builder's battery at e2d8c01e5 stands (5 foreign standing reds, isolation-proven).

## Mutation proof (F1 cure)

Restoring the original "every leg" wording (python replace, APPLIED verified) →
the new pin test reddens EXACTLY 1F; cp-restore + re-apply + green re-run;
final doc md5 bbc73f7421fd2ec689f737e705ac8527.
(Gotcha logged: the restore step cp'd the PRE-cure backup and reverted the fix;
caught by the next md5, re-applied, verified.)

## Weld carries

- DUR row for `candy-code/tests/Providers/DocumentAttachmentWireTest.php`
  (NEW file this lane): now **15 tests / 42 assertions** (builder's carry said 14T/35A).
- suite-figure + README re-pin: live enumeration moved +1T by this fix (on top of
  the lane's +7T total over d83e4eed4); staleness pair is the only red.
- Base re-derivation for weld: chain d83e4eed4 → 1a0d724b3 → fe49217ac → e2d8c01e5
  → 056298ca7 (tip, cat-file commit OK).

# C-TOOL-2.e — FIX-1 (round 2, DuplicatedTestHelperDrift license)

REVIEW-2.md absent on disk (main-tree lane dir + worktree + /tmp searched) →
findings taken verbatim from the dispatch brief; re-confirmed against tree.

## MAJOR: shared PDF const reddened the tree

`DocumentAttachmentWireTest::PDF` (added round-1 sibling of the C-TOOL-2.e
wire pins) is byte-identical to `AttachmentWireTest::PDF` →
`DuplicatedTestHelperDriftTest::testNoDuplicatedConstIsUnrecordedInEitherPolity`
failed at tip 056298ca7 (red-pre proven: 14T/197A/1F naming
`duplication PDF: Providers/AttachmentWireTest.php [identical] vs
Providers/DocumentAttachmentWireTest.php [identical]`).
Fix: `'PDF' =>` row in ACCEPTED_CONST_DUPLICATION (DuplicatedTestHelperDriftTest.php,
alphabetical slot between PLAIN and PNG_B64, mirroring the PNG_B64/TINY_PNG_B64
media-pair shape). License-not-consolidate chosen: hoisting one carrier behind
two capture fakes couples the audit 15b-15 census to the C-TOOL-2.e suite and
drags a second file into the lane's declared scope for zero assertion gain;
a re-roll of the fixture must move both together anyway (both assert its base64
rides the wire). Row names both suites and the identical capture fixture.

Commit 9a20c6632 (cat-file OK, detain %ae+%ce, zero trailers) — ONE file,
candy-code/tests/Support/DuplicatedTestHelperDriftTest.php, +1 line.

## Gates (cwd=candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe)

- `--filter 'DuplicatedTestHelperDrift|DocumentAttachmentWire|AttachmentWire'`: OK 69T/342A (green-post at 9a20c6632; the 2 deprecations are ambient, identical pre-fix).
- Provider family `DocumentAttachmentWireTest|AttachmentWireTest|Gemini|Vertex|OpenAI|Bedrock|Anthropic|Codex*ProviderTest`: OK 381T/1004A — exact figure match to round 1 (my row adds zero tests).
- Disclosure: bare `--filter Providers` (2577T) SEGFAULTS at ~366/2577 in a foreign provider suite; reproduced byte-identically at base with my file reverted (providers-base.log), so NOT this fix. That filter was never a lane gate.

## Ledger honesty (NIT) — no code change

The GeminiProviderTest/VertexProviderTest `supportsDocuments()` flips in
fe49217ac are IN-STEP mandates of the §1.8 law: the round-1 code change
flipped those two providers' declared capability, so their existing capability
pins HAD to flip in the same commit or the tree was red at every intermediate
step. They are not scope creep; weld union audit should read them as
contract-required companion edits to the provider-leg change, not extra surface.

## Weld carries (round 2)

- No new test files, no durations.tsv delta; suite-figure live enumeration unchanged (+0T this round) — the round-1 carries above still stand.
- Chain now: d83e4eed4 → 1a0d724b3 → fe49217ac → e2d8c01e5 → 056298ca7 → 9a20c6632 (tip).
