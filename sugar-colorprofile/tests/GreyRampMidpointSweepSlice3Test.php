<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Tests;

/** Slice 3 of the grey-ramp midpoint sweep; the sweep and why it is sliced live in GreyRampMidpointSweepCase. */
final class GreyRampMidpointSweepSlice3Test extends GreyRampMidpointSweepCase
{
    protected static function slice(): int
    {
        return 3;
    }
}
