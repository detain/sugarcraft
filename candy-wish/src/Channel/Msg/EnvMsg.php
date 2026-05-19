<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Channel\Msg;

use SugarCraft\Wish\Channel\ChannelMsg;

/**
 * Environment channel message.
 *
 * Emitted when the SSH client requests a channel environment variable
 * to be set before the shell or exec starts.
 */
final class EnvMsg extends ChannelMsg
{
    public function __construct(
        public readonly string $name,
        public readonly string $value,
    ) {}
}
