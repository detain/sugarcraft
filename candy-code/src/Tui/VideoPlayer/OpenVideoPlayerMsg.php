<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\VideoPlayer;

use SugarCraft\Code\App\Msg;

/**
 * "Open the video player" — the message a driver sends the shell when the
 * operator types `/play` on a playable artifact (crush-media W5.4). Mirrors
 * {@see \SugarCraft\Code\Tui\MediaForm\OpenMediaFormMsg}: the command
 * resolves and consents to the path, the shell owns the slot, and the
 * player build itself happens behind the shell's "Preparing…" frame so a
 * slow probe never blocks the keystroke that opened it.
 */
final readonly class OpenVideoPlayerMsg implements Msg
{
    public function __construct(public string $path)
    {
    }
}
