<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Media;

/**
 * Video player controls display mode.
 */
enum ControlsStyle: string
{
    case Auto = 'auto';
    case Always = 'always';
    case Hidden = 'hidden';
}
