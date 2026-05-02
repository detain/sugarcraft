<?php

declare(strict_types=1);

namespace CandyCore\Core\Msg;

use CandyCore\Core\Msg;

/**
 * Reply to a {@see \CandyCore\Core\Cmd::readClipboard()}. The terminal
 * answers `OSC 52 ; <selection> ; <base64-content> BEL|ST`; the
 * input reader decodes the base64 payload before constructing this
 * Msg.
 *
 * `$selection` is the single-byte selection key — `c` for the system
 * clipboard, `p` for X11/Wayland primary, `s` for secondary, `0`–`7`
 * for cut buffers.
 */
final class ClipboardMsg implements Msg
{
    public function __construct(
        public readonly string $content,
        public readonly string $selection = 'c',
    ) {}
}
