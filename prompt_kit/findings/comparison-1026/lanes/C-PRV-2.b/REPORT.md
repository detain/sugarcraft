# C-PRV-2.b — DONE, tip a65b7302e (cc/C-PRV-2.b, base e4a705244, not pushed)

Responses arm now (1) requests + collects + replays encrypted reasoning items (`reasoning.summary:auto` + `include:["reasoning.encrypted_content"]` inside 2.a's effort gate; batch collect, stream `response.reasoning_summary_text.delta` → reasoning channel + `output_item.done` → thinkingBlocks frame; replay tagged `openai_reasoning_item` as `reasoning` items ahead of function_call records, id+encrypted_content both required on collect AND replay, summary parts re-validated); (2) renders tool images in `function_call_output` parts lists via the Anthropic gate ladder (error/vision/budget/sniff), string spelling byte-identical when no image; (3) Anthropic `anthropicBlocks()` replay now filters to `thinking`/`redacted_thinking` (shared carrier can hold foreign items). Non-reasoning bodies byte-identical.

Files: OpenAiResponsesWire.php, AnthropicMessagesWire.php (filter + toolResultImage truth-patch), OpenAIProvider.php (arm doc + `responsesToolResultImageBudget()` answering the abstract — trait const is cross-trait-inaccessible; budget source = AnthropicProvider's SHL-3 const), ADDING_A_PROVIDER.md (thinkingBlocks-ownership law paragraph), OpenAiResponsesWireTest.php in-step flip (reasoning assertSame + include-absence leg).

NEW test file (gate owes durations row): candy-code/tests/Providers/OpenAiResponsesReasoningTest.php (10T).

Gates (plain pipe, cwd candy-code): red-before via base-source swap = 8F/2-legs-correctly-green (negative polarity); feature 68T/217A OK; family 307T/835A OK; guards 517T/92,365A — ONLY staleness pair (live +10T, README/suite-figure re-pin owed by welder). Mutations: M1 include-hoist → exactly 2 reds; M2 vision-rung neuter → exactly 1 red; md5-clean restores.

Deviations: Message.php untouched (carrier verified type-agnostic); CompleteResponse docblock stays Anthropic-worded (out of files — seam); budget-via-abstract shape (design brief assumed direct const reference — impossible across traits); negative/gate legs green-at-base by polarity, 8 feature legs all red-before.

---

## Gate recheck round-3 (2026-10-28, tip be3d3af18 verified via git log -1 + porcelain 0)

r3 brief carried MINOR-1 ("Responses replays stored reasoning items unconditionally") as still-open.
On-disk REVIEW-2.md verdict is CLEAN (0C/0M/0m) and the cure is fully present at be3d3af18, re-proven this round:

- Gate lives at OpenAiResponsesWire.php responsesInput(): replay foreach wrapped in
  `$this->shapesEffortFor($request->model)` (-> isReasoningFamily, pure request-model derivation);
  `include:["reasoning.encrypted_content"]` rides the same effort gate in responsesBody().
- Regression pair EXISTS and is green: testReplayStaysOffTheWireAfterAMidSessionSwitchToATextOnlyModel (:312)
  + testReplayReturnsWhenTheModelSwitchesBackToAnEffortFamily (:335).
- Load-bearing re-proofs (this round, /tmp copies only, cmp-verified restores, porcelain 0 after each):
  M1 gate neutered (`if (true)`) -> EXACTLY 1 red: the text-only-switch test (unconditional replay = the brief's defect, pinned).
  M2 replay neutered (`if (false)`) -> EXACTLY 3 reds: testStoredItemsReplayAsReasoningItemsAheadOfTheCallRecords,
  testReplayedSummaryPartsAreReValidatedFromHostileStores, testReplayReturnsWhenTheModelSwitchesBackToAnEffortFamily.
- EXACT measured gate figures (plain pipe, env -u LINES -u COLUMNS, cwd candy-code, PtyRunToolTest never in filter):
  `--filter OpenAiResponsesReasoningTest` -> Tests: 12, Assertions: 32, OK (r2 brief's "10 tests, 30 assertions" = pre-FIX-1 state).
  `--filter Providers` -> Tests: 2438, Assertions: 9024, OK (byte-equal to REVIEW-2 §3; superset unchanged).
  `--filter GlobDialectDifferentialTest` -> Tests: 13, Assertions: 366, OK — corpus figure 304,700 (PathGlob.php:51) unchanged.
- Docblock truth-check (r3 brief asked to "make truthful" — probes probe-public.php/probe-private.php/probe-class.php here):
  PHP 8.3.6: `Trait::PUBLIC_CONST` via trait NAME -> Fatal "Cannot access trait constant A::K directly" (exit 255);
  `Trait::private const` via trait name -> Fatal "Cannot access private constant"; via USING CLASS -> legal.
  The "PHP fatal across traits" wording is TRUE for public consts too (visibility is irrelevant); OpenAIProvider.php:346
  and OpenAiResponsesWire.php:408-411 stay as written. FIX-1's archived probe.php carries the same result. REVIEW-2 §2 sustained.
- NIT-4 evidence carry: /tmp/opencode/cc-cmp/C-PRV-2b-fix1/ logs (red-before.log, green-file.log, gate-superset.log,
  gate-guards.log, probe.php) copied into this lane dir alongside the r3 probes.
- NO AMEND performed: r3 produced zero code/test diff (cure already landed in round 1, certified in round 2).
  A message-only amend would move the SHA that REVIEW-2.md and the welder carries reference; tip stays be3d3af18.
