<?php

declare(strict_types=1);

namespace CandyCore\Gloss;

/**
 * Horizontal alignment of content within a fixed-width Style box.
 */
enum Align: string
{
    case Left   = 'left';
    case Center = 'center';
    case Right  = 'right';
}
