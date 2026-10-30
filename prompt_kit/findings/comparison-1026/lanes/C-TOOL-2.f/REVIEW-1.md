# C-TOOL-2.f — REVIEW 1 (round: r1)

VERDICT: FINDINGS — 0 CRITICAL / 0 MAJOR / 1 MINOR (step-local) / 1 NIT. Lane is functionally sound, scoped, and honestly pinned; one memory-hardening nit worth a fix-now one-liner.

Reviewed at tip eaa8aaa8b (1 commit off base 410f5ac58; 5 files, +603/−11). All diff files are named in the design block (files.create: McpToolBridgeImageChannelTest.php; files.modify: McpToolBridge.php, docs/MCP.md; tests line names the two other test-file modifies). Zero out-of-scope edits; no weld-only files (suite-figure.json/durations.tsv) touched — correctly carried to WELD.

## Correctness (hand-traced)

- Channel wiring is real, not fabricated: `Tools\ToolResult` ctor carries `imageBytes/imageProtocol/documentBytes/documentName` (C-TOOL-2.d landed at base — verified present); consumers exist and gate properly (AnthropicMessagesWire toolResultImage :957 / toolResultDocument :997, OpenAiResponsesWire :429 — each checks isError, capability, own size cap, and RE-SNIFFS bytes). `BootProbe::mosaic()->protocol()` mirrors Read.php:860 (same `as BootProbe` house alias: Read/GenerateImage/Doctor).
- Error-flag carriage polarity claim VERIFIED on disk: wires refuse to bill error results, transcript preview keeps them — docblock truth.
- Positional threading: `carriedMedia` walks `array_values($content)` (index = position, counts non-array parts); `renderContent` increments `$position` BEFORE the branches — the two walks agree, incl. object-keyed (json_decode) content. Carried flag can only ever hit an `image` part (index only set for type==='image'); audio/text/resource lines byte-untouched.
- Edge: missing/non-string `data`, non-string `type`, invalid base64, empty decode, oversize — all fall through to the legacy text lines. First-PASSING candidate wins per channel; failing candidates move the walk on (pinned for both channels).
- `McpCallTool` inheritance: design's "verify" done behaviorally (direct replyResult == bridged, byte-identical) + source-scan leg.
- McpToolBridgeTest legacy pins: 'AAAA'/5000-A fixtures decode to NUL-garbage, sniff fails, lines honestly stay "not shown" + new null-channel legs — non-vacuous (M2 mutation story consistent).

## Safety

- Untrusted MCP bytes: strict base64, SNIFF required (mime claim never consulted — forged-mime refusal documented + pinned), tool-side budgets mirror Read's carry caps; `%PDF-` gate on the document channel; audio never invented a channel. No SSRF (no network), no inline exec (fence + model-facing text only), no new proc_open/fork sites (no roster rows owed). E646: zero timers introduced.
- `documentName` = `basename($uri)` of server-supplied uri: basename can emit no path separators (no traversal); the string flows only into the C-TOOL-2.d consumers (wire document-block title, Runtime/EngineBackend event fields) — same trust surface Read already established with `basename($path)`; no new sink.

## Findings

- MINOR (step-local) — `candy-code/src/Tools/McpToolBridge.php` `decodeSniffed()` (~:1160 in the new block): the `$maxBytes` budget is enforced AFTER `base64_decode($payload, true)` materializes the whole decoded string. A hostile-but-framing-legal server inside the transport's existing 64 MiB frame cap (sugar-mcp-client `StdioMcpServer::MAX_FRAME_BYTES`) can put many oversize or non-sniffing `image` parts in one reply; every failing candidate is fully decoded (~48 MB transient per candidate) before the length/sniff gates reject it. Bounded (transient, serial) so not a correctness or containment defect — a pre-decode refusal on base64 TEXT length is the cheap hardening, and `renderInlineBytes` already counts those same bytes. Cure: at the top of `decodeSniffed`, `if (strlen($payload) > 4 * intdiv($maxBytes, 3) + 4) { return null; }` (every 3 decoded bytes need 4 text bytes; the +4 slack covers padding) + one pin (a base64 string far past the budget keeps its "not shown" line without decoding — the existing oversize test's `$huge` fixture then rides the new door; optionally assert the door by feeding a ~200 MB-shaped string cheaply as text, no decode needed).
- NIT — the carried part's line tells the model "carried as an image on this result" even on non-vision wires that will drop the channel; the phrasing is literally true about the ToolResult and the size still rides the line (design's "state it in the caption" risk note is honored), so no change demanded; if touched anyway, "carried on this result (attached where the model supports images)" is a hair more precise.

## Gates (reviewer-run, cwd=candy-code, plain pipe, -c phpunit.xml)

- `--filter 'McpToolBridgeImageChannelTest|McpToolBridgeResultCapTest'`: `OK — Tests: 19, Assertions: 69` (+14T new file +5T incl. the new cap-with-image leg).
- `--filter 'McpToolBridgeTest|McpOutputFenceTest|DocFigureProseDriftTest'`: `OK — Tests: 162, Assertions: 4311` (MCP.md paragraph broke no DocFigure arm — deliberately numeral-free prose confirmed).
- `--filter 'GlobDialectDifferential|DuplicatedTestHelperDrift|StderrEmitterCensus|TreeWideGuardRoster|SymbolCitationDrift|ChildWallClockBudget'`: `OK — Tests: 159, Assertions: 57248` — guard family fully green (no new emitters/sprintf census hits; helper names diverge from McpToolBridgeTest twins legally; no glob-shaped literals; `{@see}` cites resolve).
- The 2 `PHPUnit Deprecations` appear identically in every filter mode incl. files this lane never touched = the disclosed foreign suite-global AccessorDeprecationTest provider artifact, NOT charged.
- Builder's reported gates cross-checked: staleness pair (pinned 25537 vs live) is the standing §7 closeout duty, excluded per spawn law; PtyRun exclusion honored (never invoked).

## Ledger

- Builder REPORT claims vs diff: all confirmed ("11 files" in the spawn digest is a garbling — REPORT itself says 5 files +603/−11, matching the diff exactly). Mutation claims (M1 8F, M2 3F) are consistent with the pin shapes read here; M1/M2 not re-run (reviewer budget) but each described mutation is discriminating by construction (neutered carriage flips ≥8 asserted channels; sniff-gate removal flips the forged-mime + invalid-base64 + NUL-fixture pins).
- WELD carries (confirmed owed): durations.tsv row for `candy-code/tests/Tools/McpToolBridgeImageChannelTest.php`; suite-figure/README re-pin +15T. docs/MCP.md serialized region landed clean here.
- Ruling: MINOR is fix-now-if-cheap (one guard line + one pin, step-local to McpToolBridge.php — no other wave file collides); NIT is take-it-or-leave-it. Either way nothing blocks the wave.
