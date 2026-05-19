<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic;

/**
 * Internal message indicating a frame-advance tick.
 *
 * Dispatched by the Cmd::tick() closure returned from
 * {@see AnimationDriver::init()} and handled in {@see AnimationDriver::update()}
 * to advance the frame index.
 *
 * Mirrors no upstream — this is a SugarCraft internal type.
 */
final class FrameTickMsg implements \SugarCraft\Core\Msg
{
}
