<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Tests;

/** Slice 1 of the grey-ramp midpoint sweep; the sweep and why it is sliced live in GreyRampMidpointSweepCase. */
final class GreyRampMidpointSweepSlice1Test extends GreyRampMidpointSweepCase
{
    protected static function slice(): int
    {
        return 1;
    }
}
