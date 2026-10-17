<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\VideoPlayer;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * The key table in front of sugar-video's Player (crush-media W5.4).
 *
 * The Player carries its own keys — space play/pause, ←/→ ±10-frame seek,
 * `[`/`]` speed, digits jump-to-percent, `m` mode cycle — and it answers
 * `q`/Esc/Ctrl+C by stopping itself AND asking the Program to quit, which
 * inside the crush shell would tear down the whole app instead of one
 * screen. So the shell intercepts exactly that close trio before anything
 * reaches the Player, and the Player never sees it. Everything else the
 * gate lets through rides straight in.
 */
final class VideoPlayerKeys
{
    private function __construct()
    {
    }

    /**
     * Whether this key closes the player screen (stop + pop, not quit).
     *
     * Ctrl+C is deliberately NOT here: it stays the app-wide quit chord
     * every full-band modal yields to, and the shutdown teardown the
     * screen registers at open is what stops the player on that path.
     */
    public static function isClose(KeyMsg $msg): bool
    {
        return $msg->type === KeyType::Escape
            || ($msg->type === KeyType::Char && !$msg->ctrl && !$msg->alt && $msg->rune === 'q');
    }

    /** The one-line control hint the chrome row appends when width allows. */
    public static function hint(): string
    {
        return 'space play/pause · ←/→ seek · [ ] speed · m mode · q close';
    }
}
