<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Tests;

/** Slice 0 of the stride-4 cube sweep; the sweep and why it is sliced live in GreyRampStrideSweepCase. */
final class GreyRampStrideSweepSlice0Test extends GreyRampStrideSweepCase
{
    protected static function slice(): int
    {
        return 0;
    }
}
