<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Components\Media;

/**
 * Video player controls display mode.
 */
enum ControlsStyle: string
{
    case Auto = 'auto';
    case Always = 'always';
    case Hidden = 'hidden';
}
