<?php

declare(strict_types=1);

namespace ChannelBridge\Candy;

/**
 * The reconnect ladder is exhausted (or the bridge is stopping): the socket
 * will not come back on its own and the process should say why and leave.
 */
final class GiveUp extends ServeError
{
    public function __construct(string $message)
    {
        parent::__construct('give_up', $message);
    }
}
