# C-CTX-6.e — structured, expandable recall row (lane REPORT)

STATUS: COMPLETE. BRANCH cc/C-CTX-6.e @ /home/sites/cc-cmp-C-CTX-6.e, BASE master c9cee706c.
This is the REAL first build: the step had ZERO disk trace from the previously-reported session.

## Shipped shape (probe-locked)

- Structured notice frame: `RuntimeNoticeSink::STRUCTURED_PREFIX . json_encode({kind,count,titles})`,
  `STRUCTURED_PREFIX = "\x01notice.v1 "` — a 0x01 head. LOAD-BEARING DETAIL: the first design used
  NUL, but PHP's default `trim()` charlist is " \t\n\r\0\x0B" and `NoticeSink::record()` trims its
  input, so a NUL head would be shaved off before the queue saw it. 0x01 survives the trim and no
  plain runtime row can begin with it. Pinned by `testAStructuredFrameSurvivesTheRecordTrimWhole`.
- Only kind whitelisted: `memory.recall` (`KIND_MEMORY_RECALL`). Unknown kinds refused at the door.
- Bound law: frame built, then shed to `MAX_CHARS` (400) by POPPING TAIL TITLES — the count is
  always kept and head titles are best-ranked; per-title clip `STRUCTURED_TITLE_MAX_CHARS` 80 with
  control-byte strip + whitespace-run fold (a newline inside a title would split the one-line
  expanded row). Skeleton-only overflow returns false; that arm is DEFENSIVE rather than live
  (the no-titles frame cannot reach 400 chars today) and is commented as such.
- Malformed / forged frames: `decodeStructuredFrame` returns null (prefix, `json_validate`,
  JsonException depth guard, shape checks) → both pumps DROP the row; it never rows as garbage.
- `RuntimeNoticeSink::recallRowText(int)` is the single prose source for the fallback sentence —
  reuses existing `chat.memory.recalled_notice.one/many` keys (lang/en.php:2300). NO new lang keys
  → LangParity satisfied via the none path. Zero new getenv. Zero new stderr emitters (the notice
  sink channel is the transport), so StderrEmitterCensus was not a party.
- Pumps: `Chat::pumpRuntimeNotices()` and SessionHost's C-CTX-2.a drain map a decoded recall frame
  to the new `Message::recallNotice()` row (uiOnly; content = the count sentence, so web/legacy
  readers see today's byte-shape). `Message` carries `list<string> $recallTitles` (add-only class
  tail: promoted param before `$rowKey`, `mutate()` row, only-when-set `jsonSerialize` key — a
  plain notice row serializes NO new key, keeping pre-change checkpoints byte-stable — and tolerant
  `fromArray`); titles are note TITLES (content first lines), never bodies (VIS-6 kept).
