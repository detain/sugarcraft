<?php

declare(strict_types=1);

namespace SugarCraft\Core;

/**
 * Concrete root model that owns a ScreenStack and delegates view
 * to the active screen while routing all ScreenStackPushedMsg /
 * ScreenStackPoppedMsg infrastructure messages through its own update().
 *
 * @see ScreenStackCapable
 * @see ScreenStackPushedMsg
 * @see ScreenStackPoppedMsg
 */
final class RootModelWithScreenStack implements Model, ScreenStackCapable
{
    use SubscriptionCapable;

    /** @var list<string> */
    public array $pushedIds = [];

    /** @var list<string> */
    public array $poppedIds = [];

    public function __construct(
        public ScreenStack $screens = new ScreenStack(),
        public ?Model $currentModel = null,
    ) {
    }

    public function screens(): ScreenStack
    {
        return $this->screens;
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof Msg\ScreenStackPushedMsg) {
            $next = clone $this;
            $next->pushedIds[] = $msg->screen->model->id;
            $msg->screen->onEnter?->__invoke();
            $next->screens = $next->screens->push($msg->screen);
            $next->currentModel = $msg->screen->model;
            return [$next, null];
        }

        if ($msg instanceof Msg\ScreenStackPoppedMsg) {
            $next = clone $this;
            if (!$next->screens->isEmpty()) {
                $popped = $next->screens->current();
                $popped->onExit?->__invoke();
                $next->poppedIds[] = $popped->model->id;
                $next->screens = $next->screens->pop();
                $next->currentModel = $next->screens->isEmpty()
                    ? null
                    : $next->screens->current()->model;
            }
            return [$next, null];
        }

        return [$this, null];
    }

    public function view(): string
    {
        return $this->currentModel?->view() ?? '';
    }
}
