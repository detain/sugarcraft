# C-SES-1.b — Background announcement obeys the `≥` law — CLOSED

## Verdict
Shipped in one commit. `BackgroundSession::announcement()` now spells the cost
via `SpendLedger::boundUsd($this->spendUnpriced, $this->costUsd)` under
`costUsd > 0.0 || spendUnpriced` — the exact fix shape from the design block.
Priced bytes unchanged; unpriced runs print `≥$x` including `≥$0.0000` at zero;
priced zero stays hidden (known nothing).

## Implementation
- `candy-code/src/Background/BackgroundSession.php` (announced region only):
  import `SugarCraft\Code\Host\SpendLedger`; cost branch in `announcement()`
  swapped from `sprintf('$%.4f', …)` gated on `> 0.0` to the law call gated on
  `> 0.0 || spendUnpriced`; `announcement()` docblock extended with the law
  sentence (guards/doc move in the same commit).
- `candy-code/tests/Chat/BackgroundResultAnnouncedTest.php`: new method
  `testTheAnnouncementCostFigureObeysTheBoundLaw` pinning four shapes —
  priced-above-zero plain `$`, unpriced-above-zero `≥$` (and the exact spelling
  is NEVER the bound one), unpriced-at-zero `≥$0.0000` floor visible,
  priced-at-zero no `$` at all. Separator-anchored needles (`' · $…'`) make the
  one-figure-one-spelling leg non-vacuous.

## Regression proof
`--filter testTheAnnouncementCostFigureObeysTheBoundLaw` at pre-fix source:
FAILS on `an unpriced figure reads as the lower bound it is` (announcement
printed `Stats: runtime 0s · $0.0123`). Post-fix: green.

## Gates (targeted, plain pipe, cwd=worktree candy-code)
- BackgroundResultAnnouncedTest: 15T/73A OK (was 14T pre-lane; +1 method).
- Consumers BackgroundReconnectAfterRestart|TaskToolBackground|BgReconnectAtBoot|BgMethods: 29T/259A OK.
- SpendLedgerTest + announced class: 30T/139A OK.
- php -l both files clean.

## Brief risk check ("announcement golden may pin old $0.0123")
The only announcement cost pins (`BackgroundResultAnnouncedTest:269`,
`BackgroundReconnectAfterRestartTest:106`) are priced sessions — the fix
guarantees their bytes are identical; no re-pin needed. Verified green in the
filter runs above. `BgReconnectAtBootTest` S1 test pins wire-row KEYS, not
announcement text — unaffected.

## Inherited base reds (pre-existing at b985fbe51, stash-proven identical at pristine base)
- TreeWideGuardRosterTest: 2F (walkers in Cli/JsonSchemaEnvelopeTest.php +
  Context/MemoryRecallBlockTest.php — foreign files, untouched by this lane).
- StderrEmitterCensusTest: 3F (known foreign-emitter family).
- ReadmeSuiteFigureDriftTest staleness pair: live 26,379 vs pinned 25,537 at
  base; this lane is +1 → 26,380. Closeout re-pin carries it.

## Deviations
None. Files touched exactly per the brief's declared regions; no new test
files; suite-figure.json / durations.tsv / dist untouched; never pushed.
