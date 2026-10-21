<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use SugarCraft\Code\Commands\ExportCommand;

/**
 * `/export` — write the transcript to a local file with its raw bytes
 * (plan W4-B); the reply is {@see ExportCommand}'s own output, which names
 * the written path. Same door the TUI opens, so a headless session exports
 * the file the TUI would.
 */
final class ExportHostCommand implements HostCommand
{
    public function run(CommandContext $context, string $text): CommandResult
    {
        ob_start();
        $exitCode = (new ExportCommand())->execute($context, CommandText::words($text));
        $output = (string) ob_get_clean();

        return $exitCode === 0
            ? CommandResult::reply($text, trim($output))
            : CommandResult::failure($text, $output, $exitCode);
    }
}
