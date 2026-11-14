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
     * Swallowed afterCapture failures (finding 096). The fire-and-forget
     * contract stays — one hook's death must not abort the session or
     * skip its neighbours — but "fire-and-forget" never meant
     * "fire-and-invisible": every death is recorded here and surfaced
     * via {@see hookErrors()} so the CLI (or any embedder) can warn that
     * a hook silently skipped its work.
     *
     * @var list<string>
     */
    private array $errors = [];

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
     *
     * Fire-and-forget: a throwing hook is recorded (see {@see hookErrors()})
     * and the sweep continues so one bad hook cannot kill the others nor
     * the recording session itself.
     */
    public function afterCapture(\SugarCraft\Vcr\Event $event): void
    {
        foreach ($this->hooks as $hook) {
            try {
                $hook->afterCapture($event);
            } catch (\Throwable $t) {
                // Keep the swallow (session continuity beats hook drama),
                // but record the death — pre-fix it vanished entirely and
                // a silently-disabled sanitizer looked identical to a
                // clean capture.
                $this->errors[] = $hook::class . '::afterCapture failed: ' . $t->getMessage();
            }
        }
    }

    /**
     * @return list<string> afterCapture failures recorded so far, each
     *                      naming the hook and the error it threw
     */
    public function hookErrors(): array
    {
        return $this->errors;
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
