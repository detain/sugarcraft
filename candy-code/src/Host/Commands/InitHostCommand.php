<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use SugarCraft\Code\Commands\InitCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Message;

/**
 * `/init [focus]` on a served session (WEB-2) — the turn-starter the terminal
 * routes as `withInputBuf(InitCommand::prompt(...))->submit()`, expressed as
 * the two pieces a headless driver can apply: the command's own echo row, and
 * a {@see CommandEffect::submitPrompt()} that lands the canned prompt as the
 * session's next turn once that row has. The AGENTS.md writing is the AGENT's
 * doing — same prompt, same gated Write tool, no second implementation here
 * ({@see \SugarCraft\Code\Commands\InitCommand::prompt()}).
 */
final class InitHostCommand implements HostCommand
{
    public function run(CommandContext $context, string $text): CommandResult
    {
        if ($context->readOnly) {
            return CommandResult::refused(Lang::t('host.command.read_only', ['name' => '/init']));
        }

        return CommandResult::new(Message::user($text)->withUiOnly())
            ->withEffect(CommandEffect::submitPrompt(InitCommand::prompt(CommandText::argument($text))));
    }
}
