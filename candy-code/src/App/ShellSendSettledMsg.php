<?php

declare(strict_types=1);

namespace SugarCraft\Code\App;

use SugarCraft\Core\Msg;
use SugarCraft\Code\Message;

/**
 * The settled answer to a `/shells send <id> …` that had to wait for its
 * relay to attach (P4-12 CC-1). The wait runs on a loop timer, so the answer
 * lands later than the echo, as its own UI-only row.
 *
 * Carried as a ready-made {@see Message} rather than a shell id and a cursor:
 * the row's wording and its error flag are decided once, where the send
 * happens, and {@see \SugarCraft\Code\Chat} only appends it.
 */
final class ShellSendSettledMsg implements Msg
{
    public function __construct(
        public readonly Message $message,
    ) {
    }
}
