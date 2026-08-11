<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Core\Msg;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\ToolStarted;

/**
 * The queue of tool-lifecycle events a {@see Backend} reported for one turn,
 * plus the final assistant {@see Message} that turn produced.
 *
 * Exists because {@see Backend::completeAsync()}'s `$onEvent` callback fires
 * deep inside the backend (or, for {@see Backend\EngineBackend}'s forked
 * provider worker, is replayed in one burst when the child's payload lands) at
 * a point where there is no way to dispatch a Msg into {@see Chat::update()}:
 * {@see Chat} is immutable, and the only channel back into the TEA loop is the
 * Msg a Cmd resolves to. So the callback merely queues events, the Cmd resolves
 * to one of these, and {@see Chat} drains it one event per `update()` — each
 * step producing its own render, which is what makes an engine-dispatched tool
 * call show the same running-then-done transcript states as a
 * {@see Chat::registerTool()} one (crush_feat.md §1 D/E1).
 *
 * Once `$events` is empty the queue hands off to an {@see AssistantMsg}
 * carrying {@see $message}, so the final reply goes through the exact same
 * arm of `update()` it always did.
 */
final class BackendToolEventsMsg implements Msg
{
    /**
     * @param list<ToolStarted|ToolFinished> $events Events still to be applied,
     *                                in the order the backend reported them.
     * @param Message $message The turn's final assistant reply, held back until
     *                                the queue drains so the transcript never
     *                                shows an answer above the tool calls that
     *                                produced it.
     * @param int|null $generation Staleness stamp, same contract as
     *                                {@see AssistantMsg::$generation}: an
     *                                event queue for an aborted or superseded
     *                                turn is dropped rather than applied on top
     *                                of whatever the user did since.
     */
    public function __construct(
        public readonly array $events,
        public readonly Message $message,
        public readonly ?int $generation = null,
    ) {}
}