- Renderer: a recall row with titles renders through the transcript's EXISTING click-to-expand
  affordance — `renderRecallRow` mirrors `renderThought`, zoned under `toolcall:` + `recallKey()`
  (same generic dispatch Chat's mouse click already routes), collapsed head "💭 Recalled N notes
  … ▸" / "▾", faint one-line-per-title expansion. NO new key binding, NO Ctrl+O claim (that stays
  the newest thought's). Empty-titles rows keep the legacy notice rendering; a plain resumed row
  renders byte-identically to before.
- `Runtime::announceMemoryRecall()` now records the STRUCTURED frame (count + first-line titles).

## Deviations from the design block (disclosed)

1. SECOND micro-region touched: `Chat::expandedByDefault()` gained a `!str_starts_with($id,
   'recall.')` term next to the pre-existing `'thought.'` term (thought/recall rows must start
   collapsed even when the user sets expandToolOutput=true). The block named only
   pumpRuntimeNotices; this is the same feature's first-click correctness, 1 line + docblock.
2. Titles sanitize beyond the block's spec: whitespace-run fold + control-strip (forgery defense,
   pinned by the bound test's expectations).
3. `STRUCTURED_PREFIX` byte is 0x01 not NUL — see the trim hazard above (the block said only
   "reserved prefix"; NUL was this lane's first choice and was measured dead-on-arrival).

## In-step test flips

- `MemoryRecallBlockTest`: the VIS-6 priming-door pins flipped from count-only-sentence rows to
  structured rows (decode count + titles; second-line body text NOT in frame; ids never in frame;
  "Recalled" sentence NOT in the frame — it is pump-side now). Singular-grammar leg decodes then
  asserts `recallRowText(1)`. The one/many catalog-key leg is untouched (keys still live).
- `RuntimeNoticeSinkTest`: +5 methods (trim-survival, tail-shed bound w/ count kept, unknown-kind
  refusal, decoder refusals x5 shapes, socket-backend byte-whole round trip).

## Gates (cwd candy-code, -c phpunit.xml, targeted only)

- `RecallNoticeRowTest|RuntimeNoticeSinkTest|MemoryRecallBlockTest` → 62T/270A OK (new file 9T/37A).
- `RuntimeNoticeSinkDeliveryTest` → 25T/6303A, 1F = `testNoSourceFileCarriesStackedDocComments`.
- `ServedRuntimeNoticeDrainTest|ToolCallClickTest|UiSettingsTest|MutateCarryCensusTest` → 47T/256A OK.
- `DocFigureProseDriftTest` → 92T/4167A OK (worst-case-datagram arm recomputes from live consts;
  frames stay ≤ MAX_CHARS).
- `RendererTest|EngineAskModalTest|StderrEmitterCensusTest|RuntimeNoticeSinkStderrTest|MessageTest|
  ThinkingBlocksTranscriptRoundTripTest` → 378T/40814A, 3F all `StderrEmitterCensusTest` roster arms
  pointing at `src/Cli/Subcommands.php` counts.

## Foreign standing reds inherited at base c9cee706c (listed, NOT chased)

- StderrEmitterCensus roster vs `src/Cli/Subcommands.php` (7→8 raw fwrite, 46→51 'candy-code: ') +
  the BinCandyCodeAutoloadGuardTest "thirty-six" prose arm (36 vs 37). C-TOOL-5.d's lsp emitters
  rode my base while welder 1030V's cure `2d44af1b5` (roster + prose flip) landed only LATER on
  master. My lane touched neither Subcommands.php nor the census files (git diff c9cee706c --
  tests/Cli/StderrEmitterCensusTest.php empty; the 4 stacked-doc offenders are likewise files this
  branch never modified). Green again as soon as this lane merges current master.
- "PHPUnit Deprecations: 2" at every gate above — reproduces on untouched base methods run in
  isolation (e.g. `testAnEmptySinkHasNothingPendingAndDrainsToNothing`), environment noise.

## Master-drift note for the welder

Welder 1030V moved master to ad60f7a3f AFTER my cut c9cee706c (C-TOOL-5.d + C-SES-8.b + C-SEC-8.b
welds + census cures). My two commits touch: src {Chat.php, Diagnostics/{NoticeSink,RuntimeNoticeSink}.php,
Host/SessionHost.php, Message.php, Renderer.php, Runtime.php}, docs/MEMORY.md,
tests {Chat/RecallNoticeRowTest.php (new), Diagnostics/RuntimeNoticeSinkTest.php, Context/MemoryRecallBlockTest.php}.
Overlap risk with C-SEC-8.b (Runtime.php/Chat.php-adjacent): cherry-pick base..tip and resolve on
content; the StderrEmitterCensus + stacked-doc-guard reds in my gate logs above are expected on my
base and should NOT be re-litigated — they are cured on master.

## DUR-ready counts for the gate (new test file → durations TSV row)

- candy-code/tests/Chat/RecallNoticeRowTest.php: 9 methods, 37 assertions, suite-time ~9.8s wall
  for the class in isolation (first-run budget: 10s).
- candy-code/tests/Diagnostics/RuntimeNoticeSinkTest.php: 30 → 35 methods (163 assertions now).
- candy-code/tests/Context/MemoryRecallBlockTest.php: 18 methods (70 assertions), one method
  renamed (testThePrimingDoorRecordsOneStructuredRowPerNewRanking) — count unchanged, no TSV churn.
