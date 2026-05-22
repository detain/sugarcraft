<?php

declare(strict_types=1);

namespace SugarCraft\Core\Cmd;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Screen;
use SugarCraft\Core\ScreenStack;

/**
 * Cmd that pushes a screen onto the Program's ScreenStack and returns
 * a {@see ScreenStackPushedMsg} so the model can update its stack state.
 *
 * @see PopScreenCmd
 */
final class PushScreenCmd
{
    private \Closure $cmd;

    public function __construct(Screen $screen)
    {
        $this->cmd = static fn (): \SugarCraft\Core\Msg\ScreenStackPushedMsg =>
            new \SugarCraft\Core\Msg\ScreenStackPushedMsg($screen);
    }

    public function __invoke(): \SugarCraft\Core\Msg\ScreenStackPushedMsg
    {
        return ($this->cmd)();
    }
}
