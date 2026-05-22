<?php

declare(strict_types=1);

namespace SugarCraft\Core\Msg;

use SugarCraft\Core\Msg;
use SugarCraft\Core\Screen;

/**
 * Carried from {@see \SugarCraft\Core\Cmd\PushScreenCmd} back into the
 * Program's dispatch loop so the model can record the new stack state.
 */
final class ScreenStackPushedMsg implements Msg
{
    public function __construct(
        public readonly Screen $screen,
    ) {
    }
}
