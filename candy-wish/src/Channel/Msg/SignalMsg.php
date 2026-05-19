<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Channel\Msg;

use SugarCraft\Wish\Channel\ChannelMsg;

/**
 * Signal channel message.
 *
 * Emitted when the SSH client delivers a signal to the process running
 * on the channel (e.g. SIGINT, SIGTERM, SIGHUP).
 */
final class SignalMsg extends ChannelMsg
{
    public function __construct(
        public readonly string $signalName,
    ) {}
}
