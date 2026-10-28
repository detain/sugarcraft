# BRIEF — C-SES-9.b: Serve `/status` prints live spend and cap

- area: ses
- src: design-ses.md
- item: C-SES-9 (sources: OBS-2-P1)
- kind: fix · MINOR · effort S
- phase: normal
- ruling: none
- wave: None | late: False
- deps: none
- serialized (claims): none (hot files: `SessionHost.php`, `CommandContext.php`)

## Files to create
none

## Files to modify
`candy-code/src/Host/Commands/CommandContext.php` (new(), properties), `candy-code/src/Host/SessionHost.php` (commandContext()), `candy-code/src/Host/Commands/StatusHostCommand.php` (facts())

## Declared regions
{"candy-code/src/Host/Commands/CommandContext.php": ["properties"], "candy-code/src/Host/SessionHost.php": ["commandContext"], "candy-code/src/Host/Commands/StatusHostCommand.php": ["facts"]}

## Tests
modify `candy-code/tests/Host/WebTwoHostCommandsTest.php`: serve `/status` prints the spend line with `≥` when unpriced, and the cap.

## Docs/guards
none

## Approach (verbatim)

- **Root cause.** Serve constructs `StatusHostCommand` with no arguments, through `withHostCommand(StatusHostCommand::class)` in `builtin-commands/1340-status.php`. It therefore prints `cmd.status.spend.untracked` (`src/Host/Commands/StatusHostCommand.php:73-75`), although `SessionHost` holds a reseeded tracker (`src/Host/SessionHost.php:175`, `:236`) and the session cap.
- **Fix.**
- `CommandContext::new()` gains optional `?TokenTracker $spendTracker` and `?float $spendCap`, both defaulting to null.
- `SessionHost::commandContext()` (`:1621`) fills them.
- `StatusHostCommand` prefers its constructor arguments, then the context.

## Risks
`CommandContext` is built in many places. Named, defaulted parameters keep every other call site byte-identical.
