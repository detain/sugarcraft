<?php

declare(strict_types=1);

namespace SugarCraft\Tetris;

use SugarCraft\Core\Msg;

/**
 * Dispatched once per gravity tick — a pure marker carrying no
 * payload. The {@see Game}'s tick handler steps the active piece
 * down one row (or locks it + spawns the next one if it can't
 * move further) and re-arms the chain only while none is pending
 * (Game::$gravityPending), so player actions can never stack ticks.
 * When a lock-delay window owns the timing the handler parks the
 * chain instead of re-arming; the input path revives it.
 */
final class GravityMsg implements Msg
{
}
