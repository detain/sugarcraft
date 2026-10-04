<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host\Commands;

/**
 * `/clear` — empty the transcript and keep everything else about the session
 * (roadmap O-2h moved its decision out of `Chat::handleClearCommand()`, whose
 * docblock enumerates what it does and does not touch).
 *
 * Deliberately NOT `/new`: the session id stays, so the session file on disk
 * keeps its checkpoints (`/rewind` still reaches the cleared turns) and its
 * title. The whole effect is {@see CommandEffect::clearTranscript()}: no echo
 * row, because the echo would be the first row of the cleared transcript. The
 * compaction circuit breaker resets with the transcript whose rewrites it
 * counted; the spend total does not — money spent stays spent.
 */
final class ClearCommand implements HostCommand
{
    public function run(CommandContext $context, string $text): CommandResult
    {
        return CommandResult::new()->withEffect(CommandEffect::clearTranscript());
    }
}
