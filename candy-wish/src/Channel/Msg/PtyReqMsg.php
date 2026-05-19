<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Channel\Msg;

use SugarCraft\Wish\Channel\ChannelMsg;

/**
 * PTY request channel message.
 *
 * Emitted when the SSH client requests a pseudo-terminal (want Pty = true)
 * or releases it (want Pty = false).
 */
final class PtyReqMsg extends ChannelMsg
{
    public function __construct(
        public readonly bool $wantPty,
        public readonly string $term = '',
        public readonly int $cols = 80,
        public readonly int $rows = 24,
        public readonly int $widthPx = 0,
        public readonly int $heightPx = 0,
    ) {}
}
