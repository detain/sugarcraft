# C-TOOL-2.f — REVIEW 2 (round: r2, fresh reviewer)

VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 open MINOR (1 bookkeeping MINOR cured by restatement in this review's weld-carries section) / 2 NIT. Reviewed at tip 81cdd8b29 (2 commits off base; master d79502693). Weld-pending.

## 1. MINOR cure verified (McpToolBridge.php:1189)

`if (strlen($payload) > 4 * intdiv($maxBytes, 3) + 4) return null;` is present, sitting AFTER the `is_string`/empty gate (mandatory — `strlen(mixed)` TypeErrors under strict_types; the spawn's "first line" means first check on the string, which is exactly right) and BEFORE `base64_decode($payload, true)`. Every call site of decodeSniffed (`carriedMedia` :1129 image @ `FileMentions::IMAGE_MAX_BYTES`, :1142 document @ `TOOL_RESULT_DOCUMENT_MAX_BYTES`) rides the door. Worst-case transient decode per failing candidate is now ≤ ~budget+3 bytes instead of the whole frame-legal ~48 MB. Docblock truth-flipped and states the WHY.

## 2. Pin non-vacuity and the honesty claim — independent analysis

`testAPayloadPastTheTextLengthDoorIsRefusedWithoutDecoding` (ImageChannelTest :119+): reflection slices decodeSniffed's OWN source (getFileName/getStartLine/getEndLine — survives method relocation within the file), asserts the door needle EXISTS (M1: removal → `strpos` false → `assertIsInt` red, the reported "false is of type int"), then asserts door index < `base64_decode($payload` index (M2: relocation → `assertLessThan` red, "259 less than 215"). Mutation-reasoned each independently: both mutations redden EXACTLY this one test — the e2e legs stay green because (see below) the door and legacy produce identical outcomes — so the structural leg is genuinely load-bearing, 1F each matches the fixer's logs. Needles are PSR-12-stable token sequences (single spaces around `> * +`), so php-cs-fixer reformat cannot break them; a semantic-preserving reword (constant hoist, helper extract) WOULD redden it — the accepted house cost of structural-order pins (AtomicJsonFile permissions-order precedent). Correctly written.

The "no behavioural pin can exist" claim: SOUND. For any payload, strict-decode output length ≤ floor(3L/4); with canonical padding, L > 4·intdiv(B,3)+4 forces L ≥ 4·intdiv(B,3)+8 → decoded ≥ 3·intdiv(B,3)+4 > B (since B ≤ 3q+2), so every door-refused candidate was already legacy-refused — over-refusal is where decoded ≤ B only possible for UNPADDED tail groups (L%4∈{2,3}) and only within 2 bytes of the exact budget (both real budgets, 5 MiB and the 32 MiB-class document cap, are ≡2 mod 3). Outcome there is benign (size line, empty channel = the universal refusal path) and my reviewer permission map denies arbitrary `php` runs, so I could not probe PHP 8.3's strict handling of unpadded groups directly — recorded as NIT-1 wording hedge, not charged as a defect.

## 3. Lane scope re-check (all clean)

- Hostile bytes: strict base64, non-empty, budget, SNIFF required; mime claim never consulted (forged-mime refusal doc-pinned); `%PDF-` gate on document channel; audio stays description-only. No eval/exec of returned content; fence-wrapped model-facing text only.
- E646: zero timers introduced by either commit. No new proc_open/fork (src): no roster/descriptor duties; the test serving harness is autoload-dev, outside check-child-lifetimes' roots.
- Lang: no user-facing UI strings added (captions are model-facing, consistent with the file's existing English lines).
- Production reachability: `Bootstrap::mcpTools()` feeds bridges; wires consume the channels (AnthropicMessagesWire :961/:1001, OpenAiResponsesWire :433) — not dormant. McpCallTool shares replyResult (verified behaviorally at r1).

## 4. Diff file set

Cumulative `master...HEAD`: the 5 block-named files (+730/−11 total, +603/−11 r1 + fix commit) PLUS `prompt_kit/findings/comparison-1026/lanes/C-TOOL-2.f/FIX-1.md` — lane evidence convention, precedent C-AGT-4.a/FIX-1.md (tracked at master). NOT touched: suite-figure.json, scripts/parallel-tests-durations.tsv, candy-code-web/dist. ✓

## 5. WELD CARRIES (corrected — this restatement IS the cure for the bookkeeping MINOR)

- durations.tsv: add row for `candy-code/tests/Tools/McpToolBridgeImageChannelTest.php` (new file; unlisted files run in zero shards).
- suite-figure.json + README re-pin: lane delta vs master is **+16T** (15 tests in the new file incl. the r2 door pin, +1 cap-with-image leg in ResultCapTest; McpToolBridgeTest 40→40, modified in-method only), assertions +~85 across the family (r1 +603 carried 69→76 pair figures at the two-file gate; weld derives exact live numbers from the green battery per §7 cadence). FIX-1.md:71 still says "+15T" — stale by its own added test; supersede with +16T.
- docs/MCP.md serialized region landed clean; no DocFigure arm re-arms needed (MCP arms green: 166T/4329A including DocFigureProseDriftTest).

## NITs (no change demanded)

- NIT-1 — ImageChannelTest docblock "no $payload can make the two disagree" and the src sentence "can never fit the budget" are absolute; add "(up to a 2-byte unpadded-tail edge at the exact budget, where refusing is also fine)" if the sentence is ever touched anyway.
- NIT-2 — docs/MCP.md says "since C-TOOL-2" — campaign-ID jargon in a user doc (the known cross-cutting family); closeout could flatten to "now" / "since the media channels landed".

## Gates (reviewer-run, cwd=candy-code, plain pipe, env -u LINES -u COLUMNS, -c phpunit.xml; PtyRun filter never invoked)

- `--filter 'McpToolBridgeImageChannelTest|McpToolBridgeResultCapTest'` → `OK — Tests: 20, Assertions: 76, PHPUnit Deprecations: 2` (fixer's figure EXACT).
- `--filter 'McpToolBridgeTest|McpOutputFenceTest|DocFigureProseDriftTest|McpCallTool'` → `OK — Tests: 166, Assertions: 4329`.
- `--filter 'GlobDialectDifferential|DuplicatedTestHelperDrift|StderrEmitterCensus|TreeWideGuardRoster|SymbolCitationDrift|ChildWallClockBudget'` → `OK — Tests: 159, Assertions: 57248` (guard family fully green at this tip; the known TreeWideGuardRoster/ServeArgs standing red did NOT fire).
- `Deprecations: 2` on every run = disclosed foreign suite-global artifact. Staleness pair (ReadmeSuiteFigureDrift) is the standing §7 weld duty, excluded.
