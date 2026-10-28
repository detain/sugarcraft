# C-TOOL-3.d — `/shells` list/output/stop
area: tool · kind: gap · effort M · phase: normal
deps: C-TOOL-3.c
serialized: [chat:handleShellsCommand(new),isReadOnlySafeCommand] [commands] [bootstrap:workspace] [lang:cmd.shells.,host.shells.] [docs:README.md] [docs:COMMANDS.md] [docs:ARCHITECTURE.md]
ruling: none
risks: Claude Code's equivalent is `/tasks`; do not alias to it (a future `/tasks` for agents would collide).

## Files
- candy-code/README.md
- candy-code/builtin-commands/2905-shells.php
- candy-code/docs/ARCHITECTURE.md
- candy-code/docs/COMMANDS.md
- candy-code/lang/en.php
- candy-code/src/Chat.php
- candy-code/src/Cli/Bootstrap.php
- candy-code/src/Host/Commands/ShellsCommand.php
- candy-code/tests/Chat/PaletteSupersetDispatchTest.php
- candy-code/tests/Commands/BuiltInCommandsTest.php
- candy-code/tests/Commands/MenuBarLabelDerivationTest.php
- candy-code/tests/Host/Commands/ShellsCommandTest.php

## files.create
`candy-code/builtin-commands/2905-shells.php`, `candy-code/src/Host/Commands/ShellsCommand.php`, `candy-code/tests/Host/Commands/ShellsCommandTest.php`

## files.modify
`candy-code/src/Cli/Bootstrap.php` (workspace() service chain), `candy-code/src/Chat.php` (new `handleShellsCommand()` beside handleSchedulesCommand :15246; isReadOnlySafeCommand arm), `candy-code/lang/en.php`, `candy-code/README.md` + `candy-code/docs/COMMANDS.md` (via `php tools/gen-command-docs.php --write`), `candy-code/docs/ARCHITECTURE.md` ("dispatch arms for N built-in slash commands" count)

## tests
create the file above (list, output, stop, foreign-owner refusal, the not-configured reply); modify `candy-code/tests/Commands/MenuBarLabelDerivationTest.php` (hint row), `candy-code/tests/Chat/PaletteSupersetDispatchTest.php` (survivor rationale row), `candy-code/tests/Commands/BuiltInCommandsTest.php` (if it pins a count)

## docs/guards
`CommandDocsDriftTest`, `ReadmeRosterDriftTest`, `DocFigureProseDriftTest` (ARCHITECTURE.md slash-command count) all moved in-step

## Approach
The human has no view of background shells; ShellOutput and ShellStop are model-only. Add a `/shells` built-in with a host command, so served and web sessions get it free through `command.exec`, with verbs:
- `list` (bare form): id, label, state, exit code, age, owner-filtered to `$context->currentSessionId()`;
- `output <id> [cursor]`: the tail via `read()`;
- `stop <id>`: the kill ladder.
The registry reaches the command as a workspace service. `Bootstrap::workspace()` (:1885-1910, the `withService` chain) registers `self::backgroundShells($root)` under `BackgroundShellRegistry::class`, so `CommandContext` is unchanged (`$context->workspace?->service(...)`, the ScheduleRegistry pattern). Read-only judgement: `list`/`output` are admitted in a read-only window and `stop` is not (a `shells` arm in `Chat::isReadOnlySafeCommand()` :10317, the `/schedules` precedent at :10334). The spec file is NEW (per-command-disjoint, not serialized).
