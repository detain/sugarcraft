<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Chart;

/**
 * Positioning constants for chart elements like legend and title.
 * Mirrors ntcharts' element position option (top/bottom/left/right).
 */
enum Position
{
    case Top;
    case Bottom;
    case Left;
    case Right;
}
