<?php

declare(strict_types=1);

namespace SugarCraft\Overlay\Animation;

/**
 * Animation kinds for overlay transitions.
 */
enum AnimationKind
{
    case SLIDE;
    case FADE;
    case SCALE;
}
