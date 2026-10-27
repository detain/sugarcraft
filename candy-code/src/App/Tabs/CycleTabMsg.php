<?php

declare(strict_types=1);

namespace SugarCraft\Code\App\Tabs;

use SugarCraft\Code\App\Msg;

/**
 * Ask the App to move the foreground along the deck by $delta tabs.
 *
 * +1 walks forward, -1 backward, both wrapping; larger magnitudes ask
 * the App for a jump of that many steps. Zero is a no-op the App may
 * drop without touching the deck.
 */
final class CycleTabMsg implements Msg
{
    /**
     * @param int $delta Signed number of tab positions to move.
     */
    public function __construct(
        public readonly int $delta = 1,
    ) {
    }

    /**
     * Return the signed step count.
     */
    public function delta(): int
    {
        return $this->delta;
    }
}
