<?php

declare(strict_types=1);

namespace SugarCraft\Code\App;

use SugarCraft\Core\Msg;
use SugarCraft\Code\Chat;

/**
 * Wake-up telling {@see Chat} to drain the attached window's remote feed
 * (C-SES-7.f): the `command.exec` replies the attachment queued and the
 * foreign turns {@see \SugarCraft\Code\Backend\RemoteBackend} has watched
 * through to completion on the server.
 *
 * Deliberately payload-free, the same law {@see ToolEventPumpMsg} states for
 * the tool-event inbox. The rows live in the backend — the single place the
 * server's event stream lands them — and carrying them in the Msg instead
 * would make the message and the inbox two authorities over one order: a
 * fold applied from a stale message could slot a foreign turn's rows before
 * the local echo the typed command just produced.
 *
 * Emitted from two places: {@see Chat::subscriptions()}'s poll (the tick that
 * notices the feed filled while no other Msg came) and the resolution of the
 * `command.exec` promise {@see Chat::dispatchCommand()} arms, so an exec
 * reply lands the moment the server answers rather than one cadence later.
 */
final class RemoteFeedPumpMsg implements Msg
{
}
