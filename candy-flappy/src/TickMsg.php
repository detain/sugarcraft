<?php

declare(strict_types=1);

namespace SugarCraft\Flappy;

use SugarCraft\Core\Msg;

/**
 * Frame-tick. Fired every {@see Game::TICK_SECONDS} seconds (~30 fps) by
 * the `game-tick` subscription that {@see Game::subscriptions()} declares —
 * a periodic timer the Program owns, re-armed by the event loop itself
 * (there is no fiber; sugar-core runs on a ReactPHP event loop).
 */
final class TickMsg implements Msg
{
}
