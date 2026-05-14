<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Keys;

use SugarCraft\Dash\Events\Key;

/**
 * Metadata for a key binding.
 *
 * @readonly
 */
final class KeyMeta
{
    public function __construct(
        public readonly KeyIdentifier $id,
        public readonly Key $key,
        public readonly string $description,
        public readonly string $category = 'general',
    ) {}

    /**
     * Get the display string for this binding.
     */
    public function display(): string
    {
        return $this->key->toString();
    }

    /**
     * Get the full description with category.
     */
    public function fullDescription(): string
    {
        return "[{$this->category}] {$this->description}";
    }
}
