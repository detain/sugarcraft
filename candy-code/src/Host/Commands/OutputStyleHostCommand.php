<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

/**
 * `/output-style [name|off]` - select the ONE output-style rulebook that
 * rides this session's prompt (roadmap C-CTX-4.e).
 *
 * Skeleton in the registry commit; the listing, selection and clearing logic
 * lands with the RulesState style slot in the next commit of this lane.
 */
final class OutputStyleHostCommand implements HostCommand
{
    public function run(CommandContext $context, string $text): CommandResult
    {
        return CommandResult::failure($text, 'output-style: not wired yet', 1);
    }
}
