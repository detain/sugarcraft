<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use SugarCraft\Code\Commands\AgentsCommand;
use SugarCraft\Code\Lang;

/**
 * `/agents` (`/agent`) — the session half of {@see AgentsCommand} (roadmap
 * O-2h): its stdout captured as the reply, a non-zero exit as a notice.
 *
 * A session with no agent manager answers "not configured" rather than
 * throwing. A `candy-code` launch always supplies one; an embedder that
 * builds a session without one gets the sentence every other optional
 * collaborator answers with. (The old `?? throw` escaped out of
 * `Chat::update()`, past sugar-core's loop, and left the terminal raw.)
 */
final class AgentsHostCommand implements HostCommand
{
    public function run(CommandContext $context, string $text): CommandResult
    {
        if ($context->agentManager === null) {
            return CommandResult::reply($text, Lang::t('host.agents.not_configured'));
        }

        ob_start();
        $exitCode = (new AgentsCommand($context->agentManager))->execute($context, CommandText::words($text));
        $output = (string) ob_get_clean();

        return $exitCode === 0
            ? CommandResult::reply($text, $output)
            : CommandResult::failure($text, $output, $exitCode);
    }
}
