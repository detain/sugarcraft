<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Core\Msg;

/**
 * Stop one delegated run from the live agents strip (`c`, roadmap P-B3): the
 * Task call it runs under, by that call's id. {@see Chat} answers it with
 * `cancel_tool{callId}` (roadmap 1.C-4b) on the running turn, so only that
 * call stops — a parallel member's process is killed, a lone Task stops at
 * its next step — and the rest of the turn carries on. A call that is no
 * longer running is left alone.
 */
final readonly class CancelAgentRunMsg implements Msg
{
    public function __construct(
        public string $parentCallId,
    ) {
    }
}
