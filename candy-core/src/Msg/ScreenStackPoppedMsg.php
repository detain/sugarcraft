<?php

declare(strict_types=1);

namespace SugarCraft\Core\Msg;

use SugarCraft\Core\Msg;

/**
 * Carried from {@see \SugarCraft\Core\Cmd\PopScreenCmd} back into the
 * Program's dispatch loop so the model can record the new stack state.
 */
final class ScreenStackPoppedMsg implements Msg
{
}
