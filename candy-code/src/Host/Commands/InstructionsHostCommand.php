<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use SugarCraft\Code\Commands\InstructionsCommand;
use SugarCraft\Code\Host\ContextSources;
use SugarCraft\Code\Lang;

/**
 * `/instructions` (C-CTX-3.a): list the instruction files this session reads
 * — tier and bytes per row, from the one row builder the `context.sources`
 * wire reads ({@see ContextSources::instructionRows()}), so the served answer
 * and the TUI listing are the same list by construction.
 *
 * `open` is a TUI command: the Chat dispatch arms the editor before this
 * router ever sees it (the same law `/memory open` carries). Reaching the
 * `open` arm here means there is no terminal to hand over, so the honest
 * answer is the needs-a-terminal notice, never a silent success. Every other
 * argument gets the usage row — there is no second verb to invent.
 */
final class InstructionsHostCommand implements HostCommand
{
    public function run(CommandContext $context, string $text): CommandResult
    {
        $after = CommandText::argument($text);
        if ($after !== '') {
            $parts = \preg_split('/\s+/', $after, 2) ?: [$after];

            return match (strtolower($parts[0])) {
                'open' => CommandResult::reply($text, Lang::t('host.instructions.open-needs-terminal')),
                default => CommandResult::reply($text, Lang::t('host.instructions.usage')),
            };
        }

        $priced = ContextSources::priceInstructionFiles($context->root);

        return CommandResult::reply($text, (new InstructionsCommand(
            ContextSources::instructionRows($priced === null ? null : $priced['files']),
            $priced === null ? [] : $priced['refused'],
        ))->report());
    }
}
