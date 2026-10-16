<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Components\Tree;

/**
 * State machine transition types.
 */
enum TransitionType: string
{
    case Normal = 'normal';
    case Guard = 'guard';
    case Internal = 'internal';
}
