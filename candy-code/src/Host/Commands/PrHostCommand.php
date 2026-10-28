<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use SugarCraft\Code\Commands\PrCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Message;

/**
 * The server-side body of `/pr` (roadmap GAP-GIT-04): the host path cannot
 * type into an input buffer, so the canned prompt rides the submit effect
 * instead — the same shape `InitHostCommand` gives `/init`, and for the same
 * reason: the driver applies the effect after the command rows land, so the
 * turn starts exactly the way the TUI's handler starts it.
 *
 * A read-only session refuses: `/pr` pushes and posts, whatever the
 * permission mode inside the turn would say.
 */
final class PrHostCommand implements HostCommand
{
    public function run(CommandContext $context, string $text): CommandResult
    {
        if ($context->readOnly) {
            return CommandResult::refused(Lang::t('host.command.read_only', ['name' => '/pr']));
        }

        return CommandResult::new(Message::user($text)->withUiOnly())
            ->withEffect(CommandEffect::submitPrompt(PrCommand::prompt(CommandText::argument($text))));
    }
}
