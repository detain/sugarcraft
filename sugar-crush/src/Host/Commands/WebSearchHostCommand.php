<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host\Commands;

use SugarCraft\Crush\Commands\WebSearchCommand;
use SugarCraft\Crush\Message;

/**
 * `/websearch <query>` — the session half of {@see WebSearchCommand}
 * (roadmap O-2h).
 *
 * THE ONE COMMAND WHOSE EXCHANGE THE MODEL SEES: the results are context the
 * user fetched FOR the conversation, so neither the echo nor the reply is
 * UI-only — the next turn reads them. A failure is still a notice.
 */
final class WebSearchHostCommand implements HostCommand
{
    public function run(CommandContext $context, string $text): CommandResult
    {
        ob_start();
        $exitCode = (new WebSearchCommand($context->webSearch))->execute($context, CommandText::words($text));
        $output = (string) ob_get_clean();

        return $exitCode === 0
            ? CommandResult::new(Message::user($text), Message::assistant($output))
            : CommandResult::failure($text, $output, $exitCode);
    }
}
