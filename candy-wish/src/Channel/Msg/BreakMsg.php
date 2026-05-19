<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Channel\Msg;

use SugarCraft\Wish\Channel\ChannelMsg;

/**
 * Break channel message.
 *
 * Emitted when the SSH client sends a break request to the channel.
 */
final class BreakMsg extends ChannelMsg
{
    public function __construct(
        public readonly int $breakLengthMs = 0,
    ) {}
}
