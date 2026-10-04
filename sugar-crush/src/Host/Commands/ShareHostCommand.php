<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host\Commands;

use SugarCraft\Crush\Commands\ShareCommand;

/**
 * `/share` — export the session to a local file (roadmap X-35a); the reply
 * is {@see ShareCommand}'s own output, which names the written path. The
 * session half moved out of `Chat` in roadmap O-2h, so a headless session
 * exports the same file the TUI would.
 */
final class ShareHostCommand implements HostCommand
{
    public function run(CommandContext $context, string $text): CommandResult
    {
        ob_start();
        $exitCode = (new ShareCommand())->execute($context, CommandText::words($text));
        $output = (string) ob_get_clean();

        return $exitCode === 0
            ? CommandResult::reply($text, trim($output))
            : CommandResult::failure($text, $output, $exitCode);
    }
}
