# C-TOOL-2.d — WELD (2026-10-29, welder: cc-cmp subagent)

Base at weld: master dea3d0b12 (weld: C-CTX-5.c). Lane cc/C-TOOL-2.d (base cf0cfd0be), 1 commit.

## Pick map
- a14d85245 → d284451fa963cca330542f013b1e9f3e01b433d6 (✅ mark) — single pick, zero conflicts, 14 files (Read.php document+pdftotext legs, ToolResult/ToolResultMessage document block, Runtime/EngineBackend fold, AnthropicMessagesWire + AttachmentEncoding providers, MEDIA.md, DuplicatedTestHelperDriftTest +3 roster lines in-step, NEW tests/Providers/ToolResultDocumentBlocksTest.php + tests/Tools/BuiltIn/ReadPdfTextTest.php).

## Absorption proof
- `git cherry master cc/C-TOOL-2.d` → `- a14d85245…` (absorbed).
- patch-id --stable SAME; `git show` patches BYTE-IDENTICAL.
- %ae=%ce=detain@interserver.net, zero Co-Authored-By.

## Merged-tree gates (cwd candy-code, env -u LINES -u COLUMNS, plain pipe, -c phpunit.xml)
- Targeted `--filter 'ReadPdfText|ReadBinaryScan|ToolResultTest|ToolResultDocumentBlocks|PromptStability|AttachmentWire'`: **Tests: 122, Assertions: 730, OK** — EXACT vs REVIEW-1/REPORT (builder + r1 both 122/730).
- `php tools/gen-tool-docs.php --check` → **tool docs: up to date** rc=0 (r1 could not run it — map-denied; weld battery discharges that debt).
- Guards filter `StderrEmitterCensus|ReadPathCensus|DenialPrefixRoster|GlobDialect|DocFigureProseDrift|ReadmeRosterDrift|ToolDescriptionSentenceFloor|DuplicatedTestHelperDrift`: **299T/27050A, 1 FAILURE**.
  - The single failure is NOT a C-TOOL-2.d defect: DuplicatedTestHelperDriftTest names the byte-identical `private const NOW = 1_800_000_000;` pair `Context/CacheTtlIdleFoldTest.php` (C-CTX-5.c's NEW file, welded one commit earlier) × `Providers/ModelMetadataTest.php` (pre-existing at base — proven via git show). Attribution: C-CTX-5.c step-loop gate list omitted the drift guard; surfaces only on merged trees. Recorded in C-CTX-5.c/WELD.md; ROUTED TO LEAD for wave-loop cure (roster ACCEPTED_CONST_DUPLICATION row w/ judgement, or consolidate — fixer's call; welder does not edit foreign lane test files).
  - Everything else in the battery rides the r1 lane-tree green (299T count identical; assertion delta −1 = the failing method's consumed assert).

## Bookkeeping
- durations.tsv rows ADDED (welder-only file, per lead): `tests/Tools/BuiltIn/ReadPdfTextTest.php 0.190 10` + `tests/Providers/ToolResultDocumentBlocksTest.php 0.092 13` — measured by dedicated per-file junit runs (0.189673/0.092322); tsv 1542→1544, asc-violations 4==4, set-identity +2 verified, desc-band inserts @576 (0.192→0.189) and @686 (0.093→0.091).
- suite-figure re-pin carry to closeout: ~+21T/+730A per REVIEW-1.

## Carries to closeout (§7)
- The NOW-pair drift roster row (see guard bullet) + figure re-pin.
- r1 disclosed its Codec-neighborhood 8 standing reds are foreign (base-reproduced at 52da3dce3); weld battery skipped that broad filter — targeted gates + gen-tool-docs are the lane's recorded reproduction.
