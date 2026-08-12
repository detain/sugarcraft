<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Core\Msg;

/**
 * Wake-up telling {@see Chat} to drain one entry from its live tool-event
 * inbox (crush_feat.md §1 E1).
 *
 * Deliberately payload-free. The events themselves live in the single shared
 * queue {@see Chat} hands its backend's `$onEvent` callback, and carrying them
 * in the Msg instead would create a SECOND ordering authority: a tick that
 * drained the queue while an earlier batch was still walking back through
 * `Cmd::send()` could apply a later event first, and a {@see
 * Events\ToolFinished} landing before its own {@see Events\ToolStarted} appends
 * a result with no placeholder to replace.
 *
 * Emitted from two places: {@see Chat::subscriptions()}'s poll (the edge that
 * notices the backend queued something) and {@see Chat::update()} itself
 * (which re-sends one per remaining entry, so a burst drains in one render
 * cycle each rather than one per tick).
 *
 * Distinct from {@see BackendToolEventsMsg}, which carries the events that
 * were STILL unpumped when the turn's promise resolved, together with the
 * turn's final reply.
 */
final class ToolEventPumpMsg implements Msg
{
}
