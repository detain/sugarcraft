<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host\Commands;

/**
 * A built-in slash command whose logic runs without a screen (roadmap O-2h):
 * the TUI and a headless {@see \SugarCraft\Crush\Host\SessionHost} both reach
 * it, through the `hostCommand` its spec file in `builtin-commands/` names
 * ({@see \SugarCraft\Crush\Commands\Specs\BuiltInCommand::withHostCommand()}).
 *
 * Built with no arguments: every input arrives on the {@see CommandContext},
 * so the spec table can construct one by class name.
 */
interface HostCommand
{
    /**
     * Run the command for $text — the WHOLE trimmed draft, `/name` included,
     * which is also the echo row's content.
     */
    public function run(CommandContext $context, string $text): CommandResult;
}
