# C-PRV-5.a — per-family thinking ceilings and omission

Branch `cc/C-PRV-5.a`, base `c36cc1982`. Verdict: **complete**.

## Root cause fixed

Only the floor (`< 1024` / `< -1`) bounded `thinkingBudget`, while
`AnthropicMessagesWire::anthropicBody()` raised `max_tokens` to
`budget + budget/2` — so `thinkingBudget 50000` asked the Messages API for a
75000-token reply above the family's 64000 published output ceiling (a 400 on
every turn), `/effort low|…|max` on `claude-3-5-*` / `gemini-2.0-*` /
`gemini-1*` sent a thinking arm those families never had, and `/effort max` on
`gemini-2.5-flash` over-ran its real-world ceiling.

## Shape shipped

- **Anthropic wire** (`anthropicBody`, before `formatAnthropicMessages` so the
  ONE-per-request budget feeds messages + replay + body): consult
  `ThinkingCapability` — known non-thinking family (`outputCeiling` non-null &&
  `!supportsThinking`) drops the budget whole (off-shape body); thinking
  family clamps budget into `min(budget, ceiling)` keeping the 1024 floor; the
  reply ceiling clamps to the family ceiling too; when the clamp pins
  `max_tokens == budget` the budget yields one token to keep the protocol
  triangle (`budget_tokens < max_tokens`). CTX-1 comment truth-flipped in-step
  (max() can now shrink).
- **Gemini wire** (`geminiBody`): same family gate for `thinkingConfig`
  (configured number AND effort tier AND `-1`/`0` dropped on pre-thinking
  families); positive budgets clamp into 65536; `-1`/`0` pass unclamped on
  thinking families (a ceiling clamp can neither produce nor eat them);
  explicit `maxOutputTokens` clamps. A21(b) comments truth-flipped.
- **`EffortBudget::capToCeiling(int, ?int)`**: the one clamp; null ceiling is
  the table's no-answer and passes through — **unknown family keeps today's
  behavior byte-for-byte** (arm sent, no clamp; the table never refuses what
  it doesn't know). `supportsThinking` returns false for unknown ids, so the
  omission is gated on `$ceiling !== null` (family KNOWN), not on
  `supportsThinking` alone.
- **`ProviderFactory`**: both resolvers take the configured model; after the
  existing legs, a whole number above the model's family output ceiling is
  launch-refused with the floor's loud provider-prefixed shape; garbage stays
  no-setting on the tolerant arm; ids outside both families are refused
  nothing.
- **`AnthropicProvider`**: hook docblock truth only (wire now has last word).
  No ctor change (design puts the launch refusal in the factory).
- **docs/SETTINGS.md**: hand prose in the `thinkingBudget` bullet only
  (ceilings/clamp/omission/launch-refusal/unknown law); L344 cross-ref left
  untouched (still true); generated regions untouched (C-CTX-6.c welder
  keep-both noted).

### Ruling-disclosed deviation

Per-**model** family ceiling at launch (not a type-max): a `vertex` provider
configured with a claude-family model and a budget in (64000, 65536] refuses
where a type-max reading of "the configured type's largest ceiling" would
accept. The family table is the only honest ceiling source and the refusal
names it; the corner is unreachable for `anthropic`/`gemini` types.

## Residual disclosed (not fixable here)

`gemini-2.5-flash`'s real per-generation output ceiling (24576) is NOT in the
family-wide `ThinkingCapability` table (that lib is C-PRV-1.a-owned and
documents the family-granularity choice); `/effort max` (32768) therefore
clamps nothing on flash. Wire clamp + launch refusal still stand at 65536.

## Files

- create `candy-code/tests/Providers/ThinkingCeilingTest.php` (20T/51A)
- modify `candy-code/src/Providers/{AnthropicProvider.php,EffortBudget.php,ProviderFactory.php}`
- modify `candy-code/src/Providers/Concerns/{AnthropicMessagesWire.php,GeminiGenerateContentWire.php}`
- modify `candy-code/docs/SETTINGS.md`

## Gates (plain pipe, cwd candy-code, env -u LINES -u COLUMNS)

- `ThinkingCeilingTest.php` alone: `OK (20 tests, 51 assertions)`
- regression proof: base swap via cp-harness → `Tests: 20, Failures: 13`; restore md5-verified
- siblings together (`ThinkingCeiling+AnthropicExtendedThinking+VertexGeminiOutputBudget+GeminiProvider+EffortGate+ProviderFactory`): `OK (204 tests, 614 assertions)`
- `--filter 'Thinking|Effort|Provider|Bedrock'`: `Tests: 2730, Assertions: ~11226-11242` (deprecation count 2, zero from my files — proven by isolated `--display-deprecations` run; wobble by design), OK
- `--filter 'SettingsSchemaDocDrift|DocFigure|TrustKey'`: `OK (128 tests, 4673 assertions)` — doc prose did not trip the drift guards
- five-guard: `Tests: 133, Failures: 2` — exactly the expected staleness pair (README/artifact 25,537 vs live 26,141 from this lane's +20 tests) — welder re-pin duty; `suite-figure.json` / `parallel-tests-durations.tsv` untouched per law.

### Mutations (3, all discriminating, cp+md5 restores verified)

- M1 neuter `max_tokens` cap leg (anthropic wire) → exactly 3 reds (50000-trim, ceiling-triangle, explicit-100000-trim)
- M2 neuter both factory ceiling throws → exactly 3 reds (the three launch-refusal tests)
- M3 neuter gemini family omission → 2 reds (configured-8192 omission, tier+dynamic omission)

## DUR row for the welder (parallel-tests-durations.tsv — NOT touched by this lane)

- `candy-code/tests/Providers/ThinkingCeilingTest.php` — 20 tests / 51 assertions, ~0.14s wall class-filtered (fast; pure wire-body + factory-build, no live transport)
