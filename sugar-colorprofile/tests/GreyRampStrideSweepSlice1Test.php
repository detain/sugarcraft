<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Tests;

/** Slice 1 of the stride-4 cube sweep; the sweep and why it is sliced live in GreyRampStrideSweepCase. */
final class GreyRampStrideSweepSlice1Test extends GreyRampStrideSweepCase
{
    protected static function slice(): int
    {
        return 1;
    }
}
