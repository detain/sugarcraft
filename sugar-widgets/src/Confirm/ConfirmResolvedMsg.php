<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Confirm;

use SugarCraft\Core\Msg;

/**
 * Sent through a Cmd when a key resolves a pending {@see Confirm}: `true`
 * after the confirm key (or the exact typed word, submitted), `false` after
 * a cancel. Only the resolving key sends it; every key before and after
 * returns a null Cmd, so a host can treat each message as the single edge
 * from pending to answered.
 *
 * Original SugarCraft work: charmbracelet/bubbles has no confirm component,
 * so there is no upstream Mirrors citation.
 */
final class ConfirmResolvedMsg implements Msg
{
    public function __construct(
        public readonly string $prompt,
        public readonly bool $confirmed,
    ) {
    }
}
