<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Render;

use SugarCraft\Vcr\Player;
use SugarCraft\Vt\Terminal;

/**
 * Orchestrates Player + Terminal to produce a stream of Snapshots
 * at the configured frames-per-second cadence.
 *
 * For each Input event from the cassette, feeds the raw bytes to the
 * Terminal so it can update its cell grid and cursor state. For Output
 * events, feeds the program output bytes directly to the Terminal as
 * well (the terminal receives what the program wrote). Resize events
 * update the Terminal dimensions. The virtual clock advances by each
 * event's timestamp delta; every 1/fps seconds a snapshot is captured.
 *
 * Sleep events advance the virtual clock without emitting bytes.
 *
 * Mirrors charmbracelet/x/vcr Renderer (simplified for PHP).
 */
final class Renderer
{
    /**
     * Drive the player through the terminal, yielding snapshots at fps cadence.
     *
     * @return FrameStream
     */
    public function render(Player $player, Terminal $terminal, float $fps = 30.0): FrameStream
    {
        return new FrameStream($player, $terminal, $fps);
    }
}
