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
        public readonly mixed $execute,
    ) {}

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
