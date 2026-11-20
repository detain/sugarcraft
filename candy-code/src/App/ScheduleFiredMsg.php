<?php

declare(strict_types=1);

namespace SugarCraft\Code\App;

use SugarCraft\Core\Msg;

/**
 * Internal Msg carrying the schedule clock's launch verdicts that settled after
 * the background tick which started them (AUT-1).
 *
 * A persisted row's daemon answers when its handshake authenticates, which is a
 * later loop turn than the tick that fired it. The verdicts that were already
 * decided (skips, refusals, a launch that settled at once) are written in that
 * tick; these late ones come back through this message so the transcript and the
 * outbound webhooks hear them exactly as they would have had the launch been
 * synchronous.
 */
final class ScheduleFiredMsg implements Msg
{
    /**
     * @param list<array{name: string, action: string, sessionId?: string, error?: string}> $results
     *        the {@see \SugarCraft\Code\Background\ScheduleRegistry::fireDue()} verdicts, in due order
     */
    public function __construct(
        public readonly array $results,
    ) {
    }
}
