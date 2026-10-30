# C-TOOL-2.f — FIX 1 (round: r1)

Applied: MINOR (step-local). Skipped: NIT (see below). Base tip eaa8aaa8b.

## MINOR — pre-decode text-length door in `decodeSniffed`

`candy-code/src/Tools/McpToolBridge.php`:

- Added the guard at the top of `decodeSniffed`, before `base64_decode`:
  `if (strlen($payload) > 4 * intdiv($maxBytes, 3) + 4) { return null; }`
  — exactly the shape REVIEW-1 prescribed (every 3 decoded bytes occupy 4 text
  bytes; +4 covers padding). A framing-legal hostile part inside sugar-mcp-client's
  64 MiB `MAX_FRAME_BYTES` can no longer force a ~48 MB transient decode per
  failing candidate on either channel.
- Docblock truth-flipped: the budget is now named as refused on the TEXT length
  before decoding, with a paragraph stating WHY the door is observably equivalent
  (see below) so a future reader does not "optimise" it away or chase a
  behavioural pin that cannot exist.

### Why the pin is STRUCTURAL, not behavioural (finding-quality note)

The spawn asked for a behaviour pin ("oversized candidate refused without
decoding — remove the guard → pin reddens"). Measured on disk: that pin CANNOT
exist. For any strict-base64 `$payload`, `strlen(base64_decode($payload, true))
== 3 * intdiv(strlen($payload), 4)` (minus padding), so the text door
`strlen($payload) > 4 * intdiv($maxBytes, 3) + 4` refuses EXACTLY the candidate
set the legacy `strlen($decoded) > $maxBytes` refuses. First probe run proved it:
the candidate built to "decode and sniff but bust the budget" returned null under
the guard REMOVED (both doors said no), i.e. no observable difference — the door
is a pure work-avoidance optimization, byte-identical outcomes.

`candy-code/tests/Tools/McpToolBridgeImageChannelTest.php` — new
`testAPayloadPastTheTextLengthDoorIsRefusedWithoutDecoding`:

- Structural leg (the discriminator): reflection-slices `decodeSniffed`'s own
  source and asserts the door expression EXISTS and sits BEFORE the
  `base64_decode($payload` call (house structural-order idiom, cf. the
  AtomicJsonFile permissions-order pin).
- End-to-end leg (the honest user-visible promise, at the real
  `FileMentions::IMAGE_MAX_BYTES` budget): a ~6.7 MB strict-base64 PNG-headed
  candidate keeps its `[… base64 bytes not shown]` size line, says nothing about
  "carried", and leaves `imageBytes()` null.

### Mutation proofs (cwd=candy-code, plain pipe, -c phpunit.xml, filter `McpToolBridgeImageChannelTest|McpToolBridgeResultCapTest`)

- M1 guard REMOVED → `Tests: 20, Assertions: 70, Failures: 1` —
  `testAPayloadPastTheTextLengthDoorIsRefusedWithoutDecoding`: "Failed asserting
  that false is of type int" (door expression gone from the slice). Exactly the
  new pin; nothing else moved.
- M2 guard MOVED AFTER `base64_decode` (the precise defect this cure is about:
  right refusal, wrong place, transient decode still happens) → `Tests: 20,
  Assertions: 72, Failures: 1` — same pin: "Failed asserting that 259 is less
  than 215" (order leg). A guard-relocation is caught, not just a guard-deletion.
- Both restores md5-verified: `81082cd08b402ab89ef487f1a7c4c9f7` == pre-mutation
  copy (`/tmp/opencode/cc-cmp-fixes/C-TOOL-2.f/McpToolBridge.php.orig`).

## NIT — carried-line wording on non-vision wires: SKIPPED (declared)

Leave-it call taken as offered. The line is literally true about the ToolResult,
the size rides it, and the design's state-the-size risk note is honored; touching
the sentence would also re-arm the two byte-pinned carriage tests for a phrase no
guard owns. Not changed.

## Gates (all cwd=candy-code, plain pipe, env -u LINES -u COLUMNS, -c phpunit.xml)

- `--filter 'McpToolBridgeImageChannelTest|McpToolBridgeResultCapTest'`: OK — 20T/76A (+1T/+7A this fix).
- `--filter 'McpToolBridgeTest|McpToolBridgeImageChannelTest|McpToolBridgeResultCapTest|McpOutputFenceTest|DocFigureProseDriftTest'`: OK — 182T/4387A.
- `--filter 'GlobDialectDifferential|DuplicatedTestHelperDrift|StderrEmitterCensus|TreeWideGuardRoster|SymbolCitationDrift|ChildWallClockBudget'`: OK — 159T/57248A (guard family untouched; no glob-shaped literals added; no new emitters).
- `--filter 'McpToolBridge|McpCallTool|ImageChannel'`: OK — 99T/297A (whole bridge family).
- `Deprecations: 2` on every run = disclosed foreign suite-global AccessorDeprecationTest artifact. PtyRunToolTest never invoked (exclusion honored).
- `suite-figure.json` / `durations.tsv` / `candy-code-web/dist` untouched — weld carries stand (durations row for the lane's new test file; +15T re-pin).
