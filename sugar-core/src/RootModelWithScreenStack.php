<?php

declare(strict_types=1);

namespace SugarCraft\Core;

/**
 * Concrete root model that owns a ScreenStack and delegates view
 * to the active screen while routing all ScreenStackPushedMsg /
 * ScreenStackPoppedMsg infrastructure messages through its own update().
 *
 * @see ScreenStackCapable
 * @see \SugarCraft\Core\Msg\ScreenStackPushedMsg
 * @see \SugarCraft\Core\Msg\ScreenStackPoppedMsg
 */
final class RootModelWithScreenStack implements Model, ScreenStackCapable
{
    use SubscriptionCapable;

    /** @var list<string> */
    public array $pushedIds = [];

    /** @var list<string> */
    public array $poppedIds = [];

    /**
     * The base layer while a screen is pushed. {@see $currentModel} mirrors the
     * live top then, so the base is parked here and restored when the stack
     * empties; with no screen pushed it stays null and the base is
     * {@see $currentModel} itself.
     */
    private ?Model $baseLayer = null;

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
        if ($screens->isEmpty()) {
            // Still empty: the base is already the active model, nothing to restore.
            if (!$this->screens->isEmpty()) {
                $next->currentModel = $this->baseLayer;
                $next->baseLayer = null;
            }
        } elseif ($this->screens->isEmpty()) {
            $next->baseLayer = $this->currentModel;
            $next->currentModel = $screens->current()->model;
        } else {
            $next->currentModel = $screens->current()->model;
        }
        return $next;
    }

    /**
     * Program calls init() on the active model only. With no screen pushed
     * that is this root, so the base layer's startup Cmd is returned here;
     * with a screen on top the screen's own init() is the one Program runs.
     */
    public function init(): ?\Closure
    {
        return $this->screens->isEmpty() ? $this->currentModel?->init() : null;
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
            if ($next->screens->isEmpty()) {
                $next->baseLayer = $next->currentModel;
            }
            $next->screens = $next->screens->push($msg->screen);
            $next->currentModel = $msg->screen->model;
            // A pushed screen starts like the root's own init(): nothing else
            // calls init() on it, so its startup Cmd is returned to the caller.
            return [$next, $model->init()];
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
                if ($next->screens->isEmpty()) {
                    // The base layer comes back with its state as it was at the push.
                    $next->currentModel = $next->baseLayer;
                    $next->baseLayer = null;
                } else {
                    $next->currentModel = $next->screens->current()->model;
                }
            }
            return [$next, null];
        }

        // Every other Msg goes to the live top of the stack (or to the
        // base model when no screen is pushed) and the result is written
        // back, so a screen's state change survives this call. Program
        // routes straight to the active screen, so this path serves direct
        // callers and nested roots.
        if (!$this->screens->isEmpty()) {
            [$nextTop, $cmd] = $this->screens->current()->model->update($msg);
            $next = clone $this;
            $next->screens = $this->screens->replaceCurrentModel($nextTop);
            $next->currentModel = $nextTop;
            return [$next, $cmd];
        }
        if ($this->currentModel !== null) {
            [$nextBase, $cmd] = $this->currentModel->update($msg);
            $next = clone $this;
            $next->currentModel = $nextBase;
            return [$next, $cmd];
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
