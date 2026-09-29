<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Media;

/**
 * Waveform visualization style.
 */
enum WaveformStyle: string
{
    case Bars = 'bars';
    case Line = 'line';
    case Blocks = 'blocks';
    case Dots = 'dots';
}
