<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Media;

/**
 * Video playback state.
 */
enum PlaybackState: string
{
    case Stopped = 'stopped';
    case Playing = 'playing';
    case Paused = 'paused';
    case Buffering = 'buffering';
}
