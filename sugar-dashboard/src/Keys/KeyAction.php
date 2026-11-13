<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Keys;

/**
 * Represents an action triggered by a key combination.
 *
 * @template T of \SugarCraft\Dashboard\Foundation\Item
 */
final class KeyAction
{
    /**
     * @param callable(Key): T $execute
     */
    public function __construct(
        public readonly string $name,
        // 'callable' is not a legal property type in PHP, so the promise is
        // enforced at construction instead of fatally at dispatch (finding
        // sugar-dashboard-142).
        public readonly mixed $execute,
    ) {
        if (!is_callable($this->execute)) {
            throw new \InvalidArgumentException(sprintf(
                'KeyAction "%s" requires a callable, got %s',
                $name,
                get_debug_type($this->execute),
            ));
        }
    }

    /**
     * Execute the action with the given key.
     *
     * @return T
     */
    public function execute(Key $key): \SugarCraft\Dashboard\Foundation\Item
    {
        return ($this->execute)($key);
    }
}
