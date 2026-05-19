<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Attribute;

use Attribute;

/**
 * Marks a command with an example usage string shown in help output.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Example
{
    public function __construct(
        public readonly string $usage,
        public readonly string $description = '',
    ) {
    }
}
