<?php

declare(strict_types=1);

namespace SugarCraft\Tetris;

use SugarCraft\Core\Msg;

/**
 * Dispatched when a grounded piece's lock-delay window expires.
 *
 * The window is generation-tagged: every open/reset bumps
 * {@see Game::$lockGeneration} and the armed message carries the new
 * tag. candy-core's tick scheduler has no cancel API, so superseded
 * windows are made inert by tag mismatch instead — a
 * {@see LockDelayMsg} whose generation no longer equals
 * {@see Game::$lockWindowGeneration} is dropped without effect.
 */
final class LockDelayMsg implements Msg
{
    public function __construct(public readonly int $generation)
    {
    }
}
