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
 *
 * $sessionId is the session the send was typed in, and $transcriptEpoch is
 * the transcript generation it was typed under (`/clear` moves it on, while
 * the session id stays put). The answer lands after a wait, and the user may
 * have switched session or cleared the transcript by then; the Chat arm checks
 * both so the row never lands in a transcript it does not belong to.
 */
final class ShellSendSettledMsg implements Msg
{
    public function __construct(
        public readonly Message $message,
        public readonly ?string $sessionId,
        public readonly int $transcriptEpoch = 0,
    ) {
    }
}
