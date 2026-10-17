<?php

declare(strict_types=1);

namespace SugarCraft\SshServer\Channel\Msg;

use SugarCraft\SshServer\Channel\ChannelMsg;

/**
 * Exec channel message.
 *
 * Emitted when the SSH client requests a specific command execution
 * rather than an interactive shell.
 */
final class ExecMsg extends ChannelMsg
{
    public function __construct(
        public readonly string $command,
    ) {}
}
