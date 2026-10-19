<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

/**
 * `/grind <condition>` on a served session (WEB-2) — `/goal` with the long
 * budget, exactly as the terminal serves both names from one handler: the mode
 * is read off the command word in the draft, so this body just hands the same
 * text to {@see GoalHostCommand} ({@see GoalMode::Grind}). Everything that
 * crosses, crosses; the continuation-round note lives there.
 */
final class GrindHostCommand implements HostCommand
{
    public function run(CommandContext $context, string $text): CommandResult
    {
        return (new GoalHostCommand())->run($context, $text);
    }
}
