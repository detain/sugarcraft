# BRIEF — C-SES-1.b: Background announcement obeys the `≥` law

- area: ses
- src: design-ses.md
- item: C-SES-1 (sources: SES-1-P3)
- kind: fix · MINOR · effort S
- phase: normal
- ruling: none
- wave: None | late: False
- deps: none
- serialized (claims): none

## Files to create
none

## Files to modify
`candy-code/src/Background/BackgroundSession.php` (announcement())

## Declared regions
{"candy-code/src/Background/BackgroundSession.php": ["announcement"]}

## Tests
modify `candy-code/tests/Chat/BackgroundResultAnnouncedTest.php`. Pin both polarities, plus unpriced-at-zero printing `≥$0`.

## Docs/guards
none

## Approach (verbatim)

- **Root cause.** `BackgroundSession::announcement()` prints `sprintf('$%.4f', $this->costUsd)` only when the cost is above zero (`src/Background/BackgroundSession.php:327-329`). It ignores `spendUnpriced` (`:77`, `:118-123`): an unpriced background session prints an exact figure, or hides its `≥$0`. The web BackgroundPanel already prints `≥`.
- **Fix.** Print `SpendLedger::boundUsd($this->spendUnpriced, $this->costUsd)` when `costUsd > 0 || spendUnpriced`.

## Risks
An announcement golden may pin the old `$0.0123` text. Re-pin it in-step.
