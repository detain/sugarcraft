<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Channel\Msg;

use SugarCraft\Wish\Channel\ChannelMsg;

/**
 * Window-change channel message.
 *
 * Emitted when the SSH client resizes the terminal (SIGWINCH equivalent
 * at the SSH channel layer).
 */
final class WindowChangeMsg extends ChannelMsg
{
    public function __construct(
        public readonly int $cols,
        public readonly int $rows,
        public readonly int $widthPx = 0,
        public readonly int $heightPx = 0,
    ) {}
}
