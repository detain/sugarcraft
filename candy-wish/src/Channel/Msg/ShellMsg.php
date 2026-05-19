<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Channel\Msg;

use SugarCraft\Wish\Channel\ChannelMsg;

/**
 * Shell channel message.
 *
 * Emitted when the SSH client requests a login shell. The subsystem
 * field carries the requested shell type (e.g. "shell", "/bin/bash").
 */
final class ShellMsg extends ChannelMsg
{
    public function __construct(
        public readonly bool $wantShell,
        public readonly string $subsystem = '',
    ) {}
}
