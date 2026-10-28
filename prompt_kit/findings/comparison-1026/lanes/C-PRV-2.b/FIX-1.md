# C-PRV-2.b FIX-1 (review round 1) — 2026-10-28

Amended commit: be3d3af187065f080fde411cb0c54112ed61e36e (was a65b7302e; master..HEAD stays 1, message kept + round note). Author/committer Joe Huss <detain@interserver.net> (%ae/%ce separate greps =1), zero trailers. Never pushed.

## MINOR-1 (accepted, fixed) — load-bearing
Replay of stored reasoning items on the Responses arm was unconditional; the Anthropic
arm gates its replay on the budget door. A mid-session switch reasoning→text-only family
could ship `encrypted_content` reasoning items to a model that rejects them (400-wedge).

- `candy-code/src/Providers/Concerns/OpenAiResponsesWire.php` (responsesInput ~:191-204):
  replay foreach now wrapped in `if ($this->shapesEffortFor($request->model))` with a
  FIX-1 comment mirroring the Anthropic door (`shapesEffortFor` = OpenAIProvider
  reasoning-family test: /\Ao\d/ or gpt-5* sans -chat).
- `candy-code/tests/Providers/OpenAiResponsesReasoningTest.php`: the two existing replay
  tests re-armed with `model: 'o3-mini'` (provider+request) so they stay positive-door
  pins; NEW negative `testReplayStaysOffTheWireAfterAMidSessionSwitchToATextOnlyModel`
  (gpt-4o-mini default door + tagged encrypted item → wire carries only `message`, no
  'enc' blob) and NEW positive pair
  `testReplayReturnsWhenTheModelSwitchesBackToAnEffortFamily` (o3-mini → reasoning item
  replayed with encrypted_content).

Red-before (base src, new tests): `1) ...testReplayStaysOffTheWireAfterAMidSessionSwitchToATextOnlyModel  Failed asserting that two arrays are identical. --- Expected +++ Actual @@ Array &0 [ -    0 => 'message', +    0 => 'reasoning', +    1 => 'message', ]` (tests/Providers/OpenAiResponsesReasoningTest.php:331; log /tmp/opencode/cc-cmp/C-PRV-2b-fix1/red-before.log).

## MINOR-2 (rejected with evidence)
Claim: OpenAIProvider.php:346 + OpenAiResponsesWire docblock wording that direct
`Trait::CONST` access is "a PHP fatal" is false for a public const.
Micro-probe /tmp/opencode/cc-cmp/C-PRV-2b-fix1/probe.php on PHP 8.3.6:
`PHP Fatal error: Uncaught Error: Cannot access trait constant A::K directly` —
the docblocks describe TRAIT::CONST direct access and are literally true. The
class-const form (AnthropicProvider::TOOL_RESULT_IMAGE_MAX_BYTES, AnthropicMessagesWire.php:90)
is legal but is not what the sentences claim. Abstract-hook design and wording left
untouched; only the reviewer's justification was wrong, and it is refuted.

## Gates (plain pipe, env -u LINES -u COLUMNS, cwd=candy-code worktree, linked)
- `--filter OpenAiResponsesReasoningTest`: 12T/32A OK (HEAD: 10T/28A-ish; +2T).
- `--filter 'OpenAiResponses|Providers'`: 2438T/9024A OK (expect ~2436 +2).
- `--filter Drift`: 593T, 7 failures = EXACTLY expected set: staleness pair
  (ReadmeSuiteFigureDrift ×2, 25537 pinned vs 27075 live — inherited fleet-wide drift,
  my +2 inside it; suite-figure/durations are lane-forbidden) + base-inherited
  PermissionsIntroDrift ×1 + ReadmeJsonErrorContractDrift ×4. Nothing else.
- Re-runs of the two fix-file gates green post-amendment (green-file.log, gate-superset.log).
