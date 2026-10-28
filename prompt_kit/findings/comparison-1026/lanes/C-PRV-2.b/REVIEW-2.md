# REVIEW-2 — C-PRV-2.b cure round, tip be3d3af18 (amended from a65b7302e)

VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 MINOR. r1's 2 MINORs adjudicated: M1 cured, M2 rejection sustained. 2 NITs unchanged (one accepted out-of-scope, one rides closeout).

## 1. MINOR-1 cure — ACCEPTED (load-bearing, verified)
- `git log master..cc/C-PRV-2.b` = 1 commit; porcelain 0 (also 0 after my gate runs); `%ae` and `%ce` separate greps = detain@interserver.net; zero trailers. Amend diff (a65b7302e..be3d3af18) contains ONLY the gate wrap + FIX-1 comment + test re-arms/new pair — scope tight.
- Gate at OpenAiResponsesWire.php responsesInput: replay foreach wrapped in `$this->shapesEffortFor($request->model)` — exactly r1's prescription. Parity with AnthropicMessagesWire.php:840 (`$thinkingBudget !== null` door) confirmed: both arms now replay provider-protocol state only under a live per-request model/capability door. Wording nit: the comment says "SAME door" as the body gate (body also requires `reasoningEffort !== null`); the family-only choice is right (input-item acceptance is a model-family property; `include` governs response content) — not a finding.
- Byte-stability law: `shapesEffortFor` → `OpenAIProvider::isReasoningFamily` (private static, pure preg/str_starts on the model string); the gate derives from the REQUEST's model field only, zero mutable provider state. `include` is a fixed literal; positive pin `testTheIncludeLegRidesTheEffortGateAndNothingElse` runs the same request twice and asserts identical wire bytes.
- Regression pair: `testReplayStaysOffTheWireAfterAMidSessionSwitchToATextOnlyModel` (default gpt-4o-mini + tagged encrypted item → `array_column($input,'type') === ['message']` + no 'enc' in JSON) and `testReplayReturnsWhenTheModelSwitchesBackToAnEffortFamily` (o3-mini → reasoning item restored with encrypted_content). Non-vacuity: red-before log on disk (/tmp/opencode/cc-cmp/C-PRV-2b-fix1/red-before.log) shows exactly D....F with the quoted Expected [0=>message] vs Actual [0=>reasoning,1=>message] — removing the gate reddens the negative leg; both pre-existing replay tests re-armed to o3-mini keep the positive door live.

## 2. MINOR-2 rejection — SUSTAINED (r1 premise was a phantom)
- Docblock claims (OpenAIProvider.php:346-350, OpenAiResponsesWire.php:405-415) are about direct `Trait::CONST` access; PHP (constants-in-traits since 8.2) forbids it via the trait name regardless of visibility — "Cannot access trait constant A::K directly" (Error). r1's counter-example AnthropicMessagesWire.php:90 is `public const` — visibility is irrelevant; the via-CLASS form `AnthropicProvider::TOOL_RESULT_IMAGE_MAX_BYTES` (line 350, AnthropicProvider composes the trait at :43) is the legal path the prose describes. Repo-wide grep: zero runtime `Trait::` constant accesses (only `{@see}` doc links — legal prose).
- Independent live probe by me DENIED by this harness's permission map (php execution refused, same as r1). Fixer's probe exists on disk (probe.php: trait A public const K, `echo \A::K`) and the quoted error matches documented PHP 8.3 behavior verbatim. Ruling: docblocks stay; r1 MINOR-2 withdrawn as premise-refuted. (Raw probe stdout was not archived — folded into the NIT-4 evidence-carry below.)

## 3. Gates re-run by me (plain pipe, env -u LINES -u COLUMNS, cwd worktree candy-code, linked; PtyRunToolTest never in filter)
- `--filter OpenAiResponsesReasoningTest`: `Tests: 12, Assertions: 32` OK — fixer claim exact.
- `--filter 'OpenAiResponses|Providers'`: `Tests: 2438, Assertions: 9024` OK — fixer claim exact (r1 baseline 2436 +2T).
- `--filter Drift`: `Tests: 593, Failures: 7` — decomposes EXACTLY: ReadmeSuiteFigureDrift ×2 (staleness pair, welder +12T re-pin carry), PermissionsIntroDrift ×1, ReadmeJsonErrorContractDrift ×4 — all foreign/base-inherited, zero self-inflicted. 2 PHPUnit Deprecations ambient (base-present).

## 4. Scope / weld carries / NITs
- Diff file set unchanged (6 files; Message.php absence stays judged correct — carrier filter is type-agnostic); no suite-figure.json / durations.tsv / dist touches (name-only grep, zero matches). New test file unchanged: OpenAiResponsesReasoningTest.php — 12 methods, durations.tsv still ONE row, but the suite-figure/README re-pin delta is now +12T (was +10T).
- NIT-3 (CompleteResponse::$thinkingBlocks docblock family-wording): out-of-scope, closeout docs collection — accepted.
- NIT-4 (lane evidence to prompt_kit, not /tmp): step-local, still open — fixer logs (red-before.log, gate logs, probe) sit under /tmp/opencode/cc-cmp/C-PRV-2b-fix1/ with outputs quoted into FIX-1.md; closeout should copy the logs into lanes/C-PRV-2.b/. Non-blocking.

## Welder carries (updated)
durations.tsv +1 row (OpenAiResponsesReasoningTest.php, 12 methods); README/suite-figure +12T re-pin pair; 5 foreign Drift reds; NIT-3/NIT-4 doc/evidence collection. Lane is CLEARED for weld.
