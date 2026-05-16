<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Hook;

/**
 * Manages a collection of Hook instances and dispatches them in order.
 *
 * Hooks are invoked in the order they were added. Each hook receives the
 * output of the previous hook, allowing chained transformations.
 */
final class HookRegistry
{
    /** @var list<Hook> */
    private array $hooks = [];

    /**
     * Register a hook to be called during recording.
     */
    public function addHook(Hook $hook): void
    {
        $this->hooks[] = $hook;
    }

    /**
     * Invoke all beforeSave hooks on an event, returning the final event.
     *
     * If any hook returns null, the event is suppressed and null is returned.
     *
     * @return \SugarCraft\Vcr\Event|null
     */
    public function beforeSave(\SugarCraft\Vcr\Event $event): ?\SugarCraft\Vcr\Event
    {
        $current = $event;
        foreach ($this->hooks as $hook) {
            $result = $hook->beforeSave($current);
            if ($result === null) {
                return null;
            }
            $current = $result;
        }
        return $current;
    }

    /**
     * Invoke all afterCapture hooks for an event.
     */
    public function afterCapture(\SugarCraft\Vcr\Event $event): void
    {
        foreach ($this->hooks as $hook) {
            try {
                $hook->afterCapture($event);
            } catch (\Throwable) {
                // Fire-and-forget; don't let one hook's error affect others
            }
        }
    }

    /**
     * Remove all registered hooks.
     */
    public function clear(): void
    {
        $this->hooks = [];
    }

    /**
     * @return int Number of registered hooks
     */
    public function count(): int
    {
        return count($this->hooks);
    }
}
