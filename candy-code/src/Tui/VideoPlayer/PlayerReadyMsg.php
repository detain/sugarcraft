<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\VideoPlayer;

use SugarCraft\Code\App\Msg;
use SugarCraft\Video\Player;

/**
 * The answer to the player build the shell armed behind its "Preparing…"
 * frame (crush-media W5.4). Exactly one side is set: a ready {@see Player}
 * to take the band, or a one-line reason the video will not open (dead
 * ffmpeg, unreadable file) which the screen shows until the operator
 * closes it. The screen stays the owner either way — the promise never
 * rejects into an ExceptionMsg for an ordinary playback failure.
 */
final readonly class PlayerReadyMsg implements Msg
{
    public function __construct(
        public ?Player $player = null,
        public ?string $error = null,
    ) {
    }
}
