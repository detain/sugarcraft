# C-TOOL-2.f — MCP images/PDF resources into the tool-result channel — LANDED

Status: DONE, committed on cc/C-TOOL-2.f (base 410f5ac58).

## Commit
- `eaa8aaa8b` — "C-TOOL-2.f: MCP images/PDF resources into the tool-result channel"
  (cat-file-verified commit object; full SHA eaa8aaa8b94e528fa194f036c4dcb0d1e751ed93;
  %ae and %ce greps each = 1 detain; zero Co-Authored trailers; 5 files, +603/−11)

## What shipped
- src/Tools/McpToolBridge.php:
  - `replyResult()` now calls new `carriedMedia($raw)` and threads the result
    channel: `imageBytes` (first decodable, in-budget, sniffs-as-image part) +
    `imageProtocol: BootProbe::mosaic()->protocol()` (Read's probe precedent),
    `documentBytes`/`documentName` (first embedded-resource blob that strict-decodes,
    is ≤ AnthropicProvider::TOOL_RESULT_DOCUMENT_MAX_BYTES and sniffs `%PDF-`;
    name = uri basename, else 'document.pdf').
  - `decodeSniffed()` — strict base64, non-empty, budget, SNIFF required; mime
    claim never consulted (lying-mime refusal documented).
  - Carriage NOT gated on isError (wire gates billing; transcript previews honestly)
    — documented + pinned. Audio never carried (no channel).
  - `renderContent()` gained `$carriedImageIndex` positional threading (position
    counts every part, incl. non-arrays, matching array_values walk); the carried
    image part's line now reads "N base64 bytes carried as an image on this result —
    the first decodable image part only; later image parts stay text"; every other
    line byte-preserved (legacy size-line pins untouched).
  - `renderPart()`/`renderInlineBytes()` docblocks truth-flipped (the "until that
    exists" falsity replaced with what now exists + the first-only honesty rule).
  - McpCallTool inherits with ZERO change (shared replyResult; verified behaviourally
    + structurally in tests — design block's "verify").
- docs/MCP.md: one new paragraph in the fence/output discussion (after the
  "Both spellings answer under the same untrusted-output fence" paragraph):
  first-decodable-image carriage, PDF resource carriage via the C-TOOL-2.d channel,
  audio stays described, later/oversize parts keep size lines, one replyResult()
  serves both spellings. Deliberately numeral-free (no new DocFigure-derivable
  figures); MCP arms stayed green.
- Tests:
  - NEW tests/Tools/McpToolBridgeImageChannelTest.php — 14T: first-valid-PNG wins
    (second stays "not shown"); protocol=probe; oversize(>IMAGE_MAX_BYTES) stays
    text + empty channel; forged mime incl. PDF-in-image-part reaches NEITHER
    channel; invalid base64 stays text; audio with real RIFF/WAVE bytes stays
    described; PDF resource blob rides document channel with its size line intact +
    basename name; non-pdf/oversize refused; first-PASSING pdf wins when earlier
    candidates fail; no-uri default name; text resource untouched; error-flag
    carriage polarity + `['error'=>…]` envelope empty; both-spellings parity
    (direct replyResult == bridged, byte-identical, + McpCallTool source-scan leg);
    cutter clips text while channel bytes ride untouched.
  - McpToolBridgeTest.php: null-channel legs + attribution comments on the two
    legacy size-line tests (their 'AAAA'/5000×'A' fixtures decode to NULs — sniff
    fails, so lines honestly stay "not shown").
  - McpToolBridgeResultCapTest.php: stdio fixture gained a schema-declared `image`
    branch + new test — oversized answer WITH carriable image: content clipped at
    65_536 with marker, imageBytes intact.

## Gates (cwd=candy-code, -c phpunit.xml, plain pipe, exact measured figures)
- `tests/Tools/McpToolBridgeImageChannelTest.php McpToolBridgeTest.php McpToolBridgeResultCapTest.php`: OK (83 tests, 239 assertions).
- file-mode McpOutputFenceTest 6T/11A, McpToolProgressTest 15T/68A, McpBackgroundCallTest 15T/123A, McpDispatcherToolsTest 9T/92A: all OK.
- `tests/Config/DocFigureProseDriftTest.php tests/Permissions/McpDispatchParityTest.php tests/Integration/McpToolWiringTest.php`: OK (136 tests, 4342 assertions).
- guard filter (ReadmeSuiteFigureDrift|GlobDialectDifferential|DuplicatedTestHelperDrift|ChildWallClockBudget|TreeWideGuardRoster|StderrEmitterCensus|SymbolCitationDrift): 163T/57274A — ONLY 2 failures = the standing staleness pair (pinned 25537 vs live 28417), foreign-inherited, closeout re-pin duty. All other guards green.

## Attribution notes (NOT charged to this lane)
- 2 "PHPUnit Deprecations" in any --filter mode = AccessorDeprecationTest::runtimeEraPairs()/eraPairs() private data providers (tests/Config/AccessorDeprecationTest.php:168/:202) — suite-global scan artifact, foreign pre-existing.
- Staleness pair as briefed; live enumeration 28,417 includes this lane's +15T.

## Mutations (both cp-restore via committed clean tree, md5 + diff-empty verified)
- M1 carriedMedia neutered (always return $none): ImageChannelTest 14T → exactly 8F (carriage legs load-bearing; legacy description pins unaffected).
- M2 sniff gate removed (decoded passes without sniff): 3F (forged-mime, document-refusal, invalid-base64 family).

## WELD CARRIES
- durations.tsv row needed: candy-code/tests/Tools/McpToolBridgeImageChannelTest.php (14T).
- suite-figure/README re-pin carries this lane's +15T (14 new file + 1 ResultCap test).
- docs/MCP.md = serialized region; landed only on cc/C-TOOL-2.f here.
- No lang keys, no emitters, no proc_open sites (no roster rows), protocol trio untouched (welder regenerates).
