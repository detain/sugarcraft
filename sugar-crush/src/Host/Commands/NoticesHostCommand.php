<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host\Commands;

use SugarCraft\Crush\Commands\NoticesCommand;

/**
 * `/notices` — every warning this launch raised, whole on the transcript.
 *
 * E653 Shape A capped what the transcript could carry and sent the whole
 * sentences only to stderr — a scrollback the app cannot re-read. This is the
 * other half: the same stores, un-capped, one line per fact.
 * {@see NoticesCommand} owns the why of reading the stores rather than keeping
 * one; this is the transcript write on top of it — a message worth scrolling
 * back to, because "what did I ignore at launch?" gets asked mid-session.
 */
final class NoticesHostCommand implements HostCommand
{
    public function run(CommandContext $context, string $text): CommandResult
    {
        return CommandResult::reply($text, (new NoticesCommand($context->agentManager))->report());
    }
}
