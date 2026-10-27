<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use SugarCraft\Code\Commands\ShareCommand;

/**
 * `/share` — export the session to a local file (roadmap X-35a); the reply
 * is {@see ShareCommand}'s own output, which names the written path. The
 * session half moved out of `Chat` in roadmap O-2h, so a headless session
 * exports the same file the TUI would.
 *
 * SES-3-P3: the capture buffer is closed in a `finally`, and level-flat
 * afterwards even if the command raised mid-write. Before, a throw escaped
 * `run()` with the buffer still open and every later host write on the
 * connection poured into a buffer nobody would ever clean.
 */
final class ShareHostCommand implements HostCommand
{
    public function run(CommandContext $context, string $text): CommandResult
    {
        $level = ob_get_level();
        ob_start();
        try {
            $exitCode = (new ShareCommand())->execute($context, CommandText::words($text));
        } finally {
            $output = (string) ob_get_clean();
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        return $exitCode === 0
            ? CommandResult::reply($text, trim($output))
            : CommandResult::failure($text, $output, $exitCode);
    }
}
