<?php

declare(strict_types=1);

namespace SugarCraft\Core\Cmd;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Msg\ScreenStackPoppedMsg;

/**
 * Cmd that pops the current screen from the Program's ScreenStack
 * and returns a {@see ScreenStackPoppedMsg} so the model can update
 * its stack state.
 *
 * @see PushScreenCmd
 */
final class PopScreenCmd
{
    private \Closure $cmd;

    public function __construct()
    {
        $this->cmd = static fn(): ScreenStackPoppedMsg => new ScreenStackPoppedMsg();
    }

    public function __invoke(): ScreenStackPoppedMsg
    {
        return ($this->cmd)();
    }
}
