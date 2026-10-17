<?php

declare(strict_types=1);

namespace SugarCraft\SshServer\Channel\Msg;

use SugarCraft\SshServer\Channel\ChannelMsg;

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
