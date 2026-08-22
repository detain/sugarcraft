<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Core\Msg;

/**
 * Internal Msg produced by the `statusLine` poll subscription.
 *
 * The `statusLine` settings key names a command whose stdout is painted as a
 * segment of the status bar ({@see Config\StatusLineCommand}). Running it is a
 * side effect, so it may not happen in {@see Chat::view()} — and it must not
 * happen once per frame either, which is once per keystroke. This Msg is the
 * clock that separates the two: {@see Chat::subscriptions()} declares a tick
 * at {@see Config\StatusLineCommand::REFRESH_SECONDS} while — and only while —
 * a command is configured, {@see Chat::update()} turns this into
 * {@see Config\StatusLineCommand::refresh()}, and the renderer only ever reads
 * the cached line.
 *
 * Carries no payload: the runner holds the state, this is only the clock —
 * the same split {@see BackgroundTickMsg} makes for the same reason.
 */
final class StatusLineTickMsg implements Msg
{
}
