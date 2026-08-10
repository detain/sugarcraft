<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Core\Msg;

/**
 * Internal Msg dispatched once a backend completion arrives.
 * Carried by the Cmd that {@see Chat} schedules when the user
 * submits a turn — the Cmd does the (possibly slow) backend
 * call off the main fiber and dispatches this Msg back into
 * `update()` with the assistant's reply.
 */
final class AssistantMsg implements Msg
{
    /**
     * @param int|null $generation Stamped with {@see Chat}'s generation
     *                              counter at the moment its Cmd was built,
     *                              so a reply that arrives after the user
     *                              aborted (or superseded) that turn can be
     *                              recognised as stale and dropped instead
     *                              of appearing after a later turn's own
     *                              messages. Null (the default) always
     *                              passes the staleness check - used by
     *                              every call site that doesn't track
     *                              generations (tests constructing this
     *                              directly).
     */
    public function __construct(
        public readonly Message $message,
        public readonly ?int $generation = null,
    ) {}
}
