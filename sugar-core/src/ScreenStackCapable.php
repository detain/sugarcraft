<?php

declare(strict_types=1);

namespace SugarCraft\Core;

/**
 * Interface for models that manage a ScreenStack.
 *
 * Models implementing this interface signal to {@see Program} that
 * screen-stack routing should be activated: the runtime will call
 * {@see screens()} to obtain the current stack and route
 * init/update/view to the active screen's model.
 *
 * @internal
 */
interface ScreenStackCapable
{
    /**
     * Return the model's current screen stack.
     */
    public function screens(): ScreenStack;
}
