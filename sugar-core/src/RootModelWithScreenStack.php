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

    /**
     * Immutable write channel honoured by {@see Program} when persisting
     * an active screen's next model (lib-reviews-1102 C6).
     */
    public function withScreens(ScreenStack $screens): self
    {
        $next = clone $this;
        $next->screens = $screens;
        $next->currentModel = $screens->isEmpty() ? null : $screens->current()->model;
        return $next;
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof Msg\ScreenStackPushedMsg) {
            $next = clone $this;
            $model = $msg->screen->model;
            // Track id only when the screen's model carries an id property
            // (e.g. a Component). Plain Models without an id are fine — they
            // just aren't traceable from the pushed/popped lists.
            if (property_exists($model, 'id')) {
                $next->pushedIds[] = (string) $model->id;
            }
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
                $poppedModel = $popped->model;
                if (property_exists($poppedModel, 'id')) {
                    $next->poppedIds[] = (string) $poppedModel->id;
                }
                $next->screens = $next->screens->pop();
                $next->currentModel = $next->screens->isEmpty()
                    ? null
                    : $next->screens->current()->model;
            }
            return [$next, null];
        }

        return [$this, null];
    }

    public function view(): string|View
    {
        // Delegate to the live top of the stack — currentModel is a
        // convenience mirror, and after a write-back (C6) the stack is
        // the authority.
        //
        // sugar-core-008: a screen may render a View (cursor placement,
        // window title, progress, image layer). The old (string) cast
        // fatal'd EVERY frame while such a screen was pushed — View
        // implements no __toString (house law, deliberately). The render
        // path consumes the union directly: renderFrame() reads
        // `$rendered->body` and runs applyViewSideEffects() for the
        // optional fields, so the View now survives intact to the wire.
        if (!$this->screens->isEmpty()) {
            return $this->screens->current()->model->view();
        }
        return $this->currentModel?->view() ?? '';
    }
}
